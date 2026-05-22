<?php
/**
 * revenue_report.php — admin report of invoiced revenue by employee, with
 * a date range filter and a "task contains" filter that can be flagged as
 * a pass-through disbursement (e.g. council fees) so it's subtracted from
 * the revenue total.
 *
 * The MySQL equivalent of the legacy Access query:
 *   SELECT Invoice_Labour.TS_DATE, Invoice_Labour.Employee_id, ...,
 *          [Hours]*Invoice_Labour![Billing Rate] AS Amnt, ...
 *     FROM (Invoice_Labour INNER JOIN Invoices ON ...) INNER JOIN Staff ON ...
 *    WHERE Invoice_Labour.TS_DATE BETWEEN ...
 *
 * In the MySQL port, "Invoice_Labour" is Timesheets WHERE Invoice_No > 0 —
 * the invoice-line rate is Timesheets.Rate (baked in by invoice_gen.php
 * with the client's Multiplier already applied — see overview.md).
 *
 * Defaults to the current NZ financial year (1 Apr – 31 Mar).
 *
 * The "task contains" filter doubles as the Council-Fee / disbursement
 * detector. When set + "subtract from revenue" is ticked, matching rows
 * are reported separately and pulled out of the headline revenue total —
 * so for insurance / PI purposes you can quote net revenue (your real
 * earnings, not the pass-through council fees).
 *
 *   ?format=csv  — download the detail table as CSV (for accountant
 *                  handover / spreadsheet pivot). Honours the same filters.
 */

require_once 'auth_check.php';
require_once 'db_connect.php';

$user = $_SESSION['UserID'] ?? '';
if (!in_array($user, ['erik', 'jen'], true)) {
    http_response_code(403); die('Admin only. <a href="menu.php">Back</a>');
}
$pdo = get_db();

// ── NZ financial year default (1 April – 31 March) ──────────────────────
function nz_fy_dates(): array {
    $y = (int)date('Y'); $m = (int)date('n');
    if ($m >= 4) return [sprintf('%04d-04-01', $y),     sprintf('%04d-03-31', $y + 1)];
    else         return [sprintf('%04d-04-01', $y - 1), sprintf('%04d-03-31', $y)];
}
[$defaultFrom, $defaultTo] = nz_fy_dates();

$from         = trim((string)($_GET['from'] ?? $defaultFrom));
$to           = trim((string)($_GET['to']   ?? $defaultTo));
$employeeId   = (int)($_GET['employee_id'] ?? 0);
$taskQ        = trim((string)($_GET['task'] ?? ''));
$subtractDisb = !empty($_GET['subtract']);          // treat matched rows as disbursement (subtract from revenue)
$asCsv        = ($_GET['format'] ?? '') === 'csv';

// Sanity-check date format (defensive)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $defaultTo;
if ($from > $to) [$from, $to] = [$to, $from];

// ── Build query ─────────────────────────────────────────────────────────
$sql = "SELECT t.TS_DATE,
               t.Employee_id,
               i.Client_ID,
               t.Hours,
               COALESCE(t.Rate, 0)               AS Rate,
               (t.Hours * COALESCE(t.Rate, 0))   AS Amnt,
               s.Login,
               s.Pay_Rate,
               t.Invoice_No,
               t.Task,
               i.Notes,
               c.Client_Name
          FROM Timesheets t
          INNER JOIN Invoices i ON t.Invoice_No  = i.Invoice_No
          INNER JOIN Staff    s ON t.Employee_id = s.Employee_ID
          LEFT  JOIN Clients  c ON i.Client_ID   = c.Client_id
         WHERE t.Invoice_No > 0
           AND t.TS_DATE BETWEEN :from AND :to";
$params = [':from' => $from, ':to' => $to];

if ($employeeId > 0) {
    $sql .= " AND t.Employee_id = :emp";
    $params[':emp'] = $employeeId;
}
$sql .= " ORDER BY t.TS_DATE, s.Login";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Classify each row: is it a disbursement match? ──────────────────────
$taskQLow = strtolower($taskQ);
foreach ($rows as &$r) {
    $r['_match'] = ($taskQ !== '' && $r['Task'] !== null
        && stripos((string)$r['Task'], $taskQ) !== false);
}
unset($r);

// ── Aggregates ──────────────────────────────────────────────────────────
$totalAll = 0.0; $hoursAll = 0.0;
$totalDisb = 0.0; $hoursDisb = 0.0;
$byEmp = [];   // login => ['hours' => ..., 'amount' => ..., 'disb_amount' => ..., 'pay' => Pay_Rate]
foreach ($rows as $r) {
    $amt = (float)$r['Amnt'];
    $hrs = (float)$r['Hours'];
    $totalAll += $amt; $hoursAll += $hrs;
    if ($r['_match']) { $totalDisb += $amt; $hoursDisb += $hrs; }
    $lg = (string)$r['Login'];
    if (!isset($byEmp[$lg])) $byEmp[$lg] = ['hours' => 0.0, 'amount' => 0.0, 'disb_amount' => 0.0, 'pay' => (float)($r['Pay_Rate'] ?? 0)];
    $byEmp[$lg]['hours']  += $hrs;
    $byEmp[$lg]['amount'] += $amt;
    if ($r['_match']) $byEmp[$lg]['disb_amount'] += $amt;
}
ksort($byEmp);

$netRevenue = $subtractDisb ? ($totalAll - $totalDisb) : $totalAll;

// ── CSV output ──────────────────────────────────────────────────────────
if ($asCsv) {
    while (ob_get_level() > 0) ob_end_clean();
    $fname = "revenue_{$from}_to_{$to}" . ($employeeId ? "_emp{$employeeId}" : '')
           . ($taskQ ? '_task-' . preg_replace('/[^A-Za-z0-9]+/', '-', $taskQ) : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['TS_DATE','Login','Employee_id','Client','Invoice_No','Task','Hours','Rate','Amount','Pay_Rate','Notes','TaskMatch']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['TS_DATE'], $r['Login'], $r['Employee_id'],
            $r['Client_Name'] ?? '', $r['Invoice_No'],
            $r['Task'] ?? '', number_format((float)$r['Hours'], 2, '.', ''),
            number_format((float)$r['Rate'], 2, '.', ''),
            number_format((float)$r['Amnt'], 2, '.', ''),
            number_format((float)($r['Pay_Rate'] ?? 0), 2, '.', ''),
            (string)($r['Notes'] ?? ''), $r['_match'] ? '1' : '0',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', '', 'TOTAL HOURS', '', number_format($totalAll, 2, '.', '')]);
    if ($taskQ !== '') {
        fputcsv($out, ['', '', '', '', '', '', "Matches \"$taskQ\"", '', number_format($totalDisb, 2, '.', '')]);
        if ($subtractDisb) fputcsv($out, ['', '', '', '', '', '', 'NET REVENUE (excl. matched)', '', number_format($netRevenue, 2, '.', '')]);
    }
    fclose($out); exit;
}

// ── Employee dropdown options ───────────────────────────────────────────
$staffStmt = $pdo->query("SELECT Employee_ID, Login FROM Staff WHERE Active <> 0 OR Active IS NULL ORDER BY Login");
$staff     = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_money(float $n): string { return ($n < 0 ? '-$' : '$') . number_format(abs($n), 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Revenue report — CADViz</title>
<link href="site.css" rel="stylesheet">
<style>
body { background:#fafafa; margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; font-size:13px; color:#222; }
.topbar { background:#9B9B1B; color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; }
.topbar a, .topbar h1 { color:#fff; text-decoration:none; }
.topbar h1 { margin:0; font-size:18px; font-weight:400; }
.page { max-width:1200px; margin:0 auto; padding:16px; }
.card { background:#fff; border:1px solid #ddd; border-radius:5px; padding:14px 16px; margin:12px 0; }
.filters { display:grid; grid-template-columns:160px 160px 1fr 1fr auto; gap:8px; align-items:end; }
.filters label { font-size:11px; color:#888; display:block; margin-bottom:2px; }
.filters input[type="text"], .filters input[type="date"], .filters select { width:100%; box-sizing:border-box; padding:5px 7px; border:1px solid #ccc; border-radius:3px; font:inherit; }
.summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:8px; margin-top:10px; }
.metric { background:#f6f6e2; border:1px solid #d3d3a5; border-radius:4px; padding:8px 12px; }
.metric .label { font-size:11px; color:#7a7a4a; }
.metric .value { font-size:18px; font-weight:600; color:#444; }
.metric.disb { background:#ffe4d6; border-color:#c33; }
.metric.disb .label { color:#a00; }
.metric.disb .value { color:#a00; }
.metric.net { background:#d6f5d6; border-color:#1a6b1a; }
.metric.net .label { color:#155515; }
.metric.net .value { color:#155515; }
table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; }
th, td { padding:5px 8px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
th { background:#f4f4f4; text-align:left; font-size:11px; }
.right { text-align:right; font-variant-numeric:tabular-nums; }
.muted { color:#888; }
tr.match td { background:#fff8e0; }
.btn { background:#9B9B1B; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer; font:inherit; text-decoration:none; display:inline-block; }
.btn:hover { background:#7a7a16; }
.btn.secondary { background:#555; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📊 Revenue report</h1>
  <div>
    <a href="more.php">← More</a> &nbsp;·&nbsp; <a href="menu.php">Menu</a>
  </div>
</div>

<div class="page">

  <div class="card">
    <form method="get" class="filters">
      <div>
        <label>From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
      </div>
      <div>
        <label>To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
      </div>
      <div>
        <label>Employee</label>
        <select name="employee_id">
          <option value="0">All staff</option>
          <?php foreach ($staff as $s): ?>
            <option value="<?= (int)$s['Employee_ID'] ?>" <?= $employeeId === (int)$s['Employee_ID'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)$s['Login']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Task contains (e.g. <code>council</code>, <code>consent</code>, <code>fee</code>)</label>
        <input type="text" name="task" value="<?= htmlspecialchars($taskQ) ?>" placeholder="leave blank for none">
      </div>
      <div>
        <button class="btn" type="submit">Run</button>
      </div>
      <div style="grid-column:1/-1;font-size:11px;color:#888">
        <label style="display:inline;cursor:pointer">
          <input type="checkbox" name="subtract" value="1" <?= $subtractDisb ? 'checked' : '' ?>>
          Treat task-match rows as <strong>disbursements</strong> and subtract from revenue
          <span class="muted">(use for council fees / pass-throughs that aren't your income for PI insurance purposes)</span>
        </label>
      </div>
    </form>

    <div class="summary">
      <div class="metric">
        <div class="label">Invoiced labour (gross)</div>
        <div class="value"><?= fmt_money($totalAll) ?></div>
        <div class="muted"><?= number_format($hoursAll, 1) ?> hours · <?= count($rows) ?> rows</div>
      </div>
      <?php if ($taskQ !== ''): ?>
        <div class="metric disb">
          <div class="label">Matches “<?= htmlspecialchars($taskQ) ?>”<?= $subtractDisb ? ' (treated as disbursement)' : '' ?></div>
          <div class="value"><?= fmt_money($totalDisb) ?></div>
          <div class="muted"><?= number_format($hoursDisb, 1) ?> hours</div>
        </div>
        <?php if ($subtractDisb): ?>
          <div class="metric net">
            <div class="label">Net revenue (excl. matched)</div>
            <div class="value"><?= fmt_money($netRevenue) ?></div>
            <div class="muted">use this figure for PI insurance</div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div style="margin-top:10px">
      <a class="btn secondary" href="?<?= http_build_query(array_merge($_GET, ['format' => 'csv'])) ?>">⬇ Download CSV</a>
      <span class="muted" style="margin-left:8px;font-size:11px">honours the same filters; opens in Excel/Sheets for accountant handover</span>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px;color:#9B9B1B;border-bottom:1px solid #eee;padding-bottom:4px;font-size:14px">By employee</h3>
    <?php if (empty($byEmp)): ?>
      <p class="muted">No invoiced labour rows in this range.</p>
    <?php else: ?>
      <table>
        <thead><tr>
          <th>Login</th>
          <th class="right">Hours</th>
          <th class="right">Revenue</th>
          <?php if ($taskQ !== ''): ?><th class="right">of which “<?= htmlspecialchars($taskQ) ?>”</th><?php endif; ?>
          <?php if ($subtractDisb && $taskQ !== ''): ?><th class="right">Net</th><?php endif; ?>
          <th class="right">Pay rate</th>
        </tr></thead>
        <tbody>
          <?php foreach ($byEmp as $lg => $row): ?>
            <tr>
              <td><strong><?= htmlspecialchars($lg) ?></strong></td>
              <td class="right"><?= number_format($row['hours'], 1) ?></td>
              <td class="right"><?= fmt_money($row['amount']) ?></td>
              <?php if ($taskQ !== ''): ?>
                <td class="right" style="color:#a00"><?= $row['disb_amount'] > 0 ? fmt_money($row['disb_amount']) : '<span class="muted">—</span>' ?></td>
              <?php endif; ?>
              <?php if ($subtractDisb && $taskQ !== ''): ?>
                <td class="right" style="color:#155515"><?= fmt_money($row['amount'] - $row['disb_amount']) ?></td>
              <?php endif; ?>
              <td class="right muted">$<?= number_format($row['pay'], 2) ?>/h</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin:0 0 8px;color:#9B9B1B;border-bottom:1px solid #eee;padding-bottom:4px;font-size:14px">
      Detail
      <span class="muted" style="font-weight:400;font-size:11px">(<?= count($rows) ?> rows<?= $taskQ !== '' ? ' — task-matches highlighted amber' : '' ?>)</span>
    </h3>
    <?php if (empty($rows)): ?>
      <p class="muted">No rows match.</p>
    <?php else: ?>
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          <th>Date</th>
          <th>Login</th>
          <th>Client</th>
          <th>Invoice</th>
          <th>Task</th>
          <th class="right">Hours</th>
          <th class="right">Rate</th>
          <th class="right">Amount</th>
          <th>Invoice notes</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['_match'] ? 'match' : '' ?>">
              <td><?= htmlspecialchars((string)$r['TS_DATE']) ?></td>
              <td><?= htmlspecialchars((string)$r['Login']) ?></td>
              <td><?= htmlspecialchars((string)($r['Client_Name'] ?? '')) ?></td>
              <td><a href="invoice.php?Invoice_No=<?= (int)$r['Invoice_No'] ?>">CAD-<?= str_pad((string)$r['Invoice_No'], 5, '0', STR_PAD_LEFT) ?></a></td>
              <td><?= htmlspecialchars((string)($r['Task'] ?? '')) ?></td>
              <td class="right"><?= number_format((float)$r['Hours'], 2) ?></td>
              <td class="right">$<?= number_format((float)$r['Rate'], 2) ?></td>
              <td class="right"><strong><?= fmt_money((float)$r['Amnt']) ?></strong></td>
              <td class="muted"><?= htmlspecialchars(mb_strimwidth((string)($r['Notes'] ?? ''), 0, 80, '…')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
