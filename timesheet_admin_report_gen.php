<?php
/**
 * timesheet_admin_report_gen.php — admin timesheet report.
 *
 * Lists timesheet entries for a date range (optionally one project and/or one
 * staff member) and AUTO-TOTALS the hours so nobody adds them up by hand:
 *   • "Hours by staff" and "Hours by project" summaries.
 *   • A staff x week matrix (the 40h-per-week check) — any week a person logged
 *     under 40h is shaded, so it's obvious who hasn't filled their week.
 *   • The detail rows grouped by work-week (Mon–Sun) with a per-week total.
 *
 * Posted from timesheet_admin1.php (StartDate, EndDate, Project, staff).
 * Admin only (erik / jen).
 */
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
    exit;
}

$pdo = get_db();

$varDATE1      = $_POST['StartDate'] ?? '';
$varDATE2      = $_POST['EndDate'] ?? '';
$projectFilter = $_POST['Project'] ?? '';
$staffFilter   = $_POST['staff'] ?? $_POST['Staff'] ?? '';

// ── Query: join Staff so every row knows who logged it ───────────────────────
$sql = "SELECT AL1.TS_DATE, AL1.TASK, AL4.JobName, AL1.hours, AL1.Employee_id, AL5.Login
        FROM Timesheets AL1
        JOIN Projects AL4 ON AL4.proj_id = AL1.proj_id
        LEFT JOIN Staff AL5 ON AL5.Employee_id = AL1.Employee_id
        WHERE 1=1";
$params = [];

// Date range. Either side may be left blank: a blank (or unreadable) START
// defaults to 1990-01-01, a blank END defaults to end-of-today — with a small
// warning so it's clear what range was actually used. (End is 23:59:59 so
// today's timestamped entries aren't dropped.)
$rangeWarnings = [];
$startRaw = trim((string)$varDATE1);
$endRaw   = trim((string)$varDATE2);
$d1 = $startRaw !== '' ? to_mysql_date(str_replace('%2F', '/', $startRaw)) : '';
$d2 = $endRaw   !== '' ? to_mysql_date(str_replace('%2F', '/', $endRaw))   : '';
if ($startRaw === '')   { $d1 = '1990-01-01'; $rangeWarnings[] = 'Start date was blank — showing from 01/01/1990.'; }
elseif (!$d1)           { $d1 = '1990-01-01'; $rangeWarnings[] = 'Couldn\'t read the start date "' . $startRaw . '" — showing from 01/01/1990.'; }
if ($endRaw === '')     { $d2 = date('Y-m-d') . ' 23:59:59'; $rangeWarnings[] = 'End date was blank — showing through today (' . date('d/m/Y') . ').'; }
elseif (!$d2)           { $d2 = date('Y-m-d') . ' 23:59:59'; $rangeWarnings[] = 'Couldn\'t read the end date "' . $endRaw . '" — showing through today (' . date('d/m/Y') . ').'; }
$sql .= " AND AL1.TS_DATE BETWEEN ? AND ?";
$params[] = $d1;
$params[] = $d2;
$dispFrom = date('d/m/Y', strtotime($d1));
$dispTo   = date('d/m/Y', strtotime($d2));
if (!empty($projectFilter)) { $sql .= " AND AL1.proj_id = ?"; $params[] = $projectFilter; }

$staffName = '';
if (!empty($staffFilter)) {
    $sql .= " AND AL1.Employee_id = ?";
    $params[] = $staffFilter;
    $su = $pdo->prepare("SELECT Login FROM Staff WHERE Employee_id = ?");
    $su->execute([$staffFilter]);
    $staffName = (string)($su->fetchColumn() ?: '');
}
$sql .= " ORDER BY AL1.TS_DATE";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Tally everything in one pass ─────────────────────────────────────────────
$WEEK_TARGET = 40.0;       // full-time work-week hours — the thing we're checking
$grand     = 0.0;
$byStaff   = [];           // Login   => hours
$byProject = [];           // JobName => hours
$weeks     = [];           // 'Y-m-d' (Monday) => "j M – j M" label, in date order
$staffWeek = [];           // Login   => [ weekKey => hours ]
$weekTotal = [];           // weekKey => hours (all staff)

foreach ($rows as $i => $r) {
    $h     = (float)($r['hours'] ?? 0);
    $sName = (string)($r['Login'] ?? ('#' . (int)($r['Employee_id'] ?? 0)));
    $pName = (string)($r['JobName'] ?? '(no project)');
    $grand            += $h;
    $byStaff[$sName]   = ($byStaff[$sName]   ?? 0) + $h;
    $byProject[$pName] = ($byProject[$pName] ?? 0) + $h;

    // ISO work-week (Mon–Sun): key on that week's Monday.
    $wk = '';
    if (!empty($r['TS_DATE'])) {
        $dt = new DateTime((string)$r['TS_DATE']);
        $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'));   // -> Monday of the ISO week
        $wk = $dt->format('Y-m-d');
        if (!isset($weeks[$wk])) {
            $sun = (new DateTime($wk))->modify('+6 days');
            $weeks[$wk] = date('j M', strtotime($wk)) . ' – ' . $sun->format('j M');
        }
        $staffWeek[$sName][$wk] = ($staffWeek[$sName][$wk] ?? 0) + $h;
        $weekTotal[$wk]         = ($weekTotal[$wk] ?? 0) + $h;
    }
    $rows[$i]['_wk'] = $wk;
}
arsort($byStaff);
arsort($byProject);
ksort($weeks);
$weekKeys = array_keys($weeks);
$fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="Window-target" content="_top">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Timesheet report — CADViz</title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#5d3a9b; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; } .topbar h1 { margin:0; font-size:17px; font-weight:400; }
.page { max-width:1050px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:6px; padding:14px 16px; margin:14px 0; }
.card h2 { margin:0 0 10px; color:#5d3a9b; font-size:15px; border-bottom:1px solid #eee; padding-bottom:5px; }
.filters b { color:#222; } .filters { color:#555; }
table.rpt { border-collapse:collapse; width:100%; font-size:13px; }
table.rpt th, table.rpt td { padding:6px 10px; border-bottom:1px solid #eee; text-align:left; }
table.rpt th { background:#f4f4f4; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#666; }
table.rpt td.num, table.rpt th.num { text-align:right; font-variant-numeric:tabular-nums; }
.summary-grid { display:flex; flex-wrap:wrap; gap:18px; }
.summary-grid > div { flex:1; min-width:280px; }
.total-row td { font-weight:700; border-top:2px solid #ccc; background:#faf7ff; }
.matrix-wrap { overflow-x:auto; }
.matrix th.num, .matrix td.num { min-width:52px; }
.under { background:#fff3cd; color:#7a5a00; font-weight:600; }   /* week under target */
.zero { color:#bbb; }
.wk-head td { background:#efe7fb; color:#4a2f86; font-weight:600; }
.wk-total td { font-weight:700; background:#f6f6f6; border-top:1px solid #ddd; }
.legend { color:#888; font-size:11px; margin-top:8px; }
.backlinks { padding:10px 16px; } .backlinks a { color:#5d3a9b; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📋 Timesheet report</h1>
  <div><a href="timesheet_admin1.php">Report options</a> &nbsp;·&nbsp; <a href="menu.php">Menu</a></div>
</div>
<div class="page">

  <div class="card filters">
    <b>Date range:</b> <?= htmlspecialchars($dispFrom) ?> to <?= htmlspecialchars($dispTo) ?>
    &nbsp;·&nbsp; <b>Project:</b> <?= !empty($projectFilter) ? htmlspecialchars((string)$projectFilter) : 'all' ?>
    &nbsp;·&nbsp; <b>Staff:</b> <?= $staffName !== '' ? htmlspecialchars($staffName) : (!empty($staffFilter) ? htmlspecialchars((string)$staffFilter) : 'all') ?>
    &nbsp;·&nbsp; <b>Grand total:</b> <?= $fmt($grand) ?> h
    <?php foreach ($rangeWarnings as $w): ?>
      <div style="margin-top:8px;color:#7a5a00;background:#fff3cd;border:1px solid #c8a52e;border-radius:4px;padding:6px 10px;font-size:12px;">&#9888; <?= htmlspecialchars($w) ?></div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($rows)): ?>
    <div class="card">No timesheet entries match those filters.</div>
  <?php else: ?>

  <div class="card">
    <div class="summary-grid">
      <div>
        <h2>Hours by staff</h2>
        <table class="rpt">
          <tr><th>Staff</th><th class="num">Hours</th></tr>
          <?php foreach ($byStaff as $name => $h): ?>
            <tr><td><?= htmlspecialchars($name) ?></td><td class="num"><?= $fmt($h) ?></td></tr>
          <?php endforeach; ?>
          <tr class="total-row"><td>Total</td><td class="num"><?= $fmt($grand) ?></td></tr>
        </table>
      </div>
      <div>
        <h2>Hours by project</h2>
        <table class="rpt">
          <tr><th>Project</th><th class="num">Hours</th></tr>
          <?php foreach ($byProject as $name => $h): ?>
            <tr><td><?= htmlspecialchars($name) ?></td><td class="num"><?= $fmt($h) ?></td></tr>
          <?php endforeach; ?>
          <tr class="total-row"><td>Total</td><td class="num"><?= $fmt($grand) ?></td></tr>
        </table>
      </div>
    </div>
  </div>

  <?php if (count($weekKeys) >= 1 && count($weekKeys) <= 31): ?>
  <div class="card">
    <h2>Hours per week by staff <span style="font-weight:400;color:#888;font-size:12px">— weeks under <?= (int)$WEEK_TARGET ?>h shaded</span></h2>
    <div class="matrix-wrap">
      <table class="rpt matrix">
        <tr>
          <th>Staff</th>
          <?php foreach ($weekKeys as $wk): ?><th class="num" title="week of <?= htmlspecialchars($wk) ?>"><?= htmlspecialchars($weeks[$wk]) ?></th><?php endforeach; ?>
          <th class="num">Total</th>
        </tr>
        <?php foreach (array_keys($byStaff) as $name): ?>
          <tr>
            <td><?= htmlspecialchars($name) ?></td>
            <?php foreach ($weekKeys as $wk): $hv = $staffWeek[$name][$wk] ?? 0; ?>
              <?php if ($hv <= 0): ?>
                <td class="num zero">–</td>
              <?php else: ?>
                <td class="num <?= $hv < $WEEK_TARGET ? 'under' : '' ?>"><?= $fmt($hv) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
            <td class="num"><b><?= $fmt($byStaff[$name]) ?></b></td>
          </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td>Week total</td>
          <?php foreach ($weekKeys as $wk): ?><td class="num"><?= $fmt($weekTotal[$wk] ?? 0) ?></td><?php endforeach; ?>
          <td class="num"><?= $fmt($grand) ?></td>
        </tr>
      </table>
    </div>
    <div class="legend">Shaded = that person logged under <?= (int)$WEEK_TARGET ?>h that week (relevant for full-time staff). "–" = nothing logged that week.</div>
  </div>
  <?php else: ?>
    <div class="card legend">Weekly matrix hidden: the range spans <?= count($weekKeys) ?> weeks (max 31). Narrow the dates to see the per-week 40h check.</div>
  <?php endif; ?>

  <div class="card">
    <h2>Entries by week</h2>
    <table class="rpt">
      <tr><th>Project</th><th>Staff</th><th>Task</th><th>Day</th><th>Date</th><th class="num">Hours</th></tr>
      <?php
      $curWk = null;
      foreach ($rows as $r) {
          $wk = $r['_wk'] ?? '';
          if ($wk !== $curWk) {
              if ($curWk !== null) {
                  echo '<tr class="wk-total"><td colspan="5">Week total — ' . htmlspecialchars($weeks[$curWk] ?? '') . '</td><td class="num">' . $fmt($weekTotal[$curWk] ?? 0) . '</td></tr>';
              }
              $label = $wk !== '' ? ('Week of ' . htmlspecialchars($weeks[$wk] ?? $wk)) : 'No date';
              echo '<tr class="wk-head"><td colspan="6">' . $label . '</td></tr>';
              $curWk = $wk;
          }
          $ts   = $r['TS_DATE'];
          $day  = $ts ? date('D', strtotime((string)$ts)) : '';
          $date = $ts ? date('d/m/Y', strtotime((string)$ts)) : '';
          echo '<tr>';
          echo '<td>' . htmlspecialchars((string)$r['JobName']) . '</td>';
          echo '<td>' . htmlspecialchars((string)($r['Login'] ?? '')) . '</td>';
          echo '<td>' . htmlspecialchars((string)$r['TASK']) . '</td>';
          echo '<td>' . htmlspecialchars($day) . '</td>';
          echo '<td>' . htmlspecialchars($date) . '</td>';
          echo '<td class="num">' . htmlspecialchars($fmt($r['hours'] ?? 0)) . '</td>';
          echo '</tr>';
      }
      if ($curWk !== null) {
          echo '<tr class="wk-total"><td colspan="5">Week total — ' . htmlspecialchars($weeks[$curWk] ?? '') . '</td><td class="num">' . $fmt($weekTotal[$curWk] ?? 0) . '</td></tr>';
      }
      ?>
      <tr class="total-row"><td colspan="5">Grand total</td><td class="num"><?= $fmt($grand) ?></td></tr>
    </table>
  </div>

  <?php endif; ?>
</div>
<div class="backlinks">
  <a href="timesheet_admin1.php">← back to report options</a> &nbsp;&nbsp; <a href="main.php">back to timesheet</a>
</div>
</body>
</html>
