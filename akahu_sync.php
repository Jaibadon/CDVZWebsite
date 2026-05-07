<?php
/**
 * Akahu sync — pulls accounts + transactions into local tables.
 *
 * Modes:
 *   - Web (admin clicks "Sync transactions now")
 *   - CLI cron: `php akahu_sync.php cron`  (run alongside the existing
 *     send_reminders cron, BEFORE it, so reminders see fresh paid state)
 *   - Library: `define('AKAHU_SYNC_LIBRARY_ONLY', 1); require_once 'akahu_sync.php';`
 *     then call `run_akahu_sync(get_db())` from another script.
 *
 * What it does:
 *   1. Refresh Bank_Accounts from GET /accounts. First-run: marks the
 *      single visible account as is_default=1 so the matcher has
 *      something to point at.
 *   2. Pulls new transactions (since last_synced_at - 7 days as a safety
 *      window) and INSERT IGNOREs them into Bank_Transactions.
 *   3. Calls run_auto_match() so any new credits get auto-allocated to
 *      invoices in the same run.
 *
 * Returns ['accounts' => N, 'transactions_new' => N, 'auto_matched' => N,
 *          'errors' => [...]].
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/akahu_client.php';
require_once __DIR__ . '/bankfeed_match.php';

function run_akahu_sync(PDO $pdo): array
{
    $r = ['accounts' => 0, 'transactions_new' => 0, 'auto_matched' => 0, 'errors' => []];
    if (!AkahuClient::isConnected($pdo)) {
        $r['errors'][] = 'Akahu not connected — sync skipped.';
        return $r;
    }
    try {
        $client = new AkahuClient($pdo);

        // ── Accounts ────────────────────────────────────────────────────
        $accs = $client->accounts();
        $upsertAcc = $pdo->prepare(
            "INSERT INTO Bank_Accounts (akahu_id, name, type, formatted_account, bank, currency, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               name = VALUES(name), type = VALUES(type), formatted_account = VALUES(formatted_account),
               bank = VALUES(bank), currency = VALUES(currency), last_synced_at = NOW()"
        );
        foreach ($accs as $a) {
            // Per the /accounts response model:
            //   _id, name, type, formatted_account, status         → top-level strings
            //   connection.name, connection.logo                   → bank + logo
            //   balance.currency                                   → currency code
            //   attributes                                         → array of strings
            //                                                        like ["TRANSACTIONS","TRANSFER_TO",
            //                                                        "PAYMENT_FROM"], not currency!
            // (The previous code used $a['attributes'][0] for currency — a leftover from misreading
            // the spec. attributes[0] is "TRANSACTIONS" not "NZD".)
            $upsertAcc->execute([
                $a['_id'] ?? '',
                $a['name'] ?? null,
                $a['type'] ?? null,
                $a['formatted_account'] ?? null,
                $a['connection']['name'] ?? ($a['bank'] ?? null),
                $a['balance']['currency'] ?? 'NZD',
            ]);
            $r['accounts']++;
        }

        // First-run convenience: if exactly one account exists and none is
        // marked default, mark it. Multi-account setups need manual flagging
        // via SQL or a future akahu_accounts.php picker.
        $defCount = (int)$pdo->query("SELECT COUNT(*) FROM Bank_Accounts WHERE is_default = 1")->fetchColumn();
        if ($defCount === 0) {
            $only = $pdo->query("SELECT akahu_id FROM Bank_Accounts WHERE Active = 1")->fetchAll();
            if (count($only) === 1) {
                $pdo->prepare("UPDATE Bank_Accounts SET is_default = 1 WHERE akahu_id = ?")->execute([$only[0]['akahu_id']]);
            }
        }

        // ── Transactions ─────────────────────────────────────────────────
        // Re-pull a 7-day window from the previous run as a safety net for
        // back-dated transactions Akahu sometimes emits late, plus any new
        // ones since.
        //
        // The Akahu /transactions endpoint expects ISO-8601 UTC
        // timestamps with millisecond resolution. start is exclusive,
        // end is inclusive. We use gmdate so the format is always
        // UTC-Z regardless of the server's PHP timezone — date('c')
        // would produce a local-offset string like +12:00 in NZ which
        // Akahu accepts but is needlessly different from the docs.
        $row = $pdo->query("SELECT last_synced_at FROM Akahu_Tokens WHERE id = 1")->fetch();
        $startEpoch = ($row && !empty($row['last_synced_at']))
            ? strtotime((string)$row['last_synced_at'] . ' UTC') - 7 * 86400  // assume DB stores UTC (NOW() on UTC server)
            : strtotime('-90 days');                                          // first run: 90 day backfill
        $start = gmdate('Y-m-d\TH:i:s.000\Z', $startEpoch);

        $insTxn = $pdo->prepare(
            "INSERT IGNORE INTO Bank_Transactions
                 (akahu_id, account_id, txn_date, amount, description, particulars, code, reference,
                  type, other_account, raw_json, pulled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        $cursor = null;
        $loop   = 0;
        do {
            $loop++;
            if ($loop > 50) { $r['errors'][] = 'Pagination cap hit (50 pages) — bailing.'; break; }
            $page = $client->transactions($start, null, $cursor);
            foreach (($page['items'] ?? []) as $t) {
                $other = $t['meta']['other_account'] ?? null;
                if (is_array($other)) $other = json_encode($other);
                $insTxn->execute([
                    $t['_id'] ?? '',
                    $t['_account'] ?? '',
                    isset($t['date']) ? substr((string)$t['date'], 0, 10) : date('Y-m-d'),
                    (float)($t['amount'] ?? 0),
                    $t['description'] ?? null,
                    $t['meta']['particulars'] ?? null,
                    $t['meta']['code']        ?? null,
                    $t['meta']['reference']   ?? null,
                    $t['type'] ?? null,
                    $other,
                    json_encode($t),
                ]);
                if ($insTxn->rowCount() > 0) $r['transactions_new']++;
            }
            $cursor = $page['cursor'] ?? null;
        } while ($cursor);

        // Stamp last_synced_at AFTER the transactions are in, so a crash
        // mid-pull doesn't leave us thinking we got further than we did.
        $pdo->prepare("UPDATE Akahu_Tokens SET last_synced_at = NOW(), last_error = NULL WHERE id = 1")->execute();

        // ── Auto-match any newly-imported credits ───────────────────────
        $r['auto_matched'] = run_auto_match($pdo);
    } catch (Exception $e) {
        $r['errors'][] = $e->getMessage();
        try { $pdo->prepare("UPDATE Akahu_Tokens SET last_error = ? WHERE id = 1")->execute([$e->getMessage()]); } catch (Exception $e2) {}
    }
    return $r;
}

if (defined('AKAHU_SYNC_LIBRARY_ONLY')) return;

$isCli  = (php_sapi_name() === 'cli');
$isCron = $isCli && in_array('cron', $argv ?? [], true);

if (!$isCli) {
    session_start();
    if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
        http_response_code(403);
        echo '<p>Admin only.</p>'; exit;
    }
}

$pdo    = get_db();
$result = run_akahu_sync($pdo);

if ($isCli) {
    echo "Akahu sync complete\n";
    echo "  accounts:         " . (int)$result['accounts'] . "\n";
    echo "  transactions_new: " . (int)$result['transactions_new'] . "\n";
    echo "  auto_matched:     " . (int)$result['auto_matched'] . "\n";
    foreach ($result['errors'] as $e) echo "    ERROR: $e\n";
    exit($result['errors'] ? 1 : 0);
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Akahu Sync</title><link href="site.css" rel="stylesheet">
<style>
.page { max-width: 720px; margin: 30px auto; }
.card { background:#fff; padding:18px 22px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
</style>
</head><body>
<div class="page"><div class="card">
  <h1>Akahu sync</h1>
  <p>
    <strong><?= (int)$result['accounts'] ?></strong> accounts refreshed,
    <strong><?= (int)$result['transactions_new'] ?></strong> new transactions imported,
    <strong><?= (int)$result['auto_matched'] ?></strong> auto-matched to invoices.
  </p>
  <?php foreach ($result['errors'] as $e): ?>
    <p style="color:#c33">Error: <?= htmlspecialchars($e) ?></p>
  <?php endforeach; ?>
  <p>
    <a href="bankfeed_reconcile.php">Reconciliation queue</a> &middot;
    <a href="akahu_connect.php">Akahu settings</a> &middot;
    <a href="menu.php">Menu</a>
  </p>
</div></div></body></html>
