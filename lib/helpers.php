<?php

/**
 * Calculate percentage safely.
 */
function percent($score, $max)
{
    if ($score === null || $max === null || $max <= 0) {
        return null;
    }
    return round(($score / $max) * 100, 2);
}

/**
 * Determine grade from percentage.
 * 6: 90-100%
 * 5: 80-89%
 * 4: 65-79%
 * 3: 50-64%
 * 2: 0-49%
 */
function grade_from_percent(?float $percent): ?int
{
    if ($percent === null) {
        return null;
    }
    if ($percent >= 90) return 6;
    if ($percent >= 80) return 5;
    if ($percent >= 65) return 4;
    if ($percent >= 50) return 3;
    return 2;
}

/**
 * Get CSS class for a grade.
 */
function get_grade_color_class(?int $grade): string
{
    if ($grade === null) return 'secondary';
    return match ($grade) {
        6 => 'success',
        5 => 'primary',
        4 => 'info',
        3 => 'warning',
        2 => 'danger',
        default => 'secondary',
    };
}

/**
 * Format a date for display.
 */
function format_date($date, $format = 'd.m.Y H:i')
{
    if (!$date) return '—';
    return date($format, strtotime($date));
}

/**
 * Ensure user is logged in and has the required role.
 */
function require_role(string $role)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== $role) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Get current user or null.
 */
function current_user()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return $_SESSION['user'] ?? null;
}

/**
 * Normalize filter datetime input.
 */
function normalize_filter_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 16) {
        $value .= ':00';
    }
    return $value;
}

/**
 * Initialize or fix attempts_grade view/table logic if needed (placeholder).
 */
function ensure_attempts_grade($pdo) {
    // Logic from original dashboard.php can go here if it needs to run globally
}

/**
 * Ensure subjects scope logic if needed (placeholder).
 */
function ensure_subjects_scope($pdo) {
    // Logic from original dashboard.php can go here
}
