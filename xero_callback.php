<?php
/**
 * OAuth 2.0 callback. Xero redirects here with ?code=... &state=... after the
 * user grants consent. Exchange the code for tokens and persist.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'xero_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$err  = $_GET['error'] ?? '';
$code = $_GET['code']  ?? '';
$state= $_GET['state'] ?? '';

if ($err) die('Xero returned error: ' . htmlspecialchars($err));
if (!$code) die('Missing authorization code.');
if (!hash_equals($_SESSION['xero_oauth_state'] ?? '', $state)) {
    die('OAuth state mismatch — possible CSRF, please try again from the menu.');
}
unset($_SESSION['xero_oauth_state']);

try {
    $info = XeroClient::exchangeCodeAndPersist(get_db(), $code, $_SESSION['UserID']);
} catch (Exception $e) {
    die('<h2>Xero connection failed</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre><p><a href="menu.php">Back</a></p>');
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"><title>Connected to Xero</title></head>
<body><div class="page"><div class="card" style="background:#d6f5d6;border:2px solid #1a6b1a;color:#1a6b1a">
  <h2 style="margin-top:0">✓ Connected to Xero</h2>
  <p>Organisation: <strong><?= htmlspecialchars($info['tenant_name']) ?></strong></p>
  <p>Tokens stored. Refresh tokens are valid for 60 days from last use; the system refreshes the access token automatically every 30 minutes.</p>
  <p><a href="menu.php" class="btn-primary">Back to Main Menu</a></p>
</div></div></body></html>
