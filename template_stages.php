<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();
$tid = (int)($_GET['template_id'] ?? $_POST['template_id'] ?? 0);
if ($tid <= 0) die('Missing template_id');

$tpl = $pdo->prepare(
    "SELECT pt.Template_ID, pt.template_name, pt.Project_Type_ID, ptype.Project_Type_Name
       FROM Proj_Templates pt
       LEFT JOIN Project_types ptype ON pt.Project_Type_ID = ptype.Project_Type_ID
      WHERE pt.Template_ID = ?"
);
$tpl->execute([$tid]);
$tpl = $tpl->fetch();
if (!$tpl) die('Template not found');

$mode             = 'template';
$owner_id         = $tid;
$owner_label      = 'Template: ' . ($tpl['template_name'] ?? '') . '  (Project Type: ' . ($tpl['Project_Type_Name'] ?? '') . ')';
$stages_table     = 'Template_Stages';
$tasks_table      = 'Template_Tasks';
$stage_owner_col  = 'Template_ID';
$task_owner_col   = 'Project_Stage_ID';
$back_url         = 'templates.php';

include __DIR__ . '/stages_editor.php';
