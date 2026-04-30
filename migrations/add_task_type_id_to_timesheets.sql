-- ============================================================================
-- Add Task_Type_ID column to Timesheets
-- ----------------------------------------------------------------------------
-- Lets us join Timesheets directly to Tasks_Types instead of doing fragile
-- text matching on the Task column. Existing rows are backfilled best-effort
-- by case-insensitive name match.
-- ============================================================================

ALTER TABLE Timesheets
    ADD COLUMN Task_Type_ID INT NULL AFTER Task;

ALTER TABLE Timesheets
    ADD INDEX idx_ts_task_type (Task_Type_ID);

-- Best-effort backfill: match existing free-text Task to Tasks_Types.Task_Name
-- (case-insensitive, trimmed). Anything that doesn't match stays NULL.
UPDATE Timesheets ts
JOIN Tasks_Types tt
  ON LOWER(TRIM(ts.Task)) = LOWER(TRIM(tt.Task_Name))
SET ts.Task_Type_ID = tt.Task_ID
WHERE ts.Task_Type_ID IS NULL;

-- Sanity check (run manually after the above):
--   SELECT COUNT(*) AS matched, SUM(Hours) AS matched_hours FROM Timesheets WHERE Task_Type_ID IS NOT NULL;
--   SELECT COUNT(*) AS unmatched, SUM(Hours) AS unmatched_hours FROM Timesheets WHERE Task_Type_ID IS NULL;
