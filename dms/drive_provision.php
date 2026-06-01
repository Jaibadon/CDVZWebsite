<?php
/**
 * Auto-provision a project's Google Drive folder structure.
 *
 * Grouping rule (what Erik asked for):
 *   • If the client already has a folder → put the project under it ALWAYS
 *     (so once a client has jobs grouped, new jobs join them automatically).
 *   • Otherwise follow the global mode (App_Meta 'dms_folder_grouping'):
 *       'client'     → create a client folder under the root, project under it
 *       'standalone' → project folder directly under the root
 *
 * Always creates a PDFS/ subfolder. Stores the project folder id on
 * Projects.drive_folder_id (and the client folder id on Clients.drive_folder_id
 * when it makes one). Idempotent: no-op if the project is already linked.
 *
 * Requires Drive connected + App_Meta 'dms_drive_root_folder_id' set. Throws on
 * Drive errors — the caller (create_project.php) treats failure as non-fatal.
 */

require_once __DIR__ . '/drive_client.php';
require_once __DIR__ . '/../helpers.php';

if (!function_exists('provision_project_drive_folder')) {

function drive_safe_name(string $name, string $fallback): string
{
    $n = trim(preg_replace('/[\/\\\\:*?"<>|]+/', '-', $name));
    return $n !== '' ? $n : $fallback;
}

function provision_project_drive_folder(PDO $pdo, int $projId): array
{
    $root = trim((string)meta_get($pdo, 'dms_drive_root_folder_id', ''));
    if ($root === '') throw new Exception('No DMS Drive root folder configured (menu → DMS auto-provisioning).');
    $templateId = trim((string)meta_get($pdo, 'dms_template_folder_id', ''));   // the _0TEMPLATE to clone

    $p = $pdo->prepare("SELECT proj_id, JobName, Client_ID, drive_folder_id FROM Projects WHERE proj_id = ?");
    $p->execute([$projId]);
    $proj = $p->fetch(PDO::FETCH_ASSOC);
    if (!$proj) throw new Exception("Project $projId not found.");
    if (trim((string)($proj['drive_folder_id'] ?? '')) !== '') {
        return ['folder_id' => (string)$proj['drive_folder_id'], 'created' => false, 'note' => 'already linked'];
    }

    $mode     = meta_get($pdo, 'dms_folder_grouping', 'client');   // 'client' | 'standalone'
    $clientId = (int)($proj['Client_ID'] ?? 0);
    $parentId = $root;

    if ($clientId > 0) {
        $c = $pdo->prepare("SELECT Client_id, Client_Name, drive_folder_id FROM Clients WHERE Client_id = ?");
        $c->execute([$clientId]);
        $client = $c->fetch(PDO::FETCH_ASSOC);
        $clientFolder = $client ? trim((string)($client['drive_folder_id'] ?? '')) : '';

        if ($clientFolder !== '') {
            // Client already grouped → always join them.
            $parentId = $clientFolder;
        } elseif ($mode === 'client' && $client) {
            $cname = drive_safe_name((string)$client['Client_Name'], 'Client ' . $clientId);
            $clientFolder = DriveClient::ensureSubfolder($pdo, $root, $cname);
            try { $pdo->prepare("UPDATE Clients SET drive_folder_id = ? WHERE Client_id = ?")->execute([$clientFolder, $clientId]); }
            catch (Exception $e) { /* column missing → still provision the project under root/client */ }
            $parentId = $clientFolder;
        }
        // mode === 'standalone' and no existing client folder → parent stays root.
    }

    // Suffix the proj_id so two projects with the same JobName don't collide
    // into one folder.
    $jobSafe  = drive_safe_name((string)$proj['JobName'], 'Project ' . $projId);
    $projName = $jobSafe . ' (#' . $projId . ')';

    if ($templateId !== '') {
        // Clone the full _0TEMPLATE skeleton (subfolders + seed files + starter .rvt).
        $projFolder = DriveClient::copyFolderRecursive($pdo, $templateId, $parentId, $projName);
        // Rename the starter REVIT/PROJECT FILE.rvt → "<JobName>.rvt".
        try {
            $revitId = DriveClient::findSubfolder($pdo, $projFolder, 'REVIT');
            if ($revitId) {
                foreach (DriveClient::listFolder($pdo, $revitId) as $f) {
                    if (strtolower(substr((string)($f['name'] ?? ''), -4)) === '.rvt') {
                        DriveClient::renameFile($pdo, (string)$f['id'], $jobSafe . '.rvt');
                        break;
                    }
                }
            }
        } catch (Exception $e) { /* rename is non-fatal — folder still provisioned */ }
        $note = 'cloned template';
    } else {
        // No template configured → minimal skeleton (folder + PDF subfolder).
        $projFolder = DriveClient::ensureSubfolder($pdo, $parentId, $projName);
        try { DriveClient::ensureSubfolder($pdo, $projFolder, 'PDF'); } catch (Exception $e) {}
        $note = 'minimal (no template set)';
    }

    $pdo->prepare("UPDATE Projects SET drive_folder_id = ? WHERE proj_id = ?")->execute([$projFolder, $projId]);
    return ['folder_id' => $projFolder, 'created' => true, 'note' => $note . ' under ' . ($parentId === $root ? 'root' : 'client folder')];
}

} // function_exists guard
