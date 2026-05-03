-- ─────────────────────────────────────────────────────────────────────────
-- Add Timesheets.Leave_Approved for full-time staff annual-leave entries.
--
-- Project #1435 is the ANNUAL LEAVE pseudo-project. When dmitriyp / hannah
-- book annual leave in advance against #1435, future-dated rows are
-- inserted with Leave_Approved = 0 (pending). The cell shows red on the
-- staff member's timesheet until Erik clicks "Approve" on the menu page,
-- which flips Leave_Approved = 1. Past leave / non-leave rows leave the
-- column NULL (not applicable).
--
-- Idempotent — safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `Timesheets`
  ADD COLUMN IF NOT EXISTS `Leave_Approved` TINYINT(1) NULL DEFAULT NULL
  COMMENT 'NULL = N/A; 0 = future leave pending Erik approval; 1 = approved';

-- Helpful index for the menu's "show pending leave" query.
CREATE INDEX IF NOT EXISTS idx_timesheets_leave_approved
  ON `Timesheets` (`proj_id`, `Leave_Approved`, `TS_DATE`);
