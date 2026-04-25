<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo    = get_db();
$projId = (int)($_GET['proj_id'] ?? 0);

// Load project
$stmt = $pdo->prepare("SELECT * FROM Projects WHERE proj_id = ?");
$stmt->execute([$projId]);
$rs = $stmt->fetch();
if (!$rs) { echo '<p>Project not found.</p>'; exit; }

// Load hours used
$tsStmt = $pdo->prepare("SELECT SUM(HOURS) AS tot FROM Timesheets WHERE proj_id = ?");
$tsStmt->execute([$projId]);
$tsRow  = $tsStmt->fetch();
$totHrs = (float)($tsRow['tot'] ?? 0);

// Load estimated hours from project tasks
$estStmt = $pdo->prepare(
    "SELECT SUM(Estimated_Time * Project_Tasks.Weight) AS EST_HOURS
       FROM Tasks_Types
       RIGHT JOIN (
           Project_Tasks
           RIGHT JOIN (
               Project_Stages
               RIGHT JOIN Projects ON Project_Stages.Proj_ID = Projects.proj_id
           ) ON Project_Tasks.Project_Stage_ID = Project_Stages.Project_Stage_ID
       ) ON Tasks_Types.Task_ID = Project_Tasks.Task_Type_ID
      WHERE Projects.proj_id = ?"
);
$estStmt->execute([$projId]);
$estRow  = $estStmt->fetch();
$estHrs  = (float)($estRow['EST_HOURS'] ?? 0);

$isAdmin = in_array($_SESSION['UserID'], ['erik', 'jen'], true);

// ── Helper: render a dropdown from any table ──────────────────────────────
function print_dd_box(PDO $pdo, string $table, string $idCol, string $nameCol, $selected, string $name): void {
    // Try with active column first; fall back if the table doesn't have it (e.g. Staff)
    try {
        $stmt = $pdo->query("SELECT `$idCol`, `$nameCol`, active FROM `$table` ORDER BY `$nameCol`");
        $hasActive = true;
    } catch (Exception $e) {
        $stmt = $pdo->query("SELECT `$idCol`, `$nameCol` FROM `$table` ORDER BY `$nameCol`");
        $hasActive = false;
    }
    echo '<select name="' . htmlspecialchars($name) . '">';
    echo '<option value=""></option>';
    while ($row = $stmt->fetch()) {
        $active = $hasActive ? ($row['active'] ?? 1) : 1;
        if ($active == 0 && $row[$idCol] != $selected) continue;
        $sel = ($row[$idCol] == $selected) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string)$row[$idCol]) . '"' . $sel . '>'
           . htmlspecialchars((string)$row[$nameCol]) . '</option>';
    }
    echo '</select>';
}

$priority = $rs['Initial_Priority'] ?? 'Normal';
$priorities = ['Normal - On Hold', 'Normal', 'High'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Project <?= htmlspecialchars($rs['proj_id']) ?> – <?= htmlspecialchars($_SESSION['UserID']) ?></title>
<link href="global.css" rel="stylesheet">
<style>
body { background:#515559; font-family:Arial,sans-serif; font-size:12px; margin:0; padding:10px; }
.hdr { background:#9B9B1B; color:#fff; }
.hdr a { color:#fff; font-size:12px; }
.nav-bar td { text-align:center; }
label-color { color:#9B9B1B; font-weight:bold; font-size:12px; }
table { border-collapse:collapse; }
input[type=text],select,textarea { font-size:12px; }
input[type=submit],input[type=reset] { padding:4px 12px; cursor:pointer; }
</style>
</head>
<body>

<!-- Nav -->
<table width="644" border="0" cellspacing="0" cellpadding="0" style="margin:0 auto">
<tr><td>
<table border="0" cellspacing="0" width="644" cellpadding="2" class="hdr">
  <tr>
    <td colspan="4" align="left"><b>&nbsp;&nbsp;&nbsp;Project Data – Job #<?= htmlspecialchars($rs['proj_id']) ?></b></td>
    <td colspan="3" align="center"><a href="logout.php">logout</a></td>
  </tr>
  <tr class="nav-bar">
    <td><a href="projects.php">My Projects</a></td>
    <td><a href="main.php">My Timesheet</a></td>
    <td><a href="login.php">Login Again</a></td>
    <td colspan="2"><a href="report.php">Reports</a></td>
    <td>&nbsp;</td><td>&nbsp;</td>
  </tr>
  <tr class="nav-bar">
    <td><a href="projects_archive1.php">Projects Archive</a></td>
    <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
    <td colspan="2"><?php if ($isAdmin): ?><a href="more.php">More&hellip;</a><?php endif; ?></td>
    <td>&nbsp;</td><td>&nbsp;</td>
  </tr>
</table>
</td></tr>
</table>

<!-- Form -->
<form method="post" name="project" action="update_admin1.php" style="width:660px;margin:6px auto">
  <table width="660" cellpadding="2" cellspacing="0">
    <tr>
      <td><font color="#9B9B1B" size="2"><b>CLIENT:</b></font> &nbsp;
        <?php print_dd_box($pdo, 'Clients', 'Client_id', 'Client_Name', $rs['Client_ID'], 'Client_box'); ?>
      </td>
    </tr>
    <tr>
      <td>
        <font color="#9B9B1B" size="2"><b>JOBNAME:</b></font>
        <input <?= $isAdmin ? '' : 'readonly' ?> type="text" name="JOBNAME" size="32"
               value="<?= htmlspecialchars($rs['JOBNAME'] ?? '') ?>">
        <input type="hidden" name="proj_id" value="<?= (int)$rs['proj_id'] ?>">
        &nbsp;<font color="#9B9B1B" size="2"><b>Draft:</b>
        <input type="text" name="DRAFT_DATE" size="9" value="<?= htmlspecialchars($rs['DRAFT_DATE'] ?? '') ?>">
        &nbsp;<b>Final:</b>
        <input type="text" name="FINAL_DATE" size="9" value="<?= htmlspecialchars($rs['FINAL_DATE'] ?? '') ?>">
        </font>
      </td>
    </tr>
    <tr>
      <td><font color="#9B9B1B" size="2"><b>Order No/Job Ref:</b></font>
        <input type="text" name="Order_no" size="33" value="<?= htmlspecialchars($rs['Order_no'] ?? '') ?>">
      </td>
    </tr>
  </table>

  <table border="0" cellpadding="2" cellspacing="0" width="660">
    <tr>
      <td width="216"><font color="#9B9B1B" size="2"><b>Job Description:</b></font></td>
      <td width="126">
        <font size="2" color="#9B9B1B"><b>Active:</b></font>
        <input type="checkbox" name="ACTIVE" value="ON" <?= $rs['Active'] ? 'checked' : '' ?>>
      </td>
      <td width="131"><b><font color="#9B9B1B" size="2">Init Priority:</font></b></td>
      <td width="180">
        <select name="initial_priority">
          <?php foreach ($priorities as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>" <?= ($priority === $p) ? 'selected' : '' ?>>
              <?= htmlspecialchars($p) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
    </tr>
    <tr>
      <td colspan="4">
        <textarea name="Job_Description" rows="4" cols="79"><?= htmlspecialchars($rs['Job_Description'] ?? '') ?></textarea>
      </td>
    </tr>
    <tr>
      <td align="center"><b><font color="#9B9B1B" size="2">Manager</font></b></td>
      <td align="center"><font color="#9B9B1B" size="2"><b>DP1</b></font></td>
      <td align="center"><font color="#9B9B1B" size="2"><b>DP2</b></font></td>
      <td align="center"><font color="#9B9B1B" size="2"><b>DP3</b></font></td>
    </tr>
    <tr>
      <td><?php print_dd_box($pdo, 'Staff', 'Employee_ID', 'Login', $rs['Manager'] ?? null, 'Manager'); ?></td>
      <td><?php print_dd_box($pdo, 'Staff', 'Employee_ID', 'Login', $rs['DP1']     ?? null, 'DP1');     ?></td>
      <td><?php print_dd_box($pdo, 'Staff', 'Employee_ID', 'Login', $rs['DP2']     ?? null, 'DP2');     ?></td>
      <td><?php print_dd_box($pdo, 'Staff', 'Employee_ID', 'Login', $rs['DP3']     ?? null, 'DP3');     ?></td>
    </tr>
    <tr>
      <td colspan="4">
        <?php if (($totHrs + 8) > $estHrs): ?>
          <font size="4" color="Red">*** </font>
        <?php else: ?>
          <font size="3" color="Silver">
        <?php endif; ?>
        <?= (float)$totHrs ?> hours used out of <?= (float)$estHrs ?> allowed
        </font>
      </td>
    </tr>
  </table>

  <table border="0" cellpadding="2" cellspacing="0" width="660">
    <tr><td><font color="#9B9B1B" size="2"><b>Status:</b></font></td></tr>
    <tr><td><textarea name="Status" cols="79" style="height:50px"><?= htmlspecialchars($rs['Status'] ?? '') ?></textarea></td></tr>
  </table>

  <table border="0" cellpadding="2" cellspacing="0" width="660" style="margin-top:6px">
    <tr>
      <td><a href="PROJECT_INFO.php?proj_id=<?= (int)$projId ?>">Project Info</a></td>
      <td>&nbsp;</td>
    </tr>
  </table>

  <table border="0" cellpadding="2" cellspacing="0" width="660">
    <tr><td><font color="#9B9B1B" size="2"><b>Contact Notes:</b></font></td></tr>
    <tr><td><textarea name="Contact_Notes" rows="5" cols="79"><?= htmlspecialchars($rs['Contact_Notes'] ?? '') ?></textarea></td></tr>
  </table>

  <table border="0" cellpadding="2" cellspacing="0" width="660">
    <tr><td><font color="#9B9B1B" size="2"><b>Job Notes:</b></font></td></tr>
    <tr><td><textarea name="Job_NOTES" cols="79" style="height:123px"><?= htmlspecialchars($rs['Job_NOTES'] ?? '') ?></textarea></td></tr>
    <tr>
      <td>
        <input type="submit" value="Submit" name="B1">
        <input type="reset"  value="Reset"  name="B2">
        <font color="#9B9B1B" size="2">&nbsp;&nbsp;Copyright © CADViz Ltd All Rights Reserved</font>
      </td>
    </tr>
  </table>
</form>

</body>
</html>
