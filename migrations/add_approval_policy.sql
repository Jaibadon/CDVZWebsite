-- ============================================================================
-- Review-and-approve-before-issue gate.
--
-- Adds:
--   • Projects.approval_policy — per-job strictness of the gate. Staff set it
--     on the project edit form. Values:
--        none          — no gate, track nothing extra
--        soft          — track approvals + nag, but never block issuing
--        hard_external — block issuing to client/council/for-construction until
--                        required approvals are in; internal review is a nudge
--                        (DEFAULT)
--        hard_always   — no transmittal of any kind until every required
--                        reviewer has approved
--   • Transmittal_Recipients approval state — whether a recipient's sign-off is
--     REQUIRED for the gate, and their decision (pending/approved/changes_requested).
--     This layers on top of the existing Acked_At/Ack_Comment columns (a passive
--     "I saw it" ack is not the same as an explicit approve / request-changes).
--
-- Idempotent. Apply via phpMyAdmin SQL tab. Additive — safe pre-go-live.
-- ============================================================================

-- ── Projects.approval_policy ──────────────────────────────────────────────
SET @has_pol := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Projects' AND COLUMN_NAME = 'approval_policy'
);
SET @sql_pol := IF(@has_pol = 0,
  "ALTER TABLE Projects ADD COLUMN approval_policy VARCHAR(20) NOT NULL DEFAULT 'hard_external' AFTER dms_active",
  'SELECT 1');
PREPARE s FROM @sql_pol; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Transmittal_Recipients: explicit approval state ───────────────────────
SET @has_req := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Transmittal_Recipients' AND COLUMN_NAME = 'Is_Required'
);
SET @sql_req := IF(@has_req = 0,
  'ALTER TABLE Transmittal_Recipients ADD COLUMN Is_Required TINYINT NOT NULL DEFAULT 0 AFTER Stakeholder_ID',
  'SELECT 1');
PREPARE s FROM @sql_req; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_as := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Transmittal_Recipients' AND COLUMN_NAME = 'Approval_Status'
);
SET @sql_as := IF(@has_as = 0,
  "ALTER TABLE Transmittal_Recipients ADD COLUMN Approval_Status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER Acked_At",
  'SELECT 1');
PREPARE s FROM @sql_as; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_aat := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Transmittal_Recipients' AND COLUMN_NAME = 'Approval_At'
);
SET @sql_aat := IF(@has_aat = 0,
  'ALTER TABLE Transmittal_Recipients ADD COLUMN Approval_At DATETIME NULL AFTER Approval_Status',
  'SELECT 1');
PREPARE s FROM @sql_aat; EXECUTE s; DEALLOCATE PREPARE s;
