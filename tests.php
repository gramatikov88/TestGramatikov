<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
ensure_subjects_scope($pdo);

$user = $_SESSION['user'] ?? null;
$role = $user['role'] ?? 'guest';

// Filters
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$subject_id = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? (int)$_GET['subject_id'] : null;
$sort = $_GET['sort'] ?? 'updated_desc'; // updated_desc|updated_asc|title_asc|title_desc
$owner = $_GET['owner'] ?? ($role==='teacher' ? 'mine' : 'shared'); // mine|shared|all
$visibility = in_array(($_GET['visibility'] ?? ''), ['private','shared'], true) ? $_GET['visibility'] : '';
$status = in_array(($_GET['status'] ?? ''), ['draft','published','archived'], true) ? $_GET['status'] : '';

// Build base query depending on role and owner view
$sql = 'SELECT t.id, t.title, t.visibility, t.status, t.updated_at, t.subject_id, u.first_name, u.last_name, s.name AS subject_name
        FROM tests t
        JOIN users u ON u.id = t.owner_teacher_id
        LEFT JOIN subjects s ON s.id = t.subject_id';
$where = [];
$params = [];

if ($role === 'teacher') {
    if ($owner === 'mine') {
        $where[] = 't.owner_teacher_id = :tid';
        $params[':tid'] = (int)$user['id'];
    } elseif ($owner === 'all') {
        $where[] = '(t.owner_teacher_id = :tid OR (t.visibility = "shared" AND t.status = "published"))';
        $params[':tid'] = (int)$user['id'];
    } else { // shared
        $where[] = '(t.visibility = "shared" AND t.status = "published")';
    }
} else {
    // Student/guest: only shared published
    $where[] = '(t.visibility = "shared" AND t.status = "published")';
}

if ($q !== '') { $where[] = 't.title LIKE :q'; $params[':q'] = '%'.$q.'%'; }
if ($subject_id) { $where[] = 't.subject_id = :sid'; $params[':sid'] = $subject_id; }
if ($visibility !== '' && $role === 'teacher' && $owner !== 'shared') { $where[] = 't.visibility = :vis'; $params[':vis'] = $visibility; }
if ($status !== '' && $role === 'teacher' && $owner !== 'shared') { $where[] = 't.status = :st'; $params[':st'] = $status; }

$order = ' ORDER BY t.updated_at DESC';
if ($sort === 'updated_asc') $order = ' ORDER BY t.updated_at ASC';
if ($sort === 'title_asc') $order = ' ORDER BY t.title ASC';
if ($sort === 'title_desc') $order = ' ORDER BY t.title DESC';

$final = $sql . ' WHERE ' . implode(' AND ', $where) . $order . ' LIMIT 100';
$stmt = $pdo->prepare($final);
$stmt->execute($params);
$tests = $stmt->fetchAll();

// Subjects for filter
$subjects = [];
try {
    if ($role === 'teacher' && $owner === 'mine') {
        $s = $pdo->prepare('SELECT id, name FROM subjects WHERE owner_teacher_id = :tid ORDER BY name');
        $s->execute([':tid'=>(int)$user['id']]);
        $subjects = $s->fetchAll();
    } else {
        $s = $pdo->query('SELECT id, name FROM subjects ORDER BY name');
        $subjects = $s->fetchAll();
    }
} catch (Throwable $e) { $subjects = []; }
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Тестове – TestGramatikov</title>
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
        <h1 class="h4 m-0">Тестове</h1>
        <a href="categories.php" class="btn btn-outline-secondary"><i class="bi bi-folder2-open"></i> Категории</a>
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body row g-2 g-md-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Търсене</label>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Заглавие на тест..." />
            </div>
            <div class="col-md-3">
                <label class="form-label">Предмет</label>
                <select name="subject_id" class="form-select">
                    <option value="">Всички</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ($subject_id && (int)$subject_id === (int)$s['id'])?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Сортиране</label>
                <select name="sort" class="form-select">
                    <option value="updated_desc" <?= $sort==='updated_desc'?'selected':'' ?>>Обновени ↓</option>
                    <option value="updated_asc" <?= $sort==='updated_asc'?'selected':'' ?>>Обновени ↑</option>
                    <option value="title_asc" <?= $sort==='title_asc'?'selected':'' ?>>Заглавие A→Я</option>
                    <option value="title_desc" <?= $sort==='title_desc'?'selected':'' ?>>Заглавие Я→A</option>
                </select>
            </div>
            <?php if ($role === 'teacher'): ?>
            <div class="col-md-3">
                <label class="form-label">Собственик</label>
                <select name="owner" class="form-select">
                    <option value="mine" <?= $owner==='mine'?'selected':'' ?>>Мои</option>
                    <option value="shared" <?= $owner==='shared'?'selected':'' ?>>Споделени</option>
                    <option value="all" <?= $owner==='all'?'selected':'' ?>>Мои + Споделени</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Видимост</label>
                <select name="visibility" class="form-select">
                    <option value="">Всички</option>
                    <option value="private" <?= $visibility==='private'?'selected':'' ?>>Частни</option>
                    <option value="shared" <?= $visibility==='shared'?'selected':'' ?>>Споделени</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="">Всички</option>
                    <option value="draft" <?= $status==='draft'?'selected':'' ?>>Чернова</option>
                    <option value="published" <?= $status==='published'?'selected':'' ?>>Публикуван</option>
                    <option value="archived" <?= $status==='archived'?'selected':'' ?>>Архивиран</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Филтър</button>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="list-group list-group-flush">
            <?php if (!$tests): ?><div class="list-group-item text-muted">Няма тестове по зададените критерии.</div><?php endif; ?>
            <?php foreach ($tests as $t): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($t['title']) ?>
                            <?php if (!empty($t['subject_name'])): ?>
                                <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($t['subject_name']) ?></span>
                            <?php endif; ?>
                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($t['status']) ?></span>
                            <span class="badge bg-light text-dark ms-1"><?= htmlspecialchars($t['visibility']) ?></span>
                        </div>
                        <div class="text-muted small">Автор: <?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?> • Обновен: <?= htmlspecialchars($t['updated_at']) ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary" href="test_view.php?test_id=<?= (int)$t['id'] ?>"><i class="bi bi-eye"></i> Преглед</a>
                        <?php if ($role === 'teacher' && (int)$user['id'] === (int)$params[':tid']): ?>
                            <a class="btn btn-sm btn-outline-primary" href="test_edit.php?id=<?= (int)$t['id'] ?>"><i class="bi bi-pencil"></i> Редакция</a>
                        <?php endif; ?>
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
</footer>
</body>
</html>
