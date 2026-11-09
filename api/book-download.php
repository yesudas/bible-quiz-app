<?php

require_once __DIR__ . '/../includes/books-catalog.php';
require_once __DIR__ . '/../includes/book-download-counter.php';

$lang = (string) ($_GET['lang'] ?? '');
$group = ($_GET['group'] ?? '') === 'books' ? 'books' : 'collections';
$stem = (string) ($_GET['file'] ?? '');

$path = quizBookResolvePdf($lang, $group, $stem);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

// Download name = the original file name (WOGBOOKS suffix kept); ASCII fallback keeps old clients
// happy, filename*= carries the real (possibly Tamil) name. Same picky-client
// header set as api/pdf-download.php, so Kodular's WebView treats it as a
// real download.
$name = preg_replace('/[\x00-\x1f"\\\\\/:*?<>|]/u', '', $stem) . '.pdf';
$ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '', $name);
if (trim($ascii, ' ._-') === 'pdf' || trim($ascii, ' ._-') === '') {
    $ascii = (preg_match('/^(\d{2})-/', $stem, $m) ? 'bible-book-' . $m[1] : 'bible-book') . '.pdf';
}

$size = (int) filesize($path);
$start = 0;
$end = $size - 1;
$partial = false;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min($end, (int) $m[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $partial = true;
}

// Count a download once, when it starts (first byte, real GET, not a bot) —
// resumed ranges and HEAD probes don't bump it.
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = false;
foreach (['bot', 'crawl', 'slurp', 'spider', 'mediapartners', 'curl', 'python', 'wget', 'facebookexternalhit', 'pingdom'] as $kw) {
    if (strpos($ua, $kw) !== false) { $isBot = true; break; }
}
if ($start === 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$isBot) {
    quizIncrementBookDownloadCount($lang);
}

http_response_code($partial ? 206 : 200);
header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . ($end - $start + 1));
if ($partial) {
    header("Content-Range: bytes $start-$end/$size");
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
@set_time_limit(0);
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, min(262144, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);
