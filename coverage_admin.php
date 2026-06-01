<?php
/**
 * coverage_admin.php — where Erik "trains" the model-change → building-code →
 * notify mapping, in two plain steps:
 *
 *   STEP 1  Clause → who to notify   (B1 → structural, C* → fire, E2 → weathertightness…)
 *           stored on NZBC_Clauses.Default_Stakeholder_Roles_CSV
 *   STEP 2  Keynote category → clause (BUILDING WRAP/RAB (WALL) → E2, SLAB/FOUNDATIONS → B1…)
 *           stored in Keynote_Clause_Map
 *
 * The coverage engine joins them on commit: a changed element → its keynote
 * code → category → clause (Step 2) → roles (Step 1) → suggested notifications.
 *
 * To make Step 2 easy, you can "import" the keynote categories from any project
 * whose keynotes are on Drive, and CADViz pre-suggests the clause by reading the
 * clause references already written in the keynote text (e.g. "E2/AS1").
 *
 * Admin only (erik / jen).
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';
require_once __DIR__ . '/dms/drive_client.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
if (!in_array($user, ['erik', 'jen'], true)) { http_response_code(403); die('Admin only. <a href="menu.php">Back</a>'); }

// Canonical stakeholder roles (matches stakeholders.php / the schema).
$ROLES = ['structural','geotech','civil','fire','weathertightness','energy_h1',
          'manufacturer_cladding','manufacturer_joinery','manufacturer_membrane',
          'manufacturer_roofing','manufacturer_other','client','council','consultant','other'];

$flash = ''; $flashErr = '';

// ── Decode + parse a Revit keynotes.txt; detect cited NZBC clauses ───────────
function ca_decode(string $bytes): string {
    if (substr($bytes, 0, 2) === "\xFF\xFE") {
        $b = substr($bytes, 2);
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($b, 'UTF-8', 'UTF-16LE') : preg_replace('/\x00/', '', $b);
    }
    if (substr($bytes, 0, 3) === "\xEF\xBB\xBF") return substr($bytes, 3);
    return $bytes;
}
function ca_parse_keynotes(string $utf8): array {
    $cats = [];   // category => ['codes'=>[], 'descs'=>[]]
    foreach (preg_split("/\r\n|\n|\r/", $utf8) as $line) {
        if ($line === '') continue;
        $p = explode("\t", $line);
        if (count($p) >= 3 && trim($p[2]) !== '') {
            $cat = trim($p[2]);
            if (!isset($cats[$cat])) $cats[$cat] = ['codes' => [], 'descs' => []];
            $cats[$cat]['codes'][] = trim($p[0]);
            $cats[$cat]['descs'][] = trim($p[1]);
        }
    }
    return $cats;
}
function ca_detect_clauses(string $text, array $catalog): array {
    preg_match_all('/\b([A-H])\s?([0-9]{1,2})\b/', strtoupper($text), $m, PREG_SET_ORDER);
    $counts = [];
    foreach ($m as $mm) {
        $code = $mm[1] . $mm[2];
        if (in_array($code, $catalog, true)) $counts[$code] = ($counts[$code] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;   // code => hits, most-cited first
}

// ── POST: Step 1 — clause → roles ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_clause_roles') {
    try {
        $roles = $_POST['roles'] ?? [];   // [clauseCode => [role,...]]
        $upd = $pdo->prepare("UPDATE NZBC_Clauses SET Default_Stakeholder_Roles_CSV = ? WHERE Clause_Code = ?");
        foreach ($roles as $code => $picked) {
            $clean = array_values(array_intersect($ROLES, is_array($picked) ? $picked : []));
            $upd->execute([implode(',', $clean), (string)$code]);
        }
        $_SESSION['cov_flash'] = 'Saved who-to-notify for each clause.';
    } catch (Exception $e) { $_SESSION['cov_flash_err'] = 'Save failed: ' . $e->getMessage(); }
    header('Location: coverage_admin.php'); exit;
}

// ── POST: Step 2 — keynote category → clause ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_keynote_map') {
    try {
        $map = $_POST['map'] ?? [];   // [category => clauseCode | '']
        $del = $pdo->prepare("DELETE FROM Keynote_Clause_Map WHERE Match_Type='category' AND Match_Value=?");
        $ins = $pdo->prepare("INSERT INTO Keynote_Clause_Map (Match_Type, Match_Value, Clause_Code, Created_By, Created_At)
                              VALUES ('category', ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE Active=1");
        foreach ($map as $cat => $clause) {
            $cat = trim((string)$cat); $clause = trim((string)$clause);
            if ($cat === '') continue;
            $del->execute([$cat]);                       // one clause per category (keep it simple)
            if ($clause !== '') $ins->execute([$cat, $clause, $user]);
        }
        $_SESSION['cov_flash'] = 'Saved keynote → clause mappings.';
    } catch (Exception $e) { $_SESSION['cov_flash_err'] = 'Save failed: ' . $e->getMessage(); }
    header('Location: coverage_admin.php' . (isset($_POST['import_proj']) && $_POST['import_proj'] !== '' ? '?import_proj=' . (int)$_POST['import_proj'] : '')); exit;
}

$flash = $_SESSION['cov_flash'] ?? ''; $flashErr = $_SESSION['cov_flash_err'] ?? '';
unset($_SESSION['cov_flash'], $_SESSION['cov_flash_err']);

// ── Load clauses + current mappings ──────────────────────────────────────────
$clauses = $pdo->query("SELECT Clause_Code, Title, Default_Stakeholder_Roles_CSV FROM NZBC_Clauses ORDER BY Clause_Code")->fetchAll(PDO::FETCH_ASSOC);
$catalog = array_column($clauses, 'Clause_Code');

$mapRows = $pdo->query("SELECT Match_Value, Clause_Code FROM Keynote_Clause_Map WHERE Match_Type='category' AND Active=1")->fetchAll(PDO::FETCH_ASSOC);
$mappedClauseByCat = [];
foreach ($mapRows as $r) $mappedClauseByCat[(string)$r['Match_Value']] = (string)$r['Clause_Code'];

// Categories that have actually been seen in commits (live data).
$liveCats = [];
try { $liveCats = $pdo->query("SELECT DISTINCT Category FROM Commit_Keynotes WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN); }
catch (Exception $e) {}

// ── Optional: import categories from a project's keynote file (with suggestions)
$importProj = (int)($_GET['import_proj'] ?? 0);
$imported = [];   // category => ['codes'=>[], 'suggest'=>clause|'', 'why'=>'']
$importErr = '';
if ($importProj > 0) {
    try {
        $pf = $pdo->prepare("SELECT drive_folder_id FROM Projects WHERE proj_id = ?");
        $pf->execute([$importProj]);
        $fid = trim((string)$pf->fetchColumn());
        if ($fid === '') throw new Exception('That project has no Drive folder linked.');
        // Resolve REVIT/ subfolder (case-insensitive) then find keynotes.txt.
        $target = $fid;
        foreach (DriveClient::listFolder($pdo, $fid) as $c) {
            if ((($c['mimeType'] ?? '') === 'application/vnd.google-apps.folder') && strtolower((string)$c['name']) === 'revit') { $target = (string)$c['id']; break; }
        }
        $knFile = null;
        foreach (array_unique([$target, $fid]) as $ff) {
            foreach (DriveClient::listFolder($pdo, $ff) as $f) {
                if (strtolower((string)($f['name'] ?? '')) === 'keynotes.txt') { $knFile = (string)$f['id']; break 2; }
            }
        }
        if (!$knFile) throw new Exception('No keynotes.txt found in that project (looked in REVIT/ and the root).');
        $cats = ca_parse_keynotes(ca_decode(DriveClient::getFileContent($pdo, $knFile)));
        foreach ($cats as $cat => $d) {
            $hits = ca_detect_clauses(implode(' ', $d['descs']), $catalog);
            $suggest = $hits ? array_key_first($hits) : '';
            $imported[$cat] = ['codes' => array_slice($d['codes'], 0, 6), 'suggest' => $suggest,
                               'why' => $suggest ? ('found "' . $suggest . '" in ' . $hits[$suggest] . ' keynote(s)') : ''];
        }
    } catch (Exception $e) { $importErr = $e->getMessage(); }
}

// Projects that have a Drive folder (for the import picker).
$driveProjects = [];
try { $driveProjects = $pdo->query("SELECT proj_id, JobName FROM Projects WHERE drive_folder_id IS NOT NULL AND drive_folder_id <> '' ORDER BY JobName")->fetchAll(PDO::FETCH_ASSOC); }
catch (Exception $e) {}

// Merge the category universe for Step 2: imported + live + already-mapped.
$allCats = [];
foreach (array_keys($imported) as $c) $allCats[$c] = true;
foreach ($liveCats as $c) $allCats[(string)$c] = true;
foreach (array_keys($mappedClauseByCat) as $c) $allCats[$c] = true;
$allCats = array_keys($allCats);
sort($allCats);

function rolelabel(string $r): string { return ucwords(str_replace('_', ' ', $r)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Building-code coverage — training</title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#5d3a9b; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; } .topbar h1 { margin:0; font-size:17px; font-weight:400; }
.page { max-width:1000px; margin:0 auto; padding:16px; }
.step { background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px 18px; margin:14px 0; }
.step h2 { margin:0 0 4px; color:#5d3a9b; font-size:16px; }
.step .lead { color:#666; margin:0 0 12px; }
.flash { background:#d6f5d6; border:1px solid #1a6b1a; color:#155515; padding:9px 13px; border-radius:4px; margin:10px 0; }
.flash-err { background:#ffd6d6; border:1px solid #a00; color:#a00; padding:9px 13px; border-radius:4px; margin:10px 0; }
table { width:100%; border-collapse:collapse; }
th, td { padding:6px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
th { background:#f4f4f4; font-size:11px; }
.roles { display:flex; flex-wrap:wrap; gap:4px 12px; }
.roles label { font-size:12px; white-space:nowrap; }
select { padding:5px 7px; border:1px solid #ccc; border-radius:3px; font:inherit; }
.btn { background:#5d3a9b; color:#fff; border:none; padding:8px 16px; border-radius:4px; cursor:pointer; font:inherit; }
.btn.secondary { background:#777; }
.suggest { background:#efe7fb; border:1px solid #c9b6ef; color:#4a2f86; border-radius:9px; padding:1px 8px; font-size:11px; }
.eg { color:#999; font-size:11px; } .muted { color:#999; }
code { background:#f3f3f3; padding:1px 5px; border-radius:3px; }
.unmapped td { background:#fffaf0; }
</style>
</head>
<body>
<div class="topbar"><h1>🏗️ Building-code coverage — training</h1><div><a href="analytics.php">Analytics</a> &nbsp;·&nbsp; <a href="menu.php">Menu</a></div></div>
<div class="page">

  <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <p class="muted">Set this up once and CADViz will, on every published revision, flag the building-code clauses a change touches and suggest who to notify. Two steps:</p>

  <!-- ── STEP 1 ─────────────────────────────────────────────────────────── -->
  <form method="post" class="step">
    <input type="hidden" name="action" value="save_clause_roles">
    <h2>Step 1 · Who handles each Building Code clause</h2>
    <p class="lead">Tick the disciplines that must be notified when a change affects each clause. (e.g. <strong>B1 Structure → structural</strong>, <strong>C clauses → fire</strong>, <strong>E2 → weathertightness</strong>.)</p>
    <table>
      <thead><tr><th style="width:230px">Clause</th><th>Notify these roles</th></tr></thead>
      <tbody>
        <?php foreach ($clauses as $c):
            $code = (string)$c['Clause_Code'];
            $have = array_filter(array_map('trim', explode(',', (string)$c['Default_Stakeholder_Roles_CSV'])));
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($code) ?></strong> &mdash; <?= htmlspecialchars((string)$c['Title']) ?></td>
            <td><div class="roles">
              <?php foreach ($ROLES as $r): ?>
                <label><input type="checkbox" name="roles[<?= htmlspecialchars($code) ?>][]" value="<?= $r ?>" <?= in_array($r, $have, true) ? 'checked' : '' ?>> <?= htmlspecialchars(rolelabel($r)) ?></label>
              <?php endforeach; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:12px"><button class="btn" type="submit">Save Step 1</button></p>
  </form>

  <!-- ── STEP 2 ─────────────────────────────────────────────────────────── -->
  <div class="step">
    <h2>Step 2 · Which keynotes touch which clause</h2>
    <p class="lead">Map each keynote <em>category</em> to the clause it affects. When a model element with that keynote changes, CADViz flags the clause and suggests the Step 1 roles. Tip: <strong>Import</strong> a project's keynotes and CADViz pre-suggests the clause from the keynote text.</p>

    <form method="get" style="margin-bottom:12px">
      <label>Import categories from a project's keynotes:
        <select name="import_proj" onchange="this.form.submit()">
          <option value="">— pick a project —</option>
          <?php foreach ($driveProjects as $p): ?>
            <option value="<?= (int)$p['proj_id'] ?>" <?= $importProj === (int)$p['proj_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['JobName']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($importErr): ?><span class="flash-err" style="display:inline-block;padding:3px 8px;margin-left:8px"><?= htmlspecialchars($importErr) ?></span><?php endif; ?>
    </form>

    <?php if (empty($allCats)): ?>
      <p class="muted">No keynote categories yet. Import them from a project above (or they'll appear automatically once revisions are published).</p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="action" value="save_keynote_map">
      <?php if ($importProj > 0): ?><input type="hidden" name="import_proj" value="<?= $importProj ?>"><?php endif; ?>
      <table>
        <thead><tr><th>Keynote category</th><th>Examples</th><th style="width:240px">Affects clause</th></tr></thead>
        <tbody>
          <?php foreach ($allCats as $cat):
              $cur = $mappedClauseByCat[$cat] ?? '';
              $imp = $imported[$cat] ?? null;
              $sel = $cur !== '' ? $cur : ($imp['suggest'] ?? '');
          ?>
            <tr class="<?= $cur === '' && $sel === '' ? 'unmapped' : '' ?>">
              <td><strong><?= htmlspecialchars($cat) ?></strong></td>
              <td class="eg">
                <?= $imp ? htmlspecialchars(implode(', ', $imp['codes'])) : '<span class="muted">—</span>' ?>
                <?php if ($imp && $imp['suggest']): ?><br><span class="suggest">suggested <?= htmlspecialchars($imp['suggest']) ?></span> <span class="muted"><?= htmlspecialchars($imp['why']) ?></span><?php endif; ?>
              </td>
              <td>
                <select name="map[<?= htmlspecialchars($cat) ?>]">
                  <option value="">— none —</option>
                  <?php foreach ($clauses as $c): $cc = (string)$c['Clause_Code']; ?>
                    <option value="<?= htmlspecialchars($cc) ?>" <?= $sel === $cc ? 'selected' : '' ?>><?= htmlspecialchars($cc . ' — ' . $c['Title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:12px"><button class="btn" type="submit">Save Step 2</button>
        <span class="muted">Rows shaded amber aren't mapped yet. Pre-filled suggestions are saved when you click Save.</span></p>
    </form>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
