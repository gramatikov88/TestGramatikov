<?php
require_once __DIR__ . '/config.php';
$pdo = db();
$stmt = $pdo->query('DESCRIBE tests');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
