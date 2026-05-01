<?php
/**
 * Variations-only quote printout. Same look as quote.php, but only shows
 * Project_Variations rows (defaults to approved/in_progress/complete; can
 * be expanded by passing ?include_unapproved=1 for an internal review draft).
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    die('Variation printouts are admin-only. Use <a href="my_checklist.php">My Project Checklist</a> instead.');
}

$pdo = get_db();
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) die('Missing proj_id');
// Default: show ALL variations (including unapproved) — pass ?approved_only=1
// to switch to a print-ready approved-only version for the client.
$approvedOnly = !empty($_GET['approved_only']);
$includeUnapproved = !$approvedOnly;

$h = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.Job_Address, p.Job_Description,
            c.Client_Name, c.Multiplier
       FROM Projects p
       LEFT JOIN Clients c ON p.Client_ID = c.Client_id
      WHERE p.proj_id = ?"
);
$h->execute([$proj_id]);
$head = $h->fetch();
if (!$head) die('Project not found');

$multiplier = (float)($head['Multiplier'] ?? 1);
if ($multiplier <= 0) $multiplier = 1;
$baseRate = 90.00;

$statusFilter = $includeUnapproved
    ? "1=1"
    : "Status IN ('approved','in_progress','complete')";

$vs = $pdo->prepare("SELECT * FROM Project_Variations WHERE Proj_ID = ? AND $statusFilter ORDER BY Variation_Number");
$vs->execute([$proj_id]);
$variations = $vs->fetchAll();

if (empty($variations)) die('No variations to display for this project.');

$vIds = array_column($variations, 'Variation_ID');
$in   = implode(',', array_fill(0, count($vIds), '?'));

$vsStmt = $pdo->prepare(
    "SELECT s.Project_Stage_ID, s.Variation_ID, s.Description AS StageDesc,
            COALESCE(s.Weight,1) AS StageWeight, st.Stage_Type_Name, st.Stage_Order
       FROM Project_Stages s
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
      WHERE s.Proj_ID = ? AND s.Variation_ID IN ($in)
      ORDER BY s.Variation_ID, st.Stage_Order"
);
$vsStmt->execute(array_merge([$proj_id], $vIds));
$variationStages = [];
foreach ($vsStmt->fetchAll() as $row) {
    $variationStages[(int)$row['Variation_ID']][] = $row;
}

$vtStmt = $pdo->prepare(
    "SELECT t.Project_Stage_ID, t.Description AS TaskDesc, COALESCE(t.Weight,1) AS TaskWeight,
            tt.Task_Name, tt.Estimated_Time, tt.Fixed_Cost,
            s.`BILLING RATE` AS StaffRate
       FROM Project_Tasks t
       LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
       LEFT JOIN Staff s ON t.Assigned_To = s.Employee_ID
      WHERE t.Variation_ID IN ($in) AND COALESCE(t.Is_Removed,0) = 0
      ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
);
$vtStmt->execute($vIds);
$variationTasksByStage = [];
foreach ($vtStmt->fetchAll() as $t) {
    $variationTasksByStage[(int)$t['Project_Stage_ID']][] = $t;
}

// Removed-task summary: which tasks were taken out of the original quote,
// from which stage, and via which variation. Filtered by approval scope.
$rmStmt = $pdo->prepare(
    "SELECT t.Description AS TaskDesc, COALESCE(t.Weight,1) AS TaskWeight,
            tt.Task_Name, tt.Estimated_Time,
            st.Stage_Type_Name AS RemovedFromStage,
            v.Variation_Number, v.Title AS RemovalVariationTitle, v.Status AS RemovalStatus
       FROM Project_Tasks t
       LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
       LEFT JOIN Project_Stages s ON t.Project_Stage_ID = s.Project_Stage_ID
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
       LEFT JOIN Project_Variations v ON t.Removed_In_Variation_ID = v.Variation_ID
      WHERE s.Proj_ID = ? AND t.Is_Removed = 1"
);
$rmStmt->execute([$proj_id]);
$removedAll = $rmStmt->fetchAll();
// Filter to only removed tasks whose removal-variation is in scope
$shownVarIds = array_column($variations, 'Variation_ID');
$removed = array_filter($removedAll, function($r) use ($shownVarIds) {
    return in_array((int)$r['Variation_Number'], array_column($GLOBALS['variations'] ?? [], 'Variation_Number'), true)
        || in_array($r['Variation_Number'], array_map(fn($v) => $v['Variation_Number'], $shownVarIds ? $GLOBALS['variations'] : []));
});
// simpler: only show removed-tasks whose Removed_In_Variation_ID is in our $variations list
$removed = [];
$visibleVids = [];
foreach ($variations as $vRow) $visibleVids[(int)$vRow['Variation_ID']] = true;
foreach ($removedAll as $r) {
    // Re-query: include if its removal variation is shown
    // (We need Removed_In_Variation_ID — query separately)
}
// Re-do cleanly:
$rmStmt = $pdo->prepare(
    "SELECT t.Removed_In_Variation_ID, t.Description AS TaskDesc, COALESCE(t.Weight,1) AS TaskWeight,
            tt.Task_Name, tt.Estimated_Time,
            st.Stage_Type_Name AS RemovedFromStage,
            v.Variation_Number, v.Title AS RemovalVariationTitle, v.Status AS RemovalStatus
       FROM Project_Tasks t
       LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
       LEFT JOIN Project_Stages s ON t.Project_Stage_ID = s.Project_Stage_ID
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
       LEFT JOIN Project_Variations v ON t.Removed_In_Variation_ID = v.Variation_ID
      WHERE s.Proj_ID = ? AND t.Is_Removed = 1"
);
$rmStmt->execute([$proj_id]);
$removed = [];
foreach ($rmStmt->fetchAll() as $r) {
    if (isset($visibleVids[(int)$r['Removed_In_Variation_ID']])) $removed[] = $r;
}

$grandHours = 0; $grandSub = 0;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Variations Quote — <?= htmlspecialchars($head['JobName']) ?></title>
<link href="site.css" rel="stylesheet">
<style>
@page { size: A4; margin: 18mm 16mm; }
body { font-family: Arial, sans-serif; font-size: 11px; color: #000; padding: 18px; }
.header { display:flex; justify-content:space-between; border-bottom:2px solid #ccc; padding-bottom:6px; margin-bottom:14px; }
.header img { width:140px; }
h1 { color:#9B9B1B; font-size:18px; margin:0 0 6px; }
h2 { color:#9B9B1B; font-size:14px; margin:14px 0 4px; }
table { width:100%; border-collapse:collapse; margin-bottom:6px; }
.quote-table th { background:#f4f4d8; text-align:left; padding:4px 6px; border-bottom:1px solid #ccc; font-size:10px; }
.quote-table td { padding:4px 6px; border-bottom:1px solid #eee; }
.quote-table .right { text-align:right; }
.quote-table .stage-row td { background:#f7f7e0; font-weight:bold; }
.quote-table .subtot td { background:#fff8b3; font-weight:bold; }
.totals td { padding:4px 8px; }
.totals .label { text-align:right; font-weight:bold; }
.totals .right { text-align:right; }
.totals .grand { font-size:13px; }
.totals .grand td { background:#fff8b3; font-weight:bold; padding:6px 8px; }
.var-card { border-left:4px solid #c33; background:#fff8e0; padding:6px 10px; margin-top:14px; }
.var-card.approved { border-left-color:#1a6b1a; background:#e8f5e8; }
.no-print { margin-bottom:10px; }
@media print { .no-print { display:none; } }
</style>
</head>
<body>

<div class="no-print">
  <a href="updateform_admin1.php?proj_id=<?= $proj_id ?>">&larr; Back to project</a> &nbsp;|&nbsp;
  <a href="quote.php?proj_id=<?= $proj_id ?>">Original quote + variations</a> &nbsp;|&nbsp;
  <button onclick="window.print()">🖨 Print</button>
  <?php if ($approvedOnly): ?>
    &nbsp;|&nbsp; <strong>Approved variations only</strong>
    &nbsp;|&nbsp; <a href="?proj_id=<?= $proj_id ?>" style="color:#c33">Switch to all variations (incl. unapproved)</a>
  <?php else: ?>
    &nbsp;|&nbsp; <em style="color:#c33"><strong>Showing ALL variations (including unapproved)</strong></em>
    &nbsp;|&nbsp; <a href="?proj_id=<?= $proj_id ?>&approved_only=1" style="background:#1a6b1a;color:#fff;padding:3px 10px;border-radius:3px;text-decoration:none">Print Approved Variations Only</a>
  <?php endif; ?>
</div>

<div class="header">
  <div><img src="images/logo.png" alt="CADViz" onerror="this.style.display='none'"></div>
  <div style="text-align:right;font-size:10px;color:#666">
    CADViz Limited<br>P: 03 384 0027<br>www.cadviz.co.nz<br>Date: <?= date('d/m/Y') ?>
  </div>
</div>

<h1>Variation <?= $includeUnapproved ? 'Schedule (DRAFT)' : 'Fee Proposal' ?></h1>
<p>
  <strong>Client:</strong> <?= htmlspecialchars($head['Client_Name'] ?? '') ?><br>
  <strong>Project:</strong> <?= htmlspecialchars($head['JobName']) ?> &nbsp; (proj #<?= $proj_id ?>)<br>
  <?php if ($head['Job_Address']): ?><strong>Address:</strong> <?= htmlspecialchars($head['Job_Address']) ?><br><?php endif; ?>
</p>

<?php foreach ($variations as $v):
    $vId = (int)$v['Variation_ID'];
    $vstages = $variationStages[$vId] ?? [];
    $vHours = 0; $vSub = 0;
?>
<div class="var-card <?= htmlspecialchars($v['Status']) ?>">
  <strong>Variation #<?= (int)$v['Variation_Number'] ?>: <?= htmlspecialchars($v['Title']) ?></strong>
  <span style="float:right;font-size:10px;color:#666">Status: <?= htmlspecialchars($v['Status']) ?>
  <?= !empty($v['Date_Approved']) ? '· Approved ' . date('d/m/Y', strtotime($v['Date_Approved'])) : '' ?></span>
  <?php if ($v['Description']): ?><div style="margin-top:4px"><?= nl2br(htmlspecialchars($v['Description'])) ?></div><?php endif; ?>
</div>
<table class="quote-table">
  <tr><th>Task</th><th class="right">Hours</th><th class="right">Rate</th><th class="right">Subtotal</th></tr>
  <?php foreach ($vstages as $vstage):
      $vsid = (int)$vstage['Project_Stage_ID'];
      $vtasks = $variationTasksByStage[$vsid] ?? [];
      $vsHours = 0; $vsSub = 0;
      $vsw = (float)$vstage['StageWeight'];
  ?>
  <tr class="stage-row"><td colspan="4"><?= htmlspecialchars($vstage['Stage_Type_Name'] ?? '') ?> — <?= htmlspecialchars($vstage['StageDesc'] ?? '') ?></td></tr>
  <?php foreach ($vtasks as $t):
      $h = (float)($t['Estimated_Time'] ?? 0) * (float)$t['TaskWeight'];
      $sr = (float)($t['StaffRate'] ?? 0);
      $rate = ($sr > 0 ? $sr : $baseRate) * $multiplier;
      $sub = $h * $rate + (float)($t['Fixed_Cost'] ?? 0);
      $vsHours += $h; $vsSub += $sub;
  ?>
  <tr>
    <td><?= htmlspecialchars($t['TaskDesc'] ?: $t['Task_Name']) ?></td>
    <td class="right"><?= number_format($h, 2) ?></td>
    <td class="right">$<?= number_format($rate, 2) ?></td>
    <td class="right">$<?= number_format($sub, 2) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="subtot">
    <td class="right">Stage subtotal:</td>
    <td class="right"><?= number_format($vsHours, 2) ?></td>
    <td></td>
    <td class="right">$<?= number_format($vsSub, 2) ?></td>
  </tr>
  <?php $vHours += $vsHours; $vSub += $vsSub; endforeach; ?>
</table>
<div style="text-align:right;margin-bottom:14px"><strong>Variation #<?= (int)$v['Variation_Number'] ?> total: <?= number_format($vHours, 2) ?> hrs &nbsp; $<?= number_format($vSub, 2) ?></strong></div>
<?php $grandHours += $vHours; $grandSub += $vSub; endforeach; ?>

<?php if (!empty($removed)): ?>
<h2>Tasks removed from the original quote</h2>
<p style="font-size:11px;color:#555">The following tasks were taken out of the original scope as part of the variations above:</p>
<table class="quote-table">
  <tr><th>Removed task</th><th>From stage</th><th>Removed in</th><th class="right">Hours saved</th></tr>
  <?php foreach ($removed as $rt):
      $rh = (float)($rt['Estimated_Time'] ?? 0) * (float)$rt['TaskWeight'];
      $grandHours -= $rh;
      $grandSub   -= $rh * $baseRate * $multiplier;
  ?>
  <tr>
    <td style="text-decoration:line-through;color:#999"><?= htmlspecialchars($rt['TaskDesc'] ?: $rt['Task_Name']) ?></td>
    <td><?= htmlspecialchars($rt['RemovedFromStage'] ?? '—') ?></td>
    <td><?= $rt['Variation_Number'] ? 'Variation #' . (int)$rt['Variation_Number'] . ': ' . htmlspecialchars($rt['RemovalVariationTitle'] ?? '') : '—' ?></td>
    <td class="right" style="color:#999">−<?= number_format($rh, 2) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p style="font-size:11px;color:#555;font-style:italic">Note: Removed task <em>"xxx"</em> from stage <em>"yyy"</em> &mdash; the original quote total has been reduced by the hours shown.</p>
<?php endif; ?>

<table class="totals">
  <tr><td class="label">Net hours change:</td><td class="right"><?= number_format($grandHours, 2) ?> hrs</td></tr>
  <tr><td class="label">SubTotal:</td><td class="right">$<?= number_format($grandSub, 2) ?></td></tr>
  <tr><td class="label">GST (15%):</td><td class="right">$<?= number_format($grandSub * 0.15, 2) ?></td></tr>
  <tr class="grand"><td class="label">Variations Total (Inc GST):</td><td class="right">$<?= number_format($grandSub * 1.15, 2) ?></td></tr>
</table>

<p style="margin-top:18px;font-size:10px;color:#666">
  This variation schedule supplements the original Fee Proposal for this project. All other terms and conditions of trade remain unchanged.
</p>
</body>
</html>
