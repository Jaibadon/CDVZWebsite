-- ─────────────────────────────────────────────────────────────────────────
-- Project_Tasks.Quoted_Rate — frozen per-task rate snapshot
-- ─────────────────────────────────────────────────────────────────────────
-- Rationale: the quote builder (stages_editor.php / quote.php) prices
-- tasks at "(assigned staff's Billing Rate or TBA) × client.Multiplier".
-- Once a quote is accepted, that dollar value is the client's contract.
-- Reassigning a task afterwards should NOT change the price — variations
-- are how scope/price changes happen. To enforce this we snapshot the
-- per-hour rate at quote time into Project_Tasks.Quoted_Rate and use it
-- in my_checklist.php for the staff hour-budget ratio:
--
--   staff_effective_hours = quoted_hours × (Quoted_Rate / staff_rate)
--
-- so Phil at $120 picking up a TBA-quoted ($90) task does the same
-- dollar value of work in fewer hours, and a junior at $75 picking up a
-- Phil-quoted ($120) task takes more hours within the same budget.
--
-- The stored Quoted_Rate is the per-hour rate WITHOUT the client
-- multiplier (because multiplier cancels in the ratio above). If the
-- multiplier changes between quote time and now, the cancellation still
-- works as long as multiplier stays uniform across the project.

-- Idempotent ADD COLUMN guard.
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Project_Tasks'
     AND COLUMN_NAME  = 'Quoted_Rate'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE Project_Tasks ADD COLUMN Quoted_Rate DECIMAL(19,4) NULL AFTER Assigned_To',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: for every existing task row that doesn't have a Quoted_Rate
-- set, infer one from the current Assigned_To's `Billing Rate`. If the
-- task is unassigned, use the TBA rate (Staff #29). This is best-effort
-- — it assumes "the current assignment was the assignment at quote
-- time". For installs where staff have been reassigned post-acceptance
-- the snapshot won't be perfectly accurate for those rows, but it's the
-- closest reconstruction we can do without a separate audit log.
UPDATE Project_Tasks pt
LEFT JOIN Staff s_assigned ON pt.Assigned_To = s_assigned.Employee_ID
LEFT JOIN Staff s_tba      ON s_tba.Employee_ID = 29
   SET pt.Quoted_Rate = COALESCE(
         s_assigned.`Billing Rate`,
         s_tba.`Billing Rate`,
         90.00
       )
 WHERE pt.Quoted_Rate IS NULL;
