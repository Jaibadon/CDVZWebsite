<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'xero_client.php';

$user    = htmlspecialchars($_SESSION['UserID']);
$isAdmin = in_array($_SESSION['UserID'], ['erik', 'jen'], true)
           || (!empty($_SESSION['AccessLevel']) && $_SESSION['AccessLevel'] >= 9);

// Run the dormant-jobs sweep at most once per month, on Erik's first menu
// load of the month. Cheap no-op the rest of the time.
$dormancyResult = null;
if ($isAdmin && ($_SESSION['UserID'] ?? '') === 'erik') {
    try {
        $dormancyResult = dormancy_sweep_if_due(get_db());
    } catch (Exception $e) { /* App_Meta may not exist if migration not run */ }
}

// Xero connection state for menu badges
$xeroConfigured = XeroClient::isConfigured();
$xeroConnected  = $xeroConfigured && XeroClient::isConnected(get_db());
$xeroFlash      = $_SESSION['xero_flash'] ?? '';
$xeroFlashErr   = $_SESSION['xero_flash_err'] ?? '';
unset($_SESSION['xero_flash'], $_SESSION['xero_flash_err']);

// Google SMTP (XOAUTH2) state
require_once 'smtp_oauth.php';
$smtpConfigured = SmtpOAuth::isConfigured();
$smtpConnected  = $smtpConfigured && SmtpOAuth::isConnected(get_db());
$smtpFlash      = $_SESSION['smtp_flash'] ?? '';
unset($_SESSION['smtp_flash']);

// Pending unapproved variations (admin alert)
$pendingVariations = [];
if ($isAdmin) {
    try {
        $pdo = get_db();
        $st = $pdo->query(
            "SELECT v.Variation_ID, v.Proj_ID, v.Variation_Number, v.Title, v.Status, v.Date_Created,
                    v.Created_By, p.JobName, s.Login AS CreatedByLogin
               FROM Project_Variations v
               LEFT JOIN Projects p ON v.Proj_ID = p.proj_id
               LEFT JOIN Staff s ON v.Created_By = s.Employee_ID
              WHERE v.Status IN ('unapproved','draft')
              ORDER BY v.Date_Created DESC, v.Variation_ID DESC"
        );
        $pendingVariations = $st->fetchAll();
    } catch (Exception $e) { /* table may not exist if migration not run */ }
}

// Pending future leave entries (Erik approves these). Only loaded for Erik —
// other admins don't see this list.
$pendingLeave = [];
if (($_SESSION['UserID'] ?? '') === 'erik') {
    try {
        $pdo = get_db();
        $st = $pdo->prepare(
            "SELECT t.TS_ID, t.TS_DATE, t.Hours, t.Task,
                    s.Login AS StaffLogin, s.`First Name` AS StaffFirstName
               FROM Timesheets t
               LEFT JOIN Staff s ON t.Employee_id = s.Employee_ID
              WHERE t.proj_id        = ?
                AND t.TS_DATE        > CURDATE()
                AND t.Leave_Approved = 0
              ORDER BY t.TS_DATE, s.Login"
        );
        $st->execute([LEAVE_PROJECT_ID]);
        $pendingLeave = $st->fetchAll();
    } catch (Exception $e) { /* migration may not be applied yet */ }
}

$leaveFlash    = $_SESSION['leave_flash']     ?? '';
$leaveFlashErr = $_SESSION['leave_flash_err'] ?? '';
unset($_SESSION['leave_flash'], $_SESSION['leave_flash_err']);

// Invoices 30+ days overdue — Erik-only "call them" callout. Pulled fresh
// from the local Xero_* mirror (whichever monthly_invoicing.php / cron sync
// last refreshed). Ordered worst-first so the loudest case is at the top.
$callList = [];
if (($_SESSION['UserID'] ?? '') === 'erik') {
    try {
        $pdo = get_db();
        $st = $pdo->query(
            "SELECT i.Invoice_No, i.Client_ID, i.Xero_AmountDue, i.Xero_DueDate,
                    DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
                    c.Client_Name, c.Phone, c.Mobile
               FROM Invoices i
               LEFT JOIN Clients c ON i.Client_ID = c.Client_id
              WHERE i.Xero_InvoiceID IS NOT NULL
                AND i.Xero_AmountDue > 0
                AND i.Xero_Status = 'AUTHORISED'
                AND i.Xero_DueDate IS NOT NULL
                AND DATEDIFF(CURDATE(), i.Xero_DueDate) >= 30
              ORDER BY days_overdue DESC, i.Xero_AmountDue DESC"
        );
        $callList = $st->fetchAll();
    } catch (Exception $e) { /* Xero columns may not exist on legacy installs */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CADViz – Main Menu</title>
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet" type="text/css">
<link href="global2.css" rel="stylesheet" type="text/css">
<style>
body  { background:#515559; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; margin:0; padding:20px; }
.wrap { max-width:700px; margin:30px auto; background:#EBEBEB; border-radius:4px; overflow:hidden; }
.hdr  { background:#9B9B1B; color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
.hdr h1 { margin:0; font-size:18px; }
.hdr a  { color:#fff; font-size:13px; text-decoration:none; }
.body { padding:20px 30px; }
.body h3 { color:#9B9B1B; border-bottom:1px solid #ccc; padding-bottom:4px; margin-top:20px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; margin-top:10px; }
/* color set via !important because global.css/global2.css have generic
   `a` selectors that override the button color and turn the text orange. */
.btn,
a.btn,
a.btn:link,
a.btn:visited,
a.btn:hover,
a.btn:active { display:block; background:#9B9B1B; color:#fff !important; text-align:center; padding:10px 6px;
        border-radius:3px; text-decoration:none; font-size:13px; }
a.btn:hover { background:#7a7a16; color:#fff !important; }
a.btn.secondary { background:#555; color:#fff !important; }
a.btn.secondary:hover { background:#333; color:#fff !important; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>CADViz – Main Menu</h1>
    <span>Logged in as <strong><?= $user ?></strong> &nbsp;|&nbsp; <a href="logout.php">Logout</a></span>
  </div>
  <div class="body">

    <?php if ($xeroFlash): ?>
      <div style="background:#d6f5d6;border:1px solid #1a6b1a;color:#1a6b1a;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($xeroFlash) ?></div>
    <?php endif; ?>
    <?php if ($xeroFlashErr): ?>
      <div style="background:#ffd6d6;border:1px solid #c33;color:#a00;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($xeroFlashErr) ?></div>
    <?php endif; ?>
    <?php if (!empty($dormancyResult) && ($dormancyResult['projects'] + $dormancyResult['clients']) > 0): ?>
      <div style="background:#fff3cd;border:1px solid #c8a52e;color:#7a5a00;padding:8px 12px;border-radius:4px;margin-bottom:12px">
        Dormancy sweep ran today: deactivated <strong><?= (int)$dormancyResult['projects'] ?></strong> projects and <strong><?= (int)$dormancyResult['clients'] ?></strong> clients with no activity in the last 5 years.
        <a href="dormancy_sweep.php?undo=1">Review / undo</a>
      </div>
    <?php endif; ?>

    <?php if ($leaveFlash): ?>
      <div style="background:#d6f5d6;border:1px solid #1a6b1a;color:#1a6b1a;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($leaveFlash) ?></div>
    <?php endif; ?>
    <?php if ($leaveFlashErr): ?>
      <div style="background:#ffd6d6;border:1px solid #c33;color:#a00;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($leaveFlashErr) ?></div>
    <?php endif; ?>

    <?php if (!empty($callList)):
        $totalOverdue = 0; foreach ($callList as $c) $totalOverdue += (float)$c['Xero_AmountDue'];
    ?>
    <div style="background:#ffe4d6;border:2px solid #c33;border-radius:4px;padding:12px 16px;margin-bottom:16px">
      <h3 style="margin:0 0 6px;color:#a00;border:none">
        📞 <?= count($callList) ?> invoice<?= count($callList) === 1 ? '' : 's' ?> 30+ days overdue &mdash; please give the client a call
      </h3>
      <p style="margin:0 0 6px;font-size:11px;color:#7a2200">
        Total overdue 30+ days: <strong>$<?= number_format($totalOverdue, 2) ?></strong>.
        A phone call recovers far more debt than another email at this point.
        Once you've spoken to them you can re-send the reminder via
        <a href="monthly_invoicing.php" style="color:#a00">Monthly Invoicing</a>.
      </p>
      <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px">
        <thead><tr style="background:#fff3cd"><th style="padding:4px 6px;text-align:left">Invoice</th><th style="padding:4px 6px;text-align:left">Client</th><th style="padding:4px 6px;text-align:left">Phone</th><th style="padding:4px 6px;text-align:right">Amount due</th><th style="padding:4px 6px;text-align:right">Days late</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($callList, 0, 12) as $c):
            $ph = trim((string)($c['Phone'] ?? '')) ?: trim((string)($c['Mobile'] ?? ''));
        ?>
          <tr style="border-bottom:1px solid #fce0bf">
            <td style="padding:4px 6px"><a href="invoice.php?Invoice_No=<?= (int)$c['Invoice_No'] ?>">CAD-<?= str_pad((string)$c['Invoice_No'], 5, '0', STR_PAD_LEFT) ?></a></td>
            <td style="padding:4px 6px"><?= htmlspecialchars($c['Client_Name'] ?? '?') ?></td>
            <td style="padding:4px 6px"><?php if ($ph !== ''): ?><a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $ph)) ?>" style="color:#a00;text-decoration:none">📞 <?= htmlspecialchars($ph) ?></a><?php else: ?><span style="color:#888">no phone on file</span><?php endif; ?></td>
            <td style="padding:4px 6px;text-align:right">$<?= number_format((float)$c['Xero_AmountDue'], 2) ?></td>
            <td style="padding:4px 6px;text-align:right;color:<?= ((int)$c['days_overdue']) >= 60 ? '#a00' : '#7a2200' ?>"><strong><?= (int)$c['days_overdue'] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($callList) > 12): ?>
        <p style="font-size:11px;color:#7a2200;margin-top:6px">+ <?= count($callList) - 12 ?> more not shown &mdash; see <a href="monthly_invoicing.php" style="color:#a00">Monthly Invoicing</a> for the full list.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($pendingLeave)):
        // Group consecutive rows for the same staff member into one bulk
        // approve form so Erik can OK a whole stretch of leave in one click.
        $groupedLeave = [];
        foreach ($pendingLeave as $pl) {
            $login = $pl['StaffLogin'] ?? '?';
            $groupedLeave[$login][] = $pl;
        }
    ?>
    <div style="background:#fff3cd;border:2px solid #c8a52e;border-radius:4px;padding:12px 16px;margin-bottom:16px">
      <h3 style="margin:0 0 6px;color:#7a5a00;border:none">
        ⚠ <?= count($pendingLeave) ?> future leave day<?= count($pendingLeave) === 1 ? '' : 's' ?> waiting for approval
      </h3>
      <p style="margin:0 0 6px;font-size:11px;color:#7a5a00">Project #<?= LEAVE_PROJECT_ID ?> — annual leave / sick. Future dates show red on the staff member's timesheet until approved.</p>
      <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px">
        <?php foreach ($groupedLeave as $login => $entries):
            $tsIds = array_map(fn($e) => (int)$e['TS_ID'], $entries);
            $first = $entries[0];
            $name  = trim((string)($first['StaffFirstName'] ?? '')) ?: $login;
            $totalHours = array_sum(array_column($entries, 'Hours'));
        ?>
          <tr style="border-bottom:1px solid #eee">
            <td style="padding:6px 4px;vertical-align:top">
              <strong><?= htmlspecialchars($name) ?></strong>
              <span style="color:#666;font-size:11px"> (<?= htmlspecialchars($login) ?>)</span>
            </td>
            <td style="padding:6px 4px;vertical-align:top;font-size:11px">
              <?= count($entries) ?> day<?= count($entries) === 1 ? '' : 's' ?> &middot; <?= number_format((float)$totalHours, 1) ?>h total<br>
              <?php
                $dateLabels = array_map(fn($e) => date('D j M', strtotime($e['TS_DATE'])), $entries);
                echo htmlspecialchars(implode(', ', $dateLabels));
              ?>
            </td>
            <td style="padding:6px 4px;vertical-align:top;text-align:right">
              <form method="post" action="approve_leave.php" style="display:inline" onsubmit="return confirm('Approve all <?= count($entries) ?> leave day(s) for <?= htmlspecialchars(addslashes($name)) ?>?');">
                <input type="hidden" name="ts_ids" value="<?= htmlspecialchars(implode(',', $tsIds)) ?>">
                <input type="submit" value="✓ Approve" style="background:#1a6b1a;color:#fff;border:none;padding:4px 10px;border-radius:3px;cursor:pointer;font-size:12px">
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin && !empty($pendingVariations)): ?>
    <div style="background:#fff3cd;border:2px solid #c33;border-radius:4px;padding:12px 16px;margin-bottom:16px">
      <h3 style="margin:0 0 6px;color:#c33;border:none">
        ⚠ <?= count($pendingVariations) ?> unapproved variation<?= count($pendingVariations) === 1 ? '' : 's' ?> waiting for review
      </h3>
      <ul style="margin:6px 0 0;padding-left:18px;font-size:13px">
        <?php foreach ($pendingVariations as $pv): ?>
          <li>
            <a href="project_stages.php?proj_id=<?= (int)$pv['Proj_ID'] ?>#variation-<?= (int)$pv['Variation_ID'] ?>"
               style="color:#c33;font-weight:bold">
              <?= htmlspecialchars($pv['JobName'] ?? 'Project #' . (int)$pv['Proj_ID']) ?>
              — Variation #<?= (int)$pv['Variation_Number'] ?>: <?= htmlspecialchars($pv['Title'] ?? '') ?>
            </a>
            <span style="color:#666;font-size:11px">
              [<?= htmlspecialchars($pv['Status']) ?>]
              <?php if ($pv['CreatedByLogin']): ?>by <?= htmlspecialchars($pv['CreatedByLogin']) ?><?php endif; ?>
              <?php if ($pv['Date_Created']): ?>· <?= date('d/m/Y', strtotime($pv['Date_Created'])) ?><?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <h3>Timesheet</h3>
    <div class="grid">
      <a class="btn" href="main.php">My Timesheet</a>
      <a class="btn" href="my_checklist.php">My Project Checklist</a>
      <?php if ($isAdmin): ?>
        <a class="btn" href="unprocessed.php">Unprocessed Entries</a>
      <?php endif; ?>
      <a class="btn" href="report.php">Reports</a>
    </div>

    <h3>Projects &amp; Clients</h3>
    <div class="grid">
      <a class="btn" href="projects.php">My Projects</a>
      <a class="btn" href="projects_archive1.php">Projects Archive</a>
      <a class="btn" href="clients.php">Clients</a>
    </div>

    <?php if ($isAdmin): ?>
    <h3>Invoicing &amp; Payments</h3>
    <div class="grid">
      <a class="btn" href="invoice_list.php">Invoice List</a>
      <a class="btn" href="invoice_archive.php">Invoice Archive</a>
      <a class="btn" href="payments.php">Payments</a>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <h3>Admin</h3>
    <div class="grid">
      <a class="btn secondary" href="timesheet_admin1.php">Timesheet Admin</a>
      <a class="btn secondary" href="clients_archive.php">Client Archive</a>
      <a class="btn secondary" href="new_client.php">New Client</a>
      <a class="btn secondary" href="project_new.php">New Project</a>
      <a class="btn secondary" href="more.php">More&hellip;</a>
      <a class="btn secondary" href="staff_hours.php">Staff Hours</a>
      <a class="btn secondary" href="monthly_invoicing.php">Monthly Invoicing</a>
      <a class="btn secondary" href="task_analytics.php">Task Analytics</a>
    </div>

    <?php if ($smtpFlash): ?><div style="background:#d6f5d6;border:1px solid #1a6b1a;color:#1a6b1a;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($smtpFlash) ?></div><?php endif; ?>

    <h3>Email (Google SMTP / OAuth)</h3>
    <div class="grid">
      <?php if (!$smtpConfigured): ?>
        <span class="btn secondary" style="background:#999;cursor:default" title="Add GOOGLE_OAUTH_CLIENT_ID + SECRET to config.php">Google SMTP — not configured</span>
      <?php elseif (!$smtpConnected): ?>
        <a class="btn" href="smtp_oauth_connect.php" style="background:#4285F4">Connect Google SMTP</a>
      <?php else: ?>
        <span class="btn secondary" style="background:#1a6b1a;cursor:default" title="Connected as <?= htmlspecialchars(SmtpOAuth::authenticatedUser(get_db())) ?>">Google SMTP ✓</span>
      <?php endif; ?>
    </div>

    <h3>Xero</h3>
    <div class="grid">
      <?php if (!$xeroConfigured): ?>
        <span class="btn secondary" style="background:#999;cursor:default" title="Add XERO_CLIENT_ID + SECRET to config.php">Xero — not configured</span>
      <?php elseif (!$xeroConnected): ?>
        <a class="btn" href="xero_connect.php" style="background:#13B5EA">Connect to Xero</a>
      <?php else: ?>
        <a class="btn secondary" href="monthly_invoicing.php">Xero: Outstanding &amp; Overdue</a>
        <a class="btn secondary" href="xero_sync.php">Sync Xero now</a>
        <a class="btn secondary" href="xero_disconnect.php" style="background:#666">Disconnect Xero</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
