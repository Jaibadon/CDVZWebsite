# CADViz — Testing & Cron Setup Guide

How to verify the invoice + reminder flows end-to-end without spamming
real clients, and how to hook the cron job up on cPanel.

> **Golden rule of test mode:** every reminder / statement test path
> diverts the recipient to a single test inbox. A real client never sees
> a test email. The diversion happens in code, not in the SMTP server.

---

## 1. Verify the reminder preview and send flow

1. In staging, apply both `migrations/add_invoice_reminders.sql` and `migrations/add_verified_invoice_delivery.sql` after the App_Meta, Xero and Akahu migrations. Open **Invoice Reminders** from **More** or **Monthly Invoicing**.
2. Verify that a 59-day overdue invoice from before the cutover is in **Backlog review**. Confirm the original send and billing recipients, then choose **Start gently** for that invoice and check the recipient, date, invoice number and gentle template. Put another invoice for the same client on a dated hold and check that it stays out of the email.
3. Set a test client to **Manual only** and confirm that client disappears from the automatic plan. A newly sent invoice under the Automatic default should show the standard eight-day grace period.
4. Run `xero_send_reminders.php?dry_run=1` or `php xero_send_reminders.php cron preview-only` to check today's plan without SMTP or Xero sync. Preview a template with **Send sample email** on **Email templates**.
5. On an isolated mail transport, run cron twice and verify that an already sent reminder does not repeat. Confirm that partial payments, changed balances and failed current Xero lookups block sends. Very firm and Final require owner approval.
6. Check that a mixed-stage statement uses the gentlest stage, leaves held invoices out and advances only the matching stages. A delivery with an uncertain result should appear in **Deliveries to check**.

---

## 3. Wire up the cron job (cPanel)

### 3a. Set up the cron schedule

In cPanel → **Cron Jobs** → "Add New Cron Job":

| Field | Value |
|---|---|
| Common settings | Daily at 9 AM |
| Command | `/usr/local/bin/php /home/USERNAME/public_html/xero_send_reminders.php cron` |

(Replace `USERNAME` with your cPanel username. The PHP binary path may
be `/usr/bin/php` on some hosts — check **Software → Select PHP Version**.)

The trailing `cron` argument tells the script it's running under cron
(skips `auth_check.php` which would otherwise reject the run).

### 3b. Confirm the cron actually fires
On the day after you wire it up:
1. Check `monthly_invoicing.php` — invoices contacted by cron should have a
   `Last reminder` date that matches the cron run.
2. Check the cPanel **Cron Output** (or the email it sends to you) for
   the script's stdout. It should say:
   ```
   Reminders run @ 2026-05-05T09:00:01+12:00
     xero sync: updated=12, paid_marked=2
     sent:    3
       INV 9489 — sent to ap@example.com (14d overdue)
     skipped: 5
       INV 9450 — reminders not started for this invoice
   ```

### 3c. What to do if the cron silently doesn't fire
1. Run the command manually from a terminal:
   `/usr/local/bin/php /home/USERNAME/public_html/xero_send_reminders.php cron`
2. If it errors, the message tells you why (missing PHP module, DB creds,
   Xero token expired, etc.).
3. Common one: **Xero token expired.** Erik needs to log in and visit
   the menu — the OAuth refresh runs automatically there.
4. Add a CADVIZ_REMINDER_CAP env if you want to cap the per-run send
   count (defaults to 30 — beyond that the script breaks early so a
   bad day doesn't send 200 emails).

---

## 4. Sample emails

Open **Email templates** and choose **Send sample email** on the saved template.
The recipient is an internal mailbox and the body uses fictional invoice data.
Check both the plain text and HTML versions. Use an isolated mail transport to
test real PDF attachment handling without sending a customer document.

## 5. CLI preview and test copies

```bash
php xero_send_reminders.php cron preview-only
```

Use `cron dry-run` to send copies of the planned reminders to
`jai@nielsen.org.nz`. The copies are labelled **[DRY RUN]**, show the
intended recipients, and omit PDFs and live payment links. Dry runs read
the local queue and leave reminder history untouched, so the same copies
can repeat if the test cron stays scheduled. A live run verifies every
invoice in Xero immediately before delivery.

---

## 6. Sanity checks for related flows

| Flow | How to verify |
|---|---|
| **Push to Xero** | Push a test invoice → `Xero_InvoiceID` appears on the row → invoice shows up in Xero with correct line items. |
| **Email from CADViz** | Click ✉ on a test invoice (with your email as billing_email) → email arrives → PDF matches Xero → totals match the email body (no doubling, no GST mistake). |
| **Statement** | Press "Send Statement" on a client with multiple unpaid invoices → check the email arrived with all PDFs attached, table is correct, fixed-price quote info if applicable. |
| **Xero sync** | Mark an invoice PAID in Xero → run `xero_sync.php` → `Invoices.Paid = 1` and `Invoices.DatePaid` populated locally → the invoice drops off `monthly_invoicing.php`. |
| **DueDate push** | On a fixed-price project, change `PaymentOption` locally → run `xero_sync.php` → Xero's DueDate gets corrected (panel shows `duedate_pushed` count). |
| **Submit timesheet** | Submit a missing weekday → row inserts cleanly even on tables that previously had a `TS_ID=0` zombie or a Timesheets_HIST PK collision. |
| **Approve future leave** | Book future leave on `main.php` (project 1435) → row shows red → Erik approves on menu → row goes back to normal. |

---

## 7. Testing concurrency (race-condition-prone code)

The MAX(id)+1 patterns (TS_ID, Invoice_No, Client_id, proj_id) are
defended with retry-on-duplicate up to 5 attempts. To verify:

1. Open two browser tabs, each on a "create" form (e.g. `new_client.php`).
2. Submit both within the same second. Both should land at consecutive
   IDs (no duplicate-key crash).
3. The retry path is silent on success; check Apache error log
   afterwards — should be clean.

For TS_ID specifically, the `Timesheet_catch` AFTER DELETE trigger uses
`INSERT IGNORE` (after running `migrations/fix_timesheets_hist_trigger.sql`).
Re-submit the same week 5× in a row; it should succeed every time.

---

## 8. Rolling back a bad test

If a test run accidentally bumped real state somewhere, here's the
audit trail to clean up.

| What | How to clear |
|---|---|
| Wrong client or invoice policy | Use **Invoice Reminders** to adjust the setting and verify the next planned email. |
| Spurious `reminder_last_<n>` rows | `DELETE FROM App_Meta WHERE meta_key LIKE 'reminder_last_%'` |
| `Sent = 1` set by a real (not test-mode) email | `UPDATE Invoices SET Sent = 0, date_sent = NULL WHERE Invoice_No = ?` |
| Stuck Xero tokens | menu → Disconnect Xero → Connect again |
| Stuck Google SMTP | menu → Disconnect (if visible) or `DELETE FROM Smtp_Tokens` |

Test mode never writes any of these, so the only way to need this is if
you ran a real (non-test) command by mistake.
