<?php
/**
 * Per-staff project checklist. Lists every active project the logged-in
 * staff member is on (Manager/DP1/DP2/DP3 or has timesheet hours), with
 * outstanding tasks (estimated hrs left), grouped by project & stage.
 *
 * Designed to print: hits the page once and shows everything. Use the
 * browser's Print to save as PDF or send to printer.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo  = get_db();
$empId = (int)($_SESSION['Employee_id'] ?? 0);
$user  = $_SESSION['UserID'] ?? '';

// Detect variation columns
$hasVariations = false;
try {
    $hasVariations = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Variation_ID'")->fetch();
} catch (Exception $e) { /* ignore */ }

$ptFilter = $hasVariations ? "AND COALESCE(pt.Is_Removed, 0) = 0" : '';

// Projects this staff member is involved with
$projsStmt = $pdo->prepare(
    "SELECT DISTINCT p.proj_id, p.JobName, p.Job_Description,
            mgr.Login AS ManagerLogin
       FROM Projects p
       LEFT JOIN Staff mgr ON p.Manager = mgr.Employee_ID
      WHERE p.Active <> 0
        AND (p.Manager = :e OR p.DP1 = :e OR p.DP2 = :e OR p.DP3 = :e
             OR p.proj_id IN (SELECT proj_id FROM Timesheets WHERE Employee_id = :e))
      ORDER BY p.JobName"
);
$projsStmt->execute([':e' => $empId]);
$projects = $projsStmt->fetchAll();

// All tasks for those projects with computed hours remaining
$projIds = array_column($projects, 'proj_id');
$tasksByProject = [];
$projTotals = []; // pid => [est, logged, remaining]

if (!empty($projIds)) {
    $in = implode(',', array_fill(0, count($projIds), '?'));

    $vCol = $hasVariations
        ? ", pt.Variation_ID, pv.Variation_Number, pv.Title AS VTitle, pv.Status AS VStatus"
        : ", NULL AS Variation_ID, NULL AS Variation_Number, NULL AS VTitle, NULL AS VStatus";
    $vJoin = $hasVariations ? "LEFT JOIN Project_Variations pv ON pt.Variation_ID = pv.Variation_ID" : "";

    $tStmt = $pdo->prepare(
        "SELECT ps.Proj_ID,
                pt.Proj_Task_ID, pt.Task_Type_ID, pt.Description AS TaskDesc,
                COALESCE(pt.Weight,1) AS TaskWeight, pt.Assigned_To,
                tt.Task_Name, tt.Estimated_Time,
                stg.Stage_Type_Name,
                COALESCE(ps.Weight,1) AS StageWeight,
                stg.Stage_Order
                $vCol
           FROM Project_Tasks pt
           JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
           JOIN Tasks_Types tt ON pt.Task_Type_ID = tt.Task_ID
           LEFT JOIN Stage_Types stg ON tt.Stage_ID = stg.Stage_Type_ID
           $vJoin
          WHERE ps.Proj_ID IN ($in) $ptFilter
          ORDER BY ps.Proj_ID, stg.Stage_Order, pt.Proj_Task_Order, pt.Proj_Task_ID"
    );
    $tStmt->execute($projIds);
    $allTasks = $tStmt->fetchAll();

    // Logged hours per Proj_Task_ID
    $loggedByPtid = [];
    try {
        $lStmt = $pdo->prepare(
            "SELECT Proj_Task_ID, SUM(Hours) AS hrs
               FROM Timesheets
              WHERE Proj_Task_ID IS NOT NULL AND proj_id IN ($in)
              GROUP BY Proj_Task_ID"
        );
        $lStmt->execute($projIds);
        foreach ($lStmt->fetchAll() as $r) $loggedByPtid[(int)$r['Proj_Task_ID']] = (float)$r['hrs'];
    } catch (Exception $e) { /* Proj_Task_ID column may not exist yet */ }

    foreach ($allTasks as $t) {
        $pid = (int)$t['Proj_ID'];
        $est = (float)$t['Estimated_Time'] * (float)$t['TaskWeight'] * (float)$t['StageWeight'];
        $logged = $loggedByPtid[(int)$t['Proj_Task_ID']] ?? 0.0;
        $remaining = $est - $logged;
        $isMine = ((int)$t['Assigned_To'] === $empId);
        $tasksByProject[$pid][] = [
            'name'      => $t['Task_Name'],
            'desc'      => $t['TaskDesc'],
            'stage'     => $t['Stage_Type_Name'] ?? 'Other',
            'est'       => $est,
            'logged'    => $logged,
            'remaining' => $remaining,
            'mine'      => $isMine,
            'vid'       => $t['Variation_ID'],
            'vnumber'   => $t['Variation_Number'],
            'vtitle'    => $t['VTitle'],
            'vstatus'   => $t['VStatus'],
        ];
        if (!isset($projTotals[$pid])) $projTotals[$pid] = ['est'=>0, 'logged'=>0, 'remaining'=>0, 'mine_remaining'=>0];
        $projTotals[$pid]['est']       += $est;
        $projTotals[$pid]['logged']    += $logged;
        $projTotals[$pid]['remaining'] += $remaining;
        if ($isMine) $projTotals[$pid]['mine_remaining'] += max(0, $remaining);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Project Checklist — <?= htmlspecialchars($user) ?></title>
<link href="site.css" rel="stylesheet">
<style>
@page { size: A4; margin: 14mm; }
body { font-family: Arial, sans-serif; font-size: 11px; padding: 14px; background:#fff; }
h1 { color:#9B9B1B; font-size:18px; margin:0 0 4px; }
h2 { color:#9B9B1B; font-size:14px; margin:14px 0 4px; border-bottom:1px solid #ccc; padding-bottom:3px; }
.proj-card { margin-bottom:14px; padding:10px 14px; background:#fff; border:1px solid #ccc; border-radius:4px; page-break-inside: avoid; }
.proj-meta { font-size:11px; color:#666; margin-bottom:6px; }
.proj-totals { display:inline-block; padding:2px 8px; background:#eef; border-radius:3px; font-size:11px; margin-left:8px; }
.proj-totals.over { background:#ffd6d6; color:#a00; }
table { width:100%; border-collapse:collapse; margin-top:4px; }
th { background:#f4f4d8; text-align:left; padding:4px 6px; font-size:10px; }
td { padding:3px 6px; border-bottom:1px solid #eee; vertical-align:top; }
.task.mine td { background:#eef; }
.task.over td { background:#ffd6d6; color:#a00; }
.task.almost td { background:#fff3cd; }
.task.var td { font-style: italic; }
.task.var.unapproved td:first-child { color:#c33; }
.right { text-align:right; }
.no-print { margin-bottom:10px; }
@media print { .no-print { display:none; } body { padding:0; } }
.tag-mine { background:#9B9B1B; color:#fff; padding:1px 4px; border-radius:2px; font-size:9px; font-weight:bold; margin-left:4px; }
.tag-var { background:#c33; color:#fff; padding:1px 4px; border-radius:2px; font-size:9px; margin-left:4px; }
.tag-var.approved { background:#1a6b1a; }
</style>
</head>
<body>

<div class="no-print">
  <a href="menu.php">&larr; Main Menu</a> &nbsp;|&nbsp;
  <a href="main.php">Timesheet</a> &nbsp;|&nbsp;
  <button onclick="window.print()" style="padding:5px 12px;background:#9B9B1B;color:#fff;border:none;border-radius:3px;cursor:pointer">🖨 Print / Save as PDF</button>
</div>

<h1>My Project Checklist — <?= htmlspecialchars($user) ?></h1>
<p style="color:#555">Generated <?= date('d/m/Y H:i') ?>. Tasks <strong>highlighted</strong> are assigned to you.
Variation tasks appear in italic; <span style="color:#c33">unapproved variations</span> show in red.</p>

<?php if (empty($projects)): ?>
  <p style="color:#888"><em>No active projects assigned. If this is a mistake, ask Erik / Jen to add you to a project.</em></p>
<?php else: ?>

<?php foreach ($projects as $p): $pid = (int)$p['proj_id']; $tasks = $tasksByProject[$pid] ?? []; $tot = $projTotals[$pid] ?? ['est'=>0,'logged'=>0,'remaining'=>0,'mine_remaining'=>0]; ?>
<div class="proj-card">
  <h2 style="margin-top:0">
    <?= htmlspecialchars($p['JobName']) ?>
    <span class="proj-totals <?= $tot['remaining'] < 0 ? 'over' : '' ?>">
      <?= number_format($tot['logged'],1) ?>h / <?= number_format($tot['est'],1) ?>h
      &middot; <?= number_format(max(0, $tot['remaining']),1) ?>h left
      <?php if ($tot['mine_remaining'] > 0): ?>
        (<strong><?= number_format($tot['mine_remaining'],1) ?>h yours</strong>)
      <?php endif; ?>
    </span>
  </h2>
  <div class="proj-meta">
    Manager: <?= htmlspecialchars($p['ManagerLogin'] ?? '?') ?>
    <?php if ($p['Job_Description']): ?> &middot; <?= htmlspecialchars(mb_substr($p['Job_Description'], 0, 120)) ?><?php endif; ?>
  </div>

  <?php if (empty($tasks)): ?>
    <em style="color:#888">No tasks defined yet for this project.</em>
  <?php else:
    // Group by stage (originals) + variation
    $groups = [];
    foreach ($tasks as $t) {
        $key = $t['vid'] ? 'V#'.$t['vnumber'].': '.$t['vtitle'] : $t['stage'];
        $groups[$key][] = $t;
    }
  ?>
  <table>
    <tr><th style="width:55%">Task</th><th class="right">Estimated</th><th class="right">Logged</th><th class="right">Remaining</th></tr>
    <?php foreach ($groups as $groupName => $groupTasks):
        $first = $groupTasks[0];
        $isVariation = !empty($first['vid']);
        $unapproved = $isVariation && ($first['vstatus'] !== 'approved');
    ?>
      <tr><td colspan="4" style="background:<?= $isVariation ? '#fff3cd' : '#f7f7e0' ?>;font-weight:bold;font-size:11px"><?= htmlspecialchars($groupName) ?>
        <?php if ($isVariation): ?><span class="tag-var <?= $unapproved ? '' : 'approved' ?>"><?= htmlspecialchars($first['vstatus']) ?></span><?php endif; ?>
      </td></tr>
      <?php foreach ($groupTasks as $t):
        $cls = '';
        if ($t['mine'])         $cls .= ' mine';
        if ($t['remaining'] < 0) $cls .= ' over';
        elseif ($t['remaining'] < 1 && $t['est'] > 0) $cls .= ' almost';
        if ($isVariation) $cls .= ' var ' . ($unapproved ? 'unapproved' : '');
      ?>
      <tr class="task<?= $cls ?>">
        <td>
          <?= htmlspecialchars($t['name']) ?>
          <?php if ($t['desc']): ?><span style="color:#666"> — <?= htmlspecialchars($t['desc']) ?></span><?php endif; ?>
          <?php if ($t['mine']): ?><span class="tag-mine">YOU</span><?php endif; ?>
        </td>
        <td class="right"><?= number_format($t['est'], 2) ?>h</td>
        <td class="right"><?= number_format($t['logged'], 2) ?>h</td>
        <td class="right"><?= number_format($t['remaining'], 2) ?>h</td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
