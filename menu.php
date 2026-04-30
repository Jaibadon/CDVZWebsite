<?php
require_once 'auth_check.php';

$user    = htmlspecialchars($_SESSION['UserID']);
$isAdmin = in_array($_SESSION['UserID'], ['erik', 'jen'], true)
           || (!empty($_SESSION['AccessLevel']) && $_SESSION['AccessLevel'] >= 9);
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
.btn  { display:block; background:#9B9B1B; color:#fff; text-align:center; padding:10px 6px;
        border-radius:3px; text-decoration:none; font-size:13px; }
.btn:hover { background:#7a7a16; }
.btn.secondary { background:#555; }
.btn.secondary:hover { background:#333; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>CADViz – Main Menu</h1>
    <span>Logged in as <strong><?= $user ?></strong> &nbsp;|&nbsp; <a href="logout.php">Logout</a></span>
  </div>
  <div class="body">

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
    <?php endif; ?>

  </div>
</div>
</body>
</html>
