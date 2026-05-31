<?php
/**
 * Build the PDF attachment list for a commit, with explicit warnings when
 * something silently drops a file. Centralises logic that was duplicated
 * (and silently buggy) across transmittal_send.php / transmittal_resend.php
 * / manual_email_send.php — they all walked Commit_Blobs the same way and
 * all silently dropped attachments when the archive path was unset / a
 * file was missing.
 *
 * Returns:
 *   attachments       — array shaped for SmtpMailer::send
 *   file_list_text    — plain-text bulleted list for the email body
 *   file_list_html    — HTML <ul> for the same
 *   warnings          — string[] of human-readable issues
 *   total_registered  — count of Commit_Blobs(role=pdf_output) rows
 *   attached          — count of files that actually attached
 */

require_once __DIR__ . '/../db_connect.php';

function load_commit_pdf_attachments(PDO $pdo, int $commitId): array
{
    $warnings    = [];
    $attachments = [];
    $fileListText = '';
    $fileListHtml = '<ul style="font-size:13px;margin:4px 0">';

    $archiveDir = (defined('CADVIZ_BLOB_ARCHIVE_PATH') && CADVIZ_BLOB_ARCHIVE_PATH !== '')
        ? rtrim(CADVIZ_BLOB_ARCHIVE_PATH, '/\\') : '';

    $pq = $pdo->prepare(
        "SELECT cb.Path_In_Project, b.Sha256, b.Filesystem_Path, b.Size_Bytes
           FROM Commit_Blobs cb
           INNER JOIN Blobs b ON b.Sha256 = cb.Blob_Sha256
          WHERE cb.Commit_ID = ? AND cb.Role = 'pdf_output'
          ORDER BY cb.Path_In_Project"
    );
    $pq->execute([$commitId]);
    $rows = $pq->fetchAll();
    $totalRegistered = count($rows);

    // Warn ONCE for the archive-unset case (it'd be redundant per row).
    $archiveWarned = false;

    // Build file_list ONLY from files that actually attached. That way the
    // recipient's email body never lists a PDF they didn't get — silent
    // graceful degradation, per the project policy of not surfacing
    // attach-failures to recipients. Staff still see every miss via the
    // returned `warnings` array.
    foreach ($rows as $pr) {
        $label = (string)$pr['Path_In_Project'];

        if (empty($pr['Filesystem_Path'])) {
            $warnings[] = "PDF \"$label\" has no Filesystem_Path on its Blob row — Drive-only blobs aren't supported as attachments yet. Skipped.";
            continue;
        }
        if ($archiveDir === '') {
            if (!$archiveWarned) {
                $warnings[] = "CADVIZ_BLOB_ARCHIVE_PATH is not set in config.php — $totalRegistered PDF(s) are registered for this commit but cannot be loaded from disk to attach. Set the constant in config.php and re-store the PDFs at next publish.";
                $archiveWarned = true;
            }
            continue;
        }
        $abs = $archiveDir . DIRECTORY_SEPARATOR . $pr['Filesystem_Path'];
        if (!is_file($abs)) {
            $warnings[] = "PDF \"$label\" missing on disk (expected at $abs) — the row is registered but the file isn't there. Either the archive path moved, the file was deleted, or commit_create.php failed to write it. Skipped.";
            continue;
        }
        if (!is_readable($abs)) {
            $warnings[] = "PDF \"$label\" exists but the PHP process can't read it ($abs). Check file permissions / open_basedir. Skipped.";
            continue;
        }
        $bytes = @file_get_contents($abs);
        if ($bytes === false) {
            $warnings[] = "PDF \"$label\" file_get_contents returned false ($abs). Skipped.";
            continue;
        }
        // SmtpMailer reads name/mime/data (NOT filename/content) — using the
        // wrong keys silently produces an attachment of empty bytes named
        // "attachment.bin", which was the real reason DMS PDFs were never
        // landing in the recipient's email.
        $attachments[] = [
            'name' => $label !== '' ? $label : ($pr['Sha256'] . '.pdf'),
            'mime' => 'application/pdf',
            'data' => $bytes,
        ];
        // Only now (post-success) does this file appear in the body list.
        $fileListText .= "  • $label\r\n";
        $fileListHtml .= '<li>' . htmlspecialchars($label) . '</li>';
    }
    $fileListHtml .= '</ul>';
    if (empty($attachments)) {
        // Either no PDFs registered at all, or every one failed to attach.
        // Either way the recipient sees the same neutral message — they
        // have the review-link to inspect the model.
        $fileListText = "  (review the drawings + model summary via the link)\r\n";
        $fileListHtml = '<p style="font-size:13px;color:#888">(review the drawings + model summary via the link)</p>';
    }

    return [
        'attachments'      => $attachments,
        'file_list_text'   => $fileListText,
        'file_list_html'   => $fileListHtml,
        'warnings'         => $warnings,
        'total_registered' => $totalRegistered,
        'attached'         => count($attachments),
    ];
}
