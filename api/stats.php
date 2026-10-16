<?php

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../includes/quiz-stats.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Cache-Control: no-store');

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$lang = (string) ($input['lang'] ?? '');
if (!in_array($lang, quizDiscoverLanguages(), true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid language.']);
    exit;
}

$totalQuestions = (int) ($input['totalQuestions'] ?? -1);
if ($totalQuestions < 1 || $totalQuestions > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid question count.']);
    exit;
}

$rawBookCounts = is_array($input['bookCounts'] ?? null) ? $input['bookCounts'] : [];
$bookCounts = [];
foreach ($rawBookCounts as $book => $count) {
    $bookNo = (int) $book;
    $count = (int) $count;
    if ($bookNo >= 1 && $bookNo <= 66 && $count > 0) {
        $bookCounts[(string) $bookNo] = $count;
    }
}

// The set's own question count is the source of truth for "questions
// answered" — book counts are a breakdown of that same set and are capped
// to it so a tampered request can't inflate the by-book totals beyond what
// the set total allows.
if (array_sum($bookCounts) > $totalQuestions) {
    http_response_code(400);
    echo json_encode(['error' => 'Book counts exceed the question count.']);
    exit;
}

quizIncrementQuizCompleted($lang);
quizIncrementQuestionsAnswered($lang, $totalQuestions);
if ($bookCounts) {
    quizIncrementQuestionsByBook($lang, $bookCounts);
}

echo json_encode(['ok' => true]);
