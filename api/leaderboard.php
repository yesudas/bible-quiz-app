<?php

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/quiz-leaderboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: public, max-age=60');
    $view = ($_GET['view'] ?? 'top10') === 'all' ? 'all' : 'top10';
    echo json_encode(quizLeaderboardReadPublic($view), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Cache-Control: no-store');

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

$displayName = trim((string) ($input['displayName'] ?? ''));
$countryCode = trim((string) ($input['countryCode'] ?? ''));
$mobile = trim((string) ($input['mobile'] ?? ''));
$correctCount = (int) ($input['correctCount'] ?? -1);
$totalQuestions = (int) ($input['totalQuestions'] ?? -1);

if ($displayName === '' || $mobile === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Both display name and mobile number are required to join the leaderboard.']);
    exit;
}

if (mb_strlen($displayName) > 60) {
    http_response_code(400);
    echo json_encode(['error' => 'Display name is too long.']);
    exit;
}

if (!isset(quizLeaderboardCountries()[$countryCode])) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a valid country code.']);
    exit;
}

if (!preg_match('/^[0-9]{4,15}$/', preg_replace('/[^0-9]/', '', $mobile))) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid mobile number.']);
    exit;
}

if ($correctCount < 0 || $totalQuestions < 0 || $correctCount > $totalQuestions) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid score.']);
    exit;
}

$result = quizLeaderboardSubmit($displayName, $countryCode, $mobile, $correctCount, $totalQuestions);
if ($result === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save your leaderboard entry.']);
    exit;
}

echo json_encode(['ok' => true] + $result);
