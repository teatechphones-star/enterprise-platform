<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/Auth.php';
Auth::logout();
redirect('login.php');
