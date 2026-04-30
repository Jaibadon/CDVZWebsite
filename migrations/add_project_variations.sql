-- ============================================================================
-- Project Variations
-- ----------------------------------------------------------------------------
-- A "variation" is a client-requested change to the original quote: extra work
-- added (or sometimes work removed). It must be tracked separately so the
-- analytics for the original quote (actual vs estimated) aren't polluted.
--
-- After this migration:
--   * Project_Stages.Variation_ID     NULL = original stage, else belongs to variation
--   * Project_Tasks.Variation_ID      NULL = original task,  else belongs to variation
--   * Project_Tasks.Is_Removed        1 = task removed from original scope (kept for audit)
--   * Project_Tasks.Removed_In_Variation_ID  which variation removed the task
--   * Timesheets.Variation_ID         denormalized for fast filtering
--   * Timesheets.Proj_Task_ID         the specific Project_Task hours were booked against
-- ============================================================================

-- 1. Variations table ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Project_Variations (
    Variation_ID     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Proj_ID          INT NOT NULL,
    Variation_Number INT NOT NULL,           -- per-project counter: #1, #2, #3
    Title            VARCHAR(255),
    Description      TEXT,
    Status           ENUM('unapproved','draft','quoted','approved','in_progress','complete','rejected')
                       NOT NULL DEFAULT 'unapproved',
    Date_Created     DATE,
    Date_Approved    DATE,
    Approved_By      VARCHAR(100),           -- client / Erik / jen
    Created_By       INT,                    -- Staff.Employee_ID
    Quote_Amount     DECIMAL(10,2),
    Notes            TEXT,
    INDEX idx_pv_proj   (Proj_ID),
    INDEX idx_pv_status (Status)
) ENGINE=InnoDB;

-- 2. Variation FK + soft-delete columns on stages/tasks ──────────────────────
ALTER TABLE Project_Stages
    ADD COLUMN Variation_ID INT NULL,
    ADD INDEX idx_ps_variation (Variation_ID);

ALTER TABLE Project_Tasks
    ADD COLUMN Variation_ID INT NULL,
    ADD COLUMN Is_Removed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN Removed_In_Variation_ID INT NULL,
    ADD INDEX idx_pt_variation  (Variation_ID),
    ADD INDEX idx_pt_removed    (Is_Removed);

-- 3. Timesheet denormalized refs ─────────────────────────────────────────────
ALTER TABLE Timesheets
    ADD COLUMN Variation_ID INT NULL AFTER Task_Type_ID,
    ADD COLUMN Proj_Task_ID INT NULL AFTER Variation_ID,
    ADD INDEX idx_ts_variation (Variation_ID),
    ADD INDEX idx_ts_projtask  (Proj_Task_ID);

-- 4. Same for Template_* (so templates can also describe variation patterns
-- in the future — harmless if unused). Skip if columns already exist.
ALTER TABLE Template_Stages
    ADD COLUMN Variation_ID INT NULL;

ALTER TABLE Template_Tasks
    ADD COLUMN Variation_ID INT NULL,
    ADD COLUMN Is_Removed TINYINT(1) NOT NULL DEFAULT 0;

-- 5. Fix NULL weights — they should always default to 1 ──────────────────────
UPDATE Project_Tasks  SET Weight = 1 WHERE Weight IS NULL OR Weight = 0;
UPDATE Project_Stages SET Weight = 1 WHERE Weight IS NULL OR Weight = 0;
UPDATE Template_Tasks  SET Weight = 1 WHERE Weight IS NULL OR Weight = 0;
UPDATE Template_Stages SET Weight = 1 WHERE Weight IS NULL OR Weight = 0;

-- Backfill Proj_Task_ID for existing Timesheets where we can match cleanly
-- (same proj_id + same Task_Type_ID + only one such project_task exists).
UPDATE Timesheets ts
JOIN (
    SELECT pt.Proj_Task_ID, pt.Task_Type_ID, ps.Proj_ID
      FROM Project_Tasks pt
      JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID
     WHERE pt.Is_Removed = 0
) pt ON pt.Proj_ID = ts.proj_id AND pt.Task_Type_ID = ts.Task_Type_ID
SET ts.Proj_Task_ID = pt.Proj_Task_ID
WHERE ts.Proj_Task_ID IS NULL
  AND ts.Task_Type_ID IS NOT NULL;
