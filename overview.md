# CADViz Timesheet/Invoicing — Quick Reference for Debugging Help

This is a PHP 7.4+ app, originally Classic ASP / MSSQL, ported to PHP / MySQL. Lives at `remote.cadviz.co.nz`. Stack: PHP + PDO + MySQL + raw HTML/CSS + a little JS. **No framework, no Composer, no build step.** Everything is loose `.php` files in the web root.

---

## File map (what does what)

### Entry & auth
- **`login.php`** — login form. Tries bcrypt → MD5 → plain text → 13-char DES `crypt()` in that order; auto-upgrades to bcrypt on success. Rate-limited (5 attempts / 15 min).
- **`auth_check.php`** — included at the top of every authenticated page. Starts session, redirects to login.php if not logged in. Sets `$_SESSION['UserID']` (string login like `'erik'`, `'jen'`, `'dmitriyp'`) and `$_SESSION['Employee_id']` (int FK into Staff).
- **`logout.php`**, **`set_password.php`** (admin-only password reset; can also widen the column from VARCHAR(32) to VARCHAR(255) for bcrypt via `?fix_column=1`).
- **`db_connect.php`** — `get_db()` returns a PDO singleton. Loads `config.php`. PDO is in ERRMODE_EXCEPTION + FETCH_ASSOC mode.
- **`config.php`** — gitignored; holds DB_* and XERO_* and SMTP_* / GOOGLE_OAUTH_* constants. Sample files: `config.xero.sample.php`, `config.smtp.sample.php`.
- **`helpers.php`** — shared functions: `to_mysql_date()`, `display_date()`, `ci()` (case-insensitive row key lookup), `is_employed_staff()`, `missing_weekdays()`, `meta_get/set()`, `dormancy_sweep_run/_if_due()`.

### Timesheets (staff-facing)
- **`menu.php`** — main navigation. Different sections for admin vs staff. Shows Xero / SMTP connect status. Shows pending-variation alert + dormancy sweep banner for Erik.
- **`main.php`** — the timesheet entry page. 30 rows, 7-day grid. Task picker is a `<select>` (not free text) populated by JS from `window.PROJECT_TASKS` keyed by proj_id. Submit button turns red + adds JS confirm if `is_employed_staff()` and there are missing weekdays. Picker option value = `Proj_Task_ID`.
- **`submit.php`** — handles the timesheet POST. Wraps DELETE+INSERTs in a single transaction, output-buffers the response (avoids ERR_HTTP2_PROTOCOL_ERROR). Resolves Proj_Task_ID → Task_Type_ID + Variation_ID via Project_Tasks JOIN. Re-checks missing weekdays AFTER the insert and shows red banner if gaps remain.
- **`my_checklist.php`** — staff-facing per-project task list. `?proj_id=X` filters to one project; `?print=1` triggers auto print dialog.
- **`variation_add.php`** — staff-facing form to request an unapproved variation. Acknowledgment checkbox required.

### Projects & quote builder (admin only)
- **`projects.php`** / **`projects_archive1.php`** — list views. For non-admin, link target points to `my_checklist.php?proj_id=X` instead of `updateform_admin1.php`.
- **`updateform_admin1.php`** + **`update_admin1.php`** — admin project edit form + handler.
- **`project_new.php`** + **`create_project.php`** — new project wizard.
- **`project_stages.php`** — admin "stages/tasks/quote builder" page. Loads `$quoteStatus` (NULL/draft = free editing, 'accepted' = locked) and includes `stages_editor.php`. Has Accept Quote / Reset to Draft buttons + Print Original Quote / Quote (+variations) / Variations / Checklist links.
- **`stages_editor.php`** — shared partial that does CRUD for stages + tasks + variations. Mode-aware: in `$isAccepted=true`, original tasks become read-only (`Save Variation` button creates new task in latest unapproved variation + marks original as `Is_Removed`). Helpers: `ensureUnapprovedVariation()`, `ensureVariationStage()`. Has a global JS submit handler that stamps `scroll_y` so PRG redirects don't lose scroll position.
- **`templates.php`** + **`template_stages.php`** + **`template_apply.php`** + **`template_save.php`** — reusable stage+task templates per Project_Type.
- **`quote.php`** — printable Fee Proposal. Includes original quote stages + approved variations. `?original_only=1` hides variations. `?nomoney=1` hides prices (used by `checklist.php`). Admin-only.
- **`quote_variations.php`** — variations-only printout. Default shows ALL variations including unapproved (internal review). `?approved_only=1` for client-facing. Admin-only.
- **`checklist.php`** — wraps quote.php with `nomoney=1`.

### Invoicing
- **`invoice_list.php`** / **`invoice_archive.php`** / **`invoice_list2.php`** — invoice listings. Each row shows Xero status badge. Push to Xero / Email from CADViz buttons.
- **`invoice.php`** — single invoice preview. Read by `?Invoice_No=N`.
- **`invoice_edit.php`** — edit individual invoice line items.
- **`invoice_gen.php`** — bulk-generate invoices from unprocessed timesheet entries.
- **`unprocessed.php`** — admin view of timesheet rows where `IFNULL(Invoice_No, 0) = 0`.
- **`monthly_invoicing.php`** — Jen's "outstanding & overdue" page. Has Sync from Xero button + per-row Send Reminder button.
- **`payments.php`** + payments CRUD.

### Xero integration
- **`xero_client.php`** — `XeroClient` class. OAuth2 token storage + auto-refresh. Methods: `postInvoice()`, `getInvoicesByStatus()`, `getInvoice()`, `emailInvoice()` (don't use — see below), `ensureContact()`, `getInvoicePdf()`, `markSentToContact()`. Static helpers: `isConfigured()`, `isConnected()`, `buildAuthorizeUrl()`, `exchangeCodeAndPersist()`, `disconnect()`.
- **`xero_connect.php`** / **`xero_callback.php`** / **`xero_disconnect.php`** — OAuth flow.
- **`xero_invoice_push.php`** — POST handler. Pushes a CADViz invoice to Xero. Auto-creates Xero contact via `ensureContact()` if missing. Account code 240. Uses Timesheet rows for line items, falls back to `Invoices.Subtotal` lump-sum if none.
- **`xero_invoice_email.php`** — POST handler. Fetches PDF from Xero and emails it via `SmtpMailer` (NOT Xero's email feature, which sends from a Xero-controlled address that hits spam). Marks `SentToContact=true` in Xero after.
- **`xero_sync.php`** — pulls AUTHORISED/SUBMITTED/PAID invoices from Xero and updates local `Xero_*` columns. When Xero says PAID, also flips local `Paid=1` + `DatePaid`.
- **`xero_send_reminders.php`** — overdue-reminder script. Web (admin) + CLI (cron) modes. Schedule: 7/14/30 days past due, then every +30 after. Tracks "last sent" via `App_Meta['reminder_last_<invoice_no>']`. Spam-guard: 6-day min gap.

### Email (SMTP via Google OAuth2)
- **`smtp_oauth.php`** — `SmtpOAuth` class. Same shape as XeroClient. Tokens in `Smtp_Tokens` table.
- **`smtp_oauth_connect.php`** / **`smtp_oauth_callback.php`** — OAuth flow.
- **`smtp_mailer.php`** — `SmtpMailer::send($msg)`. Builds multipart MIME, opens socket to `smtp.gmail.com:587`, STARTTLS, AUTH XOAUTH2 with the access token. From: `accounts@cadviz.co.nz` (which is a verified send-as alias on Erik's Gmail; NOT a real mailbox — it's a Google Group containing erik@ + jen@).

### Analytics & dormancy
- **`task_analytics.php`** — actual-vs-estimated by Project_Type / Manager / Stage / Task. Median/mean/stddev/n. Chart.js (CDN). Filter: original / variations / all. Has 1-click "Apply" buttons to update Tasks_Types.Estimated_Time when the median ratio is outside ±15% over n≥3 projects.
- **`dormancy_sweep.php`** — admin page to review/undo auto-deactivations from the 5-year dormancy sweep.
- **`staff_hours.php`** — uninvoiced hours per staff (all-time).
- **`report.php`**, **`reports/*`** — assorted older reports.

### Shared CSS
- **`site.css`** — new shared mobile-friendly stylesheet. Cohesive button / card / table classes. `@media (max-width:700px)` breakpoint.
- **`global.css`** + **`global2.css`** — legacy stylesheets, still loaded for backward compat. Some pages have inline `<style>` blocks too.

---

## Schema (PASTE YOUR `SHOW CREATE TABLE` DUMPS HERE)

```sql
-- TODO: paste the structures so Haiku knows the exact columns + types

```

### Schema highlights worth memorising
- **Table names are case-sensitive** on Linux MySQL: `Projects`, `Clients`, `Invoices`, `Timesheets`, `Staff`, `Project_Stages`, `Project_Tasks`, `Project_Variations`, `Project_Types`, `Tasks_Types`, `Stage_Types`, `Spec_SubCats`, `Payments`, `Xero_Tokens`, `Smtp_Tokens`, `App_Meta`, `Dormancy_Log`.
- **PK columns frequently lacked AUTO_INCREMENT** (legacy from MSSQL). The convention in many handlers is `MAX(id)+1` before INSERT. Schema_upgrades.sql added AUTO_INCREMENT for most, but check before relying.
- **Weights default to 1.** Use `COALESCE(weight, 1)` in queries — old data has NULLs.
- **Stage weights are no longer used in calculations.** UI input was removed; existing column data is kept but multiplied by 0 effectively (we drop the multiplier in formulas).
- **`Variation_ID IS NULL`** = original quote scope. `IS NOT NULL` = belongs to a variation.
- **`Project_Tasks.Is_Removed = 1`** = soft-deleted from original quote (kept for audit). Filter with `COALESCE(Is_Removed, 0) = 0`.
- **`Projects.Quote_Status`** ENUM('draft','accepted') NULL DEFAULT NULL. NULL == draft (every existing project reads as draft until explicitly accepted).
- **`Projects.Active <> 0`** filter on lists; dormancy sweep flips this to 0 after 5 years of no activity.
- **`Invoices.Paid` (tinyint 0/1)** + **`Invoices.DatePaid` (datetime)** — `xero_sync.php` flips these when Xero says PAID.

---

## Naming conventions
- DB column names are mostly TitleCase or snake_case mixed (`Invoice_No`, `proj_id`, `JobName`, `billing_email`, `Job_Notes`). PDO is **case-sensitive on row keys**. The `ci()` helper exists for fuzzy lookup when you don't know the case.
- Form field names mostly mirror DB column names. Some accept multiple cases (e.g. `$_GET['Invoice_No'] ?? $_GET['invoice_no'] ?? 0`).
- POST actions: each handler uses a hidden `action` field + a switch/elseif chain. Multiple `<form>` per page is normal, NEVER nest forms (browsers strip the inner tag and merge fields → silent data corruption — we hit this bug with `update_variation` vs `drop_variation`).

---

## Common patterns
- **PRG redirects after POST**: `header('Location: ' . $_SERVER['REQUEST_URI']); exit;`. stages_editor.php extends this with a `scroll_y` round-trip so saves don't bounce you to the top.
- **Feature detection**: every Xero / Variation / OAuth integration starts with `try { $hasFoo = (bool)$pdo->query("SHOW COLUMNS FROM X LIKE 'Y'")->fetch(); } catch (Exception $e) {}` so the app degrades gracefully when a migration hasn't been run yet.
- **Output buffering** before any echo on POST handlers that return HTML (avoids HTTP/2 protocol errors and lets us do header() redirects after some logic).
- **Admin gate**: `if (!in_array($_SESSION['UserID'] ?? '', ['erik','jen'], true)) { http_response_code(403); die('Admin only.'); }`. Use this everywhere admin-only data is touched. NB: don't rely on session AccessLevel — older code uses it but the canonical check is the username list.
- **Flash messages**: `$_SESSION['xero_flash']` / `xero_flash_err` / `smtp_flash` — set by handlers, read + cleared by menu.php / monthly_invoicing.php.
- **Money math**: `Invoices.Subtotal` is decimal(19,4). Tax_Rate decimal(18,4) defaults to 0.1500 (15% NZ GST). Total inc. tax = `Subtotal * (1 + Tax_Rate)`.
- **Date math**: use `DateTime` + `DateInterval` for day iteration (DST-safe). Don't use `strtotime('+1 day', $t)` in loops.
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

---

## Top 10 bugs to watch for when debugging
1. **Case-sensitive table names**. `projects` won't work, must be `Projects`. Same for `Project_Types` (not `Project_types`), `Spec_SubCats` (not `Spec_Subcats`), `Tasks_Types` (not `tasks_types`).
2. **PDO row key case**. `$rs['invoice_no']` is NOT `$rs['Invoice_No']`. Use the column's exact case from `SHOW CREATE TABLE`.
3. **`SELECT *` over a JOIN drops columns** when both tables have a column with the same name (PDO FETCH_ASSOC keeps only the last). Always alias explicitly when joining Projects/Invoices/Timesheets.
4. **Nested `<form>` tags are invalid HTML**. Browsers strip the inner `<form>` opening tag and merge its fields into the outer form. Side effects: hidden `action` fields collide, last-one-wins → wrong handler runs. We hit this with Save Variation / Drop Variation. Always sibling forms, never nested.
5. **Missing AUTO_INCREMENT** on legacy PKs. If `Duplicate entry '0' for key 'PRIMARY'` errors, the column lacks AUTO_INCREMENT. Run `MAX(id)+1` manually or `ALTER TABLE … MODIFY id INT NOT NULL AUTO_INCREMENT`.
6. **Date strtotime(NULL) → epoch**. `date('d/m/Y', strtotime(null))` returns `01/01/1970`. Always null-check dates before formatting.
7. **TS_DATE comparison fails** when one side is DATE and the other DATETIME. Always `DATE_FORMAT(col, '%Y-%m-%d')`.
8. **NULL weights**. `tt.Estimated_Time * pt.Weight` returns NULL if weight is NULL. Use `COALESCE(pt.Weight, 1)`.
9. **mod_rewrite filesystem path** in Location: headers from .htaccess RewriteRule without `RewriteBase /` and a leading `/` on the substitution — produces redirects to `/home3/sys_5e4089532_6/public_html/...` instead of the URL. Already fixed; if you see this again, check `.htaccess`.
10. **Output before `header()`** kills redirects. POST handlers must `ob_start()` (or just `require_once` config files that don't echo) before any HTML.

---

## Where things live for common tasks

| Task | File |
|------|------|
| Add a new column to invoices | edit `Invoices` schema, then update SELECT + UPDATE in `invoice.php`, `invoice_edit.php`, `invoice_list.php`, `xero_invoice_push.php` |
| Change invoice number prefix | `xero_invoice_push.php:104` (`'CAD-'`) and `xero_invoice_email.php:69` |
| Tweak email reminder schedule | `xero_send_reminders.php` `$reminderStages = [7, 14, 30]` |
| Add a new POST action to stages_editor | `stages_editor.php` POST handler block (~line 35-330), then add the matching `<form>` in render section |
| Disable dormancy sweep temporarily | `meta_set($pdo, 'dormancy_last_run', date('Y-m'));` on the menu — or comment out the call in `menu.php` |
| Reset Xero connection | menu → Disconnect Xero, OR `DELETE FROM Xero_Tokens;` |
| Reset Google SMTP | menu → (if available) Disconnect, OR `DELETE FROM Smtp_Tokens;` |
| Force admin to be erik/jen only | already enforced via `in_array(..., ['erik','jen'], true)` checks; don't add to that list lightly |

---

## Don't break these
- The admin gate string list `['erik','jen']` — used in ~30 files. If the user list changes, grep them all.
- `Xero_InvoiceID` round-tripping. If a local invoice has it set, that's the binding to the Xero record. Re-pushing must `InvoiceID` it (we update, not duplicate).
- The redirect after POST pattern. Without it, refresh re-submits and you get duplicate rows.
- The `is_employed_staff()` check (currently `dmitriyp`, `hannah`). If full-time staff change, update `helpers.php`.

---

## When something's broken — quick triage
1. **White screen / 500**: check Apache error log. Likely PDO exception (read the message — usually a missing column or table name case mismatch).
2. **"Missing X"**: usually a query string param case mismatch (`Invoice_No` vs `invoice_no`).
3. **Blank table where data should be**: query is fine but returned 0 rows — check WHERE clauses (esp. `Variation_ID IS NULL`, `Is_Removed = 0`, `Active <> 0`).
4. **Push/sync to Xero fails with 401**: token expired and auto-refresh failed. Disconnect + reconnect in menu.
5. **Email fails**: check `smtp_flash` / `xero_flash_err` on the menu page. Common causes: SMTP not connected (do OAuth flow), or the Gmail alias not verified yet.
6. **"Submission blocked" unexpectedly**: `is_employed_staff()` block — but we removed the hard block in submit.php. If it returns, check submit.php top.
