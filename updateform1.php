<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo = get_db();

$proj_id = (int) ($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) {
    echo "<p>Missing project id. <a href=\"projects.php\">Back to projects</a></p>";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM Projects WHERE proj_id = ?");
$stmt->execute([$proj_id]);
$rs = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rs) {
    echo "<p>Project not found. <a href=\"projects.php\">Back to projects</a></p>";
    exit;
}

// Resolve fields case-insensitively then format dates for display
function ci_get(array $row, array $candidates, $default = '') {
    foreach ($candidates as $c) {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $c) === 0) return $v;
        }
    }
    return $default;
}
function fmtDate($d) {
    if (empty($d)) return '';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : '';
}
$jobName    = (string)ci_get($rs, ['JobName', 'JOBNAME']);
$draftDate  = fmtDate(ci_get($rs, ['Draft_Date', 'DRAFT_DATE']));
$finalDate  = fmtDate(ci_get($rs, ['Final_Date', 'FINAL_DATE']));
$jobNotes   = (string)ci_get($rs, ['Job_Notes', 'Job_NOTES', 'JOB_NOTES']);

// Priority / overdue logic
$priority  = ci_get($rs, ['Initial_Priority']);
$final_date = ci_get($rs, ['Final_Date', 'FINAL_DATE']);
$od = 0;
if (!empty($final_date)) {
    $dd = (int) floor((time() - strtotime($final_date)) / 86400);
    if ($dd > 7)       $od = 3;
    elseif ($dd > 0)   $od = 2;
    elseif ($dd > -2)  $od = 1;
    else               $od = 0;
}

if (stripos($priority, 'Hold') !== false) {
    $priorityColor = 'Silver';
} elseif (stripos($priority, 'High') !== false) {
    $priorityColor = 'Red';
} else {
    $priorityColor = 'white';
}

if (stripos($priority, 'Hold') !== false) {
    $priorityLabel = 'Normal - On Hold';
} elseif (stripos($priority, 'Normal') !== false) {
    $labels = ['Normal', 'Normal - Almost Due', 'Normal - Overdue', 'Urgent - Overdue'];
    $priorityLabel = $labels[$od] ?? 'Normal';
} elseif (stripos($priority, 'High') !== false) {
    $labels = ['High', 'High - Almost Due', 'Critical - Overdue', 'Critical - Overdue'];
    $priorityLabel = $labels[$od] ?? 'High';
} else {
    $priorityLabel = htmlspecialchars($priority);
}

// Staff lookup helper
function staffName($id) {
    $map = [1 => 'erik', 2 => 'craig', 4 => 'dylan', 22 => 'leilani', 30 => 'phil'];
    $emails = [1 => 'erik@cadviz.co.nz', 2 => 'craig@cadviz.co.nz', 4 => 'dylan@cadviz.co.nz',
               22 => 'leilani@cadviz.co.nz', 30 => 'phil@cadviz.co.nz'];
    if (isset($map[$id])) {
        return "<a href='mailto:{$emails[$id]}'><font color='#FFFFFF' size='3'>" . ucfirst($map[$id]) . "</font></a>";
    }
    return '';
}

$userID = $_SESSION['UserID'];
$archiveUsers = ['erik', 'jen', 'Craig', 'Dylan', 'Leilani'];
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<title>Active Projects for <?php echo htmlspecialchars($userID); ?></title>
<link href="site.css" rel="stylesheet">
<basefont face="arial">
<style type="text/css">
.style1 { text-align: left; }
.style2 { border-collapse: collapse; }
.style3 { background-color: #9B9B1B; }
</style>
</head>
<body bgcolor="#515559" text="black">

<table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table6">
  <tr>
    <td>
      <table border="0" cellspacing="0" width="644" cellpadding="0" id="table7" class="style3">
        <tr>
          <td align="center" colspan="4" height="26">
            <p align="left"><b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Project Information</font></b></td>
          <td align="center" colspan="3" height="26">
            <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
        </tr>
        <tr>
          <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
          <td align="center"><a href="main.php"><font color="#FFFFFF">My Timesheet</font></a></td>
          <td align="center"><a href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
          <td align="center" colspan="2"><a href="report.php"><font color="#FFFFFF">Reports</font></a></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
        <tr>
          <td align="center">&nbsp;
            <?php if (in_array($userID, $archiveUsers)): ?>
              <a href='projects_archive1.php'><font color='#FFFFFF' size='2'>Projects Archive</font></a>
            <?php endif; ?>
          </td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center" colspan="2">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<form method="POST" name="project" action="update1.php">
  <p>
    <font color="#9B9B1B" size="2"><b>JOBNAME:</b> </font>
    <input type="text" name="JOBNAME" READONLY style="color: #996633" size="33" value="<?php echo htmlspecialchars($jobName); ?>">
    &nbsp;&nbsp;
    <input type="text" name="proj_id" READONLY style="color: #996633" size="2" value="<?php echo (int)$rs['proj_id']; ?>">
    &nbsp;&nbsp;&nbsp;<b>&nbsp;</b>
    <font color="#9B9B1B" size="2"><b>Draft:</b>
    <input type="text" name="DRAFT" READONLY style="color: #996633" size="12" value="<?php echo htmlspecialchars($draftDate); ?>">
    &nbsp;&nbsp;&nbsp;&nbsp;<b>Final:</b>
    <input type="text" name="FINAL_DATE" READONLY style="color: #996633" size="12" value="<?php echo htmlspecialchars($finalDate); ?>">
    &nbsp;</font>
  </p>

  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="653" id="AutoNumber2">
    <tr>
      <td align="center" width="246">
        <p align="left"><font color="#9B9B1B" size="2"><b>Job Description:</b></font></td>
      <td align="center" width="152">&nbsp;</td>
      <td align="center" width="120"><b><font color="#9B9B1B" size="2">&nbsp; Priority:</font></b></td>
      <td align="center" width="135">
        <font size="3" color="<?php echo $priorityColor; ?>"><?php echo $priorityLabel; ?></font>
      </td>
    </tr>
    <tr>
      <td align="center" colspan="4">
        <textarea name="Job_Description" rows="4" cols="79"><?php echo htmlspecialchars($rs['Job_Description'] ?? ''); ?></textarea>
      </td>
    </tr>
    <tr>
      <td align="center" width="246" style="height: 19px"><b><font color="#9B9B1B" size="2">Manager</font></b></td>
      <td align="center" width="152" style="height: 19px"><font color="#9B9B1B" size="2"><b>Team</b></font></td>
      <td align="center" width="120" style="height: 19px"></td>
      <td align="center" width="135" style="height: 19px"></td>
    </tr>
    <tr>
      <td align="center" width="246">
        <p align="center"><?php echo staffName($rs['Manager'] ?? 0); ?></p>
      </td>
      <td width="152"><p align="center"><?php echo staffName($rs['DP1'] ?? 0); ?></p></td>
      <td width="120"><?php echo staffName($rs['DP2'] ?? 0); ?></td>
      <td width="135"><?php echo staffName($rs['DP3'] ?? 0); ?></td>
    </tr>
  </table>

  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="651" id="table1">
    <tr>
      <td align="left" width="121"><font color="#9B9B1B" size="2"><b>Status:</b></font></td>
      <td width="530" align="left">&nbsp;</td>
    </tr>
    <tr>
      <td align="right" width="651" colspan="2">
        <textarea name="Status" cols="79" style="height: 78px"><?php echo htmlspecialchars($rs['Status'] ?? ''); ?></textarea>
      </td>
    </tr>
  </table>

  <table border="0" cellpadding="0" cellspacing="0" bordercolor="#111111" width="564" id="AutoNumber3" class="style2">
    <tr>
      <td align="left" width="116"><font color="#9B9B1B" size="2"><b>Last Contact Date:</b></font></td>
      <td width="162">
        <input type="text" name="Contact_Date_Last" size="22" value="<?php echo htmlspecialchars($rs['Contact_Date_Last'] ?? ''); ?>">
      </td>
      <td width="127"><font color="#9B9B1B" size="2"><b>Next Contact Date:</b></font></td>
      <td width="159">
        <input type="text" name="Contact_Date_Next" size="20" value="<?php echo htmlspecialchars($rs['Contact_Date_Next'] ?? ''); ?>">
      </td>
    </tr>
  </table>

  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
    <tr>
      <td align="left" width="121"><font color="#9B9B1B" size="2"><b>Contact Notes:</b></font></td>
      <td width="493" align="left">&nbsp;</td>
    </tr>
    <tr>
      <td align="right" width="652" colspan="2">
        <textarea name="Contact_Notes" rows="5" cols="79"><?php echo htmlspecialchars($rs['Contact_Notes'] ?? ''); ?></textarea>
      </td>
    </tr>
  </table>

  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="651" id="table5">
    <tr>
      <td align="left" width="121"><font color="#9B9B1B" size="2"><b>Job Notes:</b></font></td>
      <td width="530" align="left">&nbsp;</td>
    </tr>
    <tr>
      <td align="right" width="651" colspan="2">
        <textarea name="Job_NOTES" cols="79" style="height: 126px"><?php echo htmlspecialchars($jobNotes); ?></textarea>
      </td>
    </tr>
    <tr>
      <td width="651" colspan="2" class="style1">
        <input type="submit" value="Submit" name="B1">
        <input type="reset" value="Reset" name="B2">
        <font color="#9B9B1B" size="2">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Copyright &copy; 2007 CADViz Ltd All Rights Reserved</font>
      </td>
    </tr>
  </table>
</form>
</body>
</html>
