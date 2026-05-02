<?php
/**
 * Kicks off the Xero OAuth 2.0 flow. Admin only — Erik clicks once,
 * gets bounced to Xero for consent, comes back via xero_callback.php.
 */
require_once 'auth_check.php';
require_once 'xero_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
if (!XeroClient::isConfigured()) {
    die('XERO_CLIENT_ID / XERO_CLIENT_SECRET are not set in config.php. See config.xero.sample.php for setup instructions.');
}

// CSRF state
$state = bin2hex(random_bytes(16));
$_SESSION['xero_oauth_state'] = $state;

header('Location: ' . XeroClient::buildAuthorizeUrl($state));
exit;
