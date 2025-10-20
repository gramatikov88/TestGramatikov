<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$pdo = db();
ensure_subjects_scope($pdo);
$errors = [];
$saved = false;

function slugify(string $text): string {
    $text = trim($text);
    $text = mb_strtolower($text, 'UTF-8');
    $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($trans !== false && $trans !== '') { $text = $trans; }
    $text = preg_replace('~[^a-z0-9]+~', '-', $text);
    $text = trim($text, '-');
    if ($text === '') $text = 'subject-' . substr(sha1(uniqid('', true)), 0, 6);
    return $text;
}

// Handle delete (only own)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $pdo->prepare('DELETE FROM subjects WHERE id = :id AND owner_teacher_id = :tid')->execute([':id'=>$id, ':tid'=>$_SESSION['user']['id']]);
        header('Location: subjects_create.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Неуспешно изтриване: ' . $e->getMessage();
    }
}

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    if ($name === '') $errors[] = 'Моля, въведете име на предмета.';
    if ($slug === '') $slug = slugify($name);

    if (!$errors) {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE subjects SET name = :name, slug = :slug WHERE id = :id AND owner_teacher_id = :tid');
                $stmt->execute([':name'=>$name, ':slug'=>$slug, ':id'=>$id, ':tid'=>$_SESSION['user']['id']]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO subjects (owner_teacher_id, name, slug) VALUES (:tid, :name, :slug)');
                $stmt->execute([':tid'=>$_SESSION['user']['id'], ':name'=>$name, ':slug'=>$slug]);
            }
            $saved = true;
        } catch (PDOException $e) {
            if ($e->getCode()==='23000') { $errors[] = 'Съществува предмет със същия slug.'; }
            else { $errors[] = 'Грешка при запис: ' . $e->getMessage(); }
        }
    }
}

// Load list (only own)
$stmt = $pdo->prepare('SELECT * FROM subjects WHERE owner_teacher_id = :tid ORDER BY name');
$stmt->execute([':tid'=>$_SESSION['user']['id']]);
$rows = $stmt->fetchAll();

// Load for edit
$edit = null;
if (isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    foreach ($rows as $r) { if ((int)$r['id'] === $sid) { $edit = $r; break; } }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Предмети – TestGramatikov</title>
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
        <h1 class="h4 m-0">Учебни предмети</h1>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>

    <?php if ($saved): ?><div class="alert alert-success">Запазено успешно.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="m-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="row g-3 g-md-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong><?= $edit ? 'Редакция на предмет' : 'Нов предмет' ?></strong></div>
                <form method="post">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Име</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($edit['name'] ?? '') ?>" required />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Slug</label>
                            <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($edit['slug'] ?? '') ?>" placeholder="автоматично от името" />
                            <div class="form-text">Само латински букви, цифри и тирета.</div>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-end gap-2">
                        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>" /><?php endif; ?>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Запази</button>
                        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="subjects_create.php">Отказ</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Всички предмети</strong></div>
                <div class="list-group list-group-flush">
                    <?php if (!$rows): ?><div class="list-group-item text-muted">Няма предмети.</div><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="text-muted small">Slug: <?= htmlspecialchars($r['slug']) ?></div>
                            </div>
                            <div class="d-flex gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="subjects_create.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
                                <a class="btn btn-sm btn-outline-danger" href="subjects_create.php?delete=<?= (int)$r['id'] ?>" onclick="return confirm('Изтриване на предмета?');"><i class="bi bi-trash"></i></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
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