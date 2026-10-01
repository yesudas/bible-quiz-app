<?php
// counter.php
require_once __DIR__ . '/includes/visitor-counter.php';
require_once __DIR__ . '/partials.php';

// List of common bot keywords in User-Agent
$botKeywords = [
    'bot', 'crawl', 'slurp', 'spider', 'mediapartners', 'curl', 'python', 'wget', 'baiduspider', 'bingpreview', 'facebookexternalhit', 'pingdom'
];

// Get lowercase user agent
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

// Check if it's a bot
$isBot = false;
foreach ($botKeywords as $keyword) {
    if (strpos($userAgent, $keyword) !== false) {
        $isBot = true;
        break;
    }
}

// $_GET['lang'] isn't validated/defaulted yet at this point in index.php/quiz.php
// (this file is included before that happens), so do the same lightweight
// validation here against the real data/ languages.
$counterLanguages = quizDiscoverLanguages();
$counterLang = str_replace(["\0", '/', '\\'], '', (string) ($_GET['lang'] ?? $counterLanguages[0]));
if (!in_array($counterLang, $counterLanguages, true)) {
    $counterLang = $counterLanguages[0];
}

$visitors2 = 1;
$visitorsByLanguage = [];

// If not a bot, increment the counter
if (!$isBot) {
    $counts = quizIncrementVisitorCount($counterLang);
    $visitors2 = $counts['total'];
    $visitorsByLanguage = $counts['languages'];
}
