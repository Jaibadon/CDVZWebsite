<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/xero_client.php';
require_once __DIR__ . '/invoice_reminder_policy.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik', 'jen'], true)) {
    http_response_code(403);
    exit('Admin only.');
}

$pdo = get_db();
$csrf = $_SESSION['invoice_reminders_csrf'] ??= bin2hex(random_bytes(32));
$flash = $_SESSION['xero_flash'] ?? '';
$flashErr = $_SESSION['xero_flash_err'] ?? '';
unset($_SESSION['xero_flash'], $_SESSION['xero_flash_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Refresh Monthly Invoicing and try again.');
    }
    $clientId = (int)($_POST['client_id'] ?? 0);
    $mode = $_POST['reminder_mode'] ?? '';
    try {
        invoice_reminder_context($pdo);
        invoice_reminder_lock($pdo);
        try {
            invoice_reminder_set_client_policy($pdo, $clientId, $mode);
        } finally {
            invoice_reminder_unlock($pdo);
        }
        $_SESSION['xero_flash'] = $mode === 'manual'
            ? 'Automatic reminders stopped for this client. Their invoice settings were kept.'
            : 'Automatic reminders started for this client. Only eligible invoices will continue on their existing schedule.';
    } catch (Throwable $e) {
        $_SESSION['xero_flash_err'] = $e->getMessage();
    }
    header('Location: monthly_invoicing.php#client-' . $clientId, true, 303);
    exit;
}

$months = $pdo->query(
    "SELECT YEAR(`date`) AS yr, MONTH(`date`) AS mnth, SUM(Subtotal) AS subtotal
       FROM Invoices
      WHERE `date` > DATE_SUB(NOW(), INTERVAL 12 MONTH)
      GROUP BY YEAR(`date`), MONTH(`date`)
      ORDER BY yr DESC, mnth DESC"
)->fetchAll();
$grand = array_sum(array_column($months, 'subtotal'));
$councilEmp = (int)meta_get($pdo, 'council_fee_employee_id', '46');
$councilTotal = null;
try {
    $st = $pdo->prepare(
        "SELECT SUM(t.Hours * COALESCE(t.Rate, 0))
           FROM Timesheets t JOIN Invoices i ON i.Invoice_No = t.Invoice_No
          WHERE t.Employee_id = ? AND i.`date` > DATE_SUB(NOW(), INTERVAL 12 MONTH)"
    );
    $st->execute([$councilEmp]);
    $councilTotal = (float)$st->fetchColumn();
} catch (PDOException $e) {
    // Monthly totals remain available when council fee data is unavailable.
}

$xeroConfigured = XeroClient::isConfigured();
$xeroConnected = $xeroConfigured && XeroClient::isConnected($pdo);
$groups = [];
$outstanding = $overdue = 0.0;
$invoiceCount = $overdueCount = 0;
$lastSync = null;
$invoiceError = '';
$reminderError = '';
$remindersAvailable = false;
$plans = $clientPolicies = $clientExceptions = [];

if ($xeroConnected) {
    try {
        $invoices = $pdo->query(
            "SELECT i.Invoice_No, i.Client_ID, i.Xero_Status, i.Xero_AmountDue, i.Xero_DueDate, i.Xero_OnlineUrl,
                    DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
                    c.Client_Name, c.Phone, c.Mobile, c.email, c.billing_email,
                    m.updated_at AS last_reminder
               FROM Invoices i
               LEFT JOIN Clients c ON i.Client_ID = c.Client_id
               LEFT JOIN App_Meta m ON m.meta_key = CONCAT('reminder_last_', i.Invoice_No)
              WHERE i.Xero_InvoiceID IS NOT NULL
                AND i.Xero_AmountDue > 0
                AND i.Xero_Status IN ('AUTHORISED', 'SUBMITTED')
                AND COALESCE(i.Paid, 0) = 0
              ORDER BY i.Xero_DueDate IS NULL, i.Xero_DueDate, i.Invoice_No"
        )->fetchAll();
        foreach ($invoices as $invoice) {
            $id = (int)$invoice['Client_ID'];
            if (!isset($groups[$id])) {
                $groups[$id] = ['client' => $invoice, 'invoices' => [], 'total' => 0.0, 'overdue' => 0.0];
            }
            $amount = (float)$invoice['Xero_AmountDue'];
            $groups[$id]['invoices'][] = $invoice;
            $groups[$id]['total'] += $amount;
            $outstanding += $amount;
            $invoiceCount++;
            if ((int)$invoice['days_overdue'] > 0) {
                $groups[$id]['overdue'] += $amount;
                $overdue += $amount;
                $overdueCount++;
            }
        }
        uasort($groups, fn($a, $b) => ($b['overdue'] <=> $a['overdue'])
            ?: strcasecmp($a['client']['Client_Name'] ?? '', $b['client']['Client_Name'] ?? ''));
        $lastSync = $pdo->query('SELECT MAX(Xero_LastSynced) FROM Invoices')->fetchColumn();
    } catch (PDOException $e) {
        error_log('Monthly invoicing: ' . $e->getMessage());
        $invoiceError = 'Could not load the Xero balances. Try syncing again.';
    }
    if ($groups) {
        try {
            $context = invoice_reminder_context($pdo);
            foreach (invoice_reminder_rows($pdo, $context['today']) as $row) {
                $plans[(int)$row['Invoice_No']] = invoice_reminder_plan($row, $context);
                if ($row['client_mode'] === 'manual' && $row['manual_exception']) {
                    $clientExceptions[(int)$row['Client_ID']] = true;
                }
            }
            $clientPolicies = $pdo->query('SELECT Client_ID, mode FROM Client_Reminder_Policy')->fetchAll(PDO::FETCH_KEY_PAIR);
            $remindersAvailable = true;
        } catch (Throwable $e) {
            $reminderError = 'Reminder controls unavailable. Check the setup on the Invoice Reminders page.';
        }
    }
}

function monthly_h($value): string {
    return htmlspecialchars((string)$value);
}
function monthly_money(float $amount): string {
    return '$' . number_format($amount, 2);
}
?>
<!doctype html>
<html lang="en-NZ">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monthly invoicing</title>
<link href="site.css" rel="stylesheet">
<style>
.invoicing-nav a { white-space:nowrap; }
.invoicing-tools, .client-tools { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.invoicing-tools { margin:10px 0; }
.invoice-summary { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin:12px 0; }
.invoice-summary .card { margin:0; }
.invoice-summary strong { display:block; font-size:22px; font-variant-numeric:tabular-nums; }
.invoice-label { color:#555; font-size:12px; }
.invoice-muted { color:#555; }
.invoice-alert { border-left:4px solid #c33; }
.invoice-success { border-left:4px solid #1a6b1a; }
.client-group { border:1px solid #ddd; border-radius:6px; margin:14px 0; overflow:hidden; scroll-margin-top:16px; }
.client-heading { padding:12px; background:#f5f5e9; display:flex; justify-content:space-between; align-items:start; flex-wrap:wrap; gap:10px; }
.client-heading h3 { margin:0; color:#222; font-size:16px; overflow-wrap:anywhere; }
.client-heading p { margin:3px 0 0; }
.client-tools form { margin:0; }
.client-balance { font-weight:bold; font-variant-numeric:tabular-nums; }
.client-contact a { overflow-wrap:anywhere; }
.invoice-grid { display:grid; grid-template-columns:minmax(100px, .85fr) minmax(100px, .85fr) minmax(95px, .7fr) minmax(145px, 1.35fr) minmax(90px, .8fr); gap:8px 12px; align-items:start; padding:9px 12px; }
.invoice-grid-head { font-size:11px; font-weight:bold; color:#555; }
.invoice-grid-row { border-top:1px solid #eee; }
.invoice-grid-row:nth-child(odd) { background:#fafafa; }
.invoice-grid p { margin:2px 0; }
.invoice-grid strong, .invoice-grid .badge { display:inline-block; }
.invoice-amount { font-weight:bold; font-variant-numeric:tabular-nums; }
.invoice-due-late { color:#a00; }
.invoice-history { max-width:560px; }
.invoice-history th:last-child, .invoice-history td:last-child { text-align:right; }
.invoice-mobile-label { display:none; }
@media (max-width:640px) {
    .invoice-grid-head { display:none; }
    .invoice-grid-row { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .invoice-mobile-label { display:block; }
    .invoice-row-actions { grid-column:1 / -1; }
}
</style>
</head>
<body><main class="page">
  <nav class="topnav invoicing-nav">
    <h1>Monthly invoicing</h1>
    <a href="invoice_list.php">Invoice list</a>
    <a href="invoice_reminders.php">Invoice Reminders</a>
    <a href="more.php">More</a>
    <a href="menu.php">Main menu</a>
    <a href="logout.php">Log out</a>
  </nav>

  <?php if ($flash): ?><p class="card invoice-success" role="status"><?= monthly_h($flash) ?></p><?php endif; ?>
  <?php if ($flashErr): ?><p class="card invoice-alert" role="alert"><?= monthly_h($flashErr) ?></p><?php endif; ?>

  <section class="invoice-summary" aria-label="Invoicing totals">
    <div class="card"><span class="invoice-label">Invoiced in the last 12 months · excl. GST</span><strong><?= monthly_money($grand) ?></strong></div>
    <?php if ($councilTotal !== null && $councilTotal > 0): ?>
      <div class="card"><span class="invoice-label">Net design revenue · excl. GST</span><strong><?= monthly_money($grand - $councilTotal) ?></strong><small class="invoice-muted"><?= monthly_money($councilTotal) ?> council fees excluded</small></div>
    <?php endif; ?>
    <?php if ($xeroConnected && !$invoiceError): ?>
      <div class="card"><span class="invoice-label">Outstanding balance</span><strong><?= monthly_money($outstanding) ?></strong><small class="invoice-muted"><?= $invoiceCount ?> open invoices</small></div>
      <div class="card"><span class="invoice-label">Overdue balance</span><strong<?= $overdueCount ? ' class="invoice-due-late"' : '' ?>><?= monthly_money($overdue) ?></strong><small class="invoice-muted"><?= $overdueCount ?> past due</small></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Outstanding invoices</h2>
    <div class="invoicing-tools">
      <a href="invoice_reminders.php" class="btn-primary">Preview and manage reminders</a>
      <?php if ($xeroConnected): ?>
        <a href="xero_sync.php" class="btn-secondary">Sync from Xero</a>
        <?php if ($lastSync): ?><small class="invoice-muted">Last synced <?= monthly_h($lastSync) ?> UTC</small><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if (!$xeroConfigured): ?>
      <p>Xero is not configured. See config.xero.sample.php.</p>
    <?php elseif (!$xeroConnected): ?>
      <p><a href="xero_connect.php">Connect to Xero</a> to load outstanding invoices.</p>
    <?php elseif ($invoiceError): ?>
      <p role="alert"><?= monthly_h($invoiceError) ?></p>
    <?php elseif (!$groups): ?>
      <p>No outstanding invoices.</p>
    <?php else: ?>
      <p class="invoice-muted">Start or stop automatic reminders once per client. Stopping also clears any invoice exceptions. Starting lets eligible invoices continue on their existing schedule. Neither button sends an email immediately.</p>
      <?php if ($reminderError): ?><p role="status"><?= monthly_h($reminderError) ?></p><?php endif; ?>
      <?php foreach ($groups as $id => $group):
          $client = $group['client'];
          $phone = trim((string)$client['Phone']) ?: trim((string)$client['Mobile']);
          $recipients = pick_billing_emails($client['billing_email'], $client['email']);
          $automatic = ($clientPolicies[$id] ?? 'automatic') === 'automatic';
      ?>
      <article class="client-group" id="client-<?= $id ?>">
        <header class="client-heading">
          <div>
            <h3><?= monthly_h($client['Client_Name'] ?: 'Unknown client') ?></h3>
            <p><span class="client-balance"><?= monthly_money($group['total']) ?> outstanding</span>
              <?php if ($group['overdue'] > 0): ?><span class="badge badge-bad"><?= monthly_money($group['overdue']) ?> overdue</span><?php endif; ?>
            </p>
            <?php if ($phone || $recipients): ?>
              <p class="client-contact invoice-muted"><?= monthly_h($phone) ?><?= $phone && $recipients ? ' · ' : '' ?>
                <?php foreach ($recipients as $index => $address): ?><?= $index ? ', ' : '' ?><a href="mailto:<?= monthly_h($address) ?>"><?= monthly_h($address) ?></a><?php endforeach; ?>
              </p>
            <?php endif; ?>
          </div>
          <?php if ($id > 0 && $remindersAvailable): ?>
          <div class="client-tools">
            <span class="badge <?= $automatic ? 'badge-ok' : 'badge-warn' ?>"><?= $automatic ? 'Automatic' : 'Manual only' ?></span>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= monthly_h($csrf) ?>">
              <input type="hidden" name="client_id" value="<?= $id ?>">
              <button class="btn-secondary" name="reminder_mode" value="<?= $automatic ? 'manual' : 'automatic' ?>"><?= $automatic ? 'Stop automatic reminders' : 'Start automatic reminders' ?></button>
              <?php if (!$automatic && !empty($clientExceptions[$id])): ?>
                <button class="btn-secondary" name="reminder_mode" value="manual">Stop invoice exceptions</button>
              <?php endif; ?>
            </form>
            <a href="invoice_reminders.php?client_id=<?= $id ?>#client-defaults">Client settings</a>
          </div>
          <?php endif; ?>
        </header>
        <div class="invoice-grid invoice-grid-head" aria-hidden="true"><div>Invoice</div><div>Due date</div><div>Balance due</div><div>Reminder status</div><div>Actions</div></div>
        <?php foreach ($group['invoices'] as $invoice):
            $no = (int)$invoice['Invoice_No'];
            $days = $invoice['days_overdue'];
            $plan = $plans[$no] ?? null;
        ?>
          <div class="invoice-grid invoice-grid-row">
            <div><span class="invoice-mobile-label invoice-label">Invoice</span>
              <strong><a href="invoice.php?Invoice_No=<?= $no ?>"><?= invoice_reminder_number($no) ?></a></strong>
              <?php if ($invoice['Xero_Status'] === 'SUBMITTED'): ?><p class="invoice-label">Submitted to Xero</p><?php endif; ?>
            </div>
            <div><span class="invoice-mobile-label invoice-label">Due date</span>
              <?= $invoice['Xero_DueDate'] ? date('d/m/Y', strtotime($invoice['Xero_DueDate'])) : 'Unknown' ?>
              <?php if ((int)$days > 0): ?><p class="invoice-due-late"><?= (int)$days ?> days overdue</p><?php endif; ?>
            </div>
            <div class="invoice-amount"><span class="invoice-mobile-label invoice-label">Balance due</span><?= monthly_money((float)$invoice['Xero_AmountDue']) ?></div>
            <div><span class="invoice-mobile-label invoice-label">Reminder status</span>
              <?= monthly_h($plan['status'] ?? ($invoice['Xero_Status'] === 'SUBMITTED' ? 'Awaiting authorisation' : 'Unavailable')) ?>
              <?php if ($plan && $plan['date']): ?><p class="invoice-label">Eligible <?= date('d/m/Y', strtotime($plan['date'])) ?></p><?php endif; ?>
              <?php if ($invoice['last_reminder']): ?><p class="invoice-label">Last contacted <?= date('d/m/Y', strtotime($invoice['last_reminder'])) ?></p><?php endif; ?>
            </div>
            <div class="invoice-row-actions">
              <a href="invoice_reminders.php?invoice_no=<?= $no ?>">Reminder options</a>
              <?php if ($invoice['Xero_OnlineUrl']): ?><p><a href="<?= monthly_h($invoice['Xero_OnlineUrl']) ?>" target="_blank" rel="noopener">View in Xero</a></p><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="card invoice-history">
    <h2>Invoicing by month</h2>
    <?php if (!$months): ?><p>No invoices in the last 12 months.</p><?php else: ?>
    <table class="table">
      <thead><tr><th scope="col">Month</th><th scope="col">Subtotal excl. GST</th></tr></thead>
      <tbody><?php foreach ($months as $month): ?>
        <tr><td><?= date('F Y', mktime(0, 0, 0, (int)$month['mnth'], 1, (int)$month['yr'])) ?></td><td><?= monthly_money((float)$month['subtotal']) ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td><strong>12-month total</strong></td><td><strong><?= monthly_money($grand) ?></strong></td></tr></tfoot>
    </table>
    <?php endif; ?>
  </section>
  <nav class="topnav invoicing-nav" aria-label="Work navigation">
    <a href="projects.php">My projects</a>
    <a href="main.php">My timesheet</a>
    <a href="report.php">Reports</a>
    <a href="login.php">Log in again</a>
  </nav>
</main></body>
</html>
