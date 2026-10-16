<?php

/**
 * Share-link counter storage: a single JSON object in counters/shares.txt
 * (gitignored, protected by counters/.htaccess) shaped as:
 *   {"total": 1234}
 *
 * Incremented whenever a visit arrives via a share link (a non-empty
 * ?test=... query parameter), regardless of whether that token turns out to
 * be valid/expired — it's a count of share-link clicks, not of successful
 * quiz starts.
 */

function quizShareCounterFile(): string
{
    return __DIR__ . '/../counters/shares.txt';
}

/**
 * @param mixed $decoded
 * @return array{total:int}
 */
function quizNormalizeShareCounts(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return ['total' => 0];
    }

    return ['total' => (int) ($decoded['total'] ?? 0)];
}

/**
 * Increments the share-link visit count and returns the updated total.
 * Safe under concurrent requests (exclusive file lock).
 *
 * @return array{total:int}
 */
function quizIncrementShareCount(): array
{
    $file = quizShareCounterFile();

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
        $counts = quizNormalizeShareCounts(json_decode(trim((string) $raw), true));

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
 * Reads the current share count without incrementing anything (used by the
 * admin panel to display stats).
 *
 * @return array{total:int}
 */
function quizReadShareCounts(): array
{
    $file = quizShareCounterFile();
    if (!is_file($file)) {
        return ['total' => 0];
    }

    $raw = file_get_contents($file);
    return quizNormalizeShareCounts($raw !== false ? json_decode(trim($raw), true) : null);
}
