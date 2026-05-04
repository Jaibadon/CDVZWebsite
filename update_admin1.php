<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$pdo = get_db();

$proj_id = (int) $_POST['proj_id'];
$curdate = date('Y-m-d');

// Fetch existing record to compare notes/status fields
$stmt = $pdo->prepare("SELECT Status, Contact_Notes, Job_Notes FROM Projects WHERE proj_id = ?");
$stmt->execute([$proj_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$stat   = $row['Status']        ?? '';
$cnotes = $row['Contact_Notes'] ?? '';
$jnotes = $row['Job_Notes']     ?? '';

// Append user/date stamp only when value has changed
// (form posts 'Job_NOTES'; older code read 'JOB_NOTES')
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

// Date fields — null if empty
$contact_date_last = !empty($_POST['Contact_Date_Last']) ? to_mysql_date($_POST['Contact_Date_Last']) : null;
$contact_date_next = !empty($_POST['Contact_Date_Next']) ? to_mysql_date($_POST['Contact_Date_Next']) : null;
$draft_date        = !empty($_POST['DRAFT_DATE'])        ? to_mysql_date($_POST['DRAFT_DATE'])        : null;
$final_date        = !empty($_POST['FINAL_DATE'])        ? to_mysql_date($_POST['FINAL_DATE'])        : null;

// Numeric fields — keep existing when submitted as empty
$est_hours      = (isset($_POST['Est_Hours'])      && $_POST['Est_Hours']      !== '') ? (int) $_POST['Est_Hours']      : null;
$manager_hours  = (isset($_POST['Manager_Hours'])  && $_POST['Manager_Hours']  !== '') ? (int) $_POST['Manager_Hours']  : null;
$dp1_hours      = (isset($_POST['DP1_Hours'])      && $_POST['DP1_Hours']      !== '') ? (int) $_POST['DP1_Hours']      : null;
$dp2_hours      = (isset($_POST['DP2_Hours'])      && $_POST['DP2_Hours']      !== '') ? (int) $_POST['DP2_Hours']      : null;
$dp3_hours      = (isset($_POST['DP3_Hours'])      && $_POST['DP3_Hours']      !== '') ? (int) $_POST['DP3_Hours']      : null;

$dp1 = (isset($_POST['DP1']) && $_POST['DP1'] !== '') ? (int) $_POST['DP1'] : null;
$dp2 = (isset($_POST['DP2']) && $_POST['DP2'] !== '') ? (int) $_POST['DP2'] : null;
$dp3 = (isset($_POST['DP3']) && $_POST['DP3'] !== '') ? (int) $_POST['DP3'] : null;

// Form posts 'ACTIVE' (uppercase from updateform_admin1.php checkbox)
$activeIn = $_POST['ACTIVE'] ?? $_POST['Active'] ?? '';
$active   = (strtoupper($activeIn) === 'ON') ? 1 : 0;

$projectType = (isset($_POST['Project_Type']) && $_POST['Project_Type'] !== '')
    ? (int)$_POST['Project_Type'] : null;

$sql = "UPDATE Projects SET
    Job_Notes          = ?,
    Status             = ?,
    Contact_Notes      = ?,
    Contact_Date_Last  = ?,
    Contact_Date_Next  = ?,
    DRAFT_DATE         = ?,
    FINAL_DATE         = ?,
    JOBNAME            = ?,
    Job_Description    = ?,
    Initial_Priority   = ?,
    Order_No           = ?,
    Client_ID          = ?,
    Manager            = ?,
    DP1                = ?,
    DP2                = ?,
    DP3                = ?,
    Est_Hours          = COALESCE(?, Est_Hours),
    Manager_Hours      = COALESCE(?, Manager_Hours),
    DP1_Hours          = COALESCE(?, DP1_Hours),
    DP2_Hours          = COALESCE(?, DP2_Hours),
    DP3_Hours          = COALESCE(?, DP3_Hours),
    Active             = ?,
    Project_Type       = ?
WHERE proj_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $new_job_notes,
    $new_status,
    $new_cnotes,
    $contact_date_last,
    $contact_date_next,
    $draft_date,
    $final_date,
    $_POST['JOBNAME']           ?? $_POST['JobName'] ?? '',
    $_POST['Job_Description']   ?? '',
    $_POST['initial_priority']  ?? '',
    $_POST['Order_No']          ?? $_POST['Order_no'] ?? '',
    (int) ($_POST['Client_box'] ?? 0),
    (int) ($_POST['Manager']    ?? 0),
    $dp1,
    $dp2,
    $dp3,
    $est_hours,
    $manager_hours,
    $dp1_hours,
    $dp2_hours,
    $dp3_hours,
    $active,
    $projectType,
    $proj_id,
]);

header('Location: projects.php');
exit;
