<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

try {
$pdo = get_db();

// Employed-staff full-week check (only for users in EMPLOYED_STAFF list)
$mustFillFullWeek = is_employed_staff($_SESSION['UserID'] ?? '');
$missingWeekdays = $mustFillFullWeek
    ? missing_weekdays($pdo, (int)($_SESSION['Employee_id'] ?? 0), 4)
    : [];

// ── Week selection ──────────────────────────────────────────────────────────
// Compute today's Monday once, robustly. `strtotime('monday this week')`
// has a known PHP quirk where on a Monday it can resolve to the *previous*
// Monday — DateTime + ISO dow is unambiguous.
$_todayDow      = (int)(new DateTime('today'))->format('N') - 1;   // 0=Mon … 6=Sun
$_todaysMonday  = (new DateTime('today'))->modify("-{$_todayDow} days")->format('Y-m-d');

// Accept either 'week' or 'Week' (form fields posted from various pages)
$currentWeek = $_POST['week'] ?? $_POST['Week']
            ?? $_GET['week']  ?? $_GET['Week']
            ?? $_SESSION['Week'] ?? $_todaysMonday;
$ts = strtotime($currentWeek);
$currentWeek = $ts ? date('Y-m-d', $ts) : $_todaysMonday;
$_SESSION['Week'] = $currentWeek;

$weekStart = $currentWeek;
$weekEnd   = date('Y-m-d', strtotime('+6 days', strtotime($weekStart)));

// ── Split missing into "current week (Mon-Fri up to yesterday)" vs
//    "previous weeks". The current-week portion gets re-checked
//    client-side as the user types, so the submit button can flip back to
//    normal once they've filled in every weekday before today. Previous
//    weeks keep the existing red-banner / red-button behavior. FUTURE
//    weeks intentionally skip the gap nag entirely — full-time staff
//    book annual leave on those weeks in advance, the past-4-weeks
//    counter has nothing to do with submitting a future week.
//
// NOTE: this block has to run AFTER $weekStart / $_todaysMonday are set,
//       otherwise $isViewingCurrentWeek silently always evaluates false
//       (which is why before this fix the button on Monday and on every
//       future week showed red — the dynamic JS path was unreachable).
$today              = date('Y-m-d');
$todayDow           = (int)date('N');                                 // 1=Mon … 7=Sun
$currentWeekMonday  = $_todaysMonday;
$isViewingCurrentWeek = ($weekStart === $currentWeekMonday);
$weekIsFuture        = ($weekStart > $currentWeekMonday);

$missingPrevWeeks = [];
$missingCurrWeek  = [];
foreach ($missingWeekdays as $d) {
    $dt   = new DateTime($d);
    $dow  = (int)$dt->format('N') - 1;
    $dMon = (clone $dt)->modify("-{$dow} days")->format('Y-m-d');
    if ($dMon === $currentWeekMonday) $missingCurrWeek[] = $d;
    else                              $missingPrevWeeks[] = $d;
}
// Current-week gap is only "real" for weekdays before today.
// (Sat/Sun never count; today and future days are still allowed to be empty.)
$missingCurrWeekUpToYesterday = array_values(array_filter($missingCurrWeek, function($d) use ($todayDow) {
    $dow = (int)date('N', strtotime($d));
    return $dow < $todayDow && $dow <= 5;
}));

// Mon..Fri DoW indices (1..5) that should be filled by now in the
// current week. JS uses these for dynamic re-checking on every keystroke.
$requiredDowsCurrentWeek = [];
for ($i = 1; $i <= 5; $i++) {
    if ($i < $todayDow) $requiredDowsCurrentWeek[] = $i;
}

// ── Load all active projects ────────────────────────────────────────────────
$projStmt = $pdo->query("SELECT proj_id, JobName, active FROM Projects ORDER BY JobName");
$PROJECTS = $projStmt->fetchAll();

// ── Feature detection (graceful if migrations not yet run) ─────────────────
$hasTaskTypeId  = false;
$hasProjTaskId  = false;
$hasVariations  = false;
try {
    $hasTaskTypeId = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Task_Type_ID'")->fetch();
    $hasProjTaskId = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Proj_Task_ID'")->fetch();
    $hasVariations = (bool)$pdo->query("SHOW TABLES LIKE 'Project_Variations'")->fetch();
} catch (Exception $e) { /* ignore */ }

// ── Per-project_task data: each Proj_Task_ID is its own option ─────────────
// COALESCE on weights handles NULLs (legacy data) — they default to 1.
$projectTasks = [];
$variationCols = $hasVariations
    ? ", pt.Variation_ID, pv.Variation_Number, pv.Title AS Variation_Title, pv.Status AS Variation_Status"
    : ", NULL AS Variation_ID, NULL AS Variation_Number, NULL AS Variation_Title, NULL AS Variation_Status";
$variationJoin = $hasVariations
    ? "LEFT JOIN Project_Variations pv ON pt.Variation_ID = pv.Variation_ID"
    : "";
$removedFilter = $hasVariations ? "AND COALESCE(pt.Is_Removed, 0) = 0" : "";

$ptStmt = $pdo->query(
    "SELECT pt.Proj_Task_ID AS ptid, ps.Proj_ID, pt.Task_Type_ID, tt.Task_Name,
            st.Stage_Type_Name,
            (tt.Estimated_Time * COALESCE(pt.Weight,1)) AS estimated
            $variationCols
       FROM Project_Tasks pt
       JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
       JOIN Tasks_Types tt   ON pt.Task_Type_ID = tt.Task_ID
       LEFT JOIN Stage_Types st ON tt.Stage_ID = st.Stage_Type_ID
       $variationJoin
      WHERE 1=1 $removedFilter"
);
foreach ($ptStmt->fetchAll() as $r) {
    $pid  = (int)$r['Proj_ID'];
    $ptid = (int)$r['ptid'];
    $vid  = $r['Variation_ID'] !== null ? (int)$r['Variation_ID'] : 0;
    $projectTasks[$pid][$ptid] = [
        'ptid'      => $ptid,
        'tid'       => (int)$r['Task_Type_ID'],
        'vid'       => $vid,
        'vnumber'   => $r['Variation_Number'] !== null ? (int)$r['Variation_Number'] : 0,
        'vtitle'    => $r['Variation_Title'] ?? '',
        'vstatus'   => $r['Variation_Status'] ?? '',
        'name'      => $r['Task_Name'],
        'stage'     => $r['Stage_Type_Name'] ?: 'Other',
        'est'       => (float)$r['estimated'],
        'logged'    => 0.0,
        'remaining' => (float)$r['estimated'],
    ];
}

// Sum already-logged hours per Proj_Task_ID
if ($hasProjTaskId) {
    $loggedRows = $pdo->query(
        "SELECT Proj_Task_ID, SUM(Hours) AS hrs
           FROM Timesheets
          WHERE Hours > 0 AND Proj_Task_ID IS NOT NULL
          GROUP BY Proj_Task_ID"
    )->fetchAll();
    foreach ($loggedRows as $r) {
        $ptid = (int)$r['Proj_Task_ID'];
        foreach ($projectTasks as $pid => $_) {
            if (isset($projectTasks[$pid][$ptid])) {
                $projectTasks[$pid][$ptid]['logged']    += (float)$r['hrs'];
                $projectTasks[$pid][$ptid]['remaining'] -= (float)$r['hrs'];
                break;
            }
        }
    }
}

// Reshape: original stages first, variations after
$projectTasksJs = [];
foreach ($projectTasks as $pid => $tasks) {
    $arr = array_values($tasks);
    usort($arr, function($a, $b) {
        if ($a['vid'] !== $b['vid']) return $a['vid'] <=> $b['vid'];
        if ($a['stage'] !== $b['stage']) return strcmp($a['stage'], $b['stage']);
        return strcmp($a['name'], $b['name']);
    });
    $projectTasksJs[(string)$pid] = $arr;
}

// ── Load timesheet rows for this employee & week ───────────────────────────
$empId = $_SESSION['Employee_id'] ?? 0;
$ttCol  = $hasTaskTypeId ? 'Task_Type_ID' : 'NULL AS Task_Type_ID';
$ptCol  = $hasProjTaskId ? 'Proj_Task_ID' : 'NULL AS Proj_Task_ID';
// Pull Leave_Approved when the migration has been applied so future leave
// cells (proj 1435, TS_DATE > today, Leave_Approved=0) can be painted red.
$hasLeaveApproved = false;
try {
    $hasLeaveApproved = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Leave_Approved'")->fetch();
} catch (Exception $e) { /* ignore */ }
$laCol = $hasLeaveApproved ? 'Leave_Approved' : 'NULL AS Leave_Approved';
$tsStmt = $pdo->prepare(
    "SELECT proj_id, TS_ID, TS_DATE, Task, $ttCol, $ptCol, $laCol, Hours, Invoice_No
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

// ── Build keyed structure: group by (proj_id, ptid|task_type_id|task text)
$taskMap = [];
foreach ($tsRows as $r) {
    $ptid = $r['Proj_Task_ID'] !== null ? (int)$r['Proj_Task_ID'] : null;
    $tid  = $r['Task_Type_ID'] !== null ? (int)$r['Task_Type_ID'] : null;
    $key  = $r['proj_id'] . '|' . ($ptid !== null ? "P$ptid" : ($tid !== null ? "T$tid" : 'X' . strtolower(trim($r['Task']))));
    if (!isset($taskMap[$key])) {
        $taskMap[$key] = [
            'proj_id'      => $r['proj_id'],
            'task'         => $r['Task'],
            'task_type_id' => $tid,
            'proj_task_id' => $ptid,
            'invoice_no'   => $r['Invoice_No'],
            'ts_id'        => $r['TS_ID'],
            'days'         => array_fill(1, 7, ''),
            // Per-day "this cell is unapproved future leave" flag — used
            // by the render loop to paint the input red.
            'pending_leave' => array_fill(1, 7, false),
            'tot'          => 0,
        ];
    }
    $dow = (int) date('N', strtotime($r['TS_DATE']));
    $taskMap[$key]['days'][$dow] = ($taskMap[$key]['days'][$dow] !== '')
        ? $taskMap[$key]['days'][$dow] + $r['Hours']
        : $r['Hours'];
    $taskMap[$key]['tot'] += $r['Hours'];
    // Flag future leave cells awaiting approval. (proj 1435, TS_DATE in
    // the future, Leave_Approved = 0). $tday is computed below — but
    // since we're inside the foreach, we need it now. Use today's Y-m-d.
    $rowDate = date('Y-m-d', strtotime($r['TS_DATE']));
    if ((int)$r['proj_id'] === LEAVE_PROJECT_ID
        && $rowDate > date('Y-m-d')
        && (int)($r['Leave_Approved'] ?? 0) === 0) {
        $taskMap[$key]['pending_leave'][$dow] = true;
    }
}
$taskRows = array_values($taskMap);

// ── Week dropdown options ───────────────────────────────────────────────────
// Use DateTime instead of strtotime — `strtotime('monday this week')` had a
// quirk where on a Monday it could resolve to the *previous* Monday, which
// made the +1-week offset land on today's week instead of next week. The
// DateTime ISO-8601 dayOfWeek arithmetic is unambiguous.
//
// Range now spans -6 weeks back through +3 weeks ahead (was +1) so full-
// time staff can enter annual-leave entries a few weeks in advance.
$weekOptions = [];
$today      = new DateTime('today');
$mondayDow  = (int)$today->format('N') - 1;          // 0 for Mon … 6 for Sun
$refMondayDt = (clone $today)->modify("-{$mondayDow} days");
$refMonday   = $refMondayDt->format('Y-m-d');
for ($i = -6; $i <= 3; $i++) {
    $opt = (clone $refMondayDt)->modify(($i >= 0 ? "+$i" : "$i") . ' weeks');
    $weekOptions[] = $opt->format('Y-m-d');
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
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Timesheet – <?= htmlspecialchars($_SESSION['UserID']) ?></title>
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet">
<style>
body  { background:#515559; font-family:Arial,sans-serif; font-size:12px; margin:0; padding:10px; }
.hdr  { background:#9B9B1B; color:#fff; }
.hdr a{ color:#fff; }
table { border-collapse:collapse; }
input[type=text] { font-size:11px; box-sizing:border-box; }
input[type=text].day-input,
input[type=text].tot-inp { padding:1px; }
.tbl-main { width:780px; margin:0 auto; table-layout:fixed; }
.tbl-main td, .tbl-main th { padding:2px 2px; overflow:hidden; }
.day-input { width:34px; text-align:center; }
.proj-sel  { width:128px; box-sizing:border-box; }
.task-sel  { width:240px; box-sizing:border-box; font-size:11px; }
.desc-inp  { width:198px; box-sizing:border-box; }
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
// Per-project task list keyed by proj_id → list of {tid, name, stage, est, logged, remaining}
window.PROJECT_TASKS = <?= json_encode($projectTasksJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

// Full-time-staff dynamic gap check. Only the *current* week recomputes
// live as the user types — previous weeks fall through to the existing
// server-rendered banner/button state.
window.IS_FULLTIME       = <?= $mustFillFullWeek ? 'true' : 'false' ?>;
window.IS_CURRENT_WEEK   = <?= $isViewingCurrentWeek ? 'true' : 'false' ?>;
window.IS_FUTURE_WEEK    = <?= $weekIsFuture ? 'true' : 'false' ?>;
window.REQUIRED_WEEKDAYS = <?= json_encode($requiredDowsCurrentWeek) ?>;
window.__currentWeekMissingDows = [];

function recomputeCurrentWeekGap() {
    if (!window.IS_FULLTIME || !window.IS_CURRENT_WEEK) return;
    var dows     = window.REQUIRED_WEEKDAYS || [];
    var dowNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    var stillMissing = [];
    for (var i = 0; i < dows.length; i++) {
        var d = dows[i];
        var hasAny = false;
        for (var r = 1; r <= 30; r++) {
            var el = document.submit_form && document.submit_form.elements['D' + d + '_' + r];
            if (el) {
                var v = parseFloat(el.value);
                if (!isNaN(v) && v > 0) { hasAny = true; break; }
            }
        }
        if (!hasAny) stillMissing.push(dowNames[d]);
    }
    var btn  = document.getElementById('submit-btn');
    var hint = document.getElementById('submit-hint');
    if (!btn) return;
    if (stillMissing.length > 0) {
        btn.value = '⚠ Submit (you have missing days)';
        btn.style.background = '#c33';
        btn.style.fontWeight = 'bold';
        btn.style.boxShadow  = '0 0 0 2px #fff3cd inset';
        if (hint) {
            hint.style.display = '';
            hint.textContent = stillMissing.length + ' weekday(s) missing this week (' +
                stillMissing.join(', ') + ') — fill every weekday before today, then ' +
                'the button will go back to normal.';
        }
    } else {
        btn.value = 'Submit Timesheet';
        btn.style.background = '#9B9B1B';
        btn.style.fontWeight = 'normal';
        btn.style.boxShadow  = '';
        if (hint) hint.style.display = 'none';
    }
    window.__currentWeekMissingDows = stillMissing;
}

// Recompute every time the user touches a day cell.
document.addEventListener('input', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('day-input')) {
        recomputeCurrentWeekGap();
    }
});

// Wire submit-time confirm dynamically. On the current week the dialog
// only fires if there are still gaps after the user's edits; on previous
// weeks we preserve the original "missing in last 4 weeks" behavior.
window.MISSING_PREV_WEEKS_COUNT = <?= count($missingPrevWeeks) ?>;
window.MISSING_LAST4_COUNT      = <?= count($missingWeekdays) ?>;
document.addEventListener('DOMContentLoaded', function() {
    // Sync button state with whatever values are already in the form.
    recomputeCurrentWeekGap();
    var f = document.submit_form;
    if (!f) return;
    f.addEventListener('submit', function(e) {
        if (!window.IS_FULLTIME) return;
        if (window.IS_CURRENT_WEEK) {
            recomputeCurrentWeekGap(); // sync with the latest edits
            var miss = window.__currentWeekMissingDows || [];
            if (miss.length > 0) {
                if (!confirm('⚠ You haven’t logged ' + miss.join(', ') +
                             ' yet this week.\n\nFull-time staff must log every weekday before ' +
                             'today (project hours OR a leave/sick entry).\n\nSubmit anyway?')) {
                    e.preventDefault();
                }
            }
        } else if (!window.IS_FUTURE_WEEK && window.MISSING_LAST4_COUNT > 0) {
            // Past-weeks nag fires when submitting a previous week with
            // historic gaps. Future weeks (booking leave in advance)
            // skip this — there's nothing to nag the user about yet.
            if (!confirm('⚠ You still have ' + window.MISSING_LAST4_COUNT +
                         ' missing weekday(s) in the past 4 weeks.\n\n' +
                         'This week will be saved, but the gap remains until you fill those days ' +
                         'too.\n\nSubmit anyway?')) {
                e.preventDefault();
            }
        }
    });
});

function populateTaskSelect(rowIdx, projId, currentPtid) {
    var sel = document.querySelector('select[name="Task' + rowIdx + '"]');
    if (!sel || sel.disabled) return;
    sel.innerHTML = '<option value="">-- pick task --</option>';
    var tasks = (window.PROJECT_TASKS && window.PROJECT_TASKS[projId]) || [];
    if (tasks.length === 0) return;
    // Group keys: stage name for original (vid==0); "v<vid>" for variations
    var groups = {};
    var groupOrder = [];
    var vMeta = {};
    tasks.forEach(function(t) {
        var key;
        if (t.vid && t.vid > 0) {
            key = 'v' + t.vid;
            if (!vMeta[key]) vMeta[key] = { vnumber: t.vnumber, vtitle: t.vtitle, vstatus: t.vstatus };
        } else {
            key = 's:' + t.stage;
        }
        if (!groups[key]) { groups[key] = []; groupOrder.push(key); }
        groups[key].push(t);
    });
    groupOrder.forEach(function(key) {
        var og = document.createElement('optgroup');
        if (key.indexOf('s:') === 0) {
            og.label = key.substring(2);
        } else {
            var m = vMeta[key];
            var unapproved = (m.vstatus || '') !== 'approved';
            og.label = '⚠ Variation #' + m.vnumber + ': ' + (m.vtitle || '') + ' [' + (m.vstatus || '?') + ']';
            if (unapproved) og.style.color = '#c33';
        }
        groups[key].forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t.ptid;
            var rem = (typeof t.remaining === 'number') ? t.remaining : (t.est - t.logged);
            var label = t.name + ' (' + rem.toFixed(1) + 'h left of ' + t.est.toFixed(1) + 'h)';
            if (t.vid && t.vid > 0 && (t.vstatus || '') !== 'approved') {
                label = '⚠ ' + label;
                opt.style.color = '#c33';
            } else if (rem < 0) {
                opt.style.color = '#a00';
            } else if (rem < 1) {
                opt.style.color = '#a60';
            }
            opt.textContent = label;
            og.appendChild(opt);
        });
        sel.appendChild(og);
    });
    if (currentPtid) sel.value = String(currentPtid);
}

function initTaskSelects() {
    document.querySelectorAll('select.proj-sel').forEach(function(projSel) {
        var rowIdx = projSel.name.replace('Project','');
        if (projSel.value) {
            var sel = document.querySelector('select[name="Task' + rowIdx + '"]');
            var pre = sel ? (sel.dataset.currentPtid || '') : '';
            populateTaskSelect(rowIdx, projSel.value, pre);
        }
        projSel.addEventListener('change', function() {
            populateTaskSelect(rowIdx, projSel.value, '');
        });
    });
}

window.addEventListener('DOMContentLoaded', initTaskSelects);

var _origOnload = window.onload;
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

<?php
// Banner #1 (existing behavior, narrowed scope) — list missing weekdays in
// previous weeks. The current week is handled separately by the live
// JS-driven hint next to the submit button.
if ($mustFillFullWeek && !empty($missingPrevWeeks)):
    $byWeek = [];
    foreach ($missingPrevWeeks as $d) {
        $dt2  = new DateTime($d);
        $dow2 = (int)$dt2->format('N') - 1;
        $mon  = (clone $dt2)->modify("-{$dow2} days")->format('Y-m-d');
        $byWeek[$mon][] = date('D j M', strtotime($d));
    }
?>
<div style="width:680px;margin:6px auto;background:#ffd6d6;border:2px solid #c33;border-radius:4px;padding:10px 14px;color:#a00">
  <strong style="font-size:14px">⚠ <?= count($missingPrevWeeks) ?> weekday(s) not filled in previous weeks</strong>
  <div style="font-size:11px;margin-top:4px">
    As a full-time staff member you must log every weekday — either with project tasks or with a leave / sick entry.
  </div>
  <ul style="margin:6px 0 0;padding-left:18px;font-size:11px">
    <?php foreach ($byWeek as $weekMon => $days): ?>
      <li>Week of <?= date('D j M', strtotime($weekMon)) ?>:
        <strong><?= htmlspecialchars(implode(', ', $days)) ?></strong>
        &nbsp;<a href="main.php?week=<?= urlencode($weekMon) ?>" style="color:#a00;text-decoration:underline">Open this week</a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php
// Banner #2 (new) — when the user clicks "Open this week" on a previous-
// week link AND they haven't filled the current week through yesterday,
// bounce them to fix the current week first.
if ($mustFillFullWeek && !$isViewingCurrentWeek && !empty($missingCurrWeekUpToYesterday)):
    $todaysGapLabels = array_map(fn($d) => date('D j M', strtotime($d)), $missingCurrWeekUpToYesterday);
?>
<div style="width:680px;margin:6px auto;background:#fff3cd;border:2px solid #d68910;border-radius:4px;padding:10px 14px;color:#7a4d00">
  <strong style="font-size:14px">⚠ Finish the current week first</strong>
  <div style="font-size:11px;margin-top:4px">
    You haven't logged every weekday before today in the current week
    (<strong><?= htmlspecialchars(implode(', ', $todaysGapLabels)) ?></strong> still empty).
    Please complete the current week before filling in earlier ones.
    &nbsp;<a href="main.php?week=<?= urlencode($currentWeekMonday) ?>" style="color:#7a4d00;text-decoration:underline;font-weight:bold">Go to current week</a>
  </div>
</div>
<?php endif; ?>

<?php if ($mustFillFullWeek): ?>
<!-- Annual-leave reminder for full-time staff. Project 1435 is the leave
     project; future-dated entries get flagged red until Erik approves them
     on the main menu. -->
<div style="width:680px;margin:6px auto;background:#eef4ff;border:1px solid #c0d0ee;border-radius:4px;padding:8px 12px;color:#246;font-size:11px">
  <strong>📅 Annual leave:</strong> please book your leave in advance by
  entering your hours against project <strong>0 - Leave Annual</strong>
  on the relevant dates. Future-dated leave entries appear red on your
  timesheet until Erik approves them — the dropdown above now goes
  <strong>3 weeks ahead</strong> so you can book them ahead of time.
</div>
<?php endif; ?>

<!-- ── Submit form ───────────────────────────────────────────────────────── -->
<?php
    // Initial state. On the current week, the JS recomputes this on every
    // keystroke (so the button flips back to normal once Mon→yesterday is
    // filled). On previous weeks we keep the original "any missing day in
    // the last 4 weeks" rule so the historic banner & button stay aligned.
    // On FUTURE weeks the gap nag never fires — the user is filling those
    // in advance for annual leave, the past-weeks counter is irrelevant.
    if (!$mustFillFullWeek)             $initialHasGap = false;
    elseif ($isViewingCurrentWeek)      $initialHasGap = !empty($missingCurrWeekUpToYesterday);
    elseif ($weekIsFuture)              $initialHasGap = false;
    else                                $initialHasGap = !empty($missingWeekdays);
?>
<form action="submit.php" id="submit_form" name="submit_form" method="post" style="width:680px;margin:0 auto">
<input type="hidden" name="hidden_week" value="<?= htmlspecialchars($weekStart) ?>">
<div style="padding:4px 0">
  <input type="submit" name="Submit" id="submit-btn"
         value="<?= $initialHasGap ? '⚠ Submit (you have missing days)' : 'Submit Timesheet' ?>"
         style="padding:6px 16px;background:<?= $initialHasGap ? '#c33' : '#9B9B1B' ?>;color:#fff;border:none;cursor:pointer;border-radius:3px;font-weight:<?= $initialHasGap ? 'bold' : 'normal' ?>;<?= $initialHasGap ? 'box-shadow:0 0 0 2px #fff3cd inset;' : '' ?>">
  <span id="submit-hint" style="color:#a00;font-size:11px;margin-left:8px;<?= $initialHasGap ? '' : 'display:none' ?>">
    <?php if ($initialHasGap && $isViewingCurrentWeek): ?>
      <?= count($missingCurrWeekUpToYesterday) ?> weekday(s) missing this week — fill every weekday before today, then the button will go back to normal.
    <?php elseif ($initialHasGap): ?>
      <?= count($missingWeekdays) ?> missing weekday(s) in the past 4 weeks — submit will save what you've entered, but please go back and fill those too.
    <?php endif; ?>
  </span>
</div>

<div>
<table class="tbl-main" border="0" cellpadding="0" cellspacing="0">

<!-- Column headings row -->
<tr>
  <th style="width:130px">Project</th>
  <th style="width:0"></th><!-- hidden Invoice_No col -->
  <th style="width:245px">Task <span style="font-weight:normal;font-size:10px">(remaining hrs)</span></th>
  <th style="width:150px">Notes</th>
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
    $tr        = $taskRows[$a - 1] ?? null;
    $projVal   = $tr ? $tr['proj_id']      : '';
    $taskPtid  = $tr ? (int)($tr['proj_task_id'] ?? 0) : 0;
    $taskTid   = $tr ? (int)($tr['task_type_id'] ?? 0) : 0;
    $taskTxt   = $tr ? $tr['task']         : '';
    $invNo     = $tr ? $tr['invoice_no']   : 0;
    $tot       = $tr ? $tr['tot']          : 0;
    $days      = $tr ? $tr['days']         : array_fill(1, 7, '');
    $pendingLv = $tr ? ($tr['pending_leave'] ?? array_fill(1, 7, false)) : array_fill(1, 7, false);
    $locked    = ($invNo != 0);
    // Resolve ptid from task_type_id when only the older FK is set
    if (!$taskPtid && $taskTid && $projVal !== '' && !empty($projectTasks[(int)$projVal])) {
        foreach ($projectTasks[(int)$projVal] as $pt) {
            if ($pt['tid'] === $taskTid && $pt['vid'] === 0) { $taskPtid = $pt['ptid']; break; }
        }
    }
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
  <td>
    <select <?= $locked ? 'disabled' : '' ?> name="Task<?= $a ?>" class="task-sel"
            data-current-ptid="<?= (int)$taskPtid ?>"
            data-row="<?= $a ?>">
      <option value="">-- pick task --</option>
      <?php if ($locked && $taskTxt !== ''): ?>
        <option value="<?= (int)$taskPtid ?>" selected><?= htmlspecialchars($taskTxt) ?></option>
      <?php endif; ?>
    </select>
  </td>
  <td><input <?= $locked ? 'disabled' : '' ?> type="text" name="Desc<?= $a ?>"
       value="<?= htmlspecialchars($taskTxt) ?>"
       class="desc-inp" placeholder="(notes)"
       style="width:140px;font-size:11px"></td>
  <?php for ($d = 1; $d <= 7; $d++):
      $cellPending = !empty($pendingLv[$d]);
      $cellStyle   = $cellPending ? 'background:#ffd6d6;border:1px solid #c33' : '';
      $cellTitle   = $cellPending ? 'Future leave entry — pending Erik\'s approval. Will go back to normal once approved.' : '';
  ?>
    <td><input <?= $locked ? 'disabled' : '' ?> type="text" size="3"
         id="D<?= $d ?>_<?= $a ?>" name="D<?= $d ?>_<?= $a ?>"
         value="<?= htmlspecialchars($days[$d]) ?>"
         class="day-input<?= $cellPending ? ' pending-leave' : '' ?>"
         <?= $cellStyle ? 'style="' . $cellStyle . '"' : '' ?>
         <?= $cellTitle ? 'title="' . htmlspecialchars($cellTitle) . '"' : '' ?>
         onblur="OnLostFocusTest(this)" onkeypress="OnKeyPressTest(event)"></td>
  <?php endfor; ?>
  <td><input disabled type="text" size="3" name="Total_<?= $a ?>"
       value="<?= $tot > 0 ? htmlspecialchars($tot) : '' ?>" class="tot-inp"></td>
</tr>
<?php endfor; ?>

</table>
</div>
</form>

<!-- ── Variation request button ─────────────────────────────────────────── -->
<div style="width:680px;margin:18px auto;text-align:center">
  <a href="variation_add.php"
     style="display:inline-block;background:#c33;color:#fff;padding:9px 18px;border-radius:4px;text-decoration:none;font-weight:bold;font-size:13px;box-shadow:0 1px 3px rgba(0,0,0,0.2)">
    + Add unapproved variation
  </a>
  <div style="color:#aaa;font-size:11px;margin-top:6px">
    Use this when a client has asked for extra work beyond the original quote.
  </div>
</div>

</body>
</html>
