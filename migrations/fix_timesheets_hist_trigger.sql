-- ─────────────────────────────────────────────────────────────────────────
-- Fix the Timesheet_catch AFTER DELETE trigger so it stops killing the
-- weekly resubmit flow with "Duplicate entry '0' for key 'PRIMARY'".
--
-- ROOT CAUSE: every DELETE on Timesheets fires Timesheet_catch which
-- INSERTs the OLD row into Timesheets_HIST. If Timesheets_HIST has a
-- PRIMARY KEY (or UNIQUE) on TS_ID, the trigger blows up whenever the
-- same TS_ID would be archived twice — typically because:
--   • a TS_ID=0 zombie row in Timesheets has been re-archived before
--   • submit.php's MAX(TS_ID)+1 collided with a value already sitting in
--     Timesheets_HIST from a much earlier archive
--
-- THE FIX: change the trigger to INSERT IGNORE — duplicate archive rows
-- silently no-op instead of bombing the parent DELETE. Then submit.php's
-- weekly delete-and-reinsert cycle works regardless of what's already in
-- the history table.
--
-- HOW TO RUN: paste the whole file into phpMyAdmin → SQL → Go.
-- Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. (informational) inspect the history table — PK on TS_ID is what
--    causes the problem. Run this first if you want to see for yourself.
-- SHOW CREATE TABLE Timesheets_HIST;

-- 2. Drop and recreate the trigger with INSERT IGNORE.
DROP TRIGGER IF EXISTS Timesheet_catch;

DELIMITER $$
CREATE TRIGGER `Timesheet_catch` AFTER DELETE ON `Timesheets` FOR EACH ROW BEGIN
    INSERT IGNORE INTO Timesheets_HIST (
        TS_ID,
        TS_DATE,
        Invoice_No,
        Employee_id,
        proj_id,
        Task,
        Hours,
        StartTime,
        StopTime,
        Notes,
        System_Date,
        Rate,
        Locked
    )
    VALUES (
        OLD.TS_ID,
        OLD.TS_DATE,
        OLD.Invoice_No,
        OLD.Employee_id,
        OLD.proj_id,
        OLD.Task,
        OLD.Hours,
        OLD.StartTime,
        OLD.StopTime,
        OLD.Notes,
        OLD.System_Date,
        OLD.Rate,
        OLD.Locked
    );
END$$
DELIMITER ;

-- 3. Clean up the zombie TS_ID=0 row if it's still in Timesheets.
--    The DELETE will fire the (now safe) trigger and silently skip the
--    HIST archive if a duplicate exists.
DELETE FROM Timesheets WHERE TS_ID = 0;

-- 4. Re-seed the AUTO_INCREMENT counter to one above the highest TS_ID
--    seen in EITHER table. (No-op if TS_ID isn't AUTO_INCREMENT yet,
--    but safe to run.)
SET @m := (SELECT GREATEST(
    COALESCE((SELECT MAX(TS_ID) FROM Timesheets), 0),
    COALESCE((SELECT MAX(TS_ID) FROM Timesheets_HIST), 0)
) + 1);
SET @s := CONCAT('ALTER TABLE `Timesheets` AUTO_INCREMENT = ', @m);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
