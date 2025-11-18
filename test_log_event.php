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

function action_label(string $action): string {
    static $map = [
        'test_start' => 'Старт на теста',
        'test_resume' => 'Възобновяване на теста',
        'test_submit' => 'Предаване на теста',
        'question_show' => 'Показване на въпрос',
        'question_answer' => 'Попълнен отговор',
        'question_change_answer' => 'Коригиран отговор',
        'navigate_next' => 'Следващ въпрос',
        'navigate_prev' => 'Предишен въпрос',
        'tab_hidden' => 'Табът е скрит',
        'tab_visible' => 'Табът е видим',
        'fullscreen_enter' => 'Влизане в цял екран',
        'fullscreen_exit' => 'Излизане от цял екран',
        'page_reload' => 'Презареждане на страницата',
        'timeout' => 'Изтекло време',
        'forced_finish' => 'Принудително приключване',
        'suspicious_pattern' => 'Подозрително поведение',
        'navigate_focus' => 'Фокус на навигация',
    ];
    return $map[$action] ?? $action;
}

function is_negative_log(string $action, ?array $meta): bool {
    static $negativeActions = [
        'tab_hidden',
        'fullscreen_exit',
        'page_reload',
        'timeout',
        'forced_finish',
        'suspicious_pattern',
    ];
    if (in_array($action, $negativeActions, true)) {
        return true;
    }
    if (!$meta) {
        return false;
    }
    $reason = $meta['reason'] ?? null;
    if (is_string($reason) && in_array($reason, ['window_blur', 'strict_mode_violation', 'too_many_tab_switches', 'page_reload'], true)) {
        return true;
    }
    if (!empty($meta['strict_violation'])) {
        return true;
    }
    if (($meta['visibility'] ?? null) === 'hidden') {
        return true;
    }
    return false;
}

function format_duration(float $seconds): string {
    $seconds = max(0, $seconds);
    $days = floor($seconds / 86400);
    $seconds -= $days * 86400;
    $hours = floor($seconds / 3600);
    $seconds -= $hours * 3600;
    $minutes = floor($seconds / 60);
    $seconds -= $minutes * 60;
    $parts = [];
    if ($days > 0) { $parts[] = $days . 'д'; }
    if ($hours > 0) { $parts[] = $hours . 'ч'; }
    if ($minutes > 0) { $parts[] = $minutes . 'м'; }
    if ($seconds > 0 || empty($parts)) {
        $parts[] = rtrim(rtrim(number_format($seconds, $seconds > 9 ? 0 : 2, '.', ''), '0'), '.') . 'с';
    }
    return implode(' ', $parts);
}

function format_log_meta(array $meta): array {
    $lines = [];
    $copy = $meta;
    $boolLabel = static function ($value): string {
        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, ['1', 'true', 'yes'], true)) {
                return 'Да';
            }
            if (in_array($value, ['0', 'false', 'no'], true)) {
                return 'Не';
            }
        }
        return $value ? 'Да' : 'Не';
    };

    if (array_key_exists('reason', $copy)) {
        $reasonMap = [
            'window_blur' => 'Причина: Прозорецът изгуби фокус',
            'tab_hidden' => 'Причина: Скрит таб',
            'strict_mode_violation' => 'Причина: Нарушение на строг режим',
            'tab_visible' => 'Причина: Табът отново е видим',
            'too_many_tab_switches' => 'Причина: Прекалено много смени на таб',
            'page_reload' => 'Причина: Презареждане на страницата',
        ];
        $reasonKey = (string)$copy['reason'];
        $lines[] = $reasonMap[$reasonKey] ?? ('Причина: ' . $reasonKey);
        unset($copy['reason']);
    }

    if (array_key_exists('visibility', $copy)) {
        $visibilityMap = [
            'hidden' => 'Екранът е скрит',
            'visible' => 'Екранът е видим',
        ];
        $visibilityKey = (string)$copy['visibility'];
        $lines[] = $visibilityMap[$visibilityKey] ?? ('Видимост: ' . $visibilityKey);
        unset($copy['visibility']);
    }

    if (array_key_exists('qtype', $copy)) {
        $qtypeMap = [
            'single_choice' => 'Тип въпрос: Единичен избор',
            'multiple_choice' => 'Тип въпрос: Множествен избор',
            'true_false' => 'Тип въпрос: Вярно/Невярно',
            'short_answer' => 'Тип въпрос: Кратък отговор',
            'numeric' => 'Тип въпрос: Числов отговор',
        ];
        $qtypeKey = (string)$copy['qtype'];
        $lines[] = $qtypeMap[$qtypeKey] ?? ('Тип въпрос: ' . $qtypeKey);
        unset($copy['qtype']);
    }

    if (array_key_exists('timestamp', $copy)) {
        $ts = (float)$copy['timestamp'];
        $seconds = $ts / 1000;
        $lines[] = 'Локален таймер: ' . format_duration($seconds) . ' (' . number_format($seconds, 2, '.', ' ') . ' сек.)';
        unset($copy['timestamp']);
    }

    if (array_key_exists('time_spent_sec', $copy)) {
        $timeSpent = (float)$copy['time_spent_sec'];
        $lines[] = 'Време за въпроса: ' . format_duration($timeSpent);
        unset($copy['time_spent_sec']);
    }

    if (array_key_exists('selected_option_id', $copy)) {
        $lines[] = 'Избран отговор: #' . (int)$copy['selected_option_id'];
        unset($copy['selected_option_id']);
    }

    if (array_key_exists('selected_option_ids', $copy)) {
        $value = $copy['selected_option_ids'];
        if (is_array($value)) {
            $value = implode(', ', array_map('intval', $value));
        }
        $lines[] = 'Избрани отговори: ' . (string)$value;
        unset($copy['selected_option_ids']);
    }

    if (array_key_exists('old_answer_id', $copy) || array_key_exists('new_answer_id', $copy)) {
        $old = isset($copy['old_answer_id']) ? (int)$copy['old_answer_id'] : null;
        $new = isset($copy['new_answer_id']) ? (int)$copy['new_answer_id'] : null;
        $lines[] = 'Промяна на отговор: ' . ($old ? '#' . $old : 'няма') . ' → ' . ($new ? '#' . $new : 'няма');
        unset($copy['old_answer_id'], $copy['new_answer_id']);
    }

    if (array_key_exists('free_text_length', $copy)) {
        $lines[] = 'Брой символи: ' . (int)$copy['free_text_length'];
        unset($copy['free_text_length']);
    }

    if (array_key_exists('numeric_value', $copy)) {
        $lines[] = 'Въведено число: ' . (is_numeric($copy['numeric_value']) ? $copy['numeric_value'] : (string)$copy['numeric_value']);
        unset($copy['numeric_value']);
    }

    if (array_key_exists('is_correct', $copy)) {
        $lines[] = 'Отговорът е верен: ' . $boolLabel($copy['is_correct']);
        unset($copy['is_correct']);
    }

    if (array_key_exists('score_awarded', $copy)) {
        $lines[] = 'Получени точки: ' . (float)$copy['score_awarded'];
        unset($copy['score_awarded']);
    }

    if (array_key_exists('score', $copy) || array_key_exists('max', $copy)) {
        $score = array_key_exists('score', $copy) ? (float)$copy['score'] : null;
        $max = array_key_exists('max', $copy) ? (float)$copy['max'] : null;
        $lines[] = 'Резултат: ' . ($score !== null ? $score : '-') . ' / ' . ($max !== null ? $max : '-');
        unset($copy['score'], $copy['max']);
    }

    if (array_key_exists('percent', $copy)) {
        $lines[] = 'Процент: ' . number_format((float)$copy['percent'], 2) . '%';
        unset($copy['percent']);
    }

    if (array_key_exists('strict_violation', $copy)) {
        $lines[] = 'Строго нарушение: ' . $boolLabel($copy['strict_violation']);
        unset($copy['strict_violation']);
    }

    if (array_key_exists('attempt_no', $copy)) {
        $lines[] = 'Номер опит: ' . (int)$copy['attempt_no'];
        unset($copy['attempt_no']);
    }

    if (array_key_exists('source', $copy)) {
        $sourceMap = [
            'page_render' => 'Източник: Зареждане на страницата',
            'frontend' => 'Източник: Фронтенд',
        ];
        $sourceKey = (string)$copy['source'];
        $lines[] = $sourceMap[$sourceKey] ?? ('Източник: ' . $sourceKey);
        unset($copy['source']);
    }

    foreach ($copy as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if (is_bool($value)) {
            $valueText = $boolLabel($value);
        } elseif (is_scalar($value)) {
            $valueText = (string)$value;
        } else {
            $valueText = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $label = ucfirst(str_replace('_', ' ', (string)$key));
        $lines[] = $label . ': ' . $valueText;
    }

    return $lines;
}

?><!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Логове за опит #<?= (int)$attempt['id'] ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .log-negative {
            color: #b02a37;
        }
        .log-negative td, .log-negative td * {
            color: inherit;
        }
        .log-negative .badge {
            background-color: rgba(176, 42, 55, 0.15);
            color: #b02a37;
            border-color: rgba(176, 42, 55, 0.3);
        }
    </style>
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
                            $metaLines = [];
                            $metaFallback = '';
                            if (is_array($metaDecoded)) {
                                $metaLines = format_log_meta($metaDecoded);
                            } elseif ($metaDecoded !== null && $metaDecoded !== '') {
                                $metaFallback = (string)$metaDecoded;
                            }
                            $isNegative = is_negative_log($row['action'], is_array($metaDecoded) ? $metaDecoded : null);
                            $actionLabel = action_label($row['action']);
                        ?>
                        <tr class="<?= $isNegative ? 'log-negative table-danger' : '' ?>">
                            <td><?= (int)$row['id'] ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($row['created_at']) ?></small></td>
                            <td><span class="badge bg-secondary-subtle text-dark border border-secondary-subtle"><?= htmlspecialchars($actionLabel) ?></span></td>
                            <td><?= $row['question_id'] !== null ? (int)$row['question_id'] : '—' ?></td>
                            <td>
                                <div class="small text-muted">IP: <?= htmlspecialchars($row['ip'] ?? '-') ?></div>
                                <div class="small text-muted">UA: <?= htmlspecialchars($row['user_agent'] ?? '-') ?></div>
                            </td>
                            <td style="max-width: 260px;">
                                <?php if ($metaLines): ?>
                                    <ul class="list-unstyled small mb-0">
                                        <?php foreach ($metaLines as $line): ?>
                                            <li><?= htmlspecialchars($line) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif ($metaFallback !== ''): ?>
                                    <pre class="small bg-light p-2 rounded text-break mb-0"><?= htmlspecialchars($metaFallback) ?></pre>
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
