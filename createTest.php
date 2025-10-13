<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'teacher') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo  = db();

// --- Безопасни помощници ---
function str_bool($v): int { return !empty($v) ? 1 : 0; }

// Валидация на visibility (допуск: private/shared)
function normalize_visibility(?string $v): string {
    $v = strtolower(trim((string)$v));
    return in_array($v, ['private','shared'], true) ? $v : 'private';
}

$errors = [];
$saved  = false;

// EDIT?
$test_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $test_id > 0;
$test    = null;

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM tests WHERE id = :id AND owner_teacher_id = :tid');
    $stmt->execute([':id'=>$test_id, ':tid'=>$user['id']]);
    $test = $stmt->fetch();
    if (!$test) {
        // или не съществува, или не е твой
        header('Location: tests_create.php');
        exit;
    }
}

// DELETE
if (isset($_GET['delete'])) {
    $del = (int)$_GET['delete'];
    // трий само собствени
    $stmt = $pdo->prepare('DELETE FROM tests WHERE id = :id AND owner_teacher_id = :tid');
    $stmt->execute([':id'=>$del, ':tid'=>$user['id']]);
    header('Location: tests_create.php');
    exit;
}

// POST: create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim((string)($_POST['title'] ?? ''));
    $visibility = normalize_visibility($_POST['visibility'] ?? 'private');

    if ($title === '') {
        $errors[] = 'Моля, въведете заглавие.';
    }

    if (!$errors) {
        try {
            if ($editing) {
                $stmt = $pdo->prepare('
                    UPDATE tests
                    SET title = :title, visibility = :visibility
                    WHERE id = :id AND owner_teacher_id = :tid
                ');
                $stmt->execute([
                    ':title'      => $title,
                    ':visibility' => $visibility,
                    ':id'         => $test_id,
                    ':tid'        => $user['id'],
                ]);
            } else {
                // КРИТИЧНО: винаги задаваме owner_teacher_id = текущия учител
                $stmt = $pdo->prepare('
                    INSERT INTO tests (title, visibility, owner_teacher_id)
                    VALUES (:title, :visibility, :tid)
                ');
                $stmt->execute([
                    ':title'      => $title,
                    ':visibility' => $visibility,
                    ':tid'        => $user['id'],
                ]);
                $test_id = (int)$pdo->lastInsertId();
                $editing = true;
            }
            $saved = true;
        } catch (Throwable $e) {
            $errors[] = 'Грешка при запис: ' . $e->getMessage();
        }
    }
}

// Списък с тестове: първо моите, после shared на други
$list = $pdo->prepare('
    SELECT id, title, visibility, owner_teacher_id,
           (owner_teacher_id = :tid) AS is_mine
    FROM tests
    WHERE owner_teacher_id = :tid OR visibility = "shared"
    ORDER BY is_mine DESC, title
');
$list->execute([':tid'=>$user['id']]);
$rows = $list->fetchAll();
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $editing ? 'Редакция на тест' : 'Нов тест' ?> – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 m-0"><?= $editing ? 'Редакция на тест' : 'Създаване на тест' ?></h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>

    <?php if ($saved): ?>
        <div class="alert alert-success">Тестът е запазен.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Основни данни</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-7">
                <label class="form-label">Заглавие</label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($test['title'] ?? '') ?>" required />
            </div>
            <div class="col-md-5">
                <label class="form-label">Видимост</label>
                <select name="visibility" class="form-select">
                    <?php
                        $vis = $test['visibility'] ?? 'private';
                        $opt = function($v,$t,$cur){ $sel = ($cur===$v)?'selected':''; echo "<option value=\"$v\" $sel>$t</option>"; };
                        $opt('private','Само аз', $vis);
                        $opt('shared', 'Споделен (видим за други учители)', $vis);
                    ?>
                </select>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Запази</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Тестове (моите първо)</strong></div>
        <div class="list-group list-group-flush">
            <?php if (!$rows): ?>
                <div class="list-group-item text-muted">Нямате тестове.</div>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($r['title']) ?>
                            <?php if ((int)$r['is_mine'] === 1): ?>
                                <span class="badge bg-primary">мой</span>
                            <?php endif; ?>
                            <?php if ($r['visibility'] === 'shared'): ?>
                                <span class="badge bg-success">shared</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">private</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">ID: <?= (int)$r['id'] ?> • Owner: <?= (int)$r['owner_teacher_id'] ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ((int)$r['is_mine'] === 1): ?>
                            <a class="btn btn-sm btn-outline-primary" href="tests_create.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
                            <a class="btn btn-sm btn-outline-danger" href="tests_create.php?delete=<?= (int)$r['id'] ?>" onclick="return confirm('Изтриване на теста?');"><i class="bi bi-trash"></i></a>
                        <?php else: ?>
                            <span class="btn btn-sm btn-outline-secondary disabled" title="Не е ваш тест"><i class="bi bi-lock"></i></span>
                        <?php endif; ?>
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
