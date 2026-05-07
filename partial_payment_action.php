<?php
/**
 * Partial-payment actions — invoked from the menu.php callout when an
 * invoice is overdue but has bank-evidence partial payment.
 *
 * Two actions:
 *   - send_thanks: emails the client the "thanks for the partial,
 *     balance still owed" template (key: partial_payment_thanks).
 *     Uses SmtpMailer + render_email_template, same path as reminders.
 *
 *   - credit_shortfall: writes off the remaining balance.
 *     • Locally: appends a negative line to the invoice's Timesheets
 *       (description "credit") so the local subtotal matches what was
 *       actually paid.
 *     • In Xero (when connected): creates an ACCRECCREDIT credit note
 *       and allocates it against the invoice. Credit notes are a real
 *       Xero API endpoint — POST /api.xro/2.0/CreditNotes — and the
 *       allocation endpoint is POST /api.xro/2.0/CreditNotes/{ID}/Allocations.
 *       The Xero half is GUARDED and tagged as untested in production.
 *     • Sets local Invoices.Paid = 1 + DatePaid = today so the alert
 *       clears immediately. xero_sync's next pass will reconcile if Xero
 *       picked up the allocation.
 */

session_start();
require_once 'db_connect.php';
require_once 'helpers.php';
require_once 'smtp_mailer.php';
require_once 'xero_client.php';

if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) {
    http_response_code(403); echo '<p>Admin only.</p>'; exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: menu.php'); exit;
}

$pdo    = get_db();
$action = $_POST['action'] ?? '';
$invNo  = (int)($_POST['invoice_no'] ?? 0);
if ($invNo <= 0) {
    $_SESSION['xero_flash_err'] = 'Missing invoice_no.';
    header('Location: menu.php'); exit;
}

try {
    if ($action === 'send_thanks') {
        send_partial_thanks_email($pdo, $invNo);
        $_SESSION['xero_flash'] = 'Thanks-for-partial email sent for CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT) . '.';
    } elseif ($action === 'credit_shortfall') {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) throw new RuntimeException('Credit amount must be positive.');
        credit_shortfall($pdo, $invNo, $amount);
        $_SESSION['xero_flash'] = 'Credited $' . number_format($amount, 2) . ' shortfall on CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT) . '. Local invoice marked PAID; Xero credit note attempt logged.';
    } else {
        throw new RuntimeException('Unknown action: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    $_SESSION['xero_flash_err'] = 'Action failed: ' . $e->getMessage();
}

header('Location: menu.php');
exit;

// ─────────────────────────────────────────────────────────────────────────
function send_partial_thanks_email(PDO $pdo, int $invNo): void
{
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.AmountPaid, i.Xero_DueDate, i.Xero_OnlineUrl,
                DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
                c.Client_Name, c.Contact, c.billing_email, c.email
           FROM Invoices i
           LEFT JOIN Clients c ON i.Client_ID = c.Client_id
          WHERE i.Invoice_No = ?"
    );
    $st->execute([$invNo]);
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('Invoice not found.');

    $toAddrs = pick_billing_emails($r['billing_email'] ?? null, $r['email'] ?? null);
    if (empty($toAddrs)) throw new RuntimeException('No valid billing email on this client.');

    $gross     = round((float)$r['Subtotal'] * (1 + (float)($r['Tax_Rate'] ?? 0)), 2);
    $paid      = round((float)($r['AmountPaid'] ?? 0), 2);
    $remaining = round($gross - $paid, 2);

    $vars = array_merge(default_email_boilerplate(), [
        'name'             => client_first_name($r['Contact'] ?? null),
        'client_name'      => trim((string)($r['Client_Name'] ?? '')),
        'invoice_no'       => 'CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT),
        'amount_paid'      => '$' . number_format($paid, 2),
        'amount_remaining' => '$' . number_format($remaining, 2),
        'due_date'         => !empty($r['Xero_DueDate']) ? date('d/m/Y', strtotime($r['Xero_DueDate'])) : '',
        'days_overdue'     => (string)(int)($r['days_overdue'] ?? 0),
        'online_url'       => (string)($r['Xero_OnlineUrl'] ?? ''),
    ]);

    $tplKey  = 'partial_payment_thanks';
    $subject = render_email_template(email_template_get($pdo, $tplKey, 'subject'), $vars);
    $text    = render_email_template(email_template_get($pdo, $tplKey, 'text'),    $vars);
    $html    = render_email_template(email_template_get($pdo, $tplKey, 'html'),    $vars);

    SmtpMailer::send([
        'to'       => $toAddrs,
        'bcc'      => ['accounts@cadviz.co.nz'],
        'reply_to' => 'accounts@cadviz.co.nz',
        'subject'  => $subject,
        'text'     => $text,
        'html'     => $html,
    ]);

    // Stamp reminder_last so the reminder cron doesn't immediately
    // schedule another auto-reminder on top of this manual nudge.
    meta_set($pdo, 'reminder_last_' . $invNo, date('Y-m-d H:i:s'));
}

function credit_shortfall(PDO $pdo, int $invNo, float $amount): void
{
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.Proj_ID, i.Xero_InvoiceID,
                c.Client_Name
           FROM Invoices i
           LEFT JOIN Clients c ON i.Client_ID = c.Client_id
          WHERE i.Invoice_No = ?"
    );
    $st->execute([$invNo]);
    $inv = $st->fetch();
    if (!$inv) throw new RuntimeException('Invoice not found.');

    $taxRate = (float)($inv['Tax_Rate'] ?? 0);
    // The shortfall amount is given INC GST (it's the "remaining" from
    // the partial-payment alert, which uses gross). Strip GST out so we
    // append a negative line at ex-GST that re-grosses to the same
    // amount the client is being credited.
    $exGst   = round($amount / (1 + $taxRate), 2);

    $pdo->beginTransaction();
    try {
        // Local: append a negative timesheet row tagged "credit" against
        // this invoice. Same Subtotal-recomputation path the existing
        // invoice flow uses (Subtotal = SUM of Timesheets.Hours × Rate
        // for the invoice's Proj_ID).
        $nextTsId = (int)$pdo->query(
            "SELECT GREATEST(
                COALESCE((SELECT MAX(TS_ID) FROM Timesheets), 0),
                COALESCE((SELECT MAX(TS_ID) FROM Timesheets_HIST), 0)
            ) + 1"
        )->fetchColumn();

        $pdo->prepare(
            "INSERT INTO Timesheets
                 (TS_ID, Employee_id, proj_id, TS_DATE, Task, Hours, Rate, Invoice_No)
             VALUES (?, NULL, ?, CURDATE(), 'credit', 1, ?, ?)"
        )->execute([
            $nextTsId,
            (int)($inv['Proj_ID'] ?? 0),
            -1 * $exGst,                  // negative rate × 1h = -$exGst line
            $invNo,
        ]);

        // Recompute Subtotal from this invoice's timesheet rows so the
        // local view matches what we just credited.
        $newSubStmt = $pdo->prepare("SELECT COALESCE(SUM(Hours * Rate), 0) FROM Timesheets WHERE Invoice_No = ?");
        $newSubStmt->execute([$invNo]);
        $newSub = (float)$newSubStmt->fetchColumn();

        $pdo->prepare("UPDATE Invoices SET Subtotal = ?, Paid = 1, DatePaid = COALESCE(DatePaid, CURDATE()) WHERE Invoice_No = ?")
            ->execute([$newSub, $invNo]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Xero: post a credit note + allocate against the invoice. UNTESTED
    // in production. Wrapped in try/catch so a Xero failure doesn't
    // unwind the local credit (the local invoice is already marked Paid;
    // Erik can manually create the matching Xero credit note if this
    // step fails).
    if (!empty($inv['Xero_InvoiceID']) && XeroClient::isConfigured() && XeroClient::isConnected($pdo)) {
        try {
            $xc = new XeroClient($pdo);
            $cnId = $xc->postCreditNote([
                'Type'    => 'ACCRECCREDIT',
                'Status'  => 'AUTHORISED',
                'Date'    => date('Y-m-d'),
                'Contact' => ['Name' => trim((string)($inv['Client_Name'] ?? ''))],
                'LineItems' => [[
                    'Description' => 'Partial-payment write-off (CAD-' . str_pad((string)$invNo, 5, '0', STR_PAD_LEFT) . ')',
                    'Quantity'    => 1,
                    'UnitAmount'  => round($exGst, 2),
                    'AccountCode' => '240',
                    'TaxType'     => $taxRate > 0 ? 'OUTPUT2' : 'NONE',
                ]],
            ]);
            if ($cnId) {
                $xc->allocateCreditNote($cnId, $inv['Xero_InvoiceID'], round($amount, 2));
            }
        } catch (Exception $e) {
            error_log('[partial_payment_action] Xero credit-note step failed for INV ' . $invNo . ': ' . $e->getMessage());
            $_SESSION['xero_flash_err'] = 'Local credit applied, but Xero credit-note step failed: ' . $e->getMessage()
                . '. You\'ll need to create the credit note in Xero manually.';
        }
    }
}
