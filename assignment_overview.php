<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'teacher') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = db();
ensure_attempts_grade($pdo);
ensure_test_theme_and_q_media($pdo);

function percent($score, $max) {
    if ($score === null || $max === null || $max <= 0) {
        return null;
    }
    return round(($score / $max) * 100, 2);
}

function grade_from_percent(?float $percent): ?int {
    if ($percent === null) {
        return null;
    }
    if ($percent >= 90) return 6;
    if ($percent >= 80) return 5;
    if ($percent >= 65) return 4;
    if ($percent >= 50) return 3;
    return 2;
}

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($assignmentId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT a.*, t.title AS test_title, t.is_strict_mode
                       FROM assignments a
                       JOIN tests t ON t.id = a.test_id
                       WHERE a.id = :id AND a.assigned_by_teacher_id = :tid');
$stmt->execute([
    ':id' => $assignmentId,
    ':tid' => (int)$user['id'],
]);
$assignment = $stmt->fetch();
if (!$assignment) {
    header('Location: dashboard.php');
    exit;
}
$strict_mode_active = !empty($assignment['is_strict_mode']);

$classesStmt = $pdo->prepare('SELECT c.id, c.grade, c.section, c.school_year, c.name
                              FROM assignment_classes ac
                              JOIN classes c ON c.id = ac.class_id
                              WHERE ac.assignment_id = :aid AND c.teacher_id = :tid
                              ORDER BY c.school_year DESC, c.grade, c.section');
$classesStmt->execute([
    ':aid' => $assignmentId,
    ':tid' => (int)$user['id'],
]);
$classes = $classesStmt->fetchAll();
$classIds = array_map(fn($c) => (int)$c['id'], $classes);

$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($selectedClassId && !in_array($selectedClassId, $classIds, true)) {
    $selectedClassId = 0;
}
if (!$selectedClassId && $classes) {
    $selectedClassId = (int)$classes[0]['id'];
}

$attemptsSql = 'SELECT atp.id, atp.student_id, atp.started_at, atp.submitted_at, atp.status,
                       atp.score_obtained, atp.max_score, atp.teacher_grade, atp.strict_violation,
                       u.first_name, u.last_name, u.email
                FROM attempts atp
                JOIN users u ON u.id = atp.student_id
                WHERE atp.assignment_id = :aid';
$attemptParams = [':aid' => $assignmentId];
if ($selectedClassId) {
    $attemptsSql .= ' AND EXISTS (
        SELECT 1
        FROM class_students cs
        WHERE cs.student_id = atp.student_id AND cs.class_id = :cid
    )';
    $attemptParams[':cid'] = $selectedClassId;
} else {
    // If there are no classes, we still want attempts only for individually assigned students
    $attemptsSql .= ' AND (
        EXISTS (SELECT 1 FROM assignment_students ast WHERE ast.assignment_id = atp.assignment_id AND ast.student_id = atp.student_id)
        OR NOT EXISTS (SELECT 1 FROM assignment_students ast2 WHERE ast2.assignment_id = atp.assignment_id)
    )';
}
$attemptsSql .= ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC, atp.id DESC';
$attemptStmt = $pdo->prepare($attemptsSql);
$attemptStmt->execute($attemptParams);
$attempts = $attemptStmt->fetchAll();

$attemptsCount = count($attempts);
$submittedCount = 0;
$gradedCount = 0;
$needsGrade = 0;
$strictViolations = 0;
$percentSum = 0.0;
$percentCount = 0;
$bestPercent = null;
$worstPercent = null;
$gradeDistribution = [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
$chartLabels = [];
$chartPercents = [];

foreach ($attempts as $idx => &$attempt) {
    $percentValue = percent($attempt['score_obtained'], $attempt['max_score']);
    if (!empty($attempt['strict_violation'])) {
        $strictViolations++;
    }
    $attempt['percent'] = $percentValue;
    $autoGrade = grade_from_percent($percentValue);
    $attempt['auto_grade'] = $autoGrade;

    if ($attempt['status'] === 'submitted' || $attempt['status'] === 'graded') {
        $submittedCount++;
    }
    if ($attempt['teacher_grade'] !== null || $attempt['status'] === 'graded') {
        $gradedCount++;
    } elseif ($attempt['status'] === 'submitted' && $attempt['teacher_grade'] === null) {
        $needsGrade++;
    }
    if ($percentValue !== null) {
        $percentSum += $percentValue;
        $percentCount++;
        if ($bestPercent === null || $percentValue > $bestPercent) {
            $bestPercent = $percentValue;
        }
        if ($worstPercent === null || $percentValue < $worstPercent) {
            $worstPercent = $percentValue;
        }
    }
    if ($autoGrade !== null) {
        $gradeDistribution[$autoGrade] = ($gradeDistribution[$autoGrade] ?? 0) + 1;
    }

    $chartLabels[] = ($idx + 1) . '. ' . trim($attempt['first_name'] . ' ' . $attempt['last_name']);
    $chartPercents[] = $percentValue !== null ? $percentValue : null;
}
unset($attempt);

$averagePercent = $percentCount ? round($percentSum / $percentCount, 2) : null;

$chartLabelsJson = json_encode($chartLabels, JSON_UNESCAPED_UNICODE);
$chartPercentsJson = json_encode($chartPercents, JSON_NUMERIC_CHECK);
$gradeDistributionJson = json_encode($gradeDistribution, JSON_NUMERIC_CHECK);

$selectedClassLabel = null;
if ($selectedClassId) {
    foreach ($classes as $classRow) {
        if ((int)$classRow['id'] === $selectedClassId) {
$selectedClassLabel = sprintf('%s%s - %s', $classRow['grade'], $classRow['section'], $classRow['school_year']);
            break;
        }
    }
}

$pageTitle = 'Задание: ' . $assignment['title'];
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
    <style>
        :root {
            --page-bg: #f5f7fb;
            --surface-1: #ffffff;
            --surface-2: #eef2ff;
            --surface-card-border: rgba(15, 23, 42, 0.08);
            --surface-border-strong: rgba(15, 23, 42, 0.15);
            --text-main: #0f172a;
            --text-muted: #5d6685;
            --accent-strong: #2563eb;
            --accent-strong-border: #1d4ed8;
            --accent-soft: rgba(37, 99, 235, 0.2);
            --accent-contrast: #ffffff;
            --success-strong: #15803d;
            --success-soft: rgba(34, 197, 94, 0.18);
            --danger-strong: #b42318;
            --danger-soft: rgba(252, 129, 129, 0.22);
            --warning-strong: #a15c07;
            --warning-soft: rgba(251, 191, 36, 0.25);
            --neutral-soft: rgba(148, 163, 184, 0.23);
            --focus-ring: rgba(37, 99, 235, 0.38);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --page-bg: #050a18;
                --surface-1: #0c1424;
                --surface-2: #131f36;
                --surface-card-border: rgba(148, 163, 184, 0.25);
                --surface-border-strong: rgba(148, 163, 184, 0.35);
                --text-main: #f8fafc;
                --text-muted: #9da8c2;
                --accent-strong: #60a5fa;
                --accent-strong-border: #3b82f6;
                --accent-soft: rgba(96, 165, 250, 0.35);
                --accent-contrast: #02101f;
                --success-strong: #4ade80;
                --success-soft: rgba(74, 222, 128, 0.25);
                --danger-strong: #fb7185;
                --danger-soft: rgba(248, 113, 113, 0.3);
                --warning-strong: #fbbf24;
                --warning-soft: rgba(251, 191, 36, 0.4);
                --neutral-soft: rgba(148, 163, 184, 0.25);
                --focus-ring: rgba(96, 165, 250, 0.45);
            }
        }
        body {
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans", sans-serif;
            background: radial-gradient(circle at top, rgba(37, 99, 235, 0.14), transparent 42%) var(--page-bg);
            color: var(--text-main);
        }
        body.app-surface {
            min-height: 100vh;
        }
        main.container {
            max-width: 1200px;
        }
        .card {
            background-color: var(--surface-1);
            border-color: var(--surface-card-border);
            border-radius: 1.1rem;
            box-shadow: 0 25px 40px rgba(15, 23, 42, 0.08);
        }
        .card-header {
            background: var(--surface-2) !important;
            border-bottom-color: var(--surface-border-strong) !important;
            font-weight: 600;
        }
        .shadow-soft {
            box-shadow: 0 20px 35px rgba(15, 23, 42, 0.12);
        }
        .text-muted, .small.text-muted {
            color: var(--text-muted) !important;
        }
        .btn {
            border-radius: 0.65rem;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--accent-strong);
            border-color: var(--accent-strong-border);
            color: var(--accent-contrast);
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.35);
        }
        .btn-primary:hover, .btn-primary:focus-visible {
            background-color: #1f4fd8;
            border-color: #1f4fd8;
        }
        .btn-outline-primary {
            color: var(--accent-strong);
            border-color: var(--accent-strong);
        }
        .btn-outline-primary:hover, .btn-outline-primary:focus-visible {
            background-color: var(--accent-strong);
            color: var(--accent-contrast);
        }
        .btn-outline-secondary {
            color: var(--text-muted);
            border-color: var(--surface-border-strong);
        }
        .btn-outline-secondary:hover, .btn-outline-secondary:focus-visible {
            color: var(--text-main);
            border-color: var(--text-muted);
            background: rgba(148, 163, 184, 0.12);
        }
        .form-control, .form-select {
            border-radius: 0.75rem;
            border-color: var(--surface-card-border);
            background-color: var(--surface-1);
            color: var(--text-main);
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--accent-strong);
            box-shadow: 0 0 0 0.2rem var(--focus-ring);
        }
        :focus-visible {
            outline: 3px solid transparent;
            box-shadow: 0 0 0 0.25rem var(--focus-ring);
        }
        .badge-soft {
            border-radius: 999px;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border: 1px solid transparent;
            letter-spacing: 0.02em;
        }
        .badge-soft-success {
            background: var(--success-soft);
            color: var(--success-strong);
            border-color: rgba(21, 128, 61, 0.35);
        }
        .badge-soft-primary {
            background: var(--accent-soft);
            color: var(--accent-strong);
            border-color: rgba(37, 99, 235, 0.35);
        }
        .badge-soft-danger {
            background: var(--danger-soft);
            color: var(--danger-strong);
            border-color: rgba(180, 35, 24, 0.35);
        }
        .badge-soft-warning {
            background: var(--warning-soft);
            color: var(--warning-strong);
            border-color: rgba(161, 92, 7, 0.35);
        }
        .badge-soft-neutral {
            background: var(--neutral-soft);
            color: var(--text-main);
            border-color: rgba(148, 163, 184, 0.35);
        }
        #assignmentShareQr {
            background: var(--surface-2);
            border-color: var(--surface-card-border) !important;
        }
        .table {
            color: var(--text-main);
        }
        .table thead,
        .table-light {
            background-color: var(--surface-2) !important;
        }
        .table-striped > tbody > tr:nth-of-type(odd) {
            background-color: rgba(15, 23, 42, 0.02);
        }
        @media (prefers-color-scheme: dark) {
            .table-striped > tbody > tr:nth-of-type(odd) {
                background-color: rgba(255, 255, 255, 0.04);
            }
        }
        .table-hover > tbody > tr:hover {
            background-color: rgba(37, 99, 235, 0.08);
        }
        .table-danger {
            background-color: var(--danger-soft) !important;
            color: var(--danger-strong) !important;
        }
        .table-responsive {
            border-radius: 1.1rem;
            border: 1px solid var(--surface-card-border);
        }
        .alert {
            border: none;
            border-radius: 1rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }
        .stat-card .card-body {
            padding: 1.5rem;
        }
        .stat-card .h4 {
            font-size: 1.75rem;
        }
        .qr-helper {
            background: rgba(37, 99, 235, 0.08);
            border-radius: 0.75rem;
            padding: 0.65rem 1rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body class="app-surface">
<main class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><?= htmlspecialchars($assignment['title']) ?><?php if ($strict_mode_active): ?><span class="badge badge-soft badge-soft-danger ms-2 text-uppercase small">Стриктен режим</span><?php endif; ?></h1>
            <div class="text-muted small">
                Тест: <?= htmlspecialchars($assignment['test_title']) ?>
                <?php if ($selectedClassLabel): ?>
                    <span class="ms-2">| Клас: <?= htmlspecialchars($selectedClassLabel) ?></span>
                <?php endif; ?>
            </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="dashboard.php"><i class="bi bi-arrow-left"></i> Назад</a>
            <a class="btn btn-primary" href="assignments_create.php?id=<?= (int)$assignment['id'] ?>"><i class="bi bi-pencil-square"></i> Редакция</a>
    </div>

    <?php if ($classes): ?>
        <form method="get" class="row gy-2 gx-2 align-items-end mb-4">
            <input type="hidden" name="id" value="<?= (int)$assignment['id'] ?>" />
            <div class="col-sm-6 col-md-4 col-lg-3">
                <label for="class_id" class="form-label small mb-1">Клас</label>
                <select name="class_id" id="class_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($classes as $option): ?>
                        <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === $selectedClassId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option['grade'] . $option['section']) ?> - <?= htmlspecialchars($option['school_year']) ?> - <?= htmlspecialchars($option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (count($classes) > 1): ?>
                <div class="col-12 col-sm-auto">
                    <button type="submit" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Обнови</button>
                </div>
            <?php endif; ?>
        </form>
    <?php elseif (!$classes): ?>
        <div class="alert alert-info d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill"></i>
            <div>Заданието е възложено индивидуално на ученици, без конкретен клас.</div>
    <?php endif; ?>

    <?php $assignmentShareLink = app_url('assignment.php?id=' . $assignmentId); ?>
    <div class="card shadow-sm mb-4" id="assignment-share">
        <div class="card-body d-flex flex-column flex-lg-row gap-4 align-items-start">
            <div>
                <div id="assignmentShareQr" data-url="<?= htmlspecialchars($assignmentShareLink) ?>" class="p-2 border rounded bg-white"></div>
                <div class="small text-muted mt-2 qr-helper">Students scan the QR code to open this assignment after logging in.</div>
            </div>
            <div class="flex-grow-1 w-100">
                <h2 class="h5 mb-2">Assignment QR Link</h2>
                <p class="text-muted small mb-3">Share this link or QR code with your class. Only students assigned to the activity will be able to start it.</p>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="assignmentShareLink" value="<?= htmlspecialchars($assignmentShareLink) ?>" readonly />
                    <button type="button" class="btn btn-outline-secondary" data-copy-target="#assignmentShareLink"><i class="bi bi-clipboard"></i> Copy</button>
                </div>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($assignmentShareLink) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Open student view</a>
            </div>
        </div>
    </div>
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Общо опити</div>
                    <div class="h4 mb-0"><?= $attemptsCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Предадени</div>
                    <div class="h4 mb-0"><?= $submittedCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Среден резултат</div>
                    <div class="h4 mb-0"><?= $averagePercent !== null ? $averagePercent . '%' : '-' ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Чакат оценка</div>
                    <div class="h4 mb-0"><?= $needsGrade ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100 stat-card">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Строги нарушения</div>
                    <div class="h4 mb-0 text-danger"><?= $strictViolations ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 g-md-4 mb-4 flex-lg-nowrap">
        <div class="col-lg-7 d-flex">
            <div class="card shadow-sm flex-fill">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Резултати по опити</strong>
                    <?php if ($bestPercent !== null): ?>
                        <span class="badge badge-soft badge-soft-success">Най-добър: <?= $bestPercent ?>%</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($attemptsCount === 0): ?>
                        <div class="text-muted">Все още няма опити за показване.</div>
                    <?php else: ?>
                        <canvas id="attemptScoresChart" height="160" aria-label="Графика с резултати" role="img"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5 d-flex">
            <div class="card shadow-sm flex-fill">
                <div class="card-header bg-white"><strong>Разпределение на оценките</strong></div>
                <div class="card-body">
                    <?php if (array_sum($gradeDistribution) === 0): ?>
                        <div class="text-muted">Все още няма изчислени оценки.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ([6,5,4,3,2] as $grade): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Оценка <?= $grade ?></span>
                                    <span class="badge badge-soft badge-soft-primary"><?= (int)($gradeDistribution[$grade] ?? 0) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <small class="text-muted d-block mt-2">Оценките са изчислени автоматично според процента.</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2">
            <strong>Списък с опити</strong>
            <div class="text-muted small">
                <?php if ($assignment['open_at']): ?>
                    Отворено: <?= htmlspecialchars($assignment['open_at']) ?>
                <?php endif; ?>
                <?php if ($assignment['due_at']): ?>
                    <span class="ms-2">Краен срок: <?= htmlspecialchars($assignment['due_at']) ?></span>
                <?php endif; ?>
                <?php if ($assignment['close_at']): ?>
                    <span class="ms-2">Затваряне: <?= htmlspecialchars($assignment['close_at']) ?></span>
                <?php endif; ?>
            </div>
        <div class="card-body p-0">
            <?php if ($attemptsCount === 0): ?>
                <div class="p-4 text-center text-muted">Няма опити за това задание.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Ученик</th>
                                <th scope="col">Начало</th>
                                <th scope="col">Предадено</th>
                                <th scope="col">Резултат</th>
                                <th scope="col">Авт. оценка</th>
                                <th scope="col">Учителска оценка</th>
                                <th scope="col">Статус</th>
                                <th scope="col" class="text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attempts as $attempt): ?>
                                <?php
                                $fullName = trim($attempt['first_name'] . ' ' . $attempt['last_name']);
                                $percentValue = $attempt['percent'];
                                $autoGrade = $attempt['auto_grade'];
                                $teacherGrade = $attempt['teacher_grade'];
                                $strictViolation = !empty($attempt['strict_violation']);
                                $rowClass = $strictViolation ? 'table-danger' : '';
                                $statusLabel = match ($attempt['status']) {
                                    'in_progress' => 'Започнат',
                                    'submitted' => 'Предаден',
                                    'graded' => 'Оценен',
                                    default => ucfirst($attempt['status']),
                                };
                                ?>
                                <tr class="<?= $rowClass ?>">
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($fullName) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($attempt['email']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($attempt['started_at'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($attempt['submitted_at'] ?: '-') ?></td>
                                    <td>
                                        <?php if ($percentValue !== null): ?>
                                            <span class="badge badge-soft <?= $percentValue >= 50 ? 'badge-soft-success' : 'badge-soft-danger' ?>"><?= $percentValue ?>%</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $autoGrade !== null ? $autoGrade : '-' ?></td>
                                    <td><?= $teacherGrade !== null ? (int)$teacherGrade : '-' ?></td>
                                    <td><?= htmlspecialchars($statusLabel) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a class="btn btn-outline-secondary" href="attempt_review.php?id=<?= (int)$attempt['id'] ?>" title="Преглед"><i class="bi bi-eye"></i></a>
                                            <a class="btn btn-outline-primary" href="student_attempt.php?id=<?= (int)$attempt['id'] ?>" title="Преглед като ученик"><i class="bi bi-box-arrow-up-right"></i></a>
                                            <?php if (!empty($attempt['submitted_at'])): ?>
                                                <a class="btn btn-outline-warning" href="test_log_event.php?attempt_id=<?= (int)$attempt['id'] ?>" title="Журнал дейности"><i class="bi bi-activity"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function(){
    var qrBox = document.getElementById('assignmentShareQr');
    if (qrBox && typeof QRCode === 'function') {
        var shareUrl = qrBox.getAttribute('data-url');
        if (shareUrl) {
            qrBox.innerHTML = '';
            new QRCode(qrBox, { text: shareUrl, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
        }
    }
    document.querySelectorAll('[data-copy-target]').forEach(function(btn){
        btn.addEventListener('click', function(){
            var target = document.querySelector(btn.getAttribute('data-copy-target'));
            if (!target) return;
            var value = target.value || target.textContent || '';
            if (!value) return;
            var highlight = function(){
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');
                setTimeout(function(){
                    btn.classList.add('btn-outline-secondary');
                    btn.classList.remove('btn-success');
                }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(function(){ highlight(); }).catch(function(){});
            } else if (target.select) {
                target.select();
                try { document.execCommand('copy'); highlight(); } catch (err) {}
                if (window.getSelection) { window.getSelection().removeAllRanges(); }
                target.blur && target.blur();
            }
        });
    });
})();
</script>
<?php if ($attemptsCount > 0): ?>
<script>
(function() {
    const labels = <?= $chartLabelsJson ?>;
    const dataValues = <?= $chartPercentsJson ?>;
    const ctx = document.getElementById('attemptScoresChart');
    if (!ctx) return;
    const rootStyles = getComputedStyle(document.documentElement);
    const barBorder = (rootStyles.getPropertyValue('--accent-strong') || '#2563eb').trim() || '#2563eb';
    const barFill = (rootStyles.getPropertyValue('--accent-soft') || 'rgba(37, 99, 235, 0.25)').trim() || 'rgba(37, 99, 235, 0.25)';
    const axisColor = (rootStyles.getPropertyValue('--text-muted') || '#475467').trim() || '#475467';
    const gridColor = (rootStyles.getPropertyValue('--surface-card-border') || 'rgba(15, 23, 42, 0.15)').trim() || 'rgba(15, 23, 42, 0.15)';
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Резултат (%)',
                data: dataValues,
                backgroundColor: barFill,
                borderColor: barBorder,
                borderWidth: 2,
                borderRadius: 6,
                maxBarThickness: 28,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: 100,
                    ticks: {
                        color: axisColor,
                        callback: value => value + '%'
                    },
                    grid: {
                        color: gridColor,
                        drawBorder: false
                    }
                },
                x: {
                    ticks: {
                        color: axisColor,
                        autoSkip: true,
                        maxRotation: 30
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.parsed.y !== null ? ctx.parsed.y + '%' : '-'
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
