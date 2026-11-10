<?php
require_once __DIR__ . '/invoice_email_safety.php';

const INVOICE_REMINDER_TONES = [1 => 'gentle', 2 => 'reminder', 3 => 'firm', 4 => 'very_firm', 5 => 'final'];
const INVOICE_REMINDER_GAPS = [1 => 7, 2 => 16, 3 => 15, 4 => 15];

function invoice_reminder_context(PDO $pdo): array {
    $cutover = meta_get($pdo, 'invoice_reminder_cutover');
    if (!$cutover) {
        throw new RuntimeException('Invoice reminder setup is incomplete. Apply add_invoice_reminders.sql.');
    }
    $clock = $pdo->query('SELECT CURDATE() AS today, NOW() AS local_now')->fetch();
    return [
        'cutover' => $cutover,
        'today' => $clock['today'],
        'local_now' => $clock['local_now'],
        'min_gap' => max(1, (int)meta_get($pdo, 'reminder_min_gap_days', '6')),
    ];
}

function invoice_reminder_rows(PDO $pdo, string $today, int $invoiceNo = 0): array {
    $contact = clients_has_contact($pdo) ? 'c.Contact' : 'NULL AS Contact';
    $where = "i.Xero_InvoiceID IS NOT NULL AND i.Xero_InvoiceID <> ''
              AND i.Xero_Status = 'AUTHORISED' AND i.Xero_AmountDue > 0 AND COALESCE(i.Paid, 0) = 0";
    $params = [$today];
    if ($invoiceNo > 0) {
        $where .= ' AND i.Invoice_No = ?';
        $params[] = $invoiceNo;
    }
    $st = $pdo->prepare(
        "SELECT i.Invoice_No, i.Client_ID, i.date_sent, i.Xero_DueDate, i.Xero_AmountDue,
                i.Xero_OnlineUrl, i.Xero_InvoiceID, i.AmountPaid, i.Xero_AmountPaid,
                DATEDIFF(?, i.Xero_DueDate) AS days_overdue,
                c.Client_Name, $contact, c.billing_email, c.email,
                COALESCE(p.mode, 'automatic') AS client_mode, COALESCE(p.grace_days, 8) AS grace_days,
                COALESCE(s.mode, 'inherit') AS mode, s.hold_until, s.started_on,
                (optin.meta_key IS NOT NULL) AS manual_exception,
                COALESCE(s.last_stage, 0) AS last_stage, s.last_sent_at, s.next_send_on,
                original.Client_ID AS verified_client_id, original.xero_invoice_id AS verified_invoice_id,
                original.xero_contact_id AS verified_contact_id, original.recipients AS verified_to,
                original.source AS delivery_source, original.verified_at,
                legacy.updated_at AS legacy_last,
                old_statement.updated_at AS legacy_statement, lc.last_legacy_contact,
                d.last_contact, d.pending_id
           FROM Invoices i
           JOIN Clients c ON c.Client_id = i.Client_ID
           LEFT JOIN Client_Reminder_Policy p ON p.Client_ID = i.Client_ID
           LEFT JOIN Invoice_Reminder_State s ON s.Invoice_No = i.Invoice_No
           LEFT JOIN Invoice_Original_Delivery original ON original.Invoice_No = i.Invoice_No
           LEFT JOIN App_Meta optin ON optin.meta_key = CONCAT('reminder_invoice_exception_', i.Invoice_No)
                 AND optin.meta_value = CONCAT(i.Client_ID, ':', i.Xero_InvoiceID)
           LEFT JOIN App_Meta legacy ON legacy.meta_key = CONCAT('reminder_last_', i.Invoice_No)
           LEFT JOIN App_Meta old_statement ON old_statement.meta_key = CONCAT('reminder_statement_last_', i.Client_ID)
           LEFT JOIN (
               SELECT older.Client_ID, MAX(m.updated_at) AS last_legacy_contact
                 FROM Invoices older JOIN App_Meta m ON m.meta_key = CONCAT('reminder_last_', older.Invoice_No)
                GROUP BY older.Client_ID
           ) lc ON lc.Client_ID = i.Client_ID
           LEFT JOIN (
               SELECT Client_ID, MAX(sent_at) AS last_contact,
                      MAX(CASE WHEN status = 'pending' THEN id END) AS pending_id
                 FROM Reminder_Deliveries WHERE status IN ('pending', 'sent') GROUP BY Client_ID
           ) d ON d.Client_ID = i.Client_ID
          WHERE $where
          ORDER BY i.Xero_DueDate, i.Invoice_No"
    );
    $st->execute($params);
    return $st->fetchAll();
}

function invoice_reminder_add_days(string $date, int $days): string {
    return (new DateTimeImmutable($date))->modify("+$days days")->format('Y-m-d');
}

function invoice_reminder_template(int $stage, bool $statement): string {
    return ($statement ? 'overdue_statement_' : 'reminder_') . INVOICE_REMINDER_TONES[$stage];
}

function invoice_reminder_number(int $invoiceNo): string {
    return invoice_email_number($invoiceNo);
}

function invoice_reminder_is_legacy(array $row, array $context): bool {
    return $row['mode'] === 'backlog' ||
        ($row['date_sent'] && $row['date_sent'] <= $context['cutover']) ||
        ($row['Xero_DueDate'] && $row['Xero_DueDate'] < substr($context['cutover'], 0, 10));
}

function invoice_reminder_delivery_matches(array $row, array $to): bool {
    return !empty($row['verified_contact_id']) &&
        (int)$row['verified_client_id'] === (int)$row['Client_ID'] &&
        strcasecmp($row['verified_invoice_id'], $row['Xero_InvoiceID']) === 0 &&
        json_decode($row['verified_to'], true) === array_values($to);
}

function invoice_reminder_legacy_key(array $row): string {
    return hash('sha256', json_encode([
        $row['Invoice_No'], $row['Client_ID'], $row['Xero_InvoiceID'], $row['Client_Name'],
        pick_billing_emails($row['billing_email'], $row['email']),
    ], JSON_THROW_ON_ERROR));
}

function invoice_reminder_exception_key(array $row): string {
    return $row['Client_ID'] . ':' . $row['Xero_InvoiceID'];
}

// Call inside the invoice policy transaction.
function invoice_reminder_set_exception(PDO $pdo, int $no, ?string $binding): void {
    $key = 'reminder_invoice_exception_' . $no;
    if ($binding === null) {
        $pdo->prepare('DELETE FROM App_Meta WHERE meta_key = ?')->execute([$key]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO App_Meta (meta_key, meta_value, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()'
    )->execute([$key, $binding]);
}

function invoice_reminder_is_manual(array $row): bool {
    return $row['mode'] === 'manual' || ($row['client_mode'] === 'manual' && empty($row['manual_exception']));
}

function invoice_reminder_verify_backlog(PDO $pdo, array $row): array {
    require_once __DIR__ . '/xero_client.php';
    $xero = new XeroClient($pdo);
    $remote = $xero->getInvoice($row['Xero_InvoiceID']);
    $contactId = invoice_email_xero_contact($row, $remote);
    $to = pick_billing_emails($row['billing_email'], $row['email']);
    $contact = $xero->findContactByName($row['Client_Name']);
    $approved = pick_billing_emails($contact['EmailAddress'] ?? '', '');
    foreach ($contact['ContactPersons'] ?? [] as $person) {
        if (($person['IncludeInEmails'] ?? false) === true) {
            $approved = array_merge($approved, pick_billing_emails($person['EmailAddress'] ?? '', ''));
        }
    }
    if (($remote['SentToContact'] ?? false) !== true || ($remote['Status'] ?? '') !== 'AUTHORISED') {
        throw new RuntimeException('Xero does not show this authorised invoice as sent. Verify the recipients and send the original invoice through CADViz before starting reminders.');
    }
    if (strcasecmp((string)($contact['ContactID'] ?? ''), $contactId) !== 0) {
        throw new RuntimeException('The Xero billing contact could not be confirmed. Review the client and invoice in Xero before starting reminders.');
    }
    $approved = array_map('strtolower', $approved);
    $recipients = array_map('strtolower', $to);
    if (!array_intersect($recipients, $approved)) {
        throw new RuntimeException('None of the listed billing addresses matches the Xero contact. Check the billing addresses in both systems before starting reminders.');
    }
    // CADViz pushes only the primary email to Xero. Allow the established
    // internal accounts-copy addresses as extras after the user confirms the
    // original recipient list, with a Xero match still required.
    $unverified = array_diff($recipients, $approved, ['accounts@cadviz.co.nz', 'erik@cadviz.co.nz']);
    if ($unverified) {
        throw new RuntimeException('Xero could not verify: ' . implode(', ', $unverified) .
            '. Confirm these billing addresses in Xero or send the original invoice through CADViz after checking them.');
    }
    return [$contactId, $to];
}

function invoice_reminder_contact_date(array $row, array $context): string {
    $last = max($row['last_contact'] ?? '', $row['legacy_statement'] ?? '', $row['last_legacy_contact'] ?? '',
                $row['legacy_last'] ?? '', $row['last_sent_at'] ?? '');
    if ($last === '') {
        return '';
    }
    $gap = (new DateTimeImmutable($last))->modify('+' . $context['min_gap'] . ' days');
    $date = $gap->format('Y-m-d');
    return $date === $context['today'] && $gap->format('Y-m-d H:i:s') > $context['local_now']
        ? invoice_reminder_add_days($date, 1)
        : $date;
}

function invoice_reminder_plan(array $row, array $context, bool $manual = false): array {
    $to = pick_billing_emails($row['billing_email'], $row['email']);
    $stage = (int)$row['last_stage'] + 1;
    $plan = ['to' => $to, 'date' => null, 'stage' => $stage, 'status' => 'Scheduled'];
    $gapDate = invoice_reminder_contact_date($row, $context);

    if ($row['pending_id']) {
        $plan['status'] = 'Delivery needs review';
    } elseif ((float)$row['AmountPaid'] > 0.005 || (float)$row['Xero_AmountPaid'] > 0.005) {
        $plan['status'] = 'Payment recorded';
    } elseif (!$row['Xero_DueDate']) {
        $plan['status'] = 'Missing due date';
    } elseif (!$to) {
        $plan['status'] = 'Missing valid billing email';
    } elseif ($stage > 5) {
        $plan['status'] = 'Final notice sent';
    } elseif (!$manual && ($row['mode'] === 'backlog' ||
               ($row['mode'] === 'inherit' && (invoice_reminder_is_legacy($row, $context) ||
                ($stage === 1 && $row['legacy_last']))))) {
        $plan['status'] = 'Backlog review';
    } elseif (!$manual && invoice_reminder_is_manual($row)) {
        $plan['status'] = 'Manual only';
    } elseif (!invoice_reminder_delivery_matches($row, $to)) {
        $plan['status'] = $row['hold_until'] > $context['today']
            ? 'On hold · delivery verification required'
            : ($row['verified_at'] ? 'Billing details changed since delivery' : 'Original invoice delivery unverified');
    } else {
        $firstDate = invoice_reminder_add_days($row['Xero_DueDate'], max(8, (int)$row['grace_days']));
        if ($row['delivery_source'] === 'email') {
            // Protect an invoice first sent after its due date.
            $firstDate = max($firstDate, invoice_reminder_add_days($row['verified_at'], 1));
        }
        $plan['date'] = max($firstDate, $gapDate, $row['started_on'] ?? '',
                            $row['hold_until'] ?? '', $row['next_send_on'] ?? '');
        if ($row['hold_until'] && $row['hold_until'] > $context['today']) {
            $plan['status'] = 'On hold';
        } elseif ($stage >= 4) {
            $plan['status'] = 'Owner approval required';
        } elseif ($plan['date'] <= $context['today']) {
            $plan['status'] = 'Ready';
        }
    }
    return $plan;
}

function invoice_reminder_manual_plan(array $row, array $context, string $action): ?array {
    $plan = invoice_reminder_plan($row, $context, true);
    if ($plan['date'] === null || $row['Xero_DueDate'] >= $context['today']) {
        return null;
    }
    if ($action === 'escalate') {
        if ($plan['stage'] < 4 || $plan['stage'] > 5 || $plan['date'] > $context['today']) {
            return null;
        }
    } elseif ($action === 'next') {
        if ($plan['stage'] < 2 || $plan['stage'] > 3 || $plan['date'] > $context['today'] ||
            !invoice_reminder_is_manual($row)) {
            return null;
        }
    } elseif ($plan['stage'] !== 1 || invoice_reminder_contact_date($row, $context) > $context['today'] ||
              $row['hold_until'] > $context['today'] ||
              ($row['delivery_source'] === 'email' && $row['verified_at'] >= $context['today'])) {
        return null;
    }
    return [
        'rows' => [$row], 'to' => $plan['to'], 'stage' => $plan['stage'],
        'template' => invoice_reminder_template($plan['stage'], false), 'advance' => [$row['Invoice_No']],
        'ready' => true, 'date' => $context['today'],
    ];
}

function invoice_reminder_cap(): int {
    return max(1, (int)(getenv('CADVIZ_REMINDER_CAP') ?: 30));
}

// The preview and sender share the same grouping, stages and recipient lookup.
function invoice_reminder_batches(array $rows, array $context): array {
    $clients = [];
    foreach ($rows as $row) {
        $plan = invoice_reminder_plan($row, $context);
        if ($plan['date'] !== null && $plan['stage'] < 4) {
            $clients[$row['Client_ID']][] = ['row' => $row, 'plan' => $plan];
        }
    }
    $batches = [];
    foreach ($clients as $items) {
        $date = max($context['today'], min(array_column(array_column($items, 'plan'), 'date')));
        $included = array_values(array_filter($items, fn($item) => $item['plan']['date'] <= $date));
        $stage = min(array_column(array_column($included, 'plan'), 'stage'));
        $invoices = array_column($included, 'row');
        $advances = array_filter($included, fn($item) => $item['plan']['stage'] === $stage);
        $batches[] = [
            'rows' => $invoices, 'to' => $included[0]['plan']['to'], 'stage' => $stage,
            'date' => $date, 'ready' => $date <= $context['today'],
            'template' => invoice_reminder_template($stage, count($invoices) > 1),
            'advance' => array_column(array_column($advances, 'row'), 'Invoice_No'),
        ];
    }
    usort($batches, fn($a, $b) => [$a['date'], $a['rows'][0]['Invoice_No']] <=> [$b['date'], $b['rows'][0]['Invoice_No']]);
    return $batches;
}

function invoice_reminder_lock(PDO $pdo): void {
    if ((int)$pdo->query("SELECT GET_LOCK(CONCAT('invoice-reminders:', MD5(DATABASE())), 0)")->fetchColumn() !== 1) {
        throw new RuntimeException('A reminder run or update is in progress. Try again shortly.');
    }
}

function invoice_reminder_unlock(PDO $pdo): void {
    $pdo->query("SELECT RELEASE_LOCK(CONCAT('invoice-reminders:', MD5(DATABASE())))");
}

// Call while holding the reminder lock. A quick mode change preserves the
// client's grace period, and neither route writes invoice reminder state.
function invoice_reminder_set_client_policy(PDO $pdo, int $clientId, string $mode, ?int $graceDays = null): void {
    if (!in_array($mode, ['automatic', 'manual'], true) ||
        ($graceDays !== null && ($graceDays < 8 || $graceDays > 365))) {
        throw new RuntimeException('Choose a valid client reminder policy and grace period.');
    }
    $st = $pdo->prepare('SELECT Client_id FROM Clients WHERE Client_id = ?');
    $st->execute([$clientId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('Client not found.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO Client_Reminder_Policy (Client_ID, mode, grace_days) VALUES (?, ?, COALESCE(?, 8))
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), grace_days = COALESCE(?, grace_days)'
        )->execute([$clientId, $mode, $graceDays, $graceDays]);
        if ($mode === 'manual') {
            $pdo->prepare(
                "DELETE FROM App_Meta WHERE meta_key LIKE 'reminder!_invoice!_exception!_%' ESCAPE '!'
                   AND meta_value LIKE CONCAT(?, ':%')"
            )->execute([$clientId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function invoice_reminder_finish_delivery(PDO $pdo, array $delivery, bool $sent): void {
    $pdo->beginTransaction();
    try {
        $stamp = $pdo->query('SELECT NOW()')->fetchColumn();
        $st = $pdo->prepare("UPDATE Reminder_Deliveries SET status = ?, sent_at = ? WHERE id = ? AND status = 'pending'");
        $st->execute([$sent ? 'sent' : 'cancelled', $sent ? $stamp : null, $delivery['id']]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('This delivery has already been reviewed.');
        }
        if ($sent) {
            $stage = (int)$delivery['stage'];
            $nextDate = isset(INVOICE_REMINDER_GAPS[$stage]) ? invoice_reminder_add_days($stamp, INVOICE_REMINDER_GAPS[$stage]) : null;
            $advance = $pdo->prepare(
                "INSERT INTO Invoice_Reminder_State (Invoice_No, last_stage, last_sent_at, next_send_on)
                 VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE
                 last_stage = VALUES(last_stage), last_sent_at = VALUES(last_sent_at), next_send_on = VALUES(next_send_on)"
            );
            foreach (json_decode($delivery['advance_numbers'], true, 512, JSON_THROW_ON_ERROR) as $no) {
                $advance->execute([$no, $stage, $stamp, $nextDate]);
            }
            // Keep the existing invoice screens' last-contact display current,
            // including invoices listed for context without advancing them.
            $contact = $pdo->prepare(
                "INSERT INTO App_Meta (meta_key, meta_value, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)"
            );
            foreach (json_decode($delivery['invoice_numbers'], true, 512, JSON_THROW_ON_ERROR) as $no) {
                $contact->execute(['reminder_last_' . $no, $stamp, $stamp]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
