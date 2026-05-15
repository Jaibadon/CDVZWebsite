<?php
/**
 * Coverage rule engine.
 *
 * Called from /api/commit_create.php after the new Element_Instances rows
 * have been written. Diffs the new commit's elements vs the parent commit
 * (if any), evaluates every active Coverage_Rule, writes firings, and
 * pre-tags the commit with suggested NZBC clauses.
 *
 * Design philosophy: simple SQL + PHP arrays. Rules are JSON predicates
 * (Trigger_Selector column). No expression engine — the supported
 * predicate shape is small and deliberate.
 *
 * Trigger_Type enum (matching add_coverage_seed.sql):
 *   element_added      — element exists in current commit, absent from parent
 *   element_removed    — present in parent, absent from current
 *   element_modified   — present in both, parameters differ
 *   param_changed      — present in both, specific named param value differs
 *   category_present   — element of this category exists (fires on first commit)
 *
 * Trigger_Selector shape (JSON):
 *   {
 *     "category":   "Wall"            OR ["Window","Door"]   (optional)
 *     "param_name": "Thickness"       OR ["Width","Height"]  (param_changed only)
 *     "param":      {"LoadBearing": "true", "Exterior": "true"}   (filter: element must have these values)
 *   }
 *
 * Returns:
 *   [
 *     'firings' => int,                          total Coverage_Rule_Firings rows written
 *     'tags'    => ['B1','E2',...],              distinct NZBC clauses suggested
 *     'details' => [ {rule_id, rule_name, nzbc_clauses_csv, default_roles_csv,
 *                     match_count, sample_matches[]}, ... ]   per-rule summary
 *     'errors'  => string[]                      per-rule failures (non-fatal)
 *   ]
 *
 * Library mode (`require_once`-only): set COVERAGE_ENGINE_LIBRARY_ONLY before
 * requiring this file to declare functions without running anything. (Reserved
 * for future cron usage; not used today since commit_create.php just calls
 * run_coverage_rules() directly.)
 */

if (!function_exists('run_coverage_rules')) {

function run_coverage_rules(PDO $pdo, int $commitId, ?int $parentCommitId): array
{
    $rules = $pdo->query("SELECT * FROM Coverage_Rules WHERE Active = 1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rules)) return ['firings'=>0, 'tags'=>[], 'details'=>[], 'errors'=>[]];

    $errors = [];

    // Build per-commit snapshots — one SQL query each for elements, one for params
    try {
        $currentSnap = load_commit_snapshot($pdo, $commitId);
    } catch (Exception $e) {
        return ['firings'=>0, 'tags'=>[], 'details'=>[], 'errors'=>['Load current snapshot failed: ' . $e->getMessage()]];
    }

    $parentSnap = ['elements' => [], 'params' => []];
    if ($parentCommitId) {
        try { $parentSnap = load_commit_snapshot($pdo, $parentCommitId); }
        catch (Exception $e) { $errors[] = 'Load parent snapshot failed: ' . $e->getMessage(); }
    }

    $diff = compute_element_diff($currentSnap, $parentSnap);

    $firings = 0;
    $tagsSet = [];   // Clause_Code => true
    $details = [];

    $insertFiring = $pdo->prepare(
        "INSERT INTO Coverage_Rule_Firings
           (Commit_ID, Coverage_Rule_ID, Element_Instance_ID, Element_Ifc_Guid, Fired_At)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $insertTag = $pdo->prepare(
        "INSERT IGNORE INTO Commit_NZBC_Tags
           (Commit_ID, Clause_Code, Source, Confidence, Tagged_By, Tagged_At)
         VALUES (?, ?, 'rule', 1.00, 'system', NOW())"
    );

    foreach ($rules as $rule) {
        try {
            $selector = json_decode((string)($rule['Trigger_Selector'] ?? ''), true);
            if (!is_array($selector)) {
                $errors[] = "Rule {$rule['Coverage_Rule_ID']} '{$rule['Name']}': invalid Trigger_Selector JSON, skipped";
                continue;
            }
            $matches = evaluate_coverage_rule((string)$rule['Trigger_Type'], $selector, $diff);
            if (empty($matches)) continue;

            $details[] = [
                'rule_id'           => (int)$rule['Coverage_Rule_ID'],
                'rule_name'         => $rule['Name'],
                'nzbc_clauses_csv'  => $rule['Nzbc_Clauses_CSV'],
                'default_roles_csv' => $rule['Default_Stakeholder_Roles_CSV'],
                'match_count'       => count($matches),
                'sample_matches'    => array_slice($matches, 0, 5),
            ];

            foreach ($matches as $m) {
                $insertFiring->execute([
                    $commitId,
                    (int)$rule['Coverage_Rule_ID'],
                    $m['element_instance_id'] ?? null,
                    $m['ifc_guid'] ?? null,
                ]);
                $firings++;
            }

            // Aggregate distinct NZBC clauses → tag the commit
            foreach (array_filter(array_map('trim', explode(',', (string)$rule['Nzbc_Clauses_CSV']))) as $clause) {
                if (!isset($tagsSet[$clause])) {
                    $tagsSet[$clause] = true;
                    $insertTag->execute([$commitId, $clause]);
                }
            }
        } catch (Exception $e) {
            $errors[] = "Rule {$rule['Coverage_Rule_ID']} '{$rule['Name']}': " . $e->getMessage();
        }
    }

    return [
        'firings' => $firings,
        'tags'    => array_keys($tagsSet),
        'details' => $details,
        'errors'  => $errors,
    ];
}

/**
 * Load every Element_Instance and its parameters for a commit, keyed by Ifc_Guid.
 * Two queries total (elements + params), regardless of model size.
 */
function load_commit_snapshot(PDO $pdo, int $commitId): array
{
    $stmt = $pdo->prepare(
        "SELECT Element_Instance_ID, Ifc_Guid, Category, Name, Type_Name, Level_Name
           FROM Element_Instances
          WHERE Commit_ID = ?"
    );
    $stmt->execute([$commitId]);

    $elements = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $guid = $e['Ifc_Guid'];
        if (!$guid) continue;
        $elements[$guid] = [
            'element_instance_id' => (int)$e['Element_Instance_ID'],
            'ifc_guid'            => $guid,
            'category'            => (string)($e['Category'] ?? ''),
            'name'                => $e['Name'],
            'type_name'           => $e['Type_Name'],
            'level'               => $e['Level_Name'],
        ];
    }

    // One query for ALL parameters of this commit's elements (avoids N+1).
    $stmt = $pdo->prepare(
        "SELECT ei.Ifc_Guid, ep.Pset, ep.Param_Name, ep.Param_Value
           FROM Element_Parameters ep
           INNER JOIN Element_Instances ei ON ei.Element_Instance_ID = ep.Element_Instance_ID
          WHERE ei.Commit_ID = ?"
    );
    $stmt->execute([$commitId]);

    $params = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $guid = $p['Ifc_Guid'];
        if (!$guid) continue;
        if (!isset($params[$guid])) $params[$guid] = [];
        // Lowercase the name for case-insensitive matching against rules
        $params[$guid][strtolower((string)$p['Param_Name'])] = (string)($p['Param_Value'] ?? '');
    }

    return ['elements' => $elements, 'params' => $params];
}

/**
 * Compute the structured diff: added / removed / modified.
 * 'modified' includes the list of param names that changed, so param_changed
 * rules can match without re-comparing the param maps later.
 */
function compute_element_diff(array $current, array $parent): array
{
    $added = []; $removed = []; $modified = [];

    foreach ($current['elements'] as $guid => $el) {
        $el['params'] = $current['params'][$guid] ?? [];
        if (!isset($parent['elements'][$guid])) {
            $added[] = $el;
        } else {
            $prev = $parent['elements'][$guid];
            $prev['params'] = $parent['params'][$guid] ?? [];
            $changedParams = changed_param_names($prev['params'], $el['params']);
            $structuralChange = ($prev['category']  !== $el['category'])
                             || ($prev['name']      !== $el['name'])
                             || ($prev['type_name'] !== $el['type_name'])
                             || ($prev['level']     !== $el['level']);
            if (!empty($changedParams) || $structuralChange) {
                $modified[] = [
                    'current'        => $el,
                    'parent'         => $prev,
                    'changed_params' => $changedParams,
                ];
            }
        }
    }
    foreach ($parent['elements'] as $guid => $el) {
        if (!isset($current['elements'][$guid])) {
            $el['params'] = $parent['params'][$guid] ?? [];
            $removed[] = $el;
        }
    }

    return ['added' => $added, 'removed' => $removed, 'modified' => $modified];
}

function changed_param_names(array $prev, array $curr): array
{
    $changed = [];
    $allKeys = array_unique(array_merge(array_keys($prev), array_keys($curr)));
    foreach ($allKeys as $k) {
        $a = $prev[$k] ?? null;
        $b = $curr[$k] ?? null;
        if ((string)$a !== (string)$b) $changed[] = $k;
    }
    return $changed;
}

function evaluate_coverage_rule(string $triggerType, array $selector, array $diff): array
{
    switch ($triggerType) {
        case 'element_added':
        case 'category_present':   // First-commit-style: every element is "added"
            return filter_coverage_elements($diff['added'], $selector);
        case 'element_removed':
            return filter_coverage_elements($diff['removed'], $selector);
        case 'element_modified':
            return filter_coverage_modified($diff['modified'], $selector);
        case 'param_changed':
            return filter_coverage_param_changed($diff['modified'], $selector);
        default:
            return [];
    }
}

function matches_coverage_category(array $element, array $selector): bool
{
    $want = $selector['category'] ?? null;
    if ($want === null) return true;
    $cat = (string)($element['category'] ?? '');
    if (is_string($want)) return strcasecmp($cat, $want) === 0;
    if (is_array($want)) {
        foreach ($want as $w) {
            if (strcasecmp($cat, (string)$w) === 0) return true;
        }
    }
    return false;
}

function matches_coverage_param_filter(array $element, array $selector): bool
{
    $filter = $selector['param'] ?? null;
    if (!is_array($filter) || empty($filter)) return true;
    $params = $element['params'] ?? [];
    foreach ($filter as $name => $wantedValue) {
        $actual = $params[strtolower((string)$name)] ?? null;
        if (strcasecmp((string)$actual, (string)$wantedValue) !== 0) return false;
    }
    return true;
}

function filter_coverage_elements(array $elements, array $selector): array
{
    $out = [];
    foreach ($elements as $el) {
        if (!matches_coverage_category($el, $selector)) continue;
        if (!matches_coverage_param_filter($el, $selector)) continue;
        $out[] = [
            'ifc_guid'            => $el['ifc_guid']            ?? null,
            'element_instance_id' => $el['element_instance_id'] ?? null,
            'category'            => $el['category']            ?? null,
            'name'                => $el['name']                ?? null,
        ];
    }
    return $out;
}

function filter_coverage_modified(array $modified, array $selector): array
{
    $out = [];
    foreach ($modified as $m) {
        $cur = $m['current'];
        if (!matches_coverage_category($cur, $selector)) continue;
        if (!matches_coverage_param_filter($cur, $selector)) continue;
        $out[] = [
            'ifc_guid'            => $cur['ifc_guid'],
            'element_instance_id' => $cur['element_instance_id'],
            'category'            => $cur['category'],
            'name'                => $cur['name'],
            'changed_params'      => $m['changed_params'],
        ];
    }
    return $out;
}

function filter_coverage_param_changed(array $modified, array $selector): array
{
    $wanted = $selector['param_name'] ?? null;
    if ($wanted === null) return [];
    $wantedList = is_array($wanted) ? $wanted : [$wanted];
    $wantedLower = array_map(static function($s){ return strtolower((string)$s); }, $wantedList);

    $out = [];
    foreach ($modified as $m) {
        $cur = $m['current'];
        if (!matches_coverage_category($cur, $selector)) continue;
        if (!matches_coverage_param_filter($cur, $selector)) continue;
        $changed = $m['changed_params'] ?? [];
        $hit = false;
        foreach ($wantedLower as $w) {
            if (in_array($w, $changed, true)) { $hit = true; break; }
        }
        if (!$hit) continue;
        $out[] = [
            'ifc_guid'            => $cur['ifc_guid'],
            'element_instance_id' => $cur['element_instance_id'],
            'category'            => $cur['category'],
            'name'                => $cur['name'],
            'changed_params'      => $changed,
        ];
    }
    return $out;
}

} // function_exists guard
