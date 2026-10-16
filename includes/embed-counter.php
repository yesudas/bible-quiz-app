<?php

/**
 * Embed-view counter storage: a single JSON object in counters/embeds.txt
 * (gitignored, protected by counters/.htaccess) shaped as:
 *   {"total": 1234}
 *
 * Incremented by counter.php whenever a non-bot request is served by
 * embed.php (the iframe-embeddable widget) — i.e. once per page load of the
 * embedded quiz on a third-party site, same granularity as the visitor
 * counter for regular pages.
 */

function quizEmbedCounterFile(): string
{
    return __DIR__ . '/../counters/embeds.txt';
}

/**
 * @param mixed $decoded
 * @return array{total:int}
 */
function quizNormalizeEmbedCounts(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return ['total' => 0];
    }

    return ['total' => (int) ($decoded['total'] ?? 0)];
}

/**
 * Increments the embed-view count and returns the updated total.
 * Safe under concurrent requests (exclusive file lock).
 *
 * @return array{total:int}
 */
function quizIncrementEmbedCount(): array
{
    $file = quizEmbedCounterFile();

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
        $counts = quizNormalizeEmbedCounts(json_decode(trim((string) $raw), true));

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
 * Reads the current embed-view count without incrementing anything (used by
 * the footer on every page, and by the admin panel to display stats).
 *
 * @return array{total:int}
 */
function quizReadEmbedCounts(): array
{
    $file = quizEmbedCounterFile();
    if (!is_file($file)) {
        return ['total' => 0];
    }

    $raw = file_get_contents($file);
    return quizNormalizeEmbedCounts($raw !== false ? json_decode(trim($raw), true) : null);
}
