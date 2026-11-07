<?php

require_once __DIR__ . '/includes/quiz-i18n.php';
require_once __DIR__ . '/includes/google-auth-client.php';

function quizDiscoverLanguages(): array
{
    $langs = [];
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        return ['English'];
    }
    foreach (scandir($dataDir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        if (is_dir($dataDir . '/' . $entry)) {
            $langs[] = $entry;
        }
    }
    sort($langs, SORT_NATURAL | SORT_FLAG_CASE);
    return $langs !== [] ? $langs : ['English'];
}

/**
 * Every page that calls renderQuizHeader() now renders Google-sign-in-aware
 * content (a visitor's own identity/session state), so it can never be a
 * shared, cacheable response — caching it anywhere (browser, mobile
 * carrier proxy, CDN) risks one visitor's signed-in page being served to
 * someone else, not just staleness. `no-store` is the strongest directive:
 * unlike `no-cache` (which still permits storing a copy as long as it's
 * revalidated first), `no-store` forbids keeping a copy at all. This also
 * fixes the "a few phones still show the old mobile-number leaderboard UI"
 * symptom reported after the Google-sign-in rewrite — that HTML had no
 * Cache-Control header at all before this, so some mobile browsers/proxies
 * were caching it on their own heuristics with no way for us to force an
 * invalidation. version.php's $version bump (see Conventions in AGENTS.md)
 * remains the right lever for the versioned CSS/JS/API URLs; it was never
 * meant to cover this page's own un-versioned URL, which is why bumping it
 * alone didn't fix this.
 */
function quizNoCacheHeaders(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function renderQuizHeader(string $pageTitle, string $metaDescription, string $version, array $options = []): void
{
    quizNoCacheHeaders();
    $bodyClass = $options['bodyClass'] ?? 'page';
    $headerTagline = $options['headerTagline'] ?? '';
    $headerLang = $options['lang'] ?? '';
    $embed = $options['embed'] ?? false;
    $brandHref = $headerLang !== '' ? 'index.php?lang=' . urlencode($headerLang) : 'index.php';
    // Profile icon next to Help: always links to the Achievements section of
    // leaderboard.php (its own sidebar handles both the signed-in and
    // signed-out states — see leaderboard.php/leaderboard.js) — shows the
    // visitor's own Google avatar when signed in (quizGoogleUser() degrades
    // to null, same as everywhere else, if the shared auth/ service isn't
    // deployed), a generic person icon otherwise.
    $headerGoogleUser = quizGoogleUser();
    $profileHref = 'leaderboard.php' . ($headerLang !== '' ? '?lang=' . urlencode($headerLang) : '') . '#achievementsSection';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta name="keywords" content="Bible Quiz, Bible Trivia, Scripture Quiz, Free Bible Quiz, WordOfGod.in">
    <meta name="author" content="www.WordOfGod.in">
    <meta name="theme-color" content="#7c5cff">
    <?php if ($embed): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php else: ?>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Bible Quiz - WordOfGod">
    <link rel="canonical" href="<?= htmlspecialchars($options['canonical'] ?? '') ?>">
    <link rel="manifest" href="manifest.json?v=<?= htmlspecialchars($version) ?>">
    <?php endif; ?>
    <link rel="icon" href="assets/images/icon-192.png">
    <link rel="apple-touch-icon" href="assets/images/icon-192.png">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= htmlspecialchars($version) ?>">

    <?php if (!$embed): ?>
    <?php include __DIR__ . '/google-analytics.php'; ?>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?><?= $embed ? ' embed-page' : '' ?>">

<?php if (!$embed): ?>
<div id="pwaInstallBanner" class="pwa-install-banner" hidden>
    <div class="pwa-banner-inner">
        <img class="pwa-banner-icon" src="assets/images/icon-192.png" alt="" width="40" height="40">
        <div class="pwa-banner-text">
            <strong>Install Free Bible Quiz</strong>
            <span>Add this app to your home screen for quick access.</span>
        </div>
        <div class="pwa-banner-actions">
            <button type="button" class="pwa-btn-install install-app-btn" id="installAppBtnBanner">Install</button>
            <button type="button" class="pwa-btn-cancel" id="dismissBannerBtn">Cancel</button>
        </div>
    </div>
</div>

<header class="site-header">
    <div class="header-inner container">
        <a class="brand" href="<?= htmlspecialchars($brandHref) ?>">
            <span class="brand-title">Bible Quiz</span>
            <?php if ($headerTagline !== ''): ?>
                <p class="header-tagline"><?= htmlspecialchars($headerTagline) ?></p>
            <?php endif; ?>
        </a>
        <?php include __DIR__ . '/menu-links.php'; ?>
        <a class="header-profile" id="profileLinkHeader" href="<?= htmlspecialchars($profileHref) ?>" title="Achievements" aria-label="Achievements">
            <?php if (!empty($headerGoogleUser['picture'])): ?>
                <img class="header-profile-avatar" src="<?= htmlspecialchars($headerGoogleUser['picture']) ?>" alt="">
            <?php else: ?>
                <i class="bi bi-person-circle" aria-hidden="true"></i>
            <?php endif; ?>
        </a>
        <a class="header-help" id="helpLinkHeader" href="help.php<?= $headerLang !== '' ? '?lang=' . urlencode($headerLang) : '' ?>">
            <i class="bi bi-question-circle" aria-hidden="true"></i> Help
        </a>
        <button type="button" class="install-app-btn header-install" id="installAppBtnHeader" hidden>Install</button>
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="siteNav">
            <span class="nav-toggle-bars" aria-hidden="true"></span>
        </button>
    </div>
</header>
<?php endif; ?>

<main class="<?= $embed ? 'embed-main' : 'site-main' ?> container">
    <?php
}

function renderQuizFooter(string $version, array $options = []): void
{
    global $visitors2, $shares2, $embeds2, $downloads2, $quizzesCompletedTotal, $questionsAnsweredTotal, $topBooks, $topLanguage;
    $embed = $options['embed'] ?? false;
    ?>
</main>

<?php if (!$embed): ?>
<footer class="site-footer">
    <p class="footer-verse">No Copyright, &ldquo;Freely you have received; Freely give&rdquo; &mdash; Matt 10:8</p>
    <?php include __DIR__ . '/footer-links.php'; ?>
    <p class="footer-install-wrap">
        <button type="button" class="install-app-btn btn btn-outline-light btn-sm" id="installAppBtnFooter" hidden>Install App</button>
    </p>
</footer>
<?php else: ?>
<footer class="embed-footer">
    <p class="embed-powered-by">Powered by: <a href="https://wordofgod.in/bible-quiz" target="_blank" rel="noopener">Bible Quiz</a></p>
</footer>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js" defer></script>
<?php if (!$embed): ?>
<script src="assets/js/app.js?v=<?= htmlspecialchars($version) ?>" defer></script>
<?php endif; ?>
</body>
</html>
    <?php
}
