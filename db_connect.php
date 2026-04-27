<?php
require_once __DIR__ . '/config.php';

// Show fatal errors but hide PHP 8.1+ deprecations/notices/warnings that clutter pages
// (real DB exceptions are caught and shown by per-page try/catch blocks)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

// ─── Defensive output buffering ────────────────────────────────────────────
// HTTP/2 (Chrome especially) raises ERR_HTTP2_PROTOCOL_ERROR if the server
// streams partial HTML and then the response is interrupted by a slow query
// or a fatal late in the script. Buffering the whole response means PHP only
// emits bytes once the page is fully built, eliminating that failure mode.
// Auto-flushes on script shutdown.
if (!ob_get_level()) {
    ob_start();
}

// Slow DB writes (e.g. submit.php saving a full week of timesheet rows) need
// more than the default 30s on shared hosting. 120s is a safe ceiling.
@set_time_limit(120);

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
