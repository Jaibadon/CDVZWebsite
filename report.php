<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo = get_db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Timesheet Reports</title>
<link rel="stylesheet" href="global.css">
<style>
body { background:#515559; font-family:Arial,sans-serif; margin:0; padding:20px; }
.wrap { width:90%; max-width:600px; margin:30px auto; background:#EBEBEB; padding:20px; border-radius:4px; }
h1 { text-align:center; }
hr { border:none; border-top:1px solid #ccc; }
label { font-family:Arial; font-size:13px; }
input[type=text] { padding:4px; }
input[type=submit] { padding:6px 20px; background:#9B9B1B; color:#fff; border:none; cursor:pointer; border-radius:3px; }
</style>
</head>
<body>
<div class="wrap">
  <h1><font face="Arial">Timesheet <b>Reports</b></font></h1>
  <hr>
  <p align="center"><font face="Arial">Welcome <?= htmlspecialchars($_SESSION['UserID']) ?>. Enter a date range to generate a timesheet summary.</font></p>
  <form action="report_gen.php" method="post" name="report_form">
    <table border="0" align="center">
      <tr>
        <td align="right"><label><font color="red"><b>* </b></font>Start Date:</label></td>
        <td><input type="text" size="20" name="StartDate" placeholder="dd/mm/yy"></td>
        <td><font face="Arial" size="2">e.g. "15/12/06"</font></td>
      </tr>
      <tr>
        <td align="right"><label><font color="red"><b>* </b></font>Finish Date:</label></td>
        <td><input type="text" size="20" name="EndDate" placeholder="dd/mm/yy"></td>
        <td><font face="Arial" size="2">e.g. "15/01/07"</font></td>
      </tr>
      <tr>
        <td align="right"><label>Project Code:</label></td>
        <td>
          <select name="Project" size="1">
            <option value=""></option>
            <?php
            $stmt = $pdo->query("SELECT proj_id, JobName FROM Projects ORDER BY JobName");
            while ($row = $stmt->fetch()):
            ?>
              <option value="<?= htmlspecialchars($row['proj_id']) ?>">
                <?= htmlspecialchars($row['JobName']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </td>
        <td>&nbsp;</td>
      </tr>
      <tr>
        <td colspan="3" align="center" style="padding-top:12px">
          <font color="red"><b>*</b></font> = Mandatory &nbsp;
          <input type="submit" name="GoButton" value="   GO   ">
        </td>
      </tr>
    </table>
  </form>
  <p align="left"><a href="main.php">← back to timesheet</a></p>
</div>
</body>
</html>
