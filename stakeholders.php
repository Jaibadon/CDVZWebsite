<?php
/**
 * stakeholders.php?proj_id=N — per-project stakeholder CRUD.
 *
 * Lists the 3rd parties tied to a project: structural eng, geotech, civil,
 * fire eng, weathertightness reviewer, cladding/joinery/membrane/roofing
 * manufacturers, consultants, client, council. Each row is a person + email
 * + role, and is the address book the transmittal-send flow draws on when
 * fanning a commit out to "everyone who needs to ack this revision".
 *
 * Access: any logged-in user. Staff need to maintain their own project's
 * contacts — gating to admin would create friction. Soft-delete via Active=0
 * preserves Transmittal_Recipients references.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
$empId = (int)($_SESSION['Employee_id'] ?? 0);
$proj_id = (int)($_GET['proj_id'] ?? 0);
if ($proj_id <= 0) die('<p>Missing proj_id. <a href="projects.php">Back to projects</a></p>');

$proj = $pdo->prepare(
    "SELECT p.proj_id, p.JobName, c.Client_Name, c.Client_id
       FROM Projects p
       LEFT JOIN Clients c ON p.Client_ID = c.Client_id
      WHERE p.proj_id = ?"
);
$proj->execute([$proj_id]);
$proj = $proj->fetch();
if (!$proj) die('<p>Project not found.</p>');

// ── Role catalogue ──────────────────────────────────────────────────────
// Keep aligned with Coverage_Rules.Default_Stakeholder_Roles_CSV values in
// add_coverage_seed.sql. The keys are what's stored in
// Project_Stakeholders.Role; the labels are display-only.
$roleLabels = [
    'structural'             => 'Structural Engineer',
    'geotech'                => 'Geotechnical Engineer',
    'civil'                  => 'Civil Engineer',
    'fire'                   => 'Fire Engineer',
    'weathertightness'       => 'Weathertightness Reviewer',
    'energy_h1'              => 'Energy / H1 Reviewer',
    'manufacturer_cladding'  => 'Cladding Manufacturer',
    'manufacturer_joinery'   => 'Joinery Manufacturer',
    'manufacturer_membrane'  => 'Membrane Manufacturer',
    'manufacturer_roofing'   => 'Roofing Manufacturer',
    'manufacturer_other'     => 'Manufacturer (other)',
    'consultant'             => 'Consultant (other)',
    'client'                 => 'Client',
    'council'                => 'Council / Building Control',
    'other'                  => 'Other',
];

$flash = ''; $flashErr = '';

// ── POST handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'add': {
                $role  = trim((string)($_POST['Role']  ?? ''));
                $name  = trim((string)($_POST['Name']  ?? ''));
                $email = trim((string)($_POST['Email'] ?? ''));
                if ($role === '' || !isset($roleLabels[$role])) throw new Exception('Pick a role.');
                if ($name === '')  throw new Exception('Name is required.');
                if ($email === '') throw new Exception('Email is required.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email is not valid.');

                $pdo->prepare(
                    "INSERT INTO Project_Stakeholders
                       (Proj_ID, Role, Role_Label, Name, Email, Phone, Company, Notes, Active, Added_By, Added_At)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())"
                )->execute([
                    $proj_id, $role,
                    trim((string)($_POST['Role_Label'] ?? '')) ?: null,
                    $name, $email,
                    trim((string)($_POST['Phone']   ?? '')) ?: null,
                    trim((string)($_POST['Company'] ?? '')) ?: null,
                    trim((string)($_POST['Notes']   ?? '')) ?: null,
                    $user,
                ]);
                $flash = "Added $name ({$roleLabels[$role]}).";
                break;
            }
            case 'update': {
                $sid = (int)($_POST['Stakeholder_ID'] ?? 0);
                if ($sid <= 0) throw new Exception('Missing Stakeholder_ID.');
                $role  = trim((string)($_POST['Role']  ?? ''));
                $name  = trim((string)($_POST['Name']  ?? ''));
                $email = trim((string)($_POST['Email'] ?? ''));
                if ($role === '' || !isset($roleLabels[$role])) throw new Exception('Pick a role.');
                if ($name === '')  throw new Exception('Name is required.');
                if ($email === '') throw new Exception('Email is required.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email is not valid.');

                $pdo->prepare(
                    "UPDATE Project_Stakeholders
                        SET Role = ?, Role_Label = ?, Name = ?, Email = ?, Phone = ?, Company = ?, Notes = ?
                      WHERE Stakeholder_ID = ? AND Proj_ID = ?"
                )->execute([
                    $role,
                    trim((string)($_POST['Role_Label'] ?? '')) ?: null,
                    $name, $email,
                    trim((string)($_POST['Phone']   ?? '')) ?: null,
                    trim((string)($_POST['Company'] ?? '')) ?: null,
                    trim((string)($_POST['Notes']   ?? '')) ?: null,
                    $sid, $proj_id,
                ]);
                $flash = "Updated $name.";
                break;
            }
            case 'deactivate':
            case 'reactivate': {
                $sid = (int)($_POST['Stakeholder_ID'] ?? 0);
                if ($sid <= 0) throw new Exception('Missing Stakeholder_ID.');
                $newActive = ($action === 'reactivate') ? 1 : 0;
                $pdo->prepare("UPDATE Project_Stakeholders SET Active = ? WHERE Stakeholder_ID = ? AND Proj_ID = ?")
                    ->execute([$newActive, $sid, $proj_id]);
                $flash = $action === 'reactivate' ? 'Reactivated.' : 'Deactivated (kept for audit / transmittal history).';
                break;
            }
        }
    } catch (Exception $e) {
        $flashErr = $e->getMessage();
    }
}

// ── Load current stakeholders ───────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT * FROM Project_Stakeholders
      WHERE Proj_ID = ?
      ORDER BY Active DESC, Role, Name"
);
$stmt->execute([$proj_id]);
$stakeholders = $stmt->fetchAll();

// Bucket by role for display, then by Active state
$activeByRole   = [];
$inactiveByRole = [];
foreach ($stakeholders as $s) {
    $r = (string)$s['Role'];
    if ((int)$s['Active'] === 1) $activeByRole[$r][] = $s;
    else                          $inactiveByRole[$r][] = $s;
}

// ── Helper: render one editable form row ─────────────────────────────────
function render_stakeholder_form(array $s, array $roleLabels): void {
?>
  <form method="post" style="padding:6px 0;border-bottom:1px solid #f0f0f0">
    <input type="hidden" name="action"         value="update">
    <input type="hidden" name="Stakeholder_ID" value="<?= (int)$s['Stakeholder_ID'] ?>">
    <div style="display:grid;grid-template-columns:160px 1.4fr 1.6fr 130px 1fr 28px;gap:6px;align-items:center">
      <select name="Role">
        <?php foreach ($roleLabels as $rk => $rl): ?>
          <option value="<?= htmlspecialchars($rk) ?>" <?= $s['Role'] === $rk ? 'selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text"  name="Name"    value="<?= htmlspecialchars((string)$s['Name'])    ?>" placeholder="Name" required>
      <input type="email" name="Email"   value="<?= htmlspecialchars((string)$s['Email'])   ?>" placeholder="email@example.com" required>
      <input type="text"  name="Phone"   value="<?= htmlspecialchars((string)($s['Phone'] ?? ''))   ?>" placeholder="Phone">
      <input type="text"  name="Company" value="<?= htmlspecialchars((string)($s['Company'] ?? '')) ?>" placeholder="Company">
      <button type="submit" class="btn" title="Save changes" style="padding:3px 6px;font-size:12px">💾</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:6px;margin-top:4px;padding-right:34px">
      <input type="text" name="Role_Label" value="<?= htmlspecialchars((string)($s['Role_Label'] ?? '')) ?>"
             placeholder='Role label (optional, e.g. "James Hardie cladding rep")' style="font-size:12px">
      <input type="text" name="Notes" value="<?= htmlspecialchars((string)($s['Notes'] ?? '')) ?>"
             placeholder="Internal notes (never shown to the stakeholder)" style="font-size:12px">
    </div>
  </form>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stakeholders — <?= htmlspecialchars((string)$proj['JobName']) ?></title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:1100px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:4px; padding:12px 14px; margin:10px 0; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer; font:inherit; text-decoration:none; display:inline-block; }
.btn:hover { background:#7a7a16; }
.btn-secondary { background:#555; }
.btn-danger { background:#c33; color:#fff; border:none; padding:3px 8px; border-radius:3px; cursor:pointer; font-size:11px; }
.btn-success { background:#1a6b1a; color:#fff; border:none; padding:3px 8px; border-radius:3px; cursor:pointer; font-size:11px; }
.flash-ok  { background:#d6f5d6; border:1px solid #1a6b1a; color:#1a6b1a; padding:8px 12px; border-radius:3px; margin:8px 0; }
.flash-err { background:#ffd6d6; border:1px solid #c33;  color:#a00;     padding:8px 12px; border-radius:3px; margin:8px 0; }
.role-section { margin:10px 0; }
.role-header { font-size:13px; font-weight:600; color:#444; padding:6px 0; border-bottom:1px solid #ccc; margin-bottom:4px; }
.role-tag { display:inline-block; background:#f6f6e2; border:1px solid #d3d3a5; padding:2px 8px; border-radius:11px; font-size:10px; color:#666; margin-left:6px; }
input[type="text"], input[type="email"], select, textarea {
  border:1px solid #ddd; padding:4px 6px; border-radius:3px; font:inherit; box-sizing:border-box;
}
input[type="text"]:focus, input[type="email"]:focus, select:focus, textarea:focus {
  outline:none; border-color:#c8a52e; background:#fff8e0;
}
.add-form { display:grid; grid-template-columns:160px 1.4fr 1.6fr 130px 1fr 90px; gap:6px; align-items:center; }
.role-empty { color:#888; font-size:11px; font-style:italic; padding:6px 0; }
</style>
</head>
<body>
<div class="topbar">
  <h1>🤝 Stakeholders — <?= htmlspecialchars((string)$proj['JobName']) ?></h1>
  <div>
    <a href="project_stages.php?proj_id=<?= $proj_id ?>">← Stages</a>
    &nbsp;·&nbsp;
    <a href="menu.php">Menu</a>
  </div>
</div>

<div class="page">

  <?php if ($flash):    ?><div class="flash-ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

  <div class="card" style="font-size:12px;color:#555">
    <strong>Project:</strong> <?= htmlspecialchars((string)$proj['JobName']) ?>
    <?php if ($proj['Client_Name']): ?>&nbsp;·&nbsp;<?= htmlspecialchars((string)$proj['Client_Name']) ?><?php endif; ?>
    <span style="margin-left:14px;color:#888">
      <?= count($stakeholders) ?> stakeholder<?= count($stakeholders) === 1 ? '' : 's' ?>
      (<?= array_sum(array_map('count', $activeByRole)) ?> active)
    </span>
  </div>

  <p style="font-size:12px;color:#666;margin:0 0 14px">
    The 3rd parties tied to this project — they receive a magic-link review email
    each time a revision affecting their discipline is published. Roles match the
    coverage-rule "default stakeholder role" suggestions.
  </p>

  <!-- ── Add new ────────────────────────────────────────────────────── -->
  <div class="card">
    <strong>+ Add stakeholder</strong>
    <form method="post" style="margin-top:8px">
      <input type="hidden" name="action" value="add">
      <div class="add-form">
        <select name="Role" required>
          <option value="">— role —</option>
          <?php foreach ($roleLabels as $rk => $rl): ?>
            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text"  name="Name"    placeholder="Name (e.g. Jens Schroeder)" required>
        <input type="email" name="Email"   placeholder="email@example.com" required>
        <input type="text"  name="Phone"   placeholder="Phone (optional)">
        <input type="text"  name="Company" placeholder="Company (optional)">
        <button type="submit" class="btn">Add</button>
      </div>
      <details style="margin-top:6px">
        <summary style="font-size:11px;color:#888;cursor:pointer">Optional: role label override + internal notes</summary>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:6px;margin-top:6px">
          <input type="text" name="Role_Label" placeholder='Role label e.g. "James Hardie cladding rep"'
                 title="Disambiguates when you have several reps for the same role/company">
          <textarea name="Notes" placeholder="Internal notes (never shown to the stakeholder)" rows="2"></textarea>
        </div>
      </details>
    </form>
  </div>

  <!-- ── Existing stakeholders by role ──────────────────────────────── -->
  <?php if (empty($stakeholders)): ?>
    <div class="card" style="text-align:center;color:#888;padding:30px">
      No stakeholders yet. Add the first one using the form above.
    </div>
  <?php else: ?>
    <?php foreach ($roleLabels as $rk => $rl):
        $here  = $activeByRole[$rk]   ?? [];
        $there = $inactiveByRole[$rk] ?? [];
        if (empty($here) && empty($there)) continue;
    ?>
      <div class="card role-section">
        <div class="role-header">
          <?= htmlspecialchars($rl) ?>
          <span class="role-tag"><?= count($here) ?> active<?= count($there) > 0 ? ' / ' . count($there) . ' archived' : '' ?></span>
        </div>
        <?php foreach ($here as $s): render_stakeholder_form($s, $roleLabels); ?>
          <div style="text-align:right;padding:2px 0 8px">
            <form method="post" style="display:inline" onsubmit="return confirm('Archive <?= htmlspecialchars(addslashes((string)$s['Name'])) ?>? They\'ll stop receiving new transmittals but past transmittal history is preserved.');">
              <input type="hidden" name="action"         value="deactivate">
              <input type="hidden" name="Stakeholder_ID" value="<?= (int)$s['Stakeholder_ID'] ?>">
              <button type="submit" class="btn-danger">Archive</button>
            </form>
          </div>
        <?php endforeach; ?>

        <?php if (!empty($there)): ?>
          <details style="margin-top:4px">
            <summary style="font-size:11px;color:#888;cursor:pointer">Archived (<?= count($there) ?>)</summary>
            <div style="opacity:0.6">
              <?php foreach ($there as $s): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0">
                  <div>
                    <strong><?= htmlspecialchars((string)$s['Name']) ?></strong>
                    <span style="color:#888">· <?= htmlspecialchars((string)$s['Email']) ?></span>
                    <?php if ($s['Company']): ?><span style="color:#888">· <?= htmlspecialchars((string)$s['Company']) ?></span><?php endif; ?>
                  </div>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action"         value="reactivate">
                    <input type="hidden" name="Stakeholder_ID" value="<?= (int)$s['Stakeholder_ID'] ?>">
                    <button type="submit" class="btn-success">Reactivate</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
</body>
</html>
