<?php

/**
 * Downloadable book PDFs (books/{Language}/*.pdf and books/{Language}/books/*.pdf)
 * plus their cover thumbnails (assets/book-thumbs/{Language}/*.jpg, made by
 * tools/generate-book-thumbs.py). Used by download-books.php and
 * api/book-download.php.
 */

/** Language folders that actually have book PDFs. */
function quizBookLanguages(): array
{
    $langs = [];
    $dir = __DIR__ . '/../books';
    if (!is_dir($dir)) {
        return $langs;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry[0] === '.' || !is_dir("$dir/$entry")) {
            continue;
        }
        if (glob("$dir/$entry/*.pdf") || glob("$dir/$entry/books/*.pdf")) {
            $langs[] = $entry;
        }
    }
    sort($langs, SORT_NATURAL);
    return $langs;
}

/** "01-Genesis-WOGBOOKS202" → "Genesis"; "10-2-சாமுவேல்-WOGBOOKS201" → "2 சாமுவேல்". */
function quizBookTitle(string $stem): string
{
    $t = preg_replace('/-WOGBOOKS\d*$/u', '', $stem);
    $t = preg_replace('/^\d{2}-/u', '', $t);
    return trim(str_replace('-', ' ', $t));
}

/**
 * @return array{collections: array, books: array} each item:
 *   stem, title, group ('collections'|'books'), size (bytes), thumb (URL|null), pdf (relative path)
 */
function quizBooksForLanguage(string $lang): array
{
    $out = ['collections' => [], 'books' => []];
    if (!in_array($lang, quizBookLanguages(), true)) {
        return $out;
    }
    $base = __DIR__ . '/../books/' . $lang;
    $groups = ['collections' => $base, 'books' => $base . '/books'];
    foreach ($groups as $group => $dir) {
        foreach (glob($dir . '/*.pdf') ?: [] as $pdf) {
            $stem = basename($pdf, '.pdf');
            $thumbFile = __DIR__ . '/../assets/book-thumbs/' . $lang . '/' . $stem . '.jpg';
            $out[$group][] = [
                'stem' => $stem,
                'title' => quizBookTitle($stem),
                'group' => $group,
                'size' => (int) filesize($pdf),
                'thumb' => is_file($thumbFile)
                    ? 'assets/book-thumbs/' . rawurlencode($lang) . '/' . rawurlencode($stem) . '.jpg?v=' . filemtime($thumbFile)
                    : null,
            ];
        }
    }
    // Books keep Bible order (the NN- prefix); collections go whole Bible,
    // OT, NT first (stems vary by language), then the rest alphabetically.
    usort($out['books'], static fn($a, $b) => strnatcmp($a['stem'], $b['stem']));
    $preferred = ['whole-bible', 'முழு-வேதாகமம்', 'old-testament', 'பழைய-ஏற்பாடு', 'new-testament', 'புதிய-ஏற்பாடு'];
    $rank = static function (array $item) use ($preferred): int {
        $name = preg_replace('/-WOGBOOKS\d*$/u', '', $item['stem']);
        $i = array_search($name, $preferred, true);
        return $i === false ? 100 : (int) $i;
    };
    usort($out['collections'], static fn($a, $b) => [$rank($a), $a['stem']] <=> [$rank($b), $b['stem']]);
    return $out;
}

/** Absolute PDF path for a (lang, group, stem), or null if it doesn't resolve inside books/. */
function quizBookResolvePdf(string $lang, string $group, string $stem): ?string
{
    if (!in_array($lang, quizBookLanguages(), true) || !preg_match('/^[^\/\\\\\0]+$/u', $stem) || $stem[0] === '.') {
        return null;
    }
    $root = realpath(__DIR__ . '/../books/' . $lang);
    $path = realpath($root . ($group === 'books' ? '/books/' : '/') . $stem . '.pdf');
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    return $path;
}

function quizFormatBytes(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}
