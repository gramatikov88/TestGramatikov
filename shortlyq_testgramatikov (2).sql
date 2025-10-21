-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Време на генериране: 21 окт 2025 в 07:46
-- Версия на сървъра: 10.11.14-MariaDB-cll-lve-log
-- Версия на PHP: 8.4.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данни: `shortlyq_testgramatikov`
--

-- --------------------------------------------------------

--
-- Структура на таблица `answers`
--

CREATE TABLE `answers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `question_id` bigint(20) UNSIGNED NOT NULL,
  `content` text NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `order_index` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `answers`
--

INSERT INTO `answers` (`id`, `question_id`, `content`, `is_correct`, `order_index`) VALUES
(60, 3, '1', 1, 1),
(61, 3, '2', 0, 2),
(62, 4, '1', 1, 1),
(63, 4, '2', 0, 2),
(87, 5, 'се притежава от организация, която продава или предоставя безплатно услуги на други потребители', 1, 1),
(88, 5, 'се споделя от няколко организации, които имат сходни цели и идеи', 0, 2),
(89, 5, 'е комбинация на два или повече облака, които се разграничават, независимо че ползват обща технология', 0, 3),
(90, 5, 'се притежава или се наема от една организация и се използва само от нея', 0, 4),
(91, 6, 'средство за съвместно използване на мощности и ресурси, работещи заедно за изпълнение на голямо количество задачи', 1, 1),
(92, 6, 'предлагане на услуги през интернет или други мрежи чрез система за хардуерни устройства, които се управляват чрез специален софтуер', 0, 2),
(93, 6, 'спътникови навигационни системи', 0, 3),
(94, 7, 'физическата част', 0, 1),
(95, 7, 'обществената част', 1, 2),
(96, 7, 'анализиращата част', 0, 3),
(97, 7, 'информационната част', 0, 4),
(98, 8, 'Петафлопс', 0, 1),
(99, 8, 'Йотафлопс', 1, 2),
(100, 8, 'Флопс', 0, 3),
(101, 8, 'Сетафлопс', 0, 4),
(102, 9, 'ДА', 1, 1),
(103, 9, 'НЕ', 0, 2),
(104, 10, 'ДА', 1, 1),
(105, 10, 'НЕ', 0, 2),
(106, 11, 'ДА', 1, 1),
(107, 11, 'НЕ', 0, 2),
(108, 12, 'ДА', 1, 1),
(109, 12, 'НЕ', 0, 2);

-- --------------------------------------------------------

--
-- Структура на таблица `assignments`
--

CREATE TABLE `assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `test_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_by_teacher_id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `open_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `close_at` datetime DEFAULT NULL,
  `attempt_limit` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `assignments`
--

INSERT INTO `assignments` (`id`, `test_id`, `assigned_by_teacher_id`, `title`, `description`, `is_published`, `open_at`, `due_at`, `close_at`, `attempt_limit`, `shuffle_questions`, `created_at`) VALUES
(17, 9, 4, 'Тест: Грид и облачни технологии', '', 1, NULL, NULL, NULL, 1, 1, '2025-10-14 12:23:35'),
(18, 9, 4, 'За Никола', '', 0, NULL, NULL, NULL, 1, 0, '2025-10-14 13:28:32'),
(19, 9, 4, 'За Никола', '', 1, NULL, NULL, NULL, 1, 0, '2025-10-14 13:28:54');

-- --------------------------------------------------------

--
-- Структура на таблица `assignment_classes`
--

CREATE TABLE `assignment_classes` (
  `assignment_id` bigint(20) UNSIGNED NOT NULL,
  `class_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `assignment_classes`
--

INSERT INTO `assignment_classes` (`assignment_id`, `class_id`) VALUES
(16, 5),
(17, 7),
(18, 8),
(19, 8);

-- --------------------------------------------------------

--
-- Структура на таблица `assignment_students`
--

CREATE TABLE `assignment_students` (
  `assignment_id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `assignment_students`
--

INSERT INTO `assignment_students` (`assignment_id`, `student_id`) VALUES
(16, 1),
(18, 66),
(19, 66);

-- --------------------------------------------------------

--
-- Структура на таблица `attempts`
--

CREATE TABLE `attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assignment_id` bigint(20) UNSIGNED NOT NULL,
  `test_id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `attempt_no` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `status` enum('in_progress','submitted','graded','expired') NOT NULL DEFAULT 'in_progress',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `duration_sec` int(10) UNSIGNED DEFAULT NULL,
  `score_obtained` decimal(8,2) DEFAULT NULL,
  `max_score` decimal(8,2) DEFAULT NULL,
  `teacher_grade` tinyint(4) DEFAULT NULL,
  `strict_violation` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `attempts`
--

INSERT INTO `attempts` (`id`, `assignment_id`, `test_id`, `student_id`, `attempt_no`, `status`, `started_at`, `submitted_at`, `duration_sec`, `score_obtained`, `max_score`, `teacher_grade`, `strict_violation`) VALUES
(40, 16, 8, 1, 1, 'submitted', '2025-10-14 11:14:55', '2025-10-14 11:14:55', NULL, 1.00, 1.00, NULL, 0),
(41, 16, 8, 1, 2, 'submitted', '2025-10-14 11:28:12', '2025-10-14 11:28:12', NULL, 0.00, 1.00, NULL, 0),
(42, 17, 9, 45, 1, 'submitted', '2025-10-14 12:27:24', '2025-10-14 12:27:24', NULL, 6.00, 8.00, NULL, 0),
(43, 17, 9, 38, 1, 'submitted', '2025-10-14 12:27:38', '2025-10-14 12:27:38', NULL, 7.00, 8.00, 2, 0),
(44, 17, 9, 45, 2, 'submitted', '2025-10-14 12:27:41', '2025-10-14 12:27:41', NULL, 0.00, 8.00, NULL, 0),
(45, 17, 9, 41, 1, 'submitted', '2025-10-14 12:28:04', '2025-10-14 12:28:04', NULL, 6.00, 8.00, NULL, 0),
(46, 17, 9, 41, 2, 'submitted', '2025-10-14 12:28:46', '2025-10-14 12:28:46', NULL, 6.00, 8.00, NULL, 0),
(47, 17, 9, 33, 1, 'submitted', '2025-10-14 12:28:57', '2025-10-14 12:28:57', NULL, 6.00, 8.00, NULL, 0),
(48, 17, 9, 24, 1, 'submitted', '2025-10-14 12:29:10', '2025-10-14 12:29:10', NULL, 8.00, 8.00, NULL, 0),
(49, 17, 9, 48, 1, 'submitted', '2025-10-14 12:29:12', '2025-10-14 12:29:12', NULL, 5.00, 8.00, NULL, 0),
(50, 17, 9, 38, 2, 'submitted', '2025-10-14 12:29:24', '2025-10-14 12:29:24', NULL, 7.00, 8.00, NULL, 0),
(51, 17, 9, 38, 3, 'submitted', '2025-10-14 12:29:27', '2025-10-14 12:29:27', NULL, 7.00, 8.00, NULL, 0),
(52, 17, 9, 39, 1, 'submitted', '2025-10-14 12:30:06', '2025-10-14 12:30:06', NULL, 5.00, 8.00, NULL, 0),
(53, 17, 9, 18, 1, 'submitted', '2025-10-14 12:30:13', '2025-10-14 12:30:13', NULL, 6.00, 8.00, NULL, 0),
(54, 17, 9, 39, 2, 'submitted', '2025-10-14 12:30:15', '2025-10-14 12:30:16', NULL, 0.00, 8.00, NULL, 0),
(55, 17, 9, 25, 1, 'submitted', '2025-10-14 12:30:17', '2025-10-14 12:30:17', NULL, 6.00, 8.00, NULL, 0),
(56, 17, 9, 20, 1, 'submitted', '2025-10-14 12:30:31', '2025-10-14 12:30:31', NULL, 7.00, 8.00, NULL, 0),
(57, 17, 9, 27, 1, 'submitted', '2025-10-14 12:30:36', '2025-10-14 12:30:36', NULL, 8.00, 8.00, NULL, 0),
(58, 17, 9, 32, 1, 'submitted', '2025-10-14 12:30:39', '2025-10-14 12:30:39', NULL, 8.00, 8.00, NULL, 0),
(59, 17, 9, 48, 2, 'submitted', '2025-10-14 12:30:54', '2025-10-14 12:30:54', NULL, 7.00, 8.00, NULL, 0),
(60, 17, 9, 19, 1, 'submitted', '2025-10-14 12:31:53', '2025-10-14 12:31:53', NULL, 7.00, 8.00, NULL, 0),
(61, 17, 9, 33, 2, 'submitted', '2025-10-14 12:31:54', '2025-10-14 12:31:54', NULL, 0.00, 8.00, NULL, 0),
(62, 17, 9, 33, 3, 'submitted', '2025-10-14 12:31:59', '2025-10-14 12:31:59', NULL, 0.00, 8.00, NULL, 0),
(63, 17, 9, 22, 1, 'submitted', '2025-10-14 12:31:59', '2025-10-14 12:31:59', NULL, 5.00, 8.00, NULL, 0),
(64, 17, 9, 21, 1, 'submitted', '2025-10-14 12:32:00', '2025-10-14 12:32:00', NULL, 5.00, 8.00, NULL, 0),
(65, 17, 9, 33, 4, 'submitted', '2025-10-14 12:32:03', '2025-10-14 12:32:03', NULL, 0.00, 8.00, NULL, 0),
(66, 17, 9, 33, 5, 'submitted', '2025-10-14 12:32:05', '2025-10-14 12:32:05', NULL, 0.00, 8.00, NULL, 0),
(67, 17, 9, 33, 6, 'submitted', '2025-10-14 12:32:08', '2025-10-14 12:32:08', NULL, 0.00, 8.00, NULL, 0),
(68, 17, 9, 33, 7, 'submitted', '2025-10-14 12:32:11', '2025-10-14 12:32:11', NULL, 0.00, 8.00, NULL, 0),
(69, 17, 9, 33, 8, 'submitted', '2025-10-14 12:32:14', '2025-10-14 12:32:14', NULL, 0.00, 8.00, NULL, 0),
(70, 17, 9, 33, 9, 'submitted', '2025-10-14 12:32:17', '2025-10-14 12:32:17', NULL, 0.00, 8.00, NULL, 0),
(71, 17, 9, 33, 10, 'submitted', '2025-10-14 12:32:22', '2025-10-14 12:32:22', NULL, 0.00, 8.00, NULL, 0),
(72, 17, 9, 33, 11, 'submitted', '2025-10-14 12:32:25', '2025-10-14 12:32:25', NULL, 0.00, 8.00, NULL, 0),
(73, 17, 9, 33, 12, 'submitted', '2025-10-14 12:32:28', '2025-10-14 12:32:28', NULL, 0.00, 8.00, NULL, 0),
(74, 17, 9, 46, 1, 'submitted', '2025-10-14 12:32:32', '2025-10-14 12:32:32', NULL, 6.00, 8.00, NULL, 0),
(75, 17, 9, 33, 13, 'submitted', '2025-10-14 12:32:33', '2025-10-14 12:32:33', NULL, 0.00, 8.00, NULL, 0),
(76, 17, 9, 33, 14, 'submitted', '2025-10-14 12:32:37', '2025-10-14 12:32:37', NULL, 0.00, 8.00, NULL, 0),
(77, 17, 9, 30, 1, 'submitted', '2025-10-14 12:32:50', '2025-10-14 12:32:50', NULL, 7.00, 8.00, NULL, 0),
(78, 17, 9, 31, 1, 'submitted', '2025-10-14 12:34:24', '2025-10-14 12:34:24', NULL, 7.00, 8.00, NULL, 0),
(79, 17, 9, 44, 1, 'submitted', '2025-10-14 12:34:31', '2025-10-14 12:34:31', NULL, 7.00, 8.00, NULL, 0),
(80, 17, 9, 26, 1, 'submitted', '2025-10-14 12:34:33', '2025-10-14 12:34:33', NULL, 6.00, 8.00, NULL, 0),
(81, 17, 9, 36, 1, 'submitted', '2025-10-14 12:34:34', '2025-10-14 12:34:34', NULL, 8.00, 8.00, NULL, 0),
(82, 17, 9, 23, 1, 'submitted', '2025-10-14 12:34:35', '2025-10-14 12:34:35', NULL, 8.00, 8.00, NULL, 0),
(83, 17, 9, 43, 1, 'submitted', '2025-10-14 12:34:39', '2025-10-14 12:34:39', NULL, 6.00, 8.00, NULL, 0),
(84, 17, 9, 40, 1, 'submitted', '2025-10-14 12:35:46', '2025-10-14 12:35:46', NULL, 8.00, 8.00, NULL, 0),
(85, 19, 9, 66, 1, 'submitted', '2025-10-14 13:31:15', '2025-10-14 13:31:15', NULL, 7.00, 8.00, 2, 0);

-- --------------------------------------------------------

--
-- Структура на таблица `attempt_answers`
--

CREATE TABLE `attempt_answers` (
  `attempt_id` bigint(20) UNSIGNED NOT NULL,
  `question_id` bigint(20) UNSIGNED NOT NULL,
  `selected_option_ids` text DEFAULT NULL,
  `free_text` text DEFAULT NULL,
  `numeric_value` decimal(12,4) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `score_awarded` decimal(6,2) DEFAULT NULL,
  `time_spent_sec` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `attempt_answers`
--

INSERT INTO `attempt_answers` (`attempt_id`, `question_id`, `selected_option_ids`, `free_text`, `numeric_value`, `is_correct`, `score_awarded`, `time_spent_sec`) VALUES
(40, 4, '62', NULL, NULL, 1, 1.00, NULL),
(41, 4, NULL, NULL, NULL, 0, 0.00, NULL),
(42, 8, '76', NULL, NULL, 1, 1.00, NULL),
(42, 10, '81', NULL, NULL, 1, 1.00, NULL),
(42, 6, '69', NULL, NULL, 0, 0.00, NULL),
(42, 9, '79', NULL, NULL, 1, 1.00, NULL),
(42, 11, '83', NULL, NULL, 1, 1.00, NULL),
(42, 5, '64', NULL, NULL, 1, 1.00, NULL),
(42, 7, '74', NULL, NULL, 0, 0.00, NULL),
(42, 12, '85', NULL, NULL, 1, 1.00, NULL),
(43, 5, '64', NULL, NULL, 1, 1.00, NULL),
(43, 11, '83', NULL, NULL, 1, 1.00, NULL),
(43, 12, '85', NULL, NULL, 1, 1.00, NULL),
(43, 7, '72', NULL, NULL, 1, 1.00, NULL),
(43, 6, '68', NULL, NULL, 1, 1.00, NULL),
(43, 8, '76', NULL, NULL, 1, 1.00, NULL),
(43, 10, '82', NULL, NULL, 0, 0.00, NULL),
(43, 9, '79', NULL, NULL, 1, 1.00, NULL),
(44, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(44, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(45, 5, '64', NULL, NULL, 1, 1.00, NULL),
(45, 8, '76', NULL, NULL, 1, 1.00, NULL),
(45, 6, '68', NULL, NULL, 1, 1.00, NULL),
(45, 11, '84', NULL, NULL, 0, 0.00, NULL),
(45, 9, '79', NULL, NULL, 1, 1.00, NULL),
(45, 7, '74', NULL, NULL, 0, 0.00, NULL),
(45, 12, '85', NULL, NULL, 1, 1.00, NULL),
(45, 10, '81', NULL, NULL, 1, 1.00, NULL),
(46, 9, '79', NULL, NULL, 1, 1.00, NULL),
(46, 8, '76', NULL, NULL, 1, 1.00, NULL),
(46, 12, '85', NULL, NULL, 1, 1.00, NULL),
(46, 6, '68', NULL, NULL, 1, 1.00, NULL),
(46, 7, '74', NULL, NULL, 0, 0.00, NULL),
(46, 5, '64', NULL, NULL, 1, 1.00, NULL),
(46, 11, '84', NULL, NULL, 0, 0.00, NULL),
(46, 10, '81', NULL, NULL, 1, 1.00, NULL),
(47, 5, '64', NULL, NULL, 1, 1.00, NULL),
(47, 12, '85', NULL, NULL, 1, 1.00, NULL),
(47, 8, '76', NULL, NULL, 1, 1.00, NULL),
(47, 9, '80', NULL, NULL, 0, 0.00, NULL),
(47, 10, '81', NULL, NULL, 1, 1.00, NULL),
(47, 6, '68', NULL, NULL, 1, 1.00, NULL),
(47, 7, '73', NULL, NULL, 0, 0.00, NULL),
(47, 11, '83', NULL, NULL, 1, 1.00, NULL),
(48, 11, '83', NULL, NULL, 1, 1.00, NULL),
(48, 9, '79', NULL, NULL, 1, 1.00, NULL),
(48, 8, '76', NULL, NULL, 1, 1.00, NULL),
(48, 5, '64', NULL, NULL, 1, 1.00, NULL),
(48, 7, '72', NULL, NULL, 1, 1.00, NULL),
(48, 10, '81', NULL, NULL, 1, 1.00, NULL),
(48, 6, '68', NULL, NULL, 1, 1.00, NULL),
(48, 12, '85', NULL, NULL, 1, 1.00, NULL),
(49, 9, '79', NULL, NULL, 1, 1.00, NULL),
(49, 10, '82', NULL, NULL, 0, 0.00, NULL),
(49, 8, '76', NULL, NULL, 1, 1.00, NULL),
(49, 5, '64', NULL, NULL, 1, 1.00, NULL),
(49, 7, '71', NULL, NULL, 0, 0.00, NULL),
(49, 6, '68', NULL, NULL, 1, 1.00, NULL),
(49, 12, '86', NULL, NULL, 0, 0.00, NULL),
(49, 11, '83', NULL, NULL, 1, 1.00, NULL),
(50, 7, '72', NULL, NULL, 1, 1.00, NULL),
(50, 6, '68', NULL, NULL, 1, 1.00, NULL),
(50, 12, '85', NULL, NULL, 1, 1.00, NULL),
(50, 11, '83', NULL, NULL, 1, 1.00, NULL),
(50, 10, '82', NULL, NULL, 0, 0.00, NULL),
(50, 8, '76', NULL, NULL, 1, 1.00, NULL),
(50, 5, '64', NULL, NULL, 1, 1.00, NULL),
(50, 9, '79', NULL, NULL, 1, 1.00, NULL),
(51, 11, '83', NULL, NULL, 1, 1.00, NULL),
(51, 10, '82', NULL, NULL, 0, 0.00, NULL),
(51, 8, '76', NULL, NULL, 1, 1.00, NULL),
(51, 7, '72', NULL, NULL, 1, 1.00, NULL),
(51, 9, '79', NULL, NULL, 1, 1.00, NULL),
(51, 12, '85', NULL, NULL, 1, 1.00, NULL),
(51, 5, '64', NULL, NULL, 1, 1.00, NULL),
(51, 6, '68', NULL, NULL, 1, 1.00, NULL),
(52, 10, '82', NULL, NULL, 0, 0.00, NULL),
(52, 5, '64', NULL, NULL, 1, 1.00, NULL),
(52, 12, '86', NULL, NULL, 0, 0.00, NULL),
(52, 8, '76', NULL, NULL, 1, 1.00, NULL),
(52, 11, '83', NULL, NULL, 1, 1.00, NULL),
(52, 7, '72', NULL, NULL, 1, 1.00, NULL),
(52, 9, '79', NULL, NULL, 1, 1.00, NULL),
(52, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(53, 7, '72', NULL, NULL, 1, 1.00, NULL),
(53, 5, '64', NULL, NULL, 1, 1.00, NULL),
(53, 10, '82', NULL, NULL, 0, 0.00, NULL),
(53, 11, '83', NULL, NULL, 1, 1.00, NULL),
(53, 9, '79', NULL, NULL, 1, 1.00, NULL),
(53, 8, '76', NULL, NULL, 1, 1.00, NULL),
(53, 12, '86', NULL, NULL, 0, 0.00, NULL),
(53, 6, '68', NULL, NULL, 1, 1.00, NULL),
(54, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(54, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(55, 11, '83', NULL, NULL, 1, 1.00, NULL),
(55, 10, '82', NULL, NULL, 0, 0.00, NULL),
(55, 6, '68', NULL, NULL, 1, 1.00, NULL),
(55, 9, '79', NULL, NULL, 1, 1.00, NULL),
(55, 7, '72', NULL, NULL, 1, 1.00, NULL),
(55, 8, '76', NULL, NULL, 1, 1.00, NULL),
(55, 5, '64', NULL, NULL, 1, 1.00, NULL),
(55, 12, '86', NULL, NULL, 0, 0.00, NULL),
(56, 12, '85', NULL, NULL, 1, 1.00, NULL),
(56, 11, '83', NULL, NULL, 1, 1.00, NULL),
(56, 8, '77', NULL, NULL, 0, 0.00, NULL),
(56, 7, '72', NULL, NULL, 1, 1.00, NULL),
(56, 6, '68', NULL, NULL, 1, 1.00, NULL),
(56, 5, '64', NULL, NULL, 1, 1.00, NULL),
(56, 10, '81', NULL, NULL, 1, 1.00, NULL),
(56, 9, '79', NULL, NULL, 1, 1.00, NULL),
(57, 5, '64', NULL, NULL, 1, 1.00, NULL),
(57, 7, '72', NULL, NULL, 1, 1.00, NULL),
(57, 9, '79', NULL, NULL, 1, 1.00, NULL),
(57, 10, '81', NULL, NULL, 1, 1.00, NULL),
(57, 6, '68', NULL, NULL, 1, 1.00, NULL),
(57, 12, '85', NULL, NULL, 1, 1.00, NULL),
(57, 11, '83', NULL, NULL, 1, 1.00, NULL),
(57, 8, '76', NULL, NULL, 1, 1.00, NULL),
(58, 11, '83', NULL, NULL, 1, 1.00, NULL),
(58, 7, '72', NULL, NULL, 1, 1.00, NULL),
(58, 6, '68', NULL, NULL, 1, 1.00, NULL),
(58, 8, '76', NULL, NULL, 1, 1.00, NULL),
(58, 10, '81', NULL, NULL, 1, 1.00, NULL),
(58, 5, '64', NULL, NULL, 1, 1.00, NULL),
(58, 9, '79', NULL, NULL, 1, 1.00, NULL),
(58, 12, '85', NULL, NULL, 1, 1.00, NULL),
(59, 7, '72', NULL, NULL, 1, 1.00, NULL),
(59, 6, '68', NULL, NULL, 1, 1.00, NULL),
(59, 12, '86', NULL, NULL, 0, 0.00, NULL),
(59, 11, '83', NULL, NULL, 1, 1.00, NULL),
(59, 9, '79', NULL, NULL, 1, 1.00, NULL),
(59, 5, '64', NULL, NULL, 1, 1.00, NULL),
(59, 10, '81', NULL, NULL, 1, 1.00, NULL),
(59, 8, '76', NULL, NULL, 1, 1.00, NULL),
(60, 7, '72', NULL, NULL, 1, 1.00, NULL),
(60, 10, '81', NULL, NULL, 1, 1.00, NULL),
(60, 11, '83', NULL, NULL, 1, 1.00, NULL),
(60, 6, '68', NULL, NULL, 1, 1.00, NULL),
(60, 5, '66', NULL, NULL, 0, 0.00, NULL),
(60, 8, '76', NULL, NULL, 1, 1.00, NULL),
(60, 9, '79', NULL, NULL, 1, 1.00, NULL),
(60, 12, '85', NULL, NULL, 1, 1.00, NULL),
(61, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(61, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(62, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(63, 7, '73', NULL, NULL, 0, 0.00, NULL),
(63, 9, '79', NULL, NULL, 1, 1.00, NULL),
(63, 12, '85', NULL, NULL, 1, 1.00, NULL),
(63, 8, '77', NULL, NULL, 0, 0.00, NULL),
(63, 11, '83', NULL, NULL, 1, 1.00, NULL),
(63, 5, '64', NULL, NULL, 1, 1.00, NULL),
(63, 10, '81', NULL, NULL, 1, 1.00, NULL),
(63, 6, '69', NULL, NULL, 0, 0.00, NULL),
(64, 10, '81', NULL, NULL, 1, 1.00, NULL),
(64, 9, '79', NULL, NULL, 1, 1.00, NULL),
(64, 6, '69', NULL, NULL, 0, 0.00, NULL),
(64, 12, '85', NULL, NULL, 1, 1.00, NULL),
(64, 11, '83', NULL, NULL, 1, 1.00, NULL),
(64, 7, '73', NULL, NULL, 0, 0.00, NULL),
(64, 8, '77', NULL, NULL, 0, 0.00, NULL),
(64, 5, '64', NULL, NULL, 1, 1.00, NULL),
(65, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(65, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(66, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(67, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(68, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(69, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(70, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(71, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(72, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(73, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(74, 9, '79', NULL, NULL, 1, 1.00, NULL),
(74, 12, '85', NULL, NULL, 1, 1.00, NULL),
(74, 8, '76', NULL, NULL, 1, 1.00, NULL),
(74, 6, '68', NULL, NULL, 1, 1.00, NULL),
(74, 7, '71', NULL, NULL, 0, 0.00, NULL),
(74, 11, '83', NULL, NULL, 1, 1.00, NULL),
(74, 10, '82', NULL, NULL, 0, 0.00, NULL),
(74, 5, '64', NULL, NULL, 1, 1.00, NULL),
(75, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(75, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 10, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 7, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 6, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 5, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 12, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 11, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 8, NULL, NULL, NULL, 0, 0.00, NULL),
(76, 9, NULL, NULL, NULL, 0, 0.00, NULL),
(77, 6, '68', NULL, NULL, 1, 1.00, NULL),
(77, 9, '79', NULL, NULL, 1, 1.00, NULL),
(77, 8, '76', NULL, NULL, 1, 1.00, NULL),
(77, 12, '85', NULL, NULL, 1, 1.00, NULL),
(77, 5, '64', NULL, NULL, 1, 1.00, NULL),
(77, 10, '81', NULL, NULL, 1, 1.00, NULL),
(77, 11, '83', NULL, NULL, 1, 1.00, NULL),
(77, 7, '73', NULL, NULL, 0, 0.00, NULL),
(78, 10, '81', NULL, NULL, 1, 1.00, NULL),
(78, 9, '79', NULL, NULL, 1, 1.00, NULL),
(78, 12, '85', NULL, NULL, 1, 1.00, NULL),
(78, 6, '68', NULL, NULL, 1, 1.00, NULL),
(78, 11, '83', NULL, NULL, 1, 1.00, NULL),
(78, 5, '64', NULL, NULL, 1, 1.00, NULL),
(78, 7, '73', NULL, NULL, 0, 0.00, NULL),
(78, 8, '76', NULL, NULL, 1, 1.00, NULL),
(79, 12, '85', NULL, NULL, 1, 1.00, NULL),
(79, 8, '76', NULL, NULL, 1, 1.00, NULL),
(79, 9, '79', NULL, NULL, 1, 1.00, NULL),
(79, 7, '73', NULL, NULL, 0, 0.00, NULL),
(79, 11, '83', NULL, NULL, 1, 1.00, NULL),
(79, 10, '81', NULL, NULL, 1, 1.00, NULL),
(79, 6, '68', NULL, NULL, 1, 1.00, NULL),
(79, 5, '64', NULL, NULL, 1, 1.00, NULL),
(80, 10, '81', NULL, NULL, 1, 1.00, NULL),
(80, 6, '68', NULL, NULL, 1, 1.00, NULL),
(80, 12, '85', NULL, NULL, 1, 1.00, NULL),
(80, 5, '64', NULL, NULL, 1, 1.00, NULL),
(80, 9, '80', NULL, NULL, 0, 0.00, NULL),
(80, 11, '83', NULL, NULL, 1, 1.00, NULL),
(80, 8, '76', NULL, NULL, 1, 1.00, NULL),
(80, 7, '71', NULL, NULL, 0, 0.00, NULL),
(81, 5, '64', NULL, NULL, 1, 1.00, NULL),
(81, 9, '79', NULL, NULL, 1, 1.00, NULL),
(81, 10, '81', NULL, NULL, 1, 1.00, NULL),
(81, 7, '72', NULL, NULL, 1, 1.00, NULL),
(81, 12, '85', NULL, NULL, 1, 1.00, NULL),
(81, 8, '76', NULL, NULL, 1, 1.00, NULL),
(81, 6, '68', NULL, NULL, 1, 1.00, NULL),
(81, 11, '83', NULL, NULL, 1, 1.00, NULL),
(82, 11, '83', NULL, NULL, 1, 1.00, NULL),
(82, 10, '81', NULL, NULL, 1, 1.00, NULL),
(82, 12, '85', NULL, NULL, 1, 1.00, NULL),
(82, 5, '64', NULL, NULL, 1, 1.00, NULL),
(82, 7, '72', NULL, NULL, 1, 1.00, NULL),
(82, 8, '76', NULL, NULL, 1, 1.00, NULL),
(82, 9, '79', NULL, NULL, 1, 1.00, NULL),
(82, 6, '68', NULL, NULL, 1, 1.00, NULL),
(83, 12, '86', NULL, NULL, 0, 0.00, NULL),
(83, 8, '76', NULL, NULL, 1, 1.00, NULL),
(83, 10, '81', NULL, NULL, 1, 1.00, NULL),
(83, 11, '83', NULL, NULL, 1, 1.00, NULL),
(83, 9, '79', NULL, NULL, 1, 1.00, NULL),
(83, 6, '68', NULL, NULL, 1, 1.00, NULL),
(83, 7, '73', NULL, NULL, 0, 0.00, NULL),
(83, 5, '64', NULL, NULL, 1, 1.00, NULL),
(84, 5, '64', NULL, NULL, 1, 1.00, NULL),
(84, 10, '81', NULL, NULL, 1, 1.00, NULL),
(84, 8, '76', NULL, NULL, 1, 1.00, NULL),
(84, 7, '72', NULL, NULL, 1, 1.00, NULL),
(84, 9, '79', NULL, NULL, 1, 1.00, NULL),
(84, 11, '83', NULL, NULL, 1, 1.00, NULL),
(84, 6, '68', NULL, NULL, 1, 1.00, NULL),
(84, 12, '85', NULL, NULL, 1, 1.00, NULL),
(85, 5, '87', NULL, NULL, 1, 1.00, NULL),
(85, 6, '93', NULL, NULL, 0, 0.00, NULL),
(85, 7, '95', NULL, NULL, 1, 1.00, NULL),
(85, 8, '99', NULL, NULL, 1, 1.00, NULL),
(85, 9, '102', NULL, NULL, 1, 1.00, NULL),
(85, 10, '104', NULL, NULL, 1, 1.00, NULL),
(85, 11, '106', NULL, NULL, 1, 1.00, NULL),
(85, 12, '108', NULL, NULL, 1, 1.00, NULL);

-- --------------------------------------------------------

--
-- Структура на таблица `classes`
--

CREATE TABLE `classes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `teacher_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `grade` tinyint(3) UNSIGNED NOT NULL,
  `section` varchar(10) NOT NULL,
  `school_year` year(4) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `classes`
--

INSERT INTO `classes` (`id`, `teacher_id`, `name`, `grade`, `section`, `school_year`, `description`, `created_at`) VALUES
(5, 4, 'Мат осн', 12, 'б', '2025', 'асдфг', '2025-10-14 10:46:05'),
(6, 4, 'Инфомрационни', 9, 'в', '2025', 'Компютърна графика', '2025-10-14 11:22:27'),
(7, 4, '9А-2025/2026', 9, 'а', '2025', '', '2025-10-14 12:14:42'),
(8, 4, 'Мрежови протоколи', 12, 'б', '2025', '', '2025-10-14 13:26:02');

-- --------------------------------------------------------

--
-- Структура на таблица `class_students`
--

CREATE TABLE `class_students` (
  `class_id` bigint(20) UNSIGNED NOT NULL,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `class_students`
--

INSERT INTO `class_students` (`class_id`, `student_id`, `joined_at`) VALUES
(5, 1, '2025-10-14 11:06:00'),
(6, 7, '2025-10-14 11:30:36'),
(7, 19, '2025-10-14 12:15:02'),
(7, 36, '2025-10-14 12:15:37'),
(7, 41, '2025-10-14 12:16:42'),
(7, 23, '2025-10-14 12:16:51'),
(7, 24, '2025-10-14 12:17:09'),
(7, 40, '2025-10-14 12:17:25'),
(7, 33, '2025-10-14 12:17:50'),
(7, 22, '2025-10-14 12:18:16'),
(7, 30, '2025-10-14 12:19:02'),
(7, 39, '2025-10-14 12:19:25'),
(7, 21, '2025-10-14 12:19:40'),
(7, 31, '2025-10-14 12:19:59'),
(7, 45, '2025-10-14 12:20:21'),
(7, 43, '2025-10-14 12:20:33'),
(7, 20, '2025-10-14 12:20:57'),
(7, 25, '2025-10-14 12:21:06'),
(7, 38, '2025-10-14 12:21:15'),
(7, 32, '2025-10-14 12:21:36'),
(7, 18, '2025-10-14 12:21:49'),
(7, 48, '2025-10-14 12:22:02'),
(7, 26, '2025-10-14 12:22:11'),
(7, 27, '2025-10-14 12:22:20'),
(7, 46, '2025-10-14 12:22:39'),
(7, 44, '2025-10-14 12:22:43'),
(8, 66, '2025-10-14 13:27:43');

-- --------------------------------------------------------

--
-- Структура на таблица `question_bank`
--

CREATE TABLE `question_bank` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_teacher_id` bigint(20) UNSIGNED NOT NULL,
  `visibility` enum('private','shared') NOT NULL DEFAULT 'private',
  `qtype` enum('single_choice','multiple_choice','true_false','short_answer','numeric') NOT NULL,
  `body` text NOT NULL,
  `explanation` text DEFAULT NULL,
  `media_url` varchar(255) DEFAULT NULL,
  `media_mime` varchar(100) DEFAULT NULL,
  `difficulty` tinyint(3) UNSIGNED DEFAULT NULL,
  `tags_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `question_bank`
--

INSERT INTO `question_bank` (`id`, `owner_teacher_id`, `visibility`, `qtype`, `body`, `explanation`, `media_url`, `media_mime`, `difficulty`, `tags_json`, `created_at`, `updated_at`) VALUES
(3, 0, 'shared', 'single_choice', 'въпрос', NULL, NULL, NULL, NULL, NULL, '2025-10-13 13:38:52', '2025-10-13 13:38:52'),
(4, 4, 'shared', 'single_choice', 'въпрос', NULL, NULL, NULL, NULL, NULL, '2025-10-14 11:06:30', '2025-10-14 11:06:30'),
(5, 4, 'shared', 'single_choice', 'Облачната инфраструктура на публичния облак:', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:55', '2025-10-14 12:13:55'),
(6, 4, 'shared', 'single_choice', 'Грид технологията представлява:', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:55', '2025-10-14 12:13:55'),
(7, 4, 'shared', 'single_choice', 'Към грид инфраструктурата не се включва:', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:55', '2025-10-14 12:13:55'),
(8, 4, 'shared', 'single_choice', 'Най- голямата единица за измерване производителността на суперкомпютрите е:', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:56', '2025-10-14 12:13:56'),
(9, 4, 'shared', 'single_choice', 'Грид технологиите се използват за изпълнение на точно определени задачи, изискващи голям ресурс:', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:56', '2025-10-14 12:13:56'),
(10, 4, 'shared', 'single_choice', 'Ресурсите при облачните технологии са притежание на една организация и могат да се използват от други организации и потребители, най- често след наемане', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:56', '2025-10-14 12:13:56'),
(11, 4, 'shared', 'single_choice', 'Грид технологиите използват общи ресурси, които са собственост на организациите, включени в конкретен проект', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:56', '2025-10-14 12:13:56'),
(12, 4, 'shared', 'single_choice', 'Облачните технологии изпълняват заявките на голям брой потребители за дълъг период от време', NULL, NULL, NULL, NULL, NULL, '2025-10-14 12:13:56', '2025-10-14 12:13:56');

-- --------------------------------------------------------

--
-- Структура на таблица `subjects`
--

CREATE TABLE `subjects` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_teacher_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `subjects`
--

INSERT INTO `subjects` (`id`, `owner_teacher_id`, `name`, `slug`) VALUES
(1, NULL, 'Български език', 'bulgarski-ezik'),
(2, NULL, 'Информатика', 'informatika'),
(3, 3, 'Информатика', 'informatika'),
(4, 0, 'Информатика', 'informatika'),
(7, 4, 'Информационни технологии', 'it');

-- --------------------------------------------------------

--
-- Структура на таблица `tests`
--

CREATE TABLE `tests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_teacher_id` bigint(20) UNSIGNED NOT NULL,
  `subject_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `visibility` enum('private','shared') NOT NULL DEFAULT 'private',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `time_limit_sec` int(10) UNSIGNED DEFAULT NULL,
  `max_attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_randomized` tinyint(1) NOT NULL DEFAULT 0,
  `is_strict_mode` tinyint(1) NOT NULL DEFAULT 0,
  `theme` varchar(32) NOT NULL DEFAULT 'default',
  `theme_config` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `tests`
--

INSERT INTO `tests` (`id`, `owner_teacher_id`, `subject_id`, `title`, `description`, `visibility`, `status`, `time_limit_sec`, `max_attempts`, `is_randomized`, `is_strict_mode`, `theme`, `theme_config`, `created_at`, `updated_at`) VALUES
(7, 0, NULL, 'Тест 1', 'адфсг', 'shared', 'draft', 0, 0, 0, 0, 'default', NULL, '2025-10-13 13:38:52', '2025-10-13 13:38:52'),
(8, 4, NULL, '400', 'дсффг', 'shared', 'published', 0, 0, 0, 0, 'default', NULL, '2025-10-14 11:06:30', '2025-10-14 11:06:30'),
(9, 4, 7, 'Грид и облачни технологии', 'Грид и облачни технологии', 'shared', 'published', 0, 1, 1, 0, 'default', NULL, '2025-10-14 12:13:55', '2025-10-14 12:36:37');

-- --------------------------------------------------------

--
-- Структура на таблица `test_questions`
--

CREATE TABLE `test_questions` (
  `test_id` bigint(20) UNSIGNED NOT NULL,
  `question_id` bigint(20) UNSIGNED NOT NULL,
  `points` decimal(6,2) NOT NULL DEFAULT 1.00,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `required` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `test_questions`
--

INSERT INTO `test_questions` (`test_id`, `question_id`, `points`, `order_index`, `required`) VALUES
(7, 3, 1.00, 1, 1),
(8, 4, 1.00, 1, 1),
(9, 5, 1.00, 1, 1),
(9, 6, 1.00, 2, 1),
(9, 7, 1.00, 3, 1),
(9, 8, 1.00, 4, 1),
(9, 9, 1.00, 5, 1),
(9, 10, 1.00, 6, 1),
(9, 11, 1.00, 7, 1),
(9, 12, 1.00, 8, 1);

-- --------------------------------------------------------

--
-- Структура на таблица `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('teacher','student') NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `status` enum('active','suspended','deleted') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Схема на данните от таблица `users`
--

INSERT INTO `users` (`id`, `role`, `email`, `password_hash`, `first_name`, `last_name`, `avatar_url`, `status`, `created_at`, `updated_at`, `last_login_at`) VALUES
(1, 'student', 'test@test.com', '$2y$10$UQCsuYXq5BuBnQNJrgF04OIcen7LsWwBmzfHmKJjxlXYLjPq8x9L.', 'test', 'test', NULL, 'active', '2025-10-10 09:17:45', '2025-10-14 11:14:45', '2025-10-14 11:14:45'),
(2, 'student', 'test2@test.com', '$2y$10$TH01lGFraFiBQUymnUuNBObs2VssSlEcylIcEl4JKLSFgXtrFAi0K', 'test2', 'test2', NULL, 'active', '2025-10-10 09:22:00', '2025-10-10 09:22:00', NULL),
(3, 'teacher', 'teacher@test.bg', '$2y$10$YrXhm56OHyvvOrH08MBy8OjZV9V.TPD/ZazRnKtXl/ODX/vReFli.', 'Teacher', 'Test', NULL, 'active', '2025-10-10 11:08:41', '2025-10-10 16:37:00', '2025-10-10 16:37:00'),
(4, 'teacher', 'gramatikov88@gmail.com', '$2y$10$Q6IIqEzNuRJ9vZNuiQ6RvuiDOwbmaVG5gvznGhRQCFg5xZwYyGMjK', 'Ивайло', 'Граматиков', NULL, 'active', '2025-10-10 18:31:32', '2025-10-21 07:42:13', '2025-10-21 07:42:13'),
(5, 'student', 'test@test.com', '$2y$10$7sc5o8sFUpwvHGasFeh15eAmD68NtoPW6SGdFjiduDq95GTdPxgiy', 'тест', 'тест', NULL, 'active', '2025-10-10 20:00:40', '2025-10-13 10:20:17', '2025-10-13 10:20:17'),
(7, 'student', 'vstoimenova20@gmail.com', '$2y$10$qNzJdQPvR1I1OOcB2tEOIuAwXnnN36Ux4RdGbAbXztpgmOSWwFVzi', 'Валентина', 'Стоименова', NULL, 'active', '2025-10-14 11:19:19', '2025-10-14 11:19:31', '2025-10-14 11:19:31'),
(8, 'student', 'simonavangelova10@gmail.com', '$2y$10$MybGEQ55guoAOBCrB4BahOWz9rKXw2YBhmp7IOWsYd8n1H6hULGHa', 'Simona', 'Vangelova', NULL, 'active', '2025-10-14 11:22:00', '2025-10-14 11:22:44', '2025-10-14 11:22:44'),
(9, 'student', 'rady.gospodinova@gmail.com', '$2y$10$N7Hvk7IA0ueH.PcdxswikeO6Trcf2W4vlWI5tskNPIM/rwHA.PQki', 'Радостина', 'Господинова', NULL, 'active', '2025-10-14 11:22:13', '2025-10-14 11:22:28', '2025-10-14 11:22:28'),
(10, 'student', 'rady.gospodinova@gmail.com', '$2y$10$EtMDSA6uy.4kzdSdUsDtj.RMu52Mr.St6La2zp3IaO81GRRWp5wc6', 'Радостина', 'Господинова', NULL, 'active', '2025-10-14 11:22:21', '2025-10-14 11:22:21', NULL),
(11, 'student', 'petrovadani2507@gmail.com', '$2y$10$6.BdpUr3EoRMCMWc7.2TB.AO6rx6OyyeidM0tczWahcE3vS4OibJ.', 'Даниела', 'Петрова', NULL, 'active', '2025-10-14 11:22:39', '2025-10-14 11:22:52', '2025-10-14 11:22:52'),
(12, 'student', 'maauzunova@gmail.com', '$2y$10$NHBFjKlzLM1K8znb38GriuP/jE4YGIX3Zkquh3UebsEbidXcXBQmq', 'Maya', 'Uzunova', NULL, 'active', '2025-10-14 11:24:06', '2025-10-14 11:24:33', '2025-10-14 11:24:33'),
(13, 'student', 'katalina190510@gmail.com', '$2y$10$NgyHRC3I0Om4S6eyqav9uO5htjWKL5sj.CcuTf36INVLGfO.T1wBC', 'Katalina', 'Ivanova', NULL, 'active', '2025-10-14 11:25:18', '2025-10-14 11:25:18', NULL),
(14, 'student', 'yasenakoleva@gmail.com', '$2y$10$T/aM6HRTiCU6702O427Pqu9PL2svvZShah6CmYjLsPcGIGX3/UXFy', 'Ясена', 'Колева', NULL, 'active', '2025-10-14 11:26:09', '2025-10-14 11:26:23', '2025-10-14 11:26:23'),
(15, 'student', 'kalinanedeva36@gmail.com', '$2y$10$B5hKdPxAYDudKS5LhHyskuWuSl/ne5W9Sot1utz49QYveUVFT.sEq', 'Калина', 'Недева', NULL, 'active', '2025-10-14 11:26:22', '2025-10-14 11:26:32', '2025-10-14 11:26:32'),
(16, 'student', 'penevaelitca@gmail.com', '$2y$10$lr7KkK440tgH74SMcDbA3OWTu3lubVZEoutlxPqRtPfEeTS4smyUC', 'Елица', 'Пенева', NULL, 'active', '2025-10-14 11:26:47', '2025-10-14 11:27:06', '2025-10-14 11:27:06'),
(17, 'student', 'yanpeev10@gmail.com', '$2y$10$CsvqD8/.ImnIHYsEEqGKYO1nUr9lddVS54cD3SSIEfPeBMYv/V85a', 'Ян', 'Пеев', NULL, 'active', '2025-10-14 11:27:11', '2025-10-14 11:27:26', '2025-10-14 11:27:26'),
(18, 'student', 'p6420163@gmail.com', '$2y$10$JXjnUV8jVrYAteX21r42kOXarwNvPtg/1ASN8KU7H.YFa9uK2vhw2', 'Павел', 'Петров', NULL, 'active', '2025-10-14 12:04:00', '2025-10-14 12:04:10', '2025-10-14 12:04:10'),
(19, 'student', 'alexhorozov10@gmail.com', '$2y$10$kDacBZzD.K2TMwm9zt7hEekipW3pMTX7Tr4O59eQXSVp8m/vDi.w6', 'Александър', 'Хорозов', NULL, 'active', '2025-10-14 12:04:09', '2025-10-14 12:04:24', '2025-10-14 12:04:24'),
(20, 'student', 'miroslavkostov06@gmail.com', '$2y$10$5YEmADcTHYn4hxf3LTVrbu9bnvWuSPfrk9rihD6FxdV6rvfjxdwIu', 'Мирослав', 'Костов', NULL, 'active', '2025-10-14 12:04:10', '2025-10-14 12:20:25', '2025-10-14 12:20:25'),
(21, 'student', 'ina960282@gmail.com', '$2y$10$7Ay4bbnKnJtZQ6D/YVyZ9e5xqXQ7h.3wSwoI6f7kqOMMYNe46gGUy', 'Ина', 'Карабаджакова', NULL, 'active', '2025-10-14 12:04:12', '2025-10-14 12:04:52', '2025-10-14 12:04:52'),
(22, 'student', 'dimanakd@icloud.com', '$2y$10$MTzEz.HthDvkZrqFi/G4FejMbPlh6sTveTjp58EK7.z/ugvKZWvny', 'Димана', 'Динева', NULL, 'active', '2025-10-14 12:04:26', '2025-10-14 16:38:35', '2025-10-14 16:38:35'),
(23, 'student', 'deva1705@abv.bg', '$2y$10$xbWbOlab3kBBR99laMadoOapYvd6DmpVEWkDOeQP2NLqgLfEqGw7S', 'Дева-Константина', 'Иванова', NULL, 'active', '2025-10-14 12:04:31', '2025-10-14 12:14:25', '2025-10-14 12:14:25'),
(24, 'student', 'orbyx123@gmail.com', '$2y$10$k8j7cUnhW5.bml3Sqmlbs.3zPkRKJzFOow9nb2sQN87TYkPxS6e3y', 'Denis', 'Dinev', NULL, 'active', '2025-10-14 12:04:32', '2025-10-14 12:23:37', '2025-10-14 12:23:37'),
(25, 'student', 'krustevkrasimir615@gmail.com', '$2y$10$.LAPkBlMABPCOzOUKqTMQuRsSJFEKT48m97AB.xOb6rNv9bD2XoTK', 'Красимир', 'Кръстев', NULL, 'active', '2025-10-14 12:04:32', '2025-10-14 12:04:55', '2025-10-14 12:04:55'),
(26, 'student', 'paveltotev71@gmail.com', '$2y$10$PtiwoxHuhx3.Tr0EFK0pP.9CSiBjydTm2ZEY7v7Oe8JUD95JNA3ii', 'Павел', 'Тотев', NULL, 'active', '2025-10-14 12:04:37', '2025-10-14 12:05:00', '2025-10-14 12:05:00'),
(27, 'student', 'stefantenev2000@gmail.com', '$2y$10$DlXM5NNI3eVc6c3t3/KGgu7la37uc9dj16QXRBsIxBU/BjofERQZ2', 'Stefan', 'Tenev', NULL, 'active', '2025-10-14 12:04:47', '2025-10-14 12:05:01', '2025-10-14 12:05:01'),
(28, 'student', 'ina960282@gmail.com', '$2y$10$TSHQ9pG39YL5KCgKyfCxN.vy12dMfUNvrl9780HL3GTe/3ISkkBDC', 'Ина', 'Карабаджакова', NULL, 'active', '2025-10-14 12:04:48', '2025-10-14 12:04:48', NULL),
(29, 'student', 'paveltotev71@gmail.com', '$2y$10$cx/IkzJSvcn2GEcGScCd8OOZf1xydSmGquHVopKp6dH7rmZs.Eej2', 'Павел', 'Тотев', NULL, 'active', '2025-10-14 12:04:49', '2025-10-14 12:04:49', NULL),
(30, 'student', 'vanko0812@abv.bg', '$2y$10$NhhfN0nrjfPhDqSyXTKZReD4weFyI6q.8HLaKzm.k.h.0GElrC0M6', 'Иван', 'Тенчев', NULL, 'active', '2025-10-14 12:04:54', '2025-10-14 12:23:44', '2025-10-14 12:23:44'),
(31, 'student', 'triteleva@gmail.com', '$2y$10$rORcg/IaWrSJEb3WovT18ulJu/1DL.0zzNYSpoBXTzNyi1NtEflay', 'Йоан', 'Димитров', NULL, 'active', '2025-10-14 12:04:55', '2025-10-14 12:05:19', '2025-10-14 12:05:19'),
(32, 'student', 'qmaqaz@gmail.com', '$2y$10$JZqXPcOZPhP8o1UO9OXkS.r0OX8MePVe4bgpYNOzJz6RCWakiiPjG', 'Николаи', 'Марков', NULL, 'active', '2025-10-14 12:04:57', '2025-10-14 12:05:19', '2025-10-14 12:05:19'),
(33, 'student', 'kolevgeya123@gmail.com', '$2y$10$ecFsGr79lLo67neyrCCjaekrvPc1hNhxd31KkHIluT0TBKB7R8BU6', 'Denislav', 'Kolev', NULL, 'active', '2025-10-14 12:05:02', '2025-10-14 12:24:53', '2025-10-14 12:24:53'),
(34, 'student', 'orbyx123@gmail.com', '$2y$10$14IXtoVIv6EuurZIpw7GhOooNujeM6UWNEzzjeEZFFhqEzM5pFsWO', 'Denis', 'Dinev', NULL, 'active', '2025-10-14 12:05:07', '2025-10-14 12:05:07', NULL),
(35, 'student', 'fazechochko@gmail.com', '$2y$10$pg4985mCp07kfG.LPXzEieb5xOqr/oiccSHLWOZ7rQhkFIFRJ6a.C', 'Кристиян', 'Калчев', NULL, 'active', '2025-10-14 12:05:09', '2025-10-14 12:05:26', '2025-10-14 12:05:26'),
(36, 'student', 'vanessamk2010@gmail.com', '$2y$10$Nmzvg2OR7nQ4L95SxW7hz.dQyaID7E3oSYkP5HaA5i3zezN6FVrHS', 'Ванеса', 'Кичукова', NULL, 'active', '2025-10-14 12:05:10', '2025-10-14 12:50:25', '2025-10-14 12:50:25'),
(37, 'student', 'orbyx123@gmail.com', '$2y$10$KNo/FISEzVT2ZnDaCXuXteemENdkXOrONPBn6ZdsfDhR8PmQHRJse', 'Denis', 'Dinev', NULL, 'active', '2025-10-14 12:05:10', '2025-10-14 12:05:10', NULL),
(38, 'student', 'mi6ev1303@gmail.com', '$2y$10$aTFd1rvBSB/ytZ0H3KFc0OcZithxqRVzmOKmvysE7XNIko7pnDHd6', 'Михаил', 'Иванов', NULL, 'active', '2025-10-14 12:05:12', '2025-10-14 12:31:04', '2025-10-14 12:31:04'),
(39, 'student', 'dinchevivko@gmail.com', '$2y$10$bN4rLFb9Rdp2c34mg2.LnO2D6wGO7Rg20l3ULMpfbAHKZ3dS1chgu', 'Иво', 'Динчев', NULL, 'active', '2025-10-14 12:05:25', '2025-10-14 12:25:46', '2025-10-14 12:25:46'),
(40, 'student', 'dennismgltd@gmail.com', '$2y$10$ppfozBb2oayffNRYVpY9OOl0f4ZZOOLe57v55oLYxiJlWFYBnOJNa', 'Денислав', 'Димитров', NULL, 'active', '2025-10-14 12:05:34', '2025-10-14 12:05:52', '2025-10-14 12:05:52'),
(41, 'student', 'g.stoychev09@gmail.com', '$2y$10$hpZHadGgsNnwUrubcAjysu6OiNTavFN2kEGaKEJkmVBYQmb7VafK.', 'Георги', 'Стойчев', NULL, 'active', '2025-10-14 12:05:36', '2025-10-14 12:05:45', '2025-10-14 12:05:45'),
(42, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$XhPEypP/CZ2ZNyQt.qHHG.SjeGyxkgxWMBBGogQ8LTfGtOezC.hAO', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:09:05', '2025-10-14 12:09:05', NULL),
(43, 'student', 'kkalchev@net-flow.net', '$2y$10$Iz0GDejsd7Jf4phs36nQ6uXzg/NK0s5hM7a7jj5MYU.LCnx1F0hfm', 'Кристиян', 'Калчев', NULL, 'active', '2025-10-14 12:16:05', '2025-10-14 12:16:18', '2025-10-14 12:16:18'),
(44, 'student', 'teoman.sinapov.vip@gmail.com', '$2y$10$3h8jhbDQ8bJ/qFI/kWtWn.dsMYYorq3d2Y4uscTnKjhTX/sBC0Rt6', 'Теоман', 'Синапов', NULL, 'active', '2025-10-14 12:16:17', '2025-10-14 12:16:36', '2025-10-14 12:16:36'),
(45, 'student', 'bor4oxd@gmail.com', '$2y$10$d/IH2Zg8i2mwJ9iP7BlrauUUSobaPwHbmQEt6RztR863cQMuvsxdG', 'Borimir', 'Belev', NULL, 'active', '2025-10-14 12:17:09', '2025-10-14 12:17:24', '2025-10-14 12:17:24'),
(46, 'student', 'teodorbelchev16@gmail.com', '$2y$10$AxdYmizlsW1Amfjafygh8OkCrTQ//VM/KjSr8PHD5cEWqKhhA/e7y', 'teodor', 'belchev', NULL, 'active', '2025-10-14 12:19:41', '2025-10-14 12:19:49', '2025-10-14 12:19:49'),
(47, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$0Jpp0h901wb6uLCLqQPEO.GQEX6QbiLBNjxQeI4cm0b4pctW5u/TK', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:21:17', '2025-10-14 12:21:17', NULL),
(48, 'student', 'ivailop636@gmail.com', '$2y$10$U.pTu2xKYEfh7L1l1HfjI.eMowwwRgMRjhNHHzNDBfsVd.GJTkO9O', 'Ivailo', 'Petrov', NULL, 'active', '2025-10-14 12:21:31', '2025-10-14 12:21:41', '2025-10-14 12:21:41'),
(49, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$fkt4zRS6VJej1W6FDhfvqumTWeX36BfzsoavCSgGEA5d2.pLuYVf2', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:23:26', '2025-10-14 12:23:26', NULL),
(50, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$32aR.zdDxkcnWZE1LV98.erSJR7o8ZF1EZV118sVZgfRcsD9ISxSO', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:24:07', '2025-10-14 12:24:07', NULL),
(51, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$waXI7.qKySbR9hgX96IcfOKyWHZ/4lYts75KC82xwX.SYk/qf19aK', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:24:43', '2025-10-14 12:24:43', NULL),
(52, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$tREVtnqpyGAZEN7lpAxBMO3SaC2vSC3EDx63KLJLbRiBSn3SvxfrC', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:36:19', '2025-10-14 12:36:19', NULL),
(53, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$3.d3ifxu5t5XpD0UANAqxeVjUVj0S7nWV6XkpAoa0cZFm7/EGJnuG', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:36:44', '2025-10-14 12:36:44', NULL),
(54, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$aIExz2JBGTwI/z4DxhJ9vOJW7XV4Z79V6xomoA0IM27Nsg8B/qRMC', 'Vencislav', 'Kalchev', NULL, 'active', '2025-10-14 12:40:20', '2025-10-14 12:40:20', NULL),
(55, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$y.QEPBh76Y3W1lMeas4eTuMQe.1xeRPtMNbbX/hr9hmLG5CqERuMe', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:42:00', '2025-10-14 12:42:00', NULL),
(56, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$5XUPpYsrVF078EohuJYyduIlWbNcYvurxTNicUzo09Aai.r/gAbvG', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:44:51', '2025-10-14 12:44:51', NULL),
(57, 'student', 'mrn_ignatova@mail.bg', '$2y$10$SEcjFcZC4.FzDyG7ExSbBuaRuJkoR.cPVi6VjR7IY.6wf5V8wOoa6', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:45:58', '2025-10-14 12:59:55', '2025-10-14 12:59:55'),
(58, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$.KzAKiQmChcRTjqjmNffUOCSuNvL6p5bocPA2RMhjUjM90lnEm5Ki', 'Vencislav', 'Kalchev', NULL, 'active', '2025-10-14 12:48:57', '2025-10-14 12:48:57', NULL),
(59, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$.j/WsX3C3h2W0ZS11Keaau80YDz.OpSy9/ot.FVZQpA09P3atyVLW', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:50:38', '2025-10-14 12:50:38', NULL),
(60, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$FWlspGFCLpWnDFiw5DmMBu8uF34cGa4Qy2uOC5eDGKkZ.YvtZGMxG', 'Vencislav', 'Kalchev', NULL, 'active', '2025-10-14 12:54:37', '2025-10-14 12:54:37', NULL),
(61, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$ADm2By55G24Uiu6WCGVepu7Fn9gSSDMlalDGvPm.8kyQLV2nudDBW', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 12:55:59', '2025-10-14 12:55:59', NULL),
(62, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$gd/0fkNQj1Wavie.2ys42ei4al0GIAawrK9qfbmc15Vd7WKIO9kCq', 'Vencislav', 'Kalchev', NULL, 'active', '2025-10-14 12:57:29', '2025-10-14 12:57:29', NULL),
(63, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$6L/BAMeFSseCmLnRdF4KcelUzgshgtbjJBtLi2Dtz2VuxPsFPfxPi', 'Vencislav', 'Kalchev', NULL, 'active', '2025-10-14 12:59:08', '2025-10-14 12:59:08', NULL),
(64, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$UCtvQh9Ov5U3.X/iUninIe8Tnz0s/CebCSCxr5DYPe7SpU9ZP3s5a', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 13:01:34', '2025-10-14 13:01:34', NULL),
(65, 'student', 'vencislav.kalchev08@mail.bg', '$2y$10$3V5SBC14Zi5bI6D9.Rr/seBL8TVVk8AGZqrAC77SIA9uAvwmsIU8m', 'Венцислав', 'Калчев', NULL, 'active', '2025-10-14 13:06:54', '2025-10-14 13:06:54', NULL),
(66, 'student', 'n.panayotov@tsa-soft.com', '$2y$10$lUaQlWsK.Bcc6nJcUAsHKe4tbYB5OVa4P12imXQpQX3pvAJX/QVB.', 'Nikola', 'Panayotov', NULL, 'active', '2025-10-14 13:26:47', '2025-10-15 09:47:05', '2025-10-15 09:47:05'),
(67, 'student', 'vencislav.kalchev08@gmail.com', '$2y$10$3MDlqVitpLPgnTjsnptLpu.wuDf1/KbKFCLBUWYgrNvTAxq/qnTyW', 'Vencislav', 'Kalchev', NULL, 'active', '2025-10-14 19:26:00', '2025-10-14 19:26:00', NULL),
(68, 'student', 'gerimixaylova@gmail.com', '$2y$10$tQ1IqHFtnZ8MvD3fgC9sY.xPaI/X4BF6ZQjIldykLyk7/v35jDSaO', 'Гергана', 'Михайлова', NULL, 'active', '2025-10-20 20:24:20', '2025-10-20 20:25:22', '2025-10-20 20:25:22');

-- --------------------------------------------------------

--
-- Структура на таблица `v_cohort_assignment_stats`
--

CREATE TABLE `v_cohort_assignment_stats` (
  `grade` tinyint(3) UNSIGNED DEFAULT NULL,
  `school_year` year(4) DEFAULT NULL,
  `assignment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `students_attempted` bigint(21) DEFAULT NULL,
  `avg_percent` decimal(14,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Структура на таблица `v_question_stats`
--

CREATE TABLE `v_question_stats` (
  `question_id` bigint(20) UNSIGNED DEFAULT NULL,
  `qtype` enum('single_choice','multiple_choice','true_false','short_answer','numeric') DEFAULT NULL,
  `attempts_count` bigint(21) DEFAULT NULL,
  `avg_score_awarded` decimal(8,3) DEFAULT NULL,
  `correct_rate` decimal(5,3) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Структура на таблица `v_student_overview`
--

CREATE TABLE `v_student_overview` (
  `student_id` bigint(20) UNSIGNED DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `assignments_taken` bigint(21) DEFAULT NULL,
  `avg_percent` decimal(14,2) DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Индекси за таблица `answers`
--
ALTER TABLE `answers`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `attempts`
--
ALTER TABLE `attempts`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `question_bank`
--
ALTER TABLE `question_bank`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `tests`
--
ALTER TABLE `tests`
  ADD PRIMARY KEY (`id`);

--
-- Индекси за таблица `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `answers`
--
ALTER TABLE `answers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `attempts`
--
ALTER TABLE `attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `question_bank`
--
ALTER TABLE `question_bank`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tests`
--
ALTER TABLE `tests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
