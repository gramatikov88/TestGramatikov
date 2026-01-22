<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$pdo = db();
$student = $_SESSION['user'];

// Load classes for filter
$classes = $pdo->prepare('SELECT c.id, c.grade, c.section, c.school_year, c.name FROM classes c JOIN class_students cs ON cs.class_id = c.id WHERE cs.student_id = :sid ORDER BY c.school_year DESC, c.grade, c.section');
$classes->execute([':sid' => $student['id']]);
$classes = $classes->fetchAll();

$selected_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
$selected_assignment = isset($_GET['assignment_id']) && $_GET['assignment_id'] !== '' ? (int) $_GET['assignment_id'] : null;

// Load assignments for dropdown (those the student has attempts for), filtered by class if set
$assParams = [':sid' => $student['id']];
$assWhere = '';
if ($selected_class) {
    $assWhere = ' AND EXISTS (SELECT 1 FROM assignment_classes ac WHERE ac.assignment_id = a.id AND ac.class_id = :cid) ';
    $assParams[':cid'] = $selected_class;
}
$ass = $pdo->prepare('SELECT DISTINCT a.id, a.title FROM attempts atp JOIN assignments a ON a.id = atp.assignment_id WHERE atp.student_id = :sid ' . $assWhere . ' ORDER BY a.title');
$ass->execute($assParams);
$assignments = $ass->fetchAll();

// Build attempts query
$params = [':sid' => $student['id']];
$where = 'WHERE atp.student_id = :sid';
if ($selected_class) {
    $where .= ' AND EXISTS (SELECT 1 FROM assignment_classes ac WHERE ac.assignment_id = atp.assignment_id AND ac.class_id = :cid)';
    $params[':cid'] = $selected_class;
}
if ($selected_assignment) {
    $where .= ' AND atp.assignment_id = :aid';
    $params[':aid'] = $selected_assignment;
}

$q = $pdo->prepare('SELECT atp.*, a.title AS assignment_title, t.title AS test_title
                    FROM attempts atp JOIN assignments a ON a.id = atp.assignment_id JOIN tests t ON t.id = atp.test_id
                    ' . $where . ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC LIMIT 200');
$q->execute($params);
$attempts = $q->fetchAll();

function percent($s, $m)
{
    if ($m > 0 && $s !== null)
        return round(($s / $m) * 100, 2);
    return null;
}
function grade_from_percent($p)
{
    if ($p === null)
        return null;
    if ($p >= 90)
        return 6;
    if ($p >= 80)
        return 5;
    if ($p >= 65)
        return 4;
    if ($p >= 50)
        return 3;
    return 2;
}
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Моите опити – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
</head>

<body class="bg-body">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-4 my-md-5">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-5 animate-fade-up">
            <div>
                <h1 class="display-6 fw-bold mb-1">Моите Опити</h1>
                <p class="text-muted">История на всички ваши решени тестове.</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 hover-lift"><i
                    class="bi bi-arrow-left me-2"></i> Табло</a>
        </div>

        <!-- Filters -->
        <div class="glass-card p-4 mb-5 animate-fade-up delay-100">
            <form method="get" class="row gy-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small text-uppercase fw-bold text-muted tracking-wider">Клас</label>
                    <select name="class_id" class="form-select border-0 bg-white bg-opacity-50"
                        onchange="this.form.submit()">
                        <option value="">-- Всички --</option>
                        <?php foreach ($classes as $c):
                            $sel = ($selected_class && (int) $selected_class === (int) $c['id']) ? 'selected' : ''; ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $sel ?>>
                                <?= htmlspecialchars($c['grade'] . $c['section']) ?> • <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small text-uppercase fw-bold text-muted tracking-wider">Задание</label>
                    <select name="assignment_id" class="form-select border-0 bg-white bg-opacity-50"
                        onchange="this.form.submit()">
                        <option value="">-- Всички --</option>
                        <?php foreach ($assignments as $a):
                            $sel = ($selected_assignment && (int) $selected_assignment === (int) $a['id']) ? 'selected' : ''; ?>
                            <option value="<?= (int) $a['id'] ?>" <?= $sel ?>><?= htmlspecialchars($a['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="my_attempts.php" class="btn btn-light rounded-pill w-100"><i
                            class="bi bi-x-circle me-1"></i> Изчисти</a>
                </div>
            </form>
        </div>

        <!-- Results List -->
        <div class="glass-card overflow-hidden animate-fade-up delay-200">
            <?php if (!$attempts): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox fs-1 opacity-25 d-block mb-3"></i>
                    <p>Няма намерени опити по избраните критерии.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="background: transparent;">
                        <thead class="bg-light bg-opacity-50 text-uppercase text-muted small tracking-wider">
                            <tr>
                                <th class="ps-4 border-0">Задание / Тест</th>
                                <th class="border-0">Дата</th>
                                <th class="border-0">Резултат</th>
                                <th class="border-0">Оценка</th>
                                <th class="pe-4 border-0 text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            <?php foreach ($attempts as $row):
                                $p = percent($row['score_obtained'], $row['max_score']);
                                $grade = $row['teacher_grade'] !== null ? (int) $row['teacher_grade'] : grade_from_percent($p);
                                $date = $row['submitted_at'] ?: $row['started_at'];
                                ?>
                                <tr class="border-light border-opacity-50">
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark mb-0"><?= htmlspecialchars($row['assignment_title']) ?>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($row['test_title']) ?></div>
                                    </td>
                                    <td class="text-muted small">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-calendar3"></i> <?= format_date($date) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($p !== null): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1"
                                                    style="width: 50px; height: 5px; background: rgba(0,0,0,0.05);">
                                                    <div class="progress-bar <?= $p >= 50 ? 'bg-success' : 'bg-danger' ?> rounded-pill"
                                                        style="width: <?= $p ?>%"></div>
                                                </div>
                                                <span class="fw-bold small"><?= $p ?>%</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($grade): ?>
                                            <?php
                                            $gColor = match ($grade) {
                                                6 => 'bg-success',
                                                5 => 'bg-primary',
                                                4 => 'bg-info',
                                                3 => 'bg-warning',
                                                2 => 'bg-danger',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge <?= $gColor ?> rounded-pill px-3"><?= $grade ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <a class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                            href="student_attempt.php?id=<?= (int) $row['id'] ?>">
                                            Преглед <i class="bi bi-chevron-right ms-1"></i>
                                        </a>
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
</body>

</html>