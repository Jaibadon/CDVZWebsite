-- ============================================================================
-- Per-staff API token — lets the Revit add-in authenticate without a browser
-- session (the desktop equivalent of the session cookie the SPA relied on).
--
-- require_api_token() in api/_bootstrap.php matches the X-CadViz-Token header
-- against Staff.api_token and adopts that staff member's identity for the
-- request (so commit_create.php's Author_UserID + "assigned to project" check
-- work unchanged).
--
-- Tokens are issued/rotated from api_token_admin.php (admin only). A NULL
-- token means that staff member can't use the add-in yet.
--
-- Idempotent. Apply via phpMyAdmin SQL tab.
-- ============================================================================

SET @has_tok := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Staff' AND COLUMN_NAME = 'api_token'
);
SET @sql_tok := IF(@has_tok = 0,
  'ALTER TABLE Staff ADD COLUMN api_token VARCHAR(64) NULL',
  'SELECT 1');
PREPARE s FROM @sql_tok; EXECUTE s; DEALLOCATE PREPARE s;

-- Unique (so a token resolves to exactly one staff member). A functional
-- UNIQUE index tolerates multiple NULLs in MySQL, which is what we want.
SET @has_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Staff' AND INDEX_NAME = 'uniq_api_token'
);
SET @sql_idx := IF(@has_idx = 0,
  'ALTER TABLE Staff ADD UNIQUE INDEX uniq_api_token (api_token)',
  'SELECT 1');
PREPARE s FROM @sql_idx; EXECUTE s; DEALLOCATE PREPARE s;
