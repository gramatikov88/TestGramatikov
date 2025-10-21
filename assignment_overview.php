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
                       atp.score_obtained, atp.max_score, atp.teacher_grade,
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
            $selectedClassLabel = sprintf('%s%s • %s', $classRow['grade'], $classRow['section'], $classRow['school_year']);
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
</head>
<body class="bg-light">
<main class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><?= htmlspecialchars($assignment['title']) ?><?php if ($strict_mode_active): ?><span class="badge bg-danger ms-2">Стриктен режим</span><?php endif; ?></h1>
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
                            <?= htmlspecialchars($option['grade'] . $option['section']) ?> • <?= htmlspecialchars($option['school_year']) ?> – <?= htmlspecialchars($option['name']) ?>
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

    <div class="row g-3 g-md-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">DzD�%D_ D_D�D,�,D,</div>
                    <div class="h4 mb-0"><?= $attemptsCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">DYD_D'D�D'D�D�D,</div>
                    <div class="h4 mb-0"><?= $submittedCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">D��?D�D'D�D� �?D�D���D��,D��,</div>
                    <div class="h4 mb-0"><?= $averagePercent !== null ? $averagePercent . '%' : '�?' ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">D-D� D_�+D�D��?D�D�D�D�</div>
                    <div class="h4 mb-0"><?= $needsGrade ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Строги нарушения</div>
                    <div class="h4 mb-0 text-danger"><?= $strictViolations ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Резултати по опити</strong>
                    <?php if ($bestPercent !== null): ?>
                        <span class="badge bg-success-subtle border border-success text-success-emphasis">Най-добър: <?= $bestPercent ?>%</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($attemptsCount === 0): ?>
                        <div class="text-muted">Все още няма опити за показване.</div>
                    <?php else: ?>
                        <canvas id="attemptScoresChart" height="200" aria-label="Графика с резултати" role="img"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Разпределение на оценките</strong></div>
                <div class="card-body">
                    <?php if (array_sum($gradeDistribution) === 0): ?>
                        <div class="text-muted">Все още няма изчислени оценки.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ([6,5,4,3,2] as $grade): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Оценка <?= $grade ?></span>
                                    <span class="badge bg-primary rounded-pill"><?= (int)($gradeDistribution[$grade] ?? 0) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <small class="text-muted d-block mt-2">Оценките са изчислени автоматично според процента.</small>
                    <?php endif; ?>
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
                                    <td><?= htmlspecialchars($attempt['started_at'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($attempt['submitted_at'] ?: '—') ?></td>
                                    <td>
                                        <?php if ($percentValue !== null): ?>
                                            <span class="badge <?= $percentValue >= 50 ? 'bg-success' : 'bg-danger' ?>"><?= $percentValue ?>%</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $autoGrade !== null ? $autoGrade : '—' ?></td>
                                    <td><?= $teacherGrade !== null ? (int)$teacherGrade : '—' ?></td>
                                    <td><?= htmlspecialchars($statusLabel) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a class="btn btn-outline-secondary" href="attempt_review.php?id=<?= (int)$attempt['id'] ?>"><i class="bi bi-eye"></i></a>
                                            <a class="btn btn-outline-primary" href="student_attempt.php?id=<?= (int)$attempt['id'] ?>"><i class="bi bi-box-arrow-up-right"></i></a>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php if ($attemptsCount > 0): ?>
<script>
(function() {
    const labels = <?= $chartLabelsJson ?>;
    const dataValues = <?= $chartPercentsJson ?>;
    const ctx = document.getElementById('attemptScoresChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Резултат (%)',
                data: dataValues,
                backgroundColor: 'rgba(13, 110, 253, 0.6)',
                borderColor: 'rgba(13, 110, 253, 1)',
                borderWidth: 1,
                borderRadius: 4,
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
                    ticks: { callback: value => value + '%' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.parsed.y !== null ? ctx.parsed.y + '%' : '—'
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