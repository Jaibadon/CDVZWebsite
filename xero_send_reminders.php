<?php
/** CLI: php xero_send_reminders.php cron [dry-run|preview-only]. */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/invoice_reminder_message.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/xero_client.php';

$isCli = PHP_SAPI === 'cli';
$previewOnly = $isCli ? in_array('preview-only', $argv, true) : isset($_GET['dry_run']);
$testMail = $isCli && !$previewOnly && in_array('dry-run', $argv, true);
$dryRun = $previewOnly || $testMail;
$testRecipient = 'jai@nielsen.org.nz';
$action = $isCli ? 'batch' : (string)($_POST['send_action'] ?? 'batch');
$no = (int)($_POST['invoice_no'] ?? 0);

if ($isCli) {
    if (in_array('test', $argv, true) || getenv('CADVIZ_REMINDER_TEST_MODE')) {
        exit("Use Email templates to send a synthetic test.\n");
    }
    if (array_diff(array_slice($argv, 1), ['cron', 'dry-run', 'preview-only'])) {
        fwrite(STDERR, "Unrecognised arguments. Use cron, dry-run or preview-only.\n");
        exit(1);
    }
    if (!$dryRun && !in_array('cron', $argv, true)) {
        exit("Use the cron argument for a live reminder run.\n");
    }
} else {
    require_once __DIR__ . '/auth_check.php';
    if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
        http_response_code(403);
        exit('Admin only.');
    }
    if (isset($_GET['test']) || isset($_GET['test_to'])) {
        header('Location: email_templates.php');
        exit;
    }
    if (!$dryRun) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $focus = (int)($_GET['invoice_no'] ?? 0);
            header('Location: invoice_reminders.php' . ($focus > 0 ? '?invoice_no=' . $focus : ''));
            exit;
        }
        if (empty($_SESSION['invoice_reminders_csrf']) ||
            !hash_equals($_SESSION['invoice_reminders_csrf'], (string)($_POST['csrf'] ?? '')) ||
            !in_array($action, ['batch', 'gentle', 'next', 'escalate'], true) ||
            ($action === 'escalate' && $_SESSION['UserID'] !== 'erik') ||
            ($action !== 'batch' && $no <= 0)) {
            http_response_code(403);
            exit('Refresh the reminder preview and try again.');
        }
    }
    session_write_close();
}

$pdo = get_db();
$results = [];
$error = '';
$locked = false;
$testMailFailed = false;
try {
    if (!$dryRun) {
        invoice_reminder_lock($pdo);
        $locked = true;
    }
    $context = invoice_reminder_context($pdo);
    $rows = invoice_reminder_rows($pdo, $context['today'], $action === 'batch' ? 0 : $no);
    if ($action === 'batch') {
        $batches = array_slice(array_values(array_filter(invoice_reminder_batches($rows, $context),
            fn($batch) => $batch['ready'])), 0, invoice_reminder_cap());
    } else {
        $batch = $rows ? invoice_reminder_manual_plan($rows[0], $context, $action) : null;
        $batches = $batch ? [$batch] : [];
    }
    $messages = array_map(fn($batch) => invoice_reminder_message($pdo, $batch, $testMail), $batches);
    $manifests = array_map('invoice_reminder_manifest', $batches, $messages);
    if (!$isCli && !$dryRun && (!$batches ||
        !hash_equals(invoice_reminder_preview_key($manifests), (string)($_POST['preview_key'] ?? '')))) {
        throw new RuntimeException('The planned messages changed. Open the preview again before sending.');
    }

    foreach ($batches as $index => $batch) {
        $numbers = array_column($batch['rows'], 'Invoice_No');
        $label = implode(', ', array_map('invoice_reminder_number', $numbers));
        $message = $messages[$index];
        if ($testMail) {
            try {
                $intended = implode(', ', $message['to']);
                $notice = 'Test copy only. Planned recipient(s): ' . $intended . '.';
                SmtpMailer::send([
                    'to' => [$testRecipient],
                    'subject' => '[DRY RUN] ' . $message['subject'],
                    'text' => $notice . "\r\n\r\n" . $message['text'],
                    'html' => '<p><strong>' . htmlspecialchars($notice) . '</strong></p>' . $message['html'],
                ]);
                $results[] = $label . ' · Test copy sent to ' . $testRecipient . ' (planned for ' . $intended . ')';
            } catch (Throwable $e) {
                $testMailFailed = true;
                $results[] = $label . ' · Test copy failed. ' . $e->getMessage();
            }
            continue;
        }
        if ($dryRun) {
            $results[] = $label . ' · Would send ' . $message['subject'] . ' to ' . implode(', ', $message['to']);
            continue;
        }
        $delivery = null;
        try {
            $xero ??= new XeroClient($pdo);
            $attachments = array_map(fn($row) => invoice_email_pdf($xero, $row), $batch['rows']);
            foreach ($batch['rows'] as $row) {
                // Read the live balance after downloading attachments. A failed
                // lookup or any change blocks this whole email, including statements.
                invoice_reminder_live_invoice($pdo, $xero, $row);
            }
            $current = $batch;
            $current['rows'] = [];
            $now = invoice_reminder_context($pdo);
            foreach ($numbers as $invoiceNo) {
                $row = invoice_reminder_rows($pdo, $now['today'], (int)$invoiceNo)[0] ?? null;
                if (!$row) {
                    throw new RuntimeException('An invoice is no longer eligible.');
                }
                $plan = $action === 'batch'
                    ? invoice_reminder_plan($row, $now)
                    : invoice_reminder_manual_plan($row, $now, $action);
                if (!$plan || ($action === 'batch' && ($plan['status'] !== 'Ready' || $plan['stage'] >= 4)) ||
                    $plan['to'] !== $batch['to'] ||
                    $plan['stage'] < $batch['stage'] ||
                    (in_array($invoiceNo, $batch['advance'], true) && $plan['stage'] !== $batch['stage'])) {
                    throw new RuntimeException('Invoice eligibility or billing details changed. Review the preview.');
                }
                $current['rows'][] = $row;
            }
            if (invoice_reminder_manifest($current, invoice_reminder_message($pdo, $current)) !== $manifests[$index]) {
                throw new RuntimeException('The invoice or message changed. Review the preview.');
            }
            $claim = $pdo->prepare(
                "INSERT INTO Reminder_Deliveries (Client_ID, recipients, invoice_numbers, advance_numbers,
                 stage, template_key, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
            );
            $advance = json_encode(array_values($batch['advance']), JSON_THROW_ON_ERROR);
            $included = json_encode($numbers, JSON_THROW_ON_ERROR);
            $claim->execute([$batch['rows'][0]['Client_ID'], implode(', ', $message['to']),
                             $included, $advance, $batch['stage'], $batch['template']]);
            $delivery = ['id' => $pdo->lastInsertId(), 'stage' => $batch['stage'],
                         'advance_numbers' => $advance, 'invoice_numbers' => $included];
            SmtpMailer::send($message + ['attachments' => $attachments]);
            invoice_reminder_finish_delivery($pdo, $delivery, true);
            $results[] = $label . ' · Sent to ' . implode(', ', $message['to']);
        } catch (Throwable $e) {
            $results[] = $label . ($delivery ? ' · Delivery needs review. ' : ' · Skipped. ') . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($locked) {
        invoice_reminder_unlock($pdo);
    }
}

if ($isCli) {
    echo 'Reminder run ' . date('c') . ($testMail ? ' (dry run · test email)' : ($previewOnly ? ' (preview only)' : '')) . "\n";
    if ($error !== '') echo "Error: $error\n";
    foreach ($results as $result) echo "$result\n";
    exit($error !== '' || $testMailFailed ? 1 : 0);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="site.css" rel="stylesheet"><title>Reminder run</title></head>
<body><main class="page"><section class="card">
  <h1>Reminder run<?= $dryRun ? ' · Dry run' : '' ?></h1>
  <p><a href="invoice_reminders.php">Back to reminder preview</a></p>
  <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <?php if (!$results && $error === ''): ?><p>No eligible messages.</p><?php endif; ?>
  <ul><?php foreach ($results as $result): ?><li><?= htmlspecialchars($result) ?></li><?php endforeach; ?></ul>
</section></main></body></html>
