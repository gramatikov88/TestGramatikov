<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php'; // Ensure helpers are loaded

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    die('Access denied');
}

// Must be a POST request with a valid CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}
csrf_verify();

$user = $_SESSION['user'];
$attemptId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$returnUrl = isset($_POST['return_url']) ? $_POST['return_url'] : 'dashboard.php';

if ($attemptId <= 0) {
    die('Invalid attempt ID');
}

$pdo = db();

// Check if teacher owns the assignment for this attempt
$stmt = $pdo->prepare('
    SELECT atp.id 
    FROM attempts atp
    JOIN assignments a ON a.id = atp.assignment_id
    WHERE atp.id = :id AND a.assigned_by_teacher_id = :tid
');
$stmt->execute([':id' => $attemptId, ':tid' => $user['id']]);

if (!$stmt->fetch()) {
    die('You do not have permission to delete this attempt.');
}

// Delete
$del = $pdo->prepare('DELETE FROM attempts WHERE id = :id');
$del->execute([':id' => $attemptId]);

// Redirect
header('Location: ' . sanitize_redirect_path($returnUrl));
exit;
