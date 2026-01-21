<?php
/**
 * API Endpoint: Generate Questions via Google Gemini
 * 
 *POST keys:
 * - text: The source text or topic
 * 
 * Returns JSON:
 * { status: 'success', questions: [...] }
 * { status: 'error', message: ... }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// 1. Auth Check (Basic) - In production, check session/user
session_start();
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Validate Input
$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');

if (empty($text)) {
    echo json_encode(['status' => 'error', 'message' => 'Empty text provided']);
    exit;
}

// 3. Check Config
if (!defined('AI_API_KEY') || empty(AI_API_KEY)) {
    echo json_encode(['status' => 'error', 'message' => 'AI_API_KEY is not configured in config.php']);
    exit;
}

// 4. Construct Prompt
$prompt = "
You are a teacher assistant. 
Analyze the following text and generate 3 multiple-choice questions (single choice) and 2 True/False questions based on it.
The output MUST be valid JSON array of objects with this exact structure:
[
  {
    \"content\": \"Question text here?\",
    \"type\": \"single\", 
    \"points\": 1,
    \"answers\": [
       { \"content\": \"Corect Answer\", \"is_correct\": 1 },
       { \"content\": \"Wrong Answer\", \"is_correct\": 0 },
       { \"content\": \"Wrong Answer 2\", \"is_correct\": 0 }
    ]
  },
  {
    \"content\": \"True/False Statement\",
    \"type\": \"true_false\",
    \"points\": 1,
    \"answers\": [
       { \"content\": \"Вярно\", \"is_correct\": 1 },
       { \"content\": \"Грешно\", \"is_correct\": 0 }
    ]
  }
]
IMPORTANT: 
- Language: Bulgarian (BG).
- Strict JSON only. No markdown formatting (```json). No extra text.
- If 'type' is 'single', provide 2 or 3 wrong answers.
- If 'type' is 'true_false', provide 'Вярно' and 'Грешно'.

TEXT TO ANALYZE:
" . substr($text, 0, 8000); // Limit context window

// 5. Call Gemini API
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . AI_MODEL . ':generateContent?key=' . AI_API_KEY;

$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['status' => 'error', 'message' => 'Curl Error: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['status' => 'error', 'message' => 'API Error (' . $httpCode . '): ' . $response]);
    exit;
}

// 6. Parse Response
$jsonResp = json_decode($response, true);
$rawText = $jsonResp['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Cleanup formatting (remove ```json wrappers if Gemini ignores instructions)
$cleanJson = preg_replace('/^```json\s*|\s*```$/', '', trim($rawText));

$questions = json_decode($cleanJson, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Fallback or error log
    echo json_encode(['status' => 'error', 'message' => 'Failed to parse AI response.', 'debug' => $rawText]);
    exit;
}

echo json_encode(['status' => 'success', 'questions' => $questions]);
