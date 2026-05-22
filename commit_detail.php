<?php
/**
 * commit_detail.php?commit_id=N — full coordination record for one commit.
 *
 * Internal (logged-in staff) view of: the commit metadata, NZBC tags,
 * element summary, PDFs, every transmittal sent for it + each recipient's
 * view/ack state, and the comment thread. This is the "did the structural
 * engineer actually acknowledge Rev C?" page — the audit answer.
 *
 * ?commit_id=N&dl=<sha256>  → streams that commit's PDF (session-auth'd,
 *                              staff only; the magic-link page has its own
 *                              token-auth'd streamer for stakeholders).
 */

require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$commitId = (int)($_GET['commit_id'] ?? 0);
if ($commitId <= 0) die('<p>Missing commit_id. <a href="projects.php">Back</a></p>');

// ── PDF download (staff, session-auth'd) ─────────────────────────────────
if (isset($_GET['dl'])) {
    $sha = (string)$_GET['dl'];
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) { http_response_code(400); die('bad id'); }
    $bq = $pdo->prepare(
        "SELECT b.Filesystem_Path, cb.Path_In_Project
           FROM Commit_Blobs cb INNER JOIN Blobs b ON b.Sha256 = cb.Blob_Sha256
          WHERE cb.Commit_ID = ? AND cb.Blob_Sha256 = ? LIMIT 1"
    );
    $bq->execute([$commitId, $sha]);
    $blob = $bq->fetch();
    if (!$blob || empty($blob['Filesystem_Path']) || !defined('CADVIZ_BLOB_ARCHIVE_PATH')) { http_response_code(404); die('not found'); }
    $abs = rtrim(CADVIZ_BLOB_ARCHIVE_PATH, '/\\') . DIRECTORY_SEPARATOR . $blob['Filesystem_Path'];
    if (!is_file($abs)) { http_response_code(404); die('file missing'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename((string)$blob['Path_In_Project']) . '"');
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}

// ── Load commit + project ────────────────────────────────────────────────
$c = $pdo->prepare(
    "SELECT cm.*, p.JobName, p.proj_id AS PID, cl.Client_Name
       FROM Commits cm
       LEFT JOIN Projects p  ON cm.Proj_ID  = p.proj_id
       LEFT JOIN Clients  cl ON p.Client_ID = cl.Client_id
      WHERE cm.Commit_ID = ?"
);
$c->execute([$commitId]);
$cm = $c->fetch();
if (!$cm) die('<p>Commit not found. <a href="projects.php">Back</a></p>');

$projId   = (int)$cm['Proj_ID'];
$revision = trim((string)($cm['Revision_Label'] ?? '')) ?: ('#' . $commitId);

// ── Re-send a transmittal (reminder / retry) ─────────────────────────────
// Reuses the existing tokens + Transmittals row — no duplicate transmittal.
$resendFlash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $tid = (int)($_POST['transmittal_id'] ?? 0);
    try {
        if (!defined('TRANSMITTAL_RESEND_LIBRARY_ONLY')) define('TRANSMITTAL_RESEND_LIBRARY_ONLY', true);
        require_once __DIR__ . '/transmittal_resend.php';
        $rr = resend_transmittal($pdo, $tid, $user, true);
        $msg = 'Re-sent transmittal #' . $tid . ' to ' . count($rr['sent'])
            . ' un-acknowledged recipient(s)'
            . ($rr['skipped_acked'] ? ', skipped ' . $rr['skipped_acked'] . ' already-acked' : '')
            . (count($rr['failed']) ? ', ' . count($rr['failed']) . ' failed' : '')
            . '. PDFs attached: ' . ($rr['pdfs_attached'] ?? 0) . ' of ' . ($rr['pdfs_registered'] ?? 0) . '.';
        if (!empty($rr['warnings'])) {
            $msg .= "\nPDF warnings:\n  • " . implode("\n  • ", $rr['warnings']);
        }
        $_SESSION['cd_flash'] = $msg;
    } catch (Exception $e) {
        $_SESSION['cd_flash'] = 'Re-send failed: ' . $e->getMessage();
    }
    header('Location: commit_detail.php?commit_id=' . $commitId);
    exit;
}
$resendFlash = $_SESSION['cd_flash'] ?? '';
unset($_SESSION['cd_flash']);

// NZBC tags
$tg = $pdo->prepare("SELECT Clause_Code, Source, Tagged_By, Tagged_At FROM Commit_NZBC_Tags WHERE Commit_ID = ? ORDER BY Clause_Code");
$tg->execute([$commitId]);
$tags = $tg->fetchAll();

// Element summary by category
$es = $pdo->prepare("SELECT Category, COUNT(*) n FROM Element_Instances WHERE Commit_ID = ? GROUP BY Category ORDER BY n DESC");
$es->execute([$commitId]);
$elementCats = $es->fetchAll();
$elementTotal = array_sum(array_column($elementCats, 'n'));

// PDFs
$pf = $pdo->prepare(
    "SELECT cb.Blob_Sha256, cb.Path_In_Project, b.Size_Bytes
       FROM Commit_Blobs cb INNER JOIN Blobs b ON b.Sha256 = cb.Blob_Sha256
      WHERE cb.Commit_ID = ? AND cb.Role = 'pdf_output' ORDER BY cb.Path_In_Project"
);
$pf->execute([$commitId]);
$pdfs = $pf->fetchAll();

// Transmittals + recipients
$tx = $pdo->prepare(
    "SELECT t.Transmittal_ID, t.Revision_Label, t.Sent_At, t.Sent_By, t.Subject, t.Message
       FROM Transmittals t WHERE t.Commit_ID = ? ORDER BY t.Sent_At DESC"
);
$tx->execute([$commitId]);
$transmittals = $tx->fetchAll();

$recipByTx = [];
if (!empty($transmittals)) {
    $ids = array_column($transmittals, 'Transmittal_ID');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $rq = $pdo->prepare(
        "SELECT tr.*, sh.Name AS ShName, sh.Email AS ShEmail, sh.Role AS ShRole, sh.Role_Label AS ShRoleLabel
           FROM Transmittal_Recipients tr
           LEFT JOIN Project_Stakeholders sh ON tr.Stakeholder_ID = sh.Stakeholder_ID
          WHERE tr.Transmittal_ID IN ($in)
          ORDER BY tr.Recipient_ID"
    );
    $rq->execute($ids);
    foreach ($rq->fetchAll() as $r) {
        $recipByTx[(int)$r['Transmittal_ID']][] = $r;
    }
}

// Comment thread
$cc = $pdo->prepare("SELECT Author_Name, Body, Posted_At, Stakeholder_ID FROM Commit_Comments WHERE Commit_ID = ? ORDER BY Posted_At ASC");
$cc->execute([$commitId]);
$comments = $cc->fetchAll();

function fmtdt($v): string { $t = $v ? strtotime((string)$v) : 0; return $t ? date('j M Y g:i a', $t) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Commit #<?= $commitId ?> — <?= htmlspecialchars((string)$cm['JobName']) ?></title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:1000px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:5px; padding:14px 16px; margin:12px 0; }
.card h2 { margin:0 0 10px; font-size:15px; color:#9B9B1B; border-bottom:1px solid #eee; padding-bottom:6px; }
.kv { display:grid; grid-template-columns:160px 1fr; gap:4px 12px; }
.kv .k { color:#888; }
.tag { display:inline-block; background:#fff3cd; border:1px solid #c8a52e; color:#7a5a00; padding:2px 9px; border-radius:11px; font-size:12px; margin:2px 4px 2px 0; }
.tag .src { color:#a98; font-size:10px; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { padding:5px 8px; text-align:left; border-bottom:1px solid #eee; vertical-align:top; }
th { background:#f4f4f4; }
.badge-ok   { background:#d6f5d6; color:#1a6b1a; padding:1px 7px; border-radius:9px; font-size:11px; }
.badge-wait { background:#fff3cd; color:#7a5a00; padding:1px 7px; border-radius:9px; font-size:11px; }
.badge-none { background:#eee;    color:#888;    padding:1px 7px; border-radius:9px; font-size:11px; }
.comment { border-left:3px solid #ddd; padding:4px 0 4px 12px; margin:8px 0; }
.comment .meta { font-size:11px; color:#888; }
.muted { color:#888; }
</style>
</head>
<body>
<div class="topbar">
  <h1>Commit #<?= $commitId ?> — <?= htmlspecialchars((string)$cm['JobName']) ?> · Rev <?= htmlspecialchars($revision) ?></h1>
  <div>
    <a href="commit_history.php?proj_id=<?= $projId ?>">← Project history</a>
    &nbsp;·&nbsp; <a href="project_stages.php?proj_id=<?= $projId ?>">Stages</a>
    &nbsp;·&nbsp; <a href="menu.php">Menu</a>
  </div>
</div>

<div class="page">

  <?php if ($resendFlash):
      $hasWarn = strpos($resendFlash, 'warnings') !== false || strpos($resendFlash, 'failed') !== false;
  ?>
    <div style="background:<?= $hasWarn ? '#fff3cd' : '#d6f5d6' ?>;border:1px solid <?= $hasWarn ? '#c8a52e' : '#1a6b1a' ?>;color:<?= $hasWarn ? '#7a5a00' : '#1a6b1a' ?>;padding:8px 12px;border-radius:4px;margin:10px 0;white-space:pre-line"><?= htmlspecialchars($resendFlash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Commit</h2>
    <div class="kv">
      <div class="k">Project</div><div><?= htmlspecialchars((string)$cm['JobName']) ?><?= $cm['Client_Name'] ? ' · ' . htmlspecialchars((string)$cm['Client_Name']) : '' ?></div>
      <div class="k">Revision</div><div><strong><?= htmlspecialchars($revision) ?></strong> · status <code><?= htmlspecialchars((string)$cm['Status']) ?></code><?= (int)$cm['Is_Superseded'] === 1 ? ' · <span class="muted">superseded</span>' : '' ?></div>
      <div class="k">Message</div><div><?= nl2br(htmlspecialchars((string)$cm['Message'])) ?></div>
      <div class="k">Author / when</div><div><?= htmlspecialchars((string)$cm['Author_UserID']) ?> · <?= fmtdt($cm['Created_At']) ?></div>
      <div class="k">IFC git SHA</div><div><code><?= htmlspecialchars(substr((string)$cm['Ifc_Git_Sha'], 0, 16)) ?></code></div>
      <div class="k">Revit backup</div><div><?= $cm['Rvt_Backup_Number'] !== null ? 'project.' . str_pad((string)$cm['Rvt_Backup_Number'], 4, '0', STR_PAD_LEFT) . '.rvt' : '<span class="muted">not recorded</span>' ?></div>
      <div class="k">Parent commit</div><div><?= $cm['Parent_Commit_ID'] ? '<a href="commit_detail.php?commit_id=' . (int)$cm['Parent_Commit_ID'] . '">#' . (int)$cm['Parent_Commit_ID'] . '</a>' : '<span class="muted">none (first commit)</span>' ?></div>
    </div>
  </div>

  <div class="card">
    <h2>NZBC tags &amp; elements</h2>
    <div style="margin-bottom:8px">
      <?php if (empty($tags)): ?><span class="muted">No NZBC clauses tagged.</span>
      <?php else: foreach ($tags as $t): ?>
        <span class="tag"><?= htmlspecialchars((string)$t['Clause_Code']) ?> <span class="src">(<?= htmlspecialchars((string)$t['Source']) ?>)</span></span>
      <?php endforeach; endif; ?>
    </div>
    <div class="muted"><strong><?= number_format($elementTotal) ?></strong> elements:
      <?php foreach ($elementCats as $i => $ec): ?><?= $i ? ' · ' : '' ?><?= htmlspecialchars((string)$ec['Category']) ?> <?= (int)$ec['n'] ?><?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>Drawings (<?= count($pdfs) ?>)</h2>
    <?php if (empty($pdfs)): ?><span class="muted">No PDFs attached to this commit.</span>
    <?php else: ?>
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($pdfs as $pd): ?>
          <li>
            <a href="commit_detail.php?commit_id=<?= $commitId ?>&dl=<?= urlencode((string)$pd['Blob_Sha256']) ?>" target="_blank"><?= htmlspecialchars((string)$pd['Path_In_Project']) ?></a>
            <span class="muted">(<?= number_format(((int)$pd['Size_Bytes'])/1024/1024, 1) ?> MB)</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Transmittals &amp; acknowledgements</h2>
    <?php if (empty($transmittals)): ?>
      <p class="muted">No transmittals sent for this commit yet — nobody has been formally notified of this revision.</p>
    <?php else: foreach ($transmittals as $t):
        $recips = $recipByTx[(int)$t['Transmittal_ID']] ?? [];
        $ackd = count(array_filter($recips, fn($r) => !empty($r['Acked_At'])));
    ?>
      <div style="margin:10px 0;border:1px solid #eee;border-radius:4px">
        <div style="background:#f6f6e2;padding:6px 10px;font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:10px">
          <div>
            <strong>Transmittal #<?= (int)$t['Transmittal_ID'] ?></strong> · sent <?= fmtdt($t['Sent_At']) ?> by <?= htmlspecialchars((string)$t['Sent_By']) ?>
            · <strong><?= $ackd ?>/<?= count($recips) ?></strong> acknowledged
            <?php if (!empty($t['Subject'])): ?><br><span class="muted">“<?= htmlspecialchars((string)$t['Subject']) ?>”</span><?php endif; ?>
          </div>
          <?php if ($ackd < count($recips)): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Re-send transmittal #<?= (int)$t['Transmittal_ID'] ?> to the <?= count($recips) - $ackd ?> recipient(s) who haven\'t acknowledged yet? Reuses their existing review link — no duplicate transmittal is created.');">
              <input type="hidden" name="action" value="resend">
              <input type="hidden" name="transmittal_id" value="<?= (int)$t['Transmittal_ID'] ?>">
              <button type="submit" style="background:#c33;color:#fff;border:none;padding:4px 10px;border-radius:3px;cursor:pointer;font-size:11px;white-space:nowrap">🔁 Re-send to <?= count($recips) - $ackd ?> un-acked</button>
            </form>
          <?php endif; ?>
        </div>
        <table>
          <thead><tr><th>Recipient</th><th>Sent</th><th>First viewed</th><th>Views</th><th>Acknowledged</th></tr></thead>
          <tbody>
            <?php foreach ($recips as $r):
                $name  = $r['ShName']  ?: ($r['Ad_Hoc_Name']  ?: 'Reviewer');
                $email = $r['ShEmail'] ?: ($r['Ad_Hoc_Email'] ?: '');
                $role  = $r['ShRoleLabel'] ?: ($r['ShRole'] ?: 'ad-hoc');
            ?>
              <tr>
                <td><strong><?= htmlspecialchars((string)$name) ?></strong><br><span class="muted"><?= htmlspecialchars((string)$role) ?> · <?= htmlspecialchars((string)$email) ?></span></td>
                <td><?= fmtdt($r['Sent_At']) ?></td>
                <td><?= $r['First_Viewed_At'] ? fmtdt($r['First_Viewed_At']) : '<span class="badge-none">not viewed</span>' ?></td>
                <td><?= (int)$r['View_Count'] ?></td>
                <td>
                  <?php if (!empty($r['Acked_At'])): ?>
                    <span class="badge-ok">✓ <?= fmtdt($r['Acked_At']) ?></span>
                    <?php if (!empty($r['Ack_Comment'])): ?><br><span class="muted">“<?= htmlspecialchars((string)$r['Ack_Comment']) ?>”</span><?php endif; ?>
                  <?php elseif (!empty($r['First_Viewed_At'])): ?>
                    <span class="badge-wait">viewed, not acked</span>
                  <?php else: ?>
                    <span class="badge-none">awaiting</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>Coordination thread (<?= count($comments) ?>)</h2>
    <?php if (empty($comments)): ?><span class="muted">No comments.</span>
    <?php else: foreach ($comments as $cmt): ?>
      <div class="comment">
        <div><?= nl2br(htmlspecialchars((string)$cmt['Body'])) ?></div>
        <div class="meta">— <?= htmlspecialchars((string)($cmt['Author_Name'] ?: 'CADViz')) ?>
          <?= $cmt['Stakeholder_ID'] ? '' : '<span style="color:#9B9B1B">(CADViz)</span>' ?>
          · <?= fmtdt($cmt['Posted_At']) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

</div>
</body>
</html>
