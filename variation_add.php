<?php
/**
 * Staff-facing form to request an unapproved variation on a project.
 * Creates a Project_Variations row (Status='unapproved') with a single
 * variation-scoped stage + the tasks the staff member specifies.
 *
 * Erik / Jen review and either approve, reject, or edit via stages_editor.php.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$empId = (int)($_SESSION['Employee_id'] ?? 0);
$err   = '';
$success = '';

// ── Submit ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_variation') {
    try {
        $projId = (int)($_POST['proj_id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $stageTypeId = (int)($_POST['Stage_Type_ID'] ?? 0);
        $ack    = ($_POST['ack'] ?? '') === 'yes';

        if (!$projId)     throw new Exception('Pick a project.');
        if ($title === '') throw new Exception('Title is required.');
        if (!$ack)         throw new Exception('You must acknowledge the estimate disclaimer.');
        if (!$stageTypeId) throw new Exception('Pick a stage type.');

        // Tasks: arrays indexed 0..n-1
        $taskTypeIds = $_POST['Task_Type_ID'] ?? [];
        $taskHours   = $_POST['Hours'] ?? [];
        $taskDescs   = $_POST['TaskDesc'] ?? [];
        $rowsToInsert = [];
        for ($i = 0; $i < count($taskTypeIds); $i++) {
            $tt = (int)($taskTypeIds[$i] ?? 0);
            $hr = (float)($taskHours[$i] ?? 0);
            if ($tt > 0 && $hr > 0) {
                $rowsToInsert[] = [
                    'task_type_id' => $tt,
                    'hours'        => $hr,
                    'desc'         => trim($taskDescs[$i] ?? ''),
                ];
            }
        }
        if (empty($rowsToInsert)) throw new Exception('Add at least one task with hours.');

        $pdo->beginTransaction();

        // Per-project variation number = max+1
        $vnum = (int)$pdo->prepare("SELECT COALESCE(MAX(Variation_Number),0)+1 FROM Project_Variations WHERE Proj_ID = ?")
                        ->execute([$projId]) ?: 1;
        // (execute returns bool — re-fetch properly)
        $st = $pdo->prepare("SELECT COALESCE(MAX(Variation_Number),0)+1 AS n FROM Project_Variations WHERE Proj_ID = ?");
        $st->execute([$projId]);
        $vnum = (int)$st->fetchColumn();

        // Create variation
        $ins = $pdo->prepare("INSERT INTO Project_Variations
            (Proj_ID, Variation_Number, Title, Description, Status, Date_Created, Created_By)
            VALUES (?, ?, ?, ?, 'unapproved', CURDATE(), ?)");
        $ins->execute([$projId, $vnum, $title, $desc, $empId]);
        $variationId = (int)$pdo->lastInsertId();

        // Create one stage in this variation
        $nextStageId = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM Project_Stages")->fetchColumn();
        $pdo->prepare("INSERT INTO Project_Stages
            (Project_Stage_ID, Proj_ID, Variation_ID, Stage_Type_ID, Description, Weight, Notes)
            VALUES (?, ?, ?, ?, ?, 1, '')")
           ->execute([$nextStageId, $projId, $variationId, $stageTypeId, $title]);

        // Create tasks
        $nextTaskId = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1 FROM Project_Tasks")->fetchColumn();
        $taskIns = $pdo->prepare("INSERT INTO Project_Tasks
            (Proj_Task_ID, Project_Stage_ID, Variation_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order)
            VALUES (?, ?, ?, ?, ?, ?, '', ?)");
        $order = 0;
        foreach ($rowsToInsert as $row) {
            // Use Weight to scale Tasks_Types.Estimated_Time so the staff-entered
            // hours become the effective estimate. weight = hours / base_estimate.
            $base = (float)$pdo->query("SELECT Estimated_Time FROM Tasks_Types WHERE Task_ID = " . (int)$row['task_type_id'])->fetchColumn();
            $weight = ($base > 0) ? ($row['hours'] / $base) : 1;
            $taskIns->execute([
                $nextTaskId, $nextStageId, $variationId, $row['task_type_id'],
                $row['desc'], $weight, $order++,
            ]);
            $nextTaskId++;
        }

        $pdo->commit();
        // No email — Erik sees pending variations in the menu.php banner
        // when he next logs in. Cleaner than spam to his inbox.
        $success = "Variation #$vnum submitted. Erik/Jen will review next time they log in (the menu shows pending variations).";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

// ── Load options ──────────────────────────────────────────────────────────
// Projects the staff member is on (Manager / DP1 / DP2 / DP3 OR has timesheet entries on)
$projects = $pdo->prepare(
    "SELECT DISTINCT p.proj_id, p.JobName
       FROM Projects p
      WHERE p.Active <> 0
        AND (p.Manager = :e OR p.DP1 = :e OR p.DP2 = :e OR p.DP3 = :e
             OR p.proj_id IN (SELECT proj_id FROM Timesheets WHERE Employee_id = :e))
      ORDER BY p.JobName"
);
$projects->execute([':e' => $empId]);
$projects = $projects->fetchAll();

$stageTypes = $pdo->query("SELECT Stage_Type_ID, Stage_Type_Name FROM Stage_Types ORDER BY Stage_Order, Stage_Type_Name")->fetchAll();
$taskTypes  = $pdo->query("SELECT Task_ID, Task_Name, Estimated_Time FROM Tasks_Types ORDER BY Task_Name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Add Unapproved Variation</title>
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet">
<style>
.warn-box { background:#fff3cd; border:1px solid #c33; border-radius:4px; padding:10px 14px; margin:10px 0; color:#7a0000; }
.task-row { background:#fafafa; padding:6px; margin:4px 0; border-radius:3px; display:grid; grid-template-columns:2fr 2fr 90px 24px; gap:6px; align-items:center; }
.btn-add { background:#5577aa; color:#fff; padding:5px 12px; border:none; border-radius:3px; cursor:pointer; font-size:12px; }
.btn-submit-red { background:#c33; color:#fff; padding:8px 16px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; font-size:14px; }
.btn-submit-red:hover { background:#a22; }
@media (max-width:700px) { .task-row { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="page">
  <div class="topnav">
    <a href="main.php">&larr; Back to Timesheet</a>
    <h1 style="color:#c33">Request Unapproved Variation</h1>
  </div>

  <?php if ($success): ?>
    <div class="card" style="background:#d6f5d6;border-color:#1a6b1a;color:#1a6b1a">
      <strong><?= htmlspecialchars($success) ?></strong><br>
      <a href="main.php">Return to timesheet</a>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="card" style="background:#ffd6d6;border-color:#a00;color:#a00">
      <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <div class="warn-box">
    <strong>⚠ Unapproved variation</strong><br>
    A variation captures extra work the client requested beyond the original quote. It will be visible on your
    timesheet immediately (with a red warning badge), but Erik / Jen need to approve it before invoicing.
    Estimate carefully — your hourly estimates form the budget for the variation.
  </div>

  <form method="post" class="card" id="vform">
    <input type="hidden" name="action" value="create_variation">

    <p>
      <label><strong>Project:</strong></label>
      <select name="proj_id" required>
        <option value="">— pick a project you're working on —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['proj_id'] ?>"><?= htmlspecialchars($p['JobName']) ?></option>
        <?php endforeach; ?>
      </select>
    </p>

    <p>
      <label><strong>Variation title:</strong></label>
      <input type="text" name="title" required style="width:340px" placeholder="e.g. Extra bedroom, Re-clad request">
    </p>

    <p>
      <label><strong>Description / what changed from the original quote:</strong></label><br>
      <textarea name="description" rows="3" style="width:96%" placeholder="What did the client ask for? What's the impact on the original scope?"></textarea>
    </p>

    <p>
      <label><strong>Variation stage:</strong></label>
      <select name="Stage_Type_ID" required>
        <option value="">— pick a stage —</option>
        <?php foreach ($stageTypes as $s): ?>
          <option value="<?= (int)$s['Stage_Type_ID'] ?>"><?= htmlspecialchars($s['Stage_Type_Name']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="subtle" style="font-size:11px;color:#666">(which design stage will this variation work fall under?)</span>
    </p>

    <h3>Tasks &amp; estimated hours</h3>
    <div id="task-rows">
      <div class="task-row">
        <select name="Task_Type_ID[]" required>
          <option value="">— pick task —</option>
          <?php foreach ($taskTypes as $t): ?>
            <option value="<?= (int)$t['Task_ID'] ?>"><?= htmlspecialchars($t['Task_Name']) ?> (default <?= (float)$t['Estimated_Time'] ?>h)</option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="TaskDesc[]" placeholder="Custom description (optional)">
        <input type="number" step="0.25" min="0" name="Hours[]" placeholder="hours" required>
        <button type="button" onclick="this.parentElement.remove()" title="Remove task" style="background:#c33;color:#fff;border:none;border-radius:3px;cursor:pointer">×</button>
      </div>
    </div>
    <p><button type="button" class="btn-add" onclick="addRow()">+ Add another task</button></p>

    <p style="margin-top:18px;padding:10px;background:#fff3cd;border-left:4px solid #c33;border-radius:3px">
      <label>
        <input type="checkbox" name="ack" value="yes" required>
        <strong>I understand that I need to make correct hourly estimates and this will need to be approved by Erik before it counts toward billing.</strong>
      </label>
    </p>

    <p>
      <input type="submit" value="Submit unapproved variation" class="btn-submit-red">
      <a href="main.php" style="margin-left:10px">Cancel</a>
    </p>
  </form>
</div>

<script>
function addRow() {
    var src = document.querySelector('.task-row');
    var clone = src.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(i) { i.value = ''; });
    clone.querySelectorAll('select').forEach(function(s) { s.selectedIndex = 0; });
    document.getElementById('task-rows').appendChild(clone);
}
</script>
</body>
</html>
