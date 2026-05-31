<?php
/**
 * bootstrap.php — one include for the common authenticated-page runtime.
 *
 * New top-level pages can do:
 *     require_once __DIR__ . '/bootstrap.php';
 * instead of separately pulling auth_check + db_connect + helpers. Pages
 * under a subfolder (e.g. dms/) use:
 *     require_once __DIR__ . '/../bootstrap.php';
 *
 * Existing pages are NOT required to switch — they keep their explicit
 * includes and continue to work. This is purely a convenience for new code.
 *
 * DO NOT use this for:
 *   • Public token-authed pages (e.g. dms/transmittal_view.php) — the magic
 *     token IS the auth; auth_check.php would wrongly redirect to login.
 *   • JSON API endpoints under api/ — they use api/_bootstrap.php, which
 *     returns JSON errors rather than redirecting.
 *
 * Order mirrors the long-standing convention across the app: auth gate first
 * (starts the session + redirects if not logged in), then DB, then helpers.
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
