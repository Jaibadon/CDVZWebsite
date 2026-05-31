-- ============================================================================
-- Drive auto-provisioning: a place to remember each client's Drive folder so
-- projects can be grouped under it.
--
-- Projects.drive_folder_id already exists (add_dms_schema.sql). This adds the
-- matching Clients.drive_folder_id so the provisioner can reuse a client's
-- folder for subsequent projects (the "if the client already has a folder,
-- put the project under it" rule).
--
-- The other provisioning settings (on/off, grouping mode, Drive root folder id)
-- are stored in App_Meta (no schema change) under keys:
--   dms_autoprovision        '0' | '1'
--   dms_folder_grouping       'client' | 'standalone'
--   dms_drive_root_folder_id  <Google Drive folder id of the Shared Drive root>
--
-- Idempotent. Apply via phpMyAdmin SQL tab.
-- ============================================================================

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Clients' AND COLUMN_NAME = 'drive_folder_id');
SET @sql := IF(@has = 0, 'ALTER TABLE Clients ADD COLUMN drive_folder_id VARCHAR(100) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
