<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

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
$Contact     = $_POST['Contact'] ?? '';
$Multiplier  = !empty($_POST['Multiplier'])  ? $_POST['Multiplier']  : 1;
$notes       = !empty($_POST['Notes'])       ? $_POST['Notes'] . " - " . $_SESSION['UserID'] . " - " . $curdate : "Client Entered - " . $_SESSION['UserID'] . " - " . $curdate;
$activeIn    = $_POST['ACTIVE'] ?? $_POST['Active'] ?? '';
$Active      = (strtoupper($activeIn) === 'ON') ? 1 : 0;

// Compute next Client_id with retry-on-duplicate (same defensive pattern
// as submit.php / invoice_gen.php — MAX+1 races under concurrent submits
// and could silently overwrite an existing Client_id).
try { $pdo->exec("DELETE FROM Clients WHERE Client_id = 0"); } catch (Exception $e) { /* non-fatal */ }
$nextStmt = $pdo->query("SELECT COALESCE(MAX(Client_id), 0) + 1 AS nxt FROM Clients");
$nextId   = (int)$nextStmt->fetch(PDO::FETCH_ASSOC)['nxt'];
if ($nextId < 1) $nextId = 1;

if (clients_has_contact($pdo)) {
    $stmt = $pdo->prepare("INSERT INTO Clients (Client_id, Client_Name, Address1, Phone, Mobile, Contact, email, Billing_Email, Multiplier, Notes, Active)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $args = fn($id) => [$id, $Client_Name, $Address1, $Phone, $Mobile, $Contact, $Email, $Billing_Email, $Multiplier, $notes, $Active];
} else {
    $stmt = $pdo->prepare("INSERT INTO Clients (Client_id, Client_Name, Address1, Phone, Mobile, email, Billing_Email, Multiplier, Notes, Active)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $args = fn($id) => [$id, $Client_Name, $Address1, $Phone, $Mobile, $Email, $Billing_Email, $Multiplier, $notes, $Active];
}
$attempts = 0;
while (true) {
    try { $stmt->execute($args($nextId)); break; }
    catch (PDOException $e) {
        $isDupe = ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false);
        if (!$isDupe || ++$attempts >= 5) throw $e;
        $r = $pdo->query("SELECT COALESCE(MAX(Client_id),0)+1 AS nxt FROM Clients");
        $nextId = max((int)$r->fetch(PDO::FETCH_ASSOC)['nxt'], $nextId + 1, 1);
    }
}

header('Location: project_new.php');
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
