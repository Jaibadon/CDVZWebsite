<?php
/**
 * transmittal_view.php?t=<64-hex-token> — magic-link revision review page.
 *
 * NO auth_check.php — the token IS the authentication. Knowing a valid
 * Transmittal_Recipients.Magic_Token grants scoped read of exactly that
 * one transmittal's commit + its PDFs, plus the ability to comment and
 * acknowledge. Tokens are 32 random bytes (64 hex) — unguessable.
 *
 * Modes:
 *   ?t=TOKEN                 → the review page (tracks first/last view + count)
 *   ?t=TOKEN&pdf=<sha256>    → streams that PDF (only if it belongs to this
 *                              token's commit — prevents blob enumeration)
 *   POST action=comment      → append a Commit_Comments row
 *   POST action=ack          → set Acked_At on the recipient row
 *
 * Acknowledging is per (transmittal, recipient). A later revision creates a
 * NEW transmittal with fresh un-acked rows, so re-issuing a drawing
 * correctly invalidates the previous sign-off.
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../helpers.php';

$pdo = get_db();

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(400);
    die('<!doctype html><meta charset="utf-8"><body style="font-family:Arial;padding:40px;color:#a00">Invalid or missing review link.</body>');
}

// ── Resolve the token → recipient → transmittal → commit → project ──────
$q = $pdo->prepare(
    "SELECT tr.Recipient_ID, tr.Transmittal_ID, tr.Stakeholder_ID, tr.Magic_Token,
            tr.First_Viewed_At, tr.View_Count, tr.Acked_At, tr.Ack_Comment,
            tr.Approval_Status, tr.Approval_At,
            t.Commit_ID, t.Revision_Label, t.Sent_At, t.Sent_By, t.Subject, t.Message AS TransMessage,
            cm.Proj_ID, cm.Message AS CommitMessage, cm.Ifc_Git_Sha, cm.Created_At AS CommitCreatedAt,
            cm.Rvt_Backup_Number,
            p.JobName, cl.Client_Name,
            tr.Ad_Hoc_Email, tr.Ad_Hoc_Name,
            sh.Name AS StakeholderName, sh.Email AS StakeholderEmail,
            sh.Role AS StakeholderRole, sh.Role_Label AS StakeholderRoleLabel
       FROM Transmittal_Recipients tr
       INNER JOIN Transmittals t  ON tr.Transmittal_ID = t.Transmittal_ID
       INNER JOIN Commits      cm ON t.Commit_ID       = cm.Commit_ID
       LEFT  JOIN Projects     p  ON cm.Proj_ID        = p.proj_id
       LEFT  JOIN Clients      cl ON p.Client_ID       = cl.Client_id
       LEFT  JOIN Project_Stakeholders sh ON tr.Stakeholder_ID = sh.Stakeholder_ID
      WHERE tr.Magic_Token = ?"
);
$q->execute([$token]);
$ctx = $q->fetch();
if (!$ctx) {
    http_response_code(404);
    die('<!doctype html><meta charset="utf-8"><body style="font-family:Arial;padding:40px;color:#a00">This review link is not valid. It may have been superseded by a newer revision — contact CADViz.</body>');
}

$commitId = (int)$ctx['Commit_ID'];

// Resolve the reviewer's display identity — either a project stakeholder
// (joined above) or an ad-hoc recipient (Ad_Hoc_Email/Name on the
// recipient row, Stakeholder_ID NULL).
$recipName  = (string)($ctx['StakeholderName']  ?: $ctx['Ad_Hoc_Name']  ?: 'Reviewer');
$recipEmail = (string)($ctx['StakeholderEmail'] ?: $ctx['Ad_Hoc_Email'] ?: '');
$recipRole  = (string)($ctx['StakeholderRoleLabel'] ?: $ctx['StakeholderRole'] ?: 'External reviewer');

// ── PDF streaming sub-request ────────────────────────────────────────────
if (isset($_GET['pdf'])) {
    $sha = (string)$_GET['pdf'];
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) { http_response_code(400); die('bad pdf id'); }

    // The requested blob MUST be a pdf_output of THIS token's commit —
    // otherwise a recipient could enumerate other commits' files.
    $bq = $pdo->prepare(
        "SELECT b.Filesystem_Path, cb.Path_In_Project
           FROM Commit_Blobs cb
           INNER JOIN Blobs b ON b.Sha256 = cb.Blob_Sha256
          WHERE cb.Commit_ID = ? AND cb.Blob_Sha256 = ? AND cb.Role = 'pdf_output'
          LIMIT 1"
    );
    $bq->execute([$commitId, $sha]);
    $blob = $bq->fetch();
    if (!$blob || empty($blob['Filesystem_Path'])) { http_response_code(404); die('not found'); }
    if (!defined('CADVIZ_BLOB_ARCHIVE_PATH') || CADVIZ_BLOB_ARCHIVE_PATH === '') { http_response_code(500); die('archive not configured'); }

    $abs = rtrim(CADVIZ_BLOB_ARCHIVE_PATH, '/\\') . DIRECTORY_SEPARATOR . $blob['Filesystem_Path'];
    if (!is_file($abs)) { http_response_code(404); die('file missing'); }

    // db_connect.php starts an output buffer; discard it before streaming
    // binary so PDF bytes aren't preceded by stray output.
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename((string)$blob['Path_In_Project']) . '"');
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}

// ── POST handlers (comment / ack) ────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($action === 'comment') {
        $bodyTxt = trim((string)($_POST['body'] ?? ''));
        if ($bodyTxt !== '') {
            $pdo->prepare(
                "INSERT INTO Commit_Comments
                   (Commit_ID, Stakeholder_ID, Author_Name, Author_Email, Body, Posted_At, Posted_From_IP)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?)"
            )->execute([
                $commitId,
                $ctx['Stakeholder_ID'] !== null ? (int)$ctx['Stakeholder_ID'] : null,
                $recipName,
                $recipEmail,
                $bodyTxt,
                $ip,
            ]);
            $flash = 'Comment posted.';
        }
    } elseif ($action === 'ack') {
        $ackComment = trim((string)($_POST['ack_comment'] ?? ''));
        $pdo->prepare(
            "UPDATE Transmittal_Recipients
                SET Acked_At = NOW(), Ack_Comment = ?
              WHERE Recipient_ID = ? AND Acked_At IS NULL"
        )->execute([$ackComment !== '' ? $ackComment : null, (int)$ctx['Recipient_ID']]);
        $flash = 'Thank you — your acknowledgement has been recorded.';
    } elseif ($action === 'approve' || $action === 'request_changes') {
        // The reviewer's decision. Approve also counts as an acknowledgement.
        $decision = ($action === 'approve') ? 'approved' : 'changes_requested';
        $note = trim((string)($_POST['ack_comment'] ?? ''));
        $pdo->prepare(
            "UPDATE Transmittal_Recipients
                SET Approval_Status = ?, Approval_At = NOW(),
                    Acked_At    = COALESCE(Acked_At, NOW()),
                    Ack_Comment = COALESCE(NULLIF(?, ''), Ack_Comment)
              WHERE Recipient_ID = ?"
        )->execute([$decision, $note, (int)$ctx['Recipient_ID']]);
        // A change request is also dropped into the comment thread so it's visible.
        if ($decision === 'changes_requested' && $note !== '') {
            $pdo->prepare(
                "INSERT INTO Commit_Comments
                   (Commit_ID, Stakeholder_ID, Author_Name, Author_Email, Body, Posted_At, Posted_From_IP)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?)"
            )->execute([
                $commitId,
                $ctx['Stakeholder_ID'] !== null ? (int)$ctx['Stakeholder_ID'] : null,
                $recipName, $recipEmail, '[Changes requested] ' . $note, $ip,
            ]);
        }
        $flash = ($decision === 'approved') ? 'Approved — thank you.' : 'Recorded — you requested changes.';
    }
    // PRG: redirect back so refresh doesn't re-post
    header('Location: transmittal_view.php?t=' . urlencode($token) . ($flash ? '&done=1' : ''));
    exit;
}

// ── Track the view (main page GET only — not PDF sub-requests) ──────────
$pdo->prepare(
    "UPDATE Transmittal_Recipients
        SET First_Viewed_At = COALESCE(First_Viewed_At, NOW()),
            Last_Viewed_At  = NOW(),
            View_Count      = View_Count + 1
      WHERE Recipient_ID = ?"
)->execute([(int)$ctx['Recipient_ID']]);

// Re-fetch ack state (the UPDATE above doesn't change it, but a prior POST might have)
$alreadyAcked   = !empty($ctx['Acked_At']);
$approvalStatus = (string)($ctx['Approval_Status'] ?? 'pending');
$decided        = in_array($approvalStatus, ['approved', 'changes_requested'], true);

// ── Load commit's PDFs, NZBC tags, and the comment thread ───────────────
$pdfs = $pdo->prepare(
    "SELECT cb.Blob_Sha256, cb.Path_In_Project, b.Size_Bytes
       FROM Commit_Blobs cb
       INNER JOIN Blobs b ON b.Sha256 = cb.Blob_Sha256
      WHERE cb.Commit_ID = ? AND cb.Role = 'pdf_output'
      ORDER BY cb.Path_In_Project"
);
$pdfs->execute([$commitId]);
$pdfs = $pdfs->fetchAll();

$tags = $pdo->prepare("SELECT DISTINCT Clause_Code FROM Commit_NZBC_Tags WHERE Commit_ID = ? ORDER BY Clause_Code");
$tags->execute([$commitId]);
$tags = array_column($tags->fetchAll(), 'Clause_Code');

$comments = $pdo->prepare(
    "SELECT Author_Name, Body, Posted_At, Stakeholder_ID
       FROM Commit_Comments
      WHERE Commit_ID = ?
      ORDER BY Posted_At ASC"
);
$comments->execute([$commitId]);
$comments = $comments->fetchAll();

$revision = trim((string)($ctx['Revision_Label'] ?? '')) ?: ('#' . $commitId);
$jobName  = (string)($ctx['JobName'] ?? ('Project ' . $ctx['Proj_ID']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Review — <?= htmlspecialchars($jobName) ?> (Rev <?= htmlspecialchars($revision) ?>)</title>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:14px; color:#222; background:#f4f4f4; }
.hdr { background:#9B9B1B; color:#fff; padding:16px 20px; }
.hdr h1 { margin:0 0 4px; font-size:20px; font-weight:500; }
.hdr .sub { font-size:13px; opacity:0.9; }
.wrap { max-width:980px; margin:18px auto; padding:0 16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:5px; padding:16px 18px; margin:14px 0; }
.card h2 { margin:0 0 10px; font-size:15px; color:#9B9B1B; border-bottom:1px solid #eee; padding-bottom:6px; }
.kv { display:grid; grid-template-columns:140px 1fr; gap:4px 12px; font-size:13px; }
.kv .k { color:#888; }
.tag { display:inline-block; background:#fff3cd; border:1px solid #c8a52e; color:#7a5a00; padding:2px 9px; border-radius:11px; font-size:12px; margin:2px 4px 2px 0; }
.pdf-frame { width:100%; height:680px; border:1px solid #ccc; border-radius:3px; margin-top:6px; background:#fff; }
.pdf-row { margin:14px 0; }
.pdf-row .name { font-weight:600; margin-bottom:4px; }
.pdf-row a { font-size:12px; color:#9B9B1B; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:9px 18px; border-radius:3px; cursor:pointer; font:inherit; text-decoration:none; display:inline-block; }
.btn:hover { background:#7a7a16; }
.btn-ack { background:#1a6b1a; font-size:16px; padding:12px 24px; }
.btn-ack:hover { background:#155515; }
textarea { width:100%; border:1px solid #ccc; border-radius:3px; padding:8px; font:inherit; box-sizing:border-box; }
.flash { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:10px 14px; border-radius:4px; margin:14px 0; }
.acked-banner { background:#d6f5d6; border:2px solid #1a6b1a; color:#155515; padding:14px 18px; border-radius:5px; margin:14px 0; font-size:15px; }
.comment { border-left:3px solid #ddd; padding:4px 0 4px 12px; margin:8px 0; }
.comment .meta { font-size:11px; color:#888; }
.footer { text-align:center; color:#aaa; font-size:11px; padding:30px 0; }
</style>
</head>
<body>
<div class="hdr">
  <h1>Drawing revision for review</h1>
  <div class="sub"><?= htmlspecialchars($jobName) ?><?= $ctx['Client_Name'] ? ' · ' . htmlspecialchars((string)$ctx['Client_Name']) : '' ?> &nbsp;·&nbsp; <strong>Revision <?= htmlspecialchars($revision) ?></strong></div>
</div>

<div class="wrap">

  <?php if (isset($_GET['done'])): ?>
    <div class="flash">Saved. Thank you.</div>
  <?php endif; ?>

  <?php if ($decided): ?>
    <div class="acked-banner" style="<?= $approvalStatus === 'changes_requested' ? 'background:#fff3cd;border-color:#c8a52e;color:#7a5a00' : '' ?>">
      <?= $approvalStatus === 'approved' ? '✓ You approved this revision' : '✎ You requested changes on this revision' ?>
      on <?= htmlspecialchars(date('j M Y, g:i a', strtotime((string)($ctx['Approval_At'] ?: $ctx['Acked_At'])))) ?>.
      <?php if (!empty($ctx['Ack_Comment'])): ?><br><span style="font-size:13px;color:#444">Your note: “<?= htmlspecialchars((string)$ctx['Ack_Comment']) ?>”</span><?php endif; ?>
      <br><span style="font-size:12px;color:#555">You can still post comments below. If a newer revision is issued you'll get a fresh link.</span>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>What this is</h2>
    <p style="margin:0 0 10px">Hi <?= htmlspecialchars($recipName) ?>, a revision affecting your scope
      (<strong><?= htmlspecialchars($recipRole) ?></strong>)
      has been issued on this project. Please review the drawings below and acknowledge that you've assessed the impact on your design.</p>
    <div class="kv">
      <div class="k">Revision</div><div><strong><?= htmlspecialchars($revision) ?></strong></div>
      <div class="k">Issued</div><div><?= htmlspecialchars(date('j M Y, g:i a', strtotime((string)$ctx['Sent_At']))) ?> by <?= htmlspecialchars((string)$ctx['Sent_By']) ?></div>
      <div class="k">What changed</div><div><?= nl2br(htmlspecialchars((string)($ctx['TransMessage'] ?: $ctx['CommitMessage']))) ?></div>
      <div class="k">Code areas flagged</div><div>
        <?php if (empty($tags)): ?><span style="color:#888">none flagged by the automated check — still review for your discipline</span>
        <?php else: foreach ($tags as $tg): ?><span class="tag"><?= htmlspecialchars($tg) ?></span><?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Drawings (<?= count($pdfs) ?>)</h2>
    <?php if (empty($pdfs)): ?>
      <p style="color:#888">No PDF drawings were attached to this transmittal. Contact CADViz if you expected drawings here.</p>
    <?php else: foreach ($pdfs as $pd):
        $pdfUrl = 'transmittal_view.php?t=' . urlencode($token) . '&pdf=' . urlencode((string)$pd['Blob_Sha256']);
    ?>
      <div class="pdf-row">
        <div class="name">
          📄 <?= htmlspecialchars((string)$pd['Path_In_Project']) ?>
          <span style="font-weight:400;color:#888;font-size:12px">(<?= number_format(((int)$pd['Size_Bytes'])/1024/1024, 1) ?> MB)</span>
          &nbsp;·&nbsp; <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank">open in new tab ↗</a>
        </div>
        <iframe class="pdf-frame" src="<?= htmlspecialchars($pdfUrl) ?>" title="<?= htmlspecialchars((string)$pd['Path_In_Project']) ?>"></iframe>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if (!$decided): ?>
  <div class="card" style="border:2px solid #1a6b1a">
    <h2 style="color:#1a6b1a;border-color:#cbe8cb">Review decision</h2>
    <p style="font-size:13px;color:#444;margin-top:0">
      Record your decision on this revision. <strong>Approve</strong> confirms you've assessed the
      impact on your discipline and are happy for it to proceed. <strong>Request changes</strong>
      flags an issue that must be resolved before this revision is released to council / construction.
      Your decision is logged with a timestamp and forms the coordination record.
    </p>
    <form method="post">
      <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
      <textarea name="ack_comment" rows="3" placeholder="Optional note (please add one if requesting changes): impact on your design / conditions of your sign-off"></textarea>
      <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" name="action" value="approve" class="btn btn-ack"
          onclick="return confirm('Approve Revision <?= htmlspecialchars($revision) ?>?');">
          ✓ Approve this revision
        </button>
        <button type="submit" name="action" value="request_changes" class="btn"
          style="background:#a86500"
          onclick="return confirm('Request changes to Revision <?= htmlspecialchars($revision) ?>?');">
          ✎ Request changes
        </button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Comments &amp; coordination notes</h2>
    <?php if (empty($comments)): ?>
      <p style="color:#888;font-size:13px">No comments yet.</p>
    <?php else: foreach ($comments as $cmt): ?>
      <div class="comment">
        <div><?= nl2br(htmlspecialchars((string)$cmt['Body'])) ?></div>
        <div class="meta">
          — <?= htmlspecialchars((string)($cmt['Author_Name'] ?: 'CADViz')) ?>
          <?= $cmt['Stakeholder_ID'] ? '' : '<span style="color:#9B9B1B">(CADViz)</span>' ?>
          · <?= htmlspecialchars(date('j M Y, g:i a', strtotime((string)$cmt['Posted_At']))) ?>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <form method="post" style="margin-top:14px">
      <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="action" value="comment">
      <textarea name="body" rows="3" placeholder="Add a comment / flag a coordination issue / ask a question…" required></textarea>
      <div style="margin-top:8px"><button type="submit" class="btn">Post comment</button></div>
    </form>
  </div>

  <div class="footer">
    CADViz Limited · This is a private review link generated for <?= htmlspecialchars($recipEmail) ?>.
    Please don't forward it — request your own from CADViz if someone else needs to review.
  </div>
</div>
</body>
</html>
