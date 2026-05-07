<?php
/**
 * Generate quote from spec — wizard.
 *
 * Three steps in one page:
 *   1. Pick which spec categories the project has (multi-checkbox).
 *   2. Page reloads showing all task types under the chosen cats'
 *      sub-cats. Each task is checked by default; admin un-ticks any
 *      they don't want.
 *   3. Submit → for every selected task, ensure the project has a
 *      Project_Stages row of the matching Stage_ID (creating one if
 *      not), then INSERT a Project_Tasks row pointing at it.
 *
 * Entry points: button on project_stages.php ("📋 Generate from spec")
 * which links here with ?proj_id=N.
 *
 * Behaviour:
 *   - Append, don't replace. Existing tasks stay. Duplicate tasks (same
 *     Task_Type_ID) ARE created — admins can dedupe in stages_editor
 *     after if needed.
 *   - Stages: matched/created on a per-task basis. Each Tasks_Types row
 *     has Stage_ID (= Stage_Type_ID). We look up an existing
 *     Project_Stages row for this project + that Stage_Type_ID
 *     (Variation_ID NULL). If none, create it.
 *   - Quoted_Rate snapshot is set per-task at TBA on insert (matches
 *     the quote-builder's add_task path).
 *   - Only runs when project is in DRAFT (Quote_Status NULL or 'draft')
 *     — refuses on accepted projects to keep the variation flow clean.
 */

session_start();
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); echo '<p>Admin only.</p>'; exit;
}

$pdo = get_db();
$proj_id = (int)($_GET['proj_id'] ?? $_POST['proj_id'] ?? 0);
if ($proj_id <= 0) {
    echo '<p>Missing proj_id. <a href="projects.php">Project list</a></p>'; exit;
}

// Project header + status check
$projStmt = $pdo->prepare("SELECT proj_id, JobName, Client_ID, COALESCE(Quote_Status, 'draft') AS Quote_Status FROM Projects WHERE proj_id = ?");
$projStmt->execute([$proj_id]);
$proj = $projStmt->fetch();
if (!$proj) { echo '<p>Project not found.</p>'; exit; }

if ($proj['Quote_Status'] === 'accepted') {
    echo '<div style="max-width:700px;margin:30px auto;padding:14px 18px;background:#ffd6d6;border:2px solid #c33;color:#a00;border-radius:6px">'
       . '<h2 style="margin:0 0 6px">Project is locked (Quote accepted)</h2>'
       . '<p>The spec wizard only runs in draft mode. To bulk-add tasks here, either reset the quote to draft first (<a href="project_stages.php?proj_id=' . $proj_id . '">project stages</a>) or add them via the variation flow.</p></div>';
    exit;
}

// Detect Quoted_Rate column for snapshotting
$hasQuotedRate = false;
try { $hasQuotedRate = (bool)$pdo->query("SHOW COLUMNS FROM Project_Tasks LIKE 'Quoted_Rate'")->fetch(); } catch (Exception $e) {}
$tbaRate = get_tba_rate($pdo);

$flash = ''; $flashErr = '';

// ── POST: actually create stages + tasks ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $taskIds = array_values(array_unique(array_map('intval', $_POST['task_ids'] ?? [])));
    if (empty($taskIds)) {
        $flashErr = 'Pick at least one task.';
    } else {
        try {
            $pdo->beginTransaction();
            // Pre-build a Stage_Type_ID → Project_Stage_ID map. Existing
            // stages are reused; missing ones get created on demand.
            $existingStages = $pdo->prepare(
                "SELECT Stage_Type_ID, Project_Stage_ID
                   FROM Project_Stages
                  WHERE Proj_ID = ? AND (Variation_ID IS NULL OR Variation_ID = 0)"
            );
            $existingStages->execute([$proj_id]);
            $stageMap = [];
            foreach ($existingStages->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stageMap[(int)$r['Stage_Type_ID']] = (int)$r['Project_Stage_ID'];
            }

            // Pull task type rows for the selected IDs in one query.
            $in = implode(',', array_fill(0, count($taskIds), '?'));
            $tts = $pdo->prepare("SELECT Task_ID, Task_Name, Stage_ID, COALESCE(Estimated_Time, 0) AS Estimated_Time, COALESCE(Task_Order, 0) AS Task_Order FROM Tasks_Types WHERE Task_ID IN ($in) ORDER BY Stage_ID, Task_Order");
            $tts->execute($taskIds);
            $taskRows = $tts->fetchAll();

            $nextStageId = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM Project_Stages")->fetchColumn();
            $nextTaskId  = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1   FROM Project_Tasks")->fetchColumn();

            $createdStages = 0; $createdTasks = 0;

            foreach ($taskRows as $tt) {
                $stid = (int)$tt['Stage_ID'];
                if ($stid <= 0) continue;  // task type with no stage — skip

                if (!isset($stageMap[$stid])) {
                    // Pull stage type name + order so the new Project_Stages
                    // row is reasonably labelled.
                    $stTypeStmt = $pdo->prepare("SELECT Stage_Type_Name FROM Stage_Types WHERE Stage_Type_ID = ?");
                    $stTypeStmt->execute([$stid]);
                    $stName = (string)($stTypeStmt->fetchColumn() ?: 'Stage');

                    // Project_Stages columns vary by install — use the
                    // standard set the rest of the codebase relies on.
                    $stageInsCols = ['Project_Stage_ID', 'Proj_ID', 'Stage_Type_ID', 'Description', 'Weight'];
                    $stageInsVals = [$nextStageId, $proj_id, $stid, $stName, 1];
                    // Variation_ID NULL where the column exists.
                    try {
                        $hasVarCol = (bool)$pdo->query("SHOW COLUMNS FROM Project_Stages LIKE 'Variation_ID'")->fetch();
                    } catch (Exception $e) { $hasVarCol = false; }
                    if ($hasVarCol) { $stageInsCols[] = 'Variation_ID'; $stageInsVals[] = null; }

                    $ph = implode(',', array_fill(0, count($stageInsCols), '?'));
                    $pdo->prepare("INSERT INTO Project_Stages (`" . implode('`,`', $stageInsCols) . "`) VALUES ($ph)")
                        ->execute($stageInsVals);
                    $stageMap[$stid] = $nextStageId;
                    $nextStageId++;
                    $createdStages++;
                }

                // Insert the project task pointing at the (existing or just-created) stage.
                $taskInsCols = ['Proj_Task_ID', 'Project_Stage_ID', 'Task_Type_ID', 'Description', 'Weight', 'Proj_Task_Notes', 'Proj_Task_Order', 'Assigned_To'];
                $taskInsVals = [$nextTaskId, $stageMap[$stid], (int)$tt['Task_ID'], '', 1, '', (int)$tt['Task_Order'], null];
                if ($hasQuotedRate) {
                    $taskInsCols[] = 'Quoted_Rate';
                    $taskInsVals[] = $tbaRate;
                }
                $ph = implode(',', array_fill(0, count($taskInsCols), '?'));
                $pdo->prepare("INSERT INTO Project_Tasks (`" . implode('`,`', $taskInsCols) . "`) VALUES ($ph)")
                    ->execute($taskInsVals);
                $nextTaskId++;
                $createdTasks++;
            }

            $pdo->commit();
            $_SESSION['xero_flash'] = "Generated $createdTasks task(s) across $createdStages new stage(s) for this project.";
            header('Location: project_stages.php?proj_id=' . $proj_id);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flashErr = 'Failed: ' . $e->getMessage();
        }
    }
}

// ── Read state for rendering ────────────────────────────────────────────
$selectedCats = array_values(array_unique(array_map('intval', $_POST['cat_ids'] ?? $_GET['cat_ids'] ?? [])));

$cats = $pdo->query("SELECT Spec_Cat_ID, Spec_Cat_Name, Spec_Cat_Order FROM Spec_Cats ORDER BY COALESCE(Spec_Cat_Order, 9999), Spec_Cat_Name")->fetchAll();

// When cats are picked, pull the candidate task types (joined to subcat → cat).
$tasksByCat = [];
if (!empty($selectedCats)) {
    $in = implode(',', array_fill(0, count($selectedCats), '?'));
    $stmt = $pdo->prepare(
        "SELECT tt.Task_ID, tt.Task_Name, tt.Estimated_Time, tt.Stage_ID,
                stg.Stage_Type_Name, stg.Stage_Order,
                sc.Spec_SubCat_ID, sc.Spec_SubCat_Name,
                cat.Spec_Cat_ID, cat.Spec_Cat_Name
           FROM Tasks_Types tt
           JOIN Spec_SubCats sc ON tt.Spec_Subcat_ID = sc.Spec_SubCat_ID
           JOIN Spec_Cats    cat ON sc.Spec_Cat_ID    = cat.Spec_Cat_ID
           LEFT JOIN Stage_Types stg ON tt.Stage_ID    = stg.Stage_Type_ID
          WHERE cat.Spec_Cat_ID IN ($in)
            AND COALESCE(sc.Internal_Use_Only, 0) = 0
          ORDER BY cat.Spec_Cat_Order, cat.Spec_Cat_Name, sc.Spec_SubCat_Order, sc.Spec_SubCat_Name, stg.Stage_Order, tt.Task_Order, tt.Task_Name"
    );
    $stmt->execute($selectedCats);
    foreach ($stmt->fetchAll() as $r) {
        $tasksByCat[(int)$r['Spec_Cat_ID']][(int)$r['Spec_SubCat_ID']]['name'] = $r['Spec_SubCat_Name'];
        $tasksByCat[(int)$r['Spec_Cat_ID']][(int)$r['Spec_SubCat_ID']]['cat_name'] = $r['Spec_Cat_Name'];
        $tasksByCat[(int)$r['Spec_Cat_ID']][(int)$r['Spec_SubCat_ID']]['tasks'][] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generate quote from spec — <?= htmlspecialchars($proj['JobName'] ?? '') ?></title>
<link href="site.css" rel="stylesheet">
<style>
.page { max-width: 980px; margin: 20px auto; }
.card { background:#fff; padding:14px 18px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:14px; }
.cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:8px; }
.cat-grid label { display:flex; align-items:center; gap:6px; padding:6px 10px; background:#f6f6e2; border-radius:4px; cursor:pointer; }
.cat-grid label:hover { background:#eaeacb; }
.subcat-block { margin:14px 0 8px; }
.subcat-block h4 { margin:0 0 4px; padding:4px 8px; background:#f4f4d8; font-size:12px; }
.task-row { display:flex; align-items:center; gap:8px; padding:3px 8px 3px 18px; font-size:12px; border-bottom:1px solid #f0f0f0; }
.task-row:hover { background:#fafafa; }
.task-row .stage { color:#888; font-size:11px; min-width:140px; }
.task-row .hrs { color:#888; font-size:11px; min-width:50px; text-align:right; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:8px 16px; border-radius:3px; cursor:pointer; font-size:13px; }
.btn.primary { background:#246; }
.muted { color:#666; font-size:12px; }
.flash-err{ background:#ffd6d6; border:1px solid #c33; color:#a00; padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.cat-summary { background:#eef; padding:6px 10px; border-radius:4px; margin-bottom:12px; font-size:12px; color:#246; }
</style>
</head><body>
<div class="page">
  <div class="card">
    <h1 style="margin:0">Generate quote from spec</h1>
    <p class="muted">Project: <strong><?= htmlspecialchars($proj['JobName'] ?? '') ?></strong> (#<?= $proj_id ?>) &middot; <a href="project_stages.php?proj_id=<?= $proj_id ?>">Back to project stages</a> &middot; <a href="spec_admin.php">Manage categories</a></p>
  </div>

  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <!-- ── Step 1: pick categories ────────────────────────────────── -->
  <form method="post">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <div class="card">
      <h3 style="margin:0 0 8px">1. Which spec categories does this project have?</h3>
      <p class="muted">Tick all that apply. Submitting this step pulls every task type under the chosen categories' sub-categories so you can prune the list before generating.</p>
      <div class="cat-grid">
        <?php foreach ($cats as $c):
          $cid = (int)$c['Spec_Cat_ID'];
          $checked = in_array($cid, $selectedCats, true);
        ?>
          <label><input type="checkbox" name="cat_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
            <?= htmlspecialchars($c['Spec_Cat_Name']) ?></label>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:12px"><button type="submit" class="btn">Show tasks &rarr;</button></p>
    </div>
  </form>

  <!-- ── Step 2: pick tasks ────────────────────────────────────── -->
  <?php if (!empty($selectedCats) && !empty($tasksByCat)):
      $totalTasks = 0;
      foreach ($tasksByCat as $catSubs) foreach ($catSubs as $sub) $totalTasks += count($sub['tasks'] ?? []);
  ?>
    <form method="post">
      <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
      <input type="hidden" name="action"  value="generate">
      <?php foreach ($selectedCats as $cid): ?>
        <input type="hidden" name="cat_ids[]" value="<?= $cid ?>">
      <?php endforeach; ?>

      <div class="card">
        <h3 style="margin:0 0 8px">2. Pick tasks (<?= $totalTasks ?> match across the chosen categories)</h3>
        <div class="cat-summary">
          Bulk: <a href="javascript:void(0)" onclick="document.querySelectorAll('input[name=task_ids[]]').forEach(c=>c.checked=true)">select all</a>
              · <a href="javascript:void(0)" onclick="document.querySelectorAll('input[name=task_ids[]]').forEach(c=>c.checked=false)">deselect all</a>
        </div>
        <?php foreach ($selectedCats as $cid):
          if (empty($tasksByCat[$cid])) continue;
          $catName = '';
          foreach ($tasksByCat[$cid] as $sub) { $catName = $sub['cat_name']; break; }
        ?>
          <h3 style="margin:14px 0 4px;color:#9B9B1B"><?= htmlspecialchars($catName) ?></h3>
          <?php foreach ($tasksByCat[$cid] as $sid => $sub): ?>
            <div class="subcat-block">
              <h4><?= htmlspecialchars($sub['name']) ?> <span class="muted">(<?= count($sub['tasks']) ?>)</span></h4>
              <?php foreach ($sub['tasks'] as $t): ?>
                <label class="task-row">
                  <input type="checkbox" name="task_ids[]" value="<?= (int)$t['Task_ID'] ?>" checked>
                  <span style="flex:1"><?= htmlspecialchars($t['Task_Name']) ?></span>
                  <span class="stage"><?= htmlspecialchars($t['Stage_Type_Name'] ?? '?') ?></span>
                  <span class="hrs"><?= number_format((float)$t['Estimated_Time'], 2) ?>h</span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <p>Submitting will append <strong>checked tasks</strong> to the project. Stages get created on demand based on each task type's <code>Stage_ID</code>. The project stays in draft mode for further editing in <a href="project_stages.php?proj_id=<?= $proj_id ?>">stages_editor</a>.</p>
        <p><button type="submit" class="btn primary">Generate &amp; return to project stages</button></p>
      </div>
    </form>
  <?php elseif (!empty($selectedCats) && empty($tasksByCat)): ?>
    <div class="card">
      <p class="muted">No task types are currently tagged to sub-categories under the chosen categories. Either tag some via <a href="task_types_admin.php">Task Types</a> first, or pick different categories.</p>
    </div>
  <?php endif; ?>

</div></body></html>
