<?php

require_once __DIR__ . '/../partials.php';

const QUIZ_SHARE_VERSION = 1;
const QUIZ_SHARE_SIG_BYTES = 6;

/**
 * Secret used to sign share tokens. Auto-generated and persisted (gitignored)
 * on first use so the feature works without manual setup; copy
 * share-secret.example.php to share-secret.php to pin your own value.
 */
function quizShareSecret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $path = __DIR__ . '/../share-secret.php';
    if (is_file($path)) {
        require $path;
        if (defined('QUIZ_SHARE_SECRET') && QUIZ_SHARE_SECRET !== '') {
            $secret = QUIZ_SHARE_SECRET;
            return $secret;
        }
    }

    $generated = bin2hex(random_bytes(32));
    $contents = "<?php\n\n// Auto-generated. Keep this file out of version control.\n"
        . "define('QUIZ_SHARE_SECRET', '" . addslashes($generated) . "');\n";
    @file_put_contents($path, $contents, LOCK_EX);
    @chmod($path, 0600);

    $secret = $generated;
    return $secret;
}

function quizShareBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function quizShareBase64UrlDecode(string $data): string|false
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad !== 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data, true);
}

/**
 * Packs a quiz selection into a short signed token: base64url(payload).base64url(hmac).
 *
 * @param array{
 *     lang: string, type: string, set_size: int, timed: bool, time_unit: string,
 *     time_value: int, books: list<int>, chapters: list<int>, topic: string, character: string, place: string,
 *     difficulty: list<string>, end_time?: int|null, one_attempt?: bool
 * } $params
 */
function quizShareEncode(array $params): string
{
    $languages = quizDiscoverLanguages();
    $langIndex = array_search($params['lang'], $languages, true);
    if ($langIndex === false) {
        throw new InvalidArgumentException('Unknown language');
    }

    $types = quizTypeKeys();
    $typeIndex = array_search($params['type'], $types, true);
    if ($typeIndex === false) {
        throw new InvalidArgumentException('Unknown quiz type');
    }

    $setSize = max(1, min(255, (int) $params['set_size']));

    $bin = pack('C4', QUIZ_SHARE_VERSION, $langIndex + 1, $typeIndex + 1, $setSize);

    $timerFlag = 0;
    $timeValue = 0;
    if (!empty($params['timed']) && (int) ($params['time_value'] ?? 0) > 0) {
        $timerFlag = ($params['time_unit'] ?? 'seconds') === 'minutes' ? 2 : 1;
        $timeValue = max(0, min(65535, (int) $params['time_value']));
    }
    $bin .= pack('C', $timerFlag);
    if ($timerFlag !== 0) {
        $bin .= pack('n', $timeValue);
    }

    $type = $params['type'];
    if ($type === 'book') {
        $books = array_values(array_unique(array_map('intval', $params['books'] ?? [])));
        $bin .= pack('C', count($books));
        foreach ($books as $b) {
            $bin .= pack('C', max(0, min(255, $b)));
        }
    } elseif ($type === 'chapter') {
        $books = array_values(array_unique(array_map('intval', $params['books'] ?? [])));
        $bin .= pack('C', count($books));
        foreach ($books as $b) {
            $bin .= pack('C', max(0, min(255, $b)));
        }
        $chapters = count($books) === 1
            ? array_values(array_unique(array_map('intval', $params['chapters'] ?? [])))
            : [];
        $bin .= pack('C', count($chapters));
        foreach ($chapters as $c) {
            $bin .= pack('C', max(0, min(255, $c)));
        }
    } elseif ($type === 'topic') {
        $topic = substr((string) ($params['topic'] ?? ''), 0, 255);
        $bin .= pack('C', strlen($topic)) . $topic;
    } elseif ($type === 'character') {
        $character = substr((string) ($params['character'] ?? ''), 0, 255);
        $bin .= pack('C', strlen($character)) . $character;
    } elseif ($type === 'place') {
        $place = substr((string) ($params['place'] ?? ''), 0, 255);
        $bin .= pack('C', strlen($place)) . $place;
    }

    $difficultyMask = 0;
    foreach ((array) ($params['difficulty'] ?? []) as $key) {
        $bitIndex = array_search($key, quizDifficultyKeys(), true);
        if ($bitIndex !== false) {
            $difficultyMask |= (1 << $bitIndex);
        }
    }
    $bin .= pack('C', $difficultyMask);

    $hasExpiry = isset($params['end_time']) && $params['end_time'] !== null;
    $oneAttempt = $hasExpiry && !empty($params['one_attempt']);
    $expiryFlags = ($hasExpiry ? 1 : 0) | ($oneAttempt ? 2 : 0);
    $bin .= pack('C', $expiryFlags);
    if ($hasExpiry) {
        $bin .= pack('N', max(0, (int) $params['end_time']));
    }

    $sig = substr(hash_hmac('sha256', $bin, quizShareSecret(), true), 0, QUIZ_SHARE_SIG_BYTES);

    return quizShareBase64UrlEncode($bin) . '.' . quizShareBase64UrlEncode($sig);
}

/**
 * Verifies and unpacks a share token produced by quizShareEncode().
 *
 * @return array{
 *     lang: string, type: string, set_size: int, timed: bool, time_unit: string,
 *     time_value: int, books: list<string>, chapters: list<string>, topic: string, character: string, place: string,
 *     difficulty: list<string>, end_time: int|null, one_attempt: bool
 * }|null
 */
function quizShareDecode(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }

    $bin = quizShareBase64UrlDecode($parts[0]);
    $sig = quizShareBase64UrlDecode($parts[1]);
    if ($bin === false || $sig === false || $bin === '' || strlen($sig) !== QUIZ_SHARE_SIG_BYTES) {
        return null;
    }

    $expected = substr(hash_hmac('sha256', $bin, quizShareSecret(), true), 0, QUIZ_SHARE_SIG_BYTES);
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $len = strlen($bin);
    if ($len < 5) {
        return null;
    }

    if (ord($bin[0]) !== QUIZ_SHARE_VERSION) {
        return null;
    }

    $langCode = ord($bin[1]);
    $typeCode = ord($bin[2]);
    $setSize = ord($bin[3]);
    $offset = 4;

    $languages = quizDiscoverLanguages();
    $types = quizTypeKeys();
    $lang = $languages[$langCode - 1] ?? null;
    $type = $types[$typeCode - 1] ?? null;
    if ($lang === null || $type === null) {
        return null;
    }

    if ($offset >= $len) {
        return null;
    }
    $timerFlag = ord($bin[$offset]);
    $offset += 1;

    $timed = false;
    $timeUnit = 'seconds';
    $timeValue = 0;
    if ($timerFlag !== 0) {
        if ($offset + 2 > $len) {
            return null;
        }
        $unpacked = unpack('n', substr($bin, $offset, 2));
        $timeValue = $unpacked[1];
        $offset += 2;
        $timed = true;
        $timeUnit = $timerFlag === 2 ? 'minutes' : 'seconds';
    }

    $books = [];
    $chapters = [];
    $topic = '';
    $character = '';
    $place = '';

    if ($type === 'book') {
        if ($offset >= $len) {
            return null;
        }
        $count = ord($bin[$offset]);
        $offset += 1;
        for ($i = 0; $i < $count; $i++) {
            if ($offset >= $len) {
                return null;
            }
            $books[] = str_pad((string) ord($bin[$offset]), 2, '0', STR_PAD_LEFT);
            $offset += 1;
        }
    } elseif ($type === 'chapter') {
        if ($offset >= $len) {
            return null;
        }
        $bookCount = ord($bin[$offset]);
        $offset += 1;
        $bookNums = [];
        for ($i = 0; $i < $bookCount; $i++) {
            if ($offset >= $len) {
                return null;
            }
            $bookNums[] = ord($bin[$offset]);
            $offset += 1;
        }
        $books = array_map(static fn ($n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT), $bookNums);

        if ($offset >= $len) {
            return null;
        }
        $chapterCount = ord($bin[$offset]);
        $offset += 1;
        for ($i = 0; $i < $chapterCount; $i++) {
            if ($offset >= $len) {
                return null;
            }
            $chNum = ord($bin[$offset]);
            $offset += 1;
            if (count($books) === 1) {
                $chapters[] = $books[0] . '-' . str_pad((string) $chNum, 3, '0', STR_PAD_LEFT);
            }
        }
    } elseif ($type === 'topic') {
        if ($offset >= $len) {
            return null;
        }
        $strLen = ord($bin[$offset]);
        $offset += 1;
        $topic = substr($bin, $offset, $strLen);
        $offset += $strLen;
    } elseif ($type === 'character') {
        if ($offset >= $len) {
            return null;
        }
        $strLen = ord($bin[$offset]);
        $offset += 1;
        $character = substr($bin, $offset, $strLen);
        $offset += $strLen;
    } elseif ($type === 'place') {
        if ($offset >= $len) {
            return null;
        }
        $strLen = ord($bin[$offset]);
        $offset += 1;
        $place = substr($bin, $offset, $strLen);
        $offset += $strLen;
    }

    $difficulty = [];
    if ($offset < $len) {
        $difficultyMask = ord($bin[$offset]);
        $offset += 1;
        foreach (quizDifficultyKeys() as $bitIndex => $key) {
            if (($difficultyMask & (1 << $bitIndex)) !== 0) {
                $difficulty[] = $key;
            }
        }
    }

    $endTime = null;
    $oneAttempt = false;
    if ($offset < $len) {
        $expiryFlags = ord($bin[$offset]);
        $offset += 1;
        $hasExpiry = ($expiryFlags & 1) === 1;
        $oneAttempt = $hasExpiry && ($expiryFlags & 2) === 2;
        if ($hasExpiry) {
            if ($offset + 4 > $len) {
                return null;
            }
            $unpacked = unpack('N', substr($bin, $offset, 4));
            $endTime = $unpacked[1];
            $offset += 4;
        }
    }

    return [
        'lang' => $lang,
        'type' => $type,
        'set_size' => $setSize,
        'timed' => $timed,
        'time_unit' => $timeUnit,
        'time_value' => $timeValue,
        'books' => $books,
        'chapters' => $chapters,
        'topic' => $topic,
        'character' => $character,
        'place' => $place,
        'difficulty' => $difficulty,
        'end_time' => $endTime,
        'one_attempt' => $oneAttempt,
    ];
}
