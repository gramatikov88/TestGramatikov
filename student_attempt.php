<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

require_role('student');

$pdo = db();
$student = $_SESSION['user'];

$attempt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attempt_id <= 0) { http_response_code(400); die('Липсва идентификатор на опит.'); }

// Load attempt
$stmt = $pdo->prepare('SELECT atp.*, a.title AS assignment_title, a.open_at, a.due_at, a.close_at, t.title AS test_title
                       FROM attempts atp
                       JOIN assignments a ON a.id = atp.assignment_id
                       JOIN tests t ON t.id = atp.test_id
                       WHERE atp.id = :id AND atp.student_id = :sid LIMIT 1');
$stmt->execute([':id'=>$attempt_id, ':sid'=>$student['id']]);
$attempt = $stmt->fetch();
if (!$attempt) { http_response_code(404); die('Опитът не е намерен.'); }

// Check if keys should be shown
$now = time();
$show_keys = false;
if (!empty($attempt['due_at']) && strtotime($attempt['due_at']) < $now) $show_keys = true;
if (!empty($attempt['close_at']) && strtotime($attempt['close_at']) < $now) $show_keys = true;

// Load questions
$qStmt = $pdo->prepare('SELECT qb.*, tq.points, tq.order_index FROM test_questions tq JOIN question_bank qb ON qb.id = tq.question_id WHERE tq.test_id = :tid ORDER BY tq.order_index');
$qStmt->execute([':tid' => (int)$attempt['test_id']]);
$questions = $qStmt->fetchAll();

// Load answers
$answersByQ = [];
if ($questions) {
    $ids = array_map(fn($r)=> (int)$r['id'], $questions);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $pdo->prepare("SELECT * FROM answers WHERE question_id IN ($in) ORDER BY order_index");
    $aStmt->execute($ids);
    while ($a = $aStmt->fetch()) { $answersByQ[(int)$a['question_id']][] = $a; }
}

// Load student response
$aa = [];
$aaStmt = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id = :att');
$aaStmt->execute([':att'=>$attempt_id]);
while ($row = $aaStmt->fetch()) { $aa[(int)$row['question_id']] = $row; }

$percent = percent($attempt['score_obtained'], $attempt['max_score']);
$grade = grade_from_percent($percent);
$gradeColor = get_grade_color_class($grade);
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Преглед на опит – TestGramatikov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/components/header.php'; ?>

<main class="container my-4 my-md-5">
    <!-- Header / Summary Card -->
    <div class="glass-card p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div>
                <a href="dashboard.php" class="text-decoration-none text-muted small mb-1 d-block"><i class="bi bi-arrow-left me-1"></i> Обратно към таблото</a>
                <h1 class="h3 fw-bold mb-1">Резултат от опит №<?= (int)$attempt['id'] ?></h1>
                <div class="text-muted">
                    <span class="d-none d-sm-inline">Задание:</span> <strong><?= htmlspecialchars($attempt['assignment_title']) ?></strong>
                    <span class="mx-2">•</span>
                    <span class="d-none d-sm-inline">Тест:</span> <strong><?= htmlspecialchars($attempt['test_title']) ?></strong>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3 bg-<?= $gradeColor ?> bg-opacity-10 p-3 rounded-4 border border-<?= $gradeColor ?> border-opacity-25">
                <div class="text-center">
                    <div class="small text-muted text-uppercase tracking-wider">Оценка</div>
                    <div class="display-6 fw-bold text-<?= $gradeColor ?>"><?= $grade ?? '—' ?></div>
                </div>
                <div class="vr opacity-25"></div>
                <div class="text-center">
                    <div class="small text-muted text-uppercase tracking-wider">Точки</div>
                    <div class="h4 mb-0"><?= (float)$attempt['score_obtained'] ?> <span class="text-muted h6">/ <?= (float)$attempt['max_score'] ?></span></div>
                </div>
                <div class="vr opacity-25"></div>
                <div class="text-center">
                    <div class="small text-muted text-uppercase tracking-wider"> Процент</div>
                    <div class="h4 mb-0"><?= $percent !== null ? $percent.'%' : '—' ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!$show_keys): ?>
            <div class="alert alert-info mt-3 mb-0 border-0 bg-info bg-opacity-10 text-info">
                <i class="bi bi-info-circle-fill me-2"></i>
                Детайлните верни отговори са скрити докато не изтече крайният срок за предаване.
            </div>
        <?php endif; ?>
    </div>

    <!-- Questions List -->
    <div class="d-flex flex-column gap-3">
        <?php foreach ($questions as $idx => $q): 
            $qid = (int)$q['id']; 
            $studentAns = $aa[$qid] ?? null; 
            $qAnswers = $answersByQ[$qid] ?? [];
            $isCorrect = $studentAns ? ((int)$studentAns['is_correct'] === 1) : false;
            $cardBorderClass = $isCorrect ? 'border-success' : ($studentAns ? 'border-danger' : 'border-secondary');
            if (!$studentAns) $cardBorderClass = 'border-light'; // No answer treated as neutral visual or unchecked
        ?>
            <div class="glass-card mb-0 position-relative animate-up" style="animation-delay: <?= $idx * 0.05 ?>s">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex gap-3">
                            <span class="badge bg-light text-dark align-self-start mt-1 border">№<?= $idx+1 ?></span>
                            <div class="lead fs-6"><?= nl2br(htmlspecialchars($q['body'])) ?></div>
                        </div>
                        <div class="text-end ms-3 flex-shrink-0">
                            <span class="badge bg-light text-secondary border"><?= (float)$q['points'] ?> т.</span>
                            
                            <?php if ($studentAns): ?>
                                <div class="mt-1 badge bg-<?= $isCorrect ? 'success' : 'danger' ?>-subtle text-<?= $isCorrect ? 'success' : 'danger' ?>">
                                    <?= $isCorrect ? '+'.(float)$studentAns['score_awarded'] : '0' ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($q['media_url'])): ?>
                        <div class="mb-4 text-center">
                            <img src="<?= htmlspecialchars($q['media_url']) ?>" alt="Media" class="img-fluid rounded-3 shadow-sm border" style="max-height: 300px;">
                        </div>
                    <?php endif; ?>

                    <div class="bg-body-secondary bg-opacity-25 rounded-3 p-3">
                        <?php if (in_array($q['qtype'], ['single_choice','true_false'], true)): ?>
                            <?php foreach ($qAnswers as $a): 
                                $isSelected = $studentAns && (string)$studentAns['selected_option_ids'] === (string)$a['id'];
                                $isThisCorrect = (int)$a['is_correct'] === 1;
                            ?>
                                <div class="d-flex align-items-center gap-2 mb-2 last-mb-0 p-2 rounded-2 <?= $isSelected ? ($isThisCorrect && $show_keys ? 'bg-success bg-opacity-10' : ($isCorrect ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10')) : '' ?>">
                                    
                                    <?php if ($show_keys): ?>
                                        <?php if ($isThisCorrect): ?>
                                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                        <?php elseif ($isSelected): ?>
                                            <i class="bi bi-x-circle-fill text-danger fs-5"></i>
                                        <?php else: ?>
                                            <i class="bi bi-circle text-muted opacity-25"></i>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <i class="bi bi-<?= $isSelected ? 'record-circle-fill text-primary' : 'circle text-muted' ?>"></i>
                                    <?php endif; ?>

                                    <span class="<?= $isSelected ? 'fw-medium' : '' ?>"><?= htmlspecialchars($a['content']) ?></span>
                                    
                                    <?php if ($isSelected): ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary ms-auto">Вашият избор</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                        <?php elseif ($q['qtype'] === 'multiple_choice'): ?>
                            <?php 
                                $sels = $studentAns && $studentAns['selected_option_ids'] ? array_map('intval', explode(',', $studentAns['selected_option_ids'])) : []; 
                            ?>
                            <?php foreach ($qAnswers as $a): 
                                $isSelected = in_array((int)$a['id'], $sels, true);
                                $isThisCorrect = (int)$a['is_correct'] === 1;
                            ?>
                                <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded-2 <?= $isSelected ? 'bg-light' : '' ?>">
                                    <?php if ($show_keys): ?>
                                        <i class="bi <?= $isThisCorrect ? 'bi-check-square-fill text-success' : ($isSelected ? 'bi-x-square-fill text-danger' : 'bi-square text-muted opacity-25') ?>"></i>
                                    <?php else: ?>
                                        <i class="bi <?= $isSelected ? 'bi-check-square-fill text-primary' : 'bi-square text-muted' ?>"></i>
                                    <?php endif; ?>
                                    <span class="<?= $isSelected ? 'fw-medium' : '' ?>"><?= htmlspecialchars($a['content']) ?></span>
                                </div>
                            <?php endforeach; ?>

                        <?php elseif ($q['qtype'] === 'short_answer' || $q['qtype'] === 'numeric'): ?>
                            <div class="mb-2">
                                <span class="text-muted small text-uppercase fw-bold">Вашият отговор:</span>
                                <div class="p-2 border rounded-2 bg-white mt-1 <?= $isCorrect ? 'border-success text-success bg-success bg-opacity-10' : 'border-danger text-danger bg-danger bg-opacity-10' ?>">
                                    <?= htmlspecialchars($q['qtype']==='numeric' ? $studentAns['numeric_value'] : $studentAns['free_text'] ?? '(няма отговор)') ?>
                                </div>
                            </div>
                            <?php if ($show_keys && !$isCorrect): ?>
                                <div class="mt-2 text-success small">
                                    <i class="bi bi-check-circle me-1"></i> Правилен отговор: 
                                    <strong><?= htmlspecialchars($qAnswers[0]['content'] ?? '—') ?></strong>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($show_keys && !empty($q['explanation'])): ?>
                            <div class="mt-3 pt-3 border-top border-secondary border-opacity-10 text-muted small">
                                <i class="bi bi-lightbulb me-1 text-warning"></i> <strong>Обяснение:</strong> <?= htmlspecialchars($q['explanation']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
</body>
</html>
