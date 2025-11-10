<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? null) !== 'teacher') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];
$pdo = db();

$action = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? 'dashboard.php';
if (!$redirect || stripos($redirect, '://') !== false) {
    $redirect = 'dashboard.php';
}

$success = null;
$error = null;

$teacherId = (int) $user['id'];

try {
    switch ($action) {
        case 'delete_class':
            $classId = (int) ($_POST['class_id'] ?? 0);
            if ($classId > 0) {
                $stmt = $pdo->prepare('DELETE FROM classes WHERE id = :id AND teacher_id = :tid');
                $stmt->execute([':id' => $classId, ':tid' => $teacherId]);
                if ($stmt->rowCount()) {
                    $success = 'Класът беше изтрит.';
                } else {
                    $error = 'Неуспешно изтриване на класа.';
                }
            } else {
                $error = 'Невалиден клас.';
            }
            break;

        case 'delete_test':
            $testId = (int) ($_POST['test_id'] ?? 0);
            if ($testId > 0) {
                $stmt = $pdo->prepare('DELETE FROM tests WHERE id = :id AND owner_teacher_id = :tid');
                $stmt->execute([':id' => $testId, ':tid' => $teacherId]);
                if ($stmt->rowCount()) {
                    $success = 'Тестът беше изтрит.';
                } else {
                    $error = 'Неуспешно изтриване на теста.';
                }
            } else {
                $error = 'Невалиден тест.';
            }
            break;

        case 'delete_assignment':
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            if ($assignmentId > 0) {
                $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = :id AND assigned_by_teacher_id = :tid');
                $stmt->execute([':id' => $assignmentId, ':tid' => $teacherId]);
                if ($stmt->rowCount()) {
                    $success = 'Заданието беше изтрито.';
                } else {
                    $error = 'Неуспешно изтриване на заданието.';
                }
            } else {
                $error = 'Невалидно задание.';
            }
            break;

        case 'assignment_remove_class':
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $classId = (int) ($_POST['class_id'] ?? 0);
            if ($assignmentId > 0 && $classId > 0) {
                $stmt = $pdo->prepare('DELETE ac FROM assignment_classes ac
                                        JOIN assignments a ON a.id = ac.assignment_id AND a.assigned_by_teacher_id = :tid
                                        JOIN classes c ON c.id = ac.class_id AND c.teacher_id = :tid
                                        WHERE ac.assignment_id = :aid AND ac.class_id = :cid');
                $stmt->execute([
                    ':tid' => $teacherId,
                    ':aid' => $assignmentId,
                    ':cid' => $classId,
                ]);
                if ($stmt->rowCount()) {
                    $success = 'Заданието беше премахнато от класа.';
                } else {
                    $error = 'Неуспешно премахване на заданието от класа.';
                }
            } else {
                $error = 'Невалидни данни за премахване.';
            }
            break;

        default:
            $error = 'Неподдържано действие.';
            break;
    }
} catch (Throwable $e) {
    $error = 'Възникна грешка: ' . $e->getMessage();
}

if ($success) {
    $_SESSION['flash_success'] = $success;
}
if ($error) {
    $_SESSION['flash_error'] = $error;
}

header('Location: ' . $redirect);
exit;
