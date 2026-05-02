<?php
/**
 * Review the most recent dormancy sweep — see who was deactivated, restore
 * any that shouldn't have been, or trigger a manual re-run.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'restore') {
        $type = $_POST['entity_type'] ?? '';
        $id   = (int)($_POST['entity_id'] ?? 0);
        if ($type === 'project' && $id > 0) {
            $pdo->prepare("UPDATE Projects SET Active = 1 WHERE proj_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE Dormancy_Log SET restored_at = NOW() WHERE entity_type = 'project' AND entity_id = ? AND restored_at IS NULL")->execute([$id]);
        } elseif ($type === 'client' && $id > 0) {
            $pdo->prepare("UPDATE Clients SET Active = 1 WHERE Client_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE Dormancy_Log SET restored_at = NOW() WHERE entity_type = 'client' AND entity_id = ? AND restored_at IS NULL")->execute([$id]);
        }
    } elseif ($action === 'rerun') {
        // Force a re-run by clearing the throttle key
        meta_set($pdo, 'dormancy_last_run', '');
        $r = dormancy_sweep_run($pdo);
        $_SESSION['flash'] = "Sweep ran: deactivated {$r['projects']} projects, {$r['clients']} clients.";
    }
    header('Location: dormancy_sweep.php');
    exit;
}

$lastRunAt = meta_get($pdo, 'dormancy_last_run_at') ?: '(never)';
try {
    $rows = $pdo->query(
        "SELECT * FROM Dormancy_Log
         WHERE swept_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
         ORDER BY swept_at DESC, id DESC LIMIT 500"
    )->fetchAll();
} catch (Exception $e) { $rows = []; }
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="site.css" rel="stylesheet"><title>Dormancy Sweep</title></head>
<body><div class="page">
<div class="topnav"><a href="menu.php">&larr; Main Menu</a><h1>Dormancy Sweep</h1></div>

<?php if (!empty($_SESSION['flash'])): ?><div class="card" style="background:#d6f5d6;border-color:#1a6b1a;color:#1a6b1a"><?= htmlspecialchars($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>

<div class="card">
  <p>Auto-deactivates jobs and clients with no activity in the last 5 years. Runs once per month on Erik's first menu load. Last run: <strong><?= htmlspecialchars($lastRunAt) ?></strong>.</p>
  <form method="post" style="display:inline" onsubmit="return confirm('Force a manual re-run now?');">
    <input type="hidden" name="action" value="rerun">
    <input type="submit" value="Run sweep now" class="btn-secondary">
  </form>
</div>

<h2>Recent deactivations (last 90 days)</h2>
<?php if (empty($rows)): ?>
  <p><em>None.</em></p>
<?php else: ?>
<table class="table">
  <tr><th>When</th><th>Type</th><th>Name</th><th>Last activity</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['swept_at']) ?></td>
    <td><?= htmlspecialchars($r['entity_type']) ?></td>
    <td><?= htmlspecialchars($r['entity_label'] ?? '#' . $r['entity_id']) ?></td>
    <td><?= htmlspecialchars($r['last_activity'] ?? '—') ?></td>
    <td><?= $r['restored_at'] ? '<span class="badge badge-ok">restored</span>' : '<span class="badge badge-bad">deactivated</span>' ?></td>
    <td>
      <?php if (!$r['restored_at']): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="entity_type" value="<?= htmlspecialchars($r['entity_type']) ?>">
        <input type="hidden" name="entity_id" value="<?= (int)$r['entity_id'] ?>">
        <input type="submit" value="Restore" class="btn-secondary">
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
</div></body></html>
