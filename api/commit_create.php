<?php
/**
 * POST /api/commit_create.php  →  create a new Commit on a project.
 *
 * Full pipeline (all implemented):
 *   1. Receive multipart form: ifc file (blob), manifest (JSON string),
 *      pdf[] (optional blobs), proj_id, rvt_backup_number,
 *      rvt_backup_filename, message, revision_label,
 *      manifest_format_version.
 *   2. Validate proj_id + that the caller is admin or assigned to it.
 *   3. sha256 + temp the IFC; git-commit it into the project's bare repo
 *      (git_repo.php creates the repo on first commit). The git commit
 *      happens BEFORE the DB transaction and is not rollback-able — a
 *      failed DB transaction just leaves an orphan SHA (harmless).
 *   4. [TXN] Blobs(IFC) + Commits + Commit_Blobs + Element_Instances/
 *      Parameters/Relationships + PDF blobs (archived to
 *      CADVIZ_BLOB_ARCHIVE_PATH). All-or-nothing.
 *   5. Coverage engine (post-commit, own try/catch — non-fatal) → firings
 *      + Commit_NZBC_Tags.
 *   6. Return commit_id, git_sha, element/relationship counts,
 *      pdfs_stored, coverage summary, warnings.
 *
 * manifest_format_version is accepted + currently ignored — it future-
 * proofs the endpoint for the Phase 2 Revit add-in to POST a richer
 * native manifest shape without breaking the IFC path.
 */

require_once __DIR__ . '/_bootstrap.php';

$uid   = require_session();
$pdo   = get_db();
$empId = (int)($_SESSION['Employee_id'] ?? 0);
$isAdmin = in_array($uid, ['erik','jen'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST only.', 405);

// ── Parse inputs (multipart form expected) ───────────────────────────────
$projId           = (int)($_POST['proj_id'] ?? 0);
$message          = trim((string)($_POST['message'] ?? ''));
$revisionLabel    = trim((string)($_POST['revision_label'] ?? ''));
$rvtBackupNumber  = isset($_POST['rvt_backup_number']) && $_POST['rvt_backup_number'] !== ''
                     ? (int)$_POST['rvt_backup_number'] : null;
$rvtBackupFile    = trim((string)($_POST['rvt_backup_filename'] ?? ''));
$manifestJson     = (string)($_POST['manifest'] ?? '');

if ($projId <= 0)         json_err('proj_id is required.');
if ($message === '')      json_err('message is required.');
if ($manifestJson === '') json_err('manifest is required (JSON string from web-ifc parser).');

$manifest = json_decode($manifestJson, true);
if (!is_array($manifest)) json_err('manifest must be valid JSON.', 400);

// ── Authorisation ────────────────────────────────────────────────────────
$proj = $pdo->prepare("SELECT proj_id, JobName, Active, Manager, DP1, DP2, DP3 FROM Projects WHERE proj_id = ?");
$proj->execute([$projId]);
$proj = $proj->fetch();
if (!$proj)                          json_err('Project not found.', 404);
if ((int)$proj['Active'] === 0)      json_err('Project is inactive.', 409);

$assignedToProject = in_array($empId, [
    (int)$proj['Manager'], (int)$proj['DP1'], (int)$proj['DP2'], (int)$proj['DP3'],
], true);
if (!$isAdmin && !$assignedToProject) json_err('Not assigned to this project.', 403);

// ── IFC file upload ──────────────────────────────────────────────────────
if (!isset($_FILES['ifc']) || $_FILES['ifc']['error'] !== UPLOAD_ERR_OK) {
    json_err('IFC file upload missing or failed.', 400, [
        'upload_error' => $_FILES['ifc']['error'] ?? 'none',
    ]);
}
$ifcTmpPath = $_FILES['ifc']['tmp_name'];
$ifcSize    = (int)$_FILES['ifc']['size'];
$ifcSha     = hash_file('sha256', $ifcTmpPath);
if (!$ifcSha) json_err('Failed to hash IFC.', 500);

// ── Hand off to git_repo.php (creates bare repo on first commit) ─────────
require_once __DIR__ . '/../git_repo.php';

try {
    $repo = GitRepo::forProject((int)$projId);
} catch (GitRepoException $e) {
    json_err('Git repo init failed: ' . $e->getMessage(), 500);
}

$parentSha = $repo->headSha();   // '' on first commit

$authorName  = $uid;
$authorEmail = $uid . '@cadviz.co.nz';
try {
    $sha = $repo->commitFile($ifcTmpPath, 'project.ifc', $message, $authorName, $authorEmail);
} catch (GitRepoException $e) {
    json_err('Git commit failed: ' . $e->getMessage(), 500);
}

// ── Resolve parent Commit_ID (most recent commit on this project) ────────
$parentCommitId = null;
$parentRow = $pdo->prepare("SELECT Commit_ID FROM Commits WHERE Proj_ID = ? AND Is_Superseded = 0 ORDER BY Commit_ID DESC LIMIT 1");
$parentRow->execute([$projId]);
$prev = $parentRow->fetchColumn();
if ($prev) $parentCommitId = (int)$prev;

// ── DB writes are transactional ──────────────────────────────────────────
// The git commit above already happened and can't be rolled back. If any
// DB write below fails we roll the whole lot back so we DON'T end up with a
// half-written commit (Commits row but no elements, etc.). The orphaned git
// SHA is harmless — it's just an unreferenced object; the retry makes a new
// commit cleanly. Coverage engine runs AFTER commit() in its own try/catch
// (a coverage failure must not lose the commit itself).
try {
    $pdo->beginTransaction();

// ── Write the Blobs row for the IFC ──────────────────────────────────────
// Idempotent: if the same sha was uploaded before (no-op rebuild) the row
// already exists. INSERT IGNORE on PK collision.
$pdo->prepare(
    "INSERT IGNORE INTO Blobs (Sha256, Size_Bytes, Content_Type, First_Seen_At)
     VALUES (?, ?, 'application/ifc', NOW())"
)->execute([$ifcSha, $ifcSize]);

// ── Write the Commit row ─────────────────────────────────────────────────
$pdo->prepare(
    "INSERT INTO Commits
       (Proj_ID, Parent_Commit_ID, Message, Author_UserID, Created_At,
        Rvt_Backup_Number, Rvt_Backup_Path, Ifc_Git_Sha, Status,
        Revision_Label, Description)
     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'wip', ?, ?)"
)->execute([
    $projId,
    $parentCommitId,
    $message,
    $uid,
    $rvtBackupNumber,
    $rvtBackupFile,
    $sha,
    $revisionLabel ?: null,
    null,
]);
$commitId = (int)$pdo->lastInsertId();

// ── Commit_Blobs: link the IFC blob to this commit ───────────────────────
$pdo->prepare(
    "INSERT INTO Commit_Blobs (Commit_ID, Blob_Sha256, Path_In_Project, Role)
     VALUES (?, ?, 'project.ifc', 'ifc')"
)->execute([$commitId, $ifcSha]);

// ── Element_Instances + Parameters + Relationships from the manifest ─────
// First cut: write every element. Coverage-rule firing (which needs the
// diff vs parent) is deferred to the next turn — see the file-header
// roadmap. We DO already have enough data here for a useful "what
// elements does this commit have" view.
$insertElement = $pdo->prepare(
    "INSERT INTO Element_Instances
       (Commit_ID, Source_Blob_Sha256, Ifc_Guid, Ifc_Entity_Type, Category, Name, Type_Name,
        Level_Name, Bbox_Min_X, Bbox_Min_Y, Bbox_Min_Z, Bbox_Max_X, Bbox_Max_Y, Bbox_Max_Z)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$insertParam = $pdo->prepare(
    "INSERT INTO Element_Parameters (Element_Instance_ID, Param_Set, Param_Name, Param_Value, Units)
     VALUES (?, ?, ?, ?, ?)"
);
$insertRel = $pdo->prepare(
    "INSERT INTO Element_Relationships
       (Commit_ID, Source_Element_Ifc_Guid, Target_Element_Ifc_Guid, Relationship_Type)
     VALUES (?, ?, ?, ?)"
);

$elements = is_array($manifest['elements'] ?? null) ? $manifest['elements'] : [];
foreach ($elements as $el) {
    if (empty($el['ifc_guid'])) continue;

    // bounding_box is a 6-tuple [minX,minY,minZ,maxX,maxY,maxZ] OR null
    // if web-ifc couldn't compute geometry for this element.
    $bbox = $el['bounding_box'] ?? null;
    $b = (is_array($bbox) && count($bbox) === 6) ? $bbox : null;

    $insertElement->execute([
        $commitId,
        $ifcSha,
        (string)$el['ifc_guid'],
        (string)($el['ifc_entity_type'] ?? ''),
        (string)($el['category'] ?? 'Other'),
        $el['name']      ?? null,
        $el['type_name'] ?? null,
        $el['level']     ?? null,
        $b ? (float)$b[0] : null,
        $b ? (float)$b[1] : null,
        $b ? (float)$b[2] : null,
        $b ? (float)$b[3] : null,
        $b ? (float)$b[4] : null,
        $b ? (float)$b[5] : null,
    ]);
    $elementId = (int)$pdo->lastInsertId();
    foreach (($el['parameters'] ?? []) as $p) {
        if (!isset($p['name'])) continue;
        $insertParam->execute([
            $elementId,
            $p['pset']  ?? null,
            (string)$p['name'],
            (string)($p['value'] ?? ''),
            $p['units'] ?? null,
        ]);
    }
}

$relationships = is_array($manifest['relationships'] ?? null) ? $manifest['relationships'] : [];
foreach ($relationships as $r) {
    if (empty($r['source_guid']) || empty($r['target_guid'])) continue;
    $insertRel->execute([
        $commitId,
        (string)$r['source_guid'],
        (string)$r['target_guid'],
        (string)($r['type'] ?? 'references'),
    ]);
}

// ── PDF outputs ─────────────────────────────────────────────────────────
// The SPA sends any PDFs it found in the project folder as pdf[] multipart
// files. We content-address them (sha256) into the blob archive on the
// host filesystem, so the magic-link review page can serve them to
// stakeholders WITHOUT a Drive round-trip on every page load. PDFs are
// outputs (not the source of truth — the .rvt + IFC are), so they don't
// go into git; the filesystem archive + Blobs row is enough for audit +
// review. Non-fatal: a PDF that fails to store just produces a warning.
$pdfWarnings = [];
$pdfStored = 0;
if (!empty($_FILES['pdf']) && is_array($_FILES['pdf']['name'] ?? null)) {
    if (!defined('CADVIZ_BLOB_ARCHIVE_PATH') || CADVIZ_BLOB_ARCHIVE_PATH === '') {
        $pdfWarnings[] = 'CADVIZ_BLOB_ARCHIVE_PATH not configured — PDFs were uploaded but not stored. Set it in config.php (see config.cadviz.sample.php).';
    } else {
        $archiveDir = rtrim(CADVIZ_BLOB_ARCHIVE_PATH, '/\\');
        if (!is_dir($archiveDir)) { @mkdir($archiveDir, 0750, true); }
        if (!is_dir($archiveDir) || !is_writable($archiveDir)) {
            $pdfWarnings[] = "Blob archive dir not writable ($archiveDir) — PDFs not stored.";
        } else {
            $insBlob = $pdo->prepare(
                "INSERT IGNORE INTO Blobs (Sha256, Filesystem_Path, Size_Bytes, Content_Type, First_Seen_At)
                 VALUES (?, ?, ?, 'application/pdf', NOW())"
            );
            $insCommitBlob = $pdo->prepare(
                "INSERT INTO Commit_Blobs (Commit_ID, Blob_Sha256, Path_In_Project, Role)
                 VALUES (?, ?, ?, 'pdf_output')"
            );
            $names = $_FILES['pdf']['name'];
            $count = count($names);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['pdf']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $pdfWarnings[] = 'PDF "' . ($names[$i] ?? "#$i") . '" upload error code ' . ($_FILES['pdf']['error'][$i] ?? '?');
                    continue;
                }
                $tmp  = $_FILES['pdf']['tmp_name'][$i];
                $orig = (string)($names[$i] ?? "file$i.pdf");
                $sha  = hash_file('sha256', $tmp);
                if (!$sha) { $pdfWarnings[] = "Couldn't hash PDF $orig."; continue; }
                $dest = $archiveDir . DIRECTORY_SEPARATOR . $sha . '.pdf';
                // sha-named: if a byte-identical PDF already exists, reuse it
                if (!file_exists($dest)) {
                    if (!@move_uploaded_file($tmp, $dest) && !@copy($tmp, $dest)) {
                        $pdfWarnings[] = "Failed to store PDF $orig to archive.";
                        continue;
                    }
                }
                // Path relative to the archive root (portable if the dir moves)
                $insBlob->execute([$sha, $sha . '.pdf', filesize($dest)]);
                $insCommitBlob->execute([$commitId, $sha, $orig]);
                $pdfStored++;
            }
        }
    }
}

    $pdo->commit();
} catch (\Throwable $txe) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_err(
        'Commit DB write failed and was rolled back — no partial commit was saved. '
        . 'The git commit is an orphan SHA (harmless); just retry the publish. '
        . 'Detail: ' . $txe->getMessage(),
        500
    );
}

// ── Coverage rule engine ─────────────────────────────────────────────────
// Compare this commit's Element_Instances against the parent commit's (if
// any), fire matching rules, write Coverage_Rule_Firings + initial
// Commit_NZBC_Tags. Runs AFTER the transaction commits — engine failure
// must not roll back the commit itself (its own try/catch below).
require_once __DIR__ . '/../coverage_engine.php';

$coverage = ['firings' => 0, 'tags' => [], 'details' => [], 'errors' => []];
try {
    $coverage = run_coverage_rules($pdo, $commitId, $parentCommitId);
} catch (Exception $e) {
    $coverage['errors'][] = 'Coverage engine threw: ' . $e->getMessage();
}

// ── Timesheet hook ───────────────────────────────────────────────────────
// Drop a Hours=0 draft Timesheets row for the author so they don't re-type
// what they did. Strictly non-fatal — never throws, never affects the
// commit. Runs post-transaction (it's its own independent write).
$draftTsId = null;
try {
    require_once __DIR__ . '/../timesheet_hook.php';
    $draftTsId = draft_timesheet_for_commit($pdo, $commitId, $empId);
} catch (\Throwable $e) { /* non-fatal by contract */ }

// ── Response ─────────────────────────────────────────────────────────────
$warnings = [];
if (count($elements) === 0) {
    $warnings[] = 'No elements parsed from the manifest — coverage rules will have nothing to fire on.';
}
$warnings = array_merge($warnings, $coverage['errors'], $pdfWarnings);

json_ok([
    'commit_id'         => $commitId,
    'git_sha'           => $sha,
    'parent_git_sha'    => $parentSha,
    'parent_commit_id'  => $parentCommitId,
    'ifc_sha256'        => $ifcSha,
    'element_count'     => count($elements),
    'relationship_count'=> count($relationships),
    'pdfs_stored'       => $pdfStored,
    'draft_timesheet_id'=> $draftTsId,
    'coverage'          => [
        'firings' => $coverage['firings'],
        'tags'    => $coverage['tags'],
        'details' => $coverage['details'],
    ],
    'warnings'          => $warnings,
], 201);
