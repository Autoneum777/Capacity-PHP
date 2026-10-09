<?php
declare(strict_types=1);

function scenario_status(mixed $value): ?string
{
    $text = trim((string) ($value ?? ''));
    return in_array($text, ['active', 'inactive', 'RFQ'], true) ? $text : null;
}

function scenario_part_status(array $bundle, array $part): string
{
    $own = scenario_status($part['status'] ?? null);
    if ($own !== null) {
        return $own;
    }
    foreach ($bundle['projects'] ?? [] as $project) {
        if ((int) ($project['id'] ?? 0) === (int) ($part['project_id'] ?? 0)) {
            return scenario_status($project['status'] ?? null) ?? 'active';
        }
    }
    return 'active';
}

function scenario_operation_status(array $bundle, array $operation): string
{
    $own = scenario_status($operation['status'] ?? null);
    if ($own !== null) {
        return $own;
    }
    foreach ($bundle['parts'] ?? [] as $part) {
        if ((int) ($part['id'] ?? 0) === (int) ($operation['part_id'] ?? 0)) {
            return scenario_part_status($bundle, $part);
        }
    }
    foreach ($bundle['projects'] ?? [] as $project) {
        if ((int) ($project['id'] ?? 0) === (int) ($operation['project_id'] ?? 0)) {
            return scenario_status($project['status'] ?? null) ?? 'active';
        }
    }
    return 'active';
}

function scenario_can_view_confidential(array $auth): bool
{
    return in_array('scenarios.view_confidential', $auth['permissions'], true);
}

function scenario_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM scenarios WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function scenario_hidden(PDO $pdo, array $auth, int $id): bool
{
    if (scenario_can_view_confidential($auth)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT is_confidential FROM scenarios WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row && (int) ($row['is_confidential'] ?? 0) === 1;
}

function scenario_decode(string $raw): array
{
    $bundle = json_decode($raw, true);
    if (!is_array($bundle)) {
        json_response(500, ['error' => 'Niepoprawny snapshot']);
    }
    $bundle['version'] = 2;
    foreach ([
        'projects', 'parts', 'operations', 'working_days', 'project_volumes', 'project_volumes_contract',
        'operation_volume_by_year', 'part_volume_by_year', 'part_volume_contract_by_year',
        'part_volume_share_by_year', 'part_volume_contract_share_by_year', 'operation_set_members',
        'project_eop_extensions', 'part_designations', 'audit_log', 'machine_locations', 'machine_statuses',
        'allocation_moves', 'volume_edits',
    ] as $key) {
        if (!isset($bundle[$key]) || !is_array($bundle[$key])) {
            $bundle[$key] = [];
        }
    }
    return $bundle;
}

function scenario_save(PDO $pdo, int $id, array $bundle): void
{
    $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE scenarios SET snapshot = ?, updated_at = ? WHERE id = ?')->execute([$json, date('Y-m-d H:i:s'), $id]);
}

function scenario_audit(array &$bundle, string $author, string $note, ?int $projectId = null, ?int $partId = null, ?int $machineId = null, ?int $operationId = null): void
{
    $next = 1;
    foreach ($bundle['audit_log'] as $entry) {
        $next = max($next, (int) ($entry['id'] ?? 0) + 1);
    }
    $bundle['audit_log'][] = [
        'id' => $next,
        'note_date' => date('Y-m-d'),
        'author' => $author,
        'note' => $note,
        'note_type' => 'auto',
        'project_id' => $projectId,
        'machine_id' => $machineId,
        'part_id' => $partId,
        'operation_id' => $operationId,
    ];
}

function scenario_archived(array $row): bool
{
    return isset($row['archived_at']) && trim((string) $row['archived_at']) !== '';
}

function scenario_require_editable(PDO $pdo, int $id): array
{
    $auth = require_permission($pdo, 'scenarios.edit');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    if (scenario_archived($row)) {
        json_response(400, ['error' => 'Scenariusz zarchiwizowany — edycja jest wyłączona.']);
    }
    return ['auth' => $auth, 'row' => $row, 'bundle' => scenario_decode((string) $row['snapshot'])];
}

function scenario_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $column) {
        $columns[] = (string) $column['name'];
    }
    return $columns;
}

function scenario_insert_rows(PDO $pdo, string $table, array $rows, array $omit = [], bool $replace = false): void
{
    if ($rows === []) {
        return;
    }
    $skip = array_fill_keys($omit, true);
    $columns = array_values(array_filter(scenario_columns($pdo, $table), fn(string $name): bool => !isset($skip[$name])));
    if ($columns === []) {
        return;
    }
    $sql = ($replace ? 'INSERT OR REPLACE' : 'INSERT') . ' INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $values = [];
        foreach ($columns as $column) {
            $values[] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        $stmt->execute($values);
    }
}

function scenario_copy_table(PDO $memory, string $table, bool $empty): void
{
    $where = $empty ? ' WHERE 0' : '';
    $memory->exec('CREATE TABLE ' . $table . ' AS SELECT * FROM src.' . $table . $where);
}

function scenario_calculator_database(PDO $pdo, int $id): ?PDO
{
    $auth = require_permission($pdo, 'scenarios.view');
    if (scenario_hidden($pdo, $auth, $id)) {
        return null;
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        return null;
    }
    $bundle = scenario_decode((string) $row['snapshot']);
    $includeRfq = !scenario_archived($row);
    $memory = new PDO('sqlite::memory:');
    $memory->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $memory->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $memory->exec('PRAGMA foreign_keys = OFF');
    $path = str_replace('\\', '/', database_path());
    $memory->exec('ATTACH DATABASE ' . $memory->quote($path) . ' AS src');
    foreach ([
        'machines', 'process_phases', 'part_designations', 'admin_settings', 'working_days',
        'machine_baseline_materials', 'machine_baseline_material_parts', 'machine_baseline_material_volumes',
    ] as $table) {
        scenario_copy_table($memory, $table, false);
    }
    foreach ([
        'projects', 'parts', 'operations', 'project_volumes', 'project_volumes_contract',
        'part_volume_by_year', 'part_volume_contract_by_year', 'part_volume_share_by_year',
        'part_volume_contract_share_by_year', 'operation_volume_by_year', 'operation_set_members',
        'project_eop_extensions',
    ] as $table) {
        scenario_copy_table($memory, $table, true);
    }
    $memory->exec('DETACH DATABASE src');
    if ($bundle['part_designations'] !== []) {
        $memory->exec('DELETE FROM part_designations');
        scenario_insert_rows($memory, 'part_designations', $bundle['part_designations']);
    }
    if ($bundle['working_days'] !== []) {
        $memory->exec('DELETE FROM working_days');
        scenario_insert_rows($memory, 'working_days', $bundle['working_days']);
    }
    $operations = [];
    $projectIds = [];
    foreach ($bundle['operations'] as $operation) {
        $status = scenario_operation_status($bundle, $operation);
        if ($status === 'inactive' || ($status === 'RFQ' && !$includeRfq)) {
            continue;
        }
        $operations[] = $operation;
        $projectIds[(int) ($operation['project_id'] ?? 0)] = true;
    }
    scenario_insert_rows($memory, 'projects', $bundle['projects']);
    scenario_insert_rows($memory, 'parts', $bundle['parts']);
    scenario_insert_rows($memory, 'operations', $operations);
    foreach ([
        'project_volumes', 'project_volumes_contract', 'part_volume_by_year', 'part_volume_contract_by_year',
        'part_volume_share_by_year', 'part_volume_contract_share_by_year', 'operation_volume_by_year',
        'operation_set_members', 'project_eop_extensions',
    ] as $table) {
        scenario_insert_rows($memory, $table, $bundle[$table]);
    }
    $ids = array_keys(array_filter($projectIds));
    if ($ids !== []) {
        $memory->exec('UPDATE projects SET status = \'active\' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
    $statusStmt = $memory->prepare('UPDATE machines SET status = ? WHERE id = ?');
    foreach ($bundle['machine_statuses'] as $item) {
        $status = scenario_status($item['status'] ?? null);
        if ($status !== null) {
            $statusStmt->execute([$status, (int) ($item['machine_id'] ?? 0)]);
        }
    }
    $locationStmt = $memory->prepare('UPDATE machines SET location = ? WHERE id = ?');
    foreach ($bundle['machine_locations'] as $item) {
        $location = trim((string) ($item['location'] ?? ''));
        $locationStmt->execute([$location !== '' ? $location : null, (int) ($item['machine_id'] ?? 0)]);
    }
    return $memory;
}

function scenario_export_live(PDO $pdo): array
{
    $bundle = ['version' => 2];
    $tables = [
        'projects', 'parts', 'operations', 'working_days', 'project_volumes', 'project_volumes_contract',
        'operation_volume_by_year', 'part_volume_by_year', 'part_volume_contract_by_year',
        'part_volume_share_by_year', 'part_volume_contract_share_by_year', 'operation_set_members',
        'project_eop_extensions', 'part_designations',
    ];
    foreach ($tables as $table) {
        $bundle[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
    }
    $bundle['machine_locations'] = $pdo->query('SELECT id AS machine_id, location FROM machines')->fetchAll();
    $bundle['machine_statuses'] = $pdo->query('SELECT id AS machine_id, status FROM machines')->fetchAll();
    $bundle['audit_log'] = [];
    $bundle['allocation_moves'] = [];
    $bundle['volume_edits'] = [];
    return $bundle;
}

function scenario_public_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'created_at' => $row['created_at'],
        'scenario_scope' => (string) ($row['scenario_scope'] ?? ''),
        'source_scenario_id' => isset($row['source_scenario_id']) && $row['source_scenario_id'] !== null ? (int) $row['source_scenario_id'] : null,
        'source_scenario_name' => $row['source_scenario_name'] ?? null,
        'source_call_off_comparison_id' => isset($row['source_call_off_comparison_id']) && $row['source_call_off_comparison_id'] !== null ? (int) $row['source_call_off_comparison_id'] : null,
        'source_call_off_name' => $row['source_call_off_name'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'archived_at' => $row['archived_at'] ?? null,
        'is_confidential' => (int) ($row['is_confidential'] ?? 0),
    ];
}

function scenario_list(PDO $pdo): void
{
    $auth = require_permission($pdo, 'scenarios.view');
    $archived = ($_GET['archived'] ?? '') === '1' || strtolower((string) ($_GET['archived'] ?? '')) === 'true';
    $sql = 'SELECT s.*, ps.name AS source_scenario_name, co.name AS source_call_off_name
            FROM scenarios s
            LEFT JOIN scenarios ps ON ps.id = s.source_scenario_id
            LEFT JOIN call_off_comparisons co ON co.id = s.source_call_off_comparison_id
            WHERE ' . ($archived ? 's.archived_at IS NOT NULL' : 's.archived_at IS NULL') . '
            ORDER BY ' . ($archived ? 's.archived_at DESC, s.created_at DESC' : 's.created_at DESC');
    $rows = [];
    foreach ($pdo->query($sql) as $row) {
        if (!scenario_can_view_confidential($auth) && (int) ($row['is_confidential'] ?? 0) === 1) {
            continue;
        }
        $rows[] = scenario_public_row($row);
    }
    json_response(200, $rows);
}

function scenario_get(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.view');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $public = scenario_public_row($row);
    $public['snapshot'] = scenario_decode((string) $row['snapshot']);
    json_response(200, $public);
}

function scenario_create(PDO $pdo): void
{
    $auth = require_permission($pdo, 'scenarios.edit');
    $body = request_json();
    $name = trim((string) ($body['name'] ?? ''));
    $scope = trim((string) ($body['scenario_scope'] ?? $body['scenarioScope'] ?? ''));
    if ($name === '') {
        json_response(400, ['error' => 'Nazwa scenariusza jest wymagana']);
    }
    if ($scope === '') {
        json_response(400, ['error' => 'Zakres scenariusza jest wymagany (pole tekstowe).']);
    }
    $sourceId = is_numeric($body['sourceScenarioId'] ?? null) ? (int) $body['sourceScenarioId'] : 0;
    $callOffId = is_numeric($body['sourceCallOffComparisonId'] ?? null) ? (int) $body['sourceCallOffComparisonId'] : 0;
    $confidential = ($body['confidential'] ?? false) === true || ($body['confidential'] ?? '') === 1 || strtolower((string) ($body['confidential'] ?? '')) === 'true';
    if ($confidential && !scenario_can_view_confidential($auth)) {
        json_response(403, ['error' => 'Brak uprawnienia do tworzenia scenariuszy poufnych.']);
    }
    if ($sourceId > 0) {
        if (scenario_hidden($pdo, $auth, $sourceId)) {
            json_response(404, ['error' => 'Nie znaleziono scenariusza źródłowego']);
        }
        $parent = scenario_row($pdo, $sourceId);
        if (!$parent) {
            json_response(404, ['error' => 'Nie znaleziono scenariusza źródłowego']);
        }
        $bundle = scenario_decode((string) $parent['snapshot']);
    } else {
        $sourceId = 0;
        $bundle = scenario_export_live($pdo);
    }
    if ($callOffId > 0) {
        $cmp = $pdo->prepare('SELECT id FROM call_off_comparisons WHERE id = ?');
        $cmp->execute([$callOffId]);
        if (!$cmp->fetch()) {
            json_response(400, ['error' => 'Nie znaleziono porównania Call off']);
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM call_off_volumes WHERE comparison_id = ?');
        $count->execute([$callOffId]);
        if ((int) $count->fetchColumn() === 0) {
            json_response(400, ['error' => 'Wybrane porównanie Call off nie ma zaimportowanych wolumenów.']);
        }
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO scenarios (name, created_at, snapshot, scenario_scope, source_scenario_id, source_call_off_comparison_id, is_confidential, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $name,
        $now,
        json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $scope,
        $sourceId > 0 ? $sourceId : null,
        $callOffId > 0 ? $callOffId : null,
        $confidential ? 1 : 0,
        $now,
    ]);
    scenario_get($pdo, (int) $pdo->lastInsertId());
}

function scenario_find(array $rows, int $id): int
{
    foreach ($rows as $index => $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $index;
        }
    }
    return -1;
}

function scenario_patch_project(PDO $pdo, int $id, int $projectId): void
{
    $opened = scenario_require_editable($pdo, $id);
    $body = request_json();
    $bundle = $opened['bundle'];
    $index = scenario_find($bundle['projects'], $projectId);
    if ($index < 0) {
        json_response(404, ['error' => 'Projekt nie występuje w tym scenariuszu.']);
    }
    $project = $bundle['projects'][$index];
    $notes = [];
    if (array_key_exists('status', $body)) {
        $status = scenario_status($body['status']);
        if ($status === null) {
            json_response(400, ['error' => 'Niedozwolony status.']);
        }
        if ((string) ($project['status'] ?? 'active') !== $status) {
            $notes[] = 'status: "' . ($project['status'] ?? 'active') . '" → "' . $status . '"';
            $project['status'] = $status;
        }
    }
    if (array_key_exists('sop', $body)) {
        $sop = format_sop_eop($body['sop']);
        if ((string) ($project['sop'] ?? '') !== $sop) {
            $notes[] = 'SOP: "' . ($project['sop'] ?? '') . '" → "' . $sop . '"';
            $project['sop'] = $sop;
        }
    }
    if (array_key_exists('eop', $body)) {
        $eop = format_sop_eop($body['eop']);
        if ((string) ($project['eop'] ?? '') !== $eop) {
            $notes[] = 'EOP: "' . ($project['eop'] ?? '') . '" → "' . $eop . '"';
            $project['eop'] = $eop;
        }
    }
    if ($notes === []) {
        json_response(200, ['id' => $projectId, 'status' => $project['status'] ?? 'active', 'sop' => $project['sop'] ?? null, 'eop' => $project['eop'] ?? null, 'unchanged' => true]);
    }
    $bundle['projects'][$index] = $project;
    scenario_audit($bundle, user_login_label($opened['auth']['user']), 'Scenariusz: ' . implode('; ', $notes), $projectId);
    scenario_save($pdo, $id, $bundle);
    json_response(200, ['id' => $projectId, 'status' => $project['status'] ?? 'active', 'sop' => $project['sop'] ?? null, 'eop' => $project['eop'] ?? null]);
}

function scenario_patch_part(PDO $pdo, int $id, int $partId): void
{
    $opened = scenario_require_editable($pdo, $id);
    $bundle = $opened['bundle'];
    $index = scenario_find($bundle['parts'], $partId);
    if ($index < 0) {
        json_response(404, ['error' => 'Detal nie występuje w tym scenariuszu.']);
    }
    $body = request_json();
    $status = array_key_exists('status', $body) && $body['status'] !== null ? scenario_status($body['status']) : null;
    if (array_key_exists('status', $body) && $body['status'] !== null && $status === null) {
        json_response(400, ['error' => 'Niedozwolony status.']);
    }
    $part = $bundle['parts'][$index];
    $before = $part['status'] ?? null;
    if ($before === $status || ($before === null && $status === null)) {
        json_response(200, ['id' => $partId, 'status' => $before, 'unchanged' => true]);
    }
    if ($status === null) {
        unset($part['status']);
    } else {
        $part['status'] = $status;
    }
    $bundle['parts'][$index] = $part;
    scenario_audit($bundle, user_login_label($opened['auth']['user']), 'Scenariusz: status detalu #' . $partId . ' → ' . ($status ?? 'projekt'), (int) ($part['project_id'] ?? 0), $partId);
    scenario_save($pdo, $id, $bundle);
    json_response(200, ['id' => $partId, 'status' => $status]);
}

function scenario_patch_operation(PDO $pdo, int $id, int $operationId): void
{
    $opened = scenario_require_editable($pdo, $id);
    $bundle = $opened['bundle'];
    $index = scenario_find($bundle['operations'], $operationId);
    if ($index < 0) {
        json_response(404, ['error' => 'Operacja nie występuje w tym scenariuszu.']);
    }
    $body = request_json();
    $status = array_key_exists('status', $body) && $body['status'] !== null ? scenario_status($body['status']) : null;
    if (array_key_exists('status', $body) && $body['status'] !== null && $status === null) {
        json_response(400, ['error' => 'Niedozwolony status.']);
    }
    $operation = $bundle['operations'][$index];
    if ($status === null) {
        unset($operation['status']);
    } else {
        $operation['status'] = $status;
    }
    $bundle['operations'][$index] = $operation;
    scenario_audit(
        $bundle,
        user_login_label($opened['auth']['user']),
        'Scenariusz: status operacji #' . $operationId . ' → ' . ($status ?? 'detal'),
        (int) ($operation['project_id'] ?? 0),
        (int) ($operation['part_id'] ?? 0),
        (int) ($operation['machine_id'] ?? 0),
        $operationId
    );
    scenario_save($pdo, $id, $bundle);
    json_response(200, ['id' => $operationId, 'status' => $status]);
}

function scenario_put_part_volumes(PDO $pdo, int $id, int $partId): void
{
    $opened = scenario_require_editable($pdo, $id);
    $body = request_json();
    $mode = trim((string) ($body['mode'] ?? 'override'));
    if ($mode !== 'override' && $mode !== 'project') {
        json_response(400, ['error' => 'Podaj mode: override lub project.']);
    }
    $applyProduction = ($body['applyProduction'] ?? true) !== false;
    $applyContract = ($body['applyContract'] ?? true) !== false;
    if (!$applyProduction && !$applyContract) {
        json_response(400, ['error' => 'Zaznacz wolumen produkcyjny albo kontraktowy.']);
    }
    $bundle = $opened['bundle'];
    $index = scenario_find($bundle['parts'], $partId);
    if ($index < 0) {
        json_response(404, ['error' => 'Detal nie występuje w tym scenariuszu.']);
    }
    $part = $bundle['parts'][$index];
    $entries = [];
    if ($mode === 'override') {
        foreach (is_array($body['volumes'] ?? null) ? $body['volumes'] : [] as $volume) {
            if (!is_array($volume) || !is_numeric($volume['year'] ?? null) || !is_numeric($volume['volume_value'] ?? null)) {
                continue;
            }
            $year = (int) $volume['year'];
            $value = (float) $volume['volume_value'];
            if ($year < 1900 || $year > 2200 || $value < 0) {
                continue;
            }
            $unit = in_array($volume['volume_unit'] ?? '', ['annual', 'monthly', 'weekly'], true) ? $volume['volume_unit'] : 'annual';
            $entries[] = ['part_id' => $partId, 'year' => $year, 'volume_value' => $value, 'volume_unit' => $unit, 'volume_origin' => 'manual_year'];
        }
        if ($entries === []) {
            json_response(400, ['error' => 'Podaj przynajmniej jeden rok z wolumenem (≥ 0).']);
        }
    }
    $keep = function (array $rows) use ($partId): array {
        return array_values(array_filter($rows, fn(array $row): bool => (int) ($row['part_id'] ?? 0) !== $partId));
    };
    if ($applyProduction) {
        $part['volume_mode'] = $mode === 'project' ? 'project' : 'override';
        $bundle['part_volume_by_year'] = $mode === 'project' ? $keep($bundle['part_volume_by_year']) : array_merge($keep($bundle['part_volume_by_year']), $entries);
    }
    if ($applyContract) {
        $part['contract_volume_mode'] = $mode === 'project' ? 'project' : 'override';
        $bundle['part_volume_contract_by_year'] = $mode === 'project' ? $keep($bundle['part_volume_contract_by_year']) : array_merge($keep($bundle['part_volume_contract_by_year']), $entries);
    }
    $bundle['parts'][$index] = $part;
    $years = array_map(fn(array $row): int => (int) $row['year'], $entries);
    scenario_audit(
        $bundle,
        user_login_label($opened['auth']['user']),
        'Scenariusz: wolumen detalu #' . $partId . ' (' . $mode . ')' . ($years !== [] ? ', lata ' . implode(', ', $years) : ''),
        (int) ($part['project_id'] ?? 0),
        $partId
    );
    scenario_save($pdo, $id, $bundle);
    $own = fn(array $rows): array => array_values(array_filter($rows, fn(array $row): bool => (int) ($row['part_id'] ?? 0) === $partId));
    json_response(200, [
        'id' => $partId,
        'volume_mode' => $part['volume_mode'] ?? 'project',
        'contract_volume_mode' => $part['contract_volume_mode'] ?? 'project',
        'volumes' => $own($bundle['part_volume_by_year']),
        'contract_volumes' => $own($bundle['part_volume_contract_by_year']),
    ]);
}

function scenario_machine_map(array &$rows, int $machineId, string $field): int
{
    foreach ($rows as $index => $row) {
        if ((int) ($row['machine_id'] ?? 0) === $machineId) {
            return $index;
        }
    }
    $rows[] = ['machine_id' => $machineId];
    return count($rows) - 1;
}

function scenario_patch_machine(PDO $pdo, int $id, int $machineId, string $field): void
{
    $opened = scenario_require_editable($pdo, $id);
    $machine = $pdo->prepare('SELECT id FROM machines WHERE id = ?');
    $machine->execute([$machineId]);
    if (!$machine->fetch()) {
        json_response(404, ['error' => 'Machine not found']);
    }
    $body = request_json();
    $bundle = $opened['bundle'];
    $key = $field === 'status' ? 'machine_statuses' : 'machine_locations';
    $index = scenario_machine_map($bundle[$key], $machineId, $field);
    if ($field === 'status') {
        $status = scenario_status($body['status'] ?? null);
        if ($status === null) {
            json_response(400, ['error' => 'Niedozwolony status.']);
        }
        $bundle[$key][$index]['status'] = $status;
        $value = $status;
    } else {
        $location = trim((string) ($body['location'] ?? ''));
        $bundle[$key][$index]['location'] = $location !== '' ? $location : null;
        $value = $bundle[$key][$index]['location'];
    }
    scenario_audit($bundle, user_login_label($opened['auth']['user']), 'Scenariusz: maszyna #' . $machineId . ' ' . $field . ' → ' . (string) $value, null, null, $machineId);
    scenario_save($pdo, $id, $bundle);
    json_response(200, ['id' => $machineId, $field => $value]);
}

function scenario_addable(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.view');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $bundle = scenario_decode((string) $row['snapshot']);
    $have = [];
    foreach ($bundle['projects'] as $project) {
        $have[(int) ($project['id'] ?? 0)] = true;
    }
    $rows = [];
    foreach ($pdo->query('SELECT id, client, name, sop, eop, status FROM projects ORDER BY client, name') as $project) {
        if (isset($have[(int) $project['id']])) {
            continue;
        }
        $project['id'] = (int) $project['id'];
        $rows[] = $project;
    }
    json_response(200, $rows);
}

function scenario_copy_project(PDO $pdo, array &$bundle, int $projectId): bool
{
    foreach ($bundle['projects'] as $project) {
        if ((int) ($project['id'] ?? 0) === $projectId) {
            return false;
        }
    }
    $project = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $project->execute([$projectId]);
    $row = $project->fetch();
    if (!$row) {
        return false;
    }
    $bundle['projects'][] = $row;
    $tables = [
        'parts' => 'SELECT * FROM parts WHERE project_id = ?',
        'operations' => 'SELECT * FROM operations WHERE project_id = ?',
        'project_volumes' => 'SELECT * FROM project_volumes WHERE project_id = ?',
        'project_volumes_contract' => 'SELECT * FROM project_volumes_contract WHERE project_id = ?',
        'project_eop_extensions' => 'SELECT * FROM project_eop_extensions WHERE project_id = ?',
    ];
    $partIds = [];
    foreach ($tables as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$projectId]);
        foreach ($stmt as $item) {
            $bundle[$key][] = $item;
            if ($key === 'parts') {
                $partIds[] = (int) $item['id'];
            }
        }
    }
    if ($partIds !== []) {
        $marks = implode(',', array_fill(0, count($partIds), '?'));
        foreach ([
            'part_volume_by_year' => 'SELECT * FROM part_volume_by_year WHERE part_id IN (' . $marks . ')',
            'part_volume_contract_by_year' => 'SELECT * FROM part_volume_contract_by_year WHERE part_id IN (' . $marks . ')',
            'part_volume_share_by_year' => 'SELECT * FROM part_volume_share_by_year WHERE part_id IN (' . $marks . ')',
            'part_volume_contract_share_by_year' => 'SELECT * FROM part_volume_contract_share_by_year WHERE part_id IN (' . $marks . ')',
        ] as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($partIds);
            foreach ($stmt as $item) {
                $bundle[$key][] = $item;
            }
        }
        $stmt = $pdo->prepare('SELECT * FROM part_designations WHERE id IN (SELECT designation_id FROM parts WHERE id IN (' . $marks . ') AND designation_id IS NOT NULL)');
        $stmt->execute($partIds);
        $have = [];
        foreach ($bundle['part_designations'] as $designation) {
            $have[(int) ($designation['id'] ?? 0)] = true;
        }
        foreach ($stmt as $item) {
            if (!isset($have[(int) $item['id']])) {
                $bundle['part_designations'][] = $item;
            }
        }
    }
    $opIds = [];
    foreach ($bundle['operations'] as $operation) {
        if ((int) ($operation['project_id'] ?? 0) === $projectId) {
            $opIds[] = (int) $operation['id'];
        }
    }
    if ($opIds !== []) {
        $marks = implode(',', array_fill(0, count($opIds), '?'));
        foreach ([
            'operation_volume_by_year' => 'SELECT * FROM operation_volume_by_year WHERE operation_id IN (' . $marks . ')',
            'operation_set_members' => 'SELECT * FROM operation_set_members WHERE operation_id IN (' . $marks . ')',
        ] as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($opIds);
            foreach ($stmt as $item) {
                $bundle[$key][] = $item;
            }
        }
    }
    return true;
}

function scenario_add_projects(PDO $pdo, int $id): void
{
    $opened = scenario_require_editable($pdo, $id);
    $body = request_json();
    $bundle = $opened['bundle'];
    $added = [];
    $skipped = [];
    $missing = [];
    foreach (is_array($body['projectIds'] ?? null) ? $body['projectIds'] : [] as $projectId) {
        $projectId = (int) $projectId;
        if ($projectId <= 0) {
            continue;
        }
        $exists = scenario_find($bundle['projects'], $projectId) >= 0;
        if ($exists) {
            $skipped[] = $projectId;
            continue;
        }
        if (scenario_copy_project($pdo, $bundle, $projectId)) {
            $added[] = $projectId;
        } else {
            $missing[] = $projectId;
        }
    }
    if ($added !== []) {
        scenario_audit($bundle, user_login_label($opened['auth']['user']), 'Scenariusz: dodano projekty ' . implode(', ', $added));
        scenario_save($pdo, $id, $bundle);
    }
    json_response(200, [
        'ok' => true,
        'addedProjectIds' => $added,
        'skippedAlreadyInBundle' => $skipped,
        'notFoundInProduction' => $missing,
    ]);
}

function scenario_deploy_secret(): string
{
    return hash('sha256', database_path() . '|capacity-deploy');
}

function scenario_deploy_token(int $id, string $phrase, int $expires): string
{
    $payload = $id . '|' . $phrase . '|' . $expires;
    $sig = hash_hmac('sha256', $payload, scenario_deploy_secret());
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

function scenario_deploy_valid(int $id, string $phrase, string $token): bool
{
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if (!is_string($raw)) {
        return false;
    }
    $parts = explode('|', $raw);
    if (count($parts) !== 4) {
        return false;
    }
    [$sid, $stored, $expires, $sig] = $parts;
    if ((int) $sid !== $id || !hash_equals($stored, $phrase) || (int) $expires < time()) {
        return false;
    }
    $payload = $sid . '|' . $stored . '|' . $expires;
    return hash_equals(hash_hmac('sha256', $payload, scenario_deploy_secret()), $sig);
}

function scenario_deploy_challenge(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.edit');
    if (scenario_hidden($pdo, $auth, $id) || !scenario_row($pdo, $id)) {
        json_response(404, ['error' => 'Not found']);
    }
    $phrase = strtoupper(bin2hex(random_bytes(5)));
    $expires = time() + 600;
    json_response(200, [
        'phrase' => $phrase,
        'expiresInSec' => 600,
        'deployToken' => scenario_deploy_token($id, $phrase, $expires),
    ]);
}

function scenario_strip_status(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        unset($row['status']);
        $out[] = $row;
    }
    return $out;
}

function scenario_apply_subset(PDO $pdo, int $id): void
{
    $opened = scenario_require_editable($pdo, $id);
    $body = request_json();
    $phrase = trim((string) ($body['challengePhrase'] ?? ''));
    $token = trim((string) ($body['deployToken'] ?? ''));
    if ($token === '' || !scenario_deploy_valid($id, $phrase, $token)) {
        json_response(400, ['error' => 'Nieprawidłowy lub wygasły kod potwierdzenia. Kliknij ponownie „Wgraj…”, pobierz nowy kod i wpisz go dokładnie (wielkość liter ma znaczenie).']);
    }
    $projectIds = [];
    foreach (is_array($body['projectIds'] ?? null) ? $body['projectIds'] : [] as $projectId) {
        $projectId = (int) $projectId;
        if ($projectId > 0) {
            $projectIds[$projectId] = true;
        }
    }
    $projectIds = array_keys($projectIds);
    if ($projectIds === []) {
        json_response(400, ['error' => 'Podaj tablicę projectIds (co najmniej jeden projekt).']);
    }
    $bundle = $opened['bundle'];
    foreach ($projectIds as $projectId) {
        if (scenario_find($bundle['projects'], $projectId) < 0) {
            json_response(400, ['error' => 'Projekt #' . $projectId . ' nie występuje w zawartości scenariusza.']);
        }
    }
    $partIds = [];
    if (is_array($body['partIds'] ?? null) && $body['partIds'] !== []) {
        foreach ($body['partIds'] as $partId) {
            $partId = (int) $partId;
            if ($partId > 0) {
                $partIds[$partId] = true;
            }
        }
    }
    $partsOnly = $partIds !== [];
    $parts = [];
    foreach ($bundle['parts'] as $part) {
        if (!in_array((int) ($part['project_id'] ?? 0), $projectIds, true)) {
            continue;
        }
        if ($partsOnly && !isset($partIds[(int) ($part['id'] ?? 0)])) {
            continue;
        }
        $parts[] = $part;
    }
    if ($partsOnly) {
        foreach (array_keys($partIds) as $partId) {
            $found = false;
            foreach ($bundle['parts'] as $part) {
                if ((int) ($part['id'] ?? 0) === $partId) {
                    $found = true;
                    if (!in_array((int) ($part['project_id'] ?? 0), $projectIds, true)) {
                        json_response(400, ['error' => 'Detal #' . $partId . ' nie należy do wybranych projektów.']);
                    }
                }
            }
            if (!$found) {
                json_response(400, ['error' => 'Detal (część) #' . $partId . ' nie występuje w scenariuszu.']);
            }
        }
    }
    if ($parts === []) {
        json_response(400, ['error' => $partsOnly ? 'Brak detali do wgrania (sprawdź zaznaczenie).' : 'Brak części przypisanych do wybranych projektów w scenariuszu.']);
    }
    $partSet = [];
    foreach ($parts as $part) {
        $partSet[(int) $part['id']] = true;
    }
    $operations = [];
    foreach ($bundle['operations'] as $operation) {
        if (in_array((int) ($operation['project_id'] ?? 0), $projectIds, true) && isset($partSet[(int) ($operation['part_id'] ?? 0)])) {
            $operations[] = $operation;
        }
    }
    $opSet = [];
    foreach ($operations as $operation) {
        $opSet[(int) $operation['id']] = true;
    }
    $inProjects = fn(array $rows, string $key): array => array_values(array_filter($rows, fn(array $row): bool => in_array((int) ($row[$key] ?? 0), $projectIds, true)));
    $inParts = fn(array $rows): array => array_values(array_filter($rows, fn(array $row): bool => isset($partSet[(int) ($row['part_id'] ?? 0)])));
    $inOps = fn(array $rows): array => array_values(array_filter($rows, fn(array $row): bool => isset($opSet[(int) ($row['operation_id'] ?? 0)])));
    $projects = array_values(array_filter($bundle['projects'], fn(array $row): bool => in_array((int) ($row['id'] ?? 0), $projectIds, true)));
    $pdo->beginTransaction();
    try {
        if (!$partsOnly) {
            $delete = $pdo->prepare('DELETE FROM projects WHERE id = ?');
            foreach ($projectIds as $projectId) {
                $delete->execute([$projectId]);
            }
        } else {
            $delete = $pdo->prepare('DELETE FROM parts WHERE id = ?');
            foreach (array_keys($partSet) as $partId) {
                $delete->execute([$partId]);
            }
            $pdo->prepare('DELETE FROM project_volumes WHERE project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')')->execute($projectIds);
            $pdo->prepare('DELETE FROM project_volumes_contract WHERE project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')')->execute($projectIds);
        }
        $designationIds = [];
        foreach ($parts as $part) {
            if (is_numeric($part['designation_id'] ?? null)) {
                $designationIds[(int) $part['designation_id']] = true;
            }
        }
        $designations = array_values(array_filter($bundle['part_designations'], fn(array $row): bool => isset($designationIds[(int) ($row['id'] ?? 0)])));
        scenario_insert_rows($pdo, 'part_designations', $designations, [], true);
        scenario_insert_rows($pdo, 'projects', $projects, [], true);
        scenario_insert_rows($pdo, 'project_eop_extensions', $inProjects($bundle['project_eop_extensions'], 'project_id'), ['id']);
        scenario_insert_rows($pdo, 'parts', scenario_strip_status($parts));
        scenario_insert_rows($pdo, 'project_volumes', $inProjects($bundle['project_volumes'], 'project_id'));
        scenario_insert_rows($pdo, 'project_volumes_contract', $inProjects($bundle['project_volumes_contract'], 'project_id'));
        scenario_insert_rows($pdo, 'part_volume_by_year', $inParts($bundle['part_volume_by_year']));
        scenario_insert_rows($pdo, 'part_volume_contract_by_year', $inParts($bundle['part_volume_contract_by_year']));
        scenario_insert_rows($pdo, 'part_volume_share_by_year', $inParts($bundle['part_volume_share_by_year']));
        scenario_insert_rows($pdo, 'part_volume_contract_share_by_year', $inParts($bundle['part_volume_contract_share_by_year']));
        scenario_insert_rows($pdo, 'operations', scenario_strip_status($operations));
        scenario_insert_rows($pdo, 'operation_set_members', array_values(array_filter(
            $bundle['operation_set_members'],
            fn(array $row): bool => isset($opSet[(int) ($row['operation_id'] ?? 0)]) && isset($partSet[(int) ($row['part_id'] ?? 0)])
        )));
        scenario_insert_rows($pdo, 'operation_volume_by_year', $inOps($bundle['operation_volume_by_year']), ['id']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(400, ['error' => 'Nie udało się wgrać wybranych danych do produkcji.']);
    }
    $message = $partsOnly
        ? 'Wgrano ' . count($parts) . ' detali (części) do produkcji w ramach ' . count($projectIds) . ' projektów (bez zmiany dni roboczych).'
        : 'Wgrano ' . count($projectIds) . ' projektów (' . count($parts) . ' części łącznie) do produkcji (bez zmiany dni roboczych).';
    json_response(200, [
        'ok' => true,
        'message' => $message,
        'projectsTouched' => count($projectIds),
        'partsTouched' => count($parts),
        'mode' => $partsOnly ? 'parts' : 'projects',
    ]);
}

function scenario_overlay_machines(PDO $pdo, int $id, array $rows): array
{
    $auth = current_auth($pdo);
    if (!$auth || scenario_hidden($pdo, $auth, $id)) {
        return $rows;
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        return $rows;
    }
    $bundle = scenario_decode((string) $row['snapshot']);
    $locations = [];
    foreach ($bundle['machine_locations'] as $item) {
        $locations[(int) ($item['machine_id'] ?? 0)] = $item['location'] ?? null;
    }
    $statuses = [];
    foreach ($bundle['machine_statuses'] as $item) {
        $status = scenario_status($item['status'] ?? null);
        if ($status !== null) {
            $statuses[(int) ($item['machine_id'] ?? 0)] = $status;
        }
    }
    foreach ($rows as &$machine) {
        $machineId = (int) $machine['id'];
        if (array_key_exists($machineId, $locations)) {
            $machine['location'] = $locations[$machineId];
        }
        if (isset($statuses[$machineId])) {
            $machine['status'] = $statuses[$machineId];
        }
    }
    unset($machine);
    return $rows;
}

function scenario_next_operation_id(PDO $pdo, int $scenarioId, array $bundle): int
{
    $max = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM operations')->fetchColumn();
    foreach ($bundle['operations'] as $operation) {
        $max = max($max, (int) ($operation['id'] ?? 0));
    }
    try {
        $reserved = $pdo->prepare('SELECT COALESCE(MAX(reserved_id), 0) FROM scenario_id_reservations WHERE entity = \'operation\'');
        $reserved->execute();
        $max = max($max, (int) $reserved->fetchColumn());
        $next = $max + 1;
        $pdo->prepare('INSERT INTO scenario_id_reservations (scenario_id, entity, reserved_id) VALUES (?, \'operation\', ?)')->execute([$scenarioId, $next]);
        return $next;
    } catch (Throwable) {
        return $max + 1;
    }
}

function scenario_execute_allocation(PDO $pdo, array $body): void
{
    $scenarioId = (int) ($body['scenarioId'] ?? 0);
    $opened = scenario_require_editable($pdo, $scenarioId);
    $bundle = $opened['bundle'];
    $memory = scenario_calculator_database($pdo, $scenarioId);
    if ($memory === null) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $operationId = (int) ($body['operationId'] ?? 0);
    $targetId = (int) ($body['targetMachineId'] ?? 0);
    $year = (int) ($body['year'] ?? date('Y'));
    $index = scenario_find($bundle['operations'], $operationId);
    if ($index < 0) {
        json_response(400, ['success' => false, 'error' => 'Operation not found']);
    }
    $operation = $bundle['operations'][$index];
    $target = $memory->prepare('SELECT id, status, internal_number FROM machines WHERE id = ?');
    $target->execute([$targetId]);
    $targetRow = $target->fetch();
    if (!$targetRow) {
        json_response(400, ['success' => false, 'error' => 'Nie znaleziono maszyny docelowej.']);
    }
    if (strtolower((string) $targetRow['status']) === 'inactive') {
        json_response(400, ['success' => false, 'error' => 'Maszyna jest nieaktywna. Wolumen można przenieść tylko na maszynę aktywną lub RFQ.']);
    }
    if ($targetId === (int) ($operation['machine_id'] ?? 0)) {
        json_response(400, ['success' => false, 'error' => 'Wybierz inną maszynę.']);
    }
    $contract = allocation_bool($body['useContractualVolumes'] ?? false);
    $stats = allocation_machine_ops($memory, (int) $operation['machine_id'], $year, $contract, $operationId);
    if ($stats === null) {
        json_response(400, ['success' => false, 'error' => 'Brak danych capacity (ustawienia roku lub maszyna).']);
    }
    $current = $stats['ops'][$operationId] ?? null;
    if ($current === null) {
        json_response(400, ['success' => false, 'error' => 'Dla wybranego roku wolumen tej operacji wynosi 0.']);
    }
    $unit = in_array($body['volumeUnit'] ?? '', ['monthly', 'weekly'], true) ? (string) $body['volumeUnit'] : 'annual';
    $volume = is_numeric($body['volumeToMove'] ?? null) ? (float) $body['volumeToMove'] : 0.0;
    $moved = allocation_move_weekly($volume, $unit, $stats['settings'], (float) $current['fraction']);
    if ((float) $current['volume_value'] <= 1e-9 && (int) $current['months'] > 0) {
        if ($volume > 1e-6) {
            json_response(400, ['success' => false, 'error' => 'Dla roku bez wolumenu można przypisać detal tylko z przeniesieniem 0.']);
        }
        $moveWeekly = 0.0;
    } else {
        if ($volume <= 0) {
            json_response(400, ['success' => false, 'error' => 'Wolumen musi być dodatni.']);
        }
        $moveWeekly = $moved['effective'];
        if ($moveWeekly > (float) $current['weekly'] + 1e-6) {
            json_response(400, ['success' => false, 'error' => 'Wolumen do przeniesienia przekracza wolumen operacji dla wybranego roku.']);
        }
    }
    $fromMonth = is_numeric($body['effectiveFromMonth'] ?? null) ? (int) $body['effectiveFromMonth'] : 0;
    $month = $fromMonth >= 1 && $fromMonth <= 12 ? $fromMonth : null;
    $week = null;
    if ($month !== null) {
        $fromWeek = is_numeric($body['effectiveFromWeek'] ?? null) ? (int) $body['effectiveFromWeek'] : 1;
        $week = min(5, max(1, $fromWeek));
    }
    $remaining = max(0.0, (float) $current['weekly'] - $moveWeekly);
    $parentBefore = null;
    foreach ($bundle['operation_volume_by_year'] as $row) {
        if ((int) ($row['operation_id'] ?? 0) === $operationId && (int) ($row['year'] ?? 0) === $year) {
            $parentBefore = $row;
        }
    }
    $bundle['operation_volume_by_year'] = array_values(array_filter(
        $bundle['operation_volume_by_year'],
        fn(array $row): bool => !((int) ($row['operation_id'] ?? 0) === $operationId && (int) ($row['year'] ?? 0) === $year)
    ));
    $bundle['operation_volume_by_year'][] = [
        'operation_id' => $operationId,
        'year' => $year,
        'volume_value' => $remaining,
        'volume_unit' => 'weekly',
        'source' => 'allocation',
        'volume_value_before' => $month !== null ? (float) $current['weekly'] : null,
        'effective_from_month' => $month,
        'effective_from_week' => $week,
    ];
    $child = $operation;
    unset($child['status']);
    $childId = scenario_next_operation_id($pdo, $scenarioId, $bundle);
    $child['id'] = $childId;
    $child['machine_id'] = $targetId;
    $child['volume_value'] = 0;
    $child['volume_unit'] = 'weekly';
    $child['split_from_operation_id'] = $operationId;
    $child['use_alternative_in_calculator'] = 0;
    $cycle = calc_cycle($operation);
    $child['cycle_time_seconds'] = $cycle['cycle'];
    $child['nests_count'] = $cycle['nests'];
    $bundle['operations'][] = $child;
    $years = [$year => true];
    foreach ($bundle['project_volumes'] as $row) {
        if ((int) ($row['project_id'] ?? 0) === (int) ($operation['project_id'] ?? 0)) {
            $years[(int) $row['year']] = true;
        }
    }
    foreach ($bundle['operation_volume_by_year'] as $row) {
        $years[(int) ($row['year'] ?? 0)] = true;
    }
    foreach (array_keys($years) as $item) {
        if ($item <= 0) {
            continue;
        }
        $bundle['operation_volume_by_year'][] = [
            'operation_id' => $childId,
            'year' => $item,
            'volume_value' => $item === $year ? $moveWeekly : 0,
            'volume_unit' => 'weekly',
            'source' => 'allocation',
            'volume_value_before' => $item === $year && $month !== null ? 0 : null,
            'effective_from_month' => $item === $year ? $month : null,
            'effective_from_week' => $item === $year ? $week : null,
        ];
    }
    $moveId = 1;
    foreach ($bundle['allocation_moves'] as $move) {
        $moveId = max($moveId, (int) ($move['id'] ?? 0) + 1);
    }
    $bundle['allocation_moves'][] = [
        'id' => $moveId,
        'batchId' => (string) ($body['batchId'] ?? ('batch-' . $moveId)),
        'at' => date('c'),
        'steps' => [[
            'sourceOperationId' => $operationId,
            'childOperationId' => $childId,
            'year' => $year,
            'sourceMachineId' => (int) $operation['machine_id'],
            'targetMachineId' => $targetId,
            'partId' => (int) ($operation['part_id'] ?? 0),
            'parentYearBefore' => $parentBefore,
        ]],
    ];
    $label = trim((string) $targetRow['internal_number']) !== '' ? (string) $targetRow['internal_number'] : '#' . $targetId;
    scenario_audit(
        $bundle,
        user_login_label($opened['auth']['user']),
        'Scenariusz: przeniesiono wolumen operacji #' . $operationId . ' na maszynę ' . $label . ' (rok ' . $year . ').',
        (int) ($operation['project_id'] ?? 0),
        (int) ($operation['part_id'] ?? 0),
        $targetId,
        $childId
    );
    scenario_save($pdo, $scenarioId, $bundle);
    json_response(200, ['success' => true]);
}

function scenario_moves(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.view');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $bundle = scenario_decode((string) $row['snapshot']);
    $items = [];
    foreach ($bundle['allocation_moves'] as $move) {
        $step = $move['steps'][0] ?? [];
        $items[] = [
            'kind' => 'allocation',
            'id' => (int) ($move['id'] ?? 0),
            'at' => (string) ($move['at'] ?? ''),
            'years' => array_map(fn(array $step): int => (int) ($step['year'] ?? 0), $move['steps'] ?? []),
            'partLabel' => 'detal #' . (int) ($step['partId'] ?? 0),
            'sourceLabel' => 'maszyna #' . (int) ($step['sourceMachineId'] ?? 0),
            'targetLabel' => 'maszyna #' . (int) ($step['targetMachineId'] ?? 0),
            'canUndo' => false,
        ];
    }
    foreach ($bundle['volume_edits'] as $edit) {
        $items[] = [
            'kind' => 'volume',
            'id' => (int) ($edit['id'] ?? 0),
            'at' => (string) ($edit['at'] ?? ''),
            'years' => $edit['years'] ?? [],
            'partLabel' => 'detal #' . (int) ($edit['partId'] ?? 0),
            'sourceLabel' => '',
            'targetLabel' => (string) ($edit['mode'] ?? ''),
            'canUndo' => false,
        ];
    }
    usort($items, fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
    if ($items !== []) {
        $items[0]['canUndo'] = true;
    }
    json_response(200, $items);
}

function scenario_undo(PDO $pdo, int $id): void
{
    $opened = scenario_require_editable($pdo, $id);
    $bundle = $opened['bundle'];
    $latest = null;
    $latestKind = '';
    $latestIndex = -1;
    foreach ($bundle['allocation_moves'] as $index => $move) {
        if ($latest === null || strcmp((string) ($move['at'] ?? ''), (string) $latest['at']) > 0) {
            $latest = $move;
            $latestKind = 'allocation';
            $latestIndex = $index;
        }
    }
    foreach ($bundle['volume_edits'] as $index => $edit) {
        if ($latest === null || strcmp((string) ($edit['at'] ?? ''), (string) $latest['at']) > 0) {
            $latest = $edit;
            $latestKind = 'volume';
            $latestIndex = $index;
        }
    }
    if ($latest === null) {
        json_response(400, ['error' => 'Brak ruchu do cofnięcia.']);
    }
    if ($latestKind === 'allocation') {
        foreach ($latest['steps'] ?? [] as $step) {
            $childId = (int) ($step['childOperationId'] ?? 0);
            $parentId = (int) ($step['sourceOperationId'] ?? 0);
            $year = (int) ($step['year'] ?? 0);
            $bundle['operations'] = array_values(array_filter($bundle['operations'], fn(array $row): bool => (int) ($row['id'] ?? 0) !== $childId));
            $bundle['operation_volume_by_year'] = array_values(array_filter(
                $bundle['operation_volume_by_year'],
                fn(array $row): bool => (int) ($row['operation_id'] ?? 0) !== $childId && !((int) ($row['operation_id'] ?? 0) === $parentId && (int) ($row['year'] ?? 0) === $year)
            ));
            if (is_array($step['parentYearBefore'] ?? null)) {
                $bundle['operation_volume_by_year'][] = $step['parentYearBefore'];
            }
        }
        array_splice($bundle['allocation_moves'], $latestIndex, 1);
    } else {
        json_response(400, ['error' => 'Cofnięcie zmiany wolumenu detalu będzie dostępne w kolejnym kroku.']);
    }
    scenario_audit($bundle, user_login_label($opened['auth']['user']), 'Scenariusz: cofnięto ostatnią alokację.');
    scenario_save($pdo, $id, $bundle);
    json_response(200, ['ok' => true, 'label' => 'alokacja']);
}

function scenario_history(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.view');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $bundle = scenario_decode((string) $row['snapshot']);
    $rows = $bundle['audit_log'];
    usort($rows, fn(array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
    $text = trim((string) ($_GET['text'] ?? ''));
    if ($text !== '') {
        $rows = array_values(array_filter($rows, fn(array $row): bool => stripos((string) ($row['note'] ?? ''), $text) !== false));
    }
    json_response(200, $rows);
}

function scenario_history_filters(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.view');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    $row = scenario_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $bundle = scenario_decode((string) $row['snapshot']);
    $projects = [];
    $clients = [];
    foreach ($bundle['projects'] as $project) {
        $projects[] = ['id' => (int) $project['id'], 'client' => (string) ($project['client'] ?? ''), 'name' => (string) ($project['name'] ?? '')];
        $client = strtoupper(trim((string) ($project['client'] ?? '')));
        if ($client !== '') {
            $clients[$client] = true;
        }
    }
    $authors = [];
    foreach ($bundle['audit_log'] as $entry) {
        $author = trim((string) ($entry['author'] ?? ''));
        if ($author !== '') {
            $authors[$author] = true;
        }
    }
    json_response(200, [
        'projects' => $projects,
        'clients' => array_keys($clients),
        'machines' => $pdo->query('SELECT id, sap_number, internal_number, type FROM machines ORDER BY internal_number')->fetchAll(),
        'details' => [],
        'authors' => array_keys($authors),
    ]);
}

function scenario_archive(PDO $pdo, int $id, bool $archive): void
{
    $auth = require_permission($pdo, 'scenarios.edit');
    if (scenario_hidden($pdo, $auth, $id) || !scenario_row($pdo, $id)) {
        json_response(404, ['error' => 'Not found']);
    }
    $pdo->prepare('UPDATE scenarios SET archived_at = ?, updated_at = ? WHERE id = ?')->execute([
        $archive ? date('Y-m-d H:i:s') : null,
        date('Y-m-d H:i:s'),
        $id,
    ]);
    json_response(200, ['ok' => true]);
}

function scenario_delete(PDO $pdo, int $id): void
{
    $auth = require_permission($pdo, 'scenarios.delete');
    if (scenario_hidden($pdo, $auth, $id)) {
        json_response(404, ['error' => 'Not found']);
    }
    $pdo->prepare('DELETE FROM scenario_id_reservations WHERE scenario_id = ?')->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM scenarios WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_response(404, ['error' => 'Not found']);
    }
    write_no_content();
}

function scenario_update(PDO $pdo, int $id): void
{
    $opened = scenario_require_editable($pdo, $id);
    $body = request_json();
    $name = array_key_exists('name', $body) ? trim((string) $body['name']) : (string) $opened['row']['name'];
    $scope = array_key_exists('scenario_scope', $body) ? trim((string) $body['scenario_scope']) : (string) ($opened['row']['scenario_scope'] ?? '');
    if ($name === '') {
        json_response(400, ['error' => 'Nazwa scenariusza jest wymagana']);
    }
    $snapshot = $opened['row']['snapshot'];
    if (isset($body['snapshot']) && is_array($body['snapshot'])) {
        $snapshot = json_encode($body['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $pdo->prepare('UPDATE scenarios SET name = ?, scenario_scope = ?, snapshot = ?, updated_at = ? WHERE id = ?')
        ->execute([$name, $scope, $snapshot, date('Y-m-d H:i:s'), $id]);
    $row = scenario_row($pdo, $id);
    json_response(200, scenario_public_row($row));
}

function handle_scenarios(string $method, string $path): bool
{
    if (!str_starts_with($path, '/scenarios')) {
        return false;
    }
    $pdo = open_database();
    bootstrap_admin($pdo);
    if ($method === 'GET' && $path === '/scenarios') {
        scenario_list($pdo);
    }
    if ($method === 'POST' && $path === '/scenarios') {
        scenario_create($pdo);
    }
    if (preg_match('#^/scenarios/(\d+)$#', $path, $match) === 1) {
        $id = (int) $match[1];
        if ($method === 'GET') {
            scenario_get($pdo, $id);
        }
        if ($method === 'PUT') {
            scenario_update($pdo, $id);
        }
        if ($method === 'DELETE') {
            scenario_delete($pdo, $id);
        }
    }
    if (preg_match('#^/scenarios/(\d+)/(.+)$#', $path, $match) === 1) {
        $id = (int) $match[1];
        $rest = $match[2];
        if ($method === 'GET' && $rest === 'deploy-challenge') {
            scenario_deploy_challenge($pdo, $id);
        }
        if ($method === 'GET' && $rest === 'addable-projects') {
            scenario_addable($pdo, $id);
        }
        if ($method === 'POST' && $rest === 'add-projects-from-capacity') {
            scenario_add_projects($pdo, $id);
        }
        if ($method === 'POST' && $rest === 'apply-subset-to-production') {
            scenario_apply_subset($pdo, $id);
        }
        if ($method === 'POST' && $rest === 'apply-to-production') {
            json_response(400, ['error' => 'Pełne zastąpienie produkcji nie jest włączone. Wybierz projekty lub detale.']);
        }
        if ($method === 'GET' && $rest === 'allocation-moves') {
            scenario_moves($pdo, $id);
        }
        if ($method === 'GET' && $rest === 'allocation-report') {
            scenario_moves($pdo, $id);
        }
        if ($method === 'POST' && $rest === 'allocation-moves/undo') {
            scenario_undo($pdo, $id);
        }
        if ($method === 'GET' && $rest === 'history') {
            scenario_history($pdo, $id);
        }
        if ($method === 'GET' && $rest === 'history/filters') {
            scenario_history_filters($pdo, $id);
        }
        if ($method === 'POST' && $rest === 'archive') {
            scenario_archive($pdo, $id, true);
        }
        if ($method === 'POST' && $rest === 'unarchive') {
            scenario_archive($pdo, $id, false);
        }
        if ($method === 'PATCH' && preg_match('#^projects/(\d+)$#', $rest, $item) === 1) {
            scenario_patch_project($pdo, $id, (int) $item[1]);
        }
        if ($method === 'PATCH' && preg_match('#^parts/(\d+)$#', $rest, $item) === 1) {
            scenario_patch_part($pdo, $id, (int) $item[1]);
        }
        if ($method === 'PUT' && preg_match('#^parts/(\d+)/volumes$#', $rest, $item) === 1) {
            scenario_put_part_volumes($pdo, $id, (int) $item[1]);
        }
        if ($method === 'PATCH' && preg_match('#^operations/(\d+)$#', $rest, $item) === 1) {
            scenario_patch_operation($pdo, $id, (int) $item[1]);
        }
        if ($method === 'PATCH' && preg_match('#^machines/(\d+)/location$#', $rest, $item) === 1) {
            scenario_patch_machine($pdo, $id, (int) $item[1], 'location');
        }
        if ($method === 'PATCH' && preg_match('#^machines/(\d+)/status$#', $rest, $item) === 1) {
            scenario_patch_machine($pdo, $id, (int) $item[1], 'status');
        }
    }
    return false;
}
