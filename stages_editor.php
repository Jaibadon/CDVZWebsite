<?php
/**
 * Shared stage/task editor partial.
 *
 * Caller provides:
 *   $mode          — 'project' or 'template'
 *   $owner_id      — proj_id or Template_ID
 *   $owner_label   — title text (e.g. "Project: Smith House" or "Template: …")
 *   $stages_table  — 'Project_Stages' or 'Template_Stages'
 *   $tasks_table   — 'Project_Tasks'  or 'Template_Tasks'
 *   $stage_owner_col — 'Proj_ID' or 'Template_ID'
 *   $task_owner_col  — 'Project_Stage_ID' (always — tasks are linked via stage)
 *   $back_url      — link target for the "back" button
 *
 * Tasks_Types and Stage_Types are shared between projects and templates.
 *
 * Convention used here: Template_Tasks.Template_ID column is included in the
 * Templates schema (per CSV). Project_Tasks has no owner col — it joins via
 * Project_Stages. So when working with templates, INSERT/SELECT on
 * Template_Tasks must include Template_ID; for Project_Tasks it must not.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_stage') {
            $cols = "$stage_owner_col, Stage_Type_ID, Description, Weight, Notes";
            $vals = "?, ?, ?, ?, ?";
            $params = [
                $owner_id,
                (int)($_POST['Stage_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Notes'] ?? '',
            ];
            // Both stages tables have Project_Stage_ID as PK and (in our DB) it's
            // not always auto_increment. Compute next id explicitly.
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM `$stages_table`")->fetchColumn();
            $cols = "Project_Stage_ID, $cols";
            $vals = "?, $vals";
            array_unshift($params, $next);
            $pdo->prepare("INSERT INTO `$stages_table` ($cols) VALUES ($vals)")->execute($params);

        } elseif ($action === 'update_stage') {
            $sid = (int)$_POST['Project_Stage_ID'];
            $stmt = $pdo->prepare("UPDATE `$stages_table` SET Stage_Type_ID = ?, Description = ?, Weight = ?, Notes = ? WHERE Project_Stage_ID = ? AND $stage_owner_col = ?");
            $stmt->execute([
                (int)($_POST['Stage_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Notes'] ?? '',
                $sid,
                $owner_id,
            ]);

        } elseif ($action === 'drop_stage') {
            $sid = (int)$_POST['Project_Stage_ID'];
            // Also delete child tasks
            if ($mode === 'template') {
                $pdo->prepare("DELETE FROM `$tasks_table` WHERE Project_Stage_ID = ? AND Template_ID = ?")->execute([$sid, $owner_id]);
            } else {
                $pdo->prepare("DELETE FROM `$tasks_table` WHERE Project_Stage_ID = ?")->execute([$sid]);
            }
            $pdo->prepare("DELETE FROM `$stages_table` WHERE Project_Stage_ID = ? AND $stage_owner_col = ?")->execute([$sid, $owner_id]);

        } elseif ($action === 'add_task') {
            $sid = (int)$_POST['Project_Stage_ID'];
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            // Get next id
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1 FROM `$tasks_table`")->fetchColumn();
            if ($mode === 'template') {
                $stmt = $pdo->prepare("INSERT INTO `$tasks_table` (Proj_Task_ID, Template_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order, Assigned_To) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $next, $owner_id, $sid,
                    (int)($_POST['Task_Type_ID'] ?? 0),
                    $_POST['Description'] ?? '',
                    (float)($_POST['Weight'] ?? 1),
                    $_POST['Proj_Task_Notes'] ?? '',
                    (int)($_POST['Proj_Task_Order'] ?? 0),
                    $assignedTo,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO `$tasks_table` (Proj_Task_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order, Assigned_To) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $next, $sid,
                    (int)($_POST['Task_Type_ID'] ?? 0),
                    $_POST['Description'] ?? '',
                    (float)($_POST['Weight'] ?? 1),
                    $_POST['Proj_Task_Notes'] ?? '',
                    (int)($_POST['Proj_Task_Order'] ?? 0),
                    $assignedTo,
                ]);
            }

        } elseif ($action === 'update_task') {
            $tid = (int)$_POST['Proj_Task_ID'];
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            $stmt = $pdo->prepare("UPDATE `$tasks_table` SET Task_Type_ID = ?, Description = ?, Weight = ?, Proj_Task_Notes = ?, Proj_Task_Order = ?, Assigned_To = ? WHERE Proj_Task_ID = ?");
            $stmt->execute([
                (int)($_POST['Task_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Proj_Task_Notes'] ?? '',
                (int)($_POST['Proj_Task_Order'] ?? 0),
                $assignedTo,
                $tid,
            ]);

        } elseif ($action === 'drop_task') {
            $tid = (int)$_POST['Proj_Task_ID'];
            $pdo->prepare("DELETE FROM `$tasks_table` WHERE Proj_Task_ID = ?")->execute([$tid]);
        }
    } catch (Exception $e) {
        $errMsg = 'DB Error: ' . htmlspecialchars($e->getMessage());
    }

    if (empty($errMsg)) {
        // PRG to avoid resubmit
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// ── Load stages + tasks ───────────────────────────────────────────────────
$stages = $pdo->prepare(
    "SELECT s.*, st.Stage_Type_Name, st.Stage_Order
       FROM `$stages_table` s
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
      WHERE s.$stage_owner_col = ?
      ORDER BY st.Stage_Order, s.Project_Stage_ID"
);
$stages->execute([$owner_id]);
$stages = $stages->fetchAll();

if ($mode === 'template') {
    $tasksStmt = $pdo->prepare(
        "SELECT t.*, tt.Task_Name, tt.Estimated_Time
           FROM `$tasks_table` t
           LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
          WHERE t.Template_ID = ?
          ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
    );
    $tasksStmt->execute([$owner_id]);
} else {
    $tasksStmt = $pdo->prepare(
        "SELECT t.*, tt.Task_Name, tt.Estimated_Time
           FROM Project_Tasks t
           LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
          WHERE t.Project_Stage_ID IN (SELECT Project_Stage_ID FROM Project_Stages WHERE Proj_ID = ?)
          ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
    );
    $tasksStmt->execute([$owner_id]);
}
$allTasks = $tasksStmt->fetchAll();

$tasksByStage = [];
foreach ($allTasks as $t) {
    $tasksByStage[$t['Project_Stage_ID']][] = $t;
}

// Lookups
$stageTypes = $pdo->query("SELECT Stage_Type_ID, Stage_Type_Name FROM Stage_Types ORDER BY Stage_Order, Stage_Type_Name")->fetchAll();
$taskTypes  = $pdo->query("SELECT Task_ID, Task_Name, Stage_ID, Estimated_Time FROM Tasks_Types ORDER BY Task_Name")->fetchAll();
// Staff list for Assigned_To dropdown — try with Active filter, fall back without
try {
    $staff = $pdo->query("SELECT Employee_ID, Login, `BILLING RATE` AS BillingRate FROM Staff WHERE Active <> 0 ORDER BY Login")->fetchAll();
} catch (Exception $e) {
    $staff = $pdo->query("SELECT Employee_ID, Login, `BILLING RATE` AS BillingRate FROM Staff ORDER BY Login")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($owner_label) ?></title>
<link href="global.css" rel="stylesheet">
<style>
body { background:#EBEBEB; font-family:Arial,sans-serif; padding:14px; font-size:12px; }
h1 { color:#9B9B1B; margin:0 0 8px; font-size:18px; }
h2 { color:#9B9B1B; margin:14px 0 4px; font-size:14px; }
.nav a { margin-right:14px; }
.actions a, .actions button, .actions input[type=submit] {
    background:#9B9B1B; color:#fff; padding:4px 8px; text-decoration:none;
    border-radius:3px; border:none; cursor:pointer; font-size:11px;
}
.danger { background:#c33 !important; }
table { border-collapse:collapse; width:100%; max-width:1100px; background:#fff; margin-bottom:6px; }
th { background:#9B9B1B; color:#fff; text-align:left; padding:4px 6px; font-size:11px; }
td { padding:3px 6px; border-top:1px solid #eee; vertical-align:middle; }
input[type=text], input[type=number], select { font-size:11px; padding:2px 3px; }
.stage-row td { background:#f7f7e0; font-weight:bold; }
.add-row td { background:#eef; }
.totals td { background:#fff8b3; font-weight:bold; }
form.inline { display:inline; margin:0; }
</style>
</head>
<body>

<div class="nav">
  <a href="<?= htmlspecialchars($back_url) ?>">&larr; Back</a>
  <a href="menu.php">Main Menu</a>
  <?php if ($mode === 'template'): ?>
    <a href="templates.php">All Templates</a>
  <?php endif; ?>
</div>
<h1><?= htmlspecialchars($owner_label) ?></h1>
<?php if (!empty($errMsg)): ?><p style="color:red"><?= $errMsg ?></p><?php endif; ?>

<?php
$grandHrs = 0;
foreach ($stages as $stage):
    $sid = (int)$stage['Project_Stage_ID'];
    $stageWeight = (float)($stage['Weight'] ?? 1);
    $tasks = $tasksByStage[$sid] ?? [];
    $stageHrs = 0;
?>

<table>
<tr class="stage-row">
  <td colspan="7">
    <form method="post" class="inline">
      <input type="hidden" name="action" value="update_stage">
      <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
      <strong>Stage:</strong>
      <select name="Stage_Type_ID">
        <?php foreach ($stageTypes as $st): ?>
          <option value="<?= (int)$st['Stage_Type_ID'] ?>" <?= ((int)$st['Stage_Type_ID'] === (int)$stage['Stage_Type_ID']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($st['Stage_Type_Name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      Weight: <input type="number" step="0.05" name="Weight" value="<?= htmlspecialchars((string)$stage['Weight'] ?? '1') ?>" style="width:55px">
      Description: <input type="text" name="Description" value="<?= htmlspecialchars((string)($stage['Description'] ?? '')) ?>" style="width:280px">
      <span class="actions">
        <input type="submit" value="Save Stage">
      </span>
    </form>
    <form method="post" class="inline" onsubmit="return confirm('Delete this entire stage and all its tasks?');">
      <input type="hidden" name="action" value="drop_stage">
      <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
      <span class="actions"><input type="submit" class="danger" value="Drop Stage"></span>
    </form>
  </td>
</tr>
<tr><th style="width:28%">Task</th><th>Description</th><th style="width:55px">Weight</th><th style="width:60px">Hours</th><th style="width:120px">Assigned To</th><th style="width:60px">Order</th><th style="width:90px">Actions</th></tr>

<?php foreach ($tasks as $t):
    $hrs = (float)($t['Estimated_Time'] ?? 0) * (float)($t['Weight'] ?? 1) * $stageWeight;
    $stageHrs += $hrs;
    $assigned = (int)($t['Assigned_To'] ?? 0);
?>
<tr>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="update_task">
    <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
    <td>
      <select name="Task_Type_ID">
        <?php foreach ($taskTypes as $tt): ?>
          <option value="<?= (int)$tt['Task_ID'] ?>" <?= ((int)$tt['Task_ID'] === (int)$t['Task_Type_ID']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($tt['Task_Name']) ?> (<?= (float)$tt['Estimated_Time'] ?>h)
          </option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="Description" value="<?= htmlspecialchars((string)($t['Description'] ?? '')) ?>" style="width:99%"></td>
    <td><input type="number" step="0.05" name="Weight" value="<?= htmlspecialchars((string)$t['Weight'] ?? '1') ?>" style="width:55px"></td>
    <td><?= number_format($hrs, 2) ?></td>
    <td>
      <select name="Assigned_To">
        <option value="">— unassigned —</option>
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['Employee_ID'] ?>" <?= ((int)$s['Employee_ID'] === $assigned) ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['Login']) ?><?= !empty($s['BillingRate']) ? ' ($' . number_format((float)$s['BillingRate'], 0) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="number" name="Proj_Task_Order" value="<?= htmlspecialchars((string)($t['Proj_Task_Order'] ?? 0)) ?>" style="width:55px"></td>
    <td class="actions">
      <input type="submit" value="Save">
  </form>
      <form method="post" class="inline" onsubmit="return confirm('Delete this task?');">
        <input type="hidden" name="action" value="drop_task">
        <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
        <input type="submit" class="danger" value="X">
      </form>
    </td>
</tr>
<?php endforeach; ?>

<tr class="add-row">
  <form method="post" class="inline">
    <input type="hidden" name="action" value="add_task">
    <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
    <td>
      <select name="Task_Type_ID" required>
        <option value="">-- pick a task --</option>
        <?php foreach ($taskTypes as $tt): ?>
          <option value="<?= (int)$tt['Task_ID'] ?>"><?= htmlspecialchars($tt['Task_Name']) ?> (<?= (float)$tt['Estimated_Time'] ?>h)</option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="Description" placeholder="(override description)" style="width:99%"></td>
    <td><input type="number" step="0.05" name="Weight" value="1" style="width:55px"></td>
    <td>—</td>
    <td>
      <select name="Assigned_To">
        <option value="">— unassigned —</option>
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['Employee_ID'] ?>"><?= htmlspecialchars($s['Login']) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="number" name="Proj_Task_Order" value="0" style="width:55px"></td>
    <td class="actions"><input type="submit" value="+ Add Task"></td>
  </form>
</tr>
<tr class="totals"><td colspan="3" align="right">Stage subtotal:</td><td><?= number_format($stageHrs, 2) ?> hrs</td><td colspan="3"></td></tr>
</table>
<?php
    $grandHrs += $stageHrs;
endforeach;
?>

<!-- Add stage form -->
<table>
<tr class="add-row">
  <form method="post" class="inline">
    <input type="hidden" name="action" value="add_stage">
    <td colspan="7">
      <strong>Add new stage:</strong>
      <select name="Stage_Type_ID" required>
        <option value="">-- stage type --</option>
        <?php foreach ($stageTypes as $st): ?>
          <option value="<?= (int)$st['Stage_Type_ID'] ?>"><?= htmlspecialchars($st['Stage_Type_Name']) ?></option>
        <?php endforeach; ?>
      </select>
      Weight: <input type="number" step="0.05" name="Weight" value="1" style="width:55px">
      Description: <input type="text" name="Description" style="width:280px">
      <span class="actions"><input type="submit" value="+ Add Stage"></span>
    </td>
  </form>
</tr>
<tr class="totals"><td colspan="3" align="right">Grand total estimated hours:</td><td><?= number_format($grandHrs, 2) ?> hrs</td><td colspan="3"></td></tr>
</table>

</body>
</html>
