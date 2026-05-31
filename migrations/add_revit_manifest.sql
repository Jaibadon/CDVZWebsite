-- ============================================================================
-- Native-Revit-manifest columns  (manifest_format_version = "revit-native-1")
--
-- The Revit add-in reads the model directly (no IFC export) and POSTs a native
-- manifest to api/commit_create.php. This migration adds the columns that
-- manifest carries, ADDITIVELY — the existing IFC-era columns (Ifc_Guid, etc.)
-- stay in place and nullable, so:
--   • the current commit_create.php keeps working unchanged, and
--   • the async milestone IFC-export path can still populate Ifc_Guid later.
--
-- Identity moves from Ifc_Guid → Element_Uid (Revit Element.UniqueId): stable,
-- and what IFC GlobalId was derived from anyway, so element-level diffs stop
-- seeing GUID churn as spurious add+remove.
--
-- Geometry is stored as REAL numeric fields (location point/curve + facing
-- vector + flip flags), NOT folded into Geometry_Hash. The hash is ONLY a
-- cheap change-detection dirty-bit for the diff; it carries no spatial info.
-- The numeric location/facing fields + the existing Bbox_* columns + the
-- Element_Relationships graph are what let an AI reason about how elements
-- sit relative to each other (adjacency, hosting, which façade a window faces).
--
-- Idempotent: re-running is a no-op (guarded by INFORMATION_SCHEMA via the
-- helper procedures, which are created and dropped within this file).
-- Apply via phpMyAdmin SQL tab.
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS cadviz_add_col $$
CREATE PROCEDURE cadviz_add_col(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN ', p_ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

DROP PROCEDURE IF EXISTS cadviz_add_index $$
CREATE PROCEDURE cadviz_add_index(IN p_tbl VARCHAR(64), IN p_idx VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND INDEX_NAME = p_idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD INDEX `', p_idx, '` (', p_cols, ')');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

DELIMITER ;

-- ── Element_Instances: identity + Revit-native classification ─────────────
CALL cadviz_add_col('Element_Instances', 'Element_Uid',       'Element_Uid VARCHAR(64) NULL AFTER Ifc_Guid');
CALL cadviz_add_col('Element_Instances', 'Builtin_Category',  'Builtin_Category VARCHAR(80) NULL AFTER Ifc_Entity_Type');
CALL cadviz_add_col('Element_Instances', 'Family',            'Family VARCHAR(255) NULL AFTER Type_Name');
CALL cadviz_add_col('Element_Instances', 'Workset',           'Workset VARCHAR(100) NULL AFTER Level_Name');
CALL cadviz_add_col('Element_Instances', 'Phase_Created',     'Phase_Created VARCHAR(100) NULL AFTER Workset');
CALL cadviz_add_col('Element_Instances', 'Phase_Demolished',  'Phase_Demolished VARCHAR(100) NULL AFTER Phase_Created');

-- ── Element_Instances: real geometry for spatial reasoning ────────────────
-- Location: a point (insertion) OR a curve (start = Loc_*, end = Loc_End_*).
CALL cadviz_add_col('Element_Instances', 'Loc_Type',   "Loc_Type VARCHAR(10) NULL");
CALL cadviz_add_col('Element_Instances', 'Loc_X',      'Loc_X DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Loc_Y',      'Loc_Y DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Loc_Z',      'Loc_Z DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Loc_End_X',  'Loc_End_X DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Loc_End_Y',  'Loc_End_Y DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Loc_End_Z',  'Loc_End_Z DOUBLE NULL');
-- Facing/orientation normal (e.g. a wall's exterior normal, a window's facing).
CALL cadviz_add_col('Element_Instances', 'Facing_X',   'Facing_X DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Facing_Y',   'Facing_Y DOUBLE NULL');
CALL cadviz_add_col('Element_Instances', 'Facing_Z',   'Facing_Z DOUBLE NULL');
-- Revit door/window flip flags — cheap, and meaningful for "which way it opens".
CALL cadviz_add_col('Element_Instances', 'Hand_Flipped',    'Hand_Flipped TINYINT NULL');
CALL cadviz_add_col('Element_Instances', 'Facing_Flipped',  'Facing_Flipped TINYINT NULL');
-- Change-detection dirty-bit ONLY (no spatial meaning — see header).
CALL cadviz_add_col('Element_Instances', 'Geometry_Hash',   'Geometry_Hash CHAR(40) NULL');

CALL cadviz_add_index('Element_Instances', 'idx_element_uid',        'Element_Uid');
CALL cadviz_add_index('Element_Instances', 'idx_commit_element_uid', 'Commit_ID, Element_Uid');

-- ── Element_Parameters: stable keys + typed numeric shadow ────────────────
-- Builtin_Key = Revit BuiltInParameter name (stable, language-independent) so
-- coverage rules predicate reliably instead of guessing IFC PSet param names.
-- Value_Num = numeric shadow of Param_Value so "thickness 90→140" is a number,
-- not a string to re-parse at feature-extraction time.
CALL cadviz_add_col('Element_Parameters', 'Builtin_Key',  'Builtin_Key VARCHAR(80) NULL AFTER Param_Name');
CALL cadviz_add_col('Element_Parameters', 'Param_Group',  'Param_Group VARCHAR(100) NULL AFTER Builtin_Key');
CALL cadviz_add_col('Element_Parameters', 'Value_Num',    'Value_Num DOUBLE NULL AFTER Param_Value');
CALL cadviz_add_col('Element_Parameters', 'Value_Type',   'Value_Type VARCHAR(30) NULL AFTER Value_Num');

CALL cadviz_add_index('Element_Parameters', 'idx_builtin_key', 'Builtin_Key');

-- ── Element_Relationships: native UniqueId endpoints ──────────────────────
-- Mirror the existing Ifc_Guid endpoints with Element_Uid ones so the
-- relationship graph keys on the same stable identity the diff uses.
CALL cadviz_add_col('Element_Relationships', 'Source_Uid', 'Source_Uid VARCHAR(64) NULL');
CALL cadviz_add_col('Element_Relationships', 'Target_Uid', 'Target_Uid VARCHAR(64) NULL');

CALL cadviz_add_index('Element_Relationships', 'idx_rel_source_uid', 'Source_Uid');
CALL cadviz_add_index('Element_Relationships', 'idx_rel_target_uid', 'Target_Uid');

-- ── Relax the IFC-era NOT NULL identity columns ───────────────────────────
-- Native commits store identity in Element_Uid / *_Uid and leave the 22-char
-- IFC GlobalId columns NULL. Without this, a native insert violates NOT NULL.
-- MODIFY is idempotent (re-running on an already-nullable column is a no-op).
ALTER TABLE Element_Instances     MODIFY Ifc_Guid                CHAR(22) NULL;
ALTER TABLE Element_Relationships MODIFY Source_Element_Ifc_Guid CHAR(22) NULL;
ALTER TABLE Element_Relationships MODIFY Target_Element_Ifc_Guid CHAR(22) NULL;

DROP PROCEDURE IF EXISTS cadviz_add_col;
DROP PROCEDURE IF EXISTS cadviz_add_index;
