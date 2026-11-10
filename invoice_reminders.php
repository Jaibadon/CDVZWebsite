<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/invoice_reminder_message.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
    http_response_code(403);
    exit('Admin only.');
}
$owner = $_SESSION['UserID'] === 'erik';
$csrf = $_SESSION['invoice_reminders_csrf'] ??= bin2hex(random_bytes(32));
$pdo = get_db();
$focus = max(0, (int)($_GET['invoice_no'] ?? 0));
$clientFocus = max(0, (int)($_GET['client_id'] ?? 0));
$returnTo = 'invoice_reminders.php' . ($focus ? '?invoice_no=' . $focus : ($clientFocus ? '?client_id=' . $clientFocus : ''));
$notice = $_SESSION['invoice_reminder_notice'] ?? '';
unset($_SESSION['invoice_reminder_notice']);
$error = '';
$errorInvoice = 0;
$rows = $batches = $clients = $pending = [];
$selected = null;

try {
    $context = invoice_reminder_context($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            exit('Refresh the reminder preview and try again.');
        }
        invoice_reminder_lock($pdo);
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'client_policy') {
                $cid = (int)($_POST['client_id'] ?? 0);
                $mode = $_POST['mode'] ?? '';
                $days = filter_var($_POST['grace_days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 8, 'max_range' => 365]]);
                if ($days === false) {
                    throw new RuntimeException('Choose a grace period from 8 to 365 days.');
                }
                invoice_reminder_set_client_policy($pdo, $cid, $mode, $days);
                $_SESSION['invoice_reminder_notice'] = 'Client reminder settings saved.';
            } elseif (in_array($action, ['start', 'resume', 'hold', 'manual'], true)) {
                $no = (int)($_POST['invoice_no'] ?? 0);
                $errorInvoice = $no;
                $st = $pdo->prepare('SELECT Invoice_No FROM Invoices WHERE Invoice_No = ? AND COALESCE(Paid, 0) = 0');
                $st->execute([$no]);
                if (!$st->fetchColumn()) {
                    throw new RuntimeException('Open invoice not found.');
                }
                $hold = null;
                if ($action === 'hold') {
                    $hold = (string)($_POST['hold_until'] ?? '');
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $hold);
                    if (!$date || $date->format('Y-m-d') !== $hold || $hold <= $context['today']) {
                        throw new RuntimeException('Choose a hold date after today.');
                    }
                }
                $mode = ['start' => 'active', 'resume' => 'active', 'hold' => 'hold', 'manual' => 'manual'][$action];
                $starting = $action === 'start' || $action === 'resume';
                $confirmed = ($_POST['legacy_confirm'] ?? '') === '1';
                $verificationError = null;
                $pdo->beginTransaction();
                try {
                    $binding = null;
                    if ($starting || ($action === 'hold' && $confirmed)) {
                        $row = invoice_reminder_rows($pdo, $context['today'], $no)[0] ?? null;
                        if ($starting && $row && $row['client_mode'] === 'manual') {
                            $binding = invoice_reminder_exception_key($row);
                            if (!hash_equals($binding, (string)($_POST['manual_exception'] ?? ''))) {
                                throw new RuntimeException('This client is Manual only. Reload and choose the option to start this invoice only.');
                            }
                        }
                        if ($row && !$row['verified_at'] && invoice_reminder_is_legacy($row, $context)) {
                            try {
                                if (!$confirmed || !hash_equals(invoice_reminder_legacy_key($row), (string)($_POST['legacy_key'] ?? ''))) {
                                    throw new RuntimeException('Confirm you checked the original send and billing recipients before starting this backlog invoice.');
                                }
                                [$contactId, $to] = invoice_reminder_verify_backlog($pdo, $row);
                            } catch (Throwable $e) {
                                if ($starting) throw $e;
                                $verificationError = $e->getMessage() ?: 'Xero verification failed.';
                            }
                            if ($verificationError === null) {
                                invoice_email_record_delivery($pdo, $row, $contactId, $to, 'legacy');
                            }
                        }
                    }
                    $pdo->prepare(
                        'INSERT INTO Invoice_Reminder_State (Invoice_No, mode, hold_until, started_on)
                         VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE
                         mode = VALUES(mode), hold_until = VALUES(hold_until), started_on = VALUES(started_on)'
                    )->execute([$no, $mode, $hold, $context['today']]);
                    if ($starting || $action === 'manual') {
                        invoice_reminder_set_exception($pdo, $no, $binding);
                    }
                    $notice = 'Reminder settings saved for ' . invoice_reminder_number($no) . '.';
                    if ($starting) {
                        $updated = invoice_reminder_rows($pdo, $context['today'], $no)[0] ?? null;
                        $plan = $updated ? invoice_reminder_plan($updated, $context) : null;
                        if ($plan && $plan['date'] && $plan['stage'] < 4) {
                            $date = reminder_date_label(max($context['today'], $plan['date']));
                            $notice .= ' This invoice is eligible from ' . $date . '. The client preview shows their earliest planned email.';
                        } else {
                            $notice .= ' Status: ' . ($plan['status'] ?? 'Waiting for an open, authorised Xero invoice') . '.';
                        }
                    } elseif ($action === 'hold') {
                        $notice .= ' Held until ' . reminder_date_label($hold) . '.';
                        if ($verificationError !== null) {
                            $notice .= ' Delivery still needs verification. ' . $verificationError;
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $_SESSION['invoice_reminder_notice'] = $notice;
            } elseif ($owner && in_array($action, ['confirm_delivered', 'release_delivery'], true)) {
                $st = $pdo->prepare("SELECT * FROM Reminder_Deliveries WHERE id = ? AND status = 'pending'");
                $st->execute([(int)($_POST['delivery_id'] ?? 0)]);
                $delivery = $st->fetch();
                if (!$delivery) {
                    throw new RuntimeException('This delivery has already been reviewed.');
                }
                invoice_reminder_finish_delivery($pdo, $delivery, $action === 'confirm_delivered');
                $_SESSION['invoice_reminder_notice'] = 'Delivery review saved.';
            } else {
                throw new RuntimeException('This reminder action is unavailable.');
            }
        } finally {
            invoice_reminder_unlock($pdo);
        }
        header('Location: ' . $returnTo, true, 303);
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (isset($context)) {
    try {
        if ($focus) {
            $st = $pdo->prepare(
                "SELECT i.Invoice_No, i.Client_ID, c.Client_Name, i.Paid,
                        COALESCE(s.mode, 'inherit') AS mode, s.hold_until, COALESCE(s.last_stage, 0) AS last_stage
                   FROM Invoices i LEFT JOIN Clients c ON c.Client_id = i.Client_ID
                   LEFT JOIN Invoice_Reminder_State s ON s.Invoice_No = i.Invoice_No WHERE i.Invoice_No = ?"
            );
            $st->execute([$focus]);
            $selected = $st->fetch();
            if ($selected) {
                $clientFocus = (int)$selected['Client_ID'];
            }
        }
        $rows = invoice_reminder_rows($pdo, $context['today']);
        $pending = $pdo->query(
            "SELECT d.*, c.Client_Name FROM Reminder_Deliveries d LEFT JOIN Clients c ON c.Client_id = d.Client_ID
              WHERE d.status = 'pending' ORDER BY d.id"
        )->fetchAll();
        if ($clientFocus) {
            $rows = array_filter($rows, fn($row) => (int)$row['Client_ID'] === $clientFocus);
            $pending = array_filter($pending, fn($row) => (int)$row['Client_ID'] === $clientFocus);
        }
        $batches = invoice_reminder_batches($rows, $context);
        $clients = $pdo->query(
            "SELECT c.Client_id, c.Client_Name, COALESCE(p.mode, 'automatic') AS mode, COALESCE(p.grace_days, 8) AS grace_days
               FROM Clients c LEFT JOIN Client_Reminder_Policy p ON p.Client_ID = c.Client_id
              ORDER BY c.Client_Name, c.Client_id"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function reminder_h($value): string {
    return htmlspecialchars((string)$value);
}
function reminder_date_label(string $date): string {
    return date('d/m/Y', strtotime($date));
}
function reminder_email_preview(array $message): void {
    $lockedHtml = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">' . $message['html'];
    ?>
    <details class="reminder-message">
      <summary>Review email</summary>
      <p><strong><?= reminder_h($message['subject']) ?></strong></p>
      <p>To <?= reminder_h(implode(', ', $message['to'])) ?> · Bcc <?= reminder_h(implode(', ', $message['bcc'])) ?></p>
      <pre><?= reminder_h($message['text']) ?></pre>
      <iframe sandbox="" title="HTML email preview" srcdoc="<?= reminder_h($lockedHtml) ?>"></iframe>
    </details>
    <?php
}
function reminder_invoice_actions(array $row, string $csrf, string $today, bool $legacy = false): void {
    $started = (int)$row['last_stage'] > 0;
    $manualClient = ($row['client_mode'] ?? '') === 'manual';
    ?>
    <form method="post" class="reminder-actions">
      <input type="hidden" name="csrf" value="<?= reminder_h($csrf) ?>">
      <input type="hidden" name="invoice_no" value="<?= (int)$row['Invoice_No'] ?>">
      <?php if ($legacy): ?>
        <input type="hidden" name="legacy_key" value="<?= reminder_h(invoice_reminder_legacy_key($row)) ?>">
        <label><input type="checkbox" name="legacy_confirm" value="1"> I checked that the original invoice was sent to the addresses shown.</label>
        <small>Confirm this to start or resume reminders. You can save a hold while delivery is unverified.</small>
      <?php endif; ?>
      <?php if ($manualClient): ?>
        <input type="hidden" name="manual_exception" value="<?= reminder_h(invoice_reminder_exception_key($row)) ?>">
      <?php endif; ?>
      <button class="btn-primary" name="action" value="<?= $started ? 'resume' : 'start' ?>"><?= $started ? 'Resume' : 'Start gently' ?><?= $manualClient ? ' for this invoice only' : '' ?></button>
      <button class="btn-secondary" name="action" value="manual">Keep manual</button>
      <input type="date" aria-label="Hold until" name="hold_until" min="<?= reminder_h(invoice_reminder_add_days($today, 1)) ?>"
             value="<?= ($row['hold_until'] ?? '') > $today ? reminder_h($row['hold_until']) : '' ?>">
      <button class="btn-secondary" name="action" value="hold">Hold until a date</button>
    </form>
    <?php
}
$templates = default_email_templates();
$readyBatches = array_slice(array_values(array_filter($batches, fn($batch) => $batch['ready'])), 0, invoice_reminder_cap());
$readyMessages = array_map(fn($batch) => invoice_reminder_message($pdo, $batch), $readyBatches);
$readyManifests = array_map('invoice_reminder_manifest', $readyBatches, $readyMessages);
$visibleSelected = in_array($focus, array_map('intval', array_column($rows, 'Invoice_No')), true);
$backlog = $scheduled = $finalReview = [];
foreach ($rows as $row) {
    $plan = invoice_reminder_plan($row, $context);
    if ($plan['status'] === 'Backlog review') {
        $backlog[] = [$row, $plan];
    } elseif ($plan['stage'] >= 4 && $plan['stage'] <= 5) {
        $finalReview[] = [$row, $plan];
    } else {
        $scheduled[] = [$row, $plan];
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice reminders</title><link href="site.css" rel="stylesheet">
<style>
.reminder-actions { display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin:4px 0; }
.reminder-actions input[type=date], .reminder-actions input[type=number] { max-width:150px; }
.reminder-table td { vertical-align:top; }
.reminder-focus { outline:2px solid #9b9b1b; outline-offset:-2px; }
.reminder-note { color:#555; }
.reminder-message pre { white-space:pre-wrap; overflow-wrap:anywhere; }
.reminder-message iframe { width:100%; min-height:240px; border:1px solid #ccc; }
details > summary { cursor:pointer; padding:4px 0; }
</style></head>
<body><main class="page">
  <nav class="topnav"><h1>Invoice reminders</h1><a href="#client-defaults">Client defaults</a><a href="monthly_invoicing.php">Monthly invoicing</a><a href="invoice_list.php">Invoice list</a><a href="email_templates.php">Email templates</a></nav>
  <?php if ($error !== ''): ?><p class="card badge-bad" role="alert">
    <?php if ($errorInvoice > 0): ?><strong><?= invoice_reminder_number($errorInvoice) ?></strong> · <?php endif; ?>
    <?= reminder_h($error) ?>
  </p><?php endif; ?>
  <?php if ($notice !== ''): ?><p class="card badge-ok"><?= reminder_h($notice) ?></p><?php endif; ?>
  <?php if (!isset($context)): ?></main></body></html><?php return; endif; ?>
  <p class="reminder-note">Newly sent invoices follow the client’s policy. Existing backlog needs a decision per invoice. Saving an option here does not send email.</p>
  <?php if ($clientFocus): ?><p><a href="invoice_reminders.php">Show all clients and invoices</a></p><?php endif; ?>

  <section class="card">
    <h2>Next email per client</h2>
    <p class="reminder-note">Planned from local payment data. Before sending, each invoice and balance is verified in Xero. A change can block the message and require a new preview.</p>
    <?php if (!$batches): ?><p>No automatic reminder emails scheduled for this view.</p><?php else: ?>
    <div class="table-scroll"><table class="table reminder-table">
      <thead><tr><th>Client</th><th>Recipient</th><th>Invoices included</th><th>Next eligible date</th><th>Exact tone</th></tr></thead>
      <tbody><?php foreach ($batches as $batch): ?>
        <tr><td><?= reminder_h($batch['rows'][0]['Client_Name']) ?></td>
          <td><?= reminder_h(implode(', ', $batch['to'])) ?></td>
          <td><?php foreach ($batch['rows'] as $row): $no = (int)$row['Invoice_No']; ?>
            <div><a href="#invoice-<?= $no ?>"><?= invoice_reminder_number($no) ?></a>
              <?php if (!in_array($row['Invoice_No'], $batch['advance'], true)): ?><small>· context only</small><?php endif; ?></div>
          <?php endforeach; ?></td>
          <td><?= reminder_date_label($batch['date']) ?>
            <?php if ($batch['date'] <= $context['today']): ?><div><?= $batch['ready'] ? 'Ready on the next run' : 'Awaiting fresh Xero data' ?></div><?php endif; ?>
          </td><td><a href="email_templates.php#<?= reminder_h($batch['template']) ?>"><?= reminder_h($templates[$batch['template']]['label']) ?></a></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if (!$clientFocus && $readyBatches): ?>
      <h3>Messages available to send now</h3>
      <?php foreach ($readyMessages as $message) reminder_email_preview($message); ?>
      <form action="xero_send_reminders.php" method="post" onsubmit="return confirm('Send the reviewed messages after verifying the invoices in Xero?')">
        <input type="hidden" name="csrf" value="<?= reminder_h($csrf) ?>">
        <input type="hidden" name="preview_key" value="<?= reminder_h(invoice_reminder_preview_key($readyManifests)) ?>">
        <button class="btn-secondary" name="send_action" value="batch">Run eligible reminders now</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($pending): ?>
  <section class="card">
    <h2>Deliveries to check</h2>
    <p class="reminder-note">Automatic sends are paused for these clients until the delivery outcome is confirmed. Check the accounts mailbox or mail log before releasing a retry.</p>
    <ul><?php foreach ($pending as $delivery): ?>
      <li><?= reminder_h($delivery['Client_Name']) ?> · <?= reminder_h($delivery['recipients']) ?> ·
        <?= reminder_h($templates[$delivery['template_key']]['label'] ?? $delivery['template_key']) ?> ·
        <?= reminder_h(implode(', ', array_map('invoice_reminder_number', json_decode($delivery['invoice_numbers'], true)))) ?>
        · attempted <?= reminder_h($delivery['created_at']) ?>
        <?php if ($owner): ?>
          <form method="post" class="reminder-actions" onsubmit="return confirm('Save this delivery outcome?')">
            <input type="hidden" name="csrf" value="<?= reminder_h($csrf) ?>">
            <input type="hidden" name="delivery_id" value="<?= (int)$delivery['id'] ?>">
            <button class="btn-primary" name="action" value="confirm_delivered">Mark as sent</button>
            <button class="btn-secondary" name="action" value="release_delivery">Release for retry</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?></ul>
  </section>
  <?php endif; ?>

  <?php if ($focus && !$visibleSelected): ?>
  <section class="card">
    <h2><?= invoice_reminder_number($focus) ?></h2>
    <?php if ($selected && !$selected['Paid']): ?>
      <p><?= reminder_h($selected['Client_Name']) ?> · Current override <?= reminder_h($selected['mode']) ?>.
        A reminder requires an open, authorised Xero invoice.</p>
      <?php reminder_invoice_actions($selected, $csrf, $context['today']); ?>
    <?php else: ?><p>No open invoice found for this number.</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php foreach (['Backlog review' => $backlog, 'Owner review · Very firm and Final' => $finalReview, 'Invoice schedule and exceptions' => $scheduled] as $heading => $entries): ?>
  <section class="card">
    <h2><?= reminder_h($heading) ?> (<?= count($entries) ?>)</h2>
    <?php if (!$entries): ?><p>Nothing in this queue.</p><?php else: ?>
    <div class="table-scroll"><table class="table reminder-table">
      <thead><tr><th>Invoice</th><th>Client and recipient</th><th>Due and balance</th><th>Reminder status</th><th>Invoice options</th></tr></thead>
      <tbody><?php foreach ($entries as [$row, $plan]): $no = (int)$row['Invoice_No'];
        $sendAction = $plan['stage'] >= 4 ? 'escalate' : ($plan['stage'] === 1 ? 'gentle' : 'next');
        $oneOff = ($sendAction === 'escalate' ? $owner : in_array($plan['status'], ['Backlog review', 'Manual only'], true))
            ? invoice_reminder_manual_plan($row, $context, $sendAction) : null;
        $startPreview = $plan['stage'] === 1 && $plan['status'] === 'Backlog review' && $row['client_mode'] === 'automatic'
            ? invoice_reminder_plan($row, $context, true) : null;
      ?>
        <tr id="invoice-<?= $no ?>"<?= $focus === $no ? ' class="reminder-focus"' : '' ?>>
          <td><a href="invoice.php?Invoice_No=<?= $no ?>"><?= invoice_reminder_number($no) ?></a></td>
          <td><?= reminder_h($row['Client_Name']) ?><br><small><?= reminder_h(implode(', ', $plan['to'])) ?></small></td>
          <td><?= $row['Xero_DueDate'] ? reminder_date_label($row['Xero_DueDate']) : 'Unknown' ?>
            <?php if ((int)$row['days_overdue'] > 0): ?><div><?= (int)$row['days_overdue'] ?> days overdue</div><?php endif; ?>
            <strong>$<?= number_format((float)$row['Xero_AmountDue'], 2) ?></strong>
          </td>
          <td><?= reminder_h($plan['status']) ?>
            <?php if ($plan['date']): ?><div><?= reminder_date_label($plan['date']) ?> · <?= reminder_h(str_replace('_', ' ', INVOICE_REMINDER_TONES[$plan['stage']])) ?></div><?php endif; ?>
            <?php if ($startPreview && $startPreview['date']): ?><div><small>If started today: gentle, eligible <?= reminder_date_label(max($context['today'], $startPreview['date'])) ?></small></div><?php endif; ?>
            <?php if ($row['legacy_last'] && !(int)$row['last_stage']): ?><small>Last contacted <?= reminder_date_label($row['legacy_last']) ?> · previous tone unknown</small><?php endif; ?>
            <?php if ($row['last_sent_at']): ?><div><small>Last stage sent <?= reminder_h(str_replace('_', ' ', INVOICE_REMINDER_TONES[(int)$row['last_stage']])) ?> · <?= reminder_date_label($row['last_sent_at']) ?></small></div><?php endif; ?>
            <?php if ($row['client_mode'] === 'manual'): ?><div><small>Client policy: Manual only<?= $row['manual_exception'] ? ' · exception for this invoice' : '' ?></small></div><?php endif; ?>
            <?php if ($row['mode'] === 'hold' && $row['hold_until'] > $context['today']): ?><div><small>Hold until <?= reminder_date_label($row['hold_until']) ?></small></div><?php endif; ?>
            <?php if ($row['mode'] === 'manual'): ?><div><small>Invoice override: Keep manual</small></div><?php endif; ?>
          </td>
          <td>
            <?php if ($plan['stage'] <= 5): reminder_invoice_actions($row, $csrf, $context['today'],
                !$row['verified_at'] && invoice_reminder_is_legacy($row, $context)); endif; ?>
            <?php if ($oneOff): ?>
            <?php $message = invoice_reminder_message($pdo, $oneOff); reminder_email_preview($message); ?>
            <form class="reminder-actions" method="post" action="xero_send_reminders.php" onsubmit="return confirm('Send this notice to the recipient shown for this invoice?')">
              <input type="hidden" name="csrf" value="<?= reminder_h($csrf) ?>">
              <input type="hidden" name="invoice_no" value="<?= $no ?>">
              <input type="hidden" name="preview_key" value="<?= reminder_h(invoice_reminder_preview_key([invoice_reminder_manifest($oneOff, $message)])) ?>">
              <button class="btn-secondary" name="send_action" value="<?= $sendAction ?>"><?= reminder_h([
                  'escalate' => $plan['stage'] === 5 ? 'Approve and send final notice' : 'Approve and send very firm reminder',
                  'gentle' => 'Send gentle reminder once',
                  'next' => 'Send ' . str_replace('_', ' ', INVOICE_REMINDER_TONES[$plan['stage']]) . ' once',
              ][$sendAction]) ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <section class="card" id="client-defaults">
    <h2>Client defaults</h2>
    <p class="reminder-note">Automatic is the default. Saving Manual only stops automatic reminders and clears any invoice exceptions. You can then start individual invoices from their own rows. Client settings preserve invoice holds and backlog decisions.</p>
    <details<?= $clientFocus ? ' open' : '' ?>><summary>Manage client settings</summary>
      <div class="table-scroll"><table class="table">
      <thead><tr><th>Client</th><th>Policy and first-reminder grace</th></tr></thead>
      <tbody><?php foreach ($clients as $client): $cid = (int)$client['Client_id']; if ($clientFocus && $clientFocus !== $cid) continue; ?>
        <tr><td><a href="invoice_reminders.php?client_id=<?= $cid ?>"><?= reminder_h($client['Client_Name']) ?></a></td><td>
          <form method="post" class="reminder-actions">
            <input type="hidden" name="csrf" value="<?= reminder_h($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= $cid ?>">
            <select name="mode" aria-label="Reminder policy">
              <option value="automatic"<?= $client['mode'] === 'automatic' ? ' selected' : '' ?>>Automatic</option>
              <option value="manual"<?= $client['mode'] === 'manual' ? ' selected' : '' ?>>Manual only</option>
            </select>
            <label>Grace <input type="number" name="grace_days" min="8" max="365" value="<?= (int)$client['grace_days'] ?>" required> days past due</label>
            <button class="btn-primary" name="action" value="client_policy">Save client</button>
          </form>
        </td></tr>
      <?php endforeach; ?></tbody>
      </table></div>
    </details>
  </section>
</main></body></html>
