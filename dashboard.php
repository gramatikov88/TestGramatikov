<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = null;
try { $pdo = db(); ensure_attempts_grade($pdo); ensure_subjects_scope($pdo); } catch (Throwable $e) { $pdo = null; }

function percent($score, $max) {
    if ($score === null || $max === null || $max <= 0) return null;
    return round(($score / $max) * 100, 2);

function grade_from_percent(?float $percent): ?int {
    if ($percent === null) return null;
    if ($percent >= 90) return 6;
    if ($percent >= 80) return 5;
    if ($percent >= 65) return 4;
    if ($percent >= 50) return 3;
    return 2;

function normalize_filter_datetime(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) {
        $value .= ':00';
    return $value;

// Initialize containers
$teacher = [
    'classes' => [],
    'tests' => [],
    'recent_attempts' => [],
    'recent_attempts_meta' => [
        'page' => 1,
        'per_page' => 5,
        'pages' => 1,
        'total' => 0,
    ],
    'assignments_current' => [],
    'assignments_past' => [],
    'class_stats' => [],
];
$student = [
    'classes' => [],
    'open_assignments' => [],
    'recent_attempts' => [],
    'overview' => null,
];

// Persist teacher dashboard filters in session
if ($user['role'] === 'teacher') {
    $filter_keys = ['c_q','c_sort','t_q','t_subject','t_visibility','t_status','t_sort','a_q','a_from','a_to','a_sort','a_page','ca_class_id','ca_sort'];
    if (isset($_GET['reset'])) {
        unset($_SESSION['dash_filters']);
        header('Location: dashboard.php');
        exit;
    if (!empty($_GET)) {
        $save = [];
        foreach ($filter_keys as $k) { if (array_key_exists($k, $_GET)) { $save[$k] = $_GET[$k]; } }
        if ($save) { $_SESSION['dash_filters'] = $save; }
    } elseif (!empty($_SESSION['dash_filters'])) {
        foreach ($_SESSION['dash_filters'] as $k => $v) { $_GET[$k] = $v; }

if ($pdo) {
    if ($user['role'] === 'teacher') {
        // Handle teacher grade update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'set_grade') {
            $attempt_id = (int)($_POST['attempt_id'] ?? 0);
            $grade = isset($_POST['teacher_grade']) && $_POST['teacher_grade'] !== '' ? (int)$_POST['teacher_grade'] : null;
            if ($attempt_id > 0 && ($grade === null || ($grade >= 2 && $grade <= 6))) {
                $upd = $pdo->prepare('UPDATE attempts atp JOIN assignments a ON a.id = atp.assignment_id SET atp.teacher_grade = :g WHERE atp.id = :id AND a.assigned_by_teacher_id = :tid');
                $upd->execute([':g' => $grade, ':id' => $attempt_id, ':tid' => (int)$user['id']]);
        // Teacher: classes (initial load; refined below by filters)
        $stmt = $pdo->prepare('SELECT id, grade, section, school_year, name, created_at FROM classes WHERE teacher_id = :tid ORDER BY school_year DESC, grade, section');
        $stmt->execute([':tid' => (int)$user['id']]);
        $teacher['classes'] = $stmt->fetchAll();

        // Teacher: own tests (initial load; refined below by filters)
        $stmt = $pdo->prepare('SELECT id, title, visibility, status, updated_at FROM tests WHERE owner_teacher_id = :tid ORDER BY updated_at DESC LIMIT 12');
        $stmt->execute([':tid' => (int)$user['id']]);
        $teacher['tests'] = $stmt->fetchAll();

        // Teacher: recent attempts across their assignments
        $stmt = $pdo->prepare('SELECT atp.id, atp.student_id, atp.submitted_at, atp.started_at, atp.score_obtained, atp.max_score, atp.teacher_grade,
                                      a.title AS assignment_title, u.first_name, u.last_name
                               FROM attempts atp
                               JOIN assignments a ON a.id = atp.assignment_id
                               JOIN users u ON u.id = atp.student_id
                               WHERE a.assigned_by_teacher_id = :tid AND atp.status IN ("submitted","graded")
                               ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC
                               LIMIT 20');
        $stmt->execute([':tid' => (int)$user['id']]);
        $teacher['recent_attempts'] = $stmt->fetchAll();

        // Teacher: class stats via view (if present)
        try {
            $stmt = $pdo->prepare('SELECT v.class_id, v.assignment_id, v.avg_percent, c.grade, c.section, c.school_year
                                   FROM v_class_assignment_stats v
                                   JOIN classes c ON c.id = v.class_id
                                   WHERE c.teacher_id = :tid
                                   ORDER BY v.avg_percent DESC
                                   LIMIT 10');
            $stmt->execute([':tid' => (int)$user['id']]);
            $teacher['class_stats'] = $stmt->fetchAll();
        } catch (Throwable $e) {
            $teacher['class_stats'] = [];
        
        // ---------- Apply optional filters and sorting (override defaults) ----------
        $t_q = isset($_GET['t_q']) ? trim((string)$_GET['t_q']) : '';
        $t_subject = (isset($_GET['t_subject']) && $_GET['t_subject'] !== '') ? (int)$_GET['t_subject'] : null;
        $t_visibility = in_array(($_GET['t_visibility'] ?? ''), ['private','shared'], true) ? $_GET['t_visibility'] : '';
        $t_status = in_array(($_GET['t_status'] ?? ''), ['draft','published','archived'], true) ? $_GET['t_status'] : '';
        $t_sort = $_GET['t_sort'] ?? '';

        $c_q = isset($_GET['c_q']) ? trim((string)$_GET['c_q']) : '';
        $c_sort = $_GET['c_sort'] ?? '';

        $a_q = isset($_GET['a_q']) ? trim((string)$_GET['a_q']) : '';
        $a_from_raw = (string)($_GET['a_from'] ?? '');
        $a_to_raw = (string)($_GET['a_to'] ?? '');
        $a_from = $a_from_raw !== '' ? normalize_filter_datetime($a_from_raw) : '';
        $a_to = $a_to_raw !== '' ? normalize_filter_datetime($a_to_raw) : '';
        $a_sort = $_GET['a_sort'] ?? '';
        $a_page = max(1, (int)($_GET['a_page'] ?? 1));

        $ca_class_id = (isset($_GET['ca_class_id']) && $_GET['ca_class_id'] !== '') ? (int)$_GET['ca_class_id'] : null;
        $ca_sort = $_GET['ca_sort'] ?? '';

        // Re-query classes with filters
        $clsSql = 'SELECT id, grade, section, school_year, name, created_at FROM classes WHERE teacher_id = :tid';
        $params = [':tid'=>(int)$user['id']];
        if ($c_q !== '') { $clsSql .= ' AND (name LIKE :q OR section LIKE :q OR CONCAT(grade, section) LIKE :q)'; $params[':q'] = '%'.$c_q.'%'; }
        $order = ' ORDER BY school_year DESC, grade, section';
        if ($c_sort === 'grade_asc') $order = ' ORDER BY grade ASC, section ASC, school_year DESC';
        if ($c_sort === 'name_asc') $order = ' ORDER BY name ASC';
        if ($c_sort === 'name_desc') $order = ' ORDER BY name DESC';
        $stmt = $pdo->prepare($clsSql . $order);
        $stmt->execute($params);
        $teacher['classes'] = $stmt->fetchAll();

        // Re-query tests with filters
        $testsSql = 'SELECT id, title, visibility, status, updated_at FROM tests WHERE owner_teacher_id = :tid';
        $tParams = [':tid'=>(int)$user['id']];
        if ($t_subject) { $testsSql .= ' AND subject_id = :sid'; $tParams[':sid'] = $t_subject; }
        if ($t_visibility !== '') { $testsSql .= ' AND visibility = :vis'; $tParams[':vis'] = $t_visibility; }
        if ($t_status !== '') { $testsSql .= ' AND status = :st'; $tParams[':st'] = $t_status; }
        if ($t_q !== '') { $testsSql .= ' AND title LIKE :tq'; $tParams[':tq'] = '%'.$t_q.'%'; }
        $tOrder = ' ORDER BY updated_at DESC';
        if ($t_sort === 'updated_asc') $tOrder = ' ORDER BY updated_at ASC';
        if ($t_sort === 'title_asc') $tOrder = ' ORDER BY title ASC';
        if ($t_sort === 'title_desc') $tOrder = ' ORDER BY title DESC';
        $stmt = $pdo->prepare($testsSql . $tOrder . ' LIMIT 50');
        $stmt->execute($tParams);
        $teacher['tests'] = $stmt->fetchAll();

        // Re-query recent attempts with filters + pagination
        $attemptsPerPage = 5;
        $aSelect = 'SELECT atp.id, atp.student_id, atp.submitted_at, atp.started_at, atp.score_obtained, atp.max_score, atp.teacher_grade,
                           a.title AS assignment_title, u.first_name, u.last_name';
        $aFrom = ' FROM attempts atp
                   JOIN assignments a ON a.id = atp.assignment_id
                   JOIN users u ON u.id = atp.student_id
                   WHERE a.assigned_by_teacher_id = :tid AND atp.status IN ("submitted","graded")';
        $aParams = [':tid'=>(int)$user['id']];
        if ($a_q !== '') { $aFrom .= ' AND (a.title LIKE :aq OR u.first_name LIKE :aq OR u.last_name LIKE :aq)'; $aParams[':aq'] = '%'.$a_q.'%'; }
        if ($a_from !== '') { $aFrom .= ' AND COALESCE(atp.submitted_at, atp.started_at) >= :af'; $aParams[':af'] = $a_from; }
        if ($a_to !== '') { $aFrom .= ' AND COALESCE(atp.submitted_at, atp.started_at) <= :at'; $aParams[':at'] = $a_to; }
        $aOrder = ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC';
        if ($a_sort === 'date_asc') $aOrder = ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) ASC';
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $aFrom);
        foreach ($aParams as $param => $value) {
            $countStmt->bindValue($param, $value);
        $countStmt->execute();
        $totalAttempts = (int)$countStmt->fetchColumn();
        $totalPages = $totalAttempts > 0 ? (int)ceil($totalAttempts / $attemptsPerPage) : 1;
        if ($totalPages < 1) {
            $totalPages = 1;
        if ($totalAttempts === 0) {
            $a_page = 1;
        } elseif ($a_page > $totalPages) {
            $a_page = $totalPages;
        $offset = ($a_page - 1) * $attemptsPerPage;
        $stmt = $pdo->prepare($aSelect . $aFrom . $aOrder . ' LIMIT :limit OFFSET :offset');
        foreach ($aParams as $param => $value) {
            $stmt->bindValue($param, $value);
        $stmt->bindValue(':limit', $attemptsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $teacher['recent_attempts'] = $stmt->fetchAll();
        $teacher['recent_attempts_meta'] = [
            'page' => $a_page,
            'per_page' => $attemptsPerPage,
            'pages' => $totalPages,
            'total' => $totalAttempts,
        ];

        // Assignments overview (current and past)
        $assignSql = 'SELECT a.id, a.title, a.open_at, a.due_at, a.close_at,
                             SUM(CASE WHEN atp.status IN ("submitted","graded") THEN 1 ELSE 0 END) AS submitted_count,
                             SUM(CASE WHEN atp.status = "graded" OR atp.teacher_grade IS NOT NULL THEN 1 ELSE 0 END) AS graded_count,
                             SUM(CASE WHEN atp.status = "submitted" AND atp.teacher_grade IS NULL THEN 1 ELSE 0 END) AS needs_grade,
                             MIN(ac.class_id) AS primary_class_id,
                             MAX(t.is_strict_mode) AS is_strict_mode
                      FROM assignments a
                      LEFT JOIN attempts atp ON atp.assignment_id = a.id
                      LEFT JOIN tests t ON t.id = a.test_id
                      LEFT JOIN assignment_classes ac ON ac.assignment_id = a.id
                      WHERE a.assigned_by_teacher_id = :tid';
        $assignParams = [':tid'=>(int)$user['id']];
        if ($a_q !== '') { $assignSql .= ' AND a.title LIKE :assign_q'; $assignParams[':assign_q'] = '%'.$a_q.'%'; }
        if ($a_from !== '') { $assignSql .= ' AND COALESCE(a.close_at, a.due_at, a.open_at) >= :assign_from'; $assignParams[':assign_from'] = $a_from; }
        if ($a_to !== '') { $assignSql .= ' AND COALESCE(a.close_at, a.due_at, a.open_at) <= :assign_to'; $assignParams[':assign_to'] = $a_to; }
        $assignSql .= ' GROUP BY a.id
                        ORDER BY COALESCE(a.close_at, a.due_at, a.open_at, NOW()) DESC, a.id DESC
                        LIMIT 50';
        $stmt = $pdo->prepare($assignSql);
        $stmt->execute($assignParams);
        $assignmentRows = $stmt->fetchAll();
        $now = date('Y-m-d H:i:s');
        $currentAssignments = [];
        $pastAssignments = [];
        foreach ($assignmentRows as $row) {
            $row['submitted_count'] = (int)($row['submitted_count'] ?? 0);
            $row['graded_count'] = (int)($row['graded_count'] ?? 0);
            $row['needs_grade'] = (int)($row['needs_grade'] ?? 0);
            $openAt = $row['open_at'] ?? null;
            $dueAt = $row['due_at'] ?? null;
            $closeAt = $row['close_at'] ?? null;

            $isPast = false;
            if ($closeAt && $closeAt < $now) {
                $isPast = true;
            } elseif (!$closeAt && $dueAt && $dueAt < $now) {
                $isPast = true;

            if ($isPast) {
                $row['status'] = 'past';
                $pastAssignments[] = $row;
            } else {
                $isOpen = !$openAt || $openAt <= $now;
                if ($isOpen) {
                    $row['status'] = 'current';
                } else {
                    $row['status'] = 'upcoming';
                $currentAssignments[] = $row;
        $teacher['assignments_current'] = array_slice($currentAssignments, 0, 8);
        $teacher['assignments_past'] = array_slice($pastAssignments, 0, 8);

        // Re-query class analytics with filters
        try {
            $caSql = 'SELECT v.class_id, v.assignment_id, v.avg_percent, c.grade, c.section, c.school_year
                      FROM v_class_assignment_stats v
                      JOIN classes c ON c.id = v.class_id
                      WHERE c.teacher_id = :tid';
            $caParams = [':tid'=>(int)$user['id']];
            if ($ca_class_id) { $caSql .= ' AND v.class_id = :cid'; $caParams[':cid'] = $ca_class_id; }
            $caOrder = ' ORDER BY v.avg_percent DESC';
            if ($ca_sort === 'percent_asc') $caOrder = ' ORDER BY v.avg_percent ASC';
            $stmt = $pdo->prepare($caSql . $caOrder . ' LIMIT 20');
            $stmt->execute($caParams);
            $teacher['class_stats'] = $stmt->fetchAll();
        } catch (Throwable $e) {
            // ignore
        }
    } else {    
        $stmt = $pdo->prepare('SELECT c.*
                               FROM classes c
                               JOIN class_students cs ON cs.class_id = c.id
                               WHERE cs.student_id = :sid
                               ORDER BY c.school_year DESC, c.grade, c.section');
        $stmt->execute([':sid' => (int)$user['id']]);
        $student['classes'] = $stmt->fetchAll();

        // Student: open assignments (class or individual)
        $stmt = $pdo->prepare('SELECT DISTINCT a.id, a.title, a.description, a.open_at, a.due_at, a.close_at, t.title AS test_title
                               FROM assignments a
                               JOIN tests t ON t.id = a.test_id
                               LEFT JOIN assignment_classes ac ON ac.assignment_id = a.id
                               LEFT JOIN class_students cs ON cs.class_id = ac.class_id AND cs.student_id = :sid
                               LEFT JOIN assignment_students ast ON ast.assignment_id = a.id AND ast.student_id = :sid
                               WHERE a.is_published = 1
                                 AND (cs.student_id IS NOT NULL OR ast.student_id IS NOT NULL)
                                 AND (a.open_at IS NULL OR a.open_at <= NOW())
                                 AND (a.close_at IS NULL OR a.close_at >= NOW())
                               ORDER BY (a.due_at IS NULL), a.due_at ASC
                               LIMIT 20');
        $stmt->execute([':sid' => (int)$user['id']]);
        $student['open_assignments'] = $stmt->fetchAll();

        // Map latest attempt per open assignment
        $student['open_attempts_map'] = [];
        if (!empty($student['open_assignments'])) {
            $ids = array_map(fn($a)=> (int)$a['id'], $student['open_assignments']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT assignment_id, MAX(id) AS last_attempt_id FROM attempts WHERE student_id = ? AND assignment_id IN ($in) GROUP BY assignment_id");
            $params = array_merge([(int)$user['id']], $ids);
            $q->execute($params);
            while ($row = $q->fetch()) {
                $student['open_attempts_map'][(int)$row['assignment_id']] = (int)$row['last_attempt_id'];

        // Student: recent attempts
        $stmt = $pdo->prepare('SELECT atp.*, a.title AS assignment_title
                               FROM attempts atp
                               JOIN assignments a ON a.id = atp.assignment_id
                               WHERE atp.student_id = :sid
                               ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC
                               LIMIT 10');
        $stmt->execute([':sid' => (int)$user['id']]);
        $student['recent_attempts'] = $stmt->fetchAll();

        // Student: overview view (if present)
        try {
            $stmt = $pdo->prepare('SELECT * FROM v_student_overview WHERE student_id = :sid LIMIT 1');
            $stmt->execute([':sid' => (int)$user['id']]);
            $student['overview'] = $stmt->fetch();
        } catch (Throwable $e) {
            $student['overview'] = null;
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Ð¢Ð°Ð±Ð»Ð¾ â€“ TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge { background: rgba(13,110,253,.1); border: 1px solid rgba(13,110,253,.2); color:#0d6efd; }
        .card-min { min-height: 120px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 m-0">Ð—Ð´Ñ€Ð°Ð²ÐµÐ¹, <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h1>
            <div class="text-muted">Ð¢Ð²Ð¾ÐµÑ‚Ð¾ Ñ‚Ð°Ð±Ð»Ð¾ (Ñ€Ð¾Ð»Ð°: <?= htmlspecialchars($user['role']) ?>)</div>
        </div>
        <?php if ($user['role'] === 'teacher'): ?>
            <div class="d-flex gap-2">
                <a class="btn btn-primary" href="createTest.php"><i class="bi bi-magic me-1"></i>ÐÐ¾Ð² Ñ‚ÐµÑÑ‚</a>
                <a class="btn btn-outline-primary" href="classes_create.php"><i class="bi bi-people me-1"></i>ÐÐ¾Ð² ÐºÐ»Ð°Ñ</a>
                <a class="btn btn-outline-secondary" href="assignments_create.php"><i class="bi bi-megaphone me-1"></i>ÐÐ¾Ð²Ð¾ Ð·Ð°Ð´Ð°Ð½Ð¸Ðµ</a>
                <a class="btn btn-outline-primary" href="subjects_create.php"><i class="bi bi-journal-text me-1"></i>ÐŸÑ€ÐµÐ´Ð¼ÐµÑ‚Ð¸</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($user['role'] === 'teacher'): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>Ð¤Ð¸Ð»Ñ‚Ñ€Ð¸ Ð¸ ÑÐ¾Ñ€Ñ‚Ð¸Ñ€Ð°Ð½Ðµ</strong><a href="dashboard.php?reset=1" class="btn btn-sm btn-outline-danger">Reset</a></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-4">
                    <form method="get" class="border rounded p-2 h-100">
                        <div class="fw-semibold mb-2">ÐšÐ»Ð°ÑÐ¾Ð²Ðµ</div>
                        <input type="text" name="c_q" value="<?= htmlspecialchars($_GET['c_q'] ?? '') ?>" class="form-control form-control-sm mb-2" placeholder="Ð¢ÑŠÑ€ÑÐµÐ½Ðµ..." />
                        <select name="c_sort" class="form-select form-select-sm mb-2">
                            <option value="">Ð¡Ð¾Ñ€Ñ‚Ð¸Ñ€Ð°Ð½Ðµ</option>
                            <option value="year_desc" <?= (($_GET['c_sort'] ?? '')==='year_desc')?'selected':'' ?>>ÐŸÐ¾ Ð³Ð¾Ð´Ð¸Ð½Ð°</option>
                            <option value="grade_asc" <?= (($_GET['c_sort'] ?? '')==='grade_asc')?'selected':'' ?>>ÐŸÐ¾ ÐºÐ»Ð°Ñ</option>
                            <option value="name_asc" <?= (($_GET['c_sort'] ?? '')==='name_asc')?'selected':'' ?>>Ð˜Ð¼Ðµ Aâ†’Ð¯</option>
                            <option value="name_desc" <?= (($_GET['c_sort'] ?? '')==='name_desc')?'selected':'' ?>>Ð˜Ð¼Ðµ Ð¯â†’A</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">ÐŸÑ€Ð¸Ð»Ð¾Ð¶Ð¸</button>
                    </form>
                </div>
                <div class="col-lg-4">
                    <form method="get" class="border rounded p-2 h-100">
                        <div class="fw-semibold mb-2">Ð¢ÐµÑÑ‚Ð¾Ð²Ðµ</div>
                        <input type="text" name="t_q" value="<?= htmlspecialchars($_GET['t_q'] ?? '') ?>" class="form-control form-control-sm mb-2" placeholder="Ð¢ÑŠÑ€ÑÐµÐ½Ðµ..." />
                        <?php
                        // Load teacher subjects for dropdown
                        $filter_subjects = [];
                        try {
                            $q = $pdo->prepare('SELECT id, name FROM subjects WHERE owner_teacher_id = :tid ORDER BY name');
                            $q->execute([':tid'=>(int)$user['id']]);
                            $filter_subjects = $q->fetchAll();
                        } catch (Throwable $e) { $filter_subjects = []; }
                        ?>
                        <select name="t_subject" class="form-select form-select-sm mb-2">
                            <option value="">ÐŸÑ€ÐµÐ´Ð¼ÐµÑ‚</option>
                            <?php foreach ($filter_subjects as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= (($_GET['t_subject'] ?? '')!=='') && ((int)$_GET['t_subject']===(int)$s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="d-flex gap-2 mb-2">
                            <select name="t_visibility" class="form-select form-select-sm">
                                <option value="">Ð’Ð¸Ð´Ð¸Ð¼Ð¾ÑÑ‚</option>
                                <option value="private" <?= (($_GET['t_visibility'] ?? '')==='private')?'selected':'' ?>>Ð§Ð°ÑÑ‚ÐµÐ½</option>
                                <option value="shared" <?= (($_GET['t_visibility'] ?? '')==='shared')?'selected':'' ?>>Ð¡Ð¿Ð¾Ð´ÐµÐ»ÐµÐ½</option>
                            </select>
                            <select name="t_status" class="form-select form-select-sm">
                                <option value="">Ð¡Ñ‚Ð°Ñ‚ÑƒÑ</option>
                                <option value="draft" <?= (($_GET['t_status'] ?? '')==='draft')?'selected':'' ?>>Ð§ÐµÑ€Ð½Ð¾Ð²Ð°</option>
                                <option value="published" <?= (($_GET['t_status'] ?? '')==='published')?'selected':'' ?>>ÐŸÑƒÐ±Ð»Ð¸ÐºÑƒÐ²Ð°Ð½</option>
                                <option value="archived" <?= (($_GET['t_status'] ?? '')==='archived')?'selected':'' ?>>ÐÑ€Ñ…Ð¸Ð²Ð¸Ñ€Ð°Ð½</option>
                            </select>
                        </div>
                        <select name="t_sort" class="form-select form-select-sm mb-2">
                            <option value="">Ð¡Ð¾Ñ€Ñ‚Ð¸Ñ€Ð°Ð½Ðµ</option>
                            <option value="updated_desc" <?= (($_GET['t_sort'] ?? '')==='updated_desc')?'selected':'' ?>>ÐžÐ±Ð½Ð¾Ð²ÐµÐ½Ð¸ â†“</option>
                            <option value="updated_asc" <?= (($_GET['t_sort'] ?? '')==='updated_asc')?'selected':'' ?>>ÐžÐ±Ð½Ð¾Ð²ÐµÐ½Ð¸ â†‘</option>
                            <option value="title_asc" <?= (($_GET['t_sort'] ?? '')==='title_asc')?'selected':'' ?>>Ð—Ð°Ð³Ð»Ð°Ð²Ð¸Ðµ Aâ†’Ð¯</option>
                            <option value="title_desc" <?= (($_GET['t_sort'] ?? '')==='title_desc')?'selected':'' ?>>Ð—Ð°Ð³Ð»Ð°Ð²Ð¸Ðµ Ð¯â†’A</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">ÐŸÑ€Ð¸Ð»Ð¾Ð¶Ð¸</button>
                    </form>
                </div>
                <div class="col-lg-4">
                    <form method="get" class="border rounded p-2 h-100">
                        <div class="fw-semibold mb-2">ÐžÐ¿Ð¸Ñ‚Ð¸</div>
                        <input type="text" name="a_q" value="<?= htmlspecialchars($_GET['a_q'] ?? '') ?>" class="form-control form-control-sm mb-2" placeholder="Ð¢ÑŠÑ€ÑÐµÐ½Ðµ (Ð·Ð°Ð´./Ð¸Ð¼Ðµ)..." />
                        <div class="d-flex gap-2 mb-2">
                            <input type="datetime-local" name="a_from" value="<?= htmlspecialchars($_GET['a_from'] ?? '') ?>" class="form-control form-control-sm" />
                            <input type="datetime-local" name="a_to" value="<?= htmlspecialchars($_GET['a_to'] ?? '') ?>" class="form-control form-control-sm" />
                        </div>
                        <select name="a_sort" class="form-select form-select-sm mb-2">
                            <option value="">Ð¡Ð¾Ñ€Ñ‚Ð¸Ñ€Ð°Ð½Ðµ</option>
                            <option value="date_desc" <?= (($_GET['a_sort'] ?? '')==='date_desc')?'selected':'' ?>>Ð”Ð°Ñ‚Ð° â†“</option>
                            <option value="date_asc" <?= (($_GET['a_sort'] ?? '')==='date_asc')?'selected':'' ?>>Ð”Ð°Ñ‚Ð° â†‘</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">ÐŸÑ€Ð¸Ð»Ð¾Ð¶Ð¸</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$pdo): ?>
        <div class="alert alert-warning">Ð›Ð¸Ð¿ÑÐ²Ð° Ð²Ñ€ÑŠÐ·ÐºÐ° ÐºÑŠÐ¼ Ð±Ð°Ð·Ð°Ñ‚Ð°. ÐŸÑ€Ð¾Ð²ÐµÑ€ÐµÑ‚Ðµ config.php Ð¸ Ð¸Ð¼Ð¿Ð¾Ñ€Ñ‚Ð½ÐµÑ‚Ðµ db/schema.sql.</div>
    <?php endif; ?>

    <?php if ($user['role'] === 'teacher'): ?>
        <!-- Teacher Dashboard -->
        <div class="row g-3 g-md-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>Ð¢Ð²Ð¾Ð¸Ñ‚Ðµ ÐºÐ»Ð°ÑÐ¾Ð²Ðµ</strong></div>
                    <div class="card-body">
                        <?php if (empty($teacher['classes'])): ?>
                            <div class="text-muted">ÐÑÐ¼Ð°Ñ‚Ðµ ÑÑŠÐ·Ð´Ð°Ð´ÐµÐ½Ð¸ ÐºÐ»Ð°ÑÐ¾Ð²Ðµ.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($teacher['classes'] as $c): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <a class="text-decoration-none" href="classes_create.php?id=<?= (int)$c['id'] ?>&created_at=<?= urlencode($c['created_at']) ?>">
                                                <?= htmlspecialchars($c['grade'] . $c['section']) ?> â€¢ <?= htmlspecialchars($c['school_year']) ?>
                                                <span class="text-muted small ms-2"><?= htmlspecialchars($c['name']) ?></span>
                                            </a>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a class="btn btn-sm btn-outline-primary" href="classes_create.php?id=<?= (int)$c['id'] ?>&created_at=<?= urlencode($c['created_at']) ?>#students"><i class="bi bi-person-plus"></i> Ð”Ð¾Ð±Ð°Ð²Ð¸ ÑƒÑ‡ÐµÐ½Ð¸Ñ†Ð¸</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>Ð¢ÐµÑÑ‚Ð¾Ð²Ðµ</strong></div>
                    <div class="card-body">
                        <?php if (empty($teacher['tests'])): ?>
                            <div class="text-muted">Ð’ÑÐµ Ð¾Ñ‰Ðµ Ð½ÑÐ¼Ð°Ñ‚Ðµ Ñ‚ÐµÑÑ‚Ð¾Ð²Ðµ.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($teacher['tests'] as $t): ?>
                                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="test_edit.php?id=<?= (int)$t['id'] ?>">
                                        <span>
                                            <?= htmlspecialchars($t['title']) ?>
                                            <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($t['visibility']) ?></span>
                                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($t['status']) ?></span>
                                        </span>
                                        <small class="text-muted"><?= htmlspecialchars($t['updated_at']) ?></small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 g-md-4 mt-1 mt-md-2">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐŸÐ¾ÑÐ»ÐµÐ´Ð½Ð¸ Ð¾Ð¿Ð¸Ñ‚Ð¸</strong></div>
                    <div class="card-body">
                        <?php if (empty($teacher['recent_attempts'])): ?>
                            <div class="text-muted">ÐžÑ‰Ðµ Ð½ÑÐ¼Ð° Ð¿Ñ€ÐµÐ´Ð°Ð´ÐµÐ½Ð¸ Ð¾Ð¿Ð¸Ñ‚Ð¸.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($teacher['recent_attempts'] as $ra): $p = percent($ra['score_obtained'], $ra['max_score']); $autoGrade = grade_from_percent($p); $displayGrade = $ra['teacher_grade'] !== null ? (int)$ra['teacher_grade'] : $autoGrade; ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div class="me-3">
                                            <div class="fw-semibold">
                                                <?= htmlspecialchars($ra['first_name'] . ' ' . $ra['last_name']) ?>
                                                <span class="text-muted">â€¢</span>
                                                <span class="text-muted"><?= htmlspecialchars($ra['assignment_title']) ?></span>
                                            </div>
                                            <div class="text-muted small"><?= htmlspecialchars($ra['submitted_at'] ?: $ra['started_at']) ?></div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge <?= ($p !== null && $p >= 50) ? 'bg-success' : 'bg-danger' ?>"><?= $p !== null ? $p . '%' : 'â€”' ?></span>
                                            <span class="badge bg-primary">ÐÐ²Ñ‚Ð¾: <?= $autoGrade !== null ? $autoGrade : 'â€”' ?></span>
                                            <form method="post" class="d-flex align-items-center gap-1">
                                                <input type="hidden" name="__action" value="set_grade" />
                                                <input type="hidden" name="attempt_id" value="<?= (int)$ra['id'] ?>" />
                                                <select name="teacher_grade" class="form-select form-select-sm" style="width:auto">
                                                    <option value="">â€”</option>
                                                    <?php for ($g=2; $g<=6; $g++): ?>
                                                        <option value="<?= $g ?>" <?= ($displayGrade === $g && $ra['teacher_grade'] !== null)?'selected':'' ?>><?= $g ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-save"></i></button>
                                            </form>
                                            <a class="btn btn-sm btn-outline-secondary" href="attempt_review.php?id=<?= (int)$ra['id'] ?>"><i class="bi bi-eye"></i> ÐŸÑ€ÐµÐ³Ð»ÐµÐ´</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php
                            $attemptMeta = $teacher['recent_attempts_meta'] ?? ['page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => max(1, count($teacher['recent_attempts']))];
                            $attemptPage = max(1, (int)($attemptMeta['page'] ?? 1));
                            $attemptPages = max(1, (int)($attemptMeta['pages'] ?? 1));
                            $attemptTotal = max(0, (int)($attemptMeta['total'] ?? 0));
                            $attemptPerPage = max(1, (int)($attemptMeta['per_page'] ?? 5));
                            $attemptCountOnPage = count($teacher['recent_attempts']);
                            $attemptFrom = $attemptCountOnPage ? (($attemptPage - 1) * $attemptPerPage + 1) : 0;
                            $attemptTo = $attemptCountOnPage ? ($attemptFrom + $attemptCountOnPage - 1) : 0;
                            $paginationWindow = 5;
                            $halfWindow = (int)floor($paginationWindow / 2);
                            $startPage = max(1, $attemptPage - $halfWindow);
                            $endPage = min($attemptPages, $startPage + $paginationWindow - 1);
                            $startPage = max(1, $endPage - $paginationWindow + 1);
                            $queryWithoutPage = $_GET;
                            unset($queryWithoutPage['a_page']);
                            $buildAttemptPageUrl = function (int $page) use ($queryWithoutPage) {
                                $params = $queryWithoutPage;
                                if ($page > 1) {
                                    $params['a_page'] = $page;
                                } else {
                                    unset($params['a_page']);
                                $qs = http_build_query($params);
                                return 'dashboard.php' . ($qs ? '?' . $qs : '');
                            };
                            ?>
                            <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center mt-3 gap-2">
                                <?php if ($attemptTotal > 0): ?>
                                    <small class="text-muted">ÐŸÐ¾ÐºÐ°Ð·Ð°Ð½Ð¸ <?= $attemptFrom ?>-<?= $attemptTo ?> Ð¾Ñ‚ <?= $attemptTotal ?></small>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                <?php if ($attemptPages > 1): ?>
                                    <nav aria-label="ÐŸÐ°Ð³Ð¸Ð½Ð°Ñ†Ð¸Ñ Ð¿Ð¾ÑÐ»ÐµÐ´Ð½Ð¸ Ð¾Ð¿Ð¸Ñ‚Ð¸">
                                        <ul class="pagination pagination-sm mb-0">
                                            <li class="page-item <?= $attemptPage <= 1 ? 'disabled' : '' ?>">
                                                <?php if ($attemptPage <= 1): ?>
                                                    <span class="page-link">ÐŸÑ€ÐµÐ´</span>
                                                <?php else: ?>
                                                    <a class="page-link" href="<?= htmlspecialchars($buildAttemptPageUrl($attemptPage - 1)) ?>">ÐŸÑ€ÐµÐ´</a>
                                                <?php endif; ?>
                                            </li>
                                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                                <li class="page-item <?= $p === $attemptPage ? 'active' : '' ?>">
                                                    <?php if ($p === $attemptPage): ?>
                                                        <span class="page-link"><?= $p ?></span>
                                                    <?php else: ?>
                                                        <a class="page-link" href="<?= htmlspecialchars($buildAttemptPageUrl($p)) ?>"><?= $p ?></a>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?= $attemptPage >= $attemptPages ? 'disabled' : '' ?>">
                                                <?php if ($attemptPage >= $attemptPages): ?>
                                                    <span class="page-link">Ð¡Ð»ÐµÐ´</span>
                                                <?php else: ?>
                                                    <a class="page-link" href="<?= htmlspecialchars($buildAttemptPageUrl($attemptPage + 1)) ?>">Ð¡Ð»ÐµÐ´</a>
                                                <?php endif; ?>
                                            </li>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐÐ½Ð°Ð»Ð¸Ñ‚Ð¸ÐºÐ° Ð¿Ð¾ ÐºÐ»Ð°Ñ</strong></div>
                    <div class="card-body">
                        <?php if (empty($teacher['class_stats'])): ?>
                            <div class="text-muted">ÐÑÐ¼Ð° Ð½Ð°Ð»Ð¸Ñ‡Ð½Ð¸ Ð´Ð°Ð½Ð½Ð¸ Ð·Ð° ÑÑ‚Ð°Ñ‚Ð¸ÑÑ‚Ð¸ÐºÐ°.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($teacher['class_stats'] as $cs): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold">ÐšÐ»Ð°Ñ <?= htmlspecialchars($cs['grade'] . $cs['section']) ?> â€¢ <?= htmlspecialchars($cs['school_year']) ?></div>
                                            <div class="text-muted small">Ð—Ð°Ð´Ð°Ð½Ð¸Ðµ #<?= (int)$cs['assignment_id'] ?></div>
                                        </div>
                                        <span class="badge bg-primary"><?= (float)$cs['avg_percent'] ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 g-md-4 mt-1 mt-md-2">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>Ð¢ÐµÐºÑƒÑ‰Ð¸ Ð·Ð°Ð´Ð°Ð½Ð¸Ñ</strong></div>
                    <div class="card-body">
                        <?php if (empty($teacher['assignments_current'])): ?>
                            <div class="text-muted">ÐÑÐ¼Ð° Ñ‚ÐµÐºÑƒÑ‰Ð¸ Ð¸Ð»Ð¸ Ð¿Ñ€ÐµÐ´ÑÑ‚Ð¾ÑÑ‰Ð¸ Ð·Ð°Ð´Ð°Ð½Ð¸Ñ.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($teacher['assignments_current'] as $assignment): ?>
                                    <?php
                                    $submittedCount = (int)($assignment['submitted_count'] ?? 0);
                                    $gradedCount = (int)($assignment['graded_count'] ?? 0);
                                    $needsGrade = (int)($assignment['needs_grade'] ?? 0);
                                    $primaryClassId = isset($assignment['primary_class_id']) ? (int)$assignment['primary_class_id'] : 0;
                                    $overviewLink = 'assignment_overview.php?id=' . (int)$assignment['id'];
                                    if ($primaryClassId > 0) {
                                        $overviewLink .= '&class_id=' . $primaryClassId;
                                    }
                                    $status = $assignment['status'] ?? 'current';
                                    $badgeClass = 'bg-success';
                                    $badgeLabel = 'D�D�D����%D_';
                                    if ($status === 'upcoming') {
                                        $badgeClass = 'bg-warning text-dark';
                                        $badgeLabel = 'DY�?D�D'�?�,D_�?�%D_';
                                    }
                                    ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="me-3">
                                            <div class="fw-semibold">
                                                <a class="text-decoration-none" href="<?= htmlspecialchars($overviewLink) ?>"><?= htmlspecialchars($assignment['title']) ?></a>
                                                <?php if (!empty($assignment['is_strict_mode'])): ?>
                                                    <span class="badge bg-danger ms-2">Строг режим</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                <?php if (!empty($assignment['open_at'])): ?>
                                                    Dz�,: <?= htmlspecialchars($assignment['open_at']) ?>
                                                <?php endif; ?>
                                                <?php if (!empty($assignment['due_at'])): ?>
                                                    <span class="ms-2">D"D_: <?= htmlspecialchars($assignment['due_at']) ?></span>
                                                <?php elseif (!empty($assignment['close_at'])): ?>
                                                    <span class="ms-2">D-D��,D�D��?�?D�D�: <?= htmlspecialchars($assignment['close_at']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                DYD_D'D�D'D�D�D,: <?= $submittedCount ?> / Dz�+D�D�D�D�D,: <?= $gradedCount ?>
                                            </div>
                                            <?php if ($needsGrade > 0): ?>
                                                <span class="badge bg-warning text-dark mt-2">D-D� D_�+D�D��?D�D�D�D�: <?= $needsGrade ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-column align-items-end gap-2">
                                            <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                            <a class="btn btn-sm btn-outline-primary" href="assignments_create.php?id=<?= (int)$assignment['id'] ?>"><i class="bi bi-pencil"></i> Dz�,D�D_�?D,</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐœÐ¸Ð½Ð°Ð»Ð¸ Ð·Ð°Ð´Ð°Ð½Ð¸Ñ</strong></div>
                    <div class="card-body">
                        <?php if (empty($teacher['assignments_past'])): ?>
                            <div class="text-muted">ÐÑÐ¼Ð° Ð¿Ñ€Ð¸ÐºÐ»ÑŽÑ‡Ð¸Ð»Ð¸ Ð·Ð°Ð´Ð°Ð½Ð¸Ñ.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($teacher['assignments_past'] as $assignment): ?>
                                    <?php
                                    $submittedCount = (int)($assignment['submitted_count'] ?? 0);
                                    $gradedCount = (int)($assignment['graded_count'] ?? 0);
                                    $needsGrade = (int)($assignment['needs_grade'] ?? 0);
                                    $primaryClassId = isset($assignment['primary_class_id']) ? (int)$assignment['primary_class_id'] : 0;
                                    $overviewLink = 'assignment_overview.php?id=' . (int)$assignment['id'];
                                    if ($primaryClassId > 0) {
                                        $overviewLink .= '&class_id=' . $primaryClassId;
                                    ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="me-3">
                                            <div class="fw-semibold">
                                                <a class="text-decoration-none" href="<?= htmlspecialchars($overviewLink) ?>"><?= htmlspecialchars($assignment['title']) ?></a>
                                                <?php if (!empty($assignment['is_strict_mode'])): ?>
                                                    <span class="badge bg-danger ms-2">Строг режим</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                <?php if (!empty($assignment['due_at'])): ?>
                                                    Ð”Ð¾: <?= htmlspecialchars($assignment['due_at']) ?>
                                                <?php elseif (!empty($assignment['close_at'])): ?>
                                                    Ð—Ð°Ñ‚Ð²Ð¾Ñ€ÐµÐ½Ð¾: <?= htmlspecialchars($assignment['close_at']) ?>
                                                <?php else: ?>
                                                    Ð‘ÐµÐ· Ð¿Ð¾ÑÐ¾Ñ‡ÐµÐ½ ÐºÑ€Ð°ÐµÐ½ ÑÑ€Ð¾Ðº
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                ÐŸÐ¾Ð´Ð°Ð´ÐµÐ½Ð¸: <?= $submittedCount ?> / ÐžÑ†ÐµÐ½ÐµÐ½Ð¸: <?= $gradedCount ?>
                                            </div>
                                            <?php if ($needsGrade > 0): ?>
                                                <span class="badge bg-warning text-dark mt-2">Ð—Ð° Ð¾Ñ†ÐµÐ½ÑÐ²Ð°Ð½Ðµ: <?= $needsGrade ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-column align-items-end gap-2">
                                            <span class="badge bg-secondary">ÐœÐ¸Ð½Ð°Ð»Ð¾</span>
                                            <a class="btn btn-sm btn-outline-secondary" href="assignments_create.php?id=<?= (int)$assignment['id'] ?>"><i class="bi bi-clipboard-check"></i> ÐžÑ†ÐµÐ½Ð¸</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Student Dashboard -->
        <div class="row g-3 g-md-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐœÐ¾Ð¸Ñ‚Ðµ ÐºÐ»Ð°ÑÐ¾Ð²Ðµ</strong></div>
                    <div class="card-body">
                        <?php if (empty($student['classes'])): ?>
                            <div class="text-muted">ÐÐµ ÑÑ‚Ðµ Ð´Ð¾Ð±Ð°Ð²ÐµÐ½Ð¸ Ð² ÐºÐ»Ð°Ñ.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($student['classes'] as $c): ?>
                                    <div class="list-group-item">
                                        <?= htmlspecialchars($c['grade'] . $c['section']) ?> â€¢ <?= htmlspecialchars($c['school_year']) ?>
                                        <span class="text-muted small ms-2"><?= htmlspecialchars($c['name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐžÑ‚Ð²Ð¾Ñ€ÐµÐ½Ð¸ Ð·Ð°Ð´Ð°Ð½Ð¸Ñ</strong></div>
                    <div class="card-body">
                        <?php if (empty($student['open_assignments'])): ?>
                            <div class="text-muted">ÐÑÐ¼Ð°Ñ‚Ðµ Ð°ÐºÑ‚Ð¸Ð²Ð½Ð¸ Ð·Ð°Ð´Ð°Ð½Ð¸Ñ Ð² Ð¼Ð¾Ð¼ÐµÐ½Ñ‚Ð°.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($student['open_assignments'] as $a): $lastAttemptId = $student['open_attempts_map'][(int)$a['id']] ?? null; ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div class="me-2">
                                            <div class="fw-semibold"><a class="text-decoration-none" href="assignment.php?id=<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['title']) ?></a></div>
                                            <div class="text-muted small">Ð¢ÐµÑÑ‚: <?= htmlspecialchars($a['test_title']) ?></div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <small class="text-muted">
                                                <?php if (!empty($a['due_at'])): ?>Ð”Ð¾ <?= htmlspecialchars($a['due_at']) ?><?php else: ?>Ð‘ÐµÐ· ÑÑ€Ð¾Ðº<?php endif; ?>
                                            </small>
                                            <?php if ($lastAttemptId): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="student_attempt.php?id=<?= (int)$lastAttemptId ?>"><i class="bi bi-eye"></i> ÐŸÑ€ÐµÐ³Ð»ÐµÐ´</a>
                                            <?php endif; ?>
                                            <a class="btn btn-sm btn-primary" href="assignment.php?id=<?= (int)$a['id'] ?>"><i class="bi bi-play-fill"></i> Ð ÐµÑˆÐ¸</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 g-md-4 mt-1 mt-md-2">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐŸÐ¾ÑÐ»ÐµÐ´Ð½Ð¸ Ð¾Ð¿Ð¸Ñ‚Ð¸</strong></div>
                    <div class="card-body">
                        <?php if (empty($student['recent_attempts'])): ?>
                            <div class="text-muted">Ð’ÑÐµ Ð¾Ñ‰Ðµ Ð½ÑÐ¼Ð°Ñ‚Ðµ Ð¿Ñ€ÐµÐ´Ð°Ð´ÐµÐ½Ð¸ Ð¾Ð¿Ð¸Ñ‚Ð¸.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($student['recent_attempts'] as $ra): $p = percent($ra['score_obtained'], $ra['max_score']); $autoGrade = grade_from_percent($p); $displayGrade = $ra['teacher_grade'] !== null ? (int)$ra['teacher_grade'] : $autoGrade; ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($ra['assignment_title']) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($ra['submitted_at'] ?: $ra['started_at']) ?></div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge <?= ($p !== null && $p >= 50) ? 'bg-success' : 'bg-danger' ?>"><?= $p !== null ? $p . '%' : 'â€”' ?></span>
                                            <span class="badge bg-primary"><?= $displayGrade !== null ? $displayGrade : 'â€”' ?></span>
                                            <a class="btn btn-sm btn-outline-secondary" href="student_attempt.php?id=<?= (int)$ra['id'] ?>"><i class="bi bi-eye"></i> ÐŸÑ€ÐµÐ³Ð»ÐµÐ´</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white"><strong>ÐžÐ±Ñ‰ Ð½Ð°Ð¿Ñ€ÐµÐ´ÑŠÐº</strong></div>
                    <div class="card-body">
                        <?php if (!$student['overview']): ?>
                            <div class="text-muted">ÐÑÐ¼Ð° Ð´Ð¾ÑÑ‚Ð°Ñ‚ÑŠÑ‡Ð½Ð¾ Ð´Ð°Ð½Ð½Ð¸.</div>
                        <?php else: ?>
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="h4 m-0"><?= (int)$student['overview']['assignments_taken'] ?></div>
                                    <div class="text-muted small">Ð—Ð°Ð´Ð°Ð½Ð¸Ñ</div>
                                </div>
                                <div class="col-4">
                                    <div class="h4 m-0"><?= (float)$student['overview']['avg_percent'] ?>%</div>
                                    <div class="text-muted small">Ð¡Ñ€ÐµÐ´ÐµÐ½ %</div>
                                </div>
                                <div class="col-4">
                                    <div class="h6 m-0"><?= htmlspecialchars($student['overview']['last_activity']) ?></div>
                                    <div class="text-muted small">ÐÐºÑ‚Ð¸Ð²Ð½Ð¾ÑÑ‚</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<footer class="border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <div class="text-muted">Â© <?= date('Y'); ?> TestGramatikov</div>
        <div class="d-flex gap-3 small">
            <a class="text-decoration-none" href="terms.php">Ð£ÑÐ»Ð¾Ð²Ð¸Ñ</a>
            <a class="text-decoration-none" href="privacy.php">ÐŸÐ¾Ð²ÐµÑ€Ð¸Ñ‚ÐµÐ»Ð½Ð¾ÑÑ‚</a>
            <a class="text-decoration-none" href="contact.php">ÐšÐ¾Ð½Ñ‚Ð°ÐºÑ‚</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</footer>
</body>
</html>
