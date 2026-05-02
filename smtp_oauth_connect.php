<?php
require_once 'auth_check.php';
require_once 'smtp_oauth.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
if (!SmtpOAuth::isConfigured()) {
    die('GOOGLE_OAUTH_CLIENT_ID / SECRET not set in config.php — see config.smtp.sample.php for setup steps.');
}

$state = bin2hex(random_bytes(16));
$_SESSION['smtp_oauth_state'] = $state;

header('Location: ' . SmtpOAuth::buildAuthorizeUrl($state));
exit;
