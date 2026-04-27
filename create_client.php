<?php
session_start();
require_once 'db_connect.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();
$curdate = date('d/m/Y');

$Client_Name = !empty($_POST['Client_Name']) ? $_POST['Client_Name'] : "_un-named_" . $_SESSION['UserID'];
$Address1    = !empty($_POST['Address1'])    ? $_POST['Address1']    : "No Address - " . $_SESSION['UserID'];
$Phone       = !empty($_POST['Phone'])       ? $_POST['Phone']       : "No Phone - " . $_SESSION['UserID'];
$Mobile      = !empty($_POST['Mobile'])      ? $_POST['Mobile']      : "No Mobile - " . $_SESSION['UserID'];
$emailIn     = $_POST['email'] ?? $_POST['Email'] ?? '';
$Email       = $emailIn !== '' ? $emailIn : "No Email - " . $_SESSION['UserID'];
$Billing_Email = !empty($_POST['Billing_Email']) ? $_POST['Billing_Email'] : "No Billing_Email - " . $_SESSION['UserID'];
$Multiplier  = !empty($_POST['Multiplier'])  ? $_POST['Multiplier']  : 1;
$notes       = !empty($_POST['Notes'])       ? $_POST['Notes'] . " - " . $_SESSION['UserID'] . " - " . $curdate : "Client Entered - " . $_SESSION['UserID'] . " - " . $curdate;
$activeIn    = $_POST['ACTIVE'] ?? $_POST['Active'] ?? '';
$Active      = (strtoupper($activeIn) === 'ON') ? 1 : 0;

// Compute next Client_id (in case the column isn't auto_increment)
$nextStmt = $pdo->query("SELECT COALESCE(MAX(Client_id), 0) + 1 AS nxt FROM Clients");
$nextId   = (int)$nextStmt->fetch(PDO::FETCH_ASSOC)['nxt'];

$stmt = $pdo->prepare("INSERT INTO Clients (Client_id, Client_Name, Address1, Phone, Mobile, email, Billing_Email, Multiplier, Notes, Active)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$nextId, $Client_Name, $Address1, $Phone, $Mobile, $Email, $Billing_Email, $Multiplier, $notes, $Active]);

header('Location: project_new.php');
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
