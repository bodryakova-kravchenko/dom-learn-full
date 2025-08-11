<?php
// index.php
// Публичная часть приложения: роутинг и отображение уровней, разделов и уроков.
// ВНИМАНИЕ: Взаимодействие с БД и весь CRUD находятся в crud.php. Здесь только рендер публичных страниц.

// Включаем показ ошибок (по ТЗ — на время тестирования и в проде тоже)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

// Подключаем функции чтения данных из crud.php (CRUD и подключение к БД реализованы там)
require_once __DIR__ . '/crud.php';

// Утилита: безопасный вывод
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Парсинг пути для роутинга
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$uri = rtrim($uri, '/');

// Корень сайта может быть не в / если развернуто в подпапке — используется относительная навигация

// Обслуживаем favicon по пути /favicon.ico, чтобы исключить 404
if ($uri === '/favicon.ico') {
    $fav = __DIR__ . '/images/favicon.ico';
    if (is_file($fav)) {
        header('Content-Type: image/x-icon');
        header('Cache-Control: public, max-age=604800'); // 7 days
        readfile($fav);
    } else {
        http_response_code(404);
    }
    exit;
}

// Маршрут: /bod — админка (HTML рендер здесь, JS и весь CRUD — в crud.php)
if ($uri === '' || $uri === false) { $uri = '/'; }
if ($uri === '/bod') {
    render_admin_page();
    exit;
}

// Главная: список уровней
if ($uri === '/') {
    $levels = db_get_levels();
    render_header('Уровни');
    echo '<main class="container">';
    echo '';
    // Каждый уровень — на всю ширину, внутри — карточки разделов и уроков
    foreach ($levels as $lv) {
        $levelPath = '/' . ((int)$lv['number']) . '-' . e($lv['slug']);
        echo '<section class="level-card card">';
        echo '  <header class="level-header">';
        echo '    <h2 class="level-title"><a href="' . $levelPath . '">Уровень ' . (int)$lv['number'] . '. ' . e($lv['title_ru']) . '</a></h2>';
        echo '  </header>';

        // Секции уровня
        $sections = db_get_sections_by_level_id((int)$lv['id']);
        if (!empty($sections)) {
            echo '  <div class="section-list">';
            foreach ($sections as $sec) {
                $sectionPath = $levelPath . '/' . ((int)$sec['section_order']) . '-' . e($sec['slug']);
                echo '    <article class="section-card card">';
                echo '      <h3 class="section-title"><a href="' . $sectionPath . '">Раздел ' . (int)$sec['section_order'] . '. ' . e($sec['title_ru']) . '</a></h3>';

                // Уроки раздела
                $lessons = db_get_lessons_by_section_id((int)$sec['id']);
                if (!empty($lessons)) {
                    echo '      <div class="lesson-list">';
                    foreach ($lessons as $lsn) {
                        $lessonPath = $sectionPath . '/' . ((int)$lsn['lesson_order']) . '-' . e($lsn['slug']);
                        echo '        <div class="lesson-card">';
                        echo '          <a class="lesson-link" href="' . $lessonPath . '"><span class="order">' . (int)$lsn['lesson_order'] . '.</span> ' . e($lsn['title_ru']) . '</a>';
                        echo '        </div>';
                    }
                    echo '      </div>';
                }

                echo '    </article>';
            }
            echo '  </div>';
        }

        echo '</section>';
    }
    echo '</main>';
    render_footer();
    exit;
}

// Маршрут: /{levelNum}-{levelSlug}
$parts = explode('/', ltrim($uri, '/'));
if (count($parts) >= 1 && preg_match('~^(\d+)-([a-z-]+)$~', $parts[0], $m1)) {
    $levelNumber = (int)$m1[1];
    $levelSlug = $m1[2];
    $level = db_get_level_by_number_slug($levelNumber, $levelSlug);
    if (!$level) { render_404(); exit; }

    // Если только уровень — показываем разделы
    if (count($parts) === 1) {
        $sections = db_get_sections_by_level_id((int)$level['id']);
        render_header($level['title_ru']);
        breadcrumbs([
            ['href' => '/', 'label' => 'Главная'],
            ['href' => '', 'label' => 'Уровень ' . $levelNumber . '. ' . $level['title_ru']],
        ]);
        echo '<main class="container">';
        echo '<h1>Уровень ' . $levelNumber . '. ' . e($level['title_ru']) . '</h1>';
        echo '<div class="grid cards">';
        foreach ($sections as $sec) {
            $path = '/' . $parts[0] . '/' . ((int)$sec['section_order']) . '-' . e($sec['slug']);
            echo '<article class="card">';
            echo '<h2><a href="' . $path . '">Раздел ' . (int)$sec['section_order'] . '. ' . e($sec['title_ru']) . '</a></h2>';
            // Список уроков в карточке раздела
            $lessons = db_get_lessons_by_section_id((int)$sec['id']);
            if (!empty($lessons)) {
                echo '<div class="lesson-list">';
                foreach ($lessons as $lsn) {
                    if (!(int)$lsn['is_published']) continue; // скрываем непубликованные
                    $lessonPath = $path . '/' . ((int)$lsn['lesson_order']) . '-' . e($lsn['slug']);
                    echo '<div class="lesson-card">';
                    echo '<a class="lesson-link" href="' . $lessonPath . '">Урок ' . (int)$lsn['lesson_order'] . '. ' . e($lsn['title_ru']) . '</a>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</article>';
        }
        echo '</div>';
        echo '</main>';
        render_footer();
        exit;
    }

    // Маршрут: /{level}/{section}
    if (count($parts) >= 2 && preg_match('~^(\d+)-([a-z-]+)$~', $parts[1], $m2)) {
        $sectionOrder = (int)$m2[1];
        $sectionSlug = $m2[2];
        $section = db_get_section_by_level_order_slug((int)$level['id'], $sectionOrder, $sectionSlug);
        if (!$section) { render_404(); exit; }

        // Если только раздел — показываем уроки
        if (count($parts) === 2) {
            $lessons = db_get_lessons_by_section_id((int)$section['id']);
            render_header($section['title_ru']);
            breadcrumbs([
                ['href' => '/', 'label' => 'Главная'],
                ['href' => '/' . $parts[0], 'label' => 'Уровень ' . $levelNumber . '. ' . $level['title_ru']],
                ['href' => '', 'label' => 'Раздел ' . $sectionOrder . '. ' . $section['title_ru']],
            ]);
            echo '<main class="container">';
            echo '<h1>Раздел ' . (int)$sectionOrder . '. ' . e($section['title_ru']) . '</h1>';
            echo '<div class="grid cards">';
            foreach ($lessons as $ls) {
                if (!(int)$ls['is_published']) continue; // скрываем непубликованные
                $path = '/' . $parts[0] . '/' . $parts[1] . '/' . ((int)$ls['lesson_order']) . '-' . e($ls['slug']);
                echo '<article class="card card-sm">';
                echo '<h3><a href="' . $path . '">Урок ' . (int)$ls['lesson_order'] . '. ' . e($ls['title_ru']) . '</a></h3>';
                echo '</article>';
            }
            echo '</div>';
            echo '<nav class="lesson-nav">';
            echo '<a class="btn" href="/" style="margin-top:3vh">На главную</a>';
            echo '</nav>';
            echo '</main>';
            render_footer();
            exit;
        }

        // Маршрут: /{level}/{section}/{lesson}
        if (count($parts) >= 3 && preg_match('~^(\d+)-([a-z-]+)$~', $parts[2], $m3)) {
            $lessonOrder = (int)$m3[1];
            $lessonSlug = $m3[2];
            $lesson = db_get_lesson_by_section_order_slug((int)$section['id'], $lessonOrder, $lessonSlug);
            if (!$lesson || !(int)$lesson['is_published']) { render_404(); exit; }

            $content = json_decode($lesson['content'] ?? '{}', true) ?: [];
            $tests = $content['tests'] ?? [];
            $tasks = $content['tasks'] ?? [];
            $theory_html = $content['theory_html'] ?? '';

            render_header($lesson['title_ru']);
            breadcrumbs([
                ['href' => '/', 'label' => 'Главная'],
                ['href' => '/' . $parts[0], 'label' => 'Уровень ' . $levelNumber . '. ' . $level['title_ru']],
                ['href' => '/' . $parts[0] . '/' . $parts[1], 'label' => 'Раздел ' . $sectionOrder . '. ' . $section['title_ru']],
                ['href' => '', 'label' => 'Урок ' . $lessonOrder . '. ' . $lesson['title_ru']],
            ]);
            echo '<main class="container lesson">';
            echo '<article class="lesson-body">';
            echo '<h1>' . e($lesson['title_ru']) . '</h1>';
            echo '<section class="theory">' . $theory_html . '</section>';

            if (!empty($tests)) {
                echo '<section class="tests">';
                echo '<h2>Тесты</h2>';
                foreach ($tests as $qi => $q) {
                    $qid = 'q' . ($qi+1);
                    echo '<div class="test-question" data-correct="' . (int)($q['correctIndex'] ?? -1) . '">';
                    $qh = $q['question_html'] ?? '';
                    echo '<h3>' . ($qh !== '' ? $qh : e($q['question'] ?? '')) . '</h3>';
                    echo '<ul class="answers">';
                    foreach (($q['answers'] ?? []) as $ai => $ans) {
                        echo '<li><button type="button" class="answer" data-idx="' . $ai . '">' . e($ans) . '</button></li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }
                echo '</section>';
            }

            if (!empty($tasks)) {
                echo '<section class="tasks">';
                echo '<h2>Задачи</h2>';
                foreach ($tasks as $t) {
                    echo '<article class="task">';
                    if (!empty($t['title'])) echo '<h3>' . e($t['title']) . '</h3>';
                    echo '<div class="task-text">' . ($t['text_html'] ?? '') . '</div>';
                    echo '</article>';
                }
                echo '</section>';
            }

            // Навигация по урокам
            $nav = db_get_prev_next_lesson((int)$section['id'], (int)$lesson['lesson_order']);
            echo '<nav class="lesson-nav">';
            if ($nav['prev']) {
                $p = '/' . $parts[0] . '/' . $parts[1] . '/' . (int)$nav['prev']['lesson_order'] . '-' . e($nav['prev']['slug']);
                echo '<a class="btn" href="' . $p . '">◀ Предыдущий</a>';
            }
            echo '<a class="btn" href="/' . $parts[0] . '/' . $parts[1] . '">В оглавление раздела</a>';
            if ($nav['next']) {
                $n = '/' . $parts[0] . '/' . $parts[1] . '/' . (int)$nav['next']['lesson_order'] . '-' . e($nav['next']['slug']);
                echo '<a class="btn" href="' . $n . '">Следующий ▶</a>';
            } else {
                echo '<a class="btn" href="/">На главную</a>';
            }
            echo '</nav>';

            echo '</article>';
            echo '</main>';

            render_footer();
            exit;
        }
    }
}

// Если ничего не совпало
render_404();
exit;

// ===== Вспомогательные функции рендера =====

function render_header(string $title, bool $with_topbar = true): void {
    echo '<!doctype html><html lang="ru"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — DOMLearn</title>';
    echo '<link rel="icon" href="/images/favicon.ico" type="image/x-icon">';
    echo '<link rel="stylesheet" href="/style.css">';
    echo '<script src="/app.js?v=' . filemtime(__DIR__ . '/app.js') . '" defer></script>';
    echo '</head><body class="theme-light">';
    if ($with_topbar) {
        echo '<header class="topbar">';
        echo '<div class="container bar">';
        echo '<a class="brand" href="/">DOMLearn</a>';
        echo '<div class="spacer"></div>';
        echo '<button id="themeToggle" class="icon-btn" title="Переключить тему">🌓</button>';
        echo '</div>';
        echo '</header>';
    }
}

function render_footer(): void {
    echo '<footer class="footer"><div class="container"><p class="muted">© ' . date('Y') . ' DOMLearn</p></div></footer>';
    echo '</body></html>';
}

function breadcrumbs(array $items): void {
    echo '<nav class="breadcrumbs container">';
    $last = count($items) - 1;
    foreach ($items as $i => $it) {
        if ($i !== 0) echo '<span class="sep">/</span>';
        if ($i === $last || empty($it['href'])) {
            echo '<span class="crumb">' . e($it['label']) . '</span>';
        } else {
            echo '<a class="crumb" href="' . e($it['href']) . '">' . e($it['label']) . '</a>';
        }
    }
    echo '</nav>';
}

function render_404(): void {
    http_response_code(404);
    render_header('Страница не найдена');
    echo '<main class="container">';
    echo '<h1>404 — Страница не найдена</h1>';
    echo '<p><a class="btn" href="/">На главную</a></p>';
    echo '</main>';
    render_footer();
}

// ===== Рендер админки (HTML). JS загружается из crud.php?action=admin_js =====
function render_admin_page(): void {
    // Админка: без верхней панели и переключателя темы, всегда светлая тема
    render_header('Админ-панель', false);
    echo '<main class="container admin">';
    echo '<div id="adminApp"></div>';
    echo '<script src="/crud.php?action=admin_js"></script>';
    echo '</main>';
    render_footer();
}
