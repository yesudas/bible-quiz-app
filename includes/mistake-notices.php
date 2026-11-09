<?php
/**
 * Pending "your reported mistake was fixed" thank-you notices.
 *
 * One tiny file per signed-in reporter under logs/notices/, named by an HMAC of
 * the lowercased email — so checking for a visitor's notices is a single
 * is_file() call, never a scan of logs/mistake-reports.log. The file is created
 * when an admin clicks "Save & mark resolved" and deleted once the player
 * dismisses the modal. Contents hold only verse references (no PII).
 */

require_once __DIR__ . '/quiz-leaderboard.php';
require_once __DIR__ . '/quiz-i18n.php';

function quizNoticeDir(): string
{
    return __DIR__ . '/../logs/notices';
}

function quizNoticeFile(string $email): string
{
    $key = hash_hmac('sha256', 'notice:' . strtolower(trim($email)), quizLeaderboardSecret()['hmac']);
    return quizNoticeDir() . '/' . $key . '.json';
}

/** Queues a notice for each given email address. */
function quizNoticeAdd(array $emails, string $verseReference, string $language): void
{
    $dir = quizNoticeDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = dirname($dir) . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
    foreach (array_unique(array_filter(array_map('strval', $emails))) as $email) {
        $fh = @fopen(quizNoticeFile($email), 'c+');
        if (!$fh) {
            continue;
        }
        flock($fh, LOCK_EX);
        $list = json_decode((string) stream_get_contents($fh), true);
        $list = is_array($list) ? $list : [];
        $list[] = ['verse' => $verseReference, 'language' => $language, 'at' => date('Y-m-d H:i:s')];
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** @return list<array{verse:string,language:string}> pending notices; [] almost always (one is_file call). */
function quizNoticeRead(string $email): array
{
    $file = quizNoticeFile($email);
    if (!is_file($file)) {
        return [];
    }
    $list = json_decode((string) @file_get_contents($file), true);
    return is_array($list) ? $list : [];
}

function quizNoticeClear(string $email): void
{
    @unlink(quizNoticeFile($email));
}

/** Thank-you text for a verse list, in the language of the (first) reported question. */
function quizNoticeMessages(array $notices): array
{
    $text = [
        'English' => [
            'title' => 'Thank you!',
            'one'   => 'Your reported mistake for %s has been addressed.',
            'many'  => 'Your reported mistakes for %s have been addressed.',
            'bless' => 'May God bless you!',
            'btn'   => 'Dismiss',
        ],
        'Tamil' => [
            'title' => 'நன்றி!',
            'one'   => 'நீங்கள் தெரிவித்த %s குறித்த தவறு சரிசெய்யப்பட்டுள்ளது.',
            'many'  => 'நீங்கள் தெரிவித்த %s குறித்த தவறுகள் சரிசெய்யப்பட்டுள்ளன.',
            'bless' => 'தேவன் உங்களை ஆசீர்வதிப்பாராக!',
            'btn'   => 'சரி',
        ],
    ];
    $lang = quizCanonicalLanguageKey((string) ($notices[0]['language'] ?? 'English'));
    $t = $text[$lang] ?? $text['English'];
    $verses = array_values(array_unique(array_map(static fn ($n) => (string) $n['verse'], $notices)));
    return [
        'title'   => $t['title'],
        'message' => sprintf(count($verses) > 1 ? $t['many'] : $t['one'], implode(', ', $verses)),
        'bless'   => $t['bless'],
        'btn'     => $t['btn'],
    ];
}
