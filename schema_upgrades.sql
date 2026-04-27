-- ─────────────────────────────────────────────────────────────────────────
-- One-off schema upgrade: enable AUTO_INCREMENT on integer primary keys
-- ─────────────────────────────────────────────────────────────────────────
-- HOW TO RUN
--   1. Back up your DB first (phpMyAdmin → Export → SQL).
--   2. Open phpMyAdmin → select database → SQL tab → paste this whole file → Go.
--   3. If a statement fails, the rest still runs. Check the error and fix
--      that one table manually.
--
-- WHAT IT DOES
--   For each table with an INT primary key, this:
--     a) ensures the column is NOT NULL (auto_increment requires it),
--     b) sets AUTO_INCREMENT,
--     c) seeds the AUTO_INCREMENT counter to MAX(id)+1 so the next insert
--        starts above any existing row.
--
-- SAFE TO RE-RUN
--   ALTER TABLE ... AUTO_INCREMENT is idempotent.
-- ─────────────────────────────────────────────────────────────────────────

-- Projects ─────────────────────────────────────────────────────────────────
ALTER TABLE `Projects`
  MODIFY COLUMN `proj_id` INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `Projects`
  AUTO_INCREMENT = 1;
-- Re-seed counter past current max
SET @m := (SELECT IFNULL(MAX(`proj_id`),0)+1 FROM `Projects`);
SET @s := CONCAT('ALTER TABLE `Projects` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Invoices ─────────────────────────────────────────────────────────────────
ALTER TABLE `Invoices`
  MODIFY COLUMN `Invoice_No` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Invoice_No`),0)+1 FROM `Invoices`);
SET @s := CONCAT('ALTER TABLE `Invoices` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Clients ──────────────────────────────────────────────────────────────────
ALTER TABLE `Clients`
  MODIFY COLUMN `Client_id` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Client_id`),0)+1 FROM `Clients`);
SET @s := CONCAT('ALTER TABLE `Clients` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Timesheets ───────────────────────────────────────────────────────────────
ALTER TABLE `Timesheets`
  MODIFY COLUMN `TS_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`TS_ID`),0)+1 FROM `Timesheets`);
SET @s := CONCAT('ALTER TABLE `Timesheets` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Payments ─────────────────────────────────────────────────────────────────
ALTER TABLE `Payments`
  MODIFY COLUMN `Payment_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Payment_ID`),0)+1 FROM `Payments`);
SET @s := CONCAT('ALTER TABLE `Payments` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Staff ────────────────────────────────────────────────────────────────────
ALTER TABLE `Staff`
  MODIFY COLUMN `Employee_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Employee_ID`),0)+1 FROM `Staff`);
SET @s := CONCAT('ALTER TABLE `Staff` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Tasks_Types ──────────────────────────────────────────────────────────────
ALTER TABLE `Tasks_Types`
  MODIFY COLUMN `Task_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Task_ID`),0)+1 FROM `Tasks_Types`);
SET @s := CONCAT('ALTER TABLE `Tasks_Types` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Project_Tasks ────────────────────────────────────────────────────────────
ALTER TABLE `Project_Tasks`
  MODIFY COLUMN `Project_Task_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Project_Task_ID`),0)+1 FROM `Project_Tasks`);
SET @s := CONCAT('ALTER TABLE `Project_Tasks` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Project_Stages ───────────────────────────────────────────────────────────
ALTER TABLE `Project_Stages`
  MODIFY COLUMN `Project_Stage_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Project_Stage_ID`),0)+1 FROM `Project_Stages`);
SET @s := CONCAT('ALTER TABLE `Project_Stages` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Spec_SubCats ─────────────────────────────────────────────────────────────
ALTER TABLE `Spec_SubCats`
  MODIFY COLUMN `Spec_SubCat_ID` INT(11) NOT NULL AUTO_INCREMENT;
SET @m := (SELECT IFNULL(MAX(`Spec_SubCat_ID`),0)+1 FROM `Spec_SubCats`);
SET @s := CONCAT('ALTER TABLE `Spec_SubCats` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Spec_Categories (if it exists) ───────────────────────────────────────────
-- Comment out if your table has a different name
-- ALTER TABLE `Spec_Categories`
--   MODIFY COLUMN `Spec_Cat_ID` INT(11) NOT NULL AUTO_INCREMENT;
-- SET @m := (SELECT IFNULL(MAX(`Spec_Cat_ID`),0)+1 FROM `Spec_Categories`);
-- SET @s := CONCAT('ALTER TABLE `Spec_Categories` AUTO_INCREMENT = ', @m);
-- PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
