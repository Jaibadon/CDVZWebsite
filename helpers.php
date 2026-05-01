<?php
/**
 * Shared helper functions for the CADViz timesheet/invoicing system.
 * Include with: require_once 'helpers.php';
 */

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
    $today = strtotime(date('Y-m-d'));
    $start = strtotime("-{$weeksBack} weeks", $today);

    $stmt = $pdo->prepare(
        "SELECT DISTINCT TS_DATE FROM Timesheets
          WHERE Employee_id = ? AND TS_DATE BETWEEN ? AND ? AND Hours > 0"
    );
    $stmt->execute([$empId, date('Y-m-d', $start), date('Y-m-d', $today)]);
    $logged = [];
    foreach ($stmt->fetchAll() as $r) $logged[$r['TS_DATE']] = true;

    $missing = [];
    for ($t = $start; $t <= $today; $t = strtotime('+1 day', $t)) {
        $dow = (int)date('N', $t); // 1=Mon … 7=Sun
        if ($dow >= 6) continue;   // skip Sat/Sun
        $d = date('Y-m-d', $t);
        if (!isset($logged[$d])) $missing[] = $d;
    }
    return $missing;
}
