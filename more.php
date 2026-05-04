<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403);
    echo '<p>Admin only. <a href="menu.php">Back to menu</a></p>';
    exit;
}

$pdo = get_db();
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet" type="text/css" />
<title>More functions for <?= htmlspecialchars($_SESSION['UserID']) ?></title>
<style type="text/css">
.style11 { text-align: left; }
.style1  { text-align: left; }
.style2  { text-align: right; }
.style3  { background-color: #9B9B1B; }
</style>
<basefont face="arial">
</head>
<body bgcolor="#515559" text="black">

<table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table6">
  <tr>
    <td>
      <table border="0" cellspacing="0" width="644" cellpadding="0" id="table7" class="style3">
        <tr>
          <td align="center" colspan="4" height="26">
            <p align="left"><b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; MORE FUNCTIONS</font></b></td>
          <td align="center" colspan="3" height="26">
            <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
        </tr>
        <tr>
          <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
          <td align="center"><a href="main.php" onMouseOver="window.status='Go to the input table'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">My Timesheet</font></a></td>
          <td align="center"><a onMouseOver="window.status='Click here to re-login'; return true" onMouseOut="window.status=''; return true" href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
          <td align="center" colspan="2"><a href="report.php" onMouseOver="window.status='Run some basic reports'; return true" onMouseOut="window.status=''; return true"><font color="#FFFFFF">Reports</font></a></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
        <tr>
          <td align="center"><a onMouseOver="window.status='Some Admin stuff'; return true" onMouseOut="window.status=''; return true" href="menu.php"><font color="#FFFFFF">Main Menu</font></a></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center" colspan="2">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<p>&nbsp;</p>

<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
  <tr><td align="Left"><strong>CLIENT FUNCTIONS</strong></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='View list of Clients'; return true" onMouseOut="window.status=''; return true" href="clients.php"><font color="#FFFFFF">Client List</font></a></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="clients_archive.php"><font color="#FFFFFF">Archived Clients</font></a></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="new_client.php"><font color="#FFFFFF">New Client</font></a></td></tr>
  <tr><td align="Left"><a href="payments.php"><font color="#FFFFFF">Client Payments</font></a></td></tr>
  <tr>
    <form method="POST" name="clients_invoicing" action="invoices_for_client.php">
      <td align="Left"><font color="#FFFFFF">View Invoicing for:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
          <?php print_dd_box($pdo, "Clients", "client_id", "Client_name", "", "Client_ID", "Active<>0"); ?>
          &nbsp;&nbsp;<input type="submit" value="View" name="View">
      </font></td>
    </form>
  </tr>
  <tr>
    <form method="POST" name="statement" action="statement.php">
      <td align="Left"><font color="#FFFFFF">Generate Statement for:
          <?php print_dd_box($pdo, "Clients", "client_id", "Client_name", "", "Client_box", "client_ID IN (SELECT CLIENT_ID FROM Invoices WHERE PAID=0)"); ?>
          &nbsp;&nbsp;<input type="submit" value="Generate" name="Generate">
      </font></td>
    </form>
  </tr>
</table>

<p>&nbsp;</p>
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
  <tr><td align="Left"><strong>PROJECT FUNCTIONS</strong></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="projects.php"><font color="#FFFFFF">Project List</font></a></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="projects_fast.php"><font color="#FFFFFF">Project List (Fast)</font></a></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="projects_archive1.php"><font color="#FFFFFF">Archived Projects</font></a></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View '; return true" onMouseOut="window.status=''; return true" href="project_new.php"><font color="#FFFFFF">New Project</font></a></td></tr>
  <tr>
    <td align="Left"><a onMouseOver="window.status='View '; return true" onMouseOut="window.status=''; return true" href="SUBCAT_TYPES.php?Spec_Cat_ID=1"><font color="#FFFFFF">Spec Subcats (Project Element Types)</font></a></td>
  </tr>
  <tr>
    <td align="Left"><a onMouseOver="window.status='View '; return true" onMouseOut="window.status=''; return true" href="TASK_TYPES.php?stage_id=1"><font color="#FFFFFF">Task Types</font></a></td>
  </tr>
  <tr>
    <form method="POST" name="job_invoicing" action="invoices_for_job.php">
      <td align="Left"><font color="#FFFFFF">View Invoicing for (JOB):&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
          <?php print_dd_box($pdo, "Projects", "proj_id", "JobName", "", "proj_id", "Active<>0"); ?>
          &nbsp;&nbsp;<input type="submit" value="View_by_Job" name="View_by_Job">
      </font></td>
    </form>
  </tr>
</table>

<p>&nbsp;</p>
<form method="POST" name="invoicing" action="invoice_gen.php">
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
  <tr><td align="Left"><strong>INVOICE FUNCTIONS</strong></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View list of Outstanding Invoices'; return true" onMouseOut="window.status=''; return true" href="invoice_list.php"><font color="#FFFFFF">Current Invoice List</font></a></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='View list of Old Invoices'; return true" onMouseOut="window.status=''; return true" href="invoice_archive.php"><font color="#FFFFFF">Archived Invoices</font></a></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="monthly_invoicing.php"><font color="#FFFFFF">Monthly Invoicing (12mths)</font></a></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='Edit the wording of every outbound email'; return true" onMouseOut="window.status=''; return true" href="email_templates.php"><font color="#FFFFFF">Email Templates (edit wording + per-tone tests)</font></a></td></tr>
  <tr>
    <td width="652" align="Left"><font color="#FFFFFF">Generate Invoice for:
        <?php print_dd_box($pdo, "Projects", "proj_id", "JOBNAME", "", "Proj_box", "proj_ID IN (SELECT proj_ID FROM Timesheets WHERE Invoice_No=0)"); ?>
        <span class="style11">&nbsp;&nbsp;<input type="submit" value="Generate" name="Generate"></span>
    </font></td>
  </tr>
  <tr><td align="right" width="652"><div align="left"></div></td></tr>
</table>
</form>

<p>&nbsp;</p>
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
  <tr><td align="Left"><strong>QUOTING / PROJECT STAGES</strong></td></tr>
  <tr><td align="Left"><a href="templates.php"><font color="#FFFFFF">Manage Project Templates</font></a></td></tr>
  <tr>
    <form method="GET" name="proj_stages_form" action="project_stages.php">
      <td align="Left"><font color="#FFFFFF">Edit Stages / Tasks for project:&nbsp;
          <?php print_dd_box($pdo, "Projects", "proj_id", "JobName", "", "proj_id", "Active<>0"); ?>
          &nbsp;&nbsp;<input type="submit" value="Edit" name="Edit_Stages">
      </font></td>
    </form>
  </tr>
  <tr>
    <form method="GET" name="quote_form" action="quote.php" target="_blank">
      <td align="Left"><font color="#FFFFFF">Print Quote for project:&nbsp;
          <?php print_dd_box($pdo, "Projects", "proj_id", "JobName", "", "proj_id", "Active<>0"); ?>
          &nbsp;&nbsp;<input type="submit" value="Print Quote" name="Print_Quote">
      </font></td>
    </form>
  </tr>
  <tr>
    <form method="GET" name="checklist_form" action="checklist.php" target="_blank">
      <td align="Left"><font color="#FFFFFF">Print Checklist for project:&nbsp;
          <?php print_dd_box($pdo, "Projects", "proj_id", "JobName", "", "proj_id", "Active<>0"); ?>
          &nbsp;&nbsp;<input type="submit" value="Print Checklist" name="Print_Checklist">
      </font></td>
    </form>
  </tr>
</table>

<p>&nbsp;</p>
<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="652" id="table4">
  <tr><td align="Left"><strong>TIMESHEET FUNCTIONS</strong></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="timesheet_admin1.php"><font color="#FFFFFF">Timesheet Reporting</font></a></td></tr>
  <tr><td align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="staff_hours.php"><font color="#FFFFFF">Staff Hours - Unprocessed</font></a></td></tr>
  <tr><td width="652" align="Left"><a onMouseOver="window.status='View'; return true" onMouseOut="window.status=''; return true" href="unprocessed.php"><font color="#FFFFFF">Uprocessed Timesheet Entries</font></a></td></tr>
  <tr><td align="right" width="652"><div align="left"></div></td></tr>
</table>

<p>&nbsp;</p><p>&nbsp;</p><p>&nbsp;</p><p>&nbsp;</p>

<font color="#9B9B1B" size="2">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Copyright &copy; 2012 CADViz Ltd All Rights Reserved</font>
</body>
</html>

<?php
function print_dd_box($pdo, $table_name, $index_name, $display_name, $default_value, $obj_name, $whr = null) {
    if (is_null($whr) || $whr === '') {
        $sql = "SELECT DISTINCT " . $index_name . ", " . $display_name . " FROM " . $table_name . " ORDER BY " . $display_name;
    } else {
        $sql = "SELECT DISTINCT " . $index_name . ", " . $display_name . " FROM " . $table_name . " WHERE " . $whr . " ORDER BY " . $display_name;
    }
    $stmt = $pdo->query($sql);
    echo '<SELECT name="' . htmlspecialchars($obj_name) . '">';
    echo '<OPTION VALUE=""> ';
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row[$index_name] == $default_value) {
            echo '<OPTION SELECTED VALUE="' . htmlspecialchars($row[$index_name]) . '">' . htmlspecialchars($row[$display_name]);
        } else {
            echo '<OPTION VALUE="' . htmlspecialchars($row[$index_name]) . '">' . htmlspecialchars($row[$display_name]);
        }
    }
    echo '</SELECT>';
}
?>
