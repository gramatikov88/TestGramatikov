<?php
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? null) !== 'student') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not_allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$attemptId = isset($payload['attempt_id']) ? (int) $payload['attempt_id'] : 0;
$action = isset($payload['action']) ? trim((string) $payload['action']) : '';
$questionId = isset($payload['question_id']) ? (int) $payload['question_id'] : null;
$meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null;

if ($attemptId <= 0 || $action === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_required_fields']);
    exit;
}

if (!in_array($action, test_log_allowed_actions(), true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_action']);
    exit;
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id, assignment_id, test_id, student_id FROM attempts WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $attemptId]);
$attempt = $stmt->fetch();

if (!$attempt || (int) $attempt['student_id'] !== (int) $user['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not_allowed']);
    exit;
}

if ($questionId !== null && $questionId <= 0) {
    $questionId = null;
}

try {
    log_test_event($pdo, [
        'attempt_id' => (int) $attempt['id'],
        'assignment_id' => (int) $attempt['assignment_id'],
        'test_id' => (int) $attempt['test_id'],
        'student_id' => (int) $attempt['student_id'],
        'question_id' => $questionId,
        'action' => $action,
        'meta' => $meta,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'log_failed']);
    exit;
}

echo json_encode(['ok' => true]);
