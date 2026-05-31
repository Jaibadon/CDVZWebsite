<?php
/**
 * analytics.php — one tabbed home for all of CADViz's reporting.
 *
 * Existing tools are embedded (iframe) so there's a single place to look;
 * new/DMS-era reports are scaffolded with the metrics + data sources they'll
 * use so they can be filled in incrementally.
 *
 * Admin only (erik / jen).
 */

require_once 'auth_check.php';
require_once 'db_connect.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
if (!in_array($user, ['erik', 'jen'], true)) { http_response_code(403); die('Admin only.'); }

// ── Tab registry ──────────────────────────────────────────────────────────
// type 'embed' → iframe the existing page; type 'stub' → native scaffold panel.
$tabs = [
    'workload'   => ['label' => 'Staff Workload',   'type' => 'embed', 'src' => 'staff_workload.php',  'group' => 'People'],
    'hours'      => ['label' => 'Uninvoiced Hours',  'type' => 'embed', 'src' => 'staff_hours.php',     'group' => 'People'],
    'tasks'      => ['label' => 'Task Analytics',    'type' => 'embed', 'src' => 'task_analytics.php',  'group' => 'Delivery'],
    'invoicing'  => ['label' => 'Monthly Invoicing', 'type' => 'embed', 'src' => 'monthly_invoicing.php','group' => 'Money'],
    'revenue'    => ['label' => 'Revenue Report',    'type' => 'embed', 'src' => 'revenue_report.php',  'group' => 'Money'],
    'annual'     => ['label' => 'Annual Overview',   'type' => 'embed', 'src' => 'annual_overview.php', 'group' => 'Money'],
    'dms_rev'    => ['label' => 'DMS · Revisions & Coverage', 'type' => 'stub', 'group' => 'DMS'],
    'dms_act'    => ['label' => 'DMS · Design Activity',      'type' => 'stub', 'group' => 'DMS'],
];

$tab = (string)($_GET['tab'] ?? 'workload');
if (!isset($tabs[$tab])) $tab = 'workload';
$cur = $tabs[$tab];

// ── Scaffold copy for the stub tabs (what each will show + its data source) ──
$stubs = [
    'annual' => [
        'blurb' => 'Whole-of-year money view: invoiced vs paid by month, FY-to-date totals, and the same figures with council/consent fees and other disbursements stripped out (true design revenue).',
        'build' => [
            'FY-to-date invoiced, paid, and outstanding (reuse monthly_invoicing.php\'s annual roll-up).',
            'A "minus council fees / disbursements" toggle — net design revenue vs gross billings.',
            'Per-employee revenue contribution, FY-to-date (joins Invoices/Timesheets → Staff), with the disbursement filter applied.',
            'Month-by-month bar (Chart.js, already used by task_analytics.php).',
        ],
        'sources' => 'Invoices, Timesheets, Staff, Payments. Council-fee/disbursement flag: the same line classification revenue_report.php already uses.',
    ],
    'dms_rev' => [
        'blurb' => 'Coordination health from the DMS: are revisions getting reviewed and approved, and what code areas keep coming up?',
        'build' => [
            'Revisions per project + open vs issued status (Commits.Status).',
            'Transmittal turnaround — avg days from sent → approved, and the current un-acked backlog (Transmittals / Transmittal_Recipients).',
            'Approval-gate outcomes — how often issue was blocked, changes-requested rate.',
            'NZBC clause frequency — which clauses get tagged most (Commit_NZBC_Tags), and rule firing accept/reject rates (Coverage_Rule_Firings.Disposition) — also the AI-training label quality signal.',
        ],
        'sources' => 'Commits, Transmittals, Transmittal_Recipients, Commit_NZBC_Tags, Coverage_Rule_Firings.',
    ],
    'dms_act' => [
        'blurb' => 'Design activity & churn from the per-commit diffs — a proxy for project complexity and where effort is going.',
        'build' => [
            'Elements changed per revision over time (Commit_Diffs) — spot late-stage churn.',
            'Most-changed categories per project (walls/windows/etc.) and geometry-change frequency.',
            'Keynote usage — which spec keynotes appear most across projects (Commit_Keynotes + element Keynote params).',
            'Model size/growth — element counts per commit (Element_Instances), a rough scope-creep indicator.',
        ],
        'sources' => 'Commit_Diffs, Commit_Diff_Params, Element_Instances, Commit_Keynotes. Populates as the add-in publishes commits.',
    ],
];

// Group tabs for the nav bar.
$byGroup = [];
foreach ($tabs as $k => $t) $byGroup[$t['group']][$k] = $t;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics — CADViz</title>
<link href="site.css" rel="stylesheet">
<style>
* { box-sizing:border-box; }
html, body { height:100%; margin:0; }
body { display:flex; flex-direction:column; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; background:#fafafa; }
.topbar { background:#5d3a9b; color:#fff; padding:9px 16px; display:flex; justify-content:space-between; align-items:center; flex:0 0 auto; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:17px; font-weight:400; }
.tabbar { background:#efeaf6; border-bottom:1px solid #d6c9ec; padding:6px 10px; display:flex; gap:14px; flex-wrap:wrap; flex:0 0 auto; align-items:center; }
.tabgroup { display:flex; gap:4px; align-items:center; }
.tabgroup .gl { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#8a7aa8; margin-right:2px; }
.tab { padding:5px 11px; border-radius:14px; text-decoration:none; color:#4a3a66; font-size:12px; border:1px solid transparent; }
.tab:hover { background:#e2d6f2; }
.tab.active { background:#5d3a9b; color:#fff; font-weight:600; }
.content { flex:1 1 auto; min-height:0; }
.content iframe { width:100%; height:100%; border:0; display:block; }
.stub { max-width:760px; margin:24px auto; padding:0 18px; }
.stub .card { background:#fff; border:1px solid #ddd; border-left:3px solid #5d3a9b; border-radius:5px; padding:16px 18px; margin:14px 0; }
.stub h2 { color:#5d3a9b; margin:0 0 8px; }
.stub ul { margin:8px 0; padding-left:20px; }
.stub li { margin:4px 0; }
.stub .src { color:#777; font-size:12px; margin-top:10px; }
.badge { background:#efeaf6; color:#5d3a9b; border:1px solid #d6c9ec; border-radius:9px; padding:1px 8px; font-size:11px; }
</style>
</head>
<body>
<div class="topbar">
  <h1>&#128202; Analytics</h1>
  <div><a href="menu.php">Menu</a></div>
</div>

<div class="tabbar">
  <?php foreach (['People','Delivery','Money','DMS'] as $g): if (empty($byGroup[$g])) continue; ?>
    <div class="tabgroup">
      <span class="gl"><?= htmlspecialchars($g) ?></span>
      <?php foreach ($byGroup[$g] as $k => $t): ?>
        <a class="tab <?= $k === $tab ? 'active' : '' ?>" href="analytics.php?tab=<?= urlencode($k) ?>">
          <?= htmlspecialchars($t['label']) ?><?= $t['type'] === 'stub' ? ' <span class="badge">soon</span>' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="content">
<?php if ($cur['type'] === 'embed'): ?>
  <iframe src="<?= htmlspecialchars($cur['src']) ?>" title="<?= htmlspecialchars($cur['label']) ?>"></iframe>
<?php else:
    $s = $stubs[$tab] ?? ['blurb' => '', 'build' => [], 'sources' => '']; ?>
  <div class="stub">
    <div class="card">
      <h2><?= htmlspecialchars($cur['label']) ?> <span class="badge">planned</span></h2>
      <p><?= htmlspecialchars($s['blurb']) ?></p>
      <strong>This tab will show:</strong>
      <ul><?php foreach ($s['build'] as $b): ?><li><?= htmlspecialchars($b) ?></li><?php endforeach; ?></ul>
      <div class="src"><strong>Data sources:</strong> <?= htmlspecialchars($s['sources']) ?></div>
    </div>
  </div>
<?php endif; ?>
</div>
</body>
</html>
