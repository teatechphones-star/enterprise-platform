<?php
// ============================================================
// Server-side setup script: hashes the admin password.
// Run once ON THE SERVER:  php /var/www/enterprise/setup_admin.php
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once APP_PATH . '/core/Database.php';

$db = Database::getInstance();
$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin123';

$hash = password_hash($password, PASSWORD_BCRYPT);
$db->query(
    "UPDATE users SET password_hash = :h WHERE username = :u",
    ['h' => $hash, 'u' => $username]
);
echo "Admin password updated for user: $username\n";
