<?php
/**
 * Send overdue-invoice reminders FROM accounts@cadviz.co.nz instead of from
 * Xero's mail server. (Xero's auto-reminders go from a Xero address that
 * many clients spam-filter — sending from your own domain converts better.)
 *
 * Recommended Xero setup:
 *   In Xero → Business → Invoices → Invoice reminders → DISABLE the
 *   automatic reminder schedule. Let this script handle reminders instead.
 *
 * Trigger options:
 *   1. Cron job (recommended). Add to cPanel Cron Jobs:
 *        0  9  *  *  *  /usr/bin/php /home/<user>/public_html/xero_send_reminders.php cron
 *      Runs at 09:00 daily; only sends reminders for invoices that are
 *      overdue at the configured intervals (7, 14, 30 days past due).
 *   2. Manual trigger: visit xero_send_reminders.php?dry_run=1 to preview
 *      who would receive a reminder, then drop the dry_run param to send.
 *   3. Per-invoice "Send reminder now" button on monthly_invoicing.php (also
 *      hits this script with ?invoice_no=N).
 *
 * Spam guards:
 *   - Skips an invoice if it was reminded within the last 6 days (configurable
 *     via App_Meta key 'reminder_min_gap_days').
 *   - Caps total sends per run at 30 (env CADVIZ_REMINDER_CAP if set).
 *
 * Run modes (CLI):  php xero_send_reminders.php cron
 *                   php xero_send_reminders.php cron dry-run
 * Web modes:        ?dry_run=1            (admin only)
 *                   ?invoice_no=NNNN      (force-send a single invoice; admin only)
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/xero_client.php';
// Pull xero_sync.php as a library (defines run_xero_sync) without
// triggering its own auth/CLI handling.
define('XERO_SYNC_LIBRARY_ONLY', true);
require_once __DIR__ . '/xero_sync.php';

// CLI vs web: CLI doesn't go through auth_check.php
$isCli = (php_sapi_name() === 'cli');
$isCron = $isCli && (in_array('cron', $argv ?? [], true));
if (!$isCli) {
    require_once __DIR__ . '/auth_check.php';
    if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
        http_response_code(403); die('Admin only.');
    }
}

$pdo     = get_db();
$dryRun  = $isCli ? in_array('dry-run', $argv, true) : !empty($_GET['dry_run']);
$singleInvoice = (int)($_GET['invoice_no'] ?? 0);

// ── Test mode ─────────────────────────────────────────────────────────────
// When enabled, every reminder / statement gets diverted to a single test
// inbox instead of the actual client address. Subject lines are prefixed
// "[TEST]" so the recipient can tell at a glance. Spam guards / Sent flag
// updates / xero SentToContact are all SKIPPED so a real client never
// has their state mutated by a test run.
//
// Activate one of:
//   web :  ?test=1                                   (web admin run)
//          ?test=1&test_to=erik@cadviz.co.nz         (override recipient)
//          ?test=1&force_days=45                     (preview a specific tone)
//          ?test=1&force_days=45&force_tone=final    (override the tone)
//   CLI :  php xero_send_reminders.php cron test
//          CADVIZ_REMINDER_TEST_MODE=1 php …
//          CADVIZ_REMINDER_TEST_TO=foo@bar.com php …
$testMode = false;
if ($isCli) {
    $testMode = in_array('test', $argv ?? [], true) || !empty($_ENV['CADVIZ_REMINDER_TEST_MODE']) || getenv('CADVIZ_REMINDER_TEST_MODE');
} else {
    $testMode = !empty($_GET['test']);
}
// Pick the test recipient: explicit override → env → default to current user's email when web,
// else fallback to the CADViz accounts inbox.
$testTo = $_GET['test_to'] ?? $_ENV['CADVIZ_REMINDER_TEST_TO'] ?? getenv('CADVIZ_REMINDER_TEST_TO') ?? '';
if ($testTo === '' || $testTo === false) $testTo = 'accounts@cadviz.co.nz';
$forceDays = isset($_GET['force_days']) ? (int)$_GET['force_days'] : null;
$forceTone = $_GET['force_tone'] ?? null; // gentle | reminder | firm | very_firm | final

if ($testMode) {
    // Loosen guards in test mode so the same invoice can be re-sent at will.
    $minGapDays = 0;
}

// Always sync from Xero before deciding who to remind, so that invoices
// paid since the last sync don't get a reminder. Errors are non-fatal —
// fall through to the existing local view if Xero is unreachable.
$syncResult = run_xero_sync($pdo);

// ── Reminder schedule: days-overdue → tone ───────────────────────────────
//
// Tiered escalation. Stages = days past the due date that trigger a
// reminder. Tones = wording used.
//
//    stage    days     tone           wording
//    -----    -----    -------------  ----------------------------
//    1        7        gentle         friendly reminder
//    2        14       reminder       please pay soon
//    3        30       firm           prompt payment requested
//    4        45       very_firm      explicit "this is now seriously overdue"
//    5        60       final          "final notice — next step is collections"
//                                     ALSO the hard-stop. Cron stops sending
//                                     after this so we don't drip the same
//                                     email forever; Erik handles 60+ day
//                                     debts manually (call, payment plan,
//                                     collections, write-off).
//
// **Opt-in by default.** Reminders DO NOT fire automatically. Erik must
// explicitly press "Start reminders" on monthly_invoicing.php for an
// invoice (writes App_Meta['reminder_started_<n>']) before the cron will
// pick it up. The manual per-row "Send reminder" button (?invoice_no=N)
// bypasses the opt-in so Erik can fire one-off reminders without flipping
// the long-term flag.
$reminderStages   = [7, 14, 30, 45, 60];
$reminderHardStop = 60;                    // matches the final-notice stage — no auto-sends past this
$minGapDays       = (int)(meta_get($pdo, 'reminder_min_gap_days') ?: 6);
$cap              = (int)($_ENV['CADVIZ_REMINDER_CAP'] ?? 30);

// ── Pick candidates ────────────────────────────────────────────────────────
// Require Xero_InvoiceID (i.e. the invoice has been pushed) and at least
// one of billing_email / email being non-empty. Address validity is checked
// per-row in the loop via pick_billing_email() so we can skip + report
// invalid addresses cleanly instead of silently dropping them.
//
// In test mode + a specific invoice_no, we drop the "must be overdue"
// requirement so admins can preview a tone against any pushed invoice
// regardless of due date. The override $daysOverride below feeds the
// pretend day-count to the renderer.
$where = "i.Xero_InvoiceID IS NOT NULL
          AND (COALESCE(c.billing_email, '') <> '' OR COALESCE(c.email, '') <> '')";
if (!$testMode || $singleInvoice <= 0) {
    $where .= " AND i.Xero_Status = 'AUTHORISED'
                AND i.Xero_AmountDue > 0
                AND i.Xero_DueDate IS NOT NULL
                AND i.Xero_DueDate < CURDATE()";
}
if ($singleInvoice > 0) $where .= " AND i.Invoice_No = " . (int)$singleInvoice;

// Gate c.Contact behind feature detection so installs that haven't run
// migrations/add_clients_contact.sql don't crash here.
$contactCol = clients_has_contact($pdo) ? 'c.Contact' : 'NULL AS Contact';
$rows = $pdo->query(
    "SELECT i.Invoice_No, i.Client_ID, i.Subtotal, i.Tax_Rate, i.Xero_DueDate, i.Xero_AmountDue,
            i.Xero_OnlineUrl, i.Xero_InvoiceID,
            DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
            c.Client_Name, {$contactCol}, c.billing_email, c.email,
            (SELECT MAX(updated_at) FROM App_Meta WHERE meta_key = CONCAT('reminder_last_',    i.Invoice_No)) AS last_reminder_at,
            (SELECT meta_value     FROM App_Meta WHERE meta_key = CONCAT('reminder_started_', i.Invoice_No)) AS reminder_started
       FROM Invoices i
       LEFT JOIN Clients c ON i.Client_ID = c.Client_id
      WHERE $where
      ORDER BY i.Xero_DueDate ASC"
)->fetchAll();

// Group every overdue row by client so we can decide per-client whether
// to send a single statement (≥ 2 overdue) or an individual reminder
// (1 overdue). Counts use the FULL overdue set for the client, not just
// rows that hit a stage today, because the user wants statements
// whenever the client has multiple overdue regardless of stage timing.
$byClient = [];
foreach ($rows as $r) {
    $cid = (int)($r['Client_ID'] ?? 0);
    if ($cid <= 0) continue;
    if (!isset($byClient[$cid])) $byClient[$cid] = ['overdue' => [], 'invoices' => []];
    $byClient[$cid]['overdue'][]  = $r;
    $byClient[$cid]['invoices'][] = (int)$r['Invoice_No'];
}

// Per-client statement spam guard — cap one overdue-statement email per
// client every $minGapDays so cron re-runs the same day don't double up.
$clientLastStatement = [];
foreach ($byClient as $cid => $_g) {
    $clientLastStatement[$cid] = meta_get($pdo, 'reminder_statement_last_' . $cid);
}

$sent = []; $skipped = [];

/**
 * Should we even consider this row today? Returns one of:
 *   'send'     — yes, eligible
 *   'skip:...' — reason to skip (passed back as $skipped[$invNo])
 */
$shouldRemind = function(array $r) use ($singleInvoice, $reminderStages, $reminderHardStop, $minGapDays, $testMode) {
    $days = (int)$r['days_overdue'];

    // Opt-in default — only send for invoices Erik flipped on. Test mode
    // and per-row manual sends bypass.
    if (!$testMode && empty($r['reminder_started']) && $singleInvoice <= 0) {
        return 'skip:reminders not started for this invoice (use Start Reminders on monthly_invoicing)';
    }
    // Hard stop — final notice goes out at 60d; cron does not auto-send past that.
    if (!$testMode && $days > $reminderHardStop && $singleInvoice <= 0) {
        return "skip:past hard-stop ({$days}d overdue, cap {$reminderHardStop}d) — handle manually";
    }
    // Stage match — exact day = 7, 14, 30, 45, 60. Test mode skips the
    // schedule check (since we may have force-overridden $days for tone preview).
    $isStage = in_array($days, $reminderStages, true);
    if (!$testMode && !$isStage && $singleInvoice <= 0) {
        return "skip:not on reminder schedule (overdue $days d)";
    }
    // Spam guard (skipped in test mode so tones can be re-fired back-to-back)
    if (!$testMode && $r['last_reminder_at']) {
        $hoursSince = (time() - strtotime($r['last_reminder_at'])) / 3600;
        if ($hoursSince < ($minGapDays * 24) && $singleInvoice <= 0) {
            return 'skip:reminded ' . round($hoursSince) . 'h ago';
        }
    }
    return 'send';
};

// In test mode, optionally pin every row's days_overdue to a value so the
// admin can preview a specific tone without waiting for time to pass.
// force_tone wins; else force_days; else the row's real days_overdue.
$forceDaysFromTone = [
    'gentle'    => 7,
    'reminder'  => 14,
    'firm'      => 30,
    'very_firm' => 45,
    'final'     => 60,
][$forceTone ?? ''] ?? null;
$daysOverride = $forceDaysFromTone ?? $forceDays;

$subjectTestPrefix = $testMode ? '[TEST] ' : '';

// ── Main loop: per client ────────────────────────────────────────────────
foreach ($byClient as $cid => $g) {
    if (count($sent) >= $cap) break;
    /** @var array $overdueRows */
    $overdueRows = $g['overdue'];
    if ($daysOverride !== null) {
        // Apply override BEFORE the per-row $shouldRemind closure runs,
        // so the tone-preview path doesn't get killed by the schedule check.
        foreach ($overdueRows as &$ovr) $ovr['days_overdue'] = (int)$daysOverride;
        unset($ovr);
    }
    $multipleOverdue = count($overdueRows) >= 2;

    // If single-invoice override (?invoice_no=N) and this client doesn't
    // contain that invoice, skip the whole client.
    if ($singleInvoice > 0) {
        $hit = false;
        foreach ($overdueRows as $r) if ((int)$r['Invoice_No'] === $singleInvoice) { $hit = true; break; }
        if (!$hit) continue;
    }

    // Resolve a single set of recipient addresses for the client (same on
    // every row of theirs). Skip the whole client if neither billing_email
    // nor email is valid — flag every row so it's visible in the report.
    $first   = $overdueRows[0];
    if ($testMode) {
        // Divert: a real client never sees a test send.
        $toAddrs = [$testTo];
    } else {
        $toAddrs = pick_billing_emails($first['billing_email'] ?? null, $first['email'] ?? null);
        if (empty($toAddrs)) {
            foreach ($overdueRows as $r) {
                $skipped[(int)$r['Invoice_No']] = 'no valid email on Clients (Billing Email / Email)';
            }
            continue;
        }
    }
    $toLabel = implode(', ', $toAddrs);

    // ── Path A: client has 2+ overdue → batch into one statement email ──
    if ($multipleOverdue) {
        // Trigger only if any of the client's overdue invoices passes the
        // per-invoice gating today. We collect their reasons either way
        // so the report is informative.
        $anyEligible = false;
        $rowReasons  = [];
        foreach ($overdueRows as $r) {
            $reason = $shouldRemind($r);
            $rowReasons[(int)$r['Invoice_No']] = $reason;
            if ($reason === 'send') $anyEligible = true;
        }
        if (!$anyEligible) {
            foreach ($rowReasons as $no => $r) $skipped[$no] = preg_replace('/^skip:/', '', $r);
            continue;
        }

        // Per-client statement spam guard (skipped in test mode so admin
        // can re-fire the same scenario back-to-back).
        if (!$testMode && !empty($clientLastStatement[$cid]) && $singleInvoice <= 0) {
            $hoursSinceStatement = (time() - strtotime($clientLastStatement[$cid])) / 3600;
            if ($hoursSinceStatement < ($minGapDays * 24)) {
                foreach ($overdueRows as $r) {
                    $skipped[(int)$r['Invoice_No']] = 'client statement sent ' . round($hoursSinceStatement) . 'h ago';
                }
                continue;
            }
        }

        if ($dryRun) {
            foreach ($overdueRows as $r) {
                $sent[(int)$r['Invoice_No']] = '(dry-run) would have included in statement to ' . $toLabel;
            }
            continue;
        }

        try {
            $maxDays = max(array_map(fn($r) => (int)$r['days_overdue'], $overdueRows));
            sendOverdueStatement($pdo, $first, $overdueRows, $toAddrs, $subjectTestPrefix);
            if (!$testMode) {
                meta_set($pdo, 'reminder_statement_last_' . $cid, date('Y-m-d H:i:s'));
                foreach ($overdueRows as $r) {
                    $invNo = (int)$r['Invoice_No'];
                    meta_set($pdo, 'reminder_last_' . $invNo, date('Y-m-d H:i:s'));
                    $sent[$invNo] = "covered in statement to {$toLabel} ({$maxDays}d worst-overdue)";
                }
            } else {
                foreach ($overdueRows as $r) {
                    $invNo = (int)$r['Invoice_No'];
                    $sent[$invNo] = "[TEST] would have included in statement to {$toLabel} ({$maxDays}d worst-overdue)";
                }
            }
        } catch (Exception $e) {
            foreach ($overdueRows as $r) {
                $skipped[(int)$r['Invoice_No']] = 'STATEMENT FAILED: ' . $e->getMessage();
            }
        }
        continue;
    }

    // ── Path B: single overdue invoice → individual reminder ────────────
    $r      = $overdueRows[0];
    $invNo  = (int)$r['Invoice_No'];
    $days   = (int)$r['days_overdue'];
    $reason = $shouldRemind($r);
    if ($reason !== 'send') {
        $skipped[$invNo] = preg_replace('/^skip:/', '', $reason);
        continue;
    }

    if ($dryRun) { $sent[$invNo] = '(dry-run) would have reminded ' . $toLabel; continue; }

    try {
        sendReminder($pdo, $r, $toAddrs, $subjectTestPrefix);
        if (!$testMode) {
            meta_set($pdo, 'reminder_last_' . $invNo, date('Y-m-d H:i:s'));
            $sent[$invNo] = 'sent to ' . $toLabel . " ({$days}d overdue)";
        } else {
            $sent[$invNo] = "[TEST] reminder sent to {$toLabel} ({$days}d overdue, tone preview)";
        }
    } catch (Exception $e) {
        $skipped[$invNo] = 'FAILED: ' . $e->getMessage();
    }
}

function sendReminder(PDO $pdo, array $r, array $toAddrs, string $testPrefix = ''): void {
    $invNumStr = 'CAD-' . str_pad((string)$r['Invoice_No'], 5, '0', STR_PAD_LEFT);
    $name      = client_first_name($r['Contact'] ?? null);
    $days      = (int)$r['days_overdue'];
    $due       = !empty($r['Xero_DueDate']) ? date('d/m/Y', strtotime($r['Xero_DueDate'])) : '';
    $amount    = '$' . number_format((float)$r['Xero_AmountDue'], 2);
    $online    = $r['Xero_OnlineUrl'] ?? '';

    if      ($days >= 60) $tone = 'final';
    elseif  ($days >= 45) $tone = 'very_firm';
    elseif  ($days >= 30) $tone = 'firm';
    elseif  ($days >= 14) $tone = 'reminder';
    else                  $tone = 'gentle';

    $tplKey = 'reminder_' . $tone;
    $vars   = array_merge(default_email_boilerplate(), [
        'name'         => $name,
        'invoice_no'   => $invNumStr,
        'amount'       => $amount,
        'due_date'     => $due,
        'days_overdue' => (string)$days,
        'online_url'   => $online,
        'client_name'  => trim((string)($r['Client_Name'] ?? '')),
    ]);

    $subject = render_email_template(email_template_get($pdo, $tplKey, 'subject'), $vars);
    $text    = render_email_template(email_template_get($pdo, $tplKey, 'text'),    $vars);
    $html    = render_email_template(email_template_get($pdo, $tplKey, 'html'),    $vars);

    SmtpMailer::send([
        'to'       => $toAddrs,
        'bcc'      => ['accounts@cadviz.co.nz'],
        'reply_to' => 'accounts@cadviz.co.nz',
        'subject'  => $testPrefix . $subject,
        'text'     => $text,
        'html'     => $html,
    ]);
}

/**
 * Overdue-statement email for a client with multiple overdue invoices.
 * Replaces N individual reminders with one consolidated email that lists
 * every overdue invoice and attaches each one's Xero PDF. Tone is keyed
 * off the WORST-overdue invoice in the bundle (so a client with one 60d
 * + four 7d invoices gets a final-notice tone, not a gentle one).
 */
function sendOverdueStatement(PDO $pdo, array $clientRow, array $overdueRows, array $toAddrs, string $testPrefix = ''): void {
    require_once __DIR__ . '/xero_client.php';
    $xc = new XeroClient($pdo);

    $name        = client_first_name($clientRow['Contact'] ?? null);
    $clientName  = trim((string)($clientRow['Client_Name'] ?? ''));
    $maxDays     = max(array_map(fn($r) => (int)$r['days_overdue'], $overdueRows));
    if      ($maxDays >= 60) $tone = 'final';
    elseif  ($maxDays >= 45) $tone = 'very_firm';
    elseif  ($maxDays >= 30) $tone = 'firm';
    elseif  ($maxDays >= 14) $tone = 'reminder';
    else                     $tone = 'gentle';

    // Build per-invoice rows + Xero PDF attachments.
    $totalDue    = 0.0;
    $linesText   = [];
    $linesHtml   = [];
    $attachments = [];
    $missingPdf  = [];
    foreach ($overdueRows as $r) {
        $invNo     = (int)$r['Invoice_No'];
        $invNumStr = 'CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT);
        $due       = $r['Xero_DueDate'] ? date('d/m/Y', strtotime($r['Xero_DueDate'])) : '';
        $amt       = (float)$r['Xero_AmountDue'];
        $totalDue += $amt;
        $online    = (string)($r['Xero_OnlineUrl'] ?? '');

        $linesText[] = sprintf("  %s   due %s   %d days overdue   $%s",
            $invNumStr, $due ?: '          ', (int)$r['days_overdue'], number_format($amt, 2));
        $linesHtml[] = '<tr>'
            . '<td style="padding:4px 8px"><strong>' . $invNumStr . '</strong></td>'
            . '<td style="padding:4px 8px">' . htmlspecialchars($due) . '</td>'
            . '<td style="padding:4px 8px;text-align:right">' . (int)$r['days_overdue'] . 'd</td>'
            . '<td style="padding:4px 8px;text-align:right">$' . number_format($amt, 2) . '</td>'
            . '<td style="padding:4px 8px;text-align:center">'
            . ($online ? '<a href="' . htmlspecialchars($online) . '" style="color:#0a6">View online</a>' : '<span style="color:#888">&mdash;</span>')
            . '</td>'
            . '</tr>';

        if (!empty($r['Xero_InvoiceID'])) {
            try {
                $pdf = $xc->getInvoicePdf($r['Xero_InvoiceID']);
                $attachments[] = [
                    'name' => $invNumStr . '.pdf',
                    'mime' => 'application/pdf',
                    'data' => $pdf,
                ];
            } catch (Exception $e) {
                $missingPdf[] = $invNumStr;
            }
        } else {
            $missingPdf[] = $invNumStr;
        }
    }

    $totalStr = '$' . number_format($totalDue, 2);
    $count    = count($overdueRows);
    $exampleRef = 'CAD-' . str_pad((string)$overdueRows[0]['Invoice_No'], 5, '0', STR_PAD_LEFT);

    $invoiceTableHtml = '<table border="1" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">'
        . '<thead><tr style="background:#eee">'
        . '<th style="padding:6px 8px;text-align:left">Invoice</th>'
        . '<th style="padding:6px 8px;text-align:left">Due</th>'
        . '<th style="padding:6px 8px;text-align:right">Days overdue</th>'
        . '<th style="padding:6px 8px;text-align:right">Amount</th>'
        . '<th style="padding:6px 8px;text-align:center">Online</th>'
        . '</tr></thead><tbody>'
        . implode('', $linesHtml)
        . '</tbody></table>';
    if (!empty($missingPdf)) {
        $invoiceTableHtml .= '<p style="color:#a00">PDF attachments could not be retrieved for: ' . htmlspecialchars(implode(', ', $missingPdf)) . '. Please reply to this email if you need copies.</p>';
    }

    $tplKey = 'overdue_statement_' . ($tone === 'reminder' ? 'gentle' : $tone);
    $vars   = array_merge(default_email_boilerplate(), [
        'name'              => $name,
        'client_name'       => $clientName,
        'count'             => (string)$count,
        'total_due'         => $totalStr,
        'days_overdue'      => (string)$maxDays,
        'invoice_table_html'=> $invoiceTableHtml,
        'invoice_lines_text'=> implode("\r\n", $linesText) . (!empty($missingPdf) ? "\r\n(PDF attachments could not be retrieved for: " . implode(', ', $missingPdf) . ". Please reply if you need copies.)" : ''),
        'example_ref'       => $exampleRef,
    ]);

    $subject = render_email_template(email_template_get($pdo, $tplKey, 'subject'), $vars);
    $text    = render_email_template(email_template_get($pdo, $tplKey, 'text'),    $vars);
    $html    = render_email_template(email_template_get($pdo, $tplKey, 'html'),    $vars);

    SmtpMailer::send([
        'to'          => $toAddrs,
        'bcc'         => ['accounts@cadviz.co.nz'],
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => $testPrefix . $subject,
        'text'        => $text,
        'html'        => $html,
        'attachments' => $attachments,
    ]);
}

// ── Output ────────────────────────────────────────────────────────────────
if ($isCli) {
    echo "Reminders run @ " . date('c')
       . ($dryRun ? ' (DRY RUN)' : '')
       . ($testMode ? " (TEST MODE → diverted to {$testTo}"
                       . ($daysOverride !== null ? ", forcing days={$daysOverride}" : '')
                       . ")" : '')
       . "\n";
    echo "  xero sync: updated=" . (int)($syncResult['updated'] ?? 0)
       . ", paid_marked=" . (int)($syncResult['paid_marked'] ?? 0) . "\n";
    foreach (($syncResult['errors'] ?? []) as $e) echo "    SYNC ERROR: $e\n";
    echo "  sent:    " . count($sent)    . "\n";
    foreach ($sent    as $no => $msg) echo "    INV $no — $msg\n";
    echo "  skipped: " . count($skipped) . "\n";
    foreach ($skipped as $no => $msg) echo "    INV $no — $msg\n";
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><link href="site.css" rel="stylesheet"><title>Send reminders</title></head>
<body><div class="page"><div class="card">
  <h2>Overdue reminders<?= $dryRun ? ' &mdash; DRY RUN' : '' ?><?= $testMode ? ' &mdash; TEST MODE' : '' ?></h2>
  <?php if ($testMode): ?>
    <div style="background:#fff3cd;border:1px solid #c8a52e;color:#7a5a00;padding:8px 12px;border-radius:4px;margin-bottom:12px">
      <strong>Test mode:</strong> all emails diverted to <code><?= htmlspecialchars($testTo) ?></code>.
      Spam guards relaxed, opt-in flag bypassed, Sent flags / reminder_last NOT updated.
      <?php if ($daysOverride !== null): ?>Tone preview: forcing days_overdue=<strong><?= (int)$daysOverride ?></strong>.<?php endif; ?>
    </div>
  <?php endif; ?>
  <p style="color:#246">Xero sync: <strong><?= (int)($syncResult['updated'] ?? 0) ?></strong> invoices refreshed,
     <strong><?= (int)($syncResult['paid_marked'] ?? 0) ?></strong> auto-marked PAID before this run.</p>
  <?php foreach (($syncResult['errors'] ?? []) as $e): ?>
    <p style="color:#c33">Sync warning: <?= htmlspecialchars($e) ?></p>
  <?php endforeach; ?>
  <p><strong><?= count($sent) ?> sent</strong>, <strong><?= count($skipped) ?> skipped</strong>.</p>
  <ul>
    <?php foreach ($sent as $no => $msg): ?>
      <li style="color:#1a6b1a">CAD-<?= str_pad((string)$no, 5, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($msg) ?></li>
    <?php endforeach; ?>
    <?php foreach ($skipped as $no => $msg): ?>
      <li style="color:#888">CAD-<?= str_pad((string)$no, 5, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($msg) ?></li>
    <?php endforeach; ?>
  </ul>
  <p><a href="monthly_invoicing.php" class="btn-primary">Back to monthly invoicing</a></p>
</div></div></body></html>
