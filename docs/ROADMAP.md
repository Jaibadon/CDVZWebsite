# CADViz DMS roadmap — toward training an AI on versioned Revit data

**North star:** accumulate a clean, labelled, version-controlled record of real
Revit projects over time, then train a model that — given a model change — predicts
the building-code clauses implicated and the stakeholders to notify (and, longer
term, flags likely coordination/compliance issues before they ship).

The schema was designed for this (vendor-neutral `category_norm`, stable
`Element_Uid` identity, flattened params with a numeric shadow, a relationship
graph, `Coverage_Rule_Firings.Disposition` as human-labelled supervision,
`Commit_NZBC_Tags.Source`+`Confidence` as weak-supervision provenance). The gap is
that **the pipeline isn't collecting data yet**, and several correctness issues
would poison the data once it does.

## Current state (honest)

- **Schema migrations are not deployed** → the commit endpoint fails and the add-in
  can't authenticate. `Commits`/`Element_Instances` are empty in prod. *(Blocker.)*
- **The add-in compiles** (all 3 TFMs, verified) but has **never been runtime-
  validated in a live Revit session**; several builder/contract gaps exist (AUDIT
  "DMS/add-in pre-launch").
- **Coverage works in principle** but seed rules likely won't fire on native commits
  (param-name matching), and the keynote→clause bridge is inert (keynote is a type
  param the builder doesn't read).

## Phase 0 — Unblock (do this first) ⭐

1. **Run the 7 pending migrations** on staging, then prod (`migrations/RUN_ORDER.md`).
   Proven idempotent against the dump. This alone turns the DMS from "non-functional"
   to "able to record a commit."
2. **Issue an `api_token`** to one pilot staffer (`api_token_admin.php`).
3. Smoke-test with the `curl` manifest harness in `TESTING_DMS.md` Step 5 — confirm a
   commit, elements, diff, and coverage rows land. *(No Revit needed.)*

## Phase 1 — Finish & validate the add-in

- **Runtime-validate in real Revit** (the one thing that can't be done off-machine):
  `ManifestBuilder` over a real model, `SheetExporter` PDF export
  (`PDFExportOptions`, Revit 2022+), `KeynotesReader` against a real
  `REVIT/KEYNOTES.txt` (UTF-16LE).
- **Derive the `.rvt` backup number** (`ManifestBuilder.cs:38-40` TODO) and **fix the
  `rvt_backup_*` contract** (AUDIT #5) so each commit pins its binary truth source.
- **Capture the per-element keynote** (read the type param — AUDIT #6) and
  `category_norm` for `BracingElement`/`Member` (AUDIT #7).
- **Fix the offline-queue 4xx data-loss** (AUDIT #12): keep 401/403/409, only drop
  genuinely-permanent 4xx.
- **Per-machine deployment + yearly Revit-version maintenance** (the add-in's new
  operational burden vs the old SPA).

## Phase 2 — Email-binding approval (#2)

Bind a magic-link approval to the recipient's email identity (today the token is the
only auth). Options: a one-time emailed code, or signed token + recorded approver
email. Tighten `dms/approval.php` so a re-notify doesn't inflate the required-approver
count (AUDIT Medium), and lock the issue-gate transition (`SELECT … FOR UPDATE`).

## Phase 3 — Mature keynote → building-code coverage

- **Match on `Builtin_Key` + `Value_Num`**, not display name + formatted string
  (AUDIT #2) — otherwise rules silently never fire.
- **Widen `Coverage_Rule_Firings.Element_Ifc_Guid`** to fit the Revit UniqueId
  (AUDIT #1) so native firings persist.
- **Deliver `notify_roles`** instead of discarding it (AUDIT #4).
- Grow `Keynote_Clause_Map` via `coverage_admin.php` (the 2-step trainer) on real
  keynote files; add **dead-rule telemetry** ("this rule has never fired") so gaps
  are visible.

## Phase 4 — Data quality & labelling for ML

- **Materialized diffs** (`Commit_Diffs`/`Commit_Diff_Params`) already exist — but
  load and store `Value_Num` so numeric deltas aren't NULL (AUDIT #3), and fix the
  `value_num` feet-vs-mm unit issue (AUDIT #8).
- **Capture model identity/version** (`manifest.project`) — currently dropped
  (AUDIT #10) — as ML provenance.
- **Identity robustness:** add a geometric/fuzzy fallback when `Element_Uid` churns
  (re-model, copy/paste), and **compare bbox/geometry** in the diff so a pure move
  isn't invisible (the memory's "highest-leverage data-quality fix").
- **Labels:** `Coverage_Rule_Firings.Disposition` defaults `pending`; build a triage
  nudge so Erik accepts/rejects firings → that's the supervised label. Backfill
  `Confidence`/`Source` discipline.
- **Adoption:** `dms_active` default 0 + no auto-import wizard → the training set is
  only new opted-in projects. Decide whether to backfill historical `.rvt`s.

## Phase 5 — Training-data export

The native manifest **is** the training schema. Build a deterministic export:
per-commit changeset (added/removed/modified + param old→new with numeric deltas) +
the labels (`Disposition`) + provenance (`Source`/`Confidence`) + the keynote→clause
edges, as JSONL keyed by `Element_Uid`. Version it with `manifest_format_version` so
a schema bump is a dataset version.

## Risks

- **Binary-truth rot:** Revit's max-backups cap (100) + no shadow-archive worker →
  old `.rvt` revisions age out; long-project full history is lost.
- **Label sparsity/bias:** without the triage nudge, `Disposition` stays `pending`
  and labels only ever exist for the narrow seeded cases.
- **Single-token Drive model + manual `drive_folder_id` pairing** → a mispaired
  folder silently breaks keynote/PDF resolution for a project.
- **Add-in maintenance** is per-Revit-version, ongoing.
- **Adoption** gates dataset size more than any algorithm choice.

## Single highest-leverage next step

**Run the 7 migrations and publish one real commit end-to-end** (Phase 0 + a single
Phase-1 runtime test). Everything else — coverage maturity, labelling, export — is
worthless until commits are actually being recorded, and a single real commit will
surface the runtime issues no amount of off-machine analysis can.
