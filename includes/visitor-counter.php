<?php

/**
 * Visitor counter storage: a single JSON object in counters/counter.txt
 * (gitignored, protected by counters/.htaccess) shaped as:
 *   {"total": 1234, "languages": {"English": 800, "தமிழ்": 434}}
 *
 * A pre-existing counter.txt holding a bare integer (the old format) is
 * migrated in place the next time it's written.
 */

function quizVisitorCounterFile(): string
{
    return __DIR__ . '/../counters/counter.txt';
}

/**
 * @param mixed $decoded
 * @return array{total:int, languages: array<string,int>}
 */
function quizNormalizeVisitorCounts(mixed $decoded): array
{
    if (is_int($decoded)) {
        // Legacy counter.txt held a bare integer — migrate it into the new shape.
        return ['total' => $decoded, 'languages' => []];
    }
    if (!is_array($decoded)) {
        return ['total' => 0, 'languages' => []];
    }

    $languages = is_array($decoded['languages'] ?? null) ? $decoded['languages'] : [];

    return [
        'total' => (int) ($decoded['total'] ?? 0),
        'languages' => array_map('intval', $languages),
    ];
}

/**
 * Increments the overall and per-language visitor counts and returns the
 * updated totals. Safe under concurrent requests (exclusive file lock).
 *
 * @return array{total:int, languages: array<string,int>}
 */
function quizIncrementVisitorCount(string $lang): array
{
    $file = quizVisitorCounterFile();

    if (!file_exists($file)) {
        @file_put_contents($file, json_encode(['total' => 0, 'languages' => []]), LOCK_EX);
    }

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return ['total' => 0, 'languages' => []];
    }

    $counts = ['total' => 0, 'languages' => []];

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $counts = quizNormalizeVisitorCounts(json_decode(trim((string) $raw), true));

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

/**
 * Reads the current visitor counts without incrementing anything (used by
 * the admin panel to display stats).
 *
 * @return array{total:int, languages: array<string,int>}
 */
function quizReadVisitorCounts(): array
{
    $file = quizVisitorCounterFile();
    if (!is_file($file)) {
        return ['total' => 0, 'languages' => []];
    }

    $raw = file_get_contents($file);
    return quizNormalizeVisitorCounts($raw !== false ? json_decode(trim($raw), true) : null);
}
