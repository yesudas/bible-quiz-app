<?php

/**
 * PDF-download counter storage: a single JSON object in
 * counters/downloads.txt (gitignored, protected by counters/.htaccess)
 * shaped as:
 *   {"total": 1234}
 *
 * Incremented via api/download.php — a fire-and-forget POST the "Download
 * PDF" button on index.php's setup form makes once its two PDFs (questions +
 * answer key) have both been generated and saved client-side, same pattern
 * as api/stats.php reporting a finished set. One click/attempt = one
 * increment, regardless of the two files it produces.
 */

function quizDownloadCounterFile(): string
{
    return __DIR__ . '/../counters/downloads.txt';
}

/**
 * @param mixed $decoded
 * @return array{total:int}
 */
function quizNormalizeDownloadCounts(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return ['total' => 0];
    }

    return ['total' => (int) ($decoded['total'] ?? 0)];
}

/**
 * Increments the PDF-download count and returns the updated total.
 * Safe under concurrent requests (exclusive file lock).
 *
 * @return array{total:int}
 */
function quizIncrementDownloadCount(): array
{
    $file = quizDownloadCounterFile();

    if (!file_exists($file)) {
        @file_put_contents($file, json_encode(['total' => 0]), LOCK_EX);
    }

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return ['total' => 0];
    }

    $counts = ['total' => 0];

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $counts = quizNormalizeDownloadCounts(json_decode(trim((string) $raw), true));

        $counts['total']++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($counts, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    return $counts;
}

/**
 * Reads the current PDF-download count without incrementing anything (used
 * by the footer on every page).
 *
 * @return array{total:int}
 */
function quizReadDownloadCounts(): array
{
    $file = quizDownloadCounterFile();
    if (!is_file($file)) {
        return ['total' => 0];
    }

    $raw = file_get_contents($file);
    return quizNormalizeDownloadCounts($raw !== false ? json_decode(trim($raw), true) : null);
}
