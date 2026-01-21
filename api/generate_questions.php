<?php
/**
 * API Endpoint: Generate Questions (Enhanced Local Logic)
 * 
 * Features:
 * - Fill-in-the-blank generation (contextual)
 * - Definition extration (What is X?)
 * - Configurable count
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// 1. Auth & Input
session_start();
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');
$requestedCount = (int) ($input['count'] ?? 3);
if ($requestedCount < 1)
    $requestedCount = 1;
if ($requestedCount > 20)
    $requestedCount = 20;

if (empty($text)) {
    echo json_encode(['status' => 'error', 'message' => 'Empty text provided']);
    exit;
}

try {
    // Simulate thinking (UX)
    usleep(500000);

    $questions = generateSmartQuestions($text, $requestedCount);
    echo json_encode(['status' => 'success', 'questions' => $questions]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Smart Local Logic Engine
 */
function generateSmartQuestions(string $text, int $limit): array
{
    // Clean text
    $cleanText = preg_replace('/\s+/', ' ', $text);

    // Split sentences more robustly
    // Look for punctuation followed by space and capital letter (or end of string)
    preg_match_all('/[^.!?]+[.!?]+/', $cleanText, $matches);
    $sentences = $matches[0] ?? [];

    // Clean each sentence
    $sentences = array_map('trim', $sentences);
    // Filter useful ones (> 20 chars, has spaces)
    $sentences = array_filter($sentences, fn($s) => mb_strlen($s) > 20 && substr_count($s, ' ') > 3);
    shuffle($sentences);

    $questions = [];
    $usedSentences = [];

    // --- Strategy 1: "Gap Fill" (Cloze Deletion) - Priority ---
    // Takes a sentence, hides a key term (capitalized or long word).
    foreach ($sentences as $k => $s) {
        if (count($questions) >= $limit)
            break;
        if (in_array($s, $usedSentences))
            continue;

        $q = tryCreateGapFill($s);
        if ($q) {
            $questions[] = $q;
            $usedSentences[] = $s;
            unset($sentences[$k]); // Remove to avoid reuse
        }
    }

    // --- Strategy 2: "What is X?" (Definition Extraction) ---
    // Looks for "X is Y", "X represents Y".
    // Reset index for remaining sentences
    $sentences = array_values($sentences);
    foreach ($sentences as $k => $s) {
        if (count($questions) >= $limit)
            break;
        if (in_array($s, $usedSentences))
            continue;

        $q = tryCreateDefinitionQuestion($s);
        if ($q) {
            $questions[] = $q;
            $usedSentences[] = $s;
            unset($sentences[$k]);
        }
    }

    // --- Strategy 3: True/False (Fallback) ---
    // If we still need questions
    $sentences = array_values($sentences);
    foreach ($sentences as $k => $s) {
        if (count($questions) >= $limit)
            break;

        $isTrue = rand(0, 1) === 1;
        $content = $s;

        // If we want a False question, we need to subtly break the sentence (hard to do locally)
        // OR just present a random other sentence as "Is this derived from...?" (confusing)
        // Safest Local Logic: Present exact sentence -> TRUE.
        // Modified sentence logic is risky without NLP.

        // Let's stick to "Is this statement correct regarding the text?" -> True
        // Or pick a random sentence from another text? (Not available).
        // Let's just make it True for now or skip logic.

        $questions[] = [
            'content' => 'Вярно ли е твърдението: "' . $s . '"',
            'type' => 'true_false',
            'points' => 1,
            'answers' => [
                ['content' => 'Вярно', 'is_correct' => 1],
                ['content' => 'Грешно', 'is_correct' => 0]
            ]
        ];
    }

    // Final Shuffle of Answers for 'single'/'multiple' types
    foreach ($questions as &$q) {
        if (in_array($q['type'], ['single', 'multiple'])) {
            shuffle($q['answers']);
        }
    }
    unset($q);

    return array_slice($questions, 0, $limit);
}

function tryCreateGapFill(string $sentence): ?array
{
    $words = explode(' ', $sentence);
    // Find candidate words to hide (Longer than 4 chars, preferably capitalized if not first word, or just long)
    $candidates = [];
    foreach ($words as $i => $w) {
        $cleanW = preg_replace('/[^a-zA-Zа-яА-Я0-9]/u', '', $w);
        if (mb_strlen($cleanW) > 5) {
            $candidates[] = $i;
        }
    }

    if (empty($candidates))
        return null;

    // Pick one
    $hideIdx = $candidates[array_rand($candidates)];
    $answer = preg_replace('/[^a-zA-Zа-яА-Я0-9\-]/u', '', $words[$hideIdx]); // Keep clean answer

    // Replace in sentence
    $words[$hideIdx] = '_______';
    $questionText = implode(' ', $words);

    // Generate wrong answers (random words from same sentence or generic)
    $wrongs = ['(липсваща дума)', 'друго', 'неизвестно'];

    return [
        'content' => $questionText,
        'type' => 'single',
        'points' => 1,
        'answers' => [
            ['content' => $answer, 'is_correct' => 1],
            ['content' => 'грешен отговор', 'is_correct' => 0], // Generic placeholders are weak pt of local logic
            ['content' => 'друго понятие', 'is_correct' => 0]
        ]
    ];
}

function tryCreateDefinitionQuestion(string $sentence): ?array
{
    // Regex for "X е Y" or "X представлява Y"
    // Limitations: structure can be complex.
    // Try simple split by " е " (is)
    if (strpos($sentence, ' е ') !== false) {
        $parts = explode(' е ', $sentence, 2);
        $term = trim($parts[0]);
        $def = trim($parts[1]);

        // Only if term is short (likely a concept) and def is resonable
        if (mb_strlen($term) < 30 && mb_strlen($def) > 10) {
            // Check if term starts with capital
            if (preg_match('/^[A-ZА-Я]/u', $term)) {
                return [
                    'content' => 'Какво е определението за: ' . $term . '?',
                    'type' => 'single',
                    'points' => 1,
                    'answers' => [
                        ['content' => $def, 'is_correct' => 1],
                        ['content' => 'Не се споменава в текста', 'is_correct' => 0],
                        ['content' => 'Друго понятие', 'is_correct' => 0]
                    ]
                ];
            }
        }
    }
    return null;
}
