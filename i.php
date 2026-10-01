<?php
/**
 * i.php — Quiz index builder
 *
 * Scans data/ question JSON files and writes indexes under index/ for all quiz types.
 * Protected: requires an active admin session from a.php.
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

require_once __DIR__ . '/includes/index-builder.php';

define('BASE_DATA_DIR', realpath(__DIR__ . '/data'));
$indexDir = __DIR__ . '/index';

$built = false;
$buildError = '';
$elapsed = 0;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }
    if (BASE_DATA_DIR === false) {
        $buildError = 'data/ directory is missing.';
    } else {
        $timeStart = microtime(true);
        $result = buildQuizIndexes(BASE_DATA_DIR, $indexDir);
        $elapsed = round(microtime(true) - $timeStart, 2);
        if ($result['errors'] === []) {
            $built = true;
        } else {
            $buildError = 'Index built with warnings — see details below.';
            $built = true;
        }
    }
}

$catalogMeta = null;
if (is_dir($indexDir)) {
    foreach (glob($indexDir . '/*/catalog.json') ?: [] as $catFile) {
        $raw = @file_get_contents($catFile);
        if ($raw) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $catalogMeta[] = [
                    'language' => $dec['language'] ?? basename(dirname($catFile)),
                    'generated' => $dec['generated'] ?? '—',
                    'count' => $dec['question_count'] ?? 0,
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Index Builder</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <style>
        body { max-width: 720px; margin: 40px auto; padding: 0 20px; }
        .card {
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 10px;
            padding: 28px 28px 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
            margin-bottom: 20px;
        }
        .card h2 { font-size: 1.1rem; color: #1a5276; margin-bottom: 14px; }
        .btn-build {
            display: inline-flex; align-items: center; gap: 8px;
            background: #1a5276; color: #fff; border: none;
            padding: 11px 26px; border-radius: 8px;
            font-size: .95rem; font-weight: 700; cursor: pointer;
        }
        .btn-build:hover { background: #154360; }
        .btn-back { display: inline-block; margin-top: 12px; color: #2980b9; font-size: .85rem; text-decoration: none; }
        .alert { padding: 11px 16px; border-radius: 8px; font-size: .88rem; margin-bottom: 18px; }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }
        .alert-error { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }
        code { background: #f4f6f9; padding: 2px 6px; border-radius: 4px; }
        .err-list { font-size: .82rem; color: #c0392b; margin-top: 10px; }
    </style>
</head>
<body>

<div style="margin-bottom:18px;">
    <a href="a.php" class="btn-back">← Back to Admin</a>
</div>

<div class="card">
    <h2>⚙️ Quiz Index Builder</h2>
    <p style="font-size:.88rem;color:#7f8c8d;margin-bottom:20px;">
        Scans <code>data/</code> and writes quiz indexes to <code>index/</code>
        (random, whole Bible, OT/NT, book, chapter, topic, character, kids, teens, adults).
        Re-run after adding or changing question files.
    </p>

    <?php if ($built && $result): ?>
        <div class="alert alert-success">
            ✅ Index build finished in <?= htmlspecialchars((string) $elapsed) ?>s.
            <?= (int) $result['stats']['files_written'] ?> files written,
            <?= (int) $result['stats']['questions'] ?> questions indexed.
        </div>
        <?php if ($result['errors'] !== []): ?>
            <div class="alert alert-error"><?= htmlspecialchars($buildError) ?></div>
            <ul class="err-list">
                <?php foreach (array_slice($result['errors'], 0, 20) as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php elseif ($buildError !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($buildError) ?></div>
    <?php endif; ?>

    <?php if ($catalogMeta): ?>
        <p style="font-size:.88rem;margin-bottom:16px;"><strong>Current index:</strong></p>
        <ul style="font-size:.88rem;margin-bottom:20px;">
            <?php foreach ($catalogMeta as $meta): ?>
                <li><?= htmlspecialchars($meta['language']) ?> — <?= (int) $meta['count'] ?> questions (<?= htmlspecialchars($meta['generated']) ?>)</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="build">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn-build">Build quiz indexes</button>
    </form>
</div>

</body>
</html>
