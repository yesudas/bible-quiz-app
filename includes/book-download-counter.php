<?php

/**
 * Book-download counter: counters/book-downloads.txt (gitignored, protected
 * by counters/.htaccess), shaped as
 *   {"total": 120, "languages": {"English": 80, "தமிழ்": 40}}
 *
 * Incremented by api/book-download.php when a download of a book PDF starts
 * (first byte range only, so resumed/partial requests aren't double-counted;
 * bots excluded). Separate from the quiz-PDF "Downloads" counter.
 */

function quizBookDownloadCounterFile(): string
{
    return __DIR__ . '/../counters/book-downloads.txt';
}

/** @return array{total:int, languages: array<string,int>} */
function quizNormalizeBookDownloadCounts(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return ['total' => 0, 'languages' => []];
    }
    $languages = is_array($decoded['languages'] ?? null) ? $decoded['languages'] : [];
    return ['total' => (int) ($decoded['total'] ?? 0), 'languages' => array_map('intval', $languages)];
}

/** @return array{total:int, languages: array<string,int>} */
function quizIncrementBookDownloadCount(string $lang): array
{
    $file = quizBookDownloadCounterFile();
    if (!file_exists($file)) {
        @file_put_contents($file, json_encode(['total' => 0, 'languages' => []]), LOCK_EX);
    }
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return ['total' => 0, 'languages' => []];
    }
    $counts = ['total' => 0, 'languages' => []];
    if (flock($fp, LOCK_EX)) {
        $counts = quizNormalizeBookDownloadCounts(json_decode(trim((string) stream_get_contents($fp)), true));
        $counts['total']++;
        if ($lang !== '') {
            $counts['languages'][$lang] = ($counts['languages'][$lang] ?? 0) + 1;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($counts, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $counts;
}

/** @return array{total:int, languages: array<string,int>} */
function quizReadBookDownloadCounts(): array
{
    $file = quizBookDownloadCounterFile();
    if (!is_file($file)) {
        return ['total' => 0, 'languages' => []];
    }
    $raw = file_get_contents($file);
    return quizNormalizeBookDownloadCounts($raw !== false ? json_decode(trim($raw), true) : null);
}

/** Language with the most book downloads, or '' if none yet. */
function quizTopBookDownloadLanguage(array $counts): string
{
    $langs = array_filter($counts['languages'] ?? [], static fn (int $c): bool => $c > 0);
    if (!$langs) {
        return '';
    }
    arsort($langs);
    return (string) array_key_first($langs);
}
