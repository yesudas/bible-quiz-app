<?php
/**
 * Reported quiz mistakes — stored one JSON object per line in logs/mistake-reports.log.
 * Written by api/report.php, read/edited/dismissed from a.php?view=mistakes.
 */

const QUIZ_MISTAKE_TYPES = [
    'question' => 'Mistake in the question',
    'answer'   => 'Mistake in the answer',
    'both'     => 'Mistake in question and answer',
];

function quizMistakeLogFile(): string
{
    return __DIR__ . '/../logs/mistake-reports.log';
}

/** Creates logs/ (with an .htaccess that blocks web access) on first use. */
function quizMistakeEnsureLogDir(): void
{
    $dir = dirname(quizMistakeLogFile());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
}

/**
 * Resolves a question path (relative to data/) to an absolute .json file inside data/.
 * Returns the absolute path, or false if invalid.
 */
function quizResolveQuestionPath(string $rel): string|false
{
    $dataRoot = realpath(__DIR__ . '/../data');
    $rel = str_replace(['\\', "\0"], ['/', ''], $rel);
    if ($dataRoot === false || $rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
        return false;
    }
    $full = realpath($dataRoot . '/' . $rel);
    if ($full === false || !str_starts_with($full, $dataRoot . DIRECTORY_SEPARATOR) || !is_file($full)) {
        return false;
    }
    return strtolower(pathinfo($full, PATHINFO_EXTENSION)) === 'json' ? $full : false;
}

/** @return list<array<string, mixed>> oldest first */
function quizMistakeReadAll(): array
{
    $file = quizMistakeLogFile();
    $raw = is_file($file) ? (string) @file_get_contents($file) : '';
    $rows = [];
    foreach (explode("\n", $raw) as $line) {
        $row = json_decode(trim($line), true);
        if (is_array($row) && isset($row['id'])) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Runs $mutate(rows) under an exclusive lock and writes back the rows it returns.
 * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $mutate
 */
function quizMistakeMutate(callable $mutate): bool
{
    quizMistakeEnsureLogDir();
    $fh = @fopen(quizMistakeLogFile(), 'c+');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    $rows = [];
    foreach (explode("\n", (string) stream_get_contents($fh)) as $line) {
        $row = json_decode(trim($line), true);
        if (is_array($row) && isset($row['id'])) {
            $rows[] = $row;
        }
    }
    $rows = $mutate($rows);
    $out = '';
    foreach ($rows as $row) {
        $out .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $out);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

/**
 * Logs a report. A repeat report of the same question + type bumps `count` instead
 * of adding another row.
 */
function quizMistakeAdd(string $relPath, string $type): bool
{
    $full = quizResolveQuestionPath($relPath);
    if ($full === false || !isset(QUIZ_MISTAKE_TYPES[$type])) {
        return false;
    }
    $q = json_decode((string) @file_get_contents($full), true);
    $q = is_array($q) ? $q : [];
    $relPath = str_replace('\\', '/', $relPath);
    $segments = explode('/', $relPath);
    $fileNumber = (int) (preg_split('/-/', pathinfo($full, PATHINFO_FILENAME))[2] ?? 0);

    return quizMistakeMutate(static function (array $rows) use ($relPath, $type, $segments, $q, $fileNumber): array {
        foreach ($rows as &$row) {
            if (($row['path'] ?? '') === $relPath && ($row['type'] ?? '') === $type) {
                $row['count'] = (int) ($row['count'] ?? 1) + 1;
                $row['last_reported'] = date('Y-m-d H:i:s');
                return $rows;
            }
        }
        unset($row);
        $rows[] = [
            'id'            => bin2hex(random_bytes(6)),
            'timestamp'     => date('Y-m-d H:i:s'),
            'last_reported' => date('Y-m-d H:i:s'),
            'count'         => 1,
            'type'          => $type,
            'language'      => $segments[0] ?? '',
            'book_no'       => $q['book_no'] ?? null,
            'book'          => $q['book'] ?? '',
            'chapter'       => $q['chapter'] ?? null,
            'question_no'   => $fileNumber,
            'question_id'   => $q['id'] ?? '',
            'path'          => $relPath,
        ];
        return $rows;
    });
}

function quizMistakeDismiss(string $id): bool
{
    return quizMistakeMutate(static fn (array $rows): array => array_values(
        array_filter($rows, static fn (array $r): bool => ($r['id'] ?? '') !== $id)
    ));
}
