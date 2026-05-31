<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';   // LEAVE_PROJECT_ID

$userID = $_SESSION['UserID'];

// Only allow access for authorised users (mirrors original ASP logic)
$allowed = ['erik', 'jen'];
if (!in_array($userID, $allowed)) {
    echo "<p>Access denied.</p>";
    exit;
}

$pdo = get_db();

// ── Cutoff filter — how far back to count uninvoiced hours ───────────────
$since = (string)($_GET['since'] ?? 'all');
$sinceLabels = ['all' => 'All time', '3m' => 'Last 3 months', '6m' => 'Last 6 months', '12m' => 'Last 12 months', 'fy' => 'This financial year'];
if (!isset($sinceLabels[$since])) $since = 'all';
$fyStartYear = ((int)date('n') >= 4) ? (int)date('Y') : (int)date('Y') - 1;
$sinceDate = null;
switch ($since) {
    case '3m':  $sinceDate = date('Y-m-d', strtotime('-3 months'));  break;
    case '6m':  $sinceDate = date('Y-m-d', strtotime('-6 months'));  break;
    case '12m': $sinceDate = date('Y-m-d', strtotime('-12 months')); break;
    case 'fy':  $sinceDate = sprintf('%04d-04-01', $fyStartYear);    break;
}
$leaveId = defined('LEAVE_PROJECT_ID') ? (int)LEAVE_PROJECT_ID : 1435;
?>
<script language="Javascript">
    document.onkeydown = function() {
        if (event.keyCode == 13) {
            window.location = "more.php"
        }
    }
</script>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Language" content="en-nz">
<title>Uninvoiced Staff Hours</title>
<link href="site.css" rel="stylesheet">
<link href="global2.css" rel="stylesheet" type="text/css" />
<basefont face="arial">
<style type="text/css">
.style1 { background-color: #9B9B1B; }
</style>
</head>
<body bgcolor="#EBEBEB" text="black">

<table width="600" border="0" cellspacing="0" cellpadding="0" bgcolor="#EBEBEB" id="table8">
  <tr>
    <td>
      <table border="0" cellspacing="0" width="644" cellpadding="0" id="table9" class="style1">
        <tr>
          <td align="center" colspan="4" height="26">
            <p align="left"><b>&nbsp;<font size="3">&nbsp;</font><font color="#FFFFFF" size="3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;UNINVOICED STAFF HOURS</font></b></td>
          <td align="center" colspan="3" height="26">
            <a href="logout.php"><font color="#FFFFFF" size="2">logout</font></a></td>
        </tr>
        <tr>
          <td align="center"><a href="projects.php"><font color="#FFFFFF">My Projects</font></a></td>
          <td align="center"><a href="main.php"><font color="#FFFFFF">My Timesheet</font></a></td>
          <td align="center"><a href="login.php"><font color="#FFFFFF">Login Again</font></a></td>
          <td align="center" colspan="2"><a href="report.php"><font color="#FFFFFF">Reports</font></a></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
        <tr>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center"></td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;
            <?php if ($userID === 'erik' || $userID === 'jen'): ?>
              <a href='more.php'><font color='#FFFFFF' size='2'>More...</font></a>
            <?php endif; ?>
          </td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
          <td align="center">&nbsp;</td>
        </tr>
      </table>
    </td>
  </tr>
</table>
<p></p>

<form method="get" style="margin:10px 12px;font-family:Arial,sans-serif;font-size:13px">
  <label>Show uninvoiced hours since:
    <select name="since" onchange="this.form.submit()">
      <?php foreach ($sinceLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $since === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<?php
try {
    // Uninvoiced billable hours per staff. Excludes the Leave project (those
    // hours are never invoiced) and future-dated entries (forward leave
    // bookings); IFNULL so a NULL Invoice_No also counts as uninvoiced;
    // honours the cutoff dropdown.
    $where  = "IFNULL(t.Invoice_No, 0) = 0 AND t.proj_id <> :leave AND t.TS_DATE <= CURDATE()";
    $params = [':leave' => $leaveId];
    if ($sinceDate !== null) { $where .= " AND t.TS_DATE >= :since"; $params[':since'] = $sinceDate; }

    $sql = "SELECT s.Employee_ID, s.Login,
                   SUM(t.Hours)   AS tothours,
                   MIN(t.TS_DATE) AS oldest,
                   MAX(t.TS_DATE) AS newest
              FROM Timesheets t
              LEFT JOIN Staff s ON t.Employee_id = s.Employee_ID
             WHERE $where
             GROUP BY s.Employee_ID, s.Login
             ORDER BY tothours DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grand = 0.0;

    if (count($rows) === 0) {
        echo '<p style="color:#888">No uninvoiced timesheet entries found for this period.</p>';
    } else {
        echo '<table cellpadding="5" cellspacing="0" border="0" style="margin:10px;border-collapse:collapse">';
        echo '<tr style="background:#9B9B1B;color:#fff"><td style="padding:4px 10px">Staff</td><td style="padding:4px 10px;text-align:right">Uninvoiced hours</td><td style="padding:4px 10px">Oldest entry</td><td style="padding:4px 10px">Newest</td></tr>';
        foreach ($rows as $row) {
            $h = (float)($row['tothours'] ?? 0);
            $grand += $h;
            echo '<tr style="border-bottom:1px solid #eee">';
            echo '<td style="padding:4px 10px">' . htmlspecialchars((string)($row['Login'] ?? '(unknown)')) . '</td>';
            echo '<td style="padding:4px 10px;text-align:right"><b>' . number_format($h, 2) . '</b></td>';
            echo '<td style="padding:4px 10px;color:#888">' . htmlspecialchars((string)($row['oldest'] ?? '')) . '</td>';
            echo '<td style="padding:4px 10px;color:#888">' . htmlspecialchars((string)($row['newest'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '<tr style="border-top:2px solid #999"><td style="padding:4px 10px"><b>TOTAL</b></td><td style="padding:4px 10px;text-align:right"><b>' . number_format($grand, 2) . '</b></td><td colspan="2"></td></tr>';
        echo '</table>';
        echo '<p style="font-size:11px;color:#888;margin:6px 12px">Excludes the Leave project &amp; future-dated entries. A very old &ldquo;oldest entry&rdquo; usually means stale uninvoiced work that should be invoiced or written off.</p>';
    }
} catch (Exception $e) {
    echo '<p style="color:red">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

</body>
</html>
