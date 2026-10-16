<?php
// embed-quiz.php — accepts the exact same quiz-selection query params as
// quiz.php (lang, type, books[]/book, chapters[]/chapter, topic, character,
// place, difficulty[], set_size, timed/time_unit/time_value), sanitizes them
// the same way quiz.php itself does, and redirects straight into
// quiz.php?...&embed=1 — skipping the setup form entirely. This is the
// "Embed Quiz" counterpart to embed.php's "Embed App" (which embeds the full
// setup form instead of one pre-configured quiz). Unrecognized params
// (e.g. the Settings-for-Competitions fields, which only ever applied to
// Share Quiz) are simply dropped, not forwarded.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/counter.php';

$languages = quizDiscoverLanguages();
$lang = str_replace(["\0", '/', '\\'], '', (string) ($_GET['lang'] ?? $languages[0]));
if (!in_array($lang, $languages, true)) {
    $lang = $languages[0];
}

$quizTypes = quizTypeOptions($lang);
$type = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['type'] ?? 'random')));
if (!isset($quizTypes[$type])) {
    $type = 'random';
}

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
}

$bookKeys = [];
if (isset($_GET['books'])) {
    $rawBooks = $_GET['books'];
    $bookKeys = is_array($rawBooks) ? $rawBooks : [$rawBooks];
} elseif (!empty($_GET['book'])) {
    $bookKeys = [(string) $_GET['book']];
}
$bookKeys = array_values(array_unique(array_filter(array_map(static function ($b) {
    $digits = preg_replace('/[^0-9]/', '', (string) $b);
    if ($digits === '') {
        return '';
    }
    $key = str_pad($digits, 2, '0', STR_PAD_LEFT);
    return preg_match('/^\d{2}$/', $key) ? $key : '';
}, $bookKeys))));
sort($bookKeys, SORT_NATURAL);

$chapterKeys = [];
if (isset($_GET['chapters'])) {
    $rawChapters = $_GET['chapters'];
    $chapterKeys = is_array($rawChapters) ? $rawChapters : [$rawChapters];
} elseif (!empty($_GET['chapter'])) {
    $chapterKeys = [(string) $_GET['chapter']];
}
$chapterKeys = array_values(array_unique(array_filter(array_map(static function ($ch) {
    $ch = preg_replace('/[^0-9\-]/', '', (string) $ch);
    return preg_match('/^\d{2}-\d{3}$/', $ch) ? $ch : '';
}, $chapterKeys))));
sort($chapterKeys, SORT_NATURAL);

$difficultyOptions = quizDifficultyOptions($lang);
$rawDifficulty = $_GET['difficulty'] ?? [];
$rawDifficulty = is_array($rawDifficulty) ? $rawDifficulty : [$rawDifficulty];
$difficultyKeys = array_values(array_intersect(array_keys($difficultyOptions), $rawDifficulty));

$topic = (string) ($_GET['topic'] ?? '');
$character = (string) ($_GET['character'] ?? '');
$place = (string) ($_GET['place'] ?? '');

$redirectParams = [
    'lang' => $lang,
    'type' => $type,
    'set_size' => $setSize,
    'embed' => '1',
];
if ($timed) {
    $redirectParams['timed'] = '1';
    $redirectParams['time_unit'] = $timeUnit;
    $redirectParams['time_value'] = $timeValue;
}
if (($type === 'book' || $type === 'chapter') && $bookKeys !== []) {
    $redirectParams['books'] = $bookKeys;
}
if ($type === 'chapter' && $chapterKeys !== []) {
    $redirectParams['chapters'] = $chapterKeys;
}
if ($type === 'topic' && $topic !== '') {
    $redirectParams['topic'] = $topic;
}
if ($type === 'character' && $character !== '') {
    $redirectParams['character'] = $character;
}
if ($type === 'place' && $place !== '') {
    $redirectParams['place'] = $place;
}
if ($difficultyKeys !== []) {
    $redirectParams['difficulty'] = $difficultyKeys;
}

header('Location: quiz.php?' . http_build_query($redirectParams));
exit;
