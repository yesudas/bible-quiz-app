<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/quiz-i18n.php';
require_once __DIR__ . '/../includes/quiz-leaderboard.php';

$lang = $_GET['lang'] ?? 'English';
$lang = str_replace(["\0", '/', '\\'], '', $lang);
if ($lang === '' || $lang[0] === '.') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid language']);
    exit;
}

$file = __DIR__ . '/../index/' . $lang . '/catalog.json';
if (!is_file($file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Index not found. Run i.php from the admin panel.']);
    exit;
}

$raw = file_get_contents($file);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot read catalog']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'Corrupt catalog']);
    exit;
}

// Lets the client switch languages via AJAX (re-fetching just this catalog)
// instead of a full page reload, while still showing quiz type / difficulty
// labels translated into the newly selected language.
$data['labels'] = [
    'language' => quizLanguageDisplayName($lang),
    'types' => quizTypeOptions($lang),
    'difficulties' => quizDifficultyOptions($lang),
    'chapterWord' => quizDetailLabels($lang)['chapter'],
    'leaderboard' => [
        'pageTitle' => quizLeaderboardLabels($lang)['pageTitle'],
        'showAll' => quizLeaderboardLabels($lang)['showAll'],
    ],
];

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
