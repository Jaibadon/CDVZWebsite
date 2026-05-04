<?php
/**
 * Editable email templates — admin can rewrite the wording of every
 * outbound email (single invoice, manual statement, 5 reminder tones,
 * 4 overdue-statement tones) without touching PHP.
 *
 * Templates live in App_Meta as `tpl_<key>_<field>` rows. When a row
 * is empty we fall back to the hardcoded default in
 * helpers.php :: default_email_templates(). "Reset" deletes the row.
 *
 * Per-template "send test" buttons fire xero_send_reminders.php in
 * test mode against a chosen invoice, so the admin can preview the
 * exact rendered email in their inbox before pushing changes live.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$pdo = get_db();

// ── POST: save a single template's three fields ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    $key = $_POST['template_key'] ?? '';
    $defaults = default_email_templates();
    if (isset($defaults[$key])) {
        foreach (['subject','text','html'] as $field) {
            $val = $_POST[$field] ?? '';
            // Empty input = revert to the hardcoded default (drops the
            // App_Meta row). Non-empty = persist as override.
            email_template_set($pdo, $key, $field, $val);
        }
        $_SESSION['template_flash'] = 'Saved "' . $defaults[$key]['label'] . '". Test it via the Send-test buttons before relying on the new wording.';
    } else {
        $_SESSION['template_flash_err'] = 'Unknown template key: ' . $key;
    }
    header('Location: email_templates.php#' . urlencode($key));
    exit;
}

// ── POST: reset a single template back to its hardcoded default ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_template') {
    $key = $_POST['template_key'] ?? '';
    foreach (['subject','text','html'] as $field) {
        email_template_set($pdo, $key, $field, '');
    }
    $_SESSION['template_flash'] = 'Reset "' . $key . '" back to default.';
    header('Location: email_templates.php#' . urlencode($key));
    exit;
}

$flash    = $_SESSION['template_flash']     ?? '';
$flashErr = $_SESSION['template_flash_err'] ?? '';
unset($_SESSION['template_flash'], $_SESSION['template_flash_err']);

$registry = default_email_templates();

// ── Pick a default invoice for "Send test" buttons ───────────────────
// Most-recently-pushed unpaid invoice with both Xero data and a billing
// email. Falls back to the first row if nothing matches the preferred
// criteria.
$defaultTestInvoice = 0;
try {
    $row = $pdo->query(
        "SELECT i.Invoice_No
           FROM Invoices i
           LEFT JOIN Clients c ON i.Client_ID = c.Client_id
          WHERE i.Xero_InvoiceID IS NOT NULL
            AND COALESCE(c.billing_email, c.email, '') <> ''
          ORDER BY i.Invoice_No DESC
          LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $defaultTestInvoice = (int)($row['Invoice_No'] ?? 0);
} catch (Exception $e) {}

// Fetch a list of recent invoices to populate the dropdown.
$invoiceOptions = [];
try {
    $invoiceOptions = $pdo->query(
        "SELECT i.Invoice_No, c.Client_Name, i.Xero_AmountDue, i.Xero_DueDate, i.Xero_InvoiceID
           FROM Invoices i
           LEFT JOIN Clients c ON i.Client_ID = c.Client_id
          WHERE i.Paid = 0
          ORDER BY i.Invoice_No DESC
          LIMIT 60"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$testTo = $_SESSION['UserID'] === 'erik' ? 'erik@cadviz.co.nz' : 'accounts@cadviz.co.nz';

// Map template key → cron force_tone value (for the test-send link)
$keyToForceTone = [
    'reminder_gentle'              => 'gentle',
    'reminder_reminder'            => 'reminder',
    'reminder_firm'                => 'firm',
    'reminder_very_firm'           => 'very_firm',
    'reminder_final'               => 'final',
    'overdue_statement_gentle'     => 'gentle',
    'overdue_statement_firm'       => 'firm',
    'overdue_statement_very_firm'  => 'very_firm',
    'overdue_statement_final'      => 'final',
];
?>
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email templates</title>
<link href="site.css" rel="stylesheet">
<style>
body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; background:#EBEBEB; padding:18px; color:#111; }
.wrap { max-width:1100px; margin:0 auto; background:#fff; border-radius:6px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
.hdr { background:#9B9B1B; color:#fff; padding:14px 22px; }
.hdr h1 { margin:0; font-size:20px; }
.hdr a { color:#fff; }
.body { padding:18px 22px; }
.flash { padding:8px 12px; border-radius:4px; margin-bottom:12px; }
.flash.ok  { background:#d6f5d6; color:#1a6b1a; border:1px solid #1a6b1a; }
.flash.err { background:#ffd6d6; color:#a00;    border:1px solid #c33; }
.tpl { border:1px solid #ccc; border-radius:4px; margin-bottom:18px; padding:12px 16px; background:#fafaf2; scroll-margin-top:12px; }
.tpl h2 { margin:0 0 6px; font-size:15px; color:#246; }
.tpl .meta { font-size:11px; color:#666; margin-bottom:10px; }
.tpl label { display:block; font-size:11px; color:#666; margin:8px 0 2px; font-weight:bold; }
.tpl input[type=text], .tpl textarea { width:100%; box-sizing:border-box; font-family:Consolas,Menlo,monospace; font-size:12px; padding:6px 8px; border:1px solid #bbb; border-radius:3px; }
.tpl textarea { resize:vertical; }
.tpl .btn-row { margin-top:10px; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.tpl button, .tpl input[type=submit], .tpl a.btn { display:inline-block; padding:5px 11px; border:none; border-radius:3px; font-size:12px; cursor:pointer; text-decoration:none; }
.btn-primary { background:#246; color:#fff !important; }
.btn-secondary { background:#777; color:#fff !important; }
.btn-test { background:#7a4d00; color:#fff !important; }
.btn-test:hover { background:#5a3700; }
.placeholders code { background:#fff; border:1px solid #ddd; padding:1px 4px; border-radius:3px; font-size:11px; }
details summary { cursor:pointer; user-select:none; padding:4px 0; font-weight:bold; color:#246; }
.test-bar { background:#fff3cd; border:1px solid #c8a52e; border-radius:4px; padding:10px 14px; margin-bottom:18px; font-size:12px; color:#7a5a00; }
.test-bar select { font-size:13px; padding:3px 6px; margin:0 4px; }
nav.toc { background:#fff; border:1px solid #ddd; border-radius:4px; padding:8px 12px; margin-bottom:14px; font-size:12px; }
nav.toc a { color:#246; margin-right:10px; text-decoration:none; }
nav.toc a:hover { text-decoration:underline; }
</style>
</head><body><div class="wrap">
<div class="hdr">
  <h1>Email templates</h1>
  <p style="margin:4px 0 0;font-size:12px;opacity:0.9">
    <a href="more.php">&larr; More menu</a> &middot;
    <a href="menu.php">Main menu</a> &middot;
    <a href="monthly_invoicing.php">Monthly invoicing</a>
  </p>
</div>
<div class="body">

<?php if ($flash):    ?><div class="flash ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<p style="font-size:13px;color:#444;margin-top:0">
  Edit any template's <strong>Subject</strong>, <strong>Text body</strong>,
  or <strong>HTML body</strong>. Saving an empty field reverts that field
  to the hardcoded default. Use <code>{placeholder}</code> tokens to
  insert dynamic values like <code>{name}</code>, <code>{invoice_no}</code>,
  <code>{amount}</code>. Available placeholders are listed at the top of
  each template; unknown ones pass through unchanged so typos are visible.
</p>

<div class="test-bar">
  <strong>Test sending:</strong> every "🧪 Send test" button below diverts to
  <strong><?= htmlspecialchars($testTo) ?></strong> with subject prefixed
  <code>[TEST]</code>. The real client never sees the email and the
  invoice's Sent / reminder_last flags are NOT touched.
  Test invoice:
  <select id="test-invoice" onchange="document.querySelectorAll('a.test-link').forEach(a => { var u = new URL(a.href); u.searchParams.set('invoice_no', this.value); a.href = u.toString(); })">
    <?php foreach ($invoiceOptions as $opt):
        $invNo = (int)$opt['Invoice_No'];
        $label = 'CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT) . ' — ' . ($opt['Client_Name'] ?? '?')
               . (!empty($opt['Xero_AmountDue']) ? ' ($' . number_format((float)$opt['Xero_AmountDue'], 2) . ')' : '');
        $sel   = ($invNo === $defaultTestInvoice) ? ' selected' : '';
    ?>
      <option value="<?= $invNo ?>"<?= $sel ?>><?= htmlspecialchars($label) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<nav class="toc">
  <strong>Jump to:</strong>
  <?php foreach ($registry as $key => $tpl): ?>
    <a href="#<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($tpl['label']) ?></a>
  <?php endforeach; ?>
</nav>

<?php foreach ($registry as $key => $tpl):
    $cur = [
        'subject' => email_template_get($pdo, $key, 'subject'),
        'text'    => email_template_get($pdo, $key, 'text'),
        'html'    => email_template_get($pdo, $key, 'html'),
    ];
    $isOverridden = (
        meta_get($pdo, 'tpl_' . $key . '_subject') !== null ||
        meta_get($pdo, 'tpl_' . $key . '_text')    !== null ||
        meta_get($pdo, 'tpl_' . $key . '_html')    !== null
    );
    $forceTone = $keyToForceTone[$key] ?? null;
?>
<div class="tpl" id="<?= htmlspecialchars($key) ?>">
  <h2><?= htmlspecialchars($tpl['label']) ?>
      <?php if ($isOverridden): ?>
        <span style="background:#1a6b1a;color:#fff;font-size:10px;padding:1px 6px;border-radius:3px;margin-left:6px">CUSTOMISED</span>
      <?php else: ?>
        <span style="background:#999;color:#fff;font-size:10px;padding:1px 6px;border-radius:3px;margin-left:6px">default</span>
      <?php endif; ?>
  </h2>
  <div class="meta placeholders">
    <strong>Available placeholders:</strong>
    <?php foreach (array_filter(array_map('trim', explode(',', $tpl['placeholders']))) as $p): ?>
      <code>{<?= htmlspecialchars($p) ?>}</code>
    <?php endforeach; ?>
    <code>{disregard}</code> <code>{disregard_html}</code>
    <code>{kind_regards}</code> <code>{kind_regards_html}</code>
    <code>{bank_details}</code> <code>{bank_details_html}</code>
  </div>

  <form method="post" action="email_templates.php">
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="template_key" value="<?= htmlspecialchars($key) ?>">

    <label>Subject</label>
    <input type="text" name="subject" value="<?= htmlspecialchars($cur['subject']) ?>">

    <label>Text body</label>
    <textarea name="text" rows="8"><?= htmlspecialchars($cur['text']) ?></textarea>

    <details>
      <summary>HTML body (advanced — keep in sync with text body)</summary>
      <textarea name="html" rows="10" style="margin-top:6px"><?= htmlspecialchars($cur['html']) ?></textarea>
    </details>

    <div class="btn-row">
      <input type="submit" class="btn-primary" value="💾 Save changes">

      <?php if ($isOverridden): ?>
        <button type="submit" class="btn-secondary"
                formaction="email_templates.php"
                onclick="this.form.action='email_templates.php'; this.form.querySelectorAll('input[name=action]')[0].value='reset_template'; return confirm('Reset this template back to the hardcoded default? Your custom wording will be lost.');">
          ↺ Reset to default
        </button>
      <?php endif; ?>

      <?php if ($forceTone !== null): ?>
        <a class="btn-test test-link" target="_blank"
           href="xero_send_reminders.php?test=1&test_to=<?= urlencode($testTo) ?>&force_tone=<?= urlencode($forceTone) ?>&invoice_no=<?= $defaultTestInvoice ?>">
          🧪 Send test (this tone, single invoice)
        </a>
      <?php elseif ($key === 'invoice'): ?>
        <a class="btn-test test-link" target="_blank"
           href="xero_invoice_email.php?Invoice_No=<?= $defaultTestInvoice ?>&test_to=<?= urlencode($testTo) ?>"
           onclick="return confirm('Send a TEST invoice email to <?= htmlspecialchars($testTo) ?>?\n\n(Diverted recipient, [TEST] subject prefix.)');">
          🧪 Send test (single invoice email)
        </a>
      <?php elseif ($key === 'statement_manual'): ?>
        <a class="btn-test test-link" target="_blank"
           href="send_statement.php?test_to=<?= urlencode($testTo) ?>&invoice_no=<?= $defaultTestInvoice ?>"
           onclick="return confirm('Send a TEST manual statement to <?= htmlspecialchars($testTo) ?>?\n\nThis picks the client of the chosen invoice and statements all of their unpaid invoices.');">
          🧪 Send test (manual statement)
        </a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endforeach; ?>

</div></div></body></html>
