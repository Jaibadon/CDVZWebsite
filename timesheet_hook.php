<?php
/**
 * Timesheet hook — when a revision is published (commit_create.php), drop a
 * DRAFT Timesheets row for the author so they don't have to re-type what
 * they did into main.php. Single source of truth: the commit message
 * becomes the timesheet task description; staff just add the Hours later.
 *
 * The row is created with Hours = 0. Reports/analytics already filter
 * Hours > 0 (see helpers.php / task_analytics.php / staff_hours.php), so a
 * zero-hour draft never skews any number — it just shows up on the staff
 * member's own timesheet grid as a pre-filled line waiting for hours.
 *
 * Task text is prefixed "↳ BIM commit:" so it's obvious where it came from
 * and easy to spot (and to filter on later if we ever want to).
 *
 * NON-FATAL by contract: every failure path returns null and never throws.
 * A timesheet-hook problem must not fail or roll back the commit itself.
 *
 * TS_ID generation mirrors submit.php exactly: MAX across Timesheets AND
 * Timesheets_HIST (the AFTER DELETE trigger archives there; picking a TS_ID
 * already in HIST would later collide), with retry-on-duplicate for the
 * PK race.
 */

require_once __DIR__ . '/db_connect.php';

/**
 * @param int      $commitId  the just-created Commits row
 * @param int|null $empId     author's Staff.Employee_ID if known (commit_create
 *                            has it from the session); else resolved from
 *                            Commits.Author_UserID → Staff.Login.
 * @return int|null           the new TS_ID, or null on any failure (logged
 *                            by the caller as a soft warning).
 */
function draft_timesheet_for_commit(PDO $pdo, int $commitId, ?int $empId = null): ?int
{
    try {
        $c = $pdo->prepare(
            "SELECT Proj_ID, Author_UserID, Message, Created_At, Revision_Label
               FROM Commits WHERE Commit_ID = ?"
        );
        $c->execute([$commitId]);
        $cm = $c->fetch(PDO::FETCH_ASSOC);
        if (!$cm) return null;

        $projId = (int)$cm['Proj_ID'];
        if ($projId <= 0) return null;

        // Resolve Employee_id if not supplied
        if (!$empId || $empId <= 0) {
            $login = trim((string)($cm['Author_UserID'] ?? ''));
            if ($login === '') return null;
            $s = $pdo->prepare("SELECT Employee_ID FROM Staff WHERE Login = ? LIMIT 1");
            $s->execute([$login]);
            $empId = (int)($s->fetchColumn() ?: 0);
            if ($empId <= 0) return null;   // no Staff row → can't attribute a timesheet line
        }

        $tsDate = $cm['Created_At'] ? date('Y-m-d', strtotime((string)$cm['Created_At'])) : date('Y-m-d');
        $rev    = trim((string)($cm['Revision_Label'] ?? ''));
        $msg    = trim((string)($cm['Message'] ?? ''));
        // Keep the Task column reasonable — it's not a giant TEXT field on
        // some legacy schemas. Truncate the message.
        $task = '↳ BIM commit'
              . ($rev !== '' ? " (Rev $rev)" : '')
              . ': '
              . mb_strimwidth($msg, 0, 180, '…');

        // ── TS_ID: MAX across Timesheets + Timesheets_HIST ───────────────
        $maxExpr = "(SELECT COALESCE(MAX(TS_ID), 0) FROM Timesheets)";
        try {
            if ($pdo->query("SHOW TABLES LIKE 'Timesheets_HIST'")->fetch()) {
                $maxExpr = "GREATEST($maxExpr, (SELECT COALESCE(MAX(TS_ID), 0) FROM Timesheets_HIST))";
            }
        } catch (Exception $e) { /* no HIST table — fine */ }

        // Base columns only — present on every Timesheets schema variant.
        // Task_Type_ID / Proj_Task_ID / Variation_ID are nullable/absent and
        // intentionally omitted (this is a free-text draft, not a task-linked
        // line; staff can re-pick a task in main.php if they want).
        $ins = $pdo->prepare(
            "INSERT INTO Timesheets (TS_ID, TS_DATE, Employee_id, proj_id, Task, Hours, Invoice_No)
             VALUES (?, ?, ?, ?, ?, 0, 0)"
        );

        // Retry-on-duplicate for the PK race (same approach as submit.php).
        $attempts = 0;
        while (true) {
            $attempts++;
            $nextTsId = (int)($pdo->query("SELECT $maxExpr + 1 AS nxt")->fetch(PDO::FETCH_ASSOC)['nxt'] ?? 0);
            if ($nextTsId < 1) $nextTsId = 1;
            try {
                $ins->execute([$nextTsId, $tsDate, $empId, $projId, $task]);
                return $nextTsId;
            } catch (PDOException $e) {
                // 23000 = integrity constraint (duplicate PK). Retry a few
                // times; anything else, or too many retries → give up soft.
                if ($e->getCode() === '23000' && $attempts < 5) {
                    continue;
                }
                return null;
            }
        }
    } catch (Throwable $e) {
        // Truly non-fatal — the commit already succeeded; a missing draft
        // timesheet line is a minor inconvenience, never an error.
        return null;
    }
}
