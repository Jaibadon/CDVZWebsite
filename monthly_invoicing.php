<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'xero_client.php';

$userID = $_SESSION['UserID'];

// Only allow access for authorised users (mirrors original ASP logic)
$allowed = ['erik', 'jen'];
if (!in_array($userID, $allowed)) {
    echo "<p>Access denied.</p>";
    exit;
}

$pdo = get_db();
$xeroConnected = XeroClient::isConfigured() && XeroClient::isConnected($pdo);

// ── Start / stop automatic reminders for a CLIENT (all of their invoices) ──
// xero_send_reminders.php now requires explicit OPT-IN, but the flag is
// PER-CLIENT (App_Meta['reminder_started_client_<Client_ID>']). Flipping it
// on for any one of a client's overdue invoices covers ALL of that client's
// overdue invoices — current and future — until Erik clicks Stop again.
// We do this client-wide so the statement-batch path (which bundles every
// overdue invoice for a client into a single email) doesn't leave invoices
// out: if it covered only the opted-in subset, the resulting email would
// say "Statement balance: $X" while the client's actual balance was higher.
// Manual ?invoice_no=N reminders still bypass opt-in for one-offs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_reminders') {
    $cid = (int)($_POST['client_id'] ?? 0);
    if ($cid > 0) {
        $key  = 'reminder_started_client_' . $cid;
        $cur  = meta_get($pdo, $key);
        if (!empty($cur)) {
            try { $pdo->prepare("DELETE FROM App_Meta WHERE meta_key = ?")->execute([$key]); } catch (Exception $e) {}
        } else {
            meta_set($pdo, $key, 'started by ' . $userID . ' on ' . date('Y-m-d'));
        }
    }
    header('Location: monthly_invoicing.php');
    exit;
}

// Pull the follow-up list: invoices that are AUTHORISED in Xero with money
// still owing past the due date.
$followups = [];
if ($xeroConnected) {
    try {
        $followups = $pdo->query(
            "SELECT i.Invoice_No, i.Date, i.Subtotal, i.Notes, i.Order_No_INV,
                    i.Xero_Status, i.Xero_AmountDue, i.Xero_AmountPaid, i.Xero_DueDate,
                    i.Xero_LastSynced, i.Xero_OnlineUrl,
                    DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
                    c.Client_Name, c.billing_email, c.Phone
               FROM Invoices i
               LEFT JOIN Clients c ON i.Client_ID = c.Client_id
              WHERE i.Xero_InvoiceID IS NOT NULL
                AND i.Xero_Status = 'AUTHORISED'
                AND i.Xero_AmountDue > 0
                AND (i.Xero_DueDate IS NULL OR i.Xero_DueDate < CURDATE())
              ORDER BY i.Xero_DueDate ASC, i.Invoice_No DESC"
        )->fetchAll();
    } catch (Exception $e) { /* migration may not be run */ }
}

$flash    = $_SESSION['xero_flash']     ?? '';
$flashErr = $_SESSION['xero_flash_err'] ?? '';
unset($_SESSION['xero_flash'], $_SESSION['xero_flash_err']);
?>
<script language="Javascript">
    document.onkeydown = function() {
        if (event.keyCode == 13) {
            window.location = "more.php"
        }
    }
</script>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<title>Invoicing</title>
<link href="site.css" rel="stylesheet">
<link href="global2.css" rel="stylesheet" type="text/css" />
<basefont face="arial">
<style type="text/css">
.style1 { background-color: #9B9B1B; }
</style>
</head>
<body bgcolor="#EBEBEB" text="black">

<?php if ($flash): ?><div style="max-width:900px;margin:10px auto;padding:8px 12px;background:#d6f5d6;color:#1a6b1a;border-radius:4px"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div style="max-width:900px;margin:10px auto;padding:8px 12px;background:#ffd6d6;color:#a00;border-radius:4px"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table8">
  <tr>
    <td>
      <table border="0" cellspacing="0" width="644" cellpadding="0" id="table9" class="style1">
        <tr>
          <td align="center" colspan="4" height="26">
            <p align="left"><b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;INVOICING FOR LAST 12 MONTHS</font></b></td>
          <td align="center" colspan="3" height="26">
            <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
        </tr>
        <tr>
          <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
          <td align="center"><a href="main.php"><font color="#FFFFFF">My Timesheet</font></a></td>
          <td align="center"><a href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
          <td align="center" colspan="2"><a href="report.php"><font color="#FFFFFF">Reports</font></a></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
        <tr>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center"></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;
            <?php if ($userID === 'erik' || $userID === 'jen'): ?>
              <a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>
            <?php endif; ?>
          </td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
      </table>
    </td>
  </tr>
</table>
<p></p>

<?php
// Last 12 months of invoices grouped by year/month
$sql = "SELECT YEAR(`date`) AS yr, MONTH(`date`) AS mnth, SUM(subtotal) AS subtotal
        FROM Invoices
        WHERE `date` > DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY YEAR(`date`), MONTH(`date`)
        ORDER BY YEAR(`date`), MONTH(`date`)";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grand = 0.0;
foreach ($rows as $row) {
    $tot    = (float) $row['subtotal'];
    $grand += $tot;
    echo '<br>';
    echo htmlspecialchars($row['yr']) . '/' . htmlspecialchars($row['mnth']);
    echo '&nbsp;=&nbsp;';
    echo '$' . number_format($tot, 2);
}
echo '<br><strong>GRAND TOTAL [excl]......$' . number_format($grand, 2) . '</strong>';

// ── Xero overdue follow-up list ───────────────────────────────────────
require_once 'xero_client.php';
$xeroConnected = XeroClient::isConnected($pdo);
$overdue = [];
$lastSync = null;
if ($xeroConnected) {
    try {
        // Note: actual Clients columns are `Phone`, `Mobile`, `email`,
        // `Billing_Email`. The previous query used `c.Phone_1` which
        // doesn't exist — error was silently swallowed by the catch
        // below, leaving $overdue=[] and the table never rendering.
        $st = $pdo->query(
            "SELECT i.Invoice_No, i.Client_ID, i.Xero_Status, i.Xero_AmountDue, i.Xero_DueDate, i.Xero_OnlineUrl,
                    i.Xero_LastSynced,
                    c.Client_Name, c.Phone, c.Mobile, c.email, c.billing_email
               FROM Invoices i
               LEFT JOIN Clients c ON i.Client_ID = c.Client_id
              WHERE i.Xero_InvoiceID IS NOT NULL
                AND i.Xero_AmountDue > 0
                AND i.Xero_Status IN ('AUTHORISED','SUBMITTED')
              ORDER BY i.Xero_DueDate ASC"
        );
        $overdue = $st->fetchAll();
    } catch (Exception $e) {
        // Surface the failure instead of silently rendering empty.
        $_SESSION['xero_flash_err'] = 'Overdue list query failed: ' . $e->getMessage();
        $flashErr = $_SESSION['xero_flash_err']; unset($_SESSION['xero_flash_err']);
    }
    try {
        $lastSync = $pdo->query("SELECT MAX(Xero_LastSynced) FROM Invoices")->fetchColumn();
    } catch (Exception $e) {}
}
?>

<div class="page" style="max-width:900px;margin-top:20px">
  <div class="card">
    <h2 style="margin-top:0">Xero — Outstanding & Overdue Invoices</h2>
    <?php if (!XeroClient::isConfigured()): ?>
      <p style="color:#a00">Xero not configured. Add XERO_CLIENT_ID / XERO_CLIENT_SECRET to config.php (see config.xero.sample.php).</p>
    <?php elseif (!$xeroConnected): ?>
      <p>Not connected. <a href="xero_connect.php" class="btn-primary">Connect to Xero</a> (Erik must complete the OAuth consent).</p>
    <?php else: ?>
      <p>
        <a href="xero_sync.php" class="btn-primary">🔄 Sync from Xero now</a>
        <?php if ($lastSync): ?>
          <span style="color:#666;font-size:11px;margin-left:10px">Last synced: <?= htmlspecialchars($lastSync) ?> UTC</span>
        <?php endif; ?>
      </p>
      <?php if (empty($overdue)): ?>
        <p style="color:#1a6b1a">✓ Nothing outstanding. Great.</p>
      <?php else: ?>
        <p style="font-size:11px;color:#555">
          Automatic reminders are <strong>opt-in</strong>. Click
          <strong>🔔 Start reminders</strong> on a row to let cron email
          that client on the 7 / 14 / 30 / 45 / 60-day overdue stages
          (60d is the final notice and the hard-stop &mdash; cron does
          not auto-send after that).
          The per-row <strong>✉ Send reminder</strong> button always
          works regardless of opt-in &mdash; useful for one-off chases
          after a phone call.
          <br>
          When a client has <em>multiple</em> overdue invoices they get
          a single overdue-statement email covering all of them, instead
          of N individual reminders.
          <br>
          <a href="xero_send_reminders.php?dry_run=1" target="_blank">Preview reminder run (dry-run)</a>
          &nbsp;|&nbsp;
          <a href="xero_send_reminders.php" target="_blank" onclick="return confirm('Send overdue reminders to ALL clients due for one right now?\n\nXero is synced first, paid invoices are skipped, opt-in flag is required, and each email asks the client to disregard if already paid. Continue?');">Run reminder batch now</a>
          <br>
          <strong style="color:#7a5a00">Test mode (no real client emails):</strong>
          <a href="xero_send_reminders.php?test=1" target="_blank">Run with diversion only</a>
          &middot;
          <a href="xero_send_reminders.php?test=1&force_tone=gentle"    target="_blank">7d / gentle</a>
          &middot;
          <a href="xero_send_reminders.php?test=1&force_tone=reminder"  target="_blank">14d / reminder</a>
          &middot;
          <a href="xero_send_reminders.php?test=1&force_tone=firm"      target="_blank">30d / firm</a>
          &middot;
          <a href="xero_send_reminders.php?test=1&force_tone=very_firm" target="_blank">45d / very_firm</a>
          &middot;
          <a href="xero_send_reminders.php?test=1&force_tone=final"     target="_blank">60d / final</a>
        </p>
        <table class="table">
          <tr><th>Invoice</th><th>Client</th><th>Phone</th><th>Email</th><th>Due</th><th class="right">Amount Due</th><th>Status</th><th>Last reminder</th><th>Action</th></tr>
          <?php foreach ($overdue as $od):
              $isOverdue    = $od['Xero_DueDate'] && $od['Xero_DueDate'] < date('Y-m-d');
              $lastReminder = meta_get($pdo, 'reminder_last_'           . (int)$od['Invoice_No']);
              $startedFlag  = meta_get($pdo, 'reminder_started_client_' . (int)$od['Client_ID']);
              $isStarted    = !empty($startedFlag);
          ?>
            <tr<?= $isOverdue ? ' style="background:#ffd6d6"' : '' ?>>
              <td>CAD-<?= str_pad((string)$od['Invoice_No'], 5, '0', STR_PAD_LEFT) ?>
                <?php if ($isStarted): ?>
                  <br><span style="background:#1a6b1a;color:#fff;padding:1px 5px;border-radius:3px;font-size:9px" title="Client-wide opt-in: <?= htmlspecialchars($startedFlag) ?>">AUTO-REMINDERS ON (client)</span>
                <?php else: ?>
                  <br><span style="background:#888;color:#fff;padding:1px 5px;border-radius:3px;font-size:9px" title="No automatic reminders are being sent for this client. Default state. Click 'Start reminders' on any of this client's invoices to enable for ALL of them.">auto-reminders off</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($od['Client_Name'] ?? '?') ?></td>
              <td><?php
                  $ph = trim((string)($od['Phone'] ?? '')) ?: trim((string)($od['Mobile'] ?? ''));
                  echo htmlspecialchars($ph);
              ?></td>
              <td><?php
                  $em = trim((string)($od['billing_email'] ?? '')) ?: trim((string)($od['email'] ?? ''));
                  if ($em !== '') echo '<a href="mailto:' . htmlspecialchars($em) . '">' . htmlspecialchars($em) . '</a>';
              ?></td>
              <td><?= $od['Xero_DueDate'] ? date('d/m/Y', strtotime($od['Xero_DueDate'])) : '?' ?>
                <?php if ($isOverdue): ?><br><strong style="color:#a00">OVERDUE</strong><?php endif; ?></td>
              <td class="right">$<?= number_format((float)$od['Xero_AmountDue'], 2) ?></td>
              <td><?= htmlspecialchars($od['Xero_Status']) ?></td>
              <td style="font-size:11px"><?= $lastReminder ? date('d/m/Y', strtotime($lastReminder)) : '<span style="color:#999">—</span>' ?></td>
              <td>
                <?php if ($od['Xero_OnlineUrl']): ?>
                  <a href="<?= htmlspecialchars($od['Xero_OnlineUrl']) ?>" target="_blank" style="font-size:11px">Online</a> ·
                <?php endif; ?>
                <a href="invoice.php?Invoice_No=<?= (int)$od['Invoice_No'] ?>" style="font-size:11px">Local</a>
                <br>
                <a href="xero_send_reminders.php?invoice_no=<?= (int)$od['Invoice_No'] ?>"
                   onclick="return confirm('Send overdue reminder for CAD-<?= str_pad((string)$od['Invoice_No'], 5, '0', STR_PAD_LEFT) ?> now from accounts@cadviz.co.nz?\n\nXero is re-synced first so a just-paid invoice will be skipped. The email asks the client to disregard if already paid. Continue?');"
                   style="background:#9B9B1B;color:#fff;padding:2px 8px;border-radius:3px;text-decoration:none;font-size:11px">✉ Send reminder</a>
                <form method="post" action="monthly_invoicing.php" style="display:inline;margin-left:4px"
                      onsubmit="return confirm('<?= $isStarted ? 'Stop' : 'Start' ?> automatic reminders for ALL of <?= htmlspecialchars(addslashes((string)($od['Client_Name'] ?? 'this client')), ENT_QUOTES) ?>’s overdue invoices?\n\n<?= $isStarted ? 'This switches OFF automatic reminders for every overdue invoice this client has — current and future. The per-row Send reminder button still works.' : 'This switches ON automatic reminders for every overdue invoice this client has — current and future. Cron will send on the 7/14/30/45/60-day stages until paid (or until day 60 hard-stop). The per-row Send reminder button always works regardless.' ?>');">
                  <input type="hidden" name="action" value="toggle_reminders">
                  <input type="hidden" name="client_id" value="<?= (int)$od['Client_ID'] ?>">
                  <input type="submit" value="<?= $isStarted ? '🔕 Stop reminders (client)' : '🔔 Start reminders (client)' ?>"
                         style="background:<?= $isStarted ? '#666' : '#1a6b1a' ?>;color:#fff;border:none;padding:2px 6px;border-radius:3px;cursor:pointer;font-size:10px">
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p style="font-size:11px;color:#666;margin-top:6px">Once paid, run "Sync from Xero now" again — paid invoices drop off this list automatically. Disable Xero's built-in auto-reminders so clients don't get duplicate emails.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
