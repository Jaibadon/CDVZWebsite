-- ─────────────────────────────────────────────────────────────────────────
-- Akahu bank-feed integration (alongside Xero — main branch)
-- ─────────────────────────────────────────────────────────────────────────
-- Apply via phpMyAdmin SQL tab. All statements are idempotent-ish — they
-- skip silently when the object already exists (we use IF NOT EXISTS or
-- check via INFORMATION_SCHEMA where MySQL allows it).
--
-- Role on this branch: Akahu pulls bank transactions and matches them
-- to invoices to PROVIDE EVIDENCE that a payment cleared the bank.
-- Xero is still the system of record for Invoices.Paid + DatePaid;
-- xero_sync.php still owns those columns. The new column added here,
-- Invoices.AmountPaid, is a cache of SUM(Bank_Allocations.amount) per
-- invoice — used to flag the discrepancy "bank says paid but Xero
-- hasn't reconciled yet" on Erik/Jen's menu.
--
-- After applying:
--   1. Visit /akahu_connect.php as erik or jen, paste in your App Token
--      and User Token from https://genie.akahu.io
--   2. Visit /akahu_sync.php (or run the cron — see BANKFEEDS.md)
--   3. Visit /bankfeed_reconcile.php to review auto-matched suggestions
--   4. Erik/Jen's menu.php will show a "needs reconciling in Xero"
--      panel when bank evidence shows payment but Xero still says
--      AUTHORISED.

-- ── Akahu API tokens (singleton row id=1) ────────────────────────────────
CREATE TABLE IF NOT EXISTS Akahu_Tokens (
    id              INT          NOT NULL DEFAULT 1,
    app_token       VARCHAR(255) NOT NULL,
    user_token      VARCHAR(255) NOT NULL,
    last_synced_at  DATETIME     NULL,
    last_error      TEXT         NULL,
    connected_at    DATETIME     NULL,
    connected_by    VARCHAR(100) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Bank accounts known to Akahu ─────────────────────────────────────────
-- Populated by akahu_sync.php from GET /accounts.
-- is_default = 1 marks the account we treat as the receivables account
-- (Westpac business chequing, in Erik's case). Only credits to the default
-- account are auto-matched against invoices.
CREATE TABLE IF NOT EXISTS Bank_Accounts (
    akahu_id          VARCHAR(64)  NOT NULL,
    name              VARCHAR(255) NULL,
    type              VARCHAR(50)  NULL,
    formatted_account VARCHAR(100) NULL,
    bank              VARCHAR(100) NULL,
    currency          VARCHAR(10)  NOT NULL DEFAULT 'NZD',
    last_synced_at    DATETIME     NULL,
    is_default        TINYINT(1)   NOT NULL DEFAULT 0,
    Active            TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (akahu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Each transaction pulled from Akahu ───────────────────────────────────
-- amount: positive = credit (received), negative = debit (paid out).
-- Auto-matching only considers credits.
-- raw_json stores the full Akahu payload for debugging / future re-matching
-- without re-pulling the API.
CREATE TABLE IF NOT EXISTS Bank_Transactions (
    id              INT          NOT NULL AUTO_INCREMENT,
    akahu_id        VARCHAR(64)  NOT NULL,
    account_id      VARCHAR(64)  NOT NULL,
    txn_date        DATE         NOT NULL,
    amount          DECIMAL(19,4) NOT NULL,
    description     TEXT         NULL,
    particulars     VARCHAR(255) NULL,
    code            VARCHAR(255) NULL,
    reference       VARCHAR(255) NULL,
    type            VARCHAR(50)  NULL,
    other_account   VARCHAR(255) NULL,
    raw_json        LONGTEXT     NULL,
    matched_status  ENUM('unmatched','partially_matched','fully_matched','manual_matched','ignored') NOT NULL DEFAULT 'unmatched',
    matched_at      DATETIME     NULL,
    matched_by      VARCHAR(100) NULL,
    notes           TEXT         NULL,
    pulled_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_akahu_id (akahu_id),
    KEY idx_date   (txn_date),
    KEY idx_acc    (account_id),
    KEY idx_status (matched_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Many-to-many: which transaction(s) paid which invoice(s) ─────────────
-- One transaction can pay several invoices (lump-sum customer payment).
-- One invoice can be paid by several transactions (partial payments).
-- The SUM(Bank_Allocations.amount) WHERE invoice_no=N is the canonical
-- "amount paid" for an invoice; Invoices.AmountPaid is a denormalised
-- cache the matcher updates in the same transaction.
CREATE TABLE IF NOT EXISTS Bank_Allocations (
    id              INT          NOT NULL AUTO_INCREMENT,
    transaction_id  INT          NOT NULL,
    invoice_no      INT          NOT NULL,
    amount          DECIMAL(19,4) NOT NULL,
    allocated_at    DATETIME     NOT NULL,
    allocated_by    VARCHAR(100) NULL,
    auto            TINYINT(1)   NOT NULL DEFAULT 0,
    note            TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_txn     (transaction_id),
    KEY idx_invoice (invoice_no),
    CONSTRAINT fk_alloc_txn FOREIGN KEY (transaction_id) REFERENCES Bank_Transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Invoices.AmountPaid ──────────────────────────────────────────────────
-- Running cache of allocated payments for this invoice. Recomputed by
-- bankfeed_match.php whenever an allocation is added or removed. Source of
-- truth is SUM(Bank_Allocations.amount) WHERE invoice_no = X.
--
-- Add only if missing. MySQL has no portable IF NOT EXISTS for ADD COLUMN
-- before 8.0.29 — guard via INFORMATION_SCHEMA.
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Invoices' AND COLUMN_NAME = 'AmountPaid'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE Invoices ADD COLUMN AmountPaid DECIMAL(19,4) NOT NULL DEFAULT 0 AFTER Subtotal',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
