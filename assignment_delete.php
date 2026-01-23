<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/helpers.php';

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    die('Access denied');
}

$user = $_SESSION['user'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die('Invalid ID');
}

$pdo = db();

// Check ownership
$stmt = $pdo->prepare('SELECT id FROM assignments WHERE id = :id AND assigned_by_teacher_id = :tid');
$stmt->execute([':id' => $id, ':tid' => $user['id']]);

if (!$stmt->fetch()) {
    die('Not your assignment');
}

// Delete (cascade should handle attempts if FK configured, but let's be safe)
// Based on schema, attempts usually have FK. If not, we might need manual cleanup.
// Assuming ON DELETE CASCADE layout or simple delete.
$pdo->prepare('DELETE FROM assignments WHERE id = :id')->execute([':id' => $id]);

header('Location: dashboard.php');
exit;
