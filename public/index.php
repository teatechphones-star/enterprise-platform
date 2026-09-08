<?php
require_once 'config.php';
require_once 'Auth.php';

echo "<h1>Enterprise Automation Platform</h1>";
echo "<p>Authentication API is running.</p>";
echo "<p>Database: " . DB_NAME . " @ " . DB_HOST . "</p>";
echo "<p>Try <code>POST /auth_api.php?action=login</code> with JSON body</p>";
?>