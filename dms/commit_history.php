<?php
/**
 * commit_history.php?proj_id=N — a project's revision history.
 *
 * Newest commit first. Per commit: revision, message, author, date, NZBC
 * tags, and the transmittal ack progress (X/Y acknowledged, oldest
 * un-acked age). Links into commit_detail.php for the full record.
 */

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../db_connect.php';

$pdo  = get_db();
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) die('<p>Missing proj_id. <a href="../projects.php">Back</a></p>');

$proj = $pdo->prepare("SELECT proj_id, JobName, Client_ID FROM Projects WHERE proj_id = ?");
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('<p>Project not found.</p>');

$cl = '';
if (!empty($proj['Client_ID'])) {
    $cs = $pdo->prepare("SELECT Client_Name FROM Clients WHERE Client_id = ?");
    $cs->execute([(int)$proj['Client_ID']]);
    $cl = (string)($cs->fetchColumn() ?: '');
}

$cstmt = $pdo->prepare(
    "SELECT Commit_ID, Revision_Label, Message, Author_UserID, Created_At, Status, Is_Superseded
       FROM Commits WHERE Proj_ID = ? ORDER BY Commit_ID DESC"
);
$cstmt->execute([$proj_id]);
$commits = $cstmt->fetchAll();

// Per-commit ack rollup (one query, grouped) + tag rollup
$ackByCommit = []; $tagByCommit = [];
if (!empty($commits)) {
    $ids = array_column($commits, 'Commit_ID');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $aq = $pdo->prepare(
        "SELECT t.Commit_ID,
                COUNT(tr.Recipient_ID) AS recips,
                SUM(CASE WHEN tr.Acked_At IS NOT NULL THEN 1 ELSE 0 END) AS acked,
                MIN(CASE WHEN tr.Acked_At IS NULL THEN tr.Sent_At END)   AS oldest_unacked
           FROM Transmittals t
           INNER JOIN Transmittal_Recipients tr ON tr.Transmittal_ID = t.Transmittal_ID
          WHERE t.Commit_ID IN ($in)
          GROUP BY t.Commit_ID"
    );
    $aq->execute($ids);
    foreach ($aq->fetchAll() as $r) $ackByCommit[(int)$r['Commit_ID']] = $r;

    $tq = $pdo->prepare("SELECT Commit_ID, GROUP_CONCAT(DISTINCT Clause_Code ORDER BY Clause_Code) AS tags FROM Commit_NZBC_Tags WHERE Commit_ID IN ($in) GROUP BY Commit_ID");
    $tq->execute($ids);
    foreach ($tq->fetchAll() as $r) $tagByCommit[(int)$r['Commit_ID']] = (string)$r['tags'];
}

function dtdays($v): string {
    if (!$v) return '';
    $t = strtotime((string)$v); if (!$t) return '';
    $d = (int)floor((time() - $t) / 86400);
    return $d <= 0 ? 'today' : ($d . 'd ago');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Revision history — <?= htmlspecialchars((string)$proj['JobName']) ?></title>
<link href="../site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:1000px; margin:0 auto; padding:16px; }
table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #ddd; border-radius:5px; overflow:hidden; }
th, td { padding:8px 10px; text-align:left; border-bottom:1px solid #eee; vertical-align:top; }
th { background:#f4f4f4; font-size:12px; }
tr:hover td { background:#fafad2; }
.tag { display:inline-block; background:#fff3cd; border:1px solid #c8a52e; color:#7a5a00; padding:1px 6px; border-radius:9px; font-size:10px; margin:1px 2px; }
.badge-ok   { background:#d6f5d6; color:#1a6b1a; padding:1px 7px; border-radius:9px; font-size:11px; }
.badge-wait { background:#ffe4d6; color:#a00;    padding:1px 7px; border-radius:9px; font-size:11px; }
.badge-none { background:#eee;    color:#888;    padding:1px 7px; border-radius:9px; font-size:11px; }
.muted { color:#888; }
a.rev { font-weight:600; color:#9B9B1B; text-decoration:none; }
a.rev:hover { text-decoration:underline; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📂 Revision history — <?= htmlspecialchars((string)$proj['JobName']) ?><?= $cl ? ' · ' . htmlspecialchars($cl) : '' ?></h1>
  <div><a href="../project_stages.php?proj_id=<?= $proj_id ?>">← Stages</a> &nbsp;·&nbsp; <a href="../menu.php">Menu</a></div>
</div>

<div class="page">
  <?php if (empty($commits)): ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:5px;padding:30px;text-align:center;color:#888">
      No commits on this project yet. Revisions appear here once staff publish them via the BIM app.
    </div>
  <?php else: ?>
    <p class="muted"><?= count($commits) ?> revision<?= count($commits) === 1 ? '' : 's' ?></p>
    <table>
      <thead>
        <tr><th>Rev</th><th>Message</th><th>Author</th><th>When</th><th>NZBC</th><th>Acknowledgement</th></tr>
      </thead>
      <tbody>
        <?php foreach ($commits as $c):
            $cid = (int)$c['Commit_ID'];
            $rev = trim((string)($c['Revision_Label'] ?? '')) ?: ('#' . $cid);
            $ack = $ackByCommit[$cid] ?? null;
        ?>
          <tr>
            <td>
              <a class="rev" href="commit_detail.php?commit_id=<?= $cid ?>"><?= htmlspecialchars($rev) ?></a>
              <?php if ((int)$c['Is_Superseded'] === 1): ?><br><span class="muted" style="font-size:10px">superseded</span><?php endif; ?>
            </td>
            <td><?= htmlspecialchars(mb_strimwidth((string)$c['Message'], 0, 140, '…')) ?></td>
            <td><?= htmlspecialchars((string)$c['Author_UserID']) ?></td>
            <td class="muted"><?= htmlspecialchars(date('j M Y', strtotime((string)$c['Created_At']))) ?></td>
            <td>
              <?php if (!empty($tagByCommit[$cid])): foreach (explode(',', $tagByCommit[$cid]) as $tg): ?>
                <span class="tag"><?= htmlspecialchars($tg) ?></span>
              <?php endforeach; else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!$ack): ?>
                <span class="badge-none">not transmitted</span>
              <?php else:
                  $r = (int)$ack['recips']; $a = (int)$ack['acked'];
                  if ($a >= $r): ?>
                    <span class="badge-ok">✓ all <?= $r ?> acknowledged</span>
                  <?php else: ?>
                    <span class="badge-wait"><?= $a ?>/<?= $r ?> acked</span>
                    <?php if (!empty($ack['oldest_unacked'])): ?>
                      <span class="muted">· oldest sent <?= dtdays($ack['oldest_unacked']) ?></span>
                    <?php endif; ?>
                  <?php endif;
              endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
