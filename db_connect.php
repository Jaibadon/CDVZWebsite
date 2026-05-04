<?php
// ─── Output buffering FIRST ────────────────────────────────────────────────
// Before anything else can possibly write a byte. HTTP/2 raises
// ERR_HTTP2_PROTOCOL_ERROR when the server emits a partial response and
// then the connection terminates mid-frame (PHP fatal, slow query
// timeout, FPM worker crash, output flush after headers were already
// sent, etc). Buffering the WHOLE response means we either send a
// complete page or — via the shutdown handler below — a clean 500
// error page. The browser never sees a malformed frame.
//
// chunk_size = 0 disables auto-flush on buffer-full so a 4KB chunk
// boundary can't sneak past us.
ob_start(null, 0);
ob_implicit_flush(false);

require_once __DIR__ . '/config.php';

// ─── Error handling ────────────────────────────────────────────────────────
// In production we NEVER bleed error messages into the response body —
// that's the #1 cause of ERR_HTTP2_PROTOCOL_ERROR (warning text injected
// mid-HTML invalidates the HTTP/2 frame). Errors go to the error log
// only; admins read them via cPanel → Errors. To turn on display in
// development, set CADVIZ_DEBUG=1 in the PHP env or .htaccess.
$_cadviz_debug = !empty(getenv('CADVIZ_DEBUG')) || (defined('CADVIZ_DEBUG') && CADVIZ_DEBUG);
ini_set('display_errors',         $_cadviz_debug ? '1' : '0');
ini_set('display_startup_errors', $_cadviz_debug ? '1' : '0');
ini_set('log_errors',             '1');
error_reporting(E_ALL);  // log everything; display is gated separately

// ─── Fatal-error catcher ───────────────────────────────────────────────────
// If a fatal error (E_ERROR / E_PARSE / OOM / max-execution-time) occurs
// after we've started producing output, the partial buffer would emit a
// half-rendered page and HTTP/2 would mark the response malformed.
// Instead we discard whatever's in the buffer and write a clean 500
// page so the browser gets a complete, well-formed response.
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($err['type'], $fatal, true)) return;

    // Throw away the partial response so the browser gets a fresh,
    // self-consistent body.
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // Surface the actual error to the error log so admins can debug.
    error_log(sprintf(
        '[CADViz fatal] %s in %s:%d',
        $err['message'] ?? 'unknown', $err['file'] ?? '?', $err['line'] ?? 0
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $debugDetail = (!empty(getenv('CADVIZ_DEBUG')) || (defined('CADVIZ_DEBUG') && CADVIZ_DEBUG))
        ? '<pre style="white-space:pre-wrap;font-size:11px;color:#666">' . htmlspecialchars($err['message'] . "\n" . ($err['file'] ?? '') . ':' . ($err['line'] ?? '')) . '</pre>'
        : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Server error</title>'
       . '<style>body{font-family:Arial,sans-serif;background:#f4f4f4;padding:30px;color:#333}'
       . '.box{max-width:560px;margin:60px auto;background:#fff;padding:24px 28px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.08);border-left:4px solid #c33}'
       . 'h1{color:#a00;margin:0 0 8px;font-size:20px}'
       . 'p{font-size:13px;line-height:1.5}</style></head><body>'
       . '<div class="box">'
       . '<h1>Server error</h1>'
       . '<p>Something went wrong while building this page. The error has been logged.</p>'
       . '<p>Please <a href="javascript:history.back()">go back</a> and try again. '
       . 'If the same page keeps failing, send the URL to Erik so he can check the server log.</p>'
       . $debugDetail
       . '</div></body></html>';
});

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
