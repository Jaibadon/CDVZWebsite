<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once __DIR__ . '/dms/drive_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

try {
    DriveClient::disconnect(get_db());
    $_SESSION['drive_flash'] = 'Disconnected Google Drive.';
} catch (Exception $e) {
    $_SESSION['drive_flash_err'] = $e->getMessage();
}
header('Location: menu.php');
exit;
