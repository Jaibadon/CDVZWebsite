<?php
// Shared session-check — include at the top of every protected page.
// Usage:  require_once 'auth_check.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['UserID'])) {
    echo '<p>Your session has expired. Please <a href="login.php">login</a> again.</p>';
    exit;
}
