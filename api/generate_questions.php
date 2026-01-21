<?php
/**
 * API Endpoint: Generate Questions (LOCAL LOGIC VERSION)
 * 
 * Since external APIs (Gemini) are returning Quota/404 errors,
 * this version implements a "Smart Local Heuristic" to generate
 * questions directly from the text without external calls.
 * 
 * It ensures the user gets questions INSTANTLY and RELIABLY.
 * 
 * Logic:
 * 1. Analyzes text structure (sentences, key terms).
 * 2. Generates True/False questions based on real sentences.
 * 3. Generates "Complete the sentence" questions.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// 1. Auth Check
session_start();
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Input
$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');

if (empty($text)) {
    echo json_encode(['status' => 'error', 'message' => 'Empty text provided']);
    exit;
}

try {
    $questions = generateLocalQuestions($text);
    // Simulate thinking time for effect (optional, but good for UX)
    usleep(800000); // 0.8s
    echo json_encode(['status' => 'success', 'questions' => $questions]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * The "Local Brain" Logic
 */
function generateLocalQuestions(string $text): array
{
    // Remove extra spaces/newlines
    $cleanText = preg_replace('/\s+/', ' ', $text);

    // Split into sentences (simple regex for . ! ?)
    $sentences = preg_split('/(?<=[.?!])\s+(?=[A-ZА-Я])/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);

    // Filter sentences that are too short to be useful
    $sentences = array_filter($sentences, function ($s) {
        return mb_strlen($s, 'UTF-8') > 15;
    });

    // Shuffle to get random parts of text
    shuffle($sentences);

    $questions = [];
    $maxQuestions = 3;

    // --- Strategy 1: "True/False" (Verifying information) ---
    // Take a real sentence -> True.
    if (count($sentences) > 0) {
        $s = array_shift($sentences);
        // Sometimes just take the sentence as is
        $questions[] = [
            'content' => 'Вярно ли е следното твърдение според текста: "' . trim($s) . '"',
            'type' => 'true_false',
            'points' => 1,
            'answers' => [
                ['content' => 'Вярно', 'is_correct' => 1],
                ['content' => 'Грешно', 'is_correct' => 0]
            ]
        ];
    }

    // --- Strategy 2: "Complete the Sentence" (Single Choice) ---
    if (count($sentences) > 0) {
        $s = array_shift($sentences);
        $words = explode(' ', trim($s));

        // Only if sentence is long enough (e.g., > 5 words)
        if (count($words) >= 5) {
            // Remove last 2-3 words to make a "gap"
            $cutCount = 2;
            if (count($words) > 10)
                $cutCount = 3;

            $hiddenPart = implode(' ', array_slice($words, -$cutCount));
            $visiblePart = implode(' ', array_slice($words, 0, count($words) - $cutCount));

            // Wrong answers logic
            $wrong1 = "не се споменава";
            $wrong2 = "грешна информация";

            $questions[] = [
                'content' => 'Довършете изречението от текста: "' . $visiblePart . ' ..."',
                'type' => 'single',
                'points' => 1,
                'answers' => [
                    ['content' => $hiddenPart, 'is_correct' => 1],
                    ['content' => 'информацията липсва', 'is_correct' => 0],
                    ['content' => 'друго', 'is_correct' => 0]
                ]
            ];
        }
    }

    // --- Strategy 3: "Keyword Context" (Fallback) ---
    // If we ran out of long sentences, or just to add variety.
    // Check if text mentions "SDLC" or "софтуер" (based on user's known text) purely heuristically?
    // No, let's look for capitalized words (terms).

    // Extract capitalized words (potential terms)
    preg_match_all('/\b[A-ZА-Я]{2,}\b/u', $text, $matches);
    $terms = array_unique($matches[0] ?? []);

    if (count($terms) > 0) {
        $term = $terms[array_rand($terms)];
        $questions[] = [
            'content' => 'Текстът обсъжда понятието/термина "' . $term . '".',
            'type' => 'true_false',
            'points' => 1,
            'answers' => [
                ['content' => 'Вярно', 'is_correct' => 1],
                ['content' => 'Грешно', 'is_correct' => 0]
            ]
        ];
    } else {
        // Generic fallback question if no terms found
        $questions[] = [
            'content' => 'Основната цел на този текст е информативна.',
            'type' => 'true_false',
            'points' => 1,
            'answers' => [
                ['content' => 'Вярно', 'is_correct' => 1],
                ['content' => 'Грешно', 'is_correct' => 0]
            ]
        ];
    }

    return $questions;
}
