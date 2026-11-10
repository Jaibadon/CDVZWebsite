-- Apply after add_invoice_reminders.sql and before deploying the audited sender.
-- Editable Sent/date_sent values are deliberately not backfilled.
CREATE TABLE IF NOT EXISTS Invoice_Original_Delivery (
    Invoice_No INT PRIMARY KEY,
    Client_ID INT NOT NULL,
    xero_invoice_id VARCHAR(64) NOT NULL,
    xero_contact_id VARCHAR(64) NOT NULL,
    recipients TEXT NOT NULL,
    source ENUM('email', 'legacy') NOT NULL,
    verified_at DATETIME NOT NULL
) ENGINE=InnoDB;
