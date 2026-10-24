<?php

/**
 * Short-lived storage for client-generated PDFs (counters/-style protected
 * directory: gitignored, `Require all denied`, never served directly).
 *
 * The "Download PDF" (index.php) and "Download results as PDF" (quiz.php)
 * buttons build their PDFs entirely client-side with jsPDF, then POST the
 * bytes to api/pdf-store.php so a real https:// URL (served by
 * api/pdf-download.php) can be handed to the browser instead of a blob:
 * object URL — blob: URLs can't be downloaded inside the Kodular Android
 * app's WebView (its OnDownloadStart/ActivityStarter block can only resolve
 * real http(s) URLs; no app can ever open a blob: scheme).
 *
 * Files are named "{timestamp}_{8 hex chars}.pdf" and cleaned up
 * opportunistically (see quizPdfTempCleanup()) rather than via a cron job,
 * since this repo has no cron access today.
 */

function quizPdfTempDir(): string
{
    return __DIR__ . '/../pdf-temp';
}

/**
 * Deletes any stored PDF whose mtime is before $cutoffTimestamp. Shared core
 * for quizPdfTempCleanup() (relative "older than N seconds") and
 * quizPdfTempCleanupBeforeToday() (absolute "before today").
 */
function quizPdfTempDeleteOlderThan(int $cutoffTimestamp): void
{
    $dir = quizPdfTempDir();
    $files = @glob($dir . '/*.pdf');
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoffTimestamp) {
            @unlink($file);
        }
    }
}

/**
 * Deletes any stored PDF older than $maxAgeSeconds. Called once at the top
 * of api/pdf-store.php so the directory doesn't grow without bound.
 */
function quizPdfTempCleanup(int $maxAgeSeconds = 3600): void
{
    quizPdfTempDeleteOlderThan(time() - $maxAgeSeconds);
}

/**
 * Deletes any stored PDF from before today (local server date) — a second,
 * coarser sweep called fire-and-forget from api/pdf-cleanup.php whenever
 * either "Download PDF" button is clicked, as a backstop alongside
 * quizPdfTempCleanup()'s per-store cleanup (e.g. if the site goes quiet for
 * a while, no store call runs to trigger that one until traffic resumes).
 */
function quizPdfTempCleanupBeforeToday(): void
{
    quizPdfTempDeleteOlderThan((int) strtotime('today'));
}

/**
 * Writes $bytes as a new uniquely-named PDF and returns its filename (not a
 * full path — callers only ever need the opaque name to hand back to the
 * client).
 */
function quizPdfTempSave(string $bytes): string
{
    $dir = quizPdfTempDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($dir . '/' . $filename, $bytes);

    return $filename;
}

/**
 * Validates a client-supplied filename against the exact shape
 * quizPdfTempSave() generates and confirms the resolved path is really
 * inside pdf-temp/ (same realpath()+str_starts_with() guard api/questions.php
 * and api/index.php already use for their own path-traversal checks).
 * Returns null on any mismatch or if the file doesn't exist.
 */
function quizPdfTempResolve(string $filename): ?string
{
    if (!preg_match('/^\d+_[0-9a-f]{8}\.pdf$/', $filename)) {
        return null;
    }

    $dirReal = realpath(quizPdfTempDir());
    if ($dirReal === false) {
        return null;
    }

    $fileReal = realpath($dirReal . '/' . $filename);
    if ($fileReal === false || !str_starts_with($fileReal, $dirReal . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $fileReal;
}
