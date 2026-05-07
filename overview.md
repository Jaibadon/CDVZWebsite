# CADViz Timesheet/Invoicing — Quick Reference for Debugging Help

This is a PHP 7.4+ app, originally Classic ASP / MSSQL, ported to PHP / MySQL. Lives at `remote.cadviz.co.nz`. Stack: PHP + PDO + MySQL + raw HTML/CSS + a little JS. **No framework, no Composer, no build step.** Everything is loose `.php` files in the web root.

---

## File map (what does what)

### Entry & auth
- **`login.php`** — login form. Tries bcrypt → MD5 → plain text → 13-char DES `crypt()` in that order; auto-upgrades to bcrypt on success. Rate-limited (5 attempts / 15 min).
- **`auth_check.php`** — included at the top of every authenticated page. Starts session, redirects to login.php if not logged in. Sets `$_SESSION['UserID']` (string login like `'erik'`, `'jen'`, `'dmitriyp'`) and `$_SESSION['Employee_id']` (int FK into Staff).
- **`logout.php`**, **`set_password.php`** (admin-only password reset; can also widen the column from VARCHAR(32) to VARCHAR(255) for bcrypt via `?fix_column=1`).
- **`db_connect.php`** — `get_db()` returns a PDO singleton. Loads `config.php`. PDO is in ERRMODE_EXCEPTION + FETCH_ASSOC mode. **Also hardened against HTTP/2 protocol errors**: starts an output buffer at line 1 (so PHP warnings can't bleed mid-frame), forces `display_errors=0` in production, registers a shutdown handler that wipes a partial buffer and emits a clean 500 page on fatal. Enable `display_errors` for local debugging via `CADVIZ_DEBUG=1` env or `.htaccess`. See `HTTP2_ERRORS.md` for the full triage.
- **`config.php`** — gitignored; holds DB_* and XERO_* and SMTP_* / GOOGLE_OAUTH_* constants. Sample files: `config.xero.sample.php`, `config.smtp.sample.php`.
- **`helpers.php`** — shared functions: `to_mysql_date()`, `display_date()`, `ci()` (case-insensitive row key lookup), `is_employed_staff()`, `missing_weekdays()`, `meta_get/set()`, `dormancy_sweep_run/_if_due()`, `compute_pay_by()`, `clients_has_contact()`, `client_first_name()`, `pick_billing_email()`/`pick_billing_emails()`, `render_email_template()`, `email_template_get/set()`, `default_email_templates()`, `default_email_boilerplate()`. Also defines `LEAVE_PROJECT_ID = 1435`.

### Timesheets (staff-facing)
- **`menu.php`** — main navigation. Different sections for admin vs staff. Shows Xero / SMTP connect status, pending-variation alert, dormancy sweep banner, **and Erik's "📞 30+ days overdue, please call" callout** (red panel listing invoices with `Xero_DueDate` ≥ 30 days past, click-to-call phone links).
- **`main.php`** — the timesheet entry page. 30 rows, 7-day grid. Task picker is a `<select>` (not free text) populated by JS from `window.PROJECT_TASKS` keyed by proj_id. Submit button turns red + adds JS confirm if `is_employed_staff()` and there are missing weekdays IN THE CURRENT WEEK (future-week or back-fill weeks suppress the alert). Picker option value = `Proj_Task_ID`.
- **`submit.php`** — handles the timesheet POST. Wraps DELETE+INSERTs in a single transaction, output-buffers the response. Resolves Proj_Task_ID → Task_Type_ID + Variation_ID via Project_Tasks JOIN. Re-checks missing weekdays AFTER the insert and shows red banner if gaps remain. **TS_ID generation:** combines `MAX(TS_ID)` across `Timesheets` AND `Timesheets_HIST` to avoid PK collisions (the AFTER DELETE trigger archives to HIST), with retry-on-duplicate.
- **`my_checklist.php`** — staff-facing per-project task list. `?proj_id=X` filters to one project; `?print=1` triggers auto print dialog. **Renders rate-adjusted hours via two perspectives** (see "Pricing & rate-ratio model" below):
  - **Viewer perspective** (default for every project): every task is scaled to the LOGGED-IN user's `Billing Rate` regardless of who's actually assigned. Answers "if I did this whole project, how many hours would I need at my rate?". A page-level banner says "Hours shown are from your perspective ($X/h)".
  - **Assigned perspective** (per-project toggle, gated to whoever is that project's `Manager` row): each task is scaled to its actual assignee's rate (or unscaled if TBA). Answers "what's the real hour budget per assignee?". The toggle button appears in the header of EACH project card the viewer manages — flipping one project doesn't affect the others. State is carried in the URL as `?assigned_projs=123,456` (CSV of flipped project IDs); non-managers in the URL are silently ignored. Admin status (`erik`/`jen`) does NOT grant the toggle — they have to be in `Projects.Manager` like anyone else.
  - When a project is flipped, the proj-meta line shows a `PER-ASSIGNEE VIEW` badge so it's obvious which projects are in which mode.
  - Estimated and Remaining columns show the scaled value bold + the original quoted value struck-through underneath. ⓘ on each task opens a perspective-aware tooltip explaining the math (different copy depending on which perspective that project is in).
  - When the viewer has no `Billing Rate` set, viewer mode falls back to raw quoted hours and the banner notes the missing rate with a link to `staff_admin.php`.
- **`variation_add.php`** — staff-facing form to request an unapproved variation. Acknowledgment checkbox required.

### Projects & quote builder (admin only)
- **`projects.php`** / **`projects_archive1.php`** — list views. For non-admin, link target points to `my_checklist.php?proj_id=X` instead of `updateform_admin1.php`.
- **`updateform_admin1.php`** + **`update_admin1.php`** — admin project edit form + handler.
- **`project_new.php`** + **`create_project.php`** — new project wizard.
- **`project_stages.php`** — admin "stages/tasks/quote builder" page. Loads `$quoteStatus` (NULL/draft = free editing, 'accepted' = locked) and includes `stages_editor.php`. Has Accept Quote / Reset to Draft buttons + Print Original Quote / Quote (+variations) / Variations / Checklist links. **PaymentOption picker** with values 1=20th of next month, 2=invoice date + 7 days, 3=invoice date itself.
- **`stages_editor.php`** — shared partial that does CRUD for stages + tasks + variations. Mode-aware: in `$isAccepted=true`, original tasks become read-only (`Save Variation` button creates new task in latest unapproved variation + marks original as `Is_Removed`). Helpers: `ensureUnapprovedVariation()`, `ensureVariationStage()`, **`taskTypeOptions()`** (renders the Task_Type select with optgroups: "Common in this stage" first, then "other task types" — matches on `Tasks_Types.Stage_ID == Project_Stages.Stage_Type_ID` so the obvious choices float to the top). Has a global JS submit handler that stamps `scroll_y` so PRG redirects don't lose scroll position.
- **`task_types_admin.php`** — single-page CRUD for the `Tasks_Types` catalog. **Replaces** the old `TASK_TYPES.php` + `task_type_add.php` + `task_type_update.php` + `task_type_drop.php` (all deleted). Filterable by stage; refuses to delete a task type that's still referenced by `Project_Tasks` rows (must reassign first).
- **`templates.php`** + **`template_stages.php`** + **`template_apply.php`** + **`template_save.php`** — reusable stage+task templates per Project_Type.
- **`quote.php`** — printable Fee Proposal. Includes original quote stages + approved variations. `?original_only=1` hides variations. `?nomoney=1` hides prices (used by `checklist.php`). **Breakdown modes:** `?breakdown=hours` (hours per task, no $), `?breakdown=full` (hours + $ per task), `?breakdown=tasks_only` (tasks + final fixed price). Admin-only.
- **`quote_variations.php`** — variations-only printout. Default shows ALL variations including unapproved (internal review). `?approved_only=1` for client-facing. Admin-only.
- **`checklist.php`** — wraps quote.php with `nomoney=1`.

### Invoicing
- **`invoice_list.php`** — primary unpaid-invoice listing. Each row is `.inv-row` (single horizontal line, `white-space:nowrap`, page scrolls horizontally if needed). Columns: invoice link → date → amount → client → project → status text → Xero badge → Email/Push button → Statement button → Start/Stop reminders button. The Email button is **infinitely resendable** (every click flips `Sent=1`, `date_sent=NOW()`, `Status_INV=2`). The Statement button only appears when the client has ≥ 2 unpaid invoices AND this row has been pushed to Xero.
- **`invoice_archive.php`** / **`invoice_list2.php`** — older archive views.
- **`invoice.php`** — single invoice preview. Read by `?Invoice_No=N`. Renders "TAX INVOICE" or "CREDIT NOTICE" depending on subtotal sign. **Enter-key target** is smart: prefers `?back=` query param (whitelisted), then HTTP_REFERER (with Client_ID/proj_id reconstructed if referer was `invoices_for_client.php`/`invoices_for_job.php` — those pages POST without a querystring, so we have to rebuild the URL), falls back to `invoice_list.php`.
- **`invoices_for_client.php`** / **`invoices_for_job.php`** — list filtered by Client_ID / proj_id. Reached via POST from more.php (no querystring). Each invoice link includes an explicit `&back=...` so invoice.php can return the user to the same filtered list.
- **`invoice_edit.php`** — edit individual invoice line items. **Note:** ticking the "Paid" checkbox here flips local `Paid=1` + `DatePaid` but does NOT push payment to Xero. See "Top bugs" item #11.
- **`invoice_gen.php`** — bulk-generate invoices from unprocessed timesheet entries. Race-proofed with zombie cleanup + retry-on-duplicate around the MAX(Invoice_No)+1 insert.
- **`unprocessed.php`** — admin view of timesheet rows where `IFNULL(Invoice_No, 0) = 0`.
- **`monthly_invoicing.php`** — Jen/Erik's "outstanding & overdue" page. Sync from Xero button + per-row Send Reminder + per-CLIENT Start/Stop reminders toggle + dry-run preview + per-tone test-mode quick-links. (Outdated query bug already fixed: was `c.Phone_1` which doesn't exist, now `c.Phone, c.Mobile`.)
- **`payments.php`** + payments CRUD.
- **`statement.php`** — printable statement (PDF-able). Pulls overdue Xero data per client.
- **`send_statement.php`** — POST handler. Bundles a client's unpaid invoices into one email with PDF attachments per invoice, marks each `Sent=1`. Test mode: `?test_to=…&invoice_no=N` (derives Client_ID from invoice).
- **`lib_invoice_email.php`** — `send_invoice_email_via_smtp(PDO, int $invoiceNo, bool $ccErik, bool $skipPush, string $testTo)`. Pushes to Xero first (so PDF + amount are fresh), fetches the PDF, renders the per-tone template via `render_email_template`, sends through `SmtpMailer`.
- **`xero_invoice_email.php`** — POST entry point that wraps `lib_invoice_email`. Accepts `?test_to=` for diversion.
- **`email_templates.php`** — admin page for editing every email body the system sends (invoice, manual statement, reminders × 5 tones, overdue statements × 5 tones). Subject + Text + HTML per template, "CUSTOMISED"/"default" badges, Reset to default, per-template "Send test" button with invoice picker.

### Xero integration
- **`xero_client.php`** — `XeroClient` class. OAuth2 token storage + auto-refresh. Methods: `postInvoice()`, `getInvoicesByStatus()`, `getInvoice()`, `emailInvoice()` (don't use — Xero's mailer hits client spam), `ensureContact()` (full upsert with Email/Phone/Mobile/Address/ContactPersons), `getInvoicePdf()`, `markSentToContact()`. Static helpers: `isConfigured()`, `isConnected()`, `buildAuthorizeUrl()`, `exchangeCodeAndPersist()`, `disconnect()`.
- **`xero_connect.php`** / **`xero_callback.php`** / **`xero_disconnect.php`** — OAuth flow.
- **`xero_invoice_push.php`** — POST handler + library function `push_invoice_to_xero(PDO, int): array`. Auto-creates Xero contact via `ensureContact()` if missing. Account code 240. Lines built from Timesheets, **grouped by JobName** so each project gets a section header in the description; each task line ends with "by FirstName" (Staff.First_Name fallback to login). Falls back to `Invoices.Subtotal` lump-sum if no timesheets. **Discount-doubling fix:** Timesheets.Rate already has Multiplier baked in by invoice_gen.php, so we only multiply baseRate fallback. Library mode via `XERO_INVOICE_PUSH_LIBRARY_ONLY`.
- **`xero_sync.php`** — `run_xero_sync(PDO): array` library function (CLI runnable too). Pulls AUTHORISED/SUBMITTED/PAID invoices from Xero and updates local `Xero_*` columns. When Xero says PAID, also flips local `Paid=1` + `DatePaid`. **Also pushes DueDate corrections back to Xero** when the local `compute_pay_by(Date, PaymentOption)` disagrees with what Xero has. Library mode via `XERO_SYNC_LIBRARY_ONLY`.
- **`xero_send_reminders.php`** — overdue-reminder script. Web (admin) + CLI (cron) modes. **Schedule: 8 / 15 / 31 / 46 / 61 days past due** (1-day buffer past the typical 7/14/30/45/60 because Xero's bank feed reflects payments ~1 day late — see "Reminder & statement system" below). Tracks "last sent" via `App_Meta['reminder_last_<invoice_no>']`. Spam-guard: 6-day min gap. **Opt-in is per-CLIENT** (`App_Meta['reminder_started_client_<Client_ID>']`). Stale-sync guard: aborts if MAX(Xero_LastSynced) is > 36 hours old.

### Email (SMTP via Google OAuth2)
- **`smtp_oauth.php`** — `SmtpOAuth` class. Same shape as XeroClient. Tokens in `Smtp_Tokens` table.
- **`smtp_oauth_connect.php`** / **`smtp_oauth_callback.php`** — OAuth flow.
- **`smtp_mailer.php`** — `SmtpMailer::send($msg)`. Builds multipart MIME, opens socket to `smtp.gmail.com:587`, STARTTLS, AUTH XOAUTH2 with the access token. From: `accounts@cadviz.co.nz` (which is a verified send-as alias on Erik's Gmail; NOT a real mailbox — it's a Google Group containing erik@ + jen@).

### Analytics, staff & dormancy
- **`task_analytics.php`** — actual-vs-estimated by Project_Type / Manager / Stage / Task. Median/mean/stddev/n. Chart.js (CDN). Filter: original / variations / all. Has 1-click "Apply" buttons to update Tasks_Types.Estimated_Time when the median ratio is outside ±15% over n≥3 projects.
- **`dormancy_sweep.php`** — admin page to review/undo auto-deactivations from the 5-year dormancy sweep.
- **`staff_admin.php`** — full CRUD for the `Staff` table: Login, First/Last Name, email, Mobile, Pay_Rate, `Billing Rate`, Level, Active. Add new staff (auto-allocates next `Employee_ID`). Passwords are NOT managed here — that's `set_password.php` (which has the bcrypt-aware logic). `Efficiency_Factor` column exists in the schema but is dead — not exposed in this UI.
- **`staff_hours.php`** — uninvoiced hours per staff (all-time).
- **`report.php`**, **`reports/*`** — assorted older reports.

### Shared CSS
- **`site.css`** — new shared mobile-friendly stylesheet. Cohesive button / card / table classes. `@media (max-width:700px)` breakpoint.
- **`global.css`** + **`global2.css`** — legacy stylesheets, still loaded for backward compat. Some pages have inline `<style>` blocks too.

### Docs
- **`overview.md`** — this file.
- **`TESTING.md`** — manual test plan. Covers the dry-run preview, per-tone test sends, multi-overdue statement path, rolling back accidental writes.
- **`HTTP2_ERRORS.md`** — triage guide for `ERR_HTTP2_PROTOCOL_ERROR`. The 80% case is fixed by db_connect.php's output buffering + display_errors=0. The other 20% is documented step-by-step.

---

## Pricing & rate-ratio model

**Two rates per task, used in different contexts:**

1. **Display rate** — used by `quote.php`, `quote_variations.php`, `stages_editor.php`. Computed live from the current `Assigned_To`: `(staff Billing Rate or TBA) × Multiplier`. This is what the client sees on the printed Fee Proposal and what the quote builder shows. Assigning Phil ($120) to a task IN the quote raises the displayed price. That's intentional — it's how Erik can quote senior labour at a higher rate.

2. **Quoted rate** — `Project_Tasks.Quoted_Rate`, snapshotted at task creation and updated on every save while the project is in draft. **Frozen when `Projects.Quote_Status = 'accepted'`** — reassignments after that don't touch it. This is the contract with the client.

**Where they diverge:** after acceptance, if Erik reassigns a task from Phil ($120) to a junior ($75), the Display Rate would calculate as $75 × Multiplier = lower price. But Quoted_Rate stays at $120 — that's what was sold. The reassignment doesn't change what the client owes.

**`my_checklist.php` uses Quoted_Rate** (NOT Display Rate) to compute the hour budget. Two perspectives are computed per task:

```
viewer_hours    = quoted_hours × (Quoted_Rate / viewer_rate)
assigned_hours  = quoted_hours × (Quoted_Rate / assigned_staff_rate)
```

**Viewer perspective is the default** for every user. So:
- Phil opens his checklist: every task on every project he can see is shown in HIS hour-budget. A 4h TBA-quoted task on a project where Sam is assigned still shows as 3h to Phil (because that's what 4h × $90 / Phil's $120 works out to). Phil's understanding: "if I did this, here's how it costs me in hours".
- A junior viewing the same project sees the same tasks at *their* rate — different numbers, same dollar total.

**Assigned perspective** (per-project toggle for the project's Manager only): each task scales to its actual assignee's rate. This is the "real" view. The Sam-assigned task on Phil's project shows 3.6h (Sam's $100 ratio of the $360 budget) when in assigned perspective. The toggle is **per-project, not page-level** — flipping project A to assigned-mode leaves project B in viewer-mode. Only the literal `Projects.Manager = $empId` user sees the toggle on a given card; admin status alone doesn't grant it.

The dollar value of the task is preserved in BOTH perspectives — only the hour-units shift between viewer and assignee.

**Where Quoted_Rate gets written:**
- `add_task` and `save_variation_task`: always set from current state.
- `update_task` and `save_stage_all`: refreshed only when `!$isAccepted`. After acceptance, the snapshot is frozen.
- `assign_stage` and `assign_all`: same — refresh while draft, frozen after acceptance.
- `reset_to_draft` (in `project_stages.php`): **re-snapshots `Quoted_Rate` from current state** on every original task as part of the reset. Without this, a task that was frozen at Phil's $120 would stay at $120 after the reset, and re-accepting without explicitly editing that task would carry the stale rate into the new contract. The reset wipes the freeze and takes a fresh snapshot.
- See `migrations/add_quoted_rate.sql` for the backfill rule for pre-existing rows.

**Variation tasks**: `Quoted_Rate` is set on creation and (currently) on every update regardless of variation status — TODO: tighten so it freezes when the variation flips to `approved`.

**To change the system-wide TBA rate**: edit Staff #29's `Billing Rate` via `staff_admin.php`. Don't grep for `90.00` — that hardcoded number is gone (only remains as a fallback inside `get_tba_rate()` for installs missing the row).

---

## Reminder & statement system (the part that surprises people)

The cron lives in `xero_send_reminders.php`. Run on a daily cron. End-to-end:

1. **Pre-sync.** First action is `run_xero_sync($pdo)` so the local Xero mirror is fresh.
2. **Stale-sync guard.** If `MAX(Xero_LastSynced)` is > 36h old (sync failed for 2 nights, OAuth expired, etc.), abort the whole run with a prominent banner. Better to miss a stage day than chase a client whose payment hasn't synced.
3. **Candidate select.** All invoices with `Xero_Status='AUTHORISED'`, `Xero_AmountDue>0`, `Xero_DueDate<CURDATE()`, valid email, `Xero_InvoiceID` not null. Each row carries the per-CLIENT opt-in flag (`reminder_started_client_<Client_ID>` from `App_Meta`).
4. **Group by client.**
5. **For each client:**
   - If client has ≥ 2 overdue invoices → batch into ONE statement email covering all of them. Tone derived from the worst-overdue invoice. Per-client spam guard (`reminder_statement_last_<cid>`, 6-day gap).
   - If client has 1 overdue invoice → individual reminder.

### Schedule (days past due)
| Stage | Day | Tone | Wording |
|-------|-----|------|---------|
| 1 | 8 | gentle | friendly reminder |
| 2 | 15 | reminder | please pay soon |
| 3 | 31 | firm | prompt payment requested |
| 4 | 46 | very_firm | seriously overdue |
| 5 | 61 | final | final notice — collections next. **Hard-stop** — cron does not auto-send past this. |

The schedule is offset +1 day from the typical 7/14/30/45/60 because **Xero's bank feed reflects payments ~1 day after the bank actually clears them**. Sending on day 7 risked chasing clients who'd paid the day before. The boilerplate `{disregard}` placeholder also explicitly asks the client to disregard if they paid in the last 24–48 hours.

### Opt-in is PER-CLIENT, not per-invoice
- App_Meta key: `reminder_started_client_<Client_ID>` (legacy `reminder_started_<n>` keys are ignored).
- Pressing **🔔 Start reminders (client)** on any one of a client's overdue invoices flips it for ALL of that client's overdue invoices (current and future).
- Reason: the statement-batch path includes EVERY overdue invoice for the client. If the toggle were per-invoice, the statement would awkwardly say "balance: $X" while the client's actual balance was higher.
- Toggle UIs: `monthly_invoicing.php` (per row) and `invoice_list.php` (per row, falls back to looking up Client_ID from invoice_no for safety).

### Email templates (editable)
- `default_email_templates()` in helpers.php registers 11 templates: `invoice`, `statement_manual`, `reminder_(gentle|reminder|firm|very_firm|final)`, `overdue_statement_(gentle|firm|very_firm|final)`.
- Admins override via `email_templates.php` (writes App_Meta). Override is shown with a "CUSTOMISED" badge and a Reset-to-default button.
- Common boilerplate (`{disregard}`, `{kind_regards}`, `{bank_details}`) lives in `default_email_boilerplate()`.
- Substitution: `{var_name}` → value; unknown placeholders pass through unchanged so admins notice typos in test sends.

### Manual override paths (bypass opt-in)
- `xero_send_reminders.php?invoice_no=N` — fire one reminder for a specific invoice. Always works regardless of opt-in or schedule. Used by the per-row "✉ Send reminder" button.
- Test mode: `?test=1&test_to=...&force_tone=...&force_days=...` — diverts every email to `test_to`, doesn't update Sent flags or `reminder_last_*`. The per-tone quick-link buttons on `monthly_invoicing.php` use this.
- "Send Statement" button on `invoice_list.php` posts to `send_statement.php` directly — independent of the cron.

---

## Schema (PASTE YOUR `SHOW CREATE TABLE` DUMPS HERE)

```sql
-- TODO: paste the structures so Haiku knows the exact columns + types

```

### Schema highlights worth memorising
- **Table names are case-sensitive** on Linux MySQL: `Projects`, `Clients`, `Invoices`, `Timesheets`, `Timesheets_HIST`, `Staff`, `Project_Stages`, `Project_Tasks`, `Project_Variations`, `Project_Types`, `Tasks_Types`, `Stage_Types`, `Spec_SubCats`, `Payments`, `Xero_Tokens`, `Smtp_Tokens`, `App_Meta`, `Dormancy_Log`.
- **PK columns frequently lacked AUTO_INCREMENT** (legacy from MSSQL). The convention in many handlers is `MAX(id)+1` before INSERT, with a retry-on-duplicate loop around the INSERT to handle races. `Schema_upgrades.sql` added AUTO_INCREMENT for most, but check before relying.
- **`Timesheets_HIST`** archives deleted Timesheets rows via an AFTER DELETE trigger. The trigger uses `INSERT IGNORE` (a previous version raised `Duplicate entry '0'` when the trigger re-archived a TS_ID that was already in HIST). When generating new TS_ID, MAX must span both Timesheets AND Timesheets_HIST.
- **Weights default to 1.** Use `COALESCE(weight, 1)` in queries — old data has NULLs.
- **Stage weights are no longer used in calculations.** UI input was removed; existing column data is kept but multiplied by 0 effectively (we drop the multiplier in formulas).
- **`Variation_ID IS NULL`** = original quote scope. `IS NOT NULL` = belongs to a variation.
- **`Project_Tasks.Is_Removed = 1`** = soft-deleted from original quote (kept for audit). Filter with `COALESCE(Is_Removed, 0) = 0`.
- **`Projects.Quote_Status`** ENUM('draft','accepted') NULL DEFAULT NULL. NULL == draft.
- **`Projects.Active <> 0`** filter on lists; dormancy sweep flips this to 0 after 5 years of no activity.
- **`Invoices.Paid` (tinyint 0/1)** + **`Invoices.DatePaid` (datetime)** — `xero_sync.php` flips these when Xero says PAID. Manual ticks in `invoice_edit.php` set local Paid=1 but don't push to Xero (see Top Bugs #11).
- **`Invoices.PaymentOption`** — 1=20th of next month, 2=invoice date + 7 days, 3=invoice date itself. `compute_pay_by()` is the single source of truth; `xero_sync.php` pushes DueDate corrections back to Xero when local & Xero disagree.
- **`Clients.Contact`** (added by `migrations/add_clients_contact.sql`) — verbatim "Dear ___" greeting. Falls back to "Valued Customer" when blank. Feature-detected via `clients_has_contact($pdo)`.
- **`Clients.billing_email`** — supports MULTIPLE recipients separated by `;` or `,`. Use `pick_billing_emails()`; each address is filter-validated.
- **`Staff` column names with spaces** (legacy MSSQL): `\`First Name\``, `\`Last Name\``, `\`Billing Rate\``. MySQL is case-insensitive on column identifiers by default, so older queries using `\`BILLING RATE\`` (uppercase) still work alongside the canonical mixed-case from `SHOW CREATE TABLE`. Other Staff cols: `Employee_ID`, `Login`, `email`, `Mobile`, `Pay_Rate`, `Level`, `Last_Review`, `Last_Increase`, `Bank_Acc`, `Active`, `password`, `securepword`, `Efficiency_Factor`. **`Efficiency_Factor` is dead** — column exists, no code reads it. Don't rely on it.
- **TBA quote rate** — sourced from `Staff WHERE Employee_ID = 29` (the placeholder "T.B.A." record) via `helpers.php::get_tba_rate()`. **Edit it from `staff_admin.php`** by changing Staff #29's `Billing Rate`. Falls back to $90 only if the row is missing. The single source of truth — `quote.php`, `quote_variations.php`, `stages_editor.php`, `helpers.php::compute_project_estimate()`, `my_checklist.php`, and `xero_invoice_push.php` all call `get_tba_rate()`. (Old code hardcoded `$baseRate = 90.00` in five places — that's been removed.)
- **`App_Meta` reminder keys:**
  - `reminder_started_client_<Client_ID>` — client-wide opt-in flag.
  - `reminder_last_<Invoice_No>` — last-sent timestamp (per invoice).
  - `reminder_statement_last_<Client_ID>` — last per-client statement timestamp.
  - `reminder_min_gap_days` — overrides the default 6-day spam-guard window.
- **`App_Meta` template keys:** `email_tpl_<key>_subject` / `email_tpl_<key>_text` / `email_tpl_<key>_html`. Empty = use default from `default_email_templates()`.

---

## Naming conventions
- DB column names are mostly TitleCase or snake_case mixed (`Invoice_No`, `proj_id`, `JobName`, `billing_email`, `Job_Notes`). PDO is **case-sensitive on row keys**. The `ci()` helper exists for fuzzy lookup when you don't know the case.
- Form field names mostly mirror DB column names. Some accept multiple cases (e.g. `$_GET['Invoice_No'] ?? $_GET['invoice_no'] ?? 0`).
- POST actions: each handler uses a hidden `action` field + a switch/elseif chain. Multiple `<form>` per page is normal, NEVER nest forms (browsers strip the inner tag and merge fields → silent data corruption — we hit this bug with `update_variation` vs `drop_variation`).

---

## Common patterns
- **PRG redirects after POST**: `header('Location: ' . $_SERVER['REQUEST_URI']); exit;`. stages_editor.php extends this with a `scroll_y` round-trip so saves don't bounce you to the top.
- **Feature detection**: every Xero / Variation / OAuth / Contact-column integration starts with `try { $hasFoo = (bool)$pdo->query("SHOW COLUMNS FROM X LIKE 'Y'")->fetch(); } catch (Exception $e) {}` so the app degrades gracefully when a migration hasn't been run yet.
- **Output buffering** is now mandatory across the whole app (db_connect.php starts one). HTTP/2 raises `ERR_HTTP2_PROTOCOL_ERROR` if a partial response is followed by a frame mid-stream, which is exactly what happens when PHP emits a warning halfway through HTML rendering. Don't `ob_end_flush()` mid-page.
- **Library-mode includes**: `xero_invoice_push.php` and `xero_sync.php` both check for a `XERO_*_LIBRARY_ONLY` constant and `return` if defined, so callers can `require_once` them without triggering the script's web/CLI behaviour.
- **Render email templates** via `render_email_template($templateKey, $vars, $pdo)` — handles the App_Meta override + boilerplate stitching + placeholder substitution.
- **Admin gate**: `if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) { http_response_code(403); die('Admin only.'); }`. Use this everywhere admin-only data is touched. NB: don't rely on session AccessLevel — older code uses it but the canonical check is the username list.
- **Flash messages**: `$_SESSION['xero_flash']` / `xero_flash_err` / `smtp_flash` — set by handlers, read + cleared by menu.php / monthly_invoicing.php.
- **Money math**: `Invoices.Subtotal` is decimal(19,4). Tax_Rate decimal(18,4) defaults to 0.1500 (15% NZ GST). Total inc. tax = `Subtotal * (1 + Tax_Rate)`.
- **Date math**: use `DateTime` + `DateInterval` for day iteration (DST-safe). Don't use `strtotime('+1 day', $t)` in loops. `compute_pay_by` uses DateTime("first day of next month") + setDate(20) — `strtotime('+1 month', Jan 31)` lands on Mar 3 and used to throw the due date a month ahead.
- **Date storage**: TS_DATE / TS_DATE-like columns can be DATE or DATETIME — always normalize with `DATE_FORMAT(col, '%Y-%m-%d')` in SQL when comparing keys.

---

## Recent migrations (run in order; all idempotent-ish)
Located in `migrations/`. Apply via phpMyAdmin SQL tab.
1. `add_task_type_id_to_timesheets.sql` — `Timesheets.Task_Type_ID`
2. `add_project_variations.sql` — `Project_Variations` table + `Variation_ID` cols + `Is_Removed` + NULL weight cleanup
3. `add_quote_status.sql` — `Projects.Quote_Status`
4. `add_xero_integration.sql` — `Xero_Tokens` table + `Invoices.Xero_*` cols
5. `add_app_meta_and_dormancy.sql` — `App_Meta` + `Dormancy_Log`
6. `add_smtp_tokens.sql` — `Smtp_Tokens` table
7. `add_clients_contact.sql` — `Clients.Contact` (verbatim greeting)
8. `add_timesheet_leave_approved.sql` — leave-approval workflow
9. `fix_timesheets_hist_trigger.sql` — fixes `Duplicate entry '0' for key 'PRIMARY'` by switching the AFTER DELETE trigger to `INSERT IGNORE`
10. `schema_upgrades.sql` — assorted indexes that were missing in the legacy MSSQL port

---

## Top bugs to watch for when debugging
1. **Case-sensitive table names**. `projects` won't work, must be `Projects`. Same for `Project_Types` (not `Project_types`), `Spec_SubCats` (not `Spec_Subcats`), `Tasks_Types` (not `tasks_types`).
2. **PDO row key case**. `$rs['invoice_no']` is NOT `$rs['Invoice_No']`. Use the column's exact case from `SHOW CREATE TABLE`. The `ci()` helper covers ambiguity.
3. **`SELECT *` over a JOIN drops columns** when both tables have a column with the same name (PDO FETCH_ASSOC keeps only the last). Always alias explicitly when joining Projects/Invoices/Timesheets.
4. **Nested `<form>` tags are invalid HTML**. Browsers strip the inner `<form>` opening tag and merge its fields into the outer form. Side effects: hidden `action` fields collide, last-one-wins → wrong handler runs. We hit this with Save Variation / Drop Variation. Always sibling forms, never nested.
5. **Missing AUTO_INCREMENT** on legacy PKs. If `Duplicate entry '0' for key 'PRIMARY'` errors, the column lacks AUTO_INCREMENT. Run `MAX(id)+1` manually (with retry-on-duplicate) or `ALTER TABLE … MODIFY id INT NOT NULL AUTO_INCREMENT`. **For Timesheets specifically**, the AFTER DELETE trigger was the culprit — ensure `fix_timesheets_hist_trigger.sql` is applied.
6. **Date strtotime(NULL) → epoch**. `date('d/m/Y', strtotime(null))` returns `01/01/1970`. Always null-check dates before formatting.
7. **TS_DATE comparison fails** when one side is DATE and the other DATETIME. Always `DATE_FORMAT(col, '%Y-%m-%d')`.
8. **NULL weights**. `tt.Estimated_Time * pt.Weight` returns NULL if weight is NULL. Use `COALESCE(pt.Weight, 1)`.
9. **mod_rewrite filesystem path** in Location: headers from .htaccess RewriteRule without `RewriteBase /` and a leading `/` on the substitution — produces redirects to `/home3/sys_5e4089532_6/public_html/...` instead of the URL. Already fixed; if you see this again, check `.htaccess`.
10. **Output before `header()`** kills redirects. POST handlers must `ob_start()` (or just `require_once 'db_connect.php'` which now does it) before any HTML.
11. **Local `Paid=1` does NOT push to Xero.** Direction is one-way: `xero_sync.php` flips local Paid when Xero says PAID; ticking Paid in `invoice_edit.php` sets local Paid + DatePaid only. The next sync leaves the local tick alone (`Paid = CASE WHEN ?='PAID' THEN 1 ELSE Paid END`) but `Xero_Status`, `Xero_AmountDue`, `Xero_DueDate` come back fresh from Xero — so anything filtering on the Xero mirror (monthly_invoicing.php, the reminder cron, Erik's 30+ callout) keeps showing the invoice as overdue. Manual tick only affects pages that filter on `Invoices.Paid` directly (e.g. invoice_list.php's `WHERE Paid = 0`). This is intentional — Xero is the source of truth because that's where bank-feed reconciliation happens — but there's a UX gap when a client confirms payment by phone and Xero hasn't synced yet.
12. **Xero bank-feed lag (~1 day).** Xero's bank feed reflects payments ~1 day after the bank actually clears them. The reminder schedule has a +1 day buffer (8/15/31/46/61) and every reminder/statement template's `{disregard}` boilerplate explicitly mentions "the last 24–48 hours" so a recently-paid client knows why they got the email.
13. **HTTP/2 protocol errors** — see `HTTP2_ERRORS.md`. The 80% case (PHP warning text bleeding into HTML) is fixed in db_connect.php. The other 20% (slow query, mod_security cancel, segfault) is on the host side.
14. **`invoices_for_client.php` / `invoices_for_job.php` are POST-loaded.** Their URL has no querystring, so naive HTTP_REFERER-based "back" navigation from invoice.php loses the filter. Fixed via `?back=` param + Client_ID/proj_id reconstruction in invoice.php.

---

## Where things live for common tasks

| Task | File |
|------|------|
| Add a new column to invoices | edit `Invoices` schema, then update SELECT + UPDATE in `invoice.php`, `invoice_edit.php`, `invoice_list.php`, `xero_invoice_push.php` |
| Change invoice number prefix | `xero_invoice_push.php` (`'CAD-'`) and `xero_invoice_email.php` |
| Tweak email reminder schedule | `xero_send_reminders.php` `$reminderStages = [8, 15, 31, 46, 61]` and the matching `$forceDaysFromTone` mapping below it |
| Edit an email body | `email_templates.php` (admin UI) — writes to `App_Meta`, falls back to `default_email_templates()` in helpers.php |
| Stop sending reminders for one client | `monthly_invoicing.php` → 🔕 Stop reminders (client) on any of their overdue rows |
| Mute reminders for everyone temporarily | `DELETE FROM App_Meta WHERE meta_key LIKE 'reminder_started_client_%'` (back to default opt-out state) |
| Add a new POST action to stages_editor | `stages_editor.php` POST handler block, then add the matching `<form>` in render section |
| Disable dormancy sweep temporarily | `meta_set($pdo, 'dormancy_last_run', date('Y-m'));` on the menu — or comment out the call in `menu.php` |
| Reset Xero connection | menu → Disconnect Xero, OR `DELETE FROM Xero_Tokens;` |
| Reset Google SMTP | menu → (if available) Disconnect, OR `DELETE FROM Smtp_Tokens;` |
| Force admin to be erik/jen only | already enforced via `in_array(..., ['erik','jen'], true)` checks; don't add to that list lightly |
| Diagnose ERR_HTTP2_PROTOCOL_ERROR | `HTTP2_ERRORS.md` — start with cPanel error log |
| Run the manual test plan | `TESTING.md` — covers dry-run preview, per-tone test sends, multi-overdue statement path |

---

## Don't break these
- The admin gate string list `['erik','jen']` — used in ~30 files. If the user list changes, grep them all.
- `Xero_InvoiceID` round-tripping. If a local invoice has it set, that's the binding to the Xero record. Re-pushing must `InvoiceID` it (we update, not duplicate).
- The redirect after POST pattern. Without it, refresh re-submits and you get duplicate rows.
- The `is_employed_staff()` check (currently `dmitriyp`, `hannah`). If full-time staff change, update `helpers.php`.
- The output buffer in `db_connect.php`. Don't add `ob_end_flush()` calls anywhere — let the shutdown handler manage it.
- Reminder opt-in is **per-client**, key `reminder_started_client_<Client_ID>`. The legacy per-invoice key (`reminder_started_<n>`) is ignored; don't reintroduce it.
- `xero_sync.php` is the only path that should flip `Invoices.Paid`. Don't add code that flips Paid based on local-only signals (e.g. don't auto-mark a client paid because they replied to an email).

---

## When something's broken — quick triage
1. **White screen / 500**: check Apache / cPanel error log first. With `display_errors=0` in production, the page returns clean but the error is logged. Likely PDO exception (read the message — usually a missing column or table name case mismatch).
2. **`ERR_HTTP2_PROTOCOL_ERROR`**: see `HTTP2_ERRORS.md`. Set `CADVIZ_DEBUG=1` to see the actual error rendered into the fatal-catcher panel.
3. **"Missing X"**: usually a query string param case mismatch (`Invoice_No` vs `invoice_no`).
4. **Blank table where data should be**: query is fine but returned 0 rows — check WHERE clauses (esp. `Variation_ID IS NULL`, `Is_Removed = 0`, `Active <> 0`, `Xero_AmountDue > 0`).
5. **Push/sync to Xero fails with 401**: token expired and auto-refresh failed. Disconnect + reconnect in menu.
6. **Email fails**: check `smtp_flash` / `xero_flash_err` on the menu page. Common causes: SMTP not connected (do OAuth flow), or the Gmail alias not verified yet.
7. **Reminders not firing despite opt-in**: check the cron's HTML report — the stale-sync guard may have aborted (Xero connection dead for >36h). Reconnect Xero, run the cron manually.
8. **"I paid that invoice yesterday why am I still being chased"**: bank-feed lag — payment cleared the bank but Xero hasn't reconciled yet. The 1-day schedule buffer absorbs most of this; the disclaimer in the email body explains it. Confirm by looking at the invoice in Xero directly.
9. **"Submission blocked" unexpectedly**: `is_employed_staff()` block — but we removed the hard block in submit.php. If it returns, check submit.php top.

---

## Akahu (bank feed) — alongside Xero on main

Akahu pulls live bank transactions and matches them to invoices. **Xero remains the books-of-record** for invoice posting/emailing/credit notes, but Akahu now has authority to flip the local `Paid` flag the moment bank evidence covers an invoice's gross amount — so the reminder cron stops chasing clients who actually paid days ago, even if no-one's reconciled in Xero yet (e.g. Erik's on holiday). The "needs reconciling in Xero" menu alert still fires on those invoices until Xero catches up, so the Xero-side action isn't forgotten.

Column ownership:
- `Invoices.Paid` + `Invoices.DatePaid` → **dual-writer**:
  - `xero_sync.php` flips Paid=1 when Xero says PAID. Sticky (CASE WHEN PAID THEN 1 ELSE Paid END — never resets).
  - `bankfeed_match.php::recompute_invoice_amount_paid()` flips Paid=1 + DatePaid=today **only** when AmountPaid ≥ gross AND DatePaid is currently NULL (= "virgin": never been touched). Guarding on DatePaid IS NULL means a manual un-tick via `invoice_edit.php` (which preserves DatePaid) won't be re-flipped.
- `Invoices.AmountPaid` → owned by Akahu (cache of `SUM(Bank_Allocations.amount)`).
- `Invoices.Xero_*` → owned by Xero.
- `Bank_Transactions` / `Bank_Allocations` → owned by Akahu.

**`Paid` is the unified "is this paid?" gate** that ALL the candidate-selection queries now respect. The query patterns below all gate on `COALESCE(Paid, 0) = 0` in addition to whatever Xero-side checks they were already doing — so the moment Akahu (or a manual tick) flips Paid=1, the invoice drops out of:
- `xero_send_reminders.php` candidate set (no more reminder emails)
- `monthly_invoicing.php` outstanding-and-overdue list
- `menu.php` "30+ days overdue, please call" callout

**Reminder cron also skips partial payments.** `xero_send_reminders.php`'s candidate filter additionally requires `AmountPaid` (Akahu) AND `Xero_AmountPaid` (Xero) to both be ~$0. The moment ANY payment is recorded against the invoice — full or partial, captured from either source — the auto-cron stops chasing. Partials surface on Erik's menu via the "💰 partial payment received" panel for manual action (send-thanks, credit shortfall) instead. Erik's per-row manual `?invoice_no=N` send still bypasses every filter so he can fire a one-off if needed. `monthly_invoicing.php`'s overdue list and the menu's 30+ overdue callout still SHOW partial-paid invoices (so Erik can see them and call) — only the auto-emailing cron skips them.

Without these changes, an Akahu-flipped Paid would have been ignored by the cron (which used to filter only on `Xero_AmountDue > 0`), and reminders would have continued chasing clients who paid days ago. With the changes, Erik's holiday scenario works: Akahu sees the bank deposit, flips Paid locally (full payment) or just records the allocation (partial), the cron stops chasing in either case, the menu shows "needs reconciling in Xero" / "partial payment received" so Erik catches up the books on his return. xero_sync's "sticky 1" means the local Paid never gets reset back when Xero hasn't yet caught up.

### Files
- `migrations/add_akahu_bankfeed.sql` — adds `Akahu_Tokens`, `Bank_Accounts`, `Bank_Transactions`, `Bank_Allocations`, and `Invoices.AmountPaid`. Idempotent.
- `akahu_client.php` — API wrapper (App Token + User Token in headers; no OAuth refresh).
- `akahu_connect.php` — token entry UI; smoke-tests on save.
- `akahu_sync.php` — pulls accounts + paginated transactions, runs `run_auto_match()` afterward. Has CLI cron + library mode.
- `bankfeed_match.php` — matcher with **liberal invoice-ref regex**. Recognises `CAD-01234`, `caD01234`, `INV-1234`, `inv 12345`, and bare `12345` (validated against actual `Invoices.Invoice_No` to suppress dates/amounts/IDs in bank descriptions). Multi-invoice in one description (`cad12345 cad6789`) walks all matches and allocates to each in order. Partial-payment-aware allocation. **Does NOT touch `Invoices.Paid`** — only writes `Invoices.AmountPaid` cache + `Bank_Allocations` rows.
- `bankfeed_reconcile.php` — manual queue UI for transactions the matcher couldn't fully resolve.
- `partial_payment_action.php` — POST handler for the menu's per-invoice action buttons (send-thanks email, credit-shortfall write-off).

### Erik/Jen menu callouts (bottom of `menu.php`)
- **🔄 N invoices may need reconciling in Xero** — Akahu sees full bank-evidence covering the invoice but Xero still has it AUTHORISED. Click into the invoice or open the reconciliation queue.
- **💰 N overdue invoices with partial payment received** — bank-evidence < gross AND past due date. Two per-row actions:
  - **✉ Send thanks** — emails the `partial_payment_thanks` template (key in `default_email_templates()`). New template added; admins can edit wording on `email_templates.php`.
  - **✂ Credit shortfall** — writes off the remaining balance. Local: appends a negative `Timesheets` row tagged "credit" to the invoice and recomputes `Subtotal`; flips local `Paid=1`. Xero: `XeroClient::postCreditNote()` + `allocateCreditNote()` post an ACCRECCREDIT credit note and allocate it against the invoice. **Xero half is UNTESTED in production** — failures are logged + surfaced to the admin so they can manually reconcile.

### Match/allocation rules (per transaction)
1. Pull description + particulars + code + reference into one blob.
2. `extract_invoice_refs($pdo, $blob)`: prefixed first (`CAD|INV` + optional dash/space), validated against Invoices. If any prefixed found, only those count. Else fall back to bare digits (validated).
3. For each ref in order, call `allocate_to_invoice()`:
   - txn = remaining → fully_matched, full allocation
   - txn < remaining → partial allocation, txn fully consumed (status fully_matched)
   - txn > remaining → allocate exactly the remaining, txn status partially_matched (leftover stays for the next ref or manual reconcile)

### Cron line
```
30 * * * * cd /home/<user>/public_html && php akahu_sync.php cron >> logs/akahu_sync.log 2>&1
```
Hourly bank-feed pull keeps `Invoices.AmountPaid` close to real-time so the menu callouts are accurate. Run this BEFORE the daily `xero_send_reminders.php` so reminders see fresh allocations.

### When to use which view
- **Reconciliation in Xero** — Erik clicks the menu callout, opens the invoice in Xero (via the Xero badge link), marks it Paid there. Next `xero_sync` run flips local `Paid=1`. Done.
- **Reconciliation in CADViz** — `bankfeed_reconcile.php` for manual allocation when the matcher couldn't figure it out (wrong reference, no reference, etc).
- **Partial-payment write-off** — menu's "✂ Credit shortfall" button. Used when client says "that's all you're getting for this one".

### The `bankfeeds-akahu` branch (Xero-replacement variant)
A separate branch (`git switch bankfeeds-akahu`) is the experimental "drop Xero entirely" cut-over. NOT smoke-tested. The on-main "alongside" version above is the production target; the branch is a possible future direction once Akahu has proven reliable. See `BANKFEEDS.md` and `SESSION_RESUME.md` on that branch for the deletion plan.
