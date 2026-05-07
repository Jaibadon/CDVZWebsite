<?php
/**
 * Spec categorisation admin: Spec_Cats (top-level) + Spec_SubCats
 * (children of cats). Replaces the old SUBCAT_TYPES.php which only
 * dealt with subcats and was awkward to navigate.
 *
 * Sub-cats drive the new "Generate quote from spec" wizard
 * (quote_from_spec.php): pick which categories a project has, the
 * wizard pulls all Tasks_Types tagged with subcats under those cats
 * and bulk-adds them to the project's stages.
 */

session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); echo '<p>Admin only.</p>'; exit;
}

$pdo = get_db();
$flash = ''; $flashErr = '';

// ── POST handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'cat_update') {
            $cid   = (int)($_POST['Spec_Cat_ID'] ?? 0);
            $name  = trim((string)($_POST['Spec_Cat_Name'] ?? ''));
            $order = $_POST['Spec_Cat_Order'] !== '' ? (int)$_POST['Spec_Cat_Order'] : null;
            if ($cid <= 0 || $name === '') throw new RuntimeException('Cat ID and name required.');
            $pdo->prepare("UPDATE Spec_Cats SET Spec_Cat_Name = ?, Spec_Cat_Order = ? WHERE Spec_Cat_ID = ?")
                ->execute([$name, $order, $cid]);
            $flash = "Category updated.";
        } elseif ($action === 'cat_add') {
            $name  = trim((string)($_POST['Spec_Cat_Name'] ?? ''));
            $order = $_POST['Spec_Cat_Order'] !== '' ? (int)$_POST['Spec_Cat_Order'] : null;
            if ($name === '') throw new RuntimeException('Category name required.');
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Spec_Cat_ID),0)+1 FROM Spec_Cats")->fetchColumn();
            $pdo->prepare("INSERT INTO Spec_Cats (Spec_Cat_ID, Spec_Cat_Name, Spec_Cat_Order) VALUES (?, ?, ?)")
                ->execute([$next, $name, $order]);
            $flash = "Category added (ID $next).";
        } elseif ($action === 'cat_delete') {
            $cid = (int)($_POST['Spec_Cat_ID'] ?? 0);
            $rs = $pdo->prepare("SELECT COUNT(*) AS n FROM Spec_SubCats WHERE Spec_Cat_ID = ?");
            $rs->execute([$cid]);
            $refs = (int)$rs->fetchColumn();
            if ($refs > 0) throw new RuntimeException("Cat has $refs sub-cat(s) — reassign or delete those first.");
            $pdo->prepare("DELETE FROM Spec_Cats WHERE Spec_Cat_ID = ?")->execute([$cid]);
            $flash = "Category deleted.";
        } elseif ($action === 'subcat_update') {
            $sid    = (int)($_POST['Spec_SubCat_ID'] ?? 0);
            $name   = trim((string)($_POST['Spec_SubCat_Name'] ?? ''));
            $cid    = (int)($_POST['Spec_Cat_ID']    ?? 0);
            $order  = $_POST['Spec_SubCat_Order'] !== '' ? (int)$_POST['Spec_SubCat_Order'] : null;
            $intern = isset($_POST['Internal_Use_Only']) ? 1 : 0;
            if ($sid <= 0 || $name === '' || $cid <= 0) throw new RuntimeException('Sub-cat ID, name, and parent cat required.');
            $pdo->prepare("UPDATE Spec_SubCats SET Spec_SubCat_Name = ?, Spec_Cat_ID = ?, Spec_SubCat_Order = ?, Internal_Use_Only = ? WHERE Spec_SubCat_ID = ?")
                ->execute([$name, $cid, $order, $intern, $sid]);
            $flash = "Sub-cat updated.";
        } elseif ($action === 'subcat_add') {
            $name   = trim((string)($_POST['Spec_SubCat_Name'] ?? ''));
            $cid    = (int)($_POST['Spec_Cat_ID'] ?? 0);
            $order  = $_POST['Spec_SubCat_Order'] !== '' ? (int)$_POST['Spec_SubCat_Order'] : null;
            $intern = isset($_POST['Internal_Use_Only']) ? 1 : 0;
            if ($name === '' || $cid <= 0) throw new RuntimeException('Name + parent cat required.');
            $pdo->prepare("INSERT INTO Spec_SubCats (Spec_SubCat_Name, Spec_Cat_ID, Spec_SubCat_Order, Internal_Use_Only) VALUES (?, ?, ?, ?)")
                ->execute([$name, $cid, $order, $intern]);
            $flash = "Sub-cat added.";
        } elseif ($action === 'subcat_delete') {
            $sid = (int)($_POST['Spec_SubCat_ID'] ?? 0);
            $rs = $pdo->prepare("SELECT COUNT(*) AS n FROM Tasks_Types WHERE Spec_Subcat_ID = ?");
            $rs->execute([$sid]);
            $refs = (int)$rs->fetchColumn();
            if ($refs > 0) throw new RuntimeException("Sub-cat used by $refs task type(s) — reassign first.");
            $pdo->prepare("DELETE FROM Spec_SubCats WHERE Spec_SubCat_ID = ?")->execute([$sid]);
            $flash = "Sub-cat deleted.";
        }
    } catch (Exception $e) { $flashErr = $e->getMessage(); }

    $_SESSION['flash']     = $flash;
    $_SESSION['flash_err'] = $flashErr;
    header('Location: spec_admin.php');
    exit;
}
$flash    = $_SESSION['flash']     ?? '';
$flashErr = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);

// ── Read ────────────────────────────────────────────────────────────────
$cats = $pdo->query("SELECT Spec_Cat_ID, Spec_Cat_Name, Spec_Cat_Order FROM Spec_Cats ORDER BY COALESCE(Spec_Cat_Order, 9999), Spec_Cat_Name")->fetchAll();

$subcats = $pdo->query(
    "SELECT s.Spec_SubCat_ID, s.Spec_SubCat_Name, s.Spec_Cat_ID, s.Spec_SubCat_Order,
            COALESCE(s.Internal_Use_Only, 0) AS Internal_Use_Only,
            (SELECT COUNT(*) FROM Tasks_Types tt WHERE tt.Spec_Subcat_ID = s.Spec_SubCat_ID) AS task_count
       FROM Spec_SubCats s
      ORDER BY s.Spec_Cat_ID, COALESCE(s.Spec_SubCat_Order, 9999), s.Spec_SubCat_Name"
)->fetchAll();

$catCount  = []; foreach ($subcats as $s) $catCount[(int)$s['Spec_Cat_ID']] = ($catCount[(int)$s['Spec_Cat_ID']] ?? 0) + 1;
$catRefs   = []; foreach ($subcats as $s) $catRefs[(int)$s['Spec_Cat_ID']]  = ($catRefs[(int)$s['Spec_Cat_ID']]  ?? 0) + (int)$s['task_count'];
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Spec Categories &amp; Sub-categories</title>
<link href="site.css" rel="stylesheet">
<style>
.page { max-width: 1100px; margin: 20px auto; }
.card { background:#fff; padding:14px 18px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:14px; }
table.tt { width:100%; border-collapse:collapse; }
table.tt th { background:#f4f4d8; text-align:left; padding:5px 7px; font-size:11px; }
table.tt td { padding:4px 6px; border-bottom:1px solid #eee; vertical-align:middle; font-size:12px; }
table.tt input[type=text], table.tt input[type=number], table.tt select { padding:3px 5px; font-size:12px; box-sizing:border-box; }
table.tt input.name  { width:100%; min-width:280px; }
table.tt input.num   { width:60px; text-align:right; }
table.tt select.cat  { width:170px; }
.btn-sm { background:#9B9B1B; color:#fff; border:none; padding:3px 9px; border-radius:3px; cursor:pointer; font-size:11px; }
.btn-sm.danger { background:#c33; }
.muted { color:#888; font-size:11px; }
.flash    { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.flash-err{ background:#ffd6d6; border:1px solid #c33;   color:#a00;    padding:8px 12px; border-radius:4px; margin-bottom:12px; }
form.inline { display:inline; margin:0; }
.cat-block { background:#f9f9ee; border-left:4px solid #9B9B1B; padding:6px 10px; margin:6px 0 0; font-size:12px; }
</style></head><body>
<div class="page">
  <div class="card">
    <h1 style="margin:0">Spec categories &amp; sub-categories</h1>
    <p class="muted">Top-level <strong>categories</strong> (Wall, Roof, Joinery…) group <strong>sub-categories</strong> (Wall Cladding, Roof Framing, Window Schedule…). Each Tasks_Types row links to a sub-category — that's how the <a href="quote_from_spec.php">quote-from-spec wizard</a> filters tasks. Internal-use-only sub-cats stay hidden from the wizard.</p>
  </div>

  <?php if ($flash):    ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <!-- ── Categories ──────────────────────────────────────────────── -->
  <div class="card">
    <h3 style="margin:0 0 8px">Categories</h3>
    <table class="tt">
      <tr><th>Name</th><th>Order</th><th class="muted">#&nbsp;subcats</th><th class="muted">#&nbsp;tasks</th><th></th></tr>
      <?php foreach ($cats as $c):
        $cid = (int)$c['Spec_Cat_ID']; $nSub = $catCount[$cid] ?? 0; $nTasks = $catRefs[$cid] ?? 0;
      ?>
        <tr>
          <form method="post">
            <input type="hidden" name="action" value="cat_update">
            <input type="hidden" name="Spec_Cat_ID" value="<?= $cid ?>">
            <td><input type="text" class="name" name="Spec_Cat_Name" value="<?= htmlspecialchars($c['Spec_Cat_Name']) ?>" required>
                <span class="muted">#<?= $cid ?></span></td>
            <td><input type="number" class="num" name="Spec_Cat_Order" value="<?= $c['Spec_Cat_Order'] !== null ? (int)$c['Spec_Cat_Order'] : '' ?>"></td>
            <td class="muted"><?= $nSub ?></td>
            <td class="muted"><?= $nTasks ?></td>
            <td>
              <button class="btn-sm">Save</button>
          </form>
              <form method="post" class="inline" onsubmit="return confirm('Delete this category? <?= $nSub ?> sub-cat(s) currently belong to it; deletion is blocked when any sub-cats exist.');">
                <input type="hidden" name="action" value="cat_delete">
                <input type="hidden" name="Spec_Cat_ID" value="<?= $cid ?>">
                <button class="btn-sm danger" <?= $nSub > 0 ? 'disabled title="Has sub-cats — reassign first"' : '' ?>>Del</button>
              </form>
            </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- ── Add new category (separate form so it survives HTML table parsing) ── -->
  <div class="card">
    <h3 style="margin:0 0 8px">Add new category</h3>
    <form method="post">
      <input type="hidden" name="action" value="cat_add">
      <table class="tt">
        <tr><th>Name</th><th>Order</th><th></th></tr>
        <tr>
          <td><input type="text" class="name" name="Spec_Cat_Name" placeholder="e.g. Fire Protection" required></td>
          <td><input type="number" class="num" name="Spec_Cat_Order" placeholder="50"></td>
          <td><button class="btn-sm">Add category</button></td>
        </tr>
      </table>
    </form>
  </div>

  <!-- ── Sub-categories grouped by parent cat ────────────────────── -->
  <div class="card">
    <h3 style="margin:0 0 8px">Sub-categories</h3>
    <table class="tt">
      <tr><th>Name</th><th>Parent category</th><th>Order</th><th class="muted">Internal&nbsp;only</th><th class="muted">#&nbsp;tasks</th><th></th></tr>
      <?php
        // Group by category for visual readability.
        $subsByCat = [];
        foreach ($subcats as $s) $subsByCat[(int)$s['Spec_Cat_ID']][] = $s;
        foreach ($cats as $c):
          $cid = (int)$c['Spec_Cat_ID'];
          if (empty($subsByCat[$cid])) continue;
      ?>
        <tr><td colspan="6" class="cat-block"><strong><?= htmlspecialchars($c['Spec_Cat_Name']) ?></strong> <span class="muted">(cat #<?= $cid ?>)</span></td></tr>
        <?php foreach ($subsByCat[$cid] as $s):
          $sid = (int)$s['Spec_SubCat_ID']; $tasks = (int)$s['task_count'];
        ?>
          <tr>
            <form method="post">
              <input type="hidden" name="action" value="subcat_update">
              <input type="hidden" name="Spec_SubCat_ID" value="<?= $sid ?>">
              <td><input type="text" class="name" name="Spec_SubCat_Name" value="<?= htmlspecialchars($s['Spec_SubCat_Name']) ?>" required>
                  <span class="muted">#<?= $sid ?></span></td>
              <td>
                <select class="cat" name="Spec_Cat_ID" required>
                  <?php foreach ($cats as $cc): ?>
                    <option value="<?= (int)$cc['Spec_Cat_ID'] ?>" <?= (int)$cc['Spec_Cat_ID'] === $cid ? 'selected' : '' ?>><?= htmlspecialchars($cc['Spec_Cat_Name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" class="num" name="Spec_SubCat_Order" value="<?= $s['Spec_SubCat_Order'] !== null ? (int)$s['Spec_SubCat_Order'] : '' ?>"></td>
              <td><input type="checkbox" name="Internal_Use_Only" <?= (int)$s['Internal_Use_Only'] === 1 ? 'checked' : '' ?>></td>
              <td class="muted"><?= $tasks ?></td>
              <td>
                <button class="btn-sm">Save</button>
            </form>
                <form method="post" class="inline" onsubmit="return confirm('Delete this sub-cat? <?= $tasks ?> task type(s) currently use it; deletion blocked when in use.');">
                  <input type="hidden" name="action" value="subcat_delete">
                  <input type="hidden" name="Spec_SubCat_ID" value="<?= $sid ?>">
                  <button class="btn-sm danger" <?= $tasks > 0 ? 'disabled title="In use — reassign tasks first"' : '' ?>>Del</button>
                </form>
              </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- ── Add new sub-category (separate form, same reason) ────────── -->
  <div class="card">
    <h3 style="margin:0 0 8px">Add new sub-category</h3>
    <form method="post">
      <input type="hidden" name="action" value="subcat_add">
      <table class="tt">
        <tr><th>Name</th><th>Parent category</th><th>Order</th><th>Internal&nbsp;only</th><th></th></tr>
        <tr>
          <td><input type="text" class="name" name="Spec_SubCat_Name" placeholder="UPPERCASE convention, e.g. SMOKE ALARMS" required></td>
          <td>
            <select class="cat" name="Spec_Cat_ID" required>
              <option value="">— pick parent —</option>
              <?php foreach ($cats as $cc): ?>
                <option value="<?= (int)$cc['Spec_Cat_ID'] ?>"><?= htmlspecialchars($cc['Spec_Cat_Name']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" class="num" name="Spec_SubCat_Order" placeholder="10"></td>
          <td><input type="checkbox" name="Internal_Use_Only"> <span class="muted">hide from quote wizard</span></td>
          <td><button class="btn-sm">Add sub-category</button></td>
        </tr>
      </table>
    </form>
  </div>

  <p class="muted"><a href="more.php">← back to More</a> &middot; <a href="task_types_admin.php">Task Types</a> &middot; <a href="quote_from_spec.php">Generate quote from spec</a></p>
</div></body></html>
