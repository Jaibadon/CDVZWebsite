<?php
/**
 * Bank-feed reconciliation queue.
 *
 * Shows transactions that the auto-matcher couldn't fully resolve, and
 * lets an admin allocate each to one or more invoices.
 *
 * Common scenarios this UI handles:
 *
 *   - "Client paid but didn't put the CAD reference on it" — the txn
 *     has no detectable invoice number, but the description usually
 *     names the client. Admin picks the right invoice from the dropdown
 *     of that client's unpaid invoices.
 *
 *   - "Client paid the wrong reference" — txn has a CAD- pattern but
 *     for an invoice that's already paid (or doesn't exist). The
 *     auto-matcher leaves it. Admin picks the actual intended invoice.
 *
 *   - "Client paid a lump sum covering multiple invoices" — auto-matched
 *     one invoice via reference, but the txn is much bigger. Status =
 *     'partially_matched' with leftover. Admin allocates the remainder
 *     to the other invoices the client owes.
 *
 *   - "Wrong allocation" — admin can undo any allocation row, which
 *     reverses Invoices.AmountPaid + Paid flag, and downgrades the
 *     transaction's status appropriately.
 */

session_start();
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'bankfeed_match.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); echo '<p>Admin only.</p>'; exit;
}

$pdo   = get_db();
$flash = ''; $flashErr = '';

// ── POST handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'allocate') {
            $txnId  = (int)($_POST['txn_id']    ?? 0);
            $invNo  = (int)($_POST['invoice_no'] ?? 0);
            $cap    = $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
            if ($txnId <= 0 || $invNo <= 0) throw new RuntimeException('Pick a transaction and an invoice.');
            if (allocate_to_invoice($pdo, $txnId, $invNo, true, $_SESSION['UserID'], $cap)) {
                $flash = 'Allocated $' . number_format((float)($cap ?? 0), 2) . ' (or remaining) of txn #' . $txnId . ' to CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT) . '.';
            } else {
                $flashErr = 'Nothing allocated — invoice may already be fully paid or transaction fully consumed.';
            }
        } elseif ($action === 'undo') {
            $allocId = (int)($_POST['alloc_id'] ?? 0);
            reverse_allocation($pdo, $allocId);
            $flash = 'Allocation undone.';
        } elseif ($action === 'ignore') {
            $txnId = (int)($_POST['txn_id'] ?? 0);
            $pdo->prepare("UPDATE Bank_Transactions SET matched_status = 'ignored', matched_at = NOW(), matched_by = ? WHERE id = ?")
                ->execute([$_SESSION['UserID'], $txnId]);
            $flash = 'Transaction marked as ignored (won\'t appear in the queue or affect any invoice).';
        } elseif ($action === 'unignore') {
            $txnId = (int)($_POST['txn_id'] ?? 0);
            $pdo->prepare("UPDATE Bank_Transactions SET matched_status = 'unmatched' WHERE id = ?")->execute([$txnId]);
            $flash = 'Restored to the queue.';
        } elseif ($action === 'rematch') {
            $n = run_auto_match($pdo);
            $flash = "Re-ran auto-match — created $n new allocation(s).";
        }
    } catch (Exception $e) { $flashErr = $e->getMessage(); }

    header('Location: bankfeed_reconcile.php' . ($_GET['filter'] ?? '' ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

// ── Read ────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'open';   // open | all | ignored

$where = "1=1";
if      ($filter === 'open')    $where = "matched_status IN ('unmatched','partially_matched')";
elseif  ($filter === 'ignored') $where = "matched_status = 'ignored'";

$txns = $pdo->query(
    "SELECT bt.*,
            COALESCE((SELECT SUM(amount) FROM Bank_Allocations WHERE transaction_id = bt.id), 0) AS allocated_so_far
       FROM Bank_Transactions bt
      WHERE bt.amount > 0
        AND $where
      ORDER BY bt.txn_date DESC, bt.id DESC
      LIMIT 200"
)->fetchAll();

// Pre-load unpaid invoices grouped by client for the per-row pickers.
$invs = $pdo->query(
    "SELECT i.Invoice_No, i.Date, i.Subtotal, i.Tax_Rate, i.AmountPaid, i.Client_ID,
            c.Client_Name,
            ROUND(i.Subtotal * (1 + COALESCE(i.Tax_Rate, 0)) - COALESCE(i.AmountPaid, 0), 2) AS remaining
       FROM Invoices i
       LEFT JOIN Clients c ON i.Client_ID = c.Client_id
      WHERE COALESCE(i.Paid, 0) = 0
     HAVING remaining > 0.005
      ORDER BY c.Client_Name, i.Invoice_No DESC"
)->fetchAll();

$invsByClient = [];
foreach ($invs as $inv) $invsByClient[(int)$inv['Client_ID']][] = $inv;

$flash    = $_SESSION['flash']     ?? $flash;
$flashErr = $_SESSION['flash_err'] ?? $flashErr;
unset($_SESSION['flash'], $_SESSION['flash_err']);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bank-feed reconciliation</title>
<link href="site.css" rel="stylesheet">
<style>
.page { max-width: 1100px; margin: 20px auto; }
.card { background:#fff; padding:14px 18px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:12px; }
.txn  { border-left:4px solid #ddd; padding:10px 14px; margin:10px 0; background:#fafafa; }
.txn.partial { border-left-color:#c8a52e; background:#fff8df; }
.txn.unmatched { border-left-color:#c33; }
.muted { color:#666; font-size:12px; }
.amt   { font-family:monospace; font-weight:bold; }
.btn-sm { background:#9B9B1B; color:#fff; border:none; padding:3px 9px; border-radius:3px; cursor:pointer; font-size:12px; }
.btn-sm.danger { background:#c33; }
.btn-sm.muted  { background:#666; }
.alloc { background:#d6f5d6; padding:4px 8px; border-radius:3px; margin:3px 0; font-size:12px; display:inline-block; }
.flash    { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.flash-err{ background:#ffd6d6; border:1px solid #c33;   color:#a00;    padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.tabs a { display:inline-block; padding:6px 12px; background:#eee; color:#333; text-decoration:none; border-radius:3px; margin-right:4px; }
.tabs a.active { background:#9B9B1B; color:#fff; }
form.inline { display:inline; }
select, input[type=number] { padding:3px 6px; font-size:12px; }
</style>
</head><body>
<div class="page">
  <div class="card">
    <h1 style="margin:0">Bank-feed reconciliation</h1>
    <p class="muted">
      Akahu transactions and the invoices they look like they pay. <strong>Xero is still the system of record</strong> — these allocations are evidence
      to help you reconcile in Xero, not a replacement. When this UI shows an invoice as "fully covered" but its Xero status is still <em>AUTHORISED</em>,
      that's the cue to mark it Paid in Xero (the next xero_sync run will then flip Invoices.Paid here automatically).
    </p>
  </div>

  <?php if ($flash):    ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <div class="card">
    <div class="tabs" style="display:flex;justify-content:space-between;align-items:center">
      <div>
        <a href="?filter=open"    class="<?= $filter==='open'?'active':'' ?>">Open queue</a>
        <a href="?filter=all"     class="<?= $filter==='all' ?'active':'' ?>">All credits</a>
        <a href="?filter=ignored" class="<?= $filter==='ignored'?'active':'' ?>">Ignored</a>
      </div>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="rematch">
        <button class="btn-sm">↻ Re-run auto-match</button>
      </form>
    </div>
  </div>

  <?php if (empty($txns)): ?>
    <div class="card"><p style="color:#1a6b1a">✓ Nothing to reconcile in this view.</p></div>
  <?php endif; ?>

  <?php foreach ($txns as $t):
      $existing = $pdo->prepare(
          "SELECT ba.id, ba.invoice_no, ba.amount, ba.allocated_at, ba.allocated_by, ba.auto, c.Client_Name
             FROM Bank_Allocations ba
             LEFT JOIN Invoices i ON i.Invoice_No = ba.invoice_no
             LEFT JOIN Clients  c ON c.Client_id = i.Client_ID
            WHERE ba.transaction_id = ?
            ORDER BY ba.allocated_at"
      );
      $existing->execute([(int)$t['id']]);
      $allocs = $existing->fetchAll();
      $alloced = round((float)$t['allocated_so_far'], 2);
      $remain  = round((float)$t['amount'] - $alloced, 2);
      $cls = $t['matched_status'] === 'partially_matched' ? 'partial' : ($t['matched_status'] === 'unmatched' ? 'unmatched' : '');
  ?>
    <div class="card">
      <div class="txn <?= $cls ?>">
        <div style="display:flex;justify-content:space-between">
          <div>
            <strong>$<?= number_format((float)$t['amount'], 2) ?></strong>
            <span class="muted"><?= htmlspecialchars($t['txn_date']) ?> &middot; <?= htmlspecialchars((string)($t['type'] ?? '')) ?></span>
            <br>
            <span><?= htmlspecialchars((string)($t['description'] ?? '')) ?></span>
            <?php if (!empty($t['particulars']) || !empty($t['code']) || !empty($t['reference'])): ?>
              <br><span class="muted">
                <?php if (!empty($t['particulars'])): ?>P: <?= htmlspecialchars($t['particulars']) ?> &middot; <?php endif; ?>
                <?php if (!empty($t['code']))       : ?>C: <?= htmlspecialchars($t['code'])        ?> &middot; <?php endif; ?>
                <?php if (!empty($t['reference']))  : ?>R: <?= htmlspecialchars($t['reference'])   ?>           <?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if (!empty($t['other_account'])): ?>
              <br><span class="muted">Other party: <?= htmlspecialchars($t['other_account']) ?></span>
            <?php endif; ?>
          </div>
          <div style="text-align:right">
            <span class="muted">status: <strong><?= htmlspecialchars($t['matched_status']) ?></strong></span><br>
            <span class="amt">allocated: $<?= number_format($alloced, 2) ?></span><br>
            <span class="amt" style="color:<?= $remain > 0.005 ? '#a00' : '#1a6b1a' ?>">remaining: $<?= number_format($remain, 2) ?></span>
          </div>
        </div>

        <?php foreach ($allocs as $a): ?>
          <div class="alloc">
            → CAD-<?= str_pad((string)$a['invoice_no'], 5, '0', STR_PAD_LEFT) ?>
            <strong>$<?= number_format((float)$a['amount'], 2) ?></strong>
            <span class="muted">
              <?= $a['auto'] ? 'auto-matched' : 'manual by ' . htmlspecialchars((string)$a['allocated_by']) ?>
              <?php if (!empty($a['Client_Name'])): ?> · <?= htmlspecialchars($a['Client_Name']) ?><?php endif; ?>
            </span>
            <form method="post" class="inline" onsubmit="return confirm('Undo this allocation? This will reverse the payment on CAD-<?= str_pad((string)$a['invoice_no'], 5, '0', STR_PAD_LEFT) ?>.');">
              <input type="hidden" name="action" value="undo">
              <input type="hidden" name="alloc_id" value="<?= (int)$a['id'] ?>">
              <button class="btn-sm danger">undo</button>
            </form>
          </div>
        <?php endforeach; ?>

        <?php if ($remain > 0.005 && $t['matched_status'] !== 'ignored'): ?>
          <form method="post" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="action" value="allocate">
            <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">

            <select name="invoice_no" required>
              <option value="">— pick invoice —</option>
              <?php
                $byClient = [];
                foreach ($invs as $inv) $byClient[(string)($inv['Client_Name'] ?? '?')][] = $inv;
                ksort($byClient, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($byClient as $cname => $clist):
              ?>
                <optgroup label="<?= htmlspecialchars($cname) ?>">
                  <?php foreach ($clist as $inv): ?>
                    <option value="<?= (int)$inv['Invoice_No'] ?>">
                      CAD-<?= str_pad((string)$inv['Invoice_No'], 5, '0', STR_PAD_LEFT) ?> &mdash;
                      <?= htmlspecialchars($inv['Date'] ?? '') ?> &mdash;
                      remaining $<?= number_format((float)$inv['remaining'], 2) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>

            <label class="muted">amount <input type="number" step="0.01" min="0" max="<?= number_format($remain, 2, '.', '') ?>" name="amount" placeholder="<?= number_format($remain, 2) ?>" style="width:90px"></label>
            <span class="muted">(blank = remaining $<?= number_format($remain, 2) ?>)</span>

            <button class="btn-sm">Allocate</button>

            <form method="post" class="inline" onsubmit="return confirm('Mark this transaction as ignored? It will stop appearing in the queue. Restore from the Ignored tab.');" style="margin-left:auto">
              <input type="hidden" name="action" value="ignore">
              <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
              <button class="btn-sm muted">Ignore</button>
            </form>
          </form>
        <?php elseif ($t['matched_status'] === 'ignored'): ?>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="unignore">
            <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
            <button class="btn-sm muted">Restore to queue</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <p class="muted"><a href="menu.php">← Back to menu</a> &middot; <a href="akahu_sync.php">Sync now</a> &middot; <a href="akahu_connect.php">Akahu settings</a></p>
</div>
</body></html>
