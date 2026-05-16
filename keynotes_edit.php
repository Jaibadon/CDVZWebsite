<?php
/**
 * keynotes_edit.php?proj_id=N — Revit keynotes.txt editor.
 *
 * Revit's keynotes.txt format (TSV):
 *
 *   <category-name>           <TAB>                                                       (category header row)
 *   <code>                    <TAB>   <description>   <TAB>   <category-name>             (item row)
 *
 * So each "section" = one category-name header + N items whose third column
 * references that category. The editor groups items under their categories
 * visually.
 *
 * ENCODING: Revit writes keynotes.txt as UTF-16 LE with a BOM. We detect the
 * source encoding on read, hold all editing in UTF-8 internally, and write
 * back in whatever encoding the file originally had. Drive doesn't transcode;
 * it stores the bytes we give it.
 *
 * Line endings: CRLF on save (Revit's tools write CRLF; we follow suit).
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'drive_client.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) die('<p>Missing proj_id. <a href="projects.php">Back to projects</a></p>');

$proj = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, p.drive_folder_id, p.Project_Type, c.Client_Name
       FROM Projects p
       LEFT JOIN Clients c ON p.Client_ID = c.Client_id
      WHERE p.proj_id = ?"
);
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('<p>Project not found. <a href="projects.php">Back</a></p>');

$folderId = trim((string)($proj['drive_folder_id'] ?? ''));
$flash = ''; $flashErr = '';

// ── Encoding helpers ─────────────────────────────────────────────────────
// Revit keynote files are usually UTF-16 LE with BOM. We detect on read and
// roundtrip the same encoding on write.

function detect_text_encoding(string $content): string {
    if (substr($content, 0, 2) === "\xFF\xFE") return 'UTF-16LE';
    if (substr($content, 0, 2) === "\xFE\xFF") return 'UTF-16BE';
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") return 'UTF-8-BOM';
    // Heuristic for BOM-less UTF-16: ASCII text is mostly < 0x80; in UTF-16 LE
    // every odd byte is 0x00 for ASCII chars. Check the first 64 bytes.
    $len = min(64, strlen($content));
    if ($len >= 4) {
        $zeros = 0;
        for ($i = 1; $i < $len; $i += 2) {
            if ($content[$i] === "\x00") $zeros++;
        }
        if ($zeros >= ($len / 2 - 2)) return 'UTF-16LE';
    }
    return 'UTF-8';
}

// Encoding conversion with graceful fallback: mbstring → iconv → (last
// resort) naive UTF-16LE<->UTF-8 byte juggling for the BMP-ASCII case.
// The host that had the Python/glibc trouble may also be missing mbstring,
// so we don't hard-depend on it.
function kn_convert(string $bytes, string $from, string $to): ?string {
    if (function_exists('mb_convert_encoding')) {
        $r = @mb_convert_encoding($bytes, $to, $from);
        if ($r !== false) return $r;
    }
    if (function_exists('iconv')) {
        $r = @iconv($from, $to . '//TRANSLIT', $bytes);
        if ($r !== false) return $r;
    }
    return null;
}

function text_to_utf8(string $content, string $encoding): string {
    switch ($encoding) {
        case 'UTF-16LE':
            $body = (substr($content, 0, 2) === "\xFF\xFE") ? substr($content, 2) : $content;
            $r = kn_convert($body, 'UTF-16LE', 'UTF-8');
            // Last-resort: strip the high zero byte of each UTF-16LE code
            // unit (correct only for U+0000–U+00FF, fine for the ASCII
            // codes + tabs Revit keynote files actually contain).
            if ($r === null) $r = preg_replace('/\x00/', '', $body);
            return (string)$r;
        case 'UTF-16BE':
            $body = (substr($content, 0, 2) === "\xFE\xFF") ? substr($content, 2) : $content;
            $r = kn_convert($body, 'UTF-16BE', 'UTF-8');
            if ($r === null) $r = preg_replace('/\x00/', '', $body);
            return (string)$r;
        case 'UTF-8-BOM':
            return substr($content, 3);
        default:
            return $content;
    }
}

function text_from_utf8(string $content, string $encoding): string {
    switch ($encoding) {
        case 'UTF-16LE':
            $r = kn_convert($content, 'UTF-8', 'UTF-16LE');
            if ($r === null) {  // naive: interleave a zero hi-byte per ASCII char
                $r = '';
                $len = strlen($content);
                for ($i = 0; $i < $len; $i++) { $r .= $content[$i] . "\x00"; }
            }
            return "\xFF\xFE" . $r;
        case 'UTF-16BE':
            $r = kn_convert($content, 'UTF-8', 'UTF-16BE');
            if ($r === null) {
                $r = '';
                $len = strlen($content);
                for ($i = 0; $i < $len; $i++) { $r .= "\x00" . $content[$i]; }
            }
            return "\xFE\xFF" . $r;
        case 'UTF-8-BOM':
            return "\xEF\xBB\xBF" . $content;
        default:
            return $content;
    }
}

// ── Handle: setting Drive folder for this project ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_folder') {
    $input = (string)($_POST['drive_folder_input'] ?? '');
    $extracted = DriveClient::extractFolderId($input);
    if (!$extracted) {
        $flashErr = "Couldn't parse a Drive folder ID. Paste the folder URL (e.g. https://drive.google.com/drive/folders/1AbCdEf…) or the bare ID.";
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

// ── Handle: saving the keynotes back to Drive ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_keynotes') {
    try {
        if ($folderId === '') throw new Exception('No Drive folder set for this project.');

        $sections = $_POST['section'] ?? [];
        $sourceEncoding = (string)($_POST['source_encoding'] ?? 'UTF-16LE');

        // Reconstruct the file. Categories come first in each section,
        // followed by their items. Sections appear in the order they were
        // submitted (PHP preserves the array order of POST input).
        $lines = [];
        foreach ($sections as $sec) {
            $catName = trim((string)($sec['name'] ?? ''));
            if ($catName !== '') {
                // Revit category header row: "<name>\t" (one tab, empty desc)
                $lines[] = $catName . "\t";
            }
            $codes = $sec['item_code'] ?? [];
            $descs = $sec['item_desc'] ?? [];
            for ($i = 0; $i < count($codes); $i++) {
                $code = trim((string)($codes[$i] ?? ''));
                $desc = trim((string)($descs[$i] ?? ''));
                if ($code === '' && $desc === '') continue;
                // Item row: <code>\t<desc>\t<category-name>
                $lines[] = $code . "\t" . $desc . "\t" . $catName;
            }
        }

        // Also handle uncategorised items (a "Loose items" section if posted)
        $orphanCodes = $_POST['orphan_code'] ?? [];
        $orphanDescs = $_POST['orphan_desc'] ?? [];
        for ($i = 0; $i < count($orphanCodes); $i++) {
            $code = trim((string)($orphanCodes[$i] ?? ''));
            $desc = trim((string)($orphanDescs[$i] ?? ''));
            if ($code === '' && $desc === '') continue;
            $lines[] = $code . "\t" . $desc;
        }

        $utf8 = implode("\r\n", $lines) . "\r\n";
        $bytes = text_from_utf8($utf8, $sourceEncoding);

        $found = DriveClient::findFilesInFolder($pdo, $folderId, 'keynotes.txt');
        if (!empty($found)) {
            DriveClient::updateFileContent($pdo, $found[0]['id'], $bytes, 'text/plain');
            $flash = 'Saved keynotes.txt to Drive (' . count($lines) . ' rows, encoding: ' . $sourceEncoding . ').';
        } else {
            $newId = DriveClient::createTextFile($pdo, $folderId, 'keynotes.txt', $bytes, 'text/plain');
            $flash = 'Created new keynotes.txt in Drive (' . count($lines) . " rows, encoding: $sourceEncoding, id $newId).";
        }
    } catch (Exception $e) {
        $flashErr = 'Save failed: ' . $e->getMessage();
    }
}

// ── Load current keynotes.txt for display ───────────────────────────────
$sections = [];        // [['name' => 'BUILDING WRAP/RAB (WALL)', 'items' => [['code','description'], ...]]]
$orphans  = [];        // items with no parent
$sourceEncoding = 'UTF-16LE';   // default for Revit (matches what staff are likely to upload)
$loadErr = '';
$fileMeta = null;
$rawByteCount = 0;

if ($folderId !== '' && DriveClient::isConfigured() && DriveClient::isConnected($pdo)) {
    try {
        $found = DriveClient::findFilesInFolder($pdo, $folderId, 'keynotes.txt');
        if (!empty($found)) {
            $fileMeta = $found[0];
            $raw = DriveClient::getFileContent($pdo, $fileMeta['id']);
            $rawByteCount = strlen($raw);
            $sourceEncoding = detect_text_encoding($raw);
            $utf8 = text_to_utf8($raw, $sourceEncoding);

            // Parse into sections. Walk lines; whenever we hit a category row,
            // start a new section. Items get appended to the current section.
            $lines = preg_split("/\r\n|\n|\r/", $utf8);
            $currentSection = null;
            $categoryByName = []; // dedupe — multiple files sometimes repeat a header
            foreach ($lines as $line) {
                if ($line === '' || preg_match('/^\s*#/', $line)) continue;
                $parts = explode("\t", $line);
                $code = isset($parts[0]) ? trim($parts[0]) : '';
                $desc = isset($parts[1]) ? trim($parts[1]) : '';
                $parent = isset($parts[2]) ? trim($parts[2]) : '';

                // Category row: code present, no description, no parent
                $isCategory = ($code !== '' && $desc === '' && $parent === '');
                if ($isCategory) {
                    if (isset($categoryByName[$code])) {
                        // Already have this category — re-enter it instead of duplicating
                        $currentSection = &$sections[$categoryByName[$code]];
                    } else {
                        $sections[] = ['name' => $code, 'items' => []];
                        $newIdx = count($sections) - 1;
                        $categoryByName[$code] = $newIdx;
                        $currentSection = &$sections[$newIdx];
                    }
                    continue;
                }

                // Item row
                $item = ['code' => $code, 'description' => $desc];
                if ($parent !== '') {
                    // Find or create the matching section
                    if (!isset($categoryByName[$parent])) {
                        $sections[] = ['name' => $parent, 'items' => []];
                        $categoryByName[$parent] = count($sections) - 1;
                    }
                    $sections[$categoryByName[$parent]]['items'][] = $item;
                } elseif ($currentSection !== null) {
                    // Inherit from the current section context
                    $currentSection['items'][] = $item;
                } else {
                    $orphans[] = $item;
                }
            }
            unset($currentSection);
        }
    } catch (Exception $e) {
        $loadErr = $e->getMessage();
    }
}

// Count summary for display
$totalItems = array_sum(array_map(fn($s) => count($s['items']), $sections)) + count($orphans);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Keynotes — <?= htmlspecialchars((string)$proj['JobName']) ?></title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:1200px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:4px; padding:12px 14px; margin:10px 0; }
.action-bar { position:sticky; top:0; z-index:10; background:#fafafa; padding:10px 0; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer; font:inherit; text-decoration:none; display:inline-block; }
.btn:hover { background:#7a7a16; }
.btn-secondary { background:#555; }
.btn-success   { background:#1a6b1a; }
.btn-danger    { background:#c33; color:#fff; border:none; padding:2px 8px; border-radius:3px; cursor:pointer; font-size:11px; }
.flash-ok  { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:3px; margin:8px 0; }
.flash-err { background:#ffd6d6; border:1px solid #c33;  color:#a00;     padding:8px 12px; border-radius:3px; margin:8px 0; }
.section { background:#fff; border:1px solid #ddd; border-radius:4px; margin:10px 0; }
.section-header { background:#f6f6e2; padding:8px 12px; border-bottom:1px solid #d3d3a5; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.section-header .cat-name { flex:1; font-size:14px; font-weight:600; color:#444; padding:4px 8px; border:1px solid transparent; background:transparent; font-family:inherit; }
.section-header .cat-name:focus { background:#fff; border-color:#c8a52e; outline:none; }
.section-meta { font-size:11px; color:#888; }
.section-body { padding:6px 12px 10px; }
.items-table { width:100%; border-collapse:collapse; font-size:13px; }
.items-table td { padding:2px; }
.items-table input[type="text"] { width:100%; box-sizing:border-box; border:1px solid transparent; padding:4px 6px; background:transparent; font:inherit; }
.items-table input[type="text"]:focus { background:#fff8e0; border-color:#c8a52e; outline:none; }
.code-cell { width:120px; font-family:Consolas,Menlo,monospace; }
.code-cell input { font-family:Consolas,Menlo,monospace; }
.del-cell { width:40px; text-align:right; }
.add-item-btn { margin-top:6px; background:#666; color:#fff; border:none; padding:3px 10px; border-radius:3px; cursor:pointer; font-size:11px; }
.add-item-btn:hover { background:#444; }
.nav-toc { position:sticky; top:60px; max-height:calc(100vh - 80px); overflow-y:auto; }
.nav-toc a { display:block; padding:3px 8px; color:#555; text-decoration:none; font-size:12px; border-left:3px solid transparent; }
.nav-toc a:hover { background:#f0f0f0; border-left-color:#c8a52e; }
.layout { display:grid; grid-template-columns:240px 1fr; gap:16px; align-items:start; }
@media (max-width:900px) { .layout { grid-template-columns:1fr; } .nav-toc { position:static; max-height:none; } }
.search-box { width:100%; padding:5px 8px; box-sizing:border-box; border:1px solid #ddd; border-radius:3px; font:inherit; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📋 Keynotes — <?= htmlspecialchars((string)$proj['JobName']) ?></h1>
  <div>
    <a href="project_stages.php?proj_id=<?= $proj_id ?>">← Stages</a>
    &nbsp;·&nbsp;
    <a href="keynotes_copy.php?proj_id=<?= $proj_id ?>">↻ Copy from similar</a>
    &nbsp;·&nbsp;
    <a href="menu.php">Menu</a>
  </div>
</div>

<div class="page">

  <?php if ($flash):    ?><div class="flash-ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <?php if (!DriveClient::isConfigured()): ?>
    <div class="card" style="background:#fff3cd;border-color:#c8a52e;color:#7a5a00">
      <strong>Google Drive isn't configured.</strong> Admin: see <code>config.cadviz.sample.php</code>, set <code>GOOGLE_OAUTH_DRIVE_REDIRECT_URI</code>, run <code>migrations/add_drive_oauth.sql</code>, then connect via the main menu.
    </div>
  <?php elseif (!DriveClient::isConnected($pdo)): ?>
    <div class="card" style="background:#fff3cd;border-color:#c8a52e;color:#7a5a00">
      <strong>Google Drive isn't connected.</strong>
      <?php if (in_array($user, ['erik','jen'], true)): ?>
        <a href="drive_oauth_connect.php" class="btn">Connect Google Drive</a>
      <?php else: ?>
        Ask Erik or Jen to connect it from the main menu.
      <?php endif; ?>
    </div>
  <?php elseif ($folderId === ''): ?>
    <div class="card" style="background:#fff3cd;border-color:#c8a52e;color:#7a5a00">
      <strong>This project doesn't have a Drive folder set yet.</strong>
      <p style="margin:6px 0">Paste the project folder URL or ID below. URLs look like
        <code>https://drive.google.com/drive/folders/1AbCdEf…</code></p>
      <form method="post">
        <input type="hidden" name="action" value="set_folder">
        <input type="text" name="drive_folder_input" placeholder="https://drive.google.com/drive/folders/…"
               style="width:70%;padding:6px;font-size:13px" required>
        <button type="submit" class="btn">Link folder</button>
      </form>
    </div>
  <?php else: ?>

    <div class="card" style="font-size:12px;color:#555">
      <strong>Drive folder:</strong> <a href="https://drive.google.com/drive/folders/<?= urlencode($folderId) ?>" target="_blank" style="font-family:Consolas,Menlo,monospace"><?= htmlspecialchars($folderId) ?> ↗</a>
      <?php if ($fileMeta): ?>
        &nbsp;·&nbsp;<strong>keynotes.txt</strong> <span style="color:#888">(<?= number_format($rawByteCount) ?> bytes, encoding detected: <strong><?= htmlspecialchars($sourceEncoding) ?></strong>, last modified <?= htmlspecialchars((string)($fileMeta['modifiedTime'] ?? '?')) ?>)</span>
      <?php else: ?>
        &nbsp;·&nbsp;<span style="color:#a00">No keynotes.txt yet — saving will create one (UTF-16 LE, the Revit default).</span>
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

    <form method="post" id="kn-form">
      <input type="hidden" name="action"           value="save_keynotes">
      <input type="hidden" name="source_encoding"  value="<?= htmlspecialchars($sourceEncoding) ?>">

      <div class="action-bar">
        <div>
          <button type="button" class="btn" onclick="addSection()">+ Add category</button>
          <button type="submit" class="btn btn-success">💾 Save to Drive</button>
          <span style="margin-left:14px;font-size:12px;color:#666">
            <span id="section-count"><?= count($sections) ?></span> categories,
            <span id="item-count"><?= $totalItems ?></span> items
          </span>
        </div>
        <input type="text" id="search" class="search-box" style="width:280px" placeholder="🔍 Filter items (code or description)…">
      </div>

      <div class="layout">

        <!-- Sidebar: jump-to-category -->
        <div>
          <div class="nav-toc card" style="padding:6px">
            <strong style="font-size:11px;color:#888;padding:4px 8px;display:block">CATEGORIES</strong>
            <div id="toc">
              <?php foreach ($sections as $i => $s): ?>
                <a href="#sec-<?= $i ?>" data-sec="<?= $i ?>"><?= htmlspecialchars($s['name']) ?> <span style="color:#aaa">(<?= count($s['items']) ?>)</span></a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Main editing area -->
        <div>
          <div id="sections">
            <?php foreach ($sections as $i => $s): ?>
              <div class="section" id="sec-<?= $i ?>" data-idx="<?= $i ?>">
                <div class="section-header">
                  <input type="text" name="section[<?= $i ?>][name]" value="<?= htmlspecialchars($s['name']) ?>" class="cat-name" placeholder="Category name">
                  <span class="section-meta"><span class="item-count"><?= count($s['items']) ?></span> items</span>
                  <button type="button" class="btn-danger" onclick="rmSection(this)" title="Delete this entire category">×</button>
                </div>
                <div class="section-body">
                  <table class="items-table">
                    <thead>
                      <tr style="font-size:11px;color:#888"><th class="code-cell" style="text-align:left">Code</th><th style="text-align:left">Description</th><th></th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($s['items'] as $it): ?>
                        <tr>
                          <td class="code-cell"><input type="text" name="section[<?= $i ?>][item_code][]" value="<?= htmlspecialchars($it['code']) ?>"></td>
                          <td><input type="text" name="section[<?= $i ?>][item_desc][]" value="<?= htmlspecialchars($it['description']) ?>"></td>
                          <td class="del-cell"><button type="button" class="btn-danger" onclick="rmItem(this)">×</button></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <button type="button" class="add-item-btn" onclick="addItem(this)">+ Add item to this category</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (!empty($orphans)): ?>
            <div class="section" id="orphans">
              <div class="section-header" style="background:#fff3cd;border-bottom-color:#c8a52e">
                <strong style="color:#7a5a00">Uncategorised (no parent reference)</strong>
                <span class="section-meta"><?= count($orphans) ?> items</span>
              </div>
              <div class="section-body">
                <table class="items-table">
                  <thead><tr style="font-size:11px;color:#888"><th class="code-cell" style="text-align:left">Code</th><th style="text-align:left">Description</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($orphans as $it): ?>
                      <tr>
                        <td class="code-cell"><input type="text" name="orphan_code[]" value="<?= htmlspecialchars($it['code']) ?>"></td>
                        <td><input type="text" name="orphan_desc[]" value="<?= htmlspecialchars($it['description']) ?>"></td>
                        <td class="del-cell"><button type="button" class="btn-danger" onclick="rmItem(this)">×</button></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <div style="margin:20px 0;text-align:center">
            <button type="button" class="btn" onclick="addSection()">+ Add another category</button>
          </div>
        </div>
      </div>
    </form>

    <p style="margin-top:20px;font-size:11px;color:#888">
      Saves write back to Drive as <code>keynotes.txt</code> (UTF-16 LE with BOM, CRLF line endings — Revit's expected format). After save, in Revit:
      Annotate → Keynote → Settings → Reload keynote table.
    </p>

    <script>
    var nextSectionIdx = <?= count($sections) ?>;

    function addSection() {
      var idx = nextSectionIdx++;
      var div = document.createElement('div');
      div.className = 'section';
      div.id = 'sec-' + idx;
      div.dataset.idx = idx;
      div.innerHTML = `
        <div class="section-header">
          <input type="text" name="section[${idx}][name]" value="" class="cat-name" placeholder="New Category" autofocus>
          <span class="section-meta"><span class="item-count">0</span> items</span>
          <button type="button" class="btn-danger" onclick="rmSection(this)">×</button>
        </div>
        <div class="section-body">
          <table class="items-table">
            <thead>
              <tr style="font-size:11px;color:#888"><th class="code-cell" style="text-align:left">Code</th><th style="text-align:left">Description</th><th></th></tr>
            </thead>
            <tbody>
              <tr>
                <td class="code-cell"><input type="text" name="section[${idx}][item_code][]" value=""></td>
                <td><input type="text" name="section[${idx}][item_desc][]" value=""></td>
                <td class="del-cell"><button type="button" class="btn-danger" onclick="rmItem(this)">×</button></td>
              </tr>
            </tbody>
          </table>
          <button type="button" class="add-item-btn" onclick="addItem(this)">+ Add item to this category</button>
        </div>
      `;
      document.getElementById('sections').appendChild(div);
      var nameInput = div.querySelector('.cat-name');
      if (nameInput) nameInput.focus();
      refreshTOC();
      updateCounts();
    }

    function rmSection(btn) {
      var section = btn.closest('.section');
      var name = section.querySelector('.cat-name');
      var label = name ? name.value : 'this category';
      var itemCount = section.querySelectorAll('input[name*="item_code"]').length;
      if (itemCount > 0 && !confirm('Delete category "' + label + '" and all ' + itemCount + ' items inside?')) return;
      section.remove();
      refreshTOC();
      updateCounts();
    }

    function addItem(btn) {
      var section = btn.closest('.section');
      var idx = section.dataset.idx;
      var tbody = section.querySelector('.items-table tbody');
      var tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="code-cell"><input type="text" name="section[${idx}][item_code][]" value=""></td>
        <td><input type="text" name="section[${idx}][item_desc][]" value=""></td>
        <td class="del-cell"><button type="button" class="btn-danger" onclick="rmItem(this)">×</button></td>
      `;
      tbody.appendChild(tr);
      tr.querySelector('input').focus();
      updateCounts();
    }

    function rmItem(btn) {
      btn.closest('tr').remove();
      updateCounts();
    }

    function refreshTOC() {
      var toc = document.getElementById('toc');
      toc.innerHTML = '';
      document.querySelectorAll('#sections .section').forEach(function(sec, i) {
        var name = sec.querySelector('.cat-name').value || '(unnamed)';
        var count = sec.querySelectorAll('input[name*="item_code"]').length;
        var a = document.createElement('a');
        a.href = '#' + sec.id;
        a.innerHTML = escapeHtml(name) + ' <span style="color:#aaa">(' + count + ')</span>';
        toc.appendChild(a);
      });
    }

    function updateCounts() {
      var sections = document.querySelectorAll('#sections .section');
      var sectionCount = sections.length;
      var totalItems = 0;
      sections.forEach(function(sec) {
        var n = sec.querySelectorAll('input[name*="item_code"]').length;
        totalItems += n;
        var meta = sec.querySelector('.item-count');
        if (meta) meta.textContent = n;
      });
      var orphanCount = document.querySelectorAll('input[name="orphan_code[]"]').length;
      totalItems += orphanCount;
      document.getElementById('section-count').textContent = sectionCount;
      document.getElementById('item-count').textContent = totalItems;
    }

    function escapeHtml(s) {
      return s.replace(/[&<>"']/g, function(c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
      });
    }

    // Live category-name updates → refresh TOC
    document.addEventListener('input', function(e) {
      if (e.target.matches('.cat-name')) refreshTOC();
    });

    // Filter: hide items whose code or description doesn't match
    document.getElementById('search').addEventListener('input', function(e) {
      var q = e.target.value.trim().toLowerCase();
      document.querySelectorAll('#sections .items-table tbody tr').forEach(function(tr) {
        var inputs = tr.querySelectorAll('input[type="text"]');
        var hay = '';
        inputs.forEach(function(i) { hay += ' ' + i.value.toLowerCase(); });
        tr.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
      });
    });
    </script>
  <?php endif; ?>
</div>
</body>
</html>
