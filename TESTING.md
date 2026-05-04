# CADViz — Testing & Cron Setup Guide

How to verify the invoice + reminder flows end-to-end without spamming
real clients, and how to hook the cron job up on cPanel.

> **Golden rule of test mode:** every reminder / statement test path
> diverts the recipient to a single test inbox. A real client never sees
> a test email. The diversion happens in code, not in the SMTP server.

---

## 1. Quick reference — test entry points

| Goal | URL / Command | What it does |
|---|---|---|
| **Preview the cron without sending anything** | `xero_send_reminders.php?dry_run=1` | Lists who *would* be reminded today. No SMTP traffic. |
| **Send a real reminder for a single invoice** | "Send reminder" button on `monthly_invoicing.php` | Bypasses opt-in and schedule. Goes to the real client. |
| **Run cron with everything diverted to your inbox** | `xero_send_reminders.php?test=1` | Same logic as cron, but `to:` is replaced with `accounts@cadviz.co.nz`. Subject prefixed `[TEST]`. No DB state changes. |
| **Send to a different test inbox** | `xero_send_reminders.php?test=1&test_to=erik@cadviz.co.nz` | Override divert target. |
| **Preview a specific tone** | `xero_send_reminders.php?test=1&force_tone=final` | Pretends every overdue invoice is at the right day count for that tone. Tone values: `gentle`, `reminder`, `firm`, `very_firm`, `final`. |
| **Preview a specific day count** | `xero_send_reminders.php?test=1&force_days=37` | Pins every overdue row to exactly 37 days overdue. |
| **All of the above from the UI** | `monthly_invoicing.php` → "Test mode" links under the table | One-click runs for each tone. |

In test mode:
- Recipient → `accounts@cadviz.co.nz` (or `?test_to=`)
- Subject prefixed with `[TEST] ` so the inbox preview is unmistakable
- Spam guards relaxed (the same client can be re-tested back-to-back)
- Opt-in flag bypassed (you don't need to flip "Start reminders")
- Hard-stop bypassed (you can preview a `final` tone on a 200-day invoice)
- `Sent` flags / `reminder_last` / `reminder_statement_last` **NOT updated** — your test never poisons the real cron schedule

---

## 2. Smoke test before flipping the cron on

Before you set up the cron job, do this once to make sure your install
sends mail correctly and reaches the right inboxes.

### 2a. Preview the actual schedule
1. Open `monthly_invoicing.php`.
2. Click **"Preview reminder run (dry-run)"**.
3. The page lists every invoice that *would* receive a reminder if cron
   ran right now, with the reason (`sent to ...`, `not on reminder schedule`,
   `reminders not started`, etc.). No emails are sent.
4. If the list is empty (because nothing's opted in yet — see step 3),
   that's fine — we'll force one in test mode below.

### 2b. Test each tone end-to-end
1. On `monthly_invoicing.php`, click each of the test-mode links:
   "7d / gentle", "14d / reminder", "30d / firm", "45d / very_firm",
   "60d / final".
2. Every click sends one email to `accounts@cadviz.co.nz` with a `[TEST]`
   subject prefix. Read each one — confirm:
   - Wording matches the tone (gentle = friendly, final = "FINAL NOTICE").
   - PDF attachments come through for clients with `Xero_InvoiceID`.
   - Bank-account details + reference (`CAD-0xxxxx`) are right.
   - The "if you've already paid, please disregard" disclaimer is there.

### 2c. Test the multi-overdue statement path
1. Find a client with at least 2 overdue invoices in the dashboard.
   (If none exist, in phpMyAdmin run
   `UPDATE Invoices SET Xero_DueDate = DATE_SUB(CURDATE(), INTERVAL 10 DAY) WHERE Invoice_No IN (...)`
   on two test invoices for the same client to fake it.)
2. Open `xero_send_reminders.php?test=1` and read the `[TEST]` email.
3. Confirm: one email covering both invoices, table of overdue invoices,
   total, all PDFs attached.

### 2d. Single-invoice send
1. On `monthly_invoicing.php`, click ✉ Send reminder for one row.
2. This is the only path that sends to the **real client** in this
   smoke test — use it on an invoice for a friendly client (or your
   own test client) and confirm the email lands.

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
1. Check `monthly_invoicing.php` — every opted-in invoice should have a
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

## 4. Examples — sending sample emails to yourself

You'll often want to "see what the client would see" before flipping
opt-in. Three quick recipes:

### Email a single invoice to your own inbox
```
xero_invoice_email.php  → not directly callable; instead use
xero_send_reminders.php?test=1&test_to=erik@cadviz.co.nz&invoice_no=9489
```
That sends the regular reminder (not the invoice email). To preview the
*invoice email* itself, push the invoice to Xero on a test client whose
billing email is yours, then click "Email from CADViz" on the invoice
list.

### Preview the very-firm wording on a real invoice
```
xero_send_reminders.php?test=1&force_tone=very_firm
```
Every overdue invoice on the system gets the very-firm wording, all
diverted to your inbox, all `Sent` flags untouched.

### Preview the final-notice wording on a single specific invoice
```
xero_send_reminders.php?test=1&force_tone=final&invoice_no=9489
```
Single invoice, final tone, diverted.

### Preview the multi-overdue statement wording
Pick a client with multiple overdue invoices, run:
```
xero_send_reminders.php?test=1&test_to=you@yourdomain.co.nz
```
The statement email includes all overdue invoices for clients with 2+,
diverted to your inbox.

---

## 5. CLI test recipes (for terminal users)

```bash
# Dry-run — shows who would be reminded, sends nothing
php xero_send_reminders.php cron dry-run

# Test mode — diverts to the default test inbox (accounts@cadviz.co.nz)
php xero_send_reminders.php cron test

# Test mode + custom recipient
CADVIZ_REMINDER_TEST_TO="erik@cadviz.co.nz" php xero_send_reminders.php cron test

# Test mode + force a specific tone
# (no flag — pass via env var so it survives a `cron`-style invocation)
CADVIZ_REMINDER_TEST_MODE=1 CADVIZ_REMINDER_TEST_TO=you@example.com \
    php xero_send_reminders.php cron
```

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
| Spurious `reminder_started_<n>` rows | `DELETE FROM App_Meta WHERE meta_key LIKE 'reminder_started_%'` (then opt back in via the UI) |
| Spurious `reminder_last_<n>` rows | `DELETE FROM App_Meta WHERE meta_key LIKE 'reminder_last_%'` |
| `Sent = 1` set by a real (not test-mode) email | `UPDATE Invoices SET Sent = 0, date_sent = NULL WHERE Invoice_No = ?` |
| Stuck Xero tokens | menu → Disconnect Xero → Connect again |
| Stuck Google SMTP | menu → Disconnect (if visible) or `DELETE FROM Smtp_Tokens` |

Test mode never writes any of these, so the only way to need this is if
you ran a real (non-test) command by mistake.
