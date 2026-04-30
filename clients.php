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
                <b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Project Information</font></b></td>
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
    echo "<a href='clients_archive.php'><font color='#FFFFFF' size='2'>Client Archive</font></a>";
}
if ($_SESSION['UserID'] == "jen") {
    echo "<a href='clients_archive.php'><font color='#FFFFFF' size='2'>Client Archive</font></a>";
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
// Case-insensitive lookup helper for MySQL row arrays
function ci(array $row, string $key, $default = '') {
    foreach ($row as $k => $v) {
        if (strcasecmp($k, $key) === 0) return $v;
    }
    return $default;
}

try {
    $pdo = get_db();
    // Pull all clients, no WHERE filter — we'll filter in PHP using a flexible
    // active check to handle 0/1, '0'/'1', '', NULL, 'Y'/'N', true/false, etc.
    $stmt = $pdo->query("SELECT * FROM Clients ORDER BY Client_name");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) === 0) {
        echo '<p style="color:red">No rows found in <code>Clients</code> table.</p>';
    } else {
        $shown = 0;
        foreach ($rows as $row) {
            $active = ci($row, 'Active');
            // Treat blank, NULL, 0, '0', 'N', 'No', false as inactive — anything else is active
            $isActive = !($active === '' || $active === null || $active === 0 || $active === '0'
                       || strcasecmp((string)$active, 'N')  === 0
                       || strcasecmp((string)$active, 'No') === 0
                       || $active === false);
            if (!$isActive) continue;

            $name = ci($row, 'Client_name', ci($row, 'Client_Name', ''));
            $cid  = ci($row, 'Client_id',   ci($row, 'Client_ID',   ''));
            echo "<br><a href=\"client_updateform.php?client_id=" . htmlspecialchars((string)$cid) . "\">";
            echo htmlspecialchars((string)$name);
            echo "</a>";
            $shown++;
        }
        if ($shown === 0) {
            echo '<p style="color:#888">'
                . count($rows) . ' client(s) found in DB but none flagged active.'
                . ' (To see all clients including inactive, use <a href="clients_archive.php">Client Archive</a>.)'
                . '</p>';
        }
    }
} catch (Exception $e) {
    echo '<p style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>


</body>

</html>
