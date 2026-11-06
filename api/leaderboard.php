<?php

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/quiz-leaderboard.php';
require_once __DIR__ . '/../includes/quiz-achievements.php';
require_once __DIR__ . '/../includes/google-auth-client.php';
require_once __DIR__ . '/../includes/device-info.php';

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

// Achievements login (leaderboard.php): looks a player up by mobile number
// only — no password, this is the "no password, just plain mobile number"
// identification the Achievements section uses. Returns the lifetime score
// (if any row matches) plus the pre-computed star-tier progress, never the
// row's own stored mobile/PII.
if (($input['action'] ?? '') === 'lookup') {
    $countryCode = trim((string) ($input['countryCode'] ?? ''));
    $mobile = trim((string) ($input['mobile'] ?? ''));

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

    $match = quizLeaderboardFindByMobile($countryCode, $mobile);
    if ($match === null) {
        echo json_encode(['ok' => true, 'found' => false]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'found' => true,
        'displayName' => $match['displayName'],
        'correctCount' => $match['correctCount'],
        'totalQuestions' => $match['totalQuestions'],
        'tiers' => quizAchievementProgress($match['correctCount']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Google sign-in join: identity (email) is read from the shared
// wordofgod.in/auth session server-side, never trusted from the request
// body, so a player can only ever submit a score under their own verified
// Google account. The displayName shown on the leaderboard is managed
// entirely server-side now (defaults to the Google profile name on first
// join, changed only via the 'update_display_name' action below) — this
// action no longer takes a displayName from the client at all.
if (($input['action'] ?? '') === 'join_google') {
    $googleUser = quizGoogleUser();
    $email = (string) ($googleUser['email'] ?? '');
    if ($email === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Not signed in with Google.']);
        exit;
    }

    $profileName = trim((string) ($googleUser['name'] ?? ''));
    $correctCount = (int) ($input['correctCount'] ?? -1);
    $totalQuestions = (int) ($input['totalQuestions'] ?? -1);

    if ($profileName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Google profile name.']);
        exit;
    }
    if ($correctCount < 0 || $totalQuestions < 0 || $correctCount > $totalQuestions) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid score.']);
        exit;
    }

    $pictureUrl = (string) ($googleUser['picture'] ?? '');
    $deviceInfo = quizParseUserAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $result = quizLeaderboardSubmitGoogle($email, $profileName, $pictureUrl, $correctCount, $totalQuestions, $deviceInfo);
    if ($result === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not save your leaderboard entry.']);
        exit;
    }

    echo json_encode(['ok' => true] + $result);
    exit;
}

// Player-driven rename, Google sign-in only: changes the leaderboard
// row's displayName (never profileName/email) for whoever is signed in
// right now. 404s (via a null result) if that email has no row yet —
// nothing to rename before the player has joined at least once.
if (($input['action'] ?? '') === 'update_display_name') {
    $googleUser = quizGoogleUser();
    $email = (string) ($googleUser['email'] ?? '');
    if ($email === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Not signed in with Google.']);
        exit;
    }

    $newDisplayName = trim((string) ($input['displayName'] ?? ''));
    if ($newDisplayName === '' || mb_strlen($newDisplayName) > 60) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid display name.']);
        exit;
    }

    $result = quizLeaderboardSetGoogleDisplayName($email, $newDisplayName);
    if ($result === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Join the leaderboard first before renaming.']);
        exit;
    }

    echo json_encode(['ok' => true] + $result);
    exit;
}

// Achievements login via Google (leaderboard.php): same idea as 'lookup',
// but the identity is the signed-in Google session itself, not a typed
// mobile number — no body params needed. Unlike 'lookup', a signed-in
// player with no leaderboard row yet still gets an identity block (just at
// 0 correct answers) rather than a "not found" error, since being signed
// in already proves who they are. Also hands back `email`/`profileName` —
// safe here because it's always the SAME player's own signed-in session,
// never anyone else's — for the edit-name UI's read-only fields.
if (($input['action'] ?? '') === 'lookup_google') {
    $googleUser = quizGoogleUser();
    $email = (string) ($googleUser['email'] ?? '');
    if ($email === '') {
        echo json_encode(['ok' => true, 'found' => false, 'signedIn' => false]);
        exit;
    }

    $match = quizLeaderboardFindByGoogleEmail($email);
    $profileName = (string) ($googleUser['name'] ?? '');
    $displayName = $match['displayName'] ?? $profileName;
    $pictureUrl = $match['pictureUrl'] ?? (string) ($googleUser['picture'] ?? '');
    $correctCount = $match['correctCount'] ?? 0;
    $totalQuestions = $match['totalQuestions'] ?? 0;

    echo json_encode([
        'ok' => true,
        'signedIn' => true,
        'found' => $match !== null,
        'email' => $email,
        'profileName' => $profileName,
        'displayName' => $displayName,
        'pictureUrl' => $pictureUrl,
        'correctCount' => $correctCount,
        'totalQuestions' => $totalQuestions,
        'tiers' => quizAchievementProgress($correctCount),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

$requestIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$result = quizLeaderboardSubmit($displayName, $countryCode, $mobile, $correctCount, $totalQuestions, $requestIp);
if ($result === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save your leaderboard entry.']);
    exit;
}

echo json_encode(['ok' => true] + $result);
