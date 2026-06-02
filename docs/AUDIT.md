# CADViz correctness & security audit

**Scope:** the PHP app (`CDVZWebsite`) + the C# Revit add-in (`CDVZRevitAddin`),
with emphasis on money and security surfaces.

**Method.** A multi-agent finder pass produced ~56 candidate findings across the
requested dimensions (SQLi, auth/access, money math, races, null/date/encoding,
DMS logic, PHP↔C# contract, idempotency). The automated adversarial-verify stage
**hit a session rate-limit and failed**, defaulting every candidate to "refuted"
with no reasoning — so **each candidate was re-verified by hand against the real
code and against the production database** (the `sys5e40895326_actualCVDB.sql`
dump, imported into a local MariaDB 10.5). Ground truth from that DB
refuted/confirmed several candidates (noted inline). Numbers below come from that
data.

**Status legend:** ✅ FIXED on `audit-hardening` · 📝 DOCUMENTED (decide/defer) ·
🧪 DMS/add-in pre-launch (not production-active until migrations + add-in ship).

## Executive summary

| Severity | Count | Fixed | Documented |
|---|---|---|---|
| Critical | 3 | 2 | 1 (operational — run migrations) |
| High | 2 | 1 | 1 |
| Medium | 7 | 2 | 5 |
| Low | 8 | 1 | 7 |
| DMS/add-in pre-launch | 13 | 0 | 13 |

The two scariest are **public, unauthenticated data leaks** (`diagnostic.php`,
`invoice_list2.php`) — both fixed. The single biggest *operational* issue is that
**the production schema predates the Revit pipeline**, so the DMS commit endpoint
and add-in can't work until 7 migrations are run (see `docs/DB_CONSISTENCY.md`).
Money surfaces are largely sound; the real money bugs found were a lump-sum
`Subtotal` zeroing (fixed), an FY-reporting boundary (fixed), and 2 zero-multiplier
clients (data).

---

## Critical

### [Critical ✅] `diagnostic.php` — unauthenticated leak of schema + password-hash prefixes
`diagnostic.php:6-9, 84-91`. The page did `session_start()` then ran with **no
auth check at all**, dumping DB host/name, the full table list, `Staff` columns,
and **every staff `Login` + `CHAR_LENGTH(password)` + `LEFT(password,4)`**.
Anyone on the internet could fingerprint accounts and the DB.
**Why real:** verified — there is no session/admin guard before the output.
**Fix (applied):** added an `erik`/`jen` admin gate before any output. Recommend
*deleting* the file in production (its own header says "DELETE after verifying").
**Fix risk:** low.

### [Critical ✅] `invoice_list2.php` — unauthenticated dump of all unpaid invoices + AR total
`invoice_list2.php:1-3, 81-137`. `session_start()` then rendered every unpaid
invoice (amounts incl. GST, client, job) and a GRAND TOTAL with **no
`$_SESSION['UserID']` check** — fully public.
**Why real:** verified — no guard; `invoice_list.php` (the canonical view) gates
session + `erik`/`jen`.
**Fix (applied):** added the same session + admin gate.
**Fix risk:** low.

### [Critical 📝] Production schema predates the Revit pipeline (operational)
`api/commit_create.php` + 7 unrun migrations. `commit_create.php` `INSERT`s
`Commits.Client_Commit_Uid` (absent live) → every commit fails; `Staff.api_token`
absent → add-in 401s. **Verified** against the dump and proven fixable by applying
the pending migrations locally. **Fix:** run the 7 migrations
(`migrations/RUN_ORDER.md`). **Fix risk:** low (idempotent, additive, verified).

---

## High

### [High 📝] `Clients.Multiplier = 0` zeroes invoices for 2 clients
`invoice_gen.php:83` (`$rate = BillingRate × Multiplier`). `COALESCE(...,1)` only
guards NULL, not 0. **Verified on data:** 2 clients have `Multiplier = 0` → their
generated invoice lines compute `Rate = 0` → $0 billing.
**Fix (recommend):** correct the 2 clients' multipliers if unintended; add a guard
treating `0` as `1` (or reject `0` at `create_client`/`update_client`). Left
unapplied because `0` *might* be intentional for a special client — **Erik to
confirm** before code-guarding. **Fix risk:** low (data) / medium (guard could
mask an intended 0).

### [High 📝] Client-management pages lack the admin gate
`clients.php:9-13` (admin gate **commented out**), `create_client.php`,
`new_client.php`, `client_updateform.php` (session-only, no `erik`/`jen` gate;
IDOR via `?client_id`). Any authenticated staffer can list clients and
view/create/edit client records — including the commercial `Multiplier` and
billing emails. **Why real:** verified; the nav links to these are all gated to
`erik`/`jen`, implying client management is meant to be admin-only.
**Fix (recommend):** re-enable/add the `erik`/`jen` gate on all four (+ the
`update_client.php` handler). Left unapplied because re-gating **changes who can
manage clients** — confirm no staff workflow depends on it. **Fix risk:** medium
(access-policy change).

---

## Medium

### [Medium ✅] `invoice_edit.php` zeroes a lump-sum invoice's `Subtotal` on view
`invoice_edit.php:322`. `UPDATE Invoices SET Subtotal = $subtot` ran on every GET,
where `$subtot` = sum of *timesheet* lines. A lump-sum/fixed-price invoice has no
timesheet rows → `$subtot = 0` → opening it zeroed the stored Subtotal.
**Verified on data:** 1,161 invoices ($1.29M Subtotal) have a non-zero Subtotal
and no timesheet rows (all currently paid, so live exposure is low — but every
future fixed-price invoice was at risk). **Fix (applied):** guard the UPDATE with
`if (!empty($timesheets))`. **Fix risk:** low.

### [Medium ✅] `annual_overview.php` FY rollup drops 31-March timestamped invoices
`annual_overview.php:38,48,65,85,101`. `Invoices.Date` is a `timestamp`;
`BETWEEN $from AND '$year-03-31'` means `03-31 00:00:00`, dropping invoices
timestamped later on 31 Mar from the FY. **Verified on data:** 192 invoices
($297,823) are affected, all in 2003–2014 (outside the page's ~5-yr window today),
but `Date` defaults to `current_timestamp()` so any invoice **created on 31 Mar**
would be dropped at FY close. **Fix (applied):** half-open range `[from, nextFY)`.
**Fix risk:** low (reporting only).

### [Medium 📝] Weak password storage for 16 staff
`Staff.password`. **Verified on data:** of 52 staff, 9 bcrypt, **16 non-bcrypt
non-empty** (plaintext / legacy 13-char DES `crypt()`), 27 empty. `login.php`
upgrades to bcrypt on successful login, so these are stored weak until the user
next logs in. **Fix (recommend):** force-reset (or widen + rehash) the 16; confirm
the 27 "empty" rows are inactive/SSO. **Fix risk:** low.

### [Medium 📝] `dms/commit_detail.php` resend doesn't scope `transmittal_id` to the commit
`dms/commit_detail.php:65-85`. The resend handler re-emails by posted
`transmittal_id` without confirming it belongs to the URL's commit/project.
**Status:** plausible from reading; needs a live check. **Fix:** verify
`Transmittals.Commit_ID = $commitId` before resending. **Fix risk:** low.

### [Medium 📝] Approval gate counts required reviewers across *all* transmittals
`dms/approval.php:35-58` → consumed at `dms/commit_detail.php:96-102`. If the same
stakeholder is re-notified on a second transmittal, the "required approvals"
denominator grows and can permanently block issue/release. **Status:** plausible;
verify against the gate's intent. **Fix:** dedupe required reviewers by
stakeholder/email, or count only the latest transmittal. **Fix risk:** medium.

### [Medium 📝] `commit_detail.php` issue-gate read-then-update isn't locked (TOCTOU)
`dms/commit_detail.php:96-102`. Approval state is read, then `Commits.Status`
updated, without a row lock — a concurrent `request_changes` could be skipped.
**Status:** real but low-probability (single-admin issue flow). **Fix:** `SELECT …
FOR UPDATE` the commit + recount inside one transaction. **Fix risk:** medium.

### [Medium 📝] `Clients.drive_folder_id` ↔ local synced path is a manual coupling
Memory + `dms/drive_client.php` / add-in. The server uses
`Projects.drive_folder_id`; the add-in uses the local `.rvt` folder. They must
point at the same physical Drive folder, with nothing enforcing it. **Fix:** the
`keynotes_edit.php?verify=1` checklist exists — make provisioning record/verify
the pairing. **Fix risk:** low.

---

## Low (production)

- **[Low ✅] `credit_notice.php` PDO row-key case → dates print 01/01/1970.**
  Read `$rs['DATE']`/`$rs['invoice_no']`/`$ts['TS_Date']` (cols are `InvDate` /
  `Invoice_No` / `TS_DATE`). **Fixed** (correct keys + null-safe). 
- **[Low 📝] `MAX(id)+1`-without-retry family.** `variation_add.php:54,66,90`,
  `stages_editor.php:105,132,334,384,457`, `template_save.php:34,46,47`,
  `project_stages.php:62,89,118`, `quote_from_spec.php:92-93`, `staff_admin.php:67`,
  `spec_admin.php:40`. Unlike `invoice_gen`/`submit`, these compute an explicit
  `MAX+1` PK with **no retry-on-duplicate**. The PKs **do** have AUTO_INCREMENT in
  prod (verified), so a concurrent admin write yields a duplicate-key **500 +
  rollback** (no corruption). **Fix:** either drop the explicit id (let
  AUTO_INCREMENT assign) or wrap in the retry loop the other writers use. **Risk:** low.
- **[Low 📝] `schema_upgrades.sql:75` targets non-existent `Project_Task_ID`.** The
  real PK is `Proj_Task_ID`; the statement errors (non-fatally). Ground truth:
  `Proj_Task_ID` already has AUTO_INCREMENT, so the "racy inserts" consequence is
  **refuted** — but fix the column name before re-running on a fresh install.
- **[Low 📝] `partial_payment_action.php` `credit_shortfall` has no upper bound.**
  `:51-53`. Admin-only; a manual over-credit could drive `Subtotal` negative.
  **Fix:** clamp to the remaining balance.
- **[Low 📝] `xero_send_reminders.php` parses some timestamps without the ` UTC`
  suffix used elsewhere** (`:278,369` vs `:115`) — spam-gap math can skew by the
  server's TZ offset. **Fix:** parse all three consistently.
- **[Low 📝] `xero_invoice_push.php` line uses `MAX(Rate)×SUM(Hours)` per
  (proj,task,employee)** (`:88-99`) — only mis-totals if one employee logged
  *different* rates on one task in one invoice (rare; `Rate` is uniform per
  employee per invoice). Don't touch the Xero money path without a test. 
- **[Low 📝] `ts_add.php:16` reads `$_POST['staff_box_new']` with no `??` guard** —
  undefined-index warning on a malformed POST. **Fix:** `?? ''`.
- **[Low 📝] One invoice has `Date = '0000-00-00'`** (zero-date). Correct the row.

---

## DMS / add-in pre-launch 🧪

These are **not production-active** (the DMS pipeline can't run until the
migrations + add-in ship), but they will bite the AI-training data and coverage on
day one. Recommend fixing as one pre-launch hardening pass (see `docs/ROADMAP.md`).
None applied — the ingestion path can't be runtime-tested here (no Revit), and a
coherent batch is safer than piecemeal edits to not-yet-live code.

| # | Finding | Location | Fix |
|---|---|---|---|
| 1 | `Coverage_Rule_Firings.Element_Ifc_Guid` is `CHAR(22)`; native commits write a ~45-char Revit UniqueId → firing INSERT truncates (or errors under strict mode, losing the firing) | `dms/coverage_engine.php:99-104` + schema | Migration: widen to `VARCHAR(64)` or add `Element_Uid` to the firings table |
| 2 | Coverage JSON-rule param match uses display `Param_Name` + formatted `Param_Value`, ignoring `Builtin_Key`/`Value_Num` → seed rules (`Thickness`, `LoadBearing`, …) likely never fire on native commits | `dms/coverage_engine.php:175,190,369-380` | Match on `Builtin_Key`; compare `Value_Num`; author rules against real Revit param keys |
| 3 | `Commit_Diff_Params.Old_Num/New_Num` always NULL for unit-bearing params (re-parses `"140 mm"`; never loads `Value_Num`) | `dms/coverage_engine.php:144-195, 313-320` | `SELECT Value_Num` in the snapshot; use it for the numeric shadow |
| 4 | `notify_roles` (who-to-notify — the coverage system's core output) is computed then **discarded** by the caller | `coverage_engine.php:123-137` + `api/commit_create.php:414-418` | Read `$cov['notify_roles']`; surface/store as suggestions |
| 5 | `rvt_backup_number`/`filename` contract mismatch → `Rvt_Backup_Number` always NULL, `Rvt_Backup_Path` always empty | `CadVizClient.cs:42-47` (only in manifest JSON) vs `api/commit_create.php:42-44` (reads `$_POST`) | PHP: read from `$manifest['source']['rvt_backup_number'\|'rvt_path']` |
| 6 | Per-element keynote link missing: Revit Keynote is a **TYPE** param but `ReadParameters` reads only instance params → `run_keynote_coverage` gets zero codes → keynote→clause coverage inert | `ManifestBuilder.cs:147-187` → `coverage_engine.php:466-475` | Read the element's type params (≥ the Keynote param) into the manifest |
| 7 | `category_norm` never emits `BracingElement`/`Member` → the seeded bracing rule can never fire | `ManifestBuilder.cs:274-297` | Map `OST_*` structural categories to `BracingElement`/`Member` |
| 8 | `value_num` is raw `p.AsDouble()` = Revit internal **feet**, while geometry is mm → numeric shadow unit-inconsistent | `ManifestBuilder.cs:166` | Convert to display units (or document units explicitly) |
| 9 | `Units`, `Param_Group`, element `Name`, `Phase_Created/Demolished` never populated → those columns always NULL for native commits | `ManifestBuilder.cs:56-67, 155-159` (+ DTO has no element `name`) | Populate from the API; add a `name` field to `ManifestElement` |
| 10 | `manifest.project` (`revit_model_guid`, `revit_version`, `title`) is sent but never read; no column captures model identity/version | `api/commit_create.php:39, 168-185` | Add `Commits` columns + persist them (valuable ML provenance) |
| 11 | `element_id` (Revit ElementId) sent but never stored | `api/commit_create.php:225-270` | Add a column if you want session-local traceability (it's not stable) |
| 12 | Offline queue treats **all** 4xx as permanent → a rotated token (401), not-yet-assigned (403), or inactive project (409) **deletes the queued commit + its PDFs** (only offline copy lost) | `CadVizQueue.cs:105-109` + `CadVizClient.cs:71` | Treat 401/403/409 as transient (keep); only drop on 400/404/413/422 |
| 13 | `builtin_category` written into `Ifc_Entity_Type VARCHAR(50)`; long `BuiltInCategory` names could exceed 50 under strict mode | `api/commit_create.php:201-206,228` | Widen the column or truncate defensively |

Also noted (lower confidence, verify on staging): `coverage_admin.php:38` `ca_decode()`
may not handle BOM-less UTF-16LE `keynotes.txt`; `CadVizClient.cs:69` duplicate
detection is a brittle substring match on the response body;
`api/commit_create.php:144-147` parent-commit resolution is a TOCTOU (two
concurrent commits to one project could fork the chain — commits are effectively
serial per project, so low).

---

## Refuted / corrected (so nothing is hidden)

The rate-limited workflow marked all 56 "refuted" with no reasons. After manual
re-verification, these are the candidates that are **genuinely not bugs** (or whose
stated consequence is wrong):

- **"`invoice_gen` never sets `Tax_Rate` → $0 GST on a TAX INVOICE."** Refuted —
  live `Invoices.Tax_Rate DEFAULT 0.1500`, so an omitted value becomes 15%.
- **"`schema_upgrades.sql` leaves `Proj_Task_ID` non-AUTO_INCREMENT → every task
  insert is racy."** Partially refuted — the migration's column name *is* wrong
  (`Project_Task_ID`), but `Proj_Task_ID` has AUTO_INCREMENT in prod
  (`AUTO_INCREMENT=30929`), so the racy-insert consequence doesn't hold.
- **"`commit_create.php` idempotency is a TOCTOU that duplicates commits."** Mostly
  refuted *after migration*: `add_keynotes_and_idempotency.sql` adds a UNIQUE index
  on `Client_Commit_Uid`, so a concurrent duplicate's second INSERT fails → the txn
  rolls back → the retry returns the existing commit. Residue: a harmless orphan
  git object. (Before the migration it's moot — the column doesn't exist.)
- **`invoice_archive.php` "emits head before the gate."** Refuted — output buffering
  in `db_connect.php` + the gate exits before flush.
- **`credit_shortfall` "MAX(TS_ID)+1 has no retry."** Real but Low (admin-only, rare;
  clean rollback on the rare collision) — listed under Low, not dropped.
