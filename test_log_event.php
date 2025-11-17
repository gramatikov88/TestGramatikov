<?php
session_start();
require_once __DIR__ . '/config.php';

$user = $_SESSION['user'] ?? null;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

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
    exit;
}

// Teacher log view
if (!$user || ($user['role'] ?? null) !== 'teacher') {
    http_response_code(403);
    echo 'Нямате достъп до този ресурс.';
    exit;
}

$attemptId = isset($_GET['attempt_id']) ? (int) $_GET['attempt_id'] : 0;
if ($attemptId <= 0) {
    http_response_code(400);
    echo 'Липсва валиден опит.';
    exit;
}

$pdo = db();

$attemptStmt = $pdo->prepare('SELECT atp.*, a.title AS assignment_title, a.assigned_by_teacher_id,
                                     t.title AS test_title,
                                     u.first_name, u.last_name, u.email
                              FROM attempts atp
                              JOIN assignments a ON a.id = atp.assignment_id
                              JOIN tests t ON t.id = atp.test_id
                              JOIN users u ON u.id = atp.student_id
                              WHERE atp.id = :id LIMIT 1');
$attemptStmt->execute([':id' => $attemptId]);
$attempt = $attemptStmt->fetch();
if (!$attempt || (int)$attempt['assigned_by_teacher_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'Нямате достъп до този опит.';
    exit;
}

$logsStmt = $pdo->prepare('SELECT * FROM test_logs WHERE attempt_id = :id ORDER BY created_at ASC, id ASC');
$logsStmt->execute([':id' => $attemptId]);
$logs = $logsStmt->fetchAll();

$actionCounts = [];
foreach ($logs as $row) {
    $action = $row['action'] ?? 'unknown';
    $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
}

$format = strtolower($_GET['format'] ?? 'html');
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'attempt' => [
            'id' => (int)$attempt['id'],
            'student' => trim($attempt['first_name'] . ' ' . $attempt['last_name']),
            'student_email' => $attempt['email'],
            'assignment' => $attempt['assignment_title'],
            'test' => $attempt['test_title'],
            'status' => $attempt['status'],
            'started_at' => $attempt['started_at'],
            'submitted_at' => $attempt['submitted_at'],
        ],
        'counts' => $actionCounts,
        'logs' => array_map(static function($row) {
            $meta = $row['meta'];
            $decoded = null;
            if ($meta !== null && $meta !== '') {
                $decoded = json_decode($meta, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $decoded = $meta;
                }
            }
            return [
                'id' => (int)$row['id'],
                'created_at' => $row['created_at'],
                'action' => $row['action'],
                'question_id' => $row['question_id'] !== null ? (int)$row['question_id'] : null,
                'ip' => $row['ip'],
                'user_agent' => $row['user_agent'],
                'meta' => $decoded,
            ];
        }, $logs),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$assignmentUrl = 'assignment_overview.php?id=' . (int)$attempt['assignment_id'];
$studentLabel = trim($attempt['first_name'] . ' ' . $attempt['last_name']);
$hasLogs = !empty($logs);

?><!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Логове за опит #<?= (int)$attempt['id'] ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<main class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Логове за <?= htmlspecialchars($studentLabel) ?></h1>
            <div class="text-muted small">
                Задание: <?= htmlspecialchars($attempt['assignment_title']) ?> · Тест: <?= htmlspecialchars($attempt['test_title']) ?>
            </div>
            <div class="text-muted small">Опит #<?= (int)$attempt['attempt_no'] ?> · Статус: <?= htmlspecialchars($attempt['status']) ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($assignmentUrl) ?>"><i class="bi bi-arrow-left"></i> Назад</a>
            <a class="btn btn-outline-primary" href="attempt_review.php?id=<?= (int)$attempt['id'] ?>"><i class="bi bi-eye"></i> Преглед на опита</a>
            <a class="btn btn-outline-dark" href="?attempt_id=<?= (int)$attempt['id'] ?>&format=json"><i class="bi bi-filetype-json"></i> JSON</a>
        </div>
    </div>

    <?php if (!$hasLogs): ?>
        <div class="alert alert-warning">Няма записани действия за този опит.</div>
    <?php else: ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Обобщение на действията</h2>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($actionCounts as $action => $count): ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                            <?= htmlspecialchars($action) ?>: <?= (int)$count ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Детайлен журнал</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Време</th>
                            <th scope="col">Действие</th>
                            <th scope="col">Въпрос</th>
                            <th scope="col">IP / Устройство</th>
                            <th scope="col">Допълнителни данни</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $row): ?>
                        <?php
                            $metaRaw = $row['meta'];
                            $metaDecoded = null;
                            if ($metaRaw !== null && $metaRaw !== '') {
                                $metaDecoded = json_decode($metaRaw, true);
                                if ($metaDecoded === null && json_last_error() !== JSON_ERROR_NONE) {
                                    $metaDecoded = $metaRaw;
                                }
                            }
                            $metaDisplay = '';
                            if (is_array($metaDecoded)) {
                                $metaDisplay = json_encode($metaDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                            } elseif ($metaDecoded !== null && $metaDecoded !== '') {
                                $metaDisplay = (string)$metaDecoded;
                            }
                        ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($row['created_at']) ?></small></td>
                            <td><span class="badge bg-secondary-subtle text-dark border border-secondary-subtle"><?= htmlspecialchars($row['action']) ?></span></td>
                            <td><?= $row['question_id'] !== null ? (int)$row['question_id'] : '—' ?></td>
                            <td>
                                <div class="small text-muted">IP: <?= htmlspecialchars($row['ip'] ?? '-') ?></div>
                                <div class="small text-muted">UA: <?= htmlspecialchars($row['user_agent'] ?? '-') ?></div>
                            </td>
                            <td style="max-width: 260px;">
                                <?php if ($metaDisplay): ?>
                                    <pre class="small bg-light p-2 rounded text-break mb-0"><?= htmlspecialchars($metaDisplay) ?></pre>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
