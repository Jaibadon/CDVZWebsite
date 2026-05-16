<?php
/**
 * POST /api/manual_email_send.php
 *
 * Body (JSON): {
 *   commit_id:int, stakeholder_ids:int[], extra_emails:string[],
 *   subject:string, body:string, attach_pdfs:bool, test_to?:string
 * }
 *
 * Thin wrapper around send_manual_email() — the informal (no magic-link,
 * no ack) email path. Logged to the commit's comment thread for audit.
 */

require_once __DIR__ . '/_bootstrap.php';

$uid = require_session();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST only.', 405);

$b = read_json_body();
$commitId    = (int)($b['commit_id'] ?? 0);
$stakeIds    = is_array($b['stakeholder_ids'] ?? null) ? $b['stakeholder_ids'] : [];
$extra       = is_array($b['extra_emails'] ?? null)    ? $b['extra_emails']    : [];
$subject     = (string)($b['subject'] ?? '');
$body        = (string)($b['body'] ?? '');
$attachPdfs  = !empty($b['attach_pdfs']);
$includeLink = !empty($b['include_magic_link']);
$testTo      = trim((string)($b['test_to'] ?? ''));

if ($commitId <= 0) json_err('commit_id is required.', 400);

if (!defined('MANUAL_EMAIL_SEND_LIBRARY_ONLY')) define('MANUAL_EMAIL_SEND_LIBRARY_ONLY', true);
require_once __DIR__ . '/../manual_email_send.php';

try {
    $result = send_manual_email($pdo, $commitId, $stakeIds, $extra, $subject, $body, $attachPdfs, $includeLink, $uid, $testTo);
    json_ok($result, 201);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}
