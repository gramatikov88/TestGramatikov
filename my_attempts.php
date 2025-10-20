<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$pdo = db();
$student = $_SESSION['user'];

// Load classes for filter
$classes = $pdo->prepare('SELECT c.id, c.grade, c.section, c.school_year, c.name FROM classes c JOIN class_students cs ON cs.class_id = c.id WHERE cs.student_id = :sid ORDER BY c.school_year DESC, c.grade, c.section');
$classes->execute([':sid'=>$student['id']]);
$classes = $classes->fetchAll();

$selected_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
$selected_assignment = isset($_GET['assignment_id']) && $_GET['assignment_id'] !== '' ? (int)$_GET['assignment_id'] : null;

// Load assignments for dropdown (those the student has attempts for), filtered by class if set
$assParams = [':sid'=>$student['id']];
$assWhere = '';
if ($selected_class) {
    $assWhere = ' AND EXISTS (SELECT 1 FROM assignment_classes ac WHERE ac.assignment_id = a.id AND ac.class_id = :cid) ';
    $assParams[':cid'] = $selected_class;
}
$ass = $pdo->prepare('SELECT DISTINCT a.id, a.title FROM attempts atp JOIN assignments a ON a.id = atp.assignment_id WHERE atp.student_id = :sid ' . $assWhere . ' ORDER BY a.title');
$ass->execute($assParams);
$assignments = $ass->fetchAll();

// Build attempts query
$params = [':sid'=>$student['id']];
$where = 'WHERE atp.student_id = :sid';
if ($selected_class) { $where .= ' AND EXISTS (SELECT 1 FROM assignment_classes ac WHERE ac.assignment_id = atp.assignment_id AND ac.class_id = :cid)'; $params[':cid']=$selected_class; }
if ($selected_assignment) { $where .= ' AND atp.assignment_id = :aid'; $params[':aid']=$selected_assignment; }

$q = $pdo->prepare('SELECT atp.*, a.title AS assignment_title, t.title AS test_title
                    FROM attempts atp JOIN assignments a ON a.id = atp.assignment_id JOIN tests t ON t.id = atp.test_id
                    ' . $where . ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC LIMIT 200');
$q->execute($params);
$attempts = $q->fetchAll();

function percent($s,$m){ if ($m>0 && $s!==null) return round(($s/$m)*100,2); return null; }
function grade_from_percent($p){ if ($p===null) return null; if ($p>=90) return 6; if ($p>=80) return 5; if ($p>=65) return 4; if ($p>=50) return 3; return 2; }
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Моите опити – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0">Моите опити</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Табло</a>
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Клас</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Всички</option>
                    <?php foreach ($classes as $c): $sel = ($selected_class && (int)$selected_class === (int)$c['id']) ? 'selected' : ''; ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['grade'].$c['section']) ?> • <?= htmlspecialchars($c['school_year']) ?> — <?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Задание</label>
                <select name="assignment_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Всички</option>
                    <?php foreach ($assignments as $a): $sel = ($selected_assignment && (int)$selected_assignment === (int)$a['id']) ? 'selected' : ''; ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $sel ?>><?= htmlspecialchars($a['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="my_attempts.php" class="btn btn-outline-secondary w-100">Изчисти</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Резултати</strong></div>
        <div class="list-group list-group-flush">
            <?php if (!$attempts): ?><div class="list-group-item text-muted">Няма опити по избраните филтри.</div><?php endif; ?>
            <?php foreach ($attempts as $row): $p = percent($row['score_obtained'], $row['max_score']); $grade = $row['teacher_grade'] !== null ? (int)$row['teacher_grade'] : grade_from_percent($p); ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($row['assignment_title']) ?></div>
                        <div class="text-muted small">Тест: <?= htmlspecialchars($row['test_title']) ?> • <?= htmlspecialchars($row['submitted_at'] ?: $row['started_at']) ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?= ($p !== null && $p >= 50) ? 'bg-success' : 'bg-danger' ?>"><?= $p !== null ? $p . '%' : '—' ?></span>
                        <span class="badge bg-primary"><?= $grade !== null ? $grade : '—' ?></span>
                        <a class="btn btn-sm btn-outline-secondary" href="student_attempt.php?id=<?= (int)$row['id'] ?>"><i class="bi bi-eye"></i> Преглед</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<footer class="border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted">© <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">Условия</a>
            <a class="text-decoration-none" href="privacy.php">Поверителност</a>
            <a class="text-decoration-none" href="contact.php">Контакт</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</footer>
</body>
</html>
