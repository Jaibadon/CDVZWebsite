<?php
/**
 * POST /api/transmittal_send.php
 *
 * Body (JSON): { commit_id:int, recipient_ids:int[], subject?:string,
 *                message?:string, test_to?:string }
 *
 * Thin wrapper around send_transmittal() (transmittal_send.php library
 * mode). The SPA's commit-result panel posts here after the user picks
 * which suggested stakeholders to notify.
 */

require_once __DIR__ . '/_bootstrap.php';

$uid = require_session();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('POST only.', 405);

$body = read_json_body();
$commitId   = (int)($body['commit_id'] ?? 0);
$recipients = is_array($body['recipient_ids'] ?? null) ? $body['recipient_ids'] : [];
$subject    = trim((string)($body['subject'] ?? ''));
$message    = trim((string)($body['message'] ?? ''));
$testTo     = trim((string)($body['test_to'] ?? ''));

if ($commitId <= 0)       json_err('commit_id is required.', 400);
if (empty($recipients))   json_err('Pick at least one recipient.', 400);

if (!defined('TRANSMITTAL_SEND_LIBRARY_ONLY')) define('TRANSMITTAL_SEND_LIBRARY_ONLY', true);
require_once __DIR__ . '/../transmittal_send.php';

try {
    $result = send_transmittal($pdo, $commitId, $recipients, $subject, $message, $uid, $testTo);
    json_ok($result, 201);
} catch (Exception $e) {
    json_err($e->getMessage(), 400);
}
