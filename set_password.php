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

// ── Detect & widen the password column if it's too small for bcrypt (60 chars) ─
$colInfo = $pdo->query("SHOW COLUMNS FROM Staff LIKE 'password'")->fetch(PDO::FETCH_ASSOC);
$colType = strtolower($colInfo['Type'] ?? '');
$tooSmall = false;
if (preg_match('/varchar\((\d+)\)/', $colType, $m) && (int)$m[1] < 60) {
    $tooSmall = true;
}

if ($tooSmall && isset($_GET['fix_column'])) {
    $pdo->exec("ALTER TABLE Staff MODIFY COLUMN `password` VARCHAR(255) NOT NULL DEFAULT ''");
    $message = '<span style="color:green">Column widened to VARCHAR(255). Reload to confirm.</span>';
    $tooSmall = false;
    $colInfo = $pdo->query("SHOW COLUMNS FROM Staff LIKE 'password'")->fetch(PDO::FETCH_ASSOC);
    $colType = strtolower($colInfo['Type'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eid  = (int)($_POST['employee_id'] ?? 0);
    $pass = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';

    if ($eid <= 0 || $pass === '') {
        $message = '<span style="color:red">Select a staff member and enter a password.</span>';
    } elseif ($pass !== $conf) {
        $message = '<span style="color:red">Passwords do not match.</span>';
    } elseif (strlen($pass) < 4) {
        $message = '<span style="color:red">Password must be at least 4 characters.</span>';
    } elseif ($tooSmall) {
        $message = '<span style="color:red">Cannot save: <code>Staff.password</code> column is ' . htmlspecialchars($colType) . ' but bcrypt needs VARCHAR(60+). <a href="?fix_column=1">Click here to widen it to VARCHAR(255)</a>.</span>';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE Staff SET `password` = ? WHERE Employee_ID = ?");
        $stmt->execute([$hash, $eid]);

        // Verify what actually got stored — if the column truncated, we'll see < 60 chars
        $check = $pdo->prepare("SELECT `password` FROM Staff WHERE Employee_ID = ?");
        $check->execute([$eid]);
        $stored = (string)$check->fetchColumn();

        if (strlen($stored) === strlen($hash) && password_verify($pass, $stored)) {
            $message = '<span style="color:green">Password updated successfully (' . strlen($stored) . ' chars stored, verify=OK).</span>';
        } else {
            $message = '<span style="color:red">Password was written but stored hash is ' . strlen($stored) . ' chars (expected ' . strlen($hash) . '). Column is likely truncating — <a href="?fix_column=1">widen the column</a> and try again.</span>';
        }
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
<p style="font-size:12px;color:#555">
  Staff.password column type: <code><?= htmlspecialchars($colType) ?></code>
  <?php if ($tooSmall): ?>
    &nbsp;<span style="color:red">⚠ Too narrow for bcrypt (60 chars).</span>
    &nbsp;<a href="?fix_column=1">Widen to VARCHAR(255) now</a>
  <?php else: ?>
    &nbsp;<span style="color:green">✓ OK for bcrypt</span>
  <?php endif; ?>
</p>
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
    <input type="password" name="new_password" minlength="4" required>
  </label>
  <label>Confirm password
    <input type="password" name="confirm_password" minlength="4" required>
  </label>
  <input type="submit" value="Set Password">
</form>
</body>
</html>
