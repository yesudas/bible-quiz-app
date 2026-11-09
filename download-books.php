<?php
include __DIR__ . '/counter.php';
include __DIR__ . '/version.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/includes/books-catalog.php';

$bookLanguages = quizBookLanguages();
$allLanguages = quizDiscoverLanguages();
$lang = $_GET['lang'] ?? ($allLanguages[0] ?? 'English');
if (!in_array($lang, $bookLanguages, true)) {
    $lang = in_array('English', $bookLanguages, true) ? 'English' : ($bookLanguages[0] ?? 'English');
}
$catalog = quizBooksForLanguage($lang);

renderQuizHeader(
    'Download Bible Questions and Answers, the Ultimate Bible Questions Bank (PDF) - Bible Quiz',
    'Download free Bible quiz books as PDF — whole Bible, Old & New Testament, every book, characters and places.',
    $version,
    [
        'bodyClass' => 'page books-page',
        'canonical' => 'https://wordofgod.in/bible-quiz/download-books.php',
        'lang' => $lang,
    ]
);

function renderBookCard(array $b, string $lang): void
{
    $href = 'api/book-download.php?lang=' . rawurlencode($lang) . '&group=' . $b['group'] . '&file=' . rawurlencode($b['stem']);
    ?>
    <article class="book-card" data-title="<?= htmlspecialchars(mb_strtolower($b['title'])) ?>">
        <div class="book-cover">
            <?php if ($b['thumb']): ?>
                <img src="<?= htmlspecialchars($b['thumb']) ?>" alt="Cover of <?= htmlspecialchars($b['title']) ?>" width="360" height="509" loading="lazy">
            <?php else: ?>
                <span class="book-cover-fallback"><i class="bi bi-book" aria-hidden="true"></i></span>
            <?php endif; ?>
        </div>
        <div class="book-info">
            <h3 class="book-title"><?= htmlspecialchars($b['title']) ?></h3>
            <p class="book-meta"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF · <?= htmlspecialchars(quizFormatBytes($b['size'])) ?></p>
            <a class="book-download" href="<?= htmlspecialchars($href) ?>" download>
                <i class="bi bi-download" aria-hidden="true"></i> Download
            </a>
        </div>
    </article>
    <?php
}
?>

<section class="card-panel books-intro">
    <h1 class="section-title"><i class="bi bi-download" aria-hidden="true"></i> Download Books</h1>
    <p>Free printable Bible quiz books in PDF. Pick a language, choose a book, and tap <strong>Download</strong>.</p>

    <div class="books-toolbar">
        <form method="get" class="books-lang-form">
            <label for="booksLang">Language</label>
            <select name="lang" id="booksLang" onchange="this.form.submit()">
                <?php foreach ($bookLanguages as $l): ?>
                    <option value="<?= htmlspecialchars($l) ?>" <?= $l === $lang ? 'selected' : '' ?>><?= htmlspecialchars(quizLanguageDisplayName($l)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <input type="search" id="booksFilter" class="books-filter" placeholder="Search books…" aria-label="Search books">
    </div>
</section>

<div class="books-view" id="booksView">
    <?php foreach (['collections' => 'Complete Collections', 'books' => 'Books of the Bible'] as $group => $heading): ?>
        <?php if ($catalog[$group]): ?>
        <section class="card-panel books-section" data-group>
            <h2 class="section-title"><?= $heading ?> <span class="books-count"><?= count($catalog[$group]) ?></span></h2>
            <div class="books-list">
                <?php foreach ($catalog[$group] as $b) { renderBookCard($b, $lang); } ?>
            </div>
        </section>
        <?php endif; ?>
    <?php endforeach; ?>
    <p class="books-empty" id="booksEmpty" hidden>No books match your search.</p>
    <?php if (!$catalog['collections'] && !$catalog['books']): ?>
        <p class="books-empty">No books are available for this language yet.</p>
    <?php endif; ?>
</div>

<script>
(function () {
    var filter = document.getElementById('booksFilter');
    var empty = document.getElementById('booksEmpty');
    filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase(), any = false;
        document.querySelectorAll('[data-group]').forEach(function (sec) {
            var shown = 0;
            sec.querySelectorAll('.book-card').forEach(function (c) {
                var hit = !q || c.dataset.title.indexOf(q) >= 0;
                c.hidden = !hit;
                if (hit) shown++;
            });
            sec.hidden = shown === 0;
            if (shown) any = true;
        });
        empty.hidden = any;
    });
})();
</script>

<?php renderQuizFooter($version); ?>
