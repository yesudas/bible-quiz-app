<?php
include __DIR__ . '/counter.php';
include __DIR__ . '/version.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/includes/quiz-leaderboard.php';

$lang = $_GET['lang'] ?? 'English';
$type = $_GET['type'] ?? 'random';
$set = max(0, (int) ($_GET['set'] ?? 0));
$setSize = (int) ($_GET['set_size'] ?? 10);
if ($setSize < 1) {
    $setSize = 1;
}
if ($setSize > 100) {
    $setSize = 100;
}
$timed = isset($_GET['timed']) && $_GET['timed'] === '1';
$timeUnit = $_GET['time_unit'] ?? 'seconds';
$timeValue = (int) ($_GET['time_value'] ?? 0);
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

$lang = str_replace(["\0", '/', '\\'], '', $lang);
$type = preg_replace('/[^a-z0-9\-]/', '', strtolower($type));
if ($timeUnit !== 'minutes') {
    $timeUnit = 'seconds';
}
if ($timeValue < 1) {
    $timed = false;
}

$bookKeys = [];
if (isset($_GET['books']) && $_GET['books'] !== '') {
    $rawBooks = $_GET['books'];
    $bookKeys = is_array($rawBooks) ? $rawBooks : explode(',', (string) $rawBooks);
} elseif (!empty($_GET['book'])) {
    $bookKeys = explode(',', (string) $_GET['book']);
}
$bookKeys = array_values(array_unique(array_filter(array_map(static function ($b) {
    $digits = preg_replace('/[^0-9]/', '', (string) $b);
    if ($digits === '') {
        return '';
    }
    $key = str_pad($digits, 2, '0', STR_PAD_LEFT);
    return preg_match('/^\d{2}$/', $key) ? $key : '';
}, $bookKeys))));
sort($bookKeys, SORT_NATURAL);
$booksAttr = implode(',', $bookKeys);

$chapterKeys = [];
if (isset($_GET['chapters']) && $_GET['chapters'] !== '') {
    $rawChapters = $_GET['chapters'];
    $chapterKeys = is_array($rawChapters) ? $rawChapters : explode(',', (string) $rawChapters);
} elseif (!empty($_GET['chapter'])) {
    $chapterKeys = explode(',', (string) $_GET['chapter']);
}
$chapterKeys = array_values(array_unique(array_filter(array_map(static function ($ch) {
    $ch = preg_replace('/[^0-9\-]/', '', (string) $ch);
    return preg_match('/^\d{2}-\d{3}$/', $ch) ? $ch : '';
}, $chapterKeys))));
sort($chapterKeys, SORT_NATURAL);
$chaptersAttr = implode(',', $chapterKeys);

$difficultyOptions = quizDifficultyOptions($lang);
$rawDifficulty = $_GET['difficulty'] ?? [];
$rawDifficulty = is_array($rawDifficulty) ? $rawDifficulty : explode(',', (string) $rawDifficulty);
$difficultyKeys = array_values(array_intersect(array_keys($difficultyOptions), $rawDifficulty));
$difficultyAttr = implode(',', $difficultyKeys);

$quizTypes = quizTypeOptions($lang);
$typeLabel = $quizTypes[$type] ?? 'Bible Quiz';
$detailLabels = quizDetailLabels($lang);

$bookNames = quizBookNamesForLanguage($lang);
$selectedBookNames = array_map(
    static fn (string $key): string => $bookNames[(int) $key] ?? $key,
    $bookKeys
);

$chaptersByBook = [];
foreach ($chapterKeys as $key) {
    [$bookKey, $chapterNum] = explode('-', $key, 2);
    $bookName = $bookNames[(int) $bookKey] ?? $bookKey;
    $chaptersByBook[$bookName][] = (int) $chapterNum;
}
$chapterSummaryParts = [];
foreach ($chaptersByBook as $bookName => $nums) {
    sort($nums);
    $chapterSummaryParts[] = $bookName . ' ' . implode(', ', $nums);
}

$topicLabel = quizIndexLabel($lang, 'topic', (string) ($_GET['topic'] ?? ''));
$characterLabel = quizIndexLabel($lang, 'character', (string) ($_GET['character'] ?? ''));
$placeLabel = quizIndexLabel($lang, 'place', (string) ($_GET['place'] ?? ''));

$quizDetails = [
    ['label' => $detailLabels['type'], 'value' => $typeLabel],
    ['label' => $detailLabels['language'], 'value' => quizLanguageDisplayName($lang)],
];
if (($type === 'book' || $type === 'chapter') && $selectedBookNames !== []) {
    $quizDetails[] = [
        'label' => count($selectedBookNames) > 1 ? $detailLabels['books'] : $detailLabels['book'],
        'value' => implode(', ', $selectedBookNames),
    ];
}
if ($type === 'chapter' && $chapterSummaryParts !== []) {
    $quizDetails[] = ['label' => $detailLabels['chapters'], 'value' => implode('; ', $chapterSummaryParts)];
}
if ($type === 'topic' && $topicLabel !== '') {
    $quizDetails[] = ['label' => $detailLabels['topic'], 'value' => $topicLabel];
}
if ($type === 'character' && $characterLabel !== '') {
    $quizDetails[] = ['label' => $detailLabels['character'], 'value' => $characterLabel];
}
if ($type === 'place' && $placeLabel !== '') {
    $quizDetails[] = ['label' => $detailLabels['place'], 'value' => $placeLabel];
}
if ($difficultyKeys !== []) {
    $quizDetails[] = [
        'label' => $detailLabels['difficulty'],
        'value' => implode(', ', array_map(static fn (string $k) => $difficultyOptions[$k], $difficultyKeys)),
    ];
}

$leaderboardLabels = quizLeaderboardLabels($lang);
$leaderboardCountries = quizLeaderboardCountries();

$pageTitle = $typeLabel . ' · Free Bible Quiz';
$metaDescription = 'Take a ' . $typeLabel . ' in ' . $lang . ' — free Bible quiz from WordOfGod.in.';

renderQuizHeader($pageTitle, $metaDescription, $version, ['bodyClass' => 'quiz-page', 'lang' => $lang, 'embed' => $embed]);

// "Change quiz" / "Back to home" need to stay inside the embed widget
// (embed.php) rather than breaking out to the full site (index.php) when
// this page was reached from an embedded iframe.
$homeHref = $embed ? 'embed.php' : 'index.php';
$embedQuery = $embed ? '&embed=1' : '';
?>

<div id="quizApp"
     class="quiz-app"
     data-lang="<?= htmlspecialchars($lang) ?>"
     data-type="<?= htmlspecialchars($type) ?>"
     data-set="<?= (int) $set ?>"
     data-set-size="<?= (int) $setSize ?>"
     data-books="<?= htmlspecialchars($booksAttr) ?>"
     data-chapters="<?= htmlspecialchars($chaptersAttr) ?>"
     data-topic="<?= htmlspecialchars($_GET['topic'] ?? '') ?>"
     data-character="<?= htmlspecialchars($_GET['character'] ?? '') ?>"
     data-place="<?= htmlspecialchars($_GET['place'] ?? '') ?>"
     data-difficulty="<?= htmlspecialchars($difficultyAttr) ?>"
     data-timed="<?= $timed ? '1' : '0' ?>"
     data-time-unit="<?= htmlspecialchars($timeUnit) ?>"
     data-time-value="<?= (int) $timeValue ?>"
     data-playing-as="<?= htmlspecialchars($leaderboardLabels['playingAs']) ?>"
     data-show-number="<?= htmlspecialchars($leaderboardLabels['showNumber']) ?>"
     data-hide-number="<?= htmlspecialchars($leaderboardLabels['hideNumber']) ?>"
     data-congrats-top100="<?= htmlspecialchars($leaderboardLabels['congratsTop100']) ?>"
     data-pdf-heading="<?= htmlspecialchars($leaderboardLabels['pdfHeading']) ?>"
     data-pdf-powered-by="<?= htmlspecialchars($leaderboardLabels['pdfPoweredBy']) ?>"
     data-pdf-review-heading="<?= htmlspecialchars($leaderboardLabels['pdfReviewHeading']) ?>">

    <div class="quiz-toolbar">
        <a href="<?= htmlspecialchars($homeHref) ?>?lang=<?= urlencode($lang) ?>&type=<?= urlencode($type) ?><?= $embedQuery ?>" class="btn btn-link">← Change quiz</a>
        <p class="quiz-mode-label"><?= htmlspecialchars($typeLabel) ?> · <?= htmlspecialchars(quizLanguageDisplayName($lang)) ?></p>
        <a href="leaderboard.php?lang=<?= urlencode($lang) ?><?= $embedQuery ?>" class="btn btn-link quiz-leaderboard-link"><i class="bi bi-trophy" aria-hidden="true"></i> <?= htmlspecialchars($leaderboardLabels['pageTitle']) ?></a>
    </div>

    <div id="leaderboardModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="leaderboardModalTitle">
        <div class="modal-box">
            <h2 id="leaderboardModalTitle"><?= htmlspecialchars($leaderboardLabels['modalTitle']) ?></h2>
            <p><?= htmlspecialchars($leaderboardLabels['modalIntro']) ?></p>
            <form id="leaderboardForm" novalidate>
                <label class="modal-field">
                    <span><?= htmlspecialchars($leaderboardLabels['displayName']) ?></span>
                    <input type="text" id="leaderboardName" name="displayName" maxlength="60" autocomplete="name">
                </label>
                <div class="modal-field-row">
                    <label class="modal-field modal-field-country">
                        <span><?= htmlspecialchars($leaderboardLabels['country']) ?></span>
                        <select id="leaderboardCountry" name="countryCode">
                            <?php foreach ($leaderboardCountries as $code => $name): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($name . ' (' . $code . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="modal-field modal-field-mobile">
                        <span><?= htmlspecialchars($leaderboardLabels['mobileNumber']) ?></span>
                        <input type="tel" id="leaderboardMobile" name="mobile" autocomplete="tel-national">
                    </label>
                </div>
                <p class="modal-disclaimer"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= htmlspecialchars($leaderboardLabels['disclaimer']) ?></p>
                <p id="leaderboardFormError" class="modal-error" role="alert" hidden><?= htmlspecialchars($leaderboardLabels['validationError']) ?></p>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" id="leaderboardSaveBtn"><?= htmlspecialchars($leaderboardLabels['save']) ?></button>
                    <button type="button" class="btn btn-link" id="leaderboardSkipBtn"><?= htmlspecialchars($leaderboardLabels['skip']) ?></button>
                </div>
            </form>
        </div>
    </div>

    <div id="leaderboardCongratsModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="leaderboardCongratsText">
        <div class="modal-box modal-box-congrats">
            <p id="leaderboardCongratsText" class="modal-congrats-text"></p>
            <div class="modal-actions">
                <button type="button" class="btn btn-primary" id="leaderboardCongratsOkBtn"><?= htmlspecialchars($leaderboardLabels['ok']) ?></button>
            </div>
        </div>
    </div>

    <div id="quizLoading" class="quiz-state">Loading questions…</div>
    <div id="quizError" class="quiz-state quiz-error" hidden></div>

    <section id="quizPlay" class="quiz-play" hidden>
        <div class="quiz-progress-row">
            <div class="quiz-progress-main">
                <div class="progress-wrap" aria-label="Quiz progress">
                    <div class="progress-bar" id="progressBar" style="width:0%"></div>
                </div>
                <p class="progress-text" id="progressText"></p>
            </div>
            <div class="timer-ring" id="timerRing" hidden aria-live="polite">
                <svg class="timer-ring-svg" viewBox="0 0 100 100" aria-hidden="true">
                    <circle class="timer-ring-track" cx="50" cy="50" r="45"></circle>
                    <circle class="timer-ring-progress" id="timerRingProgress" cx="50" cy="50" r="45"></circle>
                </svg>
                <span class="timer-ring-value" id="timerText"></span>
            </div>
        </div>

        <article class="question-card" id="questionCard" aria-live="polite"></article>

        <div class="quiz-actions">
            <button type="button" class="btn btn-primary" id="nextBtn" disabled>Next question</button>
        </div>
    </section>

    <section id="quizSetResults" class="quiz-results" hidden>
        <h2>Quiz set complete</h2>
        <p id="leaderboardPlayerInfo" class="leaderboard-player-info" hidden></p>
        <p id="leaderboardSaveStatus" class="leaderboard-save-status" role="status" aria-live="polite" hidden></p>
        <?php if ($quizDetails !== []): ?>
            <dl class="quiz-details">
                <?php foreach ($quizDetails as $detail): ?>
                    <div class="quiz-detail-row">
                        <dt><?= htmlspecialchars($detail['label']) ?></dt>
                        <dd><?= htmlspecialchars($detail['value']) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <div id="setScoreChart" class="score-chart-wrap" aria-live="polite"></div>
        <p id="setScoreSummary" class="score-summary"></p>
        <p id="setEncouragement" class="encouragement"></p>
        <div class="results-actions">
            <button type="button" class="btn btn-outline-primary" id="reviewBtn">Review answers</button>
            <button type="button" class="btn btn-primary" id="nextSetBtn" hidden>Next set</button>
            <button type="button" class="btn btn-outline-primary" id="downloadPdfBtn"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> <?= htmlspecialchars($leaderboardLabels['downloadPdf']) ?></button>
            <a href="<?= htmlspecialchars($homeHref) ?>?lang=<?= urlencode($lang) ?><?= $embedQuery ?>" class="btn btn-link">Back to home</a>
        </div>
    </section>

    <section id="quizReview" class="quiz-review" hidden>
        <h2>Your answers</h2>
        <div id="reviewScoreChart" class="score-chart-wrap" aria-live="polite"></div>
        <div id="reviewList" class="review-list"></div>
        <div class="results-actions">
            <button type="button" class="btn btn-primary" id="backFromReviewBtn">Back to results</button>
            <button type="button" class="btn btn-outline-primary" id="nextSetFromReviewBtn" hidden>Next set</button>
        </div>
    </section>
</div>

<script>
    window.QUIZ_VERSION = <?= json_encode($version) ?>;
</script>
<script src="assets/js/quiz.js?v=<?= htmlspecialchars($version) ?>" defer></script>

<?php renderQuizFooter($version, ['embed' => $embed]); ?>
