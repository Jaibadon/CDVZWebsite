<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();
$client_id = (string)($_GET['client_id'] ?? '');

if ($client_id === '') {
    echo "<p>Missing client_id. <a href=\"clients.php\">Back to client list</a></p>";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM Clients WHERE Client_id = ?");
$stmt->execute([$client_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "<p>Client not found. <a href=\"clients.php\">Back to client list</a></p>";
    exit;
}

// Resolve column names regardless of casing returned by MySQL
$clientName = $row['Client_Name'] ?? $row['CLIENT_NAME'] ?? $row['Client_name'] ?? '';
$clientIdVal = $row['client_id'] ?? $row['Client_id'] ?? $row['Client_ID'] ?? '';
$email = $row['Email'] ?? $row['email'] ?? '';
?>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet" type="text/css" />
<title>Client Details </title>
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
                <p align="left">
              <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Client Information</font></b></td>
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
    echo "<a href='clients.php'><font color='#FFFFFF' size='2'>Client List</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='clients.php'><font color='#FFFFFF' size='2'>Client List</font></a>";
}
?>
              </td>
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
              <td align="center">&nbsp;
              <?php
if ($_SESSION['UserID'] == "erik") {
    echo "<a href='clients_projects.php?client_id=" . htmlspecialchars($client_id) . "'><font color='#FFFFFF' size='2'>This Clients Jobs</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='clients_projects.php?client_id=" . htmlspecialchars($client_id) . "'><font color='#FFFFFF' size='2'>This Clients Jobs</font></a>";
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
<form method="POST" name="project" action="update_client.php">
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="651">
    <tr>
      <td width="112"><font color=#9B9B1B size="2"><b>Client_Name:</b></font></td>
      <td width="523"><input type="text" name="Client_Name" size="35" value="<?= htmlspecialchars($clientName) ?>">&nbsp;  <font color=#9B9B1B size="2"><b>Active:</b></font>
<?php
if (!empty($row['Active']) && $row['Active'] != 0) {
    echo "<input type='checkbox' name='ACTIVE' value='ON' checked>";
} else {
    echo "<input type='checkbox' name='ACTIVE' value='ON'>";
}
echo "<input type='text' hidden name='client_id' value='" . htmlspecialchars($clientIdVal) . "'>";
?>
</td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Address1 (Postal):</b></font></td>
      <td><textarea name="Address1" rows="4" cols="35"><?= htmlspecialchars($row['Address1'] ?? '') ?></textarea></td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Address2 (Physical):</b></font></td>
      <td><textarea name="Address2" rows="4" cols="35"><?= htmlspecialchars($row['Address2'] ?? '') ?>
    </textarea></td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Phone:</b></font></td>
      <td><input type="text" name="Phone" size="35" value="<?= htmlspecialchars($row['Phone'] ?? '') ?>"></td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Mobile:</b></font></td>
      <td><input type="text" name="Mobile" size="35" value="<?= htmlspecialchars($row['Mobile'] ?? '') ?>"></td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Email:</b></font></td>
      <td><input type="text" name="email" size="35" value="<?= htmlspecialchars($email) ?>"></td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Billing Email:</b></font></td>
      <td><input type="text" name="Billing_Email" size="35" value="<?= htmlspecialchars($row['Billing_Email'] ?? '') ?>"></td>
    </tr>
    <tr>
      <td>&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Multiplier:</b></font></td>
      <td><input type="text" name="Multiplier" size="35" value="<?= htmlspecialchars((string)($row['Multiplier'] ?? '')) ?>"></td>
    </tr>
    <tr>
      <td><font color=#9B9B1B size="2"><b>Notes:</b></font></td>
      <td><textarea name="Notes" rows="5" cols="70"><?= htmlspecialchars($row['Notes'] ?? '') ?></textarea></td>
    </tr>
    <tr>
      <td>&nbsp;</td>
      <td><span class="style11">
        <input type="submit" value="Submit" name="B1">
        <input type="reset" value="Reset" name="B2">
      <font color=#9B9B1B size="2">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</font></span></td>
    </tr>
  </table>
  <p>&nbsp;</p>
  <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse" bordercolor="#111111" width="651" id="table5">
    <tr>
      <td width="1181" class="style1"><font color=#9B9B1B size="2">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</font>
		</td>
    </tr>
  </table>
</form>
</body>

</html>
