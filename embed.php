<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/counter.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/includes/quiz-leaderboard.php';

$languages = quizDiscoverLanguages();
$selectedLanguage = $_GET['lang'] ?? $languages[0];
if (!in_array($selectedLanguage, $languages, true)) {
    $selectedLanguage = $languages[0];
}

$quizTypes = quizTypeOptions($selectedLanguage);
$selectedType = $_GET['type'] ?? 'random';
if (!isset($quizTypes[$selectedType])) {
    $selectedType = 'random';
}

$difficultyOptions = quizDifficultyOptions($selectedLanguage);
$rawDifficulty = $_GET['difficulty'] ?? [];
$rawDifficulty = is_array($rawDifficulty) ? $rawDifficulty : [$rawDifficulty];
$selectedDifficulties = array_values(array_intersect($rawDifficulty, array_keys($difficultyOptions)));

$leaderboardTop10 = quizLeaderboardReadPublic('top10');
$leaderboardLabels = quizLeaderboardLabels($selectedLanguage);
$leaderboardPodium = array_slice($leaderboardTop10, 0, 3);
$leaderboardRest = array_slice($leaderboardTop10, 3, 7);

$pageTitle = 'Bible Quiz (Embed) - WordOfGod';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= htmlspecialchars($version) ?>">
</head>
<body class="embed-page">

<main class="embed-main container">

    <section class="quiz-setup card-panel" aria-labelledby="setup-title">
        <h2 id="setup-title" class="section-title">Start a quiz</h2>

        <form id="quizSetupForm" class="quiz-form" action="quiz.php" method="get">
            <input type="hidden" name="embed" value="1">
            <div class="form-row">
                <label for="lang">Language</label>
                <select name="lang" id="lang" required>
                    <?php foreach ($languages as $lang): ?>
                        <option value="<?= htmlspecialchars($lang) ?>" <?= $lang === $selectedLanguage ? 'selected' : '' ?>>
                            <?= htmlspecialchars(quizLanguageDisplayName($lang)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="type">Quiz type</label>
                <select name="type" id="type" required>
                    <?php foreach ($quizTypes as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $key === $selectedType ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row conditional-field" data-show-for="book chapter" hidden>
                <span class="field-label" id="book_list_label">Books</span>
                <p class="field-hint" id="bookPickerHint">Select one or more books.</p>
                <div class="picker-actions">
                    <button type="button" class="picker-btn" id="bookSelectAll" disabled>Select all</button>
                    <button type="button" class="picker-btn" id="bookClearAll" disabled>Clear</button>
                </div>
                <div class="checkbox-grid" id="bookCheckboxList" role="group" aria-labelledby="book_list_label">
                    <p class="field-hint">Loading books…</p>
                </div>
            </div>

            <div class="form-row conditional-field" data-show-for="chapter" hidden id="chapterPickerSection">
                <span class="field-label" id="chapter_list_label">Chapters</span>
                <p class="field-hint" id="chapterPickerHint">Select one book above to choose chapters.</p>
                <div class="picker-actions" id="chapterPickerActions" hidden>
                    <button type="button" class="picker-btn" id="chapterSelectAll" disabled>Select all</button>
                    <button type="button" class="picker-btn" id="chapterClearAll" disabled>Clear</button>
                </div>
                <div class="checkbox-grid" id="chapterCheckboxList" role="group" aria-labelledby="chapter_list_label" hidden>
                    <p class="field-hint">Select exactly one book to enable chapters.</p>
                </div>
            </div>

            <div class="form-row conditional-field" data-show-for="topic" hidden>
                <label for="topic">Topic</label>
                <select name="topic" id="topic" disabled data-preselect="<?= htmlspecialchars((string) ($_GET['topic'] ?? '')) ?>">
                    <option value="">Loading topics…</option>
                </select>
            </div>

            <div class="form-row conditional-field" data-show-for="character" hidden>
                <label for="character">Character</label>
                <select name="character" id="character" disabled data-preselect="<?= htmlspecialchars((string) ($_GET['character'] ?? '')) ?>">
                    <option value="">Loading characters…</option>
                </select>
            </div>

            <div class="form-row conditional-field" data-show-for="place" hidden>
                <label for="place">Place</label>
                <select name="place" id="place" disabled data-preselect="<?= htmlspecialchars((string) ($_GET['place'] ?? '')) ?>">
                    <option value="">Loading places…</option>
                </select>
            </div>

            <div class="form-row">
                <label for="set_size" id="set_size_label">Questions per set</label>
                <div class="qty-stepper" data-qty-stepper role="group" aria-labelledby="set_size_label">
                    <button type="button" class="qty-stepper-btn" data-step="-1" aria-label="Decrease questions per set">−</button>
                    <input type="number" name="set_size" id="set_size" class="qty-stepper-input" min="1" max="100" value="10" inputmode="numeric" required>
                    <button type="button" class="qty-stepper-btn" data-step="1" aria-label="Increase questions per set">+</button>
                </div>
                <p class="field-hint">Each set ends with your score and a full answer review.</p>
            </div>

            <fieldset class="difficulty-fieldset">
                <legend>Difficulty (optional)</legend>
                <div class="difficulty-options">
                    <?php foreach ($difficultyOptions as $key => $label): ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="difficulty[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $selectedDifficulties, true) ? 'checked' : '' ?>>
                            <span data-difficulty-label="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field-hint">Leave all unchecked to include every difficulty.</p>
            </fieldset>

            <fieldset class="timed-fieldset">
                <legend>Optional timer (per set)</legend>
                <label class="checkbox-row">
                    <input type="checkbox" name="timed" id="timed" value="1">
                    <span>Enable timed mode</span>
                </label>
                <div class="timed-options" id="timedOptions" hidden>
                    <div class="time-unit-row">
                        <label class="time-unit-choice">
                            <input type="radio" name="time_unit" value="seconds" checked> Seconds
                        </label>
                        <label class="time-unit-choice">
                            <input type="radio" name="time_unit" value="minutes"> Minutes
                        </label>
                    </div>
                    <div class="timed-limit-row">
                        <label for="time_value" class="timed-limit-label">Time limit</label>
                        <div class="qty-stepper" data-qty-stepper>
                            <button type="button" class="qty-stepper-btn" data-step="-1" aria-label="Decrease time limit" tabindex="-1">−</button>
                            <input type="number" name="time_value" id="time_value" class="qty-stepper-input" min="30" max="3600" value="120" inputmode="numeric" disabled>
                            <button type="button" class="qty-stepper-btn" data-step="1" aria-label="Increase time limit" tabindex="-1">+</button>
                        </div>
                    </div>
                </div>
            </fieldset>

            <p class="form-hint" id="indexHint">Questions are loaded from the built index in <code>index/</code>.</p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Start Quiz</button>
            </div>
        </form>
    </section>

    <section class="leaderboard-widget card-panel" id="leaderboardWidget" aria-labelledby="leaderboard-widget-title" <?= $leaderboardTop10 === [] ? 'hidden' : '' ?>>
        <h2 id="leaderboard-widget-title" class="section-title" data-leaderboard-label="pageTitle">🏆 <?= htmlspecialchars($leaderboardLabels['pageTitle']) ?></h2>

        <?php $podiumOrder = [1 => 'silver', 0 => 'gold', 2 => 'bronze']; ?>
        <div class="podium">
            <?php foreach ($podiumOrder as $i => $tier): ?>
                <?php if (!isset($leaderboardPodium[$i])) continue; ?>
                <?php $row = $leaderboardPodium[$i]; ?>
                <div class="podium-step <?= $tier ?>">
                    <span class="ord-tag"><?= $i + 1 ?></span>
                    <span class="p-name"><?= htmlspecialchars((string) $row['displayName']) ?></span>
                    <span class="p-country"><?= htmlspecialchars((string) $row['country']) ?></span>
                    <span class="p-score"><?= (int) $row['score'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($leaderboardRest !== []): ?>
            <div class="rest-list">
                <?php foreach ($leaderboardRest as $i => $row): ?>
                    <div class="rest-row">
                        <span class="r-rank"><?= $i + 4 ?></span>
                        <span class="r-name"><?= htmlspecialchars((string) $row['displayName']) ?></span>
                        <span class="r-country"><?= htmlspecialchars((string) $row['country']) ?></span>
                        <span class="r-score"><?= (int) $row['score'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <a class="leaderboard-widget-more" data-leaderboard-label="showAll" href="leaderboard.php?lang=<?= urlencode($selectedLanguage) ?>&embed=1">
            <?= htmlspecialchars($leaderboardLabels['showAll']) ?> →
        </a>
    </section>

    <footer class="embed-footer">
        <p class="embed-powered-by">Powered by: <a href="https://wordofgod.in/bible-quiz" target="_blank" rel="noopener">Bible Quiz</a></p>
    </footer>

</main>

<script>
    window.QUIZ_I18N = <?= json_encode(['chapterWord' => quizDetailLabels($selectedLanguage)['chapter']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.QUIZ_VERSION = <?= json_encode($version) ?>;
</script>
<script src="assets/js/home.js?v=<?= htmlspecialchars($version) ?>" defer></script>
</body>
</html>
