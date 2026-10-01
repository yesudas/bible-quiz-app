<?php
/**
 * sitemap.php — SEO sitemap generator for Free Bible Quiz
 *
 * Protected: requires admin session from a.php (same pattern as i.php).
 */

session_name('admin_sess');
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache');
header('Referrer-Policy: same-origin');

if (empty($_SESSION['admin_ok']) || $_SESSION['admin_ok'] !== true) {
    header('Location: a.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$baseUrl = 'https://wordofgod.in/bible-quiz/';
$today = date('Y-m-d');
$masterFile = __DIR__ . '/sitemap.xml';
$built = false;
$buildError = '';
$urlCount = 0;
$sitemapFiles = [];

function sm_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sm_collectUrls(string $baseUrl): array
{
    $urls = [
        ['loc' => $baseUrl, 'priority' => '1.0'],
        ['loc' => $baseUrl . 'index.php', 'priority' => '1.0'],
    ];

    $indexDir = __DIR__ . '/index';
    if (!is_dir($indexDir)) {
        return $urls;
    }

    foreach (glob($indexDir . '/*/catalog.json') ?: [] as $catalogFile) {
        $lang = basename(dirname($catalogFile));
        $raw = file_get_contents($catalogFile);
        $catalog = $raw ? json_decode($raw, true) : null;
        if (!is_array($catalog)) {
            continue;
        }

        $urls[] = [
            'loc' => $baseUrl . 'index.php?lang=' . rawurlencode($lang),
            'priority' => '0.9',
        ];

        $simpleTypes = ['random', 'whole-bible', 'old-testament', 'new-testament', 'kids', 'teens', 'adults'];
        foreach ($simpleTypes as $type) {
            $urls[] = [
                'loc' => $baseUrl . 'index.php?lang=' . rawurlencode($lang) . '&type=' . rawurlencode($type),
                'priority' => '0.8',
            ];
        }

        foreach ($catalog['books'] ?? [] as $book) {
            $urls[] = [
                'loc' => $baseUrl . 'index.php?lang=' . rawurlencode($lang)
                    . '&type=book&book=' . rawurlencode($book['key']),
                'priority' => '0.7',
            ];
        }
        foreach ($catalog['topics'] ?? [] as $topic) {
            $urls[] = [
                'loc' => $baseUrl . 'index.php?lang=' . rawurlencode($lang)
                    . '&type=topic&topic=' . rawurlencode($topic['slug']),
                'priority' => '0.6',
            ];
        }
        foreach ($catalog['characters'] ?? [] as $character) {
            $urls[] = [
                'loc' => $baseUrl . 'index.php?lang=' . rawurlencode($lang)
                    . '&type=character&character=' . rawurlencode($character['slug']),
                'priority' => '0.6',
            ];
        }
    }

    return $urls;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $urls = sm_collectUrls($baseUrl);
    $urlCount = count($urls);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . sm_escape($u['loc']) . "</loc>\n";
        $xml .= "    <lastmod>{$today}</lastmod>\n";
        $xml .= '    <changefreq>yearly</changefreq>' . "\n";
        $xml .= '    <priority>' . sm_escape($u['priority']) . "</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= "</urlset>\n";

    if (file_put_contents($masterFile, $xml, LOCK_EX) !== false) {
        $built = true;
        $sitemapFiles[] = 'sitemap.xml';
    } else {
        $buildError = 'Could not write sitemap.xml — check permissions.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sitemap Generator · Bible Quiz</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <style>
        body { max-width: 720px; margin: 40px auto; padding: 0 20px; }
        .card { background:#fff;border:1px solid #dce3ec;border-radius:10px;padding:28px; }
        .btn-build { background:#1a5276;color:#fff;border:none;padding:11px 26px;border-radius:8px;font-weight:700;cursor:pointer; }
        .alert-success { background:#eafaf1;color:#1e8449;padding:12px;border-radius:8px;margin-bottom:16px; }
        .alert-error { background:#fdf2f2;color:#c0392b;padding:12px;border-radius:8px;margin-bottom:16px; }
    </style>
</head>
<body>
<p><a href="a.php">← Back to Admin</a></p>
<div class="card">
    <h2>🗺️ Sitemap Generator</h2>
    <p style="font-size:.9rem;color:#666;">Writes <code>sitemap.xml</code> for quiz landing URLs (languages and modes).</p>

    <?php if ($built): ?>
        <div class="alert-success">✅ Generated sitemap.xml with <?= (int) $urlCount ?> URLs.</div>
        <p><a href="sitemap.xml" target="_blank" rel="noopener"><?= sm_escape($baseUrl) ?>sitemap.xml</a></p>
    <?php elseif ($buildError): ?>
        <div class="alert-error"><?= htmlspecialchars($buildError) ?></div>
    <?php endif; ?>

    <form method="POST" style="margin-top:16px;">
        <input type="hidden" name="action" value="build">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn-build">Generate sitemap</button>
    </form>
</div>
</body>
</html>
