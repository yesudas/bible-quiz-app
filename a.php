<?php
/**
 * a.php — Secure Admin Control Panel
 *
 * Features: create folders, upload files/folders, rename, delete
 * All operations are restricted to the data/ and index/ directories.
 *
 * Login credentials (ADMIN_USER / ADMIN_HASH) live in admin-credentials.php,
 * which is gitignored — see that file for how to change the password.
 */

require_once __DIR__ . '/admin-credentials.php';
require_once __DIR__ . '/includes/visitor-counter.php';
require_once __DIR__ . '/includes/quiz-i18n.php';
require_once __DIR__ . '/includes/mistake-reports.php';

// ── Configuration ────────────────────────────────────────────────────────────
//define('ADMIN_USER', 'USER_NAME_HERE');
//define('ADMIN_HASH', 'YOUR_ADMIN_PASSWORD_HERE'); // bcrypt hash of the password

define('BASE_DATA_DIR',       realpath(__DIR__ . '/data'));
define('BASE_INDEX_DIR',      realpath(__DIR__ . '/index'));
define('MAX_UPLOAD_BYTES',    512 * 1024 * 1024); // 512 MB per file
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOCKOUT_SECONDS',     900);               // 15 minutes

// Whitelisted upload extensions (no PHP, no scripts)
$ALLOWED_EXT = [
    'json',
    'pdf','epub','mobi','doc','docx','odt','rtf','txt','md',
    'mp3','mp4','m4a','m4b','m4v','wav','ogg','aac','flac','wma',
    'jpg','jpeg','png','gif','webp','bmp','tiff','svg',
    'zip','rar','7z','tar','gz','bz2',
    'apk',
    'xls','xlsx','ppt','pptx','csv',
    'html','htm',
];

// ── Security headers ─────────────────────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── Session configuration ────────────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
session_name('admin_sess');
session_start();

// ── Helper functions ─────────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('CSRF validation failed. Go back and try again.');
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['admin_ok']) && $_SESSION['admin_ok'] === true;
}

function isLockedOut(): bool {
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lastTime  = $_SESSION['last_attempt']  ?? 0;
    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        if ((time() - $lastTime) < LOCKOUT_SECONDS) {
            return true;
        }
        // Lockout expired — reset counter
        $_SESSION['login_attempts'] = 0;
    }
    return false;
}

/** @return array<string, string|false> */
function adminRoots(): array {
    return ['data' => BASE_DATA_DIR, 'index' => BASE_INDEX_DIR];
}

function normalizeAdminRoot(string $root): string|false {
    $roots = adminRoots();
    return (isset($roots[$root]) && $roots[$root] !== false) ? $root : false;
}

function adminBaseDir(string $root): string|false {
    $root = normalizeAdminRoot($root);
    return $root === false ? false : adminRoots()[$root];
}

function adminBrowseUrl(string $root, string $path = ''): string {
    $params = ['root' => $root];
    if ($path !== '') {
        $params['path'] = $path;
    }
    return 'a.php?' . http_build_query($params);
}

function adminLocationQuery(string $root, string $path): string {
    $params = ['root' => $root];
    if ($path !== '') {
        $params['path'] = $path;
    }
    return '?' . http_build_query($params);
}

/**
 * Resolve a relative path inside an admin root using realpath (existing paths only).
 * Returns the absolute path or false if outside the root.
 */
function resolveAdminPath(string $root, string $rel): string|false {
    $base = adminBaseDir($root);
    if ($base === false) return false;
    $rel  = str_replace("\0", '', $rel);
    if ($rel === '' || $rel === '.' || $rel === '/') return $base;
    $full = realpath($base . DIRECTORY_SEPARATOR . ltrim($rel, '/\\'));
    if ($full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) && $full !== $base) {
        return false;
    }
    return $full;
}

/** Resolve a path inside data/ (copyright reports and public file paths). */
function resolveDataPath(string $rel): string|false {
    return resolveAdminPath('data', $rel);
}

/**
 * Build a safe absolute path for a not-yet-existing item inside an admin root.
 * Manually resolves ".." to prevent traversal — since realpath() only works
 * on existing paths.
 */
function resolveAdminPathNew(string $root, string $parentFull, string $name): string|false {
    $base = adminBaseDir($root);
    if ($base === false || !str_starts_with($parentFull, $base)) return false;
    // $name must have no path separators (sanitized before calling)
    if (str_contains($name, '/') || str_contains($name, '\\') || $name === '' || $name === '.' || $name === '..') {
        return false;
    }
    return $parentFull . DIRECTORY_SEPARATOR . $name;
}

function resolveDataPathNew(string $parentFull, string $name): string|false {
    return resolveAdminPathNew('data', $parentFull, $name);
}

/**
 * Sanitize a single filename or folder-name component.
 * Strips null bytes, path separators, leading dots, and unsafe chars.
 */
function sanitizeName(string $name): string {
    $name = str_replace(["\0", '/', '\\'], '', $name);
    $name = ltrim($name, '.');                                // no hidden files
    $name = preg_replace('/[\x00-\x1F\x7F<>:"?*|]/', '', $name);
    return trim($name);
}

function isAllowedExt(string $filename, array $allowed): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true);
}

function humanSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576,    2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024,       2) . ' KB';
    return $bytes . ' B';
}

/** Recursively delete a directory — guards that path stays inside the given admin root. */
function rmdirRecursive(string $path, string $base): bool {
    if (!str_starts_with($path, $base . DIRECTORY_SEPARATOR) || $path === $base) {
        return false; // refuse to delete the root itself
    }
    if (is_file($path) || is_link($path)) return unlink($path);
    if (!is_dir($path)) return false;
    $items = scandir($path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        rmdirRecursive($path . DIRECTORY_SEPARATOR . $item, $base);
    }
    return rmdir($path);
}

// ── Handle POST: Login ───────────────────────────────────────────────────────
$msg     = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (isLockedOut()) {
        $msg = 'Too many failed attempts. Try again in 15 minutes.';
        $msgType = 'error';
    } else {
        $u = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';
        if ($u === ADMIN_USER && password_verify($p, ADMIN_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin_ok']        = true;
            $_SESSION['login_attempts']  = 0;
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['last_attempt']   = time();
        // Generic error to avoid username enumeration
        $msg = 'Invalid username or password.';
        $msgType = 'error';
    }
}

// ── Handle POST: Authenticated actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    verifyCsrf();

    $action      = $_POST['action']       ?? '';
    $currentRoot = normalizeAdminRoot($_POST['current_root'] ?? 'data') ?: 'data';
    $currentPath = $_POST['current_path'] ?? '';
    $atAdminHome = ($_POST['current_root'] ?? '') === '' && $currentPath === '';

    // Logout
    if ($action === 'logout') {
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // Create folder
    if ($action === 'create_folder') {
        if ($atAdminHome) {
            $msg = 'Open data/ or index/ before creating a folder.'; $msgType = 'error';
        } else {
        $rawName = $_POST['folder_name'] ?? '';
        $name    = sanitizeName($rawName);
        if ($name === '') {
            $msg = 'Invalid folder name.'; $msgType = 'error';
        } else {
            $parent = resolveAdminPath($currentRoot, $currentPath);
            if (!$parent || !is_dir($parent)) {
                $msg = 'Invalid parent directory.'; $msgType = 'error';
            } else {
                $newDir = resolveAdminPathNew($currentRoot, $parent, $name);
                if ($newDir === false) {
                    $msg = 'Invalid folder name.'; $msgType = 'error';
                } elseif (file_exists($newDir)) {
                    $msg = "Folder \"$name\" already exists."; $msgType = 'error';
                } elseif (@mkdir($newDir, 0755)) {
                    $msg = "Folder \"$name\" created successfully."; $msgType = 'success';
                } else {
                    $msg = 'Failed to create folder. Check server permissions.'; $msgType = 'error';
                }
            }
        }
        }
    }

    // Upload files (including webkitdirectory folder uploads)
    if ($action === 'upload' && !empty($_FILES['files'])) {
        if ($atAdminHome) {
            $msg = 'Open data/ or index/ before uploading.'; $msgType = 'error';
        } else {
        $dest = resolveAdminPath($currentRoot, $currentPath);
        if (!$dest || !is_dir($dest)) {
            $msg = 'Invalid upload destination.'; $msgType = 'error';
        } else {
            $uploadBase = adminBaseDir($currentRoot);
            $uploaded = 0;
            $failed   = 0;
            $skipped  = [];

            $names    = (array)($_FILES['files']['name']     ?? []);
            $tmpNames = (array)($_FILES['files']['tmp_name'] ?? []);
            $errors   = (array)($_FILES['files']['error']    ?? []);
            $sizes    = (array)($_FILES['files']['size']     ?? []);

            foreach ($names as $i => $rawRelName) {
                if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $failed++; continue;
                }
                if (($sizes[$i] ?? 0) > MAX_UPLOAD_BYTES) {
                    $skipped[] = basename($rawRelName) . ' (too large)'; $failed++; continue;
                }
                if (!is_uploaded_file($tmpNames[$i])) {
                    $failed++; continue;
                }

                // Parse relative path (webkitdirectory sends "FolderA/sub/file.pdf")
                $rawRelName  = str_replace("\0", '', $rawRelName);
                $pathParts   = preg_split('#[/\\\\]+#', $rawRelName);

                // Sanitize every component
                $cleanParts = [];
                foreach ($pathParts as $part) {
                    $clean = sanitizeName($part);
                    if ($clean !== '') $cleanParts[] = $clean;
                }

                if (empty($cleanParts)) { $failed++; continue; }

                $fileName = array_pop($cleanParts); // last = filename, rest = subdirs

                // Extension check on filename
                if (!isAllowedExt($fileName, $ALLOWED_EXT)) {
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $skipped[] = "$fileName (.$ext not allowed)"; $failed++; continue;
                }

                // Build (and create) subdirectory path
                $targetDir = $dest;
                foreach ($cleanParts as $dirPart) {
                    $targetDir .= DIRECTORY_SEPARATOR . $dirPart;
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                }

                // Verify targetDir is still inside the admin root after creation
                $realTargetDir = realpath($targetDir);
                if (!$realTargetDir || !$uploadBase || !str_starts_with($realTargetDir, $uploadBase)) {
                    $failed++; continue;
                }

                $targetFile = $realTargetDir . DIRECTORY_SEPARATOR . $fileName;

                // If a file with the same name exists, add a suffix rather than overwrite blindly
                if (file_exists($targetFile)) {
                    $ext  = pathinfo($fileName, PATHINFO_EXTENSION);
                    $base = pathinfo($fileName, PATHINFO_FILENAME);
                    $n    = 1;
                    do {
                        $fileName   = $base . "_($n)" . ($ext ? ".$ext" : '');
                        $targetFile = $realTargetDir . DIRECTORY_SEPARATOR . $fileName;
                        $n++;
                    } while (file_exists($targetFile));
                }

                if (move_uploaded_file($tmpNames[$i], $targetFile)) {
                    $uploaded++;
                } else {
                    $failed++;
                }
            }

            if ($uploaded > 0) {
                $msg = "$uploaded file(s) uploaded successfully.";
                if ($failed > 0) {
                    $msg .= " $failed rejected";
                    if ($skipped) $msg .= ': ' . implode(', ', array_slice($skipped, 0, 5));
                    $msg .= '.';
                }
                $msgType = 'success';
            } else {
                $msg = "All uploads failed. Only these extensions are allowed: " . implode(', ', $ALLOWED_EXT) . '.';
                $msgType = 'error';
            }
        }
        }
    }

    // Rename
    if ($action === 'rename') {
        $itemPath = $_POST['item_path'] ?? '';
        $newName  = sanitizeName($_POST['new_name'] ?? '');

        $rootBase = adminBaseDir($currentRoot);
        $oldFull  = resolveAdminPath($currentRoot, $itemPath);

        if (!$oldFull || !file_exists($oldFull)) {
            $msg = 'Item not found.'; $msgType = 'error';
        } elseif ($rootBase && $oldFull === $rootBase) {
            $msg = 'Cannot rename the root ' . $currentRoot . ' directory.'; $msgType = 'error';
        } elseif ($newName === '') {
            $msg = 'Invalid name.'; $msgType = 'error';
        } else {
            // For files: if renaming without extension, keep the original one;
            // and always ensure the final extension is on the whitelist.
            if (is_file($oldFull)) {
                $origExt = strtolower(pathinfo($oldFull, PATHINFO_EXTENSION));
                $newExt  = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
                if ($newExt === '' && $origExt !== '') {
                    $newName .= '.' . $origExt;
                    $newExt = $origExt;
                }
                if (!in_array($newExt, $ALLOWED_EXT, true)) {
                    $msg = "Extension .$newExt is not allowed."; $msgType = 'error';
                    goto render_page; // skip rename
                }
            }

            $parentFull = dirname($oldFull);
            $newFull    = resolveAdminPathNew($currentRoot, $parentFull, $newName);

            if ($newFull === false) {
                $msg = 'Invalid new name.'; $msgType = 'error';
            } elseif (file_exists($newFull)) {
                $msg = "\"$newName\" already exists in this folder."; $msgType = 'error';
            } elseif (rename($oldFull, $newFull)) {
                $msg = "Renamed to \"$newName\" successfully."; $msgType = 'success';
            } else {
                $msg = 'Rename failed. Check server permissions.'; $msgType = 'error';
            }
        }
    }

    // Hide a reported file from the public site (rename with "HIDE - " prefix)
    if ($action === 'hide_report_file') {
        $filePath = $_POST['file_path'] ?? '';
        $full     = resolveDataPath($filePath);

        if (!$full || !is_file($full)) {
            $msg = 'File not found.'; $msgType = 'error';
        } else {
            $dir  = dirname($full);
            $name = basename($full);
            if (stripos($name, 'HIDE') === 0) {
                $msg = "\"$name\" is already hidden."; $msgType = 'success';
            } else {
                $newName = 'HIDE - ' . $name;
                $newFull = $dir . DIRECTORY_SEPARATOR . $newName;
                if (file_exists($newFull)) {
                    $msg = 'A hidden version of this file already exists.'; $msgType = 'error';
                } elseif (rename($full, $newFull)) {
                    $msg = "\"$name\" has been hidden from the public site."; $msgType = 'success';
                } else {
                    $msg = 'Failed to hide file. Check server permissions.'; $msgType = 'error';
                }
            }
        }
        header('Location: a.php?view=reports');
        exit;
    }

    // Recover a previously hidden file (remove the "HIDE - " prefix)
    if ($action === 'recover_report_file') {
        $filePath  = $_POST['file_path'] ?? '';
        $dir       = dirname($filePath);
        $base      = basename($filePath);
        $hiddenRel = ($dir === '.' || $dir === '') ? ('HIDE - ' . $base) : ($dir . '/HIDE - ' . $base);
        $hiddenFull = resolveDataPath($hiddenRel);

        if (!$hiddenFull || !is_file($hiddenFull)) {
            $msg = 'Hidden file not found.'; $msgType = 'error';
        } else {
            $targetFull = dirname($hiddenFull) . DIRECTORY_SEPARATOR . $base;
            if (file_exists($targetFull)) {
                $msg = "A file named \"$base\" already exists; cannot recover."; $msgType = 'error';
            } elseif (rename($hiddenFull, $targetFull)) {
                $msg = "\"$base\" has been restored to the public site."; $msgType = 'success';
            } else {
                $msg = 'Failed to recover file. Check server permissions.'; $msgType = 'error';
            }
        }
        header('Location: a.php?view=reports');
        exit;
    }

    // Delete a copyright report
    if ($action === 'delete_report') {
        $reportsDir  = realpath(__DIR__ . '/reports');
        $reportName  = basename($_POST['report_id'] ?? '');
        $reportFile  = $reportsDir ? $reportsDir . DIRECTORY_SEPARATOR . $reportName : false;
        if ($reportsDir && $reportFile && pathinfo($reportFile, PATHINFO_EXTENSION) === 'json'
            && is_file($reportFile) && dirname(realpath($reportFile)) === $reportsDir) {
            @unlink($reportFile);
        }
        header('Location: a.php?view=reports');
        exit;
    }

    // Dismiss (resolve) a reported quiz mistake
    if ($action === 'dismiss_mistake') {
        quizMistakeDismiss((string)($_POST['report_id'] ?? ''));
        header('Location: a.php?view=mistakes');
        exit;
    }

    // Save an edited question JSON file (from the reported-mistakes screen)
    if ($action === 'save_question_json') {
        $rel  = (string)($_POST['file_path'] ?? '');
        $full = quizResolveQuestionPath($rel);
        $text = str_replace("\r\n", "\n", (string)($_POST['json_content'] ?? ''));
        json_decode($text);
        if ($full === false) {
            $msg = 'Question file not found.'; $msgType = 'error';
        } elseif (trim($text) === '' || json_last_error() !== JSON_ERROR_NONE) {
            $msg = 'Not saved — invalid JSON (' . json_last_error_msg() . '). ' . $rel; $msgType = 'error';
        } elseif (file_put_contents($full, rtrim($text) . "\n", LOCK_EX) === false) {
            $msg = 'Could not write the file. Check server permissions.'; $msgType = 'error';
        } else {
            $msg = "Saved $rel. Rebuild the quiz index if you changed themes, characters, places or difficulty.";
            $msgType = 'success';
            if (!empty($_POST['also_dismiss'])) {
                quizMistakeDismiss((string)($_POST['report_id'] ?? ''));
            }
        }
        $_GET['view'] = 'mistakes';
        $_POST['_keep_edit'] = $rel;
    }

    // Delete
    if ($action === 'delete') {
        $itemPath = $_POST['item_path'] ?? '';
        $rootBase = adminBaseDir($currentRoot);
        $full     = resolveAdminPath($currentRoot, $itemPath);

        if (!$full || !file_exists($full)) {
            $msg = 'Item not found.'; $msgType = 'error';
        } elseif ($rootBase && $full === $rootBase) {
            $msg = 'Cannot delete the root ' . $currentRoot . ' directory.'; $msgType = 'error';
        } elseif ($rootBase && rmdirRecursive($full, $rootBase)) {
            $name = basename($full);
            $msg  = "\"$name\" deleted successfully."; $msgType = 'success';
            // If we just deleted our current folder, move up
            if ($currentPath !== '' && str_ends_with('/' . $currentPath . '/', '/' . basename($full) . '/')) {
                $currentPath = dirname($currentPath);
                if ($currentPath === '.') $currentPath = '';
            }
        } else {
            $msg = 'Delete failed. Check server permissions.'; $msgType = 'error';
        }
    }
}

// ── Show login page if not authenticated ─────────────────────────────────────
if (!isLoggedIn()) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f4f6f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.12);
            padding: 40px 36px;
            width: 100%;
            max-width: 380px;
        }
        .login-card h1 {
            font-size: 1.4rem;
            color: #1a5276;
            margin-bottom: 6px;
            text-align: center;
        }
        .login-card p.sub {
            font-size: .82rem;
            color: #7f8c8d;
            text-align: center;
            margin-bottom: 28px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #dce3ec;
            border-radius: 7px;
            font-size: .95rem;
            color: #2c3e50;
            outline: none;
            transition: border-color .2s;
        }
        .form-group input:focus { border-color: #2980b9; }
        .btn-login {
            width: 100%;
            padding: 11px;
            background: #1a5276;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            transition: background .18s;
        }
        .btn-login:hover { background: #154360; }
        .alert {
            padding: 10px 14px;
            border-radius: 7px;
            font-size: .85rem;
            margin-bottom: 18px;
        }
        .alert-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
<div class="login-card">
    <h1>✝️ Admin Login</h1>
    <p class="sub">Bible Quiz Control Panel</p>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="a.php" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus
                   autocomplete="username" maxlength="64">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password" maxlength="128">
        </div>
        <button type="submit" class="btn-login">Login</button>
    </form>
</div>
</body>
</html>
<?php
    exit;
}

// ── Copyright reports view ────────────────────────────────────────────────────
if (($_GET['view'] ?? '') === 'reports') {
    $csrf = csrfToken();

    $reportsDir = __DIR__ . '/reports';
    $reports = [];
    if (is_dir($reportsDir)) {
        foreach (glob($reportsDir . '/*.json') as $reportFile) {
            $data = json_decode((string)@file_get_contents($reportFile), true);
            if (is_array($data)) {
                $data['_file'] = basename($reportFile);
                $reports[] = $data;
            }
        }
    }
    usort($reports, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));

    // Determine whether the reported file is currently visible, already hidden
    // (renamed with the "HIDE - " prefix), or no longer exists at all.
    function reportedFileStatus(string $relPath): string {
        if ($relPath === '') return 'missing';
        $full = resolveDataPath($relPath);
        if ($full && is_file($full)) {
            return (stripos(basename($full), 'HIDE') === 0) ? 'hidden' : 'visible';
        }
        $dir       = dirname($relPath);
        $base      = basename($relPath);
        $hiddenRel = ($dir === '.' || $dir === '') ? ('HIDE - ' . $base) : ($dir . '/HIDE - ' . $base);
        $hiddenFull = resolveDataPath($hiddenRel);
        if ($hiddenFull && is_file($hiddenFull)) return 'hidden';
        return 'missing';
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copyright Reports — Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <style>
        .admin-header {
            background: linear-gradient(135deg, #154360 0%, #1a2535 100%);
            color: #fff; padding: 14px 20px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .admin-header h1 { font-size: 1.15rem; font-weight: 700; }
        .btn-logout {
            background: rgba(255,255,255,.15); color: #fff;
            border: 1px solid rgba(255,255,255,.3); padding: 7px 16px;
            border-radius: 6px; font-size: .83rem; font-weight: 600; cursor: pointer;
        }
        .btn-logout:hover { background: rgba(255,255,255,.25); }
        .action-bar { max-width: 960px; margin: 16px auto 0; padding: 0 16px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
            border-radius: 7px; font-size: .85rem; font-weight: 600; cursor: pointer;
            border: none; text-decoration: none; }
        .btn-secondary { background: #f0f4f8; color: #2c3e50; border: 1px solid #dce3ec; }
        .btn-secondary:hover { background: #e2ecf5; }
        .alert { max-width: 960px; margin: 14px auto 0; padding: 11px 16px; border-radius: 8px; font-size: .88rem; font-weight: 500; }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }
        .alert-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }
        .reports-wrap { max-width: 960px; margin: 20px auto; padding: 0 16px; }
        .report-card {
            background: #fff; border: 1px solid #dce3ec; border-radius: 10px;
            padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .report-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
        .report-file { font-weight: 700; color: #1a5276; font-size: .95rem; word-break: break-word; }
        .report-date { font-size: .75rem; color: #7f8c8d; }
        .report-owns { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 700; }
        .report-owns.yes { background: #eafaf1; color: #1e8449; }
        .report-owns.no  { background: #fdf2f2; color: #c0392b; }
        .report-status { display: inline-block; margin-top: 4px; margin-left: 6px; padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 700; }
        .report-status.hidden  { background: #eef2f5; color: #566573; }
        .report-status.missing { background: #fdf2f2; color: #c0392b; }
        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 16px; margin-top: 10px; font-size: .85rem; }
        .report-grid dt { font-weight: 600; color: #2c3e50; }
        .report-grid dd { color: #566573; word-break: break-word; }
        .report-details { margin-top: 10px; font-size: .85rem; background: #f9fbfd; border-radius: 7px; padding: 10px 12px; color: #2c3e50; white-space: pre-wrap; }
        .report-actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-danger-sm {
            background: #e74c3c; color: #fff; border: none; padding: 6px 14px;
            border-radius: 6px; font-size: .78rem; font-weight: 600; cursor: pointer;
        }
        .btn-danger-sm:hover { background: #c0392b; }
        .btn-hide-sm {
            background: #566573; color: #fff; border: none; padding: 6px 14px;
            border-radius: 6px; font-size: .78rem; font-weight: 600; cursor: pointer;
        }
        .btn-hide-sm:hover { background: #3e4a56; }
        .btn-hide-sm:disabled { background: #b7c0c8; cursor: not-allowed; }
        .btn-recover-sm {
            background: #1e8449; color: #fff; border: none; padding: 6px 14px;
            border-radius: 6px; font-size: .78rem; font-weight: 600; cursor: pointer;
        }
        .btn-recover-sm:hover { background: #196f3d; }
        .empty-state { text-align: center; padding: 48px 20px; color: #7f8c8d; font-size: .95rem; }
    </style>
</head>
<body>

<div class="admin-header">
    <h1>🚩 Copyright Reports <span style="opacity:.7;font-weight:400;">(<?= count($reports) ?>)</span></h1>
    <form method="POST" action="a.php">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn-logout">Logout</button>
    </form>
</div>

<div class="action-bar">
    <a class="btn btn-secondary" href="a.php">⬅ Back to Admin</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="reports-wrap">
    <?php if (empty($reports)): ?>
        <div class="empty-state">
            <span style="font-size:2.5rem;display:block;margin-bottom:10px;">📭</span>
            No copyright reports have been submitted yet.
        </div>
    <?php else: ?>
        <?php foreach ($reports as $r):
            $fileStatus = reportedFileStatus($r['file_path'] ?? '');
        ?>
            <div class="report-card">
                <div class="report-card-top">
                    <div>
                        <div class="report-file">📄 <?= htmlspecialchars($r['file_path'] ?? $r['file_name'] ?? 'Unknown file') ?></div>
                        <div class="report-date"><?= htmlspecialchars($r['timestamp'] ?? '') ?></div>
                        <span class="report-owns <?= ($r['owns'] ?? '') === 'yes' ? 'yes' : 'no' ?>">
                            <?= ($r['owns'] ?? '') === 'yes' ? '✅ Claims ownership' : '⚠️ Does not own' ?>
                        </span>
                        <?php if ($fileStatus === 'hidden'): ?>
                            <span class="report-status hidden">🙈 Already Hidden</span>
                        <?php elseif ($fileStatus === 'missing'): ?>
                            <span class="report-status missing">❌ File No Longer Exists</span>
                        <?php endif; ?>
                    </div>
                </div>
                <dl class="report-grid">
                    <div><dt>Name</dt><dd><?= htmlspecialchars($r['name'] ?? '') ?></dd></div>
                    <div><dt>Mobile/WhatsApp</dt><dd><?= htmlspecialchars($r['mobile'] ?? '') ?></dd></div>
                    <div><dt>Email</dt><dd><?= htmlspecialchars($r['email'] !== '' ? $r['email'] : '—') ?></dd></div>
                    <div><dt>IP</dt><dd><?= htmlspecialchars($r['ip'] ?? '') ?></dd></div>
                </dl>
                <?php if (!empty($r['details'])): ?>
                    <div class="report-details"><?= nl2br(htmlspecialchars($r['details'])) ?></div>
                <?php endif; ?>
                <div class="report-actions">
                    <?php if (!empty($r['file_path']) && $fileStatus === 'visible'): ?>
                        <form method="POST" action="a.php?view=reports"
                              onsubmit="return confirm('Hide this file from the public site? It will be renamed with a HIDE prefix.');">
                            <input type="hidden" name="action" value="hide_report_file">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="file_path" value="<?= htmlspecialchars($r['file_path']) ?>">
                            <button type="submit" class="btn-hide-sm">🙈 Hide the Reported File</button>
                        </form>
                    <?php elseif (!empty($r['file_path']) && $fileStatus === 'hidden'): ?>
                        <form method="POST" action="a.php?view=reports"
                              onsubmit="return confirm('Restore this file to the public site? This removes the HIDE prefix.');">
                            <input type="hidden" name="action" value="recover_report_file">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="file_path" value="<?= htmlspecialchars($r['file_path']) ?>">
                            <button type="submit" class="btn-recover-sm">♻️ Recover Back</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" action="a.php?view=reports"
                          onsubmit="return confirm('Delete this report?');">
                        <input type="hidden" name="action" value="delete_report">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="report_id" value="<?= htmlspecialchars($r['_file']) ?>">
                        <button type="submit" class="btn-danger-sm">🗑️ Delete Report</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
    <?php
    exit;
}

// ── Reported quiz mistakes view ──────────────────────────────────────────────
if (($_GET['view'] ?? '') === 'mistakes') {
    $csrf = csrfToken();
    $reports = array_reverse(quizMistakeReadAll());
    $openEdit = (string)($_POST['_keep_edit'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reported Mistakes — Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <style>
        .admin-header {
            background: linear-gradient(135deg, #154360 0%, #1a2535 100%);
            color: #fff; padding: 14px 20px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .admin-header h1 { font-size: 1.15rem; font-weight: 700; }
        .btn-logout {
            background: rgba(255,255,255,.15); color: #fff;
            border: 1px solid rgba(255,255,255,.3); padding: 7px 16px;
            border-radius: 6px; font-size: .83rem; font-weight: 600; cursor: pointer;
        }
        .action-bar { max-width: 960px; margin: 16px auto 0; padding: 0 16px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
            border-radius: 7px; font-size: .85rem; font-weight: 600; cursor: pointer;
            border: none; text-decoration: none; }
        .btn-secondary { background: #f0f4f8; color: #2c3e50; border: 1px solid #dce3ec; }
        .btn-secondary:hover { background: #e2ecf5; }
        .btn-primary { background: #2980b9; color: #fff; }
        .btn-primary:hover { background: #1f6fa5; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 6px 14px; font-size: .78rem; }
        .alert { max-width: 960px; margin: 14px auto 0; padding: 11px 16px; border-radius: 8px; font-size: .88rem; font-weight: 500; }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }
        .alert-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }
        .reports-wrap { max-width: 960px; margin: 20px auto; padding: 0 16px; }
        .report-card {
            background: #fff; border: 1px solid #dce3ec; border-radius: 10px;
            padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .report-file { font-weight: 700; color: #1a5276; font-size: .95rem; word-break: break-word; }
        .report-date { font-size: .75rem; color: #7f8c8d; }
        .badge-type { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 700; background: #fef5e7; color: #b9770e; }
        .badge-count { display: inline-block; margin-top: 4px; margin-left: 6px; padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 700; background: #eef2f5; color: #566573; }
        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px 16px; margin-top: 10px; font-size: .85rem; }
        .report-grid dt { font-weight: 600; color: #2c3e50; }
        .report-grid dd { color: #566573; word-break: break-word; }
        .q-preview { margin-top: 10px; font-size: .88rem; background: #f9fbfd; border-radius: 7px; padding: 10px 12px; color: #2c3e50; }
        .q-preview ol { margin: 6px 0 0 20px; }
        .q-preview li.correct { color: #1e8449; font-weight: 700; }
        .report-actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .edit-form { margin-top: 12px; display: none; }
        .edit-form.open { display: block; }
        .edit-form textarea {
            width: 100%; min-height: 320px; padding: 10px 12px; box-sizing: border-box;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .82rem;
            border: 1.5px solid #dce3ec; border-radius: 7px; color: #2c3e50; tab-size: 2;
        }
        .edit-form textarea:focus { border-color: #2980b9; outline: none; }
        .empty-state { text-align: center; padding: 48px 20px; color: #7f8c8d; font-size: .95rem; }
    </style>
</head>
<body>

<div class="admin-header">
    <h1>🛠️ Reported Mistakes <span style="opacity:.7;font-weight:400;">(<?= count($reports) ?>)</span></h1>
    <form method="POST" action="a.php">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn-logout">Logout</button>
    </form>
</div>

<div class="action-bar">
    <a class="btn btn-secondary" href="a.php">⬅ Back to Admin</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="reports-wrap">
    <?php if (empty($reports)): ?>
        <div class="empty-state">
            <span style="font-size:2.5rem;display:block;margin-bottom:10px;">🎉</span>
            No mistakes have been reported.
        </div>
    <?php else: ?>
        <?php foreach ($reports as $r):
            $relPath  = (string)($r['path'] ?? '');
            $full     = quizResolveQuestionPath($relPath);
            $raw      = $full !== false ? (string)file_get_contents($full) : '';
            $q        = $full !== false ? json_decode($raw, true) : null;
            $typeText = QUIZ_MISTAKE_TYPES[$r['type'] ?? ''] ?? (string)($r['type'] ?? '');
            $formId   = 'edit-' . preg_replace('/[^a-z0-9]/i', '', (string)$r['id']);
            $isOpen   = $openEdit !== '' && $openEdit === $relPath;
            $editText = $isOpen && isset($_POST['json_content']) ? (string)$_POST['json_content'] : $raw;
        ?>
            <div class="report-card">
                <div class="report-file">📄 <?= htmlspecialchars($relPath) ?></div>
                <div class="report-date">Reported <?= htmlspecialchars((string)($r['timestamp'] ?? '')) ?><?php if (($r['last_reported'] ?? '') !== ($r['timestamp'] ?? '')): ?> · last <?= htmlspecialchars((string)$r['last_reported']) ?><?php endif; ?></div>
                <span class="badge-type"><?= htmlspecialchars($typeText) ?></span>
                <?php if ((int)($r['count'] ?? 1) > 1): ?>
                    <span class="badge-count">×<?= (int)$r['count'] ?> reports</span>
                <?php endif; ?>
                <?php if ($full === false): ?>
                    <span class="badge-count" style="background:#fdf2f2;color:#c0392b;">❌ File no longer exists</span>
                <?php endif; ?>

                <dl class="report-grid">
                    <div><dt>Language</dt><dd><?= htmlspecialchars((string)($r['language'] ?? '')) ?></dd></div>
                    <div><dt>Book</dt><dd><?= htmlspecialchars(trim(($r['book_no'] ?? '') . ' ' . ($r['book'] ?? ''))) ?></dd></div>
                    <div><dt>Chapter</dt><dd><?= htmlspecialchars((string)($r['chapter'] ?? '')) ?></dd></div>
                    <div><dt>Question no.</dt><dd><?= htmlspecialchars((string)($r['question_no'] ?? '')) ?></dd></div>
                </dl>

                <?php if (is_array($q)): ?>
                    <div class="q-preview">
                        <strong><?= htmlspecialchars((string)($q['question'] ?? '')) ?></strong>
                        <?php if (!empty($q['options']) && is_array($q['options'])): ?>
                            <ol type="A">
                                <?php foreach ($q['options'] as $i => $opt): ?>
                                    <li<?= $i === (int)($q['correct_option'] ?? -1) ? ' class="correct"' : '' ?>><?= htmlspecialchars((string)$opt) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="report-actions">
                    <?php if ($full !== false): ?>
                        <button type="button" class="btn btn-primary btn-sm"
                                onclick="document.getElementById('<?= $formId ?>').classList.toggle('open')">✏️ Edit</button>
                    <?php endif; ?>
                    <form method="POST" action="a.php?view=mistakes"
                          onsubmit="return confirm('Dismiss this report without editing?');">
                        <input type="hidden" name="action" value="dismiss_mistake">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="report_id" value="<?= htmlspecialchars((string)$r['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Dismiss</button>
                    </form>
                </div>

                <?php if ($full !== false): ?>
                    <form method="POST" action="a.php?view=mistakes" id="<?= $formId ?>" class="edit-form<?= $isOpen ? ' open' : '' ?>">
                        <input type="hidden" name="action" value="save_question_json">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="file_path" value="<?= htmlspecialchars($relPath) ?>">
                        <input type="hidden" name="report_id" value="<?= htmlspecialchars((string)$r['id']) ?>">
                        <textarea name="json_content" spellcheck="false"><?= htmlspecialchars($editText) ?></textarea>
                        <div class="report-actions">
                            <button type="submit" class="btn btn-primary btn-sm">💾 Save</button>
                            <button type="submit" name="also_dismiss" value="1" class="btn btn-secondary btn-sm">💾 Save &amp; mark resolved</button>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    onclick="document.getElementById('<?= $formId ?>').classList.remove('open')">Cancel</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
    <?php
    exit;
}

// ── Directory listing ─────────────────────────────────────────────────────────
render_page:

$currentRoot = $_GET['root'] ?? (isset($currentRoot) ? $currentRoot : '');
$currentPath = $_GET['path'] ?? (isset($currentPath) ? $currentPath : '');
$currentPath = str_replace("\0", '', $currentPath);

// Legacy URLs: a.php?path=… without root= still opens data/
if ($currentRoot === '' && $currentPath !== '') {
    $currentRoot = 'data';
}

$atAdminHome = ($currentRoot === '' || normalizeAdminRoot($currentRoot) === false) && $currentPath === '';
$visitorCounts = quizReadVisitorCounts();
$mistakeCount = count(quizMistakeReadAll());

$dirs  = [];
$files = [];

if ($atAdminHome) {
    foreach (adminRoots() as $rootKey => $rootPath) {
        if ($rootPath === false || !is_dir($rootPath)) {
            continue;
        }
        $dirs[] = [
            'name'         => $rootKey,
            'path'         => '',
            'href'         => adminBrowseUrl($rootKey),
            'browse_only'  => true,
        ];
    }
    usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
} else {
    $currentRoot = normalizeAdminRoot($currentRoot) ?: 'data';
    $fullPath = resolveAdminPath($currentRoot, $currentPath);
    if ($fullPath === false || !is_dir($fullPath)) {
        $fullPath    = adminBaseDir($currentRoot) ?: BASE_DATA_DIR;
        $currentPath = '';
        if ($fullPath === false) {
            $atAdminHome = true;
            $currentRoot = '';
        }
    }

    if (!$atAdminHome) {
        $rawItems = @scandir($fullPath) ?: [];
        foreach ($rawItems as $item) {
            if ($item === '.' || $item === '..') continue;
            $itemFull = $fullPath . DIRECTORY_SEPARATOR . $item;
            $itemRel  = ($currentPath !== '' ? $currentPath . '/' : '') . $item;
            if (is_dir($itemFull)) {
                $dirs[] = [
                    'name' => $item,
                    'path' => $itemRel,
                    'href' => adminBrowseUrl($currentRoot, $itemRel),
                ];
            } elseif (is_file($itemFull)) {
                $files[] = ['name' => $item, 'path' => $itemRel, 'size' => filesize($itemFull)];
            }
        }
        usort($dirs,  fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    }
}

// Breadcrumb
$breadcrumb = [];
if (!$atAdminHome && $currentRoot !== '') {
    $breadcrumb[] = ['name' => $currentRoot . '/', 'path' => '', 'root' => $currentRoot];
}
if (!$atAdminHome && $currentPath !== '') {
    $parts   = explode('/', trim($currentPath, '/'));
    $cumPath = '';
    foreach ($parts as $part) {
        $cumPath .= ($cumPath === '' ? '' : '/') . $part;
        $breadcrumb[] = ['name' => $part, 'path' => $cumPath, 'root' => $currentRoot];
    }
}

// Parent URL
$parentUrl = '';
if (!$atAdminHome && $currentPath !== '') {
    $parentRel = dirname($currentPath);
    if ($parentRel === '.' || $parentRel === '') {
        $parentUrl = adminBrowseUrl($currentRoot);
    } else {
        $parentUrl = adminBrowseUrl($currentRoot, $parentRel);
    }
} elseif (!$atAdminHome && $currentRoot !== '') {
    $parentUrl = 'a.php';
}

$adminQs = $atAdminHome ? '' : adminLocationQuery($currentRoot, $currentPath);
$listTitle = $atAdminHome
    ? '📚 Admin roots'
    : ($currentPath !== '' ? '📂 ' . basename($currentPath) : '📂 ' . $currentRoot . '/ (root)');

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Bible Quiz</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <style>
        /* ── Admin-specific overrides ── */
        .admin-header {
            background: linear-gradient(135deg, #154360 0%, #1a2535 100%);
            color: #fff;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .admin-header h1 { font-size: 1.15rem; font-weight: 700; }
        .admin-header .badge {
            background: #e74c3c;
            color: #fff;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .6px;
            padding: 2px 7px;
            border-radius: 10px;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .btn-logout {
            background: rgba(255,255,255,.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,.3);
            padding: 7px 16px;
            border-radius: 6px;
            font-size: .83rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s;
        }
        .btn-logout:hover { background: rgba(255,255,255,.25); }

        /* ── Alerts ── */
        .alert {
            max-width: 960px;
            margin: 14px auto 0;
            padding: 11px 16px;
            border-radius: 8px;
            font-size: .88rem;
            font-weight: 500;
        }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }
        .alert-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }

        /* ── Visitor stats ── */
        .visitor-stats {
            max-width: 960px;
            margin: 16px auto 0;
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 10px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 20px;
        }
        .visitor-stats-total {
            display: flex;
            flex-direction: column;
            padding-right: 20px;
            border-right: 1px solid #dce3ec;
        }
        .visitor-stats-number {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a5276;
            line-height: 1.1;
        }
        .visitor-stats-label {
            font-size: .72rem;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .visitor-stats-langs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
        }
        .visitor-stats-lang {
            display: flex;
            align-items: baseline;
            gap: 6px;
            font-size: .88rem;
        }
        .visitor-stats-lang-name { font-weight: 600; color: #2c3e50; }
        .visitor-stats-lang-count { color: #2980b9; font-weight: 700; }
        @media (max-width: 600px) {
            .visitor-stats { flex-direction: column; align-items: flex-start; gap: 10px; }
            .visitor-stats-total {
                width: 100%;
                border-right: none;
                padding-right: 0;
                border-bottom: 1px solid #dce3ec;
                padding-bottom: 10px;
            }
        }

        /* ── Action bar ── */
        .action-bar {
            max-width: 960px;
            margin: 16px auto 0;
            padding: 0 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 7px;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: background .18s, transform .1s;
        }
        .btn:active { transform: scale(.97); }
        .btn-primary   { background: #2980b9; color: #fff; }
        .btn-primary:hover { background: #1f6fa5; }
        .btn-secondary { background: #f0f4f8; color: #2c3e50; border: 1px solid #dce3ec; }
        .btn-secondary:hover { background: #e2ecf5; }
        .btn-danger    { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 5px 11px; font-size: .78rem; }

        /* ── Inline panels (create folder / upload) ── */
        .panel {
            max-width: 960px;
            margin: 12px auto 0;
            padding: 0 16px;
            display: none;
        }
        .panel.open { display: block; }
        .panel-inner {
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 10px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
        }
        .panel-inner h3 {
            font-size: .95rem;
            margin-bottom: 12px;
            color: #1a5276;
        }
        .input-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .input-row input[type="text"],
        .input-row input[type="file"] {
            flex: 1;
            min-width: 200px;
            padding: 8px 12px;
            border: 1.5px solid #dce3ec;
            border-radius: 7px;
            font-size: .9rem;
            color: #2c3e50;
            outline: none;
            transition: border-color .2s;
        }
        .input-row input:focus { border-color: #2980b9; }
        .upload-note {
            font-size: .75rem;
            color: #7f8c8d;
            margin-top: 8px;
        }

        /* ── File list item actions ── */
        .file-item { position: relative; }
        .item-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
            margin-left: 8px;
        }

        /* ── Rename modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff;
            border-radius: 12px;
            padding: 28px 28px 22px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 8px 32px rgba(0,0,0,.2);
        }
        .modal h3 { font-size: 1rem; color: #1a5276; margin-bottom: 14px; }
        .modal input[type="text"] {
            width: 100%;
            padding: 9px 13px;
            border: 1.5px solid #dce3ec;
            border-radius: 7px;
            font-size: .95rem;
            margin-bottom: 16px;
            outline: none;
            color: #2c3e50;
        }
        .modal input:focus { border-color: #2980b9; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

        /* ── Delete confirm ── */
        .delete-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .delete-overlay.open { display: flex; }

        @media (max-width: 600px) {
            .action-bar { gap: 8px; }
            .item-actions .btn-sm { padding: 4px 8px; font-size: .72rem; }
            .admin-header h1 { font-size: 1rem; }
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="admin-header">
    <div>
        <h1>✝️ Bible Quiz <span class="badge">Admin</span></h1>
    </div>
    <form method="POST" action="a.php<?= htmlspecialchars($adminQs) ?>">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="current_root" value="<?= htmlspecialchars($atAdminHome ? '' : $currentRoot) ?>">
        <input type="hidden" name="current_path" value="<?= htmlspecialchars($currentPath) ?>">
        <button type="submit" class="btn-logout">Logout</button>
    </form>
</div>

<!-- Breadcrumb -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <div class="breadcrumb-inner">
        <a href="a.php">🏠 Admin</a>
        <?php foreach ($breadcrumb as $crumb): ?>
            <span class="sep">›</span>
            <?php if ($crumb === end($breadcrumb)): ?>
                <span class="current"><?= htmlspecialchars($crumb['name']) ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars(adminBrowseUrl($crumb['root'], $crumb['path'])) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>

<!-- Alert message -->
<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Visitor stats -->
<?php if ($atAdminHome): ?>
    <div class="visitor-stats">
        <div class="visitor-stats-total">
            <span class="visitor-stats-number"><?= number_format($visitorCounts['total']) ?></span>
            <span class="visitor-stats-label">Total visitors</span>
        </div>
        <?php if ($visitorCounts['languages']): ?>
            <?php $langCounts = $visitorCounts['languages']; arsort($langCounts); ?>
            <div class="visitor-stats-langs">
                <?php foreach ($langCounts as $lang => $count): ?>
                    <div class="visitor-stats-lang">
                        <span class="visitor-stats-lang-name"><?= htmlspecialchars(quizLanguageDisplayName($lang)) ?></span>
                        <span class="visitor-stats-lang-count"><?= number_format($count) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Action bar -->
<div class="action-bar">
    <button class="btn btn-primary" onclick="togglePanel('panel-folder')">📁 New Folder</button>
    <button class="btn btn-primary" onclick="togglePanel('panel-upload')">⬆️ Upload Files</button>
    <button class="btn btn-secondary" onclick="togglePanel('panel-upload-dir')">📂 Upload Folder</button>
    <?php if (!$atAdminHome && $currentPath !== ''): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($parentUrl) ?>">⬆️ Up one level</a>
    <?php elseif (!$atAdminHome && $currentRoot !== ''): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($parentUrl) ?>">⬆️ Admin home</a>
    <?php endif; ?>
    <a class="btn btn-secondary" href="index.php<?= (!$atAdminHome && $currentRoot === 'data' && $currentPath !== '') ? ('?path=' . rawurlencode($currentPath)) : '' ?>" target="_blank" rel="noopener noreferrer">👁️ View Public Site</a>
    <a class="btn btn-secondary" href="a.php?view=mistakes" title="Questions reported by players as wrong">🛠️ Reported Mistakes<?= $mistakeCount ? ' (' . $mistakeCount . ')' : '' ?></a>
    <a class="btn btn-secondary" href="i.php" title="Rebuild quiz indexes after adding or removing questions">⚙️ Rebuild Quiz Index</a>
    <a class="btn btn-secondary" href="sitemap.php" title="Regenerate sitemap.xml after adding or removing files">🗺️ Generate Sitemap</a>
</div>

<!-- Panel: Create Folder -->
<div id="panel-folder" class="panel">
    <div class="panel-inner">
        <h3>📁 Create New Folder</h3>
        <form method="POST" action="a.php<?= htmlspecialchars($adminQs) ?>">
            <input type="hidden" name="action"       value="create_folder">
            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="current_root" value="<?= htmlspecialchars($atAdminHome ? '' : $currentRoot) ?>">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($currentPath) ?>">
            <div class="input-row">
                <input type="text" name="folder_name" placeholder="Folder name" required maxlength="200" autofocus>
                <button type="submit" class="btn btn-primary">Create</button>
                <button type="button" class="btn btn-secondary" onclick="togglePanel('panel-folder')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Panel: Upload Files -->
<div id="panel-upload" class="panel">
    <div class="panel-inner">
        <h3>⬆️ Upload Files</h3>
        <form method="POST" enctype="multipart/form-data"
              action="a.php<?= htmlspecialchars($adminQs) ?>">
            <input type="hidden" name="action"       value="upload">
            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="current_root" value="<?= htmlspecialchars($atAdminHome ? '' : $currentRoot) ?>">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($currentPath) ?>">
            <div class="input-row">
                <input type="file" name="files[]" multiple required id="file-input">
                <button type="submit" class="btn btn-primary">Upload</button>
                <button type="button" class="btn btn-secondary" onclick="togglePanel('panel-upload')">Cancel</button>
            </div>
            <p class="upload-note">
                Max <?= round(MAX_UPLOAD_BYTES / 1048576) ?> MB per file.
                Allowed: <?= implode(', ', array_map(fn($e) => ".$e", $ALLOWED_EXT)) ?>
            </p>
        </form>
    </div>
</div>

<!-- Panel: Upload Folder (webkitdirectory) -->
<div id="panel-upload-dir" class="panel">
    <div class="panel-inner">
        <h3>📂 Upload Folder</h3>
        <form method="POST" enctype="multipart/form-data"
              action="a.php<?= htmlspecialchars($adminQs) ?>">
            <input type="hidden" name="action"       value="upload">
            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="current_root" value="<?= htmlspecialchars($atAdminHome ? '' : $currentRoot) ?>">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($currentPath) ?>">
            <div class="input-row">
                <input type="file" name="files[]" multiple required
                       webkitdirectory mozdirectory directory id="dir-input">
                <button type="submit" class="btn btn-primary">Upload Folder</button>
                <button type="button" class="btn btn-secondary" onclick="togglePanel('panel-upload-dir')">Cancel</button>
            </div>
            <p class="upload-note">Select a folder — its entire structure will be recreated here. Unsupported file types are skipped.</p>
        </form>
    </div>
</div>

<!-- File list -->
<main>
    <div class="file-list">
        <div class="file-list-header">
            <span>
                <?= htmlspecialchars($listTitle) ?>
            </span>
            <span><?= count($dirs) ?> folder<?= count($dirs) !== 1 ? 's' : '' ?>, <?= count($files) ?> file<?= count($files) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (!$atAdminHome && $currentPath !== ''): ?>
            <a class="file-item file-item-up" href="<?= htmlspecialchars($parentUrl) ?>">
                <span class="icon">⬆️</span>
                <span class="name">.. (Up one level)</span>
            </a>
        <?php elseif (!$atAdminHome && $currentRoot !== ''): ?>
            <a class="file-item file-item-up" href="<?= htmlspecialchars($parentUrl) ?>">
                <span class="icon">⬆️</span>
                <span class="name">.. (Admin home)</span>
            </a>
        <?php endif; ?>

        <?php if (empty($dirs) && empty($files)): ?>
            <div class="empty-state">
                <span>📭</span>
                <?= $atAdminHome ? 'No admin folders are available on this server.' : 'This folder is empty. Create a folder or upload files above.' ?>
            </div>
        <?php else: ?>

            <?php if (!empty($dirs)): ?>
                <div class="section-label">Folders</div>
                <?php foreach ($dirs as $dir): ?>
                    <div class="file-item" style="text-decoration:none;">
                        <span class="icon">📁</span>
                        <a class="name" href="<?= htmlspecialchars($dir['href']) ?>"
                           style="color:inherit;text-decoration:none;flex:1;">
                            <?= htmlspecialchars($dir['name']) ?><?= !empty($dir['browse_only']) ? '/' : '' ?>
                        </a>
                        <?php if (empty($dir['browse_only'])): ?>
                        <div class="item-actions">
                            <button class="btn btn-secondary btn-sm"
                                    onclick="openRename(<?= htmlspecialchars(json_encode($dir['path'])) ?>, <?= htmlspecialchars(json_encode($dir['name'])) ?>)">
                                ✏️ Rename
                            </button>
                            <button class="btn btn-danger btn-sm"
                                    onclick="openDelete(<?= htmlspecialchars(json_encode($dir['path'])) ?>, <?= htmlspecialchars(json_encode($dir['name'])) ?>, true)">
                                🗑️ Delete
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($files)): ?>
                <div class="section-label">Files</div>
                <?php foreach ($files as $file): ?>
                    <div class="file-item">
                        <span class="icon" style="font-size:1.1rem;">📄</span>
                        <span class="name" style="flex:1;">
                            <?= htmlspecialchars($file['name']) ?>
                        </span>
                        <span class="meta"><?= humanSize($file['size']) ?></span>
                        <div class="item-actions">
                            <button class="btn btn-secondary btn-sm"
                                    onclick="openRename(<?= htmlspecialchars(json_encode($file['path'])) ?>, <?= htmlspecialchars(json_encode($file['name'])) ?>)">
                                ✏️ Rename
                            </button>
                            <button class="btn btn-danger btn-sm"
                                    onclick="openDelete(<?= htmlspecialchars(json_encode($file['path'])) ?>, <?= htmlspecialchars(json_encode($file['name'])) ?>, false)">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</main>

<!-- Rename modal -->
<div id="rename-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rename-title">
    <div class="modal">
        <h3 id="rename-title">✏️ Rename</h3>
        <form method="POST" action="a.php<?= htmlspecialchars($adminQs) ?>"
              id="rename-form">
            <input type="hidden" name="action"       value="rename">
            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="current_root" value="<?= htmlspecialchars($atAdminHome ? '' : $currentRoot) ?>">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($currentPath) ?>">
            <input type="hidden" name="item_path"    id="rename-item-path" value="">
            <input type="text"   name="new_name"     id="rename-new-name"  value="" required maxlength="250"
                   placeholder="New name">
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rename-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Rename</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete confirmation modal -->
<div id="delete-modal" class="delete-overlay" role="dialog" aria-modal="true" aria-labelledby="delete-title">
    <div class="modal">
        <h3 id="delete-title">🗑️ Confirm Delete</h3>
        <p id="delete-message" style="font-size:.9rem;margin-bottom:18px;color:#2c3e50;"></p>
        <form method="POST" action="a.php<?= htmlspecialchars($adminQs) ?>"
              id="delete-form">
            <input type="hidden" name="action"       value="delete">
            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="current_root" value="<?= htmlspecialchars($atAdminHome ? '' : $currentRoot) ?>">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($currentPath) ?>">
            <input type="hidden" name="item_path"    id="delete-item-path" value="">
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('delete-modal')">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePanel(id) {
    const panel = document.getElementById(id);
    const isOpen = panel.classList.contains('open');
    // Close all panels first
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('open'));
    if (!isOpen) panel.classList.add('open');
}

function openRename(itemPath, currentName) {
    document.getElementById('rename-item-path').value = itemPath;
    document.getElementById('rename-new-name').value  = currentName;
    document.getElementById('rename-modal').classList.add('open');
    setTimeout(() => document.getElementById('rename-new-name').select(), 50);
}

function openDelete(itemPath, name, isDir) {
    document.getElementById('delete-item-path').value = itemPath;
    const msg = isDir
        ? `Delete folder "${name}" and ALL its contents? This cannot be undone.`
        : `Delete file "${name}"? This cannot be undone.`;
    document.getElementById('delete-message').textContent = msg;
    document.getElementById('delete-modal').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay, .delete-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open, .delete-overlay.open').forEach(el => {
            el.classList.remove('open');
        });
    }
});

// Show file count feedback for uploads
document.getElementById('file-input')?.addEventListener('change', function() {
    const n = this.files.length;
    if (n > 0) this.labels && (this.labels[0].textContent = `${n} file(s) selected`);
});
document.getElementById('dir-input')?.addEventListener('change', function() {
    const n = this.files.length;
    if (n > 0) {
        const msg = document.querySelector('#panel-upload-dir .upload-note');
        if (msg) msg.textContent = `${n} file(s) selected from folder. Click Upload Folder to proceed.`;
    }
});
</script>

</body>
</html>
