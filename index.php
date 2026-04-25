<?php
session_start();

// Build a clean absolute URL using the current scheme + host
// (defends against hosts that resolve relative Location: headers oddly)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');  // e.g. "" or "/subdir"
$target = !empty($_SESSION['UserID']) ? 'menu.php' : 'login.php';

header('Location: ' . $scheme . '://' . $host . $base . '/' . $target);
exit;
