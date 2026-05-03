-- ─────────────────────────────────────────────────────────────────────────
-- Add Clients.Contact column for the per-client contact name. Used by
-- lib_invoice_email.php / send_statement.php / xero_send_reminders.php
-- to greet the recipient by first name ("Dear John") instead of by the
-- company name.
--
-- HOW TO RUN
--   1. Back up your DB.
--   2. phpMyAdmin → SQL tab → paste → Go.
--   3. Safe to re-run: the IF NOT EXISTS guard skips it on subsequent runs.
--      (Older MySQL builds don't support IF NOT EXISTS on ADD COLUMN — if
--       phpMyAdmin complains, just remove "IF NOT EXISTS" from line 17.)
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE `Clients`
  ADD COLUMN IF NOT EXISTS `Contact` VARCHAR(255) NULL DEFAULT NULL
  AFTER `Mobile`;
