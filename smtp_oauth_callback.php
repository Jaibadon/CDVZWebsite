<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'smtp_oauth.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$err   = $_GET['error'] ?? '';
$expectedState = $_SESSION['smtp_oauth_state'] ?? '';
unset($_SESSION['smtp_oauth_state']);

if ($err)   die('Google returned error: ' . htmlspecialchars($err));
if (!$code) die('Missing authorization code.');
if (!$state || !hash_equals($expectedState, $state)) die('OAuth state mismatch — please retry.');

try {
    $info = SmtpOAuth::exchangeCodeAndPersist(get_db(), $code, $_SESSION['UserID']);
    $_SESSION['smtp_flash'] = 'Connected Google SMTP as ' . $info['email'] . '.';
} catch (Exception $e) {
    die('<h2>Google SMTP connect failed</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre><p><a href="menu.php">Back</a></p>');
}

header('Location: menu.php');
exit;
