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

// Google Drive (separate OAuth grant — used for keynotes editor + future
// server-side Drive file ops like PDF proxying for magic-link reviews)
require_once 'drive_client.php';
$driveConfigured = DriveClient::isConfigured();
$driveConnected  = $driveConfigured;
try { $driveConnected = $driveConfigured && DriveClient::isConnected(get_db()); } catch (Exception $e) { $driveConnected = false; }
$driveFlash      = $_SESSION['drive_flash'] ?? '';
$driveFlashErr   = $_SESSION['drive_flash_err'] ?? '';
unset($_SESSION['drive_flash'], $_SESSION['drive_flash_err']);

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
                AND COALESCE(i.Paid, 0) = 0  /* skip invoices Akahu (or a manual tick) has already marked paid locally */
              ORDER BY days_overdue DESC, i.Xero_AmountDue DESC"
        );
        $callList = $st->fetchAll();
    } catch (Exception $e) { /* Xero columns may not exist on legacy installs */ }
}

// ── DMS: commits with un-acknowledged transmittals > 7 days old ─────────
// The loop-closing visibility: a revision was sent to a 3rd party for
// review and they still haven't acknowledged it a week later. Feature-
// detected (Transmittals table may not exist if the DMS migration hasn't
// been run) so the menu still loads on installs that haven't adopted it.
$unackedCommits = [];
if ($isAdmin) {
    try {
        $st = $pdo->query(
            "SELECT t.Commit_ID, t.Transmittal_ID, t.Sent_At,
                    cm.Revision_Label, cm.Proj_ID,
                    p.JobName,
                    COUNT(tr.Recipient_ID) AS recips,
                    SUM(CASE WHEN tr.Acked_At IS NULL THEN 1 ELSE 0 END) AS unacked,
                    DATEDIFF(NOW(), MIN(CASE WHEN tr.Acked_At IS NULL THEN tr.Sent_At END)) AS oldest_days
               FROM Transmittals t
               INNER JOIN Transmittal_Recipients tr ON tr.Transmittal_ID = t.Transmittal_ID
               INNER JOIN Commits  cm ON t.Commit_ID = cm.Commit_ID
               LEFT  JOIN Projects p  ON cm.Proj_ID  = p.proj_id
              WHERE tr.Acked_At IS NULL
                AND tr.Sent_At < (NOW() - INTERVAL 7 DAY)
              GROUP BY t.Transmittal_ID
              HAVING unacked > 0
              ORDER BY oldest_days DESC"
        );
        $unackedCommits = $st->fetchAll();
    } catch (Exception $e) { /* DMS tables not present yet — skip the panel */ }
}

// ── Bank-feed reconciliation alerts (Akahu, alongside Xero) ──────────────
// Two separate panels. Suppressed entirely when the migration hasn't run
// or when Akahu hasn't been connected yet — the page should still load
// for installs that haven't adopted bank feeds.
$bankNeedsReconciling = [];
$bankPartialOverdue   = [];
$bankAlertsAvailable  = false;
if (in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    try {
        $bankAlertsAvailable = (bool)$pdo->query("SHOW COLUMNS FROM Invoices LIKE 'AmountPaid'")->fetch();
    } catch (Exception $e) {}
    if ($bankAlertsAvailable) {
        try {
            require_once __DIR__ . '/bankfeed_match.php';
            $det = detect_unreconciled($pdo);
            $bankNeedsReconciling = $det['needs_reconciling'] ?? [];
            $bankPartialOverdue   = $det['partial_overdue']   ?? [];
        } catch (Exception $e) {}
    }
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

    <?php if ($isAdmin && !empty($unackedCommits)):
        $worst = (int)($unackedCommits[0]['oldest_days'] ?? 0);
    ?>
    <div style="background:#ffe4d6;border:2px solid #c33;border-radius:4px;padding:12px 16px;margin-bottom:16px">
      <h3 style="margin:0 0 6px;color:#a00;border:none">
        🔔 <?= count($unackedCommits) ?> transmittal<?= count($unackedCommits) === 1 ? '' : 's' ?> still un-acknowledged after 7+ days
      </h3>
      <p style="margin:0 0 6px;font-size:11px;color:#7a2200">
        A revision was sent to a 3rd party for review and they haven't acknowledged it.
        Oldest is <strong><?= $worst ?> days</strong> waiting. Chase them — an un-acked revision
        going to council is exactly the coordination gap this system exists to stop.
      </p>
      <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px">
        <thead><tr style="background:#fff3cd">
          <th style="padding:4px 6px;text-align:left">Project · Rev</th>
          <th style="padding:4px 6px;text-align:right">Un-acked</th>
          <th style="padding:4px 6px;text-align:right">Oldest</th>
          <th style="padding:4px 6px;text-align:left"></th>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($unackedCommits, 0, 15) as $uc): ?>
          <tr style="border-bottom:1px solid #fce0bf">
            <td style="padding:4px 6px">
              <?= htmlspecialchars($uc['JobName'] ?? 'Project #' . (int)$uc['Proj_ID']) ?>
              · Rev <?= htmlspecialchars(trim((string)($uc['Revision_Label'] ?? '')) ?: '#' . (int)$uc['Commit_ID']) ?>
            </td>
            <td style="padding:4px 6px;text-align:right"><strong><?= (int)$uc['unacked'] ?></strong> of <?= (int)$uc['recips'] ?></td>
            <td style="padding:4px 6px;text-align:right;color:<?= ((int)$uc['oldest_days']) >= 14 ? '#a00' : '#7a2200' ?>"><strong><?= (int)$uc['oldest_days'] ?>d</strong></td>
            <td style="padding:4px 6px"><a href="commit_detail.php?commit_id=<?= (int)$uc['Commit_ID'] ?>" style="color:#a00;font-weight:bold">view / chase →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($unackedCommits) > 15): ?>
        <p style="font-size:11px;color:#7a2200;margin-top:6px">+ <?= count($unackedCommits) - 15 ?> more.</p>
      <?php endif; ?>
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
      <a class="btn" href="clients_view.php">Clients</a>
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
    <?php if ($driveFlash): ?><div style="background:#d6f5d6;border:1px solid #1a6b1a;color:#1a6b1a;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($driveFlash) ?></div><?php endif; ?>
    <?php if ($driveFlashErr): ?><div style="background:#ffd6d6;border:1px solid #c33;color:#a00;padding:8px 12px;border-radius:4px;margin-bottom:12px"><?= htmlspecialchars($driveFlashErr) ?></div><?php endif; ?>

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

    <h3>Google Drive (for keynotes editor + project files)</h3>
    <div class="grid">
      <?php if (!$driveConfigured): ?>
        <span class="btn secondary" style="background:#999;cursor:default" title="Add GOOGLE_OAUTH_DRIVE_REDIRECT_URI to config.php and run migrations/add_drive_oauth.sql">Google Drive — not configured</span>
      <?php elseif (!$driveConnected): ?>
        <a class="btn" href="drive_oauth_connect.php" style="background:#0F9D58">Connect Google Drive</a>
      <?php else: ?>
        <span class="btn secondary" style="background:#1a6b1a;cursor:default" title="Connected as <?= htmlspecialchars(DriveClient::authenticatedUser(get_db())) ?>">Google Drive ✓</span>
        <a class="btn secondary" href="drive_oauth_disconnect.php" style="background:#666">Disconnect Drive</a>
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

    <?php if ($bankAlertsAvailable): ?>
    <h3>Bank feed (Akahu) — alongside Xero</h3>
    <div class="grid">
      <a class="btn secondary" href="akahu_connect.php">Akahu connection</a>
      <a class="btn secondary" href="akahu_sync.php">Sync transactions now</a>
      <a class="btn secondary" href="bankfeed_reconcile.php">Reconciliation queue</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($bankNeedsReconciling)):
        $totalUnreconciled = 0; foreach ($bankNeedsReconciling as $r) $totalUnreconciled += (float)$r['amount_paid'];
    ?>
    <div style="background:#fff3cd;border:2px solid #c8a52e;border-radius:4px;padding:12px 16px;margin-top:16px">
      <h3 style="margin:0 0 6px;color:#7a5a00;border:none">
        🔄 <?= count($bankNeedsReconciling) ?> invoice<?= count($bankNeedsReconciling) === 1 ? '' : 's' ?> need reconciling in Xero
      </h3>
      <p style="margin:0 0 6px;font-size:11px;color:#7a5a00">
        Akahu sees bank evidence covering <strong>$<?= number_format($totalUnreconciled, 2) ?></strong> total, but Xero still has these as <em>AUTHORISED</em>.
        These are already marked <strong>Paid locally</strong> (so the reminder cron won't chase the client) — but Xero's books still need the matching reconciliation.
        After you reconcile in Xero, the alert clears on the next xero_sync.
      </p>
      <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px">
        <thead><tr style="background:#fff8de"><th style="padding:4px 6px;text-align:left">Invoice</th><th style="padding:4px 6px;text-align:left">Client</th><th style="padding:4px 6px;text-align:right">Bank evidence</th><th style="padding:4px 6px;text-align:right">Invoice gross</th><th style="padding:4px 6px;text-align:left">Xero</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($bankNeedsReconciling, 0, 12) as $r): ?>
          <tr style="border-bottom:1px solid #fce8aa">
            <td style="padding:4px 6px"><a href="invoice.php?Invoice_No=<?= (int)$r['Invoice_No'] ?>">CAD-<?= str_pad((string)$r['Invoice_No'], 5, '0', STR_PAD_LEFT) ?></a></td>
            <td style="padding:4px 6px"><?= htmlspecialchars($r['Client_Name'] ?? '?') ?></td>
            <td style="padding:4px 6px;text-align:right">$<?= number_format((float)$r['amount_paid'], 2) ?></td>
            <td style="padding:4px 6px;text-align:right">$<?= number_format((float)$r['gross'], 2) ?></td>
            <td style="padding:4px 6px;color:#a05a00"><?= htmlspecialchars((string)($r['Xero_Status'] ?? '?')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($bankNeedsReconciling) > 12): ?>
        <p style="font-size:11px;color:#7a5a00;margin-top:6px">+ <?= count($bankNeedsReconciling) - 12 ?> more — see <a href="bankfeed_reconcile.php" style="color:#7a5a00">Reconciliation queue</a> for the full list.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($bankPartialOverdue)):
        $totalShortfall = 0; foreach ($bankPartialOverdue as $r) $totalShortfall += (float)$r['remaining'];
    ?>
    <div style="background:#ffe4d6;border:2px solid #c33;border-radius:4px;padding:12px 16px;margin-top:12px">
      <h3 style="margin:0 0 6px;color:#a00;border:none">
        💰 <?= count($bankPartialOverdue) ?> overdue invoice<?= count($bankPartialOverdue) === 1 ? '' : 's' ?> with PARTIAL payment received
      </h3>
      <p style="margin:0 0 6px;font-size:11px;color:#7a2200">
        The bank shows part-payment but the balance is still owed and the due date has passed. Total outstanding: <strong>$<?= number_format($totalShortfall, 2) ?></strong>.
        Send a "thanks for the partial payment" follow-up, OR write the shortfall off as a credit (negative line + Xero credit note).
      </p>
      <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px">
        <thead><tr style="background:#fff3cd"><th style="padding:4px 6px;text-align:left">Invoice</th><th style="padding:4px 6px;text-align:left">Client</th><th style="padding:4px 6px;text-align:right">Paid</th><th style="padding:4px 6px;text-align:right">Owing</th><th style="padding:4px 6px;text-align:right">Days late</th><th style="padding:4px 6px;text-align:left">Action</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($bankPartialOverdue, 0, 12) as $r): ?>
          <tr style="border-bottom:1px solid #fce0bf">
            <td style="padding:4px 6px"><a href="invoice.php?Invoice_No=<?= (int)$r['Invoice_No'] ?>">CAD-<?= str_pad((string)$r['Invoice_No'], 5, '0', STR_PAD_LEFT) ?></a></td>
            <td style="padding:4px 6px"><?= htmlspecialchars($r['Client_Name'] ?? '?') ?></td>
            <td style="padding:4px 6px;text-align:right">$<?= number_format((float)$r['amount_paid'], 2) ?></td>
            <td style="padding:4px 6px;text-align:right;color:#a00"><strong>$<?= number_format((float)$r['remaining'], 2) ?></strong></td>
            <td style="padding:4px 6px;text-align:right;color:#a00"><strong><?= (int)$r['days_overdue'] ?></strong></td>
            <td style="padding:4px 6px;font-size:11px">
              <form method="post" action="partial_payment_action.php" style="display:inline" onsubmit="return confirm('Send the &quot;thank you for the partial payment, balance still owed&quot; email to this client?');">
                <input type="hidden" name="action" value="send_thanks">
                <input type="hidden" name="invoice_no" value="<?= (int)$r['Invoice_No'] ?>">
                <button type="submit" style="background:#9B9B1B;color:#fff;border:none;padding:2px 8px;border-radius:3px;cursor:pointer;font-size:11px">✉ Send thanks</button>
              </form>
              <form method="post" action="partial_payment_action.php" style="display:inline" onsubmit="return confirm('Write off the remaining $<?= number_format((float)$r['remaining'], 2) ?> as a CREDIT?\n\nThis will:\n• Add a negative credit line to the local invoice (description: \&quot;credit\&quot;).\n• Create + allocate a credit note in Xero (if connected).\n\nIrreversible — only do this if you\'ve agreed with the client to accept the partial payment as full settlement.');">
                <input type="hidden" name="action" value="credit_shortfall">
                <input type="hidden" name="invoice_no" value="<?= (int)$r['Invoice_No'] ?>">
                <input type="hidden" name="amount"     value="<?= number_format((float)$r['remaining'], 2, '.', '') ?>">
                <button type="submit" style="background:#c33;color:#fff;border:none;padding:2px 8px;border-radius:3px;cursor:pointer;font-size:11px;margin-left:4px">✂ Credit shortfall</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($bankPartialOverdue) > 12): ?>
        <p style="font-size:11px;color:#a00;margin-top:6px">+ <?= count($bankPartialOverdue) - 12 ?> more — see <a href="bankfeed_reconcile.php" style="color:#a00">Reconciliation queue</a>.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

  </div>
</div>
</body>
</html>
