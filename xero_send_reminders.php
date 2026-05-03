<?php
/**
 * Send overdue-invoice reminders FROM accounts@cadviz.co.nz instead of from
 * Xero's mail server. (Xero's auto-reminders go from a Xero address that
 * many clients spam-filter — sending from your own domain converts better.)
 *
 * Recommended Xero setup:
 *   In Xero → Business → Invoices → Invoice reminders → DISABLE the
 *   automatic reminder schedule. Let this script handle reminders instead.
 *
 * Trigger options:
 *   1. Cron job (recommended). Add to cPanel Cron Jobs:
 *        0  9  *  *  *  /usr/bin/php /home/<user>/public_html/xero_send_reminders.php cron
 *      Runs at 09:00 daily; only sends reminders for invoices that are
 *      overdue at the configured intervals (7, 14, 30 days past due).
 *   2. Manual trigger: visit xero_send_reminders.php?dry_run=1 to preview
 *      who would receive a reminder, then drop the dry_run param to send.
 *   3. Per-invoice "Send reminder now" button on monthly_invoicing.php (also
 *      hits this script with ?invoice_no=N).
 *
 * Spam guards:
 *   - Skips an invoice if it was reminded within the last 6 days (configurable
 *     via App_Meta key 'reminder_min_gap_days').
 *   - Caps total sends per run at 30 (env CADVIZ_REMINDER_CAP if set).
 *
 * Run modes (CLI):  php xero_send_reminders.php cron
 *                   php xero_send_reminders.php cron dry-run
 * Web modes:        ?dry_run=1            (admin only)
 *                   ?invoice_no=NNNN      (force-send a single invoice; admin only)
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/xero_client.php';
// Pull xero_sync.php as a library (defines run_xero_sync) without
// triggering its own auth/CLI handling.
define('XERO_SYNC_LIBRARY_ONLY', true);
require_once __DIR__ . '/xero_sync.php';

// CLI vs web: CLI doesn't go through auth_check.php
$isCli = (php_sapi_name() === 'cli');
$isCron = $isCli && (in_array('cron', $argv ?? [], true));
if (!$isCli) {
    require_once __DIR__ . '/auth_check.php';
    if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
        http_response_code(403); die('Admin only.');
    }
}

$pdo     = get_db();
$dryRun  = $isCli ? in_array('dry-run', $argv, true) : !empty($_GET['dry_run']);
$singleInvoice = (int)($_GET['invoice_no'] ?? 0);

// Always sync from Xero before deciding who to remind, so that invoices
// paid since the last sync don't get a reminder. Errors are non-fatal —
// fall through to the existing local view if Xero is unreachable.
$syncResult = run_xero_sync($pdo);

// ── Reminder schedule: days-overdue → reminder index ──────────────────────
$reminderStages = [7, 14, 30];      // also resends on +30 increments after that
$minGapDays     = (int)(meta_get($pdo, 'reminder_min_gap_days') ?: 6);
$cap            = (int)($_ENV['CADVIZ_REMINDER_CAP'] ?? 30);

// ── Pick candidates ────────────────────────────────────────────────────────
// Require Xero_InvoiceID (i.e. the invoice has been pushed) and at least
// one of billing_email / email being non-empty. Address validity is checked
// per-row in the loop via pick_billing_email() so we can skip + report
// invalid addresses cleanly instead of silently dropping them.
$where = "i.Xero_InvoiceID IS NOT NULL
          AND i.Xero_Status = 'AUTHORISED'
          AND i.Xero_AmountDue > 0
          AND i.Xero_DueDate IS NOT NULL
          AND i.Xero_DueDate < CURDATE()
          AND (COALESCE(c.billing_email, '') <> '' OR COALESCE(c.email, '') <> '')";
if ($singleInvoice > 0) $where .= " AND i.Invoice_No = " . (int)$singleInvoice;

// Gate c.Contact behind feature detection so installs that haven't run
// migrations/add_clients_contact.sql don't crash here.
$contactCol = clients_has_contact($pdo) ? 'c.Contact' : 'NULL AS Contact';
$rows = $pdo->query(
    "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.Xero_DueDate, i.Xero_AmountDue,
            i.Xero_OnlineUrl, i.Xero_InvoiceID,
            DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
            c.Client_Name, {$contactCol}, c.billing_email, c.email,
            (SELECT MAX(updated_at) FROM App_Meta WHERE meta_key = CONCAT('reminder_last_', i.Invoice_No)) AS last_reminder_at
       FROM Invoices i
       LEFT JOIN Clients c ON i.Client_ID = c.Client_id
      WHERE $where
      ORDER BY i.Xero_DueDate ASC"
)->fetchAll();

$sent = []; $skipped = [];
foreach ($rows as $r) {
    if (count($sent) >= $cap) break;
    $invNo  = (int)$r['Invoice_No'];
    $days   = (int)$r['days_overdue'];

    // Decide if this is a reminder day. Either we hit a stage exactly, or
    // we're 30+ days past + it's been 30 days since last reminder.
    $isStage = in_array($days, $reminderStages, true) || ($days > 30 && $days % 30 === 0);
    if (!$isStage && $singleInvoice <= 0) {
        $skipped[$invNo] = "not on reminder schedule (overdue $days d)";
        continue;
    }

    // Spam guard
    if ($r['last_reminder_at']) {
        $hoursSince = (time() - strtotime($r['last_reminder_at'])) / 3600;
        if ($hoursSince < ($minGapDays * 24) && $singleInvoice <= 0) {
            $skipped[$invNo] = "reminded " . round($hoursSince) . "h ago";
            continue;
        }
    }

    // Resolve a deliverable address (billing_email then email, both
    // FILTER_VALIDATE_EMAIL'd). Skip if neither is valid — the WHERE
    // already filtered out totally-empty rows, so this catches the
    // "filled in with garbage" case ("No Email - erik" etc).
    $toAddr = pick_billing_email($r['billing_email'] ?? null, $r['email'] ?? null);
    if (!$toAddr) {
        $skipped[$invNo] = 'no valid email on Clients (Billing Email / Email)';
        continue;
    }

    if ($dryRun) { $sent[$invNo] = '(dry-run) would have reminded ' . $toAddr; continue; }

    try {
        sendReminder($pdo, $r, $toAddr);
        meta_set($pdo, 'reminder_last_' . $invNo, date('Y-m-d H:i:s'));
        $sent[$invNo] = 'sent to ' . $toAddr . " ({$days}d overdue)";
    } catch (Exception $e) {
        $skipped[$invNo] = 'FAILED: ' . $e->getMessage();
    }
}

function sendReminder(PDO $pdo, array $r, string $toAddr): void {
    $invNumStr = 'CAD-' . str_pad((string)$r['Invoice_No'], 5, '0', STR_PAD_LEFT);
    $totalIncTax = (float)$r['Subtotal'] * (1 + (float)$r['Tax_Rate']);
    // Greet by Contact first name; fallback to "Valued Customer".
    $name      = client_first_name($r['Contact'] ?? null);
    $days      = (int)$r['days_overdue'];
    $due       = date('d/m/Y', strtotime($r['Xero_DueDate']));
    $amount    = '$' . number_format((float)$r['Xero_AmountDue'], 2);
    $online    = $r['Xero_OnlineUrl'] ?? '';

    $tone = $days >= 30 ? 'firm' : ($days >= 14 ? 'reminder' : 'gentle');

    $text = "Dear {$name},\r\n\r\n";
    if ($tone === 'gentle') {
        $text .= "This is a friendly reminder that invoice {$invNumStr} for {$amount} was due on {$due} ({$days} days ago).\r\n\r\n";
    } elseif ($tone === 'reminder') {
        $text .= "Invoice {$invNumStr} for {$amount} is now {$days} days overdue (due date {$due}).\r\n\r\n";
        $text .= "Please arrange payment at your earliest convenience.\r\n\r\n";
    } else {
        $text .= "Invoice {$invNumStr} for {$amount} is now {$days} days overdue. We would appreciate prompt payment.\r\n\r\n";
        $text .= "If there's an issue with this invoice please reply to this email so we can sort it.\r\n\r\n";
    }
    if ($online) $text .= "View / pay online: {$online}\r\n\r\n";
    $text .= "If you have already paid this invoice, please disregard this email — our records simply haven't caught up with your payment yet.\r\n\r\n";
    $text .= "Kind regards,\r\nCADViz Accounts\r\naccounts@cadviz.co.nz\r\n";

    $html  = "<p>Dear " . htmlspecialchars($name) . ",</p>";
    if ($tone === 'gentle') {
        $html .= "<p>This is a friendly reminder that invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> was due on <strong>{$due}</strong> ({$days} days ago).</p>";
    } elseif ($tone === 'reminder') {
        $html .= "<p>Invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> is now <strong>{$days} days overdue</strong> (due date {$due}).</p>";
        $html .= "<p>Please arrange payment at your earliest convenience.</p>";
    } else {
        $html .= "<p>Invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> is now <strong>{$days} days overdue</strong>. We would appreciate prompt payment.</p>";
        $html .= "<p>If there's an issue with this invoice please reply to this email so we can sort it.</p>";
    }
    if ($online) $html .= "<p><a href=\"" . htmlspecialchars($online) . "\">View / pay invoice online</a></p>";
    $html .= "<p style=\"color:#666\"><em>If you have already paid this invoice, please disregard this email &mdash; our records simply haven't caught up with your payment yet.</em></p>";
    $html .= "<p>Kind regards,<br>CADViz Accounts<br>accounts@cadviz.co.nz</p>";

    SmtpMailer::send([
        'to'       => $toAddr,
        'bcc'      => ['accounts@cadviz.co.nz'],
        'reply_to' => 'accounts@cadviz.co.nz',
        'subject'  => "Reminder: Invoice {$invNumStr} ({$days} days overdue)",
        'text'     => $text,
        'html'     => $html,
    ]);
}

// ── Output ────────────────────────────────────────────────────────────────
if ($isCli) {
    echo "Reminders run @ " . date('c') . ($dryRun ? ' (DRY RUN)' : '') . "\n";
    echo "  xero sync: updated=" . (int)($syncResult['updated'] ?? 0)
       . ", paid_marked=" . (int)($syncResult['paid_marked'] ?? 0) . "\n";
    foreach (($syncResult['errors'] ?? []) as $e) echo "    SYNC ERROR: $e\n";
    echo "  sent:    " . count($sent)    . "\n";
    foreach ($sent    as $no => $msg) echo "    INV $no — $msg\n";
    echo "  skipped: " . count($skipped) . "\n";
    foreach ($skipped as $no => $msg) echo "    INV $no — $msg\n";
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"><title>Send reminders</title></head>
<body><div class="page"><div class="card">
  <h2>Overdue reminders<?= $dryRun ? ' — DRY RUN' : '' ?></h2>
  <p style="color:#246">Xero sync: <strong><?= (int)($syncResult['updated'] ?? 0) ?></strong> invoices refreshed,
     <strong><?= (int)($syncResult['paid_marked'] ?? 0) ?></strong> auto-marked PAID before this run.</p>
  <?php foreach (($syncResult['errors'] ?? []) as $e): ?>
    <p style="color:#c33">Sync warning: <?= htmlspecialchars($e) ?></p>
  <?php endforeach; ?>
  <p><strong><?= count($sent) ?> sent</strong>, <strong><?= count($skipped) ?> skipped</strong>.</p>
  <ul>
    <?php foreach ($sent as $no => $msg): ?>
      <li style="color:#1a6b1a">CAD-<?= str_pad((string)$no, 5, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($msg) ?></li>
    <?php endforeach; ?>
    <?php foreach ($skipped as $no => $msg): ?>
      <li style="color:#888">CAD-<?= str_pad((string)$no, 5, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($msg) ?></li>
    <?php endforeach; ?>
  </ul>
  <p><a href="monthly_invoicing.php" class="btn-primary">Back to monthly invoicing</a></p>
</div></div></body></html>
