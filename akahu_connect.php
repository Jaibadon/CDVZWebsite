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
                $flash = 'Akahu connected. Authenticated as ' . htmlspecialchars((string)$name)
                       . ', ' . count($accs) . ' account(s) visible.'
                       . (count($accs) > 1
                            ? ' Run a sync, then pick the receivables account in the "Connected accounts" table below.'
                            : ' Run a sync to pull your transactions — your single account will be auto-flagged as the receivables target.');
            } catch (Exception $e) {
                $flashErr = 'Failed: ' . $e->getMessage();
                try { $pdo->prepare("UPDATE Akahu_Tokens SET last_error = ? WHERE id = 1")->execute([$e->getMessage()]); } catch (Exception $e2) {}
            }
        }
    } elseif ($action === 'disconnect') {
        AkahuClient::disconnect($pdo);
        $flash = 'Akahu disconnected. The bank-feed sync will not run again until you reconnect.';
    } elseif ($action === 'set_default_account') {
        $aid = trim((string)($_POST['akahu_id'] ?? ''));
        if ($aid !== '') {
            try {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE Bank_Accounts SET is_default = 0");
                $pdo->prepare("UPDATE Bank_Accounts SET is_default = 1 WHERE akahu_id = ?")->execute([$aid]);
                $pdo->commit();
                $flash = 'Receivables account set. Auto-matching now only considers credits to that account.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flashErr = 'Could not set default: ' . $e->getMessage();
            }
        }
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
    <p class="muted">Akahu gives us a single API for live bank-transaction feeds across the major NZ banks (Westpac, ANZ, ASB, BNZ, Kiwibank). It runs <strong>alongside Xero</strong> — Xero stays the books-of-record for invoicing and credit notes, Akahu provides the bank-side evidence so payments get reconciled in CADViz the moment they land in the bank, even if Xero hasn't caught up yet.</p>

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

      <?php
        // Account list — useful even for single-account installs as a
        // sanity check that the right account is feeding through. The
        // "is_default" flag picks which account's credits the
        // auto-matcher considers (everything else just shows up in the
        // reconcile queue without auto-allocations).
        $accounts = [];
        try { $accounts = $pdo->query("SELECT akahu_id, name, type, formatted_account, bank, currency, is_default, COALESCE(Active, 1) AS Active FROM Bank_Accounts ORDER BY is_default DESC, name")->fetchAll(); }
        catch (Exception $e) {}
        $defaultCount = 0; foreach ($accounts as $a) if ((int)$a['is_default'] === 1) $defaultCount++;
      ?>
      <?php if (!empty($accounts)): ?>
        <h3 style="margin-top:18px">Connected accounts</h3>
        <p class="muted">Tick the receivables account — auto-matching only considers credits that land here. Other accounts still show up in the reconcile queue but never auto-allocate. Single-account setups get auto-flagged on first sync.</p>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <tr style="background:#eee"><th style="text-align:left;padding:4px 6px">Receivables</th><th style="text-align:left;padding:4px 6px">Bank</th><th style="text-align:left;padding:4px 6px">Account</th><th style="text-align:left;padding:4px 6px">Type</th><th style="text-align:left;padding:4px 6px">Formatted</th></tr>
          <?php foreach ($accounts as $a): ?>
            <tr style="border-bottom:1px solid #eee">
              <td style="padding:4px 6px">
                <form method="post" style="display:inline" onsubmit="return confirm('Set this account as the receivables target? Auto-matching will then only consider credits to this account, ignoring others.');">
                  <input type="hidden" name="action" value="set_default_account">
                  <input type="hidden" name="akahu_id" value="<?= htmlspecialchars($a['akahu_id']) ?>">
                  <?php if ((int)$a['is_default'] === 1): ?>
                    <strong style="color:#1a6b1a">✓ default</strong>
                  <?php else: ?>
                    <button type="submit" style="background:#246;color:#fff;border:none;padding:2px 8px;border-radius:3px;cursor:pointer;font-size:11px">Set as default</button>
                  <?php endif; ?>
                </form>
              </td>
              <td style="padding:4px 6px"><?= htmlspecialchars((string)($a['bank'] ?? '?')) ?></td>
              <td style="padding:4px 6px"><?= htmlspecialchars((string)($a['name'] ?? '?')) ?></td>
              <td style="padding:4px 6px"><?= htmlspecialchars((string)($a['type'] ?? '')) ?></td>
              <td style="padding:4px 6px"><code><?= htmlspecialchars((string)($a['formatted_account'] ?? '')) ?></code></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php if ($defaultCount === 0 && count($accounts) > 1): ?>
          <p style="color:#a00;font-size:11px;margin-top:6px"><strong>No receivables account flagged.</strong> Auto-matching is paused until you set one above. (Single-account installs get auto-flagged; multi-account installs need a manual choice.)</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted" style="margin-top:14px">No accounts cached yet. Click <strong>Sync transactions now</strong> to populate.</p>
      <?php endif; ?>

      <p style="margin-top:16px">
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
        <li>Sign up / log in at <a href="https://genie.akahu.io" target="_blank">genie.akahu.io</a> (Akahu's personal-app dashboard).</li>
        <li>Inside Genie, connect your Westpac (or other NZ bank) account through Akahu's authorisation flow. You log in to your bank inside Akahu's flow — CADViz never sees your bank credentials.</li>
        <li>Once at least one account is connected, open <strong>My Apps</strong> (or <strong>Settings → Developers</strong>) in Genie and copy:
          <ul>
            <li><strong>App ID Token</strong> — Akahu's identifier for your personal app. Sent as the <code>X-Akahu-Id</code> header.</li>
            <li><strong>User Access Token</strong> — your personal authorisation. Sent as <code>Authorization: Bearer …</code>.</li>
          </ul>
        </li>
        <li>Paste both below and click Save. Akahu's personal-app tokens don't expire and don't refresh — you do this once.</li>
      </ol>
      <p class="muted">When you save, this page calls <code>GET /v1/me</code> + <code>GET /v1/accounts</code> against the Akahu API to confirm the credentials work, and tells you straight away if either is wrong.</p>
      <form method="post">
        <input type="hidden" name="action" value="save">
        <label>App ID Token <span class="muted">(sent as <code>X-Akahu-Id</code>)</span></label>
        <input type="text"     name="app_token"  required autocomplete="off" placeholder="app_token_xxxxxxxxxxxxxxxxxxxxxxxxx">
        <label>User Access Token <span class="muted">(sent as <code>Authorization: Bearer</code>)</span></label>
        <input type="password" name="user_token" required autocomplete="off" placeholder="user_token_xxxxxxxxxxxxxxxxxxxxxxxxx">
        <p style="margin-top:14px"><button type="submit">Save &amp; test connection</button></p>
      </form>
    <?php endif; ?>

    <p class="muted" style="margin-top:18px"><a href="menu.php">← Back to menu</a></p>
  </div>
</div>
</body></html>
