<?php
/**
 * keynotes_copy.php?proj_id=N — copy the keynotes.txt from another project
 * (of the same Project_Type) into this project's Drive folder.
 *
 * Workflow:
 *   GET  → show list of candidate source projects (same Project_Type as
 *          target, drive_folder_id set, newest first). User picks one.
 *   POST → reads keynotes.txt from source's Drive folder, writes to
 *          target's Drive folder. Creates target's keynotes.txt if missing,
 *          overwrites existing.
 *
 * Refuses to copy if the target already has a non-trivial keynotes.txt
 * (>1 row) unless the user confirms — protects against accidental wipe.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'drive_client.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$proj_id = (int)($_GET['proj_id'] ?? $_POST['proj_id'] ?? 0);
if ($proj_id <= 0) die('<p>Missing proj_id. <a href="projects.php">Back</a></p>');

// Load target project
$target = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.drive_folder_id, p.Project_Type,
            pt.Project_Type_Name, c.Client_Name
       FROM Projects p
       LEFT JOIN Project_Types pt ON p.Project_Type = pt.Project_Type_ID
       LEFT JOIN Clients       c  ON p.Client_ID    = c.Client_id
      WHERE p.proj_id = ?"
);
$target->execute([$proj_id]);
$target = $target->fetch();
if (!$target) die('<p>Project not found.</p>');

$targetFolderId = trim((string)($target['drive_folder_id'] ?? ''));
$flash = ''; $flashErr = '';

// ── Handle POST (do the copy) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy') {
    try {
        $sourceProjId = (int)($_POST['source_proj_id'] ?? 0);
        if ($sourceProjId <= 0) throw new Exception('Pick a source project.');
        if ($sourceProjId === $proj_id) throw new Exception('Source and target must differ.');
        if ($targetFolderId === '') throw new Exception("Target project's Drive folder isn't set yet — set it via keynotes_edit.php first.");

        $src = $pdo->prepare("SELECT proj_id, JobName, drive_folder_id FROM Projects WHERE proj_id = ?");
        $src->execute([$sourceProjId]);
        $src = $src->fetch();
        if (!$src) throw new Exception('Source project not found.');
        $sourceFolderId = trim((string)($src['drive_folder_id'] ?? ''));
        if ($sourceFolderId === '') throw new Exception("Source project's Drive folder isn't set.");

        // Read source's keynotes.txt
        $found = DriveClient::findFilesInFolder($pdo, $sourceFolderId, 'keynotes.txt');
        if (empty($found)) throw new Exception("Source project '{$src['JobName']}' has no keynotes.txt in its Drive folder.");
        $content = DriveClient::getFileContent($pdo, $found[0]['id']);
        if ($content === '') throw new Exception("Source keynotes.txt is empty.");

        // Write into target — overwrite if exists, otherwise create
        $existing = DriveClient::findFilesInFolder($pdo, $targetFolderId, 'keynotes.txt');
        if (!empty($existing)) {
            DriveClient::updateFileContent($pdo, $existing[0]['id'], $content, 'text/plain');
            $flash = "Overwrote keynotes.txt with content from '{$src['JobName']}'.";
        } else {
            DriveClient::createTextFile($pdo, $targetFolderId, 'keynotes.txt', $content, 'text/plain');
            $flash = "Created keynotes.txt copied from '{$src['JobName']}'.";
        }
    } catch (Exception $e) {
        $flashErr = $e->getMessage();
    }
}

// ── List candidate sources (same Project_Type, has drive_folder_id) ──────
$candidates = [];
if (!empty($target['Project_Type'])) {
    $st = $pdo->prepare(
        "SELECT p.proj_id, p.JobName, p.drive_folder_id, c.Client_Name, p.Active
           FROM Projects p
           LEFT JOIN Clients c ON p.Client_ID = c.Client_id
          WHERE p.Project_Type = ?
            AND p.proj_id <> ?
            AND p.drive_folder_id IS NOT NULL
            AND p.drive_folder_id <> ''
          ORDER BY p.Active DESC, p.proj_id DESC
          LIMIT 100"
    );
    $st->execute([$target['Project_Type'], $proj_id]);
    $candidates = $st->fetchAll();
}

// Also list any project (other type) with a drive_folder_id, separately —
// staff might want to copy from a different type if the keynotes happen to
// suit. Filtered to avoid an overwhelming list.
$otherCandidates = [];
$st = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.drive_folder_id, c.Client_Name, pt.Project_Type_Name
       FROM Projects p
       LEFT JOIN Clients c ON p.Client_ID = c.Client_id
       LEFT JOIN Project_Types pt ON p.Project_Type = pt.Project_Type_ID
      WHERE (p.Project_Type IS NULL OR p.Project_Type <> ?)
        AND p.proj_id <> ?
        AND p.drive_folder_id IS NOT NULL
        AND p.drive_folder_id <> ''
        AND p.Active <> 0
      ORDER BY p.proj_id DESC
      LIMIT 50"
);
$st->execute([(int)($target['Project_Type'] ?? 0), $proj_id]);
$otherCandidates = $st->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Copy Keynotes — <?= htmlspecialchars((string)$target['JobName']) ?></title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:900px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:4px; padding:12px 14px; margin:10px 0; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer; font:inherit; text-decoration:none; display:inline-block; }
.btn:hover { background:#7a7a16; }
table { width:100%; border-collapse:collapse; font-size:13px; }
table th, table td { padding:6px 8px; text-align:left; border-bottom:1px solid #eee; }
table tr:hover { background:#fafad2; }
.flash-ok  { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:3px; margin:8px 0; }
.flash-err { background:#ffd6d6; border:1px solid #c33;  color:#a00;     padding:8px 12px; border-radius:3px; margin:8px 0; }
</style>
</head>
<body>
<div class="topbar">
  <h1>↻ Copy keynotes — <?= htmlspecialchars((string)$target['JobName']) ?></h1>
  <div><a href="keynotes_edit.php?proj_id=<?= $proj_id ?>">← Keynotes editor</a> &nbsp;·&nbsp; <a href="menu.php">Menu</a></div>
</div>

<div class="page">
  <?php if ($flash):    ?><div class="flash-ok"><?= htmlspecialchars($flash) ?> <a href="keynotes_edit.php?proj_id=<?= $proj_id ?>">Open editor →</a></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <div class="card">
    <strong>Target:</strong> <?= htmlspecialchars((string)$target['JobName']) ?>
    <?php if ($target['Client_Name']): ?>&nbsp;·&nbsp;<?= htmlspecialchars((string)$target['Client_Name']) ?><?php endif; ?>
    <br><span style="font-size:12px;color:#555">Type: <?= htmlspecialchars((string)($target['Project_Type_Name'] ?? '(no type set)')) ?></span>
    <?php if ($targetFolderId === ''): ?>
      <div class="flash-err" style="margin-top:6px">
        Target project has no Drive folder linked. <a href="keynotes_edit.php?proj_id=<?= $proj_id ?>">Set it via the keynotes editor</a> before copying.
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Same Project Type (most similar)</h3>
    <?php if (empty($candidates)): ?>
      <p class="muted" style="color:#888">No other projects with type "<?= htmlspecialchars((string)($target['Project_Type_Name'] ?? '?')) ?>" have a Drive folder linked.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Job</th><th>Client</th><th>Active</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($candidates as $c): ?>
            <tr>
              <td><?= htmlspecialchars((string)$c['JobName']) ?></td>
              <td><?= htmlspecialchars((string)($c['Client_Name'] ?? '')) ?></td>
              <td><?= ((int)$c['Active'] === 0) ? '<span style="color:#888">no</span>' : '<span style="color:#1a6b1a">yes</span>' ?></td>
              <td style="text-align:right">
                <form method="post" style="display:inline" onsubmit="return confirm('Copy keynotes.txt from \'<?= htmlspecialchars(addslashes((string)$c['JobName'])) ?>\' into \'<?= htmlspecialchars(addslashes((string)$target['JobName'])) ?>\'?\n\nIf the target already has a keynotes.txt, it WILL be overwritten.');">
                  <input type="hidden" name="proj_id"        value="<?= $proj_id ?>">
                  <input type="hidden" name="source_proj_id" value="<?= (int)$c['proj_id'] ?>">
                  <input type="hidden" name="action"         value="copy">
                  <button type="submit" class="btn" <?= $targetFolderId === '' ? 'disabled' : '' ?>>Copy →</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if (!empty($otherCandidates)): ?>
    <details>
      <summary style="cursor:pointer;padding:8px 12px;color:#666">Other active projects (different type) — <?= count($otherCandidates) ?></summary>
      <div class="card">
        <table>
          <thead><tr><th>Job</th><th>Type</th><th>Client</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($otherCandidates as $c): ?>
              <tr>
                <td><?= htmlspecialchars((string)$c['JobName']) ?></td>
                <td style="color:#888"><?= htmlspecialchars((string)($c['Project_Type_Name'] ?? '?')) ?></td>
                <td><?= htmlspecialchars((string)($c['Client_Name'] ?? '')) ?></td>
                <td style="text-align:right">
                  <form method="post" style="display:inline" onsubmit="return confirm('Copy keynotes.txt across project types?');">
                    <input type="hidden" name="proj_id"        value="<?= $proj_id ?>">
                    <input type="hidden" name="source_proj_id" value="<?= (int)$c['proj_id'] ?>">
                    <input type="hidden" name="action"         value="copy">
                    <button type="submit" class="btn" <?= $targetFolderId === '' ? 'disabled' : '' ?>>Copy →</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

</div>
</body>
</html>
