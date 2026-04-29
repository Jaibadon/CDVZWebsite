<?php
/**
 * Apply a template's stages + tasks to a project.
 *
 * Mirrors the original Access Filler_Click() VBA: copies each stage from
 * Template_Stages into Project_Stages (allocating a new Project_Stage_ID),
 * then copies each Template_Tasks row whose Project_Stage_ID was the
 * template's stage into Project_Tasks pointing at the *new* project stage.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();

$proj_id = (int)($_POST['proj_id']     ?? $_GET['proj_id']     ?? 0);
$tid     = (int)($_POST['Template_ID'] ?? $_GET['Template_ID'] ?? 0);

if ($proj_id <= 0 || $tid <= 0) {
    die('Missing proj_id or Template_ID. <a href="templates.php">Back</a>');
}

// Verify both exist
$ok = $pdo->prepare("SELECT 1 FROM Projects WHERE proj_id = ?");
$ok->execute([$proj_id]);
if (!$ok->fetchColumn()) die('Project not found');

$ok = $pdo->prepare("SELECT 1 FROM Proj_Templates WHERE Template_ID = ?");
$ok->execute([$tid]);
if (!$ok->fetchColumn()) die('Template not found');

try {
    $pdo->beginTransaction();

    // Pull all template stages
    $tplStages = $pdo->prepare("SELECT * FROM Template_Stages WHERE Template_ID = ? ORDER BY Project_Stage_ID");
    $tplStages->execute([$tid]);

    // Next-id allocators
    $nextStageId = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM Project_Stages")->fetchColumn();
    $nextTaskId  = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1     FROM Project_Tasks")->fetchColumn();

    $stageInsert = $pdo->prepare(
        "INSERT INTO Project_Stages (Project_Stage_ID, Proj_ID, Stage_Type_ID, Description, Weight, Notes)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $taskInsert = $pdo->prepare(
        "INSERT INTO Project_Tasks (Proj_Task_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $tplTasksFor = $pdo->prepare(
        "SELECT * FROM Template_Tasks WHERE Template_ID = ? AND Project_Stage_ID = ? ORDER BY Proj_Task_Order, Proj_Task_ID"
    );

    $stageCount = 0;
    $taskCount  = 0;

    while ($ts = $tplStages->fetch(PDO::FETCH_ASSOC)) {
        $oldStageId = (int)$ts['Project_Stage_ID'];
        $newStageId = $nextStageId++;

        $stageInsert->execute([
            $newStageId,
            $proj_id,
            (int)$ts['Stage_Type_ID'],
            $ts['Description'] ?? '',
            (float)($ts['Weight'] ?? 1),
            $ts['Notes'] ?? '',
        ]);
        $stageCount++;

        // Copy this stage's template tasks
        $tplTasksFor->execute([$tid, $oldStageId]);
        while ($tt = $tplTasksFor->fetch(PDO::FETCH_ASSOC)) {
            $taskInsert->execute([
                $nextTaskId++,
                $newStageId,
                (int)$tt['Task_Type_ID'],
                $tt['Description'] ?? '',
                (float)($tt['Weight'] ?? 1),
                $tt['Proj_Task_Notes'] ?? '',
                (int)($tt['Proj_Task_Order'] ?? 0),
            ]);
            $taskCount++;
        }
    }

    $pdo->commit();
    header('Location: project_stages.php?proj_id=' . $proj_id . '&applied=' . $tid . '&s=' . $stageCount . '&t=' . $taskCount);
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo '<p style="color:red">Failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="project_stages.php?proj_id=' . $proj_id . '">Back to project stages</a></p>';
}
