<?php
/**
 * Shared bootstrap for /api/ endpoints.
 *
 * - Starts the session (so $_SESSION['UserID'] is available).
 * - Sends JSON responses by default.
 * - Provides json_ok() / json_err() helpers.
 * - Provides require_session() — returns the UserID, or 401's the response.
 * - Provides require_admin() for endpoints that erik/jen only should hit.
 *
 * Doubles as an actual endpoint at ?action=session — used by the SPA's
 * auth.ts to confirm the session is alive before mounting routes.
 *
 * Endpoints under /api/ are called from the SPA at /dms/ which is the
 * same origin, so the session cookie flows automatically. No CORS needed.
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../helpers.php';

// Tell the browser this is JSON; never leak HTML even on a fatal error.
if (!headers_sent()) header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

function json_ok($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $status = 400, $extra = null): void
{
    http_response_code($status);
    $body = ['error' => $message];
    if ($extra !== null) $body['detail'] = $extra;
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return string the logged-in CADViz UserID. 401's the response (and exits)
 *                if there's no session.
 */
function require_session(): string
{
    $uid = $_SESSION['UserID'] ?? '';
    if ($uid === '') json_err('Not signed in.', 401);
    return $uid;
}

function require_admin(): string
{
    $uid = require_session();
    if (!in_array($uid, ['erik','jen'], true)) {
        json_err('Admin only.', 403);
    }
    return $uid;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) json_err('Request body must be a JSON object.', 400);
    return $j;
}

// ── Direct endpoint: GET /api/_bootstrap.php?action=session ──────────────
// Returns the current session info or 401. Called by the SPA on mount.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $action = $_GET['action'] ?? '';
    if ($action === 'session') {
        $uid = $_SESSION['UserID'] ?? '';
        if ($uid === '') json_err('Not signed in.', 401);
        json_ok([
            'user_id'     => $uid,
            'employee_id' => isset($_SESSION['Employee_id']) ? (int)$_SESSION['Employee_id'] : null,
            'is_admin'    => in_array($uid, ['erik','jen'], true),
        ]);
    }
    json_err('Unknown action.', 400);
}
