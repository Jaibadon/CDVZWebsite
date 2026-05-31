<?php
/**
 * staff_workload.php — "who has how much work stacked up".
 *
 * Summary of estimated task hours STILL TO BE DONE, per assigned staff member,
 * across all ACTIVE projects. Answers Erik's "who's going to need something to
 * do soon?" without going by gut feel.
 *
 * Per task:  remaining = Tasks_Types.Estimated_Time × COALESCE(Weight,1) − hours logged
 * (same math as my_checklist.php). A task over its estimate counts as 0 (no
 * work left). Tasks with no assignee fall into an "Unassigned / TBA" bucket so
 * unallocated work is visible too.
 *
 * Non-quoted / ongoing jobs: they only show up here once they have tasks with
 * an Estimated_Time. Add or rough-estimate tasks via the project's quote
 * builder (project_stages.php) and they'll appear.
 *
 * Admin only (erik / jen) — matches staff_hours.php.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo    = get_db();
$userID = $_SESSION['UserID'] ?? '';
if (!in_array($userID, ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

// ── Feature-detect the columns the newer migrations added ─────────────────
$hasPtid = false;       // Timesheets.Proj_Task_ID — lets us tie logged hours to a task
$hasRemoved = false;    // Project_Tasks.Is_Removed — soft-deleted original-quote tasks
try { $hasPtid    = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Proj_Task_ID'")->fetch(); } catch (Exception $e) {}
try { $hasRemoved = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Is_Removed'")->fetch(); } catch (Exception $e) {}

$loggedJoin = $hasPtid
    ? "LEFT JOIN (SELECT Proj_Task_ID, SUM(Hours) AS hrs
                    FROM Timesheets
                   WHERE Proj_Task_ID IS NOT NULL AND Proj_Task_ID > 0
                   GROUP BY Proj_Task_ID) l ON l.Proj_Task_ID = pt.Proj_Task_ID"
    : "";
$loggedCol     = $hasPtid ? "COALESCE(l.hrs, 0)" : "0";
$removedFilter = $hasRemoved ? "AND COALESCE(pt.Is_Removed, 0) = 0" : "";

// ── One task-grain query over all active projects ─────────────────────────
$sql = "SELECT p.proj_id, p.JobName,
               pt.Assigned_To,
               COALESCE(pt.Weight, 1) AS W,
               tt.Estimated_Time      AS Est,
               $loggedCol             AS Logged
          FROM Project_Tasks pt
          JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
          JOIN Projects       p  ON ps.Proj_ID          = p.proj_id
          JOIN Tasks_Types    tt ON pt.Task_Type_ID     = tt.Task_ID
          $loggedJoin
         WHERE p.Active <> 0
           $removedFilter";

$rows = [];
try { $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
catch (Exception $e) { $rows = []; $dbErr = $e->getMessage(); }

// ── Staff display names (Employee_ID → "First Last" or Login) ─────────────
$names = [];
try {
    foreach ($pdo->query("SELECT Employee_ID, Login, `First Name` AS fn, `Last Name` AS ln FROM Staff")->fetchAll() as $s) {
        $disp = trim((string)($s['fn'] ?? '') . ' ' . (string)($s['ln'] ?? ''));
        $names[(int)$s['Employee_ID']] = $disp !== '' ? $disp : (string)$s['Login'];
    }
} catch (Exception $e) {}

// ── Aggregate per assignee (emp 0 = Unassigned / TBA) ─────────────────────
$byStaff = [];   // emp => ['remaining'=>float, 'tasks'=>int, 'projects'=>[pid=>['name','remaining','tasks']]]
$grand = 0.0;
foreach ($rows as $r) {
    $est       = (float)$r['Est'] * (float)$r['W'];
    $remaining = $est - (float)$r['Logged'];
    if ($remaining < 0.05) continue;   // task complete / over budget → no work left

    $emp = (int)($r['Assigned_To'] ?? 0);
    if (!isset($byStaff[$emp])) $byStaff[$emp] = ['remaining' => 0.0, 'tasks' => 0, 'projects' => []];
    $byStaff[$emp]['remaining'] += $remaining;
    $byStaff[$emp]['tasks']++;

    $pid = (int)$r['proj_id'];
    if (!isset($byStaff[$emp]['projects'][$pid])) {
        $byStaff[$emp]['projects'][$pid] = ['name' => (string)$r['JobName'], 'remaining' => 0.0, 'tasks' => 0];
    }
    $byStaff[$emp]['projects'][$pid]['remaining'] += $remaining;
    $byStaff[$emp]['projects'][$pid]['tasks']++;
    $grand += $remaining;
}

// Sort staff by remaining hours, busiest first.
uasort($byStaff, fn($a, $b) => $b['remaining'] <=> $a['remaining']);

function wl_name(int $emp, array $names): string {
    if ($emp === 0) return 'Unassigned / TBA';
    return $names[$emp] ?? ('Staff #' . $emp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff workload — hours remaining</title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:880px; margin:0 auto; padding:16px; }
.note { color:#666; font-size:12px; background:#fff; border:1px solid #e6e6c8; border-left:3px solid #9B9B1B; padding:8px 12px; border-radius:4px; margin:12px 0; }
table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #ddd; border-radius:5px; overflow:hidden; }
th, td { padding:8px 10px; text-align:left; border-bottom:1px solid #eee; }
th { background:#f4f4f4; font-size:12px; }
td.right, th.right { text-align:right; }
tr:hover td { background:#fafad2; }
.hrs { font-weight:700; }
.bar { display:inline-block; height:9px; background:#9B9B1B; border-radius:2px; vertical-align:middle; }
.unassigned td { background:#fff4e5; }
details { background:#fff; border:1px solid #ddd; border-radius:5px; margin:8px 0; padding:0 12px; }
details > summary { cursor:pointer; padding:9px 0; font-weight:600; list-style:none; display:flex; justify-content:space-between; }
details > summary::-webkit-details-marker { display:none; }
details .proj { display:flex; justify-content:space-between; padding:5px 0 5px 14px; border-top:1px solid #f0f0f0; font-size:12px; }
details .proj a { color:#246; text-decoration:none; }
.muted { color:#999; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📊 Staff workload — estimated hours remaining</h1>
  <div><a href="menu.php">Menu</a> &nbsp;·&nbsp; <a href="staff_hours.php">Uninvoiced hours</a></div>
</div>

<div class="page">

  <div class="note">
    Estimated task hours still to be done per staff member, across <strong>all active projects</strong>.
    Remaining = a task's estimated hours (estimate × weight) minus hours already logged; tasks over their
    estimate count as zero. Ongoing / non-quoted jobs appear here once they have tasks with an estimate —
    add or rough-estimate them via a project's <em>quote builder</em>.
    <?php if (!$hasPtid): ?><br><strong>Note:</strong> the <code>Timesheets.Proj_Task_ID</code> column isn't present, so logged hours can't be subtracted — figures below are full estimates, not net-of-work-done.<?php endif; ?>
  </div>

  <?php if (!empty($dbErr)): ?>
    <p style="color:#a00">Couldn't load workload: <?= htmlspecialchars($dbErr) ?></p>
  <?php elseif (empty($byStaff)): ?>
    <p class="muted">No outstanding estimated task hours on active projects. (Either everything's logged, or active projects don't have estimated tasks yet.)</p>
  <?php else:
      $max = 0.0; foreach ($byStaff as $d) $max = max($max, $d['remaining']);
  ?>
    <table>
      <thead>
        <tr><th>Staff</th><th class="right">Open tasks</th><th class="right">Projects</th><th class="right">Hours remaining</th><th style="width:160px"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($byStaff as $emp => $d):
            $w = $max > 0 ? round(140 * $d['remaining'] / $max) : 0;
        ?>
          <tr class="<?= $emp === 0 ? 'unassigned' : '' ?>">
            <td><?= htmlspecialchars(wl_name((int)$emp, $names)) ?></td>
            <td class="right"><?= (int)$d['tasks'] ?></td>
            <td class="right"><?= count($d['projects']) ?></td>
            <td class="right hrs"><?= number_format($d['remaining'], 1) ?>h</td>
            <td><span class="bar" style="width:<?= $w ?>px"></span></td>
          </tr>
        <?php endforeach; ?>
        <tr><td colspan="3" style="text-align:right"><strong>TOTAL outstanding</strong></td><td class="right hrs"><?= number_format($grand, 1) ?>h</td><td></td></tr>
      </tbody>
    </table>

    <h3 style="margin:22px 0 6px;font-weight:500;color:#666">Breakdown by staff → project</h3>
    <?php foreach ($byStaff as $emp => $d):
        uasort($d['projects'], fn($a, $b) => $b['remaining'] <=> $a['remaining']);
    ?>
      <details>
        <summary>
          <span><?= htmlspecialchars(wl_name((int)$emp, $names)) ?></span>
          <span class="hrs"><?= number_format($d['remaining'], 1) ?>h · <?= (int)$d['tasks'] ?> tasks</span>
        </summary>
        <?php foreach ($d['projects'] as $pid => $pr): ?>
          <div class="proj">
            <span>
              <a href="project_stages.php?proj_id=<?= (int)$pid ?>" title="Open quote builder / tasks"><?= htmlspecialchars($pr['name']) ?></a>
              &nbsp;<a class="muted" href="my_checklist.php?proj_id=<?= (int)$pid ?>" title="Checklist">checklist ↗</a>
            </span>
            <span><?= number_format($pr['remaining'], 1) ?>h · <?= (int)$pr['tasks'] ?> tasks</span>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
</body>
</html>
