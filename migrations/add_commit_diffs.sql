-- ============================================================================
-- Commit_Diffs — materialized per-commit changeset.
--
-- Today the element diff is computed transiently in coverage_engine.php and
-- thrown away (only Coverage_Rule_Firings survive). These tables persist the
-- full structured changeset for THREE uses at once:
--   1. the reviewer's "what changed since the last revision" magic-link view,
--   2. the staff/audit answer on commit_detail.php,
--   3. the labelled training record (added/removed/modified + param old→new).
--
-- Written by api/commit_create.php right after Element_Instances, diffing the
-- new commit against its Parent_Commit_ID by Element_Uid.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Apply via phpMyAdmin SQL tab.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Commit_Diffs (
    Diff_ID             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID           INT NOT NULL,              -- the new (child) commit
    Parent_Commit_ID    INT NULL,                  -- NULL on a project's first commit
    Element_Uid         VARCHAR(64) NOT NULL,      -- Revit UniqueId of the changed element
    Change_Type         VARCHAR(10) NOT NULL,      -- added / removed / modified
    Category            VARCHAR(50),               -- normalized category (snapshot for fast display)
    Name                VARCHAR(255),
    Changed_Param_Count INT NOT NULL DEFAULT 0,
    Geometry_Changed    TINYINT NOT NULL DEFAULT 0,  -- 1 if Geometry_Hash differed
    Created_At          DATETIME NOT NULL,
    INDEX (Commit_ID),
    INDEX (Change_Type),
    INDEX (Element_Uid),
    INDEX (Commit_ID, Change_Type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Commit_Diff_Params (
    Diff_Param_ID   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Diff_ID         INT NOT NULL,
    Param_Set       VARCHAR(100),
    Param_Name      VARCHAR(100) NOT NULL,
    Builtin_Key     VARCHAR(80),
    Old_Value       TEXT,
    New_Value       TEXT,
    Old_Num         DOUBLE NULL,                   -- numeric shadow for delta queries
    New_Num         DOUBLE NULL,
    INDEX (Diff_ID),
    INDEX (Param_Name)
) ENGINE=InnoDB;
