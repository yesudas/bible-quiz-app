#!/usr/bin/env python3
"""Pre-generate static Bible Quiz PDFs (questions + answer key) for every
atomic selection unit — each standalone type (random/whole-bible/old-
testament/new-testament/kids/teens/adults), each book, each chapter, each
topic, each character, and each place — per language.

LOCAL-ONLY TOOL. This is never deployed to the website and never runs as
part of a live request; run it on your own machine, then upload the
resulting pdf/ tree to the server yourself. The live site's job after that
is just to find the two matching static files and stamp the player's
Header/Sub Headers (plus the page-number/link footer) onto them — see
includes/pdf-prebuilt.php.

Same recipe as the sibling bible-devotions tool
(.../private-apps/python/bible-devotions/output_pdf.py): build a
self-contained HTML page with the same Noto-first font stack, then print it
to PDF with headless Chrome via --print-to-pdf — this is what renders
Tamil/Devanagari/Kannada correctly (real browser text shaping), the same
reason the website's own client-side PDF generation used canvas rendering
for those scripts.

Page 1 of every PDF reserves a blank top-160pt zone (via the CSS `@page
:first` margin trick below) — nothing is drawn there — because the live
site's overlay step can only draw ON TOP of an existing page, never erase
existing content, so that zone has to start out genuinely empty.

Usage::

    python3 generate-quiz-pdfs.py [--repo-root PATH] [--output-dir DIR]
        [--chrome PATH] [--language LANGUAGE] [--only SUBSTR] [--limit N]
        [--force] [--quiet] [--html-only] [--keep-html]
"""

from __future__ import annotations

import argparse
import html
import json
import shutil
import subprocess
import sys
import tempfile
from dataclasses import dataclass, field
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT_DEFAULT = SCRIPT_DIR.parent

CHROME_CANDIDATES = (
    Path("/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"),
    Path("/Applications/Chromium.app/Contents/MacOS/Chromium"),
    Path("/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge"),
    Path("/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary"),
)

# One stack for every language: a browser picks the first family that has
# the glyph, so listing Latin + Tamil + Devanagari + Kannada side by side is
# harmless for editions that need none of the non-Latin faces. Same stack
# bible-devotions/output_pdf.py already uses.
FONT_STACK = (
    '"Noto Sans", "Noto Sans Tamil", "Noto Sans Devanagari", '
    '"Noto Sans Kannada", "Tamil Sangam MN", "Devanagari Sangam MN", '
    '"Kannada Sangam MN", "Kohinoor Devanagari", "Nirmala UI", '
    '"Latha", "Mangal", "Tunga", system-ui, sans-serif'
)

TYPE_KEYS = ["random", "whole-bible", "old-testament", "new-testament", "kids", "teens", "adults"]

# Mirrors includes/quiz-i18n.php's quizTypeLabelsByLanguage()/quizDetailLabelsByLanguage()
# verbatim (only English/Tamil have their own strings there too — every other
# language falls back to English, same as quizCanonicalLanguageKey() does).
TYPE_LABELS = {
    "English": {
        "random": "Random Quiz", "whole-bible": "Quiz by Whole Bible",
        "old-testament": "Quiz by Old Testament", "new-testament": "Quiz by New Testament",
        "book": "Quiz by Book", "chapter": "Quiz by Chapter", "topic": "Quiz by Topic",
        "character": "Quiz by Character", "kids": "Quiz for Kids", "teens": "Quiz for Teens",
        "adults": "Quiz for Adults", "place": "Quiz by Place",
    },
    "Tamil": {
        "random": "வினாடி வினா", "whole-bible": "முழு பைபிள் வினாடி வினா",
        "old-testament": "பழைய ஏற்பாடு வினாடி வினா", "new-testament": "புதிய ஏற்பாடு வினாடி வினா",
        "book": "புத்தகம் வாரியாக வினாடி வினா", "chapter": "அதிகாரம் வாரியாக வினாடி வினா",
        "topic": "தலைப்பு வாரியாக வினாடி வினா", "character": "வேதாகம நபர்கள் குறித்த வினாடி வினா",
        "kids": "குழந்தைகளுக்கான வினாடி வினா", "teens": "இளம் பருவத்தினருக்கான வினாடி வினா",
        "adults": "பெரியவர்களுக்கான வினாடி வினா", "place": "இடம் வாரியாக வினாடி வினா",
    },
}
DETAIL_LABELS = {
    "English": {"book": "Book", "chapter": "Chapter", "topic": "Topic", "character": "Character", "place": "Place"},
    "Tamil": {"book": "புத்தகம்", "chapter": "அதிகாரம்", "topic": "தலைப்பு", "character": "பாத்திரம்", "place": "இடம்"},
}
# Only Tamil gets its own "Questions & Options" heading (matches the
# website's own home.js, which also singles out only Tamil for this one
# string) — "Answer Key" is never translated anywhere in this app.
QUESTIONS_HEADING = {"Tamil": "கேள்விகள்"}
ANSWERS_HEADING = "Answer Key"

# "{lang} name" as used by quizCanonicalLanguageKey() to decide which of the
# two label tables above applies — both हिंदी and ಕನ್ನಡ fall back to English.
def canonical_lang_key(lang: str) -> str:
    return "Tamil" if lang in ("Tamil", "தமிழ்") else "English"


def type_label(lang: str, key: str) -> str:
    table = TYPE_LABELS[canonical_lang_key(lang)]
    return table.get(key, TYPE_LABELS["English"][key])


def detail_label(lang: str, key: str) -> str:
    table = DETAIL_LABELS[canonical_lang_key(lang)]
    return table.get(key, DETAIL_LABELS["English"][key])


def questions_heading(lang: str) -> str:
    return QUESTIONS_HEADING.get(canonical_lang_key(lang), "Questions & Options")


OPTION_LETTERS = "ABCDEFGHIJ"

# Same brand purple as the "Questions & Options"/"Answer Key" heading and
# the live site's own PDF footers.
BRAND_PURPLE = "#6d28d9"

# Both footer pieces (page number bottom-left, "WordOfGod.in" bottom-right)
# are `@page` margin-box content, not `position: fixed` HTML elements —
# confirmed empirically (decompressed the actual PDF content stream) that
# this is the only way to get them sitting on the literal same baseline.
# `position: fixed` was tried first and works for repeating something on
# every page, but it's hard-clamped to the page's CONTENT box in Chrome's
# print engine and can never actually render inside the @page margin area
# at all — pushing its `bottom` offset further doesn't move it down into
# the margin, it just clips/squashes the box against that boundary. Margin
# boxes don't have that problem (confirmed: a `@bottom-left` counter and a
# @bottom-right` string land on the identical `Tm` text-matrix Y value in
# the content stream), but they also can't host a clickable `<a href>` —
# only text/counters. That's an acceptable trade-off here: the raw
# Python-generated file's "WordOfGod.in" is styled text, not a link, but
# every file actually served to a player already gets a real clickable
# link stamped on by the live overlay (includes/pdf-prebuilt.php,
# `quizStampPdfHeaderAndFooters()`), so nothing is lost end-to-end.
#
# The blank top-160pt zone on page 1 is a plain spacer `<div>` in normal
# flow (TOP_SPACER_HEIGHT_PT below), not an `@page :first` margin override —
# confirmed empirically that an `@page :first` margin different from the
# base `@page` margin broke `position: fixed`'s page-to-page consistency
# when that was still in use; kept as a spacer regardless since it's simpler
# and doesn't depend on any page-specific margin trick.
PAGE_TOP_MARGIN_PT = 30
FIRST_PAGE_BLANK_ZONE_PT = 160
TOP_SPACER_HEIGHT_PT = FIRST_PAGE_BLANK_ZONE_PT - PAGE_TOP_MARGIN_PT
FOOTER_FONT_SIZE_PT = 11

PAGE_CSS = f"""
@page {{
  size: A4;
  margin: {PAGE_TOP_MARGIN_PT}pt 40pt;
  @bottom-left {{ content: counter(page); color: #787878; font-size: {FOOTER_FONT_SIZE_PT}pt; font-family: {FONT_STACK}; }}
  @bottom-right {{ content: "WordOfGod.in"; color: {BRAND_PURPLE}; font-weight: 700; font-size: {FOOTER_FONT_SIZE_PT}pt; font-family: {FONT_STACK}; }}
}}
* {{ box-sizing: border-box; }}
html {{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }}
body {{
  font-family: {FONT_STACK};
  font-size: 11pt;
  line-height: 1.5;
  color: #1a1a1a;
  margin: 0;
}}
.top-spacer {{ height: {TOP_SPACER_HEIGHT_PT}pt; }}
.heading {{ font-size: 15pt; font-weight: 700; color: {BRAND_PURPLE}; margin: 0 0 10pt; }}
.selection-lines {{ margin: 0 0 14pt; }}
.selection-line {{ font-size: 10pt; color: #4b4b4b; margin: 0 0 3pt; }}
.question {{ margin: 0 0 10pt; page-break-inside: avoid; }}
.question .qtext {{ font-weight: 700; font-size: 11.5pt; margin: 0 0 3pt; }}
.question .opt {{ font-size: 10.5pt; margin: 0 0 1pt 14pt; }}
.answers-columns {{ column-count: 2; column-gap: 28pt; column-fill: auto; }}
.answer {{ font-size: 10.5pt; margin: 0 0 4pt; break-inside: avoid; page-break-inside: avoid; }}
"""


def esc(value: object) -> str:
    return html.escape(str(value if value is not None else ""))


@dataclass
class Unit:
    """One atomic selection unit — one pair of output PDFs."""

    dest_stub: str  # relative to pdf/{lang}/, no extension, e.g. "chapter/01/001"
    index_rel: str  # relative to index/{lang}/, e.g. "chapter/01-001.json"
    type_key: str
    selection_lines: list[str] = field(default_factory=list)


def log(msg: str, *, quiet: bool = False) -> None:
    if not quiet:
        print(msg, file=sys.stderr)


def find_chrome(cli_path: Path | None) -> Path | None:
    if cli_path is not None:
        return cli_path if cli_path.is_file() else None
    import os

    env = os.environ.get("CHROME") or os.environ.get("GOOGLE_CHROME_BIN")
    if env and Path(env).is_file():
        return Path(env)
    for path in CHROME_CANDIDATES:
        if path.is_file():
            return path
    for name in ("google-chrome", "chromium", "chromium-browser", "chrome"):
        found = shutil.which(name)
        if found:
            return Path(found)
    return None


def discover_languages(repo_root: Path) -> list[str]:
    data_dir = repo_root / "data"
    return sorted(
        p.name for p in data_dir.iterdir() if p.is_dir() and not p.name.startswith(".")
    )


def load_json(path: Path) -> dict | None:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        log(f"  ! could not read {path}: {exc}")
        return None


def collect_units(repo_root: Path, lang: str, catalog: dict) -> list[Unit]:
    units: list[Unit] = []

    for key in TYPE_KEYS:
        units.append(Unit(
            dest_stub=key,
            index_rel=f"types/{key}.json",
            type_key=key,
            selection_lines=[f"Quiz type: {type_label(lang, key)}"],
        ))

    for book in catalog.get("books", []):
        book_key = str(book.get("key") or "")
        book_name = str(book.get("name") or book_key)
        if not book_key:
            continue
        units.append(Unit(
            dest_stub=f"book/{book_key}",
            index_rel=f"book/{book_key}.json",
            type_key="book",
            selection_lines=[
                f"Quiz type: {type_label(lang, 'book')}",
                f"{detail_label(lang, 'book')}: {book_name}",
            ],
        ))
        for chapter_no in book.get("chapters", []):
            chapter_str = f"{int(chapter_no):03d}"
            units.append(Unit(
                dest_stub=f"chapter/{book_key}/{chapter_str}",
                index_rel=f"chapter/{book_key}-{chapter_str}.json",
                type_key="chapter",
                selection_lines=[
                    f"Quiz type: {type_label(lang, 'chapter')}",
                    f"{detail_label(lang, 'book')}: {book_name}",
                    f"{detail_label(lang, 'chapter')}: {chapter_no}",
                ],
            ))

    for kind, type_key in (("topics", "topic"), ("characters", "character"), ("places", "place")):
        for item in catalog.get(kind, []):
            slug = str(item.get("slug") or "")
            label = str(item.get("label") or slug)
            if not slug:
                continue
            units.append(Unit(
                dest_stub=f"{type_key}/{slug}",
                index_rel=f"{type_key}/{slug}.json",
                type_key=type_key,
                selection_lines=[
                    f"Quiz type: {type_label(lang, type_key)}",
                    f"{detail_label(lang, type_key)}: {label}",
                ],
            ))

    return units


def build_html(heading: str, selection_lines: list[str], body_html: str) -> str:
    sel_html = "\n".join(f'<div class="selection-line">{esc(line)}</div>' for line in selection_lines)
    return (
        "<!doctype html>\n"
        f'<html lang="en"><head><meta charset="utf-8">\n'
        f"<title>{esc(heading)}</title>\n"
        f"<style>{PAGE_CSS}</style></head><body>\n"
        '<div class="top-spacer"></div>\n'
        f'<div class="heading">{esc(heading)}</div>\n'
        f'<div class="selection-lines">{sel_html}</div>\n'
        f"{body_html}\n"
        "</body></html>\n"
    )


def build_questions_body(questions: list[dict]) -> str:
    rows = []
    for i, q in enumerate(questions, start=1):
        rows.append(f'<div class="question"><div class="qtext">{i}. {esc(q.get("question", ""))}</div>')
        for idx, opt in enumerate(q.get("options") or []):
            letter = OPTION_LETTERS[idx] if idx < len(OPTION_LETTERS) else "?"
            rows.append(f'<div class="opt">{letter}. {esc(opt)}</div>')
        rows.append("</div>")
    return "\n".join(rows)


def build_answers_body(questions: list[dict]) -> str:
    rows = []
    for i, q in enumerate(questions, start=1):
        options = q.get("options") or []
        correct_idx = q.get("correct_option")
        correct_idx = correct_idx if isinstance(correct_idx, int) else -1
        letter = OPTION_LETTERS[correct_idx] if 0 <= correct_idx < len(OPTION_LETTERS) else "?"
        correct_text = options[correct_idx] if 0 <= correct_idx < len(options) else ""
        rows.append(f'<div class="answer">{i}. {letter}) {esc(correct_text)}</div>')
    return '<div class="answers-columns">\n' + "\n".join(rows) + "\n</div>"


def pdf_render_timeout(question_count: int) -> int:
    """Large documents (e.g. English whole-bible/random at 13k+ questions)
    need far longer than small ones for Chrome to lay out thousands of
    page-break-inside:avoid blocks — scale the timeout with question count
    instead of using one fixed value that's either too short for huge units
    or needlessly long for small ones."""
    return min(3600, max(300, question_count // 3))


def render_pdf(chrome: Path, html_path: Path, pdf_path: Path, *, timeout: int = 300) -> None:
    cmd = [
        str(chrome),
        "--headless=new",
        "--disable-gpu",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-extensions",
        "--no-pdf-header-footer",
        "--disable-hang-monitor",
        "--run-all-compositor-stages-before-draw",
        "--virtual-time-budget=15000",
        f"--print-to-pdf={pdf_path}",
        html_path.as_uri(),
    ]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        pdf_path.unlink(missing_ok=True)  # drop any partial file so a retry doesn't skip it
        raise RuntimeError(f"Chrome timed out after {timeout}s writing {pdf_path.name}")
    if result.returncode != 0 or not pdf_path.is_file() or pdf_path.stat().st_size == 0:
        detail = (result.stderr or result.stdout or "").strip()
        raise RuntimeError(
            f"Chrome failed to write {pdf_path.name} (exit {result.returncode})"
            + (f": {detail}" if detail else "")
        )


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Pre-generate static Bible Quiz PDFs (questions + answer key) "
        "for every atomic selection unit, per language."
    )
    parser.add_argument("--repo-root", type=Path, default=REPO_ROOT_DEFAULT, help="bible-quiz-app checkout root")
    parser.add_argument("--output-dir", type=Path, default=None, help="default: <repo-root>/pdf")
    parser.add_argument("--chrome", type=Path, default=None, help="path to the Chrome/Chromium binary")
    parser.add_argument("--language", default=None, help="only this language (data/ folder name)")
    parser.add_argument("--only", default="", help="only units whose dest path contains this substring")
    parser.add_argument("--limit", type=int, default=0, help="only the first N units (post --only/--language)")
    parser.add_argument("--force", action="store_true", help="re-render even when the target PDF exists")
    parser.add_argument("--html-only", action="store_true", help="write the HTML files but do not run Chrome")
    parser.add_argument("--keep-html", action="store_true", help="keep the intermediate HTML under <output-dir>/html/")
    parser.add_argument("--quiet", action="store_true", help="suppress progress output")
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)
    repo_root: Path = args.repo_root.expanduser().resolve()
    output_dir: Path = (args.output_dir or (repo_root / "pdf")).expanduser().resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    chrome = None
    if not args.html_only:
        chrome = find_chrome(args.chrome)
        if chrome is None:
            log("FATAL: could not find Chrome/Chromium. Pass --chrome PATH, set $CHROME, or use --html-only.")
            return 1
        log(f"Chrome: {chrome}", quiet=args.quiet)

    languages = [args.language] if args.language else discover_languages(repo_root)
    log(f"Languages: {', '.join(languages)}", quiet=args.quiet)

    if args.keep_html or args.html_only:
        html_dir = output_dir / "html"
        html_dir.mkdir(exist_ok=True)
        cleanup_html = False
    else:
        html_dir = Path(tempfile.mkdtemp(prefix="quiz-pdf-html-"))
        cleanup_html = True

    all_units: list[tuple[str, Unit]] = []
    for lang in languages:
        catalog_path = repo_root / "index" / lang / "catalog.json"
        catalog = load_json(catalog_path)
        if not catalog:
            log(f"  ! skipping {lang}: no catalog.json (run the index builder first)")
            continue
        for unit in collect_units(repo_root, lang, catalog):
            if args.only and args.only not in unit.dest_stub and args.only not in lang:
                continue
            all_units.append((lang, unit))

    if args.limit:
        all_units = all_units[: args.limit]

    log(f"Total units: {len(all_units)}", quiet=args.quiet)

    made = skipped = failed = 0
    try:
        for lang, unit in all_units:
            index_path = repo_root / "index" / lang / unit.index_rel
            index_data = load_json(index_path)
            if not index_data or not index_data.get("paths"):
                log(f"  ! no questions for {lang}/{unit.dest_stub}, skipping")
                continue

            questions = []
            for rel_path in index_data["paths"]:
                q = load_json(repo_root / "data" / rel_path)
                if q:
                    questions.append(q)
            if not questions:
                continue

            questions_pdf = output_dir / lang / f"{unit.dest_stub}.pdf"
            answers_pdf = output_dir / lang / f"{unit.dest_stub}-answers.pdf"
            questions_pdf.parent.mkdir(parents=True, exist_ok=True)

            for pdf_path, heading, body_builder in (
                (questions_pdf, questions_heading(lang), build_questions_body),
                (answers_pdf, ANSWERS_HEADING, build_answers_body),
            ):
                if pdf_path.is_file() and not args.force and not args.html_only:
                    skipped += 1
                    continue
                html_doc = build_html(heading, unit.selection_lines, body_builder(questions))
                html_path = html_dir / lang / f"{pdf_path.stem}.html"
                html_path.parent.mkdir(parents=True, exist_ok=True)
                html_path.write_text(html_doc, encoding="utf-8")
                if args.html_only:
                    made += 1
                    continue
                try:
                    render_pdf(chrome, html_path, pdf_path, timeout=pdf_render_timeout(len(questions)))
                    made += 1
                    log(f"  pdf  {lang}/{pdf_path.relative_to(output_dir / lang)}", quiet=args.quiet)
                except RuntimeError as exc:
                    failed += 1
                    log(f"  ! {exc}")
    finally:
        if cleanup_html:
            shutil.rmtree(html_dir, ignore_errors=True)

    log(
        f"\nDone. {made} PDF(s) written, {skipped} already present, {failed} failed, in {output_dir}",
        quiet=args.quiet,
    )
    return 1 if failed and not made else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
