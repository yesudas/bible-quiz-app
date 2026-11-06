<?php

/**
 * Thin bridge to the shared wordofgod.in/auth service (local sibling repo:
 * github-projects/auth, deployed at wordofgod.in/auth — a sibling folder of
 * this app on the server too, hence the relative ../../ path). See
 * github-projects/auth/README.md for the Google Cloud Console setup and
 * how other wordofgod.in sub-apps use the same service.
 *
 * Every function here is a no-op-safe passthrough: if the shared auth/
 * folder or its config.php hasn't been deployed yet, quizGoogleUser()
 * returns null instead of fataling, so Google sign-in is simply absent
 * (the existing name+mobile leaderboard join flow still works) rather than
 * breaking the page.
 */

/**
 * Absolute URL of the current request (scheme + host + path + query).
 * authLoginUrl()/authLogoutUrl()'s ?return= must be absolute, not a bare
 * path, because the auth service and this app can be on different origins
 * (e.g. local dev with two separate dev servers — bible-quiz-app on
 * :9090, auth on :8080); a bare path would resolve against the AUTH
 * service's own origin once callback.php redirects back, not this app's.
 */
function quizCurrentUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme . '://' . $host . $uri;
}

function quizGoogleUser(): ?array
{
    $sessionFile = __DIR__ . '/../../auth/session.php';
    if (!is_file($sessionFile)) {
        return null;
    }
    require_once $sessionFile;

    try {
        return authUser();
    } catch (Throwable $e) {
        return null;
    }
}

function quizGoogleLoginUrl(string $returnTo): ?string
{
    $sessionFile = __DIR__ . '/../../auth/session.php';
    if (!is_file($sessionFile)) {
        return null;
    }
    require_once $sessionFile;
    return authLoginUrl($returnTo);
}

function quizGoogleLogoutUrl(string $returnTo): ?string
{
    $sessionFile = __DIR__ . '/../../auth/session.php';
    if (!is_file($sessionFile)) {
        return null;
    }
    require_once $sessionFile;
    return authLogoutUrl($returnTo);
}
