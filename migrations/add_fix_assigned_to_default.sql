-- ============================================================================
-- Drop the legacy DEFAULT on Project_Tasks.Assigned_To.
--
-- The column carried a stray default (staff #58) from the MSSQL port. Any
-- INSERT that OMITTED Assigned_To silently got #58 — e.g. variation_add.php
-- before its fix — so genuinely-unassigned tasks showed up assigned to a
-- phantom staffer in Staff Workload, my_checklist, etc.
--
-- Dropping the default makes an omitted assignee resolve to NULL (the column is
-- nullable — stages_editor.php already inserts NULL for unassigned), which is
-- what every read path treats as "unassigned".
--
-- NOTE: this does NOT touch existing rows. Tasks already created with #58 keep
-- it; reassign those via stages_editor.php (there's no safe blanket fix because
-- a real assignment to #58 is indistinguishable from the leaked default).
--
-- Apply via phpMyAdmin SQL tab.
-- ============================================================================

ALTER TABLE Project_Tasks ALTER COLUMN Assigned_To DROP DEFAULT;
