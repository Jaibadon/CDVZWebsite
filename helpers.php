<?php
/**
 * Shared helper functions for the CADViz timesheet/invoicing system.
 * Include with: require_once 'helpers.php';
 */

// ─── App_Meta key/value store ─────────────────────────────────────────
function meta_get(PDO $pdo, string $key, ?string $default = null): ?string {
    try {
        $st = $pdo->prepare("SELECT meta_value FROM App_Meta WHERE meta_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Exception $e) { return $default; }
}
function meta_set(PDO $pdo, string $key, string $value): void {
    try {
        $pdo->prepare("INSERT INTO App_Meta (meta_key, meta_value, updated_at) VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()")
            ->execute([$key, $value]);
    } catch (Exception $e) { /* table may not exist yet */ }
}

/**
 * Auto-deactivate jobs and clients that have had no activity in the last
 * 5 years. Activity = a Timesheets row (for projects) or a Project / Invoice
 * touched in the window (for clients). Logs every deactivation in
 * Dormancy_Log so an admin can review/undo from dormancy_sweep.php.
 *
 * Idempotent — re-runs in the same month are skipped via App_Meta.
 *
 * Returns ['projects' => N, 'clients' => N, 'ran' => bool].
 */
function dormancy_sweep_if_due(PDO $pdo): array {
    $thisMonth = date('Y-m');
    if (meta_get($pdo, 'dormancy_last_run') === $thisMonth) {
        return ['projects' => 0, 'clients' => 0, 'ran' => false];
    }
    return dormancy_sweep_run($pdo);
}

function dormancy_sweep_run(PDO $pdo): array {
    $cutoff = date('Y-m-d', strtotime('-5 years'));
    $log = $pdo->prepare("INSERT INTO Dormancy_Log (swept_at, entity_type, entity_id, entity_label, last_activity)
                          VALUES (NOW(), ?, ?, ?, ?)");

    // ── Projects: Active=1 with no timesheet entry in 5y AND created/touched 5y+ ago
    $projCount = 0;
    try {
        $proj = $pdo->prepare(
            "SELECT p.proj_id, p.JobName,
                    (SELECT MAX(TS_DATE) FROM Timesheets WHERE proj_id = p.proj_id) AS last_ts
               FROM Projects p
              WHERE COALESCE(p.Active, 0) <> 0
                AND NOT EXISTS (SELECT 1 FROM Timesheets t WHERE t.proj_id = p.proj_id AND t.TS_DATE >= ?)"
        );
        $proj->execute([$cutoff]);
        $upd = $pdo->prepare("UPDATE Projects SET Active = 0 WHERE proj_id = ?");
        foreach ($proj->fetchAll() as $r) {
            $upd->execute([(int)$r['proj_id']]);
            $log->execute(['project', (int)$r['proj_id'], $r['JobName'], $r['last_ts']]);
            $projCount++;
        }
    } catch (Exception $e) { /* schema mismatch — skip */ }

    // ── Clients: no recent project AND no recent invoice
    $cliCount = 0;
    try {
        // Detect Clients.Active column (some installs may not have it)
        $hasActive = (bool)$pdo->query("SHOW COLUMNS FROM Clients LIKE 'Active'")->fetch();
        if ($hasActive) {
            $cli = $pdo->prepare(
                "SELECT c.Client_id, c.Client_Name,
                        GREATEST(
                          COALESCE((SELECT MAX(t.TS_DATE) FROM Timesheets t JOIN Projects p ON t.proj_id = p.proj_id WHERE p.Client_ID = c.Client_id), '1970-01-01'),
                          COALESCE((SELECT MAX(i.Date)    FROM Invoices  i WHERE i.Client_ID = c.Client_id), '1970-01-01')
                        ) AS last_activity
                   FROM Clients c
                  WHERE COALESCE(c.Active, 0) <> 0
                 HAVING last_activity < ?"
            );
            $cli->execute([$cutoff]);
            $upd = $pdo->prepare("UPDATE Clients SET Active = 0 WHERE Client_id = ?");
            foreach ($cli->fetchAll() as $r) {
                $upd->execute([(int)$r['Client_id']]);
                $log->execute(['client', (int)$r['Client_id'], $r['Client_Name'], $r['last_activity']]);
                $cliCount++;
            }
        }
    } catch (Exception $e) { /* skip */ }

    meta_set($pdo, 'dormancy_last_run', date('Y-m'));
    meta_set($pdo, 'dormancy_last_run_at', date('Y-m-d H:i:s'));
    return ['projects' => $projCount, 'clients' => $cliCount, 'ran' => true];
}

/**
 * Convert any common date string into MySQL ISO format (Y-m-d).
 * Accepts: d/m/Y, d-m-Y, d.m.Y, Y-m-d, "Monday, 26 April 2026", etc.
 * Returns null if the input can't be parsed.
 */
function to_mysql_date($input): ?string {
    if ($input === null || $input === '' || $input === false) {
        return null;
    }
    $s = trim((string)$input);

    // Already ISO
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}/', $s)) {
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    // d/m/Y, d-m-Y, d.m.Y (UK / NZ format)
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $s, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) { $y += ($y < 70 ? 2000 : 1900); }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return null;
    }

    // Fallback: let strtotime try (handles "Monday, 26 April 2026")
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Compute the invoice's payment-due date (Y-m-d) from its invoice date and
 * PaymentOption code. Returns null if either input is missing/invalid.
 *
 * PaymentOption 1 = 20th of the *next* calendar month, ALWAYS (regardless
 *   of the invoice's day-of-month — `strtotime('+1 month', Jan 31)` lands
 *   on Mar 3, which used to throw the due date a month ahead).
 * PaymentOption 2 = invoice date + 7 days.
 * PaymentOption 3 = invoice date itself (due immediately).
 */
function compute_pay_by($invoiceDate, $paymentOption): ?string {
    if (empty($invoiceDate)) return null;
    $opt = (int)$paymentOption;
    $ts  = strtotime((string)$invoiceDate);
    if (!$ts) return null;

    if ($opt === 1) {
        $first = new DateTime(date('Y-m-1', $ts));
        $first->modify('+1 month');
        return $first->format('Y-m-') . '20';
    }
    if ($opt === 2) return date('Y-m-d', strtotime('+7 days', $ts));
    if ($opt === 3) return date('Y-m-d', $ts);
    return null;
}

/**
 * Cached "does Clients.Contact exist?" check. The Contact column is added
 * by migrations/add_clients_contact.sql. SELECTs that reference it must
 * gate on this so installs that haven't run the migration still work
 * (the queries silently substitute NULL AS Contact).
 */
function clients_has_contact(PDO $pdo): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $cached = (bool)$pdo->query("SHOW COLUMNS FROM Clients LIKE 'Contact'")->fetch();
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Pull a salutation first name out of the Clients.Contact column.
 * Splits on the first whitespace, comma, slash, or ampersand so values like
 * "John Smith", "John, Sales Manager", or "John & Jane Smith" all yield
 * "John". Returns the fallback ("Valued Customer") when Contact is empty —
 * reads naturally on an invoice ("Dear Valued Customer,") whereas the
 * casual "there" fallback would be jarring on a billing document.
 */
function client_first_name($contact, string $fallback = 'Valued Customer'): string {
    $c = trim((string)($contact ?? ''));
    if ($c === '') return $fallback;
    // Split on the first delimiter — whitespace, comma, slash, ampersand.
    $parts = preg_split('/[\s,\/&]+/u', $c, 2);
    $first = trim($parts[0] ?? '');
    return $first !== '' ? $first : $fallback;
}

/**
 * Pick the best email to send invoices/statements to: billing_email first,
 * email column second. Validates with PHP's RFC-ish email filter so blanks
 * and obvious typos ("foo @bar") never get used. Returns null when neither
 * column holds a valid address — callers should surface that to the admin
 * (e.g. via $_SESSION['xero_flash_err']) rather than attempting to send.
 */
function pick_billing_email($billingEmail, $email): ?string {
    foreach ([$billingEmail, $email] as $candidate) {
        $e = trim((string)($candidate ?? ''));
        if ($e === '') continue;
        // Some legacy rows store "No Email - <user>" as a placeholder; treat
        // anything that doesn't pass FILTER_VALIDATE_EMAIL as missing.
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
    }
    return null;
}

/**
 * Format a MySQL date string for display in d/m/Y, safely handling nulls.
 */
function display_date($input, string $fmt = 'd/m/Y'): string {
    if (empty($input)) return '';
    $ts = strtotime((string)$input);
    return $ts ? date($fmt, $ts) : '';
}

/**
 * Employed (full-time) staff who must fill out every weekday before they can
 * submit. Either a real timesheet entry or a leave/sick entry counts.
 * Match is case-insensitive against $_SESSION['UserID'].
 */
const EMPLOYED_STAFF = ['dmitriyp', 'hannah'];

function is_employed_staff(?string $userId): bool {
    if ($userId === null || $userId === '') return false;
    return in_array(strtolower($userId), EMPLOYED_STAFF, true);
}

/**
 * Return the list of missing weekdays (Mon–Fri only, ISO Y-m-d) for the given
 * employee in the past N weeks up to and including today. A weekday counts as
 * filled if there's any Timesheets row for that employee on that date with
 * Hours > 0 (so leave/sick on the LEAVE SICK project counts too).
 */
function missing_weekdays(PDO $pdo, int $empId, int $weeksBack = 4): array {
    if ($empId <= 0) return [];
    $today = new DateTime(date('Y-m-d'));
    $start = (clone $today)->modify("-{$weeksBack} weeks");

    // Normalise both sides to Y-m-d. Timesheets.TS_DATE may be DATE or
    // DATETIME — we always cast to DATE in SQL to make the keys comparable.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT DATE_FORMAT(TS_DATE, '%Y-%m-%d') AS d
           FROM Timesheets
          WHERE Employee_id = ?
            AND TS_DATE BETWEEN ? AND ?
            AND Hours > 0"
    );
    $stmt->execute([$empId, $start->format('Y-m-d'), $today->format('Y-m-d')]);
    $logged = [];
    foreach ($stmt->fetchAll() as $r) $logged[$r['d']] = true;

    $missing = [];
    $cur = clone $start;
    $oneDay = new DateInterval('P1D');
    while ($cur <= $today) {
        $dow = (int)$cur->format('N'); // 1=Mon … 7=Sun
        if ($dow < 6) {
            $d = $cur->format('Y-m-d');
            if (!isset($logged[$d])) $missing[] = $d;
        }
        $cur->add($oneDay);
    }
    return $missing;
}
