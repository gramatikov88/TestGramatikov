<?php
$templatePath = __DIR__ . "../templates/questions_template.xlsx";

if (!is_file($templatePath) || !is_readable($templatePath)) {
    http_response_code(404);
    echo "Template not found.";
    exit;
}

$size = filesize($templatePath);
if ($size === false) {
    http_response_code(500);
    echo "Server error.";
    exit;
}

$handle = fopen($templatePath, "rb");
if ($handle === false) {
    http_response_code(500);
    echo "Server error.";
    exit;
}

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"questions_template.xlsx\"");
header("Content-Length: " . $size);
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");

fpassthru($handle);
fclose($handle);
exit;
