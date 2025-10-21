<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = db();

function dt_from_input(?string $s): ?string
{
    if ($s === null || $s === '')
        return null;
    // html datetime-local: 2025-10-10T12:30 -> 2025-10-10 12:30:00
    return str_replace('T', ' ', $s) . ':00';
}

/* ------------------------ Load selectable data ------------------------ */
// Тестове – позволени за учителя (със запомняне на id-тата за валидация)
$testsStmt = $pdo->prepare("
    SELECT id, title
    FROM tests
    WHERE owner_teacher_id = :tid OR visibility = 'shared'
    ORDER BY (owner_teacher_id = :tid) DESC, title
");
$testsStmt->execute([':tid' => $user['id']]);
$tests = $testsStmt->fetchAll();
$allowed_test_ids = array_map(fn($t) => (int) $t['id'], $tests);

// Класове на учителя
$classesStmt = $pdo->prepare('
    SELECT id, grade, section, school_year, name
    FROM classes
    WHERE teacher_id = :tid
    ORDER BY school_year DESC, grade, section
');
$classesStmt->execute([':tid' => $user['id']]);
$classes = $classesStmt->fetchAll();

// Ученици от класовете на учителя (distinct)
// ВАЖНО: допускам и NULL статус, защото новите ученици нямат зададен 'active'
$studentsStmt = $pdo->prepare('
    SELECT u.id, u.first_name, u.last_name, u.email,
           GROUP_CONCAT(DISTINCT cs.class_id) AS class_ids
    FROM users u
    JOIN class_students cs ON cs.student_id = u.id
    JOIN classes c       ON c.id = cs.class_id
    WHERE u.role = "student"
      AND c.teacher_id = :tid
      AND (u.status IS NULL OR u.status = "active")
    GROUP BY u.id, u.first_name, u.last_name, u.email
    ORDER BY u.first_name, u.last_name
');
$studentsStmt->execute([':tid' => $user['id']]);
$students = $studentsStmt->fetchAll();

/* ---------------------------- Edit existing --------------------------- */
$assignment_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $assignment_id > 0;

$assignment = null;
if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = :id AND assigned_by_teacher_id = :tid');
    $stmt->execute([':id' => $assignment_id, ':tid' => $user['id']]);
    $assignment = $stmt->fetch();
    if (!$assignment) {
        header('Location: assignments_create.php');
        exit;
    }
}

/* --------------------------- Form state (sticky) ---------------------- */
$errors = [];
$saved = false;

$form = [
    'title' => $assignment['title'] ?? '',
    'description' => $assignment['description'] ?? '',
    'test_id' => isset($assignment['test_id']) ? (int) $assignment['test_id'] : 0,
    'open_at' => !empty($assignment['open_at']) ? substr($assignment['open_at'], 0, 16) : '',
    'due_at' => !empty($assignment['due_at']) ? substr($assignment['due_at'], 0, 16) : '',
    'close_at' => !empty($assignment['close_at']) ? substr($assignment['close_at'], 0, 16) : '',
    'attempt_limit' => (int) ($assignment['attempt_limit'] ?? 0),
    'shuffle_questions' => !empty($assignment['shuffle_questions']) ? 1 : 0,
    'is_published' => !empty($assignment['is_published']) ? 1 : 0,
    'class_ids' => [],
    'student_ids' => [],
];

// При редакция – зареди чекнатите класове/ученици за sticky
if ($editing) {
    $chkC = $pdo->prepare('SELECT class_id FROM assignment_classes WHERE assignment_id = :aid');
    $chkC->execute([':aid' => $assignment_id]);
    $form['class_ids'] = array_map('intval', array_column($chkC->fetchAll(), 'class_id'));

    $chkS = $pdo->prepare('SELECT student_id FROM assignment_students WHERE assignment_id = :aid');
    $chkS->execute([':aid' => $assignment_id]);
    $form['student_ids'] = array_map('intval', array_column($chkS->fetchAll(), 'student_id'));
}

/* ------------------------------- POST -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sticky от POST
    $form['title'] = trim((string) ($_POST['title'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['test_id'] = (int) ($_POST['test_id'] ?? 0);
    $form['is_published'] = !empty($_POST['is_published']) ? 1 : 0;
    $form['open_at'] = (string) ($_POST['open_at'] ?? '');
    $form['due_at'] = (string) ($_POST['due_at'] ?? '');
    $form['close_at'] = (string) ($_POST['close_at'] ?? '');
    $form['attempt_limit'] = max(0, (int) ($_POST['attempt_limit'] ?? 0));
    $form['shuffle_questions'] = !empty($_POST['shuffle_questions']) ? 1 : 0;
    $form['class_ids'] = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? array_map('intval', $_POST['class_ids']) : [];
    $form['student_ids'] = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : [];

    // Валидации
    if ($form['title'] === '') {
        $errors[] = 'Моля, въведете заглавие.';
    }
    if ($form['test_id'] <= 0) {
        $errors[] = 'Изберете тест.';
    } elseif (!in_array($form['test_id'], $allowed_test_ids, true)) {
        $errors[] = 'Избраният тест не е достъпен.';
    }
    if (!$form['class_ids'] && !$form['student_ids']) {
        $errors[] = 'Изберете поне един клас или ученик.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $open_at = dt_from_input($form['open_at'] ?: null);
            $due_at = dt_from_input($form['due_at'] ?: null);
            $close_at = dt_from_input($form['close_at'] ?: null);

            if ($editing) {
                $stmt = $pdo->prepare('UPDATE assignments
                    SET test_id=:test_id, title=:title, description=:description, is_published=:pub,
                        open_at=:open_at, due_at=:due_at, close_at=:close_at,
                        attempt_limit=:limitv, shuffle_questions=:shuffle
                    WHERE id = :id AND assigned_by_teacher_id = :tid');
                $stmt->execute([
                    ':test_id' => $form['test_id'],
                    ':title' => $form['title'],
                    ':description' => $form['description'],
                    ':pub' => $form['is_published'],
                    ':open_at' => $open_at,
                    ':due_at' => $due_at,
                    ':close_at' => $close_at,
                    ':limitv' => $form['attempt_limit'],
                    ':shuffle' => $form['shuffle_questions'],
                    ':id' => $assignment_id,
                    ':tid' => $user['id'],
                ]);

                $pdo->prepare('DELETE FROM assignment_classes WHERE assignment_id = :id')->execute([':id' => $assignment_id]);
                $pdo->prepare('DELETE FROM assignment_students WHERE assignment_id = :id')->execute([':id' => $assignment_id]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO assignments
                        (test_id, assigned_by_teacher_id, title, description, is_published,
                         open_at, due_at, close_at, attempt_limit, shuffle_questions)
                    VALUES
                        (:test_id, :tid, :title, :description, :pub,
                         :open_at, :due_at, :close_at, :limitv, :shuffle)
                ');
                $stmt->execute([
                    ':test_id' => $form['test_id'],
                    ':tid' => $user['id'],
                    ':title' => $form['title'],
                    ':description' => $form['description'],
                    ':pub' => $form['is_published'],
                    ':open_at' => $open_at,
                    ':due_at' => $due_at,
                    ':close_at' => $close_at,
                    ':limitv' => $form['attempt_limit'],
                    ':shuffle' => $form['shuffle_questions'],
                ]);
                $assignment_id = (int) $pdo->lastInsertId();
                $editing = true;
            }

            // Targets
            if ($form['class_ids']) {
                $ins = $pdo->prepare('INSERT INTO assignment_classes (assignment_id, class_id) VALUES (:aid, :cid)');
                foreach ($form['class_ids'] as $cid) {
                    $ins->execute([':aid' => $assignment_id, ':cid' => $cid]);
                }
            }
            if ($form['student_ids']) {
                $ins = $pdo->prepare('INSERT INTO assignment_students (assignment_id, student_id) VALUES (:aid, :sid)');
                foreach ($form['student_ids'] as $sid) {
                    $ins->execute([':aid' => $assignment_id, ':sid' => $sid]);
                }
            }

            $pdo->commit();
            $saved = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $errors[] = 'Грешка при запис: ' . $e->getMessage();
        }
    }
}

/* ------------------------- Own assignments list ----------------------- */
$listStmt = $pdo->prepare('
    SELECT a.id, a.title, a.is_published, a.open_at, a.due_at, a.close_at, t.title AS test_title
    FROM assignments a
    JOIN tests t ON t.id = a.test_id
    WHERE a.assigned_by_teacher_id = :tid
    ORDER BY a.id DESC
    LIMIT 50
');
$listStmt->execute([':tid' => $user['id']]);
$list = $listStmt->fetchAll();

/* ------------------------------- Delete ------------------------------- */
if (isset($_GET['delete'])) {
    $del = (int) $_GET['delete'];
    $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = :id AND assigned_by_teacher_id = :tid');
    $stmt->execute([':id' => $del, ':tid' => $user['id']]);
    header('Location: assignments_create.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $editing ? 'Редакция на задание' : 'Ново задание' ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .brand-badge {
            background: rgba(13, 110, 253, .1);
            border: 1px solid rgba(13, 110, 253, .2);
            color: #0d6efd;
        }

        .scroll-area {
            max-height: 220px;
            overflow: auto;
            border: 1px solid #eee;
            border-radius: .5rem;
            padding: .5rem;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-4 my-md-5">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h1 class="h4 m-0"><?= $editing ? 'Редакция на задание' : 'Създаване на задание' ?></h1>
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
        </div>

        <?php if ($saved): ?>
            <div class="alert alert-success">Заданието е запазено.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="m-0 ps-3">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="card shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Основни данни</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Заглавие</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($form['title']) ?>"
                        required />
                </div>
                <div class="col-md-6">
                    <label class="form-label">Тест</label>
                    <select name="test_id" class="form-select" required>
                        <option value="">— Изберете тест —</option>
                        <?php foreach ($tests as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= ($form['test_id'] === (int) $t['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-control"
                        rows="2"><?= htmlspecialchars($form['description']) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">От</label>
                    <input type="datetime-local" name="open_at" class="form-control"
                        value="<?= $form['open_at'] ? str_replace(' ', 'T', $form['open_at']) : '' ?>" />
                </div>
                <div class="col-md-3">
                    <label class="form-label">Срок</label>
                    <input type="datetime-local" name="due_at" class="form-control"
                        value="<?= $form['due_at'] ? str_replace(' ', 'T', $form['due_at']) : '' ?>" />
                </div>
                <div class="col-md-3">
                    <label class="form-label">До</label>
                    <input type="datetime-local" name="close_at" class="form-control"
                        value="<?= $form['close_at'] ? str_replace(' ', 'T', $form['close_at']) : '' ?>" />
                </div>
                <div class="col-md-3">
                    <label class="form-label">Лимит опити</label>
                    <input type="number" min="0" name="attempt_limit" class="form-control"
                        value="<?= (int) $form['attempt_limit'] ?>" />
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="shuffle_questions" name="shuffle_questions"
                            <?= $form['shuffle_questions'] ? 'checked' : '' ?> />
                        <label class="form-check-label" for="shuffle_questions">Разбъркване на въпроси</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                            <?= $form['is_published'] ? 'checked' : '' ?> />
                        <label class="form-check-label" for="is_published">Публикувано</label>
                    </div>
                </div>
            </div>

            <div class="card-header bg-white border-top">
                <strong>Класове</strong> <span class="text-muted small">(по избор)</span>
            </div>
            <div class="card-body">
                <div class="scroll-area">
                    <?php foreach ($classes as $c): ?>
                        <?php $isChecked = in_array((int) $c['id'], $form['class_ids'], true); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="class_ids[]" value="<?= (int) $c['id'] ?>"
                                id="class<?= (int) $c['id'] ?>" <?= $isChecked ? 'checked' : '' ?> />
                            <label class="form-check-label" for="class<?= (int) $c['id'] ?>">
                                <?= htmlspecialchars($c['grade'] . $c['section']) ?> •
                                <?= htmlspecialchars($c['school_year']) ?> — <?= htmlspecialchars($c['name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card-header bg-white border-top">
                <strong>Ученици</strong> <span class="text-muted small">(по избор)</span>
            </div>
            <div class="card-body">
                <div class="scroll-area">
                    <?php foreach ($students as $s):
                        $isChecked = in_array((int) $s['id'], $form['student_ids'], true);
                        $classIdsRaw = (string)($s['class_ids'] ?? '');
                        $classIdsArr = $classIdsRaw !== '' ? array_filter(array_map('intval', explode(',', $classIdsRaw))) : [];
                        $classIdsAttr = htmlspecialchars(implode(' ', $classIdsArr));
                        ?>
                        <div class="form-check" data-student-row data-class-ids="<?= $classIdsAttr ?>">
                            <input class="form-check-input" type="checkbox" name="student_ids[]"
                                value="<?= (int) $s['id'] ?>" id="st<?= (int) $s['id'] ?>" <?= $isChecked ? 'checked' : '' ?> />
                            <label class="form-check-label" for="st<?= (int) $s['id'] ?>">
                                <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                                <span class="text-muted small">(<?= htmlspecialchars($s['email']) ?>)</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$students): ?>
                        <div class="text-muted small">Няма намерени ученици във ваши класове. Уверете се, че сте добавили
                            ученици към клас и че не са архивирани.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Запази</button>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="card-header bg-white"><strong>Моите задания</strong></div>
            <div class="list-group list-group-flush">
                <?php if (!$list): ?>
                    <div class="list-group-item text-muted">Нямате задания.</div>
                <?php endif; ?>
                <?php foreach ($list as $row): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold">
                                <?= htmlspecialchars($row['title']) ?>
                                <?= !empty($row['is_published']) ? '<span class="badge bg-success">публикувано</span>' : '<span class="badge bg-secondary">чернова</span>' ?>
                            </div>
                            <div class="text-muted small">
                                Тест: <?= htmlspecialchars($row['test_title']) ?>
                                <?php if ($row['due_at']): ?> • Срок: <?= htmlspecialchars($row['due_at']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary"
                                href="assignments_create.php?id=<?= (int) $row['id'] ?>"><i class="bi bi-pencil"></i></a>
                            <a class="btn btn-sm btn-outline-danger"
                                href="assignments_create.php?delete=<?= (int) $row['id'] ?>"
                                onclick="return confirm('Изтриване на заданието?');"><i class="bi bi-trash"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
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
    (function () {
        const classCheckboxes = Array.from(document.querySelectorAll('input[name="class_ids[]"]'));
        const studentRows = Array.from(document.querySelectorAll('[data-student-row]'));
        if (!classCheckboxes.length || !studentRows.length) {
            return;
        }

        function getSelectedClassIds() {
            return classCheckboxes.filter(cb => cb.checked).map(cb => cb.value);
        }

        function updateStudentVisibility() {
            const selectedIds = getSelectedClassIds();
            const hasSelection = selectedIds.length > 0;
            studentRows.forEach(row => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                const attr = (row.getAttribute('data-class-ids') || '').trim();
                const rowClassIds = attr !== '' ? attr.split(/\s+/) : [];

                const shouldShow = !hasSelection || rowClassIds.some(id => selectedIds.includes(id));

                if (shouldShow) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                }
            });
        }

        classCheckboxes.forEach(cb => cb.addEventListener('change', updateStudentVisibility));
        updateStudentVisibility();
    })();
    </script>
</footer>
</body>

</html>