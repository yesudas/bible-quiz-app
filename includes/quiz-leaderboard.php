<?php

/**
 * Leaderboard storage.
 *
 * Private master record (PII — gitignored, blocked from web access the same
 * way as logs/mistake-reports.log): logs/leaderboard-data.json, one JSON
 * array of rows:
 *   {id, displayName, countryCode, countryName, mobileEnc, matchHash,
 *    correctCount, totalQuestions, createdAt, updatedAt}
 *
 * No language field: the score is a lifetime total across every quiz a
 * phone number has ever played, and the same person may well play some
 * quizzes in their mother tongue and others in English — there's no single
 * "the" language to record against the row.
 *
 * Public derived files (regenerated after every write, no PII):
 *   leaderboard/top10.json, leaderboard/all.json — each
 *   [{displayName, country, score}, ...] sorted by score descending.
 *
 * Joining the leaderboard requires BOTH a display name and a mobile number;
 * a submission without a mobile number is rejected before it reaches here
 * (see api/leaderboard.php). correctCount/totalQuestions is a LIFETIME
 * running total: every finished set/quiz a mobile number submits gets ADDED
 * to that row (matched via an HMAC of the normalized number, without ever
 * decrypting), it never resets and never just keeps a "best" — it keeps
 * growing the more you play, however many separate quizzes that spans.
 */

function quizLeaderboardDataFile(): string
{
    return __DIR__ . '/../logs/leaderboard-data.json';
}

function quizLeaderboardPublicDir(): string
{
    return __DIR__ . '/../leaderboard';
}

/** Creates logs/ (with an .htaccess that blocks web access) on first use. */
function quizLeaderboardEnsureLogDir(): void
{
    $dir = dirname(quizLeaderboardDataFile());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
}

function quizLeaderboardEnsurePublicDir(): void
{
    $dir = quizLeaderboardPublicDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/**
 * Common dial-code => country-name list. Single source of truth, used both
 * to render the country <select> and to resolve a country name for storage.
 * Not exhaustive — covers the countries this app's audience is most likely
 * to be in; extend as needed.
 */
function quizLeaderboardCountries(): array
{
    return [
        '+91' => 'India',
        '+1' => 'USA/Canada',
        '+44' => 'United Kingdom',
        '+61' => 'Australia',
        '+971' => 'UAE',
        '+966' => 'Saudi Arabia',
        '+65' => 'Singapore',
        '+60' => 'Malaysia',
        '+94' => 'Sri Lanka',
        '+92' => 'Pakistan',
        '+880' => 'Bangladesh',
        '+977' => 'Nepal',
        '+27' => 'South Africa',
        '+254' => 'Kenya',
        '+234' => 'Nigeria',
        '+233' => 'Ghana',
        '+63' => 'Philippines',
        '+64' => 'New Zealand',
        '+49' => 'Germany',
        '+33' => 'France',
        '+39' => 'Italy',
        '+34' => 'Spain',
        '+31' => 'Netherlands',
        '+41' => 'Switzerland',
        '+46' => 'Sweden',
        '+353' => 'Ireland',
        '+81' => 'Japan',
        '+82' => 'South Korea',
        '+86' => 'China',
        '+852' => 'Hong Kong',
        '+974' => 'Qatar',
        '+965' => 'Kuwait',
        '+973' => 'Bahrain',
        '+968' => 'Oman',
        '+55' => 'Brazil',
        '+52' => 'Mexico',
        '+20' => 'Egypt',
    ];
}

function quizLeaderboardCountryName(string $countryCode): string
{
    return quizLeaderboardCountries()[$countryCode] ?? $countryCode;
}

/**
 * Secret keys used to encrypt mobile numbers (reversible, admin can decrypt)
 * and to compute a deterministic match hash (for dedup lookups, without ever
 * decrypting). Auto-generated and persisted (gitignored) on first use.
 */
function quizLeaderboardSecret(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $path = __DIR__ . '/../leaderboard-secret.php';
    if (is_file($path)) {
        require $path;
        if (defined('QUIZ_LEADERBOARD_ENC_KEY') && defined('QUIZ_LEADERBOARD_HMAC_KEY')
            && QUIZ_LEADERBOARD_ENC_KEY !== '' && QUIZ_LEADERBOARD_HMAC_KEY !== ''
        ) {
            $keys = ['enc' => QUIZ_LEADERBOARD_ENC_KEY, 'hmac' => QUIZ_LEADERBOARD_HMAC_KEY];
            return $keys;
        }
    }

    $encKey = bin2hex(random_bytes(32));
    $hmacKey = bin2hex(random_bytes(32));
    $contents = "<?php\n\n// Auto-generated. Keep this file out of version control.\n"
        . "define('QUIZ_LEADERBOARD_ENC_KEY', '" . addslashes($encKey) . "');\n"
        . "define('QUIZ_LEADERBOARD_HMAC_KEY', '" . addslashes($hmacKey) . "');\n";
    @file_put_contents($path, $contents, LOCK_EX);
    @chmod($path, 0600);

    $keys = ['enc' => $encKey, 'hmac' => $hmacKey];
    return $keys;
}

/** Strips everything but digits from the country code + number and concatenates them. */
function quizLeaderboardNormalizeMobile(string $countryCode, string $mobile): string
{
    $digits = preg_replace('/[^0-9]/', '', $countryCode . $mobile);
    return (string) $digits;
}

function quizLeaderboardMatchHash(string $normalizedMobile): string
{
    return hash_hmac('sha256', $normalizedMobile, quizLeaderboardSecret()['hmac']);
}

function quizLeaderboardEncryptMobile(string $normalizedMobile): string
{
    $key = hex2bin(quizLeaderboardSecret()['enc']);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($normalizedMobile, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return base64_encode($nonce . $tag . $ciphertext);
}

function quizLeaderboardDecryptMobile(string $blob): ?string
{
    $key = hex2bin(quizLeaderboardSecret()['enc']);
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 12 + 16) {
        return null;
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plain === false ? null : $plain;
}

/** @return list<array<string, mixed>> */
function quizLeaderboardReadAllAdmin(): array
{
    $file = quizLeaderboardDataFile();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $rows = $raw !== false ? json_decode($raw, true) : null;
    return is_array($rows) ? $rows : [];
}

/**
 * Runs $mutate(rows) under an exclusive lock, writes back the rows it
 * returns, and regenerates the public top10/all files from the result.
 * Modeled on quizMistakeMutate() in includes/mistake-reports.php.
 *
 * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $mutate
 */
function quizLeaderboardMutate(callable $mutate): bool
{
    quizLeaderboardEnsureLogDir();
    $fh = @fopen(quizLeaderboardDataFile(), 'c+');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    $raw = (string) stream_get_contents($fh);
    $rows = $raw !== '' ? json_decode($raw, true) : [];
    $rows = is_array($rows) ? $rows : [];

    $rows = $mutate($rows);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    quizLeaderboardRegeneratePublicFiles($rows);
    return true;
}

function quizLeaderboardRegeneratePublicFiles(array $rows): void
{
    quizLeaderboardEnsurePublicDir();

    usort($rows, static fn (array $a, array $b): int => ($b['correctCount'] ?? 0) <=> ($a['correctCount'] ?? 0));

    $public = array_map(static fn (array $r): array => [
        'displayName' => (string) ($r['displayName'] ?? ''),
        'country' => (string) ($r['countryName'] ?? ''),
        'score' => (int) ($r['correctCount'] ?? 0),
    ], $rows);

    $top10 = array_slice($public, 0, 10);

    @file_put_contents(
        quizLeaderboardPublicDir() . '/top10.json',
        json_encode($top10, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    @file_put_contents(
        quizLeaderboardPublicDir() . '/all.json',
        json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/** @return list<array{displayName:string, country:string, score:int}> */
function quizLeaderboardReadPublic(string $which): array
{
    $file = quizLeaderboardPublicDir() . '/' . ($which === 'all' ? 'all.json' : 'top10.json');
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Submits a finished set/quiz's score to the leaderboard. Both $displayName
 * and $mobile are required (validated by the caller, api/leaderboard.php) —
 * there is no "name only" leaderboard entry. $correctCount/$totalQuestions
 * are ADDED to the existing row for the same mobile number (matched via an
 * HMAC of the normalized number, without ever decrypting) — the leaderboard
 * score is a lifetime running total across every quiz/set a phone number
 * has ever finished, not just its best single attempt. Each call must pass
 * the score for ONE finished set only (quiz.js submits once per completed
 * set); passing an attempt-cumulative total here would double-count it on
 * every subsequent set of the same attempt.
 *
 * @return array{rank:int, correctCount:int, totalQuestions:int}|null the
 *     submitter's updated lifetime totals and 1-based rank among all
 *     entries, or null if the submission was rejected/failed.
 */
function quizLeaderboardSubmit(
    string $displayName,
    string $countryCode,
    string $mobile,
    int $correctCount,
    int $totalQuestions
): ?array {
    $normalized = quizLeaderboardNormalizeMobile($countryCode, $mobile);
    if ($displayName === '' || $normalized === '') {
        return null;
    }

    $matchHash = quizLeaderboardMatchHash($normalized);
    $countryName = quizLeaderboardCountryName($countryCode);
    $now = date('c');
    $rank = null;
    $newCorrectCount = null;
    $newTotalQuestions = null;

    $ok = quizLeaderboardMutate(static function (array $rows) use (
        $displayName, $countryCode, $countryName, $normalized, $matchHash,
        $correctCount, $totalQuestions, $now, &$rank, &$newCorrectCount, &$newTotalQuestions
    ): array {
        $found = false;
        foreach ($rows as &$row) {
            if (($row['matchHash'] ?? '') === $matchHash) {
                $found = true;
                $row['displayName'] = $displayName;
                $row['countryCode'] = $countryCode;
                $row['countryName'] = $countryName;
                $row['updatedAt'] = $now;
                $row['correctCount'] = (int) ($row['correctCount'] ?? 0) + $correctCount;
                $row['totalQuestions'] = (int) ($row['totalQuestions'] ?? 0) + $totalQuestions;
                $newCorrectCount = $row['correctCount'];
                $newTotalQuestions = $row['totalQuestions'];
                break;
            }
        }
        unset($row);

        if (!$found) {
            $rows[] = [
                'id' => $matchHash,
                'displayName' => $displayName,
                'countryCode' => $countryCode,
                'countryName' => $countryName,
                'mobileEnc' => quizLeaderboardEncryptMobile($normalized),
                'matchHash' => $matchHash,
                'correctCount' => $correctCount,
                'totalQuestions' => $totalQuestions,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];
            $newCorrectCount = $correctCount;
            $newTotalQuestions = $totalQuestions;
        }

        $ranked = $rows;
        usort($ranked, static fn (array $a, array $b): int => ($b['correctCount'] ?? 0) <=> ($a['correctCount'] ?? 0));
        foreach ($ranked as $i => $r) {
            if (($r['matchHash'] ?? '') === $matchHash) {
                $rank = $i + 1;
                break;
            }
        }

        return $rows;
    });

    if (!$ok || $rank === null) {
        return null;
    }

    return ['rank' => $rank, 'correctCount' => $newCorrectCount, 'totalQuestions' => $newTotalQuestions];
}

function quizLeaderboardDeleteEntry(string $id): bool
{
    return quizLeaderboardMutate(static fn (array $rows): array => array_values(
        array_filter($rows, static fn (array $r): bool => ($r['id'] ?? '') !== $id)
    ));
}

/**
 * Admin-only edit: corrects a leaderboard row's display name, country, or
 * lifetime score directly (e.g. to fix a typo'd name or back out a bad
 * submission). The mobile number itself is never editable here — it's the
 * row's identity (matchHash is derived from it); changing it would really
 * mean "merge into a different person's row", which isn't what an edit
 * screen should quietly do.
 */
function quizLeaderboardEditEntry(
    string $id,
    string $displayName,
    string $countryCode,
    int $correctCount,
    int $totalQuestions
): bool {
    if ($displayName === '' || !isset(quizLeaderboardCountries()[$countryCode]) || $correctCount < 0 || $totalQuestions < 0) {
        return false;
    }

    $countryName = quizLeaderboardCountryName($countryCode);
    $now = date('c');
    $found = false;

    return quizLeaderboardMutate(static function (array $rows) use (
        $id, $displayName, $countryCode, $countryName, $correctCount, $totalQuestions, $now, &$found
    ): array {
        foreach ($rows as &$row) {
            if (($row['id'] ?? '') === $id) {
                $found = true;
                $row['displayName'] = $displayName;
                $row['countryCode'] = $countryCode;
                $row['countryName'] = $countryName;
                $row['correctCount'] = $correctCount;
                $row['totalQuestions'] = $totalQuestions;
                $row['updatedAt'] = $now;
                break;
            }
        }
        unset($row);
        return $rows;
    }) && $found;
}
