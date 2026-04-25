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
