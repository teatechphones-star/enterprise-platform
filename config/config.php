<?php
// ============================================================
// Enterprise Automation Platform - Core Configuration
// ============================================================
define('APP_NAME', 'Enterprise Automation Platform');
define('BASE_URL', 'http://192.168.100.98/');

// Database credentials (dedicated app user)
define('DB_HOST', 'localhost');
define('DB_NAME', 'enterprise_platform');
define('DB_USER', 'enterprise_app');
define('DB_PASS', 'EnterpriseApp@2026');

// Session / security
define('SESSION_NAME', 'enterprise_session');

// Absolute filesystem paths
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH',  ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Error reporting (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('UTC');

session_name(SESSION_NAME);
session_start();

spl_autoload_register(function ($class) {
    $file = APP_PATH . '/core/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
