<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();

function populateProjectBox($pdo) {
    $sql = "SELECT proj_id, JobName FROM Projects ORDER BY JobName";
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<option value=\"" . htmlspecialchars($row['proj_id']) . "\">" . htmlspecialchars($row['JobName']) . "</option>";
    }
}

function populateUserBox($pdo) {
    $sql = "SELECT Employee_id, Login FROM Staff ORDER BY Login";
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<option value=\"" . htmlspecialchars($row['Employee_id']) . "\">" . htmlspecialchars($row['Login']) . "</option>";
    }
}
?>
<script language="Javascript">
	document.onkeydown = function() {
		if (event.keyCode == 13) {
			window.location="more.php"
		}
	}
</script>

<html>

<head>
<meta http-equiv="Window-target" content="_top">
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
<title>Timesheet Reporting</title>
<link rel="stylesheet" href="global.css" type="text/css">
</head>
<body bgcolor="#515559">
<table width="100%" border="0" cellspacing="0" cellpadding="0" height="100%">
  <tr>
    <td align="center" valign="middle">
      <table width="90%" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB">
        <tr>
          <td>&nbsp;</td>
        </tr>
        <tr>
          <td>
            <h1 align="center"><font face="Arial">Timesheet<b> Reports</b>
			</font> <br>
            </h1>
            <hr noshade size="1">
            <p align="center"><font face="Arial">Welcome to Admin Timesheet Reporting <?= htmlspecialchars($_SESSION['UserID']) ?>.
			</font> </p>
            <form action="timesheet_admin_report_gen.php" method="POST" name="report_form" target="_parent">
              <p>&nbsp;</p>
              <div align="center">
                <center>
                  <table border="0">
                    <tr>
                      <td align="right">
                        <p><font face="Arial"><font color="#FF0000"><b>* </b></font>Start Date :</font></p>
                      </td>
                      <td>
                        <p>
                          <font face="Arial">
                          <input type="text" size="20" name="StartDate">
                        </font>
                        </p>
                      </td>
                      <td>
                        <p><font face="Arial">eg. &quot;15/12/06&quot;</font></p>
                      </td>
                    </tr>
                    <tr>
                      <td align="right">
                        <p><font face="Arial"><font color="#FF0000"><b>* </b></font>Finish Date :</font></p>
                      </td>
                      <td>
                        <p>
                          <font face="Arial">
                          <input type="text" size="20" name="EndDate">
                        </font>
                        </p>
                      </td>
                      <td>
                        <p><font face="Arial">eg. &quot;15/01/07&quot;</font></p>
                      </td>
                    </tr>
                    <tr>
                      <td align="right">
                        <p><font face="Arial">Project Name :</font></p>
                      </td>
                      <td>
                        <p>
                          <font face="Arial">
                          <select name="Project" size="1">
                            <option value=""></option>
                            <?php populateProjectBox($pdo); ?>
                          </select>
                        </font>
                        </p>
                      </td>
                      <td>
                        <p>&nbsp;</p>
                      </td>
                    </tr>
                    <tr>
                      <td align="right">
                        <p><font face="Arial">Staff Name :</font></p>
                      </td>
                      <td>
                        <p>
                          <font face="Arial">
                          <select name="staff" size="1">
                            <option value=""></option>
                            <?php populateUserBox($pdo); ?>
                          </select>
                        </font>
                        </p>
                      </td>
                      <td>
                        <p>&nbsp;</p>
                      </td>
                    </tr>
                    </table>
                  <p><font face="Arial"><font color="#FF0000"><b>*</b></font> = Mandatory field
                    <input type="submit" name="GoButton" value="   GO   ">
                    <br>
                    </font> </p>
                </center>
              </div>
              </form>
          </td>
        </tr>
      </table>
    	<p align="left"><font face="Arial">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
		<a href="main.php">back to timesheet</a></font></td>
  </tr>
</table>
</body>
</html>
