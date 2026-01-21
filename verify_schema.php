<?php
/**
 * Verification Script for Smart Engine Schema
 * Checks if 'access_token' column exists in 'assignment_classes'.
 */
require_once __DIR__ . '/config.php';

echo "Running Verification...\n";
$pdo = db();

// Check 1: access_token column
$stmt = $pdo->query("DESCRIBE assignment_classes");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (in_array('access_token', $columns)) {
    echo "[PASS] Column 'access_token' exists in assignment_classes.\n";
} else {
    echo "[FAIL] Column 'access_token' is MISSING in assignment_classes.\n";
}

// Check 2: Index exists (Optional but good)
// Usually 'SHOW INDEX'
$stmt = $pdo->query("SHOW INDEX FROM assignment_classes WHERE Key_name = 'idx_access_token'");
if ($stmt->fetch()) {
    echo "[PASS] Index 'idx_access_token' exists.\n";
} else {
    echo "[WARN] Index 'idx_access_token' might be missing (not critical but recommended).\n";
}

echo "Verification Complete.\n";
