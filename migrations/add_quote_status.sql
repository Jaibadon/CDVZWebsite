-- ============================================================================
-- Add Quote_Status flag to Projects.
-- NULL = draft (default for all existing/past projects).
-- 'accepted' = client has accepted; original-quote tasks become read-only and
--              edits/additions/removals route through the latest unapproved/draft variation.
-- ============================================================================

ALTER TABLE Projects
    ADD COLUMN Quote_Status ENUM('draft','accepted') NULL DEFAULT NULL;

-- All past projects automatically read as draft (NULL == draft in app code).
-- No backfill UPDATE needed — explicit "accepted" must be opt-in via the UI.
