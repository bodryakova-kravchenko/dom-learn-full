-- db_seed.sql
-- Тестовые данные: 1 уровень (Начало|start) -> 1 раздел (Введение|intro) -> 1 урок (Привет, DOM!|hello-dom)

SET NAMES utf8mb4;
SET collation_connection = 'utf8mb4_unicode_ci';

-- Уровни (фиксированные 5 записей)
INSERT INTO `levels` (`number`, `title_ru`, `slug`) VALUES
(1, 'Начало', 'start'),
(2, 'Основы', 'basics'),
(3, 'Углубление', 'deep'),
(4, 'Продвинутое', 'advanced'),
(5, 'Гуру', 'guru');

-- Раздел в уровне 1
INSERT INTO `sections` (`level_id`, `title_ru`, `slug`, `section_order`)
VALUES ((SELECT id FROM levels WHERE number=1), 'Введение', 'intro', 1);

-- Урок в разделе 1 уровня 1
INSERT INTO `lessons` (`section_id`, `title_ru`, `slug`, `lesson_order`, `content`, `is_published`)
VALUES (
  (SELECT s.id FROM sections s JOIN levels l ON l.id=s.level_id WHERE l.number=1 AND s.section_order=1),
  'Привет, DOM!',
  'hello-dom',
  1,
  JSON_OBJECT(
    'tests', JSON_ARRAY(
      JSON_OBJECT(
        'question', 'Что такое DOM?',
        'answers', JSON_ARRAY('Объектная модель документа', 'Язык программирования', 'СУБД', 'ОС'),
        'correctIndex', 0
      ),
      JSON_OBJECT(
        'question', 'Как получить элемент по id?',
        'answers', JSON_ARRAY('document.querySelector("#id")', 'document.getElementsByClassName("id")', 'window.getElementById("id")', 'document.getElementById("id")'),
        'correctIndex', 3
      ),
      JSON_OBJECT(
        'question', 'Что вернёт document.querySelector(".item")?',
        'answers', JSON_ARRAY('HTMLCollection', 'Первый подходящий элемент', 'NodeList всех подходящих', 'Массив элементов'),
        'correctIndex', 1
      )
    ),
    'tasks', JSON_ARRAY(
      JSON_OBJECT('title', 'Найдите элемент', 'text_html', '<p>Найдите элемент с id="app" и выведите его в консоль.</p>')
    ),
    'theory_html', '<h2>Введение в DOM</h2><p>DOM — это программный интерфейс для HTML/XML документов.</p>'
  ),
  1
);
