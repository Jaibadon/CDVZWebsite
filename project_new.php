<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

// Helper function to print a dropdown box from a DB table.
// Tries with `active` column first; falls back if the table doesn't have it (e.g. Staff).
function print_dd_box($pdo, $table_name, $index_name, $display_name, $default_value, $obj_name) {
    try {
        $sql = "SELECT `$index_name`, `$display_name`, active FROM `$table_name` ORDER BY `$display_name`";
        $stmt = $pdo->query($sql);
        $hasActive = true;
    } catch (Exception $e) {
        $sql = "SELECT `$index_name`, `$display_name` FROM `$table_name` ORDER BY `$display_name`";
        $stmt = $pdo->query($sql);
        $hasActive = false;
    }
    echo '<SELECT name="' . htmlspecialchars($obj_name) . '">';
    echo '<OPTION VALUE=""> ';
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $active = $hasActive ? ($r['active'] ?? 1) : 1;
        $isSelected = ($r[$index_name] == $default_value);
        // Show row if it's active OR it's the currently selected default
        if ($active != 0 || $isSelected) {
            $sel = $isSelected ? ' SELECTED' : '';
            echo '<OPTION' . $sel . ' VALUE="' . htmlspecialchars((string)$r[$index_name]) . '">'
               . htmlspecialchars((string)$r[$display_name]);
        }
    }
    echo '</SELECT>';
}
?>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta http-equiv="Content-Language" content="en-nz">
<link href="global.css" rel="stylesheet" type="text/css" />
<title>New Project Created by <?= htmlspecialchars($_SESSION['UserID']) ?> </title>
<basefont face="arial">
<style type="text/css">
.style1 { text-align: left; }
.style2 { text-align: right; }
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
                <p align="left">
              <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; New Project</font></b></td>
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
              <?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='projects_archive1.php'><font color='#FFFFFF' size='2'>Projects Archive</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='projects_archive1.php'><font color='#FFFFFF' size='2'>Projects Archive</font></a>";
}
?></td>
              <td align="center">&nbsp;</td>
              <td align="center">&nbsp;
              <?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='new_client.php'><font color='#FFFFFF' size='2'>New client</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='new_client.php'><font color='#FFFFFF' size='2'>New client</font></a>";
}
?>
              </td>
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
<form method="POST" name="project" action="create_project.php">
  <table style="width: 653px" cellpadding="0" cellspacing="0">
	<tr>
	  <td><font color=#9B9B1B size="2"><b> CLIENT: </b>&nbsp;&nbsp;&nbsp;&nbsp; </font>

<SELECT name="Client_Name">
  <OPTION VALUE="">
  <?php
    // Pull clients including the active flag so we can filter to active ones
    $clientStmt = $pdo->query("SELECT Client_ID, Client_Name, Active FROM Clients ORDER BY Client_Name");
    while ($cr = $clientStmt->fetch(PDO::FETCH_ASSOC)) {
        $cActive = $cr['Active'] ?? $cr['active'] ?? 1;
        if ($cActive != 0) {
            echo '<OPTION VALUE="' . htmlspecialchars((string)$cr['Client_ID']) . '">'
               . htmlspecialchars((string)$cr['Client_Name']);
        }
    }
  ?>
                    </select></td>
    </tr>
	<tr>
		<td><font color=#9B9B1B size="2"><b>JOBNAME:</b> </font>
  <input type="text" name="JOBNAME" size="35" value="">&nbsp;&nbsp;&nbsp;&nbsp;<b>&nbsp;</b><font color=#9B9B1B size="2"><b>Draft:</b>
  <input type="text" name="DRAFT_DATE" size="12" value="">&nbsp;&nbsp;&nbsp;&nbsp;<b>
	Final:</b>
	<input type="text" name="FINAL_DATE" size="12" value="">&nbsp;</font></td>
	</tr>
	<tr>
	  <td class="style2"></td>
    </tr>
	<tr>
		<td class="style2">
<font color=#9B9B1B size="2"><b> Order No/Job Ref:</b> </font>
          <input type="text" name="Order_no" size="33">
          &nbsp;&nbsp;&nbsp;&nbsp;<font color=#9B9B1B size="2"><b>Project Type:</b></font>
          <select name="Project_Type">
            <option value="">-- select --</option>
            <?php
            $ptStmt = $pdo->query("SELECT Project_Type_ID, Project_Type_Name FROM Project_Types WHERE Active <> 0 ORDER BY Project_Type_Name");
            while ($pt = $ptStmt->fetch(PDO::FETCH_ASSOC)) {
                echo '<option value="' . (int)$pt['Project_Type_ID'] . '">'
                   . htmlspecialchars($pt['Project_Type_Name']) . '</option>';
            }
            ?>
          </select>
        </td>
	</tr>
	</table>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="653" id="AutoNumber2">
    <tr>
      <td align="center" width="216">
		<p align="left"><font color=#9B9B1B size="2"><b>Job Description:</b></font></td>
      <td align="center" width="126"><font size="2" color="#9B9B1B"><b>Active:</b></font>
<?php echo "<input type='checkbox' name='ACTIVE' value='ON' checked>"; ?>
</td>
      <td align="center" width="131"><b>
  <font color=#9B9B1B size="2">&nbsp; Init Priority:
  </font></b></td>
      <td align="center" width="180">
  <p align="left">
  <font color=#9B9B1B face="Arial">
       <select size="1" name="initial_priority">
    <?php
        echo "<OPTION SELECTED VALUE='Normal'>Normal";
        echo "<OPTION VALUE='High'>High";
        echo "<OPTION VALUE='Normal - On Hold'>Normal - On Hold";
    ?>
          </select></font></td>
    </tr>
    <tr>
      <td align="center" colspan="4">
		<Textarea name="Job_Description" rows="4" cols="79">"Enter Description Here"</Textarea></td>
    </tr>
    <tr>
      <td align="center" height="26" width="216"><b><font color=#9B9B1B size="2">Manager</font></b></td>
      <td align="center" height="26" width="126"><font color=#9B9B1B size="2"><b>Team</b></font></td>
      <td align="center" height="26" width="131">&nbsp;</td>
      <td align="center" height="26" width="180">&nbsp;</td>
    </tr>
    <tr>
      <td width="216">
		<p align="center"><font color=#9B9B1B face="Arial">&nbsp;&nbsp;&nbsp;&nbsp;
        <?php print_dd_box($pdo, "Staff", "Employee_ID", "Login", 1, "Manager"); ?></font></td>
      <td width="126"><font color=#9B9B1B face="Arial">
       <?php print_dd_box($pdo, "Staff", "Employee_ID", "Login", 29, "DP1"); ?>
        </font></td>
      <td width="131"><font color=#9B9B1B face="Arial">
       <?php print_dd_box($pdo, "Staff", "Employee_ID", "Login", 29, "DP2"); ?>
        </font></td>
      <td width="180"><font color=#9B9B1B face="Arial">
       <?php print_dd_box($pdo, "Staff", "Employee_ID", "Login", 29, "DP3"); ?>
        </font></td>
    </tr>
    <tr>
      <td colspan="4" align="left">&nbsp;&nbsp;&nbsp;&nbsp;<font color=#9B9B1B size="2"></font>
 </td>
    </tr>
  </table>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="651" id="table1">
    <tr>
      <td align="left" width="121"><font color=#9B9B1B size="2"><b>Status:</b></font></td>
      <td width="530" align="left">
      </td>
    </tr>
    <tr>
      <td align="right" width="651" colspan="2">
		<Textarea name="Status" cols="79" style="height: 74px"></Textarea></td>
    </tr>
  </table>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="564" id="AutoNumber3" height="45">
    <tr>
      <td colspan="4"> </td>
    </tr>
    <tr>
      <td align="left" width="116"><font color=#9B9B1B size="2"><b>Last Contact Date:</b></font></td>
      <td width="162"><font color=#9B9B1B face="Arial">
      <input type="text" name="Contact_Date_Last" size="22">
      </td>
      <td width="127"><font color=#9B9B1B size="2"><b>Next Contact Date:</b></font></td>
      <td width="159">
      <input type="text" name="Contact_Date_Next" size="20"></td>
    </tr>
  </table>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
    <tr>
      <td align="left" width="121"><font color=#9B9B1B size="2"><b>Contact Notes:</b></font></td>
      <td width="493" align="left">&nbsp;</td>
    </tr>
    <tr>
      <td align="right" width="652" colspan="2">
		<Textarea name="Contact_Notes" rows="5" cols="79"></Textarea></td>
    </tr>
  </table>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="651" id="table5">
    <tr>
      <td align="left" width="121"><font color=#9B9B1B size="2"><b>Job Notes:</b></font></td>
      <td width="530" align="left">&nbsp;</td>
    </tr>
    <tr>
      <td align="right" width="651" colspan="2">
		<Textarea name="Job_NOTES" cols="79" style="height: 123px"></Textarea></td>
    </tr>
    <tr>
      <td width="651" colspan="2" class="style1">
		<input type="submit" value="Submit" name="B1"><input type="reset" value="Reset" name="B2">
		<font color=#9B9B1B size="2">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Copyright &copy; 2020 CADViz Ltd All Rights Reserved
</font>
		</td>
    </tr>
  </table>
</form>
</body>
</html>
