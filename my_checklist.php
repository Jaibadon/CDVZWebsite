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
require_once 'helpers.php';

$pdo  = get_db();
$empId = (int)($_SESSION['Employee_id'] ?? 0);
$user  = $_SESSION['UserID'] ?? '';

// ?proj_id=X filters to a single project (used by per-project print button
// and by the link from projects.php for staff).
$singleProjId = (int)($_GET['proj_id'] ?? 0);
$autoPrint    = !empty($_GET['print']);

// Detect variation columns
$hasVariations = false;
try {
    $hasVariations = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Variation_ID'")->fetch();
} catch (Exception $e) { /* ignore */ }

$ptFilter = $hasVariations ? "AND COALESCE(pt.Is_Removed, 0) = 0" : '';

// Tasks are priced at quote time using either the assigned staff's
// billing rate, or the TBA rate when nobody is assigned. The rate at
// QUOTE time is snapshotted into Project_Tasks.Quoted_Rate (added by
// migrations/add_quoted_rate.sql; backfilled from current Assigned_To
// if the task predates the migration). After acceptance, reassigning a
// task does NOT update Quoted_Rate — the contract dollar value is
// frozen — so the staff member's hour budget scales by
// (Quoted_Rate / their_rate). When Quoted_Rate is NULL (very old rows
// the migration couldn't infer a sensible value for), fall back to the
// system-wide TBA rate.
$tbaRate = get_tba_rate($pdo);  // sourced from Staff #29

// Has the migration that added Project_Tasks.Quoted_Rate run? Gate the
// SELECT so installs that haven't migrated still load the page.
$hasQuotedRate = false;
try { $hasQuotedRate = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Quoted_Rate'")->fetch(); } catch (Exception $e) {}
$staffBillingRate = [];
try {
    foreach ($pdo->query("SELECT Employee_ID, `Billing Rate` AS BillingRate, Login FROM Staff")->fetchAll() as $sr) {
        $staffBillingRate[(int)$sr['Employee_ID']] = [
            'rate'  => (float)($sr['BillingRate'] ?? 0),
            'login' => $sr['Login'],
        ];
    }
} catch (Exception $e) {
    // Older installs may use `BILLING RATE` (uppercase). Fall back.
    try {
        foreach ($pdo->query("SELECT Employee_ID, `BILLING RATE` AS BillingRate, Login FROM Staff")->fetchAll() as $sr) {
            $staffBillingRate[(int)$sr['Employee_ID']] = [
                'rate'  => (float)($sr['BillingRate'] ?? 0),
                'login' => $sr['Login'],
            ];
        }
    } catch (Exception $e2) {}
}

// Projects this staff member is involved with.
// When ?proj_id=X is set, narrow to just that project (still requires the
// staff member to have a connection to it — admins bypass the connection check).
$isAdminUser = in_array($user, ['erik','jen'], true);
$projFilterSql = $singleProjId > 0 ? " AND p.proj_id = :pid" : '';

// Quote_Status detection — older installs don't have the column; the
// "assigned to a task in an accepted quote" rule degrades to a no-op.
$hasQuoteStatus = false;
try { $hasQuoteStatus = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'Quote_Status'")->fetch(); } catch (Exception $e) {}
$ptRemovedFilter = $hasVariations ? "AND COALESCE(pt.Is_Removed, 0) = 0" : '';
$assignedClause = $hasQuoteStatus
    ? "OR (
          p.Quote_Status = 'accepted'
          AND EXISTS (
              SELECT 1 FROM Project_Tasks pt
              JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
             WHERE ps.Proj_ID = p.proj_id
               AND pt.Assigned_To = :e
               $ptRemovedFilter
          )
       )"
    : '';

$peopleFilter = $isAdminUser
    ? ''  // admins see any project
    : "AND (p.Manager = :e OR p.DP1 = :e OR p.DP2 = :e OR p.DP3 = :e
           OR p.proj_id IN (SELECT proj_id FROM Timesheets WHERE Employee_id = :e)
           $assignedClause)";
$activeFilter = $singleProjId > 0 ? '' : 'AND p.Active <> 0';  // single-project view shows even inactive

$projsStmt = $pdo->prepare(
    "SELECT DISTINCT p.proj_id, p.JobName, p.Job_Description,
            p.Manager, p.DP1, p.DP2, p.DP3,
            mgr.Login AS ManagerLogin
       FROM Projects p
       LEFT JOIN Staff mgr ON p.Manager = mgr.Employee_ID
      WHERE 1=1 $activeFilter $projFilterSql $peopleFilter
      ORDER BY p.JobName"
);
$bind = [];
if ($singleProjId > 0) $bind[':pid'] = $singleProjId;
if (!$isAdminUser) $bind[':e'] = $empId;
$projsStmt->execute($bind);
$projects = $projsStmt->fetchAll();

// ── Perspective state ────────────────────────────────────────────────────
// Default: every task's hours are scaled to the VIEWER'S rate, so a staff
// member sees "if I did this, how many hours would it take me at my
// billing rate". Per-project toggle (gated to whoever is the project's
// `Manager` row): flips THAT PROJECT to "assigned" perspective where
// each task scales to its actual assignee's rate. The toggle is per
// project — so a manager of project A and DP1 of project B sees a
// toggle on A only and B is locked to viewer perspective.
//
// State flows through the URL as ?assigned_projs=123,456 (CSV of
// proj_ids currently in assigned mode). Toggle buttons add/remove
// their project from the list.
$viewerInfo    = $staffBillingRate[$empId] ?? null;
$viewerRate    = $viewerInfo ? (float)$viewerInfo['rate'] : 0.0;
$viewerHasRate = $viewerRate > 0.001;

$assignedProjsRaw = (string)($_GET['assigned_projs'] ?? '');
$assignedProjsRequested = [];
foreach (explode(',', $assignedProjsRaw) as $tok) {
    $tok = trim($tok);
    if ($tok !== '' && ctype_digit($tok)) $assignedProjsRequested[(int)$tok] = true;
}

// Authoritative per-project perspective map. Only honour the URL flag
// when the viewer is the actual Manager of that project — non-managers
// passing assigned_projs in the URL are silently ignored.
$projectPerspective = [];
$canToggleForProject = [];
foreach ($projects as $p) {
    $pid = (int)$p['proj_id'];
    $isMgr = ((int)($p['Manager'] ?? 0) === $empId);
    $canToggleForProject[$pid] = $isMgr;
    $projectPerspective[$pid] = ($isMgr && !empty($assignedProjsRequested[$pid])) ? 'assigned' : 'viewer';
}

// Helper: build the URL for toggling a single project's perspective.
// Preserves any other ?param=value (proj_id, print) in the URL.
function build_perspective_url(int $pid, bool $turnOn, array $currentlyAssigned): string {
    $set = $currentlyAssigned;
    if ($turnOn)  $set[$pid] = true;
    else          unset($set[$pid]);
    $list = array_keys(array_filter($set));
    sort($list);
    $qs = $_GET;
    if (empty($list)) unset($qs['assigned_projs']);
    else              $qs['assigned_projs'] = implode(',', $list);
    return 'my_checklist.php' . (!empty($qs) ? '?' . http_build_query($qs) : '');
}

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
    $qrCol = $hasQuotedRate ? ", pt.Quoted_Rate" : ", NULL AS Quoted_Rate";

    $tStmt = $pdo->prepare(
        "SELECT ps.Proj_ID,
                pt.Proj_Task_ID, pt.Task_Type_ID, pt.Description AS TaskDesc,
                COALESCE(pt.Weight,1) AS TaskWeight, pt.Assigned_To,
                tt.Task_Name, tt.Estimated_Time,
                stg.Stage_Type_Name,
                COALESCE(ps.Weight,1) AS StageWeight,
                stg.Stage_Order
                $vCol
                $qrCol
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
              WHERE Proj_Task_ID IS NOT NULL AND Proj_Task_ID > 0 AND proj_id IN ($in)
              GROUP BY Proj_Task_ID"
        );
        $lStmt->execute($projIds);
        foreach ($lStmt->fetchAll() as $r) $loggedByPtid[(int)$r['Proj_Task_ID']] = (float)$r['hrs'];
    } catch (Exception $e) { /* Proj_Task_ID column may not exist yet */ }

    // Unassigned hours per project — Timesheets rows that ran against this
    // project but with no Proj_Task_ID linkage (legacy entries, quick logs
    // without picking a task). Don't get attributed to any task above, but
    // still consume project budget, so surface them on the totals badge.
    $unassignedByProj = [];
    try {
        $uStmt = $pdo->prepare(
            "SELECT proj_id, SUM(Hours) AS hrs
               FROM Timesheets
              WHERE proj_id IN ($in)
                AND (Proj_Task_ID IS NULL OR Proj_Task_ID = 0)
              GROUP BY proj_id"
        );
        $uStmt->execute($projIds);
        foreach ($uStmt->fetchAll() as $r) $unassignedByProj[(int)$r['proj_id']] = (float)$r['hrs'];
    } catch (Exception $e) {
        // Proj_Task_ID column missing → fall back to "everything is unassigned"
        // so the badge still shows logged hours instead of zero.
        try {
            $uStmt = $pdo->prepare(
                "SELECT proj_id, SUM(Hours) AS hrs FROM Timesheets WHERE proj_id IN ($in) GROUP BY proj_id"
            );
            $uStmt->execute($projIds);
            foreach ($uStmt->fetchAll() as $r) $unassignedByProj[(int)$r['proj_id']] = (float)$r['hrs'];
        } catch (Exception $e2) { /* give up */ }
    }

    foreach ($allTasks as $t) {
        $pid = (int)$t['Proj_ID'];
        $est = (float)$t['Estimated_Time'] * (float)$t['TaskWeight'];
        $logged = $loggedByPtid[(int)$t['Proj_Task_ID']] ?? 0.0;
        $remaining = $est - $logged;
        $isMine = ((int)$t['Assigned_To'] === $empId);

        // Rate-adjusted hours.
        //
        // The dollar budget for the task was locked when the quote was
        // accepted, at the rate captured in pt.Quoted_Rate. We compute
        // hour figures for TWO perspectives:
        //
        //   ASSIGNED perspective (manager toggle, or unassigned tasks):
        //     hours = quoted_hours × (Quoted_Rate / assigned_staff_rate)
        //     "How many hours does Phil get under this task's budget at
        //      his billing rate?"
        //
        //   VIEWER perspective (default for everyone):
        //     hours = quoted_hours × (Quoted_Rate / viewer_rate)
        //     "If I (the logged-in user) were doing this task, how many
        //      hours would it take me at MY billing rate to use the same
        //      dollar budget?"
        //
        // The viewer perspective is what most staff actually want to see
        // — the project as their personal hour-budget. Managers can flip
        // back to assigned perspective via the toggle to see the truth
        // for the actual assignees.
        //
        // When Quoted_Rate is missing (very old rows the migration
        // couldn't backfill), fall back to the system-wide TBA rate.
        $assignedId   = (int)($t['Assigned_To'] ?? 0);
        $assignedInfo = $staffBillingRate[$assignedId] ?? null;
        $quotedRate   = ($t['Quoted_Rate'] !== null && (float)$t['Quoted_Rate'] > 0.001)
                          ? (float)$t['Quoted_Rate']
                          : $tbaRate;

        // Assigned-perspective scaling (used by manager toggle ON, or
        // when rendering "real assignment" info anywhere).
        $assignedScaledEst = $est;
        $assignedScaledRem = $remaining;
        $assignedRate      = 0.0;
        $assignedLogin     = '';
        $assignedHasRatio  = false;
        if ($assignedInfo && (float)$assignedInfo['rate'] > 0.001) {
            $assignedRate  = (float)$assignedInfo['rate'];
            $assignedLogin = (string)$assignedInfo['login'];
            $ar            = $quotedRate / $assignedRate;
            if (abs($ar - 1.0) > 0.001) {
                $assignedScaledEst = $est * $ar;
                $assignedScaledRem = $assignedScaledEst - $logged;
                $assignedHasRatio  = true;
            }
        }

        // Viewer-perspective scaling (default render). When the viewer
        // has no billing rate set (admin without one, or a Staff row
        // missing the column), fall through to raw quoted hours — we
        // can't compute a meaningful viewer ratio from a zero rate.
        $viewerScaledEst = $est;
        $viewerScaledRem = $remaining;
        $viewerHasRatio  = false;
        if ($viewerHasRate) {
            $vr = $quotedRate / $viewerRate;
            if (abs($vr - 1.0) > 0.001) {
                $viewerScaledEst = $est * $vr;
                $viewerScaledRem = $viewerScaledEst - $logged;
                $viewerHasRatio  = true;
            }
        }

        // Pick which set the renderer will use, based on THIS project's
        // perspective (managers can flip individual projects via the
        // per-card toggle; everyone else sees viewer perspective).
        $thisPerspective = $projectPerspective[$pid] ?? 'viewer';
        if ($thisPerspective === 'assigned') {
            $scaledEst = $assignedScaledEst;
            $scaledRem = $assignedScaledRem;
            $hasRatio  = $assignedHasRatio;
        } else {
            $scaledEst = $viewerScaledEst;
            $scaledRem = $viewerScaledRem;
            $hasRatio  = $viewerHasRatio;
        }

        $tasksByProject[$pid][] = [
            'name'           => $t['Task_Name'],
            'desc'           => $t['TaskDesc'],
            'stage'          => $t['Stage_Type_Name'] ?? 'Other',
            'est'            => $est,
            'logged'         => $logged,
            'remaining'      => $remaining,
            'mine'           => $isMine,
            'assigned_id'    => $assignedId,
            'assigned_login' => $assignedLogin,
            'assigned_rate'  => $assignedRate,
            'quoted_rate'    => $quotedRate,
            'scaled_est'     => $scaledEst,        // perspective-aware
            'scaled_rem'     => $scaledRem,        // perspective-aware
            'has_ratio'      => $hasRatio,         // perspective-aware
            'perspective'    => $thisPerspective,  // 'viewer' | 'assigned'
            'vid'            => $t['Variation_ID'],
            'vnumber'        => $t['Variation_Number'],
            'vtitle'         => $t['VTitle'],
            'vstatus'        => $t['VStatus'],
        ];
        if (!isset($projTotals[$pid])) $projTotals[$pid] = ['est'=>0, 'logged'=>0, 'remaining'=>0, 'mine_remaining'=>0, 'unassigned'=>0];
        // Project totals are aggregated in the chosen perspective so the
        // proj-card badge is internally consistent with the per-row values
        // shown below it. Logged hours are real time and aren't scaled.
        $projTotals[$pid]['est']       += $hasRatio ? $scaledEst : $est;
        $projTotals[$pid]['logged']    += $logged;
        $projTotals[$pid]['remaining'] += $hasRatio ? $scaledRem : $remaining;
        // mine_remaining = the viewer's hours-left budget for tasks they
        // are explicitly assigned to. In viewer perspective this is the
        // viewer-scaled remaining; in assigned perspective for $isMine
        // tasks the assigned and viewer rates are the same person, so
        // both modes yield the viewer-scaled value either way.
        if ($isMine) $projTotals[$pid]['mine_remaining'] += max(0, $hasRatio ? $scaledRem : $remaining);
    }
    // Backfill unassigned hours per project (works even for projects with
    // zero Project_Tasks rows — they'd otherwise be missing from $projTotals).
    foreach ($projIds as $pid) {
        $pid = (int)$pid;
        if (!isset($projTotals[$pid])) $projTotals[$pid] = ['est'=>0, 'logged'=>0, 'remaining'=>0, 'mine_remaining'=>0, 'unassigned'=>0];
        $projTotals[$pid]['unassigned'] = (float)($unassignedByProj[$pid] ?? 0);
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

/* Collapse / expand chrome */
.proj-card h2 { cursor: pointer; user-select: none; position: relative; padding-right: 28px; }
.proj-card h2 .toggle { position:absolute; right:0; top:0; font-size:16px; color:#9B9B1B; transition: transform 0.15s; display:inline-block; }
.proj-card.collapsed h2 .toggle { transform: rotate(-90deg); }
.proj-card.collapsed .proj-body { display: none; }
.bulk-controls { margin-bottom:10px; }
.bulk-controls button { padding:4px 10px; background:#555; color:#fff; border:none; border-radius:3px; cursor:pointer; font-size:11px; margin-right:4px; }
@media print { .bulk-controls { display:none; } .proj-card.collapsed .proj-body { display: block !important; } .proj-card h2 .toggle { display:none; } }
</style>
</head>
<body>

<div class="no-print">
  <a href="menu.php">&larr; Main Menu</a> &nbsp;|&nbsp;
  <a href="main.php">Timesheet</a>
  <?php if ($singleProjId > 0): ?>
    &nbsp;|&nbsp; <a href="my_checklist.php">All my projects</a>
  <?php endif; ?>
  &nbsp;|&nbsp;
  <button onclick="window.print()" style="padding:5px 12px;background:#9B9B1B;color:#fff;border:none;border-radius:3px;cursor:pointer">🖨 Print / Save as PDF</button>
</div>

<h1><?= $singleProjId > 0 ? 'Project Checklist' : 'My Project Checklist' ?> — <?= htmlspecialchars($user) ?></h1>
<p style="color:#555">Generated <?= date('d/m/Y H:i') ?>. Tasks <strong>highlighted</strong> are assigned to you.
Variation tasks appear in italic; <span style="color:#c33">unapproved variations</span> show in red.
Click a project heading to collapse/expand. Printing always shows everything.</p>

<?php
// ── Perspective banner (page-level) ─────────────────────────────────────
// Generic note. The actual perspective toggle lives on each project
// card the viewer manages — see the loop below.
$anyManaged = false;
foreach ($canToggleForProject as $b) { if ($b) { $anyManaged = true; break; } }
?>
<div class="no-print" style="background:#eef;border:1px solid #b0c4d8;color:#246;padding:6px 10px;border-radius:4px;font-size:12px;margin-bottom:10px">
  <?php if ($viewerHasRate): ?>
    <strong>Hours shown are from your perspective</strong>
    &mdash; every task is scaled to your assigned rate so the totals reflect "if I did this whole project, how many hours I'd need".
    <?php if ($anyManaged): ?>
      Projects you manage have a <em>switch perspective</em> button on the card &mdash; flip individual projects to the per-assignee view.
    <?php endif; ?>
  <?php else: ?>
    <strong>Hours shown un-scaled</strong> &mdash; your rate isn't on file yet, so hours are the raw quoted hours. Ask Erik or Jen to set you up via <a href="staff_admin.php">staff_admin</a>.
  <?php endif; ?>
</div>

<div class="bulk-controls no-print">
  <button onclick="document.querySelectorAll('.proj-card').forEach(c => c.classList.remove('collapsed'))">Expand all</button>
  <button onclick="document.querySelectorAll('.proj-card').forEach(c => c.classList.add('collapsed'))">Collapse all</button>
  <button onclick="document.querySelectorAll('.proj-card').forEach(c => c.querySelector('.tag-mine') ? c.classList.remove('collapsed') : c.classList.add('collapsed'))">Show only projects with my tasks</button>
</div>

<?php if (empty($projects)): ?>
  <p style="color:#888"><em>No active projects assigned. If this is a mistake, ask Erik / Jen to add you to a project.</em></p>
<?php else: ?>

<?php foreach ($projects as $p): $pid = (int)$p['proj_id']; $tasks = $tasksByProject[$pid] ?? []; $tot = $projTotals[$pid] ?? ['est'=>0,'logged'=>0,'remaining'=>0,'mine_remaining'=>0,'unassigned'=>0]; ?>
<div class="proj-card" id="proj-<?= $pid ?>">
  <h2 style="margin-top:0" onclick="if(event.target.tagName!=='A')this.parentElement.classList.toggle('collapsed')">
    <?= htmlspecialchars($p['JobName']) ?>
    <span class="proj-totals <?= $tot['remaining'] < 0 ? 'over' : '' ?>">
      <?= number_format($tot['logged'],1) ?>h / <?= number_format($tot['est'],1) ?>h
      &middot; <?= number_format(max(0, $tot['remaining']),1) ?>h left
      <?php if ($tot['mine_remaining'] > 0): ?>
        (<strong><?= number_format($tot['mine_remaining'],1) ?>h yours</strong>)
      <?php endif; ?>
      <?php if (!empty($tot['unassigned']) && (float)$tot['unassigned'] > 0): ?>
        &middot; <span style="color:#a05a00" title="Hours logged on this project that aren't tied to a specific task — staff who entered them didn't pick a task in the picker. Counted toward the project's overall hours but not against any task's remaining budget."><?= number_format((float)$tot['unassigned'], 1) ?>h unassigned</span>
      <?php endif; ?>
    </span>
    <?php if ($singleProjId === 0): ?>
      <a href="my_checklist.php?proj_id=<?= $pid ?>&print=1" target="_blank"
         class="no-print"
         style="font-size:11px;background:#246;color:#fff !important;padding:3px 8px;border-radius:3px;text-decoration:none;margin-left:8px"
         onclick="event.stopPropagation()">🖨 Print this project</a>
    <?php endif; ?>
    <?php if ($canToggleForProject[$pid] ?? false):
        $thisP   = $projectPerspective[$pid] ?? 'viewer';
        $turnOn  = ($thisP !== 'assigned');  // if currently viewer, toggle turns ON; if assigned, turns OFF
        $btnUrl  = build_perspective_url($pid, $turnOn, $assignedProjsRequested);
        $btnText = $turnOn ? '👁 Switch to per-assignee view' : '↩ Back to your perspective';
        $btnBg   = $turnOn ? '#246' : '#9B9B1B';
    ?>
      <a href="<?= htmlspecialchars($btnUrl) ?>"
         class="no-print"
         title="You manage this project, so you can flip its hours between &quot;your perspective&quot; (scaled to your rate) and &quot;per-assignee&quot; (each task scaled to whoever's assigned)."
         style="font-size:11px;background:<?= $btnBg ?>;color:#fff !important;padding:3px 8px;border-radius:3px;text-decoration:none;margin-left:6px"
         onclick="event.stopPropagation()"><?= $btnText ?></a>
    <?php endif; ?>
    <span class="toggle">▾</span>
  </h2>
  <div class="proj-body">
  <div class="proj-meta">
    Manager: <?= htmlspecialchars($p['ManagerLogin'] ?? '?') ?>
    <?php if ($projectPerspective[$pid] === 'assigned'): ?>
      &middot; <span style="background:#246;color:#fff;padding:1px 6px;border-radius:3px;font-size:10px">PER-ASSIGNEE VIEW</span>
    <?php endif; ?>
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
          <?php if (!empty($t['assigned_login'])): ?>
            <span style="color:#666;font-size:10px"> · assigned to <?= htmlspecialchars($t['assigned_login']) ?></span>
          <?php endif; ?>
          <?php if ($t['has_ratio']):
              // Tooltip explains which perspective this task was scaled in.
              // Deliberately rate-free — staff don't need to see each
              // other's billing numbers; only the resulting ratio matters.
              if (($t['perspective'] ?? 'viewer') === 'viewer' && $viewerRate > 0) {
                  $ratioPct = number_format($t['quoted_rate'] / $viewerRate, 2);
                  $tip = "Hour budget for this task is {$ratioPct}× the quoted hours, scaled to your rate.";
              } else {
                  $ratioPct = !empty($t['assigned_rate']) && $t['assigned_rate'] > 0
                      ? number_format($t['quoted_rate'] / $t['assigned_rate'], 2)
                      : 'n/a';
                  $tip = "Hour budget scales by {$ratioPct}× for the assignee's rate.";
              }
          ?>
            <span style="color:#999;font-size:10px;cursor:help" title="<?= htmlspecialchars($tip) ?>"> ⓘ</span>
          <?php endif; ?>
        </td>
        <td class="right">
          <?php if ($t['has_ratio']): ?>
            <strong><?= number_format($t['scaled_est'], 2) ?>h</strong>
            <span style="color:#999;font-size:10px;text-decoration:line-through;display:block"><?= number_format($t['est'], 2) ?>h quoted</span>
          <?php else: ?>
            <?= number_format($t['est'], 2) ?>h
          <?php endif; ?>
        </td>
        <td class="right"><?= number_format($t['logged'], 2) ?>h</td>
        <td class="right">
          <?php if ($t['has_ratio']): ?>
            <strong><?= number_format($t['scaled_rem'], 2) ?>h</strong>
          <?php else: ?>
            <?= number_format($t['remaining'], 2) ?>h
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  </div><!-- /proj-body -->
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
// Optional deep-link: my_checklist.php#proj-1234 expands and scrolls to that card
(function() {
    var hash = window.location.hash;
    if (hash && hash.indexOf('#proj-') === 0) {
        var el = document.getElementById(hash.substring(1));
        if (el) {
            el.classList.remove('collapsed');
            setTimeout(function() { el.scrollIntoView({behavior:'smooth', block:'start'}); }, 50);
        }
    }
})();

<?php if ($autoPrint): ?>
// Single-project print mode: open the print dialog after the page renders
window.addEventListener('load', function() { setTimeout(function(){ window.print(); }, 300); });
<?php endif; ?>
</script>
</body>
</html>
