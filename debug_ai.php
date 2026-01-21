<?php
require_once __DIR__ . '/config.php';

$key = AI_API_KEY;
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=$key";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if (isset($data['models'])) {
    foreach ($data['models'] as $m) {
        $name = str_replace('models/', '', $m['name']);
        if (strpos($name, 'gemini') !== false) {
            echo $name . "\n";
        }
    }
} else {
    echo "NO MODELS FOUND or Error: " . $response;
}
