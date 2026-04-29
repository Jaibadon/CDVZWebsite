<?php
/**
 * Snapshot a project's current stages + tasks into a new template.
 *
 * Mirrors the original Access SaveTemplate_Click() VBA.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();

$proj_id   = (int)($_POST['proj_id'] ?? $_GET['proj_id'] ?? 0);
$tplName   = trim($_POST['template_name'] ?? '');

if ($proj_id <= 0)        die('Missing proj_id');
if ($tplName === '')      die('Missing template name. <a href="javascript:history.back()">Back</a>');

// Get project type
$proj = $pdo->prepare("SELECT proj_id, JobName, Project_Type FROM Projects WHERE proj_id = ?");
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('Project not found');
$projType = (int)($proj['Project_Type'] ?? 0);

try {
    $pdo->beginTransaction();

    // Allocate the new Template_ID
    $newTplId = (int)$pdo->query("SELECT COALESCE(MAX(Template_ID),0)+1 FROM Proj_Templates")->fetchColumn();

    // Create template header
    $pdo->prepare("INSERT INTO Proj_Templates (Template_ID, Project_Type_ID, template_name) VALUES (?, ?, ?)")
        ->execute([$newTplId, $projType, $tplName]);

    // Pull project stages and copy each into Template_Stages with a fresh
    // Project_Stage_ID (so the template tasks can FK to it without colliding
    // with existing Project_Stages rows).
    $projStages = $pdo->prepare("SELECT * FROM Project_Stages WHERE Proj_ID = ? ORDER BY Project_Stage_ID");
    $projStages->execute([$proj_id]);

    $nextTplStageId = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM Template_Stages")->fetchColumn();
    $nextTplTaskId  = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1 FROM Template_Tasks")->fetchColumn();

    $tplStageInsert = $pdo->prepare(
        "INSERT INTO Template_Stages (Project_Stage_ID, Template_ID, Stage_Type_ID, Description, Weight, Notes)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $tplTaskInsert = $pdo->prepare(
        "INSERT INTO Template_Tasks (Proj_Task_ID, Template_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $projTasksFor = $pdo->prepare(
        "SELECT * FROM Project_Tasks WHERE Project_Stage_ID = ? ORDER BY Proj_Task_Order, Proj_Task_ID"
    );

    $stageCount = 0;
    $taskCount  = 0;

    while ($ps = $projStages->fetch(PDO::FETCH_ASSOC)) {
        $oldStageId = (int)$ps['Project_Stage_ID'];
        $newTplStageId = $nextTplStageId++;

        $tplStageInsert->execute([
            $newTplStageId,
            $newTplId,
            (int)$ps['Stage_Type_ID'],
            $ps['Description'] ?? '',
            (float)($ps['Weight'] ?? 1),
            $ps['Notes'] ?? '',
        ]);
        $stageCount++;

        $projTasksFor->execute([$oldStageId]);
        while ($pt = $projTasksFor->fetch(PDO::FETCH_ASSOC)) {
            $tplTaskInsert->execute([
                $nextTplTaskId++,
                $newTplId,
                $newTplStageId,
                (int)$pt['Task_Type_ID'],
                $pt['Description'] ?? '',
                (float)($pt['Weight'] ?? 1),
                $pt['Proj_Task_Notes'] ?? '',
                (int)($pt['Proj_Task_Order'] ?? 0),
            ]);
            $taskCount++;
        }
    }

    $pdo->commit();
    header('Location: template_stages.php?template_id=' . $newTplId . '&saved=1&s=' . $stageCount . '&t=' . $taskCount);
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo '<p style="color:red">Failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="project_stages.php?proj_id=' . $proj_id . '">Back to project stages</a></p>';
}
