<?php

/**
 * Quiz-activity counters: how many sets have been finished, how many
 * questions answered, and how those questions break down by book — each in
 * its own gitignored JSON file under counters/ (same protected-folder
 * pattern as counter.txt/shares.txt), one counter per file so a future
 * counter can be added without touching these.
 *
 * All three are updated from api/stats.php when a quiz set finishes
 * (assets/js/quiz.js's finishSet()), and are also directly editable from the
 * admin screen (a.php?view=stats) via the quizSet*() functions below, which
 * overwrite rather than increment.
 */

function quizStatsQuizzesFile(): string
{
    return __DIR__ . '/../counters/quizzes-completed.txt';
}

function quizStatsQuestionsFile(): string
{
    return __DIR__ . '/../counters/questions-answered.txt';
}

function quizStatsBooksFile(): string
{
    return __DIR__ . '/../counters/questions-by-book.txt';
}

/**
 * Reads a counter file (under an exclusive lock when $mutate is given,
 * mutating the decoded array in place and writing the result back) or just
 * returns the normalized current contents when $mutate is null.
 *
 * @param array<string,mixed> $default
 * @param (callable(array<string,mixed> &$data): void)|null $mutate
 * @return array<string,mixed>
 */
function quizStatsLockedUpdate(string $file, array $default, ?callable $mutate): array
{
    if ($mutate === null) {
        if (!is_file($file)) {
            return $default;
        }
        $raw = file_get_contents($file);
        $decoded = $raw !== false ? json_decode(trim($raw), true) : null;
        return is_array($decoded) ? array_replace($default, $decoded) : $default;
    }

    if (!file_exists($file)) {
        @file_put_contents($file, json_encode($default), LOCK_EX);
    }

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return $default;
    }

    $data = $default;

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $decoded = json_decode(trim((string) $raw), true);
        $data = is_array($decoded) ? array_replace($default, $decoded) : $default;

        $mutate($data);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    return $data;
}

/**
 * @return array{total:int, languages: array<string,int>}
 */
function quizIncrementQuizCompleted(string $lang): array
{
    return quizStatsLockedUpdate(quizStatsQuizzesFile(), ['total' => 0, 'languages' => []], function (array &$data) use ($lang): void {
        $data['total'] = (int) ($data['total'] ?? 0) + 1;
        if ($lang !== '') {
            $data['languages'][$lang] = (int) ($data['languages'][$lang] ?? 0) + 1;
        }
    });
}

/**
 * @return array{total:int, languages: array<string,int>}
 */
function quizReadQuizzesCompleted(): array
{
    return quizStatsLockedUpdate(quizStatsQuizzesFile(), ['total' => 0, 'languages' => []], null);
}

/**
 * Overwrites the per-language breakdown (admin edit) and recomputes the
 * total as the sum of those languages.
 *
 * @param array<string,int> $languages
 * @return array{total:int, languages: array<string,int>}
 */
function quizSetQuizzesCompleted(array $languages): array
{
    $languages = array_map('intval', $languages);
    return quizStatsLockedUpdate(quizStatsQuizzesFile(), ['total' => 0, 'languages' => []], function (array &$data) use ($languages): void {
        $data['languages'] = $languages;
        $data['total'] = array_sum($languages);
    });
}

/**
 * @return array{total:int, languages: array<string,int>}
 */
function quizIncrementQuestionsAnswered(string $lang, int $count): array
{
    if ($count <= 0) {
        return quizReadQuestionsAnswered();
    }
    return quizStatsLockedUpdate(quizStatsQuestionsFile(), ['total' => 0, 'languages' => []], function (array &$data) use ($lang, $count): void {
        $data['total'] = (int) ($data['total'] ?? 0) + $count;
        if ($lang !== '') {
            $data['languages'][$lang] = (int) ($data['languages'][$lang] ?? 0) + $count;
        }
    });
}

/**
 * @return array{total:int, languages: array<string,int>}
 */
function quizReadQuestionsAnswered(): array
{
    return quizStatsLockedUpdate(quizStatsQuestionsFile(), ['total' => 0, 'languages' => []], null);
}

/**
 * Overwrites the per-language breakdown (admin edit) and recomputes the
 * total as the sum of those languages.
 *
 * @param array<string,int> $languages
 * @return array{total:int, languages: array<string,int>}
 */
function quizSetQuestionsAnswered(array $languages): array
{
    $languages = array_map('intval', $languages);
    return quizStatsLockedUpdate(quizStatsQuestionsFile(), ['total' => 0, 'languages' => []], function (array &$data) use ($languages): void {
        $data['languages'] = $languages;
        $data['total'] = array_sum($languages);
    });
}

/**
 * Adds this set's per-book question counts to both that language's
 * breakdown and the cross-language total.
 *
 * @param array<string,int> $bookCounts book number (as string) => count of
 *     questions from that book in this set
 * @return array{total: array<string,int>, languages: array<string,array<string,int>>}
 */
function quizIncrementQuestionsByBook(string $lang, array $bookCounts): array
{
    $bookCounts = array_filter(array_map('intval', $bookCounts), static fn (int $c): bool => $c > 0);
    if (!$bookCounts) {
        return quizReadQuestionsByBook();
    }

    return quizStatsLockedUpdate(quizStatsBooksFile(), ['total' => [], 'languages' => []], function (array &$data) use ($lang, $bookCounts): void {
        foreach ($bookCounts as $book => $count) {
            $book = (string) $book;
            $data['total'][$book] = (int) ($data['total'][$book] ?? 0) + $count;
            if ($lang !== '') {
                $data['languages'][$lang][$book] = (int) ($data['languages'][$lang][$book] ?? 0) + $count;
            }
        }
    });
}

/**
 * @return array{total: array<string,int>, languages: array<string,array<string,int>>}
 */
function quizReadQuestionsByBook(): array
{
    return quizStatsLockedUpdate(quizStatsBooksFile(), ['total' => [], 'languages' => []], null);
}

/**
 * Overwrites one language's per-book breakdown (admin edit) and recomputes
 * the cross-language `total` for every book from scratch.
 *
 * @param array<string,int> $bookCounts book number (as string) => count
 * @return array{total: array<string,int>, languages: array<string,array<string,int>>}
 */
function quizSetQuestionsByBookForLanguage(string $lang, array $bookCounts): array
{
    $bookCounts = array_filter(array_map('intval', $bookCounts), static fn (int $c): bool => $c > 0);

    return quizStatsLockedUpdate(quizStatsBooksFile(), ['total' => [], 'languages' => []], function (array &$data) use ($lang, $bookCounts): void {
        if ($bookCounts) {
            $data['languages'][$lang] = $bookCounts;
        } else {
            unset($data['languages'][$lang]);
        }

        $totals = [];
        foreach ($data['languages'] as $langCounts) {
            foreach ($langCounts as $book => $count) {
                $totals[$book] = ($totals[$book] ?? 0) + (int) $count;
            }
        }
        $data['total'] = $totals;
    });
}
