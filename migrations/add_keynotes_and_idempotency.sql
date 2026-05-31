-- ============================================================================
-- Keynote catalogue + offline-queue idempotency.
--
-- 1. Commits.Client_Commit_Uid — idempotency key for the Revit add-in's offline
--    queue. A queued commit retried after a network blip carries the SAME uid;
--    api/commit_create.php returns the existing commit instead of duplicating.
--
-- 2. Commit_Keynotes — per-commit snapshot of the project's Revit keynote table
--    (keynotes.txt: code → description → category). Lets the AI resolve an
--    element's Keynote parameter code to its spec meaning at that revision.
--    Per-commit (not per-project) so "what did the catalogue say at Rev C" is
--    answerable; keynote files change slowly so the duplication is cheap.
--
-- Idempotent. Apply via phpMyAdmin SQL tab.
-- ============================================================================

-- ── Commits.Client_Commit_Uid (+ unique index) ───────────────────────────
SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Commits' AND COLUMN_NAME = 'Client_Commit_Uid');
SET @sql := IF(@has = 0, 'ALTER TABLE Commits ADD COLUMN Client_Commit_Uid VARCHAR(64) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @hasi := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Commits' AND INDEX_NAME = 'uniq_client_commit_uid');
SET @sqli := IF(@hasi = 0, 'ALTER TABLE Commits ADD UNIQUE INDEX uniq_client_commit_uid (Client_Commit_Uid)', 'SELECT 1');
PREPARE s FROM @sqli; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Commit_Keynotes ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Commit_Keynotes (
    Commit_Keynote_ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID         INT NOT NULL,
    Code              VARCHAR(64) NOT NULL,        -- the keynote key used on elements
    Description       TEXT,                        -- spec text
    Category          VARCHAR(255),                -- keynote category (group)
    INDEX (Commit_ID),
    INDEX (Code),
    INDEX (Commit_ID, Code)
) ENGINE=InnoDB;
