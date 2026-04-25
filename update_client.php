<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo = get_db();

$client_id = (int) $_POST['client_id'];
$curdate   = date('Y-m-d');

// Fetch existing Notes to compare
$stmt = $pdo->prepare("SELECT Notes FROM Clients WHERE client_id = ?");
$stmt->execute([$client_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$notes = $row['Notes'] ?? '';

// Append stamp only when value has changed (form posts "Notes")
$new_notes = $_POST['Notes'] ?? $_POST['NOTES'] ?? '';
if ($new_notes !== $notes) {
    $new_notes = $new_notes . ' - ' . $_SESSION['UserID'] . ' - ' . $curdate;
}

$multiplier   = (isset($_POST['Multiplier'])   && $_POST['Multiplier']   !== '') ? $_POST['Multiplier']   : null;
$client_name  = (isset($_POST['Client_Name'])  && $_POST['Client_Name']  !== '') ? $_POST['Client_Name']  : null;

// Form posts "ACTIVE" (uppercase from client_updateform.php)
$activeVal = $_POST['ACTIVE'] ?? $_POST['Active'] ?? '';
$active = (strtoupper($activeVal) === 'ON') ? 1 : 0;

$sql = "UPDATE Clients SET
    Notes          = ?,
    Address1       = ?,
    Address2       = ?,
    Phone          = ?,
    Mobile         = ?,
    email          = ?,
    Billing_Email  = ?,
    Multiplier     = COALESCE(?, Multiplier),
    Client_Name    = COALESCE(?, Client_Name),
    Active         = ?
WHERE client_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $new_notes,
    $_POST['Address1']      ?? '',
    $_POST['Address2']      ?? '',
    $_POST['Phone']         ?? '',
    $_POST['Mobile']        ?? '',
    $_POST['email']         ?? ($_POST['Email'] ?? ''),
    $_POST['Billing_Email'] ?? '',
    $multiplier,
    $client_name,
    $active,
    $client_id,
]);

header('Location: clients.php');
exit;
