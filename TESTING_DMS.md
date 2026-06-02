# TESTING_DMS.md — staging validation plan for the DMS / Revit add-in

There is no local PHP/MySQL on the dev box, so this is a **manual runbook to
validate the DMS end-to-end on staging** (or production-with-care). It assumes
`remote.cadviz.co.nz` or a staging clone with the same code.

> **Order matters.** Do **Step 0 (migrations)** first — until the pending
> migrations are run, `api/commit_create.php` fails on every commit and the
> add-in can't authenticate. See `migrations/RUN_ORDER.md` and
> `docs/DB_CONSISTENCY.md`.

Legend: 🟢 expected pass · 🗄️ DB check (run in phpMyAdmin) · ⚠️ gotcha.

---

## Step 0 — Apply pending migrations & confirm

1. **Back up the database** (phpMyAdmin → Export).
2. Run the 7 pending files in order (see `migrations/RUN_ORDER.md`):
   `add_api_tokens` → `add_revit_manifest` → `add_commit_diffs` →
   `add_keynotes_and_idempotency` → `add_keynote_clause_map` →
   `add_approval_policy` → `add_drive_provisioning`.
3. 🗄️ Run the verification query at the bottom of `RUN_ORDER.md` — every column
   should be present and `Ifc_Guid` nullable.

## Step 1 — Connectivity & config

- `config.php` has `DB_*`, `GOOGLE_OAUTH_CLIENT_ID/SECRET`,
  `GOOGLE_OAUTH_DRIVE_REDIRECT_URI`, and `CADVIZ_GIT_REPOS_PATH` +
  `CADVIZ_BLOB_ARCHIVE_PATH` set and writable by the web user.
- 🟢 `menu.php` loads as `erik`/`jen` with no warnings.
- ⚠️ If `CADVIZ_BLOB_ARCHIVE_PATH` is unset, PDF upload degrades to a warning
  (non-fatal) — fine for first tests, but set it before testing sheet-PDF.

## Step 2 — Connect Google Drive + provisioning settings

1. Menu → **Connect Google Drive** (`drive_oauth_connect.php`). Consent screen
   must be **Internal** so the broad `drive` scope skips Google verification.
   🗄️ `SELECT COUNT(*) FROM Drive_Tokens;` ≥ 1.
2. Menu (bottom) → set `dms_autoprovision=1`, `dms_folder_grouping=client`,
   `dms_drive_root_folder_id=<Shared Drive root>`. Set the template id:
   `App_Meta['dms_template_folder_id']` = the `_0TEMPLATE` folder id.
3. ⚠️ The add-in uses the **local synced path** (dirname of the `.rvt`), while
   the server uses `Projects.drive_folder_id`. They must point at the **same
   physical folder** — this is the manual coupling to watch.

## Step 3 — Issue an API token for the add-in

1. As `erik`/`jen`, open `api_token_admin.php`, issue a token for a staff member.
   🗄️ `SELECT Employee_ID, api_token IS NOT NULL FROM Staff WHERE api_token IS NOT NULL;`
2. Sanity-check auth without Revit (PowerShell/curl):
   ```bash
   curl -s -X POST https://STAGING/api/_bootstrap.php?action=session \
        -H "X-CadViz-Token: <TOKEN>"
   ```
   ⚠️ `_bootstrap.php?action=session` only reads the session, not the token —
   to truly exercise token auth, hit `commit_create.php` (Step 5) with the header.

## Step 4 — Provision a project from `_0TEMPLATE`

1. Create a new project (`project_new.php` → `create_project.php`) for a test
   client, `dms_active=1`.
2. 🟢 Provisioning hook clones `_0TEMPLATE` into the client's Drive folder.
   🗄️ `SELECT proj_id, drive_folder_id FROM Projects WHERE proj_id=<id>;` set.
   🗄️ `SELECT drive_folder_id FROM Clients WHERE Client_id=<cid>;` set (grouping).
3. In Drive, confirm the folder has `REVIT/`, `PDF/`, `RECEIVED/{CLIENT,ENGINEER,
   FIRE,GEOTECH,TRUSS,CCTV}`, `LODGEMENT/`, `SPEC`, `SURVEY`, … and a starter
   `.rvt` renamed to the job, plus `REVIT/KEYNOTES.txt`.
4. In `keynotes_edit.php?proj_id=<id>&verify=1`, confirm the checklist shows
   `KEYNOTES.txt` / `PDFS/` / `.rvt` present (proves `drive_folder_id` ↔ model
   folder match).

## Step 5 — Publish a commit (add-in, or simulated)

**Preferred:** from Revit with the add-in (Publish Revision). **Without Revit**,
simulate the native manifest POST (this is also the smoke test for the endpoint):

```bash
# minimal revit-native-1 manifest
cat > manifest.json <<'JSON'
{"manifest_format_version":"revit-native-1",
 "project":{"proj_id":<ID>,"revit_model_guid":"test","revit_version":"2025","title":"Test"},
 "source":{"addin_version":"0.0-test","exported_at":"2026-06-02T10:00:00+12:00","rvt_backup_number":1,"rvt_path":"REVIT/Test.0001.rvt"},
 "elements":[{"uid":"uid-wall-1","element_id":1001,"category":"Walls","category_norm":"Wall",
   "builtin_category":"OST_Walls","type_name":"Generic - 200mm","level":"Ground",
   "bbox":[0,0,0,5000,200,2400],"location":{"type":"curve","x":0,"y":0,"z":0,"x2":5000,"y2":0,"z2":0},
   "geometry_hash":"abc","parameters":[{"name":"Thickness","builtin":"WALL_ATTR_WIDTH_PARAM","value":"200","value_num":200,"type":"Length","units":"mm"}]}],
 "relationships":[],
 "keynotes":[{"code":"EW-02","description":"140mm wall as per E2/AS1","category":"External Walls"}]}
JSON

curl -s -X POST "https://STAGING/api/commit_create.php" \
  -H "X-CadViz-Token: <TOKEN>" \
  -F "proj_id=<ID>" -F "message=Test commit 1" -F "manifest_format_version=revit-native-1" \
  -F "client_commit_uid=$(uuidgen)" -F "manifest=<manifest.json"
```

🟢 Response `201` with `commit_id`, `git_sha`, `element_count:1`, `keynote_count:1`.
🗄️ `SELECT * FROM Commits WHERE Proj_ID=<ID>;` — one row, `Status='wip'`,
   `Client_Commit_Uid` set.
🗄️ `SELECT Element_Uid, Category FROM Element_Instances WHERE Commit_ID=<cid>;` —
   `uid-wall-1` / `Wall`.
🗄️ `SELECT Code FROM Commit_Keynotes WHERE Commit_ID=<cid>;` — `EW-02`.
- Confirm the git repo exists under `CADVIZ_GIT_REPOS_PATH/<proj>` and
  `project.model.json` is committed (`git -C … log --stat`).
⚠️ If you get a 500 mentioning an unknown column, a migration was missed — re-check Step 0.

## Step 6 — Diff, coverage & keynote tags (2nd commit)

1. Publish a **second** commit changing the wall's `Thickness` 200 → 140 (and add
   a window). Reuse the same `proj_id`, new `client_commit_uid`.
2. 🗄️ `SELECT Change_Type, COUNT(*) FROM Commit_Diffs WHERE Commit_ID=<c2> GROUP BY Change_Type;`
   — expect a `modified` (the wall) and `added` (the window).
3. 🗄️ `SELECT * FROM Commit_Diff_Params WHERE Diff_ID IN (…);` — Thickness
   `Old_Num=200, New_Num=140`.
4. 🗄️ `SELECT Coverage_Rule_ID, Disposition FROM Coverage_Rule_Firings WHERE Commit_ID=<c2>;`
   — the "Wall thickness changed" rule (B1) fired.
5. 🗄️ `SELECT Clause_Code, Source FROM Commit_NZBC_Tags WHERE Commit_ID=<c2>;` —
   `B1`/`rule` plus any `keynote`-sourced tags (E2 from the EW-02 keynote citing E2/AS1).
   ⚠️ Keynote→clause needs `Keynote_Clause_Map` rows — seed them in
   `coverage_admin.php` (Step 2 there) if no keynote-sourced tags appear.

## Step 7 — Transmittal: send + approve gate

1. Add stakeholders (`dms/stakeholders.php`): one `client`, one `structural`.
2. From `dms/commit_detail.php`, **send a transmittal** to them
   (`dms/transmittal_send.php`). 🗄️ `Transmittals` + `Transmittal_Recipients`
   rows; each recipient has a 64-char `Magic_Token`, `Is_Required=1`.
3. Open the magic link (`dms/transmittal_view.php?token=…`) in a private window
   (no login). 🟢 Renders the commit + PDFs without a session.
   ⚠️ Confirm the token only exposes **that** transmittal — try tampering the
   token / a different commit id; it must not leak another project's data.
4. Click **Approve** (and on a second recipient, **Request changes**).
   🗄️ `Approval_Status` = `approved` / `changes_requested`, `Approval_At` set.
5. Back on `commit_detail.php`, try **Issue** (Status → `issued`):
   - With `approval_policy='hard_external'` and a required client/council
     reviewer still pending → 🟢 blocked.
   - After all required approve → 🟢 allowed; `Commits.Status='issued'`.

## Step 8 — Offline queue + idempotency

1. With the add-in (or by replaying the Step-5 curl **twice with the same
   `client_commit_uid`**): the second call must return the **existing** commit.
   🟢 Response `duplicate:true`, same `commit_id`. 🗄️ still only one `Commits` row.
2. Add-in offline path: disconnect network, Publish → it enqueues
   (`CadVizQueue`); reconnect → **Sync Pending** flushes FIFO. 🟢 one commit per
   queued publish, queue empties; a 4xx removes the item (no infinite retry),
   a network/5xx keeps it.

## Step 9 — Analytics pages

- `analytics.php` — each tab iframe loads (staff_workload, staff_hours,
  task_analytics, monthly_invoicing, revenue_report, annual_overview).
- `annual_overview.php?fy=2025` — gross/paid/outstanding, council (staff #46)
  separated, net design revenue. 🗄️ Cross-check gross vs
  `SELECT ROUND(SUM(Subtotal),2) FROM Invoices WHERE Date>='2025-04-01' AND Date<'2026-04-01';`
  (the half-open FY fix). ⚠️ Council staffer is `Employee_ID 46` (App_Meta
  `council_fee_employee_id`).

## Step 10 — Regression smoke (don't break production)

- Generate an invoice (`invoice_gen.php`) → edit → push to Xero → confirm
  `Xero_InvoiceID` round-trips; **only `xero_sync.php` flips `Paid`**.
- Submit a timesheet (`submit.php`) → confirm TS_ID generation + HIST trigger.
- Reminder cron dry-run (`xero_send_reminders.php?test=1&test_to=…`).
- Admin gate: hit a DMS/admin page as a non-erik/jen user → 403.

---

### Cleanup / rollback
- Test commits: delete the `Commits` row (cascade Element_*/Diffs by `Commit_ID`),
  remove the test project, and `rm -rf` its git repo under `CADVIZ_GIT_REPOS_PATH`.
- Migrations are additive — no down-migration needed; they don't touch existing
  rows (except `add_fix_assigned_to_default`, already applied).
