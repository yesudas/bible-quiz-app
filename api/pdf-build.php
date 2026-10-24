<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/pdf-prebuilt.php';
require_once __DIR__ . '/../includes/pdf-temp-store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$lang = str_replace(["\0", '/', '\\'], '', (string) ($input['lang'] ?? ''));
$type = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($input['type'] ?? '')));
$headerText = trim((string) ($input['header'] ?? '')) ?: 'Bible Quiz';
$subLines = [
    trim((string) ($input['sub1'] ?? '')),
    trim((string) ($input['sub2'] ?? '')),
    trim((string) ($input['sub3'] ?? '')),
];

$selection = [
    'books' => is_array($input['books'] ?? null) ? $input['books'] : [],
    'chapters' => is_array($input['chapters'] ?? null) ? $input['chapters'] : [],
    'topic' => (string) ($input['topic'] ?? ''),
    'character' => (string) ($input['character'] ?? ''),
    'place' => (string) ($input['place'] ?? ''),
];

$paths = quizResolvePrebuiltPaths($lang, $type, $selection);
if ($paths === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Please select exactly one book, chapter, topic, character, or place (or a whole-Bible/Old-Testament/New-Testament/Kids/Teens/Adults type) for PDF download.']);
    exit;
}

quizPdfTempCleanup();

try {
    $questionsBytes = quizStampPdfHeaderAndFooters($paths['questions'], $headerText, $subLines);
    $answersBytes = quizStampPdfHeaderAndFooters($paths['answers'], $headerText, $subLines);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'questionsFile' => quizPdfTempSave($questionsBytes),
    'answersFile' => quizPdfTempSave($answersBytes),
]);
