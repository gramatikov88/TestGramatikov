<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = db();
ensure_attempts_grade($pdo);

$view = $_GET['view'] ?? 'active'; // 'active', 'history'
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;
$search = trim((string) ($_GET['q'] ?? ''));

// Build Query
$where = 'WHERE a.assigned_by_teacher_id = :tid';
$params = [':tid' => $user['id']];

if ($view === 'history') {
    // History: Closed OR Archived
    $where .= ' AND (a.is_published = 0 OR (a.close_at IS NOT NULL AND a.close_at < NOW()))';
} else {
    // Active: Published AND (Open OR Future Close)
    $where .= ' AND a.is_published = 1 AND (a.close_at IS NULL OR a.close_at >= NOW())';
}

if ($search !== '') {
    $where .= ' AND a.title LIKE :q';
    $params[':q'] = "%$search%";
}

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM assignments a $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $per_page);

// Fetch
$sql = "SELECT a.*, t.title as test_title,
               (SELECT COUNT(*) FROM attempts atp WHERE atp.assignment_id = a.id) as attempts_count,
               (SELECT COUNT(*) FROM attempts atp WHERE atp.assignment_id = a.id AND atp.status = 'submitted' AND atp.teacher_grade IS NULL) as needs_grade
        FROM assignments a
        JOIN tests t ON t.id = a.test_id
        $where
        ORDER BY a.created_at DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="bg">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>
        <?= $view === 'history' ? 'Архив Задания' : 'Активни Задания' ?> – TestGramatikov
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= time() ?>">
</head>

<body class="bg-body">
    <?php include __DIR__ . '/components/header.php'; ?>

    <main class="container my-5">
        <div class="d-flex justify-content-between align-items-center mb-5 animate-fade-up">
            <div>
                <h1 class="display-6 fw-bold mb-1">
                    <?= $view === 'history' ? 'Архив' : 'Задания' ?>
                </h1>
                <p class="text-muted lead">Управление на възложените тестове.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="assignments.php?view=active"
                    class="btn btn-outline-primary rounded-pill px-4 <?= $view !== 'history' ? 'active' : '' ?>">Активни</a>
                <a href="assignments.php?view=history"
                    class="btn btn-outline-secondary rounded-pill px-4 <?= $view === 'history' ? 'active' : '' ?>">Архив</a>
                <a href="dashboard.php" class="btn btn-light rounded-circle"><i class="bi bi-arrow-left"></i></a>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="glass-card p-3 mb-4 animate-fade-up delay-100">
            <form method="get" class="d-flex gap-3">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                <div class="input-group flex-grow-1">
                    <span class="input-group-text bg-white border-0 ps-3"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control border-0 bg-white"
                        placeholder="Търсене по заглавие..." value="<?= htmlspecialchars($search) ?>">
                    <?php if ($search): ?>
                        <a href="assignments.php?view=<?= $view ?>" class="btn btn-light border-0"><i
                                class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary rounded-pill px-4">Търси</button>
            </form>
        </div>

        <!-- List -->
        <div class="row g-4 animate-fade-up delay-200">
            <?php if (!$items): ?>
                <div class="col-12">
                    <div class="glass-card p-5 text-center text-muted">
                        <i class="bi bi-inbox display-4 opacity-25"></i>
                        <p class="mt-3">Няма намерени задания.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($items as $item):
                    $active = $item['is_published'] && (empty($item['close_at']) || strtotime($item['close_at']) > time());
                    $needsGrade = (int) $item['needs_grade'];
                    ?>
                    <div class="col-12">
                        <div
                            class="glass-card p-4 hover-lift position-relative overflow-hidden <?= $needsGrade > 0 ? 'border-start border-4 border-warning' : '' ?>">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div class="d-flex flex-column gap-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <h5 class="fw-bold mb-0 text-dark">
                                            <?= htmlspecialchars($item['title']) ?>
                                        </h5>
                                        <?php if ($needsGrade > 0): ?>
                                            <span class="badge bg-warning text-dark rounded-pill shadow-sm"><i
                                                    class="bi bi-fire me-1"></i>
                                                <?= $needsGrade ?> за оценка
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!$item['is_published']): ?>
                                            <span class="badge bg-secondary rounded-pill">Чернова</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small">
                                        <i class="bi bi-file-text me-1"></i>
                                        <?= htmlspecialchars($item['test_title']) ?>
                                        <span class="mx-2">•</span>
                                        <i class="bi bi-people me-1"></i>
                                        <?= (int) $item['attempts_count'] ?> опита
                                        <?php if ($item['due_at']): ?>
                                            <span class="mx-2">•</span>
                                            <i class="bi bi-clock me-1"></i> Краен срок:
                                            <?= format_date($item['due_at']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <a href="assignment_overview.php?id=<?= $item['id'] ?>"
                                        class="btn btn-primary rounded-pill px-4 shadow-sm z-2">Преглед</a>
                                    <a href="assignments_create.php?id=<?= $item['id'] ?>"
                                        class="btn btn-light rounded-circle shadow-sm z-2" title="Редакция"><i
                                            class="bi bi-pencil"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <nav class="mt-5 d-flex justify-content-center animate-fade-up delay-300">
                <ul class="pagination pagination-glass">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?view=<?= $view ?>&q=<?= urlencode($search) ?>&page=<?= $i ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </main>

    <?php include __DIR__ . '/components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>