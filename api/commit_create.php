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
 * manifest_format_version selects the path: "revit-native-1" commits the
 * manifest JSON itself (project.model.json) as the versioned artifact and
 * writes the native element columns; anything else uses the legacy IFC path
 * (an uploaded .ifc file committed as project.ifc).
 */

require_once __DIR__ . '/_bootstrap.php';

$uid   = require_session_or_token();   // browser session OR add-in API token
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
$clientCommitUid  = trim((string)($_POST['client_commit_uid'] ?? ''));   // idempotency key (offline queue)

if ($projId <= 0)         json_err('proj_id is required.');
if ($message === '')      json_err('message is required.');
if ($manifestJson === '') json_err('manifest is required (JSON string from web-ifc parser).');

$manifest = json_decode($manifestJson, true);
if (!is_array($manifest)) json_err('manifest must be valid JSON.', 400);

// Native Revit manifest vs legacy IFC manifest. Native commits the manifest
// JSON itself as the versioned artifact (project.model.json); the legacy path
// commits an uploaded IFC file. See DMS_ADDIN_PLAN.md / manifest-schema.md.
$manifestFmt = trim((string)($_POST['manifest_format_version'] ?? ''));
$isNative    = ($manifestFmt === 'revit-native-1');

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

// ── Idempotency (offline-queue retries) ───────────────────────────────────
// The add-in queues a commit and retries after a network blip, reusing the
// same client_commit_uid. If we already have a commit with that UID, return it
// instead of creating a duplicate. Column from add_keynotes_and_idempotency.sql.
if ($clientCommitUid !== '') {
    try {
        $dup = $pdo->prepare("SELECT Commit_ID, Ifc_Git_Sha FROM Commits WHERE Client_Commit_Uid = ? LIMIT 1");
        $dup->execute([$clientCommitUid]);
        if ($row = $dup->fetch()) {
            json_ok([
                'commit_id' => (int)$row['Commit_ID'],
                'git_sha'   => $row['Ifc_Git_Sha'],
                'duplicate' => true,
                'message'   => 'Commit already received (idempotent retry).',
            ], 200);
        }
    } catch (\Throwable $e) { /* column missing on legacy installs — skip dedupe */ }
}

// ── Source artifact → git ─────────────────────────────────────────────────
// Native: the manifest JSON IS the versioned artifact (diffable, durable, =
// training data) → project.model.json. Legacy: the uploaded .ifc → project.ifc.
// The git commit happens BEFORE the DB txn and isn't rollback-able (a failed
// txn just leaves a harmless orphan SHA).
require_once __DIR__ . '/../dms/git_repo.php';

try {
    $repo = GitRepo::forProject((int)$projId);
} catch (GitRepoException $e) {
    json_err('Git repo init failed: ' . $e->getMessage(), 500);
}
$parentSha   = $repo->headSha();   // '' on first commit
$authorName  = $uid;
$authorEmail = $uid . '@cadviz.co.nz';

$nativeTmp = null;
if ($isNative) {
    $nativeTmp = tempnam(sys_get_temp_dir(), 'cadviz_model_');
    if ($nativeTmp === false || @file_put_contents($nativeTmp, $manifestJson) === false) {
        json_err('Failed to stage manifest for commit.', 500);
    }
    $srcTmp         = $nativeTmp;
    $srcSize        = (int)filesize($srcTmp);
    $srcPathInRepo  = 'project.model.json';
    $srcContentType = 'application/json';
    $srcBlobRole    = 'model_json';
} else {
    if (!isset($_FILES['ifc']) || $_FILES['ifc']['error'] !== UPLOAD_ERR_OK) {
        json_err('IFC file upload missing or failed (or set manifest_format_version=revit-native-1).', 400, [
            'upload_error' => $_FILES['ifc']['error'] ?? 'none',
        ]);
    }
    $srcTmp         = $_FILES['ifc']['tmp_name'];
    $srcSize        = (int)$_FILES['ifc']['size'];
    $srcPathInRepo  = 'project.ifc';
    $srcContentType = 'application/ifc';
    $srcBlobRole    = 'ifc';
}
$srcSha = hash_file('sha256', $srcTmp);
if (!$srcSha) { if ($nativeTmp) @unlink($nativeTmp); json_err('Failed to hash source artifact.', 500); }

try {
    $gitSha = $repo->commitFile($srcTmp, $srcPathInRepo, $message, $authorName, $authorEmail);
} catch (GitRepoException $e) {
    if ($nativeTmp) @unlink($nativeTmp);
    json_err('Git commit failed: ' . $e->getMessage(), 500);
}
if ($nativeTmp) @unlink($nativeTmp);

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
     VALUES (?, ?, ?, NOW())"
)->execute([$srcSha, $srcSize, $srcContentType]);

// ── Write the Commit row ─────────────────────────────────────────────────
$pdo->prepare(
    "INSERT INTO Commits
       (Proj_ID, Parent_Commit_ID, Message, Author_UserID, Created_At,
        Rvt_Backup_Number, Rvt_Backup_Path, Ifc_Git_Sha, Status,
        Revision_Label, Description, Client_Commit_Uid)
     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'wip', ?, ?, ?)"
)->execute([
    $projId,
    $parentCommitId,
    $message,
    $uid,
    $rvtBackupNumber,
    $rvtBackupFile,
    $gitSha,
    $revisionLabel ?: null,
    null,
    $clientCommitUid !== '' ? $clientCommitUid : null,
]);
$commitId = (int)$pdo->lastInsertId();

// ── Commit_Blobs: link the IFC blob to this commit ───────────────────────
$pdo->prepare(
    "INSERT INTO Commit_Blobs (Commit_ID, Blob_Sha256, Path_In_Project, Role)
     VALUES (?, ?, ?, ?)"
)->execute([$commitId, $srcSha, $srcPathInRepo, $srcBlobRole]);

// ── Element_Instances + Parameters + Relationships from the manifest ─────
// First cut: write every element. Coverage-rule firing (which needs the
// diff vs parent) is deferred to the next turn — see the file-header
// roadmap. We DO already have enough data here for a useful "what
// elements does this commit have" view.
$insertElement = $pdo->prepare(
    "INSERT INTO Element_Instances
       (Commit_ID, Source_Blob_Sha256, Ifc_Guid, Element_Uid, Ifc_Entity_Type, Builtin_Category,
        Category, Name, Type_Name, Family, Level_Name, Workset, Phase_Created, Phase_Demolished,
        Bbox_Min_X, Bbox_Min_Y, Bbox_Min_Z, Bbox_Max_X, Bbox_Max_Y, Bbox_Max_Z,
        Loc_Type, Loc_X, Loc_Y, Loc_Z, Loc_End_X, Loc_End_Y, Loc_End_Z,
        Facing_X, Facing_Y, Facing_Z, Hand_Flipped, Facing_Flipped, Geometry_Hash)
     VALUES (?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?)"
);
$insertParam = $pdo->prepare(
    "INSERT INTO Element_Parameters
       (Element_Instance_ID, Param_Set, Param_Name, Builtin_Key, Param_Group, Param_Value, Value_Num, Units, Value_Type)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$insertRel = $pdo->prepare(
    "INSERT INTO Element_Relationships
       (Commit_ID, Source_Element_Ifc_Guid, Target_Element_Ifc_Guid, Source_Uid, Target_Uid, Relationship_Type)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$num = function ($v) { return is_numeric($v) ? (float)$v : null; };

$elements = is_array($manifest['elements'] ?? null) ? $manifest['elements'] : [];
foreach ($elements as $el) {
    if (!is_array($el)) continue;

    if ($isNative) {
        if (empty($el['uid'])) continue;
        $ifcGuid = null; $elementUid = (string)$el['uid'];
        $entityType = (string)($el['builtin_category'] ?? '');
        $builtinCat = $el['builtin_category'] ?? null;
        $category   = (string)($el['category_norm'] ?? ($el['category'] ?? 'Other'));
        $name = $el['name'] ?? null; $typeName = $el['type_name'] ?? null;
        $family = $el['family'] ?? null; $level = $el['level'] ?? null;
        $workset = $el['workset'] ?? null;
        $phaseC = $el['phase_created'] ?? null; $phaseD = $el['phase_demolished'] ?? null;

        $bb   = (is_array($el['bbox'] ?? null) && count($el['bbox']) === 6) ? $el['bbox'] : null;
        $loc  = is_array($el['location'] ?? null) ? $el['location'] : [];
        $face = is_array($el['facing'] ?? null) ? $el['facing'] : [];
        $locType = $loc['type'] ?? null;
        $locX = $num($loc['x'] ?? null);  $locY = $num($loc['y'] ?? null);  $locZ = $num($loc['z'] ?? null);
        $locEX = $num($loc['x2'] ?? null); $locEY = $num($loc['y2'] ?? null); $locEZ = $num($loc['z2'] ?? null);
        $faX = $num($face['x'] ?? null);  $faY = $num($face['y'] ?? null);  $faZ = $num($face['z'] ?? null);
        $hand     = array_key_exists('hand_flipped', $el)   ? (int)(bool)$el['hand_flipped']   : null;
        $faceFlip = array_key_exists('facing_flipped', $el) ? (int)(bool)$el['facing_flipped'] : null;
        $geomHash = $el['geometry_hash'] ?? null;
        $params   = is_array($el['parameters'] ?? null) ? $el['parameters'] : [];
    } else {
        if (empty($el['ifc_guid'])) continue;
        $ifcGuid = (string)$el['ifc_guid']; $elementUid = null;
        $entityType = (string)($el['ifc_entity_type'] ?? '');
        $builtinCat = null;
        $category   = (string)($el['category'] ?? 'Other');
        $name = $el['name'] ?? null; $typeName = $el['type_name'] ?? null;
        $family = null; $level = $el['level'] ?? null;
        $workset = null; $phaseC = null; $phaseD = null;

        $bb = (is_array($el['bounding_box'] ?? null) && count($el['bounding_box']) === 6) ? $el['bounding_box'] : null;
        $locType = null; $locX = $locY = $locZ = $locEX = $locEY = $locEZ = null;
        $faX = $faY = $faZ = null; $hand = null; $faceFlip = null; $geomHash = null;
        $params = is_array($el['parameters'] ?? null) ? $el['parameters'] : [];
    }

    $insertElement->execute([
        $commitId, $srcSha, $ifcGuid, $elementUid, $entityType, $builtinCat,
        $category, $name, $typeName, $family, $level, $workset, $phaseC, $phaseD,
        $bb ? (float)$bb[0] : null, $bb ? (float)$bb[1] : null, $bb ? (float)$bb[2] : null,
        $bb ? (float)$bb[3] : null, $bb ? (float)$bb[4] : null, $bb ? (float)$bb[5] : null,
        $locType, $locX, $locY, $locZ, $locEX, $locEY, $locEZ,
        $faX, $faY, $faZ, $hand, $faceFlip, $geomHash,
    ]);
    $elementId = (int)$pdo->lastInsertId();

    foreach ($params as $p) {
        if (!is_array($p) || !isset($p['name'])) continue;
        if ($isNative) {
            $insertParam->execute([
                $elementId, null, (string)$p['name'], $p['builtin'] ?? null, $p['group'] ?? null,
                (string)($p['value'] ?? ''),
                (isset($p['value_num']) && is_numeric($p['value_num'])) ? (float)$p['value_num'] : null,
                $p['units'] ?? null, $p['type'] ?? null,
            ]);
        } else {
            $insertParam->execute([
                $elementId, $p['pset'] ?? null, (string)$p['name'], null, null,
                (string)($p['value'] ?? ''), null, $p['units'] ?? null, null,
            ]);
        }
    }
}

$relationships = is_array($manifest['relationships'] ?? null) ? $manifest['relationships'] : [];
foreach ($relationships as $r) {
    if (!is_array($r)) continue;
    if ($isNative) {
        if (empty($r['source_uid']) || empty($r['target_uid'])) continue;
        $insertRel->execute([
            $commitId, null, null,
            (string)$r['source_uid'], (string)$r['target_uid'], (string)($r['type'] ?? 'references'),
        ]);
    } else {
        if (empty($r['source_guid']) || empty($r['target_guid'])) continue;
        $insertRel->execute([
            $commitId, (string)$r['source_guid'], (string)$r['target_guid'], null, null,
            (string)($r['type'] ?? 'references'),
        ]);
    }
}

// ── Keynotes catalogue (native manifest) ─────────────────────────────────
// Snapshot the project's Revit keynote table (code → description → category)
// so an element's Keynote parameter code resolves to its spec meaning at this
// revision. The add-in reads keynotes.txt from the project folder.
$keynotes = is_array($manifest['keynotes'] ?? null) ? $manifest['keynotes'] : [];
if (!empty($keynotes)) {
    $insKn = $pdo->prepare(
        "INSERT INTO Commit_Keynotes (Commit_ID, Code, Description, Category) VALUES (?, ?, ?, ?)"
    );
    foreach ($keynotes as $kn) {
        if (!is_array($kn) || (string)($kn['code'] ?? '') === '') continue;
        $insKn->execute([$commitId, (string)$kn['code'], (string)($kn['description'] ?? ''), $kn['category'] ?? null]);
    }
}

// ── PDF outputs ─────────────────────────────────────────────────────────
// The add-in (and the legacy SPA) send drawing PDFs as pdf[] multipart
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
require_once __DIR__ . '/../dms/coverage_engine.php';

// Persist the structured changeset (Commit_Diffs) and reuse it for coverage so
// snapshots load once. Both are post-commit + non-fatal — a diff/coverage
// failure must not lose the commit.
$coverage   = ['firings' => 0, 'tags' => [], 'details' => [], 'errors' => []];
$diffCounts = ['added' => 0, 'removed' => 0, 'modified' => 0];
$precomputedDiff = null;
try {
    $precomputedDiff = build_and_persist_commit_diff($pdo, $commitId, $parentCommitId);
    $diffCounts = [
        'added'    => count($precomputedDiff['added']),
        'removed'  => count($precomputedDiff['removed']),
        'modified' => count($precomputedDiff['modified']),
    ];
} catch (\Throwable $e) {
    $coverage['errors'][] = 'Diff persist failed: ' . $e->getMessage();
}
try {
    $cov = run_coverage_rules($pdo, $commitId, $parentCommitId, $precomputedDiff);
    $coverage['firings'] = $cov['firings'];
    $coverage['tags']    = $cov['tags'];
    $coverage['details'] = $cov['details'];
    $coverage['errors']  = array_merge($coverage['errors'], $cov['errors']);
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
    'git_sha'           => $gitSha,
    'parent_git_sha'    => $parentSha,
    'parent_commit_id'  => $parentCommitId,
    'manifest_format'   => $isNative ? 'revit-native-1' : 'ifc',
    'artifact_sha256'   => $srcSha,
    'element_count'     => count($elements),
    'relationship_count'=> count($relationships),
    'keynote_count'     => count($keynotes),
    'diff'              => $diffCounts,
    'pdfs_stored'       => $pdfStored,
    'draft_timesheet_id'=> $draftTsId,
    'coverage'          => [
        'firings' => $coverage['firings'],
        'tags'    => $coverage['tags'],
        'details' => $coverage['details'],
    ],
    'warnings'          => $warnings,
], 201);
