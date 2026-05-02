-- ============================================================================
-- Xero OAuth 2.0 integration
-- ----------------------------------------------------------------------------
-- Singleton row in Xero_Tokens holds the org-level OAuth tokens. Erik logs in
-- once via xero_connect.php; the refresh_token lets the server mint new
-- access_tokens automatically (Xero access tokens last 30 minutes; refresh
-- tokens last 60 days from last use).
--
-- Each invoice tracks its corresponding Xero InvoiceID + sync timestamps so
-- monthly_invoicing.php can query payment / overdue status for follow-up.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Xero_Tokens (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id       VARCHAR(64) NOT NULL,    -- Xero org tenant GUID
    tenant_name     VARCHAR(255),            -- Org display name (cosmetic)
    access_token    TEXT NOT NULL,           -- ~1.5 KB JWT
    refresh_token   VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,       -- access token expiry (UTC)
    scope           TEXT,                    -- granted scopes
    connected_by    VARCHAR(100),            -- which UserID kicked off OAuth
    connected_at    DATETIME NOT NULL,
    last_refresh_at DATETIME
) ENGINE=InnoDB;

ALTER TABLE Invoices
    ADD COLUMN Xero_InvoiceID  VARCHAR(64) NULL,
    ADD COLUMN Xero_Status     VARCHAR(32) NULL,   -- DRAFT/SUBMITTED/AUTHORISED/PAID/VOIDED/DELETED
    ADD COLUMN Xero_AmountDue  DECIMAL(10,2) NULL,
    ADD COLUMN Xero_AmountPaid DECIMAL(10,2) NULL,
    ADD COLUMN Xero_DueDate    DATE NULL,
    ADD COLUMN Xero_LastSynced DATETIME NULL,
    ADD COLUMN Xero_OnlineUrl  VARCHAR(255) NULL,
    ADD INDEX idx_inv_xero (Xero_InvoiceID);

-- Useful query for Jen's monthly follow-up:
--   SELECT * FROM Invoices
--    WHERE Xero_Status = 'AUTHORISED'
--      AND Xero_AmountDue > 0
--      AND Xero_DueDate < CURDATE();
