<?php
session_start();

// Already logged in → go to menu
if (!empty($_SESSION['UserID'])) {
    header('Location: menu.php');
    exit;
}

require_once 'db_connect.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Simple rate-limiting (5 attempts per 15 min per session)
    $now = time();
    if (empty($_SESSION['login_attempts'])) { $_SESSION['login_attempts'] = 0; }
    if (empty($_SESSION['login_time']))     { $_SESSION['login_time']     = $now; }
    if ($now - $_SESSION['login_time'] > 900) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_time']     = $now;
    }

    if ($_SESSION['login_attempts'] >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $_SESSION['login_attempts']++;

        $pdo  = get_db();
        $stmt = $pdo->prepare(
            "SELECT Employee_ID, Login, `password`, `First Name`, Level
               FROM Staff
              WHERE Login = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        $valid = false;
        if ($row) {
            $stored = trim($row['password'] ?? '');

            // 1. bcrypt (set by set_password.php or auto-upgrade below)
            if (strlen($stored) >= 60 && substr($stored, 0, 2) === '$2') {
                $valid = password_verify($password, $stored);
            }
            // 2. MD5 fallback (32-char hex)
            elseif (strlen($stored) === 32 && ctype_xdigit($stored)) {
                $valid = (strtolower($stored) === md5($password));
                if ($valid) {
                    $pdo->prepare("UPDATE Staff SET `password` = ? WHERE Employee_ID = ?")
                        ->execute([password_hash($password, PASSWORD_BCRYPT), $row['Employee_ID']]);
                }
            }
            // 3. Plain-text fallback
            elseif ($stored !== '' && $stored === $password) {
                $valid = true;
                $pdo->prepare("UPDATE Staff SET `password` = ? WHERE Employee_ID = ?")
                    ->execute([password_hash($password, PASSWORD_BCRYPT), $row['Employee_ID']]);
            }
            // 4. DES-crypt fallback (13-char strings from old ASP cipherer)
            elseif (strlen($stored) === 13 && function_exists('crypt')) {
                $valid = (crypt($password, $stored) === $stored);
                if ($valid) {
                    $pdo->prepare("UPDATE Staff SET `password` = ? WHERE Employee_ID = ?")
                        ->execute([password_hash($password, PASSWORD_BCRYPT), $row['Employee_ID']]);
                }
            }
        }

        if ($valid) {
            // Rotate session ID to prevent fixation
            session_regenerate_id(true);
            $_SESSION['UserID']      = $row['Login'];
            $_SESSION['Employee_id'] = $row['Employee_ID'];
            $_SESSION['DisplayName'] = $row['First Name'] ?? $row['Login'];
            $_SESSION['AccessLevel'] = $row['Level'] ?? '';
            $_SESSION['login_attempts'] = 0;

            // Default week = current Monday
            if (empty($_SESSION['Week'])) {
                $_SESSION['Week'] = date('Y-m-d', strtotime('monday this week'));
            }

            header('Location: menu.php');
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CADViz – Login</title>
<link href="global.css" rel="stylesheet" type="text/css">
<style>
body { background:#515559; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:Arial,sans-serif; }
.login-box { background:#EBEBEB; padding:30px 40px; border-radius:4px; width:320px; }
.login-box h2 { text-align:center; color:#9B9B1B; margin-bottom:20px; }
.login-box label { display:block; margin-top:12px; font-size:13px; color:#333; }
.login-box input[type=text],
.login-box input[type=password] { width:100%; padding:7px; box-sizing:border-box; margin-top:4px; border:1px solid #bbb; border-radius:3px; }
.login-box input[type=submit] { width:100%; margin-top:20px; padding:8px; background:#9B9B1B; color:#fff; border:none; border-radius:3px; cursor:pointer; font-size:14px; }
.login-box input[type=submit]:hover { background:#7a7a16; }
.error { color:red; font-size:13px; margin-top:10px; text-align:center; }
.logo { text-align:center; margin-bottom:10px; }
</style>
</head>
<body>
<div class="login-box">
  <div class="logo"><img src="cadviz_logo.gif" alt="CADViz" style="max-width:180px"></div>
  <h2>Sign In</h2>
  <?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>
  <form method="post" action="login.php" autocomplete="off">
    <label for="username">Username</label>
    <input type="text" id="username" name="username"
           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
           autofocus autocomplete="username">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password">
    <input type="submit" value="Login">
  </form>
</div>
</body>
</html>
