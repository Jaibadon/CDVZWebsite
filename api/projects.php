<?php
/**
 * GET /api/projects.php  →  list of projects the current user can see.
 *
 * Same filter logic as variation_add.php's project picker: projects the
 * user is on (Manager / DP1 / DP2 / DP3) OR has timesheet entries against.
 * Admins (erik/jen) see all active projects.
 */
require_once __DIR__ . '/_bootstrap.php';

$uid = require_session();
$pdo = get_db();

$isAdmin = in_array($uid, ['erik','jen'], true);
$empId   = (int)($_SESSION['Employee_id'] ?? 0);

// Feature-detect new columns so this endpoint works on hosts that haven't
// applied add_dms_schema.sql yet (rare but matches the rest of the codebase's
// idempotent-degrade pattern).
$hasDms = false; $hasDriveFolder = false;
try { $hasDms          = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'dms_active'")->fetch(); } catch (Exception $e) {}
try { $hasDriveFolder  = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'drive_folder_id'")->fetch(); } catch (Exception $e) {}

$dmsCol   = $hasDms          ? "COALESCE(p.dms_active, 0)" : "0";
$driveCol = $hasDriveFolder  ? "p.drive_folder_id"          : "NULL";

if ($isAdmin) {
    $sql = "SELECT p.proj_id, p.JobName, c.Client_Name,
                   $dmsCol   AS dms_active,
                   $driveCol AS drive_folder_id
              FROM Projects p
              LEFT JOIN Clients c ON p.Client_ID = c.Client_id
             WHERE p.Active <> 0
             ORDER BY p.JobName";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT DISTINCT p.proj_id, p.JobName, c.Client_Name,
                   $dmsCol   AS dms_active,
                   $driveCol AS drive_folder_id
              FROM Projects p
              LEFT JOIN Clients c ON p.Client_ID = c.Client_id
             WHERE p.Active <> 0
               AND (p.Manager = :e OR p.DP1 = :e OR p.DP2 = :e OR p.DP3 = :e
                    OR p.proj_id IN (SELECT proj_id FROM Timesheets WHERE Employee_id = :e))
             ORDER BY p.JobName";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':e' => $empId]);
}

$rows = $stmt->fetchAll();
// Cast types so the SPA's TypeScript shapes are honoured
foreach ($rows as &$r) {
    $r['proj_id']    = (int)$r['proj_id'];
    $r['dms_active'] = (int)$r['dms_active'];
}
unset($r);

json_ok($rows);
