-- Projects.Job_Address — site address for the project, shown on the
-- printed Fee Proposal (quote.php uses it). Wired into project_new.php
-- + create_project.php (new) and updateform_admin1.php +
-- update_admin1.php (edit). All four pages feature-detect the column
-- via SHOW COLUMNS so they keep working on legacy installs that
-- haven't run this migration yet.
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Projects'
     AND COLUMN_NAME  = 'Job_Address'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE Projects ADD COLUMN Job_Address TEXT NULL AFTER Job_Description',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
