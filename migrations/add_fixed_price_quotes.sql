-- ============================================================================
-- Fixed-price (lump-sum) quote mode for Projects.
-- ----------------------------------------------------------------------------
-- Quote_Type:
--   NULL or 'estimate' = current behaviour (sum of tasks × rate × multiplier)
--   'fixed'            = lump-sum quote with internal safety margin.
--                        For small jobs (≤ $3k) Erik typically just sets a
--                        price without breaking down tasks. The margin is
--                        added on top internally; the client only sees the
--                        marked-up price + GST.
--
-- Fixed_Price       = base price quoted to the client (pre-margin, pre-GST)
-- Fixed_Margin_Pct  = safety margin % added on top (default 12.5%)
--
-- Variations on fixed-price projects use Project_Variations.Quote_Amount as
-- a manual price override (no margin applied — the lump sum IS the figure).
-- ============================================================================

ALTER TABLE Projects
    ADD COLUMN Quote_Type ENUM('estimate','fixed') NULL DEFAULT NULL,
    ADD COLUMN Fixed_Price DECIMAL(10,2) NULL,
    ADD COLUMN Fixed_Margin_Pct DECIMAL(5,2) NULL DEFAULT 12.50;
