<?php
require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();
$proj_id = (int)($_GET['proj_id'] ?? $_POST['proj_id'] ?? 0);
if ($proj_id <= 0) die('Missing proj_id');

// ── Inline Project_Type update (handle FIRST so reload reflects the new type)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_project_type') {
    $newType = (isset($_POST['Project_Type']) && $_POST['Project_Type'] !== '')
        ? (int)$_POST['Project_Type'] : null;
    $upd = $pdo->prepare("UPDATE Projects SET Project_Type = ? WHERE proj_id = ?");
    $upd->execute([$newType, $proj_id]);
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

// ── Inline Project Description update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_description') {
    $description = $_POST['Job_Description'] ?? '';
    $upd = $pdo->prepare("UPDATE Projects SET Job_Description = ? WHERE proj_id = ?");
    $upd->execute([$description, $proj_id]);
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

// ── Quote status: Accept / Reset ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_quote') {
    try {
        $pdo->prepare("UPDATE Projects SET Quote_Status = 'accepted' WHERE proj_id = ?")->execute([$proj_id]);
    } catch (Exception $e) { /* column may not exist if migration not run */ }
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}
// ── Staffing update (Manager / DP1 / DP2 / DP3) ────────────────────────
// Mirrors the same fields from updateform_admin1.php so Erik can assign
// staff straight from the quote builder. Empty value = NULL (unassigned).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_staffing') {
    $picks = [];
    foreach (['Manager','DP1','DP2','DP3'] as $k) {
        $v = $_POST[$k] ?? '';
        $picks[$k] = ($v === '' || $v === '0') ? null : (int)$v;
    }
    try {
        $pdo->prepare("UPDATE Projects SET Manager = ?, DP1 = ?, DP2 = ?, DP3 = ? WHERE proj_id = ?")
            ->execute([$picks['Manager'], $picks['DP1'], $picks['DP2'], $picks['DP3'], $proj_id]);
    } catch (Exception $e) { /* schema mismatch — fall through */ }
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

// ── Quote type / fixed price update ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_quote_type') {
    $qt = ($_POST['Quote_Type'] ?? '') === 'fixed' ? 'fixed' : 'estimate';
    $fp = ($_POST['Fixed_Price'] ?? '') !== '' ? (float)$_POST['Fixed_Price'] : null;
    $fm = ($_POST['Fixed_Margin_Pct'] ?? '') !== '' ? (float)$_POST['Fixed_Margin_Pct'] : 12.5;
    try {
        $pdo->prepare("UPDATE Projects SET Quote_Type = ?, Fixed_Price = ?, Fixed_Margin_Pct = ? WHERE proj_id = ?")
            ->execute([$qt, $fp, $fm, $proj_id]);
    } catch (Exception $e) { /* migration may not be run */ }
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_to_draft') {
    try {
        $pdo->beginTransaction();
        // Delete all variations on this project (and their stages, tasks, removal flags)
        $pdo->prepare("DELETE pt FROM Project_Tasks pt JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID WHERE ps.Proj_ID = ? AND ps.Variation_ID IS NOT NULL")->execute([$proj_id]);
        $pdo->prepare("DELETE FROM Project_Stages WHERE Proj_ID = ? AND Variation_ID IS NOT NULL")->execute([$proj_id]);
        $pdo->prepare("UPDATE Project_Tasks pt JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID SET pt.Is_Removed = 0, pt.Removed_In_Variation_ID = NULL WHERE ps.Proj_ID = ?")->execute([$proj_id]);
        $pdo->prepare("DELETE FROM Project_Variations WHERE Proj_ID = ?")->execute([$proj_id]);
        $pdo->prepare("UPDATE Projects SET Quote_Status = 'draft' WHERE proj_id = ?")->execute([$proj_id]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    header('Location: project_stages.php?proj_id=' . $proj_id);
    exit;
}

$hasQuoteStatus = false;
$hasQuoteType   = false;
try { $hasQuoteStatus = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'Quote_Status'")->fetch(); } catch (Exception $e) {}
try { $hasQuoteType   = (bool)$pdo->query("SHOW COLUMNS FROM Projects LIKE 'Quote_Type'")->fetch(); } catch (Exception $e) {}
$qsCol = $hasQuoteStatus ? ', Quote_Status' : ", NULL AS Quote_Status";
$qtCol = $hasQuoteType   ? ', Quote_Type, Fixed_Price, Fixed_Margin_Pct' : ", NULL AS Quote_Type, NULL AS Fixed_Price, NULL AS Fixed_Margin_Pct";
$proj = $pdo->prepare("SELECT proj_id, JobName, Project_Type, Job_Description, Manager, DP1, DP2, DP3$qsCol$qtCol FROM Projects WHERE proj_id = ?");
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('Project not found');

// Staff list for the Manager / DP picker. Active staff only on installs
// that have an `active` column; older schemas fall back to all staff.
$staffPick = [];
try {
    $hasActiveCol = (bool)$pdo->query("SHOW COLUMNS FROM Staff LIKE 'active'")->fetch();
    $sql = $hasActiveCol
        ? "SELECT Employee_ID, Login FROM Staff WHERE COALESCE(active,1) <> 0 ORDER BY Login"
        : "SELECT Employee_ID, Login FROM Staff ORDER BY Login";
    $staffPick = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }

// NULL or 'draft' means draft (free editing). 'accepted' means quote locked.
$quoteStatus = ($proj['Quote_Status'] ?? null) === 'accepted' ? 'accepted' : 'draft';
$quoteType   = ($proj['Quote_Type']   ?? null) === 'fixed'    ? 'fixed'    : 'estimate';

// Compute the estimate-mode total for this project — used to:
//   1. Pre-fill the Fixed_Price input when no value is saved yet (so Erik
//      can hit Save to lock the estimate as the fixed price unchanged).
//   2. Show the delta on the internal "Hours + Prices" breakdown view in
//      quote.php so reviewers can see how the fixed price compares to the
//      raw estimate.
$projMultiplier = 1.0;
try {
    $mst = $pdo->prepare("SELECT c.Multiplier FROM Projects p LEFT JOIN Clients c ON p.Client_ID = c.Client_id WHERE p.proj_id = ?");
    $mst->execute([$proj_id]);
    $projMultiplier = (float)($mst->fetchColumn() ?: 1.0);
} catch (Exception $e) { /* fallback to 1 */ }
$estimate           = compute_project_estimate($pdo, $proj_id, $projMultiplier, 90.00);
$estimateSubtotal   = (float)$estimate['subtotal'];
$estimateHours      = (float)$estimate['hours'];

// Editor settings (consumed by stages_editor.php)
$mode             = 'project';
$owner_id         = $proj_id;
$owner_label      = 'Project: #' . (int)$proj['proj_id'] . ' — ' . ($proj['JobName'] ?? '');
$stages_table     = 'Project_Stages';
$tasks_table      = 'Project_Tasks';
$stage_owner_col  = 'Proj_ID';
$task_owner_col   = 'Project_Stage_ID';
$back_url         = 'updateform_admin1.php?proj_id=' . $proj_id;

// Lookup data for the quick-action panel
$projectTypeId = (int)($proj['Project_Type'] ?? 0);

$projectTypes = $pdo->query(
    "SELECT Project_Type_ID, Project_Type_Name FROM Project_Types ORDER BY Project_Type_Name"
)->fetchAll();

// All templates for this project's type (newest first)
$availableTpls = [];
if ($projectTypeId > 0) {
    $stmt = $pdo->prepare(
        "SELECT Template_ID, template_name FROM Proj_Templates
          WHERE Project_Type_ID = ?
          ORDER BY Template_ID DESC"
    );
    $stmt->execute([$projectTypeId]);
    $availableTpls = $stmt->fetchAll();
}

// Build the quick-action panel
ob_start();
?>
<div style="background:#fff;border:1px solid #ccc;padding:10px;max-width:1100px;margin:0 0 10px;">
  <!-- Project type selector -->
  <form method="post" style="display:inline-block;margin-right:14px;border-right:1px solid #ddd;padding-right:14px">
    <input type="hidden" name="action" value="update_project_type">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <strong>Project Type:</strong>
    <select name="Project_Type" onchange="this.form.submit()">
      <option value="">-- none --</option>
      <?php foreach ($projectTypes as $pt): ?>
        <option value="<?= (int)$pt['Project_Type_ID'] ?>"
                <?= ((int)$pt['Project_Type_ID'] === $projectTypeId) ? 'selected' : '' ?>>
          <?= htmlspecialchars($pt['Project_Type_Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><input type="submit" value="Save"></noscript>
  </form>

  <!-- Project Description -->
  <form method="post" style="display:block;margin:10px 0;">
    <input type="hidden" name="action" value="update_description">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <strong>Project Description:</strong>
    <textarea name="Job_Description"
              style="display:block;width:100%;min-height:80px;margin:5px 0;padding:8px;font-family:Arial,sans-serif;font-size:13px;border:1px solid #ccc;border-radius:3px;"
              placeholder="Enter project description..."><?= htmlspecialchars($proj['Job_Description'] ?? '') ?></textarea>
    <input type="submit" value="Save Description"
           style="background:#555;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
  </form>

  <!-- Staffing — Manager + DP1/2/3. These mirror updateform_admin1.php so
       Erik can assign drafting persons straight from the quote builder.
       Once the quote is accepted, anyone listed as Manager/DP1/DP2/DP3
       OR assigned to any task here will see the project on their
       My Projects / My Project Checklist pages. -->
  <form method="post" style="display:block;margin:10px 0;border-top:1px solid #eee;padding-top:10px">
    <input type="hidden" name="action" value="update_staffing">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <strong>Staffing:</strong>
    <?php
      $staffOptions = function($selected, $name) use ($staffPick) {
          $out  = '<select name="' . htmlspecialchars($name) . '" style="margin:0 6px 0 4px">';
          $out .= '<option value="">— unassigned —</option>';
          foreach ($staffPick as $s) {
              $eid = (int)$s['Employee_ID'];
              $sel = ((int)($selected ?? 0) === $eid) ? ' selected' : '';
              $out .= '<option value="' . $eid . '"' . $sel . '>' . htmlspecialchars($s['Login']) . '</option>';
          }
          $out .= '</select>';
          return $out;
      };
    ?>
    <span style="margin-left:6px;font-size:11px;color:#666">Manager:</span>
    <?= $staffOptions($proj['Manager'] ?? null, 'Manager') ?>
    <span style="font-size:11px;color:#666">DP1:</span>
    <?= $staffOptions($proj['DP1']     ?? null, 'DP1') ?>
    <span style="font-size:11px;color:#666">DP2:</span>
    <?= $staffOptions($proj['DP2']     ?? null, 'DP2') ?>
    <span style="font-size:11px;color:#666">DP3:</span>
    <?= $staffOptions($proj['DP3']     ?? null, 'DP3') ?>
    <input type="submit" value="Save Staffing"
           style="background:#555;color:#fff;border:none;padding:4px 10px;border-radius:3px;cursor:pointer;margin-left:6px">
    <div style="font-size:11px;color:#666;margin-top:4px">
      DPs assigned to specific tasks below also see the project once the quote is <em>accepted</em>.
    </div>
  </form>

  <!-- Templates for this project type -->
  <?php if ($projectTypeId === 0): ?>
    <em style="color:#888">Pick a project type to see available templates.</em>
  <?php elseif (count($availableTpls) === 0): ?>
    <em style="color:#888">No templates exist for this project type yet — save the current stages below to create one.</em>
  <?php else: ?>
    <strong>Apply template:</strong>
    <?php foreach ($availableTpls as $i => $t): ?>
      <form method="post" action="template_apply.php" style="display:inline"
            onsubmit="return confirm('Apply template &quot;<?= htmlspecialchars(addslashes($t['template_name'])) ?>&quot;? Existing stages and tasks will NOT be deleted — the template will be added on top.');">
        <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
        <input type="hidden" name="Template_ID" value="<?= (int)$t['Template_ID'] ?>">
        <input type="submit"
               value="<?= htmlspecialchars($t['template_name']) ?><?= ($i === 0) ? ' (latest)' : '' ?>"
               style="background:#9B9B1B;color:#fff;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:11px">
      </form>
    <?php endforeach; ?>
  <?php endif; ?>

  <hr style="border:none;border-top:1px solid #eee;margin:8px 0">

  <!-- Save / Print actions -->
  <form method="post" action="template_save.php" style="display:inline"
        onsubmit="this.template_name.value = prompt('Name for the new template:', '<?= htmlspecialchars(addslashes($proj['JobName'] ?? '')) ?>'); return this.template_name.value !== null && this.template_name.value !== '';">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <input type="hidden" name="template_name" value="">
    <input type="submit" value="Save current stages as Template"
           style="background:#555;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
  </form>
  <a href="quote.php?proj_id=<?= $proj_id ?>&original_only=1" target="_blank"
     style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Original Quote</a>
  <a href="quote.php?proj_id=<?= $proj_id ?>" target="_blank"
     style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Quote (+ variations)</a>
  <a href="quote_variations.php?proj_id=<?= $proj_id ?>" target="_blank"
     style="background:#c33;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Variations</a>
  <a href="checklist.php?proj_id=<?= $proj_id ?>" target="_blank"
     style="background:#246;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Print Checklist</a>
  <a href="templates.php"
     style="background:#777;color:#fff;padding:5px 10px;border-radius:3px;text-decoration:none">Manage all templates</a>

  <hr style="border:none;border-top:1px solid #eee;margin:8px 0">

  <!-- Quote type: Estimate (task-based) vs Fixed Price (lump sum) -->
  <form method="post" style="display:block;margin-bottom:6px">
    <input type="hidden" name="action" value="update_quote_type">
    <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
    <strong>Quote Type:</strong>
    <label style="margin-left:10px"><input type="radio" name="Quote_Type" value="estimate" <?= $quoteType==='estimate'?'checked':'' ?> onchange="this.form.submit()"> <strong>Estimate</strong> <span style="font-size:11px;color:#666">(task breakdown × rates)</span></label>
    <label style="margin-left:14px"><input type="radio" name="Quote_Type" value="fixed" <?= $quoteType==='fixed'?'checked':'' ?> onchange="this.form.submit()"> <strong>Fixed Price (lump sum)</strong> <span style="font-size:11px;color:#666">(small jobs ≤ ~$3k, set price + safety margin)</span></label>
  </form>

  <?php if ($quoteType === 'fixed'): ?>
  <div style="background:#eef4ff;border:1px solid #c0d0ee;padding:8px 12px;border-radius:4px;margin-bottom:6px">
    <form method="post">
      <input type="hidden" name="action" value="update_quote_type">
      <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
      <input type="hidden" name="Quote_Type" value="fixed">
      <strong>Fixed Price (excl. GST):</strong>
      <?php
        // When no Fixed_Price is saved yet, pre-fill the input with the
        // computed estimate total so Erik has a starting point — he can
        // overwrite, or hit Save to lock the estimate in unchanged.
        $fixedPriceInputVal = ($proj['Fixed_Price'] !== null && $proj['Fixed_Price'] !== '')
            ? (string)$proj['Fixed_Price']
            : ($estimateSubtotal > 0 ? number_format($estimateSubtotal, 2, '.', '') : '');
      ?>
      $<input type="number" step="0.01" min="0" name="Fixed_Price" value="<?= htmlspecialchars($fixedPriceInputVal) ?>" style="width:100px" placeholder="0.00"
              title="Defaults to the project's estimated total (<?= htmlspecialchars(number_format($estimateSubtotal, 2)) ?>). Edit to override.">
      &nbsp; <strong>Safety margin:</strong>
      <input type="number" step="0.5" min="0" max="50" name="Fixed_Margin_Pct" value="<?= htmlspecialchars((string)($proj['Fixed_Margin_Pct'] ?? '12.5')) ?>" style="width:55px">%
      <input type="submit" value="Save"
             style="background:#246;color:#fff;border:none;padding:4px 10px;border-radius:3px;cursor:pointer;margin-left:6px">
      <span style="font-size:11px;color:#666;margin-left:6px">
        Final to client = price × (1 + margin) + GST.
        <?php
          $fp = (float)($proj['Fixed_Price'] ?? 0);
          $fm = (float)($proj['Fixed_Margin_Pct'] ?? 12.5);
          if ($fp > 0):
            $marked = $fp * (1 + $fm/100);
            $gst    = $marked * 0.15;
        ?>
        Preview: <strong>$<?= number_format($marked + $gst, 2) ?> incl GST</strong>
        (margin $<?= number_format($marked - $fp, 2) ?>, GST $<?= number_format($gst, 2) ?>)
        <?php endif; ?>
      </span>
    </form>
    <div style="font-size:11px;color:#666;margin-top:4px">
      📌 In Fixed-Price mode you don't need to break the project into tasks. The stage/task editor below stays available as optional internal notes
      (timesheet hours can still be logged against tasks for analytics). Variations on this project use a manual price (no margin applied).
    </div>
    <!-- Breakdown views — both internal (with margin info) and client-facing.
         The quote.php page also has a "Client-facing version" checkbox in
         its print bar that toggles between the two without leaving the page. -->
    <div style="margin-top:8px;font-size:11px">
      <strong style="color:#246">Internal review (with margin):</strong>
      <a href="quote.php?proj_id=<?= $proj_id ?>&breakdown=hours" target="_blank"
         style="background:#7a4d00;color:#fff;padding:3px 8px;border-radius:3px;text-decoration:none;margin-left:6px">Hours only</a>
      <a href="quote.php?proj_id=<?= $proj_id ?>&breakdown=full" target="_blank"
         style="background:#7a4d00;color:#fff;padding:3px 8px;border-radius:3px;text-decoration:none;margin-left:4px">Hours + prices</a>
    </div>
    <div style="margin-top:6px;font-size:11px">
      <strong style="color:#246">Client-facing breakdown:</strong>
      <a href="quote.php?proj_id=<?= $proj_id ?>&breakdown=hours&audience=client" target="_blank"
         style="background:#246;color:#fff;padding:3px 8px;border-radius:3px;text-decoration:none;margin-left:6px">Hours only (with fixed price)</a>
      <a href="quote.php?proj_id=<?= $proj_id ?>&breakdown=full&audience=client" target="_blank"
         style="background:#246;color:#fff;padding:3px 8px;border-radius:3px;text-decoration:none;margin-left:4px">Hours + prices</a>
      <span style="color:#666">&nbsp;(includes T&amp;Cs / signature; happy-to-share with select clients)</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Quote status badge + Accept / Reset -->
  <strong>Quote Status:</strong>
  <?php if ($quoteStatus === 'accepted'): ?>
    <span style="background:#1a6b1a;color:#fff;padding:3px 10px;border-radius:8px;font-weight:bold;font-size:11px;margin-right:8px">ACCEPTED (locked)</span>
    <form method="post" style="display:inline" onsubmit="return confirm('Reset to DRAFT mode? This will DELETE all variations on this project (their stages, tasks, and removal flags) — variations cannot survive a reset because the original quote may be edited again. Are you sure?');">
      <input type="hidden" name="action" value="reset_to_draft">
      <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
      <input type="submit" value="Reset to Draft"
             style="background:#c33;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
    </form>
    <span style="font-size:11px;color:#666;margin-left:6px">In accepted mode the original quote is locked — edits/additions/removals route to the latest unapproved variation.</span>
  <?php else: ?>
    <span style="background:#9B9B1B;color:#fff;padding:3px 10px;border-radius:8px;font-weight:bold;font-size:11px;margin-right:8px">DRAFT (free editing)</span>
    <form method="post" style="display:inline" onsubmit="return confirm('Accept this quote? After acceptance the original quote becomes read-only and all changes route through variations.');">
      <input type="hidden" name="action" value="accept_quote">
      <input type="hidden" name="proj_id" value="<?= $proj_id ?>">
      <input type="submit" value="Accept Quote (lock original)"
             style="background:#1a6b1a;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer">
    </form>
    <span style="font-size:11px;color:#666;margin-left:6px">In draft mode you can freely add, edit, and remove tasks.</span>
  <?php endif; ?>
</div>
<?php
$quickActions = ob_get_clean();

// Render the editor and splice the quick-action panel in just under <h1>
ob_start();
include __DIR__ . '/stages_editor.php';
$content = ob_get_clean();
$content = preg_replace('#(<h1[^>]*>.*?</h1>)#s', '$1' . $quickActions, $content, 1);
echo $content;
