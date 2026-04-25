<?php
session_start();
// If already logged in, go straight to the menu
if (!empty($_SESSION['UserID'])) {
    header('Location: menu.php');
    exit;
}
header('Location: login.php');
exit;
