<?php
/**
 * ZKTeco / ADMS Push Protocol Gateway - getrequest.php
 * Handles terminal command queries (e.g. sync time, enroll user requests, restart).
 */
require_once __DIR__ . '/../config.php';

$sn = $_GET['SN'] ?? $_GET['sn'] ?? '';
header('Content-Type: text/plain');

// Return OK to acknowledge terminal heartbeat/poll
echo "OK";
