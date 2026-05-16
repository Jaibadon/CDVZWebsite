-- ============================================================================
-- Transmittal_Recipients: allow ad-hoc (non-stakeholder) recipients.
--
-- Originally a recipient row REQUIRED a Project_Stakeholders.Stakeholder_ID
-- (the magic token bound to a stakeholder identity). That meant manual
-- emails to arbitrary addresses (a one-off builder, a consultant not in the
-- stakeholder list) couldn't carry a working review/ack link.
--
-- This makes Stakeholder_ID nullable and adds Ad_Hoc_Email / Ad_Hoc_Name so
-- a recipient row can represent either:
--   • a stakeholder      (Stakeholder_ID set, Ad_Hoc_* NULL)
--   • an ad-hoc address  (Stakeholder_ID NULL, Ad_Hoc_Email set)
--
-- Idempotent. Run after add_dms_schema.sql. Safe to re-run.
-- ============================================================================

-- Make Stakeholder_ID nullable (was INT NOT NULL). MODIFY is idempotent —
-- re-running just re-applies the same column definition.
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Transmittal_Recipients'
     AND COLUMN_NAME  = 'Stakeholder_ID'
     AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@col = 1,
  'ALTER TABLE Transmittal_Recipients MODIFY Stakeholder_ID INT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Transmittal_Recipients'
     AND COLUMN_NAME  = 'Ad_Hoc_Email'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE Transmittal_Recipients
     ADD COLUMN Ad_Hoc_Email VARCHAR(255) NULL AFTER Stakeholder_ID,
     ADD COLUMN Ad_Hoc_Name  VARCHAR(255) NULL AFTER Ad_Hoc_Email',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
