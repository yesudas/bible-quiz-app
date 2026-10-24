<?php

/**
 * Resolves a quiz selection to its pre-generated static PDF pair (under
 * pdf/{Language}/...) and stamps the player's Header/Sub Headers onto page
 * 1 of each, plus a page-number + "WordOfGod.in" link footer on every page.
 *
 * The static files themselves are produced entirely offline by
 * tools/generate-quiz-pdfs.py (run locally, never on this server) — this
 * file only ever reads them. See that script's own header comment and
 * AGENTS.md's PDF-download-delivery bullet for the full directory-layout
 * and blank-top-160pt-zone contract both sides agree on.
 *
 * Needs FPDI + FPDF (setasign/fpdi, setasign/fpdf) vendored into
 * includes/vendor/ — see includes/vendor/README.md for exactly how, since
 * that step has to happen on your own machine (this environment has no way
 * to fetch third-party library source reliably).
 */

$quizFpdiAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($quizFpdiAutoload)) {
    require_once $quizFpdiAutoload;
}

function quizPrebuiltPdfDir(): string
{
    return __DIR__ . '/../pdf';
}

/**
 * @param array{books?: list<string>, chapters?: list<string>, topic?: string, character?: string, place?: string} $selection
 * @return array{questions: string, answers: string}|null absolute paths, or null if the selection isn't exactly one
 *         atomic unit or the matching files don't exist.
 */
function quizResolvePrebuiltPaths(string $lang, string $type, array $selection): ?array
{
    $langDir = realpath(quizPrebuiltPdfDir() . '/' . $lang);
    if ($langDir === false) {
        return null;
    }

    $simpleTypes = ['random', 'whole-bible', 'old-testament', 'new-testament', 'kids', 'teens', 'adults'];
    $stub = null;

    if (in_array($type, $simpleTypes, true)) {
        $stub = $type;
    } elseif ($type === 'book') {
        $books = array_values(array_filter((array) ($selection['books'] ?? [])));
        if (count($books) !== 1) {
            return null;
        }
        $bookKey = str_pad(preg_replace('/[^0-9]/', '', (string) $books[0]), 2, '0', STR_PAD_LEFT);
        if (!preg_match('/^\d{2}$/', $bookKey)) {
            return null;
        }
        $stub = 'book/' . $bookKey;
    } elseif ($type === 'chapter') {
        $chapters = array_values(array_filter((array) ($selection['chapters'] ?? [])));
        if (count($chapters) !== 1) {
            return null;
        }
        $chapterKey = preg_replace('/[^0-9\-]/', '', (string) $chapters[0]);
        if (!preg_match('/^(\d{2})-(\d{3})$/', $chapterKey, $m)) {
            return null;
        }
        $stub = 'chapter/' . $m[1] . '/' . $m[2];
    } elseif (in_array($type, ['topic', 'character', 'place'], true)) {
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}\-_]/u', '', (string) ($selection[$type] ?? ''));
        if ($slug === '') {
            return null;
        }
        $stub = $type . '/' . $slug;
    } else {
        return null;
    }

    $questions = realpath($langDir . '/' . $stub . '.pdf');
    $answers = realpath($langDir . '/' . $stub . '-answers.pdf');
    if ($questions === false || $answers === false) {
        return null;
    }
    if (!str_starts_with($questions, $langDir . DIRECTORY_SEPARATOR) || !str_starts_with($answers, $langDir . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return ['questions' => $questions, 'answers' => $answers];
}

const QUIZ_PDF_BRAND = [109, 40, 217];

/**
 * Imports every page of $staticPdfPath as-is, draws $headerText + up to
 * three $subLines into the blank top-160pt zone of page 1 only (the one
 * truly dynamic part — the offline generator leaves that area empty for
 * exactly this). The page-number and "WordOfGod.in" footer text are NOT
 * redrawn here — the offline generator already bakes both into every page
 * via CSS `@bottom-left`/`@bottom-right` margin boxes, and drawing them a
 * second time on top (as this used to do) produced visibly doubled,
 * misaligned footer text, since FPDF's own text metrics don't line up
 * pixel-for-pixel with Chrome's. Instead, an invisible clickable Link()
 * annotation is placed over the baked-in "WordOfGod.in" text's approximate
 * position, so the link still works in interactive viewers without drawing
 * anything visible. Returns the finished PDF as a string, ready for
 * quizPdfTempSave().
 *
 * @param list<string> $subLines
 */
function quizStampPdfHeaderAndFooters(string $staticPdfPath, string $headerText, array $subLines): string
{
    if (!class_exists(\setasign\Fpdi\Fpdi::class)) {
        throw new RuntimeException(
            'FPDI/FPDF not installed — see includes/vendor/README.md for the one-time local setup step.'
        );
    }

    $pdf = new \setasign\Fpdi\Fpdi('P', 'pt', 'A4');
    $pdf->SetAutoPageBreak(false);
    $pageCount = $pdf->setSourceFile($staticPdfPath);

    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $templateId = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($templateId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);

        if ($pageNo === 1) {
            $pdf->SetFont('Helvetica', 'B', 20);
            $pdf->SetTextColor(QUIZ_PDF_BRAND[0], QUIZ_PDF_BRAND[1], QUIZ_PDF_BRAND[2]);
            $pdf->SetXY(0, 40);
            $pdf->Cell($size['width'], 28, $headerText, 0, 2, 'C');

            $pdf->SetFont('Helvetica', '', 11);
            $pdf->SetTextColor(70, 70, 70);
            $y = 40 + 34;
            foreach ($subLines as $sub) {
                $sub = trim((string) $sub);
                if ($sub === '') {
                    continue;
                }
                $pdf->SetXY(0, $y);
                $pdf->Cell($size['width'], 16, $sub, 0, 2, 'C');
                $y += 24;
            }
        }

        $footerY = $size['height'] - 24;
        $pdf->Link($size['width'] - 140, $footerY - 4, 104, 18, 'https://wordofgod.in/bible-quiz');
    }

    return $pdf->Output('S');
}
