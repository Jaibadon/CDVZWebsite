<?php
// ─────────────────────────────────────────────────────────────────────────
// Do ALL processing (session, validation, DB work) BEFORE any HTML output.
// HTTP/2 is strict: if the server starts streaming HTML and then the
// connection or response is interrupted (slow query, error mid-write,
// chunked-encoding hiccup), Chrome raises ERR_HTTP2_PROTOCOL_ERROR.
// Buffering the whole response and only flushing once it's complete
// avoids that class of failure.
// ─────────────────────────────────────────────────────────────────────────
session_start();
require_once 'db_connect.php';   // also enables output buffering + 120s time limit
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

// If accessed directly without form POST, redirect to main.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['hidden_week'])) {
    header('Location: main.php');
    exit;
}

$pdo = get_db();

// Note: the missing-weekdays warning is shown on main.php (banner) so
// full-time staff can see the gap. We deliberately do NOT block submit
// here — that would create a chicken-and-egg where they can't fill in
// missed days because the very submission that would fill them is blocked.

$error = "successful";
$locked = "false";

// get proper format for start and end dates
$weekStart = $_POST['hidden_week'];
$weekEnd = date('Y-m-d', strtotime("+6 days", strtotime($weekStart)));

// MySQL ISO date strings for DELETE query
$weekStartISO = date('Y-m-d', strtotime($weekStart));
$weekEndISO   = date('Y-m-d', strtotime($weekEnd));

$tday = date('Y-m-d');
$lockbackdate = date('Y-m-d', strtotime("-2 months", strtotime($tday)));
$editperiod = $_POST['hidden_week'];

// DateDiff "m" equivalent: negative means editperiod is before lockbackdate
$lockMonths = ((int)date('Y', strtotime($editperiod)) - (int)date('Y', strtotime($lockbackdate))) * 12
            + ((int)date('n', strtotime($editperiod)) - (int)date('n', strtotime($lockbackdate)));

if ($lockMonths < 0) {
    $locked = "true";
    $error = "<font color=red><b>FAILED</b></font>";
}

$totaltime = 0;
$errorShown = false;

if ($locked === "true") {
    echo "<br><br><font color=red size=2 face=tahoma><b>You are attempting to modify your timesheet in an unauthorised period!  You may only modify your timesheet for the last couple months.<br><br></font></b>";
} else {
    // Wrap delete + all inserts in a single transaction — much faster than
    // 30 individual auto-commits, and atomic if anything fails.
    $pdo->beginTransaction();
    try {
        // Detect Leave_Approved column up front so we can preserve any
        // already-approved future leave entries through the delete+insert
        // cycle (otherwise every resubmit would force Erik to re-approve).
        $hasLeaveApproved = false;
        try {
            $hasLeaveApproved = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Leave_Approved'")->fetch();
        } catch (Exception $e) { /* ignore */ }

        $preApprovedDates = [];
        if ($hasLeaveApproved) {
            try {
                $sn = $pdo->prepare("SELECT TS_DATE FROM Timesheets
                                      WHERE proj_id = ? AND Employee_id = ?
                                        AND TS_DATE BETWEEN ? AND ?
                                        AND Leave_Approved = 1");
                $sn->execute([LEAVE_PROJECT_ID, (int)$_SESSION['Employee_id'], $weekStartISO, $weekEndISO]);
                foreach ($sn->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $preApprovedDates[date('Y-m-d', strtotime($r['TS_DATE']))] = true;
                }
            } catch (Exception $e) { /* non-fatal */ }
        }

        $delStmt = $pdo->prepare("DELETE FROM Timesheets WHERE TS_DATE BETWEEN ? AND ? AND Employee_id = ? AND Invoice_No = 0");
        $delStmt->execute([$weekStartISO, $weekEndISO, (int)$_SESSION['Employee_id']]);

        // ── TS_ID assignment ──────────────────────────────────────────────
        // The Timesheets table has an AFTER DELETE trigger
        // (Timesheet_catch) that copies every deleted row into
        // Timesheets_HIST. If we ever pick a TS_ID that's already sitting
        // in Timesheets_HIST, the next time that row is archived the
        // trigger throws "Duplicate entry … for key 'PRIMARY'" and kills
        // the whole submit. So we MUST compute MAX across both tables —
        // not just Timesheets — when picking the next TS_ID.
        $maxExpr = "(SELECT COALESCE(MAX(TS_ID), 0) FROM Timesheets)";
        try {
            if ($pdo->query("SHOW TABLES LIKE 'Timesheets_HIST'")->fetch()) {
                $maxExpr = "GREATEST($maxExpr, (SELECT COALESCE(MAX(TS_ID), 0) FROM Timesheets_HIST))";
            }
        } catch (Exception $e) { /* HIST table doesn't exist — fine */ }
        $nextStmt = $pdo->query("SELECT $maxExpr + 1 AS nxt");
        $nextTsId = (int)$nextStmt->fetch(PDO::FETCH_ASSOC)['nxt'];
        if ($nextTsId < 1) $nextTsId = 1;

        // Detect new schema columns. Each falls back gracefully if missing.
        $hasTaskTypeId = false;
        $hasProjTaskId = false;
        $hasVariation  = false;
        try {
            $hasTaskTypeId = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Task_Type_ID'")->fetch();
            $hasProjTaskId = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Proj_Task_ID'")->fetch();
            $hasVariation  = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Variation_ID'")->fetch();
        } catch (Exception $e) { /* ignore */ }

        // Build INSERT shape based on which columns exist
        $cols = ['TS_ID','TS_DATE','Employee_id','proj_id','Task'];
        if ($hasTaskTypeId)   $cols[] = 'Task_Type_ID';
        if ($hasVariation)    $cols[] = 'Variation_ID';
        if ($hasProjTaskId)   $cols[] = 'Proj_Task_ID';
        if ($hasLeaveApproved) $cols[] = 'Leave_Approved';
        $cols[] = 'Hours';
        $cols[] = 'Invoice_No';
        $placeholders = array_fill(0, count($cols) - 1, '?');
        $placeholders[] = '0'; // Invoice_No always 0 on submit
        $insStmt = $pdo->prepare("INSERT INTO Timesheets (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")");

        // Build Proj_Task_ID → {tid, vid, name} lookup so we can derive
        // Task_Type_ID + Variation_ID from the picker's posted ptid.
        $ptidLookup = [];
        $hasPTVariationCol = false;
        try {
            $hasPTVariationCol = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Variation_ID'")->fetch();
        } catch (Exception $e) { /* ignore */ }
        $vCol = $hasPTVariationCol ? 'pt.Variation_ID' : 'NULL AS Variation_ID';
        foreach ($pdo->query("SELECT pt.Proj_Task_ID, pt.Task_Type_ID, $vCol, tt.Task_Name
                                FROM Project_Tasks pt
                                JOIN Tasks_Types tt ON pt.Task_Type_ID = tt.Task_ID")->fetchAll() as $r) {
            $ptidLookup[(int)$r['Proj_Task_ID']] = [
                'tid'  => (int)$r['Task_Type_ID'],
                'vid'  => $r['Variation_ID'] !== null ? (int)$r['Variation_ID'] : null,
                'name' => $r['Task_Name'],
            ];
        }

        for ($a = 1; $a <= 40; $a++) {
            if (!isset($_POST['Project' . $a])
                || $_POST['Project' . $a] === ""
                || ($_POST['Invoice_No' . $a] ?? '') !== "0") {
                continue;
            }

            // Picker now posts Proj_Task_ID. Resolve to Task_Type_ID + Variation_ID.
            $projTaskId = isset($_POST['Task' . $a]) && $_POST['Task' . $a] !== ''
                ? (int)$_POST['Task' . $a]
                : null;
            $taskTypeId = null;
            $variationId = null;
            $resolvedName = null;
            if ($projTaskId !== null && isset($ptidLookup[$projTaskId])) {
                $taskTypeId   = $ptidLookup[$projTaskId]['tid'];
                $variationId  = $ptidLookup[$projTaskId]['vid'];
                $resolvedName = $ptidLookup[$projTaskId]['name'];
            }

            for ($b = 1; $b <= 7; $b++) {
                $dayKey = "D" . $b . "_" . $a;
                if (!isset($_POST[$dayKey]) || $_POST[$dayKey] === "") continue;

                $hours = (float)$_POST[$dayKey];
                $totaltime += $hours;

                // Description: explicit Notes input wins; else fall back to task name.
                $descKey = isset($_POST['Desc' . $a]) ? 'Desc' . $a : 'desc' . $a;
                $userDesc = trim($_POST[$descKey] ?? '');
                $desc = $userDesc !== '' ? $userDesc : ($resolvedName ?? '');

                if ($desc === '' && $projTaskId === null) {
                    if (!$errorShown) {
                        echo "<font face=tahoma size=2 color=red><br><hr>There was an error in your submission.  Hit the back button and correct your data.<br>Remember you MUST pick a Task or fill in a description.<br><hr></font>";
                        $errorShown = true;
                    }
                    $error = "not cool";
                    continue;
                }

                $tsDate = date('Y-m-d', strtotime("+" . ($b - 1) . " days", strtotime($weekStart)));
                $projId = (int)$_POST['Project' . $a];
                $empId  = (int)$_SESSION['Employee_id'];

                // Future-dated leave entries (project 1435) get
                // Leave_Approved=0 unless Erik already approved this exact
                // date before the resubmit (preserved via $preApprovedDates).
                // Past leave + every non-leave row stays NULL (N/A).
                $leaveApprovedVal = null;
                if ($hasLeaveApproved && $projId === LEAVE_PROJECT_ID && $tsDate > $tday) {
                    $leaveApprovedVal = isset($preApprovedDates[$tsDate]) ? 1 : 0;
                }

                $params = [$nextTsId, $tsDate, $empId, $projId, $desc];
                if ($hasTaskTypeId)    $params[] = $taskTypeId;
                if ($hasVariation)     $params[] = $variationId;
                if ($hasProjTaskId)    $params[] = $projTaskId;
                if ($hasLeaveApproved) $params[] = $leaveApprovedVal;
                $params[] = $hours;

                // Retry-on-duplicate: a stale row, race, or weird default
                // can leave the candidate TS_ID already taken. Refetch
                // MAX+1 (across BOTH tables, see comment above) and bump
                // $nextTsId; cap at 5 attempts to avoid an infinite loop.
                $attempts = 0;
                while (true) {
                    try {
                        $insStmt->execute($params);
                        break;
                    } catch (PDOException $insErr) {
                        $isDupe = ($insErr->getCode() === '23000' || strpos($insErr->getMessage(), '1062') !== false);
                        if (!$isDupe || ++$attempts >= 5) throw $insErr;
                        $r = $pdo->query("SELECT $maxExpr + 1 AS nxt");
                        $fresh = (int)$r->fetch(PDO::FETCH_ASSOC)['nxt'];
                        $nextTsId = max($fresh, $nextTsId + 1, 1);
                        $params[0] = $nextTsId;   // TS_ID is always the first param
                    }
                }
                $nextTsId++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "<font color=red><b>FAILED: " . htmlspecialchars($e->getMessage()) . "</b></font>";
    }
}

// Re-check missing weekdays AFTER the submit so we can warn the staff
// member (without blocking) about any remaining gaps.
$postSubmitMissing = [];
if (is_employed_staff($_SESSION['UserID'] ?? '')) {
    try {
        $postSubmitMissing = missing_weekdays($pdo, (int)($_SESSION['Employee_id'] ?? 0), 4);
    } catch (Exception $e) { /* helpers may not be loaded */ }
}

// ─── DB work done — start emitting the response page ────────────────────
?>
<!DOCTYPE html>
<html>
<head>
<style type="text/css">
.style1 { background-color: #9B9B1B; }
</style>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>CADViz Timesheet</title>
<link rel="stylesheet" href="global.css" type="text/css">
</head>
<body bgcolor="#DFEFEF">

<?php if (!empty($postSubmitMissing)): ?>
<!-- Big red banner for full-time staff who still have missing days. The
     timesheet WAS submitted (this week's entries are saved) — this is just
     a reminder that the gap remains. -->
<div style="max-width:760px;margin:14px auto;padding:14px 18px;background:#ffd6d6;border:3px solid #c33;border-radius:6px;color:#a00">
  <h2 style="margin:0 0 6px;color:#a00">⚠ Submitted, but you still have missing days</h2>
  <p style="margin:0 0 8px"><strong>Your timesheet WAS saved</strong> — but as a full-time staff member you
     still have <strong><?= count($postSubmitMissing) ?> weekday(s)</strong> in the past 4 weeks with no time
     logged. Please go back and fill them in (project work, leave, or sick).</p>
  <p style="margin:0 0 6px"><strong>Missing dates:</strong></p>
  <ul style="margin:0 0 8px 18px">
    <?php foreach (array_slice($postSubmitMissing, 0, 12) as $d):
        $mon = date('Y-m-d', strtotime('monday this week', strtotime($d)));
    ?>
      <li><?= htmlspecialchars(date('D j M Y', strtotime($d))) ?> &nbsp;
          <a href="main.php?week=<?= urlencode($mon) ?>" style="color:#a00">→ Open week of <?= htmlspecialchars(date('D j M', strtotime($mon))) ?></a></li>
    <?php endforeach; ?>
    <?php if (count($postSubmitMissing) > 12): ?><li>(+<?= count($postSubmitMissing) - 12 ?> more)</li><?php endif; ?>
  </ul>
  <a href="main.php" style="background:#c33;color:#fff;padding:6px 14px;border-radius:3px;text-decoration:none;font-weight:bold">← Back to timesheet to fill missing days</a>
</div>
<?php endif; ?>

<?php if ($errorShown): ?>
<?php elseif ($locked === "false" || $error === "successful"): ?>
<p>&nbsp;</p><p>&nbsp;</p>
<?php endif; ?>
          <table align="center" border="0" cellspacing="0" width="90%" cellpadding="0" id="table1" class="style1">
            <tr>
              <td align="center" colspan="7">
                <h1><b>&nbsp;<br>
                  <font color="#FFFFFF">CADViz Timesheet Data for <?= htmlspecialchars($_SESSION['UserID']) ?></font></b>
                </h1>
              </td>
            </tr>
            <tr>
              <td align="center"><a href="http://www.cadviz.co.nz" onMouseOver="window.status='www.cadviz.co.nz'; return true" onMouseOut="window.status=''; return true">
				<font color="#FFFFFF">CADViz Website</font></a>
				<td align="center"><a href="mailto:mail.cadviz.co.nz"><font color="#FFFFFF">Mail Queries</font></a></td>
              <td align="center"><a href="main.php" onMouseOver="window.status='Go to the input table'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">Main Timesheet
                Screen</font></a></td>
              <td align="center">
				<a onMouseOver="window.status='Click here to re-login'; return true" onMouseOut="window.status=''; return true" href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
              <td align="center"><a href="report.php" onMouseOver="window.status='Run some basic reports'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">Reports</font></a></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
            <tr>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
          </table>
<center>
  <table width="90%" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB">
    <tr>
      <td>
        <div align="center">
          <p>&nbsp;</p>
          <p><font color="#515559">Submit <?= $error ?> for </font><b>
			<font color="#515559"><?= htmlspecialchars($_SESSION['UserID']) ?></font></b><font color="#515559">, Week Beginning
			</font><b> <font color="#515559"><?= date('l, d F Y', strtotime($weekStart)) ?></font></b><font color="#515559"><br>
            <br>
            Total Hours Submitted (exluding locked/invoiced rows):</font><b><font color="#515559"><?= $totaltime ?></font></b><font color="#515559"> <br>
            <br>
            Retrieve Again?</font>
            <?php retrieve_part(date('Y-m-d', strtotime("+7 days", strtotime($weekStart)))); ?>
          </p>
          </div>
      </td>
    </tr>
  </table>
  <p>&nbsp;</p>
</center></body>
</html>
<?php
// do the retrieve part: week dd box and retrieve button
function retrieve_part($currentWeek) {
?>
<form action="main.php" name="retrieve_form" method="POST">
  <p><b>Week starting:</b>
    <SELECT name="week">
      <?php
    $curdate = date('Y-m-d');
    $wd = (int)date('N', strtotime($curdate)); // 1=Mon, 7=Sun
    $startdate = date('Y-m-d', strtotime("-" . ($wd - 1) . " days", strtotime($curdate)));
    for ($a = -4; $a <= 2; $a++) {
        $curdate = date('Y-m-d', strtotime("+" . (7 * $a) . " days", strtotime($startdate)));
        $oput = date('l, d F Y', strtotime($curdate));
        if ($currentWeek === $curdate) {
            echo "<OPTION SELECTED VALUE=\"$curdate\">$oput";
        } else {
            echo "<OPTION VALUE=\"$curdate\">$oput";
        }
    }
?>
    </SELECT>
    <input type="submit" name="Retrieve" value="Retrieve">
  </p>
</form>
<div align="left">
  <p><br>
  </p>
</div>
<?php
} // end retrieve_part
?>
