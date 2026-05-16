<?php
/**
 * GET /api/stakeholders.php?proj_id=N  → list of stakeholders for a project.
 *
 * Used by the SPA's post-publish flow to look up which actual people to send
 * a transmittal to, given the suggested-role hints from the coverage engine.
 *
 * Returns only Active = 1 stakeholders. Archived ones are visible in
 * stakeholders.php (the admin page) but excluded from new transmittals.
 *
 * Query params:
 *   proj_id     int    (required)
 *   roles_csv   string (optional) — comma-separated role keys; filters server-side.
 *                                   e.g. "structural,weathertightness"
 *
 * No POST/PUT/DELETE here yet — stakeholder mutations happen via the PHP
 * page (stakeholders.php). Add them here if/when the SPA grows inline CRUD.
 */

require_once __DIR__ . '/_bootstrap.php';

$uid = require_session();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('GET only.', 405);

$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) json_err('proj_id required.', 400);

// Optional role filter, comma-separated. Sanitised to alnum+underscore so it
// can't smuggle SQL through.
$rolesCsv = trim((string)($_GET['roles_csv'] ?? ''));
$roles = [];
if ($rolesCsv !== '') {
    foreach (explode(',', $rolesCsv) as $r) {
        $r = trim($r);
        if ($r !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $r)) $roles[] = $r;
    }
}

if (!empty($roles)) {
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT Stakeholder_ID, Proj_ID, Role, Role_Label, Name, Email, Phone, Company, Notes
              FROM Project_Stakeholders
             WHERE Proj_ID = ? AND Active = 1 AND Role IN ($placeholders)
             ORDER BY Role, Name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$proj_id], $roles));
} else {
    $stmt = $pdo->prepare(
        "SELECT Stakeholder_ID, Proj_ID, Role, Role_Label, Name, Email, Phone, Company, Notes
           FROM Project_Stakeholders
          WHERE Proj_ID = ? AND Active = 1
          ORDER BY Role, Name"
    );
    $stmt->execute([$proj_id]);
}

$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['Stakeholder_ID'] = (int)$r['Stakeholder_ID'];
    $r['Proj_ID']        = (int)$r['Proj_ID'];
}
unset($r);

json_ok($rows);
