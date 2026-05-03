<?php
/**
 * Erik's "approve future leave" handler.
 *
 * Sets Leave_Approved = 1 on a list of Timesheets rows. The matching SELECTs
 * on the menu page restrict to (proj_id = LEAVE_PROJECT_ID, TS_DATE > today,
 * Leave_Approved = 0) so this handler only ever flips genuinely-pending
 * future leave entries — even if a TS_ID slipped in from a tampered form,
 * the WHERE clause below prevents anything else being touched.
 *
 * POST params:
 *   ts_ids — comma-separated list of Timesheets.TS_ID values to approve
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

if (($_SESSION['UserID'] ?? '') !== 'erik') {
    http_response_code(403); die('Approving leave is restricted to Erik.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('POST only.');
}

$pdo = get_db();

$raw = (string)($_POST['ts_ids'] ?? '');
$ids = array_values(array_filter(array_map('intval', explode(',', $raw)), fn($i) => $i > 0));

try {
    if (empty($ids)) throw new Exception('No timesheet IDs supplied.');

    $in  = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE Timesheets
               SET Leave_Approved = 1
             WHERE TS_ID IN ($in)
               AND proj_id = ?
               AND TS_DATE > CURDATE()
               AND Leave_Approved = 0";
    $st  = $pdo->prepare($sql);
    $params = array_merge($ids, [LEAVE_PROJECT_ID]);
    $st->execute($params);

    $approved = $st->rowCount();
    if ($approved === 0) {
        $_SESSION['leave_flash_err'] = 'Nothing approved — those entries may have already been approved or are no longer in the future.';
    } else {
        $_SESSION['leave_flash'] = "Approved {$approved} future leave day" . ($approved === 1 ? '' : 's') . '.';
    }
} catch (Exception $e) {
    $_SESSION['leave_flash_err'] = 'Approval failed: ' . $e->getMessage();
}

header('Location: menu.php');
exit;
