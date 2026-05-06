<?php
/**
 * Staff admin — single page for everything about a staff record except
 * the password (set_password.php handles that, since it has the
 * bcrypt-aware logic and rate-limiting).
 *
 * Edit anything: First/Last Name, email, Mobile, Login, Pay Rate,
 * Billing Rate, Level, Active.
 *
 * Note: column names with spaces (`First Name`, `Last Name`,
 * `Billing Rate`) are MySQL identifiers from the legacy MSSQL port —
 * they're quoted in every query.
 *
 * Efficiency_Factor is in the schema but unused anywhere in the codebase
 * (we grepped) — not exposed in this UI.
 */

session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); echo '<p>Admin only.</p>'; exit;
}

$pdo = get_db();

// ── POST handlers ───────────────────────────────────────────────────────
$flash = ''; $flashErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            $eid = (int)($_POST['Employee_ID'] ?? 0);
            if ($eid <= 0) throw new RuntimeException('Missing Employee_ID.');
            $login = trim((string)($_POST['Login'] ?? ''));
            if ($login === '') throw new RuntimeException('Login is required.');
            $pdo->prepare(
                "UPDATE Staff
                    SET Login = ?,
                        `First Name` = ?,
                        `Last Name`  = ?,
                        email        = ?,
                        Mobile       = ?,
                        Pay_Rate     = ?,
                        `Billing Rate` = ?,
                        Level        = ?,
                        Active       = ?
                  WHERE Employee_ID = ?"
            )->execute([
                $login,
                trim((string)($_POST['First Name'] ?? '')),
                trim((string)($_POST['Last Name']  ?? '')),
                trim((string)($_POST['email']      ?? '')),
                trim((string)($_POST['Mobile']     ?? '')),
                $_POST['Pay_Rate']     === '' ? null : (float)$_POST['Pay_Rate'],
                $_POST['Billing Rate'] === '' ? null : (float)$_POST['Billing Rate'],
                $_POST['Level']        === '' ? null : (int)$_POST['Level'],
                isset($_POST['Active']) ? 1 : 0,
                $eid,
            ]);
            $flash = 'Staff #' . $eid . ' updated.';
        } elseif ($action === 'add') {
            $login = trim((string)($_POST['Login'] ?? ''));
            if ($login === '') throw new RuntimeException('Login is required for new staff.');
            // Pick next Employee_ID — schema lacks AUTO_INCREMENT (legacy MSSQL port).
            $eid = (int)$pdo->query("SELECT COALESCE(MAX(Employee_ID), 0) + 1 FROM Staff")->fetchColumn();
            $pdo->prepare(
                "INSERT INTO Staff
                    (Employee_ID, Login, `First Name`, `Last Name`, email, Mobile,
                     Pay_Rate, `Billing Rate`, Level, Active, password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')"
            )->execute([
                $eid,
                $login,
                trim((string)($_POST['First Name'] ?? '')),
                trim((string)($_POST['Last Name']  ?? '')),
                trim((string)($_POST['email']      ?? '')),
                trim((string)($_POST['Mobile']     ?? '')),
                $_POST['Pay_Rate']     === '' ? null : (float)$_POST['Pay_Rate'],
                $_POST['Billing Rate'] === '' ? null : (float)$_POST['Billing Rate'],
                $_POST['Level']        === '' ? null : (int)$_POST['Level'],
                isset($_POST['Active']) ? 1 : 0,
            ]);
            $flash = 'Staff #' . $eid . ' (' . htmlspecialchars($login) . ') created. Set their password via <a href="set_password.php">set_password.php</a> before they can log in.';
        }
    } catch (Exception $e) { $flashErr = $e->getMessage(); }

    $_SESSION['flash'] = $flash; $_SESSION['flash_err'] = $flashErr;
    header('Location: staff_admin.php');
    exit;
}
$flash    = $_SESSION['flash']     ?? '';
$flashErr = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);

// ── Read ────────────────────────────────────────────────────────────────
$rows = $pdo->query(
    "SELECT Employee_ID, Login,
            `First Name` AS FirstName, `Last Name` AS LastName,
            email, Mobile,
            Pay_Rate, `Billing Rate` AS BillingRate, Level,
            COALESCE(Active, 0) AS Active
       FROM Staff
      ORDER BY COALESCE(Active, 0) DESC, Login"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Admin</title>
<link href="site.css" rel="stylesheet">
<style>
.page { max-width: 1200px; margin: 20px auto; }
.card { background:#fff; padding:14px 18px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:12px; }
table.st { width:100%; border-collapse:collapse; font-size:11px; }
table.st th { background:#f4f4d8; text-align:left; padding:5px 6px; font-size:10px; }
table.st td { padding:3px 5px; border-bottom:1px solid #eee; vertical-align:middle; }
table.st input[type=text], table.st input[type=number], table.st input[type=email], table.st select { padding:3px 5px; font-size:11px; box-sizing:border-box; }
table.st input.w-narrow { width:75px; }
table.st input.w-mid    { width:120px; }
table.st input.w-wide   { width:170px; }
table.st tr.inactive td { color:#999; background:#fafafa; }
.btn-sm { background:#9B9B1B; color:#fff; border:none; padding:3px 9px; border-radius:3px; cursor:pointer; font-size:11px; }
.flash    { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.flash-err{ background:#ffd6d6; border:1px solid #c33;   color:#a00;    padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.muted { color:#888; font-size:11px; }
form.row { margin: 0; }
</style></head><body>
<div class="page">
  <div class="card">
    <h1 style="margin:0">Staff</h1>
    <p class="muted">All editable staff details. <strong>Pay Rate</strong> is what we pay them; <strong>Billing Rate</strong> is what we charge clients for their time. Passwords are managed separately on <a href="set_password.php">set_password.php</a>.</p>
  </div>

  <?php if ($flash):    ?><div class="flash"    ><?= $flash /* may contain a link */ ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <div class="card">
    <table class="st">
      <tr>
        <th>ID</th><th>Login</th><th>First name</th><th>Last name</th>
        <th>Email</th><th>Mobile</th>
        <th>Pay&nbsp;rate</th><th>Billing&nbsp;rate</th>
        <th>Level</th><th>Active</th><th></th>
      </tr>
      <?php foreach ($rows as $r):
          $eid = (int)$r['Employee_ID']; $active = (int)$r['Active'];
      ?>
        <tr class="<?= $active ? '' : 'inactive' ?>">
          <form method="post" class="row">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="Employee_ID" value="<?= $eid ?>">
            <td><strong>#<?= $eid ?></strong></td>
            <td><input type="text"  name="Login"        class="w-mid"    value="<?= htmlspecialchars($r['Login']     ?? '') ?>" required></td>
            <td><input type="text"  name="First Name"   class="w-mid"    value="<?= htmlspecialchars($r['FirstName'] ?? '') ?>"></td>
            <td><input type="text"  name="Last Name"    class="w-mid"    value="<?= htmlspecialchars($r['LastName']  ?? '') ?>"></td>
            <td><input type="email" name="email"        class="w-wide"   value="<?= htmlspecialchars($r['email']     ?? '') ?>"></td>
            <td><input type="text"  name="Mobile"       class="w-mid"    value="<?= htmlspecialchars($r['Mobile']    ?? '') ?>"></td>
            <td><input type="number" step="0.01" name="Pay_Rate"     class="w-narrow" value="<?= $r['Pay_Rate']    !== null ? htmlspecialchars((string)$r['Pay_Rate'])    : '' ?>"></td>
            <td><input type="number" step="0.01" name="Billing Rate" class="w-narrow" value="<?= $r['BillingRate'] !== null ? htmlspecialchars((string)$r['BillingRate']) : '' ?>"></td>
            <td><input type="number" name="Level" class="w-narrow" value="<?= $r['Level'] !== null ? htmlspecialchars((string)$r['Level']) : '' ?>"></td>
            <td><input type="checkbox" name="Active" value="1" <?= $active ? 'checked' : '' ?>></td>
            <td><button class="btn-sm">Save</button></td>
          </form>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px">Add new staff member</h3>
    <p class="muted">After saving, set their password on <a href="set_password.php">set_password.php</a> before they can log in.</p>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <table class="st">
        <tr>
          <th>Login</th><th>First name</th><th>Last name</th>
          <th>Email</th><th>Mobile</th>
          <th>Pay&nbsp;rate</th><th>Billing&nbsp;rate</th>
          <th>Level</th><th>Active</th><th></th>
        </tr>
        <tr>
          <td><input type="text"   name="Login"        class="w-mid" required></td>
          <td><input type="text"   name="First Name"   class="w-mid"></td>
          <td><input type="text"   name="Last Name"    class="w-mid"></td>
          <td><input type="email"  name="email"        class="w-wide"></td>
          <td><input type="text"   name="Mobile"       class="w-mid"></td>
          <td><input type="number" step="0.01" name="Pay_Rate"     class="w-narrow"></td>
          <td><input type="number" step="0.01" name="Billing Rate" class="w-narrow"></td>
          <td><input type="number" name="Level" class="w-narrow" value="1"></td>
          <td><input type="checkbox" name="Active" value="1" checked></td>
          <td><button class="btn-sm">Add</button></td>
        </tr>
      </table>
    </form>
  </div>

  <p class="muted"><a href="more.php">← back to More</a> &middot; <a href="set_password.php">set_password.php</a></p>
</div></body></html>
