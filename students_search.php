<?php
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Only teachers can search
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'teacher') {
    http_response_code(403);
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if ($q === '') {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';

try {
    if ($class_id > 0) {
        $stmt = $pdo->prepare(
            'SELECT u.id,
                    CONCAT(u.first_name, " ", u.last_name, " — ", u.email) AS text
             FROM users u
             WHERE u.role = "student"
               AND (u.first_name LIKE :q OR u.last_name LIKE :q OR CONCAT(u.first_name, " ", u.last_name) LIKE :q OR u.email LIKE :q)
               AND u.id NOT IN (SELECT cs.student_id FROM class_students cs WHERE cs.class_id = :cid)
             ORDER BY u.first_name, u.last_name
             LIMIT 20'
        );
        $stmt->execute([':q' => $like, ':cid' => $class_id]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.id,
                    CONCAT(u.first_name, " ", u.last_name, " — ", u.email) AS text
             FROM users u
             WHERE u.role = "student"
               AND (u.first_name LIKE :q OR u.last_name LIKE :q OR CONCAT(u.first_name, " ", u.last_name) LIKE :q OR u.email LIKE :q)
             ORDER BY u.first_name, u.last_name
             LIMIT 20'
        );
        $stmt->execute([':q' => $like]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['results' => array_map(function($r){ return ['id' => (int)$r['id'], 'text' => $r['text']]; }, $rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'DB error'], JSON_UNESCAPED_UNICODE);
}
