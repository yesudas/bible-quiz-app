<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/pdf-temp-store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// A standard multipart file upload (not a raw application/pdf request
// body) — some hosts' WAF/ModSecurity rules block an unusual raw-body
// Content-Type on a POST to a .php file (seen in practice as a 406 "Not
// Acceptable" before this script ever runs), whereas a normal file upload
// field is universally allowed.
if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty or invalid upload']);
    exit;
}

// Comfortably above any real quiz PDF, well under typical upload_max_filesize —
// just a sanity cap against a stray/abusive oversized request.
if ($_FILES['pdf']['size'] > 8 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'File too large']);
    exit;
}

$bytes = file_get_contents($_FILES['pdf']['tmp_name']);
if ($bytes === false || $bytes === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty upload']);
    exit;
}

quizPdfTempCleanup();
$filename = quizPdfTempSave($bytes);

echo json_encode(['file' => $filename]);
