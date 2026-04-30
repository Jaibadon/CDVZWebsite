<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();
$proj_id = (int)($_GET['proj_id'] ?? $_POST['proj_id'] ?? 0);
if ($proj_id <= 0) die('Missing proj_id');

// ── Inline Project_Type update (handle FIRST so reload reflects the new type)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_project_type') {
    $newType = (isset($_POST['Project_Type']) && $_POST['Project_Type'] !== '')
        ? (int)$_POST['Project_Type'] : null;
    $upd = $pdo->prepare("UPDATE Projects SET Project_Type = ? WHERE proj_id = ?");
    $upd->execute([$newType, $proj_id]);
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

// ── Inline Project Description update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_description') {
    $description = $_POST['Job_Description'] ?? '';
    $upd = $pdo->prepare("UPDATE Projects SET Job_Description = ? WHERE proj_id = ?");
    $upd->execute([$description, $proj_id]);
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

$proj = $pdo->prepare("SELECT proj_id, JobName, Project_Type, Job_Description FROM Projects WHERE proj_id = ?");
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('Project not found');

// Editor settings (consumed by stages_editor.php)
$mode             = 'project';
$owner_id         = $proj_id;
$owner_label      = 'Project: #' . (int)$proj['proj_id'] . ' — ' . ($proj['JobName'] ?? '');
$stages_table     = 'Project_Stages';
$tasks_table      = 'Project_Tasks';
$stage_owner_col  = 'Proj_ID';
$task_owner_col   = 'Project_Stage_ID';
$back_url         = 'updateform_admin1.php?proj_id=' . $proj_id;

// Lookup data for the quick-action panel
$projectTypeId = (int)($proj['Project_Type'] ?? 0);

$projectTypes = $pdo->query(
    "SELECT Project_Type_ID, Project_Type_Name FROM Project_Types ORDER BY Project_Type_Name"
)->fetchAll();

// All templates for this project's type (newest first)
$availableTpls = [];
if ($projectTypeId > 0) {
    $stmt = $pdo->prepare(
        "SELECT Template_ID, template_name FROM Proj_Templates
          WHERE Project_Type_ID = ?
          ORDER BY Template_ID DESC"
    );
    $stmt->execute([$projectTypeId]);
    $availableTpls = $stmt->fetchAll();
}

// Build the quick-action panel
ob_start();
?>
<div style="background:#fff;border:1px solid #ccc;padding:10px;max-width:1100px;margin:0 0 10px;">
  <!-- Project type selector -->
  <form method="post" style="display:inline-block;margin-right:14px;border-right:1px solid #ddd;padding-right:14px">
    <input type="hidden" name="action" value="update_project_type">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <strong>Project Type:</strong>
    <select name="Project_Type" onchange="this.form.submit()">
      <option value="">-- none --</option>
      <?php foreach ($projectTypes as $pt): ?>
        <option value="<?= (int)$pt['Project_Type_ID'] ?>"
                <?= ((int)$pt['Project_Type_ID'] === $projectTypeId) ? 'selected' : '' ?>>
          <?= htmlspecialchars($pt['Project_Type_Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><input type="submit" value="Save"></noscript>
  </form>

  <!-- Project Description -->
  <form method="post" style="display:block;margin:10px 0;">
    <input type="hidden" name="action" value="update_description">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <strong>Project Description:</strong>
    <textarea name="Job_Description" 
              style="display:block;width:100%;min-height:80px;margin:5px 0;padding:8px;font-family:Arial,sans-serif;font-size:13px;border:1px solid #ccc;border-radius:3px;"
              placeholder="Enter project description..."><?= htmlspecialchars($proj['Job_Description'] ?? '') ?></textarea>
    <input type="submit" value="Save Description" 
           style="background:#555;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
  </form>

  <!-- Templates for this project type -->
  <?php if ($projectTypeId === 0): ?>
    <em style="color:#888">Pick a project type to see available templates.</em>
  <?php elseif (count($availableTpls) === 0): ?>
    <em style="color:#888">No templates exist for this project type yet — save the current stages below to create one.</em>
  <?php else: ?>
    <strong>Apply template:</strong>
    <?php foreach ($availableTpls as $i => $t): ?>
      <form method="post" action="template_apply.php" style="display:inline"
            onsubmit="return confirm('Apply template &quot;<?= htmlspecialchars(addslashes($t['template_name'])) ?>&quot;? Existing stages and tasks will NOT be deleted — the template will be added on top.');">
        <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
        <input type="hidden" name="Template_ID" value="<?= (int)$t['Template_ID'] ?>">
        <input type="submit"
               value="<?= htmlspecialchars($t['template_name']) ?><?= ($i === 0) ? ' (latest)' : '' ?>"
               style="background:#9B9B1B;color:#fff;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:11px">
      </form>
    <?php endforeach; ?>
  <?php endif; ?>

  <hr style="border:none;border-top:1px solid #eee;margin:8px 0">

  <!-- Save / Print actions -->
  <form method="post" action="template_save.php" style="display:inline"
        onsubmit="this.template_name.value = prompt('Name for the new template:', '<?= htmlspecialchars(addslashes($proj['JobName'] ?? '')) ?>'); return this.template_name.value !== null && this.template_name.value !== '';">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <input type="hidden" name="template_name" value="">
    <input type="submit" value="Save current stages as Template"
           style="background:#555;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
  </form>
  <a href="quote.php?proj_id=<?= $proj_id ?>" target="_blank"
     style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Quote</a>
  <a href="checklist.php?proj_id=<?= $proj_id ?>" target="_blank"
     style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Checklist</a>
  <a href="templates.php"
     style="background:#777;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Manage all templates</a>
</div>
<?php
$quickActions = ob_get_clean();

// Render the editor and splice the quick-action panel in just under <h1>
ob_start();
include __DIR__ . '/stages_editor.php';
$content = ob_get_clean();
$content = preg_replace('#(<h1[^>]*>.*?</h1>)#s', '$1' . $quickActions, $content, 1);
echo $content;
