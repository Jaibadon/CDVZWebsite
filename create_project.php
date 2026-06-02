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
$jaddr     = trim((string)($_POST['Job_Address'] ?? ''));
$order_no  = $_POST['Order_No'] ?? $_POST['Order_no'] ?? '';
if ($order_no === '') $order_no = '-';
$ip        = $_POST['initial_priority'] ?? '';
$Manager   = $_POST['Manager'] ?? '';
$DP1       = $_POST['DP1'] ?? '';
$DP2       = $_POST['DP2'] ?? '';
$DP3       = $_POST['DP3'] ?? '';
$activeIn  = $_POST['ACTIVE'] ?? $_POST['Active'] ?? '';
$ACT       = (strtoupper($activeIn) === 'ON') ? 1 : 0;
$projType  = (isset($_POST['Project_Type']) && $_POST['Project_Type'] !== '') ? (int)$_POST['Project_Type'] : null;

// Compute next proj_id with the same defensive pattern (zombie cleanup,
// MAX+1 + clamp, retry on duplicate). Concurrent "Save Project" clicks
// would otherwise race the MAX+1 computation.
try { $pdo->exec("DELETE FROM Projects WHERE proj_id = 0"); } catch (Exception $e) { /* non-fatal */ }
$nextStmt = $pdo->query("SELECT COALESCE(MAX(proj_id), 0) + 1 AS nxt FROM Projects");
$nextId   = (int)$nextStmt->fetch(PDO::FETCH_ASSOC)['nxt'];
if ($nextId < 1) $nextId = 1;

// Convert Manager/DP* empty strings to NULL so they don't insert as 0
$Manager = ($Manager === '' ? null : (int)$Manager);
$DP1     = ($DP1     === '' ? null : (int)$DP1);
$DP2     = ($DP2     === '' ? null : (int)$DP2);
$DP3     = ($DP3     === '' ? null : (int)$DP3);

// Job_Address column may not exist on legacy installs that haven't run
// the migration. Detect once and fold into the INSERT only when present
// so the form keeps working on either schema.
$hasAddr = false;
try { $hasAddr = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'Job_Address'")->fetch(); } catch (Exception $e) {}

$cols = ['proj_id','JobName','Client_ID','Job_Notes','Status','Contact_Notes','Contact_Date_Last','Contact_Date_Next','Draft_Date','Final_Date','Job_Description','Initial_Priority','Order_No','Manager','DP1','DP2','DP3','Active','Project_Type'];
$vals = [$nextId, $jname, $client_id, $jnotes, $stat, $cnotes, $clast, $cnext, $ddate, $fdate, $jd, $ip, $order_no, $Manager, $DP1, $DP2, $DP3, $ACT, $projType];
if ($hasAddr) { $cols[] = 'Job_Address'; $vals[] = $jaddr; }
$ph = implode(',', array_fill(0, count($cols), '?'));
$stmt = $pdo->prepare("INSERT INTO Projects (`" . implode('`,`', $cols) . "`) VALUES ($ph)");
$attempts = 0;
while (true) {
    try {
        $vals[0] = $nextId;
        $stmt->execute($vals);
        break;
    } catch (PDOException $e) {
        $isDupe = ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false);
        if (!$isDupe || ++$attempts >= 5) throw $e;
        $r = $pdo->query("SELECT COALESCE(MAX(proj_id),0)+1 AS nxt FROM Projects");
        $nextId = max((int)$r->fetch(PDO::FETCH_ASSOC)['nxt'], $nextId + 1, 1);
    }
}

// ── Auto-provision the project's Drive folder (if enabled) ────────────────
// Cloning the _0TEMPLATE skeleton is many sequential Drive API round-trips
// (one per subfolder + file = several seconds). The Drive API has no "copy a
// folder tree" call, so that cost is unavoidable — BUT we don't make the user
// wait for it. On PHP-FPM we send the redirect, finish the response, and clone
// AFTER the browser has already navigated to main.php, so "New Project" feels
// instant. The project row already exists; the Drive folder appears shortly
// after. Non-fatal either way: a failure just leaves drive_folder_id unset
// (link it later from the project edit / keynotes page).
$provFile = __DIR__ . '/dms/drive_provision.php';
$provisionEnabled = false;
try { $provisionEnabled = (meta_get($pdo, 'dms_autoprovision', '0') === '1') && is_file($provFile); }
catch (Exception $e) { /* meta/file issue → skip provisioning */ }

if ($provisionEnabled && function_exists('fastcgi_finish_request')) {
    // ── Background path (PHP-FPM): respond now, clone after ──
    header('Location: main.php');
    session_write_close();                       // release the session lock so main.php doesn't block
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
    fastcgi_finish_request();                     // browser navigates to main.php now
    ignore_user_abort(true);
    @set_time_limit(180);                         // the clone can take a while; don't get killed mid-tree
    try {
        require_once $provFile;
        provision_project_drive_folder($pdo, $nextId);
    } catch (\Throwable $e) {
        error_log('[create_project] async Drive provisioning failed for proj ' . $nextId . ': ' . $e->getMessage());
    }
    exit;
}

// ── Inline path (no FPM, or provisioning disabled): original behaviour ──
if ($provisionEnabled) {
    try {
        require_once $provFile;
        provision_project_drive_folder($pdo, $nextId);
    } catch (Exception $e) {
        $_SESSION['drive_flash_err'] = 'Project created, but Drive folder auto-provisioning failed: ' . $e->getMessage();
    }
}

header('Location: main.php');
exit;
?>
<html>
<body>
Copyright &copy; 2012 CADViz Ltd All Rights Reserved
</body>
</html>
