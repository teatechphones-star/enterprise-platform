<?php
// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'enterprise_platform');
define('DB_USER', 'teatech_app');
define('DB_PASS', 'TeaAppPass123!');
define('DB_PORT', 3306);

// Application Settings
define('APP_NAME', 'Enterprise Automation Platform');
define('APP_URL', 'http://192.168.100.98');
define('APP_ENV', 'development');
define('SESSION_TIMEOUT', 3600); // 1 hour

// Security
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_HASH_COST', 10);

// CORS & API
define('CORS_ENABLED', true);
define('API_RATE_LIMIT', 100); // requests per minute

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error Reporting (change to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', '/var/log/php_errors.log');
?>