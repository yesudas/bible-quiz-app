<?php
/**
 * Optional: copy this file to share-secret.php (gitignored) to pin the HMAC
 * secret used to sign "Share Quiz" links. If share-secret.php is missing,
 * one is generated automatically on first use, so this step isn't required.
 *
 *   cp share-secret.example.php share-secret.php
 *
 * Generate a random secret:
 *   php -r "echo bin2hex(random_bytes(32));"
 */

define('QUIZ_SHARE_SECRET', 'PASTE_RANDOM_SECRET_HERE');
