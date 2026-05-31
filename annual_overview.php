<?php
/**
 * annual_overview.php — whole-of-financial-year money view, with council fees
 * (a pass-through disbursement) separated out to show true design revenue.
 *
 * Council fees are entered on invoices as a labour line under a placeholder
 * staff member (Employee_ID 46 by default — Erik puts the council amount
 * there). So "ex-council" = gross invoiced minus that staffer's billed total.
 * The id is configurable via App_Meta 'council_fee_employee_id' so it isn't
 * hardcoded.
 *
 * Bases (chosen to reconcile with the existing reports):
 *   • Gross invoiced  = SUM(Invoices.Subtotal) by invoice month  (matches the
 *     annual roll-up on monthly_invoicing.php — captures lump-sum invoices too).
 *   • Council portion = SUM(Timesheets.Hours × Rate) for the council staffer,
 *     joined to its invoice's month (Rate already has the Multiplier baked in,
 *     same basis as revenue_report.php).
 *   • Net design revenue = gross − council.
 *
 * NZ financial year (1 Apr – 31 Mar). Admin only (erik / jen).
 */

require_once 'auth_check.php';
require_once 'db_connect.php';
require_once 'helpers.php';

$pdo  = get_db();
$user = $_SESSION['UserID'] ?? '';
if (!in_array($user, ['erik', 'jen'], true)) { http_response_code(403); die('Admin only. <a href="menu.php">Back</a>'); }

$councilEmp = (int)meta_get($pdo, 'council_fee_employee_id', '46');

// ── Financial-year selection (start year; FY runs 1 Apr start → 31 Mar next) ──
$curFyStart = ((int)date('n') >= 4) ? (int)date('Y') : (int)date('Y') - 1;
$fyStart = (int)($_GET['fy'] ?? $curFyStart);
if ($fyStart < 2000 || $fyStart > $curFyStart + 1) $fyStart = $curFyStart;
$from = sprintf('%04d-04-01', $fyStart);
$to   = sprintf('%04d-03-31', $fyStart + 1);

// ── Gross invoiced by month (Invoices.Subtotal) + paid/outstanding ──────────
$gByMonth = [];   // "Y-m" => gross
$grossTotal = 0.0; $paidTotal = 0.0;
$gs = $pdo->prepare(
    "SELECT YEAR(`Date`) y, MONTH(`Date`) m,
            SUM(Subtotal) gross,
            SUM(CASE WHEN COALESCE(Paid,0) = 1 THEN Subtotal ELSE 0 END) paid
       FROM Invoices
      WHERE `Date` BETWEEN ? AND ?
      GROUP BY YEAR(`Date`), MONTH(`Date`)"
);
$gs->execute([$from, $to]);
foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = sprintf('%04d-%02d', (int)$r['y'], (int)$r['m']);
    $gByMonth[$key] = (float)$r['gross'];
    $grossTotal += (float)$r['gross'];
    $paidTotal  += (float)$r['paid'];
}

// ── Council portion by month (the council staffer's billed labour) ──────────
$cByMonth = []; $councilTotal = 0.0;
$cs = $pdo->prepare(
    "SELECT YEAR(i.`Date`) y, MONTH(i.`Date`) m, SUM(t.Hours * COALESCE(t.Rate,0)) council
       FROM Timesheets t
       INNER JOIN Invoices i ON t.Invoice_No = i.Invoice_No
      WHERE t.Employee_id = ? AND i.`Date` BETWEEN ? AND ?
      GROUP BY YEAR(i.`Date`), MONTH(i.`Date`)"
);
$cs->execute([$councilEmp, $from, $to]);
foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = sprintf('%04d-%02d', (int)$r['y'], (int)$r['m']);
    $cByMonth[$key] = (float)$r['council'];
    $councilTotal += (float)$r['council'];
}

$netTotal         = $grossTotal - $councilTotal;
$outstandingTotal = $grossTotal - $paidTotal;

// ── Per-employee invoiced labour, FY-to-date (design contribution) ──────────
$byEmp = [];
$es = $pdo->prepare(
    "SELECT t.Employee_id, s.Login, SUM(t.Hours * COALESCE(t.Rate,0)) amount, SUM(t.Hours) hours
       FROM Timesheets t
       INNER JOIN Invoices i ON t.Invoice_No = i.Invoice_No
       LEFT  JOIN Staff    s ON t.Employee_id = s.Employee_ID
      WHERE i.`Date` BETWEEN ? AND ?
      GROUP BY t.Employee_id, s.Login
      ORDER BY amount DESC"
);
$es->execute([$from, $to]);
$empRows = $es->fetchAll(PDO::FETCH_ASSOC);

// ── Profitability estimate (labour margin) ────────────────────────────────
// Cost of the INVOICED labour that produced the revenue = Hours × the
// assignee's CURRENT Pay_Rate (an estimate — not historical rates). Council
// staffer excluded (pass-through, no real wage cost).
$lc = $pdo->prepare(
    "SELECT SUM(t.Hours * COALESCE(s.Pay_Rate, 0))
       FROM Timesheets t
       INNER JOIN Invoices i ON t.Invoice_No = i.Invoice_No
       LEFT  JOIN Staff    s ON t.Employee_id = s.Employee_ID
      WHERE i.`Date` BETWEEN ? AND ? AND t.Employee_id <> ?"
);
$lc->execute([$from, $to, $councilEmp]);
$labourCost  = (float)$lc->fetchColumn();
$grossMargin = $netTotal - $labourCost;                    // design revenue (ex-council) − labour cost
$overheads   = max(0.0, (float)($_GET['overheads'] ?? 0)); // optional annual overheads
$netProfit   = $grossMargin - $overheads;
$payeTax     = nz_income_tax(max(0.0, $netProfit));        // if drawn by one person (Erik)
$takeHome    = $netProfit - $payeTax;
$effRate     = $netProfit > 0 ? ($payeTax / $netProfit) : 0.0;

// ── FY-ordered month list (Apr … Mar) ───────────────────────────────────────
$months = [];
for ($i = 0; $i < 12; $i++) {
    $mm = 4 + $i;            // Apr=4 … Mar=15
    $yy = $fyStart + intdiv($mm - 1, 12);
    $mn = (($mm - 1) % 12) + 1;
    $months[] = sprintf('%04d-%02d', $yy, $mn);
}
$maxNet = 0.0;
foreach ($months as $k) $maxNet = max($maxNet, ($gByMonth[$k] ?? 0) - ($cByMonth[$k] ?? 0));

function ao_money(float $n): string { return ($n < 0 ? '-$' : '$') . number_format(abs($n), 2); }
function ao_mlabel(string $key): string { $t = strtotime($key . '-01'); return $t ? date('M Y', $t) : $key; }

// NZ resident income-tax brackets (PAYE estimate; excludes ACC earner's levy,
// KiwiSaver, student loan). Progressive — each slice taxed at its own rate.
function nz_tax_brackets(): array {
    return [[15600, 0.105], [53500, 0.175], [78100, 0.30], [180000, 0.33], [PHP_INT_MAX, 0.39]];
}
function nz_income_tax(float $income): float {
    $tax = 0.0; $lower = 0.0;
    foreach (nz_tax_brackets() as [$upper, $rate]) {
        if ($income <= $lower) break;
        $tax  += (min($income, (float)$upper) - $lower) * $rate;
        $lower = (float)$upper;
    }
    return $tax;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Annual overview — CADViz</title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#5d3a9b; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:17px; font-weight:400; }
.page { max-width:1000px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:5px; padding:14px 16px; margin:12px 0; }
.summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:8px; }
.metric { background:#f4f4f4; border:1px solid #ddd; border-radius:4px; padding:9px 12px; }
.metric .label { font-size:11px; color:#777; }
.metric .value { font-size:19px; font-weight:600; }
.metric.net { background:#d6f5d6; border-color:#1a6b1a; } .metric.net .value { color:#155515; }
.metric.disb { background:#ffe4d6; border-color:#c33; } .metric.disb .value { color:#a00; }
.metric.out  { background:#fff3cd; border-color:#c8a52e; } .metric.out .value { color:#7a5a00; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { padding:5px 9px; border-bottom:1px solid #f0f0f0; }
th { background:#f4f4f4; text-align:left; font-size:11px; }
.right { text-align:right; font-variant-numeric:tabular-nums; }
.bar { display:inline-block; height:9px; background:#5d3a9b; border-radius:2px; vertical-align:middle; }
.muted { color:#888; }
select { padding:5px 8px; border:1px solid #ccc; border-radius:3px; font:inherit; }
</style>
</head>
<body>
<div class="topbar">
  <h1>&#128202; Annual overview &mdash; FY <?= $fyStart ?>/<?= substr((string)($fyStart + 1), 2) ?></h1>
  <div><a href="analytics.php">Analytics</a> &nbsp;·&nbsp; <a href="menu.php">Menu</a></div>
</div>

<div class="page">

  <div class="card">
    <form method="get" style="display:flex;gap:10px;align-items:center">
      <label>Financial year
        <select name="fy" onchange="this.form.submit()">
          <?php for ($y = $curFyStart + 1; $y >= $curFyStart - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $fyStart ? 'selected' : '' ?>>FY <?= $y ?>/<?= substr((string)($y + 1), 2) ?> (1 Apr <?= $y ?> &ndash; 31 Mar <?= $y + 1 ?>)</option>
          <?php endfor; ?>
        </select>
      </label>
      <label>Annual overheads (optional)
        <input type="number" name="overheads" value="<?= $overheads > 0 ? (int)round($overheads) : '' ?>" step="1000" min="0" placeholder="0" style="width:120px;padding:5px;border:1px solid #ccc;border-radius:3px">
      </label>
      <button type="submit" style="background:#5d3a9b;color:#fff;border:none;padding:6px 14px;border-radius:3px;cursor:pointer">Apply</button>
      <span class="muted">Council fees tracked under staff #<?= $councilEmp ?> (App_Meta <code>council_fee_employee_id</code>).</span>
    </form>

    <div class="summary" style="margin-top:12px">
      <div class="metric"><div class="label">Invoiced (gross, ex-GST)</div><div class="value"><?= ao_money($grossTotal) ?></div></div>
      <div class="metric"><div class="label">Paid</div><div class="value"><?= ao_money($paidTotal) ?></div></div>
      <div class="metric out"><div class="label">Outstanding</div><div class="value"><?= ao_money($outstandingTotal) ?></div></div>
      <div class="metric disb"><div class="label">Council fees (pass-through)</div><div class="value"><?= ao_money($councilTotal) ?></div></div>
      <div class="metric net"><div class="label">Net design revenue (ex-council)</div><div class="value"><?= ao_money($netTotal) ?></div></div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px;color:#5d3a9b;font-size:14px;border-bottom:1px solid #eee;padding-bottom:4px">Profitability (estimate)</h3>
    <table style="max-width:540px">
      <tr><td>Net design revenue (ex-council)</td><td class="right"><?= ao_money($netTotal) ?></td></tr>
      <tr><td>&minus; Labour cost (invoiced hours &times; pay rate)</td><td class="right" style="color:#a00">&minus;<?= ao_money($labourCost) ?></td></tr>
      <tr style="border-top:1px solid #ddd"><td><strong>= Gross margin</strong></td><td class="right"><strong><?= ao_money($grossMargin) ?></strong></td></tr>
      <?php if ($overheads > 0): ?><tr><td>&minus; Overheads (entered)</td><td class="right" style="color:#a00">&minus;<?= ao_money($overheads) ?></td></tr><?php endif; ?>
      <tr style="border-top:2px solid #5d3a9b"><td><strong>= Net profit</strong></td><td class="right"><strong style="color:#155515"><?= ao_money($netProfit) ?></strong></td></tr>
    </table>

    <div style="margin-top:14px">
      <strong>If drawn by one person (Erik) as salary &mdash; NZ PAYE estimate:</strong>
      <table style="max-width:540px;margin-top:6px">
        <tr><td>PAYE income tax</td><td class="right" style="color:#a00">&minus;<?= ao_money($payeTax) ?></td></tr>
        <tr><td><strong>Estimated take-home</strong></td><td class="right"><strong style="color:#155515"><?= ao_money($takeHome) ?></strong></td></tr>
        <tr><td class="muted">Effective tax rate</td><td class="right muted"><?= number_format($effRate * 100, 1) ?>%</td></tr>
      </table>
      <details style="margin-top:8px"><summary style="cursor:pointer;color:#888">tax bracket breakdown</summary>
        <table style="max-width:540px;margin-top:6px;font-size:11px">
          <?php
            $inc = max(0.0, $netProfit); $lower = 0.0;
            $labels = ['$0&ndash;15,600 @ 10.5%', '$15,601&ndash;53,500 @ 17.5%', '$53,501&ndash;78,100 @ 30%', '$78,101&ndash;180,000 @ 33%', '$180,001+ @ 39%'];
            foreach (nz_tax_brackets() as $idx => [$upper, $rate]):
              $slice = $inc > $lower ? (min($inc, (float)$upper) - $lower) : 0.0;
              $lower = (float)$upper;
          ?>
            <tr><td><?= $labels[$idx] ?></td><td class="right"><?= ao_money($slice * $rate) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </details>
    </div>

    <p class="muted" style="font-size:11px;margin-top:10px">
      Estimate only. Labour cost = each staffer's <em>current</em> Pay_Rate &times; their invoiced hours (not historical rates). Gross margin = revenue &minus; that labour cost; it does <strong>not</strong> include rent, software, vehicles, ACC, KiwiSaver, etc. &mdash; put those in &ldquo;Annual overheads&rdquo; for a net-profit figure. The PAYE estimate assumes the whole net profit is one person's salary and excludes the ACC earner's levy / KiwiSaver / student loan.
    </p>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px;color:#5d3a9b;font-size:14px;border-bottom:1px solid #eee;padding-bottom:4px">Month by month</h3>
    <table>
      <thead><tr><th>Month</th><th class="right">Invoiced</th><th class="right">Council</th><th class="right">Net (ex-council)</th><th style="width:150px"></th></tr></thead>
      <tbody>
        <?php foreach ($months as $k):
            $g = $gByMonth[$k] ?? 0.0; $c = $cByMonth[$k] ?? 0.0; $n = $g - $c;
            $w = $maxNet > 0 ? round(140 * max(0, $n) / $maxNet) : 0;
        ?>
          <tr>
            <td><?= htmlspecialchars(ao_mlabel($k)) ?></td>
            <td class="right"><?= ao_money($g) ?></td>
            <td class="right" style="color:#a00"><?= $c > 0 ? ao_money($c) : '<span class="muted">—</span>' ?></td>
            <td class="right" style="color:#155515"><?= ao_money($n) ?></td>
            <td><span class="bar" style="width:<?= $w ?>px"></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;border-top:2px solid #ddd">
          <td>FY total</td>
          <td class="right"><?= ao_money($grossTotal) ?></td>
          <td class="right" style="color:#a00"><?= ao_money($councilTotal) ?></td>
          <td class="right" style="color:#155515"><?= ao_money($netTotal) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px;color:#5d3a9b;font-size:14px;border-bottom:1px solid #eee;padding-bottom:4px">Revenue by employee (FY-to-date)</h3>
    <?php if (empty($empRows)): ?>
      <p class="muted">No invoiced labour in this financial year.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Staff</th><th class="right">Hours</th><th class="right">Invoiced revenue</th><th>&nbsp;</th></tr></thead>
        <tbody>
          <?php foreach ($empRows as $r):
              $isCouncil = ((int)$r['Employee_id'] === $councilEmp);
              $name = $isCouncil ? 'Council fees (disbursement)' : (string)($r['Login'] ?: ('Staff #' . (int)$r['Employee_id']));
          ?>
            <tr<?= $isCouncil ? ' style="background:#ffe4d6"' : '' ?>>
              <td><?= htmlspecialchars($name) ?></td>
              <td class="right"><?= number_format((float)$r['hours'], 1) ?></td>
              <td class="right"><?= ao_money((float)$r['amount']) ?></td>
              <td><?= $isCouncil ? '<span class="muted" style="font-size:11px">excluded from net design revenue</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted" style="font-size:11px;margin-top:8px">Revenue = invoiced labour (Hours × Rate, Rate has the client multiplier baked in). Reconciles with the Revenue Report tab; council fees are the staff #<?= $councilEmp ?> line, shown separately and excluded from net design revenue.</p>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
