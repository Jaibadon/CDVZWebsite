<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();
$msg = '';

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['template_name'] ?? '');
        $type = (int)($_POST['Project_Type_ID'] ?? 0);
        if ($name !== '' && $type > 0) {
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Template_ID),0)+1 FROM Proj_Templates")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO Proj_Templates (Template_ID, Project_Type_ID, template_name) VALUES (?, ?, ?)");
            $stmt->execute([$next, $type, $name]);
            header('Location: template_stages.php?template_id=' . $next);
            exit;
        }
        $msg = '<span style="color:red">Name and project type are required.</span>';
    }

    if ($action === 'delete') {
        $tid = (int)$_POST['Template_ID'];
        $pdo->prepare("DELETE FROM Template_Tasks  WHERE Template_ID = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM Template_Stages WHERE Template_ID = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM Proj_Templates  WHERE Template_ID = ?")->execute([$tid]);
        header('Location: templates.php');
        exit;
    }
}

// ── Data ──────────────────────────────────────────────────────────────────
$templates = $pdo->query(
    "SELECT pt.Template_ID, pt.template_name, pt.Project_Type_ID, ptype.Project_Type_Name,
            (SELECT COUNT(*) FROM Template_Stages ts WHERE ts.Template_ID = pt.Template_ID) AS stage_count,
            (SELECT COUNT(*) FROM Template_Tasks  tt WHERE tt.Template_ID = pt.Template_ID) AS task_count
       FROM Proj_Templates pt
       LEFT JOIN Project_Types ptype ON pt.Project_Type_ID = ptype.Project_Type_ID
      ORDER BY ptype.Project_Type_Name, pt.template_name"
)->fetchAll();

$projectTypes = $pdo->query("SELECT Project_Type_ID, Project_Type_Name FROM Project_Types ORDER BY Project_Type_Name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Project Templates</title>
<link href="site.css" rel="stylesheet">
<link href="global.css" rel="stylesheet">
<style>
body { background:#EBEBEB; font-family:Arial,sans-serif; padding:20px; }
h1 { color:#9B9B1B; }
table { border-collapse:collapse; width:100%; max-width:900px; background:#fff; }
th { background:#9B9B1B; color:#fff; text-align:left; padding:6px 10px; }
td { padding:6px 10px; border-top:1px solid #ddd; }
tr:hover td { background:#f7f7e0; }
a.btn, button.btn, input[type=submit].btn {
    background:#9B9B1B; color:#fff; padding:5px 10px; text-decoration:none;
    border-radius:3px; border:none; cursor:pointer; font-size:12px;
}
a.btn:hover { background:#7a7a16; }
.create-form { background:#fff; padding:14px; margin:14px 0; max-width:900px; border:1px solid #ddd; }
.create-form input[type=text], .create-form select { padding:5px; }
.nav a { margin-right:14px; }
.danger { background:#c33; }
</style>
</head>
<body>
<div class="nav"><a href="menu.php">&larr; Main Menu</a> <a href="more.php">More Admin</a></div>
<h1>Project Templates</h1>
<?php if ($msg) echo "<p>$msg</p>"; ?>

<div class="create-form">
  <strong>Create new (blank) template:</strong>
  <form method="post" style="display:inline">
    <input type="hidden" name="action" value="create">
    <input type="text" name="template_name" placeholder="Template name" required>
    <select name="Project_Type_ID" required>
      <option value="">-- project type --</option>
      <?php foreach ($projectTypes as $pt): ?>
        <option value="<?= (int)$pt['Project_Type_ID'] ?>"><?= htmlspecialchars($pt['Project_Type_Name'] ?? '') ?></option>
      <?php endforeach; ?>
    </select>
    <input type="submit" class="btn" value="Create &amp; Edit">
  </form>
</div>

<table>
<tr><th>Template</th><th>Project Type</th><th>Stages</th><th>Tasks</th><th style="width:240px">Actions</th></tr>
<?php foreach ($templates as $t): ?>
<tr>
  <td><?= htmlspecialchars($t['template_name'] ?? '') ?></td>
  <td><?= htmlspecialchars($t['Project_Type_Name'] ?? '') ?></td>
  <td><?= (int)$t['stage_count'] ?></td>
  <td><?= (int)$t['task_count'] ?></td>
  <td>
    <a class="btn" href="template_stages.php?template_id=<?= (int)$t['Template_ID'] ?>">Edit</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete template &quot;<?= htmlspecialchars(addslashes($t['template_name'] ?? '')) ?>&quot;?');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="Template_ID" value="<?= (int)$t['Template_ID'] ?>">
      <input type="submit" class="btn danger" value="Delete">
    </form>
  </td>
</tr>
<?php endforeach; ?>
<?php if (count($templates) === 0): ?>
<tr><td colspan="5" style="color:#888">No templates yet. Create one above, or open a project's stages page and use "Save as Template".</td></tr>
<?php endif; ?>
</table>
</body>
</html>
