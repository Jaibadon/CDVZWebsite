<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'xero_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    XeroClient::disconnect(get_db());
    header('Location: menu.php');
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"><title>Disconnect Xero</title></head>
<body><div class="page"><div class="card">
  <h2>Disconnect Xero?</h2>
  <p>This deletes the stored OAuth tokens. Existing invoices in Xero are unaffected, but the app will stop syncing payment status until you reconnect.</p>
  <form method="post"><button type="submit" class="btn-danger">Yes, disconnect</button>
    &nbsp; <a href="menu.php">Cancel</a></form>
</div></div></body></html>
