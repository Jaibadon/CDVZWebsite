<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
?>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<link href="site.css" rel="stylesheet">
<link href="global2.css" rel="stylesheet" type="text/css" />
<title>Active Projects for <?= htmlspecialchars($_SESSION['UserID']) ?> </title>
<basefont face="arial">
<style type="text/css">
.style1 {
	background-color: #9B9B1B;
}
</style>
</head>
<body bgcolor="#EBEBEB" text="black">

    <table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table8">
      <tr>
        <td>
          <table border="0" cellspacing="0" width="644" cellpadding="0" id="table9" class="style1">
            <tr>
              <td align="center" colspan="4" height="26">
                <p align="left">
              <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Project List</font></b></td>
              <td align="center" colspan="3" height="26">
                <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
            </tr>
            <tr>
              <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
              <td align="center">
				<a href="main.php"
	onMouseOver="window.status='Go to the input table'; return true",
	onMouseOut="window.status=''; return true"><font color="#FFFFFF">My Timesheet</font></a></td>
              <td align="center">
				<a onMouseOver="window.status='Click here to re-login'; return true" , onMouseOut="window.status=''; return true" href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
              <td align="center" colspan="2"><a href="report.php"
	onMouseOver="window.status='Run some basic reports'; return true",
	onMouseOut="window.status=''; return true"><font color="#FFFFFF">Reports</font></a></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
            <tr>
            <td align="center">&nbsp;
			<a href='projects_archive1.php'><font color='#FFFFFF' size='2'>Projects Archive</font></a>
              </td>
              <td align="center">&nbsp;
</td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
              <td align="center" colspan="2"><?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>";
}
?></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
<p></p>

<?php
// Case-insensitive lookup helper for MySQL row arrays
function ci(array $row, string $key, $default = '') {
    foreach ($row as $k => $v) {
        if (strcasecmp($k, $key) === 0) return $v;
    }
    return $default;
}

function fmtDate($d) {
    if (empty($d)) return '';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : '';
}

try {
$pdo = get_db();

if ($_SESSION['UserID'] == "erik") {
    $strSQL = "SELECT * FROM Projects WHERE Active <> 0 AND Client_ID <> 195 ORDER BY JobName";
    $stmt = $pdo->query($strSQL);
} else {
    $emp_id = (int)($_SESSION['Employee_id'] ?? 0);
    // Visibility:
    //   1. Manager / DP1 / DP2 / DP3 directly on Projects (legacy rule).
    //   2. NEW: assigned to any task in an *accepted* quote on this
    //      project — i.e. once the quote is locked, anyone Erik assigned
    //      a task to can see the project too. Gated on the quote being
    //      accepted so draft-stage scribbles don't leak the project early.
    //      Falls back gracefully when Quote_Status column doesn't exist.
    $hasQuoteStatus = false;
    try { $hasQuoteStatus = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'Quote_Status'")->fetch(); } catch (Exception $e) {}
    $hasIsRemoved = false;
    try { $hasIsRemoved = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Is_Removed'")->fetch(); } catch (Exception $e) {}
    $removedFilter = $hasIsRemoved ? "AND COALESCE(pt.Is_Removed, 0) = 0" : "";
    $assignedClause = $hasQuoteStatus
        ? "OR EXISTS (
              SELECT 1 FROM Project_Tasks pt
              JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
             WHERE ps.Proj_ID = Projects.proj_id
               AND pt.Assigned_To = ?
               $removedFilter
           ) AND Projects.Quote_Status = 'accepted'"
        : "";
    $sql = "SELECT * FROM Projects
             WHERE (Active <> 0 AND Client_ID <> 195)
               AND (Manager = ? OR DP1 = ? OR DP2 = ? OR DP3 = ? $assignedClause)
             ORDER BY JobName";
    $stmt = $pdo->prepare($sql);
    $params = [$emp_id, $emp_id, $emp_id, $emp_id];
    if ($hasQuoteStatus) $params[] = $emp_id;  // for the EXISTS subquery
    $stmt->execute($params);
}

// One-time check: does Timesheets have the Proj_Task_ID column? Used per
// row below to split logged hours into "via tasks" vs "unassigned".
$hasProjTaskIdCol = false;
try { $hasProjTaskIdCol = (bool)$pdo->query("SHOW COLUMNS FROM Timesheets LIKE 'Proj_Task_ID'")->fetch(); } catch (Exception $e) {}

$shown = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $shown++;
    $projId   = ci($row, 'proj_id');
    $jobName  = ci($row, 'JobName');
    $finalDt  = ci($row, 'Final_Date');
    $draftDt  = ci($row, 'Draft_Date');
    $priority = ci($row, 'Initial_Priority');
    $status   = ci($row, 'Status');

    echo "<br>";
    $isAdminUser = in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true);
    $linkHref = $isAdminUser
        ? "updateform_admin1.php?proj_id=" . htmlspecialchars((string)$projId)
        : "my_checklist.php?proj_id=" . htmlspecialchars((string)$projId);
    echo "<a href=\"$linkHref\">";
    echo htmlspecialchars((string)$jobName);
    echo "</a>";
    echo "&nbsp;&nbsp;&nbsp;-&nbsp;&nbsp;&nbsp;Priority:&nbsp;";

    // ── Compute overdue level from Final_Date ──────────────────────────────
    $od = 0;
    if (!empty($finalDt)) {
        $dd = (int)((strtotime(date('Y-m-d')) - strtotime($finalDt)) / 86400);
        if      ($dd > 7)   $od = 3;
        elseif  ($dd > 0)   $od = 2;
        elseif  ($dd > -2)  $od = 1;
        else                $od = 0;
    }

    // ── Render priority label (red HIGHLIGHT for Urgent / Critical) ────────
    if (stripos($priority, 'Hold') !== false) {
        echo '<span style="color:silver;font-size:14px">Normal - On Hold</span>';
    } elseif (stripos($priority, 'Normal') !== false) {
        switch ($od) {
            case 0: echo '<span style="color:black;font-size:14px">Normal</span>'; break;
            case 1: echo '<span style="color:orange;font-size:14px">Normal - Almost Due</span>'; break;
            case 2: echo '<span style="color:orange;font-size:14px">Normal - Overdue</span>'; break;
            case 3: echo '<span style="background:red;color:white;font-weight:bold;padding:2px 6px;font-size:14px">Urgent - Overdue</span>'; break;
        }
    } elseif (stripos($priority, 'High') !== false) {
        switch ($od) {
            case 0: echo '<span style="color:red;font-size:14px">High</span>'; break;
            case 1: echo '<span style="color:red;font-size:14px">High - Almost Due</span>'; break;
            case 2:
            case 3: echo '<span style="background:red;color:white;font-weight:bold;padding:2px 6px;font-size:14px">Critical - Overdue</span>'; break;
        }
    } else {
        echo '<span style="font-size:14px">' . htmlspecialchars((string)$priority) . '</span>';
    }
    echo ",&nbsp;";

    // ── Hours used vs estimated ────────────────────────────────────────────
    // Match my_checklist.php's accounting: split logged time into "via tasks"
    // (Proj_Task_ID linked to a specific Project_Tasks row) and "unassigned"
    // (rows with no Proj_Task_ID — legacy data or quick entries without
    // picking a task). Unassigned hours don't count toward the per-task
    // remaining-budget rollup but they DO consume the project's overall
    // budget, so we surface them explicitly here.
    if ($hasProjTaskIdCol) {
        $tsStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN Proj_Task_ID IS NOT NULL AND Proj_Task_ID > 0 THEN Hours ELSE 0 END), 0) AS tasked,
                COALESCE(SUM(CASE WHEN Proj_Task_ID IS NULL     OR  Proj_Task_ID = 0 THEN Hours ELSE 0 END), 0) AS unassigned
              FROM Timesheets WHERE proj_id = ?"
        );
        $tsStmt->execute([$projId]);
        $tsRow      = $tsStmt->fetch(PDO::FETCH_ASSOC) ?: ['tasked' => 0, 'unassigned' => 0];
        $tasked     = (float)$tsRow['tasked'];
        $unassigned = (float)$tsRow['unassigned'];
    } else {
        $tsStmt = $pdo->prepare("SELECT COALESCE(SUM(Hours), 0) AS tot FROM Timesheets WHERE proj_id = ?");
        $tsStmt->execute([$projId]);
        $tasked     = (float)($tsStmt->fetch(PDO::FETCH_ASSOC)['tot'] ?? 0);
        $unassigned = 0.0;
    }
    $tot = $tasked + $unassigned;

    $estStmt = $pdo->prepare(
        "SELECT SUM(Estimated_Time * Project_Tasks.Weight) AS EST_HOURS
           FROM Tasks_Types
           RIGHT JOIN (Project_Tasks
                       RIGHT JOIN (Project_Stages
                                   RIGHT JOIN Projects ON Project_Stages.Proj_ID = Projects.proj_id)
                                  ON Project_Tasks.Project_Stage_ID = Project_Stages.Project_Stage_ID)
                      ON Tasks_Types.Task_ID = Project_Tasks.Task_Type_ID
          WHERE Projects.proj_id = ?"
    );
    $estStmt->execute([$projId]);
    $estHours = (float)($estStmt->fetch(PDO::FETCH_ASSOC)['EST_HOURS'] ?? 0);

    // Compose the breakdown string. Always include an estimate reference —
    // the previous version went silent when no estimate was set, leaving
    // the staff member staring at "100 hours used" with no idea whether
    // that was on-budget or wildly over.
    $breakdown = number_format($tasked, 1) . 'h via tasks';
    if ($unassigned > 0) {
        $breakdown .= ' + ' . number_format($unassigned, 1) . 'h unassigned';
    }
    $breakdown .= ' (' . number_format($tot, 1) . 'h total used)';
    $estLabel = $estHours > 0
        ? ' out of ' . number_format($estHours, 1) . 'h allowed'
        : ' &mdash; <em>no estimate set yet (add tasks in the quote builder)</em>';

    $isOver = ($estHours > 0 && ($tot + 8) > $estHours);
    if ($isOver) {
        echo '<span style="color:red;font-weight:bold;font-size:14px">*** ' . $breakdown . $estLabel . '</span>';
    } else {
        echo '<span style="color:green;font-size:13px">' . $breakdown . $estLabel . '</span>';
    }
    echo ',&nbsp;&nbsp;&nbsp;Current Status:&nbsp;';
    echo htmlspecialchars((string)$status);
    echo "&nbsp;&nbsp;";
    if (!empty($draftDt)) echo "Draft: " . fmtDate($draftDt) . "&nbsp;&nbsp;";
    if (!empty($finalDt)) echo "Final: " . fmtDate($finalDt) . "&nbsp;&nbsp;";
    echo "<a href=\"jobdone.php?proj_id=" . htmlspecialchars((string)$projId) . "\">Click to set Job Done!</a>";
    echo "<br>";
}
if ($shown === 0) {
    echo '<p style="color:#888">No active projects found for this user.</p>';
}
} catch (Exception $e) {
    echo '<p style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>


</body>

</html>
