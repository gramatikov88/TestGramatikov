<?php
// Central app configuration and DB helper

// Database credentials (edit here or set environment variables)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'shortlyq_testgramatikov');
define('DB_USER', getenv('DB_USER') ?: 'shortlyq');
define('DB_PASS', getenv('DB_PASS') ?: ':Y0ENz5l[3Us1r');

// Lazy PDO singleton
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// Optional: lightweight schema migration helper for subjects scoping
function ensure_subjects_scope(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "subjects" AND COLUMN_NAME = "owner_teacher_id"');
        $stmt->execute([':db' => DB_NAME]);
        $has = (int)$stmt->fetchColumn() > 0;
        if (!$has) {
            try { $pdo->exec('ALTER TABLE subjects ADD COLUMN owner_teacher_id BIGINT UNSIGNED NULL AFTER id'); } catch (Throwable $e) {}
            try { $pdo->exec('ALTER TABLE subjects DROP INDEX uq_subjects_slug'); } catch (Throwable $e) {}
            try { $pdo->exec('ALTER TABLE subjects ADD UNIQUE KEY uq_subjects_owner_slug (owner_teacher_id, slug)'); } catch (Throwable $e) {}
            try { $pdo->exec('ALTER TABLE subjects ADD CONSTRAINT fk_subjects_owner FOREIGN KEY (owner_teacher_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE'); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        // ignore — do not block page
    }
    $done = true;
}

// Ensure attempts have editable teacher grade column
function ensure_attempts_grade(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "attempts" AND COLUMN_NAME = "teacher_grade"');
        $stmt->execute([':db' => DB_NAME]);
        $has = (int)$stmt->fetchColumn() > 0;
        if (!$has) {
            try { $pdo->exec('ALTER TABLE attempts ADD COLUMN teacher_grade TINYINT NULL AFTER max_score'); } catch (Throwable $e) {}
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "attempts" AND COLUMN_NAME = "strict_violation"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try { $pdo->exec('ALTER TABLE attempts ADD COLUMN strict_violation TINYINT(1) NOT NULL DEFAULT 0 AFTER teacher_grade'); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

// Ensure tests extra columns and question_bank media columns exist
function ensure_test_theme_and_q_media(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        // tests.is_strict_mode
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "tests" AND COLUMN_NAME = "is_strict_mode"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try { $pdo->exec("ALTER TABLE tests ADD COLUMN is_strict_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER is_randomized"); } catch (Throwable $e) {}
        }
        // tests.theme
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "tests" AND COLUMN_NAME = "theme"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try { $pdo->exec("ALTER TABLE tests ADD COLUMN theme VARCHAR(32) NOT NULL DEFAULT 'default' AFTER is_randomized"); } catch (Throwable $e) {}
        }
        // tests.theme_config
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "tests" AND COLUMN_NAME = "theme_config"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try { $pdo->exec("ALTER TABLE tests ADD COLUMN theme_config TEXT NULL AFTER theme"); } catch (Throwable $e) {}
        }
        // question_bank media
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "question_bank" AND COLUMN_NAME = "media_url"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try { $pdo->exec("ALTER TABLE question_bank ADD COLUMN media_url VARCHAR(255) NULL AFTER explanation"); } catch (Throwable $e) {}
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "question_bank" AND COLUMN_NAME = "media_mime"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try { $pdo->exec("ALTER TABLE question_bank ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_url"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) { /* ignore */ }
}
