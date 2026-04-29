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

$proj = $pdo->prepare("SELECT proj_id, JobName, Project_Type FROM Projects WHERE proj_id = ?");
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('Project not found');

// Editor settings
$mode             = 'project';
$owner_id         = $proj_id;
$owner_label      = 'Project: #' . (int)$proj['proj_id'] . ' — ' . ($proj['JobName'] ?? '');
$stages_table     = 'Project_Stages';
$tasks_table      = 'Project_Tasks';
$stage_owner_col  = 'Proj_ID';
$task_owner_col   = 'Project_Stage_ID';
$back_url         = 'updateform_admin1.php?proj_id=' . $proj_id;

// Action shortcuts (rendered above the editor)
$projectTypeId = (int)($proj['Project_Type'] ?? 0);
$latestTpl = null;
if ($projectTypeId > 0) {
    $stmt = $pdo->prepare("SELECT Template_ID, template_name FROM Proj_Templates WHERE Project_Type_ID = ? ORDER BY Template_ID DESC LIMIT 1");
    $stmt->execute([$projectTypeId]);
    $latestTpl = $stmt->fetch();
}
ob_start();
?>
<div style="background:#fff;border:1px solid #ccc;padding:10px;max-width:1100px;margin:0 0 10px;">
  <strong>Quick actions:</strong>
  <?php if ($latestTpl): ?>
    <form method="post" action="template_apply.php" style="display:inline" onsubmit="return confirm('Apply template &quot;<?= htmlspecialchars(addslashes($latestTpl['template_name'])) ?>&quot;? Existing stages and tasks for this project will NOT be deleted — the template will be added on top.');">
      <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
      <input type="hidden" name="Template_ID" value="<?= (int)$latestTpl['Template_ID'] ?>">
      <input type="submit" value="Apply latest template (<?= htmlspecialchars($latestTpl['template_name']) ?>)" style="background:#9B9B1B;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
    </form>
  <?php else: ?>
    <em style="color:#888">No template exists for this project's type yet — save the current stages as a template below to start one.</em>
  <?php endif; ?>
  <form method="post" action="template_save.php" style="display:inline" onsubmit="this.template_name.value = prompt('Name for the new template:', '<?= htmlspecialchars(addslashes($proj['JobName'] ?? '')) ?>'); return this.template_name.value !== null && this.template_name.value !== '';">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <input type="hidden" name="template_name" value="">
    <input type="submit" value="Save current stages as Template" style="background:#555;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
  </form>
  <a href="quote.php?proj_id=<?= $proj_id ?>" target="_blank" style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Quote</a>
  <a href="checklist.php?proj_id=<?= $proj_id ?>" target="_blank" style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Checklist</a>
</div>
<?php
$quickActions = ob_get_clean();

// Inject the quick actions into the editor by capturing its output
ob_start();
include __DIR__ . '/stages_editor.php';
$content = ob_get_clean();
// Insert quick actions just after the <h1> line
$content = preg_replace('#(<h1[^>]*>.*?</h1>)#s', '$1' . $quickActions, $content, 1);
echo $content;
