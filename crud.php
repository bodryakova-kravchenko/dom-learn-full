<?php
// crud.php
// Единый файл BackEnd: подключение к БД, функции CRUD, загрузка изображений, выдача JS админки.
// Вся публичная часть рендера выполняется в index.php. Здесь — только данные и API.

// Включаем ошибки (по ТЗ — показываем и на проде на время тестирования)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

// ================== Конфиг / подключение к БД ==================

/**
 * Простой парсер .env (без внешних библиотек)
 */
function env_load(string $path): array {
    $res = [];
    if (!is_file($path)) return $res;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $res[$key] = $val;
    }
    return $res;
}

$env = env_load(__DIR__ . '/.env');
$DB_HOST = $env['DB_HOST'] ?? 'localhost';
$DB_NAME = $env['DB_NAME'] ?? '';
$DB_USER = $env['DB_USER'] ?? '';
$DB_PASSWORD = $env['DB_PASSWORD'] ?? '';
$DB_CHARSET = $env['DB_CHARSET'] ?? 'utf8mb4';
// Админские креды теперь из .env
$ADMIN_LOGIN = $env['ADMIN_LOGIN'] ?? 'bodryakov.web';
$ADMIN_PASSWORD = $env['ADMIN_PASSWORD'] ?? 'Anna-140275';

/** @var PDO $pdo */
$pdo = null;

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD, $DB_CHARSET;
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $DB_USER, $DB_PASSWORD, $opt);
    // Устанавливаем collation соединения явно
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

// ================== Публичные функции чтения (используются index.php) ==================

/** Получить все уровни в порядке number ASC */
function db_get_levels(): array {
    $stmt = db()->query("SELECT id, number, title_ru, slug FROM levels ORDER BY number ASC");
    return $stmt->fetchAll();
}

/** Получить уровень по number+slug */
function db_get_level_by_number_slug(int $number, string $slug): ?array {
    $stmt = db()->prepare("SELECT * FROM levels WHERE number=? AND slug=? LIMIT 1");
    $stmt->execute([$number, $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Секции по level_id (section_order ASC) */
function db_get_sections_by_level_id(int $level_id): array {
    $stmt = db()->prepare("SELECT * FROM sections WHERE level_id=? ORDER BY section_order ASC");
    $stmt->execute([$level_id]);
    return $stmt->fetchAll();
}

/** Найти раздел по level_id + section_order + slug */
function db_get_section_by_level_order_slug(int $level_id, int $order, string $slug): ?array {
    $stmt = db()->prepare("SELECT * FROM sections WHERE level_id=? AND section_order=? AND slug=? LIMIT 1");
    $stmt->execute([$level_id, $order, $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Уроки по section_id (lesson_order ASC) */
function db_get_lessons_by_section_id(int $section_id): array {
    $stmt = db()->prepare("SELECT * FROM lessons WHERE section_id=? ORDER BY lesson_order ASC");
    $stmt->execute([$section_id]);
    return $stmt->fetchAll();
}

/** Найти урок по section_id + lesson_order + slug */
function db_get_lesson_by_section_order_slug(int $section_id, int $order, string $slug): ?array {
    $stmt = db()->prepare("SELECT * FROM lessons WHERE section_id=? AND lesson_order=? AND slug=? LIMIT 1");
    $stmt->execute([$section_id, $order, $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Предыдущий и следующий урок в разделе по lesson_order */
function db_get_prev_next_lesson(int $section_id, int $order): array {
    $prevStmt = db()->prepare("SELECT id, slug, lesson_order FROM lessons WHERE section_id=? AND lesson_order<? AND is_published=1 ORDER BY lesson_order DESC LIMIT 1");
    $prevStmt->execute([$section_id, $order]);
    $prev = $prevStmt->fetch();

    $nextStmt = db()->prepare("SELECT id, slug, lesson_order FROM lessons WHERE section_id=? AND lesson_order>? AND is_published=1 ORDER BY lesson_order ASC LIMIT 1");
    $nextStmt->execute([$section_id, $order]);
    $next = $nextStmt->fetch();

    return [ 'prev' => $prev ?: null, 'next' => $next ?: null ];
}

// ================== API (минимум для начала) ==================

$action = $_GET['action'] ?? '';
if ($action !== '') {
    switch ($action) {
        case 'admin_js':
            header('Content-Type: application/javascript; charset=utf-8');
            echo admin_js_bundle();
            exit;
        case 'upload_image':
            api_upload_image();
            exit;
        case 'tree':
            api_admin_tree();
            exit;
        case 'section_save':
            api_section_save();
            exit;
        case 'section_delete':
            api_section_delete();
            exit;
        case 'sections_reorder':
            api_sections_reorder();
            exit;
        case 'lesson_save':
            api_lesson_save();
            exit;
        case 'lesson_delete':
            api_lesson_delete();
            exit;
        case 'lessons_reorder':
            api_lessons_reorder();
            exit;
        case 'ping_login':
            // Создаём сессию на сервере после валидации
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['ok'=>false,'error'=>'method']); }
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true) ?: [];
            if (admin_login((string)($data['l'] ?? ''), (string)($data['p'] ?? ''))) {
                json_response(['ok'=>true]);
            } else { http_response_code(401); json_response(['ok'=>false]); }
            exit;
        case 'logout':
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['ok'=>false,'error'=>'method']); }
            admin_logout();
            json_response(['ok'=>true]);
            exit;
        case 'session_ok':
            // Проверка активной сессии
            if (is_admin_authenticated()) { json_response(['ok'=>true]); } else { http_response_code(401); json_response(['ok'=>false]); }
            exit;
        default:
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unknown action']);
            exit;
    }
}

// ================== Загрузка изображений ==================

/** Загрузка изображения в images/lesson_{lesson_id}/, форматы: png,jpg,webp; до 5 МБ */
function api_upload_image(): void {
    // Проверка метода
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    // Простая проверка авторизации админки (сессия)
    if (!is_admin_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $lesson_id = (int)($_POST['lesson_id'] ?? 0);
    if ($lesson_id <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'lesson_id is required']);
        return;
    }

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'file is required']);
        return;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'upload error', 'code' => $file['error']]);
        return;
    }

    // Ограничение размера 5 МБ
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'file too large']);
        return;
    }

    // Разрешённые форматы
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unsupported type']);
        return;
    }

    $ext = $allowed[$mime];

    // Каталог урока
    $dir = __DIR__ . '/images/lesson_' . $lesson_id;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // Генерируем имя файла
    $base = pathinfo($file['name'], PATHINFO_FILENAME);
    $base = preg_replace('~[^a-zA-Z0-9_-]+~', '-', $base) ?: 'image';
    $name = $base . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'failed to move file']);
        return;
    }

    $url = '/images/lesson_' . $lesson_id . '/' . $name;
    header('Content-Type: application/json');
    echo json_encode(['url' => $url, 'filename' => $name]);
}

// ================== Примитивная аутентификация админки ==================

function is_admin_authenticated(): bool {
    return !empty($_SESSION['admin_ok']);
}

function admin_login(string $login, string $password): bool {
    // Данные из .env
    global $ADMIN_LOGIN, $ADMIN_PASSWORD;
    $ok = ($login === $ADMIN_LOGIN && $password === $ADMIN_PASSWORD);
    if ($ok) {
        $_SESSION['admin_ok'] = true;
    }
    return $ok;
}

function admin_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ================== Минимальный JS для админки (рендер SPA без библиотек) ==================

function admin_js_bundle(): string {
    // Весь JS в одной строке для простоты; в реальном коде можно разбить
    ob_start(); ?>
(function(){
  'use strict';

  var LS_REMEMBER = 'domlearn-remember';

  function h(tag, attrs){
    var el = document.createElement(tag);
    if (attrs) for (var k in attrs){ if (k==='text') el.textContent=attrs[k]; else el.setAttribute(k, attrs[k]); }
    return el;
  }

  function mountLogin(){
    var root = document.getElementById('adminApp');
    root.innerHTML = '';

    var wrap = h('div', {class: 'admin-login'});
    var title = h('h2', {text: 'Вход в админ-панель'});
    var f = h('form');
    var login = h('input'); login.type='text'; login.placeholder='Логин';
    var pass = h('input'); pass.type='password'; pass.placeholder='Пароль';
    var rememberLbl = h('label');
    var remember = h('input'); remember.type='checkbox';
    rememberLbl.appendChild(remember);
    rememberLbl.appendChild(document.createTextNode(' Запомнить меня'));
    var btn = h('button', {text: 'Войти'}); btn.type='submit';
    var msg = h('div', {class: 'admin-msg'});

    // Если есть сессионная авторизация — сразу в панель
    fetch('/crud.php?action=session_ok').then(function(r){ if(r.ok) return r.json(); throw 0; }).then(function(){ mountPanel(); }).catch(function(){
      // Если включён флаг remember — после логина не разлогинивать на перезагрузках
    });

    f.addEventListener('submit', function(ev){
      ev.preventDefault();
      var l = login.value.trim();
      var p = pass.value;
      fetch('/crud.php?action=ping_login', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({l:l,p:p})})
        .then(function(r){ if(!r.ok) throw new Error('Неверный логин или пароль'); return r.json(); })
        .then(function(){ try{ if(remember.checked){ localStorage.setItem(LS_REMEMBER,'1'); } }catch(e){}; mountPanel(); })
        .catch(function(e){ msg.textContent = (e && e.message) ? e.message : 'Ошибка авторизации'; });
    });

    wrap.appendChild(title);
    f.appendChild(login); f.appendChild(pass); f.appendChild(rememberLbl); f.appendChild(btn);
    wrap.appendChild(f);
    wrap.appendChild(msg);
    root.appendChild(wrap);
  }

  function api(url, opt){
    opt = opt || {};
    return fetch(url, opt).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
  }

  function el(tag, cls, txt){ var x=document.createElement(tag); if(cls) x.className=cls; if(txt) x.textContent=txt; return x; }

  function mountPanel(){
    var root = document.getElementById('adminApp');
    root.innerHTML = '';
    var bar = h('div', {class:'admin-bar'});
    var title = h('h2', {text:'Панель управления'});
    var logout = h('button', {text:'Выйти'});
    logout.addEventListener('click', function(){
      try{ localStorage.removeItem(LS_REMEMBER); }catch(e){}
      fetch('/crud.php?action=logout', {method:'POST'}).finally(mountLogin);
    });
    bar.appendChild(title); bar.appendChild(logout);

    var shell = el('div','admin-shell');
    var left = el('div','admin-left');
    var right = el('div','admin-right');
    shell.appendChild(left); shell.appendChild(right);

    root.appendChild(bar);
    root.appendChild(shell);

    // Загрузка дерева
    api('/crud.php?action=tree').then(function(data){
      renderTree(left, right, data);
    }).catch(function(err){ left.textContent = 'Ошибка загрузки: '+err.message; });
  }

  function renderTree(left, right, data){
    left.innerHTML = '';
    right.innerHTML = '';
    var levels = data.levels || [];

    var levelTabs = el('div','level-tabs');
    levels.forEach(function(lv, idx){
      var b = el('button','tab', lv.title_ru);
      b.addEventListener('click', function(){ selectLevel(idx); });
      levelTabs.appendChild(b);
    });
    left.appendChild(levelTabs);

    var sectionsWrap = el('div','sections-wrap');
    left.appendChild(sectionsWrap);

    var lessonsWrap = el('div','lessons-wrap');
    right.appendChild(lessonsWrap);

    var currentLevelIndex = 0;
    var currentSectionId = null;

    function selectLevel(i){ currentLevelIndex = i; renderSections(); lessonsWrap.innerHTML=''; }
    function renderSections(){
      sectionsWrap.innerHTML = '';
      var lv = levels[currentLevelIndex];
      var head = el('div','head'); head.textContent = 'Разделы — '+lv.title_ru; sectionsWrap.appendChild(head);
      var addBtn = el('button','btn', '➕ Добавить раздел');
      addBtn.addEventListener('click', function(){
        var title = prompt('Название раздела (рус)'); if(!title) return;
        var slug = prompt('Slug (только a-z и -)'); if(!slug) return;
        api('/crud.php?action=section_save', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ level_id: lv.id, title_ru: title, slug: slug })})
          .then(function(){ return api('/crud.php?action=tree'); })
          .then(function(d){ data=d; levels=d.levels||[]; renderSections(); lessonsWrap.innerHTML=''; })
          .catch(function(e){ alert('Ошибка: '+e.message); });
      });
      sectionsWrap.appendChild(addBtn);

      var ul = el('ul','list'); ul.setAttribute('data-level', lv.id);
      (lv.sections||[]).forEach(function(sec){
        var li = el('li','item'); li.draggable = true; li.dataset.id = sec.id;
        var a = el('a',null, sec.section_order+'. '+sec.title_ru);
        a.href='#'; a.addEventListener('click', function(ev){ ev.preventDefault(); currentSectionId=sec.id; renderLessons(sec); });
        var edit = el('button','sm','✎'); edit.title='Редактировать';
        edit.addEventListener('click', function(){
          var title = prompt('Название раздела', sec.title_ru); if(!title) return;
          var slug = prompt('Slug', sec.slug); if(!slug) return;
          api('/crud.php?action=section_save', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: sec.id, level_id: lv.id, title_ru: title, slug: slug, section_order: sec.section_order })})
            .then(function(){ return api('/crud.php?action=tree'); })
            .then(function(d){ data=d; levels=d.levels||[]; renderSections(); if(currentSectionId===sec.id){ var s=findSection(sec.id); if(s) renderLessons(s); } })
            .catch(function(e){ alert('Ошибка: '+e.message); });
        });
        var del = el('button','sm','🗑'); del.title='Удалить';
        del.addEventListener('click', function(){
          if(!confirm('Удалить раздел и все его уроки?')) return;
          api('/crud.php?action=section_delete', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: sec.id })})
            .then(function(){ return api('/crud.php?action=tree'); })
            .then(function(d){ data=d; levels=d.levels||[]; currentSectionId=null; renderSections(); lessonsWrap.innerHTML=''; })
            .catch(function(e){ alert('Ошибка: '+e.message); });
        });
        li.appendChild(a); li.appendChild(edit); li.appendChild(del); ul.appendChild(li);
      });

      // Drag & Drop
      var dragId = null;
      ul.addEventListener('dragstart', function(e){ var t=e.target.closest('li.item'); if(!t) return; dragId=t.dataset.id; e.dataTransfer.effectAllowed='move'; });
      ul.addEventListener('dragover', function(e){ e.preventDefault(); });
      ul.addEventListener('drop', function(e){ e.preventDefault(); var t=e.target.closest('li.item'); if(!t||!dragId) return; if(t.dataset.id===dragId) return; ul.insertBefore(document.querySelector('li.item[data-id="'+dragId+'"]'), t); saveReorderSections(); });

      function saveReorderSections(){
        var ids = Array.from(ul.querySelectorAll('li.item')).map(function(li){ return parseInt(li.dataset.id,10); });
        api('/crud.php?action=sections_reorder', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ level_id: lv.id, ids: ids })})
          .then(function(){ return api('/crud.php?action=tree'); })
          .then(function(d){ data=d; levels=d.levels||[]; renderSections(); if(currentSectionId){ var s=findSection(currentSectionId); if(s) renderLessons(s); }} )
          .catch(function(e){ alert('Ошибка: '+e.message); });
      }

      sectionsWrap.appendChild(ul);
    }

    function findSection(id){
      for (var i=0;i<levels.length;i++){ var ss = levels[i].sections||[]; for (var j=0;j<ss.length;j++){ if(ss[j].id===id) return ss[j]; } }
      return null;
    }

    function renderLessons(sec){
      lessonsWrap.innerHTML='';
      var head = el('div','head'); head.textContent = 'Уроки — '+sec.title_ru; lessonsWrap.appendChild(head);
      var addBtn = el('button','btn','➕ Добавить урок');
      addBtn.addEventListener('click', function(){ openLessonEditor({section_id: sec.id, title_ru:'', slug:'', lesson_order: null, is_published:false, content:{tests:[],tasks:[],theory_html:''}}, true); });
      lessonsWrap.appendChild(addBtn);

      var ul = el('ul','list'); ul.setAttribute('data-section', sec.id);
      (sec.lessons||[]).forEach(function(ls){
        var li = el('li','item'); li.draggable=true; li.dataset.id = ls.id;
        var pub = ls.is_published? '🟢' : '⚪';
        var a = el('a',null, pub+' '+ls.lesson_order+'. '+ls.title_ru);
        a.href='#'; a.addEventListener('click', function(ev){ ev.preventDefault(); openLessonEditor(ls,false); });
        var del = el('button','sm','🗑'); del.title='Удалить';
        del.addEventListener('click', function(){ if(!confirm('Удалить урок и его файлы?')) return; api('/crud.php?action=lesson_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:ls.id})}).then(function(){ return api('/crud.php?action=tree'); }).then(function(d){ data=d; var s=findSection(sec.id); if(s) renderLessons(s); }).catch(function(e){ alert('Ошибка: '+e.message); }); });
        li.appendChild(a); li.appendChild(del); ul.appendChild(li);
      });

      // Drag & Drop
      var dragId = null;
      ul.addEventListener('dragstart', function(e){ var t=e.target.closest('li.item'); if(!t) return; dragId=t.dataset.id; e.dataTransfer.effectAllowed='move'; });
      ul.addEventListener('dragover', function(e){ e.preventDefault(); });
      ul.addEventListener('drop', function(e){ e.preventDefault(); var t=e.target.closest('li.item'); if(!t||!dragId) return; if(t.dataset.id===dragId) return; ul.insertBefore(document.querySelector('li.item[data-id="'+dragId+'"]'), t); saveReorderLessons(); });

      function saveReorderLessons(){
        var ids = Array.from(ul.querySelectorAll('li.item')).map(function(li){ return parseInt(li.dataset.id,10); });
        api('/crud.php?action=lessons_reorder',{method:'POST',headers:{'Content-Type':'application/json'}, body: JSON.stringify({ section_id: sec.id, ids: ids })})
          .then(function(){ return api('/crud.php?action=tree'); })
          .then(function(d){ data=d; var s=findSection(sec.id); if(s) renderLessons(s); })
          .catch(function(e){ alert('Ошибка: '+e.message); });
      }

      lessonsWrap.appendChild(ul);
    }

    selectLevel(0);
  }

  function openLessonEditor(ls, isNew){
    var dlg = document.createElement('div'); dlg.className='modal';
    var box = document.createElement('div'); box.className='modal-box'; dlg.appendChild(box);
    var title = document.createElement('h3'); title.textContent = (isNew?'Новый урок':'Редактировать урок'); box.appendChild(title);

    var f = document.createElement('form'); f.className='form';
    var inTitle = document.createElement('input'); inTitle.placeholder='Название (рус)'; inTitle.value = ls.title_ru||''; f.appendChild(inTitle);
    var inSlug = document.createElement('input'); inSlug.placeholder='slug (a-z и -)'; inSlug.value = ls.slug||''; f.appendChild(inSlug);
    var inPub = document.createElement('label'); var cb=document.createElement('input'); cb.type='checkbox'; cb.checked=!!ls.is_published; inPub.appendChild(cb); inPub.appendChild(document.createTextNode(' Опубликован')); f.appendChild(inPub);
    var taTheory = document.createElement('textarea'); taTheory.placeholder='Теория (HTML)'; taTheory.value = (ls.content&&ls.content.theory_html)||''; f.appendChild(taTheory);
    var taTests = document.createElement('textarea'); taTests.placeholder='Тесты (JSON массив объектов)'; taTests.value = JSON.stringify((ls.content&&ls.content.tests)||[], null, 2); f.appendChild(taTests);
    var taTasks = document.createElement('textarea'); taTasks.placeholder='Задачи (JSON массив объектов)'; taTasks.value = JSON.stringify((ls.content&&ls.content.tasks)||[], null, 2); f.appendChild(taTasks);

    // Статусы рядом с кнопками, автоисчезновение 5 сек
    var row = document.createElement('div'); row.className='row';
    var btnSave = document.createElement('button'); btnSave.type='button'; btnSave.textContent='💾 Сохранить черновик';
    var status1 = document.createElement('span'); status1.className='status';
    var btnPub = document.createElement('button'); btnPub.type='button'; btnPub.textContent='📢 Опубликовать';
    var status2 = document.createElement('span'); status2.className='status';
    row.appendChild(btnSave); row.appendChild(status1); row.appendChild(btnPub); row.appendChild(status2);
    f.appendChild(row);

    box.appendChild(f);
    document.body.appendChild(dlg);

    function flash(stEl, text){ stEl.textContent = '✓ '+text; setTimeout(function(){ stEl.textContent=''; }, 5000); }

    // CKEditor 5 (локально из каталога /ckeditor)
    var ckeEditor = null;
    function loadScript(src, cb){ var s=document.createElement('script'); s.src=src; s.onload=cb; s.onerror=function(){ cb(new Error('Fail '+src)); }; document.head.appendChild(s); }
    function getClassicCtor(){ return (window.ClassicEditor) || (window.CKEDITOR && window.CKEDITOR.ClassicEditor) || null; }
    function ensureCKE(cb){
      if (getClassicCtor()) return cb();
      // Пробуем стандартные пути распаковки
      loadScript('/ckeditor/ckeditor5/ckeditor5.umd.js', function(errU){
        if (!errU && getClassicCtor()) return cb();
        loadScript('/ckeditor/ckeditor5/ckeditor5.js', function(errX){
          if (!errX && getClassicCtor()) return cb();
          loadScript('/ckeditor/ckeditor5/ckeditor.js', function(err0){
            if (!err0 && getClassicCtor()) return cb();
            loadScript('/ckeditor/ckeditor.js', function(err){
              if (!err && getClassicCtor()) return cb();
              loadScript('/ckeditor/build/ckeditor.js', function(err2){
                if (!err2 && getClassicCtor()) return cb();
                // Относительные пути (если каталог ckeditor рядом с корнем приложения)
                loadScript('ckeditor/ckeditor5/ckeditor5.umd.js', function(errUr){
                  if (!errUr && getClassicCtor()) return cb();
                  loadScript('ckeditor/ckeditor5/ckeditor5.js', function(errXr){
                    if (!errXr && getClassicCtor()) return cb();
                    loadScript('ckeditor/ckeditor5/ckeditor.js', function(err3){
                      if (!err3 && getClassicCtor()) return cb();
                      loadScript('ckeditor/build/ckeditor.js', function(err4){
                        if (!getClassicCtor()) console.warn('CKEditor: не найден глобальный ClassicEditor. Используйте UMD Classic build (ckeditor/build/ckeditor.js) или укажите корректный путь.');
                        cb();
                      });
                    });
                  });
                });
              });
            });
          });
        });
      });
    }
    function UploadAdapter(loader){ this.loader = loader; }
    UploadAdapter.prototype.upload = function(){
      var that = this;
      return this.loader.file.then(function(file){
        return new Promise(function(resolve, reject){
          var form = new FormData();
          form.append('file', file);
          form.append('lesson_id', ls.id ? String(ls.id) : '0');
          fetch('/crud.php?action=upload_image', { method:'POST', body: form })
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(function(json){ if(json && json.url){ resolve({ default: json.url }); } else { reject('Некорректный ответ сервера'); } })
            .catch(function(e){ reject(e); });
        });
      });
    };
    UploadAdapter.prototype.abort = function(){};

    ensureCKE(function(){
      var Ctor = getClassicCtor();
      if (!Ctor) return;
      Ctor.create(taTheory, {})
        .then(function(ed){
          ckeEditor = ed;
          // Кастомный адаптер загрузки
          ed.plugins.get('FileRepository').createUploadAdapter = function(loader){ return new UploadAdapter(loader); };
        })
        .catch(function(e){ console.warn('CKE init error', e); });
    });

    // --- Конструктор тестов и задач (гибрид) ---
    var testsBuilderWrap = document.createElement('div'); testsBuilderWrap.className = 'builder tests-builder';
    var tasksBuilderWrap = document.createElement('div'); tasksBuilderWrap.className = 'builder tasks-builder';

    // Кнопки переключения между textarea и конструктором
    var toggles = document.createElement('div'); toggles.className = 'row';
    var btnTestsBuilder = document.createElement('button'); btnTestsBuilder.type='button'; btnTestsBuilder.textContent='🧩 Конструктор тестов';
    var btnTasksBuilder = document.createElement('button'); btnTasksBuilder.type='button'; btnTasksBuilder.textContent='🧩 Конструктор задач';
    toggles.appendChild(btnTestsBuilder); toggles.appendChild(btnTasksBuilder); f.insertBefore(toggles, row);

    // Стили минимальные (встраиваемые)
    var style = document.createElement('style'); style.textContent = `
      .builder{ margin: 12px 0; padding: 10px; border:1px dashed #bbb; border-radius:8px;}
      .builder h4{ margin: 6px 0 10px; }
      .builder .item{ border:1px solid #ddd; padding:10px; border-radius:8px; margin-bottom:10px; }
      .builder .row{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
      .builder input[type="text"]{ width:100%; padding:6px; }
      .answers-list{ display:grid; gap:6px; }
      .answers-list .answer-row{ display:flex; gap:6px; align-items:center; }
      .answers-list .answer-row input[type="text"]{ flex:1; }
      .builder .btn-small{ font-size:12px; padding:4px 8px; }
    `; document.head.appendChild(style);

    // Хранилища инстансов редакторов для динамических элементов
    var testsEditors = []; // [{qid, editor}]
    var tasksEditors = []; // [{tid, editor}]

    function destroyEditors(arr){
      (arr||[]).forEach(function(rec){ if(rec && rec.editor){ try{ rec.editor.destroy(); }catch(e){} } });
      arr.length = 0;
    }

    function uid(){ return Math.random().toString(36).slice(2,9); }

    // --- Конструктор тестов ---
    function buildTestsUI(){
      testsBuilderWrap.innerHTML = '';
      var h = document.createElement('h4'); h.textContent = 'Тестовые вопросы'; testsBuilderWrap.appendChild(h);
      var list = document.createElement('div'); testsBuilderWrap.appendChild(list);
      var addBtn = document.createElement('button'); addBtn.type='button'; addBtn.className='btn-small'; addBtn.textContent='+ Добавить вопрос'; testsBuilderWrap.appendChild(addBtn);

      function addQuestion(q){
        var qid = uid();
        var item = document.createElement('div'); item.className='item'; item.dataset.qid = qid;
        // Заголовок вопроса (rich)
        var qLabel = document.createElement('div'); qLabel.textContent = 'Текст вопроса:'; item.appendChild(qLabel);
        var qArea = document.createElement('div'); qArea.setAttribute('contenteditable','true'); qArea.style.minHeight='80px'; qArea.style.border='1px solid #ccc'; qArea.style.padding='6px'; item.appendChild(qArea);
        // Ответы
        var answersWrap = document.createElement('div'); answersWrap.className='answers-list'; item.appendChild(answersWrap);
        var ansLabel = document.createElement('div'); ansLabel.textContent='Ответы (выберите правильный):'; item.insertBefore(ansLabel, answersWrap);

        function addAnswerRow(val, idx, correctIdx){
          var row = document.createElement('div'); row.className='answer-row';
          var rb = document.createElement('input'); rb.type='radio'; rb.name='correct-'+qid; rb.value=String(idx);
          if (typeof correctIdx==='number' && correctIdx===idx) rb.checked = true;
          var inp = document.createElement('input'); inp.type='text'; inp.placeholder='Ответ'; inp.value = val||'';
          var del = document.createElement('button'); del.type='button'; del.className='btn-small'; del.textContent='Удалить';
          del.addEventListener('click', function(){ row.remove(); renumberAnswers(); });
          row.appendChild(rb); row.appendChild(inp); row.appendChild(del); answersWrap.appendChild(row);
        }

        function renumberAnswers(){
          // Обновлять name не требуется, только индексы в value радио при синхронизации пересчитаем
        }

        var addAnsBtn = document.createElement('button'); addAnsBtn.type='button'; addAnsBtn.className='btn-small'; addAnsBtn.textContent='+ Ответ';
        addAnsBtn.addEventListener('click', function(){ addAnswerRow('', answersWrap.children.length, null); });
        item.appendChild(addAnsBtn);

        // Кнопки управления вопросом
        var tools = document.createElement('div'); tools.className='row';
        var delQ = document.createElement('button'); delQ.type='button'; delQ.className='btn-small'; delQ.textContent='Удалить вопрос';
        delQ.addEventListener('click', function(){
          // удалить редактор
          var recIdx = testsEditors.findIndex(function(r){ return r.qid===qid; });
          if(recIdx>=0){ try{ testsEditors[recIdx].editor.destroy(); }catch(e){} testsEditors.splice(recIdx,1); }
          item.remove();
        });
        tools.appendChild(delQ); item.appendChild(tools);

        list.appendChild(item);

        // Инициализируем редактор для вопроса (с учётом асинх. загрузки CKE)
        var Ctor = getClassicCtor();
        function initQ(){
          var C = getClassicCtor();
          if(!C){ console.warn('CKE not ready for tests'); return; }
          C.create(qArea, {})
            .then(function(ed){
              testsEditors.push({qid: qid, editor: ed});
              ed.plugins.get('FileRepository').createUploadAdapter = function(loader){ return new UploadAdapter(loader); };
              if (q && q.question_html){ ed.setData(q.question_html); }
              else if (q && q.question){ ed.setData(q.question); }
            })
            .catch(function(e){ console.warn('CKE tests init error', e); });
        }
        if (Ctor) initQ(); else ensureCKE(initQ);

        // Предзаполним ответы
        var answers = (q && Array.isArray(q.answers)) ? q.answers : ['',''];
        var corr = (q && typeof q.correctIndex==='number') ? q.correctIndex : -1;
        answers.forEach(function(a,i){ addAnswerRow(a, i, corr); });
      }

      addBtn.addEventListener('click', function(){ addQuestion({answers:['',''], correctIndex:-1}); });

      // Заполняем из текущего JSON
      var currentTests = [];
      try { currentTests = JSON.parse(taTests.value||'[]'); } catch(e){ currentTests = []; }
      (currentTests||[]).forEach(addQuestion);

      return { list: list };
    }

    function testsToJSON(){
      var arr = [];
      testsBuilderWrap.querySelectorAll('.item').forEach(function(item){
        var qid = item.dataset.qid;
        var rec = testsEditors.find(function(r){ return r.qid===qid; });
        var html = rec && rec.editor ? rec.editor.getData() : '';
        var answers = [];
        var correctIndex = -1;
        var rows = item.querySelectorAll('.answers-list .answer-row');
        rows.forEach(function(row, idx){
          var inp = row.querySelector('input[type="text"]');
          var rb = row.querySelector('input[type="radio"]');
          answers.push((inp&&inp.value)||'');
          if (rb && rb.checked) correctIndex = idx;
        });
        arr.push({ question_html: html, answers: answers, correctIndex: correctIndex });
      });
      return arr;
    }

    // --- Конструктор задач ---
    function buildTasksUI(){
      tasksBuilderWrap.innerHTML = '';
      var h = document.createElement('h4'); h.textContent = 'Задачи'; tasksBuilderWrap.appendChild(h);
      var list = document.createElement('div'); tasksBuilderWrap.appendChild(list);
      var addBtn = document.createElement('button'); addBtn.type='button'; addBtn.className='btn-small'; addBtn.textContent='+ Добавить задачу'; tasksBuilderWrap.appendChild(addBtn);

      function addTask(t){
        var tid = uid();
        var item = document.createElement('div'); item.className='item'; item.dataset.tid = tid;
        var titleIn = document.createElement('input'); titleIn.type='text'; titleIn.placeholder='Заголовок'; titleIn.value = (t&&t.title)||''; item.appendChild(titleIn);
        var bodyLabel = document.createElement('div'); bodyLabel.textContent='Текст задачи:'; item.appendChild(bodyLabel);
        var body = document.createElement('div'); body.setAttribute('contenteditable','true'); body.style.minHeight='100px'; body.style.border='1px solid #ccc'; body.style.padding='6px'; item.appendChild(body);

        var tools = document.createElement('div'); tools.className='row';
        var delT = document.createElement('button'); delT.type='button'; delT.className='btn-small'; delT.textContent='Удалить задачу';
        delT.addEventListener('click', function(){
          var recIdx = tasksEditors.findIndex(function(r){ return r.tid===tid; });
          if(recIdx>=0){ try{ tasksEditors[recIdx].editor.destroy(); }catch(e){} tasksEditors.splice(recIdx,1); }
          item.remove();
        });
        tools.appendChild(delT); item.appendChild(tools);

        list.appendChild(item);

        var Ctor = getClassicCtor();
        function initT(){
          var C = getClassicCtor();
          if(!C){ console.warn('CKE not ready for tasks'); return; }
          C.create(body, {})
            .then(function(ed){
              tasksEditors.push({tid: tid, editor: ed, titleIn: titleIn});
              ed.plugins.get('FileRepository').createUploadAdapter = function(loader){ return new UploadAdapter(loader); };
              if (t && t.text_html){ ed.setData(t.text_html); }
            })
            .catch(function(e){ console.warn('CKE tasks init error', e); });
        }
        if (Ctor) initT(); else ensureCKE(initT);
      }

      addBtn.addEventListener('click', function(){ addTask({title:'', text_html:''}); });

      var currentTasks = [];
      try { currentTasks = JSON.parse(taTasks.value||'[]'); } catch(e){ currentTasks = []; }
      (currentTasks||[]).forEach(addTask);

      return { list: list };
    }

    function tasksToJSON(){
      return Array.from(tasksBuilderWrap.querySelectorAll('.item')).map(function(item){
        var tid = item.dataset.tid;
        var rec = tasksEditors.find(function(r){ return r.tid===tid; });
        return { title: rec && rec.titleIn ? rec.titleIn.value : '', text_html: rec && rec.editor ? rec.editor.getData() : '' };
      });
    }

    // Обработчики кнопок включения конструкторов
    btnTestsBuilder.addEventListener('click', function(){
      // Скрыть textarea, показать конструктор
      taTests.style.display='none';
      if (!testsBuilderWrap.parentNode) f.insertBefore(testsBuilderWrap, row);
      destroyEditors(testsEditors);
      buildTestsUI();
    });
    btnTasksBuilder.addEventListener('click', function(){
      taTasks.style.display='none';
      if (!tasksBuilderWrap.parentNode) f.insertBefore(tasksBuilderWrap, row);
      destroyEditors(tasksEditors);
      buildTasksUI();
    });

    function syncBuildersToTextareas(){
      if (testsBuilderWrap.parentNode){ taTests.value = JSON.stringify(testsToJSON(), null, 2); }
      if (tasksBuilderWrap.parentNode){ taTasks.value = JSON.stringify(tasksToJSON(), null, 2); }
    }

    function send(isPublished){
      // Перед отправкой синхронизируем данные из конструкторов
      syncBuildersToTextareas();
      var payload = {
        id: ls.id||null,
        section_id: ls.section_id,
        title_ru: inTitle.value.trim(),
        slug: inSlug.value.trim(),
        is_published: !!isPublished,
        content: {
          tests: JSON.parse(taTests.value||'[]'),
          tasks: JSON.parse(taTasks.value||'[]'),
          theory_html: (ckeEditor? ckeEditor.getData() : (taTheory.value||''))
        }
      };
      return api('/crud.php?action=lesson_save', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    }

    btnSave.addEventListener('click', function(){ send(false).then(function(){ flash(status1,'Сохранено'); }).catch(function(e){ alert('Ошибка: '+e.message); }); });
    btnPub.addEventListener('click', function(){ send(true).then(function(){ flash(status2,'Опубликовано'); }).catch(function(e){ alert('Ошибка: '+e.message); }); });

    dlg.addEventListener('click', function(e){ if(e.target===dlg) dlg.remove(); });
  }

  // Инициализация
  mountLogin();
})();
<?php
    return ob_get_clean();
}

// На прямой вызов файла без action — ничего не рендерим
// Все функции используются из index.php

// ================== Вспомогательные общие функции API/CRUD ==================

function json_response($data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function validate_slug(string $slug): bool {
    return (bool)preg_match('~^[a-z-]+$~', $slug);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

// ================== TREE для админки ==================
function api_admin_tree(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    // уровни
    $levels = db_get_levels();
    foreach ($levels as &$lv) {
        $secs = db_get_sections_by_level_id((int)$lv['id']);
        foreach ($secs as &$sec) {
            $lsStmt = db()->prepare('SELECT id, section_id, title_ru, slug, lesson_order, is_published, content FROM lessons WHERE section_id=? ORDER BY lesson_order ASC');
            $lsStmt->execute([(int)$sec['id']]);
            $sec['lessons'] = $lsStmt->fetchAll();
            foreach ($sec['lessons'] as &$ls) { $ls['content'] = json_decode($ls['content'], true); }
        }
        $lv['sections'] = $secs;
    }
    json_response(['levels'=>$levels]);
}

// ================== SECTIONS: save/delete/reorder ==================
function api_section_save(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['error'=>'method']); return; }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $level_id = (int)($data['level_id'] ?? 0);
    $title_ru = trim((string)($data['title_ru'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
    $order = $data['section_order'] ?? null; $order = ($order===null? null : (int)$order);

    if ($level_id<=0 || $title_ru==='') { http_response_code(400); json_response(['error'=>'level_id и title_ru обязательны']); return; }
    if (!validate_slug($slug)) { http_response_code(400); json_response(['error'=>'Неверный slug']); return; }

    // Проверка уникальности slug в уровне
    $q = db()->prepare('SELECT id FROM sections WHERE level_id=? AND slug=?' . ($id? ' AND id<>?' : ''));
    $params = [$level_id, $slug]; if ($id) $params[] = $id; $q->execute($params);
    if ($q->fetch()) { http_response_code(400); json_response(['error'=>'Такой slug уже есть в уровне']); return; }

    if ($id) {
        // update
        if ($order===null) {
            // оставить прежний order
            $cur = db()->prepare('SELECT section_order FROM sections WHERE id=?'); $cur->execute([$id]); $order = (int)($cur->fetch()['section_order'] ?? 1);
        }
        // проверка уникальности order
        $qo = db()->prepare('SELECT id FROM sections WHERE level_id=? AND section_order=? AND id<>?'); $qo->execute([$level_id, $order, $id]);
        if ($qo->fetch()) { http_response_code(400); json_response(['error'=>'Такой порядковый номер уже занят']); return; }
        $st = db()->prepare('UPDATE sections SET level_id=?, title_ru=?, slug=?, section_order=? WHERE id=?');
        $st->execute([$level_id, $title_ru, $slug, $order, $id]);
        json_response(['ok'=>true, 'id'=>$id]);
    } else {
        if ($order===null) {
            $mx = db()->prepare('SELECT COALESCE(MAX(section_order),0) m FROM sections WHERE level_id=?'); $mx->execute([$level_id]); $order = ((int)$mx->fetch()['m']) + 1;
        } else {
            $qo = db()->prepare('SELECT id FROM sections WHERE level_id=? AND section_order=?'); $qo->execute([$level_id, $order]); if ($qo->fetch()) { http_response_code(400); json_response(['error'=>'Такой порядковый номер уже занят']); return; }
        }
        $st = db()->prepare('INSERT INTO sections(level_id,title_ru,slug,section_order) VALUES (?,?,?,?)');
        $st->execute([$level_id, $title_ru, $slug, $order]);
        json_response(['ok'=>true, 'id'=> (int)db()->lastInsertId() ]);
    }
}

function api_section_delete(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['error'=>'method']); return; }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if ($id<=0) { http_response_code(400); json_response(['error'=>'id required']); return; }
    // удалить папки уроков этого раздела
    $ls = db()->prepare('SELECT id FROM lessons WHERE section_id=?'); $ls->execute([$id]);
    foreach ($ls->fetchAll() as $row) { rrmdir(__DIR__ . '/images/lesson_' . (int)$row['id']); }
    // удалить раздел (каскад удалит уроки)
    $st = db()->prepare('DELETE FROM sections WHERE id=?'); $st->execute([$id]);
    json_response(['ok'=>true]);
}

function api_sections_reorder(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['error'=>'method']); return; }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $level_id = (int)($data['level_id'] ?? 0);
    $ids = $data['ids'] ?? [];
    if ($level_id<=0 || !is_array($ids) || empty($ids)) { http_response_code(400); json_response(['error'=>'params']); return; }
    db()->beginTransaction();
    try{
        $ord=1; $st = db()->prepare('UPDATE sections SET section_order=? WHERE id=? AND level_id=?');
        foreach ($ids as $id) { $st->execute([$ord++, (int)$id, $level_id]); }
        db()->commit();
        json_response(['ok'=>true]);
    }catch(Throwable $e){ db()->rollBack(); http_response_code(500); json_response(['error'=>$e->getMessage()]); }
}

// ================== LESSONS: save/delete/reorder ==================
function api_lesson_save(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['error'=>'method']); return; }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $section_id = (int)($data['section_id'] ?? 0);
    $title_ru = trim((string)($data['title_ru'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
    $is_published = !empty($data['is_published']) ? 1 : 0;
    $content = $data['content'] ?? ['tests'=>[],'tasks'=>[],'theory_html'=>''];

    if ($section_id<=0 || $title_ru==='') { http_response_code(400); json_response(['error'=>'section_id и title_ru обязательны']); return; }
    if (!validate_slug($slug)) { http_response_code(400); json_response(['error'=>'Неверный slug']); return; }
    // нормализуем контент
    $norm = [ 'tests'=> (array)($content['tests'] ?? []), 'tasks'=> (array)($content['tasks'] ?? []), 'theory_html'=> (string)($content['theory_html'] ?? '') ];
    $json = json_encode($norm, JSON_UNESCAPED_UNICODE);

    if ($id) {
        // проверить уникальность slug в разделе
        $q = db()->prepare('SELECT id FROM lessons WHERE section_id=? AND slug=? AND id<>?'); $q->execute([$section_id, $slug, $id]); if ($q->fetch()) { http_response_code(400); json_response(['error'=>'Такой slug уже есть в разделе']); return; }
        $st = db()->prepare('UPDATE lessons SET section_id=?, title_ru=?, slug=?, content=?, is_published=? WHERE id=?');
        $st->execute([$section_id, $title_ru, $slug, $json, $is_published, $id]);
        json_response(['ok'=>true, 'id'=>$id]);
    } else {
        // определить порядок: следующий
        $mx = db()->prepare('SELECT COALESCE(MAX(lesson_order),0) m FROM lessons WHERE section_id=?'); $mx->execute([$section_id]); $order = ((int)$mx->fetch()['m']) + 1;
        // проверка уникальности slug в разделе
        $q = db()->prepare('SELECT id FROM lessons WHERE section_id=? AND slug=?'); $q->execute([$section_id, $slug]); if ($q->fetch()) { http_response_code(400); json_response(['error'=>'Такой slug уже есть в разделе']); return; }
        $st = db()->prepare('INSERT INTO lessons(section_id,title_ru,slug,lesson_order,content,is_published) VALUES (?,?,?,?,?,?)');
        $st->execute([$section_id, $title_ru, $slug, $order, $json, $is_published]);
        json_response(['ok'=>true, 'id'=> (int)db()->lastInsertId() ]);
    }
}

function api_lesson_delete(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['error'=>'method']); return; }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if ($id<=0) { http_response_code(400); json_response(['error'=>'id required']); return; }
    // удалить папку изображений
    rrmdir(__DIR__ . '/images/lesson_' . $id);
    $st = db()->prepare('DELETE FROM lessons WHERE id=?'); $st->execute([$id]);
    json_response(['ok'=>true]);
}

function api_lessons_reorder(): void {
    if (!is_admin_authenticated()) { http_response_code(401); json_response(['error'=>'Unauthorized']); return; }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); json_response(['error'=>'method']); return; }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $section_id = (int)($data['section_id'] ?? 0);
    $ids = $data['ids'] ?? [];
    if ($section_id<=0 || !is_array($ids) || empty($ids)) { http_response_code(400); json_response(['error'=>'params']); return; }
    db()->beginTransaction();
    try{
        $ord=1; $st = db()->prepare('UPDATE lessons SET lesson_order=? WHERE id=? AND section_id=?');
        foreach ($ids as $id) { $st->execute([$ord++, (int)$id, $section_id]); }
        db()->commit();
        json_response(['ok'=>true]);
    }catch(Throwable $e){ db()->rollBack(); http_response_code(500); json_response(['error'=>$e->getMessage()]); }
}
