<?php
/**
 * Transmittal sender.
 *
 * send_transmittal() creates a Transmittals row for a commit, then a
 * Transmittal_Recipients row per chosen stakeholder with a unique magic
 * token, renders the `transmittal` email template, attaches the commit's
 * PDF outputs, and sends each via SmtpMailer. The magic-link URL in the
 * email points at transmittal_view.php?t=<token> — no login; the token
 * IS the auth.
 *
 * Every send creates a NEW transmittal (revisions are re-sent every time
 * something changes — that's the whole point of the coordination system).
 * Acks are per (transmittal, recipient): a new revision's transmittal has
 * fresh un-acked recipient rows, so prior acks don't carry over. That's
 * by design — re-issuing a drawing invalidates the previous sign-off.
 *
 * Library mode: define TRANSMITTAL_SEND_LIBRARY_ONLY before requiring to
 * get send_transmittal() without the script body (used by
 * api/transmittal_send.php). Script mode (?test=1&...) is a manual tester.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/smtp_mailer.php';

/**
 * @param int[]  $recipientStakeholderIds  Project_Stakeholders.Stakeholder_ID to send to
 * @param string $sentBy                   CADViz UserID of the sender
 * @param string $testTo                   if non-empty, ALL emails divert here (no DB writes to Sent flags); for testing
 * @return array  ['transmittal_id'=>int|null, 'sent'=>[{name,email}], 'failed'=>[{name,email,error}], 'test'=>bool]
 */
function send_transmittal(
    PDO $pdo,
    int $commitId,
    array $recipientStakeholderIds,
    string $subject,
    string $message,
    string $sentBy,
    string $testTo = ''
): array {
    if ($commitId <= 0) throw new Exception('Missing commit_id.');
    $recipientStakeholderIds = array_values(array_unique(array_map('intval', $recipientStakeholderIds)));
    $recipientStakeholderIds = array_filter($recipientStakeholderIds, fn($x) => $x > 0);
    if (empty($recipientStakeholderIds)) throw new Exception('No recipients selected.');
    $isTest = $testTo !== '';

    // ── Load commit + project ────────────────────────────────────────────
    $c = $pdo->prepare(
        "SELECT cm.Commit_ID, cm.Proj_ID, cm.Message, cm.Revision_Label, cm.Ifc_Git_Sha,
                p.JobName, cl.Client_Name
           FROM Commits cm
           LEFT JOIN Projects p  ON cm.Proj_ID  = p.proj_id
           LEFT JOIN Clients  cl ON p.Client_ID = cl.Client_id
          WHERE cm.Commit_ID = ?"
    );
    $c->execute([$commitId]);
    $commit = $c->fetch();
    if (!$commit) throw new Exception("Commit #$commitId not found.");

    $revision = trim((string)($commit['Revision_Label'] ?? '')) ?: ('#' . $commitId);
    $jobName  = (string)($commit['JobName'] ?? ('Project ' . $commit['Proj_ID']));
    $projName = trim((string)($commit['Client_Name'] ?? '')) !== ''
              ? $commit['Client_Name'] . ' — ' . $jobName
              : $jobName;

    // ── NZBC tags for context line ───────────────────────────────────────
    $tagStmt = $pdo->prepare("SELECT DISTINCT Clause_Code FROM Commit_NZBC_Tags WHERE Commit_ID = ? ORDER BY Clause_Code");
    $tagStmt->execute([$commitId]);
    $tags = array_column($tagStmt->fetchAll(), 'Clause_Code');
    $nzbcTags = empty($tags) ? '(none flagged)' : implode(', ', $tags);

    // ── PDF attachments (centralised — see commit_pdf_helper.php) ────────
    // Returns explicit warnings when a registered PDF can't be loaded
    // (archive path unset, file missing, unreadable, etc.) so silent drops
    // stop happening.
    require_once __DIR__ . '/commit_pdf_helper.php';
    $pdfPack = load_commit_pdf_attachments($pdo, $commitId);
    $attachments  = $pdfPack['attachments'];
    $fileListText = $pdfPack['file_list_text'];
    $fileListHtml = $pdfPack['file_list_html'];
    $pdfWarnings  = $pdfPack['warnings'];

    // ── Sender reply-to: prefer the staff member's real email ────────────
    $senderEmail = '';
    $senderName  = $sentBy;
    try {
        $s = $pdo->prepare("SELECT `First Name` AS fn, email FROM Staff WHERE Login = ? LIMIT 1");
        $s->execute([$sentBy]);
        if ($srow = $s->fetch()) {
            $senderEmail = trim((string)($srow['email'] ?? ''));
            $senderName  = trim((string)($srow['fn'] ?? '')) ?: $sentBy;
        }
    } catch (Exception $e) { /* Staff column variance — fall back to login */ }
    if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $senderEmail = $sentBy . '@cadviz.co.nz';
    }

    // ── Load chosen stakeholders ─────────────────────────────────────────
    $ph = implode(',', array_fill(0, count($recipientStakeholderIds), '?'));
    $stStmt = $pdo->prepare(
        "SELECT Stakeholder_ID, Role, Role_Label, Name, Email
           FROM Project_Stakeholders
          WHERE Proj_ID = ? AND Active = 1 AND Stakeholder_ID IN ($ph)"
    );
    $stStmt->execute(array_merge([(int)$commit['Proj_ID']], $recipientStakeholderIds));
    $stakeholders = $stStmt->fetchAll();
    if (empty($stakeholders)) throw new Exception('None of the selected stakeholders are active on this project.');

    // ── Create the Transmittals row ──────────────────────────────────────
    $pdo->prepare(
        "INSERT INTO Transmittals (Commit_ID, Revision_Label, Sent_At, Sent_By, Subject, Message)
         VALUES (?, ?, NOW(), ?, ?, ?)"
    )->execute([$commitId, $revision, $sentBy, $subject, $message]);
    $transmittalId = (int)$pdo->lastInsertId();

    $baseUrl = transmittal_base_url();

    $insRecipient = $pdo->prepare(
        "INSERT INTO Transmittal_Recipients (Transmittal_ID, Stakeholder_ID, Magic_Token, Sent_At, View_Count)
         VALUES (?, ?, ?, NOW(), 0)"
    );

    $sent = []; $failed = [];
    foreach ($stakeholders as $sh) {
        $token = bin2hex(random_bytes(32));   // 64 hex chars → CHAR(64)
        $toAddr = $isTest ? $testTo : (string)$sh['Email'];
        $roleLabel = trim((string)($sh['Role_Label'] ?? '')) !== ''
            ? $sh['Role_Label'] . ' / ' . $sh['Role']
            : (string)$sh['Role'];

        $vars = [
            'stakeholder_name' => (string)$sh['Name'],
            'role_label'       => $roleLabel,
            'project_name'     => $projName,
            'job_name'         => $jobName,
            'revision'         => $revision,
            'commit_message'   => $message !== '' ? $message : (string)$commit['Message'],
            'sender_name'      => $senderName,
            'nzbc_tags'        => $nzbcTags,
            'file_list'        => $fileListText,
            'review_url'       => $baseUrl . 'transmittal_view.php?t=' . $token,
        ];
        $varsHtml = $vars;
        $varsHtml['file_list'] = $fileListHtml;

        $tplSubject = $subject !== '' ? $subject : email_template_get($pdo, 'transmittal', 'subject');
        $bodyText = render_email_template(email_template_get($pdo, 'transmittal', 'text'), $vars);
        $bodyHtml = render_email_template(email_template_get($pdo, 'transmittal', 'html'), $varsHtml);
        $subjOut  = render_email_template($tplSubject, $vars);

        try {
            SmtpMailer::send([
                'to'          => $toAddr,
                'subject'     => ($isTest ? '[TEST] ' : '') . $subjOut,
                'text'        => $bodyText,
                'html'        => $bodyHtml,
                'attachments' => $attachments,
                'reply_to'    => $senderEmail,
            ]);
            // Only write the recipient row on a real send (test mode never
            // pollutes the audit trail / magic-token table).
            if (!$isTest) {
                $insRecipient->execute([$transmittalId, (int)$sh['Stakeholder_ID'], $token]);
            }
            $sent[] = ['name' => $sh['Name'], 'email' => $toAddr];
        } catch (Exception $e) {
            $failed[] = ['name' => $sh['Name'], 'email' => $toAddr, 'error' => $e->getMessage()];
        }
    }

    // If a real send produced zero recipient rows (all failed), roll back the
    // empty Transmittals row so it doesn't look like a successful transmittal.
    if (!$isTest && empty($sent)) {
        $pdo->prepare("DELETE FROM Transmittals WHERE Transmittal_ID = ?")->execute([$transmittalId]);
        $transmittalId = null;
    }

    return [
        'transmittal_id' => $isTest ? null : $transmittalId,
        'sent'           => $sent,
        'failed'         => $failed,
        'warnings'       => $pdfWarnings,
        'test'           => $isTest,
        'pdf_count'      => count($attachments),
    ];
}

/** Best-effort absolute base URL (scheme://host/) for magic links. */
function transmittal_base_url(): string
{
    if (defined('CADVIZ_BASE_URL') && CADVIZ_BASE_URL !== '') {
        return rtrim(CADVIZ_BASE_URL, '/') . '/';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'remote.cadviz.co.nz';
    return $scheme . '://' . $host . '/';
}

// ── Library mode stops here ──────────────────────────────────────────────
if (defined('TRANSMITTAL_SEND_LIBRARY_ONLY')) return;

// ── Script mode: manual tester ───────────────────────────────────────────
require_once __DIR__ . '/auth_check.php';
if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}
$commitId = (int)($_GET['commit_id'] ?? $_POST['commit_id'] ?? 0);
$testTo   = (string)($_GET['test_to'] ?? '');
$ids      = array_filter(array_map('intval', explode(',', (string)($_GET['recipient_ids'] ?? ''))));
if ($commitId <= 0 || empty($ids)) {
    die('Usage: transmittal_send.php?commit_id=N&recipient_ids=1,2,3[&test_to=you@example.com]');
}
header('Content-Type: text/plain');
try {
    $r = send_transmittal(
        get_db(), $commitId, $ids,
        '', 'Manual test transmittal.', $_SESSION['UserID'], $testTo
    );
    echo "OK\n" . json_encode($r, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
