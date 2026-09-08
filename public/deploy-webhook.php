<?php
// GitHub Auto-Deploy Webhook
// Called by GitHub when a push happens to the repository

$repo_path = '/var/www/enterprise/public';
$secret = 'enterprise-deploy-secret-2026';
$log_file = '/var/www/enterprise/.deploy/deploy.log';

// Verify signature
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$computed = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($computed, $signature)) {
    http_response_code(403);
    error_log('Deploy: Invalid signature', 3, $log_file);
    exit('Invalid signature');
}

// Log the event
$data = json_decode($payload, true);
$branch = $data['ref'] ?? '';
error_log("Deploy triggered: " . $branch . " at " . date('Y-m-d H:i:s'), 3, $log_file);

// Pull latest code
$cmd = "cd $repo_path && git pull origin main 2>&1";
$output = shell_exec($cmd);
error_log($output, 3, $log_file);

// Optional: run migrations or cache clear here

echo "Deploy complete at " . date('Y-m-d H:i:s');
