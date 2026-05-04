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
$where = "i.Xero_InvoiceID IS NOT NULL
          AND i.Xero_Status = 'AUTHORISED'
          AND i.Xero_AmountDue > 0
          AND i.Xero_DueDate IS NOT NULL
          AND i.Xero_DueDate < CURDATE()
          AND (COALESCE(c.billing_email, '') <> '' OR COALESCE(c.email, '') <> '')";
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
$shouldRemind = function(array $r) use ($singleInvoice, $reminderStages, $reminderHardStop, $minGapDays) {
    $days = (int)$r['days_overdue'];

    // Opt-in default — only send for invoices Erik flipped on.
    if (empty($r['reminder_started']) && $singleInvoice <= 0) {
        return 'skip:reminders not started for this invoice (use Start Reminders on monthly_invoicing)';
    }
    // Hard stop — final notice goes out at 60d; cron does not auto-send past that.
    if ($days > $reminderHardStop && $singleInvoice <= 0) {
        return "skip:past hard-stop ({$days}d overdue, cap {$reminderHardStop}d) — handle manually";
    }
    // Stage match — exact day = 7, 14, 30, 45, 60.
    $isStage = in_array($days, $reminderStages, true);
    if (!$isStage && $singleInvoice <= 0) {
        return "skip:not on reminder schedule (overdue $days d)";
    }
    // Spam guard
    if ($r['last_reminder_at']) {
        $hoursSince = (time() - strtotime($r['last_reminder_at'])) / 3600;
        if ($hoursSince < ($minGapDays * 24) && $singleInvoice <= 0) {
            return 'skip:reminded ' . round($hoursSince) . 'h ago';
        }
    }
    return 'send';
};

// ── Main loop: per client ────────────────────────────────────────────────
foreach ($byClient as $cid => $g) {
    if (count($sent) >= $cap) break;
    /** @var array $overdueRows */
    $overdueRows = $g['overdue'];
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
    $toAddrs = pick_billing_emails($first['billing_email'] ?? null, $first['email'] ?? null);
    if (empty($toAddrs)) {
        foreach ($overdueRows as $r) {
            $skipped[(int)$r['Invoice_No']] = 'no valid email on Clients (Billing Email / Email)';
        }
        continue;
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

        // Per-client statement spam guard
        if (!empty($clientLastStatement[$cid]) && $singleInvoice <= 0) {
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
            sendOverdueStatement($pdo, $first, $overdueRows, $toAddrs);
            meta_set($pdo, 'reminder_statement_last_' . $cid, date('Y-m-d H:i:s'));
            // Mark every covered invoice's reminder_last so the spam guard
            // applies whether the next batch picks individual or statement.
            foreach ($overdueRows as $r) {
                $invNo = (int)$r['Invoice_No'];
                meta_set($pdo, 'reminder_last_' . $invNo, date('Y-m-d H:i:s'));
                $sent[$invNo] = "covered in statement to {$toLabel} ({$maxDays}d worst-overdue)";
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
        sendReminder($pdo, $r, $toAddrs);
        meta_set($pdo, 'reminder_last_' . $invNo, date('Y-m-d H:i:s'));
        $sent[$invNo] = 'sent to ' . $toLabel . " ({$days}d overdue)";
    } catch (Exception $e) {
        $skipped[$invNo] = 'FAILED: ' . $e->getMessage();
    }
}

function sendReminder(PDO $pdo, array $r, array $toAddrs): void {
    $invNumStr = 'CAD-' . str_pad((string)$r['Invoice_No'], 5, '0', STR_PAD_LEFT);
    $totalIncTax = (float)$r['Subtotal'] * (1 + (float)$r['Tax_Rate']);
    // Greet by Contact first name; fallback to "Valued Customer".
    $name      = client_first_name($r['Contact'] ?? null);
    $days      = (int)$r['days_overdue'];
    $due       = date('d/m/Y', strtotime($r['Xero_DueDate']));
    $amount    = '$' . number_format((float)$r['Xero_AmountDue'], 2);
    $online    = $r['Xero_OnlineUrl'] ?? '';

    // Tone tiers — match the stage table: 7=gentle, 14=reminder, 30=firm,
    // 45=very_firm, 60=final (also the hard-stop).
    if      ($days >= 60) $tone = 'final';
    elseif  ($days >= 45) $tone = 'very_firm';
    elseif  ($days >= 30) $tone = 'firm';
    elseif  ($days >= 14) $tone = 'reminder';
    else                  $tone = 'gentle';

    $text = "Dear {$name},\r\n\r\n";
    if ($tone === 'gentle') {
        $text .= "This is a friendly reminder that invoice {$invNumStr} for {$amount} was due on {$due} ({$days} days ago).\r\n\r\n";
    } elseif ($tone === 'reminder') {
        $text .= "Invoice {$invNumStr} for {$amount} is now {$days} days overdue (due date {$due}).\r\n\r\n";
        $text .= "Please arrange payment at your earliest convenience.\r\n\r\n";
    } elseif ($tone === 'firm') {
        $text .= "Invoice {$invNumStr} for {$amount} is now {$days} days overdue. We would appreciate prompt payment.\r\n\r\n";
        $text .= "If there's an issue with this invoice please reply to this email so we can sort it.\r\n\r\n";
    } elseif ($tone === 'very_firm') {
        $text .= "Invoice {$invNumStr} for {$amount} is now {$days} days overdue (due date {$due}) — this is significantly outside our usual payment terms.\r\n\r\n";
        $text .= "Please settle this invoice this week. Under our standard Terms and Conditions of Trade (clause 5.2) interest of 2.5% per month or part month may be added to any amount owing past the due date.\r\n\r\n";
        $text .= "If there is a dispute or a hardship issue please reply to this email today so we can talk it through before it escalates further.\r\n\r\n";
    } else { // final (90+ days)
        $text .= "FINAL NOTICE — Invoice {$invNumStr} for {$amount} is now {$days} days overdue (due date {$due}).\r\n\r\n";
        $text .= "If we have not received payment or heard from you within 7 days we will refer this account to a debt-collection agency. Late-payment interest (2.5% per month or part month, T&Cs clause 5.2) and any reasonable solicitor's / collection-agency fees we incur become payable in addition to the invoice amount (clause 5.3).\r\n\r\n";
        $text .= "Please reply to this email today even if you cannot pay in full — we would much rather agree a payment plan than escalate.\r\n\r\n";
    }
    if ($online) $text .= "View / pay online: {$online}\r\n\r\n";
    $text .= "If you have already paid this invoice, please disregard this email — our records simply haven't caught up with your payment yet.\r\n\r\n";
    $text .= "Kind regards,\r\nCADViz Accounts\r\naccounts@cadviz.co.nz\r\n";

    $html  = "<p>Dear " . htmlspecialchars($name) . ",</p>";
    if ($tone === 'gentle') {
        $html .= "<p>This is a friendly reminder that invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> was due on <strong>{$due}</strong> ({$days} days ago).</p>";
    } elseif ($tone === 'reminder') {
        $html .= "<p>Invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> is now <strong>{$days} days overdue</strong> (due date {$due}).</p>";
        $html .= "<p>Please arrange payment at your earliest convenience.</p>";
    } elseif ($tone === 'firm') {
        $html .= "<p>Invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> is now <strong>{$days} days overdue</strong>. We would appreciate prompt payment.</p>";
        $html .= "<p>If there's an issue with this invoice please reply to this email so we can sort it.</p>";
    } elseif ($tone === 'very_firm') {
        $html .= "<p>Invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> is now <strong>{$days} days overdue</strong> (due date <strong>{$due}</strong>) &mdash; this is significantly outside our usual payment terms.</p>";
        $html .= "<p>Please settle this invoice this week. Under our standard Terms and Conditions of Trade (clause&nbsp;5.2) interest of 2.5% per month or part month may be added to any amount owing past the due date.</p>";
        $html .= "<p>If there is a dispute or a hardship issue please reply to this email today so we can talk it through before it escalates further.</p>";
    } else { // final (90+ days)
        $html .= "<p style=\"color:#a00;font-weight:bold\">FINAL NOTICE</p>";
        $html .= "<p>Invoice <strong>{$invNumStr}</strong> for <strong>{$amount}</strong> is now <strong>{$days} days overdue</strong> (due date <strong>{$due}</strong>).</p>";
        $html .= "<p>If we have not received payment or heard from you <strong>within 7 days</strong> we will refer this account to a debt-collection agency. Late-payment interest (2.5% per month or part month, T&amp;Cs clause&nbsp;5.2) and any reasonable solicitor's / collection-agency fees we incur become payable in addition to the invoice amount (clause&nbsp;5.3).</p>";
        $html .= "<p>Please reply to this email today even if you cannot pay in full &mdash; we would much rather agree a payment plan than escalate.</p>";
    }
    if ($online) $html .= "<p><a href=\"" . htmlspecialchars($online) . "\">View / pay invoice online</a></p>";
    $html .= "<p style=\"color:#666\"><em>If you have already paid this invoice, please disregard this email &mdash; our records simply haven't caught up with your payment yet.</em></p>";
    $html .= "<p>Kind regards,<br>CADViz Accounts<br>accounts@cadviz.co.nz</p>";

    // Subject prefix matches the tone so the urgency is visible from the
    // inbox preview without opening the email.
    $subjectPrefix = [
        'gentle'    => 'Reminder',
        'reminder'  => 'Overdue',
        'firm'      => 'Overdue',
        'very_firm' => 'OVERDUE — please action',
        'final'     => 'FINAL NOTICE',
    ][$tone] ?? 'Reminder';

    SmtpMailer::send([
        'to'       => $toAddrs,
        'bcc'      => ['accounts@cadviz.co.nz'],
        'reply_to' => 'accounts@cadviz.co.nz',
        'subject'  => "{$subjectPrefix}: Invoice {$invNumStr} ({$days} days overdue)",
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
function sendOverdueStatement(PDO $pdo, array $clientRow, array $overdueRows, array $toAddrs): void {
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

    // ── Build text body ────────────────────────────────────────────────
    $text  = "Dear {$name},\r\n\r\n";
    if ($tone === 'gentle' || $tone === 'reminder') {
        $text .= "We have {$count} overdue invoices totalling {$totalStr} on your account with CADViz Limited. Please find attached and arrange payment when you can.\r\n\r\n";
    } elseif ($tone === 'firm') {
        $text .= "We have {$count} overdue invoices totalling {$totalStr} on your account with CADViz Limited. Please arrange payment promptly.\r\n\r\n";
        $text .= "If there's a dispute or hardship issue please reply to this email so we can talk it through.\r\n\r\n";
    } elseif ($tone === 'very_firm') {
        $text .= "Your CADViz Limited account currently has {$count} overdue invoices totalling {$totalStr} — the oldest is now {$maxDays} days past due, which is significantly outside our payment terms.\r\n\r\n";
        $text .= "Please settle these invoices this week. Under our standard Terms and Conditions of Trade (clause 5.2) interest of 2.5% per month or part month may be added to any amount owing past the due date.\r\n\r\n";
        $text .= "If there is a dispute or a hardship issue please reply to this email today so we can agree a payment plan before the matter escalates further.\r\n\r\n";
    } else { // final
        $text .= "FINAL NOTICE — your CADViz Limited account has {$count} overdue invoices totalling {$totalStr}, the oldest now {$maxDays} days past due.\r\n\r\n";
        $text .= "If we have not received payment in full or heard from you within 7 days we will refer this account to a debt-collection agency. Late-payment interest (2.5% per month or part month, T&Cs clause 5.2) and any reasonable solicitor's / collection-agency fees we incur become payable in addition to the invoice amounts (clause 5.3).\r\n\r\n";
        $text .= "Please reply to this email today even if you cannot pay in full — we would much rather agree a payment plan than escalate.\r\n\r\n";
    }
    $text .= "Outstanding invoices:\r\n" . implode("\r\n", $linesText) . "\r\n\r\n";
    $text .= "TOTAL OVERDUE: {$totalStr}\r\n\r\n";
    $text .= "IMPORTANT: please pay each invoice INDIVIDUALLY using that invoice's number (e.g. " . ($overdueRows[0]['Invoice_No'] ? 'CAD-' . str_pad((string)$overdueRows[0]['Invoice_No'], 5, '0', STR_PAD_LEFT) : 'CAD-09489') . ") as the bank-transfer reference, so we can match each payment correctly.\r\n";
    $text .= "Internet Bank Transfer\r\n";
    $text .= "Account Name: CADViz Limited\r\n";
    $text .= "Account Number: 03-0275-0551274-000\r\n\r\n";
    if (!empty($missingPdf)) {
        $text .= "(PDF attachments could not be retrieved for: " . implode(', ', $missingPdf) . ". Please reply if you need copies.)\r\n\r\n";
    }
    $text .= "If you have already paid any of the invoices listed above, please disregard this email for those — our records simply haven't caught up with your payment(s) yet.\r\n\r\n";
    $text .= "Kind regards,\r\nCADViz Accounts\r\naccounts@cadviz.co.nz\r\n";

    // ── Build HTML body ────────────────────────────────────────────────
    $html  = '<p>Dear ' . htmlspecialchars($name) . ',</p>';
    if ($tone === 'gentle' || $tone === 'reminder') {
        $html .= "<p>We have <strong>{$count} overdue invoices</strong> totalling <strong>{$totalStr}</strong> on your account with CADViz Limited. Please find attached and arrange payment when you can.</p>";
    } elseif ($tone === 'firm') {
        $html .= "<p>We have <strong>{$count} overdue invoices</strong> totalling <strong>{$totalStr}</strong> on your account with CADViz Limited. Please arrange payment promptly.</p>";
        $html .= '<p>If there&rsquo;s a dispute or hardship issue please reply to this email so we can talk it through.</p>';
    } elseif ($tone === 'very_firm') {
        $html .= "<p>Your CADViz Limited account currently has <strong>{$count} overdue invoices</strong> totalling <strong>{$totalStr}</strong> &mdash; the oldest is now <strong>{$maxDays} days past due</strong>, which is significantly outside our payment terms.</p>";
        $html .= '<p>Please settle these invoices this week. Under our standard Terms and Conditions of Trade (clause&nbsp;5.2) interest of 2.5% per month or part month may be added to any amount owing past the due date.</p>';
        $html .= '<p>If there is a dispute or a hardship issue please reply to this email today so we can agree a payment plan before the matter escalates further.</p>';
    } else {
        $html .= '<p style="color:#a00;font-weight:bold">FINAL NOTICE</p>';
        $html .= "<p>Your CADViz Limited account has <strong>{$count} overdue invoices</strong> totalling <strong>{$totalStr}</strong>, the oldest now <strong>{$maxDays} days past due</strong>.</p>";
        $html .= '<p>If we have not received payment in full or heard from you <strong>within 7 days</strong> we will refer this account to a debt-collection agency. Late-payment interest (2.5% per month or part month, T&amp;Cs clause&nbsp;5.2) and any reasonable solicitor&rsquo;s / collection-agency fees we incur become payable in addition to the invoice amounts (clause&nbsp;5.3).</p>';
        $html .= '<p>Please reply to this email today even if you cannot pay in full &mdash; we would much rather agree a payment plan than escalate.</p>';
    }
    $html .= '<table border="1" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">';
    $html .= '<thead><tr style="background:#eee">'
          .  '<th style="padding:6px 8px;text-align:left">Invoice</th>'
          .  '<th style="padding:6px 8px;text-align:left">Due</th>'
          .  '<th style="padding:6px 8px;text-align:right">Days overdue</th>'
          .  '<th style="padding:6px 8px;text-align:right">Amount</th>'
          .  '<th style="padding:6px 8px;text-align:center">Online</th>'
          .  '</tr></thead><tbody>';
    $html .= implode('', $linesHtml);
    $html .= '</tbody></table>';
    $html .= "<p style=\"font-size:16px;margin-top:10px\"><strong>Total overdue: {$totalStr}</strong></p>";
    $exampleRef = 'CAD-' . str_pad((string)$overdueRows[0]['Invoice_No'], 5, '0', STR_PAD_LEFT);
    $html .= '<p style="color:#a00000"><strong>Important:</strong> please pay each invoice <em>individually</em> and quote that invoice&rsquo;s reference (e.g. <strong>' . htmlspecialchars($exampleRef) . '</strong>) on your bank transfer so we can match each payment to the correct invoice.</p>';
    $html .= '<p>Internet Bank Transfer: <strong>03 0275 0551274 00</strong> (CADViz Limited)</p>';
    if (!empty($missingPdf)) {
        $html .= '<p style="color:#a00">PDF attachments could not be retrieved for: ' . htmlspecialchars(implode(', ', $missingPdf)) . '. Please reply to this email if you need copies.</p>';
    }
    $html .= '<p style="color:#666"><em>If you have already paid any of the invoices listed above, please disregard this email for those &mdash; our records simply haven&rsquo;t caught up with your payment(s) yet.</em></p>';
    $html .= '<p>Kind regards,<br>CADViz Accounts<br>accounts@cadviz.co.nz</p>';

    $subjectPrefix = [
        'gentle'    => 'Overdue invoices',
        'reminder'  => 'Overdue invoices',
        'firm'      => 'Overdue invoices',
        'very_firm' => 'OVERDUE — please action',
        'final'     => 'FINAL NOTICE',
    ][$tone] ?? 'Overdue invoices';

    SmtpMailer::send([
        'to'          => $toAddrs,
        'bcc'         => ['accounts@cadviz.co.nz'],
        'reply_to'    => 'accounts@cadviz.co.nz',
        'subject'     => "{$subjectPrefix}: {$count} overdue invoice(s) for " . ($clientName ?: 'your account') . " ({$totalStr})",
        'text'        => $text,
        'html'        => $html,
        'attachments' => $attachments,
    ]);
}

// ── Output ────────────────────────────────────────────────────────────────
if ($isCli) {
    echo "Reminders run @ " . date('c') . ($dryRun ? ' (DRY RUN)' : '') . "\n";
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
  <h2>Overdue reminders<?= $dryRun ? ' — DRY RUN' : '' ?></h2>
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
