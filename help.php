<?php
include __DIR__ . '/counter.php';
include __DIR__ . '/version.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/includes/help-mockups.php';

$languages = quizDiscoverLanguages();
$selectedLanguage = $_GET['lang'] ?? $languages[0];
if (!in_array($selectedLanguage, $languages, true)) {
    $selectedLanguage = $languages[0];
}
$homeHref = 'index.php?lang=' . urlencode($selectedLanguage);

$pageTitle = 'Help & Step-by-Step Guide - Bible Quiz';
$metaDescription = 'How to start a quiz, share a quiz link, embed the app or a quiz on your own site, and download a printable quiz PDF — step by step.';
$canonical = 'https://wordofgod.in/bible-quiz/help.php';

renderQuizHeader($pageTitle, $metaDescription, $version, [
    'bodyClass' => 'help-page',
    'canonical' => $canonical,
    'headerTagline' => 'A step-by-step guide to every feature on this site.',
    'lang' => $selectedLanguage,
]);
?>

<section class="card-panel help-intro">
    <h2 class="section-title">Help &amp; Step-by-Step Guide</h2>
    <p>This page walks through every button on the <a href="<?= htmlspecialchars($homeHref) ?>">quiz setup form</a>, one step at a time, with a small illustration for each step showing exactly where to tap or click.</p>
    <nav class="help-toc" aria-label="Help sections">
        <a href="#start-quiz"><i class="bi bi-play-circle" aria-hidden="true"></i> Start Quiz</a>
        <a href="#share-quiz"><i class="bi bi-share" aria-hidden="true"></i> Share Quiz</a>
        <a href="#embed-app"><i class="bi bi-window" aria-hidden="true"></i> Embed App</a>
        <a href="#embed-quiz"><i class="bi bi-code-slash" aria-hidden="true"></i> Embed Quiz</a>
        <a href="#pdf"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Download PDF</a>
    </nav>
</section>

<section class="card-panel help-section" id="start-quiz" aria-labelledby="start-quiz-title">
    <h2 id="start-quiz-title" class="section-title"><i class="bi bi-play-circle" aria-hidden="true"></i> Start Quiz</h2>
    <p class="help-section-intro">Taking a quiz always starts on the <a href="<?= htmlspecialchars($homeHref) ?>">quiz setup form</a> on the home page.</p>

    <ol class="help-steps">
        <li class="help-step">
            <h3>1. Pick a language</h3>
            <p>Open the <strong>Language</strong> dropdown and choose the language you want your questions in.</p>
            <div class="help-mockup">
                <?php
                $w = 600; $h = 70;
                $svg = helpSvgStart($w, $h);
                $svg .= helpLabel(20, 26, 'Language');
                $svg .= helpFieldBox(20, 34, 240, 34, 'English');
                $svg .= helpHighlight(20, 34, 240, 34, 'Tap to choose', 'top');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>2. Choose a quiz type</h3>
            <p>Use the <strong>Quiz type</strong> dropdown — or scroll down and tap one of the "Quiz modes" tiles — to pick Random, Whole Bible, a specific book, topic, character, place, and more.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 70);
                $svg .= helpLabel(20, 26, 'Quiz type');
                $svg .= helpFieldBox(20, 34, 240, 34, 'Whole Bible');
                $svg .= helpHighlight(20, 34, 240, 34, 'Tap to choose', 'top');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>3. Narrow it down (if asked)</h3>
            <p>If your quiz type is Book, Chapter, Topic, Character, or Place, a picker appears below the dropdown — check the book(s) you want, or choose one topic/character/place from its own dropdown.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 110);
                $svg .= helpLabel(20, 24, 'Books');
                $bx = 20; $by = 32; $bw = 170; $bh = 22; $gapx = 190; $gapy = 30;
                $books = [['Genesis', true], ['Exodus', false], ['Matthew', false], ['John', false]];
                foreach ($books as $i => [$name, $checked]) {
                    $cx = $bx + ($i % 3) * $gapx;
                    $cy = $by + intdiv($i, 3) * $gapy;
                    $svg .= helpCheckboxRow($cx, $cy + 14, $name, $checked);
                }
                $svg .= helpHighlight($bx - 4, $by, 160, 22, 'Tap to select', 'top');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>4. Set questions per set</h3>
            <p>Use the <strong>−</strong> / <strong>+</strong> stepper to choose how many questions appear in each set (1–100). Long quizzes are automatically split into sets of this size, each ending with a score and answer review.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 70);
                $svg .= helpLabel(20, 26, 'Questions per set');
                $svg .= helpButton(20, 34, 32, 32, '−', 'outline');
                $svg .= helpFieldBox(56, 34, 60, 32, '10', '');
                $svg .= helpButton(120, 34, 32, 32, '+', 'outline');
                $svg .= helpHighlight(20, 34, 132, 32, 'Adjust the count', 'top');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>5. Optional: difficulty &amp; timer</h3>
            <p>Check <strong>Easy/Medium/Difficult</strong> to filter by difficulty (leave all unchecked for every difficulty), and turn on <strong>Enable timed mode</strong> if you want a countdown per set.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 90);
                $svg .= helpLabel(20, 24, 'Difficulty (optional)');
                $svg .= helpCheckboxRow(20, 48, 'Easy', true);
                $svg .= helpCheckboxRow(120, 48, 'Medium', false);
                $svg .= helpCheckboxRow(240, 48, 'Difficult', false);
                $svg .= helpCheckboxRow(380, 48, 'Enable timed mode', false);
                $svg .= helpHighlight(14, 36, 240, 22, 'Pick difficulties', 'bottom');
                $svg .= helpHighlight(374, 36, 190, 22, 'Or set a timer', 'bottom');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>6. Tap "Start Quiz"</h3>
            <p>Once your selection looks right, tap <strong>Start Quiz</strong> to begin.</p>
            <div class="help-mockup"><?= helpActionButtonsMockup('start', 'Tap to begin') ?></div>
        </li>
    </ol>
</section>

<section class="card-panel help-section" id="share-quiz" aria-labelledby="share-quiz-title">
    <h2 id="share-quiz-title" class="section-title"><i class="bi bi-share" aria-hidden="true"></i> Share Quiz</h2>
    <p class="help-section-intro">Share Quiz turns your current selection into a link other people can open to take the exact same quiz — handy for competitions and group play.</p>

    <ol class="help-steps">
        <li class="help-step">
            <h3>1. Configure your quiz</h3>
            <p>Follow <a href="#start-quiz">steps 1–5 of Start Quiz</a> above to choose the language, type, and any filters you want the shared link to use.</p>
        </li>

        <li class="help-step">
            <h3>2. Optional: Settings for Competitions</h3>
            <p>Open the <strong>Settings for Competitions</strong> section to make the link expire after a set time, and/or allow only one attempt per visitor.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 110);
                $svg .= helpLabel(20, 24, 'Settings for Competitions');
                $svg .= helpCheckboxRow(20, 48, 'Link expires after', true);
                $svg .= helpFieldBox(220, 36, 70, 24, '60', '');
                $svg .= helpRadioRow(310, 52, 'Minutes', true);
                $svg .= helpRadioRow(390, 52, 'Hours', false);
                $svg .= helpCheckboxRow(20, 76, 'One attempt per visitor', false);
                $svg .= helpHighlight(14, 36, 560, 56, 'Optional competition rules', 'bottom');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>3. Tap "Share Quiz"</h3>
            <p>A link appears right below the buttons.</p>
            <div class="help-mockup"><?= helpActionButtonsMockup('share', 'Tap to generate a link') ?></div>
        </li>

        <li class="help-step">
            <h3>4. Copy and send the link</h3>
            <p>Tap <strong>Copy</strong>, then paste the link anywhere — chat, email, social media — for others to open and take the quiz.</p>
            <div class="help-mockup"><?= helpCopyRowMockup('Shareable link', 'https://…/bible-quiz/index.php?test=abc123…') ?></div>
        </li>
    </ol>
</section>

<section class="card-panel help-section" id="embed-app" aria-labelledby="embed-app-title">
    <h2 id="embed-app-title" class="section-title"><i class="bi bi-window" aria-hidden="true"></i> Embed App</h2>
    <p class="help-section-intro">Embed App drops the entire quiz setup form — language, every quiz type, and the leaderboard — into your own website as an iframe. Visitors pick everything themselves once it loads.</p>

    <ol class="help-steps">
        <li class="help-step">
            <h3>1. Tap "Embed App"</h3>
            <p>No setup needed first — this button always embeds the full app, not your current selection.</p>
            <div class="help-mockup"><?= helpActionButtonsMockup('embedApp', 'Tap to get the embed code') ?></div>
        </li>

        <li class="help-step">
            <h3>2. Copy the code and paste it into your website</h3>
            <p>Tap <strong>Copy</strong>, then paste the snippet into your site's HTML wherever you want the quiz app to appear.</p>
            <div class="help-mockup"><?= helpCopyCodeMockup('Embed App code', '<iframe src="…/embed.php?lang=English">') ?></div>
        </li>
    </ol>
</section>

<section class="card-panel help-section" id="embed-quiz" aria-labelledby="embed-quiz-title">
    <h2 id="embed-quiz-title" class="section-title"><i class="bi bi-code-slash" aria-hidden="true"></i> Embed Quiz</h2>
    <p class="help-section-intro">Embed Quiz skips the setup form — visitors go straight into the one quiz you've configured, right inside your page.</p>

    <ol class="help-steps">
        <li class="help-step">
            <h3>1. Configure the quiz you want to embed</h3>
            <p>Follow <a href="#start-quiz">steps 1–5 of Start Quiz</a> above — this button embeds exactly what's currently selected on the form.</p>
        </li>

        <li class="help-step">
            <h3>2. Tap "Embed Quiz"</h3>
            <div class="help-mockup"><?= helpActionButtonsMockup('embedQuiz', 'Tap to get the embed code') ?></div>
        </li>

        <li class="help-step">
            <h3>3. Copy the code and paste it into your website</h3>
            <p>Tap <strong>Copy</strong>, then paste the snippet into your site's HTML wherever you want that one quiz to appear.</p>
            <div class="help-mockup"><?= helpCopyCodeMockup('Embed Quiz code', '<iframe src="…/embed-quiz.php?type=book&…">') ?></div>
        </li>
    </ol>
</section>

<section class="card-panel help-section" id="pdf" aria-labelledby="pdf-title">
    <h2 id="pdf-title" class="section-title"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Download PDF</h2>
    <p class="help-section-intro">Download PDF creates a printable worksheet (questions) and a matching answer key — great for competitions, classes, or offline study.</p>

    <ol class="help-steps">
        <li class="help-step">
            <h3>1. Select exactly one book, chapter, topic, character, or place</h3>
            <p>Or choose one of the seven standalone types (Random, Whole Bible, Old/New Testament, Kids, Teens, Adults). Every other combination — like two books, or a difficulty filter — isn't available as a PDF, since PDFs are prepared in advance for single selections only.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 80);
                $svg .= helpLabel(20, 24, 'Books');
                $svg .= helpCheckboxRow(20, 52, 'Genesis', true);
                $svg .= helpCheckboxRow(150, 52, 'Exodus', false);
                $svg .= helpCheckboxRow(280, 52, 'Matthew', false);
                $svg .= helpHighlight(14, 40, 110, 22, 'Exactly one checked', 'bottom');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>2. Tap "PDF"</h3>
            <p>This opens the "Download Quiz PDF" window.</p>
            <div class="help-mockup"><?= helpActionButtonsMockup('pdf', 'Tap to open PDF options') ?></div>
        </li>

        <li class="help-step">
            <h3>3. Optional: add a header and sub-headers</h3>
            <p>Fill in a title and up to three sub-header lines (e.g. "Organized by", "Name / Mobile", "Date / Score") — or leave them blank to get sensible fill-in-the-blank defaults.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 170);
                $svg .= helpLabel(20, 24, 'Download Quiz PDF');
                $svg .= helpLabel(20, 46, 'Header', '#8a8299', 11);
                $svg .= helpFieldBox(20, 52, 300, 28, 'Bible Quiz', '');
                $svg .= helpLabel(20, 96, 'Sub Header Details 1', '#8a8299', 11);
                $svg .= helpFieldBox(20, 102, 300, 28, 'Organized by: …', '');
                $svg .= helpHighlight(14, 44, 312, 92, 'Type your own text (optional)', 'bottom');
                $svg .= helpButton(430, 60, 140, 34, 'Download', 'primary');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>4. Tap "Download"</h3>
            <p>The site prepares your Questions PDF and Answer Key PDF and shows two download links.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 70);
                $svg .= helpButton(20, 18, 140, 34, 'Download', 'primary');
                $svg .= helpButton(172, 18, 100, 34, 'Cancel', 'outline');
                $svg .= helpHighlight(20, 18, 140, 34, 'Tap to build your PDFs', 'top');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>

        <li class="help-step">
            <h3>5. Tap each link to save your PDFs</h3>
            <p>Two links appear — one for the Questions PDF, one for the Answer Key PDF. Tap each to download it.</p>
            <div class="help-mockup">
                <?php
                $svg = helpSvgStart(600, 90);
                $svg .= helpButton(20, 18, 260, 32, '⬇ Tap to download Questions PDF', 'primary');
                $svg .= helpButton(20, 56, 260, 32, '⬇ Tap to download Answer Key PDF', 'primary');
                $svg .= helpHighlight(20, 18, 260, 32, 'Tap', 'right');
                $svg .= helpHighlight(20, 56, 260, 32, 'Tap', 'right');
                $svg .= helpSvgEnd();
                echo $svg;
                ?>
            </div>
        </li>
    </ol>
</section>

<?php renderQuizFooter($version); ?>
