<?php
/**
 * Shared stage/task editor partial.
 *
 * Caller provides:
 *   $mode          — 'project' or 'template'
 *   $owner_id      — proj_id or Template_ID
 *   $owner_label   — title text (e.g. "Project: Smith House" or "Template: …")
 *   $stages_table  — 'Project_Stages' or 'Template_Stages'
 *   $tasks_table   — 'Project_Tasks'  or 'Template_Tasks'
 *   $stage_owner_col — 'Proj_ID' or 'Template_ID'
 *   $task_owner_col  — 'Project_Stage_ID' (always — tasks are linked via stage)
 *   $back_url      — link target for the "back" button
 */
require_once 'auth_check.php';
require_once 'db_connect.php';

if (!in_array($_SESSION['UserID'], ['erik', 'jen'], true)) {
    http_response_code(403);
    die('Admin only.');
}

$pdo = get_db();

// Detect variation support (only for project mode)
$hasVariations = false;
if ($mode === 'project') {
    try {
        $hasVariations = (bool)$pdo->query("SHOW TABLES LIKE 'Project_Variations'")->fetch();
    } catch (Exception $e) { /* ignore */ }
}

// $quoteStatus may be set by the caller (project_stages.php). Default to draft.
if (!isset($quoteStatus)) $quoteStatus = 'draft';
$isAccepted = ($mode === 'project' && $quoteStatus === 'accepted' && $hasVariations);

/**
 * Find the latest unapproved/draft variation for this project, creating
 * one on-demand if none exists. Returns Variation_ID. Used in accepted
 * mode to auto-route edits/additions to a variation.
 */
function ensureUnapprovedVariation(PDO $pdo, int $projId, int $createdBy = 0): int {
    $st = $pdo->prepare("SELECT Variation_ID FROM Project_Variations
                           WHERE Proj_ID = ? AND Status IN ('unapproved','draft')
                           ORDER BY Variation_Number DESC LIMIT 1");
    $st->execute([$projId]);
    $vid = $st->fetchColumn();
    if ($vid) return (int)$vid;
    $st = $pdo->prepare("SELECT COALESCE(MAX(Variation_Number),0)+1 FROM Project_Variations WHERE Proj_ID = ?");
    $st->execute([$projId]);
    $vnum = (int)$st->fetchColumn();
    $pdo->prepare("INSERT INTO Project_Variations (Proj_ID, Variation_Number, Title, Description, Status, Date_Created, Created_By)
                    VALUES (?, ?, ?, ?, 'draft', CURDATE(), ?)")
        ->execute([$projId, $vnum, "Auto variation #$vnum", "Auto-created to capture changes after quote was accepted", $createdBy]);
    return (int)$pdo->lastInsertId();
}

/**
 * Find or create a variation-scoped stage with the same Stage_Type_ID as the
 * given original stage. So edits to "Schematic Design" tasks land under the
 * variation's "Schematic Design" stage.
 */
function ensureVariationStage(PDO $pdo, int $projId, int $variationId, int $stageTypeId): int {
    $st = $pdo->prepare("SELECT Project_Stage_ID FROM Project_Stages
                           WHERE Proj_ID = ? AND Variation_ID = ? AND Stage_Type_ID = ? LIMIT 1");
    $st->execute([$projId, $variationId, $stageTypeId]);
    $sid = $st->fetchColumn();
    if ($sid) return (int)$sid;
    $next = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM Project_Stages")->fetchColumn();
    $pdo->prepare("INSERT INTO Project_Stages (Project_Stage_ID, Proj_ID, Variation_ID, Stage_Type_ID, Description, Weight, Notes)
                    VALUES (?, ?, ?, ?, '', 1, '')")
        ->execute([$next, $projId, $variationId, $stageTypeId]);
    return $next;
}

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_stage') {
            $variationId = ($hasVariations && isset($_POST['Variation_ID']) && $_POST['Variation_ID'] !== '')
                ? (int)$_POST['Variation_ID'] : null;
            $variationCol = $hasVariations ? ', Variation_ID' : '';
            $variationPlaceholder = $hasVariations ? ', ?' : '';

            $cols = "$stage_owner_col, Stage_Type_ID, Description, Weight, Notes$variationCol";
            $vals = "?, ?, ?, ?, ?$variationPlaceholder";
            $params = [
                $owner_id,
                (int)($_POST['Stage_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Notes'] ?? '',
            ];
            if ($hasVariations) $params[] = $variationId;
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Project_Stage_ID),0)+1 FROM `$stages_table`")->fetchColumn();
            $cols = "Project_Stage_ID, $cols";
            $vals = "?, $vals";
            array_unshift($params, $next);
            $pdo->prepare("INSERT INTO `$stages_table` ($cols) VALUES ($vals)")->execute($params);

        } elseif ($action === 'update_stage') {
            $sid = (int)$_POST['Project_Stage_ID'];
            $stmt = $pdo->prepare("UPDATE `$stages_table` SET Stage_Type_ID = ?, Description = ?, Weight = ?, Notes = ? WHERE Project_Stage_ID = ? AND $stage_owner_col = ?");
            $stmt->execute([
                (int)($_POST['Stage_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Notes'] ?? '',
                $sid,
                $owner_id,
            ]);

        } elseif ($action === 'save_stage_all') {
            // Update stage header
            $sid = (int)$_POST['Project_Stage_ID'];
            $stmt = $pdo->prepare("UPDATE `$stages_table` SET Stage_Type_ID = ?, Description = ?, Weight = ?, Notes = ? WHERE Project_Stage_ID = ? AND $stage_owner_col = ?");
            $stmt->execute([
                (int)($_POST['Stage_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Notes'] ?? '',
                $sid,
                $owner_id,
            ]);
            // Update all tasks submitted as arrays
            $taskStmt = $pdo->prepare("UPDATE `$tasks_table` SET Task_Type_ID = ?, Description = ?, Weight = ?, Proj_Task_Notes = ?, Proj_Task_Order = ?, Assigned_To = ? WHERE Proj_Task_ID = ?");
            foreach ($_POST['tasks'] ?? [] as $tp) {
                $tid = (int)($tp['Proj_Task_ID'] ?? 0);
                if ($tid <= 0) continue;
                $assignedTo = (isset($tp['Assigned_To']) && $tp['Assigned_To'] !== '') ? (int)$tp['Assigned_To'] : null;
                $taskStmt->execute([
                    (int)($tp['Task_Type_ID'] ?? 0),
                    $tp['Description'] ?? '',
                    (float)($tp['Weight'] ?? 1),
                    $tp['Proj_Task_Notes'] ?? '',
                    (int)($tp['Proj_Task_Order'] ?? 0),
                    $assignedTo,
                    $tid,
                ]);
            }

        } elseif ($action === 'assign_stage') {
            $sid = (int)$_POST['Project_Stage_ID'];
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            if ($mode === 'template') {
                $pdo->prepare("UPDATE `$tasks_table` SET Assigned_To = ? WHERE Project_Stage_ID = ? AND Template_ID = ?")->execute([$assignedTo, $sid, $owner_id]);
            } else {
                $pdo->prepare("UPDATE `$tasks_table` SET Assigned_To = ? WHERE Project_Stage_ID = ?")->execute([$assignedTo, $sid]);
            }

        } elseif ($action === 'assign_all') {
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            if ($mode === 'template') {
                $pdo->prepare("UPDATE `$tasks_table` SET Assigned_To = ? WHERE Template_ID = ?")->execute([$assignedTo, $owner_id]);
            } else {
                $pdo->prepare("UPDATE `$tasks_table` SET Assigned_To = ? WHERE Project_Stage_ID IN (SELECT Project_Stage_ID FROM Project_Stages WHERE Proj_ID = ?)")->execute([$assignedTo, $owner_id]);
            }

        } elseif ($action === 'drop_stage') {
            $sid = (int)$_POST['Project_Stage_ID'];
            if ($mode === 'template') {
                $pdo->prepare("DELETE FROM `$tasks_table` WHERE Project_Stage_ID = ? AND Template_ID = ?")->execute([$sid, $owner_id]);
            } else {
                $pdo->prepare("DELETE FROM `$tasks_table` WHERE Project_Stage_ID = ?")->execute([$sid]);
            }
            $pdo->prepare("DELETE FROM `$stages_table` WHERE Project_Stage_ID = ? AND $stage_owner_col = ?")->execute([$sid, $owner_id]);

        } elseif ($action === 'add_task') {
            $sid = (int)$_POST['Project_Stage_ID'];
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            // Inherit Variation_ID from parent stage so tasks stay aligned
            $taskVariationId = null;
            $effectiveSid = $sid;
            if ($hasVariations) {
                $st = $pdo->prepare("SELECT Variation_ID, Stage_Type_ID FROM `$stages_table` WHERE Project_Stage_ID = ?");
                $st->execute([$sid]);
                $row = $st->fetch();
                $taskVariationId = $row && $row['Variation_ID'] !== null ? (int)$row['Variation_ID'] : null;
                $stageTypeId = $row ? (int)$row['Stage_Type_ID'] : 0;
                // ── Accepted-mode auto-route: if adding to an original-quote stage,
                //    redirect into the latest unapproved variation's matching stage.
                if ($isAccepted && $taskVariationId === null && $mode === 'project' && $stageTypeId > 0) {
                    $autoVid = ensureUnapprovedVariation($pdo, (int)$owner_id, (int)($_SESSION['Employee_id'] ?? 0));
                    $effectiveSid = ensureVariationStage($pdo, (int)$owner_id, $autoVid, $stageTypeId);
                    $taskVariationId = $autoVid;
                }
            }
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1 FROM `$tasks_table`")->fetchColumn();
            $vCol = $hasVariations ? ', Variation_ID' : '';
            $vPh  = $hasVariations ? ', ?' : '';
            if ($mode === 'template') {
                $stmt = $pdo->prepare("INSERT INTO `$tasks_table` (Proj_Task_ID, Template_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order, Assigned_To$vCol) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?$vPh)");
                $params = [$next, $owner_id, $sid,
                    (int)($_POST['Task_Type_ID'] ?? 0),
                    $_POST['Description'] ?? '',
                    (float)($_POST['Weight'] ?? 1),
                    $_POST['Proj_Task_Notes'] ?? '',
                    (int)($_POST['Proj_Task_Order'] ?? 0),
                    $assignedTo];
                if ($hasVariations) $params[] = $taskVariationId;
                $stmt->execute($params);
            } else {
                $stmt = $pdo->prepare("INSERT INTO `$tasks_table` (Proj_Task_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order, Assigned_To$vCol) VALUES (?, ?, ?, ?, ?, ?, ?, ?$vPh)");
                $params = [$next, $effectiveSid,
                    (int)($_POST['Task_Type_ID'] ?? 0),
                    $_POST['Description'] ?? '',
                    (float)($_POST['Weight'] ?? 1),
                    $_POST['Proj_Task_Notes'] ?? '',
                    (int)($_POST['Proj_Task_Order'] ?? 0),
                    $assignedTo];
                if ($hasVariations) $params[] = $taskVariationId;
                $stmt->execute($params);
            }

        } elseif ($action === 'save_variation_task' && $hasVariations && $mode === 'project') {
            // Accepted-mode "Save Variation" on an original-quote task.
            // Creates a NEW task in the latest unapproved variation that mirrors
            // the form values, and marks the original task as removed in that
            // variation. Net effect: the original quote stays untouched in the
            // database; the variation captures the change.
            $origTid = (int)$_POST['Proj_Task_ID'];
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            // Look up the original task's parent stage to get Stage_Type_ID
            $st = $pdo->prepare("SELECT pt.Project_Stage_ID, ps.Stage_Type_ID FROM Project_Tasks pt JOIN Project_Stages ps ON pt.Project_Stage_ID = ps.Project_Stage_ID WHERE pt.Proj_Task_ID = ?");
            $st->execute([$origTid]);
            $origMeta = $st->fetch();
            if (!$origMeta) throw new Exception('Original task not found');
            $autoVid = ensureUnapprovedVariation($pdo, (int)$owner_id, (int)($_SESSION['Employee_id'] ?? 0));
            $varStageId = ensureVariationStage($pdo, (int)$owner_id, $autoVid, (int)$origMeta['Stage_Type_ID']);
            $next = (int)$pdo->query("SELECT COALESCE(MAX(Proj_Task_ID),0)+1 FROM Project_Tasks")->fetchColumn();
            $pdo->prepare("INSERT INTO Project_Tasks (Proj_Task_ID, Project_Stage_ID, Task_Type_ID, Description, Weight, Proj_Task_Notes, Proj_Task_Order, Assigned_To, Variation_ID)
                            VALUES (?, ?, ?, ?, ?, '', ?, ?, ?)")
                ->execute([
                    $next, $varStageId,
                    (int)($_POST['Task_Type_ID'] ?? 0),
                    'CHANGED: ' . ($_POST['Description'] ?? ''),
                    (float)($_POST['Weight'] ?? 1),
                    (int)($_POST['Proj_Task_Order'] ?? 0),
                    $assignedTo,
                    $autoVid,
                ]);
            // Mark the original task as removed in this variation
            $pdo->prepare("UPDATE Project_Tasks SET Is_Removed = 1, Removed_In_Variation_ID = ? WHERE Proj_Task_ID = ?")
                ->execute([$autoVid, $origTid]);

        } elseif ($action === 'update_task') {
            $tid = (int)$_POST['Proj_Task_ID'];
            $assignedTo = (isset($_POST['Assigned_To']) && $_POST['Assigned_To'] !== '') ? (int)$_POST['Assigned_To'] : null;
            $stmt = $pdo->prepare("UPDATE `$tasks_table` SET Task_Type_ID = ?, Description = ?, Weight = ?, Proj_Task_Notes = ?, Proj_Task_Order = ?, Assigned_To = ? WHERE Proj_Task_ID = ?");
            $stmt->execute([
                (int)($_POST['Task_Type_ID'] ?? 0),
                $_POST['Description'] ?? '',
                (float)($_POST['Weight'] ?? 1),
                $_POST['Proj_Task_Notes'] ?? '',
                (int)($_POST['Proj_Task_Order'] ?? 0),
                $assignedTo,
                $tid,
            ]);

        } elseif ($action === 'drop_task') {
            $tid = (int)$_POST['Proj_Task_ID'];
            $pdo->prepare("DELETE FROM `$tasks_table` WHERE Proj_Task_ID = ?")->execute([$tid]);

        } elseif ($action === 'mark_task_removed' && $hasVariations) {
            // Soft-delete an original-quote task. Restore (Is_Removed=0) clears
            // the variation link. Removal auto-attaches to the latest unapproved
            // variation (creating one if necessary) — no UI dropdown needed.
            $tid = (int)$_POST['Proj_Task_ID'];
            $isRemoved = ($_POST['Is_Removed'] ?? '1') === '0' ? 0 : 1;
            if ($isRemoved === 0) {
                $pdo->prepare("UPDATE Project_Tasks SET Is_Removed = 0, Removed_In_Variation_ID = NULL WHERE Proj_Task_ID = ?")
                    ->execute([$tid]);
            } else {
                $autoVid = ensureUnapprovedVariation($pdo, (int)$owner_id, (int)($_SESSION['Employee_id'] ?? 0));
                $pdo->prepare("UPDATE Project_Tasks SET Is_Removed = 1, Removed_In_Variation_ID = ? WHERE Proj_Task_ID = ?")
                    ->execute([$autoVid, $tid]);
            }

        } elseif ($action === 'add_variation' && $hasVariations) {
            $title = trim($_POST['Title'] ?? '');
            $desc  = trim($_POST['Description'] ?? '');
            $status = in_array($_POST['Status'] ?? '', ['unapproved','draft','quoted','approved','in_progress','complete','rejected'], true)
                ? $_POST['Status'] : 'draft';
            if ($title === '') throw new Exception('Variation title required.');
            $vnum = (int)$pdo->prepare("SELECT COALESCE(MAX(Variation_Number),0)+1 FROM Project_Variations WHERE Proj_ID = ?")
                ->execute([$owner_id]) ?: 1;
            $st = $pdo->prepare("SELECT COALESCE(MAX(Variation_Number),0)+1 AS n FROM Project_Variations WHERE Proj_ID = ?");
            $st->execute([$owner_id]);
            $vnum = (int)$st->fetchColumn();
            $pdo->prepare("INSERT INTO Project_Variations (Proj_ID, Variation_Number, Title, Description, Status, Date_Created, Created_By)
                           VALUES (?, ?, ?, ?, ?, CURDATE(), ?)")
                ->execute([$owner_id, $vnum, $title, $desc, $status, (int)($_SESSION['Employee_id'] ?? 0)]);

        } elseif ($action === 'update_variation' && $hasVariations) {
            $vid = (int)$_POST['Variation_ID'];
            $status = in_array($_POST['Status'] ?? '', ['unapproved','draft','quoted','approved','in_progress','complete','rejected'], true)
                ? $_POST['Status'] : 'draft';
            $approved = ($status === 'approved') ? 'CURDATE()' : 'Date_Approved';
            $pdo->prepare("UPDATE Project_Variations SET Title = ?, Description = ?, Status = ?, Quote_Amount = ?, Approved_By = ?,
                           Date_Approved = CASE WHEN ? = 'approved' AND Date_Approved IS NULL THEN CURDATE() ELSE Date_Approved END
                           WHERE Variation_ID = ? AND Proj_ID = ?")
                ->execute([
                    $_POST['Title'] ?? '',
                    $_POST['Description'] ?? '',
                    $status,
                    ($_POST['Quote_Amount'] ?? '') !== '' ? (float)$_POST['Quote_Amount'] : null,
                    $_POST['Approved_By'] ?? '',
                    $status,
                    $vid, $owner_id,
                ]);

        } elseif ($action === 'drop_variation' && $hasVariations) {
            $vid = (int)$_POST['Variation_ID'];
            $pdo->prepare("DELETE FROM Project_Tasks WHERE Variation_ID = ?")->execute([$vid]);
            $pdo->prepare("DELETE FROM Project_Stages WHERE Variation_ID = ? AND Proj_ID = ?")->execute([$vid, $owner_id]);
            $pdo->prepare("UPDATE Project_Tasks SET Is_Removed = 0, Removed_In_Variation_ID = NULL WHERE Removed_In_Variation_ID = ?")->execute([$vid]);
            $pdo->prepare("DELETE FROM Project_Variations WHERE Variation_ID = ? AND Proj_ID = ?")->execute([$vid, $owner_id]);
        }
    } catch (Exception $e) {
        $errMsg = 'DB Error: ' . htmlspecialchars($e->getMessage());
    }

    if (empty($errMsg)) {
        // Preserve scroll position. Anchor wins (jumps to that section);
        // else fall back to a saved Y pixel offset via query string.
        $redir = $_SERVER['REQUEST_URI'];
        $anchor = trim($_POST['scroll_anchor'] ?? '');
        $scrollY = (int)($_POST['scroll_y'] ?? 0);
        if ($anchor !== '') {
            $redir = preg_replace('/#.*$/', '', $redir) . '#' . $anchor;
        } elseif ($scrollY > 0) {
            $sep = (strpos($redir, '?') === false) ? '?' : '&';
            $redir = preg_replace('/[?&]_y=\d+/', '', $redir) . $sep . '_y=' . $scrollY;
        }
        header('Location: ' . $redir);
        exit;
    }
}

// ── Load stages + tasks ───────────────────────────────────────────────────
// In project mode with variations enabled, only show ORIGINAL stages here
// (Variation_ID IS NULL). Variation stages are rendered separately below.
$stagesWhere = "s.$stage_owner_col = ?";
if ($hasVariations && $mode === 'project') {
    $stagesWhere .= " AND s.Variation_ID IS NULL";
}
$stages = $pdo->prepare(
    "SELECT s.*, st.Stage_Type_Name, st.Stage_Order
       FROM `$stages_table` s
       LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
      WHERE $stagesWhere
      ORDER BY st.Stage_Order, s.Project_Stage_ID"
);
$stages->execute([$owner_id]);
$stages = $stages->fetchAll();

if ($mode === 'template') {
    $tasksStmt = $pdo->prepare(
        "SELECT t.*, tt.Task_Name, tt.Estimated_Time
           FROM `$tasks_table` t
           LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
          WHERE t.Template_ID = ?
          ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
    );
    $tasksStmt->execute([$owner_id]);
} else {
    $taskFilter = $hasVariations ? " AND COALESCE(t.Is_Removed, 0) = 0 AND t.Variation_ID IS NULL" : '';
    $tasksStmt = $pdo->prepare(
        "SELECT t.*, tt.Task_Name, tt.Estimated_Time
           FROM Project_Tasks t
           LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
          WHERE t.Project_Stage_ID IN (SELECT Project_Stage_ID FROM Project_Stages WHERE Proj_ID = ?)
            $taskFilter
          ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
    );
    $tasksStmt->execute([$owner_id]);
}
$allTasks = $tasksStmt->fetchAll();

$tasksByStage = [];
foreach ($allTasks as $t) {
    $tasksByStage[$t['Project_Stage_ID']][] = $t;
}

// ── Load variations + their stages + tasks (project mode only) ────────────
$variations = [];
$variationStages = [];   // variation_id => [stages]
$variationTasksByStage = []; // stage_id => [tasks]
$removedTasks = [];      // [task_rows] flagged Is_Removed
if ($hasVariations && $mode === 'project') {
    $vs = $pdo->prepare("SELECT * FROM Project_Variations WHERE Proj_ID = ? ORDER BY Variation_Number");
    $vs->execute([$owner_id]);
    $variations = $vs->fetchAll();

    if (!empty($variations)) {
        $vIds = array_column($variations, 'Variation_ID');
        $in = implode(',', array_fill(0, count($vIds), '?'));
        $vsStmt = $pdo->prepare(
            "SELECT s.*, st.Stage_Type_Name, st.Stage_Order
               FROM Project_Stages s
               LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
              WHERE s.Proj_ID = ? AND s.Variation_ID IN ($in)
              ORDER BY s.Variation_ID, st.Stage_Order, s.Project_Stage_ID"
        );
        $vsStmt->execute(array_merge([$owner_id], $vIds));
        foreach ($vsStmt->fetchAll() as $vs) {
            $variationStages[(int)$vs['Variation_ID']][] = $vs;
        }
        $vtStmt = $pdo->prepare(
            "SELECT t.*, tt.Task_Name, tt.Estimated_Time
               FROM Project_Tasks t
               LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
              WHERE t.Variation_ID IN ($in) AND COALESCE(t.Is_Removed, 0) = 0
              ORDER BY t.Proj_Task_Order, t.Proj_Task_ID"
        );
        $vtStmt->execute($vIds);
        foreach ($vtStmt->fetchAll() as $t) {
            $variationTasksByStage[(int)$t['Project_Stage_ID']][] = $t;
        }
    }

    // Removed-from-quote tasks (flagged but kept for audit trail)
    $rmStmt = $pdo->prepare(
        "SELECT t.*, tt.Task_Name, tt.Estimated_Time, st.Stage_Type_Name, v.Variation_Number, v.Title AS RemovalVariationTitle
           FROM Project_Tasks t
           LEFT JOIN Tasks_Types tt ON t.Task_Type_ID = tt.Task_ID
           LEFT JOIN Project_Stages s ON t.Project_Stage_ID = s.Project_Stage_ID
           LEFT JOIN Stage_Types st ON s.Stage_Type_ID = st.Stage_Type_ID
           LEFT JOIN Project_Variations v ON t.Removed_In_Variation_ID = v.Variation_ID
          WHERE s.Proj_ID = ? AND t.Is_Removed = 1
          ORDER BY t.Proj_Task_ID"
    );
    $rmStmt->execute([$owner_id]);
    $removedTasks = $rmStmt->fetchAll();
}

// ── Pricing setup (quote.php style) ────────────────────────────────────
$baseRate = 90.00;  // CADViz base hourly rate
$multiplier = 1.0;  // Client multiplier (default)
if ($mode === 'project') {
    $projData = $pdo->prepare("SELECT p.Client_ID, c.Multiplier FROM Projects p LEFT JOIN Clients c ON p.Client_ID = c.Client_id WHERE p.proj_id = ?")->fetch();
    if ($projData) {
        $multiplier = (float)($projData['Multiplier'] ?? 1);
        if ($multiplier <= 0) $multiplier = 1;
    }
}

// Build staff lookup by Employee_ID for quick rate access
$staffRates = [];
foreach ($allTasks as $t) {
    if ($t['Assigned_To'] && !isset($staffRates[$t['Assigned_To']])) {
        $sr = $pdo->prepare("SELECT `BILLING RATE` FROM Staff WHERE Employee_ID = ?")->fetch();
        $staffRates[$t['Assigned_To']] = (float)($sr['BILLING RATE'] ?? 0);
    }
}

// Lookups
$stageTypes = $pdo->query("SELECT Stage_Type_ID, Stage_Type_Name FROM Stage_Types ORDER BY Stage_Order, Stage_Type_Name")->fetchAll();
$taskTypes  = $pdo->query("SELECT Task_ID, Task_Name, Stage_ID, Estimated_Time, Fixed_Cost FROM Tasks_Types ORDER BY Task_Name")->fetchAll();
try {
    $staff = $pdo->query("SELECT Employee_ID, Login, `BILLING RATE` AS BillingRate FROM Staff WHERE Active <> 0 ORDER BY Login")->fetchAll();
} catch (Exception $e) {
    $staff = $pdo->query("SELECT Employee_ID, Login, `BILLING RATE` AS BillingRate FROM Staff ORDER BY Login")->fetchAll();
}

// Build staff dropdown options HTML (reused in multiple places)
function staffOptions(array $staff, int $selected = 0): string {
    $html = '<option value="">— unassigned —</option>';
    foreach ($staff as $s) {
        $sel = ((int)$s['Employee_ID'] === $selected) ? ' selected' : '';
        $rate = !empty($s['BillingRate']) ? ' ($' . number_format((float)$s['BillingRate'], 0) . ')' : '';
        $html .= '<option value="' . (int)$s['Employee_ID'] . '"' . $sel . '>' . htmlspecialchars($s['Login']) . $rate . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($owner_label) ?></title>
<link href="global.css" rel="stylesheet">
<style>
body { background:#EBEBEB; font-family:Arial,sans-serif; padding:14px; font-size:12px; }
h1 { color:#9B9B1B; margin:0 0 8px; font-size:18px; }
h2 { color:#9B9B1B; margin:14px 0 4px; font-size:14px; }
.nav a { margin-right:14px; }
.actions a, .actions button, .actions input[type=submit] {
    background:#9B9B1B; color:#fff; padding:4px 8px; text-decoration:none;
    border-radius:3px; border:none; cursor:pointer; font-size:11px;
}
.danger { background:#c33 !important; }
.assign-btn { background:#5577aa !important; }
table { border-collapse:collapse; width:100%; max-width:1100px; background:#fff; margin-bottom:2px; }
th { background:#9B9B1B; color:#fff; text-align:left; padding:4px 6px; font-size:11px; }
td { padding:3px 6px; border-top:1px solid #eee; vertical-align:middle; }
input[type=text], input[type=number], select { font-size:11px; padding:2px 3px; }
.stage-row td { background:#f7f7e0; font-weight:bold; }
.add-row td { background:#eef; }
.totals td { background:#fff8b3; font-weight:bold; }
.assign-bar { max-width:1100px; background:#eef4ff; border:1px solid #c0d0ee; padding:5px 8px; margin-bottom:8px; font-size:11px; }
.assign-stage-bar { max-width:1100px; background:#f5f5ff; border:1px solid #dde; padding:4px 8px; margin-bottom:6px; font-size:11px; }
form.inline { display:inline; margin:0; }
</style>
<script>
// Collect all task row data and attach as array fields to the Save Stage form
function saveStageWithTasks(form, sid) {
    // Remove any previously added bulk-task hidden inputs
    form.querySelectorAll('[data-bulk-task]').forEach(function(el){ el.remove(); });
    var i = 0;
    document.querySelectorAll('.task-form[data-stage-id="' + sid + '"]').forEach(function(tf) {
        ['Proj_Task_ID','Task_Type_ID','Description','Weight','Proj_Task_Notes','Proj_Task_Order','Assigned_To'].forEach(function(f) {
            var el = tf.querySelector('[name="' + f + '"]');
            var h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'tasks[' + i + '][' + f + ']';
            h.value = el ? el.value : '';
            h.setAttribute('data-bulk-task', '1');
            form.appendChild(h);
        });
        i++;
    });
    return true;
}

// ── Scroll-position preservation across form posts ─────────────────────
// On submit: stamp current Y pixel into a hidden scroll_y input on the form.
// On load: if URL has ?_y=NNN scroll there; OR if URL has #anchor browser auto-jumps.
document.addEventListener('submit', function(ev) {
    var f = ev.target;
    if (!f || f.tagName !== 'FORM') return;
    if (f.querySelector('[name="scroll_anchor"]')) return; // anchor mode wins
    var existing = f.querySelector('input[name="scroll_y"][data-auto]');
    if (!existing) {
        existing = document.createElement('input');
        existing.type = 'hidden';
        existing.name = 'scroll_y';
        existing.setAttribute('data-auto', '1');
        f.appendChild(existing);
    }
    existing.value = String(window.scrollY || window.pageYOffset || 0);
}, true);

window.addEventListener('DOMContentLoaded', function() {
    var m = window.location.search.match(/[?&]_y=(\d+)/);
    if (m) {
        // Defer slightly so browser layout settles (variation cards, tables, etc.)
        setTimeout(function() { window.scrollTo(0, parseInt(m[1], 10)); }, 0);
    }
});
</script>
</head>
<body>

<div class="nav">
  <a href="<?= htmlspecialchars($back_url) ?>">&larr; Back</a>
  <a href="menu.php">Main Menu</a>
  <?php if ($mode === 'template'): ?>
    <a href="templates.php">All Templates</a>
  <?php endif; ?>
</div>
<h1><?= htmlspecialchars($owner_label) ?></h1>
<?php if (!empty($errMsg)): ?><p style="color:red"><?= $errMsg ?></p><?php endif; ?>

<!-- Assign all tasks in project -->
<?php if (!empty($stages)): ?>
<div class="assign-bar">
  <strong>Bulk assign entire project:</strong>
  <form method="post" class="inline" onsubmit="return confirm('Assign ALL tasks across ALL stages to this person?');">
    <input type="hidden" name="action" value="assign_all">
    <select name="Assigned_To" required>
      <?= staffOptions($staff) ?>
    </select>
    <span class="actions"><input type="submit" class="assign-btn" value="Assign all tasks in project"></span>
  </form>
</div>
<?php endif; ?>

<?php if ($isAccepted): ?>
<div class="card" style="background:#fff8e0;border-left:4px solid #1a6b1a;padding:8px 12px">
  <strong>📦 Quote ACCEPTED</strong> — original tasks are read-only. Edits/additions/removals automatically route into the latest unapproved variation (auto-created on first change).
</div>
<?php endif; ?>

<?php
$grandHrs = 0;
foreach ($stages as $stage):
    $sid = (int)$stage['Project_Stage_ID'];
    // Stage weight removed from functionality — always treat as 1.
    $stageWeight = 1.0;
    $tasks = $tasksByStage[$sid] ?? [];
    $stageHrs = 0;
?>

<table>
<tr class="stage-row">
  <td colspan="9">
    <?php if ($isAccepted): ?>
      <!-- Accepted mode: stage header is read-only -->
      <strong>Stage:</strong> <?= htmlspecialchars($stage['Stage_Type_Name'] ?? '?') ?>
      &nbsp; <em><?= htmlspecialchars($stage['Description'] ?? '') ?></em>
    <?php else: ?>
      <!-- Save Stage (also saves all tasks via JS) -->
      <form method="post" class="inline" onsubmit="return saveStageWithTasks(this, <?= $sid ?>);">
        <input type="hidden" name="action" value="save_stage_all">
        <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
        <input type="hidden" name="Weight" value="1">
        <strong>Stage:</strong>
        <select name="Stage_Type_ID">
          <?php foreach ($stageTypes as $st): ?>
            <option value="<?= (int)$st['Stage_Type_ID'] ?>" <?= ((int)$st['Stage_Type_ID'] === (int)$stage['Stage_Type_ID']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($st['Stage_Type_Name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        Description: <input type="text" name="Description" value="<?= htmlspecialchars((string)($stage['Description'] ?? '')) ?>" style="width:240px">
        <span class="actions">
          <input type="submit" value="Save Stage">
        </span>
      </form>
      <form method="post" class="inline" onsubmit="return confirm('Delete this entire stage and all its tasks?');">
        <input type="hidden" name="action" value="drop_stage">
        <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
        <span class="actions"><input type="submit" class="danger" value="Drop Stage"></span>
      </form>
    <?php endif; ?>
  </td>
</tr>
<tr><th style="width:28%">Task</th><th>Description</th><th style="width:55px">Weight</th><th style="width:60px">Hours</th><th style="width:70px">Rate</th><th style="width:75px">Price</th><th style="width:130px">Assigned To</th><th style="width:60px">Order</th><th style="width:90px">Actions</th></tr>

<?php foreach ($tasks as $t):
    $hrs = (float)($t['Estimated_Time'] ?? 0) * (float)($t['Weight'] ?? 1);
    $assigned = (int)($t['Assigned_To'] ?? 0);
    $staffRate = (float)($staffRates[$assigned] ?? 0);
    $rateBase = ($staffRate > 0) ? $staffRate : $baseRate;
    $rate = $rateBase * $multiplier;
    $price = $hrs * $rate;
    $stageHrs += $hrs;
    $stagePrice = ($stagePrice ?? 0) + $price;
?>
<tr>
  <form method="post" class="inline task-form" data-stage-id="<?= $sid ?>">
    <input type="hidden" name="action" value="update_task">
    <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
    <td>
      <select name="Task_Type_ID">
        <?php foreach ($taskTypes as $tt): ?>
          <option value="<?= (int)$tt['Task_ID'] ?>" <?= ((int)$tt['Task_ID'] === (int)$t['Task_Type_ID']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($tt['Task_Name']) ?> (<?= (float)$tt['Estimated_Time'] ?>h)
          </option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="Description" value="<?= htmlspecialchars((string)($t['Description'] ?? '')) ?>" style="width:99%"></td>
    <td><input type="number" step="0.05" name="Weight" value="<?= htmlspecialchars((string)($t['Weight'] ?? '1')) ?>" style="width:55px"></td>
    <td><?= number_format($hrs, 2) ?></td>
    <td style="text-align:right">$<?= number_format($rate, 2) ?></td>
    <td style="text-align:right">$<?= number_format($price, 2) ?></td>
    <td>
      <select name="Assigned_To">
        <?= staffOptions($staff, $assigned) ?>
      </select>
    </td>
    <td><input type="number" name="Proj_Task_Order" value="<?= htmlspecialchars((string)($t['Proj_Task_Order'] ?? 0)) ?>" style="width:55px"></td>
    <td class="actions">
      <?php if ($isAccepted): ?>
        <input type="hidden" name="action" value="save_variation_task">
        <input type="submit" value="Save Variation" title="Saves change as a new task in the latest unapproved variation; original is auto-marked as removed in that variation."
               style="background:#c33;color:#fff;border:none;border-radius:3px;cursor:pointer;padding:2px 6px;font-size:11px">
      <?php else: ?>
        <input type="hidden" name="action" value="update_task">
        <input type="submit" value="Save">
      <?php endif; ?>
  </form>
      <?php if (!$isAccepted): ?>
        <!-- Draft mode: red X drop button -->
        <form method="post" class="inline" onsubmit="return confirm('Delete this task?');">
          <input type="hidden" name="action" value="drop_task">
          <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
          <input type="submit" class="danger" value="X">
        </form>
      <?php endif; ?>
      <?php if ($hasVariations): ?>
      <!-- Orange minus: mark as removed (auto-attaches to latest unapproved variation) -->
      <form method="post" class="inline" onsubmit="return confirm('Mark this task as removed from the original quote? It will be auto-attached to the latest unapproved variation (one will be created if none exists).');">
        <input type="hidden" name="action" value="mark_task_removed">
        <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
        <input type="hidden" name="Is_Removed" value="1">
        <input type="submit" value="−" title="Remove from original quote (routes to latest unapproved variation)" style="background:#a60;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:12px;padding:2px 8px;font-weight:bold">
      </form>
      <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>

<tr class="add-row">
  <form method="post" class="inline">
    <input type="hidden" name="action" value="add_task">
    <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
    <td>
      <select name="Task_Type_ID" required>
        <option value="">-- pick a task --</option>
        <?php foreach ($taskTypes as $tt): ?>
          <option value="<?= (int)$tt['Task_ID'] ?>"><?= htmlspecialchars($tt['Task_Name']) ?> (<?= (float)$tt['Estimated_Time'] ?>h)</option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="Description" placeholder="(override description)" style="width:99%"></td>
    <td><input type="number" step="0.05" name="Weight" value="1" style="width:55px"></td>
    <td>—</td>
    <td>
      <select name="Assigned_To">
        <?= staffOptions($staff) ?>
      </select>
    </td>
    <td><input type="number" name="Proj_Task_Order" value="0" style="width:55px"></td>
    <td class="actions"><input type="submit" value="+ Add Task"></td>
  </form>
  <!-- Bulk assign for this stage -->
<?php if (!empty($tasks)): ?>
<div class="assign-stage-bar">
  <strong>Bulk assign this stage:</strong>
  <form method="post" class="inline" onsubmit="return confirm('Assign all tasks in this stage to the selected person?');">
    <input type="hidden" name="action" value="assign_stage">
    <input type="hidden" name="Project_Stage_ID" value="<?= $sid ?>">
    <select name="Assigned_To" required>
      <?= staffOptions($staff) ?>
    </select>
    <span class="actions"><input type="submit" class="assign-btn" value="Assign all in stage"></span>
  </form>
</div>
<?php endif; ?>
</tr>

<tr class="totals"><td colspan="3" align="right">Stage subtotal:</td><td><?= number_format($stageHrs, 2) ?> hrs</td><td></td><td style="text-align:right">$<?= number_format($stagePrice ?? 0, 2) ?></td><td colspan="2"></td></tr>
</table>



<?php
    $grandHrs += $stageHrs;
    $grandPrice = ($grandPrice ?? 0) + ($stagePrice ?? 0);
endforeach;
?>

<!-- Add stage form (draft mode only — accepted projects can't add original stages) -->
<?php if (!$isAccepted): ?>
<table>
<tr class="add-row">
  <form method="post" class="inline">
    <input type="hidden" name="action" value="add_stage">
    <input type="hidden" name="Weight" value="1">
    <td colspan="9">
      <strong>Add new stage:</strong>
      <select name="Stage_Type_ID" required>
        <option value="">-- stage type --</option>
        <?php foreach ($stageTypes as $st): ?>
          <option value="<?= (int)$st['Stage_Type_ID'] ?>"><?= htmlspecialchars($st['Stage_Type_Name']) ?></option>
        <?php endforeach; ?>
      </select>
      Description: <input type="text" name="Description" style="width:280px">
      <span class="actions"><input type="submit" value="+ Add Stage"></span>
    </td>
  </form>
</tr>
</table>
<?php endif; ?>
<table>
<tr class="totals"><td colspan="3" align="right">Grand total (original quote):</td><td><?= number_format($grandHrs, 2) ?> hrs</td><td></td><td style="text-align:right">$<?= number_format($grandPrice ?? 0, 2) ?></td><td colspan="2"></td></tr>
</table>

<?php if ($hasVariations && $mode === 'project'): ?>
<!-- ── Variations section ─────────────────────────────────────────────── -->
<style>
.var-card { background:#fff; border:2px solid #c33; border-radius:6px; padding:10px 14px; margin:14px 0; }
.var-card.approved { border-color:#1a6b1a; }
.var-card.rejected { border-color:#999; opacity:.6; }
.var-card.complete { border-color:#5577aa; }
.var-card h3 { margin:0 0 6px; color:#c33; font-size:14px; }
.var-card.approved h3 { color:#1a6b1a; }
.var-status { display:inline-block; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:bold; margin-left:6px; }
.s-unapproved, .s-rejected { background:#ffd6d6; color:#a00; }
.s-draft { background:#eee; color:#555; }
.s-quoted, .s-in_progress { background:#fff3cd; color:#7a5a00; }
.s-approved, .s-complete { background:#d6f5d6; color:#1a6b1a; }
.var-meta input, .var-meta select, .var-meta textarea { font-size:11px; padding:2px 4px; }
.removed-row td { background:#fff0f0; color:#999; text-decoration:line-through; }
</style>

<h2 style="color:#c33">Variations</h2>
<p class="subtle">Variations track scope changes after the original quote. Status flow: <em>unapproved → quoted → approved → in_progress → complete</em>. Hours booked here are excluded from original-quote analytics.</p>

<?php foreach ($variations as $v):
    $vid = (int)$v['Variation_ID'];
    $vstages = $variationStages[$vid] ?? [];
    $vEstHrs = 0;
?>
<div class="var-card <?= htmlspecialchars($v['Status']) ?>">
  <h3>
    Variation #<?= (int)$v['Variation_Number'] ?>: <?= htmlspecialchars($v['Title']) ?>
    <span class="var-status s-<?= htmlspecialchars($v['Status']) ?>"><?= htmlspecialchars(strtoupper($v['Status'])) ?></span>
  </h3>

  <form method="post" class="var-meta" style="margin-bottom:6px">
    <input type="hidden" name="action" value="update_variation">
    <input type="hidden" name="Variation_ID" value="<?= $vid ?>">
    <input type="hidden" name="scroll_anchor" value="variation-<?= $vid ?>">
    Title: <input type="text" name="Title" value="<?= htmlspecialchars($v['Title']) ?>" style="width:200px">
    Status:
    <select name="Status">
      <?php foreach (['unapproved','draft','quoted','approved','in_progress','complete','rejected'] as $st): ?>
        <option value="<?= $st ?>" <?= $v['Status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
      <?php endforeach; ?>
    </select>
    Quote $: <input type="number" step="0.01" name="Quote_Amount" value="<?= htmlspecialchars((string)($v['Quote_Amount'] ?? '')) ?>" style="width:80px">
    Approved by: <input type="text" name="Approved_By" value="<?= htmlspecialchars($v['Approved_By'] ?? '') ?>" style="width:120px">
    <br>Description: <textarea name="Description" rows="2" style="width:96%"><?= htmlspecialchars($v['Description'] ?? '') ?></textarea>
    <br><span class="actions"><input type="submit" value="Save Variation"></span>
  </form>
  <!-- Drop must be a SEPARATE form (siblings, not nested) -->
  <form method="post" class="inline" style="float:right;margin-top:-30px"
        onsubmit="return confirm('Delete variation #<?= (int)$v['Variation_Number'] ?> and ALL its stages, tasks, and removal flags?');">
    <input type="hidden" name="action" value="drop_variation">
    <input type="hidden" name="Variation_ID" value="<?= $vid ?>">
    <input type="submit" class="danger" value="Drop Variation">
  </form>
  <div id="variation-<?= $vid ?>"></div>

  <?php foreach ($vstages as $vstage):
      $vsid = (int)$vstage['Project_Stage_ID'];
      $vtasks = $variationTasksByStage[$vsid] ?? [];
      $vstageWeight = (float)($vstage['Weight'] ?? 1);
      $vstageHrs = 0;
  ?>
  <table>
    <tr class="stage-row">
      <td colspan="6">
        <strong>Stage:</strong> <?= htmlspecialchars($vstage['Stage_Type_Name'] ?? '?') ?>
        — <?= htmlspecialchars($vstage['Description'] ?? '') ?>
        <span style="float:right">
          <form method="post" class="inline" onsubmit="return confirm('Drop this variation stage and its tasks?');">
            <input type="hidden" name="action" value="drop_stage">
            <input type="hidden" name="Project_Stage_ID" value="<?= $vsid ?>">
            <input type="submit" class="danger" value="Drop">
          </form>
        </span>
      </td>
    </tr>
    <tr><th>Task</th><th>Description</th><th style="width:55px">Weight</th><th style="width:60px">Hours</th><th style="width:130px">Assigned</th><th style="width:90px">Actions</th></tr>
    <?php foreach ($vtasks as $t):
        $hrs = (float)($t['Estimated_Time'] ?? 0) * (float)($t['Weight'] ?? 1);
        $vstageHrs += $hrs;
    ?>
    <tr>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="update_task">
        <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
        <td>
          <select name="Task_Type_ID">
            <?php foreach ($taskTypes as $tt): ?>
              <option value="<?= (int)$tt['Task_ID'] ?>" <?= ((int)$tt['Task_ID'] === (int)$t['Task_Type_ID']) ? 'selected' : '' ?>><?= htmlspecialchars($tt['Task_Name']) ?> (<?= (float)$tt['Estimated_Time'] ?>h)</option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input type="text" name="Description" value="<?= htmlspecialchars($t['Description'] ?? '') ?>" style="width:99%"></td>
        <td><input type="number" step="0.05" name="Weight" value="<?= htmlspecialchars((string)($t['Weight'] ?? 1)) ?>" style="width:55px"></td>
        <td><?= number_format($hrs, 2) ?></td>
        <td>
          <select name="Assigned_To">
            <?= staffOptions($staff, (int)($t['Assigned_To'] ?? 0)) ?>
          </select>
        </td>
        <td class="actions">
          <input type="hidden" name="Proj_Task_Order" value="<?= (int)($t['Proj_Task_Order'] ?? 0) ?>">
          <input type="submit" value="Save">
      </form>
          <form method="post" class="inline" onsubmit="return confirm('Delete this task?');">
            <input type="hidden" name="action" value="drop_task">
            <input type="hidden" name="Proj_Task_ID" value="<?= (int)$t['Proj_Task_ID'] ?>">
            <input type="submit" class="danger" value="X">
          </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <tr class="add-row">
      <form method="post" class="inline">
        <input type="hidden" name="action" value="add_task">
        <input type="hidden" name="Project_Stage_ID" value="<?= $vsid ?>">
        <td>
          <select name="Task_Type_ID" required>
            <option value="">-- pick a task --</option>
            <?php foreach ($taskTypes as $tt): ?>
              <option value="<?= (int)$tt['Task_ID'] ?>"><?= htmlspecialchars($tt['Task_Name']) ?> (<?= (float)$tt['Estimated_Time'] ?>h)</option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input type="text" name="Description" placeholder="(optional)" style="width:99%"></td>
        <td><input type="number" step="0.05" name="Weight" value="1" style="width:55px"></td>
        <td>—</td>
        <td><select name="Assigned_To"><?= staffOptions($staff) ?></select></td>
        <td class="actions"><input type="hidden" name="Proj_Task_Order" value="0"><input type="submit" value="+ Add"></td>
      </form>
    </tr>
    <tr class="totals"><td colspan="3" align="right">Stage subtotal:</td><td><?= number_format($vstageHrs, 2) ?> hrs</td><td colspan="2"></td></tr>
  </table>
  <?php $vEstHrs += $vstageHrs; endforeach; ?>

  <!-- Add stage to this variation -->
  <form method="post" style="margin-top:6px">
    <input type="hidden" name="action" value="add_stage">
    <input type="hidden" name="Variation_ID" value="<?= $vid ?>">
    <strong>Add stage to this variation:</strong>
    <select name="Stage_Type_ID" required>
      <option value="">--</option>
      <?php foreach ($stageTypes as $st): ?>
        <option value="<?= (int)$st['Stage_Type_ID'] ?>"><?= htmlspecialchars($st['Stage_Type_Name']) ?></option>
      <?php endforeach; ?>
    </select>
    Description: <input type="text" name="Description" style="width:200px">
    <input type="hidden" name="Weight" value="1">
    <span class="actions"><input type="submit" value="+ Add Stage"></span>
  </form>

  <p style="margin-top:8px"><strong>Variation total: <?= number_format($vEstHrs, 2) ?> hrs</strong></p>
</div>
<?php endforeach; ?>

<!-- Add new variation form -->
<div class="card" style="border-left:4px solid #c33">
  <form method="post">
    <input type="hidden" name="action" value="add_variation">
    <strong>+ Add new variation:</strong>
    Title: <input type="text" name="Title" required style="width:240px" placeholder="e.g. Extra bedroom">
    Status:
    <select name="Status">
      <option value="draft">draft</option>
      <option value="unapproved">unapproved</option>
      <option value="approved">approved</option>
    </select>
    <br>Description: <textarea name="Description" rows="2" style="width:90%"></textarea>
    <br><span class="actions"><input type="submit" value="Create Variation"></span>
  </form>
</div>

<?php if (!empty($removedTasks)): ?>
<h2 style="color:#888">Removed-from-quote tasks (audit trail)</h2>
<table>
  <tr><th>Task</th><th>Stage</th><th>Hrs (was)</th><th>Removed in</th><th>Restore</th></tr>
  <?php foreach ($removedTasks as $rt): ?>
  <tr class="removed-row">
    <td><?= htmlspecialchars($rt['Task_Name'] ?? '?') ?></td>
    <td><?= htmlspecialchars($rt['Stage_Type_Name'] ?? '?') ?></td>
    <td><?= number_format((float)($rt['Estimated_Time'] ?? 0) * (float)($rt['Weight'] ?? 1), 2) ?></td>
    <td><?= $rt['Variation_Number'] ? '#' . (int)$rt['Variation_Number'] . ': ' . htmlspecialchars($rt['RemovalVariationTitle'] ?? '') : '—' ?></td>
    <td>
      <form method="post" class="inline" onsubmit="return confirm('Restore this task to the original quote?');">
        <input type="hidden" name="action" value="mark_task_removed">
        <input type="hidden" name="Proj_Task_ID" value="<?= (int)$rt['Proj_Task_ID'] ?>">
        <input type="hidden" name="Is_Removed" value="0">
        <input type="submit" value="Restore">
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php endif; /* hasVariations */ ?>

</body>
</html>
