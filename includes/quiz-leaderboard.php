<?php

/**
 * Leaderboard storage.
 *
 * Private master record (PII — gitignored, blocked from web access the same
 * way as logs/mistake-reports.log): logs/leaderboard-data.json, one JSON
 * array of rows. Two row shapes coexist:
 *   - Legacy mobile-number rows (player-facing UI for creating these has
 *     been removed — see "Known gap" notes in AGENTS.md — but existing rows
 *     and their admin tooling still work):
 *     {id, displayName, countryCode, countryName, mobileEnc, matchHash,
 *      correctCount, totalQuestions, createdAt, updatedAt}
 *   - Google-identity rows (the only join path now — quizLeaderboardSubmitGoogle()):
 *     {id, displayName, profileName, countryCode:'', countryName:'',
 *      pictureUrl, lastDevice, googleEmailEnc, matchHash, correctCount,
 *      totalQuestions, createdAt, updatedAt}. `profileName` is the
 *      player's current Google account name (refreshed on every submit,
 *      never player-edited); `displayName` is what's actually shown,
 *      defaults to `profileName` when the row is first created and
 *      afterwards changes ONLY via quizLeaderboardSetGoogleDisplayName()
 *      (an explicit player rename) — never silently overwritten again.
 *     `lastDevice` is quizParseUserAgent()'s best-effort output
 *      (deviceType/platform/browser/brand/model — see includes/device-info.php
 *      for what's reliable vs. not), overwritten on every submit with
 *      whichever device most recently played.
 *
 * No language field: the score is a lifetime total across every quiz a
 * player has ever played, and the same person may well play some quizzes
 * in their mother tongue and others in English — there's no single "the"
 * language to record against the row.
 *
 * Public derived files (regenerated after every write):
 *   leaderboard/top10.json, leaderboard/all.json — each
 *   [{displayName, country, score, pictureUrl, gmailId}, ...] sorted by
 *   score descending. `gmailId` is published in PLAINTEXT (decrypted from
 *   googleEmailEnc on every regen) at the owner's explicit request, as the
 *   leaderboard's real unique key, since `displayName` is player-editable
 *   and can collide between different people — this is a deliberate
 *   departure from the mobile-number era's "never shown anywhere" rule for
 *   the identity field; empty string for legacy mobile-number rows.
 *
 * correctCount/totalQuestions is a LIFETIME running total: every finished
 * set/quiz gets ADDED to that row (matched via an HMAC of the normalized
 * identity — mobile number or "google:<email>" — without ever decrypting
 * for the match itself), it never resets and never just keeps a "best" —
 * it keeps growing the more you play, however many separate quizzes that
 * spans.
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

/** Lowercases/trims an email for use as a Google-identity match key. */
function quizLeaderboardNormalizeGoogleEmail(string $email): string
{
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/**
 * Same HMAC match-hash scheme as a mobile number, but over a
 * "google:<email>" identity string so a Google-signed-in row's matchHash
 * space never collides with a mobile-number row's.
 */
function quizLeaderboardGoogleMatchHash(string $normalizedEmail): string
{
    return hash_hmac('sha256', 'google:' . $normalizedEmail, quizLeaderboardSecret()['hmac']);
}

/**
 * Looks up a player's own lifetime score by mobile number, for the
 * Achievements login on leaderboard.php — the only non-admin place a
 * player's own matchHash is ever resolved. Returns only non-PII fields
 * (never the row's mobileEnc); null if country/mobile don't match any row.
 *
 * @return array{displayName:string, correctCount:int, totalQuestions:int}|null
 */
function quizLeaderboardFindByMobile(string $countryCode, string $mobile): ?array
{
    $normalized = quizLeaderboardNormalizeMobile($countryCode, $mobile);
    if ($normalized === '') {
        return null;
    }
    $matchHash = quizLeaderboardMatchHash($normalized);
    foreach (quizLeaderboardReadAllAdmin() as $row) {
        if (($row['matchHash'] ?? '') === $matchHash) {
            return [
                'displayName' => (string) ($row['displayName'] ?? ''),
                'correctCount' => (int) ($row['correctCount'] ?? 0),
                'totalQuestions' => (int) ($row['totalQuestions'] ?? 0),
            ];
        }
    }
    return null;
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

    $public = array_map(static function (array $r): array {
        // gmailId is deliberately published in plaintext (not just the
        // encrypted googleEmailEnc kept at rest) at the owner's explicit
        // request, as the leaderboard's real unique key — displayName is
        // player-editable and can collide between different people, so it
        // can't be used to tell two rows apart. Empty for legacy
        // mobile-number rows (no googleEmailEnc to decrypt).
        $gmailId = isset($r['googleEmailEnc']) ? (quizLeaderboardDecryptMobile((string) $r['googleEmailEnc']) ?? '') : '';
        return [
            'displayName' => (string) ($r['displayName'] ?? ''),
            'country' => (string) ($r['countryName'] ?? ''),
            'score' => (int) ($r['correctCount'] ?? 0),
            // Public Google avatar URL for rows joined via Google sign-in
            // (empty for mobile-number rows) — not PII, same picture anyone
            // with that Google account already shows publicly.
            'pictureUrl' => (string) ($r['pictureUrl'] ?? ''),
            'gmailId' => $gmailId,
        ];
    }, $rows);

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
    int $totalQuestions,
    string $requestIp = ''
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
    $previousDisplayName = null;

    $ok = quizLeaderboardMutate(static function (array $rows) use (
        $displayName, $countryCode, $countryName, $normalized, $matchHash,
        $correctCount, $totalQuestions, $now, &$rank, &$newCorrectCount, &$newTotalQuestions, &$previousDisplayName
    ): array {
        $found = false;
        foreach ($rows as &$row) {
            if (($row['matchHash'] ?? '') === $matchHash) {
                $found = true;
                $previousDisplayName = (string) ($row['displayName'] ?? '');
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

    if ($previousDisplayName !== null && $previousDisplayName !== $displayName) {
        $by = 'user request (IP: ' . ($requestIp !== '' ? $requestIp : 'unknown') . ')';
        quizLeaderboardLogNameChange($matchHash, $previousDisplayName, $displayName, $by);
    }

    return ['rank' => $rank, 'correctCount' => $newCorrectCount, 'totalQuestions' => $newTotalQuestions];
}

/**
 * Google-identity counterpart to quizLeaderboardFindByMobile() — used by
 * api/leaderboard.php's 'lookup_google' action and by the achievements/
 * results-screen identity displays. Also returns the row's own verified
 * email (safe to hand back to the SAME player whose session it came from —
 * never returned about anyone else) and `profileName` (their current
 * Google account name, kept separate from the player-editable
 * `displayName` — see quizLeaderboardSubmitGoogle()'s doc comment).
 *
 * @return array{email:string, displayName:string, profileName:string, pictureUrl:string, correctCount:int, totalQuestions:int}|null
 */
function quizLeaderboardFindByGoogleEmail(string $email): ?array
{
    $normalized = quizLeaderboardNormalizeGoogleEmail($email);
    if ($normalized === '') {
        return null;
    }
    $matchHash = quizLeaderboardGoogleMatchHash($normalized);
    foreach (quizLeaderboardReadAllAdmin() as $row) {
        if (($row['matchHash'] ?? '') === $matchHash) {
            return [
                'email' => $normalized,
                'displayName' => (string) ($row['displayName'] ?? ''),
                'profileName' => (string) ($row['profileName'] ?? ($row['displayName'] ?? '')),
                'pictureUrl' => (string) ($row['pictureUrl'] ?? ''),
                'correctCount' => (int) ($row['correctCount'] ?? 0),
                'totalQuestions' => (int) ($row['totalQuestions'] ?? 0),
            ];
        }
    }
    return null;
}

/**
 * Google-identity counterpart to quizLeaderboardSubmit() — same lifetime-
 * running-total behavior, but matched/deduped on a verified Google account
 * email (via the shared wordofgod.in/auth session) instead of a typed
 * mobile number, $email never taken from client input — always read
 * server-side from the signed-in session by the caller. The row has no
 * countryCode/countryName (not collected on this path) and stores an
 * encrypted copy of the email (googleEmailEnc) instead of mobileEnc, using
 * the same AES key/scheme as quizLeaderboardEncryptMobile().
 *
 * `profileName` vs `displayName`: profileName is the player's CURRENT
 * Google account name, refreshed on every submit (same as pictureUrl) —
 * it's not player-editable, it just tracks whatever their Google profile
 * says right now. `displayName` is what's actually shown on the
 * leaderboard/results screen; it defaults to profileName ONLY when the row
 * is first created, and is never again touched by this function — the
 * only way it changes afterwards is the player explicitly renaming it via
 * quizLeaderboardSetGoogleDisplayName(). $deviceInfo (from
 * quizParseUserAgent(), best-effort) is stored as `lastDevice`, overwritten
 * on every submit with whichever device most recently played.
 *
 * @param array{deviceType:string,platform:string,browser:string,brand:string,model:string} $deviceInfo
 * @return array{rank:int, correctCount:int, totalQuestions:int, displayName:string, pictureUrl:string}|null
 */
function quizLeaderboardSubmitGoogle(
    string $email,
    string $profileName,
    string $pictureUrl,
    int $correctCount,
    int $totalQuestions,
    array $deviceInfo = []
): ?array {
    $normalized = quizLeaderboardNormalizeGoogleEmail($email);
    if ($profileName === '' || $normalized === '') {
        return null;
    }

    $matchHash = quizLeaderboardGoogleMatchHash($normalized);
    $now = date('c');
    $rank = null;
    $newCorrectCount = null;
    $newTotalQuestions = null;
    $finalDisplayName = null;

    $ok = quizLeaderboardMutate(static function (array $rows) use (
        $profileName, $pictureUrl, $normalized, $matchHash, $deviceInfo,
        $correctCount, $totalQuestions, $now, &$rank, &$newCorrectCount, &$newTotalQuestions, &$finalDisplayName
    ): array {
        $found = false;
        foreach ($rows as &$row) {
            if (($row['matchHash'] ?? '') === $matchHash) {
                $found = true;
                $row['profileName'] = $profileName;
                $row['pictureUrl'] = $pictureUrl;
                $row['lastDevice'] = $deviceInfo;
                $row['updatedAt'] = $now;
                $row['correctCount'] = (int) ($row['correctCount'] ?? 0) + $correctCount;
                $row['totalQuestions'] = (int) ($row['totalQuestions'] ?? 0) + $totalQuestions;
                $newCorrectCount = $row['correctCount'];
                $newTotalQuestions = $row['totalQuestions'];
                $finalDisplayName = (string) ($row['displayName'] ?? $profileName);
                break;
            }
        }
        unset($row);

        if (!$found) {
            $rows[] = [
                'id' => $matchHash,
                'displayName' => $profileName,
                'profileName' => $profileName,
                'countryCode' => '',
                'countryName' => '',
                'pictureUrl' => $pictureUrl,
                'lastDevice' => $deviceInfo,
                'googleEmailEnc' => quizLeaderboardEncryptMobile($normalized),
                'matchHash' => $matchHash,
                'correctCount' => $correctCount,
                'totalQuestions' => $totalQuestions,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];
            $newCorrectCount = $correctCount;
            $newTotalQuestions = $totalQuestions;
            $finalDisplayName = $profileName;
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

    return [
        'rank' => $rank,
        'correctCount' => $newCorrectCount,
        'totalQuestions' => $newTotalQuestions,
        'displayName' => $finalDisplayName,
        'pictureUrl' => $pictureUrl,
    ];
}

/**
 * Player-driven rename: the ONLY thing that changes a Google-identified
 * row's `displayName` after it's first created (see
 * quizLeaderboardSubmitGoogle()'s doc comment). $email is always read
 * server-side from the signed-in session by the caller, never trusted from
 * the request body — a player can only ever rename their OWN row.
 *
 * @return array{displayName:string}|null null if no row exists yet for
 *     this email (nothing to rename) or the name is invalid.
 */
function quizLeaderboardSetGoogleDisplayName(string $email, string $newDisplayName): ?array
{
    $normalized = quizLeaderboardNormalizeGoogleEmail($email);
    if ($normalized === '' || $newDisplayName === '' || mb_strlen($newDisplayName) > 60) {
        return null;
    }

    $matchHash = quizLeaderboardGoogleMatchHash($normalized);
    $found = false;
    $previousDisplayName = null;

    $ok = quizLeaderboardMutate(static function (array $rows) use (
        $matchHash, $newDisplayName, &$found, &$previousDisplayName
    ): array {
        foreach ($rows as &$row) {
            if (($row['matchHash'] ?? '') === $matchHash) {
                $found = true;
                $previousDisplayName = (string) ($row['displayName'] ?? '');
                $row['displayName'] = $newDisplayName;
                $row['updatedAt'] = date('c');
                break;
            }
        }
        unset($row);
        return $rows;
    }) && $found;

    if (!$ok) {
        return null;
    }

    if ($previousDisplayName !== null && $previousDisplayName !== $newDisplayName) {
        quizLeaderboardLogNameChange($matchHash, $previousDisplayName, $newDisplayName, 'user request (Google sign-in)');
    }

    return ['displayName' => $newDisplayName];
}

/**
 * Log of display-name changes for an existing mobile number (matched via the
 * same matchHash the leaderboard row itself uses — never the raw mobile).
 * Written automatically whenever quizLeaderboardSubmit()/quizLeaderboardEditEntry()
 * change a row's displayName for a number that already had one, so an admin
 * can see when/why a name changed and revert it. Kept in its own file next to
 * logs/leaderboard-data.json (same protected/gitignored directory).
 */
function quizLeaderboardNameLogFile(): string
{
    return __DIR__ . '/../logs/leaderboard-name-changes.json';
}

/** @param callable(list<array<string, mixed>>): list<array<string, mixed>> $mutate */
function quizLeaderboardNameLogMutate(callable $mutate): bool
{
    quizLeaderboardEnsureLogDir();
    $fh = @fopen(quizLeaderboardNameLogFile(), 'c+');
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
    return true;
}

/** @return list<array<string, mixed>> newest first */
function quizLeaderboardReadNameLog(): array
{
    $file = quizLeaderboardNameLogFile();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $rows = $raw !== false ? json_decode($raw, true) : null;
    $rows = is_array($rows) ? $rows : [];
    usort($rows, static fn (array $a, array $b): int => ((string) ($b['at'] ?? '')) <=> ((string) ($a['at'] ?? '')));
    return $rows;
}

/** Appends a name-change entry; no-ops if $from/$to are the same or $from is empty (nothing to roll back to). */
function quizLeaderboardLogNameChange(string $matchHash, string $from, string $to, string $by): void
{
    if ($from === $to || $from === '' || $matchHash === '') {
        return;
    }
    quizLeaderboardNameLogMutate(static function (array $rows) use ($matchHash, $from, $to, $by): array {
        $rows[] = [
            'id' => bin2hex(random_bytes(8)),
            'matchHash' => $matchHash,
            'from' => $from,
            'to' => $to,
            'by' => $by,
            'at' => date('c'),
            'reverted' => false,
        ];
        return $rows;
    });
}

/**
 * Admin action: reverts one logged name change by restoring the leaderboard
 * row's displayName back to that entry's "from" value, then marks the log
 * entry itself as reverted (so the button doesn't show again for it). Fails
 * if the entry doesn't exist, was already reverted, or its row no longer
 * exists (e.g. the entry was later deleted/moderated).
 */
function quizLeaderboardRevertNameChange(string $logId): bool
{
    $entry = null;
    foreach (quizLeaderboardReadNameLog() as $row) {
        if (($row['id'] ?? '') === $logId) {
            $entry = $row;
            break;
        }
    }
    if ($entry === null || ($entry['reverted'] ?? false)) {
        return false;
    }

    $matchHash = (string) ($entry['matchHash'] ?? '');
    $from = (string) ($entry['from'] ?? '');
    if ($matchHash === '' || $from === '') {
        return false;
    }

    $rowFound = false;
    quizLeaderboardMutate(static function (array $rows) use ($matchHash, $from, &$rowFound): array {
        foreach ($rows as &$row) {
            if (($row['matchHash'] ?? '') === $matchHash) {
                $rowFound = true;
                $row['displayName'] = $from;
                $row['updatedAt'] = date('c');
                break;
            }
        }
        unset($row);
        return $rows;
    });

    if (!$rowFound) {
        return false;
    }

    quizLeaderboardNameLogMutate(static function (array $rows) use ($logId): array {
        foreach ($rows as &$row) {
            if (($row['id'] ?? '') === $logId) {
                $row['reverted'] = true;
                $row['revertedAt'] = date('c');
                break;
            }
        }
        unset($row);
        return $rows;
    });

    return true;
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
    $previousDisplayName = null;

    $ok = quizLeaderboardMutate(static function (array $rows) use (
        $id, $displayName, $countryCode, $countryName, $correctCount, $totalQuestions, $now, &$found, &$previousDisplayName
    ): array {
        foreach ($rows as &$row) {
            if (($row['id'] ?? '') === $id) {
                $found = true;
                $previousDisplayName = (string) ($row['displayName'] ?? '');
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

    if ($ok && $previousDisplayName !== null && $previousDisplayName !== $displayName) {
        quizLeaderboardLogNameChange($id, $previousDisplayName, $displayName, 'admin');
    }

    return $ok;
}

/**
 * Admin-only edit for a Google-identified row (a.php?view=users) — same
 * idea as quizLeaderboardEditEntry(), but deliberately narrower: only
 * `displayName` and the lifetime score are editable. `profileName`,
 * `pictureUrl`, and the row's identity (email, via `googleEmailEnc`) are
 * NEVER touched here — profileName/pictureUrl are synced from the
 * player's own Google account on their next submit regardless of
 * whatever an admin does here, and the identity has to stay fixed or this
 * stops being "edit this row" and starts being "merge into someone else's
 * row". There's no countryCode/countryName field on a Google row to edit.
 */
function quizLeaderboardEditGoogleEntry(
    string $id,
    string $displayName,
    int $correctCount,
    int $totalQuestions
): bool {
    if ($displayName === '' || mb_strlen($displayName) > 60 || $correctCount < 0 || $totalQuestions < 0) {
        return false;
    }

    $now = date('c');
    $found = false;
    $previousDisplayName = null;

    $ok = quizLeaderboardMutate(static function (array $rows) use (
        $id, $displayName, $correctCount, $totalQuestions, $now, &$found, &$previousDisplayName
    ): array {
        foreach ($rows as &$row) {
            if (($row['id'] ?? '') === $id && isset($row['googleEmailEnc'])) {
                $found = true;
                $previousDisplayName = (string) ($row['displayName'] ?? '');
                $row['displayName'] = $displayName;
                $row['correctCount'] = $correctCount;
                $row['totalQuestions'] = $totalQuestions;
                $row['updatedAt'] = $now;
                break;
            }
        }
        unset($row);
        return $rows;
    }) && $found;

    if ($ok && $previousDisplayName !== null && $previousDisplayName !== $displayName) {
        quizLeaderboardLogNameChange($id, $previousDisplayName, $displayName, 'admin');
    }

    return $ok;
}

/**
 * All Google-identified rows (a.php?view=users — "manage all the users"),
 * decrypted for admin display: email and the already-public pictureUrl,
 * plus profileName/displayName/lastDevice/score/timestamps. Never includes
 * legacy mobile-number rows (no googleEmailEnc to decrypt) — those stay
 * under a.php?view=leaderboard.
 *
 * @return list<array<string, mixed>>
 */
function quizLeaderboardReadAllGoogleUsers(): array
{
    $out = [];
    foreach (quizLeaderboardReadAllAdmin() as $row) {
        if (!isset($row['googleEmailEnc'])) {
            continue;
        }
        $out[] = [
            'id' => (string) ($row['id'] ?? ''),
            'email' => quizLeaderboardDecryptMobile((string) $row['googleEmailEnc']) ?? '',
            'profileName' => (string) ($row['profileName'] ?? ''),
            'displayName' => (string) ($row['displayName'] ?? ''),
            'pictureUrl' => (string) ($row['pictureUrl'] ?? ''),
            'lastDevice' => (array) ($row['lastDevice'] ?? []),
            'correctCount' => (int) ($row['correctCount'] ?? 0),
            'totalQuestions' => (int) ($row['totalQuestions'] ?? 0),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
            'updatedAt' => (string) ($row['updatedAt'] ?? ''),
        ];
    }
    return $out;
}
