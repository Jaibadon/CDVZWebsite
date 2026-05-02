<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$user    = htmlspecialchars($_SESSION['UserID']);
$isAdmin = in_array($_SESSION['UserID'], ['erik', 'jen'], true)
           || (!empty($_SESSION['AccessLevel']) && $_SESSION['AccessLevel'] >= 9);

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

    <h3>Xero</h3>
    <div class="grid">
      <?php
        require_once 'xero_client.php';
        $xeroConfigured = XeroClient::isConfigured();
        $xeroConnected  = $xeroConfigured && XeroClient::isConnected($pdo);
      ?>
      <?php if (!$xeroConfigured): ?>
        <span class="btn secondary" style="background:#999;cursor:default" title="Add XERO_CLIENT_ID + SECRET to config.php">Xero — not configured</span>
      <?php elseif (!$xeroConnected): ?>
        <a class="btn secondary" href="xero_connect.php" style="background:#c33">Connect to Xero</a>
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
