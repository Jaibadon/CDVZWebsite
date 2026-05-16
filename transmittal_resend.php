<?php
/**
 * Re-send an EXISTING transmittal (the retry / nudge path).
 *
 * The original gap (review finding E): a partially-failed transmittal, or
 * an engineer who just hasn't actioned it, had no retry — re-sending via
 * the normal flow spawned a NEW Transmittals row + NEW tokens, so any
 * email that DID land now pointed at a different review page and the
 * recipient's view/ack state forked.
 *
 * resend_transmittal() re-delivers to existing recipients REUSING their
 * existing Magic_Token and the SAME Transmittals row — no duplicate
 * transmittal, links already in the wild stay valid, ack state preserved.
 * By default it only re-sends to recipients who haven't acknowledged yet
 * (the common "chase the non-responders" case the menu nag drives you to).
 *
 * Library mode: define TRANSMITTAL_RESEND_LIBRARY_ONLY before requiring.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/smtp_mailer.php';

if (!function_exists('cadviz_base_url')) {
    function cadviz_base_url(): string
    {
        if (defined('CADVIZ_BASE_URL') && CADVIZ_BASE_URL !== '') {
            return rtrim(CADVIZ_BASE_URL, '/') . '/';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'remote.cadviz.co.nz';
        return $scheme . '://' . $host . '/';
    }
}

/**
 * @param bool $onlyUnacked  true → skip recipients who already acknowledged
 * @return array ['sent'=>[{name,email}], 'failed'=>[...], 'skipped_acked'=>int, 'test'=>bool]
 */
function resend_transmittal(
    PDO $pdo,
    int $transmittalId,
    string $sentBy,
    bool $onlyUnacked = true,
    string $testTo = ''
): array {
    if ($transmittalId <= 0) throw new Exception('Missing transmittal_id.');
    $isTest = $testTo !== '';

    $t = $pdo->prepare(
        "SELECT t.Transmittal_ID, t.Commit_ID, t.Revision_Label, t.Subject, t.Message,
                cm.Proj_ID, cm.Message AS CommitMessage,
                p.JobName, cl.Client_Name
           FROM Transmittals t
           INNER JOIN Commits  cm ON t.Commit_ID = cm.Commit_ID
           LEFT  JOIN Projects p  ON cm.Proj_ID  = p.proj_id
           LEFT  JOIN Clients  cl ON p.Client_ID = cl.Client_id
          WHERE t.Transmittal_ID = ?"
    );
    $t->execute([$transmittalId]);
    $tx = $t->fetch();
    if (!$tx) throw new Exception("Transmittal #$transmittalId not found.");

    $commitId = (int)$tx['Commit_ID'];
    $revision = trim((string)($tx['Revision_Label'] ?? '')) ?: ('#' . $commitId);
    $jobName  = (string)($tx['JobName'] ?? ('Project ' . $tx['Proj_ID']));
    $projName = trim((string)($tx['Client_Name'] ?? '')) !== ''
              ? $tx['Client_Name'] . ' — ' . $jobName : $jobName;

    // NZBC tags for the context line
    $tagStmt = $pdo->prepare("SELECT DISTINCT Clause_Code FROM Commit_NZBC_Tags WHERE Commit_ID = ? ORDER BY Clause_Code");
    $tagStmt->execute([$commitId]);
    $tags = array_column($tagStmt->fetchAll(), 'Clause_Code');
    $nzbcTags = empty($tags) ? '(none flagged)' : implode(', ', $tags);

    // PDF attachments (same archive as the original send)
    $attachments = []; $fileListText = ''; $fileListHtml = '<ul style="font-size:13px;margin:4px 0">';
    $archiveDir = (defined('CADVIZ_BLOB_ARCHIVE_PATH') && CADVIZ_BLOB_ARCHIVE_PATH !== '')
        ? rtrim(CADVIZ_BLOB_ARCHIVE_PATH, '/\\') : '';
    $pq = $pdo->prepare(
        "SELECT cb.Path_In_Project, b.Filesystem_Path
           FROM Commit_Blobs cb INNER JOIN Blobs b ON b.Sha256 = cb.Blob_Sha256
          WHERE cb.Commit_ID = ? AND cb.Role = 'pdf_output' ORDER BY cb.Path_In_Project"
    );
    $pq->execute([$commitId]);
    foreach ($pq->fetchAll() as $pr) {
        $label = (string)$pr['Path_In_Project'];
        $fileListText .= "  • $label\r\n";
        $fileListHtml .= '<li>' . htmlspecialchars($label) . '</li>';
        if ($archiveDir !== '' && !empty($pr['Filesystem_Path'])) {
            $abs = $archiveDir . DIRECTORY_SEPARATOR . $pr['Filesystem_Path'];
            if (is_file($abs)) {
                $attachments[] = ['filename' => $label, 'content' => file_get_contents($abs), 'mime' => 'application/pdf'];
            }
        }
    }
    $fileListHtml .= '</ul>';
    if (empty($attachments)) { $fileListText = "  (no PDFs)\r\n"; $fileListHtml = '<p style="font-size:13px;color:#888">(no PDFs)</p>'; }

    // Sender reply-to
    $senderEmail = ''; $senderName = $sentBy;
    try {
        $s = $pdo->prepare("SELECT `First Name` AS fn, email FROM Staff WHERE Login = ? LIMIT 1");
        $s->execute([$sentBy]);
        if ($srow = $s->fetch()) {
            $senderEmail = trim((string)($srow['email'] ?? ''));
            $senderName  = trim((string)($srow['fn'] ?? '')) ?: $sentBy;
        }
    } catch (Exception $e) {}
    if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $senderEmail = $sentBy . '@cadviz.co.nz';
    }

    // Recipients (reuse their existing tokens)
    $rq = $pdo->prepare(
        "SELECT tr.Recipient_ID, tr.Magic_Token, tr.Acked_At,
                tr.Ad_Hoc_Email, tr.Ad_Hoc_Name,
                sh.Name AS ShName, sh.Email AS ShEmail, sh.Role AS ShRole, sh.Role_Label AS ShRoleLabel
           FROM Transmittal_Recipients tr
           LEFT JOIN Project_Stakeholders sh ON tr.Stakeholder_ID = sh.Stakeholder_ID
          WHERE tr.Transmittal_ID = ?"
    );
    $rq->execute([$transmittalId]);
    $recips = $rq->fetchAll();
    if (empty($recips)) throw new Exception('This transmittal has no recipient rows to re-send to.');

    $baseUrl  = cadviz_base_url();
    $subjTpl  = ($tx['Subject'] !== '' && $tx['Subject'] !== null) ? $tx['Subject'] : email_template_get($pdo, 'transmittal', 'subject');
    $textTpl  = email_template_get($pdo, 'transmittal', 'text');
    $htmlTpl  = email_template_get($pdo, 'transmittal', 'html');
    $message  = ($tx['Message'] !== '' && $tx['Message'] !== null) ? $tx['Message'] : (string)$tx['CommitMessage'];

    $sent = []; $failed = []; $skippedAcked = 0;
    foreach ($recips as $r) {
        if ($onlyUnacked && !empty($r['Acked_At'])) { $skippedAcked++; continue; }

        $name  = $r['ShName']  ?: ($r['Ad_Hoc_Name']  ?: 'Reviewer');
        $email = $r['ShEmail'] ?: ($r['Ad_Hoc_Email'] ?: '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failed[] = ['name' => $name, 'email' => $email, 'error' => 'no valid email on recipient row'];
            continue;
        }
        $role  = $r['ShRoleLabel'] ?: ($r['ShRole'] ?: 'reviewer');
        $to    = $isTest ? $testTo : $email;

        $vars = [
            'stakeholder_name' => (string)$name,
            'role_label'       => (string)$role,
            'project_name'     => $projName,
            'job_name'         => $jobName,
            'revision'         => $revision,
            'commit_message'   => $message,
            'sender_name'      => $senderName,
            'nzbc_tags'        => $nzbcTags,
            'file_list'        => $fileListText,
            'review_url'       => $baseUrl . 'transmittal_view.php?t=' . $r['Magic_Token'],
        ];
        $varsHtml = $vars; $varsHtml['file_list'] = $fileListHtml;

        try {
            SmtpMailer::send([
                'to'          => $to,
                'subject'     => ($isTest ? '[TEST] ' : '') . 'Reminder: ' . render_email_template($subjTpl, $vars),
                'text'        => "(Reminder — this revision is still awaiting your acknowledgement.)\r\n\r\n"
                                 . render_email_template($textTpl, $vars),
                'html'        => '<p style="color:#a00"><em>Reminder — this revision is still awaiting your acknowledgement.</em></p>'
                                 . render_email_template($htmlTpl, $varsHtml),
                'attachments' => $attachments,
                'reply_to'    => $senderEmail,
            ]);
            $sent[] = ['name' => $name, 'email' => $to];
        } catch (Exception $e) {
            $failed[] = ['name' => $name, 'email' => $to, 'error' => $e->getMessage()];
        }
    }

    // Audit log (skip in test mode)
    if (!$isTest && !empty($sent)) {
        $who = implode(', ', array_map(fn($x) => $x['name'] . ' <' . $x['email'] . '>', $sent));
        try {
            $pdo->prepare(
                "INSERT INTO Commit_Comments
                   (Commit_ID, Stakeholder_ID, Author_Name, Author_Email, Body, Posted_At, Posted_From_IP)
                 VALUES (?, NULL, ?, ?, ?, NOW(), ?)"
            )->execute([
                $commitId, $senderName, $senderEmail,
                "🔁 Transmittal #{$transmittalId} re-sent (reminder) to: {$who}",
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception $e) { /* best-effort */ }
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped_acked' => $skippedAcked, 'test' => $isTest];
}

if (defined('TRANSMITTAL_RESEND_LIBRARY_ONLY')) return;

// ── Script-mode tester ───────────────────────────────────────────────────
require_once __DIR__ . '/auth_check.php';
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
$tid    = (int)($_GET['transmittal_id'] ?? 0);
$testTo = (string)($_GET['test_to'] ?? '');
if ($tid <= 0) die('Usage: transmittal_resend.php?transmittal_id=N[&test_to=you@example.com][&all=1]');
header('Content-Type: text/plain');
try {
    $r = resend_transmittal(get_db(), $tid, $_SESSION['UserID'], empty($_GET['all']), $testTo);
    echo "OK\n" . json_encode($r, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
