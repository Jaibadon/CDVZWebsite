# Migration run order & status

This is the canonical apply order for every file in `migrations/`, plus the
**pending set you still need to run on production**.

It was produced by diffing the live production schema (the phpMyAdmin dump
`sys5e40895326_actualCVDB.sql`, server `10.5.27-MariaDB`, generated
2026-05-31 22:57) against every migration file and against the columns/tables
the PHP code reads and writes. The pending set was then **applied to a local
import of that dump and re-applied a second time** — all seven apply cleanly
(exit 0) and are idempotent (second run is a no-op, no errors).

> All migrations are designed to be **idempotent**: they guard with
> `INFORMATION_SCHEMA` look-ups, `CREATE TABLE IF NOT EXISTS`,
> `ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`, or `MODIFY` (which is a no-op when
> the column already matches). Re-running a migration that's already applied is
> safe.

## How to run

1. **Back up the database first** (phpMyAdmin → Export → SQL, or
   `mysqldump`). Always.
2. phpMyAdmin → select the database → **SQL** tab → paste the file's contents → **Go**.
3. Run the **PENDING** files below **in the listed order**. Order only matters in
   that every pending file depends on tables created by `add_dms_schema.sql`
   (already live), so just run them top-to-bottom.
4. After running, execute the **verification queries** at the bottom.

---

## PENDING on production — run these (in order)

These create the schema the Revit add-in + native commit pipeline require. Until
they are run, `api/commit_create.php` **fails on every commit** (its `INSERT INTO
Commits (… Client_Commit_Uid)` references a column that doesn't exist yet) and
the add-in **cannot authenticate** (`Staff.api_token` doesn't exist). See
`docs/DB_CONSISTENCY.md` for the full impact analysis.

> **Follow-up migration (added with the DMS code fixes):** also run
> **`add_coverage_firing_uid.sql`** — widens `Coverage_Rule_Firings.Element_Ifc_Guid`
> from `CHAR(22)` to `VARCHAR(64)` so a native Revit UniqueId (~45 chars) fits;
> without it, coverage firings on native commits error/truncate under strict SQL
> mode. Idempotent; safe to run after the seven below.

| # | File | Adds | Why it's required |
|---|------|------|-------------------|
| 1 | `add_api_tokens.sql` | `Staff.api_token` + unique index `uniq_api_token` | Add-in auth (`resolve_api_token()` in `api/_bootstrap.php`). Without it the add-in has **no** way to authenticate. |
| 2 | `add_revit_manifest.sql` | `Element_Instances`: `Element_Uid`, `Builtin_Category`, `Family`, `Workset`, `Phase_Created/Demolished`, `Loc_*`, `Facing_*`, `Hand_Flipped`, `Facing_Flipped`, `Geometry_Hash`. `Element_Parameters`: `Builtin_Key`, `Param_Group`, `Value_Num`, `Value_Type`. `Element_Relationships`: `Source_Uid`, `Target_Uid`. **Relaxes `Ifc_Guid`/`*_Ifc_Guid` to NULL.** | The `revit-native-1` path writes all of these. The NOT-NULL relax is **critical**: native rows leave `Ifc_Guid` NULL, so without it every native element insert violates NOT NULL and the commit transaction rolls back. |
| 3 | `add_commit_diffs.sql` | `Commit_Diffs`, `Commit_Diff_Params` tables | `build_and_persist_commit_diff()` writes these. (Currently caught + non-fatal, so the diff is silently lost without them.) |
| 4 | `add_keynotes_and_idempotency.sql` | `Commits.Client_Commit_Uid` + unique index `uniq_client_commit_uid`; `Commit_Keynotes` table | **The commit INSERT lists `Client_Commit_Uid` — without this column every commit (native AND legacy) fails.** The unique index also makes the offline-queue idempotency check race-safe. |
| 5 | `add_keynote_clause_map.sql` | `Keynote_Clause_Map` table; seeds `NZBC_Clauses` (INSERT IGNORE) | Keynote→building-code coverage (`run_keynote_coverage()`, `coverage_admin.php`). |
| 6 | `add_approval_policy.sql` | `Projects.approval_policy`; `Transmittal_Recipients.Is_Required`, `.Approval_Status`, `.Approval_At` | The review-and-approve-before-issue gate (`dms/approval.php`). |
| 7 | `add_drive_provisioning.sql` | `Clients.drive_folder_id` | Drive auto-provisioning grouping ("put the project under the client's folder"). |

`add_element_geometry.sql` is **already satisfied** on production (`Level_Name` +
`Bbox_*` are present) — its guards make re-running a safe no-op, so you can run
it too without harm, but it changes nothing.

---

## Full canonical order (fresh install)

For standing up a brand-new database from scratch. On the current production DB,
everything in the "Applied" groups is already in place (verified against the
dump).

### Already applied on production
**Legacy / core port**
1. `schema_upgrades.sql` — AUTO_INCREMENT on legacy INT PKs. ⚠️ Line ~75 targets `Project_Tasks.Project_Task_ID`, but the real column is `Proj_Task_ID`; that one statement errors (non-fatally — the file is "continue on error") and the AUTO_INCREMENT was set another way. Harmless on the live DB; fix the column name if you ever re-run on a fresh install.
2. `add_task_type_id_to_timesheets.sql` — `Timesheets.Task_Type_ID`
3. `add_project_variations.sql` — `Project_Variations` + `Variation_ID` cols + `Is_Removed`
4. `add_quote_status.sql` — `Projects.Quote_Status`
5. `add_quoted_rate.sql` — `Project_Tasks.Quoted_Rate`
6. `add_fixed_price_quotes.sql` — `Projects.Quote_Type/Fixed_Price/Fixed_Margin_Pct`
7. `add_job_address.sql` — `Projects.Job_Address`
8. `add_fix_assigned_to_default.sql` — drops the stray `Project_Tasks.Assigned_To` default (#58). NB: does not fix existing rows (3 remain on #58 — see `docs/DB_CONSISTENCY.md`).
9. `add_spec_categorisation.sql` — Spec sub-cat taxonomy + `Tasks_Types.Spec_Subcat_ID` tagging
10. `add_clients_contact.sql` — `Clients.Contact`
11. `add_timesheet_leave_approved.sql` — `Timesheets.Leave_Approved`
12. `fix_timesheets_hist_trigger.sql` — `Timesheet_catch` trigger uses `INSERT IGNORE` (verified live)

**Integrations**
13. `add_app_meta_and_dormancy.sql` — `App_Meta`, `Dormancy_Log`
14. `add_xero_integration.sql` — `Xero_Tokens` + `Invoices.Xero_*`
15. `add_smtp_tokens.sql` — `Smtp_Tokens`
16. `add_akahu_bankfeed.sql` — `Akahu_Tokens`, `Bank_Accounts`, `Bank_Transactions`, `Bank_Allocations`, `Invoices.AmountPaid`
17. `add_drive_oauth.sql` — `Drive_Tokens`

**DMS core**
18. `add_dms_schema.sql` — `Commits`, `Blobs`, `Commit_Blobs`, `Commit_Comments`, `Element_Instances/Parameters/Relationships`, `Project_Stakeholders`, `Coverage_Rules`, `Coverage_Rule_Firings`, `Commit_NZBC_Tags`, `NZBC_Clauses`, `Transmittals`, `Transmittal_Recipients`
19. `add_coverage_seed.sql` — seeds `NZBC_Clauses` + 10 starter `Coverage_Rules`
20. `add_element_geometry.sql` — `Element_Instances.Level_Name` + `Bbox_*` (already in `add_dms_schema` for new installs; no-op here)
21. `add_adhoc_recipients.sql` — `Transmittal_Recipients.Ad_Hoc_*` + nullable `Stakeholder_ID`

### Pending (the 7 from the table above)
22. `add_api_tokens.sql`
23. `add_revit_manifest.sql`
24. `add_commit_diffs.sql`
25. `add_keynotes_and_idempotency.sql`
26. `add_keynote_clause_map.sql`
27. `add_approval_policy.sql`
28. `add_drive_provisioning.sql`

---

## Post-run verification

Run this after applying the pending set — every value should be `1`/`YES`:

```sql
SELECT
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Staff'             AND COLUMN_NAME='api_token')          AS staff_api_token,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Element_Instances' AND COLUMN_NAME='Element_Uid')        AS element_uid,
 (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Element_Instances' AND COLUMN_NAME='Ifc_Guid')        AS ifc_guid_nullable,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Commits'           AND COLUMN_NAME='Client_Commit_Uid')  AS commit_uid,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Commits' AND INDEX_NAME='uniq_client_commit_uid')     AS commit_uid_unique_idx,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Commit_Diffs')      AS t_commit_diffs,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Commit_Keynotes')   AS t_commit_keynotes,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Keynote_Clause_Map') AS t_keynote_map,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Projects'          AND COLUMN_NAME='approval_policy')    AS approval_policy,
 (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clients'           AND COLUMN_NAME='drive_folder_id')    AS clients_drive_folder;
```

_Verified locally on an import of the 2026-05-31 dump: applying the seven pending
files turns every column above from absent → present and flips
`Ifc_Guid` to nullable; a second run produces no errors._
