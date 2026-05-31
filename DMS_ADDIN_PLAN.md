# CADViz DMS — Revit add-in cut-over & review/approval plan

Status: **in progress.** This supersedes the SPA path (the `cadviz-bim`
web-ifc front-end is permanently lost and will not be rebuilt).

Done so far (branch `dms-revit-addin`):
- ✅ Schema migrations written — `migrations/add_revit_manifest.sql`,
  `add_commit_diffs.sql`, `add_approval_policy.sql` (all additive + idempotent;
  no DMS data is live yet, so they're safe to apply any time).
- ✅ DMS file carve — 12 files moved to `dms/`; includes, asset links, nav
  links, magic-link URLs, and all inbound references fixed. `bootstrap.php`
  added. OAuth-flow pages kept in root (external redirect-URI contract).
- ✅ C# add-in repo scaffolded at `../CDVZRevitAddin` (README, manifest schema
  doc, DTO classes; `.csproj` + Revit extraction pending target Revit version).

Pending: native-manifest path in `commit_create.php` + diff persistence; the
magic-link approve gate; the Revit-API extraction in the add-in.

## Decisions locked in
1. **Versioned artifact = native JSON manifest** (primary). `.rvt` backup
   number/path kept as the high-fidelity pointer. IFC export becomes
   **async, milestone-only** (for neutral archival when handing a model to an
   external consultant) — never on the commit hot path.
2. **A Revit add-in (C#/.NET) replaces the SPA.** It reads the native model
   (no IFC export wait), produces the manifest, and POSTs to the existing
   `api/commit_create.php`.
3. **Magic-link "review & approve before issue"** gate, with **per-project
   configurable strictness** (`Projects.approval_policy`).
4. **File reorg = DMS carve + shared bootstrap only.** Legacy invoicing files
   stay put.

---

## 1. Native manifest schema  (`manifest_format_version: "revit-native-1"`)

Designed so (a) the add-in can emit it straight from the Revit API, (b) the
existing `commit_create.php` element-writer barely changes (same
`elements[]` / `relationships[]` shape), and (c) it doubles as the training
record.

```jsonc
{
  "manifest_format_version": "revit-native-1",
  "project":  { "proj_id": 123, "revit_model_guid": "…", "revit_version": "2024", "title": "…" },
  "source":   { "addin_version": "1.0.0", "exported_at": "2026-05-31T10:00:00+12:00",
                "rvt_backup_number": 42, "rvt_path": "…/Project.0042.rvt" },
  "elements": [
    {
      "uid":            "<Revit UniqueId>",   // STABLE identity — replaces ifc_guid for diffing
      "element_id":     348201,               // Revit ElementId — info only, NOT stable across sessions
      "category":       "Walls",              // raw Revit Category.Name
      "category_norm":  "Wall",               // normalized → what Coverage_Rules predicate on (unchanged)
      "builtin_category":"OST_Walls",
      "family":         "Basic Wall",
      "type_name":      "Generic - 200mm",
      "level":          "Ground Floor",
      "workset":        "Shell",
      "phase_created":  "New Construction",
      "phase_demolished": null,
      "bbox":           [minX,minY,minZ,maxX,maxY,maxZ],   // model units (mm)
      "geometry_hash":  "<sha1 of key dims / tessellation>", // cheap geometric-change detection
      "parameters": [
        { "name":"Structural", "builtin":"WALL_STRUCTURAL_SIGNIFICANT",
          "group":"Construction", "value":"true", "type":"YesNo",
          "value_num": 1, "units": null }
      ]
    }
  ],
  "relationships": [
    { "source_uid":"…", "target_uid":"…", "type":"hosts|contains|bounds|references|connects_to" }
  ]
}
```

Why these fields (each fixes a flagged gap):
- **`uid` (Revit UniqueId)** is stable and is what IFC GlobalId was derived
  from anyway → kills the GUID-churn / spurious add+remove problem.
- **`category_norm`** keeps `Coverage_Rules` working unchanged.
- **`parameters[].builtin` + `value_num`** → stable, language-independent
  param keys + a typed numeric shadow (fixes param-name brittleness + the
  all-TEXT storage problem for feature extraction).
- **`geometry_hash`** → detect "wall moved" even when params are identical
  (today's diff can't see pure geometric change).

### Schema migration needed
- `Element_Instances`: add `Element_Uid VARCHAR(64)` (indexed); keep
  `Ifc_Guid` nullable for milestone IFC exports. Diff keys on `Element_Uid`.
- `Element_Parameters`: add `Builtin_Key VARCHAR(80) NULL`, `Value_Num DOUBLE NULL`.
- `Element_Relationships`: add `Source_Uid` / `Target_Uid VARCHAR(64)`
  alongside the existing IFC-guid columns.

---

## 2. Persist the diff  (feeds the review view AND the AI)

Today the diff is computed transiently in `coverage_engine.php` and discarded.
Materialize it at commit time:

```sql
CREATE TABLE Commit_Diffs (
  Diff_ID            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  Commit_ID          INT NOT NULL,
  Parent_Commit_ID   INT NULL,
  Element_Uid        VARCHAR(64) NOT NULL,
  Change_Type        VARCHAR(10) NOT NULL,   -- added / removed / modified
  Category           VARCHAR(50),
  Name               VARCHAR(255),
  Changed_Param_Count INT NOT NULL DEFAULT 0,
  Geometry_Changed   TINYINT NOT NULL DEFAULT 0,
  Created_At         DATETIME NOT NULL,
  INDEX (Commit_ID), INDEX (Change_Type), INDEX (Element_Uid)
) ENGINE=InnoDB;

CREATE TABLE Commit_Diff_Params (
  Diff_Param_ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  Diff_ID       INT NOT NULL,
  Param_Set     VARCHAR(100),
  Param_Name    VARCHAR(100) NOT NULL,
  Old_Value     TEXT, New_Value TEXT,
  Old_Num       DOUBLE NULL, New_Num DOUBLE NULL,
  INDEX (Diff_ID)
) ENGINE=InnoDB;
```

This is the reviewer's "what changed" view, the audit answer, and the
labelled changeset for training — one artifact, three uses.

---

## 3. Review & approve before issue

Flow: **commit (status `wip`/`for_review`) → staff pick reviewers → magic
links emailed → reviewer views diff/PDF, clicks Approve / Request changes →
issue is gated on required approvals.**

Reuses existing machinery: `Transmittal_Recipients` magic tokens + view
tracking, `Commit_Comments`, the sha256 PDF streamer, `Commits.Status`
(`for_review` already in the enum).

Additions:
- `Transmittal_Recipients`: `Is_Required TINYINT`, `Approval_Status
  VARCHAR(20)` (pending/approved/changes_requested), `Approval_At DATETIME`.
- `transmittal_view.php` (the magic-link page): add a diff section (from
  `Commit_Diffs`) + Approve / Request-changes buttons (token = auth, log IP).

### Per-job configurable gate
```sql
ALTER TABLE Projects ADD COLUMN approval_policy
  VARCHAR(20) NOT NULL DEFAULT 'hard_external';
```
| Policy | Behaviour |
|---|---|
| `none` | No gate. Track nothing extra. |
| `soft` | Track approvals + nudge, but never block issuing. |
| `hard_external` | Block issuing to **client / council / for-construction** until required approvals are in; internal review is a soft nudge. (Default.) |
| `hard_always` | No transmittal of any kind until every required reviewer approves. |

Staff set it on the project edit form (`updateform_admin1.php`).

### Driving adoption (so the links actually get used)
- **Gate is the workflow:** for gated jobs, the *only* way to mark a revision
  Issued/For-Construction/For-Council is through the system — no parallel
  "just email a PDF" path.
- **One-click send:** the add-in, post-publish, deep-links to the review-send
  page with reviewers pre-filled from `Project_Stakeholders` + coverage
  engine's suggested roles.
- **Menu callouts** (reuse the overdue-invoice pattern): "🔎 N revisions
  awaiting your approval" / "⏳ N revisions you published are unapproved."

---

## 4. Backend changes

- **`api/commit_create.php`**: branch on `manifest_format_version`; read
  `uid` instead of `ifc_guid` for `revit-native-1`; commit
  `project.model.json` (not `project.ifc`) to git; after the element write +
  coverage, compute & persist `Commit_Diffs`/`Commit_Diff_Params`.
- **`git_repo.php`**: no real change — `commitFile()` already takes an
  arbitrary `pathInRepo`; just pass `project.model.json`. git diff on JSON is
  now meaningful (unlike IFC).
- **Auth**: add `require_api_token()` alongside `require_session()` in
  `api/_bootstrap.php` (the add-in has no browser session). Per-staff API key
  is simplest; OAuth device flow if we want revocation/rotation.
- **Coverage rules**: re-key seed selectors from assumed IFC PSet names to
  `category_norm` + `builtin` param keys so they actually fire.

---

## 5. File reorg (DMS carve + bootstrap)
- Add `bootstrap.php` (root) = `db_connect` + `auth_check` + `helpers` in one
  include. Zero risk to existing files.
- Move the DMS subsystem into `dms/` (they already use `__DIR__` includes):
  `commit_history`, `commit_detail`, `commit_pdf_helper`, `coverage_engine`,
  `git_repo`, `drive_*`, `keynotes_*`, `transmittal_*`, `stakeholders`.
  Fix the handful of cross-links between them; `api/` stays put.
- **Legacy invoicing/timesheet files stay in root** (fragile bare-relative
  links — moving them buys cosmetics for real regression risk).
- Do it on a branch, verify includes/links per file, test before merge.

---

## Build sequence (recommended)
1. **Schema migrations** (`add_revit_manifest.sql`, `add_commit_diffs.sql`,
   `add_approval_policy.sql`) — additive, idempotent, safe on the live DB.
2. **`commit_create.php` native-manifest path + diff persistence** — backend
   ready before the add-in exists; testable with a hand-crafted manifest.
3. **Magic-link approve + per-job gate.**
4. **DMS file carve + bootstrap** (branch).
5. **Revit add-in** (separate C#/.NET project/repo) — POSTs the manifest.
   Can be built in parallel once step 1's schema is fixed.
6. *(later)* async milestone IFC export; firing-disposition triage UI for
   training labels.
