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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
    <style>
        .stat-card-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 1.5rem;
        }
    </style>
</head>

<body class="bg-body">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-5">
        <!-- Header -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-5 animate-fade-up">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h1 class="display-6 fw-bold m-0"><?= htmlspecialchars($assignment['title']) ?></h1>
                    <?php if ($strict_mode_active): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill text-uppercase tracking-wider small px-3">Стриктен режим</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted d-flex align-items-center gap-2">
                    <i class="bi bi-folder2-open"></i>
                    Test: <span class="fw-semibold text-body"><?= htmlspecialchars($assignment['test_title']) ?></span>
                    <?php if ($selectedClassLabel): ?>
                        <span class="text-secondary mx-1">•</span>
                        <i class="bi bi-people"></i> <?= htmlspecialchars($selectedClassLabel) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary rounded-pill px-4" href="dashboard.php"><i class="bi bi-arrow-left me-2"></i> Табло</a>
                <a class="btn btn-primary rounded-pill px-4 shadow-sm" href="assignments_create.php?id=<?= (int)$assignment['id'] ?>"><i class="bi bi-pencil-square me-2"></i> Редакция</a>
            </div>
        </div>

        <!-- Filters (Glass Panel) -->
        <?php if ($classes): ?>
            <div class="glass-card p-4 mb-5 animate-fade-up delay-100">
                <form method="get" class="row gy-2 gx-3 align-items-end">
                    <input type="hidden" name="id" value="<?= (int)$assignment['id'] ?>" />
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <label for="class_id" class="form-label small text-muted text-uppercase tracking-wider fw-bold">Филтър по Клас</label>
                        <select name="class_id" id="class_id" class="form-select border-0 bg-white bg-opacity-50" onchange="this.form.submit()">
                            <?php foreach ($classes as $option): ?>
                                <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === $selectedClassId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option['grade'] . $option['section']) ?> • <?= htmlspecialchars($option['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (count($classes) > 1): ?>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-light rounded-pill px-3"><i class="bi bi-arrow-repeat"></i></button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php elseif (!$classes): ?>
            <div class="alert alert-info border-0 bg-info bg-opacity-10 d-flex align-items-center gap-3 rounded-4 mb-5">
                <i class="bi bi-info-circle-fill fs-4 text-info"></i>
                <div class="text-info-emphasis">Заданието е възложено индивидуално на ученици, без конкретен клас.</div>
            </div>
        <?php endif; ?>

        <!-- QR & Share (Glass Card) -->
        <?php $assignmentShareLink = app_url('assignment.php?id=' . $assignmentId); ?>
        <div class="glass-card p-0 mb-5 overflow-hidden animate-fade-up delay-200">
            <div class="row g-0">
                <div class="col-md-3 bg-secondary bg-opacity-10 p-4 d-flex align-items-center justify-content-center border-end border-light border-opacity-10">
                    <div id="assignmentShareQr" data-url="<?= htmlspecialchars($assignmentShareLink) ?>" class="p-2 bg-white rounded-3 shadow-sm d-inline-block"></div>
                </div>
                <div class="col-md-9 p-4 d-flex flex-column justify-content-center">
                    <h5 class="fw-bold mb-2">QR Код за бърз достъп</h5>
                    <p class="text-muted small mb-4">Споделете този линк или QR код с учениците. Само тези, които са добавени към заданието, ще имат достъп.</p>
                    
                    <div class="input-group shadow-sm rounded-pill overflow-hidden" style="max-width: 600px;">
                        <span class="input-group-text bg-white border-0 ps-3"><i class="bi bi-link-45deg"></i></span>
                        <input type="text" class="form-control border-0 bg-white ps-1" id="assignmentShareLink" value="<?= htmlspecialchars($assignmentShareLink) ?>" readonly />
                        <button class="btn btn-primary px-4" type="button" data-copy-target="#assignmentShareLink">Копирай</button>
                        <a class="btn btn-outline-secondary px-3" href="<?= htmlspecialchars($assignmentShareLink) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="row g-4 mb-5 animate-fade-up delay-300">
            <div class="col-sm-6 col-lg-3">
                <div class="glass-card p-4 h-100 hover-lift">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="text-muted small text-uppercase tracking-wider fw-bold">Общо опити</div>
                        <div class="stat-card-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-people"></i></div>
                    </div>
                    <div class="display-6 fw-bold"><?= $attemptsCount ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="glass-card p-4 h-100 hover-lift">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="text-muted small text-uppercase tracking-wider fw-bold">Предадени</div>
                        <div class="stat-card-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check2-circle"></i></div>
                    </div>
                    <div class="display-6 fw-bold"><?= $submittedCount ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="glass-card p-4 h-100 hover-lift">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="text-muted small text-uppercase tracking-wider fw-bold">Среден успех</div>
                        <div class="stat-card-icon bg-info bg-opacity-10 text-info"><i class="bi bi-graph-up-arrow"></i></div>
                    </div>
                    <div class="display-6 fw-bold"><?= $averagePercent !== null ? $averagePercent . '<span class="fs-5 text-muted ms-1">%</span>' : '—' ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="glass-card p-4 h-100 hover-lift <?= $strictViolations > 0 ? 'border-danger' : '' ?>">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="text-muted small text-uppercase tracking-wider fw-bold">Нарушения</div>
                        <div class="stat-card-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-exclamation-triangle"></i></div>
                    </div>
                    <div class="display-6 fw-bold text-danger"><?= $strictViolations ?></div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row g-4 mb-5 animate-fade-up delay-300">
            <div class="col-lg-8">
                <div class="glass-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold m-0">Резултати</h5>
                        <?php if ($bestPercent !== null): ?>
                            <span class="badge bg-success rounded-pill px-3 shadow-sm">Най-добър: <?= $bestPercent ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div style="height: 300px;">
                        <?php if ($attemptsCount === 0): ?>
                            <div class="h-100 d-flex align-items-center justify-content-center text-muted border rounded-3 bg-light bg-opacity-50">
                                Няма достатъчно данни за графика.
                            </div>
                        <?php else: ?>
                            <canvas id="attemptScoresChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="glass-card p-4 h-100">
                    <h5 class="fw-bold mb-4">Разпределение</h5>
                    <?php if (array_sum($gradeDistribution) === 0): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-bar-chart fs-1 opacity-25 d-block mb-2"></i>
                            Няма оценки
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ([6, 5, 4, 3, 2] as $grade): 
                                $count = $gradeDistribution[$grade] ?? 0;
                                $maxCount = max($gradeDistribution) ?: 1;
                                $width = ($count / $maxCount) * 100;
                                $colorClass = match($grade) {
                                    6 => 'bg-success',
                                    5 => 'bg-primary',
                                    4 => 'bg-info',
                                    3 => 'bg-warning',
                                    2 => 'bg-danger',
                                    default => 'bg-secondary'
                                };
                            ?>
                                <div>
                                    <div class="d-flex justify-content-between small fw-bold mb-1">
                                        <span>Оценка <?= $grade ?></span>
                                        <span class="text-muted"><?= $count ?></span>
                                    </div>
                                    <div class="progress" style="height: 8px; background: rgba(0,0,0,0.05);">
                                        <div class="progress-bar <?= $colorClass ?> rounded-pill" style="width: <?= $width ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Attempts List -->
        <div class="glass-card animate-fade-up delay-300">
            <div class="p-4 border-bottom border-light border-opacity-10 bg-secondary bg-opacity-10 d-flex flex-wrap gap-3 justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold m-0">Списък с предали</h5>
                    <div class="small text-muted mt-1">Детайлна справка за всички опити</div>
                </div>
                <div class="d-flex gap-3 small text-muted">
                    <?php if ($assignment['due_at']): ?>
                        <div class="d-flex align-items-center gap-1"><i class="bi bi-clock"></i> Краен срок: <?= format_date($assignment['due_at']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($attemptsCount === 0): ?>
                <div class="p-5 text-center">
                    <div class="display-1 text-muted opacity-25 mb-3"><i class="bi bi-inbox"></i></div>
                    <h5 class="mb-2">Все още няма опити</h5>
                    <p class="text-muted">Щом учениците започнат да решават, резултатите ще се появят тук.</p>
                </div>
            <?php else: ?>
                <?php 
                // Debug if user sees empty table despite count > 0
                if (isset($_GET['debug'])) {
                    echo '<pre class="bg-dark text-white p-3 m-3 rounded">';
                    print_r($attempts[0] ?? 'No attempts data?');
                    echo '</pre>';
                }
                ?>
                <div class="table-responsive" style="overflow: visible;">
                    <!-- Force color:inherit to ensure text is white in dark mode -->
                    <table class="table table-hover align-middle mb-0" style="background: transparent; color: inherit;">
                        <thead class="text-uppercase small tracking-wider opacity-75" style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <tr>
                                <th class="ps-4 border-0">Ученик</th>
                                <th class="border-0">Време</th>
                                <th class="border-0">Резултат</th>
                                <th class="border-0">Оценка</th>
                                <th class="border-0">Статус</th>
                                <th class="pe-4 border-0 text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            <?php foreach ($attempts as $idx => $attemptRow): ?>
                                <?php
                                $fName = (string)($attemptRow['first_name'] ?? '');
                                $lName = (string)($attemptRow['last_name'] ?? '');
                                $fullName = trim($fName . ' ' . $lName);
                                if ($fullName === '') $fullName = 'Anonymous';
                                $email = (string)($attemptRow['email'] ?? '');
                                
                                $percentValue = $attemptRow['percent'];
                                $autoGrade = $attemptRow['auto_grade'];
                                $teacherGrade = $attemptRow['teacher_grade'];
                                $statusKey = (string)($attemptRow['status'] ?? '');
                                
                                $statusLabel = match ($statusKey) {
                                    'in_progress' => 'Работи',
                                    'submitted' => 'Предаден',
                                    'graded' => 'Оценен',
                                    default => ucfirst($statusKey),
                                };
                                $statusClass = match ($statusKey) {
                                    'in_progress' => 'bg-info bg-opacity-10 text-info',
                                    'submitted' => 'bg-warning bg-opacity-10 text-warning',
                                    'graded' => 'bg-success bg-opacity-10 text-success',
                                    default => 'bg-secondary bg-opacity-10 text-secondary',
                                };
                                ?>
                                <tr class="border-light border-opacity-50">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-initials rounded-circle bg-white text-primary border shadow-sm d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;">
                                                <?= mb_substr($fullName, 0, 1) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold fs-6"><?= htmlspecialchars($fullName) ?></div>
                                                <div class="small text-secondary"><?= htmlspecialchars($email) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <?php if (!empty($attemptRow['submitted_at'])): ?>
                                                <div><?= format_date($attemptRow['submitted_at']) ?></div>
                                                <div class="small text-secondary">Предаден</div>
                                            <?php else: ?>
                                                <div><?= format_date($attemptRow['started_at']) ?></div>
                                                <div class="small text-secondary">Започнат</div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($percentValue !== null): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="width: 60px; height: 6px; background: rgba(0,0,0,0.1);">
                                                    <div class="progress-bar <?= $percentValue >= 50 ? 'bg-success' : 'bg-danger' ?> rounded-pill" style="width: <?= $percentValue ?>%"></div>
                                                </div>
                                                <span class="fw-bold small text-body"><?= $percentValue ?>%</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="opacity-50">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($teacherGrade): ?>
                                            <span class="badge bg-primary rounded-pill px-3"><?= (int)$teacherGrade ?></span>
                                        <?php elseif ($autoGrade): ?>
                                            <span class="badge bg-light text-secondary border rounded-pill px-3"><?= $autoGrade ?> (Авт)</span>
                                        <?php else: ?>
                                            <span class="small opacity-50">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $statusClass ?> rounded-pill px-2 fw-normal">
                                            <?= htmlspecialchars($statusLabel) ?>
                                        </span>
                                        <?php if (!empty($attemptRow['strict_violation'])): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Нарушение на стриктен режим"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light rounded-circle" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                                                <li><a class="dropdown-item" href="attempt_review.php?id=<?= (int)$attemptRow['id'] ?>"><i class="bi bi-eye me-2 text-muted"></i> Преглед</a></li>
                                                <li><a class="dropdown-item" href="student_attempt.php?id=<?= (int)$attemptRow['id'] ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-2 text-muted"></i> Виж като ученик</a></li>
                                                <?php if (!empty($attemptRow['submitted_at'])): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item small" href="test_log_event.php?attempt_id=<?= (int)$attemptRow['id'] ?>"><i class="bi bi-activity me-2 text-muted"></i> Журнал</a></li>
                                                <?php endif; ?>
                                            </ul>
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

    <?php include __DIR__ . '/components/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        // QR Code
        (function() {
            var qrBox = document.getElementById('assignmentShareQr');
            if (qrBox && typeof QRCode === 'function') {
                var shareUrl = qrBox.getAttribute('data-url');
                if (shareUrl) {
                    qrBox.innerHTML = '';
                    new QRCode(qrBox, {
                        text: shareUrl,
                        width: 128,
                        height: 128,
                        correctLevel: QRCode.CorrectLevel.M,
                        colorDark: "#1e293b",
                        colorLight: "#ffffff"
                    });
                }
            }
            
            // Clipboard Copy
            document.querySelectorAll('[data-copy-target]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = document.querySelector(btn.getAttribute('data-copy-target'));
                    if (!target) return;
                    navigator.clipboard.writeText(target.value).then(function() {
                        var originalText = btn.innerHTML;
                        btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                        btn.classList.add('btn-success');
                        btn.classList.remove('btn-primary');
                        setTimeout(function() {
                            btn.innerHTML = originalText;
                            btn.classList.remove('btn-success');
                            btn.classList.add('btn-primary');
                        }, 2000);
                    });
                });
            });
        })();

        // Chart
        <?php if ($attemptsCount > 0): ?>
        (function() {
            const ctx = document.getElementById('attemptScoresChart');
            if (!ctx) return;
            
            // Robust Dark Mode Detection
            const isDarkMode = document.documentElement.getAttribute('data-bs-theme') === 'dark' 
                            || document.body.getAttribute('data-bs-theme') === 'dark'
                            || window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            // Hardcoded accessible colors to ensure visibility regardless of CSS var failure
            const textColor = isDarkMode ? '#f1f5f9' : '#1e293b'; // Slate-100 vs Slate-900
            const gridColor = isDarkMode ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';
            
            const style = getComputedStyle(document.body);
            const primaryRgb = style.getPropertyValue('--tg-primary-rgb') || '148, 163, 184';
            const primaryColor = style.getPropertyValue('--tg-primary') || '#94a3b8';

            const labels = <?= $chartLabelsJson ?>;
            const dataValues = <?= $chartPercentsJson ?>;
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Успеваемост (%)',
                        data: dataValues,
                        backgroundColor: `rgba(${primaryRgb}, 0.25)`,
                        borderColor: primaryColor,
                        borderWidth: 2,
                        borderRadius: 6,
                        hoverBackgroundColor: `rgba(${primaryRgb}, 0.45)`,
                        maxBarThickness: 40
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: {
                                color: gridColor,
                                drawBorder: false
                            },
                            ticks: {
                                color: textColor,
                                callback: function(value) { return value + '%' }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { 
                                display: false,
                                color: textColor
                            }
                        }
                    }
                }
            });
        })();
        <?php endif; ?>
    </script>
</body>

</html>
