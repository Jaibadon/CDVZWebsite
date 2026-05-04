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
?>
<html>

<head>
<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<title>Active Projects </title>
<link href="site.css" rel="stylesheet">
<link href="global2.css" rel="stylesheet" type="text/css" />
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
              <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; CLIENT ARCHIVE</font></b></td>
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
              <td align="center">

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
<p></p>

<?php
try {
$pdo = get_db();
$strSQL = "SELECT * FROM Clients ORDER BY Client_Name";
$stmt = $pdo->query($strSQL);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = $row['Client_Name'] ?? $row['CLIENT_NAME'] ?? $row['Client_name'] ?? '';
    $cid  = $row['client_id'] ?? $row['Client_id'] ?? $row['Client_ID'] ?? '';
    echo "<br>";
    echo "<a href=\"client_updateform.php?client_id=" . htmlspecialchars($cid) . "\">";
    echo htmlspecialchars($name);
    echo "</a>";
    echo "&nbsp;&nbsp;";
    if (!empty($row['Active']) && $row['Active'] != 0) {
        echo "<font size=3 color=Red>*Active*</font>";
    } else {
        echo "<font size=3 color=Silver>Archived</font>";
    }
    echo "<br>";
}
} catch (Exception $e) {
    echo '<p style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>


</body>

</html>
