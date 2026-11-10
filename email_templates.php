<?php
/**
 * Editable email templates — admin can rewrite the wording of every
 * outbound email (single invoice, manual statement, 5 reminder tones,
 * 5 overdue-statement tones) without touching PHP.
 *
 * Templates live in App_Meta as `tpl_<key>_<field>` rows. When a row
 * is empty we fall back to the hardcoded default in
 * helpers.php :: default_email_templates(). "Reset" deletes the row.
 *
 * Sample emails are rendered with fictional invoice data.
 */
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'invoice_email_safety.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); die('Admin only.');
}

$pdo = get_db();
$csrf = invoice_email_csrf();
$registry = default_email_templates();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    invoice_email_require_post();
    $key = is_string($_POST['template_key'] ?? null) ? $_POST['template_key'] : '';
    $action = $_POST['action'] ?? '';
    try {
        if (!isset($registry[$key]) || !in_array($action, ['save_template', 'reset_template'], true)) {
            throw new RuntimeException('Choose a valid email template and action.');
        }
        $pdo->beginTransaction();
        foreach (['subject','text','html'] as $field) {
            if ($action === 'save_template' && !is_string($_POST[$field] ?? null)) {
                throw new RuntimeException('The email template was incomplete. Reload the form and try again.');
            }
            email_template_set($pdo, $key, $field, $action === 'reset_template' ? '' : $_POST[$field]);
        }
        $pdo->commit();
        $_SESSION['template_flash'] = 'Updated "' . $registry[$key]['label'] . '".';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['template_flash_err'] = $e->getMessage();
    }
    header('Location: email_templates.php#' . urlencode($key), true, 303);
    exit;
}

$flash    = $_SESSION['template_flash']     ?? '';
$flashErr = $_SESSION['template_flash_err'] ?? '';
unset($_SESSION['template_flash'], $_SESSION['template_flash_err']);

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
  Sample emails use fictional invoice data and go to the internal test mailbox for your account.
  Save changes first to test the latest wording.
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
    <input type="hidden" name="email_csrf" value="<?= htmlspecialchars($csrf) ?>">
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
      <button type="submit" name="action" value="save_template" class="btn-primary">Save changes</button>
      <?php if ($isOverridden): ?>
        <button type="submit" class="btn-secondary" name="action" value="reset_template"
                onclick="return confirm('Reset this template to its default wording?');">Reset to default</button>
      <?php endif; ?>
      <?php if (in_array($key, ['invoice', 'statement_manual'], true) || str_starts_with($key, 'reminder_') || str_starts_with($key, 'overdue_statement_')): ?>
        <button type="submit" class="btn-test" formaction="invoice_email_test.php"
                name="test_template" value="<?= htmlspecialchars($key) ?>"
                onclick="return confirm('Send a sample email to the internal test mailbox?');">Send sample email</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endforeach; ?>

</div></div></body></html>
