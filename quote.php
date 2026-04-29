<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo = get_db();
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) die('Missing proj_id');

$showMoney = !isset($_GET['nomoney']);  // checklist.php sets this

// ── Project + client header ───────────────────────────────────────────────
$h = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.Job_Address, p.Job_Description, p.Project_Type,
            c.Client_Name, c.Multiplier,
            ptype.Project_Type_Name
       FROM Projects p
       LEFT JOIN Clients       c     ON p.Client_ID    = c.Client_id
       LEFT JOIN Project_Types ptype ON p.Project_Type = ptype.Project_Type_ID
      WHERE p.proj_id = ?"
);
$h->execute([$proj_id]);
$head = $h->fetch();
if (!$head) die('Project not found');

$multiplier = (float)($head['Multiplier'] ?? 1);
if ($multiplier <= 0) $multiplier = 1;

// CADViz base hourly rate (from quote sample)
$baseRate = 90.00;

// ── Load stages + tasks ────────────────────────────────────────────────────
$stages = $pdo->prepare(
    "SELECT s.Project_Stage_ID, s.Stage_Type_ID, s.Description AS StageDesc, s.Weight AS StageWeight, st.Stage_Type_Name, st.Stage_Order
       FROM Project_Stages s
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
      WHERE s.Proj_ID = ?
      ORDER BY st.Stage_Order, s.Project_Stage_ID"
);
$stages->execute([$proj_id]);
$stages = $stages->fetchAll();

$tasksStmt = $pdo->prepare(
    "SELECT t.Project_Stage_ID, t.Description AS TaskDesc, t.Weight AS TaskWeight, t.Proj_Task_Order,
            tt.Task_Name, tt.Estimated_Time, tt.Fixed_Cost
       FROM Project_Tasks t
       LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
      WHERE t.Project_Stage_ID IN (SELECT Project_Stage_ID FROM Project_Stages WHERE Proj_ID = ?)
      ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
);
$tasksStmt->execute([$proj_id]);
$tasksByStage = [];
foreach ($tasksStmt->fetchAll() as $t) {
    $tasksByStage[$t['Project_Stage_ID']][] = $t;
}

$grandHours = 0.0;
$grandSub   = 0.0;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= $showMoney ? 'Fee Proposal — ' : 'Project Checklist — ' ?><?= htmlspecialchars($head['JobName'] ?? '') ?></title>
<style>
@page { size: A4; margin: 18mm 16mm; }
body  { font-family:Arial,sans-serif; font-size:11px; color:#000; margin:0; padding:18px; }
.header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #ccc; padding-bottom:6px; }
.header .logo img { width:140px; }
.header .contact { font-size:10px; line-height:1.4; text-align:right; color:#666; }
h1 { color:#666; font-weight:300; font-size:22px; margin:14px 0 12px; text-align:center; }
.field { border:1px solid #aaa; margin:0 0 6px; padding:4px 8px; }
.field label { color:#888; font-size:10px; display:block; }
.field .v { font-size:13px; min-height:18px; }
table.items { border-collapse:collapse; width:100%; margin-top:10px; border:1px solid #aaa; }
table.items th { background:#eee; color:#333; text-align:left; padding:5px 8px; font-size:11px; border-bottom:1px solid #aaa; }
table.items td { padding:4px 8px; border-bottom:1px solid #eee; }
.stage-head td { background:#f6f6e2; font-weight:bold; font-size:12px; }
.subtotal td { background:#fafad2; font-weight:bold; }
.right { text-align:right; }
.totals { margin-top:14px; width:50%; margin-left:50%; border-collapse:collapse; }
.totals td { padding:5px 10px; }
.totals .label { text-align:right; color:#666; }
.totals .grand { font-size:14px; font-weight:bold; background:#fafad2; }
.notes { margin-top:18px; font-size:10px; color:#444; }
.print-bar { background:#9B9B1B; color:#fff; padding:8px; text-align:center; font-size:12px; }
.print-bar a, .print-bar button { color:#fff; background:transparent; border:1px solid #fff; padding:3px 10px; margin-left:8px; cursor:pointer; text-decoration:none; }
@media print { .print-bar { display:none; } body { padding:0; } }
.sig { margin-top:30px; padding:14px; border:1px solid #ccc; }
.sig div { margin-bottom:14px; }
</style>
</head>
<body>
<div class="print-bar">
  <?= $showMoney ? 'Fee Proposal / Estimate of Costs' : 'Project Checklist (no rates)' ?>
  <button onclick="window.print()">Print / Save as PDF</button>
  <a href="project_stages.php?proj_id=<?= $proj_id ?>">Back to project stages</a>
</div>

<div class="header">
  <div class="logo"><img src="cadviz_logo.gif" alt="CADViz"></div>
  <div class="contact">
    Unit 8, 6-8 Omega Street<br>
    PO Box 302387, North Harbour<br>
    Auckland 0751<br><br>
    call 09.486.7031<br>
    mail@cadviz.co.nz<br>
    www.cadviz.co.nz
  </div>
</div>

<h1><?= $showMoney ? 'Fee Proposal / Estimate of Costs' : 'Project Checklist' ?></h1>

<div class="field"><label>Client Name</label><div class="v"><?= htmlspecialchars($head['Client_Name'] ?? '') ?></div></div>
<div class="field"><label>Job Name</label><div class="v"><?= htmlspecialchars($head['JobName'] ?? '') ?></div></div>
<div class="field"><label>Job Address</label><div class="v" style="min-height:32px"><?= nl2br(htmlspecialchars($head['Job_Address'] ?? '')) ?></div></div>
<div class="field"><label>Project Type</label><div class="v"><?= htmlspecialchars($head['Project_Type_Name'] ?? '') ?></div></div>
<div class="field"><label>Job Description / Scope of Works</label><div class="v" style="min-height:50px"><?= nl2br(htmlspecialchars($head['Job_Description'] ?? '')) ?></div></div>

<p style="margin-top:14px"><?= $showMoney
    ? 'Based on the scope of works CADViz can itemise the stages and tasks required to complete your project as follows:'
    : 'Stages and tasks for this project, with target hours per task:' ?></p>

<table class="items">
<?php foreach ($stages as $stage):
    $sid = (int)$stage['Project_Stage_ID'];
    $stageWeight = (float)($stage['StageWeight'] ?? 1);
    $tasks = $tasksByStage[$sid] ?? [];
    $stageHours = 0.0;
    $stageSub   = 0.0;
?>
<tr class="stage-head">
  <td colspan="<?= $showMoney ? 4 : 2 ?>">Stage: <?= htmlspecialchars(($stage['Stage_Type_Name'] ?? '') . ($stage['StageDesc'] ? ' — ' . $stage['StageDesc'] : '')) ?></td>
</tr>
<tr><th>Task / Item</th>
  <th class="right" style="width:60px">Hours</th>
  <?php if ($showMoney): ?>
    <th class="right" style="width:80px">Rate</th>
    <th class="right" style="width:90px">Subtotal</th>
  <?php endif; ?>
</tr>
<?php foreach ($tasks as $t):
    $hrs = (float)($t['Estimated_Time'] ?? 0) * (float)($t['Weight'] ?? $t['TaskWeight'] ?? 1) * $stageWeight;
    $rate = $baseRate * $multiplier;
    $sub  = $hrs * $rate + (float)($t['Fixed_Cost'] ?? 0);
    $stageHours += $hrs;
    $stageSub   += $sub;
    $name = $t['TaskDesc'] ? $t['TaskDesc'] : $t['Task_Name'];
?>
<tr>
  <td><?= htmlspecialchars((string)$name) ?></td>
  <td class="right"><?= number_format($hrs, 2) ?></td>
  <?php if ($showMoney): ?>
    <td class="right">$<?= number_format($rate, 2) ?></td>
    <td class="right">$<?= number_format($sub, 2) ?></td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
<tr class="subtotal">
  <td class="right">Subtotal:</td>
  <td class="right"><?= number_format($stageHours, 2) ?></td>
  <?php if ($showMoney): ?>
    <td></td>
    <td class="right">$<?= number_format($stageSub, 2) ?></td>
  <?php endif; ?>
</tr>
<?php
    $grandHours += $stageHours;
    $grandSub   += $stageSub;
endforeach;
?>
</table>

<?php if ($showMoney):
    $gst   = $grandSub * 0.15;
    $total = $grandSub + $gst;
?>
<table class="totals">
<tr><td class="label">SubTotal:</td><td class="right">$<?= number_format($grandSub, 2) ?></td></tr>
<tr><td class="label">GST (15%):</td><td class="right">$<?= number_format($gst, 2) ?></td></tr>
<tr class="grand"><td class="label">Grand Total (Inc GST):</td><td class="right">$<?= number_format($total, 2) ?></td></tr>
</table>

<div class="notes">
  <p>Please note that the rates listed are a combination of "base" rate and the principle's / director's rate for some tasks. The base rate represents the average of the staff's actually billable rate. The billable rates are tuned based on staff efficiency so that the net invoiced is equivalent.</p>
  <p>It is possible that the "Grand Total" listed above may not be enough to complete the job if the client requires revisions or otherwise changes the scope of work. This estimate does not include any printing costs, courier fees, council fees, engineer's fees or other third party services or materials.</p>
  <p>Refer to the attached Terms and Conditions of Trade. In addition to the standard terms and conditions of trade, when work is carried out over a timeframe greater than one month, progress payments would be invoiced for monthly.</p>
</div>

<div class="sig">
  <div>Authorised Signature (CADViz Ltd): _____________________________ &nbsp;&nbsp; Dated: <?= date('d/m/Y') ?></div>
  <div style="font-size:10px;color:#666">Director CADViz Limited, Licensed Building Practitioner (Design 2). Building Practitioner Number BP118489</div>
  <div style="margin-top:18px"><strong>Client Acknowledgement &amp; Acceptance</strong></div>
  <div style="font-size:10px;color:#444">You (the Client) hereby accept this Estimate and the attached Terms and Conditions of Trade.</div>
  <div style="margin-top:14px">Authorised Signature: _____________________________ &nbsp;&nbsp; Dated: ____________________</div>
</div>
<?php else: /* checklist mode */ ?>
<table class="totals">
<tr class="grand"><td class="label">Total Estimated Hours:</td><td class="right"><?= number_format($grandHours, 2) ?> hrs</td></tr>
</table>
<?php endif; ?>

<p style="margin-top:30px;text-align:right;color:#888;font-size:10px"><?= date('l, j F Y') ?></p>

</body>
</html>
