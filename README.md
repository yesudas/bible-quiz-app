# Free Bible Quiz

Mobile-friendly, SEO-oriented Bible quiz web app with PWA support. Question JSON lives under `data/` (for example `data/English/01/` for Genesis). The public site reads pre-built indexes from `index/`.

## Requirements

- PHP 8.1+ with JSON extension
- Web server (Apache/Nginx) or `php -S` for local development

## Local development

```bash
cd bible-quiz-app
php -S localhost:8080
```

Open `http://localhost:8080/`.

## Admin access

The admin panel (`a.php`) manages question files under `data/` and links to the index builder (`i.php`) and sitemap generator (`sitemap.php`).

**Credentials are not in git.** Copy the example file and set a bcrypt password hash:

```bash
cp admin-credentials.example.php admin-credentials.php
php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost'=>12]);"
```

Paste the hash into `ADMIN_HASH` in `admin-credentials.php`. Use `ADMIN_USER` for the login username.

Then visit `/a.php`, log in, upload or edit JSON under `data/`, run **Rebuild Quiz Index** (`i.php`), and optionally **Generate Sitemap** (`sitemap.php`).

## Question JSON format

Each file is one question, for example:

- `id`, `language`, `book_no`, `book`, `chapter`, `verse_reference`
- `question`, `options` (array), `correct_option` (0-based index)
- `themes`, `characters`, `audiences` (`kids`, `teens`, `adults`, …)
- `places` (optional) — array of `{"name": "Egypt", "type": "country"}` objects; only `name` is indexed today, `type` is carried through for future use
- `explanation`

## Quiz modes

Indexes are built for: random, whole Bible, Old/New Testament, book, chapter, topic, character, place, kids, teens, and adults. On the home page, users choose **how many questions per set** (default 10); the quiz chunks the indexed `paths` accordingly for scoring and review.

Answer options are reshuffled client-side each time a question is rendered (`assets/js/quiz.js`), so the correct answer isn't always in the same position on a repeat attempt; scoring and review stay correct because `correct_option` is remapped along with the shuffle.

Every quiz type can optionally be narrowed by **difficulty** (Easy / Medium / Difficult, from each question's `difficulty` field) — check any combination on the setup form, or leave all unchecked to include every difficulty. This is layered on top of the chosen type via `index/{Language}/difficulty/{easy|medium|difficult}.json` (built alongside the other indexes) and applied server-side in `api/index.php` as a path intersection, so it works with random, book, chapter, topic, character, and place quizzes alike.

Below the mode tiles, the home page also lists every individual **book, topic ("theme"), character, and place** for the selected language as a clickable chip (with its question count), pulled straight from `index/{Language}/catalog.json` via `quizCatalogForLanguage()`. Clicking one jumps straight into `quiz.php` with that book/topic/character/place and a default 10-question set (no timer, no difficulty filter) — unlike the "Quiz modes" tiles above, which land on the setup form instead. These lists — like the mode tiles — refresh via AJAX when the language is switched, and a section hides itself entirely if that language has nothing to list yet (e.g. no places tagged in the source data).

## Languages & localization

Each folder under `data/` is a language (e.g. `data/English/`, `data/தமிழ்/`); folders may be named natively (`தமிழ்`) or in ASCII (`Tamil`) — both work. Book names shown to users come from the question data itself (the `book` field), so they're automatically in whatever language that data is written in.

Quiz type labels ("Random Quiz", "Quiz by Book", …) and the results-screen field labels ("Type", "Book", "Chapters", …) are translated in `includes/quiz-i18n.php`. To add a new language's UI text:

1. Add the language's native display name to `quizLanguageNames()` (and, if its `data/` folder uses a native-script name, an alias entry in `quizLanguageAliasKeys()` mapping it back to a canonical ASCII key).
2. Add a matching entry to `quizTypeLabelsByLanguage()` and `quizDetailLabelsByLanguage()`.

Missing translations fall back to English automatically. Topic/character labels don't need a translation table — they're read from that language's own built index (`index/{Language}/topic/*.json`), which already stores the label text as written in the source data.

**Important:** these labels only reflect what's in `index/`. Any time `data/` changes — new questions, edited themes/characters, or a fresh translation of existing content — you need to **rebuild the index** (admin panel → Rebuild Quiz Index, or `i.php`) before the new/translated books, topics, or characters show up anywhere on the site. There's no separate "update the translation" step for data-driven content; the rebuild *is* the update.

Switching the Language dropdown on the home page doesn't reload the page — it re-fetches `api/catalog.php`, which returns that language's books/topics/characters *and* a `labels` object (translated quiz type names, difficulty names, and the word for "Chapter"). `assets/js/home.js` uses this to re-translate the quiz type dropdown, the mode tiles, and the difficulty checkboxes in place. The rest of the quiz setup (type, book/chapter/topic/character selection, difficulty, timer) is **reset back to its defaults** on every language switch, rather than carried across — topic and character slugs in particular are language-specific (a Tamil theme slug doesn't exist in the English index), so keeping them selected after a switch would point at something that no longer exists.

## Sharing a quiz

The **Share Quiz** button (next to Start Quiz) generates a short signed link like `/bible-quiz/?test=Ab7Kx92PmQ.X8kL2a` that reproduces the exact quiz setup (language, type, set size, timer, books/chapters/topic/character/place, difficulty) for whoever opens it.

- `api/share.php` validates the current selection and packs it into a compact binary payload, signed with HMAC-SHA256 (`includes/quiz-share.php`).
- Visiting `index.php?test=...` decodes and verifies the token, then redirects straight into `quiz.php` with the reconstructed parameters. A tampered or invalid token shows an inline notice instead of failing silently.
- The signing key lives in `share-secret.php` (gitignored) and is generated automatically on first use — no setup required. To pin your own, copy `share-secret.example.php` to `share-secret.php`.

## Leaderboard

Right after finishing the **first set** of a quiz attempt (not the whole attempt — long books split into many sets might never be finished in one sitting), players are asked once whether they want to join the leaderboard by giving a display name and a mobile number with country code. **Both are required to join** — leaving the mobile number out (or dismissing the popup) just shows the display name on the results screen, with nothing saved anywhere. Once they've joined or skipped, later sets in the same attempt don't ask again: joining keeps the leaderboard score updated as they complete more sets, skipping just keeps showing their typed name.

- The popup clearly states both fields are mandatory, and explains that the mobile number is encrypted and never shown publicly or shared.
- `includes/quiz-leaderboard.php` encrypts the mobile number with AES-256-GCM before writing it (and computes a separate HMAC "match hash" so repeat players — same number — get merged into one row that keeps their best score, without ever needing to decrypt anything to check for a match). The private master record lives in `logs/leaderboard-data.json` (gitignored, same protected directory as `logs/mistake-reports.log`).
- Two public files are regenerated after every submission, containing no PII — just `{displayName, country, score}`: `leaderboard/top10.json` (top 10) and `leaderboard/all.json` (everyone, highest to lowest). `api/leaderboard.php` serves these; `leaderboard.php` is the public "Top 10 / Show all" page.
- The encryption/match-hash keys live in `leaderboard-secret.php` (gitignored) and are generated automatically on first use — no setup required. To pin your own, copy `leaderboard-secret.example.php` to `leaderboard-secret.php`.
- `a.php?view=leaderboard` is the only place a mobile number is ever decrypted and shown — an admin-only view for moderation, with a "Remove" action per entry.
- On their own results screen, a player who joined sees their mobile number masked (e.g. `+91 ••••••89`) with a "Show number"/"Hide number" toggle — this is purely a convenience on their own device, unrelated to the "never shown publicly" guarantee for everyone else.
- A finished quiz's results screen also has a "Download results as PDF" button (client-side, via `html2canvas` + `jsPDF` loaded from a CDN on first click) that works on both desktop and mobile browsers.
- The display name, country code, and mobile number typed into the popup are remembered in the browser's `localStorage` (`bibleQuizPlayerInfo`), so a returning player doesn't have to retype them on their next quiz.

## Assets

CSS, JavaScript, and PWA icons live under `assets/`. Service worker: `sw.js`, manifest: `manifest.json`.

## Visitor counter

`counter.php` increments `counter.txt` for non-bot visits (included on public pages), tracking both an overall total and a per-language breakdown:

```json
{"total": 1234, "languages": {"English": 800, "தமிழ்": 434}}
```

The read/write logic lives in `includes/visitor-counter.php` (`quizIncrementVisitorCount()` / `quizReadVisitorCounts()`), guarded by an exclusive file lock so concurrent requests don't clobber each other. A `counter.txt` left over from before this change (a bare integer) is migrated into the new shape automatically the next time it's written — no manual step needed. The admin panel (`a.php`) shows both the total and the per-language counts at the top of the admin home view; it only *reads* the counts, so opening or refreshing it never bumps any number. The public site footer still shows only the overall total.

A page counts as a visit in whatever language its `?lang=...` says — so every internal link (including the header logo and "Back to home") needs to carry the current language along, or it's counted (and shown) as the default language instead.

## License

No Copyright — “Freely you have received; Freely give” — Matt 10:8
