<?php
/**
 * Copy this file to admin-credentials.php (gitignored) and set your admin password.
 *
 *   cp admin-credentials.example.php admin-credentials.php
 *
 * Generate a bcrypt hash:
 *   php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost'=>12]);"
 */

define('ADMIN_USER', 'admin');
define('ADMIN_HASH', 'PASTE_BCRYPT_HASH_HERE');
