<?php

require_once __DIR__ . '/bible-books.php';

function indexSlug(string $value): string
{
    $slug = preg_replace('/[^\p{L}\p{M}\p{N}\-_]+/u', '-', $value);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'item';
}

function indexWriteJson(string $file, array $payload): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $payload['generated'] = date('c');
    return file_put_contents(
        $file,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    ) !== false;
}

/**
 * @return array{languages: array, errors: string[], stats: array}
 */
function buildQuizIndexes(string $dataDir, string $indexDir): array
{
    $errors = [];
    $stats = [
        'questions' => 0,
        'files_written' => 0,
    ];

    if (!is_dir($dataDir)) {
        return ['languages' => [], 'errors' => ['data/ directory not found.'], 'stats' => $stats];
    }

    $languages = [];
    foreach (scandir($dataDir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        $langPath = $dataDir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($langPath)) {
            $languages[] = $entry;
        }
    }
    sort($languages, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($languages as $lang) {
        $langPath = $dataDir . DIRECTORY_SEPARATOR . $lang;
        $langIndexRoot = $indexDir . DIRECTORY_SEPARATOR . $lang;

        $records = [];
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($langPath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'json') {
                continue;
            }
            $full = $fileInfo->getPathname();
            $rel = $lang . '/' . ltrim(str_replace($langPath, '', $full), '/\\');
            $rel = str_replace('\\', '/', $rel);

            $raw = @file_get_contents($full);
            if ($raw === false) {
                $errors[] = "Cannot read: $rel";
                continue;
            }
            $q = json_decode($raw, true);
            if (!is_array($q)) {
                $errors[] = "Invalid JSON: $rel";
                continue;
            }

            $bookNo = (int) ($q['book_no'] ?? 0);
            $chapter = (int) ($q['chapter'] ?? 0);
            $records[] = [
                'path' => $rel,
                'id' => (string) ($q['id'] ?? ''),
                'book_no' => $bookNo,
                'book' => (string) ($q['book'] ?? ''),
                'chapter' => $chapter,
                'themes' => array_values(array_filter((array) ($q['themes'] ?? []))),
                'characters' => array_values(array_filter((array) ($q['characters'] ?? []))),
                'places' => array_values(array_filter(array_map(
                    static fn ($place) => is_array($place) ? (string) ($place['name'] ?? '') : (string) $place,
                    (array) ($q['places'] ?? [])
                ))),
                'audiences' => array_values(array_map('strtolower', (array) ($q['audiences'] ?? []))),
                'difficulty' => strtolower(trim((string) ($q['difficulty'] ?? ''))),
            ];
        }

        usort($records, static fn($a, $b) => strcmp($a['path'], $b['path']));
        $stats['questions'] += count($records);

        $allPaths = array_column($records, 'path');

        $byBook = [];
        $byChapter = [];
        $byTopic = [];
        $byCharacter = [];
        $byPlace = [];
        $byAudience = ['kids' => [], 'teens' => [], 'adults' => []];
        $byDifficulty = ['easy' => [], 'medium' => [], 'difficult' => []];

        foreach ($records as $rec) {
            $bookKey = str_pad((string) $rec['book_no'], 2, '0', STR_PAD_LEFT);
            $byBook[$bookKey][] = $rec['path'];
            $chapterKey = $bookKey . '-' . str_pad((string) $rec['chapter'], 3, '0', STR_PAD_LEFT);
            $byChapter[$chapterKey][] = $rec['path'];

            foreach ($rec['themes'] as $theme) {
                $slug = indexSlug($theme);
                if (!isset($byTopic[$slug])) {
                    $byTopic[$slug] = ['label' => $theme, 'paths' => []];
                }
                $byTopic[$slug]['paths'][] = $rec['path'];
            }
            foreach ($rec['characters'] as $character) {
                $slug = indexSlug($character);
                if (!isset($byCharacter[$slug])) {
                    $byCharacter[$slug] = ['label' => $character, 'paths' => []];
                }
                $byCharacter[$slug]['paths'][] = $rec['path'];
            }
            foreach ($rec['places'] as $place) {
                $slug = indexSlug($place);
                if (!isset($byPlace[$slug])) {
                    $byPlace[$slug] = ['label' => $place, 'paths' => []];
                }
                $byPlace[$slug]['paths'][] = $rec['path'];
            }
            foreach ($rec['audiences'] as $aud) {
                if (isset($byAudience[$aud])) {
                    $byAudience[$aud][] = $rec['path'];
                }
            }
            if (isset($byDifficulty[$rec['difficulty']])) {
                $byDifficulty[$rec['difficulty']][] = $rec['path'];
            }
        }

        $catalogBooks = [];
        $bookNames = bibleBookNames();
        foreach ($byBook as $bookKey => $paths) {
            $bookKey = str_pad((string) $bookKey, 2, '0', STR_PAD_LEFT);
            $bookNo = (int) $bookKey;
            $chapters = [];
            foreach ($byChapter as $chKey => $chPaths) {
                if (str_starts_with($chKey, $bookKey . '-')) {
                    $chapters[] = (int) substr($chKey, 3);
                }
            }
            sort($chapters);
            $displayName = $bookNames[$bookNo] ?? "Book $bookNo";
            foreach ($records as $rec) {
                if ($rec['book_no'] === $bookNo && $rec['book'] !== '') {
                    $displayName = $rec['book'];
                    break;
                }
            }
            $catalogBooks[] = [
                'no' => $bookNo,
                'key' => $bookKey,
                'name' => $displayName,
                'chapters' => $chapters,
                'question_count' => count($paths),
            ];
        }
        usort($catalogBooks, static fn($a, $b) => $a['no'] <=> $b['no']);

        $catalogTopics = [];
        foreach ($byTopic as $slug => $info) {
            $catalogTopics[] = [
                'slug' => $slug,
                'label' => $info['label'],
                'question_count' => count($info['paths']),
            ];
        }
        usort($catalogTopics, static fn($a, $b) => strcasecmp($a['label'], $b['label']));

        $catalogCharacters = [];
        foreach ($byCharacter as $slug => $info) {
            $catalogCharacters[] = [
                'slug' => $slug,
                'label' => $info['label'],
                'question_count' => count($info['paths']),
            ];
        }
        usort($catalogCharacters, static fn($a, $b) => strcasecmp($a['label'], $b['label']));

        $catalogPlaces = [];
        foreach ($byPlace as $slug => $info) {
            $catalogPlaces[] = [
                'slug' => $slug,
                'label' => $info['label'],
                'question_count' => count($info['paths']),
            ];
        }
        usort($catalogPlaces, static fn($a, $b) => strcasecmp($a['label'], $b['label']));

        $catalog = [
            'language' => $lang,
            'question_count' => count($allPaths),
            'books' => $catalogBooks,
            'topics' => $catalogTopics,
            'characters' => $catalogCharacters,
            'places' => $catalogPlaces,
            'audiences' => [
                'kids' => count($byAudience['kids']),
                'teens' => count($byAudience['teens']),
                'adults' => count($byAudience['adults']),
            ],
            'difficulties' => [
                'easy' => count($byDifficulty['easy']),
                'medium' => count($byDifficulty['medium']),
                'difficult' => count($byDifficulty['difficult']),
            ],
        ];
        if (indexWriteJson($langIndexRoot . '/catalog.json', $catalog)) {
            $stats['files_written']++;
        } else {
            $errors[] = "Cannot write catalog for $lang";
        }

        $typeWriters = [
            'random' => $allPaths,
            'whole-bible' => $allPaths,
            'old-testament' => array_values(array_filter($allPaths, static function ($path) use ($records) {
                foreach ($records as $rec) {
                    if ($rec['path'] === $path) {
                        return isOldTestamentBook($rec['book_no']);
                    }
                }
                return false;
            })),
            'new-testament' => array_values(array_filter($allPaths, static function ($path) use ($records) {
                foreach ($records as $rec) {
                    if ($rec['path'] === $path) {
                        return isNewTestamentBook($rec['book_no']);
                    }
                }
                return false;
            })),
            'kids' => array_values(array_unique($byAudience['kids'])),
            'teens' => array_values(array_unique($byAudience['teens'])),
            'adults' => array_values(array_unique($byAudience['adults'])),
        ];

        foreach ($typeWriters as $type => $paths) {
            $paths = array_values($paths);
            if ($type === 'random') {
                shuffle($paths);
            }
            $payload = [
                'type' => $type,
                'language' => $lang,
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/types/' . $type . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }

        foreach ($byBook as $bookKey => $paths) {
            $payload = [
                'type' => 'book',
                'language' => $lang,
                'book' => $bookKey,
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/book/' . $bookKey . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }

        foreach ($byChapter as $chapterKey => $paths) {
            $payload = [
                'type' => 'chapter',
                'language' => $lang,
                'chapter' => $chapterKey,
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/chapter/' . $chapterKey . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }

        foreach ($byTopic as $slug => $info) {
            $paths = array_values($info['paths']);
            $payload = [
                'type' => 'topic',
                'language' => $lang,
                'topic' => $slug,
                'label' => $info['label'],
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/topic/' . $slug . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }

        foreach ($byCharacter as $slug => $info) {
            $paths = array_values($info['paths']);
            $payload = [
                'type' => 'character',
                'language' => $lang,
                'character' => $slug,
                'label' => $info['label'],
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/character/' . $slug . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }

        foreach ($byPlace as $slug => $info) {
            $paths = array_values($info['paths']);
            $payload = [
                'type' => 'place',
                'language' => $lang,
                'place' => $slug,
                'label' => $info['label'],
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/place/' . $slug . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }

        foreach ($byDifficulty as $diffKey => $paths) {
            $payload = [
                'type' => 'difficulty',
                'language' => $lang,
                'difficulty' => $diffKey,
                'count' => count($paths),
                'paths' => $paths,
            ];
            $file = $langIndexRoot . '/difficulty/' . $diffKey . '.json';
            if (indexWriteJson($file, $payload)) {
                $stats['files_written']++;
            } else {
                $errors[] = "Cannot write $file";
            }
        }
    }

    return ['languages' => $languages, 'errors' => $errors, 'stats' => $stats];
}
