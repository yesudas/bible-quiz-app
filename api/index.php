<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$lang = $_GET['lang'] ?? 'English';
$type = $_GET['type'] ?? 'random';
$lang = str_replace(["\0", '/', '\\'], '', $lang);
$type = preg_replace('/[^a-z0-9\-]/', '', strtolower($type));

$book = $_GET['book'] ?? '';
$chapter = $_GET['chapter'] ?? '';
$topic = $_GET['topic'] ?? '';
$character = $_GET['character'] ?? '';
$place = $_GET['place'] ?? '';

$topic = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', $topic);
$character = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', $character);
$place = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', $place);

/**
 * @return list<string>
 */
function normalizeBookKeys(mixed $booksParam, string $singleBook): array
{
    $raw = [];
    if ($booksParam !== null && $booksParam !== '') {
        $raw = is_array($booksParam) ? $booksParam : explode(',', (string) $booksParam);
    } elseif ($singleBook !== '') {
        $raw = [$singleBook];
    }

    $keys = [];
    foreach ($raw as $item) {
        $digits = preg_replace('/[^0-9]/', '', (string) $item);
        if ($digits === '') {
            continue;
        }
        $key = str_pad($digits, 2, '0', STR_PAD_LEFT);
        if (preg_match('/^\d{2}$/', $key)) {
            $keys[] = $key;
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys, SORT_NATURAL);
    return $keys;
}

/**
 * @return list<string>
 */
function normalizeChapterKeys(mixed $chaptersParam, string $singleChapter): array
{
    $raw = [];
    if ($chaptersParam !== null && $chaptersParam !== '') {
        $raw = is_array($chaptersParam) ? $chaptersParam : explode(',', (string) $chaptersParam);
    } elseif ($singleChapter !== '') {
        $raw = [$singleChapter];
    }

    $keys = [];
    foreach ($raw as $item) {
        $ch = preg_replace('/[^0-9\-]/', '', (string) $item);
        if (preg_match('/^\d{2}-\d{3}$/', $ch)) {
            $keys[] = $ch;
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys, SORT_NATURAL);
    return $keys;
}

/**
 * @return list<string>
 */
function normalizeDifficultyKeys(mixed $difficultyParam): array
{
    $raw = [];
    if ($difficultyParam !== null && $difficultyParam !== '') {
        $raw = is_array($difficultyParam) ? $difficultyParam : explode(',', (string) $difficultyParam);
    }

    $allowed = ['easy', 'medium', 'difficult'];
    $keys = [];
    foreach ($raw as $item) {
        $key = strtolower(preg_replace('/[^a-z]/i', '', (string) $item));
        if (in_array($key, $allowed, true)) {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

/**
 * @return list<string>
 */
function mergeIndexPaths(string $indexRoot, string $lang, string $subdir, array $keys): array
{
    $merged = [];
    foreach ($keys as $key) {
        $file = $indexRoot . '/' . $lang . '/' . $subdir . '/' . $key . '.json';
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, $indexRoot . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $raw = file_get_contents($real);
        if ($raw === false) {
            continue;
        }
        $dec = json_decode($raw, true);
        if (!is_array($dec)) {
            continue;
        }
        foreach ($dec['paths'] ?? [] as $path) {
            if (is_string($path) && $path !== '') {
                $merged[$path] = true;
            }
        }
    }
    $paths = array_keys($merged);
    sort($paths, SORT_NATURAL);
    return $paths;
}

/**
 * @param list<string> $paths
 * @param list<string>|null $allowedPaths When given, $paths is intersected with this set first.
 */
function outputMergedIndex(string $type, string $lang, array $paths, array $meta = [], ?array $allowedPaths = null): void
{
    if ($allowedPaths !== null) {
        $allowedSet = array_flip($allowedPaths);
        $paths = array_values(array_filter($paths, static fn (string $p) => isset($allowedSet[$p])));
    }

    if ($paths === []) {
        http_response_code(404);
        echo json_encode(['error' => 'Quiz index not found for this selection.']);
        exit;
    }
    echo json_encode(array_merge([
        'type' => $type,
        'language' => $lang,
        'count' => count($paths),
        'paths' => $paths,
        'generated' => date('c'),
    ], $meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return list<string>
 */
function readIndexPaths(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !is_array($data['paths'] ?? null)) {
        return [];
    }
    return array_values(array_filter($data['paths'], 'is_string'));
}

$indexRoot = realpath(__DIR__ . '/../index');
if ($indexRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Index folder missing']);
    exit;
}

$bookKeys = normalizeBookKeys($_GET['books'] ?? null, $book);
$chapterKeys = normalizeChapterKeys($_GET['chapters'] ?? null, $chapter);
$difficultyKeys = normalizeDifficultyKeys($_GET['difficulty'] ?? null);

$allowedPaths = $difficultyKeys !== []
    ? mergeIndexPaths($indexRoot, $lang, 'difficulty', $difficultyKeys)
    : null;
$difficultyMeta = $difficultyKeys !== [] ? ['difficulty' => $difficultyKeys] : [];

$file = null;

$simpleTypes = ['random', 'whole-bible', 'old-testament', 'new-testament', 'kids', 'teens', 'adults'];
if (in_array($type, $simpleTypes, true)) {
    $file = $indexRoot . '/' . $lang . '/types/' . $type . '.json';
} elseif ($type === 'book') {
    if (count($bookKeys) === 1) {
        $file = $indexRoot . '/' . $lang . '/book/' . $bookKeys[0] . '.json';
    } elseif (count($bookKeys) > 1) {
        outputMergedIndex(
            'book',
            $lang,
            mergeIndexPaths($indexRoot, $lang, 'book', $bookKeys),
            array_merge(['books' => $bookKeys], $difficultyMeta),
            $allowedPaths
        );
    }
} elseif ($type === 'chapter') {
    if (count($bookKeys) > 1) {
        outputMergedIndex(
            'chapter',
            $lang,
            mergeIndexPaths($indexRoot, $lang, 'book', $bookKeys),
            array_merge(['books' => $bookKeys], $difficultyMeta),
            $allowedPaths
        );
    }
    if (count($bookKeys) === 1 && $chapterKeys !== []) {
        foreach ($chapterKeys as $chKey) {
            if (!str_starts_with($chKey, $bookKeys[0] . '-')) {
                http_response_code(400);
                echo json_encode(['error' => 'Selected chapters must belong to the chosen book.']);
                exit;
            }
        }
        if (count($chapterKeys) === 1) {
            $file = $indexRoot . '/' . $lang . '/chapter/' . $chapterKeys[0] . '.json';
        } else {
            outputMergedIndex(
                'chapter',
                $lang,
                mergeIndexPaths($indexRoot, $lang, 'chapter', $chapterKeys),
                array_merge(['books' => $bookKeys, 'chapters' => $chapterKeys], $difficultyMeta),
                $allowedPaths
            );
        }
    } elseif (count($bookKeys) === 0 && $chapterKeys !== []) {
        if (count($chapterKeys) === 1) {
            $file = $indexRoot . '/' . $lang . '/chapter/' . $chapterKeys[0] . '.json';
        } else {
            outputMergedIndex(
                'chapter',
                $lang,
                mergeIndexPaths($indexRoot, $lang, 'chapter', $chapterKeys),
                array_merge(['chapters' => $chapterKeys], $difficultyMeta),
                $allowedPaths
            );
        }
    }
} elseif ($type === 'topic' && $topic !== '') {
    $file = $indexRoot . '/' . $lang . '/topic/' . $topic . '.json';
} elseif ($type === 'character' && $character !== '') {
    $file = $indexRoot . '/' . $lang . '/character/' . $character . '.json';
} elseif ($type === 'place' && $place !== '') {
    $file = $indexRoot . '/' . $lang . '/place/' . $place . '.json';
}

if ($file === null || !is_file($file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz index not found for this selection.']);
    exit;
}

$real = realpath($file);
if ($real === false || !str_starts_with($real, $indexRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($allowedPaths === null) {
    $raw = file_get_contents($real);
    if ($raw === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot read index']);
        exit;
    }
    echo $raw;
    exit;
}

outputMergedIndex($type, $lang, readIndexPaths($real), $difficultyMeta, $allowedPaths);
