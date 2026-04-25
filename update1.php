<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

$pdo = get_db();

$proj_id = (int) $_POST['proj_id'];
$curdate = date('Y-m-d');

// Fetch existing fields for comparison
$stmt = $pdo->prepare("SELECT Status, Contact_Notes, Job_Notes FROM Projects WHERE proj_id = ?");
$stmt->execute([$proj_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$stat   = $row['Status']        ?? '';
$cnotes = $row['Contact_Notes'] ?? '';
$jnotes = $row['Job_Notes']     ?? '';

// Append user/date stamp only when changed (form may post Job_NOTES or JOB_NOTES)
$new_job_notes = $_POST['Job_NOTES'] ?? $_POST['JOB_NOTES'] ?? '';
if ($new_job_notes !== $jnotes) {
    $new_job_notes = $new_job_notes . ' - ' . $_SESSION['UserID'] . ' - ' . $curdate;
}

$new_status = $_POST['Status'] ?? '';
if ($new_status !== $stat) {
    $new_status = $new_status . ' - ' . $_SESSION['UserID'] . ' - ' . $curdate;
}

$new_cnotes = $_POST['Contact_Notes'] ?? '';
if ($new_cnotes !== $cnotes) {
    $new_cnotes = $new_cnotes . ' - ' . $_SESSION['UserID'] . ' - ' . $curdate;
}

// Contact_Date_Last — keep as-is if empty
$contact_date_last = !empty($_POST['Contact_Date_Last']) ? to_mysql_date($_POST['Contact_Date_Last']) : null;

// Contact_Date_Next — default to +7 days if not supplied
$contact_date_next = !empty($_POST['Contact_Date_Next'])
    ? to_mysql_date($_POST['Contact_Date_Next'])
    : date('Y-m-d', strtotime('+7 days'));

$sql = "UPDATE Projects SET
    Job_Notes         = ?,
    Status            = ?,
    Contact_Notes     = ?,
    Contact_Date_Last = ?,
    Contact_Date_Next = ?,
    Job_Description   = ?
WHERE proj_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $new_job_notes,
    $new_status,
    $new_cnotes,
    $contact_date_last,
    $contact_date_next,
    $_POST['Job_Description'] ?? '',
    $proj_id,
]);

header('Location: projects.php');
exit;
