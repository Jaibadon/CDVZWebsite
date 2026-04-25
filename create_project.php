<?php
session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (empty($_SESSION['UserID'])) {
    echo "<p>Your session has expired. Please <a href=\"login.php\">login</a> again</p>";
    exit;
}

$pdo = get_db();
$curdate = date('d/m/Y');  // for display in notes

$jname     = !empty($_POST['JOBNAME'])           ? $_POST['JOBNAME']           : "_un-named_" . $_SESSION['UserID'];
// Form posts Client_Name (the dropdown holds the client_id value) — fall back to client_name
$client_id = (int)($_POST['Client_Name'] ?? $_POST['client_name'] ?? 0);
$jnotesIn  = $_POST['Job_NOTES'] ?? $_POST['JOB_NOTES'] ?? '';
$jnotes    = $jnotesIn !== ''    ? $jnotesIn . " - " . $_SESSION['UserID'] . " - " . $curdate : "Job Entered - " . $_SESSION['UserID'] . " - " . $curdate;
$stat      = !empty($_POST['Status'])            ? $_POST['Status'] . " - " . $_SESSION['UserID'] . " - " . $curdate    : "Job Entered - " . $_SESSION['UserID'] . " - " . $curdate;
$cnotes    = !empty($_POST['Contact_Notes'])     ? $_POST['Contact_Notes'] . " - " . $_SESSION['UserID'] . " - " . $curdate : "No Contact Notes";
// Date columns: convert to MySQL Y-m-d
$clast     = to_mysql_date(!empty($_POST['Contact_Date_Last']) ? $_POST['Contact_Date_Last'] : 'today');
$cnext     = to_mysql_date(!empty($_POST['Contact_Date_Next']) ? $_POST['Contact_Date_Next'] : '+7 days');
$ddate     = to_mysql_date(!empty($_POST['DRAFT_DATE'])        ? $_POST['DRAFT_DATE']        : '+7 days');
$fdate     = to_mysql_date(!empty($_POST['FINAL_DATE'])        ? $_POST['FINAL_DATE']        : '+14 days');
$jd        = !empty($_POST['Job_Description'])   ? $_POST['Job_Description']   : "Job Entered No Description - " . $_SESSION['UserID'] . " - " . $curdate;
$order_no  = $_POST['Order_No'] ?? $_POST['Order_no'] ?? '';
if ($order_no === '') $order_no = '-';
$ip        = $_POST['initial_priority'] ?? '';
$Manager   = $_POST['Manager'] ?? '';
$DP1       = $_POST['DP1'] ?? '';
$DP2       = $_POST['DP2'] ?? '';
$DP3       = $_POST['DP3'] ?? '';
$activeIn  = $_POST['ACTIVE'] ?? $_POST['Active'] ?? '';
$ACT       = (strtoupper($activeIn) === 'ON') ? 1 : 0;

$stmt = $pdo->prepare("INSERT INTO Projects (JobName, Client_ID, JOB_NOTES, Status, Contact_Notes, Contact_Date_Last, Contact_Date_next, Draft_Date, Final_Date, Job_Description, Initial_priority, Order_No, Manager, DP1, DP2, DP3, Active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$jname, $client_id, $jnotes, $stat, $cnotes, $clast, $cnext, $ddate, $fdate, $jd, $ip, $order_no, $Manager, $DP1, $DP2, $DP3, $ACT]);

header('Location: main.php');
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
