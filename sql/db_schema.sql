-- db_schema.sql
-- Создание структуры таблиц для DOMLearn (utf8mb4 / utf8mb4_unicode_ci)
-- Внимание: база данных уже создана, скрипт не создаёт БД, только таблицы

SET NAMES utf8mb4;
/* Пропущено: SET SESSION sql_require_primary_key = 0; — не поддерживается на текущем хостинге */

-- Глобальные настройки соединения на всякий случай
SET collation_connection = 'utf8mb4_unicode_ci';

-- Таблица уровней (фиксированные 5 записей)
DROP TABLE IF EXISTS `lessons`;
DROP TABLE IF EXISTS `sections`;
DROP TABLE IF EXISTS `levels`;

CREATE TABLE `levels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` TINYINT UNSIGNED NOT NULL,
  `title_ru` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_levels_number` (`number`),
  UNIQUE KEY `uq_levels_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица разделов
CREATE TABLE `sections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level_id` INT UNSIGNED NOT NULL,
  `title_ru` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_order` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_level_slug` (`level_id`, `slug`),
  UNIQUE KEY `uq_sections_level_order` (`level_id`, `section_order`),
  KEY `fk_sections_level_id` (`level_id`),
  CONSTRAINT `fk_sections_level_id` FOREIGN KEY (`level_id`) REFERENCES `levels`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица уроков
CREATE TABLE `lessons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id` INT UNSIGNED NOT NULL,
  `title_ru` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lesson_order` INT UNSIGNED NOT NULL,
  `content` JSON NOT NULL,
  `is_published` BOOLEAN NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lessons_section_slug` (`section_id`, `slug`),
  UNIQUE KEY `uq_lessons_section_order` (`section_id`, `lesson_order`),
  KEY `fk_lessons_section_id` (`section_id`),
  CONSTRAINT `fk_lessons_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CHECK (JSON_VALID(`content`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
