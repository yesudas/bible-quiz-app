<?php
include __DIR__ . '/counter.php';
include __DIR__ . '/version.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/includes/quiz-share.php';
require_once __DIR__ . '/includes/quiz-leaderboard.php';

$shareError = null;
$shareNudge = false;
$shareNudgeLang = null;
if (isset($_GET['test']) && $_GET['test'] !== '') {
    $shareToken = (string) $_GET['test'];
    $shared = quizShareDecode($shareToken);
    $shareNudgeLang = $shared['lang'] ?? null;

    if ($shared !== null && $shared['end_time'] !== null && time() > $shared['end_time']) {
        $shared = null;
        $shareError = 'This shared quiz link has expired.';
        $shareNudge = true;
    }

    if ($shared !== null && $shared['one_attempt']) {
        $shareAttemptCookie = 'quiz_attempt_' . substr(hash('sha256', $shareToken), 0, 24);
        if (isset($_COOKIE[$shareAttemptCookie])) {
            $shared = null;
            $shareError = 'This shared quiz link allows only one attempt, and it looks like you already took it.';
            $shareNudge = true;
        } else {
            setcookie($shareAttemptCookie, '1', [
                'expires' => $shared['end_time'] ?? (time() + 86400),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    if ($shared !== null) {
        $redirectParams = [
            'lang' => $shared['lang'],
            'type' => $shared['type'],
            'set_size' => $shared['set_size'],
        ];
        if ($shared['timed']) {
            $redirectParams['timed'] = '1';
            $redirectParams['time_unit'] = $shared['time_unit'];
            $redirectParams['time_value'] = $shared['time_value'];
        }
        if ($shared['type'] === 'book' || $shared['type'] === 'chapter') {
            $redirectParams['books'] = implode(',', $shared['books']);
        }
        if ($shared['type'] === 'chapter') {
            $redirectParams['chapters'] = implode(',', $shared['chapters']);
        }
        if ($shared['type'] === 'topic') {
            $redirectParams['topic'] = $shared['topic'];
        }
        if ($shared['type'] === 'character') {
            $redirectParams['character'] = $shared['character'];
        }
        if ($shared['type'] === 'place') {
            $redirectParams['place'] = $shared['place'];
        }
        if ($shared['difficulty'] !== []) {
            $redirectParams['difficulty'] = implode(',', $shared['difficulty']);
        }

        header('Location: quiz.php?' . http_build_query($redirectParams));
        exit;
    }

    if ($shareError === null) {
        $shareError = 'This shared quiz link is invalid or has expired.';
    }
}

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

$catalog = quizCatalogForLanguage($selectedLanguage);
$catalogBooks = $catalog['books'] ?? [];
$catalogTopics = array_values(array_filter($catalog['topics'] ?? [], fn($t) => (int) $t['question_count'] > 1));
$catalogCharacters = array_values(array_filter($catalog['characters'] ?? [], fn($c) => (int) $c['question_count'] > 1));
$catalogPlaces = array_values(array_filter($catalog['places'] ?? [], fn($p) => (int) $p['question_count'] > 1));

$leaderboardTop10 = quizLeaderboardReadPublic('top10');
$leaderboardLabels = quizLeaderboardLabels($selectedLanguage);
$leaderboardPodium = array_slice($leaderboardTop10, 0, 3);
$leaderboardRest = array_slice($leaderboardTop10, 3, 7);

$pageTitle = 'Bible Quiz - WordOfGod';
$metaDescription = 'Free Bible quiz with random, book, chapter, topic, and audience modes in multiple languages. Test your Scripture knowledge at WordOfGod.in.';
$canonical = 'https://wordofgod.in/bible-quiz/';

renderQuizHeader($pageTitle, $metaDescription, $version, [
    'bodyClass' => 'home',
    'canonical' => $canonical,
    'headerTagline' => 'Grow in Scripture with quizzes by book, chapter, topic, character, or audience — in English, Tamil, and more.',
    'lang' => $selectedLanguage,
]);
?>

<section class="quiz-setup card-panel" aria-labelledby="setup-title">
    <h2 id="setup-title" class="section-title">Start a quiz</h2>

    <?php if ($shareError): ?>
        <p class="share-error-banner">
            <?= htmlspecialchars($shareError) ?>
            <?php if ($shareNudge): ?>
                Why not <a href="index.php<?= $shareNudgeLang ? '?lang=' . urlencode($shareNudgeLang) : '' ?>">create your own quiz</a>?
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form id="quizSetupForm" class="quiz-form" action="quiz.php" method="get">
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

        <fieldset class="share-protect-fieldset">
            <legend>Settings for Competitions</legend>
            <label class="checkbox-row">
                <input type="checkbox" name="share_expiry" id="share_expiry" value="1">
                <span>Link expires after</span>
            </label>
            <div class="share-expiry-options" id="shareExpiryOptions" hidden>
                <div class="time-unit-row">
                    <label class="time-unit-choice">
                        <input type="radio" name="share_expiry_unit" value="minutes" checked> Minutes
                    </label>
                    <label class="time-unit-choice">
                        <input type="radio" name="share_expiry_unit" value="hours"> Hours
                    </label>
                    <label class="time-unit-choice">
                        <input type="radio" name="share_expiry_unit" value="days"> Days
                    </label>
                </div>
                <div class="timed-limit-row">
                    <label for="share_expiry_value" class="timed-limit-label">Expires in</label>
                    <div class="qty-stepper" data-qty-stepper>
                        <button type="button" class="qty-stepper-btn" data-step="-1" aria-label="Decrease expiry" tabindex="-1">−</button>
                        <input type="number" name="share_expiry_value" id="share_expiry_value" class="qty-stepper-input" min="5" max="1440" value="60" inputmode="numeric" disabled>
                        <button type="button" class="qty-stepper-btn" data-step="1" aria-label="Increase expiry" tabindex="-1">+</button>
                    </div>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="share_one_attempt" id="share_one_attempt" value="1" disabled>
                    <span>Allow only one attempt per visitor until it expires</span>
                </label>
            </div>
            <p class="field-hint">Only applies to the link created by "Share Quiz" below. Expiry can be 5 minutes to 30 days.</p>
        </fieldset>

        <p class="form-hint" id="indexHint">Questions are loaded from the built index in <code>index/</code>. Run the index builder in the admin panel after updating <code>data/</code>.</p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">Start Quiz</button>
            <button type="button" id="shareQuizBtn" class="btn btn-outline-primary btn-lg">Share Quiz</button>
            <button type="button" id="embedAppBtn" class="btn btn-outline-primary btn-lg">Embed App</button>
            <button type="button" id="embedQuizBtn" class="btn btn-outline-primary btn-lg">Embed Quiz</button>
            <button type="button" id="pdfQuizBtn" class="btn btn-outline-primary btn-lg"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</button>
        </div>
        <p class="field-hint">"Embed App" drops the full quiz setup form into your site. "Embed Quiz" skips straight to the quiz you've configured above. "Download PDF" creates printable question and answer-key PDFs for the quiz you've configured above.</p>

        <div class="share-result" id="shareResult" hidden>
            <label for="shareUrlInput" class="field-label">Shareable link</label>
            <div class="share-result-row">
                <input type="text" id="shareUrlInput" class="share-url-input" readonly>
                <button type="button" class="btn btn-outline-primary" id="shareCopyBtn">Copy</button>
            </div>
            <p class="share-result-msg" id="shareResultMsg" aria-live="polite"></p>
        </div>

        <div class="share-result" id="embedAppResult" hidden>
            <label for="embedAppCodeInput" class="field-label">Embed App code</label>
            <p class="field-hint">Use this code to embed the full Bible Quiz app — setup form, leaderboard, and all — on your own website.</p>
            <div class="share-result-row">
                <textarea id="embedAppCodeInput" class="share-url-input embed-code-input" rows="2" readonly></textarea>
                <button type="button" class="btn btn-outline-primary" id="embedAppCopyBtn">Copy</button>
            </div>
            <p class="share-result-msg" id="embedAppResultMsg" aria-live="polite"></p>
        </div>

        <div class="share-result" id="embedQuizResult" hidden>
            <label for="embedQuizCodeInput" class="field-label">Embed Quiz code</label>
            <p class="field-hint">Use this code to embed just the quiz you've configured above — visitors go straight into it, skipping the setup form.</p>
            <div class="share-result-row">
                <textarea id="embedQuizCodeInput" class="share-url-input embed-code-input" rows="2" readonly></textarea>
                <button type="button" class="btn btn-outline-primary" id="embedQuizCopyBtn">Copy</button>
            </div>
            <p class="share-result-msg" id="embedQuizResultMsg" aria-live="polite"></p>
        </div>
    </form>

    <div id="pdfModal" class="modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="pdfModalTitle">
        <div class="modal-box">
            <h2 id="pdfModalTitle">Download Quiz PDF</h2>
            <p>These printable PDFs use the quiz you've configured above. All fields below are optional.</p>
            <form id="pdfForm" novalidate>
                <label class="modal-field">
                    <span>Header</span>
                    <input type="text" id="pdfHeader" maxlength="80" placeholder="Bible Quiz">
                </label>
                <label class="modal-field">
                    <span>Sub Header Details 1</span>
                    <input type="text" id="pdfSub1" maxlength="120">
                </label>
                <label class="modal-field">
                    <span>Sub Header Details 2</span>
                    <input type="text" id="pdfSub2" maxlength="120">
                </label>
                <label class="modal-field">
                    <span>Sub Header Details 3</span>
                    <input type="text" id="pdfSub3" maxlength="120">
                </label>
                <div class="modal-field modal-field-plain">
                    <span>PDF size</span>
                    <div class="time-unit-row">
                        <label class="time-unit-choice">
                            <input type="radio" name="pdf_size" value="A5" checked> A5
                        </label>
                        <label class="time-unit-choice">
                            <input type="radio" name="pdf_size" value="A4"> A4
                        </label>
                    </div>
                </div>
                <p id="pdfFormError" class="modal-error" role="alert" hidden>Could not prepare the PDFs. Please try again.</p>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" id="pdfDownloadBtn">Download</button>
                    <button type="button" class="btn btn-link" id="pdfCancelBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="quiz-types-overview" aria-labelledby="types-overview">
    <h2 id="types-overview" class="section-title">Quiz modes</h2>
    <ul class="type-grid">
        <?php foreach ($quizTypes as $key => $label): ?>
            <li><a href="?type=<?= urlencode($key) ?>&lang=<?= urlencode($selectedLanguage) ?>" data-type-key="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
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

    <a class="leaderboard-widget-more" data-leaderboard-label="showAll" href="leaderboard.php?lang=<?= urlencode($selectedLanguage) ?>">
        <?= htmlspecialchars($leaderboardLabels['showAll']) ?> →
    </a>
</section>

<section class="quiz-items-overview" id="booksOverviewSection" aria-labelledby="books-overview-title" <?= $catalogBooks === [] ? 'hidden' : '' ?>>
    <h2 id="books-overview-title" class="section-title" data-type-key="book"><?= htmlspecialchars($quizTypes['book']) ?></h2>
    <div class="item-chip-list" id="bookLinksList">
        <?php foreach ($catalogBooks as $book): ?>
            <a class="item-chip" href="quiz.php?type=book&amp;lang=<?= urlencode($selectedLanguage) ?>&amp;books%5B%5D=<?= urlencode($book['key']) ?>">
                <?= htmlspecialchars($book['name']) ?> <span class="count">(<?= (int) $book['question_count'] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="quiz-items-overview" id="topicsOverviewSection" aria-labelledby="topics-overview-title" <?= $catalogTopics === [] ? 'hidden' : '' ?>>
    <h2 id="topics-overview-title" class="section-title" data-type-key="topic"><?= htmlspecialchars($quizTypes['topic']) ?></h2>
    <div class="item-chip-list" id="topicLinksList">
        <?php foreach ($catalogTopics as $topic): ?>
            <a class="item-chip" href="quiz.php?type=topic&amp;lang=<?= urlencode($selectedLanguage) ?>&amp;topic=<?= urlencode($topic['slug']) ?>">
                <?= htmlspecialchars($topic['label']) ?> <span class="count">(<?= (int) $topic['question_count'] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="quiz-items-overview" id="charactersOverviewSection" aria-labelledby="characters-overview-title" <?= $catalogCharacters === [] ? 'hidden' : '' ?>>
    <h2 id="characters-overview-title" class="section-title" data-type-key="character"><?= htmlspecialchars($quizTypes['character']) ?></h2>
    <div class="item-chip-list" id="characterLinksList">
        <?php foreach ($catalogCharacters as $character): ?>
            <a class="item-chip" href="quiz.php?type=character&amp;lang=<?= urlencode($selectedLanguage) ?>&amp;character=<?= urlencode($character['slug']) ?>">
                <?= htmlspecialchars($character['label']) ?> <span class="count">(<?= (int) $character['question_count'] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="quiz-items-overview" id="placesOverviewSection" aria-labelledby="places-overview-title" <?= $catalogPlaces === [] ? 'hidden' : '' ?>>
    <h2 id="places-overview-title" class="section-title" data-type-key="place"><?= htmlspecialchars($quizTypes['place']) ?></h2>
    <div class="item-chip-list" id="placeLinksList">
        <?php foreach ($catalogPlaces as $place): ?>
            <a class="item-chip" href="quiz.php?type=place&amp;lang=<?= urlencode($selectedLanguage) ?>&amp;place=<?= urlencode($place['slug']) ?>">
                <?= htmlspecialchars($place['label']) ?> <span class="count">(<?= (int) $place['question_count'] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<script>
    window.QUIZ_I18N = <?= json_encode(['chapterWord' => quizDetailLabels($selectedLanguage)['chapter']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.QUIZ_VERSION = <?= json_encode($version) ?>;
</script>
<script src="assets/js/home.js?v=<?= htmlspecialchars($version) ?>" defer></script>

<?php renderQuizFooter($version); ?>
