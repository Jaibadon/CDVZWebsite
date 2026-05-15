<?php
/**
 * clients_view.php — staff-facing clients page.
 *
 * Read-only list of active clients with their active projects, so any
 * logged-in staff member can answer "what projects is this client on" and
 * "who's the client of this project" without needing admin access to the
 * full client-edit page.
 *
 * Admin (erik/jen) still get clients.php / clients_archive.php for full
 * CRUD. This is the lightweight read-only view for everyone else.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$isAdmin = in_array($user, ['erik','jen'], true);

$q = trim((string)($_GET['q'] ?? ''));

// Active clients with their active projects in one query. LEFT JOIN so
// clients without active projects still appear.
$sql = "SELECT c.Client_id, c.Client_Name, c.Phone, c.Mobile, c.email,
               c.billing_email, c.Active AS Client_Active,
               p.proj_id, p.JobName, p.Active AS Proj_Active, p.Manager,
               s.Login AS ManagerLogin
          FROM Clients c
          LEFT JOIN Projects p ON p.Client_ID = c.Client_id AND p.Active <> 0
          LEFT JOIN Staff s    ON p.Manager   = s.Employee_ID
         WHERE (c.Active IS NULL OR c.Active <> 0)";

$params = [];
if ($q !== '') {
    $sql .= " AND (c.Client_Name LIKE :q OR p.JobName LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " ORDER BY c.Client_Name, p.JobName";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group rows by Client_id
$byClient = [];
foreach ($rows as $r) {
    $cid = (int)$r['Client_id'];
    if (!isset($byClient[$cid])) {
        $byClient[$cid] = [
            'Client_id'     => $cid,
            'Client_Name'   => $r['Client_Name'],
            'Phone'         => $r['Phone'],
            'Mobile'        => $r['Mobile'],
            'email'         => $r['email'],
            'billing_email' => $r['billing_email'],
            'projects'      => [],
        ];
    }
    if (!empty($r['proj_id'])) {
        $byClient[$cid]['projects'][] = [
            'proj_id'        => (int)$r['proj_id'],
            'JobName'        => $r['JobName'],
            'ManagerLogin'   => $r['ManagerLogin'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clients — CADViz</title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.search { background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); padding:4px 10px; border-radius:3px; }
.search input { background:transparent; border:none; color:#fff; outline:none; font:inherit; width:200px; }
.search input::placeholder { color:rgba(255,255,255,0.7); }
.page { max-width:1100px; margin:0 auto; padding:16px; }
.client-card { background:#fff; border:1px solid #ddd; border-radius:4px; margin:8px 0; padding:10px 14px; }
.client-name { font-weight:600; font-size:14px; color:#444; }
.client-contact { font-size:11px; color:#888; margin-top:2px; }
.projects { margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; }
.proj-chip {
  display:inline-flex; align-items:center;
  background:#f6f6e2; border:1px solid #d3d3a5;
  padding:3px 9px; border-radius:11px;
  font-size:12px; text-decoration:none; color:#444;
}
.proj-chip:hover { background:#fafad2; }
.proj-chip .mgr { color:#999; font-size:10px; margin-left:4px; }
.no-projects { font-size:11px; color:#888; font-style:italic; }
.muted { color:#888; font-size:12px; }
</style>
</head>
<body>
<div class="topbar">
  <h1>👥 Clients</h1>
  <form method="get" class="search">
    🔍 <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="search clients / projects…" autofocus>
  </form>
  <div>
    <a href="menu.php" style="font-size:13px">Menu</a>
    <?php if ($isAdmin): ?>
      &nbsp;·&nbsp;
      <a href="clients.php"            style="font-size:13px">Edit clients</a>
      &nbsp;·&nbsp;
      <a href="clients_archive.php"    style="font-size:13px">Archive</a>
    <?php endif; ?>
  </div>
</div>

<div class="page">
  <p class="muted">Active clients and their active projects. Click a project to open it.
    <?php if (!$isAdmin): ?>
      Admin pages (edit clients / new client / archive) are restricted to Erik / Jen.
    <?php endif; ?>
  </p>

  <?php if (empty($byClient)): ?>
    <div class="client-card">
      <?= $q === '' ? 'No active clients.' : 'No clients or projects match "' . htmlspecialchars($q) . '".' ?>
    </div>
  <?php else: ?>
    <p class="muted"><?= count($byClient) ?> client<?= count($byClient) === 1 ? '' : 's' ?>
      &middot; <?= array_sum(array_map(fn($c) => count($c['projects']), $byClient)) ?> active project<?= array_sum(array_map(fn($c) => count($c['projects']), $byClient)) === 1 ? '' : 's' ?>
    </p>

    <?php foreach ($byClient as $c):
        $phone = trim((string)($c['Phone'] ?? ''));
        $mobile = trim((string)($c['Mobile'] ?? ''));
        $email  = trim((string)($c['email'] ?? '')) ?: trim((string)($c['billing_email'] ?? ''));
    ?>
      <div class="client-card">
        <div class="client-name">
          <?php if ($isAdmin): ?>
            <a href="client_updateform.php?client_id=<?= $c['Client_id'] ?>" style="color:#444;text-decoration:none"><?= htmlspecialchars($c['Client_Name']) ?></a>
          <?php else: ?>
            <?= htmlspecialchars($c['Client_Name']) ?>
          <?php endif; ?>
        </div>
        <?php if ($phone || $mobile || $email): ?>
          <div class="client-contact">
            <?php if ($phone): ?>📞 <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $phone)) ?>" style="color:#888"><?= htmlspecialchars($phone) ?></a>&nbsp;<?php endif; ?>
            <?php if ($mobile): ?>📱 <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $mobile)) ?>" style="color:#888"><?= htmlspecialchars($mobile) ?></a>&nbsp;<?php endif; ?>
            <?php if ($email): ?>✉ <a href="mailto:<?= htmlspecialchars($email) ?>" style="color:#888"><?= htmlspecialchars($email) ?></a><?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="projects">
          <?php if (empty($c['projects'])): ?>
            <span class="no-projects">no active projects</span>
          <?php else: foreach ($c['projects'] as $p): ?>
            <a class="proj-chip" href="my_checklist.php?proj_id=<?= $p['proj_id'] ?>">
              <?= htmlspecialchars((string)$p['JobName']) ?>
              <?php if ($p['ManagerLogin']): ?>
                <span class="mgr">· <?= htmlspecialchars((string)$p['ManagerLogin']) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>
