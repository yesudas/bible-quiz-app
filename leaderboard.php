<?php
include __DIR__ . '/counter.php';
include __DIR__ . '/version.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/includes/quiz-leaderboard.php';

$languages = quizDiscoverLanguages();
$lang = $_GET['lang'] ?? $languages[0];
if (!in_array($lang, $languages, true)) {
    $lang = $languages[0];
}

$view = ($_GET['view'] ?? 'top10') === 'all' ? 'all' : 'top10';
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$labels = quizLeaderboardLabels($lang);
$rows = quizLeaderboardReadPublic($view);

$pageTitle = $labels['pageTitle'] . ' · Free Bible Quiz';
$metaDescription = 'See the top Bible quiz scores on WordOfGod.in.';

renderQuizHeader($pageTitle, $metaDescription, $version, ['bodyClass' => 'page leaderboard-page', 'lang' => $lang, 'embed' => $embed]);

$homeHref = $embed ? 'embed.php' : 'index.php';
$embedQuery = $embed ? '&embed=1' : '';
?>

<div class="leaderboard-app container">
    <div class="leaderboard-toolbar">
        <h1><?= htmlspecialchars($labels['pageTitle']) ?> <?= $view === 'all' ? '' : '— ' . htmlspecialchars($labels['top10']) ?></h1>
        <form method="get" action="leaderboard.php" class="leaderboard-lang-form">
            <?php if ($view === 'all'): ?><input type="hidden" name="view" value="all"><?php endif; ?>
            <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <select name="lang" onchange="this.form.submit()" aria-label="<?= htmlspecialchars($labels['pageTitle']) ?>">
                <?php foreach ($languages as $l): ?>
                    <option value="<?= htmlspecialchars($l) ?>" <?= $l === $lang ? 'selected' : '' ?>><?= htmlspecialchars(quizLanguageDisplayName($l)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <p class="empty-state"><?= htmlspecialchars($labels['empty']) ?></p>
    <?php else: ?>
        <table class="leaderboard-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($labels['rank']) ?></th>
                    <th><?= htmlspecialchars($labels['name']) ?></th>
                    <th><?= htmlspecialchars($labels['country']) ?></th>
                    <th><?= htmlspecialchars($labels['score']) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <tr>
                        <td><?= (int) $i + 1 ?></td>
                        <td><?= htmlspecialchars((string) ($row['displayName'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['country'] ?? '')) ?></td>
                        <td><?= (int) ($row['score'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="leaderboard-actions">
        <?php if ($view === 'top10'): ?>
            <a href="leaderboard.php?view=all&lang=<?= urlencode($lang) ?><?= $embedQuery ?>" class="btn btn-outline-primary"><?= htmlspecialchars($labels['showAll']) ?></a>
        <?php else: ?>
            <a href="leaderboard.php?lang=<?= urlencode($lang) ?><?= $embedQuery ?>" class="btn btn-outline-primary"><?= htmlspecialchars($labels['top10']) ?></a>
        <?php endif; ?>
        <button type="button" class="btn btn-link" onclick="history.back()">← Back</button>
        <a href="<?= htmlspecialchars($homeHref) ?>?lang=<?= urlencode($lang) ?><?= $embedQuery ?>" class="btn btn-link">← Back to home</a>
    </div>
</div>

<?php renderQuizFooter($version, ['embed' => $embed]); ?>
