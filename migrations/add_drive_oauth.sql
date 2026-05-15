-- ============================================================================
-- Drive_Tokens — Google OAuth 2.0 tokens for the Drive API.
--
-- Singleton-ish (latest row wins). Erik or Jen authenticates once with the
-- 'drive' scope and the access_token refreshes automatically via the
-- refresh_token. Used by drive_client.php for server-side Drive operations:
--   • Read / write keynotes.txt in a project's Drive folder
--   • (Future) PDF proxying for magic-link stakeholder reviews
--   • (Future) Legacy-project activate-DMS folder enumeration
--
-- Shape mirrors Smtp_Tokens / Xero_Tokens exactly. Reuses the same
-- GOOGLE_OAUTH_CLIENT_ID / SECRET as SMTP — separate consent grants with
-- separate scope sets so disconnecting one doesn't break the other.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Drive_Tokens (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,    -- the authenticated Google user
    access_token    TEXT NOT NULL,
    refresh_token   VARCHAR(512) NOT NULL,
    expires_at      DATETIME NOT NULL,        -- access token expiry (UTC)
    scope           TEXT,
    connected_by    VARCHAR(100),
    connected_at    DATETIME NOT NULL,
    last_refresh_at DATETIME
) ENGINE=InnoDB;
