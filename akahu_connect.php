<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'akahu_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
    exit;
}

$pdo = get_db();
$flash = ''; $flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $appToken  = trim((string)($_POST['app_token']  ?? ''));
        $userToken = trim((string)($_POST['user_token'] ?? ''));
        if ($appToken === '' || $userToken === '') {
            $flashErr = 'Both App Token and User Token are required.';
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO Akahu_Tokens (id, app_token, user_token, connected_at, connected_by)
                     VALUES (1, ?, ?, NOW(), ?)
                     ON DUPLICATE KEY UPDATE
                       app_token    = VALUES(app_token),
                       user_token   = VALUES(user_token),
                       connected_at = NOW(),
                       connected_by = VALUES(connected_by),
                       last_error   = NULL"
                )->execute([$appToken, $userToken, $_SESSION['UserID']]);

                // Smoke-test the credentials immediately so the admin gets
                // instant feedback instead of finding out at sync time.
                $client = new AkahuClient($pdo);
                $me     = $client->me();
                $name   = $me['item']['name'] ?? $me['name'] ?? 'unknown';
                $accs   = $client->accounts();
                $flash  = 'Akahu connected. Authenticated as ' . htmlspecialchars((string)$name)
                        . ', ' . count($accs) . ' account(s) visible. Visit '
                        . '<a href="akahu_accounts.php">Bank Accounts</a> to mark which one is your receivables account.';
            } catch (Exception $e) {
                $flashErr = 'Failed: ' . $e->getMessage();
                try { $pdo->prepare("UPDATE Akahu_Tokens SET last_error = ? WHERE id = 1")->execute([$e->getMessage()]); } catch (Exception $e2) {}
            }
        }
    } elseif ($action === 'disconnect') {
        AkahuClient::disconnect($pdo);
        $flash = 'Akahu disconnected. The bank-feed sync will not run again until you reconnect.';
    }
}

$tokenRow = null;
try { $tokenRow = $pdo->query("SELECT app_token, user_token, last_synced_at, connected_at, connected_by, last_error FROM Akahu_Tokens WHERE id = 1")->fetch(); } catch (Exception $e) {}
$connected = $tokenRow && !empty($tokenRow['app_token']);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Akahu Bank Feed</title>
<link href="site.css" rel="stylesheet">
<style>
.page { max-width: 720px; margin: 30px auto; }
.card { background:#fff; padding:18px 22px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
.card h1 { margin-top:0; color:#9B9B1B; }
input[type=text], input[type=password] { width:100%; padding:6px 8px; box-sizing:border-box; font-family:monospace; }
label { display:block; margin-top:10px; font-weight:bold; font-size:13px; }
.flash    { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.flash-err{ background:#ffd6d6; border:1px solid #c33;   color:#a00;    padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.muted    { color:#666; font-size:12px; }
button    { background:#9B9B1B; color:#fff; border:none; padding:8px 16px; border-radius:3px; cursor:pointer; }
.danger   { background:#c33; }
</style>
</head><body>
<div class="page">
  <div class="card">
    <h1>Akahu Bank Feed</h1>
    <p class="muted">Akahu gives us a single API for live bank-transaction feeds across the major NZ banks (Westpac, ANZ, ASB, BNZ, Kiwibank). Replaces Xero on the payments side.</p>

    <?php if ($flash):    ?><div class="flash"    ><?= $flash ?></div>    <?php endif; ?>
    <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

    <?php if ($connected): ?>
      <p><strong>Status:</strong> <span style="color:#1a6b1a">Connected</span>
        <?php if (!empty($tokenRow['connected_at'])): ?>
          <span class="muted">since <?= htmlspecialchars($tokenRow['connected_at']) ?> by <?= htmlspecialchars((string)($tokenRow['connected_by'] ?? '?')) ?></span>
        <?php endif; ?>
      </p>
      <?php if (!empty($tokenRow['last_synced_at'])): ?>
        <p class="muted">Last synced: <?= htmlspecialchars($tokenRow['last_synced_at']) ?> UTC</p>
      <?php endif; ?>
      <?php if (!empty($tokenRow['last_error'])): ?>
        <div class="flash-err">Last sync error: <?= htmlspecialchars($tokenRow['last_error']) ?></div>
      <?php endif; ?>
      <p>
        <a href="akahu_sync.php" class="btn">Sync transactions now</a> &middot;
        <a href="bankfeed_reconcile.php">Reconciliation queue</a>
      </p>
      <form method="post" onsubmit="return confirm('Disconnect Akahu? The cron will stop pulling new transactions until you reconnect — but transactions already in Bank_Transactions stay where they are.');">
        <input type="hidden" name="action" value="disconnect">
        <button type="submit" class="danger">Disconnect Akahu</button>
      </form>
    <?php else: ?>
      <h3>Setup steps</h3>
      <ol>
        <li>Go to <a href="https://genie.akahu.io" target="_blank">genie.akahu.io</a> and sign up / log in.</li>
        <li>Connect your Westpac (or other NZ bank) account through Akahu's flow.</li>
        <li>Open <strong>Settings → Developers</strong> and copy the <strong>App Token</strong> and <strong>User Token</strong>.</li>
        <li>Paste them below and click Save. Tokens never expire — you only do this once.</li>
      </ol>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <label>App Token (X-Akahu-Id header)</label>
        <input type="text"     name="app_token"  required autocomplete="off">
        <label>User Token (Authorization Bearer)</label>
        <input type="password" name="user_token" required autocomplete="off">
        <p style="margin-top:14px"><button type="submit">Save &amp; test connection</button></p>
      </form>
    <?php endif; ?>

    <p class="muted" style="margin-top:18px"><a href="menu.php">← Back to menu</a></p>
  </div>
</div>
</body></html>
