<?php
/**
 * api_token_admin.php — admin-only: issue / revoke per-staff API tokens for the
 * Revit add-in. The token goes in the staff member's add-in settings
 * (%AppData%\CadViz\addin.json) and is sent as the X-CadViz-Token header;
 * api/_bootstrap.php::resolve_api_token() validates it. See add_api_tokens.sql.
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$pdo = get_db();
if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp    = (int)($_POST['employee_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($emp > 0 && $action === 'generate') {
        $tok = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE Staff SET api_token = ? WHERE Employee_ID = ?")->execute([$tok, $emp]);
        $_SESSION['tok_flash'] = "New token for staff #$emp:\n$tok\n\nCopy it into that person's add-in settings now — only the last 6 chars are shown afterwards.";
    } elseif ($emp > 0 && $action === 'revoke') {
        $pdo->prepare("UPDATE Staff SET api_token = NULL WHERE Employee_ID = ?")->execute([$emp]);
        $_SESSION['tok_flash'] = "Token revoked for staff #$emp.";
    }
    header('Location: api_token_admin.php');
    exit;
}
$flash = $_SESSION['tok_flash'] ?? '';
unset($_SESSION['tok_flash']);

$staff = $pdo->query(
    "SELECT Employee_ID, Login, `First Name` AS fn, `Last Name` AS ln, api_token, Active
       FROM Staff ORDER BY COALESCE(Active,1) DESC, Login"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Add-in API tokens</title>
<link href="site.css" rel="stylesheet">
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;font-size:13px;color:#222;max-width:860px;margin:18px auto;padding:0 16px}
table{width:100%;border-collapse:collapse}th,td{padding:6px 8px;border-bottom:1px solid #eee;text-align:left}
th{background:#f4f4f4}code{background:#f6f6f6;padding:1px 5px;border-radius:3px}
.flash{background:#d6f5d6;border:1px solid #1a6b1a;color:#155515;padding:10px 14px;border-radius:4px;white-space:pre-wrap;margin:12px 0}
.btn{background:#9B9B1B;color:#fff;border:none;padding:5px 12px;border-radius:3px;cursor:pointer;text-decoration:none}
.btn.rev{background:#999}
</style>
</head>
<body>
<h1>Revit add-in — API tokens</h1>
<p><a href="menu.php">← Menu</a></p>
<?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<table>
  <thead><tr><th>Staff</th><th>Login</th><th>Token</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($staff as $s):
      $tok = (string)($s['api_token'] ?? '');
      $mask = $tok !== '' ? '…' . substr($tok, -6) : '<span style="color:#999">none</span>';
  ?>
    <tr>
      <td><?= htmlspecialchars(trim(($s['fn'] ?? '') . ' ' . ($s['ln'] ?? ''))) ?><?= (int)($s['Active'] ?? 1) === 0 ? ' <span style="color:#999">(inactive)</span>' : '' ?></td>
      <td><code><?= htmlspecialchars((string)$s['Login']) ?></code></td>
      <td><?= $mask ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="employee_id" value="<?= (int)$s['Employee_ID'] ?>">
          <button class="btn" name="action" value="generate" onclick="return confirm('Generate a new token? Any existing token for this person stops working.');"><?= $tok !== '' ? 'Regenerate' : 'Generate' ?></button>
          <?php if ($tok !== ''): ?>
            <button class="btn rev" name="action" value="revoke" onclick="return confirm('Revoke this token?');">Revoke</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
