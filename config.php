<?php
// Central app configuration and DB helper

// Database credentials (edit here or set environment variables)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'gramtest_testgramatikov');
define('DB_USER', getenv('DB_USER') ?: 'gramtest');
define('DB_PASS', getenv('DB_PASS') ?: 'zbc2D!shaZirp7t');

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

function generate_token(int $bytes = 16): string {
    if ($bytes < 1) {
        $bytes = 16;
    }
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function send_app_mail(string $to, string $subject, string $body): bool {
    $fromEmail = getenv('APP_MAIL_FROM') ?: 'no-reply@testgramatikov.local';
    $fromName = getenv('APP_MAIL_FROM_NAME') ?: 'TestGramatikov';

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
    ];

    return mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}

function app_url(string $path = ''): string {
    $base = getenv('APP_BASE_URL');
    if ($base) {
        $base = rtrim($base, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            $dir = '';
        }
        $base = $scheme . '://' . $host . ($dir ? rtrim($dir, '/') : '');
    }
    $normalizedPath = ltrim($path, '/');
    return $base . '/' . $normalizedPath;
}

function sanitize_redirect_path(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^(?:[a-z][a-z0-9+\-.]*:)?//~i', $path)) {
        return '';
    }
    $path = str_replace(["\r", "\n"], '', $path);
    return $path === '' ? '' : $path;
}

function class_generate_join_token(PDO $pdo, ?int $excludeId = null): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $length = 6;
    for ($i = 0; $i < 10; $i++) {
        $token = '';
        for ($j = 0; $j < $length; $j++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $sql = 'SELECT COUNT(*) FROM classes WHERE join_token = :token';
        $params = [':token' => $token];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            return $token;
        }
    }
    throw new RuntimeException('Unable to generate unique class join token');
}

function class_ensure_join_token(PDO $pdo, int $classId): string {
    $stmt = $pdo->prepare('SELECT join_token FROM classes WHERE id = :id');
    $stmt->execute([':id' => $classId]);
    $token = $stmt->fetchColumn();
    if ($token === false || $token === null || $token === '') {
        $token = class_generate_join_token($pdo, $classId);
        $upd = $pdo->prepare('UPDATE classes SET join_token = :token WHERE id = :id');
        $upd->execute([':token' => $token, ':id' => $classId]);
    }
    return (string)$token;
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

function ensure_class_invite_token(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "classes" AND COLUMN_NAME = "join_token"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            try {
                $pdo->exec('ALTER TABLE classes ADD COLUMN join_token VARCHAR(64) NULL AFTER description');
            } catch (Throwable $e) {}
        }
        try {
            $pdo->exec('ALTER TABLE classes ADD UNIQUE KEY uq_classes_join_token (join_token)');
        } catch (Throwable $e) {}
        $stmt = $pdo->query('SELECT id FROM classes WHERE join_token IS NULL OR join_token = "" LIMIT 200');
        $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($ids as $classId) {
            try {
                class_ensure_join_token($pdo, (int)$classId);
            } catch (Throwable $e) {}
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

function ensure_password_resets_table(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "password_resets"');
        $stmt->execute([':db' => DB_NAME]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec(
                "CREATE TABLE password_resets (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    selector VARCHAR(32) NOT NULL,
                    token_hash VARCHAR(255) NOT NULL,
                    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    request_ip VARCHAR(45) NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_password_resets_selector (selector),
                    KEY idx_password_resets_user (user_id),
                    KEY idx_password_resets_expires (expires_at),
                    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } else {
            // Ensure newer columns exist when deploying on older dumps
            $columns = [
                'selector' => 'ALTER TABLE password_resets ADD COLUMN selector VARCHAR(32) NOT NULL AFTER user_id',
                'token_hash' => 'ALTER TABLE password_resets ADD COLUMN token_hash VARCHAR(255) NOT NULL AFTER selector',
                'requested_at' => 'ALTER TABLE password_resets ADD COLUMN requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER token_hash',
                'expires_at' => 'ALTER TABLE password_resets ADD COLUMN expires_at DATETIME NOT NULL AFTER requested_at',
                'used_at' => 'ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL AFTER expires_at',
                'request_ip' => 'ALTER TABLE password_resets ADD COLUMN request_ip VARCHAR(45) NULL AFTER used_at',
            ];
            foreach ($columns as $column => $sql) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "password_resets" AND COLUMN_NAME = :column');
                $stmt->execute([':db' => DB_NAME, ':column' => $column]);
                if ((int)$stmt->fetchColumn() === 0) {
                    try { $pdo->exec($sql); } catch (Throwable $e) {}
                }
            }
            try { $pdo->exec('ALTER TABLE password_resets ADD UNIQUE KEY uq_password_resets_selector (selector)'); } catch (Throwable $e) {}
            try { $pdo->exec('ALTER TABLE password_resets ADD KEY idx_password_resets_user (user_id)'); } catch (Throwable $e) {}
            try { $pdo->exec('ALTER TABLE password_resets ADD KEY idx_password_resets_expires (expires_at)'); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        // ignore to avoid blocking the request
    }
    $done = true;
}
