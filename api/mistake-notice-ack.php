<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/mistake-notices.php';
require_once __DIR__ . '/../includes/google-auth-client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Identity is the server-side Google session only — a player can only clear their own notices.
$user = quizGoogleUser();
$email = $user ? strtolower((string) ($user['email'] ?? '')) : '';
if ($email === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in.']);
    exit;
}

quizNoticeClear($email);
echo json_encode(['ok' => true]);
