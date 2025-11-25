<?php
session_start();
require_once __DIR__ . '/config.php';
// header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = null;
try {
    $pdo = db();
    ensure_attempts_grade($pdo);
    ensure_subjects_scope($pdo);
} catch (Throwable $e) {
    $pdo = null;
}

function percent($score, $max)
{
    if ($score === null || $max === null || $max <= 0)
        return null;
    return round(($score / $max) * 100, 2);
}

function grade_from_percent(?float $percent): ?int
{
    if ($percent === null)
        return null;
    if ($percent >= 90)
        return 6;
    if ($percent >= 80)
        return 5;
    if ($percent >= 65)
        return 4;
    if ($percent >= 50)
        return 3;
    return 2;
}

function normalize_filter_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) {
        $value .= ':00';
    }
    return $value;
}

function render_preserved_filters(array $excludeKeys = []): void
{
    foreach ($_GET as $param => $value) {
        if (in_array($param, $excludeKeys, true))
            continue;
        if (is_array($value))
            continue;
        echo '<input type="hidden" name="' . htmlspecialchars($param, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">' . PHP_EOL;
    }
}

function format_class_label(array $class): string
{
    $parts = [];
    $gradeSection = trim((string) ($class['grade'] ?? '') . (string) ($class['section'] ?? ''));
    if ($gradeSection !== '')
        $parts[] = $gradeSection;
    if (!empty($class['school_year']))
        $parts[] = (string) $class['school_year'];
    if (!empty($class['name']))
        $parts[] = (string) $class['name'];
    return implode(' • ', $parts);
}

// Initialize containers
$teacher = [
    'classes' => [],
    'class_options' => [],
    'tests' => [],
    'recent_attempts' => [],
    'recent_attempts_meta' => [
        'page' => 1,
        'per_page' => 5,
        'pages' => 1,
        'total' => 0,
    ],
    'recent_attempts_class_options' => [],
    'assignments_current' => [],
    'assignments_past' => [],
    'assignments_past_class_options' => [],
    'class_stats' => [],
    'class_stats_options' => [],
];
$student = [
    'classes' => [],
    'open_assignments' => [],
    'recent_attempts' => [],
    'overview' => null,
];

// Persist teacher dashboard filters in session
if ($user['role'] === 'teacher') {
    $filter_keys = ['c_q', 'c_sort', 'c_class_id', 't_q', 't_subject', 't_visibility', 't_status', 't_sort', 'a_q', 'a_from', 'a_to', 'a_sort', 'a_page', 'ra_class_id', 'ap_page', 'ap_class_id', 'ca_class_id', 'ca_sort'];
    if (isset($_GET['reset'])) {
        unset($_SESSION['dash_filters']);
        header('Location: dashboard.php');
        exit;
    }
    if (!empty($_GET)) {
        $save = [];
        foreach ($filter_keys as $k) {
            if (array_key_exists($k, $_GET)) {
                $save[$k] = $_GET[$k];
            }
        }
        if ($save) {
            $_SESSION['dash_filters'] = $save;
        }
    } elseif (!empty($_SESSION['dash_filters'])) {
        foreach ($_SESSION['dash_filters'] as $k => $v) {
            $_GET[$k] = $v;
        }
    }
}

if ($pdo) {
    if ($user['role'] === 'teacher') {
        // Handle teacher grade update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'set_grade') {
            $attempt_id = (int) ($_POST['attempt_id'] ?? 0);
            $grade = isset($_POST['teacher_grade']) && $_POST['teacher_grade'] !== '' ? (int) $_POST['teacher_grade'] : null;
            if ($attempt_id > 0 && ($grade === null || ($grade >= 2 && $grade <= 6))) {
                $upd = $pdo->prepare('UPDATE attempts atp JOIN assignments a ON a.id = atp.assignment_id SET atp.teacher_grade = :g WHERE atp.id = :id AND a.assigned_by_teacher_id = :tid');
                $upd->execute([':g' => $grade, ':id' => $attempt_id, ':tid' => (int) $user['id']]);
            }
        }
        // Teacher: classes (initial load; refined below by filters)
        $stmt = $pdo->prepare('SELECT id, grade, section, school_year, name, created_at FROM classes WHERE teacher_id = :tid ORDER BY school_year DESC, grade, section');
        $stmt->execute([':tid' => (int) $user['id']]);
        $teacher['classes'] = $stmt->fetchAll();
        $teacher['class_options'] = $teacher['classes'];

        // Teacher: own tests (initial load; refined below by filters)
        $stmt = $pdo->prepare('SELECT id, title, visibility, status, updated_at FROM tests WHERE owner_teacher_id = :tid ORDER BY updated_at DESC LIMIT 12');
        $stmt->execute([':tid' => (int) $user['id']]);
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
        $stmt->execute([':tid' => (int) $user['id']]);
        $teacher['recent_attempts'] = $stmt->fetchAll();

        // Teacher: class stats via view (if present)
        try {
            $stmt = $pdo->prepare('SELECT v.class_id, v.assignment_id, v.avg_percent, c.grade, c.section, c.school_year
                                   FROM v_class_assignment_stats v
                                   JOIN classes c ON c.id = v.class_id
                                   WHERE c.teacher_id = :tid
                                   ORDER BY v.avg_percent DESC
                                   LIMIT 10');
            $stmt->execute([':tid' => (int) $user['id']]);
            $teacher['class_stats'] = $stmt->fetchAll();
        } catch (Throwable $e) {
            $teacher['class_stats'] = [];
        }

        // ---------- Apply optional filters and sorting (override defaults) ----------
        $t_q = isset($_GET['t_q']) ? trim((string) $_GET['t_q']) : '';
        $t_subject = (isset($_GET['t_subject']) && $_GET['t_subject'] !== '') ? (int) $_GET['t_subject'] : null;
        $t_visibility = in_array(($_GET['t_visibility'] ?? ''), ['private', 'shared'], true) ? $_GET['t_visibility'] : '';
        $t_status = in_array(($_GET['t_status'] ?? ''), ['draft', 'published', 'archived'], true) ? $_GET['t_status'] : '';
        $t_sort = $_GET['t_sort'] ?? '';

        $c_q = isset($_GET['c_q']) ? trim((string) $_GET['c_q']) : '';
        $c_sort = $_GET['c_sort'] ?? '';
        $c_class_id = (isset($_GET['c_class_id']) && $_GET['c_class_id'] !== '') ? (int) $_GET['c_class_id'] : null;

        $a_q = isset($_GET['a_q']) ? trim((string) $_GET['a_q']) : '';
        $a_from_raw = (string) ($_GET['a_from'] ?? '');
        $a_to_raw = (string) ($_GET['a_to'] ?? '');
        $a_from = $a_from_raw !== '' ? normalize_filter_datetime($a_from_raw) : '';
        $a_to = $a_to_raw !== '' ? normalize_filter_datetime($a_to_raw) : '';
        $a_sort = $_GET['a_sort'] ?? '';
        $a_page = max(1, (int) ($_GET['a_page'] ?? 1));
        $ra_class_id = (isset($_GET['ra_class_id']) && $_GET['ra_class_id'] !== '') ? (int) $_GET['ra_class_id'] : null;
        $ap_page = max(1, (int) ($_GET['ap_page'] ?? 1));
        $ap_class_id = (isset($_GET['ap_class_id']) && $_GET['ap_class_id'] !== '') ? (int) $_GET['ap_class_id'] : null;

        $ca_class_id = (isset($_GET['ca_class_id']) && $_GET['ca_class_id'] !== '') ? (int) $_GET['ca_class_id'] : null;
        $ca_sort = $_GET['ca_sort'] ?? '';

        // Re-query classes with filters
        $clsSql = 'SELECT id, grade, section, school_year, name, created_at FROM classes WHERE teacher_id = :tid';
        $params = [':tid' => (int) $user['id']];
        if ($c_q !== '') {
            $clsSql .= ' AND (name LIKE :q OR section LIKE :q OR CONCAT(grade, section) LIKE :q)';
            $params[':q'] = '%' . $c_q . '%';
        }
        if ($c_class_id) {
            $clsSql .= ' AND id = :class_filter_id';
            $params[':class_filter_id'] = $c_class_id;
        }
        $order = ' ORDER BY school_year DESC, grade, section';
        if ($c_sort === 'grade_asc')
            $order = ' ORDER BY grade ASC, section ASC, school_year DESC';
        if ($c_sort === 'name_asc')
            $order = ' ORDER BY name ASC';
        if ($c_sort === 'name_desc')
            $order = ' ORDER BY name DESC';
        $stmt = $pdo->prepare($clsSql . $order);
        $stmt->execute($params);
        $teacher['classes'] = $stmt->fetchAll();

        // Re-query tests with filters
        $testsSql = 'SELECT id, title, visibility, status, updated_at FROM tests WHERE owner_teacher_id = :tid';
        $tParams = [':tid' => (int) $user['id']];
        if ($t_subject) {
            $testsSql .= ' AND subject_id = :sid';
            $tParams[':sid'] = $t_subject;
        }
        if ($t_visibility !== '') {
            $testsSql .= ' AND visibility = :vis';
            $tParams[':vis'] = $t_visibility;
        }
        if ($t_status !== '') {
            $testsSql .= ' AND status = :st';
            $tParams[':st'] = $t_status;
        }
        if ($t_q !== '') {
            $testsSql .= ' AND title LIKE :tq';
            $tParams[':tq'] = '%' . $t_q . '%';
        }
        $tOrder = ' ORDER BY updated_at DESC';
        if ($t_sort === 'updated_asc')
            $tOrder = ' ORDER BY updated_at ASC';
        if ($t_sort === 'title_asc')
            $tOrder = ' ORDER BY title ASC';
        if ($t_sort === 'title_desc')
            $tOrder = ' ORDER BY title DESC';
        $stmt = $pdo->prepare($testsSql . $tOrder . ' LIMIT 50');
        $stmt->execute($tParams);
        $teacher['tests'] = $stmt->fetchAll();

        try {
            $raClassSql = 'SELECT DISTINCT c.id, c.grade, c.section, c.school_year, c.name
                           FROM classes c
                           JOIN assignment_classes ac ON ac.class_id = c.id
                           JOIN assignments a ON a.id = ac.assignment_id
                           JOIN attempts atp ON atp.assignment_id = a.id
                           WHERE c.teacher_id = :tid AND a.assigned_by_teacher_id = :tid
                             AND atp.status IN ("submitted","graded")
                           ORDER BY c.school_year DESC, c.grade, c.section';
            $raClassStmt = $pdo->prepare($raClassSql);
            $raClassStmt->execute([':tid' => (int) $user['id']]);
            $teacher['recent_attempts_class_options'] = $raClassStmt->fetchAll();
        } catch (Throwable $e) {
            $teacher['recent_attempts_class_options'] = [];
        }

        // Re-query recent attempts with filters + pagination
        $attemptsPerPage = 5;
        $aSelect = 'SELECT atp.id, atp.student_id, atp.submitted_at, atp.started_at, atp.score_obtained, atp.max_score, atp.teacher_grade,
                           a.title AS assignment_title, u.first_name, u.last_name';
        $aFrom = ' FROM attempts atp
                   JOIN assignments a ON a.id = atp.assignment_id
                   JOIN users u ON u.id = atp.student_id
                   WHERE a.assigned_by_teacher_id = :tid AND atp.status IN ("submitted","graded")';
        $aParams = [':tid' => (int) $user['id']];
        if ($a_q !== '') {
            $aFrom .= ' AND (a.title LIKE :aq OR u.first_name LIKE :aq OR u.last_name LIKE :aq)';
            $aParams[':aq'] = '%' . $a_q . '%';
        }
        if ($a_from !== '') {
            $aFrom .= ' AND COALESCE(atp.submitted_at, atp.started_at) >= :af';
            $aParams[':af'] = $a_from;
        }
        if ($a_to !== '') {
            $aFrom .= ' AND COALESCE(atp.submitted_at, atp.started_at) <= :at';
            $aParams[':at'] = $a_to;
        }
        if ($ra_class_id) {
            $aFrom .= ' AND EXISTS (SELECT 1 FROM assignment_classes ac WHERE ac.assignment_id = a.id AND ac.class_id = :ra_class_id)';
            $aParams[':ra_class_id'] = $ra_class_id;
        }
        $aOrder = ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC';
        if ($a_sort === 'date_asc')
            $aOrder = ' ORDER BY COALESCE(atp.submitted_at, atp.started_at) ASC';
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $aFrom);
        foreach ($aParams as $param => $value) {
            $countStmt->bindValue($param, $value);
        }
        $countStmt->execute();
        $totalAttempts = (int) $countStmt->fetchColumn();
        $totalPages = $totalAttempts > 0 ? (int) ceil($totalAttempts / $attemptsPerPage) : 1;
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($totalAttempts === 0) {
            $a_page = 1;
        } elseif ($a_page > $totalPages) {
            $a_page = $totalPages;
        }
        $offset = ($a_page - 1) * $attemptsPerPage;
        $stmt = $pdo->prepare($aSelect . $aFrom . $aOrder . ' LIMIT :limit OFFSET :offset');
        foreach ($aParams as $param => $value) {
            $stmt->bindValue($param, $value);
        }
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
        $assignSql = 'SELECT a.id, a.title, a.open_at, a.due_at, a.close_at, a.created_at,
                             SUM(CASE WHEN atp.status IN ("submitted","graded") THEN 1 ELSE 0 END) AS submitted_count,
                             SUM(CASE WHEN atp.status = "graded" OR atp.teacher_grade IS NOT NULL THEN 1 ELSE 0 END) AS graded_count,
                             SUM(CASE WHEN atp.status = "submitted" AND atp.teacher_grade IS NULL THEN 1 ELSE 0 END) AS needs_grade,
                             MIN(ac.class_id) AS primary_class_id,
                             MAX(t.is_strict_mode) AS is_strict_mode,
                             MAX(COALESCE(atp.submitted_at, atp.started_at)) AS last_activity_at
                      FROM assignments a
                      LEFT JOIN attempts atp ON atp.assignment_id = a.id
                      LEFT JOIN tests t ON t.id = a.test_id
                      LEFT JOIN assignment_classes ac ON ac.assignment_id = a.id
                      WHERE a.assigned_by_teacher_id = :tid';
        $assignParams = [':tid' => (int) $user['id']];
        if ($a_q !== '') {
            $assignSql .= ' AND a.title LIKE :assign_q';
            $assignParams[':assign_q'] = '%' . $a_q . '%';
        }
        if ($a_from !== '') {
            $assignSql .= ' AND COALESCE(a.close_at, a.due_at, a.open_at) >= :assign_from';
            $assignParams[':assign_from'] = $a_from;
        }
        if ($a_to !== '') {
            $assignSql .= ' AND COALESCE(a.close_at, a.due_at, a.open_at) <= :assign_to';
            $assignParams[':assign_to'] = $a_to;
        }
        $assignSql .= ' GROUP BY a.id
                        ORDER BY COALESCE(a.close_at, a.due_at, a.open_at, NOW()) DESC, a.id DESC
                        LIMIT 50';
        $stmt = $pdo->prepare($assignSql);
        $stmt->execute($assignParams);
        $assignmentRows = $stmt->fetchAll();
        $assignmentClassesMap = [];
        if ($assignmentRows) {
            $assignmentIds = array_map(fn($row) => (int) $row['id'], $assignmentRows);
            $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
            $acParams = $assignmentIds;
            $acParams[] = (int) $user['id'];
            $query = "SELECT ac.assignment_id, c.id AS class_id, c.grade, c.section, c.school_year, c.name
                      FROM assignment_classes ac
                      JOIN classes c ON c.id = ac.class_id
                      WHERE ac.assignment_id IN ($placeholders) AND c.teacher_id = ?";
            $acStmt = $pdo->prepare($query);
            $acStmt->execute($acParams);
            while ($row = $acStmt->fetch()) {
                $aid = (int) $row['assignment_id'];
                $assignmentClassesMap[$aid][] = [
                    'id' => (int) $row['class_id'],
                    'grade' => $row['grade'],
                    'section' => $row['section'],
                    'school_year' => $row['school_year'],
                    'name' => $row['name'],
                ];
            }
        }
        $now = date('Y-m-d H:i:s');
        $nowTs = strtotime($now);
        $staleThreshold = strtotime('-12 hours', $nowTs);
        $currentAssignments = [];
        $pastAssignments = [];
        foreach ($assignmentRows as $row) {
            $row['submitted_count'] = (int) ($row['submitted_count'] ?? 0);
            $row['graded_count'] = (int) ($row['graded_count'] ?? 0);
            $row['needs_grade'] = (int) ($row['needs_grade'] ?? 0);
            $openAt = $row['open_at'] ?? null;
            $dueAt = $row['due_at'] ?? null;
            $closeAt = $row['close_at'] ?? null;
            $lastActivity = $row['last_activity_at'] ?? null;
            $createdAt = $row['created_at'] ?? null;

            $isPast = false;
            if ($closeAt && $closeAt < $now) {
                $isPast = true;
            } elseif (!$closeAt && $dueAt && $dueAt < $now) {
                $isPast = true;
            } elseif (!$closeAt && !$dueAt) {
                $reference = $lastActivity ?: $createdAt;
                if ($reference && strtotime($reference) <= $staleThreshold) {
                    $isPast = true;
                }
            }

            $row['classes'] = $assignmentClassesMap[(int) $row['id']] ?? [];
            if ($isPast) {
                $row['status'] = 'past';
                $pastAssignments[] = $row;
            } else {
                $isOpen = !$openAt || $openAt <= $now;
                if ($isOpen) {
                    $row['status'] = 'current';
                } else {
                    $row['status'] = 'upcoming';
                }
                $currentAssignments[] = $row;
            }
        }
        $teacher['assignments_current'] = array_slice($currentAssignments, 0, 8);

        $pastClassOptions = [];
        foreach ($pastAssignments as $row) {
            if (!empty($row['classes'])) {
                foreach ($row['classes'] as $cls) {
                    $cid = (int) ($cls['id'] ?? 0);
                    if ($cid > 0) {
                        $pastClassOptions[$cid] = $cls;
                    }
                }
            }
        }
        $teacher['assignments_past_class_options'] = array_values($pastClassOptions);

        if ($ap_class_id) {
            $pastAssignments = array_values(array_filter($pastAssignments, function ($assignment) use ($ap_class_id) {
                if (empty($assignment['classes']))
                    return false;
                foreach ($assignment['classes'] as $cls) {
                    if ((int) ($cls['id'] ?? 0) === $ap_class_id)
                        return true;
                }
                return false;
            }));
        }

        $pastPerPage = 5;
        $pastTotal = count($pastAssignments);
        $pastPages = max(1, (int) ceil($pastTotal / $pastPerPage));
        if ($pastTotal === 0) {
            $ap_page = 1;
        } elseif ($ap_page > $pastPages) {
            $ap_page = $pastPages;
        }
        $pastOffset = ($ap_page - 1) * $pastPerPage;
        $teacher['assignments_past'] = array_slice($pastAssignments, $pastOffset, $pastPerPage);
        $teacher['assignments_past_meta'] = [
            'page' => $ap_page,
            'per_page' => $pastPerPage,
            'pages' => $pastPages,
            'total' => $pastTotal,
        ];

        // Re-query class analytics with filters
        try {
            $caSql = 'SELECT v.class_id, v.assignment_id, v.avg_percent, c.grade, c.section, c.school_year
                      FROM v_class_assignment_stats v
                      JOIN classes c ON c.id = v.class_id
                      WHERE c.teacher_id = :tid';
            $caParams = [':tid' => (int) $user['id']];
            if ($ca_class_id) {
                $caSql .= ' AND v.class_id = :cid';
                $caParams[':cid'] = $ca_class_id;
            }
            $caOrder = ' ORDER BY v.avg_percent DESC';
            if ($ca_sort === 'percent_asc')
                $caOrder = ' ORDER BY v.avg_percent ASC';
            $stmt = $pdo->prepare($caSql . $caOrder . ' LIMIT 20');
            $stmt->execute($caParams);
            $teacher['class_stats'] = $stmt->fetchAll();
        } catch (Throwable $e) {
            $teacher['class_stats'] = [];
        }
        try {
            $statOptionsStmt = $pdo->prepare('SELECT DISTINCT c.id, c.grade, c.section, c.school_year, c.name
                                              FROM v_class_assignment_stats v
                                              JOIN classes c ON c.id = v.class_id
                                              WHERE c.teacher_id = :tid
                                              ORDER BY c.school_year DESC, c.grade, c.section');
            $statOptionsStmt->execute([':tid' => (int) $user['id']]);
            $teacher['class_stats_options'] = $statOptionsStmt->fetchAll();
        } catch (Throwable $e) {
            $teacher['class_stats_options'] = [];
        }
    } elseif ($user['role'] === 'student') {
        $stmt = $pdo->prepare('SELECT c.*
                               FROM classes c
                               JOIN class_students cs ON cs.class_id = c.id
                               WHERE cs.student_id = :sid
                               ORDER BY c.school_year DESC, c.grade, c.section');
        $stmt->execute([':sid' => (int) $user['id']]);
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
        $stmt->execute([':sid' => (int) $user['id']]);
        $student['open_assignments'] = $stmt->fetchAll();

        // Map latest attempt per open assignment
        $student['open_attempts_map'] = [];
        if (!empty($student['open_assignments'])) {
            $ids = array_map(fn($a) => (int) $a['id'], $student['open_assignments']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT assignment_id, MAX(id) AS last_attempt_id FROM attempts WHERE student_id = ? AND assignment_id IN ($in) GROUP BY assignment_id");
            $params = array_merge([(int) $user['id']], $ids);
            $q->execute($params);
            while ($row = $q->fetch()) {
                $student['open_attempts_map'][(int) $row['assignment_id']] = (int) $row['last_attempt_id'];
            }
        }

        // Student: recent attempts
        $stmt = $pdo->prepare('SELECT atp.*, a.title AS assignment_title
                               FROM attempts atp
                               JOIN assignments a ON a.id = atp.assignment_id
                               WHERE atp.student_id = :sid
                               ORDER BY COALESCE(atp.submitted_at, atp.started_at) DESC
                               LIMIT 10');
        $stmt->execute([':sid' => (int) $user['id']]);
        $student['recent_attempts'] = $stmt->fetchAll();

        // Student: overview view (if present)
        try {
            $stmt = $pdo->prepare('SELECT * FROM v_student_overview WHERE student_id = :sid LIMIT 1');
            $stmt->execute([':sid' => (int) $user['id']]);
            $student['overview'] = $stmt->fetch();
        } catch (Throwable $e) {
            $student['overview'] = null;
        }
    }
}

$heroStats = [];
$heroSubtitle = $user['role'] === 'teacher'
    ? 'Организирай класове, задания и оценки от едно място.'
    : 'Проследявай заданията си и подобрявай резултатите си.';

if ($user['role'] === 'teacher') {
    $pendingGradesTotal = 0;
    foreach ($teacher['assignments_current'] as $pending) {
        $pendingGradesTotal += (int) ($pending['needs_grade'] ?? 0);
    }
    $heroStats = [
        ['label' => 'Класове', 'value' => count($teacher['classes'])],
        ['label' => 'Тестове', 'value' => count($teacher['tests'])],
        ['label' => 'За оценяване', 'value' => $pendingGradesTotal],
    ];
} else {
    $avgPercent = null;
    if (!empty($student['overview']['avg_percent'])) {
        $avgPercent = round((float) $student['overview']['avg_percent'], 1) . '%';
    }
    $studentClassLabels = [];
    foreach ($student['classes'] as $cls) {
        $studentClassLabels[] = format_class_label($cls);
    }
    $classDetail = $studentClassLabels ? implode(', ', $studentClassLabels) : null;
    $heroStats = [
        ['label' => 'Класове', 'value' => count($student['classes']), 'detail' => $classDetail],
        ['label' => 'Активни задания', 'value' => count($student['open_assignments'])],
        ['label' => 'Среден резултат', 'value' => $avgPercent ?? '—'],
    ];
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$currentUrl = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
$currentUrlSafe = htmlspecialchars($currentUrl, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Табло – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --tg-dashboard-bg-light: linear-gradient(180deg, #f4f7fb 0%, #ffffff 60%);
            --tg-dashboard-bg-dark: radial-gradient(circle at top, rgba(59, 130, 246, 0.25), transparent 55%) #050b18;
            --tg-card-bg-light: #ffffff;
            --tg-card-bg-dark: #111927;
        }

        body {
            background: var(--tg-dashboard-bg-light);
            min-height: 100vh;
        }

        html[data-bs-theme="dark"] body {
            background: var(--tg-dashboard-bg-dark);
            color: #e2e8f0;
        }

        .brand-badge {
            background: rgba(13, 110, 253, .12);
            border: 1px solid rgba(13, 110, 253, .25);
            color: #0d6efd;
        }

        .dashboard-hero {
            background: radial-gradient(circle at top right, rgba(111, 66, 193, 0.25), transparent 55%),
                linear-gradient(135deg, #0d6efd, #6f42c1);
            border-radius: 1.5rem;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 25px 45px rgba(13, 52, 115, .35);
        }

        html[data-bs-theme="dark"] .dashboard-hero {
            background: linear-gradient(135deg, #1f3b8c, #6f42c1);
            box-shadow: 0 25px 50px rgba(0, 0, 0, .55);
        }

        .dashboard-hero::after {
            content: '';
            position: absolute;
            inset: 1.5rem;
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 1.5rem;
            pointer-events: none;
        }

        .hero-label {
            text-transform: uppercase;
            letter-spacing: .15em;
            font-size: .75rem;
            opacity: .85;
        }

        .hero-actions .btn {
            backdrop-filter: blur(6px);
            border-color: rgba(255, 255, 255, .4);
            color: #fff;
        }
        }

        .stat-pill small {
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: .08em;
        }

        .section-card {
            border: none;
            border-radius: 1rem;
            background: var(--tg-card-bg-light);
            box-shadow: 0 20px 40px rgba(15, 23, 42, .08);
            transition: box-shadow .2s ease, transform .2s ease;
        }

        html[data-bs-theme="dark"] .section-card {
            background: var(--tg-card-bg-dark);
            box-shadow: 0 25px 45px rgba(0, 0, 0, .65);
        }

        .section-card .card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: transparent;
            border-bottom: 1px solid rgba(15, 23, 42, .06);
            padding: 1rem 1.5rem;
        }

        html[data-bs-theme="dark"] .section-card .card-header {
            border-bottom-color: rgba(148, 163, 184, .15);
        }

        .section-card .card-header .section-title {
            display: flex;
            align-items: center;
            gap: .65rem;
            flex: 1;
        }

        .section-title i {
            font-size: 1.2rem;
            color: #0d6efd;
        }

        html[data-bs-theme="dark"] .section-title i {
            color: #60a5fa;
        }

        .list-elevated .list-group-item {
            border: none;
            border-radius: .75rem;
            margin-bottom: .75rem;
            background: #f8f9fb;
        }

        html[data-bs-theme="dark"] .list-elevated .list-group-item {
            background: rgba(255, 255, 255, .04);
            color: inherit;
        }

        .list-elevated .list-group-item:last-child {
            margin-bottom: 0;
        }

        .list-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .4rem;
            justify-content: flex-end;
        }

        .action-icon-btn {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: .75rem;
            border: 1px solid rgba(148, 163, 184, .45);
            background: transparent;
            color: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .2s ease, color .2s ease, border-color .2s ease;
        }

        html[data-bs-theme="dark"] .action-icon-btn {
            border-color: rgba(148, 163, 184, .5);
        }

        .action-icon-btn--primary {
            border-color: rgba(13, 110, 253, .4);
            color: #0d6efd;
        }

        .action-icon-btn--primary:hover {
            background: rgba(13, 110, 253, .1);
        }

        .action-icon-btn--danger {
            border-color: rgba(220, 53, 69, .4);
            color: #dc3545;
        }

        .action-icon-btn--danger:hover {
            background: rgba(220, 53, 69, .12);
        }

        .action-icon-btn--muted {
            border-color: rgba(148, 163, 184, .6);
            color: rgba(15, 23, 42, .75);
        }

        html[data-bs-theme="dark"] .action-icon-btn--muted {
            color: rgba(226, 232, 240, .85);
        }

        .action-icon-btn--muted:hover {
            background: rgba(148, 163, 184, .12);
        }

        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }

        /* ---- ЛЕЙАУТ НА КАРТИТЕ ----
           Мобилни: 1 на ред
           ≥992px (desktop): точно 2 на ред
        */
        .dashboard-columns {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 992px) {
            .dashboard-columns {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* Нулирай влиянието на вложени Bootstrap редове/колони в секциите на таблото */
        .dashboard-columns>.row {
            display: contents;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        .dashboard-columns>.row>[class*="col"] {
            display: contents;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .dashboard-columns .section-card {
            height: 100%;
            width: 100%;
        }

        .filter-card form {
            border: 1px dashed rgba(13, 110, 253, .35);
            border-radius: 1rem;
            padding: 1rem;
            background: rgba(13, 110, 253, .03);
        }

        html[data-bs-theme="dark"] .filter-card form {
            background: rgba(255, 255, 255, .02);
            border-color: rgba(96, 165, 250, .4);
        }

        .filter-card form .form-label {
            font-weight: 600;
            font-size: .9rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.45rem;
            top: .75rem;
            width: .45rem;
            height: .45rem;
            border-radius: 50%;
            background: #0d6efd;
        }

        .timeline-item {
            position: relative;
            padding-left: .5rem;
        }

        .empty-state {
            background: #f8f9fb;
            border-radius: 1rem;
            padding: 1.25rem;
            text-align: center;
        }

        html[data-bs-theme="dark"] .empty-state {
            background: rgba(255, 255, 255, .05);
        }

        .section-card .card-body>.text-muted {
            background: #f8f9fb;
            border-radius: 1rem;
            padding: 1.25rem;
        }

        html[data-bs-theme="dark"] .section-card .card-body>.text-muted {
            background: rgba(255, 255, 255, .04);
        }

        .assignment-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .2rem .65rem;
            border-radius: 999px;
            border: 1px solid rgba(13, 110, 253, .25);
            background: rgba(13, 110, 253, .08);
            font-size: .8rem;
        }

        html[data-bs-theme="dark"] .assignment-chip {
            border-color: rgba(96, 165, 250, .4);
            background: rgba(96, 165, 250, .15);
            color: #e2e8f0;
        }

        .assignment-chip button {
            border: none;
            background: transparent;
            color: inherit;
            display: inline-flex;
            align-items: center;
            padding: 0;
            cursor: pointer;
            line-height: 1;
        }

        .assignment-chip button:hover,
        .assignment-chip button:focus {
            color: #dc3545;
        }

        .card-toggle {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            border: 1px solid rgba(13, 110, 253, .25);
            background: rgba(13, 110, 253, .12);
            color: #0d6efd;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .2s ease, transform .2s ease;
            cursor: pointer;
        }

        html[data-bs-theme="dark"] .card-toggle {
            border-color: rgba(255, 255, 255, .2);
            background: rgba(255, 255, 255, .08);
            color: #f8fafc;
        }

        .card-toggle .bi {
            transition: transform .2s ease;
        }

        .card-toggle[aria-expanded="false"] .bi {
            transform: rotate(180deg);
        }

        .section-card.is-collapsed .card-body,
        .section-card.is-collapsed .card-footer {
            display: none !important;
        }

        .section-card.is-collapsed {
            opacity: .92;
        }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.css"/>
</head>

<body>
    <?php include __DIR__ . '/components/header.php'; ?>
    <div id="top"></div>

    <main class="container my-4 my-md-5">
        <?php if ($flashSuccess || $flashError): ?>
            <div class="mb-4">
                <?php if ($flashSuccess): ?>
                    <div class="alert alert-success mb-2"><?= htmlspecialchars($flashSuccess) ?></div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="alert alert-danger m-0"><?= htmlspecialchars($flashError) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <section class="dashboard-hero p-4 p-md-5 mb-4 mb-md-5 animate-fade-up">
            <div class="hero-pattern"></div>
            <div class="row align-items-center g-4 g-lg-5">
                <div class="col-lg-7 position-relative">
                    <span class="hero-label mb-2 d-block">Твоят профил · <?= htmlspecialchars($user['role']) ?></span>
                    <div class="d-flex align-items-center mb-3">
                        <h1 class="display-5 fw-bold m-0 me-3">Здравей, <?= htmlspecialchars($user['first_name']) ?>!</h1>
                        <div class="form-check form-switch d-flex align-items-center gap-2 m-0" title="Включи интерактивен помощник">
                            <input class="form-check-input" type="checkbox" role="switch" id="helpToggle" style="width: 3em; height: 1.5em; cursor: pointer;">
                            <label class="form-check-label fw-bold text-white-50" for="helpToggle" style="cursor: pointer;">ПОМОЩ</label>
                        </div>
                    </div>
                    <p class="lead mb-4 text-white-50"><?= htmlspecialchars($heroSubtitle) ?></p>
                    
                    <div class="hero-actions d-grid gap-3 d-sm-flex mb-4 mb-lg-0">
                        <?php if ($user['role'] === 'teacher'): ?>
                            <a class="btn btn-primary btn-lg hover-lift shadow-sm" href="createTest.php">
                                <i class="bi bi-magic me-2"></i>Нов тест
                            </a>
                            <a class="btn btn-outline-light btn-lg hover-lift" href="classes_create.php">
                                <i class="bi bi-people me-2"></i>Нов клас
                            </a>
                            <a class="btn btn-outline-light btn-lg hover-lift" href="assignments_create.php">
                                <i class="bi bi-megaphone me-2"></i>Задание
                            </a>
                            <a class="btn btn-outline-light btn-lg hover-lift" href="subjects_create.php">
                                <i class="bi bi-journal-text me-2"></i>Тема
                            </a>
                        <?php else: ?>
                            <a class="btn btn-primary btn-lg hover-lift shadow-sm" href="#student-assignments">
                                <i class="bi bi-clipboard-check me-2"></i>Активни задания
                            </a>
                            <a class="btn btn-outline-light btn-lg hover-lift" href="tests.php">
                                <i class="bi bi-play-fill me-2"></i>Стартирай тест
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($user['role'] === 'student'): ?>
                        <div class="card shadow-sm mt-4 join-code-entry glass-panel border-0" style="max-width: 500px;">
                            <div class="card-body p-3 p-md-4">
                                <form class="row g-2 align-items-center" method="get" action="join_class.php">
                                    <div class="col-sm-7 col-md-8">
                                        <label class="form-label text-muted small mb-1 fw-semibold">КОД ЗА КЛАС</label>
                                        <input type="text" name="code" class="form-control form-control-lg text-uppercase" maxlength="6" pattern="[A-Z0-9]{6}" placeholder="напр. ABC123" oninput="this.value = this.value.toUpperCase()" required>
                                    </div>
                                    <div class="col-sm-5 col-md-4 d-flex align-items-end">
                                        <button class="btn btn-primary btn-lg w-100 hover-lift" type="submit">
                                            <i class="bi bi-arrow-right-circle me-2"></i>Влез
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted opacity-75">Въведи 6-символния код, предоставен от твоя учител.</small>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-lg-5">
                    <div class="row row-cols-2 g-3 g-md-4">
                        <?php 
                        $delay = 100;
                        foreach ($heroStats as $stat): 
                            $icon = 'bi-bar-chart';
                            if (strpos($stat['label'], 'Класове') !== false) $icon = 'bi-people';
                            elseif (strpos($stat['label'], 'Тестове') !== false) $icon = 'bi-kanban';
                            elseif (strpos($stat['label'], 'оценяване') !== false) $icon = 'bi-check2-circle';
                            elseif (strpos($stat['label'], 'задания') !== false) $icon = 'bi-clipboard-check';
                            elseif (strpos($stat['label'], 'резултат') !== false) $icon = 'bi-trophy';
                        ?>
                            <div class="col animate-fade-up delay-<?= $delay ?>">
                                <div class="stat-card-modern">
                                    <div class="stat-icon shadow-sm"><i class="bi <?= $icon ?>"></i></div>
                                    <div class="h2 fw-bold mb-1"><?= htmlspecialchars((string) $stat['value']) ?></div>
                                    <small class="text-muted text-uppercase fw-bold stat-label"><?= htmlspecialchars($stat['label']) ?></small>
                                </div>
                            </div>
                        <?php 
                        $delay += 100;
                        endforeach; 
                        ?>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($user['role'] === 'teacher'): ?>
            <div class="card section-card filter-card mb-4" data-locked-open="true" data-card-key="filters">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="section-title m-0">
                        <i class="bi bi-funnel-fill"></i>
                        <div>
                            <div class="fw-semibold">Филтри и сортиране</div>
                            <small class="text-muted">Намери бързо нужните записи</small>
                        </div>
                    </div>
                    <a href="dashboard.php?reset=1" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Изчисти
                    </a>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <form method="get" class="rounded p-3 h-100">
                                <label class="form-label text-uppercase small fw-semibold mb-2 d-block">Класове</label>
                                <input type="text" name="c_q" value="<?= htmlspecialchars($_GET['c_q'] ?? '') ?>"
                                    class="form-control form-control-sm mb-2" placeholder="Търсене..." />
                                <select name="c_sort" class="form-select form-select-sm mb-2">
                                    <option value="">Сортиране</option>
                                    <option value="year_desc" <?= (($_GET['c_sort'] ?? '') === 'year_desc') ? 'selected' : '' ?>>По
                                        година</option>
                                    <option value="grade_asc" <?= (($_GET['c_sort'] ?? '') === 'grade_asc') ? 'selected' : '' ?>>По
                                        клас</option>
                                    <option value="name_asc" <?= (($_GET['c_sort'] ?? '') === 'name_asc') ? 'selected' : '' ?>>
                                        Име
                                        A→Я</option>
                                    <option value="name_desc" <?= (($_GET['c_sort'] ?? '') === 'name_desc') ? 'selected' : '' ?>>
                                        Име Я→A</option>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Приложи</button>
                            </form>
                        </div>
                        <div class="col-lg-4">
                            <form method="get" class="rounded p-3 h-100">
                                <label class="form-label text-uppercase small fw-semibold mb-2 d-block">Тестове</label>
                                <input type="text" name="t_q" value="<?= htmlspecialchars($_GET['t_q'] ?? '') ?>"
                                    class="form-control form-control-sm mb-2" placeholder="Търсене..." />
                                <?php
                                // Load teacher subjects for dropdown
                                $filter_subjects = [];
                                try {
                                    $q = $pdo->prepare('SELECT id, name FROM subjects WHERE owner_teacher_id = :tid ORDER BY name');
                                    $q->execute([':tid' => (int) $user['id']]);
                                    $filter_subjects = $q->fetchAll();
                                } catch (Throwable $e) {
                                    $filter_subjects = [];
                                }
                                ?>
                                <select name="t_subject" class="form-select form-select-sm mb-2">
                                    <option value="">Предмет</option>
                                    <?php foreach ($filter_subjects as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" <?= (($_GET['t_subject'] ?? '') !== '') && ((int) $_GET['t_subject'] === (int) $s['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <select name="t_visibility" class="form-select form-select-sm">
                                        <option value="">Видимост</option>
                                        <option value="private" <?= (($_GET['t_visibility'] ?? '') === 'private') ? 'selected' : '' ?>>Частен</option>
                                        <option value="shared" <?= (($_GET['t_visibility'] ?? '') === 'shared') ? 'selected' : '' ?>>Споделен</option>
                                    </select>
                                    <select name="t_status" class="form-select form-select-sm">
                                        <option value="">Статус</option>
                                        <option value="draft" <?= (($_GET['t_status'] ?? '') === 'draft') ? 'selected' : '' ?>>
                                            Чернова</option>
                                        <option value="published" <?= (($_GET['t_status'] ?? '') === 'published') ? 'selected' : '' ?>>Публикуван</option>
                                        <option value="archived" <?= (($_GET['t_status'] ?? '') === 'archived') ? 'selected' : '' ?>>Архивиран</option>
                                    </select>
                                </div>
                                <select name="t_sort" class="form-select form-select-sm mb-2">
                                    <option value="">Сортиране</option>
                                    <option value="updated_desc" <?= (($_GET['t_sort'] ?? '') === 'updated_desc') ? 'selected' : '' ?>>Обновени ↓</option>
                                    <option value="updated_asc" <?= (($_GET['t_sort'] ?? '') === 'updated_asc') ? 'selected' : '' ?>>Обновени ↑</option>
                                    <option value="title_asc" <?= (($_GET['t_sort'] ?? '') === 'title_asc') ? 'selected' : '' ?>>
                                        Заглавие A→Я</option>
                                    <option value="title_desc" <?= (($_GET['t_sort'] ?? '') === 'title_desc') ? 'selected' : '' ?>>
                                        Заглавие Я→A</option>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Приложи</button>
                            </form>
                        </div>
                        <div class="col-lg-4">
                            <form method="get" class="rounded p-3 h-100">
                                <label class="form-label text-uppercase small fw-semibold mb-2 d-block">Задания</label>
                                <input type="text" name="a_q" value="<?= htmlspecialchars($_GET['a_q'] ?? '') ?>"
                                    class="form-control form-control-sm mb-2" placeholder="Търсене (зад./име)..." />
                                <div class="d-flex flex-column gap-2 mb-2">
                                    <input type="datetime-local" name="a_from"
                                        value="<?= htmlspecialchars($_GET['a_from'] ?? '') ?>"
                                        class="form-control form-control-sm" />
                                    <input type="datetime-local" name="a_to"
                                        value="<?= htmlspecialchars($_GET['a_to'] ?? '') ?>"
                                        class="form-control form-control-sm" />
                                </div>
                                <select name="a_sort" class="form-select form-select-sm mb-2">
                                    <option value="">Сортиране</option>
                                    <option value="date_desc" <?= (($_GET['a_sort'] ?? '') === 'date_desc') ? 'selected' : '' ?>>
                                        Дата ↓</option>
                                    <option value="date_asc" <?= (($_GET['a_sort'] ?? '') === 'date_asc') ? 'selected' : '' ?>>
                                        Дата
                                        ↑</option>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Приложи</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$pdo): ?>
            <div class="alert alert-warning">Липсва връзка към базата. Проверете config.php и импортнете db/schema.sql.
            </div>
        <?php endif; ?>

        <?php if ($user['role'] === 'teacher'): ?>
            <!-- Teacher Dashboard -->
            <div class="dashboard-columns teacher-panel">
                <div class="row g-3 g-md-4">
                    <div class="col-lg-6">
                        <div class="card section-card h-100" data-card-key="teacher-classes">
                            <div class="card-header">
                                <div class="section-title"><i class="bi bi-people-fill"></i><strong>Твоите класове</strong>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($teacher['class_options'])): ?>
                                    <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                        <label class="text-muted small mb-0" for="filter-classes-select">Филтър по клас</label>
                                        <select id="filter-classes-select" name="c_class_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                            <option value=""><?= htmlspecialchars('Всички класове') ?></option>
                                            <?php foreach ($teacher['class_options'] as $option): ?>
                                                <option value="<?= (int) $option['id'] ?>" <?= ((int) ($_GET['c_class_id'] ?? 0) === (int) $option['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(format_class_label($option)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php render_preserved_filters(['c_class_id']); ?>
                                    </form>
                                <?php endif; ?>
                                <?php if (empty($teacher['classes'])): ?>
                                    <div class="text-muted">Нямате създадени класове.</div>
                                <?php else: ?>
                                    <div class="list-group list-elevated">
                                        <?php foreach ($teacher['classes'] as $c): ?>
                                            <div
                                                class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                                                <div>
                                                    <a class="text-decoration-none"
                                                        href="classes_create.php?id=<?= (int) $c['id'] ?>&created_at=<?= urlencode($c['created_at']) ?>">
                                                        <?= htmlspecialchars($c['grade'] . $c['section']) ?> •
                                                        <?= htmlspecialchars($c['school_year']) ?>
                                                        <span
                                                            class="text-muted small ms-2"><?= htmlspecialchars($c['name']) ?></span>
                                                    </a>
                                                </div>
                                                <div class="list-actions">
                                                    <a class="action-icon-btn action-icon-btn--muted"
                                                        href="classes_create.php?id=<?= (int) $c['id'] ?>&created_at=<?= urlencode($c['created_at']) ?>"
                                                        title="Редактирай клас">
                                                        <i class="bi bi-pencil"></i>
                                                        <span class="visually-hidden">Редактирай</span>
                                                    </a>
                                                    <a class="action-icon-btn action-icon-btn--primary"
                                                        href="classes_create.php?id=<?= (int) $c['id'] ?>&created_at=<?= urlencode($c['created_at']) ?>#students"
                                                        title="Добави ученици">
                                                        <i class="bi bi-person-plus"></i>
                                                        <span class="visually-hidden">Добави ученици</span>
                                                    </a>
                                                    <form method="post" action="teacher_actions.php" class="m-0"
                                                        onsubmit="return confirm('Наистина ли да изтрием този клас и свързаните ученици/назначения?');">
                                                        <input type="hidden" name="action" value="delete_class">
                                                        <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                                                        <input type="hidden" name="redirect" value="<?= $currentUrlSafe ?>">
                                                        <button class="action-icon-btn action-icon-btn--danger" type="submit"
                                                            title="Изтрий клас">
                                                            <i class="bi bi-trash"></i>
                                                            <span class="visually-hidden">Изтрий</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card section-card h-100" data-card-key="teacher-tests">
                            <div class="card-header">
                                <div class="section-title"><i class="bi bi-kanban"></i><strong>Тестове</strong></div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($teacher['tests'])): ?>
                                    <div class="text-muted">Все още нямате тестове.</div>
                                <?php else: ?>
                                    <div class="list-group list-elevated">
                                        <?php foreach ($teacher['tests'] as $t): ?>
                                            <div
                                                class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                                                <div class="me-2">
                                                    <div class="fw-semibold">
                                                        <a class="text-decoration-none"
                                                            href="test_edit.php?id=<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                                                        <span
                                                            class="badge bg-light text-dark ms-2"><?= htmlspecialchars($t['visibility']) ?></span>
                                                        <span
                                                            class="badge bg-secondary ms-1"><?= htmlspecialchars($t['status']) ?></span>
                                                    </div>
                                                    <small class="text-muted">Обновен:
                                                        <?= htmlspecialchars($t['updated_at']) ?></small>
                                                </div>
                                                <div class="list-actions">
                                                    <a class="action-icon-btn action-icon-btn--primary"
                                                        href="test_edit.php?id=<?= (int) $t['id'] ?>" title="Редактирай тест">
                                                        <i class="bi bi-pencil"></i>
                                                        <span class="visually-hidden">Редактирай</span>
                                                    </a>
                                                    <form method="post" action="teacher_actions.php" class="m-0"
                                                        data-confirm="Изтриване на тест „<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>“? Свързаните задания и опити също ще бъдат премахнати."
                                                        onsubmit="return confirm(this.dataset.confirm);">
                                                        <input type="hidden" name="action" value="delete_test">
                                                        <input type="hidden" name="test_id" value="<?= (int) $t['id'] ?>">
                                                        <input type="hidden" name="redirect" value="<?= $currentUrlSafe ?>">
                                                        <button class="action-icon-btn action-icon-btn--danger" type="submit"
                                                            title="Изтрий тест">
                                                            <i class="bi bi-trash"></i>
                                                            <span class="visually-hidden">Изтрий</span>
                                                        </button>
                                                    </form>
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
                        <div class="card section-card h-100" data-card-key="teacher-recent-attempts">
                            <div class="card-header">
                                <div class="section-title"><i class="bi bi-activity"></i><strong>Последни опити</strong>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($teacher['recent_attempts_class_options'])): ?>
                                    <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                        <label class="text-muted small mb-0" for="filter-recent-attempts-select">Филтър по клас</label>
                                        <select id="filter-recent-attempts-select" name="ra_class_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                            <option value=""><?= htmlspecialchars('Всички класове') ?></option>
                                            <?php foreach ($teacher['recent_attempts_class_options'] as $option): ?>
                                                <option value="<?= (int) $option['id'] ?>" <?= ((int) ($_GET['ra_class_id'] ?? 0) === (int) $option['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(format_class_label($option)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="a_page" value="1">
                                        <?php render_preserved_filters(['ra_class_id', 'a_page']); ?>
                                    </form>
                                <?php endif; ?>
                                <?php if (empty($teacher['recent_attempts'])): ?>
                                    <div class="text-muted">Още няма предадени опити.</div>
                                <?php else: ?>
                                    <div class="list-group list-elevated">
                                        <?php foreach ($teacher['recent_attempts'] as $ra):
                                            $p = percent($ra['score_obtained'], $ra['max_score']);
                                            $autoGrade = grade_from_percent($p);
                                            $displayGrade = $ra['teacher_grade'] !== null ? (int) $ra['teacher_grade'] : $autoGrade; ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div class="me-3">
                                                    <div class="fw-semibold">
                                                        <?= htmlspecialchars($ra['first_name'] . ' ' . $ra['last_name']) ?>
                                                        <span class="text-muted">•</span>
                                                        <span
                                                            class="text-muted"><?= htmlspecialchars($ra['assignment_title']) ?></span>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars($ra['submitted_at'] ?: $ra['started_at']) ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span
                                                        class="badge <?= ($p !== null && $p >= 50) ? 'bg-success' : 'bg-danger' ?>"><?= $p !== null ? $p . '%' : '—' ?></span>
                                                    <span class="badge bg-primary">Авто:
                                                        <?= $autoGrade !== null ? $autoGrade : '—' ?></span>
                                                    <form method="post" class="d-flex align-items-center gap-1">
                                                        <input type="hidden" name="__action" value="set_grade" />
                                                        <input type="hidden" name="attempt_id" value="<?= (int) $ra['id'] ?>" />
                                                        <select name="teacher_grade" class="form-select form-select-sm"
                                                            style="width:auto">
                                                            <option value="">—</option>
                                                            <?php for ($g = 2; $g <= 6; $g++): ?>
                                                                <option value="<?= $g ?>" <?= ($displayGrade === $g && $ra['teacher_grade'] !== null) ? 'selected' : '' ?>><?= $g ?></option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <button class="btn btn-sm btn-outline-primary" type="submit"><i
                                                                class="bi bi-save"></i></button>
                                                    </form>
                                                    <a class="btn btn-sm btn-outline-secondary"
                                                        href="attempt_review.php?id=<?= (int) $ra['id'] ?>"><i
                                                            class="bi bi-eye"></i>
                                                        Преглед</a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php
                                    $attemptMeta = $teacher['recent_attempts_meta'] ?? ['page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => max(1, count($teacher['recent_attempts']))];
                                    $attemptPage = max(1, (int) ($attemptMeta['page'] ?? 1));
                                    $attemptPages = max(1, (int) ($attemptMeta['pages'] ?? 1));
                                    $attemptTotal = max(0, (int) ($attemptMeta['total'] ?? 0));
                                    $attemptPerPage = max(1, (int) ($attemptMeta['per_page'] ?? 5));
                                    $attemptCountOnPage = count($teacher['recent_attempts']);
                                    $attemptFrom = $attemptCountOnPage ? (($attemptPage - 1) * $attemptPerPage + 1) : 0;
                                    $attemptTo = $attemptCountOnPage ? ($attemptFrom + $attemptCountOnPage - 1) : 0;
                                    $paginationWindow = 5;
                                    $halfWindow = (int) floor($paginationWindow / 2);
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
                                        }
                                        $qs = http_build_query($params);
                                        return 'dashboard.php' . ($qs ? '?' . $qs : '');
                                    };
                                    ?>
                                    <div
                                        class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center mt-3 gap-2">
                                        <?php if ($attemptTotal > 0): ?>
                                            <small class="text-muted">Показани <?= $attemptFrom ?>-<?= $attemptTo ?> от
                                                <?= $attemptTotal ?></small>
                                        <?php else: ?>
                                            <span></span>
                                        <?php endif; ?>
                                        <?php if ($attemptPages > 1): ?>
                                            <nav aria-label="Странициране на последните опити">
                                                <ul class="pagination pagination-sm mb-0">
                                                    <li class="page-item <?= $attemptPage <= 1 ? 'disabled' : '' ?>">
                                                        <?php if ($attemptPage <= 1): ?>
                                                            <span class="page-link">Предишна</span>
                                                        <?php else: ?>
                                                            <a class="page-link"
                                                                href="<?= htmlspecialchars($buildAttemptPageUrl($attemptPage - 1)) ?>">Предишна</a>
                                                        <?php endif; ?>
                                                    </li>
                                                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                                        <li class="page-item <?= $p === $attemptPage ? 'active' : '' ?>">
                                                            <?php if ($p === $attemptPage): ?>
                                                                <span class="page-link"><?= $p ?></span>
                                                            <?php else: ?>
                                                                <a class="page-link"
                                                                    href="<?= htmlspecialchars($buildAttemptPageUrl($p)) ?>"><?= $p ?></a>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endfor; ?>
                                                    <li class="page-item <?= $attemptPage >= $attemptPages ? 'disabled' : '' ?>">
                                                        <?php if ($attemptPage >= $attemptPages): ?>
                                                            <span class="page-link">Следваща</span>
                                                        <?php else: ?>
                                                            <a class="page-link"
                                                                href="<?= htmlspecialchars($buildAttemptPageUrl($attemptPage + 1)) ?>">Следваща</a>
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
                        <div class="card section-card h-100" data-card-key="teacher-class-analytics">
                            <div class="card-header">
                                <div class="section-title"><i class="bi bi-graph-up-arrow"></i><strong>Аналитика по
                                        клас</strong></div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($teacher['class_stats_options'])): ?>
                                    <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                        <label class="text-muted small mb-0" for="filter-class-stats-select">Филтър по клас</label>
                                        <select id="filter-class-stats-select" name="ca_class_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                            <option value=""><?= htmlspecialchars('Всички класове') ?></option>
                                            <?php foreach ($teacher['class_stats_options'] as $option): ?>
                                                <option value="<?= (int) $option['id'] ?>" <?= ((int) ($_GET['ca_class_id'] ?? 0) === (int) $option['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(format_class_label($option)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php render_preserved_filters(['ca_class_id']); ?>
                                    </form>
                                <?php endif; ?>
                                <?php if (empty($teacher['class_stats'])): ?>
                                    <div class="text-muted">Няма налични данни за статистика.</div>
                                <?php else: ?>
                                    <div class="list-group list-elevated">
                                        <?php foreach ($teacher['class_stats'] as $cs): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="fw-semibold">Клас
                                                        <?= htmlspecialchars($cs['grade'] . $cs['section']) ?> •
                                                        <?= htmlspecialchars($cs['school_year']) ?>
                                                    </div>
                                                    <div class="text-muted small">Задание #<?= (int) $cs['assignment_id'] ?></div>
                                                </div>
                                                <span class="badge bg-primary"><?= (float) $cs['avg_percent'] ?>%</span>
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
                        <div class="card section-card h-100" data-card-key="teacher-assignments-current">
                            <div class="card-header">
                                <div class="section-title"><i class="bi bi-clipboard-check"></i><strong>Текущи
                                        задания</strong></div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($teacher['assignments_current'])): ?>
                                    <div class="text-muted">Няма текущи или предстоящи задания.</div>
                                <?php else: ?>
                                    <div class="list-group list-elevated">
                                        <?php foreach ($teacher['assignments_current'] as $assignment): ?>
                                            <?php
                                            $submittedCount = (int) ($assignment['submitted_count'] ?? 0);
                                            $gradedCount = (int) ($assignment['graded_count'] ?? 0);
                                            $needsGrade = (int) ($assignment['needs_grade'] ?? 0);
                                            $primaryClassId = isset($assignment['primary_class_id']) ? (int) $assignment['primary_class_id'] : 0;
                                            $overviewLink = 'assignment_overview.php?id=' . (int) $assignment['id'];
                                            if ($primaryClassId > 0) {
                                                $overviewLink .= '&class_id=' . $primaryClassId;
                                            }
                                            $status = $assignment['status'] ?? 'current';
                                            $badgeClass = 'bg-success';
                                            $badgeLabel = 'Активно';
                                            if ($status === 'upcoming') {
                                                $badgeClass = "bg-warning text-dark";
                                                $badgeLabel = 'Предстоящо';
                                            }
                                            $classTargets = $assignment['classes'] ?? [];
                                            ?>
                                            <div class="list-group-item">
                                                <div
                                                    class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                                                    <div class="me-3 flex-grow-1">
                                                        <div class="fw-semibold">
                                                            <a class="text-decoration-none"
                                                                href="<?= htmlspecialchars($overviewLink) ?>"><?= htmlspecialchars($assignment['title']) ?></a>
                                                            <?php if (!empty($assignment['is_strict_mode'])): ?><span
                                                                    class="badge bg-danger ms-2">Строг режим</span><?php endif; ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?php if (!empty($assignment['open_at'])): ?>От:
                                                                <?= htmlspecialchars($assignment['open_at']) ?>            <?php endif; ?>
                                                            <?php if (!empty($assignment['due_at'])): ?><span class="ms-2">До:
                                                                    <?= htmlspecialchars($assignment['due_at']) ?></span>
                                                            <?php elseif (!empty($assignment['close_at'])): ?><span
                                                                    class="ms-2">Затваря се:
                                                                    <?= htmlspecialchars($assignment['close_at']) ?></span><?php endif; ?>
                                                        </div>
                                                        <div class="text-muted small">Подадени: <?= $submittedCount ?> / Оценени:
                                                            <?= $gradedCount ?></div>
                                                        <?php if ($needsGrade > 0): ?><span
                                                                class="badge bg-warning text-dark mt-2 d-inline-block">За оценяване:
                                                                <?= $needsGrade ?></span><?php endif; ?>
                                                        <?php if (!empty($classTargets)): ?>
                                                            <div class="d-flex flex-wrap gap-2 mt-3">
                                                                <?php foreach ($classTargets as $target): ?>
                                                                    <?php
                                                                    $labelParts = [];
                                                                    $labelParts[] = trim(($target['grade'] ?? '') . ($target['section'] ?? ''));
                                                                    if (!empty($target['school_year'])) {
                                                                        $labelParts[] = $target['school_year'];
                                                                    }
                                                                    if (!empty($target['name'])) {
                                                                        $labelParts[] = $target['name'];
                                                                    }
                                                                    $chipLabel = implode(' • ', array_filter($labelParts));
                                                                    ?>
                                                                    <form method="post" action="teacher_actions.php" class="assignment-chip"
                                                                        data-confirm="Да премахнем ли заданието от клас „<?= htmlspecialchars($chipLabel, ENT_QUOTES) ?>“?"
                                                                        onsubmit="return confirm(this.dataset.confirm);">
                                                                        <input type="hidden" name="action" value="assignment_remove_class">
                                                                        <input type="hidden" name="assignment_id"
                                                                            value="<?= (int) $assignment['id'] ?>">
                                                                        <input type="hidden" name="class_id"
                                                                            value="<?= (int) $target['id'] ?>">
                                                                        <input type="hidden" name="redirect" value="<?= $currentUrlSafe ?>">
                                                                        <span><?= htmlspecialchars($chipLabel) ?></span>
                                                                        <button type="submit" aria-label="Премахни"><i
                                                                                class="bi bi-x"></i></button>
                                                                    </form>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="list-actions justify-content-end">
                                                        <span
                                                            class="badge <?= $badgeClass ?> align-self-center"><?= $badgeLabel ?></span>
                                                        <a class="action-icon-btn action-icon-btn--primary"
                                                            href="assignments_create.php?id=<?= (int) $assignment['id'] ?>"
                                                            title="Редактирай заданието">
                                                            <i class="bi bi-pencil"></i>
                                                            <span class="visually-hidden">Редактирай</span>
                                                        </a>
                                                        <form method="post" action="teacher_actions.php" class="m-0"
                                                            data-confirm="Изтриване на заданието „<?= htmlspecialchars($assignment['title'], ENT_QUOTES) ?>“? Всички опити ще бъдат премахнати."
                                                            onsubmit="return confirm(this.dataset.confirm);">
                                                            <input type="hidden" name="action" value="delete_assignment">
                                                            <input type="hidden" name="assignment_id"
                                                                value="<?= (int) $assignment['id'] ?>">
                                                            <input type="hidden" name="redirect" value="<?= $currentUrlSafe ?>">
                                                            <button class="action-icon-btn action-icon-btn--danger" type="submit"
                                                                title="Изтрий заданието">
                                                                <i class="bi bi-trash"></i>
                                                                <span class="visually-hidden">Изтрий</span>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card section-card h-100" data-card-key="teacher-assignments-past">
                                <div class="card-header">
                                    <div class="section-title"><i class="bi bi-archive"></i><strong>Минали задания</strong></div>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($teacher['assignments_past_class_options'])): ?>
                                        <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                            <label class="text-muted small mb-0" for="filter-assignments-past-select">Филтър по клас</label>
                                            <select id="filter-assignments-past-select" name="ap_class_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                                <option value=""><?= htmlspecialchars('Всички класове') ?></option>
                                                <?php foreach ($teacher['assignments_past_class_options'] as $option): ?>
                                                    <option value="<?= (int) $option['id'] ?>" <?= ((int) ($_GET['ap_class_id'] ?? 0) === (int) $option['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars(format_class_label($option)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="ap_page" value="1">
                                            <?php render_preserved_filters(['ap_class_id', 'ap_page']); ?>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (empty($teacher['assignments_past'])): ?>
                                        <div class="text-muted">Няма приключили задания.</div>
                                    <?php else: ?>
                                        <div class="list-group list-elevated">
                                            <?php foreach ($teacher['assignments_past'] as $assignment): ?>
                                                <?php
                                                $submittedCount = (int) ($assignment['submitted_count'] ?? 0);
                                                $gradedCount = (int) ($assignment['graded_count'] ?? 0);
                                                $needsGrade = (int) ($assignment['needs_grade'] ?? 0);
                                                $primaryClassId = isset($assignment['primary_class_id']) ? (int) $assignment['primary_class_id'] : 0;
                                                $overviewLink = 'assignment_overview.php?id=' . (int) $assignment['id'];
                                                if ($primaryClassId > 0) {
                                                    $overviewLink .= '&class_id=' . $primaryClassId;
                                                }
                                                $classTargets = $assignment['classes'] ?? [];
                                                ?>
                                                <div class="list-group-item">
                                                    <div
                                                        class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                                                        <div class="me-3 flex-grow-1">
                                                            <div class="fw-semibold">
                                                                <a class="text-decoration-none"
                                                                    href="<?= htmlspecialchars($overviewLink) ?>"><?= htmlspecialchars($assignment['title']) ?></a>
                                                                <?php if (!empty($assignment['is_strict_mode'])): ?><span
                                                                        class="badge bg-danger ms-2">Строг режим</span><?php endif; ?>
                                                            </div>
                                                            <div class="text-muted small">
                                                                <?php if (!empty($assignment['due_at'])): ?>Завършено на:
                                                                    <?= htmlspecialchars($assignment['due_at']) ?>            <?php endif; ?></div>
                                                            <?php if (!empty($classTargets)): ?>
                                                                <div class="d-flex flex-wrap gap-2 mt-3">
                                                                    <?php foreach ($classTargets as $target): ?>
                                                                        <?php
                                                                        $labelParts = [];
                                                                        $labelParts[] = trim(($target['grade'] ?? '') . ($target['section'] ?? ''));
                                                                        if (!empty($target['school_year'])) {
                                                                            $labelParts[] = $target['school_year'];
                                                                        }
                                                                        if (!empty($target['name'])) {
                                                                            $labelParts[] = $target['name'];
                                                                        }
                                                                        $chipLabel = implode(' • ', array_filter($labelParts));
                                                                        ?>
                                                                        <form method="post" action="teacher_actions.php"
                                                                            class="assignment-chip"
                                                                            data-confirm="Да премахнем ли заданието от клас „<?= htmlspecialchars($chipLabel, ENT_QUOTES) ?>“?"
                                                                            onsubmit="return confirm(this.dataset.confirm);">
                                                                            <input type="hidden" name="action"
                                                                                value="assignment_remove_class">
                                                                            <input type="hidden" name="assignment_id"
                                                                                value="<?= (int) $assignment['id'] ?>">
                                                                            <input type="hidden" name="class_id"
                                                                                value="<?= (int) $target['id'] ?>">
                                                                            <input type="hidden" name="redirect"
                                                                                value="<?= $currentUrlSafe ?>">
                                                                            <span><?= htmlspecialchars($chipLabel) ?></span>
                                                                            <button type="submit" aria-label="Премахни"><i
                                                                                    class="bi bi-x"></i></button>
                                                                        </form>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="list-actions justify-content-end">
                                                            <div class="text-muted small align-self-center">Подадени:
                                                                <?= $submittedCount ?> / Оценени: <?= $gradedCount ?></div>
                                                            <?php if ($needsGrade > 0): ?><span
                                                                    class="badge bg-warning text-dark align-self-center">За проверка:
                                                                    <?= $needsGrade ?></span><?php endif; ?>
                                                            <a class="action-icon-btn action-icon-btn--muted"
                                                                href="assignments_create.php?id=<?= (int) $assignment['id'] ?>"
                                                                title="Отвори заданието">
                                                                <i class="bi bi-pencil"></i>
                                                                <span class="visually-hidden">Редактирай</span>
                                                            </a>
                                                            <form method="post" action="teacher_actions.php" class="m-0"
                                                                data-confirm="Изтриване на заданието „<?= htmlspecialchars($assignment['title'], ENT_QUOTES) ?>“?"
                                                                onsubmit="return confirm(this.dataset.confirm);">
                                                                <input type="hidden" name="action" value="delete_assignment">
                                                                <input type="hidden" name="assignment_id"
                                                                    value="<?= (int) $assignment['id'] ?>">
                                                                <input type="hidden" name="redirect" value="<?= $currentUrlSafe ?>">
                                                                <button class="action-icon-btn action-icon-btn--danger"
                                                                    type="submit" title="Изтрий заданието">
                                                                    <i class="bi bi-trash"></i>
                                                                    <span class="visually-hidden">Изтрий</span>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                            </div>
                    <?php endforeach; ?>
                                    </div>
                                        <?php
                                        $pastMeta = $teacher['assignments_past_meta'] ?? ['page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => max(1, count($teacher['assignments_past']))];
                                        $pastPage = max(1, (int) ($pastMeta['page'] ?? 1));
                                        $pastPages = max(1, (int) ($pastMeta['pages'] ?? 1));
                                        $pastTotal = max(0, (int) ($pastMeta['total'] ?? 0));
                                        $pastPerPage = max(1, (int) ($pastMeta['per_page'] ?? 5));
                                        $pastCountOnPage = count($teacher['assignments_past']);
                                        $pastFrom = $pastCountOnPage ? (($pastPage - 1) * $pastPerPage + 1) : 0;
                                        $pastTo = $pastCountOnPage ? ($pastFrom + $pastCountOnPage - 1) : 0;
                                        $pastPaginationWindow = 5;
                                        $pastHalfWindow = (int) floor($pastPaginationWindow / 2);
                                        $pastStartPage = max(1, $pastPage - $pastHalfWindow);
                                        $pastEndPage = min($pastPages, $pastStartPage + $pastPaginationWindow - 1);
                                        $pastStartPage = max(1, $pastEndPage - $pastPaginationWindow + 1);
                                        $queryWithoutPastPage = $_GET;
                                        unset($queryWithoutPastPage['ap_page']);
                                        $buildPastPageUrl = function (int $page) use ($queryWithoutPastPage) {
                                            $params = $queryWithoutPastPage;
                                            if ($page > 1) {
                                                $params['ap_page'] = $page;
                                            } else {
                                                unset($params['ap_page']);
                                            }
                                            $qs = http_build_query($params);
                                            return 'dashboard.php' . ($qs ? '?' . $qs : '');
                                        };
                                        ?>
                                        <div
                                            class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center mt-3 gap-2">
                                            <?php if ($pastTotal > 0): ?>
                                                <small class="text-muted">От <?= $pastFrom ?>-<?= $pastTo ?> До
                                                    <?= $pastTotal ?></small>
                                            <?php else: ?>
                                                <span></span>
                                            <?php endif; ?>
                                            <?php if ($pastPages > 1): ?>
                                                <nav aria-label="Странициране на миналите задания">
                                                    <ul class="pagination pagination-sm mb-0">
                                                    <li class="page-item <?= $pastPage <= 1 ? 'disabled' : '' ?>">
                                                        <?php if ($pastPage <= 1): ?>
                                                            <span class="page-link">Предишна</span>
                                                        <?php else: ?>
                                                            <a class="page-link"
                                                                href="<?= htmlspecialchars($buildPastPageUrl($pastPage - 1)) ?>">Предишна</a>
                                                        <?php endif; ?>
                                                        </li>
                                                        <?php for ($p = $pastStartPage; $p <= $pastEndPage; $p++): ?>
                                                            <li class="page-item <?= $p === $pastPage ? 'active' : '' ?>">
                                                                <?php if ($p === $pastPage): ?>
                                                                    <span class="page-link"><?= $p ?></span>
                                                                <?php else: ?>
                                                                    <a class="page-link"
                                                                        href="<?= htmlspecialchars($buildPastPageUrl($p)) ?>"><?= $p ?></a>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endfor; ?>
                                                        <li class="page-item <?= $pastPage >= $pastPages ? 'disabled' : '' ?>">
                                                            <?php if ($pastPage >= $pastPages): ?>
                                                                <span class="page-link">Следваща</span>
                                                            <?php else: ?>
                                                                <a class="page-link"
                                                                    href="<?= htmlspecialchars($buildPastPageUrl($pastPage + 1)) ?>">Следваща</a>
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
                    </div>
                <?php else: ?>
                    <!-- Student Dashboard -->
                    <div class="dashboard-columns student-panel">
                        <div class="row g-3 g-md-4">
                            <div class="col-12">
                                <div class="card section-card h-100" id="student-assignments" data-card-key="student-open-assignments">
                                    <div class="card-header">
                                        <div class="section-title"><i class="bi bi-clipboard-check"></i><strong>Активни задания</strong></div>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($student['open_assignments'])): ?>
                                            <div class="text-muted">Няма активни задания в момента.</div>
                                        <?php else: ?>
                                            <div class="list-group list-elevated">
                                                <?php foreach ($student['open_assignments'] as $assignment):
                                                    $assignmentId = (int) $assignment['id'];
                                                    $lastAttemptId = $student['open_attempts_map'][$assignmentId] ?? null;
                                                    $windowLabel = null;
                                                    if (!empty($assignment['due_at'])) {
                                                        $windowLabel = 'До: ' . $assignment['due_at'];
                                                    } elseif (!empty($assignment['close_at'])) {
                                                        $windowLabel = 'Затваря се: ' . $assignment['close_at'];
                                                    }
                                                    ?>
                                                    <div class="list-group-item position-relative d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                                        <div class="flex-grow-1">
                                                            <div class="fw-semibold">
                                                                <a class="text-decoration-none" href="assignment.php?id=<?= $assignmentId ?>">
                                                                    <?= htmlspecialchars($assignment['title']) ?>
                                                                </a>
                                                            </div>
                                                            <div class="text-muted small">
                                                                <?= htmlspecialchars($assignment['test_title']) ?>
                                                                <?php if ($windowLabel): ?>
                                                                    <span class="ms-2"><?= htmlspecialchars($windowLabel) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($assignment['description'])): ?>
                                                                <div class="text-muted small mt-1"><?= nl2br(htmlspecialchars(mb_strimwidth($assignment['description'], 0, 160, '…'))) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                                            <a class="btn btn-sm btn-primary" href="assignment.php?id=<?= $assignmentId ?>">
                                                                <i class="bi bi-play-fill"></i> Започни
                                                            </a>
                                                            <?php if ($lastAttemptId): ?>
                                                                <a class="btn btn-sm btn-outline-secondary" href="student_attempt.php?id=<?= (int) $lastAttemptId ?>">
                                                                    <i class="bi bi-clock-history"></i> Последен опит
                                                                </a>
                                                            <?php endif; ?>
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
                                <div class="card section-card h-100" data-card-key="student-recent-attempts">
                                    <div class="card-header">
                                        <div class="section-title"><i class="bi bi-clock-history"></i><strong>Последни
                                                опити</strong></div>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($student['recent_attempts'])): ?>
                                            <div class="text-muted">Все още нямате предадени опити.</div>
                                        <?php else: ?>
                                            <div class="list-group list-elevated">
                                                <?php foreach ($student['recent_attempts'] as $ra):
                                                    $p = percent($ra['score_obtained'], $ra['max_score']);
                                                    $autoGrade = grade_from_percent($p);
                                                    $displayGrade = $ra['teacher_grade'] !== null ? (int) $ra['teacher_grade'] : $autoGrade; ?>
                                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <div class="fw-semibold">
                                                                <?= htmlspecialchars($ra['assignment_title']) ?></div>
                                                            <div class="text-muted small">
                                                                <?= htmlspecialchars($ra['submitted_at'] ?: $ra['started_at']) ?>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span
                                                                class="badge <?= ($p !== null && $p >= 50) ? 'bg-success' : 'bg-danger' ?>"><?= $p !== null ? $p . '%' : '—' ?></span>
                                                            <span
                                                                class="badge bg-primary"><?= $displayGrade !== null ? $displayGrade : '—' ?></span>
                                                            <a class="btn btn-sm btn-outline-secondary"
                                                                href="student_attempt.php?id=<?= (int) $ra['id'] ?>"><i
                                                                    class="bi bi-eye"></i> Преглед</a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card section-card h-100" data-card-key="student-overview">
                                    <div class="card-header">
                                        <div class="section-title"><i class="bi bi-speedometer2"></i><strong>Общ
                                                напредък</strong></div>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!$student['overview']): ?>
                                            <div class="text-muted">Няма достатъчно данни.</div>
                                        <?php else: ?>
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <div class="h4 m-0"><?= (int) $student['overview']['assignments_taken'] ?>
                                                    </div>
                                                    <div class="text-muted small">Задания</div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="h4 m-0"><?= (float) $student['overview']['avg_percent'] ?>%
                                                    </div>
                                                    <div class="text-muted small">Среден %</div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="h6 m-0">
                                                        <?= htmlspecialchars($student['overview']['last_activity']) ?>
                                                    </div>
                                                    <div class="text-muted small">Активност</div>
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
            <div class="text-muted">&copy; <?= date('Y'); ?> TestGramatikov</div>
            <div class="d-flex gap-3 small">
                <a class="text-decoration-none" href="terms.php">Условия</a>
                <a class="text-decoration-none" href="privacy.php">Поверителност</a>
                <a class="text-decoration-none" href="contact.php">Контакт</a>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            (() => {
                const cards = document.querySelectorAll('.section-card');
                cards.forEach((card, index) => {
                    const header = card.querySelector('.card-header');
                    const bodies = card.querySelectorAll('.card-body, .card-footer');
                    if (!header || bodies.length === 0) {
                        return;
                    }

                    let toggle = header.querySelector('.card-toggle');
                    const lockedOpen = card.dataset.lockedOpen === 'true';
                    const defaultOpen = card.dataset.defaultOpen === 'true';

                    if (!toggle && !lockedOpen) {
                        toggle = document.createElement('button');
                        toggle.type = 'button';
                        toggle.className = 'card-toggle';
                        toggle.setAttribute('aria-label', 'Свий или разгъни секцията');
                        toggle.innerHTML = '<i class="bi bi-chevron-up"></i>';
                        header.appendChild(toggle);
                    }

                    const keyBase = card.id || card.getAttribute('data-card-key') || `card-${index}`;
                    const storageKey = `tg-dashboard-card-${keyBase}`;

                    const setCollapsed = (collapsed) => {
                        card.classList.toggle('is-collapsed', collapsed);
                        bodies.forEach(el => {
                            el.hidden = collapsed;
                        });
                        if (toggle) {
                            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                        }
                    };

                    let initialCollapsed = lockedOpen ? false : !defaultOpen;
                    if (!lockedOpen) {
                        try {
                            const stored = localStorage.getItem(storageKey);
                            if (stored !== null) {
                                initialCollapsed = stored === '1';
                            }
                        } catch (err) {
                            // ignore storage issues
                        }
                    }

                    setCollapsed(initialCollapsed);

                    if (lockedOpen) {
                        if (toggle) {
                            toggle.remove();
                        }
                        bodies.forEach(el => {
                            el.hidden = false;
                        });
                        return;
                    }

                    toggle.addEventListener('click', () => {
                        const nextCollapsed = !card.classList.contains('is-collapsed');
                        setCollapsed(nextCollapsed);
                        try {
                            localStorage.setItem(storageKey, nextCollapsed ? '1' : '0');
                        } catch (err) {
                            // ignore storage issues
                        }
    <footer class="border-top py-4">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <div class="text-muted">&copy; <?= date('Y'); ?> TestGramatikov</div>
            <div class="d-flex gap-3 small">
                <a class="text-decoration-none" href="terms.php">Условия</a>
                <a class="text-decoration-none" href="privacy.php">Поверителност</a>
                <a class="text-decoration-none" href="contact.php">Контакт</a>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            (() => {
                const cards = document.querySelectorAll('.section-card');
                cards.forEach((card, index) => {
                    const header = card.querySelector('.card-header');
                    const bodies = card.querySelectorAll('.card-body, .card-footer');
                    if (!header || bodies.length === 0) {
                        return;
                    }

                    let toggle = header.querySelector('.card-toggle');
                    const lockedOpen = card.dataset.lockedOpen === 'true';
                    const defaultOpen = card.dataset.defaultOpen === 'true';

                    if (!toggle && !lockedOpen) {
                        toggle = document.createElement('button');
                        toggle.type = 'button';
                        toggle.className = 'card-toggle';
                        toggle.setAttribute('aria-label', 'Свий или разгъни секцията');
                        toggle.innerHTML = '<i class="bi bi-chevron-up"></i>';
                        header.appendChild(toggle);
                    }

                    const keyBase = card.id || card.getAttribute('data-card-key') || `card-${index}`;
                    const storageKey = `tg-dashboard-card-${keyBase}`;

                    const setCollapsed = (collapsed) => {
                        card.classList.toggle('is-collapsed', collapsed);
                        bodies.forEach(el => {
                            el.hidden = collapsed;
                        });
                        if (toggle) {
                            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                        }
                    };

                    let initialCollapsed = lockedOpen ? false : !defaultOpen;
                    if (!lockedOpen) {
                        try {
                            const stored = localStorage.getItem(storageKey);
                            if (stored !== null) {
                                initialCollapsed = stored === '1';
                            }
                        } catch (err) {
                            // ignore storage issues
                        }
                    }

                    setCollapsed(initialCollapsed);

                    if (lockedOpen) {
                        if (toggle) {
                            toggle.remove();
                        }
                        bodies.forEach(el => {
                            el.hidden = false;
                        });
                        return;
                    }

                    toggle.addEventListener('click', () => {
                        const nextCollapsed = !card.classList.contains('is-collapsed');
                        setCollapsed(nextCollapsed);
                        try {
                            localStorage.setItem(storageKey, nextCollapsed ? '1' : '0');
                        } catch (err) {
                            // ignore storage issues
                        }
                    });
                });
            })();
        </script>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.js.iife.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const driver = window.driver.js.driver;
            const userRole = '<?= $user['role'] ?>';
            const toggle = document.getElementById('helpToggle');
            
            const teacherSteps = [
                { popover: { title: 'Добре дошли!', description: 'Това е вашето табло за управление. Тук можете да управлявате класове, тестове и задания.' } },
                { element: '.hero-actions', popover: { title: 'Бързи действия', description: 'От тук можете бързо да създавате нови тестове, класове и задания.', side: 'bottom' } },
                { element: '.col-lg-5 .row', popover: { title: 'Статистика', description: 'Бърз преглед на вашите класове, тестове и активност.', side: 'left' } },
                { element: '.filter-card', popover: { title: 'Филтри', description: 'Използвайте тези филтри, за да намерите конкретни класове, тестове или задания.', side: 'top' } },
                { element: '[data-card-key="teacher-classes"]', popover: { title: 'Вашите класове', description: 'Списък с всички ваши класове. Можете да ги редактирате или да добавяте ученици.', side: 'top' } },
                { element: '[data-card-key="teacher-tests"]', popover: { title: 'Вашите тестове', description: 'Всички създадени от вас тестове. Можете да ги редактирате, споделяте или изтривате.', side: 'top' } },
                { element: '[data-card-key="teacher-recent-attempts"]', popover: { title: 'Последни опити', description: 'Тук ще видите последните предадени тестове от ученици. Можете да ги оценявате директно.', side: 'top' } },
                { element: '[data-card-key="teacher-assignments-current"]', popover: { title: 'Активни задания', description: 'Списък с текущите задания, които сте възложили.', side: 'top' } }
            ];

            const studentSteps = [
                { popover: { title: 'Добре дошли!', description: 'Това е вашето табло. Тук ще намерите вашите задания и резултати.' } },
                { element: '.join-code-entry', popover: { title: 'Влез в клас', description: 'Въведете кода, предоставен от вашия учител, за да се присъедините към клас.', side: 'right' } },
                { element: '.hero-actions', popover: { title: 'Бързи връзки', description: 'Бърз достъп до вашите активни задания и тестове.', side: 'bottom' } },
                { element: '#student-assignments', popover: { title: 'Вашите задания', description: 'Тук са всички тестове, които трябва да направите. Следете сроковете!', side: 'top' } }
            ];

            const driverObj = driver({
                showProgress: true,
                steps: userRole === 'teacher' ? teacherSteps : studentSteps,
                onDestroyed: () => {
                    toggle.checked = false;
                }
            });

            toggle.addEventListener('change', (e) => {
                if (e.target.checked) {
                    driverObj.drive();
                } else {
                    driverObj.destroy();
                }
            });
        });
    </script>
</body>
</html>
