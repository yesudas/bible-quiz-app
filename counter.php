<?php
// counter.php
require_once __DIR__ . '/includes/visitor-counter.php';
require_once __DIR__ . '/includes/share-counter.php';
require_once __DIR__ . '/includes/embed-counter.php';
require_once __DIR__ . '/includes/quiz-stats.php';
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
$shares2 = 0;
$embeds2 = 0;

// A request served by embed.php (the full setup form, iframe-embeddable) or
// embed-quiz.php (a single pre-configured quiz, iframe-embeddable) — counted
// separately from ordinary page views, regardless of which page included
// this file.
$isEmbedRequest = in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['embed.php', 'embed-quiz.php'], true);

// If not a bot, increment the counter
if (!$isBot) {
    $counts = quizIncrementVisitorCount($counterLang);
    $visitors2 = $counts['total'];
    $visitorsByLanguage = $counts['languages'];

    // A share-link visit (?test=...) also counts toward the shares total,
    // regardless of whether the token itself turns out to be valid/expired —
    // this file runs before index.php decodes/validates it.
    if (isset($_GET['test']) && $_GET['test'] !== '') {
        $shareCounts = quizIncrementShareCount();
        $shares2 = $shareCounts['total'];
    } else {
        $shares2 = quizReadShareCounts()['total'];
    }

    if ($isEmbedRequest) {
        $embedCounts = quizIncrementEmbedCount();
        $embeds2 = $embedCounts['total'];
    } else {
        $embeds2 = quizReadEmbedCounts()['total'];
    }
} else {
    $shares2 = quizReadShareCounts()['total'];
    $embeds2 = quizReadEmbedCounts()['total'];
}

// Quiz-activity stats for the footer — read-only here; these are only ever
// incremented by api/stats.php (called when a set finishes) or edited from
// the admin screen, never bumped on a page view.
$quizzesCompletedTotal = quizReadQuizzesCompleted()['total'];
$questionsAnsweredTotal = quizReadQuestionsAnswered()['total'];

$questionsAnsweredByLang = quizReadQuestionsAnswered()['languages'];
$topLanguage = '';
if ($questionsAnsweredByLang) {
    arsort($questionsAnsweredByLang);
    $topLanguage = (string) array_key_first($questionsAnsweredByLang);
}

$questionsByBookTotals = quizReadQuestionsByBook()['total'];
arsort($questionsByBookTotals);
$topBookNumbers = array_slice(array_keys(array_filter($questionsByBookTotals, static fn (int $c): bool => $c > 0)), 0, 3);
$bookNamesForCounter = quizBookNamesForLanguage($counterLang);
$topBooks = array_map(
    static fn (string $no): string => $bookNamesForCounter[(int) $no] ?? ('Book ' . $no),
    $topBookNumbers
);
