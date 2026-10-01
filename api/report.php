<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/mistake-reports.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$path = is_array($input) && is_string($input['path'] ?? null) ? $input['path'] : '';
$type = is_array($input) && is_string($input['type'] ?? null) ? $input['type'] : '';

if (quizResolveQuestionPath($path) === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Question not found.']);
    exit;
}

if ($path === '' || !isset(QUIZ_MISTAKE_TYPES[$type])) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose what the mistake is in.']);
    exit;
}

if (!quizMistakeAdd($path, $type)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save your report.']);
    exit;
}

echo json_encode(['ok' => true]);
