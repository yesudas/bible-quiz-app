<?php

require_once __DIR__ . '/../includes/pdf-temp-store.php';

$file = (string) ($_GET['file'] ?? '');
$path = quizPdfTempResolve($file);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$name = (string) ($_GET['name'] ?? '');
$name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $name);
if ($name === '' || !str_ends_with(strtolower($name), '.pdf')) {
    $name = 'bible-quiz.pdf';
}

// Same header set as the files-browser-app's proven-working download.php —
// the plain quoted filename alone wasn't enough to get Kodular's WebView to
// treat this as a real download, so this matches it header-for-header:
// filename*=UTF-8''... (RFC 6266) alongside the ASCII filename=, plus the
// legacy Content-Description/Content-Transfer-Encoding/Expires/Pragma
// combination old download scripts carry for exactly this kind of
// picky-client compatibility.
$encodedName = rawurlencode($name);
$contentDisposition = 'attachment;'
    . ' filename="' . addslashes($name) . '";'
    . ' filename*=UTF-8\'\'' . $encodedName;

header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $contentDisposition);
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');

if (ob_get_level() > 0) {
    ob_clean();
}
flush();
readfile($path);
