# CADViz hardening — executive summary

Branch: **`audit-hardening`** (off `main`). All work committed there; nothing
pushed. Production code paths (money, auth, Xero, Akahu) were left intact except
the targeted fixes below.

## What I did

- **Mapped both codebases** and wrote the docs you asked for: `docs/ARCHITECTURE.md`,
  `docs/AUDIT.md`, `docs/DB_CONSISTENCY.md`, `migrations/RUN_ORDER.md`,
  `TESTING_DMS.md`, `docs/ROADMAP.md`, and de-staled `overview.md`.
- **Used the production dump you provided as ground truth.** I stood up a local
  MariaDB 10.5, imported the dump (8,953 invoices / 45,306 timesheets / 1,690
  projects), and **applied the pending migrations twice to prove they're clean and
  idempotent** — and ran money/security validation queries against the real data.
- **Re-verified the audit by hand.** The multi-agent finder pass produced ~56
  candidates, but its automated verify stage **hit a session rate-limit and failed**
  (marking everything "refuted" with no reasoning). I re-verified each candidate
  against the real code + the live DB, which corrected several (see AUDIT's refuted
  appendix).
- **Applied 6 high-confidence, low-risk fixes** (commits on the branch):
  `diagnostic.php` + `invoice_list2.php` gates, `invoice_edit.php` Subtotal guard,
  `annual_overview.php` FY range, `credit_notice.php` date keys.

## Top findings by severity

- **CRITICAL — fixed:** `diagnostic.php` and `invoice_list2.php` were **publicly
  reachable with no login**, leaking DB schema + staff password-hash prefixes, and
  the entire unpaid-AR ledger, respectively. Both now require an `erik`/`jen`
  session. *(Recommend deleting `diagnostic.php` outright.)*
- **CRITICAL — operational (you must act):** the **production schema predates the
  Revit pipeline**. `api/commit_create.php` fails on every commit (missing
  `Commits.Client_Commit_Uid`) and the add-in can't authenticate (missing
  `Staff.api_token`). **7 migrations are pending** — verified fixable.
- **HIGH — decide:** **2 clients have `Multiplier = 0`** → their generated invoices
  bill **$0** (no guard for 0, only NULL). Confirm intended or correct the data.
  Client-management pages (`clients.php` gate is commented out, `create_client` /
  `new_client` / `client_updateform`) lack the admin gate.
- **MEDIUM — fixed:** `invoice_edit.php` zeroed a **lump-sum invoice's Subtotal just
  by opening it** (1,161 such invoices, $1.29M, exist — all currently paid).
  `annual_overview.php` dropped 31-March-timestamped invoices from the FY.
- **MEDIUM — decide:** 16 staff have non-bcrypt/plaintext passwords (upgraded only on
  next login); a few DMS approval-gate/transmittal scoping items.
- **DMS/add-in pre-launch (13 items, documented):** coverage won't fire correctly on
  native commits (param-name matching; a `CHAR(22)` firing column too narrow for the
  45-char Revit UniqueId), the keynote→code bridge is inert (Keynote is a *type*
  param the builder doesn't read), `.rvt` backup number never recorded, and the
  offline queue **deletes a queued commit on any 4xx** (incl. recoverable 401/403/409).

## What to do next (in order)

1. **Run the 7 pending migrations** on staging then prod (`migrations/RUN_ORDER.md`)
   — this is the single highest-leverage step; it unblocks the entire DMS/add-in.
2. **Review the 2 zero-`Multiplier` clients** and the 16 weak-password staff.
3. **Decide on the client-page admin gates** (I left these unapplied because they
   change who can manage clients — your call).
4. **Merge `audit-hardening`** after reviewing the 6 fixes (all small, all commented).
5. **Then** tackle the DMS/add-in pre-launch pass + one real end-to-end commit
   (ROADMAP Phase 0–1).

## Notes

- Verification was done against a **local throwaway copy** of the dump — production
  was never touched, and the dump/credentials were kept local and never committed.
- Test artifacts to delete when done: `C:\Users\Jai\cadviz_localtest\` and
  `%TEMP%\cadviz_*.sql` / `mariadb.zip` / `php.zip`.
- Not done (needs a live Revit + the deployed schema): runtime validation of the
  add-in. Covered in ROADMAP Phase 1.
