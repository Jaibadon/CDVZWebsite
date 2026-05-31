<?php
/**
 * Issue-gate logic for review-and-approve-before-release.
 *
 * IMPORTANT (the non-obvious bit): the gate guards the Commits.Status
 * transition to a RELEASED state (issued / for_council / for_construction).
 * It does NOT gate sending review transmittals — those are how reviewers
 * approve in the first place, so blocking them would be circular.
 *
 * Per-project strictness: Projects.approval_policy
 *   none          → never gated
 *   soft          → never blocked; caller warns when approvals incomplete
 *   hard_external → block release to external statuses until approvals complete
 *   hard_always   → block ANY released status until approvals complete
 */

if (!function_exists('cadviz_released_statuses')) {

/** Statuses representing a formal release (vs internal wip / for_review). */
function cadviz_released_statuses(): array
{
    return ['issued', 'for_council', 'for_construction'];
}

/** Released statuses that reach EXTERNAL parties (client / council / builder). */
function cadviz_external_statuses(): array
{
    return ['issued', 'for_council', 'for_construction'];
}

/**
 * Approval rollup for a commit across all its transmittals: how many REQUIRED
 * reviewers there are vs how many approved / requested changes.
 */
function cadviz_approval_state(PDO $pdo, int $commitId): array
{
    $st = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN tr.Is_Required = 1 THEN 1 ELSE 0 END) AS required,
            SUM(CASE WHEN tr.Is_Required = 1 AND tr.Approval_Status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN tr.Is_Required = 1 AND tr.Approval_Status = 'changes_requested' THEN 1 ELSE 0 END) AS changes
           FROM Transmittals t
           INNER JOIN Transmittal_Recipients tr ON tr.Transmittal_ID = t.Transmittal_ID
          WHERE t.Commit_ID = ?"
    );
    $st->execute([$commitId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $required = (int)($r['required'] ?? 0);
    $approved = (int)($r['approved'] ?? 0);
    $changes  = (int)($r['changes'] ?? 0);
    return [
        'required'          => $required,
        'approved'          => $approved,
        'changes_requested' => $changes,
        // complete = ≥1 required reviewer, all approved, none requesting changes.
        // The ≥1 rule prevents bypass-by-inviting-nobody on hard policies.
        'complete'          => ($required >= 1 && $approved >= $required && $changes === 0),
    ];
}

/**
 * Decide whether $targetStatus is allowed under $policy given $state.
 * @return array ['allowed'=>bool, 'reason'=>string]   reason is a warning (soft) or block message.
 */
function cadviz_issue_gate(string $policy, string $targetStatus, array $state): array
{
    $released = in_array($targetStatus, cadviz_released_statuses(), true);
    if (!$released) return ['allowed' => true, 'reason' => ''];   // wip / for_review / superseded never gated

    $external = in_array($targetStatus, cadviz_external_statuses(), true);

    switch ($policy) {
        case 'none':
            return ['allowed' => true, 'reason' => ''];
        case 'soft':
            return ['allowed' => true, 'reason' => $state['complete'] ? '' : 'Issuing without full reviewer approval (soft policy — proceeding anyway).'];
        case 'hard_always':
            return $state['complete'] ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => cadviz_gate_reason($state)];
        case 'hard_external':
        default:
            if (!$external) return ['allowed' => true, 'reason' => ''];
            return $state['complete'] ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => cadviz_gate_reason($state)];
    }
}

function cadviz_gate_reason(array $state): string
{
    if ($state['required'] < 1) {
        return 'No required reviewers designated. Send a review transmittal (its recipients become required approvers) before issuing.';
    }
    if ($state['changes_requested'] > 0) {
        return $state['changes_requested'] . ' reviewer(s) requested changes — resolve and re-issue for review before releasing.';
    }
    return 'Awaiting approval: ' . $state['approved'] . ' of ' . $state['required'] . ' required reviewers have approved.';
}

/** Read a project's approval policy, defaulting to hard_external. */
function cadviz_project_approval_policy(PDO $pdo, int $projId): string
{
    try {
        $st = $pdo->prepare("SELECT approval_policy FROM Projects WHERE proj_id = ?");
        $st->execute([$projId]);
        $v = (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $e) { $v = ''; }
    $allowed = ['none', 'soft', 'hard_external', 'hard_always'];
    return in_array($v, $allowed, true) ? $v : 'hard_external';
}

} // function_exists guard
