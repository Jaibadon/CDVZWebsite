# Database consistency report

**Scope:** verify that every table/column the CADViz PHP code reads or writes is
created by a migration, that the migrations are idempotent, and that the **live
production schema** matches what the code expects — with particular attention to
the DMS / Revit-add-in pipeline.

**Method (ground truth):** the live production schema was taken from the
phpMyAdmin dump `sys5e40895326_actualCVDB.sql` (server `10.5.27-MariaDB`, dumped
2026-05-31 22:57). Its DDL was diffed against (a) every file in `migrations/`
and (b) the column/table names the PHP code references. The migration set was
then **imported into a local MariaDB and the pending migrations applied twice**
to confirm they close every gap and are idempotent.

---

## TL;DR — the headline

**The production database predates the entire Revit-native commit pipeline.**
Seven migrations that the current `main` code depends on have **not been run**.
Consequences, in order of severity:

1. **`api/commit_create.php` fails on _every_ commit (native and legacy).** Its
   `INSERT INTO Commits (… , Client_Commit_Uid) …` references a column that
   doesn't exist on production → SQLSTATE 42S22 → the whole transaction rolls
   back. The endpoint cannot create a commit at all right now. *(`Commits` is
   empty in the dump — 0 rows — consistent with this never having worked.)*
2. **The Revit add-in cannot authenticate.** `resolve_api_token()` queries
   `Staff.api_token`, which doesn't exist; the query throws, is caught, and
   returns "no token" → 401. There is no other auth path for the add-in.
3. **Native element/parameter/relationship writes would fail even past #1**, both
   because the native columns (`Element_Uid`, `Builtin_Key`, `Value_Num`,
   `Geometry_Hash`, `Loc_*`, `Facing_*`, `Source_Uid`/`Target_Uid`, …) don't
   exist and because `Element_Instances.Ifc_Guid` is still `NOT NULL` (native
   rows leave it null).
4. **Diffs, keynotes, the approval gate, and Drive grouping silently degrade**
   (`Commit_Diffs`, `Commit_Keynotes`, `Keynote_Clause_Map`,
   `Projects.approval_policy`, `Clients.drive_folder_id` all absent).

**Fix:** run the seven pending migrations (see
[`migrations/RUN_ORDER.md`](../migrations/RUN_ORDER.md)). Verified locally:
applying them makes the commit endpoint's inserts valid and re-running them is a
clean no-op.

The good news: **the migrations themselves are well-built** (idempotent,
guarded) and the **code is correct against the post-migration schema** — this is
an *operational deployment gap*, not a code defect, with one wrinkle (#3's
NOT-NULL relax must accompany the column adds, which `add_revit_manifest.sql`
does handle).

---

## Migration application matrix

Legend: ✅ applied on prod · ⛔ **pending** · ➖ no-op (already satisfied)

| Migration | Status | Evidence in live dump |
|---|---|---|
| `schema_upgrades.sql` | ✅ | AUTO_INCREMENT present on `Invoice_No`, `TS_ID`, `Commit_ID`, … |
| `add_task_type_id_to_timesheets.sql` | ✅ | `Timesheets.Task_Type_ID` present |
| `add_project_variations.sql` | ✅ | `Project_Variations` + `Variation_ID`/`Is_Removed` cols present |
| `add_quote_status.sql` | ✅ | `Projects.Quote_Status` enum present |
| `add_quoted_rate.sql` | ✅ | `Project_Tasks.Quoted_Rate` present |
| `add_fixed_price_quotes.sql` | ✅ | `Projects.Quote_Type/Fixed_Price/Fixed_Margin_Pct` present |
| `add_job_address.sql` | ✅ | `Projects.Job_Address` present |
| `add_fix_assigned_to_default.sql` | ✅ | `Project_Tasks.Assigned_To` default is NULL (no #58 default) |
| `add_spec_categorisation.sql` | ✅ | `ux_subcat_name` unique key + Project-Process taxonomy present |
| `add_clients_contact.sql` | ✅ | `Clients.Contact` present |
| `add_timesheet_leave_approved.sql` | ✅ | `Timesheets.Leave_Approved` present |
| `fix_timesheets_hist_trigger.sql` | ✅ | `Timesheet_catch` trigger uses `INSERT IGNORE` (verified) |
| `add_app_meta_and_dormancy.sql` | ✅ | `App_Meta`, `Dormancy_Log` present |
| `add_xero_integration.sql` | ✅ | `Xero_Tokens` + `Invoices.Xero_*` present |
| `add_smtp_tokens.sql` | ✅ | `Smtp_Tokens` present |
| `add_akahu_bankfeed.sql` | ✅ | `Akahu_Tokens`/`Bank_*`/`Invoices.AmountPaid` present |
| `add_drive_oauth.sql` | ✅ | `Drive_Tokens` present |
| `add_dms_schema.sql` | ✅ | `Commits`/`Blobs`/`Element_*`/`Transmittals`/… present |
| `add_coverage_seed.sql` | ✅ | `Coverage_Rules` seeded (AUTO_INCREMENT=11 ⇒ 10 rules), `NZBC_Clauses` populated |
| `add_element_geometry.sql` | ➖ | `Element_Instances.Level_Name`+`Bbox_*` already present |
| `add_adhoc_recipients.sql` | ✅ | `Transmittal_Recipients.Ad_Hoc_Email/Name`, nullable `Stakeholder_ID` |
| **`add_api_tokens.sql`** | ⛔ | `Staff.api_token` **absent** |
| **`add_revit_manifest.sql`** | ⛔ | `Element_Uid`/`Builtin_Key`/`Value_Num`/`Geometry_Hash`/`Loc_*`/`Facing_*`/`Source_Uid` **absent**; `Ifc_Guid` still **NOT NULL** |
| **`add_commit_diffs.sql`** | ⛔ | `Commit_Diffs`, `Commit_Diff_Params` **absent** |
| **`add_keynotes_and_idempotency.sql`** | ⛔ | `Commits.Client_Commit_Uid` **absent**; `Commit_Keynotes` **absent** |
| **`add_keynote_clause_map.sql`** | ⛔ | `Keynote_Clause_Map` **absent** |
| **`add_approval_policy.sql`** | ⛔ | `Projects.approval_policy`, `Transmittal_Recipients.Is_Required/Approval_Status/Approval_At` **absent** |
| **`add_drive_provisioning.sql`** | ⛔ | `Clients.drive_folder_id` **absent** (`Projects.drive_folder_id` is present, from `add_dms_schema`) |

## Code ⇄ schema reconciliation (post-migration)

After the pending set is applied (verified locally), every column/table the DMS
code touches exists, and the case matches:

- `api/commit_create.php` — `Commits(… Client_Commit_Uid)`, `Element_Instances`
  native columns, `Element_Parameters(Builtin_Key, Param_Group, Value_Num,
  Value_Type)`, `Element_Relationships(Source_Uid, Target_Uid)`,
  `Commit_Keynotes` — **all present**, `Ifc_Guid` **nullable** ✓
- `dms/coverage_engine.php` — `Commit_Diffs`, `Commit_Diff_Params`,
  `Commit_NZBC_Tags`, `Keynote_Clause_Map`, `Coverage_Rule_Firings` ✓
- `dms/approval.php` / `dms/transmittal_*.php` — `Projects.approval_policy`,
  `Transmittal_Recipients.Is_Required/Approval_Status/Approval_At` ✓
- `api/_bootstrap.php` — `Staff.api_token` (+ unique index) ✓

> Note on column **case**: MySQL on the Linux host is case-sensitive for table
> names and (with the table's collation) generally tolerant for column names,
> but the code and schema already agree on case for the DMS tables. The one
> latent issue is in `schema_upgrades.sql` (`Project_Task_ID` vs the real
> `Proj_Task_ID`) — non-fatal, documented in `RUN_ORDER.md`.

## Idempotency

All migrations guard their changes (`INFORMATION_SCHEMA` checks, `CREATE TABLE IF
NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`, `MODIFY`, and helper
stored procedures in `add_revit_manifest.sql` that are created and dropped within
the file). **Verified:** applying the seven pending files to a fresh import of
the production dump, then applying them a *second* time, produced exit code 0
every time with no errors — safe to re-run.

---

## Data-quality findings (from the live data)

These came out of running validation queries against the imported production
data. They are **data/reporting issues**, separate from the migration gap. The
money ones are detailed (and the code fixes recommended) in
[`docs/AUDIT.md`](AUDIT.md).

| Finding | Measured on live data | Impact |
|---|---|---|
| **FY-boundary drop** in `annual_overview.php` | **192 invoices, $297,823.12 of Subtotal** are dated 31-Mar with a non-midnight time | `Date BETWEEN '…-04-01' AND '…-03-31'` treats `'…-03-31'` as midnight, so these timestamped invoices fall out of **both** the FY they belong to and the next — silently missing from the annual overview. Fix: half-open range (`Date < '…-04-01'`) or `DATE(Date)`. |
| **`Clients.Multiplier = 0`** | **2 clients** | `invoice_gen.php` computes `Rate = BillingRate × Multiplier`; `COALESCE` only catches NULL, not 0, so these clients' generated invoice lines get **Rate 0 → $0**. Confirm intentional or correct the data; consider a guard. |
| **Weak password storage** | 52 staff: 9 bcrypt, **16 non-bcrypt non-empty** (plaintext / legacy DES), 27 empty | Stored credentials are weak until each user next logs in (`login.php` upgrades to bcrypt on success). Recommend a forced reset for the 16, and confirm the 27 "empty" accounts are inactive/SSO-only. |
| Credit notes | 82 invoices, −$116,368.91 total | Healthy — confirms the negative-Subtotal credit-note path is in real use. |
| Subtotal vs timesheet sum | 13 invoices (that have timesheets) differ by > $1 | Likely manual edits / partial credits / lump-sums; worth a one-time reconcile, not a code bug. |
| Orphans | 3 timesheets → missing project; 5 invoices → missing client; 0 tasks → missing staff | Minor referential cleanup. |
| Leaked `Assigned_To = 58` (inactive `dmitriyp2`) | 3 tasks | Exactly the residue `add_fix_assigned_to_default.sql` warned it wouldn't auto-fix. Reassign via `stages_editor.php`. |

## Recommendations

1. **Run the seven pending migrations** (back up first). This is the single
   highest-impact action — it turns the DMS/add-in pipeline from "non-functional
   on production" to "functional". See `migrations/RUN_ORDER.md`.
2. Apply the **`annual_overview.php` FY-boundary fix** (see `docs/AUDIT.md`).
3. Review the **2 zero-Multiplier clients** and the **16 weak-password staff**.
4. Tidy the small orphan/`#58` residue when convenient.
