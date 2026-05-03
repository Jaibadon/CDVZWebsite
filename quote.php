<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    die('Quotes are admin-only. Use <a href="my_checklist.php">My Project Checklist</a> instead.');
}

$pdo = get_db();
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) die('Missing proj_id');

// Breakdown override:
//   breakdown=hours  → render the task breakdown (even on fixed-price
//                      projects), hours column only, no money.
//   breakdown=full   → render the task breakdown WITH money columns and,
//                      on fixed-price projects, a margin-vs-estimate panel.
//   (unset)          → existing behaviour: lump-sum line for fixed-price,
//                      full estimate breakdown otherwise.
$breakdown      = $_GET['breakdown'] ?? '';
$forceBreakdown = ($breakdown === 'hours' || $breakdown === 'full');
$internalView   = $forceBreakdown;          // CADViz-only review — hides T&Cs/sig
$showMoney      = !isset($_GET['nomoney']) && $breakdown !== 'hours';
$originalOnly = !empty($_GET['original_only']);  // hide variations section

// Detect fixed-price columns
$hasFixedPrice = false;
try { $hasFixedPrice = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'Quote_Type'")->fetch(); } catch (Exception $e) {}
$fpCols = $hasFixedPrice ? ', p.Quote_Type, p.Fixed_Price, p.Fixed_Margin_Pct' : ", NULL AS Quote_Type, NULL AS Fixed_Price, NULL AS Fixed_Margin_Pct";

// ── Project + client header ───────────────────────────────────────────────
$h = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.Job_Address, p.Job_Description, p.Project_Type,
            c.Client_Name, c.Multiplier,
            ptype.Project_Type_Name
            $fpCols
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

// Detect variation columns
$hasVariations = false;
try {
    $hasVariations = (bool)$pdo->query("SHOW TABLES LIKE 'Project_Variations'")->fetch();
} catch (Exception $e) { /* ignore */ }

// ── Load stages + tasks (ORIGINAL quote only — variations appear after) ───
$stagesWhere = "s.Proj_ID = ?" . ($hasVariations ? " AND s.Variation_ID IS NULL" : "");
$stages = $pdo->prepare(
    "SELECT s.Project_Stage_ID, s.Stage_Type_ID, s.Description AS StageDesc, COALESCE(s.Weight,1) AS StageWeight, st.Stage_Type_Name, st.Stage_Order
       FROM Project_Stages s
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
      WHERE $stagesWhere
      ORDER BY st.Stage_Order, s.Project_Stage_ID"
);
$stages->execute([$proj_id]);
$stages = $stages->fetchAll();

$tasksFilter = $hasVariations ? " AND COALESCE(t.Is_Removed, 0) = 0 AND t.Variation_ID IS NULL" : '';
$tasksStmt = $pdo->prepare(
    "SELECT t.Project_Stage_ID,
            t.Description       AS TaskDesc,
            COALESCE(t.Weight,1) AS TaskWeight,
            t.Proj_Task_Order,
            t.Assigned_To        AS Assigned_To,
            tt.Task_Name,
            tt.Estimated_Time,
            tt.Fixed_Cost,
            s.Login              AS AssignedLogin,
            s.`BILLING RATE`     AS StaffRate
       FROM Project_Tasks t
       LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
       LEFT JOIN Staff       s  ON t.Assigned_To  = s.Employee_ID
      WHERE t.Project_Stage_ID IN (SELECT Project_Stage_ID FROM Project_Stages WHERE Proj_ID = ?)
        $tasksFilter
      ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
);
$tasksStmt->execute([$proj_id]);
$tasksByStage = [];
foreach ($tasksStmt->fetchAll() as $t) {
    $tasksByStage[$t['Project_Stage_ID']][] = $t;
}

// ── Load APPROVED variations + their stages + tasks (for inclusion in quote)
$variations = [];
$variationStages = [];
$variationTasksByStage = [];
if ($hasVariations && !$originalOnly) {
    $vs = $pdo->prepare("SELECT * FROM Project_Variations WHERE Proj_ID = ? AND Status IN ('approved','in_progress','complete') ORDER BY Variation_Number");
    $vs->execute([$proj_id]);
    $variations = $vs->fetchAll();
    if (!empty($variations)) {
        $vIds = array_column($variations, 'Variation_ID');
        $in = implode(',', array_fill(0, count($vIds), '?'));
        $vsStmt = $pdo->prepare(
            "SELECT s.Project_Stage_ID, s.Variation_ID, s.Stage_Type_ID, s.Description AS StageDesc,
                    COALESCE(s.Weight,1) AS StageWeight, st.Stage_Type_Name, st.Stage_Order
               FROM Project_Stages s
               LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
              WHERE s.Proj_ID = ? AND s.Variation_ID IN ($in)
              ORDER BY s.Variation_ID, st.Stage_Order"
        );
        $vsStmt->execute(array_merge([$proj_id], $vIds));
        foreach ($vsStmt->fetchAll() as $row) {
            $variationStages[(int)$row['Variation_ID']][] = $row;
        }
        $vtStmt = $pdo->prepare(
            "SELECT t.Project_Stage_ID, t.Description AS TaskDesc, COALESCE(t.Weight,1) AS TaskWeight,
                    t.Assigned_To, tt.Task_Name, tt.Estimated_Time, tt.Fixed_Cost,
                    s.Login AS AssignedLogin, s.`BILLING RATE` AS StaffRate
               FROM Project_Tasks t
               LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
               LEFT JOIN Staff s ON t.Assigned_To = s.Employee_ID
              WHERE t.Variation_ID IN ($in) AND COALESCE(t.Is_Removed,0) = 0
              ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
        );
        $vtStmt->execute($vIds);
        foreach ($vtStmt->fetchAll() as $t) {
            $variationTasksByStage[(int)$t['Project_Stage_ID']][] = $t;
        }
    }
}

$grandHours = 0.0;
$grandSub   = 0.0;

$isFixedPrice = ($head['Quote_Type'] ?? '') === 'fixed';
$fixedPrice   = (float)($head['Fixed_Price'] ?? 0);
$fixedMargin  = (float)($head['Fixed_Margin_Pct'] ?? 12.5);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $showMoney ? 'Fee Proposal — ' : 'Project Checklist — ' ?><?= htmlspecialchars($head['JobName'] ?? '') ?></title>
<link href="site.css" rel="stylesheet">
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
.sig-row { display:flex; align-items:flex-end; gap:14px; }
.sig-img { height:48px; max-width:240px; object-fit:contain; border-bottom:1px solid #888; padding-bottom:2px; }
.sig-meta { display:flex; align-items:center; gap:10px; margin-top:6px; }
.lbp-badge { width:42px; height:42px; object-fit:contain; }
.tc-page { page-break-before:always; padding-top:6px; }
.tc-page h2 { color:#333; font-size:14px; text-align:center; border-bottom:1px solid #999; padding-bottom:4px; margin-bottom:8px; }
.tc-page ol.outer { padding-left:20px; }
.tc-page ol.outer > li { font-weight:bold; text-transform:uppercase; margin-top:8px; font-size:10.5px; }
.tc-page ol.outer > li > div { font-weight:normal; text-transform:none; margin:3px 0 0; }
.tc-page ol.inner { list-style:none; padding-left:0; margin:3px 0 0; font-size:9.5px; line-height:1.35; }
.tc-page ol.inner li { margin-bottom:3px; padding-left:32px;  }
.tc-page .tc-num { display:inline-block; width:28px; font-weight:bold; }
</style>
</head>
<body>
<div class="print-bar">
  <?php
    if ($internalView)            echo 'Internal breakdown — ' . ($breakdown === 'hours' ? 'hours only' : 'hours + prices + margin');
    elseif (!$showMoney)          echo 'Project Checklist (no rates)';
    else                          echo 'Fee Proposal / Estimate of Costs';
  ?>
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

<?php if ($internalView): ?>
<div style="background:#7a4d00;color:#fff;padding:6px 10px;text-align:center;font-size:11px;letter-spacing:1px">
  ⚠ INTERNAL CADViz REVIEW &mdash; DO NOT SEND THIS DOCUMENT TO THE CLIENT.
  <?= $breakdown === 'hours' ? 'Hours-only breakdown.' : 'Hours + prices breakdown with margin comparison.' ?>
</div>
<?php endif; ?>

<h1><?php
    if ($internalView) {
        echo 'Internal Project Breakdown' . ($breakdown === 'hours' ? ' — Hours' : ' — Hours + Prices');
    } else {
        echo $showMoney
            ? ($isFixedPrice ? 'Fee Proposal / Fixed-Price Quote' : 'Fee Proposal / Estimate of Costs')
            : 'Project Checklist';
    }
?></h1>

<div class="field"><label>Client Name</label><div class="v"><?= htmlspecialchars($head['Client_Name'] ?? '') ?></div></div>
<div class="field"><label>Job Name</label><div class="v"><?= htmlspecialchars($head['JobName'] ?? '') ?></div></div>
<div class="field"><label>Job Address</label><div class="v" style="min-height:32px"><?= nl2br(htmlspecialchars($head['Job_Address'] ?? '')) ?></div></div>
<div class="field"><label>Project Type</label><div class="v"><?= htmlspecialchars($head['Project_Type_Name'] ?? '') ?></div></div>
<div class="field"><label>Job Description / Scope of Works</label><div class="v" style="min-height:50px"><?= nl2br(htmlspecialchars($head['Job_Description'] ?? '')) ?></div></div>

<?php if ($isFixedPrice && $showMoney && !$forceBreakdown): ?>
<!-- ── Fixed-price (lump sum) quote line ─────────────────────────────── -->
<p style="margin-top:14px">Based on the scope of works above, CADViz quotes the following <strong>fixed price</strong> for this project:</p>
<?php
    $fpMarked = $fixedPrice * (1 + $fixedMargin/100);
    // Internally we add the safety margin; the client only sees the marked-up price.
    // The "rate" column is hidden so they don't see hourly breakdowns.
    $grandSub = $fpMarked;  // this becomes the project subtotal
?>
<table class="items">
<tr><th>Item</th><th class="right" style="width:120px">Price</th></tr>
<tr>
  <td><?= htmlspecialchars($head['JobName'] ?? 'Project') ?> &mdash; lump-sum quote for the scope above</td>
  <td class="right">$<?= number_format($fpMarked, 2) ?></td>
</tr>
</table>
<?php else: ?>

<p style="margin-top:14px"><?= $showMoney
    ? 'Based on the scope of works CADViz can itemise the stages and tasks required to complete your project as follows:'
    : 'Stages and tasks for this project, with target hours per task:' ?></p>

<?php if (empty($stages)): ?>
<div class="card" style="background:#fff3cd;border:1px solid #c8a52e;color:#7a5a00;padding:10px 14px;margin:10px 0">
  <strong>No stages defined yet on this project.</strong><br>
  <span style="font-size:11px">Add stages and tasks via
    <a href="project_stages.php?proj_id=<?= $proj_id ?>">Stages / Tasks (Quote builder)</a>
    before printing the quote. (Or, if this is a small fixed-price job, switch the project's Quote Type to "fixed" so it prints as a lump-sum quote.)</span>
</div>
<?php endif; ?>
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
    $hrs = (float)($t['Estimated_Time'] ?? 0) * (float)($t['Weight'] ?? $t['TaskWeight'] ?? 1);
    // If a staff member is assigned and has a billing rate, use it.
    // Otherwise fall back to the project base rate. Either way, multiply
    // by the client's negotiated Multiplier.
    $staffRate = (float)($t['StaffRate'] ?? 0);
    $rateBase  = ($staffRate > 0) ? $staffRate : $baseRate;
    $rate = $rateBase * $multiplier;
    $sub  = $hrs * $rate + (float)($t['Fixed_Cost'] ?? 0);
    $stageHours += $hrs;
    $stageSub   += $sub;
    $rawName = (string)($t['TaskDesc'] ? $t['TaskDesc'] : $t['Task_Name']);
    $nameHtml = htmlspecialchars($rawName);
    if (!empty($t['AssignedLogin'])) {
        $nameHtml .= ' <span style="color:#888;font-size:9px">(' . htmlspecialchars($t['AssignedLogin']) . ')</span>';
    }
?>
<tr>
  <td><?= $nameHtml ?></td>
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
<?php endif; /* end estimate-mode task table */ ?>

<?php
// ── Variations section: only approved/in_progress/complete are billable ───
if (!empty($variations)):
    $variationGrandHours = 0.0;
    $variationGrandSub   = 0.0;
?>
<h2 style="margin-top:18px;color:#9B9B1B;border-bottom:2px solid #9B9B1B;padding-bottom:3px">Variations</h2>
<?php foreach ($variations as $v):
    $vId = (int)$v['Variation_ID'];
    $vstages = $variationStages[$vId] ?? [];
    $vHours = 0.0; $vSub = 0.0;
    // Use Quote_Amount as a manual price override when set. For fixed-price
    // projects this is the variation's lump-sum price (no margin applied).
    $vOverride = ($v['Quote_Amount'] !== null && $v['Quote_Amount'] !== '') ? (float)$v['Quote_Amount'] : null;
    $useFixedForVariation = ($isFixedPrice || $vOverride !== null);
?>
<div style="margin-top:12px">
  <div style="background:#fff8e0;padding:6px 10px;border-left:4px solid #c33">
    <strong>Variation #<?= (int)$v['Variation_Number'] ?>: <?= htmlspecialchars($v['Title']) ?></strong>
    <span style="float:right;font-size:10px;color:#666">Status: <?= htmlspecialchars($v['Status']) ?>
    <?= !empty($v['Date_Approved']) ? '· Approved ' . date('d/m/Y', strtotime($v['Date_Approved'])) : '' ?></span>
    <?php if ($v['Description']): ?><div style="font-size:11px;margin-top:4px;color:#333"><?= nl2br(htmlspecialchars($v['Description'])) ?></div><?php endif; ?>
  </div>
  <?php if ($useFixedForVariation && $showMoney && $vOverride !== null): ?>
    <!-- Fixed-price variation: client just sees one line item -->
    <table class="quote-table" style="margin-top:6px">
      <tr><td>Variation #<?= (int)$v['Variation_Number'] ?> &mdash; <?= htmlspecialchars($v['Title']) ?></td>
          <td class="right" style="width:120px">$<?= number_format($vOverride, 2) ?></td></tr>
    </table>
    <?php $vSub = $vOverride; $vHours = 0; ?>
  <?php else: ?>
  <table class="quote-table" style="margin-top:6px">
  <?php foreach ($vstages as $vstage):
      $vsid = (int)$vstage['Project_Stage_ID'];
      $vsHours = 0.0; $vsSub = 0.0;
      $vtasks = $variationTasksByStage[$vsid] ?? [];
  ?>
    <tr class="stage-row"><td colspan="<?= $showMoney ? 4 : 2 ?>"><?= htmlspecialchars($vstage['Stage_Type_Name'] ?? '') ?> — <?= htmlspecialchars($vstage['StageDesc'] ?? '') ?></td></tr>
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
      <?php if ($showMoney): ?>
        <td class="right">$<?= number_format($rate, 2) ?></td>
        <td class="right">$<?= number_format($sub, 2) ?></td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <tr class="subtot"><td class="right">Subtotal:</td><td class="right"><?= number_format($vsHours, 2) ?></td>
      <?php if ($showMoney): ?><td></td><td class="right">$<?= number_format($vsSub, 2) ?></td><?php endif; ?>
    </tr>
    <?php $vHours += $vsHours; $vSub += $vsSub; endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($showMoney): ?>
  <div style="text-align:right;font-weight:bold;margin-top:4px">Variation total: <?= $vHours > 0 ? number_format($vHours, 2) . ' hrs &nbsp; ' : '' ?>$<?= number_format($vSub, 2) ?></div>
  <?php endif; ?>
</div>
<?php $variationGrandHours += $vHours; $variationGrandSub += $vSub; endforeach; ?>

<?php if ($showMoney): ?>
<table class="totals" style="margin-top:10px">
  <tr><td class="label">Variations subtotal:</td><td class="right">$<?= number_format($variationGrandSub, 2) ?></td></tr>
  <tr><td class="label">Variations GST (15%):</td><td class="right">$<?= number_format($variationGrandSub * 0.15, 2) ?></td></tr>
  <tr class="grand"><td class="label">Variations Grand Total (Inc GST):</td><td class="right">$<?= number_format($variationGrandSub * 1.15, 2) ?></td></tr>
</table>
<?php endif; ?>
<?php
$grandHours += $variationGrandHours;
$grandSub   += $variationGrandSub;
endif; /* variations */ ?>

<?php if ($showMoney):
    $gst   = $grandSub * 0.15;
    $total = $grandSub + $gst;
?>
<table class="totals">
<tr><td class="label">SubTotal:</td><td class="right">$<?= number_format($grandSub, 2) ?></td></tr>
<tr><td class="label">GST (15%):</td><td class="right">$<?= number_format($gst, 2) ?></td></tr>
<tr class="grand"><td class="label">Grand Total (Inc GST):</td><td class="right">$<?= number_format($total, 2) ?></td></tr>
</table>

<?php if ($isFixedPrice && $forceBreakdown):
    // Margin comparison: estimate (from the breakdown above) vs the
    // fixed price the client will actually be billed.
    $estSubtotal   = $grandSub; // rows above sum up the un-margined estimate
    $fpExGst       = $fixedPrice;
    $fpMarkedExGst = $fixedPrice * (1 + $fixedMargin/100);
    $delta         = $fpMarkedExGst - $estSubtotal;
    $deltaPct      = $estSubtotal > 0 ? ($delta / $estSubtotal) * 100 : 0;
    $deltaSign     = $delta >= 0 ? '+' : '−';
    $deltaAbs      = abs($delta);
    $deltaColor    = $delta >= 0 ? '#1a6b1a' : '#a00';
?>
<table class="totals" style="margin-top:6px;width:60%;margin-left:40%;background:#fffaf0;border:1px solid #c8a52e">
  <tr><td colspan="2" style="background:#fff3cd;padding:6px 10px;font-weight:bold;color:#7a5a00">Fixed-price vs. estimate (internal)</td></tr>
  <tr><td class="label">Estimate subtotal (sum of tasks):</td><td class="right">$<?= number_format($estSubtotal, 2) ?></td></tr>
  <tr><td class="label">Fixed price (excl. GST, before safety margin):</td><td class="right">$<?= number_format($fpExGst, 2) ?></td></tr>
  <tr><td class="label">Safety margin applied (<?= number_format($fixedMargin, 2) ?>%):</td><td class="right">$<?= number_format($fpMarkedExGst - $fpExGst, 2) ?></td></tr>
  <tr><td class="label"><strong>Fixed price quoted to client (excl. GST):</strong></td><td class="right"><strong>$<?= number_format($fpMarkedExGst, 2) ?></strong></td></tr>
  <tr><td class="label" style="color:<?= $deltaColor ?>"><strong>Delta vs estimate:</strong></td>
      <td class="right" style="color:<?= $deltaColor ?>"><strong><?= $deltaSign ?>$<?= number_format($deltaAbs, 2) ?> (<?= $deltaSign ?><?= number_format(abs($deltaPct), 1) ?>%)</strong></td></tr>
</table>
<p style="font-size:10px;color:#7a5a00;margin-top:6px">
  Positive delta = the fixed price (with safety margin) is <em>above</em> the bottom-up estimate (we're protected).
  Negative delta = the fixed price is <em>below</em> the estimate (we'd be working at a loss vs. the rates above — review margin or scope).
</p>
<?php endif; ?>

<?php if (!$internalView): ?>
<div class="notes">
  <p>Please note that the rates listed are a combination of "base" rate and the principle's / director's rate for some tasks. The base rate represents the average of the staff's actually billable rate. The billable rates are tuned based on staff efficiency so that the net invoiced is equivalent.</p>
  <p>It is possible that the "Grand Total" listed above may not be enough to complete the job if the client requires revisions or otherwise changes the scope of work. This estimate does not include any printing costs, courier fees, council fees, engineer's fees or other third party services or materials.</p>
  <p>Refer to the attached Terms and Conditions of Trade. In addition to the standard terms and conditions of trade, when work is carried out over a timeframe greater than one month, progress payments would be invoiced for monthly.</p>
</div>

<div class="sig">
  <div>
    <div style="margin-bottom:4px">Authorised Signature (CADViz Ltd):</div>
    <div class="sig-row">
      <img class="sig-img" src="signature.png" alt="Erik Nielsen">
      <div>Dated: <?= date('d/m/Y') ?></div>
    </div>
    <div class="sig-meta">
      <img class="lbp-badge" src="lbp_logo.png" alt="LBP">
      <span style="font-size:10px;color:#666">Director CADViz Limited, Licensed Building Practitioner (Design 2). Building Practitioner Number BP118489</span>
    </div>
  </div>
  <div style="margin-top:18px"><strong>Client Acknowledgement &amp; Acceptance</strong></div>
  <div style="font-size:10px;color:#444">You (the Client) hereby accept this Estimate and the attached Terms and Conditions of Trade.</div>
  <div style="margin-top:14px">Authorised Signature: _____________________________ &nbsp;&nbsp; Dated: ____________________</div>
</div>

<!-- ─── Terms & Conditions of Trade (NZ) ─────────────────────────────────── -->
<div class="tc-page">
<h2>Terms and Conditions of Trade</h2>

<ol class="outer">
  <li>DEFINITIONS
    <ol class="inner">
      <li><span class="tc-num">1.1</span> &ldquo;CADViz&rdquo; shall mean CADViz Limited, or any agents or employees thereof.</li>
      <li><span class="tc-num">1.2</span> &ldquo;Client&rdquo; shall mean the Client, any person acting on behalf of and with the authority of the Client, or any person purchasing services and products from CADViz.</li>
      <li><span class="tc-num">1.3</span> &ldquo;Services and products&rdquo; shall mean all drafting and architectural services and associated products, advice, graphics, training and onsite services and all charges for time and attendances, hire charges, insurance charges, or any fee or charge associated with the supply of services and products by CADViz to the Client.</li>
      <li><span class="tc-num">1.4</span> &ldquo;Price&rdquo; shall mean the cost of the services and products as agreed between CADViz and the Client and includes all disbursements (e.g. charges CADViz pay to others on the Client&rsquo;s behalf) subject to clause 4 of this contract.</li>
    </ol>
  </li>

  <li>ACCEPTANCE
    <ol class="inner">
      <li><span class="tc-num">2.1</span> Any instructions received by CADViz from the Client for the supply of services and products, including services and products that CADViz have ordered or are required to order from overseas, shall constitute a binding contract and acceptance of the terms and conditions contained herein.</li>
    </ol>
  </li>

  <li>COLLECTION AND USE OF INFORMATION
    <ol class="inner">
      <li><span class="tc-num">3.1</span> The Client authorises CADViz to collect, retain and use any information about the Client for the purpose of assessing the Client&rsquo;s credit worthiness, enforcing any rights under this contract, or marketing any services and products provided by CADViz.</li>
      <li><span class="tc-num">3.2</span> The Client authorises CADViz to disclose any information obtained to any person for the purposes set out in clause 3.1.</li>
      <li><span class="tc-num">3.3</span> Where the Client is a natural person, the authorities under clauses 3.1 and 3.2 are authorisations for the purposes of the Privacy Act 2020. The Client has the right to request access to and correction of any personal information held by CADViz, subject to the Privacy Act 2020.</li>
    </ol>
  </li>

  <li>PRICE
    <ol class="inner">
      <li><span class="tc-num">4.1</span> Where no price is stated in writing or agreed to orally, the services and products shall be deemed to be sold at the current amount such services and products are sold by CADViz at the time of the contract.</li>
      <li><span class="tc-num">4.2</span> The price may be increased by the amount of any reasonable increase in the cost of supply of the services and products that is beyond the control of CADViz between the date of the contract and delivery of the services and products.</li>
    </ol>
  </li>

  <li>PAYMENT
    <ol class="inner">
      <li><span class="tc-num">5.1</span> Payment for services and products shall be made as follows:
        <ol class="inner" style="margin-top:3px">
          <li><span class="tc-num">5.1.1</span> in full on or before the 20<sup>th</sup> day of the month following the date of the invoice (&ldquo;the due date&rdquo;); or</li>
          <li><span class="tc-num">5.1.2</span> in full on or before the 7<sup>th</sup> day following the date of the invoice (&ldquo;the due date&rdquo;).</li>
        </ol>
      </li>
      <li><span class="tc-num">5.2</span> Interest may be charged on any amount owing after the due date at the rate of 2.5% per month or part month.</li>
      <li><span class="tc-num">5.3</span> Any expenses, disbursements and legal costs incurred by CADViz in the enforcement of any rights contained in this contract shall be paid by the Client, including any reasonable solicitor&rsquo;s fees or debt collection agency fees.</li>
      <li><span class="tc-num">5.4</span> Receipt of a cheque, bill of exchange, or other negotiable instrument shall not constitute payment until such negotiable instrument is paid in full.</li>
      <li><span class="tc-num">5.5</span> A deposit may be required.</li>
    </ol>
  </li>

  <li>ESTIMATE
    <ol class="inner">
      <li><span class="tc-num">6.1</span> Where an estimate is given by CADViz for services and products:
        <ol class="inner" style="margin-top:3px">
          <li><span class="tc-num">6.1.1</span> The estimate may be withdrawn at any time; and</li>
          <li><span class="tc-num">6.1.2</span> The estimate shall be exclusive of goods and services tax (GST) unless specifically stated to the contrary.</li>
        </ol>
      </li>
      <li><span class="tc-num">6.2</span> The Client needs to be aware that the final price may vary from the estimate.</li>
      <li><span class="tc-num">6.3</span> Where services and products are required in addition to the estimate, the estimate will be increased accordingly.</li>
    </ol>
  </li>

  <li>AGENCY
    <ol class="inner">
      <li><span class="tc-num">7.1</span> The Client authorises CADViz to contract either as principal or agent for the provision of services and products that are the subject of this contract.</li>
      <li><span class="tc-num">7.2</span> Where CADViz enters into a contract of the type referred to in clause 7.1, it shall be read with and form part of this agreement and the Client agrees to pay any amounts due under that contract.</li>
    </ol>
  </li>

  <li>RETENTION OF TITLE
    <ol class="inner">
      <li><span class="tc-num">8.1</span> Title in any products supplied by CADViz passes to the Client only when the Client has made payment in full for all products provided by CADViz and of all other sums due to CADViz by the Client on any account whatsoever.</li>
      <li><span class="tc-num">8.2</span> The Client gives irrevocable authority to CADViz to enter any premises occupied by the Client or on which products are situated at any reasonable time, to remove any products not paid for in full by the Client. CADViz shall not be liable for costs, damages, expenses or any other losses incurred by the Client or any third party as a result of this action, nor liable in contract or in tort or otherwise in any way whatsoever.</li>
    </ol>
  </li>

  <li>LIABILITY
    <ol class="inner">
      <li><span class="tc-num">9.1</span> The Consumer Guarantees Act 1993, the Commerce Act 1986, the Fair Trading Act 1986 and other statutes may imply warranties or conditions or impose obligations upon CADViz which cannot by law (or which can only to a limited extent by law) be excluded or modified. In respect of any such implied warranties, conditions or terms imposed on CADViz, CADViz&rsquo;s liability shall, where it is allowed, be excluded or, if not able to be excluded, only apply to the minimum extent required by the relevant statute.</li>
      <li><span class="tc-num">9.2</span> Except as otherwise provided by clause 9.1, CADViz shall not be liable for:
        <ol class="inner" style="margin-top:3px">
          <li><span class="tc-num">9.2.1</span> Any loss or damage of any kind whatsoever, including consequential loss, whether suffered or incurred by the Client or another person and whether in contract, tort (including negligence), or otherwise, and whether such loss or damage arises directly or indirectly from services and products provided by CADViz to the Client.</li>
          <li><span class="tc-num">9.2.2</span> The Client shall indemnify CADViz against all claims and loss of any kind whatsoever however caused or arising, and without limiting the generality of the foregoing, whether caused or arising as a result of the negligence of CADViz or otherwise, brought by any person in connection with any matter, act, omission, or error by CADViz, its agents or employees, in connection with the services and products.</li>
        </ol>
      </li>
    </ol>
  </li>

  <li>COPYRIGHT AND INTELLECTUAL PROPERTY
    <ol class="inner">
      <li><span class="tc-num">10.1</span> CADViz owns and has copyright in all work, art, film, tooling, drawings, specifications, models, photographs, documents, software and products produced by it in connection with the services and products that form the subject of this contract. The Client may use them only if paid for in full and only for the purpose for which they were intended and supplied by CADViz.</li>
    </ol>
  </li>

  <li>CONSUMER GUARANTEES ACT
    <ol class="inner">
      <li><span class="tc-num">11.1</span> The guarantees contained in the Consumer Guarantees Act 1993 are excluded where the Client acquires services and products from CADViz for the purposes of a business in terms of sections 2 and 43 of that Act.</li>
    </ol>
  </li>

  <li>PERSONAL GUARANTEE OF COMPANY DIRECTORS OR TRUSTEES
    <ol class="inner">
      <li><span class="tc-num">12.1</span> If the Client is a company or trust, the director(s) or trustee(s) signing this contract, in consideration for CADViz agreeing to supply services and products and grant credit to the Client at their request, also sign this contract in their personal capacity and jointly and severally personally undertake as principal debtors to CADViz the payment of any and all monies now or hereafter owed by the Client to CADViz, and indemnify CADViz against non-payment by the Client. Any personal liability of a signatory hereto shall not exclude the Client in any way whatsoever from the liabilities and obligations contained in this contract. The signatories and Client shall be jointly and severally liable under the terms and conditions of this contract and for payment of all sums due hereunder.</li>
    </ol>
  </li>

  <li>CANCELLATION
    <ol class="inner">
      <li><span class="tc-num">13.1</span> CADViz shall, without any liability and without prejudice to any other right it has at law or in equity, have the right by notice to suspend or cancel in whole or in part any contract for the supply of services and products to the Client if the Client fails to pay any money owing after the due date or the Client commits an act of bankruptcy as defined in section 17 of the Insolvency Act 2006, or if the Client (being a company) becomes subject to liquidation, voluntary administration or receivership.</li>
      <li><span class="tc-num">13.2</span> Any cancellation or suspension under clause 13.1 of this agreement shall not affect CADViz&rsquo;s claim for money due at the time of cancellation or suspension, or for damages for any breach of any terms of this contract or the Client&rsquo;s obligations to CADViz under this contract.</li>
    </ol>
  </li>

  <li>MISCELLANEOUS
    <ol class="inner">
      <li><span class="tc-num">14.1</span> CADViz shall not be liable for delay or failure to perform its obligations if the cause of the delay or failure is beyond its control.</li>
      <li><span class="tc-num">14.2</span> Failure by CADViz to enforce any of the terms and conditions contained in this contract shall not be deemed to be a waiver of any of the rights or obligations CADViz has under this contract.</li>
      <li><span class="tc-num">14.3</span> If any provision of this contract shall be invalid, void, illegal or unenforceable, the validity, existence, legality and enforceability of the remaining provisions shall not be affected, prejudiced or impaired.</li>
      <li><span class="tc-num">14.4</span> Where these terms and conditions of trade are at variance with any order or instructions from the Client, these terms and conditions of trade shall prevail.</li>
      <li><span class="tc-num">14.5</span> The Client shall not assign all or any of its rights or obligations under this contract without the written consent of CADViz.</li>
      <li><span class="tc-num">14.6</span> This contract shall be governed by and construed in accordance with the laws of New Zealand, and the parties submit to the exclusive jurisdiction of the New Zealand Courts.</li>
    </ol>
  </li>
</ol>
</div>
<?php endif; /* end !$internalView wrapper around T&C + sig blocks */ ?>
<?php else: /* checklist mode (no money) */ ?>
<table class="totals">
<tr class="grand"><td class="label">Total Estimated Hours:</td><td class="right"><?= number_format($grandHours, 2) ?> hrs</td></tr>
</table>
<?php endif; ?>

<p style="margin-top:30px;text-align:right;color:#888;font-size:10px"><?= date('l, j F Y') ?></p>

</body>
</html>
