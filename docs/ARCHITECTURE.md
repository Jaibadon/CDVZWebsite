# CADViz architecture

Engineering reference for the two CADViz codebases and how they fit together.
Companion to `overview.md` (timesheet/invoicing detail), `docs/DB_CONSISTENCY.md`
(schema), `docs/AUDIT.md` (findings), and `DMS_ADDIN_PLAN.md` (the DMS spec).

## The two codebases

| Repo | What it is | Stack |
|---|---|---|
| **`CDVZWebsite`** (`remote.cadviz.co.nz`) | The web app: timesheets, quoting, invoicing, Xero, Akahu bank feed, analytics — **and** the new DMS / Revit version-control layer | PHP 7.4+ (prod runs 8.1), PDO + MySQL/MariaDB, raw HTML/CSS, no framework, no build step |
| **`CDVZRevitAddin`** (sibling repo) | A Revit add-in that reads the open model and POSTs a "native manifest" to the web app to create a DMS commit | C#/.NET, multi-targeted net48 / net8.0-windows / net10.0-windows (Revit 2023→2027) |

They meet at exactly one seam: the add-in `POST`s to
`api/commit_create.php` with the `X-CadViz-Token` header and a
`manifest_format_version: "revit-native-1"` JSON body (the **manifest contract**,
below).

## Subsystems (PHP)

- **Auth & session** — `login.php` (bcrypt→MD5→plain→DES ladder, auto-upgrades to
  bcrypt; rate-limited), `auth_check.php` (session gate included by most pages),
  `bootstrap.php` (convenience: auth_check + db_connect + helpers), `db_connect.php`
  (`get_db()` PDO singleton + output-buffering/HTTP-2 hardening), `set_password.php`.
  **Admin gate** = `in_array($_SESSION['UserID'], ['erik','jen'], true)` (canonical;
  NOT `AccessLevel`). API auth: `api/_bootstrap.php` →
  `require_session_or_token()` → `resolve_api_token()` maps the `X-CadViz-Token`
  header to `Staff.api_token`.
- **Timesheets** — `main.php` (grid), `submit.php` (transactional DELETE+INSERT;
  `TS_ID` spans `Timesheets` + `Timesheets_HIST` via the `INSERT IGNORE` AFTER-DELETE
  trigger), `ts_add/ts_update`, `approve_leave.php`.
- **Projects & quoting** — `project_new`/`create_project`, `project_stages.php` +
  `stages_editor.php` (stage/task/variation CRUD; quote builder), `quote*.php`
  (printable proposals), `quote_from_spec.php`, templates. Pricing uses two rates
  (live display rate vs frozen `Quoted_Rate`); see `overview.md`.
- **Invoicing** — `invoice_gen.php` (bulk-generate from uninvoiced timesheets;
  `Rate = BillingRate × client Multiplier` baked in here), `invoice_edit`/`invoice_update`,
  `invoice.php`/`credit_notice.php` (print), `invoice_list*.php`, `payments`.
- **Xero** — `xero_client.php` (OAuth2 + refresh), `xero_invoice_push.php`
  (timesheets → grouped Xero lines), `xero_sync.php` (**the only writer that flips
  `Invoices.Paid` from Xero**), `xero_send_reminders.php` (8/15/31/46/61-day cron),
  statements, `email_templates.php`.
- **Akahu bank feed** — `akahu_sync.php` + `bankfeed_match.php` (ref-regex + blacklist
  → `Bank_Allocations` → `Invoices.AmountPaid`; flips `Paid` only when
  `AmountPaid ≥ gross AND DatePaid IS NULL`), `bankfeed_reconcile.php`,
  `partial_payment_action.php`.
- **Email** — `smtp_oauth.php` + `smtp_mailer.php` (Gmail XOAUTH2 from
  `accounts@cadviz.co.nz`).
- **Analytics** — `analytics.php` (tabbed hub, iframes), `annual_overview.php`
  (FY money: gross / paid / outstanding, council fees = `Employee_ID 46`, net design
  revenue, NZ PAYE estimate), `task_analytics`, `staff_workload`, `staff_hours`,
  `revenue_report`.
- **DMS** — see the pipeline below.

## DMS pipeline (the Revit version-control layer)

```
Revit (add-in) ──manifest──▶ api/commit_create.php ──▶ git_repo.php (project.model.json → bare git repo)
                                     │
                                     ├─[txn] Blobs, Commits, Commit_Blobs,
                                     │        Element_Instances / _Parameters / _Relationships,
                                     │        Commit_Keynotes, PDF blobs (filesystem archive)
                                     │
                                     ├─ build_and_persist_commit_diff()  ──▶ Commit_Diffs / Commit_Diff_Params
                                     │        (diffs new vs parent commit on COALESCE(Element_Uid, Ifc_Guid))
                                     │
                                     └─ run_coverage_rules()  ──▶ Coverage_Rule_Firings + Commit_NZBC_Tags
                                              ├─ JSON rules (Coverage_Rules.Trigger_Selector)
                                              └─ run_keynote_coverage(): changed element → keynote code →
                                                 category → Keynote_Clause_Map → NZBC clause → notify-roles
```

- **Identity:** element-level diff keys on `Element_Uid` (Revit `UniqueId`) for native
  commits, falling back to the 22-char IFC `Ifc_Guid` for the legacy path —
  unified as `COALESCE(Element_Uid, Ifc_Guid)`.
- **Versioned artifact:** the native manifest JSON itself is committed to the
  project's bare git repo as `project.model.json` (git = content-addressed byte
  store; `git diff` on it is not used). The `.rvt` backup number is meant to be the
  high-fidelity pointer (see AUDIT #5 — currently not recorded).
- **Coverage** turns model changes into "which NZBC clauses to re-check + which
  stakeholders to notify." Keynote descriptions literally cite clauses
  (e.g. "…as per E2/AS1"), so **keynotes are the bridge from model element → building
  code** — this is the supervised signal the AI goal depends on.
- **Transmittals & approval:** `dms/transmittal_send.php` emails stakeholders a
  magic-link (`Transmittal_Recipients.Magic_Token`); `dms/transmittal_view.php` is the
  public token-authed review page (no login — the token *is* the auth);
  `dms/approval.php` gates the `Commits.Status` transition to a released state
  (`issued`/`for_council`/`for_construction`) per `Projects.approval_policy`.
- **Drive & provisioning:** `dms/drive_client.php` (shared Google service-token,
  Shared-Drive aware), `dms/drive_provision.php` clones the `_0TEMPLATE` folder per
  project. Keynotes live in `REVIT/KEYNOTES.txt`; `keynotes_edit.php` reads/writes it.

## The model → Drive ↔ DB coupling (and its risk)

There are **three** representations of one project's model, loosely coupled:

1. The live + backup **`.rvt` files** in a staff member's Google-Drive-synced folder
   (Revit's `.NNNN.rvt` auto-backup numbering is the intended "binary truth").
2. The **git repo** on the CADViz host holding `project.model.json` per commit.
3. The **MySQL metadata** (`Commits`, `Element_*`, diffs, coverage, transmittals).

The coupling between (1) and the DB is **manual**: the server addresses the Drive
folder by `Projects.drive_folder_id`, while the add-in addresses it by the local
synced path (the `.rvt`'s parent). Nothing enforces that these point at the same
physical folder — a coherence risk (AUDIT, Medium). `keynotes_edit.php?verify=1`
exists to spot-check the pairing.

## The PHP ↔ C# manifest contract (`revit-native-1`)

The add-in serializes a `CadVizManifest` (`src/Models/CadVizManifest.cs`) and POSTs
it as the multipart `manifest` field; `api/commit_create.php` parses it. Wire keys
(snake_case, locked by `[JsonPropertyName]`):

```jsonc
{
  "manifest_format_version": "revit-native-1",
  "project":  { "proj_id", "revit_model_guid", "revit_version", "title" },
  "source":   { "addin_version", "exported_at", "rvt_backup_number", "rvt_path" },
  "elements": [ {
     "uid",            // Revit UniqueId — the diff identity
     "element_id", "category", "category_norm", "builtin_category",
     "family", "type_name", "level", "workset", "phase_created", "phase_demolished",
     "bbox":[6], "location":{type,x,y,z,x2?,y2?,z2?}, "facing":{x,y,z},
     "hand_flipped", "facing_flipped", "geometry_hash",
     "parameters":[ {name, builtin, group, value, value_num, type, units} ]
  } ],
  "relationships": [ {source_uid, target_uid, type} ],   // hosts|contains|bounds|references|connects_to
  "keynotes":      [ {code, description, category} ]
}
```
Plus multipart fields the **server** reads directly: `proj_id`, `message`,
`revision_label`, `manifest_format_version`, `client_commit_uid` (offline-queue
idempotency key), `pdf[]` (drawing PDFs).

**Contract gaps found** (see `docs/AUDIT.md` "DMS/add-in pre-launch"): the server
reads `rvt_backup_number`/`rvt_backup_filename` from `$_POST` but the add-in only
puts them in `source.*` (→ never recorded); `project.*` and `element_id` are sent
but not read/stored; the per-element keynote code isn't emitted (it's a *type*
parameter the builder doesn't read). These matter because **the manifest is also
the AI training record** — bump `manifest_format_version` for breaking changes.

## Conventions that bite (the short list)

- Table/column names are **case-sensitive** on the Linux host; PDO `FETCH_ASSOC`
  keys must match the column case exactly (the `$rs['DATE']`→`InvDate` class of bug).
- `Staff` has space-named columns: `` `First Name` ``, `` `Last Name` ``, `` `Billing Rate` ``.
- Most PKs **do** have AUTO_INCREMENT in prod, but several writers still compute an
  explicit `MAX(id)+1` (some without retry — see AUDIT Low).
- Output buffering in `db_connect.php` is mandatory (HTTP/2 fix) — never `ob_end_flush()` mid-page.
- `Invoices.Date` is a `timestamp` (not a date) — half-open ranges for FY math.
- Money: `Subtotal decimal(19,4)`, `Tax_Rate decimal(18,4) DEFAULT 0.1500` (15% GST),
  total = `Subtotal × (1 + Tax_Rate)`.
