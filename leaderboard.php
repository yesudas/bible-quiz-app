<?php
include __DIR__ . '/counter.php';
include __DIR__ . '/version.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/includes/quiz-leaderboard.php';
require_once __DIR__ . '/includes/quiz-achievements.php';
require_once __DIR__ . '/includes/google-auth-client.php';

$googleUser = quizGoogleUser();
$googleLoginUrl = quizGoogleLoginUrl(quizCurrentUrl());
$googleLogoutUrl = quizGoogleLogoutUrl(quizCurrentUrl());

$languages = quizDiscoverLanguages();
$lang = $_GET['lang'] ?? $languages[0];
if (!in_array($lang, $languages, true)) {
    $lang = $languages[0];
}

$view = ($_GET['view'] ?? 'top10') === 'all' ? 'all' : 'top10';
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$labels = quizLeaderboardLabels($lang);
$rows = quizLeaderboardReadPublic($view);
$achievementTiers = quizAchievementProgress(0);

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
                    <th><?= htmlspecialchars($labels['score']) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <?php $pictureUrl = (string) ($row['pictureUrl'] ?? ''); ?>
                    <tr>
                        <td><?= (int) $i + 1 ?></td>
                        <td class="leaderboard-name-cell">
                            <?php if ($pictureUrl !== ''): ?>
                                <img class="player-avatar" src="<?= htmlspecialchars($pictureUrl) ?>" alt="" width="28" height="28">
                            <?php endif; ?>
                            <?= htmlspecialchars((string) ($row['displayName'] ?? '')) ?>
                        </td>
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

    <?php
    // Medal icon per tier — purely decorative, keyed by quizAchievementTiers()'s
    // own tier keys so it stays correct regardless of display order/labels.
    $achievementTierIcons = [
        'silver' => '🥈', 'bronze' => '🥉', 'gold' => '🥇', 'platinum' => '💎', 'diamond' => '👑',
    ];
    ?>
    <div class="achievements-section card-panel" id="achievementsSection">
        <h2><?= htmlspecialchars($labels['achievementsHeading']) ?></h2>
        <p class="achievements-intro"><?= htmlspecialchars($labels['achievementsIntro']) ?></p>

        <p class="achievements-badge-banner" id="achievementsBadgeBanner" hidden></p>

        <div class="achv-split">
            <div class="achv-sidebar">
                <div class="achievements-identity" id="achievementsIdentity" hidden>
                    <div class="achv-avatar" id="achievementsAvatarWrap"></div>
                    <strong class="achv-name" id="achievementsNameText"></strong>
                    <div class="achv-score"><span id="achievementsScoreNumber">0</span></div>
                    <span class="achv-score-label"><?= htmlspecialchars($labels['achievementsScoreLabel']) ?></span>
                    <div class="achv-actions">
                        <button type="button" class="achv-btn achv-btn-outline" id="achievementsEditBtn"><i class="bi bi-pencil-square" aria-hidden="true"></i> <?= htmlspecialchars($labels['editName']) ?></button>
                        <?php if ($googleLogoutUrl !== null): ?>
                            <a href="<?= htmlspecialchars($googleLogoutUrl) ?>" class="achv-btn achv-btn-outline-teal" id="achievementsLogoutBtn"><?= htmlspecialchars($labels['achievementsLogout']) ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Mobile-number login has been removed from this UI (Google
                     sign-in below is now the only login path);
                     quizLeaderboardFindByMobile() and the 'lookup' action in
                     api/leaderboard.php are kept for legacy rows and admin
                     editing. -->
                <div class="achievements-login" id="achievementsLoginWrap">
                    <div class="achv-avatar achv-avatar-placeholder"></div>
                    <?php if ($googleLoginUrl !== null): ?>
                        <a href="<?= htmlspecialchars($googleLoginUrl) ?>" class="achv-btn achv-btn-google" id="achievementsGoogleBtn">
                            <i class="bi bi-google" aria-hidden="true"></i> <?= htmlspecialchars($labels['signInGoogle']) ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="achievement-tiers" id="achievementTiers">
                <?php foreach ($achievementTiers as $tier): ?>
                    <div class="achievement-tier" style="--tier-color: <?= htmlspecialchars($tier['color']) ?>">
                        <div class="achievement-tier-icon"><?= $achievementTierIcons[$tier['key']] ?? '⭐' ?></div>
                        <div class="achievement-tier-body">
                            <span class="achievement-tier-label"><?= htmlspecialchars($tier['label']) ?></span>
                            <div class="achievement-tier-stars">
                                <?php foreach ($tier['stars'] as $i => $lit): ?>
                                    <?php $threshold = (int) ($tier['thresholds'][$i] ?? 0); ?>
                                    <div class="achievement-star-wrap">
                                        <span
                                            class="achievement-star<?= $lit ? ' is-lit' : '' ?>"
                                            data-tier="<?= htmlspecialchars($tier['key']) ?>"
                                            data-index="<?= (int) $i ?>"
                                            style="--tier-color: <?= htmlspecialchars($tier['color']) ?>"
                                            title="<?= $threshold ?>+"
                                        >★</span>
                                        <span
                                            class="achievement-star-remaining"
                                            data-tier="<?= htmlspecialchars($tier['key']) ?>"
                                            data-index="<?= (int) $i ?>"
                                            data-threshold="<?= $threshold ?>"
                                        ><?= $lit ? '' : htmlspecialchars((string) $threshold) . ' ' . htmlspecialchars($labels['achievementsToGo']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="achievementsEditModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="achievementsEditModalTitle">
            <div class="modal-box">
                <h2 id="achievementsEditModalTitle"><?= htmlspecialchars($labels['editName']) ?></h2>
                <label class="modal-field">
                    <span><?= htmlspecialchars($labels['displayName']) ?></span>
                    <input type="text" id="achievementsEditDisplayName" maxlength="60">
                </label>
                <label class="modal-field">
                    <span><?= htmlspecialchars($labels['profileNameLabel']) ?></span>
                    <input type="text" id="achievementsEditProfileName" readonly disabled>
                </label>
                <label class="modal-field">
                    <span><?= htmlspecialchars($labels['gmailIdLabel']) ?></span>
                    <input type="text" id="achievementsEditEmail" readonly disabled>
                </label>
                <p class="modal-error" id="achievementsEditError" hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-primary btn-sm" id="achievementsEditSaveBtn"><?= htmlspecialchars($labels['saveName']) ?></button>
                    <button type="button" class="btn btn-link btn-sm" id="achievementsEditCancelBtn"><?= htmlspecialchars($labels['cancelEdit']) ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.QUIZ_ACHIEVEMENTS_I18N = <?= json_encode([
        'scoreLabel' => $labels['achievementsScoreLabel'],
        'toGo' => $labels['achievementsToGo'],
        'badgeMessage' => $labels['achievementsBadgeMessage'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.QUIZ_GOOGLE_USER = <?= json_encode($googleUser) ?>;
</script>
<script src="assets/js/leaderboard.js?v=<?= htmlspecialchars($version) ?>" defer></script>

<?php renderQuizFooter($version, ['embed' => $embed]); ?>
