-- ============================================================================
-- Widen Coverage_Rule_Firings.Element_Ifc_Guid so it can hold a native Revit
-- UniqueId (~45 chars), not just the 22-char IFC GlobalId.
--
-- coverage_engine.php writes COALESCE(Element_Uid, Ifc_Guid) into this column.
-- For revit-native-1 commits that value is the 45-char Revit UniqueId; under
-- MariaDB's default STRICT_TRANS_TABLES mode, inserting 45 chars into CHAR(22)
-- ERRORS and the firing is lost (the coverage step is wrapped in try/catch, so
-- it fails silently per-rule). Widening to VARCHAR(64) fits both identities.
--
-- Idempotent: only widens when the column is currently narrower than 64.
-- Additive, safe. Apply via phpMyAdmin SQL tab.
-- ============================================================================

SET @w := (
  SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Coverage_Rule_Firings'
     AND COLUMN_NAME  = 'Element_Ifc_Guid'
);
SET @sql := IF(@w IS NOT NULL AND @w < 64,
  'ALTER TABLE Coverage_Rule_Firings MODIFY Element_Ifc_Guid VARCHAR(64) DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
