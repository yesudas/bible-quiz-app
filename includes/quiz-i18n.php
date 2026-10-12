<?php

require_once __DIR__ . '/bible-books.php';

/**
 * Canonical, language-independent order of quiz type keys. Keep this order
 * stable — share-link encoding (quiz-share.php) relies on it.
 */
function quizTypeKeys(): array
{
    return [
        'random', 'whole-bible', 'old-testament', 'new-testament',
        'book', 'chapter', 'topic', 'character', 'kids', 'teens', 'adults',
        'place',
    ];
}

/**
 * Canonical, language-independent order of difficulty keys. Keep this order
 * stable — share-link encoding (quiz-share.php) relies on it.
 */
function quizDifficultyKeys(): array
{
    return ['easy', 'medium', 'difficult'];
}

/**
 * Maps a language's native-script folder name (e.g. "தமிழ்") back to its
 * canonical ASCII key ("Tamil") so the translation tables below work
 * whichever way a language's data/ folder happens to be named. Add an entry
 * here when a new language is introduced with a native-script folder name.
 */
function quizLanguageAliasKeys(): array
{
    return [
        'தமிழ்' => 'Tamil',
        'ಕನ್ನಡ' => 'Kannada',
        'മലയാളം' => 'Malayalam',
        'తెలుగు' => 'Telugu',
        'हिन्दी' => 'Hindi',
    ];
}

function quizCanonicalLanguageKey(string $lang): string
{
    return quizLanguageAliasKeys()[$lang] ?? $lang;
}

/**
 * Native display name for each language, keyed by its canonical ASCII name.
 * Add an entry here when a new language (Kannada, Malayalam, ...) is
 * introduced; unknown languages fall back to their folder name as-is.
 */
function quizLanguageNames(): array
{
    return [
        'English' => 'English',
        'Tamil' => 'தமிழ்',
        'Kannada' => 'ಕನ್ನಡ',
        'Malayalam' => 'മലയാളം',
        'Telugu' => 'తెలుగు',
        'Hindi' => 'हिन्दी',
    ];
}

function quizLanguageDisplayName(string $lang): string
{
    $canonical = quizCanonicalLanguageKey($lang);
    return quizLanguageNames()[$canonical] ?? $lang;
}

/**
 * Quiz type labels per language, keyed by the language folder name. Add a
 * new top-level entry here (matching a data/ folder name) to localize quiz
 * type labels for a new language; missing keys fall back to English.
 */
function quizTypeLabelsByLanguage(): array
{
    return [
        'English' => [
            'random' => 'Random Quiz',
            'whole-bible' => 'Quiz by Whole Bible',
            'old-testament' => 'Quiz by Old Testament',
            'new-testament' => 'Quiz by New Testament',
            'book' => 'Quiz by Book',
            'chapter' => 'Quiz by Chapter',
            'topic' => 'Quiz by Topic',
            'character' => 'Quiz by Character',
            'kids' => 'Quiz for Kids',
            'teens' => 'Quiz for Teens',
            'adults' => 'Quiz for Adults',
            'place' => 'Quiz by Place',
        ],
        'Tamil' => [
            'random' => 'வினாடி வினா',
            'whole-bible' => 'முழு பைபிள் வினாடி வினா',
            'old-testament' => 'பழைய ஏற்பாடு வினாடி வினா',
            'new-testament' => 'புதிய ஏற்பாடு வினாடி வினா',
            'book' => 'புத்தகம் வாரியாக வினாடி வினா',
            'chapter' => 'அதிகாரம் வாரியாக வினாடி வினா',
            'topic' => 'தலைப்பு வாரியாக வினாடி வினா',
            'character' => 'வேதாகம நபர்கள் குறித்த வினாடி வினா',
            'kids' => 'குழந்தைகளுக்கான வினாடி வினா',
            'teens' => 'இளம் பருவத்தினருக்கான வினாடி வினா',
            'adults' => 'பெரியவர்களுக்கான வினாடி வினா',
            'place' => 'இடம் வாரியாக வினாடி வினா',
        ],
    ];
}

/**
 * @return array<string,string> quiz type key => localized label, in quizTypeKeys() order
 */
function quizTypeOptions(string $lang = 'English'): array
{
    $byLang = quizTypeLabelsByLanguage();
    $labels = $byLang[quizCanonicalLanguageKey($lang)] ?? $byLang['English'];
    $fallback = $byLang['English'];

    $out = [];
    foreach (quizTypeKeys() as $key) {
        $out[$key] = $labels[$key] ?? $fallback[$key];
    }
    return $out;
}

/**
 * Field labels for the quiz-details summary (setup hints, results screen).
 */
function quizDetailLabelsByLanguage(): array
{
    return [
        'English' => [
            'type' => 'Type',
            'language' => 'Language',
            'book' => 'Book',
            'books' => 'Books',
            'chapter' => 'Chapter',
            'chapters' => 'Chapters',
            'topic' => 'Topic',
            'character' => 'Character',
            'place' => 'Place',
            'difficulty' => 'Difficulty',
        ],
        'Tamil' => [
            'type' => 'வகை',
            'language' => 'மொழி',
            'book' => 'புத்தகம்',
            'books' => 'புத்தகங்கள்',
            'chapter' => 'அதிகாரம்',
            'chapters' => 'அதிகாரங்கள்',
            'topic' => 'தலைப்பு',
            'character' => 'பாத்திரம்',
            'place' => 'இடம்',
            'difficulty' => 'கடின நிலை',
        ],
    ];
}

function quizDetailLabels(string $lang): array
{
    $byLang = quizDetailLabelsByLanguage();
    return $byLang[quizCanonicalLanguageKey($lang)] ?? $byLang['English'];
}

/**
 * Difficulty option labels per language, keyed by the language folder name.
 * Missing keys fall back to English.
 */
function quizDifficultyLabelsByLanguage(): array
{
    return [
        'English' => [
            'easy' => 'Easy',
            'medium' => 'Medium',
            'difficult' => 'Difficult',
        ],
        'Tamil' => [
            'easy' => 'எளிது',
            'medium' => 'நடுத்தரம்',
            'difficult' => 'கடினம்',
        ],
    ];
}

/**
 * @return array<string,string> difficulty key => localized label, in quizDifficultyKeys() order
 */
function quizDifficultyOptions(string $lang = 'English'): array
{
    $byLang = quizDifficultyLabelsByLanguage();
    $labels = $byLang[quizCanonicalLanguageKey($lang)] ?? $byLang['English'];
    $fallback = $byLang['English'];

    $out = [];
    foreach (quizDifficultyKeys() as $key) {
        $out[$key] = $labels[$key] ?? $fallback[$key];
    }
    return $out;
}

/**
 * Leaderboard popup/results-screen/leaderboard-page copy, per language.
 * Missing keys fall back to English.
 */
function quizLeaderboardLabelsByLanguage(): array
{
    return [
        'English' => [
            'modalTitle' => 'Join the Leaderboard',
            'modalIntro' => 'Enter your name and mobile number to appear on the leaderboard. Both are required to join — you can skip this and still see your results.',
            'displayName' => 'Display name',
            'mobileNumber' => 'Mobile number',
            'country' => 'Country',
            'disclaimer' => 'Your mobile number is encrypted and never shown anywhere on this website or shared with anyone.',
            'validationError' => 'Enter both your name and mobile number to join the leaderboard, or skip to just see your results.',
            'save' => 'Save & join leaderboard',
            'skip' => 'Skip',
            'playingAs' => 'Playing as',
            'showNumber' => 'Show number',
            'hideNumber' => 'Hide number',
            'pageTitle' => 'Leaderboard',
            'top10' => 'Top 10',
            'showAll' => 'Show all',
            'rank' => 'Rank',
            'name' => 'Name',
            'score' => 'Score',
            'empty' => 'No one has joined the leaderboard yet — be the first!',
            'downloadPdf' => 'Download results as PDF',
            'congratsTop100' => '🎉 Congratulations! You\'re in the Top 100 on the leaderboard — rank #{rank}!',
            'ok' => 'OK',
            'pdfHeading' => 'Bible Quiz Results',
            'pdfPoweredBy' => 'Powered by:',
            'pdfReviewHeading' => 'Questions & Answers',
        ],
        'Tamil' => [
            'modalTitle' => 'முன்னணி பட்டியலில் இணையுங்கள்',
            'modalIntro' => 'முன்னணி பட்டியலில் இடம்பெற உங்கள் பெயர் மற்றும் மொபைல் எண்ணை உள்ளிடவும். இரண்டும் அவசியம் — இதைத் தவிர்த்தும் உங்கள் முடிவுகளை பார்க்கலாம்.',
            'displayName' => 'காட்சிப் பெயர்',
            'mobileNumber' => 'மொபைல் எண்',
            'country' => 'நாடு',
            'disclaimer' => 'உங்கள் மொபைல் எண் என்க்ரிப்ட் செய்யப்பட்டு, இந்த வலைத்தளத்தில் எங்கும் காட்டப்படாது, யாரிடமும் பகிரப்படாது.',
            'validationError' => 'முன்னணி பட்டியலில் இணைய பெயர் மற்றும் மொபைல் எண் இரண்டையும் உள்ளிடவும், அல்லது தவிர்த்து முடிவுகளை மட்டும் காணவும்.',
            'save' => 'சேமித்து இணைக',
            'skip' => 'தவிர்க்க',
            'playingAs' => 'விளையாடுபவர்',
            'showNumber' => 'எண்ணைக் காட்டு',
            'hideNumber' => 'எண்ணை மறை',
            'pageTitle' => 'முன்னணி பட்டியல்',
            'top10' => 'முதல் 10',
            'showAll' => 'அனைத்தையும் காட்டு',
            'rank' => 'தரவரிசை',
            'name' => 'பெயர்',
            'score' => 'மதிப்பெண்',
            'empty' => 'இன்னும் யாரும் முன்னணி பட்டியலில் இணையவில்லை — முதலில் இணையுங்கள்!',
            'downloadPdf' => 'முடிவுகளை PDF ஆக பதிவிறக்கவும்',
            'congratsTop100' => '🎉 வாழ்த்துக்கள்! நீங்கள் முன்னணி பட்டியலில் முதல் 100-க்குள் இடம்பெற்றுள்ளீர்கள் — தரவரிசை #{rank}!',
            'ok' => 'சரி',
            'pdfHeading' => 'பைபிள் வினாடி வினா முடிவுகள்',
            'pdfPoweredBy' => 'வழங்குபவர்:',
            'pdfReviewHeading' => 'கேள்விகள் & பதில்கள்',
        ],
    ];
}

function quizLeaderboardLabels(string $lang): array
{
    $byLang = quizLeaderboardLabelsByLanguage();
    $labels = $byLang[quizCanonicalLanguageKey($lang)] ?? $byLang['English'];
    $fallback = $byLang['English'];
    return $labels + $fallback;
}

/**
 * Reads a language's built catalog (books, topics, characters, ...) as-is.
 * Used both to resolve display names and to list browsable books/themes/
 * characters (e.g. the homepage's "Quiz modes" section). Returns null if the
 * index hasn't been built for this language yet.
 */
function quizCatalogForLanguage(string $lang): ?array
{
    static $cache = [];
    if (array_key_exists($lang, $cache)) {
        return $cache[$lang];
    }

    $catalogFile = __DIR__ . '/../index/' . $lang . '/catalog.json';
    if (!is_file($catalogFile)) {
        return $cache[$lang] = null;
    }

    $raw = file_get_contents($catalogFile);
    $data = $raw !== false ? json_decode($raw, true) : null;

    return $cache[$lang] = is_array($data) ? $data : null;
}

/**
 * Book display names for a language, preferring names actually used in that
 * language's question data (via the built catalog) and falling back to the
 * canonical English names for any book the catalog doesn't cover.
 *
 * @return array<int,string> book number (1-66) => display name
 */
function quizBookNamesForLanguage(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $names = bibleBookNames();

    $data = quizCatalogForLanguage($lang);
    if (is_array($data['books'] ?? null)) {
        foreach ($data['books'] as $book) {
            if (isset($book['no'], $book['name']) && $book['name'] !== '') {
                $names[(int) $book['no']] = (string) $book['name'];
            }
        }
    }

    return $cache[$lang] = $names;
}

/**
 * Looks up the display label stored for a topic/character slug in a
 * language's built index (already in that language's script, since it's
 * copied verbatim from the source question data). Falls back to the slug
 * itself if the index entry can't be found.
 */
function quizIndexLabel(string $lang, string $type, string $slug): string
{
    if ($slug === '') {
        return '';
    }

    $file = __DIR__ . '/../index/' . $lang . '/' . $type . '/' . $slug . '.json';
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data) && !empty($data['label'])) {
            return (string) $data['label'];
        }
    }

    return $slug;
}
