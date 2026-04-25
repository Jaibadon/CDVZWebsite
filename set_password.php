<?php
/**
 * Admin password-reset utility.
 * Access this page once to set/reset staff passwords to bcrypt.
 * DELETE or restrict access to this file after use.
 *
 * Usage: open in browser, pick a staff member, enter new password, submit.
 */
require_once 'db_connect.php';

// Require login before allowing password resets
session_start();
if (empty($_SESSION['UserID'])) {
    die('Please <a href="login.php">log in</a> first.');
}
// Only erik or jen can reset passwords
if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Access denied — admin only.');
}

$pdo = get_db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eid  = (int)($_POST['employee_id'] ?? 0);
    $pass = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';

    if ($eid <= 0 || $pass === '') {
        $message = '<span style="color:red">Select a staff member and enter a password.</span>';
    } elseif ($pass !== $conf) {
        $message = '<span style="color:red">Passwords do not match.</span>';
    } elseif (strlen($pass) < 8) {
        $message = '<span style="color:red">Password must be at least 8 characters.</span>';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE Staff SET `password` = ? WHERE Employee_ID = ?");
        $stmt->execute([$hash, $eid]);
        $message = '<span style="color:green">Password updated successfully.</span>';
    }
}

$staff = $pdo->query("SELECT Employee_ID, Login, `First Name` FROM Staff ORDER BY Login")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Set Staff Password</title>
<style>
body { font-family:Arial,sans-serif; padding:30px; background:#eee; }
form { background:#fff; padding:20px; border-radius:4px; max-width:400px; }
label { display:block; margin-top:12px; }
select,input[type=password] { width:100%; padding:6px; margin-top:4px; box-sizing:border-box; }
input[type=submit] { margin-top:16px; padding:8px 20px; background:#9B9B1B; color:#fff; border:none; cursor:pointer; border-radius:3px; }
</style>
</head>
<body>
<h2>Staff Password Reset</h2>
<p><strong>Delete this file after use.</strong></p>
<?php if ($message): ?><p><?= $message ?></p><?php endif; ?>
<form method="post">
  <label>Staff member
    <select name="employee_id">
      <option value="">-- select --</option>
      <?php foreach ($staff as $s): ?>
        <option value="<?= $s['Employee_ID'] ?>"
          <?= (isset($_POST['employee_id']) && $_POST['employee_id'] == $s['Employee_ID']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($s['Login']) ?> (<?= htmlspecialchars($s['First Name'] ?? '') ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>New password
    <input type="password" name="new_password" minlength="8" required>
  </label>
  <label>Confirm password
    <input type="password" name="confirm_password" minlength="8" required>
  </label>
  <input type="submit" value="Set Password">
</form>
</body>
</html>
