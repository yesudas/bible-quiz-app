<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/quiz-share.php';

$lang = (string) ($_GET['lang'] ?? 'English');
$lang = str_replace(["\0", '/', '\\'], '', $lang);

$type = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['type'] ?? 'random')));

$setSize = (int) ($_GET['set_size'] ?? 10);
if ($setSize < 1) {
    $setSize = 1;
}
if ($setSize > 100) {
    $setSize = 100;
}

$timed = isset($_GET['timed']) && $_GET['timed'] === '1';
$timeUnit = ($_GET['time_unit'] ?? 'seconds') === 'minutes' ? 'minutes' : 'seconds';
$timeValue = (int) ($_GET['time_value'] ?? 0);
if ($timeValue < 1) {
    $timed = false;
    $timeValue = 0;
}

$booksRaw = $_GET['books'] ?? ($_GET['book'] ?? []);
$booksRaw = is_array($booksRaw) ? $booksRaw : [$booksRaw];
$books = [];
foreach ($booksRaw as $b) {
    $n = (int) preg_replace('/[^0-9]/', '', (string) $b);
    if ($n >= 1 && $n <= 66) {
        $books[] = $n;
    }
}
$books = array_values(array_unique($books));

$chaptersRaw = $_GET['chapters'] ?? ($_GET['chapter'] ?? []);
$chaptersRaw = is_array($chaptersRaw) ? $chaptersRaw : [$chaptersRaw];
$chapters = [];
foreach ($chaptersRaw as $c) {
    $c = (string) $c;
    if (preg_match('/^\d{2}-(\d{1,3})$/', $c, $m)) {
        $chapters[] = (int) $m[1];
    } elseif (preg_match('/^\d{1,3}$/', $c)) {
        $chapters[] = (int) $c;
    }
}
$chapters = array_values(array_unique($chapters));

$topic = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', (string) ($_GET['topic'] ?? ''));
$character = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', (string) ($_GET['character'] ?? ''));
$place = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', (string) ($_GET['place'] ?? ''));

$difficultyRaw = $_GET['difficulty'] ?? [];
$difficultyRaw = is_array($difficultyRaw) ? $difficultyRaw : [$difficultyRaw];
$difficulty = array_values(array_intersect(
    array_map(static fn ($d) => strtolower((string) $d), $difficultyRaw),
    quizDifficultyKeys()
));

$languages = quizDiscoverLanguages();
if (!in_array($lang, $languages, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown language.']);
    exit;
}

$types = quizTypeOptions();
if (!isset($types[$type])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown quiz type.']);
    exit;
}

if ($type === 'book' && $books === []) {
    http_response_code(400);
    echo json_encode(['error' => 'Select at least one book to share this quiz.']);
    exit;
}
if ($type === 'chapter' && $books === []) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a book to share this quiz.']);
    exit;
}
if ($type === 'topic' && $topic === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Select a topic to share this quiz.']);
    exit;
}
if ($type === 'character' && $character === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Select a character to share this quiz.']);
    exit;
}
if ($type === 'place' && $place === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Select a place to share this quiz.']);
    exit;
}

try {
    $token = quizShareEncode([
        'lang' => $lang,
        'type' => $type,
        'set_size' => $setSize,
        'timed' => $timed,
        'time_unit' => $timeUnit,
        'time_value' => $timeValue,
        'books' => $books,
        'chapters' => $chapters,
        'topic' => $topic,
        'character' => $character,
        'place' => $place,
        'difficulty' => $difficulty,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not create a shareable link for this selection.']);
    exit;
}

echo json_encode(['token' => $token]);
