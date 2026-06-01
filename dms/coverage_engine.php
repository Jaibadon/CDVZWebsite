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

function run_coverage_rules(PDO $pdo, int $commitId, ?int $parentCommitId, ?array $precomputedDiff = null): array
{
    $rules = $pdo->query("SELECT * FROM Coverage_Rules WHERE Active = 1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rules)) return ['firings'=>0, 'tags'=>[], 'details'=>[], 'errors'=>[]];

    $errors = [];

    // Reuse the diff commit_create already built for Commit_Diffs, else build it
    // here (standalone call). Either way snapshots load once per commit.
    if ($precomputedDiff !== null) {
        $diff = $precomputedDiff;
    } else {
        try {
            $diff = compute_commit_diff($pdo, $commitId, $parentCommitId);
        } catch (Exception $e) {
            return ['firings'=>0, 'tags'=>[], 'details'=>[], 'errors'=>['Diff build failed: ' . $e->getMessage()]];
        }
    }

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

    // ── Keynote-driven coverage (Erik's keynote category → clause training) ──
    // Runs alongside the JSON rules: a changed element's keynote → category →
    // clause (Keynote_Clause_Map) → roles (NZBC_Clauses). Merged into the tags.
    $notifyRoles = [];
    try {
        $kc = run_keynote_coverage($pdo, $commitId, $diff);
        foreach ($kc['tags'] as $t)    $tagsSet[$t] = true;
        foreach ($kc['notify'] as $rr) $notifyRoles[$rr] = true;
        foreach ($kc['details'] as $d) $details[] = $d;
    } catch (Exception $e) { $errors[] = 'Keynote coverage: ' . $e->getMessage(); }

    return [
        'firings'      => $firings,
        'tags'         => array_keys($tagsSet),
        'notify_roles' => array_keys($notifyRoles),
        'details'      => $details,
        'errors'       => $errors,
    ];
}

/**
 * Load every Element_Instance and its parameters for a commit, keyed by Ifc_Guid.
 * Two queries total (elements + params), regardless of model size.
 */
function load_commit_snapshot(PDO $pdo, int $commitId): array
{
    $stmt = $pdo->prepare(
        "SELECT Element_Instance_ID,
                COALESCE(Element_Uid, Ifc_Guid) AS Guid,
                Category, Name, Type_Name, Level_Name, Geometry_Hash
           FROM Element_Instances
          WHERE Commit_ID = ?"
    );
    $stmt->execute([$commitId]);

    $elements = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $guid = $e['Guid'];
        if (!$guid) continue;
        $elements[$guid] = [
            'element_instance_id' => (int)$e['Element_Instance_ID'],
            'ifc_guid'            => $guid,   // unified identity (Element_Uid or Ifc_Guid)
            'category'            => (string)($e['Category'] ?? ''),
            'name'                => $e['Name'],
            'type_name'           => $e['Type_Name'],
            'level'               => $e['Level_Name'],
            'geometry_hash'       => $e['Geometry_Hash'],
        ];
    }

    // One query for ALL parameters of this commit's elements (avoids N+1).
    // Column is Param_Set (the IFC PSet name), NOT "Pset" — selecting the
    // wrong name here previously made the whole snapshot load throw, which
    // silently disabled ALL coverage rules.
    $stmt = $pdo->prepare(
        "SELECT COALESCE(ei.Element_Uid, ei.Ifc_Guid) AS Guid, ep.Param_Set, ep.Param_Name, ep.Param_Value
           FROM Element_Parameters ep
           INNER JOIN Element_Instances ei ON ei.Element_Instance_ID = ep.Element_Instance_ID
          WHERE ei.Commit_ID = ?"
    );
    $stmt->execute([$commitId]);

    // Key by composite "pset\x1fname" (both lowercased) so two PSets that
    // share a property name (e.g. both have "Reference") don't clobber each
    // other. The \x1f unit-separator can't appear in a real PSet/param name.
    $params = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $guid = $p['Guid'];
        if (!$guid) continue;
        if (!isset($params[$guid])) $params[$guid] = [];
        $key = strtolower((string)($p['Param_Set'] ?? '')) . "\x1f" . strtolower((string)$p['Param_Name']);
        $params[$guid][$key] = (string)($p['Param_Value'] ?? '');
    }

    return ['elements' => $elements, 'params' => $params];
}

/** The bare param name from a composite "pset\x1fname" key. */
function cov_param_basename(string $compositeKey): string
{
    $pos = strpos($compositeKey, "\x1f");
    return $pos === false ? $compositeKey : substr($compositeKey, $pos + 1);
}

/**
 * Look up a param value by bare name (case-insensitive), ignoring which
 * PSet it's in. Returns the first match, or null. Used by the rule
 * "param": {Name: Value} filter, which doesn't specify a PSet.
 */
function cov_param_value(array $paramsMap, string $name): ?string
{
    $want = strtolower($name);
    foreach ($paramsMap as $compositeKey => $val) {
        if (cov_param_basename($compositeKey) === $want) return (string)$val;
    }
    return null;
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
            $geometryChanged = ((string)($prev['geometry_hash'] ?? '') !== (string)($el['geometry_hash'] ?? ''));
            $structuralChange = ($prev['category']  !== $el['category'])
                             || ($prev['name']      !== $el['name'])
                             || ($prev['type_name'] !== $el['type_name'])
                             || ($prev['level']     !== $el['level']);
            if (!empty($changedParams) || $structuralChange || $geometryChanged) {
                $modified[] = [
                    'current'         => $el,
                    'parent'          => $prev,
                    'changed_params'  => $changedParams,
                    'geometry_changed'=> $geometryChanged,
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

/**
 * Distinct bare param NAMES whose value changed between two composite-keyed
 * param maps. Returns lowercased bare names so filter_coverage_param_changed
 * can do a simple in_array() against a rule's param_name.
 */
/**
 * Load current + parent snapshots and compute the structured diff. Shared by
 * run_coverage_rules() and build_and_persist_commit_diff() so snapshots load
 * via one code path.
 */
function compute_commit_diff(PDO $pdo, int $commitId, ?int $parentCommitId): array
{
    $current = load_commit_snapshot($pdo, $commitId);
    $parent  = $parentCommitId ? load_commit_snapshot($pdo, $parentCommitId) : ['elements' => [], 'params' => []];
    return compute_element_diff($current, $parent);
}

/**
 * Compute + persist the changeset to Commit_Diffs / Commit_Diff_Params and
 * return the diff (so the caller can pass it to run_coverage_rules without a
 * second snapshot load). Identity is the unified COALESCE(Element_Uid, Ifc_Guid).
 */
function build_and_persist_commit_diff(PDO $pdo, int $commitId, ?int $parentCommitId): array
{
    $diff = compute_commit_diff($pdo, $commitId, $parentCommitId);

    $insDiff = $pdo->prepare(
        "INSERT INTO Commit_Diffs
           (Commit_ID, Parent_Commit_ID, Element_Uid, Change_Type, Category, Name,
            Changed_Param_Count, Geometry_Changed, Created_At)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $insParam = $pdo->prepare(
        "INSERT INTO Commit_Diff_Params
           (Diff_ID, Param_Set, Param_Name, Old_Value, New_Value, Old_Num, New_Num)
         VALUES (?, NULL, ?, ?, ?, ?, ?)"
    );

    foreach ($diff['added'] as $el) {
        $insDiff->execute([$commitId, $parentCommitId, $el['ifc_guid'] ?? '', 'added',
            $el['category'] ?? null, $el['name'] ?? null, 0, 0]);
    }
    foreach ($diff['removed'] as $el) {
        $insDiff->execute([$commitId, $parentCommitId, $el['ifc_guid'] ?? '', 'removed',
            $el['category'] ?? null, $el['name'] ?? null, 0, 0]);
    }
    foreach ($diff['modified'] as $m) {
        $cur = $m['current']; $prev = $m['parent'];
        $changed = $m['changed_params'] ?? [];
        $insDiff->execute([$commitId, $parentCommitId, $cur['ifc_guid'] ?? '', 'modified',
            $cur['category'] ?? null, $cur['name'] ?? null,
            count($changed), !empty($m['geometry_changed']) ? 1 : 0]);
        $diffId = (int)$pdo->lastInsertId();
        foreach ($changed as $pname) {
            $old = cov_param_value($prev['params'] ?? [], $pname);
            $new = cov_param_value($cur['params'] ?? [], $pname);
            $insParam->execute([
                $diffId, $pname, $old, $new,
                ($old !== null && is_numeric($old)) ? (float)$old : null,
                ($new !== null && is_numeric($new)) ? (float)$new : null,
            ]);
        }
    }
    return $diff;
}

function changed_param_names(array $prev, array $curr): array
{
    $changed = [];
    $allKeys = array_unique(array_merge(array_keys($prev), array_keys($curr)));
    foreach ($allKeys as $k) {
        $a = $prev[$k] ?? null;
        $b = $curr[$k] ?? null;
        if ((string)$a !== (string)$b) $changed[cov_param_basename((string)$k)] = true;
    }
    return array_keys($changed);
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
        // Look the param up by bare name across any PSet (composite keys).
        $actual = cov_param_value($params, (string)$name);
        if ($actual === null || strcasecmp($actual, (string)$wantedValue) !== 0) return false;
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

/**
 * Keynote-driven coverage: map this commit's CHANGED elements (added + modified)
 * to NZBC clauses via their keynote code → category → Keynote_Clause_Map, and
 * the clause's notify-roles. Writes keynote-sourced Commit_NZBC_Tags.
 * Returns ['tags'=>[clauseCodes], 'notify'=>[roles], 'details'=>[...]].
 */
function run_keynote_coverage(PDO $pdo, int $commitId, array $diff): array
{
    $empty = ['tags' => [], 'notify' => [], 'details' => []];

    // Changed elements present in THIS commit (removed elements live in the
    // parent commit; covering those is a future pass).
    $present = $diff['added'] ?? [];
    foreach (($diff['modified'] ?? []) as $m) { if (isset($m['current'])) $present[] = $m['current']; }
    $instIds = [];
    foreach ($present as $el) { if (!empty($el['element_instance_id'])) $instIds[] = (int)$el['element_instance_id']; }
    $instIds = array_values(array_unique($instIds));
    if (empty($instIds)) return $empty;

    // Keynote code per changed element (the Revit Keynote parameter).
    $in = implode(',', array_fill(0, count($instIds), '?'));
    $kq = $pdo->prepare(
        "SELECT DISTINCT ep.Param_Value AS code
           FROM Element_Parameters ep
          WHERE ep.Element_Instance_ID IN ($in)
            AND ep.Param_Value IS NOT NULL AND ep.Param_Value <> ''
            AND (LOWER(ep.Param_Name) = 'keynote' OR UPPER(COALESCE(ep.Builtin_Key,'')) LIKE '%KEYNOTE%')"
    );
    $kq->execute($instIds);
    $changedCodes = array_column($kq->fetchAll(PDO::FETCH_ASSOC), 'code');
    if (empty($changedCodes)) return $empty;

    // code → category (this commit's keynote catalogue).
    $catByCode = [];
    $cn = $pdo->prepare("SELECT Code, Category FROM Commit_Keynotes WHERE Commit_ID = ?");
    $cn->execute([$commitId]);
    foreach ($cn->fetchAll(PDO::FETCH_ASSOC) as $r) $catByCode[(string)$r['Code']] = (string)$r['Category'];

    $maps = $pdo->query("SELECT Match_Type, Match_Value, Clause_Code, Notify_Roles_CSV FROM Keynote_Clause_Map WHERE Active = 1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($maps)) return $empty;

    $clauseRoles = [];
    foreach ($pdo->query("SELECT Clause_Code, Default_Stakeholder_Roles_CSV FROM NZBC_Clauses")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $clauseRoles[(string)$r['Clause_Code']] = (string)$r['Default_Stakeholder_Roles_CSV'];
    }

    $byClause = [];   // clause => ['roles'=>set, 'cats'=>set, 'codes'=>set]
    foreach ($changedCodes as $code) {
        $code = (string)$code;
        $cat  = $catByCode[$code] ?? null;
        foreach ($maps as $mp) {
            $mt = (string)$mp['Match_Type']; $mv = (string)$mp['Match_Value']; $matched = false;
            if ($mt === 'category')         $matched = ($cat !== null && strcasecmp($cat, $mv) === 0);
            elseif ($mt === 'code')         $matched = (strcasecmp($code, $mv) === 0);
            elseif ($mt === 'code_prefix')  $matched = ($mv !== '' && stripos($code, $mv) === 0);
            if (!$matched) continue;
            $clause = (string)$mp['Clause_Code'];
            if (!isset($byClause[$clause])) $byClause[$clause] = ['roles' => [], 'cats' => [], 'codes' => []];
            $roles = trim((string)($mp['Notify_Roles_CSV'] ?? '')) !== '' ? (string)$mp['Notify_Roles_CSV'] : ($clauseRoles[$clause] ?? '');
            foreach (array_filter(array_map('trim', explode(',', $roles))) as $rr) $byClause[$clause]['roles'][$rr] = true;
            if ($cat !== null) $byClause[$clause]['cats'][$cat] = true;
            $byClause[$clause]['codes'][$code] = true;
        }
    }
    if (empty($byClause)) return $empty;

    $insTag = $pdo->prepare(
        "INSERT IGNORE INTO Commit_NZBC_Tags (Commit_ID, Clause_Code, Source, Confidence, Tagged_By, Tagged_At)
         VALUES (?, ?, 'keynote', 0.90, 'system', NOW())"
    );
    $tags = []; $notify = []; $details = [];
    foreach ($byClause as $clause => $info) {
        $insTag->execute([$commitId, $clause]);
        $tags[] = $clause;
        foreach (array_keys($info['roles']) as $rr) $notify[$rr] = true;
        $details[] = [
            'source'       => 'keynote',
            'clause'       => $clause,
            'roles'        => array_keys($info['roles']),
            'keynote_cats' => array_keys($info['cats']),
            'sample_codes' => array_slice(array_keys($info['codes']), 0, 6),
        ];
    }
    return ['tags' => $tags, 'notify' => array_keys($notify), 'details' => $details];
}

} // function_exists guard
