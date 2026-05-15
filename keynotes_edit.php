<?php
/**
 * keynotes_edit.php?proj_id=N — Revit keynotes.txt editor.
 *
 * Loads the project's Drive folder (Projects.drive_folder_id), looks for
 * a `keynotes.txt` file inside it, parses the tab-separated rows into an
 * editable table. Saving writes the table back out as keynotes.txt via
 * the Drive API.
 *
 * Revit keynote file format: tab-separated, no header.
 *   <code>    <description>    [<parent_code>]
 *
 * The optional third column declares the parent code explicitly (Revit
 * lets you use it instead of inferring hierarchy from dotted codes). We
 * preserve whatever column count the file uses; on save we mirror the
 * original's structure.
 *
 * If the project doesn't have a drive_folder_id set, we prompt to set
 * one (pasted Drive URL → extracted folder ID). Edits the column on
 * Projects directly so a separate "project settings" page isn't needed.
 *
 * Permissioning: any logged-in user can view + edit. The Revit keynotes
 * file is a project deliverable, not sensitive financial data.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'drive_client.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) {
    die('<p>Missing proj_id. <a href="projects.php">Back to projects</a></p>');
}

// ── Load project + Drive folder ID ───────────────────────────────────────
$proj = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.drive_folder_id, p.Project_Type,
            c.Client_Name
       FROM Projects p
       LEFT JOIN Clients c ON p.Client_ID = c.Client_id
      WHERE p.proj_id = ?"
);
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('<p>Project not found. <a href="projects.php">Back</a></p>');

$folderId = trim((string)($proj['drive_folder_id'] ?? ''));

// ── Handle: setting the Drive folder ID for this project ────────────────
$flash = ''; $flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_folder') {
    $input = (string)($_POST['drive_folder_input'] ?? '');
    $extracted = DriveClient::extractFolderId($input);
    if (!$extracted) {
        $flashErr = "Couldn't parse a Drive folder ID out of that. Paste the folder URL (e.g. https://drive.google.com/drive/folders/1AbCdEf…) or the bare ID.";
    } else {
        try {
            $pdo->prepare("UPDATE Projects SET drive_folder_id = ? WHERE proj_id = ?")
                ->execute([$extracted, $proj_id]);
            $folderId = $extracted;
            $flash = 'Drive folder linked.';
        } catch (Exception $e) {
            $flashErr = 'Save failed: ' . $e->getMessage();
        }
    }
}

// ── Handle: saving the keynotes table back to Drive ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_keynotes') {
    try {
        if ($folderId === '') throw new Exception('No Drive folder set for this project.');

        // Build tab-separated content. Drop rows where both code AND description are empty.
        $codes = $_POST['code'] ?? [];
        $descs = $_POST['description'] ?? [];
        $parents = $_POST['parent'] ?? [];
        $hasParentCol = !empty($_POST['has_parent_col']);

        $lines = [];
        for ($i = 0; $i < count($codes); $i++) {
            $code = trim((string)($codes[$i] ?? ''));
            $desc = trim((string)($descs[$i] ?? ''));
            if ($code === '' && $desc === '') continue;
            if ($hasParentCol) {
                $parent = trim((string)($parents[$i] ?? ''));
                $lines[] = $code . "\t" . $desc . "\t" . $parent;
            } else {
                $lines[] = $code . "\t" . $desc;
            }
        }
        // Sort by code so the saved file has a stable order — Revit doesn't
        // require this but it makes future diffs cleaner.
        usort($lines, function($a, $b) {
            $ca = explode("\t", $a, 2)[0];
            $cb = explode("\t", $b, 2)[0];
            return strnatcasecmp($ca, $cb);
        });
        $content = implode("\r\n", $lines) . "\r\n"; // CRLF — Revit reads either, but the original tools use CRLF

        // Find existing file or create new
        $found = DriveClient::findFilesInFolder($pdo, $folderId, 'keynotes.txt');
        if (!empty($found)) {
            DriveClient::updateFileContent($pdo, $found[0]['id'], $content, 'text/plain');
            $flash = 'Saved keynotes.txt (' . count($lines) . ' rows) to Drive.';
        } else {
            $newId = DriveClient::createTextFile($pdo, $folderId, 'keynotes.txt', $content, 'text/plain');
            $flash = 'Created new keynotes.txt (' . count($lines) . " rows) in Drive (id $newId).";
        }
    } catch (Exception $e) {
        $flashErr = 'Save failed: ' . $e->getMessage();
    }
}

// ── Load current keynotes.txt content for display ────────────────────────
$rows = [];          // [['code'=>, 'description'=>, 'parent'=>], ...]
$hasParentCol = false;
$loadErr = '';
$fileMeta = null;

if ($folderId !== '' && DriveClient::isConfigured() && DriveClient::isConnected($pdo)) {
    try {
        $found = DriveClient::findFilesInFolder($pdo, $folderId, 'keynotes.txt');
        if (!empty($found)) {
            $fileMeta = $found[0];
            $content = DriveClient::getFileContent($pdo, $fileMeta['id']);
            // Parse — handle CRLF, LF, and tab-separated columns
            $lines = preg_split("/\r\n|\n|\r/", $content);
            foreach ($lines as $line) {
                if ($line === '' || preg_match('/^\s*#/', $line)) continue; // skip blank lines + comment-style lines
                $parts = explode("\t", $line);
                if (count($parts) >= 3) $hasParentCol = true;
                $rows[] = [
                    'code'        => $parts[0] ?? '',
                    'description' => $parts[1] ?? '',
                    'parent'      => $parts[2] ?? '',
                ];
            }
        }
    } catch (Exception $e) {
        $loadErr = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Keynotes — <?= htmlspecialchars((string)$proj['JobName']) ?></title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:1100px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:4px; padding:12px 14px; margin:10px 0; }
table.keynotes { width:100%; border-collapse:collapse; font-size:13px; }
table.keynotes th { background:#eee; padding:6px 8px; text-align:left; border-bottom:1px solid #ccc; position:sticky; top:0; }
table.keynotes td { padding:3px; border-bottom:1px solid #f0f0f0; }
table.keynotes td input { width:100%; box-sizing:border-box; border:1px solid transparent; padding:4px 6px; background:transparent; font:inherit; }
table.keynotes td input:focus { background:#fff8e0; border-color:#c8a52e; outline:none; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer; font:inherit; text-decoration:none; display:inline-block; }
.btn:hover { background:#7a7a16; }
.btn-secondary { background:#555; }
.btn-danger { background:#c33; color:#fff; border:none; padding:2px 8px; border-radius:3px; cursor:pointer; font-size:11px; }
.flash-ok  { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:3px; margin:8px 0; }
.flash-err { background:#ffd6d6; border:1px solid #c33;  color:#a00;     padding:8px 12px; border-radius:3px; margin:8px 0; }
.depth-1 { padding-left:14px !important; }
.depth-2 { padding-left:28px !important; }
.depth-3 { padding-left:42px !important; }
.depth-4 { padding-left:56px !important; }
.row-code { font-family:Consolas,Menlo,monospace; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📋 Keynotes — <?= htmlspecialchars((string)$proj['JobName']) ?></h1>
  <div>
    <a href="project_stages.php?proj_id=<?= $proj_id ?>">← Stages</a>
    &nbsp;·&nbsp;
    <a href="menu.php">Menu</a>
  </div>
</div>

<div class="page">

  <?php if ($flash):    ?><div class="flash-ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <div class="card">
    <strong>Project:</strong> <?= htmlspecialchars((string)$proj['JobName']) ?>
    <?php if ($proj['Client_Name']): ?>&nbsp;·&nbsp;<?= htmlspecialchars((string)$proj['Client_Name']) ?><?php endif; ?>
    &nbsp;·&nbsp;<a href="keynotes_copy.php?proj_id=<?= $proj_id ?>" class="btn btn-secondary" style="font-size:11px;padding:3px 8px">↻ Copy from similar project</a>
  </div>

  <?php if (!DriveClient::isConfigured()): ?>
    <div class="card" style="background:#fff3cd;border-color:#c8a52e;color:#7a5a00">
      <strong>Google Drive isn't configured yet.</strong> An admin needs to:
      <ol>
        <li>Set <code>GOOGLE_OAUTH_DRIVE_REDIRECT_URI</code> in <code>config.php</code> — see <code>config.cadviz.sample.php</code></li>
        <li>Run <code>migrations/add_drive_oauth.sql</code></li>
        <li>Visit menu → Connect Google Drive</li>
      </ol>
    </div>
  <?php elseif (!DriveClient::isConnected($pdo)): ?>
    <div class="card" style="background:#fff3cd;border-color:#c8a52e;color:#7a5a00">
      <strong>Google Drive isn't connected yet.</strong>
      <?php if (in_array($user, ['erik','jen'], true)): ?>
        <a href="drive_oauth_connect.php" class="btn">Connect Google Drive</a>
      <?php else: ?>
        Ask Erik or Jen to connect it via the main menu.
      <?php endif; ?>
    </div>
  <?php elseif ($folderId === ''): ?>
    <!-- Project hasn't had its Drive folder linked yet -->
    <div class="card" style="background:#fff3cd;border-color:#c8a52e;color:#7a5a00">
      <strong>This project doesn't have a Drive folder set yet.</strong>
      <p style="margin:6px 0">Paste the project's Drive folder URL (or just the folder ID) below.
        Open the folder in Drive, copy the URL from the address bar — looks like
        <code>https://drive.google.com/drive/folders/1AbCdEf…</code></p>
      <form method="post">
        <input type="hidden" name="action" value="set_folder">
        <input type="text" name="drive_folder_input" placeholder="https://drive.google.com/drive/folders/…"
               style="width:70%;padding:6px;font-size:13px" required>
        <button type="submit" class="btn">Link folder</button>
      </form>
    </div>
  <?php else: ?>
    <!-- All preconditions met — render the editor -->

    <div class="card" style="font-size:12px;color:#555">
      <strong>Drive folder:</strong> <code><?= htmlspecialchars($folderId) ?></code>
      <a href="https://drive.google.com/drive/folders/<?= urlencode($folderId) ?>" target="_blank" style="font-size:11px">↗ open in Drive</a>
      <?php if ($fileMeta): ?>
        &nbsp;·&nbsp;<strong>keynotes.txt:</strong> <code><?= htmlspecialchars($fileMeta['id']) ?></code>
        <span style="color:#888">(last modified <?= htmlspecialchars((string)($fileMeta['modifiedTime'] ?? '?')) ?>)</span>
      <?php else: ?>
        &nbsp;·&nbsp;<span style="color:#a00">No keynotes.txt yet — saving will create one.</span>
      <?php endif; ?>
      <form method="post" style="display:inline;margin-left:10px">
        <input type="hidden" name="action" value="set_folder">
        <input type="text" name="drive_folder_input" placeholder="Re-link folder…" style="font-size:11px;padding:2px 6px;width:200px">
        <button type="submit" class="btn-danger" style="font-size:10px">Change folder</button>
      </form>
    </div>

    <?php if ($loadErr): ?>
      <div class="flash-err"><strong>Failed to load keynotes.txt:</strong> <?= htmlspecialchars($loadErr) ?></div>
    <?php endif; ?>

    <form method="post" id="keynotes-form">
      <input type="hidden" name="action" value="save_keynotes">
      <input type="hidden" name="has_parent_col" value="<?= $hasParentCol ? '1' : '0' ?>">

      <div style="margin:10px 0;display:flex;justify-content:space-between;align-items:center">
        <div>
          <button type="button" class="btn" onclick="addRow()">+ Add keynote</button>
          <button type="submit" class="btn" style="background:#1a6b1a">💾 Save to Drive</button>
        </div>
        <div style="font-size:11px;color:#666"><span id="row-count"><?= count($rows) ?></span> rows</div>
      </div>

      <table class="keynotes" id="kn-table">
        <thead>
          <tr>
            <th style="width:140px">Code</th>
            <th>Description</th>
            <?php if ($hasParentCol): ?><th style="width:140px">Parent Code</th><?php endif; ?>
            <th style="width:50px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
              $depth = substr_count($row['code'], '.');
              $depthCls = $depth > 0 ? ' class="depth-' . min($depth, 4) . ' row-code"' : ' class="row-code"';
          ?>
            <tr>
              <td<?= $depthCls ?>><input type="text" name="code[]" value="<?= htmlspecialchars($row['code']) ?>" class="row-code"></td>
              <td><input type="text" name="description[]" value="<?= htmlspecialchars($row['description']) ?>"></td>
              <?php if ($hasParentCol): ?><td><input type="text" name="parent[]" value="<?= htmlspecialchars($row['parent']) ?>" class="row-code"></td><?php endif; ?>
              <td><button type="button" class="btn-danger" onclick="rmRow(this)">×</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($rows) === 0): ?>
            <tr>
              <td><input type="text" name="code[]" value="" class="row-code"></td>
              <td><input type="text" name="description[]" value="" placeholder="(empty file — add your first keynote)"></td>
              <?php if ($hasParentCol): ?><td><input type="text" name="parent[]" value="" class="row-code"></td><?php endif; ?>
              <td><button type="button" class="btn-danger" onclick="rmRow(this)">×</button></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div style="margin:10px 0">
        <button type="button" class="btn" onclick="addRow()">+ Add keynote</button>
        <button type="submit" class="btn" style="background:#1a6b1a">💾 Save to Drive</button>
      </div>
    </form>

    <script>
    function addRow() {
      var table = document.getElementById('kn-table').getElementsByTagName('tbody')[0];
      var hasParent = <?= $hasParentCol ? 'true' : 'false' ?>;
      var tr = document.createElement('tr');
      var parentCell = hasParent ? '<td><input type="text" name="parent[]" class="row-code"></td>' : '';
      tr.innerHTML = '<td class="row-code"><input type="text" name="code[]" class="row-code"></td>' +
                     '<td><input type="text" name="description[]"></td>' +
                     parentCell +
                     '<td><button type="button" class="btn-danger" onclick="rmRow(this)">×</button></td>';
      table.appendChild(tr);
      updateCount();
      // Focus the new code input
      tr.querySelector('input').focus();
    }
    function rmRow(btn) {
      btn.closest('tr').remove();
      updateCount();
    }
    function updateCount() {
      document.getElementById('row-count').textContent =
        document.getElementById('kn-table').getElementsByTagName('tbody')[0].rows.length;
    }
    // Indent visualisation on input: re-apply depth class when code changes
    document.getElementById('kn-table').addEventListener('input', function(e) {
      if (e.target.matches('input[name="code[]"]')) {
        var td = e.target.closest('td');
        td.className = 'row-code';
        var depth = (e.target.value.match(/\./g) || []).length;
        if (depth > 0) td.classList.add('depth-' + Math.min(depth, 4));
      }
    });
    </script>

  <?php endif; ?>

  <p style="margin-top:20px;font-size:11px;color:#888">
    Saves write back to the project's Drive folder as <code>keynotes.txt</code>
    (tab-separated). Revit reads this file when staff opens the project. After
    a save, staff need to reload the keynote table in Revit (Annotate → Keynote →
    Settings → Reload) for changes to appear.
  </p>

</div>
</body>
</html>
