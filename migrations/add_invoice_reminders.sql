-- Run after the App_Meta, Xero and Akahu migrations.
CREATE TABLE IF NOT EXISTS Client_Reminder_Policy (
    Client_ID INT PRIMARY KEY,
    mode ENUM('automatic', 'manual') NOT NULL DEFAULT 'automatic',
    grace_days SMALLINT UNSIGNED NOT NULL DEFAULT 8
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Invoice_Reminder_State (
    Invoice_No INT PRIMARY KEY,
    mode ENUM('inherit', 'backlog', 'active', 'hold', 'manual') NOT NULL DEFAULT 'inherit',
    hold_until DATE NULL,
    started_on DATE NULL,
    last_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL,
    next_send_on DATE NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Reminder_Deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Client_ID INT NOT NULL,
    recipients TEXT NOT NULL,
    invoice_numbers TEXT NOT NULL,
    advance_numbers TEXT NOT NULL,
    stage TINYINT UNSIGNED NOT NULL,
    template_key VARCHAR(64) NOT NULL,
    status ENUM('pending', 'sent', 'cancelled') NOT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    INDEX idx_reminder_delivery_client (Client_ID, status, sent_at)
) ENGINE=InnoDB;

START TRANSACTION;
INSERT IGNORE INTO App_Meta (meta_key, meta_value, updated_at)
VALUES ('invoice_reminder_cutover', NOW(), NOW());
SET @invoice_reminder_first_run = ROW_COUNT();

-- Snapshot the known overdue backlog so re-emailing an old invoice later
-- cannot quietly enroll it. The sender also checks the original cutover.
INSERT IGNORE INTO Invoice_Reminder_State (Invoice_No, mode)
SELECT Invoice_No, 'backlog'
  FROM Invoices
 WHERE @invoice_reminder_first_run = 1
   AND COALESCE(Paid, 0) = 0
   AND Xero_DueDate < CURDATE();
COMMIT;
