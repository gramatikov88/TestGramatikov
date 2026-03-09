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

// ── Teacher log view ────────────────────────────────────────────────────────
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

$attemptStmt = $pdo->prepare(
    'SELECT atp.*, a.title AS assignment_title, a.assigned_by_teacher_id,
            t.title AS test_title,
            u.first_name, u.last_name, u.email
     FROM attempts atp
     JOIN assignments a ON a.id = atp.assignment_id
     JOIN tests t ON t.id = atp.test_id
     JOIN users u ON u.id = atp.student_id
     WHERE atp.id = :id LIMIT 1'
);
$attemptStmt->execute([':id' => $attemptId]);
$attempt = $attemptStmt->fetch();
if (!$attempt || (int) $attempt['assigned_by_teacher_id'] !== (int) $user['id']) {
    http_response_code(403);
    echo 'Нямате достъп до този опит.';
    exit;
}

$logsStmt = $pdo->prepare('SELECT * FROM test_logs WHERE attempt_id = :id ORDER BY created_at ASC, id ASC');
$logsStmt->execute([':id' => $attemptId]);
$logs = $logsStmt->fetchAll();

// ── JSON export ──────────────────────────────────────────────────────────────
$format = strtolower($_GET['format'] ?? 'html');
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $actionCounts = [];
    foreach ($logs as $row) {
        $a = $row['action'] ?? 'unknown';
        $actionCounts[$a] = ($actionCounts[$a] ?? 0) + 1;
    }
    echo json_encode([
        'attempt' => [
            'id' => (int) $attempt['id'],
            'student' => trim($attempt['first_name'] . ' ' . $attempt['last_name']),
            'student_email' => $attempt['email'],
            'assignment' => $attempt['assignment_title'],
            'test' => $attempt['test_title'],
            'status' => $attempt['status'],
            'started_at' => $attempt['started_at'],
            'submitted_at' => $attempt['submitted_at'],
        ],
        'counts' => $actionCounts,
        'logs' => array_map(static function ($row) {
            $meta = $row['meta'];
            $decoded = null;
            if ($meta !== null && $meta !== '') {
                $decoded = json_decode($meta, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $decoded = $meta;
                }
            }
            return [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'],
                'action' => $row['action'],
                'question_id' => $row['question_id'] !== null ? (int) $row['question_id'] : null,
                'ip' => $row['ip'],
                'user_agent' => $row['user_agent'],
                'meta' => $decoded,
            ];
        }, $logs),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ── Helper functions ─────────────────────────────────────────────────────────

/**
 * Human-readable label for each action.
 */
function action_label(string $action): string
{
    static $map = [
    'test_start' => 'Старт на теста',
    'test_resume' => 'Възобновяване на теста',
    'test_submit' => 'Предаване на теста',
    'question_show' => 'Показан въпрос',
    'question_answer' => 'Попълнен отговор',
    'question_change_answer' => 'Коригиран отговор',
    'navigate_next' => 'Следващ въпрос',
    'navigate_prev' => 'Предишен въпрос',
    'navigate_focus' => 'Фокус върху въпрос',
    'tab_hidden' => 'Табът е скрит / прозорецът е напуснат',
    'tab_visible' => 'Върнат фокус на таба',
    'fullscreen_enter' => 'Включен цял екран',
    'fullscreen_exit' => 'Изключен цял екран',
    'page_reload' => 'Презареждане на страницата',
    'timeout' => 'Изтекло времe',
    'forced_finish' => 'Принудително приключване',
    'suspicious_pattern' => 'Подозрително поведение',
    'copy_attempt' => 'Опит за копиране',
    'paste_attempt' => 'Опит за поставяне',
    'mouse_leave' => 'Мишката напусна прозореца',
    'context_menu' => 'Десен клик / контекстно меню',
    'keyboard_shortcut' => 'Подозрителна клавишна комбинация',
    'screen_info' => 'Информация за устройство/екран',
    ];
    return $map[$action] ?? $action;
}

/**
 * Bootstrap icon class for each action type.
 */
function action_icon(string $action): string
{
    static $map = [
    'test_start' => 'bi-play-circle-fill',
    'test_resume' => 'bi-arrow-clockwise',
    'test_submit' => 'bi-send-check-fill',
    'question_show' => 'bi-eye',
    'question_answer' => 'bi-check-circle',
    'question_change_answer' => 'bi-pencil',
    'navigate_next' => 'bi-arrow-right-circle',
    'navigate_prev' => 'bi-arrow-left-circle',
    'navigate_focus' => 'bi-cursor',
    'tab_hidden' => 'bi-eye-slash-fill',
    'tab_visible' => 'bi-eye-fill',
    'fullscreen_enter' => 'bi-fullscreen',
    'fullscreen_exit' => 'bi-fullscreen-exit',
    'page_reload' => 'bi-arrow-repeat',
    'timeout' => 'bi-hourglass-bottom',
    'forced_finish' => 'bi-x-octagon-fill',
    'suspicious_pattern' => 'bi-shield-exclamation',
    'copy_attempt' => 'bi-clipboard',
    'paste_attempt' => 'bi-clipboard-check',
    'mouse_leave' => 'bi-box-arrow-right',
    'context_menu' => 'bi-three-dots-vertical',
    'keyboard_shortcut' => 'bi-keyboard',
    'screen_info' => 'bi-display',
    ];
    return $map[$action] ?? 'bi-circle';
}

/**
 * Severity category: 'normal' | 'warning' | 'danger'
 */
function action_severity(string $action, ?array $meta): string
{
    $danger = [
        'tab_hidden',
        'fullscreen_exit',
        'page_reload',
        'timeout',
        'forced_finish',
        'suspicious_pattern',
        'copy_attempt',
        'paste_attempt',
        'keyboard_shortcut'
    ];
    $warning = ['mouse_leave', 'context_menu', 'fullscreen_enter'];

    if (in_array($action, $danger, true)) {
        // window_blur is danger, window_focus is just warning
        if ($action === 'tab_hidden') {
            $reason = $meta['reason'] ?? $meta['visibility'] ?? '';
            if ($reason === 'window_focus' || $reason === 'tab_visible')
                return 'normal';
        }
        return 'danger';
    }
    if (in_array($action, $warning, true)) {
        return 'warning';
    }
    return 'normal';
}

/**
 * Parse a UA string into a short readable label, e.g. "Chrome 120 · Windows 10"
 */
function parse_browser(string $ua): string
{
    if ($ua === '')
        return 'Неизвестен';
    $browser = 'Неизвестен браузър';
    $os = '';
    // Browser
    if (preg_match('/(OPR|Opera)[\/ ]([\d.]+)/', $ua, $m)) {
        $browser = 'Opera ' . $m[2];
    } elseif (preg_match('/Edg\/([\d.]+)/', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/SamsungBrowser\/([\d.]+)/', $ua, $m)) {
        $browser = 'Samsung Browser ' . $m[1];
    } elseif (preg_match('/Chrome\/([\d.]+)/', $ua, $m) && strpos($ua, 'Chromium') === false) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/([\d.]+)/', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Safari\/([\d.]+)/', $ua, $m) && strpos($ua, 'Chrome') === false) {
        $browser = 'Safari';
    }
    // OS
    if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
        $ntMap = [
            '10.0' => 'Windows 10/11',
            '6.3' => 'Windows 8.1',
            '6.2' => 'Windows 8',
            '6.1' => 'Windows 7',
            '6.0' => 'Windows Vista'
        ];
        $os = $ntMap[$m[1]] ?? 'Windows NT ' . $m[1];
    } elseif (preg_match('/Android ([\d.]+)/', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/iPhone OS ([\d_]+)/', $ua, $m)) {
        $os = 'iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Linux/', $ua)) {
        $os = 'Linux';
    }
    return $browser . ($os ? ' · ' . $os : '');
}

/**
 * Format meta fields into readable lines.
 */
function format_log_meta(array $meta, array $context = []): array
{
    $lines = [];
    $copy = $meta;

    $boolLabel = static function ($v): string {
        if (is_string($v)) {
            $v = strtolower($v);
            if (in_array($v, ['1', 'true', 'yes'], true))
                return 'Да';
            if (in_array($v, ['0', 'false', 'no'], true))
                return 'Не';
        }
        return $v ? 'Да' : 'Не';
    };

    // reason
    if (array_key_exists('reason', $copy)) {
        $rm = [
            'window_blur' => 'Причина: Прозорецът изгуби фокус',
            'window_focus' => 'Причина: Прозорецът получи фокус',
            'tab_hidden' => 'Причина: Скрит таб',
            'tab_visible' => 'Причина: Видим таб',
            'strict_mode_violation' => 'Причина: Нарушение на строг режим',
            'too_many_tab_switches' => 'Причина: Прекалено много смени на таб',
            'page_reload' => 'Причина: Презареждане на страницата',
        ];
        $rk = (string) $copy['reason'];
        $lines[] = $rm[$rk] ?? ('Причина: ' . $rk);
        unset($copy['reason']);
    }
    // visibility
    if (array_key_exists('visibility', $copy)) {
        $vm = ['hidden' => 'Екранът е скрит', 'visible' => 'Екранът е видим'];
        $vk = (string) $copy['visibility'];
        $lines[] = $vm[$vk] ?? ('Видимост: ' . $vk);
        unset($copy['visibility']);
    }
    // qtype
    if (array_key_exists('qtype', $copy)) {
        $qtm = [
            'single_choice' => 'Тип въпрос: Единичен избор',
            'multiple_choice' => 'Тип въпрос: Множествен избор',
            'true_false' => 'Тип въпрос: Вярно/Невярно',
            'short_answer' => 'Тип въпрос: Кратък отговор',
            'numeric' => 'Тип въпрос: Числов отговор',
        ];
        $qtk = (string) $copy['qtype'];
        $lines[] = $qtm[$qtk] ?? ('Тип въпрос: ' . $qtk);
        unset($copy['qtype']);
    }
    // timestamp (relative)
    if (array_key_exists('timestamp', $copy)) {
        $ts = (float) $copy['timestamp'];
        unset($copy['timestamp']); // skip raw timestamp – shown in row
    }
    // time_spent_sec
    if (array_key_exists('time_spent_sec', $copy)) {
        $ts2 = (float) $copy['time_spent_sec'];
        $lines[] = 'Време за въпроса: ' . format_duration($ts2);
        unset($copy['time_spent_sec']);
    }
    // answer info
    if (array_key_exists('selected_option_id', $copy)) {
        $lines[] = 'Избран отговор: #' . (int) $copy['selected_option_id'];
        unset($copy['selected_option_id']);
    }
    if (array_key_exists('selected_option_ids', $copy)) {
        $v = $copy['selected_option_ids'];
        if (is_array($v))
            $v = implode(', ', array_map('intval', $v));
        $lines[] = 'Избрани отговори: ' . (string) $v;
        unset($copy['selected_option_ids']);
    }
    if (array_key_exists('old_answer_id', $copy) || array_key_exists('new_answer_id', $copy)) {
        $old = isset($copy['old_answer_id']) ? (int) $copy['old_answer_id'] : null;
        $new = isset($copy['new_answer_id']) ? (int) $copy['new_answer_id'] : null;
        $lines[] = 'Промяна: ' . ($old ? '#' . $old : 'няма') . ' → ' . ($new ? '#' . $new : 'няма');
        unset($copy['old_answer_id'], $copy['new_answer_id']);
    }
    if (array_key_exists('free_text_length', $copy)) {
        $lines[] = 'Брой символи: ' . (int) $copy['free_text_length'];
        unset($copy['free_text_length']);
    }
    if (array_key_exists('numeric_value', $copy)) {
        $lines[] = 'Въведено число: ' . $copy['numeric_value'];
        unset($copy['numeric_value']);
    }
    if (array_key_exists('is_correct', $copy)) {
        $lines[] = 'Верен: ' . $boolLabel($copy['is_correct']);
        unset($copy['is_correct']);
    }
    if (array_key_exists('score_awarded', $copy)) {
        $lines[] = 'Точки: ' . (float) $copy['score_awarded'];
        unset($copy['score_awarded']);
    }
    if (array_key_exists('score', $copy) || array_key_exists('max', $copy)) {
        $sc = array_key_exists('score', $copy) ? (float) $copy['score'] : null;
        $mx = array_key_exists('max', $copy) ? (float) $copy['max'] : null;
        $lines[] = 'Резултат: ' . ($sc !== null ? $sc : '-') . ' / ' . ($mx !== null ? $mx : '-');
        unset($copy['score'], $copy['max']);
    }
    if (array_key_exists('percent', $copy)) {
        $lines[] = 'Процент: ' . number_format((float) $copy['percent'], 2) . '%';
        unset($copy['percent']);
    }
    if (array_key_exists('strict_violation', $copy)) {
        $lines[] = 'Строго нарушение: ' . $boolLabel($copy['strict_violation']);
        unset($copy['strict_violation']);
    }
    if (array_key_exists('attempt_no', $copy)) {
        $lines[] = 'Номер опит: ' . (int) $copy['attempt_no'];
        unset($copy['attempt_no']);
    }
    if (array_key_exists('source', $copy)) {
        $sm = ['page_render' => 'Зареждане на страницата', 'frontend' => 'Фронтенд'];
        $sk = (string) $copy['source'];
        $lines[] = 'Източник: ' . ($sm[$sk] ?? $sk);
        unset($copy['source']);
    }
    // keyboard combo
    if (array_key_exists('combo', $copy)) {
        $lines[] = 'Комбинация: ' . htmlspecialchars((string) $copy['combo']);
        unset($copy['combo']);
    }
    // mouse_leave count
    if (array_key_exists('count', $copy)) {
        $lines[] = 'Обща бройка: ' . (int) $copy['count'];
        unset($copy['count']);
    }
    // screen_info
    foreach (['screen_w', 'screen_h', 'avail_w', 'avail_h', 'window_w', 'window_h'] as $k) {
        if (array_key_exists($k, $copy)) {
            $labels = [
                'screen_w' => 'Екран ширина',
                'screen_h' => 'Екран височина',
                'avail_w' => 'Достъпна ширина',
                'avail_h' => 'Достъпна височина',
                'window_w' => 'Прозорец ширина',
                'window_h' => 'Прозорец височина',
            ];
            $lines[] = $labels[$k] . ': ' . (int) $copy[$k] . 'px';
            unset($copy[$k]);
        }
    }
    if (array_key_exists('pixel_ratio', $copy)) {
        $lines[] = 'Пиксел съотношение: ' . (float) $copy['pixel_ratio'];
        unset($copy['pixel_ratio']);
    }
    if (array_key_exists('touch', $copy)) {
        $lines[] = 'Тъч устройство: ' . $boolLabel($copy['touch']);
        unset($copy['touch']);
    }
    if (array_key_exists('color_depth', $copy)) {
        $lines[] = 'Дълбочина на цвета: ' . (int) $copy['color_depth'] . ' bit';
        unset($copy['color_depth']);
    }
    if (array_key_exists('orientation', $copy)) {
        $om = [
            'landscape-primary' => 'Хоризонтална',
            'portrait-primary' => 'Вертикална',
            'landscape-secondary' => 'Хоризонтална (обратна)',
            'portrait-secondary' => 'Вертикална (обратна)'
        ];
        $ok2 = (string) $copy['orientation'];
        $lines[] = 'Ориентация: ' . ($om[$ok2] ?? $ok2);
        unset($copy['orientation']);
    }

    // Remaining unknown keys
    foreach ($copy as $key => $value) {
        if ($value === null || $value === '')
            continue;
        if (is_bool($value))
            $vt = $boolLabel($value);
        elseif (is_scalar($value))
            $vt = (string) $value;
        else
            $vt = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $label = ucfirst(str_replace('_', ' ', (string) $key));
        $lines[] = $label . ': ' . $vt;
    }
    return $lines;
}

function format_duration(float $seconds): string
{
    $seconds = max(0, $seconds);
    $h = floor($seconds / 3600);
    $seconds -= $h * 3600;
    $m = floor($seconds / 60);
    $seconds -= $m * 60;
    $parts = [];
    if ($h > 0)
        $parts[] = $h . 'ч';
    if ($m > 0)
        $parts[] = $m . 'м';
    if ($seconds > 0 || empty($parts))
        $parts[] = rtrim(rtrim(number_format($seconds, $seconds > 9 ? 0 : 2, '.', ''), '0'), '.') . 'с';
    return implode(' ', $parts);
}

// ── Build data for view ──────────────────────────────────────────────────────
$assignmentUrl = 'assignment_overview.php?id=' . (int) $attempt['assignment_id'];
$studentLabel = trim($attempt['first_name'] . ' ' . $attempt['last_name']);
$hasLogs = !empty($logs);

// Action counts & severity counts
$actionCounts = [];
$suspectCount = 0;
$suspectActions = [
    'tab_hidden',
    'fullscreen_exit',
    'page_reload',
    'timeout',
    'forced_finish',
    'suspicious_pattern',
    'copy_attempt',
    'paste_attempt',
    'keyboard_shortcut',
    'mouse_leave',
    'context_menu'
];
$deviceInfo = null; // from screen_info log
$firstIp = null;
$firstUa = null;

foreach ($logs as $row) {
    $a = $row['action'] ?? 'unknown';
    $actionCounts[$a] = ($actionCounts[$a] ?? 0) + 1;
    if (in_array($a, $suspectActions, true)) {
        $suspectCount++;
    }
    if ($firstIp === null && ($row['ip'] ?? '') !== '') {
        $firstIp = $row['ip'];
    }
    if ($firstUa === null && ($row['user_agent'] ?? '') !== '') {
        $firstUa = $row['user_agent'];
    }
    if ($a === 'screen_info' && $row['meta']) {
        $decoded = json_decode($row['meta'], true);
        if (is_array($decoded) && isset($decoded['screen_w'])) {
            $deviceInfo = $decoded;
        }
    }
}

// Duration
$startedAt = $attempt['started_at'];
$submittedAt = $attempt['submitted_at'];
$durationStr = '–';
if ($startedAt && $submittedAt) {
    $diff = strtotime($submittedAt) - strtotime($startedAt);
    $durationStr = format_duration((float) max(0, $diff));
}

// Risk level
$riskLevel = 'low';
$riskLabel = 'Нисък риск';
$riskColor = 'success';
if ($suspectCount >= 10) {
    $riskLevel = 'high';
    $riskLabel = 'Висок риск';
    $riskColor = 'danger';
} elseif ($suspectCount >= 3) {
    $riskLevel = 'medium';
    $riskLabel = 'Среден риск';
    $riskColor = 'warning';
}

// Severity icon colors
$severityConfig = [
    'danger' => ['color' => 'var(--tg-danger)', 'bg' => 'rgba(var(--tg-danger-rgb),0.12)', 'border' => 'var(--tg-danger)'],
    'warning' => ['color' => 'var(--tg-warning)', 'bg' => 'rgba(var(--tg-warning-rgb),0.12)', 'border' => 'var(--tg-warning)'],
    'normal' => ['color' => 'var(--tg-primary)', 'bg' => 'rgba(var(--tg-primary-rgb),0.10)', 'border' => 'transparent'],
];

$browserLabel = $firstUa ? parse_browser($firstUa) : '–';

// Group action counts for summary display
$groupedSummary = [
    'normal' => [],
    'warning' => [],
    'danger' => [],
];
foreach ($actionCounts as $a => $cnt) {
    $sev = action_severity($a, null);
    $groupedSummary[$sev][$a] = $cnt;
}
?><!DOCTYPE html>
<html lang="bg" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Журнал – <?= htmlspecialchars($studentLabel) ?> – Опит #<?= (int) $attempt['attempt_no'] ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
    <style>
        /* ── Timeline ── */
        .tl-wrapper {
            position: relative;
            padding-left: 2rem;
        }

        .tl-wrapper::before {
            content: '';
            position: absolute;
            left: .65rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, var(--tg-primary), transparent);
            opacity: .3;
        }

        .tl-item {
            position: relative;
            margin-bottom: .75rem;
        }

        .tl-dot {
            position: absolute;
            left: -2rem;
            top: .45rem;
            width: 1.1rem;
            height: 1.1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .55rem;
            border: 2px solid transparent;
            flex-shrink: 0;
        }

        .tl-dot.sev-danger {
            background: rgba(var(--tg-danger-rgb), .2);
            border-color: var(--tg-danger);
            color: var(--tg-danger);
        }

        .tl-dot.sev-warning {
            background: rgba(var(--tg-warning-rgb), .2);
            border-color: var(--tg-warning);
            color: var(--tg-warning);
        }

        .tl-dot.sev-normal {
            background: rgba(var(--tg-primary-rgb), .15);
            border-color: transparent;
            color: var(--tg-primary);
        }

        .tl-card {
            background: var(--tg-glass-bg);
            border: 1px solid var(--tg-glass-border);
            border-radius: .75rem;
            padding: .6rem .85rem;
            transition: border-color .2s;
        }

        .tl-card.sev-danger {
            border-left: 3px solid var(--tg-danger) !important;
        }

        .tl-card.sev-warning {
            border-left: 3px solid var(--tg-warning) !important;
        }

        .tl-action {
            font-size: .8rem;
            font-weight: 600;
        }

        .tl-time {
            font-size: .72rem;
            opacity: .6;
        }

        .tl-meta {
            font-size: .75rem;
            opacity: .75;
        }

        details>summary {
            cursor: pointer;
        }

        details>summary::-webkit-details-marker {
            color: var(--tg-primary);
        }

        /* ── Summary cards ── */
        .stat-card {
            background: var(--tg-glass-bg);
            border: 1px solid var(--tg-glass-border);
            border-radius: 1rem;
            padding: 1rem 1.2rem;
        }

        .action-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            border-radius: 999px;
            padding: .22rem .6rem;
            font-size: .72rem;
            font-weight: 600;
        }

        .action-pill.pill-normal {
            background: rgba(var(--tg-primary-rgb), .12);
            color: var(--tg-primary);
        }

        .action-pill.pill-warning {
            background: rgba(var(--tg-warning-rgb), .15);
            color: var(--tg-warning);
        }

        .action-pill.pill-danger {
            background: rgba(var(--tg-danger-rgb), .15);
            color: var(--tg-danger);
        }
    </style>
</head>

<body class="bg-body">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container py-4 py-md-5 animate-fade-up" style="max-width:1000px">

        <!-- ── Page header ───────────────────────────────────────────────────── -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
            <div>
                <div class="text-muted small mb-1">
                    <a href="<?= htmlspecialchars($assignmentUrl) ?>" class="text-decoration-none text-muted">
                        <i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($attempt['assignment_title']) ?>
                    </a>
                </div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-journal-text me-2 text-primary"></i>
                    Журнал – <?= htmlspecialchars($studentLabel) ?>
                </h1>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                        <i class="bi bi-file-text me-1"></i><?= htmlspecialchars($attempt['test_title']) ?>
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                        <i class="bi bi-hash me-1"></i>Опит <?= (int) $attempt['attempt_no'] ?>
                    </span>
                    <?php
                    $statusLabels = [
                        'submitted' => ['Предаден', 'success'],
                        'in_progress' => ['В процес', 'warning'],
                        'abandoned' => ['Изоставен', 'danger'],
                    ];
                    [$stLabel, $stColor] = $statusLabels[$attempt['status']] ?? [ucfirst($attempt['status']), 'secondary'];
                    ?>
                    <span
                        class="badge bg-<?= $stColor ?>-subtle text-<?= $stColor ?> border border-<?= $stColor ?>-subtle">
                        <?= $stLabel ?>
                    </span>
                    <span
                        class="badge bg-<?= $riskColor ?>-subtle text-<?= $riskColor ?> border border-<?= $riskColor ?>-subtle">
                        <i
                            class="bi bi-shield-<?= $riskLevel === 'low' ? 'check' : ($riskLevel === 'medium' ? 'exclamation' : 'x') ?> me-1"></i><?= $riskLabel ?>
                    </span>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="attempt_review.php?id=<?= (int) $attempt['id'] ?>"
                    class="btn btn-sm btn-outline-primary rounded-pill px-3">
                    <i class="bi bi-eye me-1"></i>Преглед на отговорите
                </a>
                <a href="?attempt_id=<?= (int) $attempt['id'] ?>&format=json"
                    class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                    <i class="bi bi-filetype-json me-1"></i>JSON
                </a>
            </div>
        </div>

        <?php if (!$hasLogs): ?>
            <div class="glass-card p-5 text-center text-muted">
                <i class="bi bi-journal-x display-5 mb-3 d-block"></i>
                <p class="mb-0">Няма записани действия за този опит.</p>
            </div>
        <?php else: ?>

            <!-- ── Info row (device + timing) ────────────────────────────────────── -->
            <div class="row g-3 mb-4">
                <!-- Device card -->
                <div class="col-md-6">
                    <div class="stat-card h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-display text-primary fs-5"></i>
                            <strong class="small text-uppercase" style="letter-spacing:.06em">Устройство</strong>
                        </div>
                        <div class="fs-6 mb-1"><?= htmlspecialchars($browserLabel) ?></div>
                        <div class="small text-muted">IP: <?= htmlspecialchars($firstIp ?? '–') ?></div>
                        <?php if ($deviceInfo): ?>
                            <div class="mt-2 d-flex flex-wrap gap-2">
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                    <?= (int) ($deviceInfo['screen_w'] ?? 0) ?>×<?= (int) ($deviceInfo['screen_h'] ?? 0) ?> px
                                </span>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                    <?= htmlspecialchars((string) ($deviceInfo['pixel_ratio'] ?? 1)) ?>x DPR
                                </span>
                                <?php if (!empty($deviceInfo['touch'])): ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                                        <i class="bi bi-phone me-1"></i>Тъч
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($deviceInfo['orientation'])): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <?= str_contains((string) ($deviceInfo['orientation'] ?? ''), 'landscape') ? 'Хоризонтален' : 'Вертикален' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Timing card -->
                <div class="col-md-6">
                    <div class="stat-card h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-clock-history text-primary fs-5"></i>
                            <strong class="small text-uppercase" style="letter-spacing:.06em">Времеви данни</strong>
                        </div>
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="small text-muted mb-1">Начало</div>
                                <div class="small fw-semibold"><?= htmlspecialchars(substr($startedAt ?? '–', 0, 16)) ?>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="small text-muted mb-1">Предаване</div>
                                <div class="small fw-semibold"><?= htmlspecialchars(substr($submittedAt ?? '–', 0, 16)) ?>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="small text-muted mb-1">Продължителност</div>
                                <div class="small fw-semibold"><?= htmlspecialchars($durationStr) ?></div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                <?= count($logs) ?> действия
                            </span>
                            <?php if ($suspectCount > 0): ?>
                                <span
                                    class="badge bg-<?= $riskColor ?>-subtle text-<?= $riskColor ?> border border-<?= $riskColor ?>-subtle">
                                    <?= $suspectCount ?> подозрителни
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Action summary ────────────────────────────────────────────────── -->
            <div class="glass-card p-4 mb-4">
                <h2 class="h6 mb-3 text-uppercase" style="letter-spacing:.07em;opacity:.7">
                    <i class="bi bi-bar-chart-line me-1"></i>Обобщение на действията
                </h2>
                <?php if (!empty($groupedSummary['danger'])): ?>
                    <div class="mb-2">
                        <span class="small fw-bold text-danger me-2"><i
                                class="bi bi-exclamation-triangle-fill me-1"></i>Подозрителни</span>
                        <?php foreach ($groupedSummary['danger'] as $a => $cnt): ?>
                            <span class="action-pill pill-danger me-1 mb-1">
                                <i class="bi <?= action_icon($a) ?>"></i>
                                <?= htmlspecialchars(action_label($a)) ?>
                                <span class="ms-1 opacity-75"><?= $cnt ?>×</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($groupedSummary['warning'])): ?>
                    <div class="mb-2">
                        <span class="small fw-bold text-warning me-2"><i
                                class="bi bi-exclamation-circle-fill me-1"></i>Внимателни</span>
                        <?php foreach ($groupedSummary['warning'] as $a => $cnt): ?>
                            <span class="action-pill pill-warning me-1 mb-1">
                                <i class="bi <?= action_icon($a) ?>"></i>
                                <?= htmlspecialchars(action_label($a)) ?>
                                <span class="ms-1 opacity-75"><?= $cnt ?>×</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($groupedSummary['normal'])): ?>
                    <div>
                        <span class="small fw-bold text-primary me-2"><i
                                class="bi bi-check-circle-fill me-1"></i>Нормални</span>
                        <?php foreach ($groupedSummary['normal'] as $a => $cnt): ?>
                            <span class="action-pill pill-normal me-1 mb-1">
                                <i class="bi <?= action_icon($a) ?>"></i>
                                <?= htmlspecialchars(action_label($a)) ?>
                                <span class="ms-1 opacity-75"><?= $cnt ?>×</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Timeline ──────────────────────────────────────────────────────── -->
            <div class="glass-card p-4">
                <h2 class="h6 mb-4 text-uppercase" style="letter-spacing:.07em;opacity:.7">
                    <i class="bi bi-list-ul me-1"></i>Хронологичен журнал
                </h2>
                <div class="tl-wrapper">
                    <?php
                    $rowNum = 0;
                    $startTs = null;
                    $prevTs = null;
                    foreach ($logs as $row):
                        $rowNum++;
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
                            $metaFallback = (string) $metaDecoded;
                        }

                        $sev = action_severity($row['action'], is_array($metaDecoded) ? $metaDecoded : null);
                        $icon = action_icon($row['action']);
                        $label = action_label($row['action']);
                        $rowTs = strtotime($row['created_at']);
                        $sinceStart = ($startTs !== null) ? format_duration((float) ($rowTs - $startTs)) : '0с';
                        $elapsed = ($prevTs !== null) ? format_duration((float) ($rowTs - $prevTs)) : null;
                        if ($startTs === null)
                            $startTs = $rowTs;
                        $prevTs = $rowTs;
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot sev-<?= $sev ?>"><i class="bi <?= $icon ?>"></i></div>
                            <div class="tl-card sev-<?= $sev ?>">
                                <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="tl-action"><?= htmlspecialchars($label) ?></span>
                                        <?php if ($row['question_id'] !== null): ?>
                                            <span class="badge bg-secondary-subtle text-muted border border-secondary-subtle"
                                                style="font-size:.65rem">
                                                В<?= (int) $row['question_id'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="tl-time"><?= htmlspecialchars(substr($row['created_at'], 11, 8)) ?></span>
                                        <span class="tl-time ms-2 opacity-50">+<?= $sinceStart ?></span>
                                        <?php if ($elapsed !== null): ?>
                                            <div class="tl-time opacity-40">∆<?= $elapsed ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($metaLines || $metaFallback): ?>
                                    <details class="mt-1">
                                        <summary class="tl-meta text-muted">Детайли</summary>
                                        <ul class="list-unstyled tl-meta mt-1 mb-0 ps-1">
                                            <?php if ($metaLines): foreach ($metaLines as $line): ?>
                                                    <li><i class="bi bi-dot me-1 opacity-50"></i><?= htmlspecialchars($line) ?></li>
                                                <?php endforeach; elseif ($metaFallback): ?>
                                                <li><code class="small"><?= htmlspecialchars($metaFallback) ?></code></li>
                                            <?php endif; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>