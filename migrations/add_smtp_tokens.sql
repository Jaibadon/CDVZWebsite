-- ============================================================================
-- Smtp_Tokens — Google OAuth 2.0 (XOAUTH2) tokens for sending email via the
-- Gmail SMTP server. Singleton-ish (latest row wins). Erik authenticates once
-- as erik@cadviz.co.nz and the access_token is refreshed automatically.
--
-- Mail is then sent with the authenticated user's account but with a
-- From: accounts@cadviz.co.nz header, which Gmail allows when accounts@
-- is a verified "Send mail as" alias on Erik's Gmail.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Smtp_Tokens (
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
