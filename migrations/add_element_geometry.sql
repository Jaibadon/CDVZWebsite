-- ============================================================================
-- Element_Instances: add Level_Name + axis-aligned bounding-box columns.
--
-- Use this on hosts where add_dms_schema.sql has already been run with the
-- earlier (pre-geometry) Element_Instances schema. New deployments get the
-- columns directly from add_dms_schema.sql and can skip this file.
--
-- Idempotent — re-running is safe.
-- ============================================================================

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Element_Instances'
     AND COLUMN_NAME  = 'Level_Name'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE Element_Instances ADD COLUMN Level_Name VARCHAR(100) NULL AFTER Type_Name',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Element_Instances'
     AND COLUMN_NAME  = 'Bbox_Min_X'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE Element_Instances ADD COLUMN Bbox_Min_X DOUBLE NULL, ADD COLUMN Bbox_Min_Y DOUBLE NULL, ADD COLUMN Bbox_Min_Z DOUBLE NULL, ADD COLUMN Bbox_Max_X DOUBLE NULL, ADD COLUMN Bbox_Max_Y DOUBLE NULL, ADD COLUMN Bbox_Max_Z DOUBLE NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Composite index for "find elements on this level in this commit" queries —
-- coverage rules like "external walls on Level 02 changed" need this.
SET @idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Element_Instances'
     AND INDEX_NAME   = 'Commit_ID_Level'
);
SET @sql := IF(@idx = 0,
  'CREATE INDEX Commit_ID_Level ON Element_Instances (Commit_ID, Level_Name)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
