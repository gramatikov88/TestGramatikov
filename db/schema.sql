-- TestGramatikov schema (MySQL/MariaDB)
-- Charset: utf8mb4, Engine: InnoDB

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Create database
CREATE DATABASE IF NOT EXISTS `testgramatikov` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `testgramatikov`;

-- Safety for re-runs
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS v_question_stats;
DROP VIEW IF EXISTS v_cohort_assignment_stats;
DROP VIEW IF EXISTS v_class_assignment_stats;
DROP VIEW IF EXISTS v_student_overview;

DROP TABLE IF EXISTS attempt_answers;
DROP TABLE IF EXISTS attempts;
DROP TABLE IF EXISTS assignment_students;
DROP TABLE IF EXISTS assignment_classes;
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS test_questions;
DROP TABLE IF EXISTS answers;
DROP TABLE IF EXISTS question_bank;
DROP TABLE IF EXISTS tests;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS class_students;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- Users
CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role          ENUM('teacher','student') NOT NULL,
  email         VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(100) NOT NULL,
  last_name     VARCHAR(100) NOT NULL,
  avatar_url    VARCHAR(255) NULL,
  status        ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Classes (grade + section = паралелка)
CREATE TABLE classes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id   BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(100) NOT NULL, -- e.g. "8А"
  grade        TINYINT UNSIGNED NOT NULL, -- e.g. 8, 9, 10, 11, 12
  section      VARCHAR(10) NOT NULL,      -- e.g. A, Б, В
  school_year  YEAR NOT NULL,             -- e.g. 2025
  description  VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_classes_teacher (teacher_id),
  UNIQUE KEY uq_class_unique (teacher_id, grade, section, school_year),
  CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollment of students in classes
CREATE TABLE class_students (
  class_id    BIGINT UNSIGNED NOT NULL,
  student_id  BIGINT UNSIGNED NOT NULL,
  joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_id, student_id),
  KEY idx_class_students_student (student_id),
  CONSTRAINT fk_class_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_class_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subjects / categories (optional)
CREATE TABLE subjects (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_teacher_id   BIGINT UNSIGNED NULL,
  name               VARCHAR(100) NOT NULL,
  slug               VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_subjects_owner (owner_teacher_id),
  UNIQUE KEY uq_subjects_owner_slug (owner_teacher_id, slug),
  CONSTRAINT fk_subjects_owner FOREIGN KEY (owner_teacher_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tests (quizzes)
CREATE TABLE tests (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_teacher_id   BIGINT UNSIGNED NOT NULL,
  subject_id         INT UNSIGNED NULL,
  title              VARCHAR(200) NOT NULL,
  description        TEXT NULL,
  visibility         ENUM('private','shared') NOT NULL DEFAULT 'private',
  status             ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  time_limit_sec     INT UNSIGNED NULL,      -- null = no limit
  max_attempts       SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- 0 = unlimited at assignment level may override
  is_randomized      TINYINT(1) NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tests_owner (owner_teacher_id),
  KEY idx_tests_subject (subject_id),
  KEY idx_tests_visibility (visibility),
  CONSTRAINT fk_tests_owner FOREIGN KEY (owner_teacher_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_tests_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Question bank (reusable across tests)
CREATE TABLE question_bank (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_teacher_id   BIGINT UNSIGNED NOT NULL,
  visibility         ENUM('private','shared') NOT NULL DEFAULT 'private',
  qtype              ENUM('single_choice','multiple_choice','true_false','short_answer','numeric') NOT NULL,
  body               TEXT NOT NULL,
  explanation        TEXT NULL,
  difficulty         TINYINT UNSIGNED NULL, -- 1..5
  tags_json          TEXT NULL,             -- JSON string with tags if needed
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qb_owner (owner_teacher_id),
  KEY idx_qb_visibility (visibility),
  KEY idx_qb_qtype (qtype),
  CONSTRAINT fk_qb_owner FOREIGN KEY (owner_teacher_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Answers for questions in the bank
CREATE TABLE answers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id   BIGINT UNSIGNED NOT NULL,
  content       TEXT NOT NULL,
  is_correct    TINYINT(1) NOT NULL DEFAULT 0,
  order_index   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_answers_question (question_id),
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapping questions into tests (with per-test scoring and order)
CREATE TABLE test_questions (
  test_id        BIGINT UNSIGNED NOT NULL,
  question_id    BIGINT UNSIGNED NOT NULL,
  points         DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  order_index    INT NOT NULL DEFAULT 0,
  required       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (test_id, question_id),
  KEY idx_tq_question (question_id),
  CONSTRAINT fk_tq_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_tq_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignments (publish tests to classes or individual students)
CREATE TABLE assignments (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_id                BIGINT UNSIGNED NOT NULL,
  assigned_by_teacher_id BIGINT UNSIGNED NOT NULL,
  title                  VARCHAR(200) NOT NULL,
  description            TEXT NULL,
  is_published           TINYINT(1) NOT NULL DEFAULT 0,
  open_at                DATETIME NULL,
  due_at                 DATETIME NULL,
  close_at               DATETIME NULL,
  attempt_limit          SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = unlimited
  shuffle_questions      TINYINT(1) NOT NULL DEFAULT 0,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assignments_test (test_id),
  KEY idx_assignments_teacher (assigned_by_teacher_id),
  CONSTRAINT fk_assignments_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_assignments_teacher FOREIGN KEY (assigned_by_teacher_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment targets: classes
CREATE TABLE assignment_classes (
  assignment_id BIGINT UNSIGNED NOT NULL,
  class_id      BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (assignment_id, class_id),
  KEY idx_ac_class (class_id),
  CONSTRAINT fk_ac_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ac_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment targets: individual students (optional per-assignment)
CREATE TABLE assignment_students (
  assignment_id BIGINT UNSIGNED NOT NULL,
  student_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (assignment_id, student_id),
  KEY idx_as_student (student_id),
  CONSTRAINT fk_as_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_as_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student attempts
CREATE TABLE attempts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id  BIGINT UNSIGNED NOT NULL,
  test_id        BIGINT UNSIGNED NOT NULL,
  student_id     BIGINT UNSIGNED NOT NULL,
  attempt_no     INT UNSIGNED NOT NULL DEFAULT 1,
  status         ENUM('in_progress','submitted','graded','expired') NOT NULL DEFAULT 'in_progress',
  started_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at   DATETIME NULL,
  duration_sec   INT UNSIGNED NULL,
  score_obtained DECIMAL(8,2) NULL,
  max_score      DECIMAL(8,2) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt (assignment_id, student_id, attempt_no),
  KEY idx_attempts_student (student_id),
  KEY idx_attempts_assignment (assignment_id),
  KEY idx_attempts_test (test_id),
  CONSTRAINT fk_attempts_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attempts_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attempts_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Answers given in an attempt
CREATE TABLE attempt_answers (
  attempt_id        BIGINT UNSIGNED NOT NULL,
  question_id       BIGINT UNSIGNED NOT NULL,
  selected_option_ids TEXT NULL,  -- JSON or CSV of answers.id for MCQ
  free_text         TEXT NULL,    -- for short answers
  numeric_value     DECIMAL(12,4) NULL,
  is_correct        TINYINT(1) NULL,
  score_awarded     DECIMAL(6,2) NULL,
  time_spent_sec    INT UNSIGNED NULL,
  PRIMARY KEY (attempt_id, question_id),
  KEY idx_aa_question (question_id),
  CONSTRAINT fk_aa_attempt FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_aa_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Views for analytics

-- Student overview: count of assignments taken, average percent, last activity
CREATE OR REPLACE VIEW v_student_overview AS
SELECT
  u.id AS student_id,
  u.first_name,
  u.last_name,
  COUNT(DISTINCT a.assignment_id) AS assignments_taken,
  ROUND(AVG(CASE WHEN a.status IN ('submitted','graded') AND a.max_score > 0 THEN (a.score_obtained / a.max_score) * 100 END), 2) AS avg_percent,
  MAX(COALESCE(a.submitted_at, a.started_at)) AS last_activity
FROM users u
LEFT JOIN attempts a ON a.student_id = u.id
WHERE u.role = 'student' AND u.status = 'active'
GROUP BY u.id, u.first_name, u.last_name;

-- Class assignment stats: average percent per class and assignment
CREATE OR REPLACE VIEW v_class_assignment_stats AS
SELECT
  c.id AS class_id,
  c.grade,
  c.section,
  c.school_year,
  ac.assignment_id,
  COUNT(DISTINCT atp.student_id) AS students_attempted,
  ROUND(AVG(CASE WHEN atp.status IN ('submitted','graded') AND atp.max_score > 0 THEN (atp.score_obtained / atp.max_score) * 100 END), 2) AS avg_percent,
  ROUND(MAX(CASE WHEN atp.max_score > 0 THEN (atp.score_obtained / atp.max_score) * 100 END), 2) AS max_percent,
  ROUND(MIN(CASE WHEN atp.max_score > 0 THEN (atp.score_obtained / atp.max_score) * 100 END), 2) AS min_percent
FROM classes c
JOIN assignment_classes ac ON ac.class_id = c.id
LEFT JOIN class_students cs ON cs.class_id = c.id
LEFT JOIN attempts atp ON atp.assignment_id = ac.assignment_id AND atp.student_id = cs.student_id
GROUP BY c.id, c.grade, c.section, c.school_year, ac.assignment_id;

-- Cohort (grade + year) stats across all classes
CREATE OR REPLACE VIEW v_cohort_assignment_stats AS
SELECT
  c.grade,
  c.school_year,
  ac.assignment_id,
  COUNT(DISTINCT atp.student_id) AS students_attempted,
  ROUND(AVG(CASE WHEN atp.status IN ('submitted','graded') AND atp.max_score > 0 THEN (atp.score_obtained / atp.max_score) * 100 END), 2) AS avg_percent
FROM classes c
JOIN assignment_classes ac ON ac.class_id = c.id
LEFT JOIN class_students cs ON cs.class_id = c.id
LEFT JOIN attempts atp ON atp.assignment_id = ac.assignment_id AND atp.student_id = cs.student_id
GROUP BY c.grade, c.school_year, ac.assignment_id;

-- Question item stats: attempts, average score, correct rate
CREATE OR REPLACE VIEW v_question_stats AS
SELECT
  qb.id AS question_id,
  qb.qtype,
  COUNT(aa.attempt_id) AS attempts_count,
  ROUND(AVG(aa.score_awarded), 3) AS avg_score_awarded,
  ROUND(AVG(CASE WHEN aa.is_correct = 1 THEN 1.0 ELSE 0.0 END), 3) AS correct_rate
FROM question_bank qb
LEFT JOIN attempt_answers aa ON aa.question_id = qb.id
GROUP BY qb.id, qb.qtype;

-- Basic seed (optional): a demo subject
-- Seed example subject without owner (visible to none by logic)
INSERT INTO subjects (owner_teacher_id, name, slug)
VALUES (NULL, 'Български език', 'bulgarski-ezik')
ON DUPLICATE KEY UPDATE name = VALUES(name);
