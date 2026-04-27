<?php
/**
 * Diagnostic page — shows session state, PHP version, and DB connection test.
 * DELETE this file after verifying everything works.
 */
session_start();
require_once 'config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diagnostic</title>';
echo '<style>body{font-family:monospace;padding:20px;} .ok{color:green;} .err{color:red;} .warn{color:orange;} pre{background:#f4f4f4;padding:10px;border-radius:4px;}</style></head><body>';

echo '<h2>CADViz Diagnostic</h2>';

// PHP version
$phpver = phpversion();
$phpOk  = version_compare($phpver, '7.4', '>=');
echo '<h3>PHP</h3>';
echo '<p>Version: <b>' . $phpver . '</b> ' . ($phpOk ? '<span class="ok">✓ OK</span>' : '<span class="err">✗ Need ≥ 7.4</span>') . '</p>';
echo '<p>str_starts_with available: ' . (function_exists('str_starts_with') ? '<span class="ok">yes (PHP 8+)</span>' : '<span class="warn">no (PHP 7.x) — login.php uses substr() fallback ✓</span>') . '</p>';
echo '<p>password_hash available: ' . (function_exists('password_hash') ? '<span class="ok">yes</span>' : '<span class="err">no</span>') . '</p>';
echo '<p>crypt() available: ' . (function_exists('crypt') ? '<span class="ok">yes</span>' : '<span class="warn">no — DES fallback disabled</span>') . '</p>';

// Session state
echo '<h3>Session</h3><pre>';
echo 'session_id: ' . session_id() . "\n";
echo 'UserID: '       . (isset($_SESSION['UserID'])       ? htmlspecialchars($_SESSION['UserID'])       : '(not set)') . "\n";
echo 'Employee_id: '  . (isset($_SESSION['Employee_id'])  ? htmlspecialchars($_SESSION['Employee_id'])  : '(not set)') . "\n";
echo 'DisplayName: '  . (isset($_SESSION['DisplayName'])  ? htmlspecialchars($_SESSION['DisplayName'])  : '(not set)') . "\n";
echo 'AccessLevel: '  . (isset($_SESSION['AccessLevel'])  ? htmlspecialchars($_SESSION['AccessLevel'])  : '(not set)') . "\n";
echo 'Week: '         . (isset($_SESSION['Week'])         ? htmlspecialchars($_SESSION['Week'])         : '(not set)') . "\n";
echo '</pre>';

// DB connection test
echo '<h3>Database</h3>';
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo '<p class="ok">✓ Connected to ' . DB_NAME . ' on ' . DB_HOST . '</p>';

    // List tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo '<p>Tables found: <b>' . count($tables) . '</b></p><pre>' . implode("\n", $tables) . '</pre>';

    // Test key tables
    $keyTables = ['Clients', 'Projects', 'Invoices', 'Staff', 'Timesheets'];
    echo '<h4>Key table checks</h4><ul>';
    foreach ($keyTables as $t) {
        try {
            $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            echo '<li class="ok">✓ ' . $t . ' — ' . $cnt . ' rows</li>';
        } catch (Exception $ex) {
            echo '<li class="err">✗ ' . $t . ' — ' . htmlspecialchars($ex->getMessage()) . '</li>';
        }
    }
    echo '</ul>';

    // Show Staff table columns
    echo '<h4>Staff columns</h4><pre>';
    $cols = $pdo->query("DESCRIBE Staff")->fetchAll();
    $pwdType = '';
    foreach ($cols as $c) {
        echo htmlspecialchars($c['Field']) . "\t" . htmlspecialchars($c['Type']) . "\n";
        if (strtolower($c['Field']) === 'password') $pwdType = strtolower($c['Type']);
    }
    echo '</pre>';

    // Bcrypt-readiness check on Staff.password column
    if (preg_match('/varchar\((\d+)\)/', $pwdType, $m)) {
        $len = (int)$m[1];
        if ($len < 60) {
            echo '<p class="err">✗ Staff.password is <code>' . htmlspecialchars($pwdType) . '</code> — too narrow for bcrypt (needs ≥60 chars). Bcrypt hashes will be silently truncated and login will fail. Open <a href="set_password.php?fix_column=1">set_password.php?fix_column=1</a> to widen it.</p>';
        } else {
            echo '<p class="ok">✓ Staff.password column (' . htmlspecialchars($pwdType) . ') is wide enough for bcrypt.</p>';
        }
    }

    // Show actual stored password lengths so truncation is visible
    echo '<h4>Stored password lengths</h4><pre>';
    $rows = $pdo->query("SELECT Login, CHAR_LENGTH(`password`) AS len, LEFT(`password`,4) AS prefix FROM Staff ORDER BY Login")->fetchAll();
    foreach ($rows as $r) {
        echo str_pad((string)$r['Login'], 16) . "\tlen=" . str_pad((string)$r['len'], 4)
           . "\tprefix=" . htmlspecialchars((string)$r['prefix']) . "\n";
    }
    echo '</pre>';
    echo '<p style="font-size:12px;color:#666">Bcrypt hashes start with <code>$2y$</code> and are exactly 60 chars. If a row says <code>len=32</code> with <code>$2y$</code> prefix, that hash was truncated by the column.</p>';

} catch (Exception $e) {
    echo '<p class="err">✗ Connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '</body></html>';
