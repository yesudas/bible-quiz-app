<?php
/**
 * Optional: copy this file to leaderboard-secret.php (gitignored) to pin the
 * keys used to encrypt leaderboard mobile numbers (AES-256-GCM) and to
 * compute the deterministic match hash used to merge repeat entries. If
 * leaderboard-secret.php is missing, both keys are generated automatically
 * on first use, so this step isn't required.
 *
 *   cp leaderboard-secret.example.php leaderboard-secret.php
 *
 * Generate random keys:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL, bin2hex(random_bytes(32));"
 */

define('QUIZ_LEADERBOARD_ENC_KEY', 'PASTE_RANDOM_KEY_HERE');
define('QUIZ_LEADERBOARD_HMAC_KEY', 'PASTE_ANOTHER_RANDOM_KEY_HERE');
