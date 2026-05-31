<?php
/**
 * Manual (free-form) email sender — the flexible sibling of
 * transmittal_send.php.
 *
 * Two knobs:
 *   $attachPdfs       — attach the commit's pdf_output blobs
 *   $includeMagicLink — ALSO create the tracked transmittal (per-stakeholder
 *                       magic-link review page + ack), and append each
 *                       recipient's private link to their copy of the email
 *
 * So the matrix is:
 *   plain note, no link        → quick "FYI / question", logged only
 *   plain note + magic link    → personal wording BUT still a tracked,
 *                                acknowledgeable transmittal
 *   (formal templated version  → transmittal_send.php)
 *
 * Magic links work for BOTH ticked stakeholders and arbitrary extra email
 * addresses: a Transmittal_Recipients row binds the token to either a
 * Stakeholder_ID OR an ad-hoc email (Ad_Hoc_Email/Ad_Hoc_Name, nullable
 * Stakeholder_ID — see add_adhoc_recipients.sql).
 *
 * Always logged: one Commit_Comments row records the send (who/subject/
 * body), so the coordination audit trail stays complete either way.
 *
 * Library mode: define MANUAL_EMAIL_SEND_LIBRARY_ONLY before requiring.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/smtp_mailer.php';

if (!function_exists('cadviz_base_url')) {
    /** Best-effort absolute base URL (scheme://host/) for magic links. */
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
 * @param int[]    $stakeholderIds   Project_Stakeholders to email
 * @param string[] $extraEmails      additional raw addresses (no magic link possible)
 * @param bool     $attachPdfs       attach this commit's pdf_output blobs
 * @param bool     $includeMagicLink create tracked transmittal + per-stakeholder review link
 * @param string   $sentBy           CADViz UserID of the sender
 * @param string   $testTo           divert all mail here if non-empty (no DB writes)
 * @return array  ['sent'=>[{name,email,linked:bool}], 'failed'=>[...],
 *                 'pdf_count'=>int, 'transmittal_id'=>int|null, 'test'=>bool]
 */
function send_manual_email(
    PDO $pdo,
    int $commitId,
    array $stakeholderIds,
    array $extraEmails,
    string $subject,
    string $body,
    bool $attachPdfs,
    bool $includeMagicLink,
    string $sentBy,
    string $testTo = ''
): array {
    if ($commitId <= 0)        throw new Exception('Missing commit_id.');
    if (trim($subject) === '') throw new Exception('Subject is required.');
    if (trim($body) === '')    throw new Exception('Email body is required.');
    $isTest = $testTo !== '';

    // Commit + project context
    $c = $pdo->prepare(
        "SELECT cm.Commit_ID, cm.Proj_ID, cm.Revision_Label, cm.Message,
                p.JobName, cl.Client_Name
           FROM Commits cm
           LEFT JOIN Projects p  ON cm.Proj_ID  = p.proj_id
           LEFT JOIN Clients  cl ON p.Client_ID = cl.Client_id
          WHERE cm.Commit_ID = ?"
    );
    $c->execute([$commitId]);
    $commit = $c->fetch();
    if (!$commit) throw new Exception("Commit #$commitId not found.");
    $jobName  = (string)($commit['JobName'] ?? ('Project ' . $commit['Proj_ID']));
    $revision = trim((string)($commit['Revision_Label'] ?? '')) ?: ('#' . $commitId);

    // Sender identity → Reply-To
    $senderEmail = ''; $senderName = $sentBy;
    try {
        $s = $pdo->prepare("SELECT `First Name` AS fn, email FROM Staff WHERE Login = ? LIMIT 1");
        $s->execute([$sentBy]);
        if ($srow = $s->fetch()) {
            $senderEmail = trim((string)($srow['email'] ?? ''));
            $senderName  = trim((string)($srow['fn'] ?? '')) ?: $sentBy;
        }
    } catch (Exception $e) { /* Staff column variance */ }
    if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $senderEmail = $sentBy . '@cadviz.co.nz';
    }

    // Resolve recipients — keep stakeholder identity (needed for magic links).
    // recips: list of ['email','label','stakeholder_id'|null]
    $recips = [];
    $seen = [];
    $stakeholderIds = array_values(array_filter(array_map('intval', $stakeholderIds), fn($x) => $x > 0));
    if (!empty($stakeholderIds)) {
        $ph = implode(',', array_fill(0, count($stakeholderIds), '?'));
        $st = $pdo->prepare(
            "SELECT Stakeholder_ID, Name, Email FROM Project_Stakeholders
              WHERE Proj_ID = ? AND Active = 1 AND Stakeholder_ID IN ($ph)"
        );
        $st->execute(array_merge([(int)$commit['Proj_ID']], $stakeholderIds));
        foreach ($st->fetchAll() as $sh) {
            $em = strtolower(trim((string)$sh['Email']));
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && !isset($seen[$em])) {
                $seen[$em] = true;
                $recips[] = ['email' => $em, 'label' => (string)$sh['Name'], 'stakeholder_id' => (int)$sh['Stakeholder_ID']];
            }
        }
    }
    foreach ($extraEmails as $raw) {
        foreach (preg_split('/[;,]/', (string)$raw) as $em) {
            $em = strtolower(trim($em));
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && !isset($seen[$em])) {
                $seen[$em] = true;
                $recips[] = ['email' => $em, 'label' => $em, 'stakeholder_id' => null];
            }
        }
    }
    if (empty($recips)) throw new Exception('No valid recipients (pick stakeholders and/or enter valid extra emails).');

    // Optional PDF attachments via the shared helper (returns explicit
    // warnings if any registered PDF can't be loaded — archive path unset,
    // file missing, unreadable, etc. — instead of silently dropping it).
    $attachments = []; $pdfNames = []; $pdfWarnings = [];
    if ($attachPdfs) {
        require_once __DIR__ . '/dms/commit_pdf_helper.php';
        $pdfPack = load_commit_pdf_attachments($pdo, $commitId);
        $attachments = $pdfPack['attachments'];
        $pdfWarnings = $pdfPack['warnings'];
        foreach ($attachments as $a) $pdfNames[] = $a['filename'];
    }

    // If magic links requested, create ONE Transmittals row up-front; each
    // stakeholder recipient then gets its own Transmittal_Recipients token.
    $transmittalId = null;
    $insRecipient = null;
    if ($includeMagicLink && !$isTest) {
        $pdo->prepare(
            "INSERT INTO Transmittals (Commit_ID, Revision_Label, Sent_At, Sent_By, Subject, Message)
             VALUES (?, ?, NOW(), ?, ?, ?)"
        )->execute([$commitId, $revision, $sentBy, $subject, $body]);
        $transmittalId = (int)$pdo->lastInsertId();
        $insRecipient = $pdo->prepare(
            "INSERT INTO Transmittal_Recipients
               (Transmittal_ID, Stakeholder_ID, Ad_Hoc_Email, Ad_Hoc_Name, Magic_Token, Sent_At, View_Count)
             VALUES (?, ?, ?, ?, ?, NOW(), 0)"
        );
    }
    $baseUrl = cadviz_base_url();

    $footerT = "\r\n\r\n—\r\nRe: {$jobName} · Revision {$revision}\r\n{$senderName}, CADViz Limited";
    $footerH = '<hr style="border:none;border-top:1px solid #ddd;margin:16px 0">'
             . '<p style="font-size:12px;color:#888">Re: <strong>' . htmlspecialchars($jobName)
             . '</strong> · Revision ' . htmlspecialchars($revision) . '<br>'
             . htmlspecialchars($senderName) . ', CADViz Limited</p>';

    $sent = []; $failed = []; $linkedCount = 0;
    foreach ($recips as $r) {
        $to = $isTest ? $testTo : $r['email'];

        // Per-recipient body: append a private magic link when requested.
        // Works for stakeholders (bind token to Stakeholder_ID) AND ad-hoc
        // extra emails (bind token to Ad_Hoc_Email/Ad_Hoc_Name). The
        // Transmittal_Recipients row is written ONLY after the email
        // actually sends (matches transmittal_send.php) so a failed send
        // doesn't leave an orphan token row that was never delivered.
        $linkText = ''; $linkHtml = ''; $linked = false; $token = null;
        if ($includeMagicLink) {
            $token = bin2hex(random_bytes(32));
            $url = $baseUrl . 'dms/transmittal_view.php?t=' . $token;
            $linkText = "\r\n\r\nReview & acknowledge this revision (private link, no login):\r\n{$url}\r\n";
            $linkHtml = '<p style="margin:18px 0"><a href="' . htmlspecialchars($url)
                      . '" style="background:#9B9B1B;color:#fff;padding:10px 18px;border-radius:3px;text-decoration:none;font-weight:bold">Review &amp; acknowledge this revision &rarr;</a></p>';
            $linked = true;
        }

        $text = $body . $linkText . $footerT;
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px">'
              . nl2br(htmlspecialchars($body)) . $linkHtml . $footerH . '</div>';

        try {
            SmtpMailer::send([
                'to'          => $to,
                'subject'     => ($isTest ? '[TEST] ' : '') . $subject,
                'text'        => $text,
                'html'        => $html,
                'attachments' => $attachments,
                'reply_to'    => $senderEmail,
            ]);
            // Send succeeded — NOW persist the recipient row (so the token
            // only exists for an email that was actually delivered).
            if ($linked && !$isTest && $insRecipient) {
                if ($r['stakeholder_id'] !== null) {
                    $insRecipient->execute([$transmittalId, (int)$r['stakeholder_id'], null, null, $token]);
                } else {
                    $insRecipient->execute([$transmittalId, null, $r['email'], $r['label'], $token]);
                }
                $linkedCount++;
            }
            $sent[] = ['name' => $r['label'], 'email' => $to, 'linked' => $linked];
        } catch (Exception $e) {
            $failed[] = ['name' => $r['label'], 'email' => $to, 'error' => $e->getMessage()];
        }
    }

    // If we created a Transmittals row but nobody actually got a linked
    // send (all failed, or all recipients were extra non-stakeholder
    // emails), roll it back so it isn't a phantom transmittal.
    if ($transmittalId !== null && $linkedCount === 0) {
        $pdo->prepare("DELETE FROM Transmittal_Recipients WHERE Transmittal_ID = ?")->execute([$transmittalId]);
        $pdo->prepare("DELETE FROM Transmittals WHERE Transmittal_ID = ?")->execute([$transmittalId]);
        $transmittalId = null;
    }

    // Audit log
    if (!$isTest && !empty($sent)) {
        $who = implode(', ', array_map(fn($x) => $x['name'] . ' <' . $x['email'] . '>'
                . ($x['linked'] ? ' [review link]' : ''), $sent));
        $logBody = "📧 Manual email sent by {$senderName}"
                 . ($transmittalId !== null ? " (with review/ack link)" : "") . "\r\n"
                 . "To: {$who}\r\n"
                 . "Subject: {$subject}\r\n"
                 . (!empty($pdfNames) ? 'Attached: ' . implode(', ', $pdfNames) . "\r\n" : '')
                 . "\r\n" . $body;
        try {
            $pdo->prepare(
                "INSERT INTO Commit_Comments
                   (Commit_ID, Stakeholder_ID, Author_Name, Author_Email, Body, Posted_At, Posted_From_IP)
                 VALUES (?, NULL, ?, ?, ?, NOW(), ?)"
            )->execute([
                $commitId, $senderName, $senderEmail, $logBody,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception $e) { /* best-effort */ }
    }

    return [
        'sent'           => $sent,
        'failed'         => $failed,
        'pdf_count'      => count($attachments),
        'warnings'       => $pdfWarnings,
        'transmittal_id' => $isTest ? null : $transmittalId,
        'linked_count'   => $linkedCount,
        'test'           => $isTest,
    ];
}

if (defined('MANUAL_EMAIL_SEND_LIBRARY_ONLY')) return;

// ── Script-mode tester ───────────────────────────────────────────────────
require_once __DIR__ . '/auth_check.php';
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
$commitId = (int)($_GET['commit_id'] ?? 0);
$testTo   = (string)($_GET['test_to'] ?? '');
$ids      = array_filter(array_map('intval', explode(',', (string)($_GET['stakeholder_ids'] ?? ''))));
$link     = !empty($_GET['link']);
if ($commitId <= 0) die('Usage: manual_email_send.php?commit_id=N&stakeholder_ids=1,2[&link=1][&test_to=you@example.com]');
header('Content-Type: text/plain');
try {
    $r = send_manual_email(get_db(), $commitId, $ids, [], 'Manual test', 'This is a manual test email.', false, $link, $_SESSION['UserID'], $testTo);
    echo "OK\n" . json_encode($r, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
