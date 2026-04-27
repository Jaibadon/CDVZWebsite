<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

try {
$pdo = get_db();

// ── Week selection ──────────────────────────────────────────────────────────
// Accept either 'week' or 'Week' (form fields posted from various pages)
$currentWeek = $_POST['week'] ?? $_POST['Week']
            ?? $_GET['week']  ?? $_GET['Week']
            ?? $_SESSION['Week'] ?? date('Y-m-d', strtotime('monday this week'));
$ts = strtotime($currentWeek);
$currentWeek = $ts ? date('Y-m-d', $ts) : date('Y-m-d', strtotime('monday this week'));
$_SESSION['Week'] = $currentWeek;

$weekStart = $currentWeek;
$weekEnd   = date('Y-m-d', strtotime('+6 days', strtotime($weekStart)));

// ── Load all active projects ────────────────────────────────────────────────
$projStmt = $pdo->query("SELECT proj_id, JobName, active FROM Projects ORDER BY JobName");
$PROJECTS = $projStmt->fetchAll();

// ── Load timesheet rows for this employee & week ───────────────────────────
$empId = $_SESSION['Employee_id'] ?? 0;
$tsStmt = $pdo->prepare(
    "SELECT proj_id, TS_ID, TS_DATE, Task, Hours, Invoice_No
       FROM Timesheets
      WHERE Employee_id = ?
        AND TS_DATE BETWEEN ? AND ?
      ORDER BY proj_id, Task"
);
$tsStmt->execute([$empId, $weekStart, $weekEnd]);
$tsRows = $tsStmt->fetchAll();

} catch (Exception $e) {
    echo '<pre style="color:red;background:#fff;padding:10px">DB Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

// ── Build a keyed structure: TS_ID → [proj, task, invoice, days[1..7], tot]
// Group by (proj_id, task) so multiple days roll up per task row
$taskMap = [];       // key = "proj_id|task"
foreach ($tsRows as $r) {
    $key = $r['proj_id'] . '|' . $r['Task'];
    if (!isset($taskMap[$key])) {
        $taskMap[$key] = [
            'proj_id'    => $r['proj_id'],
            'task'       => $r['Task'],
            'invoice_no' => $r['Invoice_No'],
            'ts_id'      => $r['TS_ID'],
            'days'       => array_fill(1, 7, ''),
            'tot'        => 0,
        ];
    }
    // day-of-week 1=Mon … 7=Sun
    $dow = (int) date('N', strtotime($r['TS_DATE']));
    $taskMap[$key]['days'][$dow] = ($taskMap[$key]['days'][$dow] !== '')
        ? $taskMap[$key]['days'][$dow] + $r['Hours']
        : $r['Hours'];
    $taskMap[$key]['tot'] += $r['Hours'];
}
$taskRows = array_values($taskMap);

// ── Week dropdown options ───────────────────────────────────────────────────
$weekOptions = [];
$refMonday   = date('Y-m-d', strtotime('monday this week'));
for ($i = -6; $i <= 1; $i++) {
    $d = date('Y-m-d', strtotime(($i >= 0 ? "+$i" : "$i") . ' weeks', strtotime($refMonday)));
    $weekOptions[] = $d;
}

// ── Day headings  ──────────────────────────────────────────────────────────
$dayNames = ['M','T','W','T','F','S','S'];
$dayDates = [];
for ($d = 0; $d < 7; $d++) {
    $dayDates[] = (int) date('j', strtotime("+$d days", strtotime($weekStart)));
}

// ── Admin check ────────────────────────────────────────────────────────────
$isAdmin = in_array($_SESSION['UserID'], ['erik','jen'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Timesheet – <?= htmlspecialchars($_SESSION['UserID']) ?></title>
<link href="global.css" rel="stylesheet">
<style>
body  { background:#515559; font-family:Arial,sans-serif; font-size:12px; margin:0; padding:10px; }
.hdr  { background:#9B9B1B; color:#fff; }
.hdr a{ color:#fff; }
table { border-collapse:collapse; }
input[type=text] { font-size:11px; }
.tbl-main { width:660px; margin:0 auto; }
th,td { padding:2px 4px; }
.day-input { width:34px; text-align:center; }
.proj-sel  { width:130px; }
.desc-inp  { width:200px; }
.tot-inp   { width:34px; background:#ddd; }
th { background:#9B9B1B; color:#fff; font-size:11px; }
.vt-cell input { background:#ddd; }
.nav-bar td { text-align:center; }
</style>
<script>
function OnKeyPressTest(e) {
    var k = e.keyCode || e.which;
    if ((k < 48 || k > 57) && k !== 46) { e.preventDefault(); return false; }
}
function OnLostFocusTest(el) {
    var parts = el.name.match(/D(\d)_(\d+)/);
    if (!parts) return;
    var c = parts[1], r = parts[2];
    // Validate project selected
    var proj = document.submit_form.elements['Project' + r];
    if (el.value !== '' && proj && proj.value === '') {
        alert('Please select a Project before entering hours');
        el.value = '';
        proj.focus();
        return;
    }
    // Row total
    var tot = 0;
    for (var ci = 1; ci <= 7; ci++) {
        var v = parseFloat(document.submit_form.elements['D' + ci + '_' + r].value) || 0;
        tot += v;
    }
    document.submit_form.elements['Total_' + r].value = tot.toFixed(2);
    // Column total
    tot = 0;
    for (var ri = 1; ri <= 30; ri++) {
        var el2 = document.submit_form.elements['D' + c + '_' + ri];
        if (el2) tot += parseFloat(el2.value) || 0;
    }
    document.submit_form.elements['VTotal_' + c].value = tot.toFixed(2);
    // Grand total
    tot = 0;
    for (ri = 1; ri <= 30; ri++) {
        var t = parseFloat((document.submit_form.elements['Total_' + ri] || {}).value) || 0;
        tot += t;
    }
    document.submit_form.elements['VTotal_8'].value = tot.toFixed(2);
}
window.onload = function () {
    // Recalculate column totals on load
    for (var c = 1; c <= 7; c++) {
        var tot = 0;
        for (var r = 1; r <= 30; r++) {
            var el = document.submit_form && document.submit_form.elements['D' + c + '_' + r];
            if (el) tot += parseFloat(el.value) || 0;
        }
        var vt = document.submit_form && document.submit_form.elements['VTotal_' + c];
        if (vt) vt.value = tot.toFixed(2);
    }
    var grand = 0;
    for (var r = 1; r <= 30; r++) {
        var t = document.submit_form && document.submit_form.elements['Total_' + r];
        if (t) grand += parseFloat(t.value) || 0;
    }
    var vt8 = document.submit_form && document.submit_form.elements['VTotal_8'];
    if (vt8) vt8.value = grand.toFixed(2);
};
</script>
</head>
<body>

<!-- ── Navigation bar ────────────────────────────────────────────────────── -->
<table width="680" border="0" cellspacing="0" cellpadding="0" style="margin:0 auto">
<tr><td>
<table border="0" cellspacing="0" width="680" cellpadding="0" class="hdr">
  <tr>
    <td colspan="4" height="26" align="left"><b>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;CADViz Timesheet – <?= htmlspecialchars($_SESSION['UserID']) ?></b></td>
    <td colspan="4" align="center"><a href="logout.php">logout</a></td>
  </tr>
  <tr class="nav-bar">
    <td><a href="projects.php">My Projects</a></td>
    <td><a href="main.php">My Timesheet</a></td>
    <td><a href="menu.php">Main Menu</a></td>
    <td colspan="2"><a href="report.php">Reports</a></td>
    <td>&nbsp;</td><td>&nbsp;</td>
  </tr>
  <tr class="nav-bar">
    <td><a href="projects_archive1.php">Projects Archive</a></td>
    <td><?php if ($isAdmin): ?><a href="timesheet_admin1.php">Timesheet Admin</a><?php endif; ?></td>
    <td><?php if ($isAdmin): ?><a href="project_new.php">New Project</a><?php endif; ?></td>
    <td colspan="2"><?php if ($isAdmin): ?><a href="more.php">More&hellip;</a><?php endif; ?></td>
    <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
  </tr>
</table>
</td></tr>
</table>

<!-- ── Week selector (retrieve form) ─────────────────────────────────────── -->
<div style="width:680px;margin:0 auto;padding:6px 0;color:#fff">
<form action="main.php" name="retrieve_form" method="post" style="display:inline">
  <span style="color:#fff;font-family:Arial">Week starting:</span>
  <select name="week" onchange="this.form.submit()">
    <?php foreach ($weekOptions as $wo): ?>
      <option value="<?= $wo ?>" <?= ($wo === $weekStart) ? 'selected' : '' ?>><?= date('d/m/Y', strtotime($wo)) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="submit" name="Retrieve" value="Retrieve">
  <span style="color:#ccc;font-family:Arial;font-size:11px">&nbsp;&nbsp;&nbsp;(Enter time in decimal hours e.g. 15min = 0.25)</span>
</form>
</div>

<!-- ── Submit form ───────────────────────────────────────────────────────── -->
<form action="submit.php" id="submit_form" name="submit_form" method="post" style="width:680px;margin:0 auto">
<input type="hidden" name="hidden_week" value="<?= htmlspecialchars($weekStart) ?>">
<div style="padding:4px 0">
  <input type="submit" name="Submit" value="Submit Timesheet" style="padding:4px 14px;background:#9B9B1B;color:#fff;border:none;cursor:pointer;border-radius:3px">
</div>

<div style="overflow-x:auto">
<table class="tbl-main" border="0" cellpadding="0" cellspacing="0">

<!-- Column headings row -->
<tr>
  <th style="width:130px">Project</th>
  <th style="width:0"></th><!-- hidden Invoice_No col -->
  <th style="width:200px">Description</th>
  <?php for ($d = 0; $d < 7; $d++): ?>
    <th class="day-input">
      <?= $dayNames[$d] ?><br><?= $dayDates[$d] ?><br>
      <input disabled type="text" size="3" name="VTotal_<?= $d+1 ?>" value="" class="vt-cell">
    </th>
  <?php endfor; ?>
  <th class="day-input">Week<br>Total<br><input disabled type="text" size="4" name="VTotal_8" value=""></th>
</tr>

<?php
// Build up to 30 rows
for ($a = 1; $a <= 30; $a++):
    $tr      = $taskRows[$a - 1] ?? null;
    $projVal = $tr ? $tr['proj_id']    : '';
    $descVal = $tr ? $tr['task']       : '';
    $invNo   = $tr ? $tr['invoice_no'] : 0;
    $tot     = $tr ? $tr['tot']        : 0;
    $days    = $tr ? $tr['days']       : array_fill(1, 7, '');
    $locked  = ($invNo != 0);
?>
<tr>
  <td>
    <select <?= $locked ? 'disabled' : '' ?> name="Project<?= $a ?>" class="proj-sel">
      <option value=""></option>
      <?php foreach ($PROJECTS as $p): ?>
        <?php if ($p['active'] != 0 || $p['proj_id'] == $projVal): ?>
          <option value="<?= htmlspecialchars($p['proj_id']) ?>"
            <?= ($p['proj_id'] == $projVal) ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['JobName']) ?>
          </option>
        <?php endif; ?>
      <?php endforeach; ?>
    </select>
  </td>
  <td><input type="hidden" name="Invoice_No<?= $a ?>" value="<?= (int)$invNo ?>"></td>
  <td><input <?= $locked ? 'disabled' : '' ?> type="text" size="30" name="Desc<?= $a ?>"
       value="<?= htmlspecialchars($descVal) ?>" class="desc-inp"
       onkeypress="if(event.keyCode===39)event.returnValue=false;"></td>
  <?php for ($d = 1; $d <= 7; $d++): ?>
    <td><input <?= $locked ? 'disabled' : '' ?> type="text" size="3"
         id="D<?= $d ?>_<?= $a ?>" name="D<?= $d ?>_<?= $a ?>"
         value="<?= htmlspecialchars($days[$d]) ?>"
         class="day-input"
         onblur="OnLostFocusTest(this)" onkeypress="OnKeyPressTest(event)"></td>
  <?php endfor; ?>
  <td><input disabled type="text" size="3" name="Total_<?= $a ?>"
       value="<?= $tot > 0 ? htmlspecialchars($tot) : '' ?>" class="tot-inp"></td>
</tr>
<?php endfor; ?>

</table>
</div>
</form>

</body>
</html>
