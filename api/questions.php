<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['paths']) || !is_array($input['paths'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$dataRoot = realpath(__DIR__ . '/../data');
if ($dataRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'data/ missing']);
    exit;
}

$questions = [];
foreach ($input['paths'] as $rel) {
    if (!is_string($rel)) {
        continue;
    }
    $rel = str_replace('\\', '/', $rel);
    if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
        continue;
    }
    $full = realpath($dataRoot . '/' . $rel);
    if ($full === false || !str_starts_with($full, $dataRoot . DIRECTORY_SEPARATOR)) {
        continue;
    }
    if (strtolower(pathinfo($full, PATHINFO_EXTENSION)) !== 'json') {
        continue;
    }
    $raw = file_get_contents($full);
    if ($raw === false) {
        continue;
    }
    $q = json_decode($raw, true);
    if (is_array($q)) {
        $q['_path'] = str_replace('\\', '/', substr($full, strlen($dataRoot) + 1));
        $questions[] = $q;
    }
}

echo json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
