<?php
/**
 * Task analytics: actual hours vs estimated hours.
 *
 * Joins Project_Tasks (with Tasks_Types.Estimated_Time × weights) against
 * Timesheets (actual hours). Reports per Task_Type, Stage_Type, Project_Type,
 * and Manager — with mean/median/stddev/n and red flags above 110%.
 *
 * Note: Timesheets.Task is free text. We text-match against Tasks_Types.Task_Name
 * (lowercased + trimmed). Unmatched timesheet rows are surfaced separately.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();

// ── Filter: which scope of work to analyze ────────────────────────────────
$filter = $_GET['filter'] ?? 'original';  // original | variations | all
if (!in_array($filter, ['original','variations','all'], true)) $filter = 'original';

// Detect variation columns once
$hasVariations = false;
try {
    $hasVariations = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Variation_ID'")->fetch();
} catch (Exception $e) { /* ignore */ }

// SQL fragments for filtering Project_Tasks and Timesheets by scope
$ptFilter = '';
$tsFilter = '';
if ($hasVariations) {
    if ($filter === 'original') {
        $ptFilter = " AND pt.Variation_ID IS NULL AND COALESCE(pt.Is_Removed,0) = 0";
        $tsFilter = " AND ts.Variation_ID IS NULL";
    } elseif ($filter === 'variations') {
        $ptFilter = " AND pt.Variation_ID IS NOT NULL";
        $tsFilter = " AND ts.Variation_ID IS NOT NULL";
    }
}

// ── POST: instant-apply template hour change ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_task_estimate') {
    $tid = (int)($_POST['Task_ID'] ?? 0);
    $newEst = (float)($_POST['Estimated_Time'] ?? 0);
    if ($tid > 0 && $newEst >= 0) {
        $pdo->prepare("UPDATE Tasks_Types SET Estimated_Time = ? WHERE Task_ID = ?")
            ->execute([$newEst, $tid]);
    }
    header('Location: task_analytics.php');
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────
function stats(array $vals): array {
    $n = count($vals);
    if ($n === 0) return ['n' => 0, 'mean' => 0, 'median' => 0, 'stddev' => 0, 'over110' => 0];
    sort($vals);
    $mean = array_sum($vals) / $n;
    $median = ($n % 2 === 1)
        ? $vals[intdiv($n, 2)]
        : (($vals[$n/2 - 1] + $vals[$n/2]) / 2);
    $var = 0;
    foreach ($vals as $v) $var += ($v - $mean) ** 2;
    $stddev = $n > 1 ? sqrt($var / ($n - 1)) : 0;
    $over110 = count(array_filter($vals, fn($v) => $v > 1.10));
    return ['n' => $n, 'mean' => $mean, 'median' => $median, 'stddev' => $stddev, 'over110' => $over110];
}

function pct(float $r): string {
    return number_format($r * 100, 0) . '%';
}

function ratioClass(float $r): string {
    if ($r > 1.10) return 'r-bad';
    if ($r > 1.00) return 'r-warn';
    if ($r > 0)    return 'r-ok';
    return '';
}

// ── Load all projects with estimated hours ─────────────────────────────────
$projStmt = $pdo->query(
    "SELECT p.proj_id, p.JobName, p.Project_Type, p.Manager,
            pt_name.Project_Type_Name,
            mgr.Login AS ManagerLogin,
            COALESCE(est.estimated, 0) AS estimated
       FROM Projects p
       LEFT JOIN Project_Types pt_name ON p.Project_Type = pt_name.Project_Type_ID
       LEFT JOIN Staff mgr ON p.Manager = mgr.Employee_ID
       LEFT JOIN (
            SELECT ps.Proj_ID, SUM(tt.Estimated_Time * COALESCE(pt.Weight,1)) AS estimated
              FROM Project_Tasks pt
              JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
              JOIN Tasks_Types tt   ON pt.Task_Type_ID = tt.Task_ID
             WHERE 1=1 $ptFilter
             GROUP BY ps.Proj_ID
       ) est ON est.Proj_ID = p.proj_id"
);
$projects = [];
foreach ($projStmt->fetchAll() as $r) {
    $projects[(int)$r['proj_id']] = [
        'proj_id'       => (int)$r['proj_id'],
        'JobName'       => $r['JobName'],
        'Project_Type'  => $r['Project_Type_Name'] ?: '— none —',
        'Manager'       => $r['ManagerLogin'] ?: '— none —',
        'estimated'     => (float)$r['estimated'],
        'actual'        => 0.0,
    ];
}

// ── Sum actual timesheet hours per project (scope-filtered) ───────────────
$actualWhere = '1=1';
if ($hasVariations) {
    if ($filter === 'original') $actualWhere .= ' AND ts.Variation_ID IS NULL';
    elseif ($filter === 'variations') $actualWhere .= ' AND ts.Variation_ID IS NOT NULL';
}
$tsAgg = $pdo->query("SELECT ts.proj_id, SUM(ts.Hours) AS actual FROM Timesheets ts WHERE $actualWhere GROUP BY ts.proj_id")->fetchAll();
foreach ($tsAgg as $r) {
    $pid = (int)$r['proj_id'];
    if (isset($projects[$pid])) $projects[$pid]['actual'] = (float)$r['actual'];
}

// ── Group projects by Project_Type, Manager ────────────────────────────────
$byProjectType = [];
$byManager = [];
foreach ($projects as $p) {
    if ($p['estimated'] <= 0 || $p['actual'] <= 0) continue;
    $ratio = $p['actual'] / $p['estimated'];
    $byProjectType[$p['Project_Type']][] = $ratio;
    $byManager[$p['Manager']][] = $ratio;
}

// ── Per-task aggregates ────────────────────────────────────────────────────
// estimated per project per task type:
$pttStmt = $pdo->query(
    "SELECT ps.Proj_ID, pt.Task_Type_ID, tt.Task_Name, tt.Stage_ID,
            stg.Stage_Type_Name,
            SUM(tt.Estimated_Time * COALESCE(pt.Weight,1)) AS est_hrs
       FROM Project_Tasks pt
       JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
       JOIN Tasks_Types tt   ON pt.Task_Type_ID = tt.Task_ID
       LEFT JOIN Stage_Types stg ON tt.Stage_ID = stg.Stage_Type_ID
      WHERE 1=1 $ptFilter
      GROUP BY ps.Proj_ID, pt.Task_Type_ID"
);
$taskEst = []; // [proj_id][task_id] => ['name'=>, 'stage'=>, 'est'=>]
foreach ($pttStmt->fetchAll() as $r) {
    $taskEst[(int)$r['Proj_ID']][(int)$r['Task_Type_ID']] = [
        'name'  => $r['Task_Name'],
        'stage' => $r['Stage_Type_Name'] ?: '— none —',
        'est'   => (float)$r['est_hrs'],
    ];
}

// Prefer Task_Type_ID FK (set by main.php's grouped task picker); fall back
// to lowercase-trim text match for legacy rows where the FK is NULL.
$hasTaskTypeId = false;
try {
    $hasTaskTypeId = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Task_Type_ID'")->fetch();
} catch (Exception $e) { /* ignore */ }

if ($hasTaskTypeId) {
    $tsTaskStmt = $pdo->query(
        "SELECT ts.proj_id,
                ts.Task_Type_ID,
                LOWER(TRIM(ts.Task)) AS task_key,
                ts.Task AS task_raw,
                ts.Employee_id,
                SUM(ts.Hours) AS actual_hrs
           FROM Timesheets ts
          WHERE ts.Hours > 0 $tsFilter
          GROUP BY ts.proj_id, ts.Task_Type_ID, LOWER(TRIM(ts.Task)), ts.Employee_id"
    );
} else {
    $tsTaskStmt = $pdo->query(
        "SELECT ts.proj_id,
                NULL AS Task_Type_ID,
                LOWER(TRIM(ts.Task)) AS task_key,
                ts.Task AS task_raw,
                ts.Employee_id,
                SUM(ts.Hours) AS actual_hrs
           FROM Timesheets ts
          WHERE ts.Hours > 0 $tsFilter
          GROUP BY ts.proj_id, LOWER(TRIM(ts.Task)), ts.Employee_id"
    );
}
// build a name → task_id lookup (lowercased)
$taskByName = [];
foreach ($pdo->query("SELECT Task_ID, Task_Name, Stage_ID FROM Tasks_Types")->fetchAll() as $r) {
    $taskByName[strtolower(trim($r['Task_Name']))] = ['id' => (int)$r['Task_ID'], 'stage' => (int)$r['Stage_ID'], 'name' => $r['Task_Name']];
}
$stageNames = [];
foreach ($pdo->query("SELECT Stage_Type_ID, Stage_Type_Name FROM Stage_Types")->fetchAll() as $r) {
    $stageNames[(int)$r['Stage_Type_ID']] = $r['Stage_Type_Name'];
}

// Build per-task ratios + collect actuals
$byTaskType = [];   // task_id => [ratios, name, est_total, act_total, samples]
$byStageType = [];  // stage_name => ratios
$staffOverrunTotals = []; // emp_id => ['name'=>, 'over_hours'=>0, 'projects'=>[]]
$tsRows = $tsTaskStmt->fetchAll();

// Build a lookup of staff
$staffMap = [];
foreach ($pdo->query("SELECT Employee_ID, Login FROM Staff")->fetchAll() as $r) {
    $staffMap[(int)$r['Employee_ID']] = $r['Login'];
}

// Project-task actuals (sum across employees) for ratio calc
$projTaskActual = []; // [proj_id][task_id] => actual_hrs
$unmatchedHours = 0;
foreach ($tsRows as $r) {
    $pid = (int)$r['proj_id'];
    // Prefer FK; fall back to text match
    $tid = null;
    if (!empty($r['Task_Type_ID'])) {
        $tid = (int)$r['Task_Type_ID'];
    } elseif ($r['task_key'] !== '' && isset($taskByName[$r['task_key']])) {
        $tid = $taskByName[$r['task_key']]['id'];
    }
    if (!$tid) {
        $unmatchedHours += (float)$r['actual_hrs'];
        continue;
    }
    $projTaskActual[$pid][$tid] = ($projTaskActual[$pid][$tid] ?? 0) + (float)$r['actual_hrs'];
}

// Now compute per-task ratios across projects
foreach ($projTaskActual as $pid => $tasks) {
    if (!isset($taskEst[$pid])) continue;
    foreach ($tasks as $tid => $actualHrs) {
        if (!isset($taskEst[$pid][$tid])) continue;
        $est = $taskEst[$pid][$tid]['est'];
        if ($est <= 0) continue;
        $ratio = $actualHrs / $est;
        $name  = $taskEst[$pid][$tid]['name'];
        $stage = $taskEst[$pid][$tid]['stage'];
        if (!isset($byTaskType[$tid])) {
            $byTaskType[$tid] = ['name' => $name, 'stage' => $stage, 'ratios' => [], 'est_total' => 0, 'act_total' => 0];
        }
        $byTaskType[$tid]['ratios'][] = $ratio;
        $byTaskType[$tid]['est_total'] += $est;
        $byTaskType[$tid]['act_total'] += $actualHrs;
        $byStageType[$stage][] = $ratio;
    }
}

// ── Top 5 overrun tasks (this month + 12 months) ───────────────────────────
$thisMonthStart = date('Y-m-01');
$twelveMonthStart = date('Y-m-01', strtotime('-11 months'));

function buildTopOverrun(PDO $pdo, string $since, array $taskByName, array $taskEst, bool $hasFK, string $tsFilter = ''): array {
    if ($hasFK) {
        $sql = "SELECT ts.proj_id, ts.Task_Type_ID AS tid, LOWER(TRIM(ts.Task)) AS k, SUM(ts.Hours) AS hrs
                  FROM Timesheets ts
                 WHERE ts.TS_DATE >= ? AND ts.Hours > 0 $tsFilter
                 GROUP BY ts.proj_id, ts.Task_Type_ID, LOWER(TRIM(ts.Task))";
    } else {
        $sql = "SELECT ts.proj_id, NULL AS tid, LOWER(TRIM(ts.Task)) AS k, SUM(ts.Hours) AS hrs
                  FROM Timesheets ts
                 WHERE ts.TS_DATE >= ? AND ts.Hours > 0 $tsFilter
                 GROUP BY ts.proj_id, LOWER(TRIM(ts.Task))";
    }
    $rows = $pdo->prepare($sql);
    $rows->execute([$since]);
    $agg = [];
    foreach ($rows->fetchAll() as $r) {
        $pid = (int)$r['proj_id'];
        $tid = !empty($r['tid']) ? (int)$r['tid'] : (isset($taskByName[$r['k']]) ? $taskByName[$r['k']]['id'] : null);
        if (!$tid || !isset($taskEst[$pid][$tid])) continue;
        $est = $taskEst[$pid][$tid]['est'];
        if ($est <= 0) continue;
        $actual = (float)$r['hrs'];
        $over = $actual - $est;
        if (!isset($agg[$tid])) {
            $agg[$tid] = ['name' => $taskEst[$pid][$tid]['name'], 'over' => 0, 'est' => 0, 'act' => 0, 'n' => 0];
        }
        $agg[$tid]['over'] += max(0, $over);
        $agg[$tid]['est']  += $est;
        $agg[$tid]['act']  += $actual;
        $agg[$tid]['n']++;
    }
    uasort($agg, fn($a, $b) => $b['over'] <=> $a['over']);
    return array_slice($agg, 0, 5, true);
}

function buildTopStaffOverrun(PDO $pdo, string $since, array $taskByName, array $taskEst, array $staffMap, bool $hasFK, string $tsFilter = ''): array {
    if ($hasFK) {
        $sql = "SELECT ts.proj_id, ts.Employee_id, ts.Task_Type_ID AS tid, LOWER(TRIM(ts.Task)) AS k, SUM(ts.Hours) AS hrs
                  FROM Timesheets ts
                 WHERE ts.TS_DATE >= ? AND ts.Hours > 0 $tsFilter
                 GROUP BY ts.proj_id, ts.Employee_id, ts.Task_Type_ID, LOWER(TRIM(ts.Task))";
    } else {
        $sql = "SELECT ts.proj_id, ts.Employee_id, NULL AS tid, LOWER(TRIM(ts.Task)) AS k, SUM(ts.Hours) AS hrs
                  FROM Timesheets ts
                 WHERE ts.TS_DATE >= ? AND ts.Hours > 0 $tsFilter
                 GROUP BY ts.proj_id, ts.Employee_id, LOWER(TRIM(ts.Task))";
    }
    $rows = $pdo->prepare($sql);
    $rows->execute([$since]);
    $totalPT = [];
    $perPTE = [];
    foreach ($rows->fetchAll() as $r) {
        $pid = (int)$r['proj_id']; $eid = (int)$r['Employee_id'];
        $tid = !empty($r['tid']) ? (int)$r['tid'] : (isset($taskByName[$r['k']]) ? $taskByName[$r['k']]['id'] : null);
        if (!$tid || !isset($taskEst[$pid][$tid])) continue;
        $hrs = (float)$r['hrs'];
        $totalPT["$pid|$tid"] = ($totalPT["$pid|$tid"] ?? 0) + $hrs;
        $perPTE[] = ['pid'=>$pid,'tid'=>$tid,'eid'=>$eid,'hrs'=>$hrs];
    }
    $agg = []; // emp => over_hours
    foreach ($perPTE as $row) {
        $key = $row['pid'].'|'.$row['tid'];
        $share = $totalPT[$key] > 0 ? ($row['hrs'] / $totalPT[$key]) : 0;
        $est = $taskEst[$row['pid']][$row['tid']]['est'] * $share;
        $over = max(0, $row['hrs'] - $est);
        if ($over <= 0) continue;
        $agg[$row['eid']] = ($agg[$row['eid']] ?? 0) + $over;
    }
    arsort($agg);
    $out = [];
    foreach (array_slice($agg, 0, 5, true) as $eid => $over) {
        $out[$eid] = ['name' => $staffMap[$eid] ?? ('emp #'.$eid), 'over' => $over];
    }
    return $out;
}

$topTasksMonth = buildTopOverrun($pdo, $thisMonthStart, $taskByName, $taskEst, $hasTaskTypeId, $tsFilter);
$topTasks12mo  = buildTopOverrun($pdo, $twelveMonthStart, $taskByName, $taskEst, $hasTaskTypeId, $tsFilter);
$topStaffMonth = buildTopStaffOverrun($pdo, $thisMonthStart, $taskByName, $taskEst, $staffMap, $hasTaskTypeId, $tsFilter);
$topStaff12mo  = buildTopStaffOverrun($pdo, $twelveMonthStart, $taskByName, $taskEst, $staffMap, $hasTaskTypeId, $tsFilter);

// ── Template-needs-adjustment: recommended new estimates ───────────────────
$tpAdj = [];
foreach ($byTaskType as $tid => $d) {
    if (count($d['ratios']) < 3) continue; // need n>=3 to recommend
    $s = stats($d['ratios']);
    if ($s['median'] < 0.85 || $s['median'] > 1.15) {
        // current Estimated_Time
        $cur = (float)$pdo->query("SELECT Estimated_Time FROM Tasks_Types WHERE Task_ID = " . (int)$tid)->fetchColumn();
        if ($cur > 0) {
            $rec = round($cur * $s['median'], 2);
            $tpAdj[] = [
                'task_id'  => $tid,
                'name'     => $d['name'],
                'stage'    => $d['stage'],
                'current'  => $cur,
                'recommended' => $rec,
                'median'   => $s['median'],
                'n'        => $s['n'],
                'stddev'   => $s['stddev'],
            ];
        }
    }
}
usort($tpAdj, fn($a,$b) => abs($b['median'] - 1) <=> abs($a['median'] - 1));

// ── Pre-compute project-level aggregates table data ────────────────────────
$projectRows = [];
foreach ($projects as $p) {
    if ($p['estimated'] <= 0 && $p['actual'] <= 0) continue;
    $r = ($p['estimated'] > 0) ? $p['actual'] / $p['estimated'] : 0;
    $projectRows[] = [
        'proj_id' => $p['proj_id'],
        'JobName' => $p['JobName'],
        'Project_Type' => $p['Project_Type'],
        'Manager' => $p['Manager'],
        'estimated' => $p['estimated'],
        'actual' => $p['actual'],
        'ratio' => $r,
    ];
}

// JSON for charts
$chartProjectType = [];
foreach ($byProjectType as $name => $ratios) {
    $s = stats($ratios);
    $chartProjectType[] = ['label' => $name, 'median' => round($s['median']*100,1), 'mean' => round($s['mean']*100,1), 'n' => $s['n']];
}
$chartManager = [];
foreach ($byManager as $name => $ratios) {
    $s = stats($ratios);
    $chartManager[] = ['label' => $name, 'median' => round($s['median']*100,1), 'mean' => round($s['mean']*100,1), 'n' => $s['n']];
}
$chartStage = [];
foreach ($byStageType as $name => $ratios) {
    $s = stats($ratios);
    $chartStage[] = ['label' => $name, 'median' => round($s['median']*100,1), 'mean' => round($s['mean']*100,1), 'n' => $s['n']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Task Analytics – Actual vs Estimated</title>
<link href="site.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.r-bad  { background:#ffd6d6; color:#a00; font-weight:bold; }
.r-warn { background:#fff3cd; color:#7a5a00; }
.r-ok   { background:#d6f5d6; color:#1a6b1a; }
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px; margin:10px 0; }
.kpi { background:#fff; border:1px solid #ddd; border-radius:6px; padding:10px; }
.kpi h4 { margin:0 0 4px; color:#9B9B1B; font-size:12px; text-transform:uppercase; letter-spacing:.5px; }
.kpi .val { font-size:22px; font-weight:bold; color:#333; }
.kpi .sub { font-size:11px; color:#777; }
.chart-wrap { background:#fff; border:1px solid #ddd; border-radius:6px; padding:10px; margin:10px 0; }
.chart-wrap canvas { max-height:300px; }
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width:780px) { .two-col { grid-template-columns:1fr; } }
table.analytics { border-collapse:collapse; width:100%; background:#fff; margin:6px 0 18px; font-size:12px; }
table.analytics th { background:#9B9B1B; color:#fff; padding:6px 8px; text-align:left; cursor:pointer; user-select:none; }
table.analytics th:hover { background:#7a7a16; }
table.analytics td { padding:5px 8px; border-top:1px solid #eee; }
table.analytics tr:nth-child(even) td { background:#fafafa; }
.subtle { color:#888; font-size:11px; }
.tag { display:inline-block; background:#eef; padding:1px 6px; border-radius:8px; font-size:10px; color:#446; }
</style>
</head>
<body>
<div class="page">
<div class="topnav">
  <a href="menu.php">&larr; Main Menu</a>
  <h1>Task Analytics — Actual vs Estimated</h1>
  <?php if ($hasVariations): ?>
  <div style="margin-left:auto">
    <strong>Scope:</strong>
    <a href="?filter=original" style="<?= $filter==='original'?'background:#9B9B1B;color:#fff;':'' ?>padding:3px 8px;border-radius:3px;text-decoration:none">Original quote</a>
    <a href="?filter=variations" style="<?= $filter==='variations'?'background:#c33;color:#fff;':'' ?>padding:3px 8px;border-radius:3px;text-decoration:none">Variations only</a>
    <a href="?filter=all" style="<?= $filter==='all'?'background:#555;color:#fff;':'' ?>padding:3px 8px;border-radius:3px;text-decoration:none">All work</a>
  </div>
  <?php endif; ?>
</div>

<?php
$totalProjects = count($projects);
$projWithBoth = count($projectRows);
$totalActualAll = array_sum(array_column($projectRows, 'actual'));
$totalEstAll    = array_sum(array_column($projectRows, 'estimated'));
$globalRatio    = $totalEstAll > 0 ? $totalActualAll / $totalEstAll : 0;
$badProjects    = count(array_filter($projectRows, fn($p) => $p['ratio'] > 1.10));
?>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi"><h4>Projects analysed</h4><div class="val"><?= $projWithBoth ?></div><div class="sub">of <?= $totalProjects ?> total (need both estimated & actual hrs)</div></div>
  <div class="kpi"><h4>Global actual / estimated</h4><div class="val <?= ratioClass($globalRatio) ?>"><?= pct($globalRatio) ?></div><div class="sub"><?= number_format($totalActualAll,0) ?>h actual / <?= number_format($totalEstAll,0) ?>h estimated</div></div>
  <div class="kpi"><h4>Projects &gt; 110%</h4><div class="val r-bad"><?= $badProjects ?></div><div class="sub"><?= $projWithBoth > 0 ? round(100*$badProjects/$projWithBoth) : 0 ?>% of analysed projects</div></div>
  <div class="kpi"><h4>Unmatched timesheet hours</h4><div class="val"><?= number_format($unmatchedHours, 0) ?>h</div><div class="sub">Task text didn't match any Tasks_Types name</div></div>
</div>

<!-- Charts -->
<div class="two-col">
  <div class="chart-wrap"><h3>By Project Type (median %)</h3><canvas id="chartPT"></canvas></div>
  <div class="chart-wrap"><h3>By Manager (median %)</h3><canvas id="chartMgr"></canvas></div>
</div>
<div class="chart-wrap"><h3>By Stage Type (median %)</h3><canvas id="chartStg"></canvas></div>

<!-- Top overruns -->
<div class="two-col">
  <div>
    <h3>Top 5 overrun tasks (this month)</h3>
    <table class="analytics">
      <tr><th>Task</th><th>Hrs over</th><th>n</th></tr>
      <?php foreach ($topTasksMonth as $row): ?>
      <tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= number_format($row['over'],1) ?>h</td><td><?= $row['n'] ?></td></tr>
      <?php endforeach; if (empty($topTasksMonth)): ?><tr><td colspan="3" class="subtle">No data</td></tr><?php endif; ?>
    </table>

    <h3>Top 5 overrun tasks (12 months)</h3>
    <table class="analytics">
      <tr><th>Task</th><th>Hrs over</th><th>n</th></tr>
      <?php foreach ($topTasks12mo as $row): ?>
      <tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= number_format($row['over'],1) ?>h</td><td><?= $row['n'] ?></td></tr>
      <?php endforeach; if (empty($topTasks12mo)): ?><tr><td colspan="3" class="subtle">No data</td></tr><?php endif; ?>
    </table>
  </div>
  <div>
    <h3>Top 5 staff causing overruns (this month)</h3>
    <table class="analytics">
      <tr><th>Staff</th><th>Hrs over</th></tr>
      <?php foreach ($topStaffMonth as $row): ?>
      <tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= number_format($row['over'],1) ?>h</td></tr>
      <?php endforeach; if (empty($topStaffMonth)): ?><tr><td colspan="2" class="subtle">No data</td></tr><?php endif; ?>
    </table>

    <h3>Top 5 staff causing overruns (12 months)</h3>
    <table class="analytics">
      <tr><th>Staff</th><th>Hrs over</th></tr>
      <?php foreach ($topStaff12mo as $row): ?>
      <tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= number_format($row['over'],1) ?>h</td></tr>
      <?php endforeach; if (empty($topStaff12mo)): ?><tr><td colspan="2" class="subtle">No data</td></tr><?php endif; ?>
    </table>
  </div>
</div>

<!-- Template adjustments -->
<h2>Template task hours that need adjustment</h2>
<p class="subtle">Tasks where the median actual/estimated ratio is outside ±15% across at least 3 projects. Click "Apply" to instantly update <code>Tasks_Types.Estimated_Time</code>.</p>
<table class="analytics" data-sortable>
  <thead><tr>
    <th>Task</th><th>Stage</th><th>Current</th><th>Median ratio</th><th>n</th><th>Std dev</th><th>Recommended</th><th>Apply</th>
  </tr></thead>
  <tbody>
  <?php foreach ($tpAdj as $a): ?>
    <tr>
      <td><?= htmlspecialchars($a['name']) ?></td>
      <td><?= htmlspecialchars($a['stage']) ?></td>
      <td><?= number_format($a['current'],2) ?>h</td>
      <td class="<?= ratioClass($a['median']) ?>"><?= pct($a['median']) ?></td>
      <td><?= $a['n'] ?></td>
      <td><?= number_format($a['stddev'],2) ?></td>
      <td><strong><?= number_format($a['recommended'],2) ?>h</strong></td>
      <td>
        <form method="post" style="margin:0" onsubmit="return confirm('Update <?= htmlspecialchars(addslashes($a['name'])) ?> from <?= $a['current'] ?>h to <?= $a['recommended'] ?>h?');">
          <input type="hidden" name="action" value="update_task_estimate">
          <input type="hidden" name="Task_ID" value="<?= (int)$a['task_id'] ?>">
          <input type="hidden" name="Estimated_Time" value="<?= $a['recommended'] ?>">
          <input type="submit" value="Apply" class="btn-primary">
        </form>
      </td>
    </tr>
  <?php endforeach; if (empty($tpAdj)): ?>
    <tr><td colspan="8" class="subtle">No tasks meet the adjustment threshold yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<!-- Grouped breakdown tables -->
<h2>By Project Type</h2>
<table class="analytics" data-sortable>
  <thead><tr><th>Project Type</th><th>n projects</th><th>Mean</th><th>Median</th><th>Std dev</th><th>&gt;110%</th></tr></thead>
  <tbody>
  <?php foreach ($byProjectType as $name => $ratios): $s = stats($ratios); ?>
    <tr>
      <td><?= htmlspecialchars($name) ?></td>
      <td><?= $s['n'] ?></td>
      <td class="<?= ratioClass($s['mean']) ?>"><?= pct($s['mean']) ?></td>
      <td class="<?= ratioClass($s['median']) ?>"><?= pct($s['median']) ?></td>
      <td><?= number_format($s['stddev'],2) ?></td>
      <td><?= $s['over110'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>By Manager</h2>
<table class="analytics" data-sortable>
  <thead><tr><th>Manager</th><th>n projects</th><th>Mean</th><th>Median</th><th>Std dev</th><th>&gt;110%</th></tr></thead>
  <tbody>
  <?php foreach ($byManager as $name => $ratios): $s = stats($ratios); ?>
    <tr>
      <td><?= htmlspecialchars($name) ?></td>
      <td><?= $s['n'] ?></td>
      <td class="<?= ratioClass($s['mean']) ?>"><?= pct($s['mean']) ?></td>
      <td class="<?= ratioClass($s['median']) ?>"><?= pct($s['median']) ?></td>
      <td><?= number_format($s['stddev'],2) ?></td>
      <td><?= $s['over110'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>By Stage Type</h2>
<table class="analytics" data-sortable>
  <thead><tr><th>Stage</th><th>n samples</th><th>Mean</th><th>Median</th><th>Std dev</th><th>&gt;110%</th></tr></thead>
  <tbody>
  <?php foreach ($byStageType as $name => $ratios): $s = stats($ratios); ?>
    <tr>
      <td><?= htmlspecialchars($name) ?></td>
      <td><?= $s['n'] ?></td>
      <td class="<?= ratioClass($s['mean']) ?>"><?= pct($s['mean']) ?></td>
      <td class="<?= ratioClass($s['median']) ?>"><?= pct($s['median']) ?></td>
      <td><?= number_format($s['stddev'],2) ?></td>
      <td><?= $s['over110'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>By Task Type</h2>
<table class="analytics" data-sortable>
  <thead><tr><th>Task</th><th>Stage</th><th>n samples</th><th>Mean</th><th>Median</th><th>Std dev</th><th>&gt;110%</th><th>Total est</th><th>Total actual</th></tr></thead>
  <tbody>
  <?php foreach ($byTaskType as $tid => $d): $s = stats($d['ratios']); ?>
    <tr>
      <td><?= htmlspecialchars($d['name']) ?></td>
      <td><?= htmlspecialchars($d['stage']) ?></td>
      <td><?= $s['n'] ?></td>
      <td class="<?= ratioClass($s['mean']) ?>"><?= pct($s['mean']) ?></td>
      <td class="<?= ratioClass($s['median']) ?>"><?= pct($s['median']) ?></td>
      <td><?= number_format($s['stddev'],2) ?></td>
      <td><?= $s['over110'] ?></td>
      <td><?= number_format($d['est_total'],1) ?>h</td>
      <td><?= number_format($d['act_total'],1) ?>h</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>All Projects</h2>
<table class="analytics" data-sortable>
  <thead><tr><th>Job</th><th>Project Type</th><th>Manager</th><th>Estimated</th><th>Actual</th><th>Ratio</th></tr></thead>
  <tbody>
  <?php foreach ($projectRows as $p): ?>
    <tr>
      <td><a href="updateform_admin1.php?proj_id=<?= $p['proj_id'] ?>"><?= htmlspecialchars($p['JobName']) ?></a></td>
      <td><?= htmlspecialchars($p['Project_Type']) ?></td>
      <td><?= htmlspecialchars($p['Manager']) ?></td>
      <td><?= number_format($p['estimated'],1) ?>h</td>
      <td><?= number_format($p['actual'],1) ?>h</td>
      <td class="<?= ratioClass($p['ratio']) ?>"><?= pct($p['ratio']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</div><!-- /page -->

<script>
// Simple click-to-sort for tables marked data-sortable
document.querySelectorAll('table[data-sortable] thead th').forEach(function(th, idx) {
    th.addEventListener('click', function() {
        var table = th.closest('table');
        var tbody = table.tBodies[0];
        var rows = Array.from(tbody.rows);
        var asc = th.dataset.sortAsc !== '1';
        rows.sort(function(a, b) {
            var av = a.cells[idx].innerText.trim();
            var bv = b.cells[idx].innerText.trim();
            var an = parseFloat(av.replace(/[^\d\.\-]/g, ''));
            var bn = parseFloat(bv.replace(/[^\d\.\-]/g, ''));
            if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
            return asc ? av.localeCompare(bv) : bv.localeCompare(av);
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
        th.parentElement.querySelectorAll('th').forEach(function(t) { delete t.dataset.sortAsc; });
        th.dataset.sortAsc = asc ? '1' : '0';
    });
});

function barChart(id, data, title) {
    new Chart(document.getElementById(id), {
        type: 'bar',
        data: {
            labels: data.map(d => d.label + ' (n=' + d.n + ')'),
            datasets: [
                { label: 'Median %', data: data.map(d => d.median), backgroundColor: data.map(d => d.median > 110 ? '#cc3333' : (d.median > 100 ? '#ddaa22' : '#669933')) },
                { label: 'Mean %',   data: data.map(d => d.mean),   backgroundColor: '#5577aa' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, title: { display: true, text: '% of estimated' } } }
        }
    });
}
barChart('chartPT',  <?= json_encode(array_values($chartProjectType)) ?>);
barChart('chartMgr', <?= json_encode(array_values($chartManager)) ?>);
barChart('chartStg', <?= json_encode(array_values($chartStage)) ?>);
</script>
</body>
</html>
