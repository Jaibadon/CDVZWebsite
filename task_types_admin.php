<?php
/**
 * Task Types admin — replaces the old per-stage TASK_TYPES.php that was
 * stuck navigating one stage at a time and didn't reflect the modern
 * quoting flow.
 *
 * Lists every Tasks_Types row in one editable table. The stage filter in
 * the URL (?stage_id=N) narrows to one stage; default = all stages.
 * Each row inline-edits via a small POST form (Task_Name, Stage,
 * Estimated_Time, Fixed_Cost, Task_Order, Spec_SubCat_ID).
 *
 * The "Stage_ID" on a Task_Type is the *primary* stage it usually appears
 * in — used by stages_editor.php to put it at the top of the "add a new
 * task" dropdown when you're editing that stage. A task type can still
 * be added to any stage on a quote; the Stage_ID is just the default
 * sort hint.
 */

session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); echo '<p>Admin only.</p>'; exit;
}

$pdo = get_db();

// Detect optional columns so installs missing them still work.
$hasFixed   = false; try { $hasFixed   = (bool)$pdo->query("SHOW COLUMNS FROM Tasks_Types LIKE 'Fixed_Cost'")->fetch(); } catch (Exception $e) {}
$hasSubcat  = false; try { $hasSubcat  = (bool)$pdo->query("SHOW COLUMNS FROM Tasks_Types LIKE 'Spec_Subcat_ID'")->fetch(); } catch (Exception $e) {}

// ── POST handlers ────────────────────────────────────────────────────────
$flash = ''; $flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            $tid    = (int)($_POST['Task_ID'] ?? 0);
            $name   = trim((string)($_POST['Task_Name'] ?? ''));
            $stage  = (int)($_POST['Stage_ID'] ?? 0);
            $est    = (float)($_POST['Estimated_Time'] ?? 0);
            $order  = (int)($_POST['Task_Order'] ?? 0);
            $fixed  = $hasFixed ? (float)($_POST['Fixed_Cost'] ?? 0) : 0;
            $sub    = $hasSubcat ? ((int)($_POST['Spec_Subcat_ID'] ?? 0) ?: null) : null;
            if ($tid <= 0 || $name === '' || $stage <= 0) throw new RuntimeException('Task ID, name, and stage are required.');

            $cols = ['Task_Name = ?', 'Stage_ID = ?', 'Estimated_Time = ?', 'Task_Order = ?'];
            $args = [$name, $stage, $est, $order];
            if ($hasFixed)  { $cols[] = 'Fixed_Cost = ?';     $args[] = $fixed; }
            if ($hasSubcat) { $cols[] = 'Spec_Subcat_ID = ?'; $args[] = $sub; }
            $args[] = $tid;
            $pdo->prepare("UPDATE Tasks_Types SET " . implode(', ', $cols) . " WHERE Task_ID = ?")->execute($args);
            $flash = 'Task type updated.';
        } elseif ($action === 'add') {
            $name   = trim((string)($_POST['Task_Name'] ?? ''));
            $stage  = (int)($_POST['Stage_ID'] ?? 0);
            $est    = (float)($_POST['Estimated_Time'] ?? 0);
            $order  = (int)($_POST['Task_Order'] ?? 0);
            $fixed  = $hasFixed ? (float)($_POST['Fixed_Cost'] ?? 0) : 0;
            $sub    = $hasSubcat ? ((int)($_POST['Spec_Subcat_ID'] ?? 0) ?: null) : null;
            if ($name === '' || $stage <= 0) throw new RuntimeException('Task name and stage are required.');
            $cols = ['Task_Name', 'Stage_ID', 'Estimated_Time', 'Task_Order'];
            $vals = ['?',         '?',        '?',              '?'];
            $args = [$name, $stage, $est, $order];
            if ($hasFixed)  { $cols[] = 'Fixed_Cost';     $vals[] = '?'; $args[] = $fixed; }
            if ($hasSubcat) { $cols[] = 'Spec_Subcat_ID'; $vals[] = '?'; $args[] = $sub; }
            $pdo->prepare("INSERT INTO Tasks_Types (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")->execute($args);
            $flash = 'Task type added.';
        } elseif ($action === 'delete') {
            $tid = (int)($_POST['Task_ID'] ?? 0);
            // Refuse if it's referenced by any Project_Tasks row — don't
            // orphan historical project data. User must reassign first.
            $refs = (int)$pdo->prepare("SELECT COUNT(*) FROM Project_Tasks WHERE Task_Type_ID = ?")->execute([$tid]);
            $countSt = $pdo->prepare("SELECT COUNT(*) AS n FROM Project_Tasks WHERE Task_Type_ID = ?");
            $countSt->execute([$tid]);
            $ref = (int)$countSt->fetchColumn();
            if ($ref > 0) throw new RuntimeException("Can't delete — $ref project task(s) still use this task type. Reassign them first.");
            $pdo->prepare("DELETE FROM Tasks_Types WHERE Task_ID = ?")->execute([$tid]);
            $flash = 'Task type deleted.';
        }
    } catch (Exception $e) { $flashErr = $e->getMessage(); }

    $_SESSION['flash'] = $flash; $_SESSION['flash_err'] = $flashErr;
    header('Location: task_types_admin.php' . (!empty($_GET['stage_id']) ? '?stage_id=' . (int)$_GET['stage_id'] : ''));
    exit;
}
$flash    = $_SESSION['flash']     ?? '';
$flashErr = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);

// ── Read ────────────────────────────────────────────────────────────────
$stages = $pdo->query("SELECT Stage_Type_ID, Stage_Type_Name, Stage_Order FROM Stage_Types ORDER BY Stage_Order, Stage_Type_Name")->fetchAll();
$stageById = [];
foreach ($stages as $s) $stageById[(int)$s['Stage_Type_ID']] = $s['Stage_Type_Name'];

$subcats = [];
if ($hasSubcat) {
    try { $subcats = $pdo->query("SELECT Spec_SubCat_ID, Spec_SubCat_Name FROM Spec_SubCats ORDER BY Spec_SubCat_Name")->fetchAll(); } catch (Exception $e) {}
}

$filterStage = (int)($_GET['stage_id'] ?? 0);
$where = $filterStage > 0 ? "WHERE Stage_ID = " . $filterStage : '';

$cols = "Task_ID, Task_Name, Stage_ID, COALESCE(Estimated_Time, 0) AS Estimated_Time, COALESCE(Task_Order, 0) AS Task_Order"
      . ($hasFixed  ? ", COALESCE(Fixed_Cost, 0) AS Fixed_Cost" : ", 0 AS Fixed_Cost")
      . ($hasSubcat ? ", Spec_Subcat_ID"                         : ", NULL AS Spec_Subcat_ID");
$rows = $pdo->query("SELECT $cols FROM Tasks_Types $where ORDER BY Stage_ID, Task_Order, Task_Name")->fetchAll();

// Ref count per task type so the UI can warn before delete.
$refCount = [];
try {
    $rc = $pdo->query("SELECT Task_Type_ID, COUNT(*) AS n FROM Project_Tasks GROUP BY Task_Type_ID")->fetchAll();
    foreach ($rc as $r) $refCount[(int)$r['Task_Type_ID']] = (int)$r['n'];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Task Types</title>
<link href="site.css" rel="stylesheet">
<style>
.page { max-width: 1100px; margin: 20px auto; }
.card { background:#fff; padding:14px 18px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:12px; }
table.tt { width:100%; border-collapse:collapse; }
table.tt th { background:#f4f4d8; text-align:left; padding:5px 7px; font-size:11px; }
table.tt td { padding:4px 6px; border-bottom:1px solid #eee; vertical-align:middle; font-size:12px; }
table.tt input[type=text], table.tt input[type=number], table.tt select { padding:3px 5px; font-size:12px; }
table.tt input.name { width:100%; min-width:340px; box-sizing:border-box; }
table.tt input.num  { width:70px; text-align:right; }
/* Spec Subcat dropdown: narrow column. Long subcat names get clipped
   visually but the full name is still selectable / readable on focus. */
table.tt td.subcat-cell { width:90px; }
table.tt select.subcat  { width:90px; max-width:90px; }
/* Make the Task Name column claim the leftover space */
table.tt th.name-col, table.tt td.name-cell { width:auto; }
.btn-sm { background:#9B9B1B; color:#fff; border:none; padding:3px 9px; border-radius:3px; cursor:pointer; font-size:11px; }
.btn-sm.danger { background:#c33; }
.muted { color:#888; font-size:11px; }
.flash    { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.flash-err{ background:#ffd6d6; border:1px solid #c33;   color:#a00;    padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.tabs a { display:inline-block; padding:5px 10px; background:#eee; color:#333; text-decoration:none; border-radius:3px; margin:2px; font-size:12px; }
.tabs a.active { background:#9B9B1B; color:#fff; }
form.inline { display:inline; margin:0; }
</style></head><body>
<div class="page">
  <div class="card">
    <h1 style="margin:0">Task Types</h1>
    <p class="muted">Catalog of every task type that quotes can use. <strong>Stage</strong> is the primary stage this task usually appears in — quotes can place it on any stage, but stages_editor will surface it at the top of the "add task" dropdown when editing that stage.</p>
  </div>

  <?php if ($flash):    ?><div class="flash"    ><?= htmlspecialchars($flash) ?></div>    <?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <div class="card">
    <strong style="font-size:12px">Filter by stage:</strong>
    <span class="tabs">
      <a href="?" class="<?= $filterStage === 0 ? 'active' : '' ?>">All</a>
      <?php foreach ($stages as $s):
        $sid = (int)$s['Stage_Type_ID'];
        $cnt = 0; foreach ($rows as $rr) if ((int)$rr['Stage_ID'] === $sid) $cnt++;
      ?>
        <a href="?stage_id=<?= $sid ?>" class="<?= $filterStage === $sid ? 'active' : '' ?>"><?= htmlspecialchars($s['Stage_Type_Name']) ?> <span class="muted">(<?= $cnt ?>)</span></a>
      <?php endforeach; ?>
    </span>
  </div>

  <div class="card">
    <table class="tt">
      <tr>
        <th>Task name</th>
        <th>Stage</th>
        <th>Order</th>
        <th>Est&nbsp;hrs</th>
        <?php if ($hasFixed):  ?><th>Fixed&nbsp;cost</th><?php endif; ?>
        <?php if ($hasSubcat): ?><th class="subcat-cell">Subcat</th><?php endif; ?>
        <th></th>
      </tr>
      <?php foreach ($rows as $r):
        $tid = (int)$r['Task_ID']; $refs = $refCount[$tid] ?? 0;
      ?>
        <tr>
          <form method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="Task_ID" value="<?= $tid ?>">
            <td>
              <input type="text" class="name" name="Task_Name" value="<?= htmlspecialchars($r['Task_Name']) ?>" required>
              <span class="muted">#<?= $tid ?> · <?= $refs ?> use<?= $refs === 1 ? '' : 's' ?></span>
            </td>
            <td>
              <select name="Stage_ID" required>
                <?php foreach ($stages as $s): ?>
                  <option value="<?= (int)$s['Stage_Type_ID'] ?>" <?= (int)$s['Stage_Type_ID'] === (int)$r['Stage_ID'] ? 'selected' : '' ?>><?= htmlspecialchars($s['Stage_Type_Name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" class="num" name="Task_Order"     value="<?= (int)$r['Task_Order'] ?>"></td>
            <td><input type="number" class="num" name="Estimated_Time" value="<?= (float)$r['Estimated_Time'] ?>" step="0.25"></td>
            <?php if ($hasFixed): ?>
              <td><input type="number" class="num" name="Fixed_Cost" value="<?= (float)$r['Fixed_Cost'] ?>" step="0.01"></td>
            <?php endif; ?>
            <?php if ($hasSubcat): ?>
              <td class="subcat-cell">
                <select name="Spec_Subcat_ID" class="subcat" title="Spec Subcat">
                  <option value="">—</option>
                  <?php foreach ($subcats as $sc): ?>
                    <option value="<?= (int)$sc['Spec_SubCat_ID'] ?>" <?= (int)$sc['Spec_SubCat_ID'] === (int)($r['Spec_Subcat_ID'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($sc['Spec_SubCat_Name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            <?php endif; ?>
            <td>
              <button class="btn-sm">Save</button>
          </form>
              <form method="post" class="inline" onsubmit="return confirm('Delete this task type? <?= $refs ?> project task(s) currently reference it; deletion is blocked when refs &gt; 0.');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="Task_ID" value="<?= $tid ?>">
                <button class="btn-sm danger" <?= $refs > 0 ? 'disabled title="In use — reassign first"' : '' ?>>Del</button>
              </form>
            </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= 5 + ($hasFixed?1:0) + ($hasSubcat?1:0) ?>" class="muted">No task types in this stage.</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px">Add new task type</h3>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <table class="tt">
        <tr>
          <th>Task name</th><th>Stage</th><th>Order</th><th>Est&nbsp;hrs</th>
          <?php if ($hasFixed):  ?><th>Fixed&nbsp;cost</th><?php endif; ?>
          <?php if ($hasSubcat): ?><th class="subcat-cell">Subcat</th><?php endif; ?>
          <th></th>
        </tr>
        <tr>
          <td><input type="text" class="name" name="Task_Name" required></td>
          <td>
            <select name="Stage_ID" required>
              <option value="">—</option>
              <?php foreach ($stages as $s): ?>
                <option value="<?= (int)$s['Stage_Type_ID'] ?>" <?= $filterStage === (int)$s['Stage_Type_ID'] ? 'selected' : '' ?>><?= htmlspecialchars($s['Stage_Type_Name']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" class="num" name="Task_Order"     value="0"></td>
          <td><input type="number" class="num" name="Estimated_Time" value="0" step="0.25"></td>
          <?php if ($hasFixed): ?>
            <td><input type="number" class="num" name="Fixed_Cost" value="0" step="0.01"></td>
          <?php endif; ?>
          <?php if ($hasSubcat): ?>
            <td class="subcat-cell">
              <select name="Spec_Subcat_ID" class="subcat" title="Spec Subcat"><option value="">—</option>
              <?php foreach ($subcats as $sc): ?><option value="<?= (int)$sc['Spec_SubCat_ID'] ?>"><?= htmlspecialchars($sc['Spec_SubCat_Name']) ?></option><?php endforeach; ?>
              </select>
            </td>
          <?php endif; ?>
          <td><button class="btn-sm">Add</button></td>
        </tr>
      </table>
    </form>
  </div>

  <p class="muted"><a href="more.php">← back to More</a></p>
</div></body></html>
