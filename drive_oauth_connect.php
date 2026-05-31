<?php
require_once 'auth_check.php';
require_once __DIR__ . '/dms/drive_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
if (!DriveClient::isConfigured()) {
    die('GOOGLE_OAUTH_CLIENT_ID / SECRET / DRIVE_REDIRECT_URI not set in config.php — see config.cadviz.sample.php.');
}

$state = bin2hex(random_bytes(16));
$_SESSION['drive_oauth_state'] = $state;

header('Location: ' . DriveClient::buildAuthorizeUrl($state));
exit;
