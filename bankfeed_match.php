<?php
/**
 * Bank-feed auto-match (Akahu alongside Xero).
 *
 * Job: read each bank credit transaction, look for invoice references in
 * the description / particulars / code / reference fields, and record
 * suggested allocations into Bank_Allocations. We do NOT touch
 * Invoices.Paid or DatePaid — Xero is still the source of truth for those
 * (xero_sync.php flips them when Xero says PAID).
 *
 * Instead, this module's role is detective: catch payments that have
 * cleared the bank but haven't been reconciled in Xero yet, and surface
 * them on Erik's menu. Once Erik reconciles in Xero, the next
 * xero_sync run will flip Paid=1 and the alert clears naturally.
 *
 * Allocation cache:
 *   Invoices.AmountPaid is updated as a SUM(Bank_Allocations.amount)
 *   cache for query convenience — DO NOT use it as a source of truth
 *   for whether an invoice is paid; use Invoices.Paid (Xero's view).
 *   The discrepancy between these two columns is precisely what the
 *   menu alert flags as "needs reconciling".
 *
 * Recognised invoice reference formats (see extract_invoice_refs):
 *   CAD-01234, CAD 01234, CAD01234, cad-01234, etc.
 *   INV-01234, INV1234, inv01234, etc.
 *   Bare numbers (only when no prefixed match found): 1234, 12345, etc.
 *   Multi-invoice in one description: "cad12345 cad6789" → matches both.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Find all candidate invoice numbers in arbitrary bank-text. Returns an
 * ordered, de-duplicated array of int Invoice_No values that exist in
 * the Invoices table.
 *
 *   1. First pass: prefixed matches (CAD or INV, with optional dash /
 *      space, with optional leading zeros). If any are found, ONLY
 *      prefixed matches are returned — bare numbers are skipped to
 *      avoid false positives from dates/amounts/transaction IDs in the
 *      bank description.
 *   2. Second pass (only if no prefixed matches): bare digit groups
 *      (2-8 digits). Validated against Invoices.Invoice_No so we don't
 *      hallucinate matches against unrelated numbers like dates.
 *
 * The validation step (does this Invoice_No actually exist?) catches
 * both bare-number false positives and obvious bad data.
 */
function extract_invoice_refs(PDO $pdo, string $text): array
{
    $candidates = []; // ordered set keyed by invoice number → true

    // Pass 1: prefixed (CAD or INV)
    if (preg_match_all('/\b(?:CAD|INV)[-\s]?0*(\d{1,8})\b/i', $text, $m)) {
        foreach ($m[1] as $n) {
            $n = (int)$n;
            if ($n > 0 && !isset($candidates[$n])) $candidates[$n] = true;
        }
    }

    if (!empty($candidates)) {
        // Have prefixed matches — validate against Invoices to drop hallucinations.
        return validate_invoice_refs($pdo, array_keys($candidates));
    }

    // Pass 2: bare digits only when no prefix found.
    if (preg_match_all('/\b0*(\d{2,8})\b/', $text, $m)) {
        foreach ($m[1] as $n) {
            $n = (int)$n;
            if ($n > 0 && !isset($candidates[$n])) $candidates[$n] = true;
        }
    }
    if (empty($candidates)) return [];
    return validate_invoice_refs($pdo, array_keys($candidates));
}

/**
 * Filter a list of candidate invoice numbers down to those that exist in
 * the Invoices table. Preserves the input order. One query, IN(...).
 */
function validate_invoice_refs(PDO $pdo, array $candidates): array
{
    if (empty($candidates)) return [];
    $in = implode(',', array_fill(0, count($candidates), '?'));
    $st = $pdo->prepare("SELECT Invoice_No FROM Invoices WHERE Invoice_No IN ($in)");
    $st->execute($candidates);
    $exists = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $exists[(int)$n] = true;
    $out = [];
    foreach ($candidates as $c) {
        if (!empty($exists[(int)$c])) $out[] = (int)$c;
    }
    return $out;
}

/**
 * Run auto-match across every unmatched / partially-matched CREDIT.
 * Returns count of NEW Bank_Allocations rows created in this pass.
 *
 * Idempotent: re-running on a fully_matched / manual_matched txn is a
 * no-op. Re-running on partially_matched walks the txn's remaining
 * unallocated amount against any new candidate references.
 */
function run_auto_match(PDO $pdo): int
{
    $created = 0;
    $rows = $pdo->query(
        "SELECT bt.*
           FROM Bank_Transactions bt
          WHERE bt.amount > 0
            AND bt.matched_status IN ('unmatched','partially_matched')
          ORDER BY bt.txn_date ASC, bt.id ASC"
    )->fetchAll();

    foreach ($rows as $bt) {
        $blob = ($bt['description'] ?? '') . ' '
              . ($bt['particulars'] ?? '') . ' '
              . ($bt['code']        ?? '') . ' '
              . ($bt['reference']   ?? '');
        $refs = extract_invoice_refs($pdo, $blob);
        if (empty($refs)) continue;

        // Allocate the txn across every recognised invoice in order. The
        // per-allocation guards (skip if invoice fully paid, skip if txn
        // fully consumed) keep this safe even when the references are
        // overlapping or stale.
        foreach ($refs as $invNo) {
            if (allocate_to_invoice($pdo, (int)$bt['id'], $invNo, /*manual*/false, /*by*/'auto-ref')) {
                $created++;
            }
        }
    }
    return $created;
}

/**
 * Allocate this transaction (or what remains of it) to one invoice.
 * Returns true if any allocation happened.
 *
 * - $manual = true ⇒ the call originates from bankfeed_reconcile.php's
 *   manual UI; the row gets auto=0 and matched_by = current user.
 * - $cap is an upper bound on the allocation amount, used by the manual
 *   UI when the admin wants to allocate a specific portion (e.g. for a
 *   true partial payment). Pass null to take the smaller of "txn
 *   remaining" and "invoice remaining".
 *
 * IMPORTANT (alongside-Xero): we do NOT flip Invoices.Paid or write
 * DatePaid. Xero is the source of truth for those. We only write
 * Invoices.AmountPaid as a denormalised cache so the menu alert can
 * SELECT cheaply.
 */
function allocate_to_invoice(PDO $pdo, int $txnId, int $invoiceNo, bool $manual = true, string $by = 'manual', ?float $cap = null): bool
{
    $bt = $pdo->prepare("SELECT id, amount FROM Bank_Transactions WHERE id = ?");
    $bt->execute([$txnId]);
    $txn = $bt->fetch();
    if (!$txn) return false;

    $inv = $pdo->prepare(
        "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.AmountPaid,
                ROUND(i.Subtotal * (1 + COALESCE(i.Tax_Rate, 0)), 2) AS gross
           FROM Invoices i
          WHERE i.Invoice_No = ?"
    );
    $inv->execute([$invoiceNo]);
    $invoice = $inv->fetch();
    if (!$invoice) return false;

    $invoiceGross     = round((float)$invoice['gross'], 2);
    $invoicePaidPrev  = round((float)($invoice['AmountPaid'] ?? 0), 2);
    $invoiceRemaining = round($invoiceGross - $invoicePaidPrev, 2);
    if ($invoiceRemaining <= 0.005) return false;  // already covered

    $spent = (float)$pdo->query(
        "SELECT COALESCE(SUM(amount), 0) FROM Bank_Allocations WHERE transaction_id = " . (int)$txnId
    )->fetchColumn();
    $txnRemaining = round((float)$txn['amount'] - $spent, 2);
    if ($txnRemaining <= 0.005) return false;

    $alloc = min($txnRemaining, $invoiceRemaining);
    if ($cap !== null) $alloc = min($alloc, max(0, round($cap, 2)));
    if ($alloc <= 0.005) return false;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO Bank_Allocations (transaction_id, invoice_no, amount, allocated_at, allocated_by, auto, note)
             VALUES (?, ?, ?, NOW(), ?, ?, ?)"
        )->execute([
            $txnId,
            $invoiceNo,
            $alloc,
            $manual ? ($_SESSION['UserID'] ?? $by) : $by,
            $manual ? 0 : 1,
            null,
        ]);

        recompute_invoice_amount_paid($pdo, $invoiceNo);

        $newSpent = $spent + $alloc;
        $newTxnRemaining = round((float)$txn['amount'] - $newSpent, 2);
        $newStatus = ($newTxnRemaining <= 0.005)
            ? ($manual ? 'manual_matched' : 'fully_matched')
            : 'partially_matched';
        $pdo->prepare(
            "UPDATE Bank_Transactions
                SET matched_status = ?, matched_at = NOW(), matched_by = ?
              WHERE id = ?"
        )->execute([
            $newStatus,
            $manual ? ($_SESSION['UserID'] ?? $by) : $by,
            $txnId,
        ]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reverse one Bank_Allocations row. Recomputes the affected invoice's
 * AmountPaid cache and downgrades the source transaction's matched_status
 * appropriately. Used by bankfeed_reconcile.php's "undo" button.
 */
function reverse_allocation(PDO $pdo, int $allocId): void
{
    $row = $pdo->prepare("SELECT transaction_id, invoice_no, amount FROM Bank_Allocations WHERE id = ?");
    $row->execute([$allocId]);
    $a = $row->fetch();
    if (!$a) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM Bank_Allocations WHERE id = ?")->execute([$allocId]);
        recompute_invoice_amount_paid($pdo, (int)$a['invoice_no']);

        $stillSpent = (float)$pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM Bank_Allocations WHERE transaction_id = " . (int)$a['transaction_id']
        )->fetchColumn();
        $txn = $pdo->prepare("SELECT amount FROM Bank_Transactions WHERE id = ?");
        $txn->execute([(int)$a['transaction_id']]);
        $txnAmount = (float)$txn->fetchColumn();
        $remaining = round($txnAmount - $stillSpent, 2);

        if ($stillSpent <= 0.005) $newStatus = 'unmatched';
        elseif ($remaining > 0.005) $newStatus = 'partially_matched';
        else $newStatus = 'fully_matched';

        $pdo->prepare("UPDATE Bank_Transactions SET matched_status = ? WHERE id = ?")
            ->execute([$newStatus, (int)$a['transaction_id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Recompute Invoices.AmountPaid for one invoice from the authoritative
 * SUM(Bank_Allocations.amount).
 *
 * Also: when bank evidence covers the invoice's gross AND the invoice
 * has never been stamped paid before (DatePaid IS NULL), promote local
 * Paid=1 + DatePaid=today. Rationale: if Erik is on holiday, payments
 * arrive but don't get reconciled in Xero — without this, the reminder
 * cron would chase clients who paid days ago. Akahu seeing the bank
 * transaction is sufficient evidence to silence the chase. Xero will
 * catch up when Erik reconciles, and xero_sync's "sticky 1" means it
 * never resets. The "needs reconciling in Xero" menu alert still fires
 * separately (driven by AmountPaid vs Xero_Status, not by local Paid)
 * so Erik knows there's still a Xero-side action to do.
 *
 * Guarding on `DatePaid IS NULL` means manual un-ticks via
 * invoice_edit.php (which preserves DatePaid) won't be re-flipped on
 * the next Akahu sync — that's an explicit admin override.
 */
function recompute_invoice_amount_paid(PDO $pdo, int $invoiceNo): void
{
    $st = $pdo->prepare(
        "SELECT COALESCE((SELECT SUM(amount) FROM Bank_Allocations WHERE invoice_no = ?), 0) AS paid"
    );
    $st->execute([$invoiceNo]);
    $paid = round((float)$st->fetchColumn(), 2);

    $pdo->prepare("UPDATE Invoices SET AmountPaid = ? WHERE Invoice_No = ?")
        ->execute([$paid, $invoiceNo]);

    // Auto-flip Paid=1 only when:
    //   (a) bank evidence ≥ invoice gross,
    //   (b) currently unpaid AND no DatePaid stamp (= virgin: never set
    //       paid by Xero, never set paid manually, never un-ticked).
    if ($paid <= 0.005) return;
    $g = $pdo->prepare(
        "SELECT Paid, DatePaid,
                ROUND(Subtotal * (1 + COALESCE(Tax_Rate, 0)), 2) AS gross
           FROM Invoices WHERE Invoice_No = ?"
    );
    $g->execute([$invoiceNo]);
    $row = $g->fetch();
    if (!$row) return;
    $gross = round((float)$row['gross'], 2);
    if ($gross <= 0.005)               return;        // refuse to flip a zero-gross invoice
    if ((int)$row['Paid'] !== 0)       return;        // already paid (Xero or manual)
    if (!empty($row['DatePaid']))      return;        // someone touched DatePaid before — respect it
    if ($paid + 0.005 < $gross)        return;        // not enough evidence

    $pdo->prepare("UPDATE Invoices SET Paid = 1, DatePaid = CURDATE() WHERE Invoice_No = ?")
        ->execute([$invoiceNo]);
}

/**
 * Detect invoices that look paid (or partially paid) per bank evidence
 * but Xero hasn't reconciled yet. Used by menu.php for the Erik/Jen
 * alerts.
 *
 * Returns ['needs_reconciling' => [...], 'partial_overdue' => [...]]
 * where each list is an array of:
 *   ['Invoice_No', 'Client_Name', 'gross', 'amount_paid', 'remaining',
 *    'days_overdue', 'Xero_Status']
 */
/**
 * Detect bank-vs-Xero discrepancies for the menu callouts.
 *
 *   needs_reconciling  : bank evidence covers the invoice (= AmountPaid ≥ gross)
 *                        but Xero_Status is not 'PAID'. Local Paid may already be
 *                        1 (Akahu auto-flipped it in recompute_invoice_amount_paid)
 *                        — that doesn't dismiss the alert, because Xero still
 *                        needs reconciling so Xero's records match.
 *   partial_overdue    : 0 < AmountPaid < gross AND past due date.
 *
 * The local Paid flag deliberately does NOT gate either alert. Akahu's
 * job here is to flag what Xero needs to do; whether we already silenced
 * the reminder cron locally is independent.
 */
function detect_unreconciled(PDO $pdo): array
{
    $out = ['needs_reconciling' => [], 'partial_overdue' => []];
    try {
        $rows = $pdo->query(
            "SELECT i.Invoice_No, i.Subtotal, i.Tax_Rate, i.AmountPaid,
                    i.Paid, i.Xero_Status, i.Xero_DueDate, i.Xero_InvoiceID,
                    DATEDIFF(CURDATE(), i.Xero_DueDate) AS days_overdue,
                    ROUND(i.Subtotal * (1 + COALESCE(i.Tax_Rate, 0)), 2) AS gross,
                    c.Client_Name
               FROM Invoices i
               LEFT JOIN Clients c ON i.Client_ID = c.Client_id
              WHERE COALESCE(i.AmountPaid, 0) > 0.005"
        )->fetchAll();
    } catch (Exception $e) { return $out; }

    foreach ($rows as $r) {
        $paid       = round((float)$r['AmountPaid'], 2);
        $gross      = round((float)$r['gross'], 2);
        $remaining  = round($gross - $paid, 2);
        $hasXeroId  = !empty($r['Xero_InvoiceID']);
        $xeroPaid   = (string)($r['Xero_Status'] ?? '') === 'PAID';
        $row = [
            'Invoice_No'   => (int)$r['Invoice_No'],
            'Client_Name'  => $r['Client_Name'],
            'gross'        => $gross,
            'amount_paid'  => $paid,
            'remaining'    => $remaining,
            'days_overdue' => (int)($r['days_overdue'] ?? 0),
            'Xero_Status'  => $r['Xero_Status'],
            'local_paid'   => (int)($r['Paid'] ?? 0),
        ];
        if ($remaining <= 0.005) {
            // Bank evidence covers gross. Alert if Xero hasn't reconciled
            // and there's actually a Xero record to reconcile against.
            if ($hasXeroId && !$xeroPaid) {
                $out['needs_reconciling'][] = $row;
            }
        } elseif (($r['days_overdue'] ?? 0) > 0) {
            // Genuine partial payment AND invoice is overdue. Surfaces
            // for both Xero-pushed AND legacy invoices because the
            // partial-payment actions (send thanks, credit shortfall)
            // are useful in both cases — credit_shortfall just skips
            // the Xero leg when Xero_InvoiceID is missing.
            $out['partial_overdue'][] = $row;
        }
    }
    return $out;
}
