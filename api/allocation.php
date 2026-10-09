<?php
declare(strict_types=1);

function allocation_bool(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 'true';
}

function allocation_year_cells(PDO $pdo, int $year, bool $contract): array
{
    $cells = [];
    foreach (calculator_machines($pdo, $year, $year, ['active'], [], null, $contract) as $machine) {
        $cells[(int) $machine['machine_id']] = $machine;
    }
    return $cells;
}

function allocation_machine_ops(PDO $pdo, int $machineId, int $year, bool $contract, ?int $stripOperationId = null): ?array
{
    $machines = calculator_filtered_machines($pdo, ['active'], [], [$machineId]);
    $machine = $machines[$machineId] ?? null;
    if ($machine === null) {
        return null;
    }
    $ops = calculator_operations($pdo, [$machineId])[$machineId] ?? [];
    $ctx = calc_context($pdo);
    $settings = baseline_volume_settings(calc_settings($pdo, $year), $machine);
    $volumeMap = $ctx['opVolumes'][$year] ?? [];
    if ($stripOperationId !== null && isset($volumeMap[$stripOperationId])) {
        $row = $volumeMap[$stripOperationId];
        $row['effective_from_month'] = null;
        $row['effective_from_week'] = null;
        $row['volume_value_before'] = null;
        $volumeMap[$stripOperationId] = $row;
    }
    $shares = calc_allocation_shares($volumeMap, $ctx['parentById'], $ctx['childrenByParent'], $year, null, $settings);
    $machineOee = isset($machine['oee_override']) && is_numeric($machine['oee_override']) ? (float) $machine['oee_override'] : null;
    $availability = calc_machine_availability($machine, $settings, $machineOee);
    $baseline = baseline_bundle($pdo);
    $materials = $baseline['materials'];
    $rows = [];
    $required = 0.0;
    foreach ($ops as $op) {
        $resolved = calc_resolve_operation($op, $year, null, $ctx, $volumeMap, $shares, $settings, $contract);
        if ($resolved === null || !calc_active_in_period($op, $year, null, $resolved['count_after_eop'])) {
            continue;
        }
        $weekly = calc_weekly_volume($resolved['volume_value'], $resolved['volume_unit'], $settings, $op, $year, $resolved['volume_origin'], $resolved['count_after_eop']);
        $seconds = calc_operation_demand($machine, $op, $weekly, $materials)['seconds'];
        $required += $seconds;
        $months = calc_production_months($op['sop'], $op['eop'], $year);
        $fraction = 1.0;
        if (!$resolved['count_after_eop'] && $months > 0 && $resolved['volume_origin'] !== 'manual_year') {
            $fraction = $months / 12;
        }
        if ($months <= 0 && !$resolved['count_after_eop']) {
            $fraction = 0.0;
        }
        $rows[(int) $op['operation_id']] = [
            'weekly' => $weekly,
            'seconds' => $seconds,
            'volume_value' => (float) $resolved['volume_value'],
            'volume_unit' => (string) $resolved['volume_unit'],
            'fraction' => $fraction,
            'months' => $months,
        ];
    }
    $monthSum = 0.0;
    for ($month = 1; $month <= 12; $month++) {
        $monthShares = calc_allocation_shares($volumeMap, $ctx['parentById'], $ctx['childrenByParent'], $year, $month, $settings);
        $monthRequired = 0.0;
        foreach ($ops as $op) {
            $resolved = calc_resolve_operation($op, $year, $month, $ctx, $volumeMap, $monthShares, $settings, $contract);
            if ($resolved === null || !calc_active_in_period($op, $year, $month, $resolved['count_after_eop'])) {
                continue;
            }
            $weekly = calc_weekly_volume($resolved['volume_value'], $resolved['volume_unit'], $settings, $op, $year, $resolved['volume_origin'], $resolved['count_after_eop']);
            $monthRequired += calc_operation_demand($machine, $op, $weekly, $materials)['seconds'];
        }
        $monthSum += $availability > 0 ? (int) round(($monthRequired / $availability) * 100) : ($monthRequired > 0 ? 100 : 0);
    }
    return [
        'machine' => $machine,
        'ops' => $rows,
        'required' => $required,
        'availability' => $availability,
        'load_percent' => (int) round($monthSum / 12),
        'settings' => $settings,
        'usage' => max(0.1, min(1.0, (float) ($machine['machine_usage'] ?? 1))),
    ];
}

function allocation_move_weekly(float $volume, string $unit, array $settings, float $fraction): array
{
    $scale = $fraction > 1e-12 ? $fraction : 1.0;
    if ($unit === 'weekly') {
        return ['effective' => $volume, 'base' => $volume / $scale];
    }
    $effective = calc_volume_to_weekly($volume, $unit, $settings) * $scale;
    return ['effective' => $effective, 'base' => $effective / $scale];
}

function allocation_volume_for_remaining(array $hint, float $remaining, float $groupWeekly): array
{
    $remaining = min(300.0, max(0.0, $remaining));
    $current = (float) $hint['current_load_percent'];
    $unit = (string) $hint['suggested_volume_unit'];
    $base = $groupWeekly > 1e-9 ? $groupWeekly : (float) $hint['weekly_volume_effective'];
    $opLoad = (float) $hint['op_load_percent'];
    if ($current <= $remaining + 1e-9 || $base <= 1e-9 || $opLoad <= 1e-9) {
        return ['volume' => 0.0, 'insufficient' => $current > $remaining + 1e-9];
    }
    $fraction = min(1.0, max(0.0, ($current - $remaining) / $opLoad));
    $moveWeekly = $fraction * $base;
    $yearFraction = (float) $hint['year_fraction'] > 1e-12 ? (float) $hint['year_fraction'] : 1.0;
    $moveBase = $moveWeekly / $yearFraction;
    $weeks = max(1.0, (float) $hint['working_weeks_per_year']);
    if ($unit === 'weekly') {
        $volume = $moveBase;
    } elseif ($unit === 'monthly') {
        $volume = ($moveBase * $weeks) / 12;
    } else {
        $volume = $moveBase * $weeks;
    }
    return ['volume' => round($volume, 6), 'insufficient' => round(max(0, $current - $opLoad)) > $remaining + 1];
}

function allocation_database(PDO $pdo): PDO
{
    $scenarioId = (int) ($_GET['scenarioId'] ?? 0);
    if ($scenarioId <= 0) {
        return $pdo;
    }
    $copy = scenario_calculator_database($pdo, $scenarioId);
    if ($copy === null) {
        json_response(404, ['error' => 'Scenariusz nie znaleziony']);
    }
    return $copy;
}

function allocation_overloaded(PDO $pdo): void
{
    require_permission($pdo, 'projects.view');
    $pdo = allocation_database($pdo);
    $year = (int) ($_GET['year'] ?? date('Y'));
    $threshold = is_numeric($_GET['threshold'] ?? null) ? (float) $_GET['threshold'] : 100.0;
    $machines = [];
    foreach (allocation_year_cells($pdo, $year, false) as $machine) {
        $cell = $machine['years'][$year] ?? null;
        if ($cell === null || (int) $cell['load_percent'] <= $threshold) {
            continue;
        }
        $machines[] = [
            'machine_id' => (int) $machine['machine_id'],
            'internal_number' => $machine['internal_number'],
            'type' => $machine['type'],
            'year' => $year,
            'load_percent' => (int) $cell['load_percent'],
            'required_sec_per_week' => (int) $cell['required_sec_per_week'],
            'availability_sec_per_week' => $cell['availability_sec_per_week'],
        ];
    }
    json_response(200, ['year' => $year, 'threshold' => $threshold, 'machines' => $machines]);
}

function allocation_candidate_row(array $machine, int $year): array
{
    $cell = $machine['years'][$year];
    return [
        'machine_id' => (int) $machine['machine_id'],
        'internal_number' => $machine['internal_number'],
        'type' => $machine['type'],
        'sap_number' => $machine['sap_number'],
        'load_percent' => (int) $cell['load_percent'],
        'free_capacity_sec_per_week' => max(0, (float) $cell['availability_sec_per_week'] - (float) $cell['required_sec_per_week']),
        'location' => $machine['location'],
    ];
}

function allocation_candidates(PDO $pdo, int $machineId): void
{
    require_permission($pdo, 'projects.view');
    $pdo = allocation_database($pdo);
    $year = (int) ($_GET['year'] ?? date('Y'));
    $maxLoad = is_numeric($_GET['maxLoad'] ?? null) ? (float) $_GET['maxLoad'] : 90.0;
    $contract = allocation_bool($_GET['useContractualVolumes'] ?? false);
    $includeOverloaded = allocation_bool($_GET['includeOverloadedAlternatives'] ?? false);
    $cells = allocation_year_cells($pdo, $year, $contract);
    if (!isset($cells[$machineId])) {
        json_response(200, ['machineId' => $machineId, 'year' => $year, 'candidates' => []]);
    }
    $ids = [];
    $nest = $pdo->prepare(
        'SELECT nm2.machine_id FROM nest_machines nm1
         JOIN nest_machines nm2 ON nm2.nest_id = nm1.nest_id
         WHERE nm1.machine_id = ? AND nm2.machine_id != ?'
    );
    $nest->execute([$machineId, $machineId]);
    foreach ($nest as $row) {
        $ids[(int) $row['machine_id']] = true;
    }
    $alts = $pdo->prepare('SELECT alternative_machine_id FROM machine_alternatives WHERE machine_id = ?');
    $alts->execute([$machineId]);
    $altIds = [];
    foreach ($alts as $row) {
        $altId = (int) $row['alternative_machine_id'];
        $ids[$altId] = true;
        $altIds[] = $altId;
    }
    $sourceLocation = $cells[$machineId]['location'];
    $build = function (bool $respectLocation) use ($ids, $cells, $year, $maxLoad, $sourceLocation): array {
        $result = [];
        foreach (array_keys($ids) as $id) {
            if (!isset($cells[$id])) {
                continue;
            }
            $row = allocation_candidate_row($cells[$id], $year);
            if ($row['load_percent'] >= $maxLoad) {
                continue;
            }
            if ($respectLocation && $sourceLocation !== null && $row['location'] !== null && (string) $row['location'] !== (string) $sourceLocation) {
                continue;
            }
            unset($row['location']);
            $result[] = $row;
        }
        usort($result, fn(array $a, array $b): int => $b['free_capacity_sec_per_week'] <=> $a['free_capacity_sec_per_week']);
        return $result;
    };
    $result = $build(true);
    if ($result === [] && $ids !== []) {
        $result = $build(false);
    }
    if ($includeOverloaded) {
        $present = [];
        foreach ($result as $row) {
            $present[$row['machine_id']] = true;
        }
        $extra = [];
        foreach ($altIds as $id) {
            if (isset($present[$id]) || !isset($cells[$id])) {
                continue;
            }
            $row = allocation_candidate_row($cells[$id], $year);
            unset($row['location']);
            $extra[] = $row;
        }
        usort($extra, fn(array $a, array $b): int => $b['free_capacity_sec_per_week'] <=> $a['free_capacity_sec_per_week']);
        $result = [...$result, ...$extra];
    }
    json_response(200, ['machineId' => $machineId, 'year' => $year, 'candidates' => $result]);
}

function allocation_hint(PDO $pdo, int $machineId): void
{
    require_permission($pdo, 'projects.view');
    $pdo = allocation_database($pdo);
    $year = (int) ($_GET['year'] ?? date('Y'));
    $operationId = (int) ($_GET['operationId'] ?? 0);
    if ($operationId <= 0) {
        json_response(400, ['error' => 'Parametr operationId jest wymagany.']);
    }
    $ids = [$operationId];
    foreach (preg_split('/[,;]+/', (string) ($_GET['operationIds'] ?? '')) ?: [] as $part) {
        if (is_numeric($part) && (int) $part > 0) {
            $ids[] = (int) $part;
        }
    }
    $ids = array_values(array_unique($ids));
    $contract = allocation_bool($_GET['useContractualVolumes'] ?? false);
    $stats = allocation_machine_ops($pdo, $machineId, $year, $contract);
    if ($stats === null) {
        json_response(400, ['error' => 'Brak danych capacity (ustawienia roku lub maszyna).']);
    }
    $chosen = [];
    foreach ($ids as $id) {
        if (isset($stats['ops'][$id])) {
            $chosen[] = $stats['ops'][$id];
        }
    }
    if ($chosen === []) {
        json_response(400, ['error' => 'Operacja nie należy do tej maszyny lub brak w danym roku.']);
    }
    $seconds = 0.0;
    $weekly = 0.0;
    $primary = $chosen[0];
    foreach ($chosen as $row) {
        $seconds += $row['seconds'];
        $weekly += $row['weekly'];
        if ($row['weekly'] > $primary['weekly']) {
            $primary = $row;
        }
    }
    $fraction = $primary['fraction'] > 1e-12 ? $primary['fraction'] : 1.0;
    $opLoad = $stats['required'] > 1e-9 ? ($seconds / $stats['required']) * $stats['load_percent'] : 0.0;
    $hint = [
        'current_load_percent' => $stats['load_percent'],
        'op_load_percent' => round($opLoad, 6),
        'suggested_volume_to_reach_100' => 0,
        'suggested_volume_unit' => $primary['volume_unit'],
        'effective_volume_value' => $primary['volume_value'],
        'effective_volume_unit' => $primary['volume_unit'],
        'load_ratio_sum' => $stats['required'],
        'usage' => $stats['usage'],
        'op_ratio_contrib' => $seconds,
        'weekly_volume_effective' => round($weekly, 6),
        'working_weeks_per_year' => (float) $stats['settings']['working_weeks_per_year'],
        'year_fraction' => round($fraction, 6),
    ];
    $hint['suggested_volume_to_reach_100'] = allocation_volume_for_remaining($hint, 100, $hint['weekly_volume_effective'])['volume'];
    json_response(200, $hint);
}

function allocation_upsert_year(PDO $pdo, int $operationId, int $year, float $value, string $unit, ?float $before, ?int $month, ?int $week): void
{
    $pdo->prepare(
        'INSERT INTO operation_volume_by_year
            (operation_id, year, volume_value, volume_unit, source, volume_value_before, effective_from_month, effective_from_week)
         VALUES (?, ?, ?, ?, \'allocation\', ?, ?, ?)
         ON CONFLICT(operation_id, year) DO UPDATE SET
            volume_value = excluded.volume_value,
            volume_unit = excluded.volume_unit,
            source = excluded.source,
            volume_value_before = excluded.volume_value_before,
            effective_from_month = excluded.effective_from_month,
            effective_from_week = excluded.effective_from_week'
    )->execute([$operationId, $year, $value, $unit, $before, $month, $week]);
}

function allocation_seed_years(PDO $pdo, int $projectId, int $parentId, int $childId, int $year, float $value, string $unit, ?int $month, ?int $week): void
{
    $years = [$year => true];
    $projectYears = $pdo->prepare('SELECT year FROM project_volumes WHERE project_id = ?');
    $projectYears->execute([$projectId]);
    foreach ($projectYears as $row) {
        $years[(int) $row['year']] = true;
    }
    $opYears = $pdo->prepare(
        'SELECT DISTINCT year FROM operation_volume_by_year
         WHERE operation_id = ? OR operation_id IN (SELECT id FROM operations WHERE project_id = ?)'
    );
    $opYears->execute([$parentId, $projectId]);
    foreach ($opYears as $row) {
        $years[(int) $row['year']] = true;
    }
    foreach (array_keys($years) as $item) {
        if ($item === $year) {
            allocation_upsert_year($pdo, $childId, $item, $value, $unit, $month !== null ? 0.0 : null, $month, $week);
        } else {
            allocation_upsert_year($pdo, $childId, $item, 0, 'weekly', null, null, null);
        }
    }
}

function allocation_execute(PDO $pdo): void
{
    require_any_permission($pdo, ['projects.edit', 'projects.create_rfq']);
    $body = request_json();
    if ((int) ($body['scenarioId'] ?? 0) > 0) {
        scenario_execute_allocation($pdo, $body);
    }
    $operationId = (int) ($body['operationId'] ?? 0);
    $targetId = (int) ($body['targetMachineId'] ?? 0);
    $year = (int) ($body['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
    $opStmt = $pdo->prepare(
        'SELECT o.*, p.status AS project_status, p.sop, p.eop
         FROM operations o JOIN projects p ON p.id = o.project_id WHERE o.id = ?'
    );
    $opStmt->execute([$operationId]);
    $op = $opStmt->fetch();
    if (!$op) {
        json_response(400, ['success' => false, 'error' => 'Operation not found']);
    }
    $auth = write_project_actor($pdo, (int) $op['project_id']);
    $target = $pdo->prepare('SELECT id, status, internal_number FROM machines WHERE id = ?');
    $target->execute([$targetId]);
    $targetRow = $target->fetch();
    if (!$targetRow) {
        json_response(400, ['success' => false, 'error' => 'Nie znaleziono maszyny docelowej.']);
    }
    if (strtolower((string) $targetRow['status']) === 'inactive') {
        $label = trim((string) $targetRow['internal_number']) !== '' ? (string) $targetRow['internal_number'] : '#' . $targetId;
        json_response(400, ['success' => false, 'error' => 'Maszyna ' . $label . ' jest nieaktywna. Wolumen można przenieść tylko na maszynę aktywną lub RFQ.']);
    }
    if ($targetId === (int) $op['machine_id']) {
        json_response(400, ['success' => false, 'error' => 'Wybierz inną maszynę.']);
    }
    $contract = allocation_bool($body['useContractualVolumes'] ?? false);
    $stats = allocation_machine_ops($pdo, (int) $op['machine_id'], $year, $contract, $operationId);
    if ($stats === null) {
        json_response(400, ['success' => false, 'error' => 'Brak danych capacity (ustawienia roku lub maszyna).']);
    }
    $current = $stats['ops'][$operationId] ?? null;
    if ($current === null) {
        json_response(400, ['success' => false, 'error' => 'Dla wybranego roku wolumen tej operacji wynosi 0.']);
    }
    $unit = in_array($body['volumeUnit'] ?? '', ['monthly', 'weekly'], true) ? (string) $body['volumeUnit'] : 'annual';
    $volume = is_numeric($body['volumeToMove'] ?? null) ? (float) $body['volumeToMove'] : 0.0;
    $months = (int) $current['months'];
    $zeroPlaceholder = $current['volume_value'] <= 1e-9 && $months > 0;
    $moved = allocation_move_weekly($volume, $unit, $stats['settings'], (float) $current['fraction']);
    if ($zeroPlaceholder) {
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
    $useAlt = allocation_bool($body['useAlternativeCycleOnTarget'] ?? false);
    if ($useAlt && !(isset($op['alt_cycle_time_seconds']) && (float) $op['alt_cycle_time_seconds'] > 0)) {
        json_response(400, ['success' => false, 'error' => 'Operacja nie ma zdefiniowanego alternatywnego czasu cyklu.']);
    }
    $manualCycle = !$useAlt && is_numeric($body['cycleTimeSecondsOnTarget'] ?? null) && (float) $body['cycleTimeSecondsOnTarget'] > 0
        ? (float) $body['cycleTimeSecondsOnTarget']
        : null;
    if ($useAlt) {
        $cycle = (float) $op['alt_cycle_time_seconds'];
        $nests = isset($op['alt_nests_count']) && (float) $op['alt_nests_count'] > 0 ? (int) $op['alt_nests_count'] : (int) ($op['nests_count'] ?? 1);
        $oee = isset($op['alt_oee_override']) && (float) $op['alt_oee_override'] > 0 ? (float) $op['alt_oee_override'] : ($op['oee_override'] !== null ? (float) $op['oee_override'] : null);
    } elseif ($manualCycle !== null) {
        $cycle = $manualCycle;
        $nests = max(1, (int) ($op['nests_count'] ?? 1));
        $oee = $op['oee_override'] !== null ? (float) $op['oee_override'] : null;
    } else {
        $cycleInfo = calc_cycle($op);
        $cycle = $cycleInfo['cycle'];
        $nests = (int) $cycleInfo['nests'];
        $oee = $op['oee_override'] !== null ? (float) $op['oee_override'] : null;
    }
    $fromMonth = is_numeric($body['effectiveFromMonth'] ?? null) ? (int) $body['effectiveFromMonth'] : 0;
    $month = $fromMonth >= 1 && $fromMonth <= 12 ? $fromMonth : null;
    $week = null;
    if ($month !== null) {
        $fromWeek = is_numeric($body['effectiveFromWeek'] ?? null) ? (int) $body['effectiveFromWeek'] : 1;
        $week = min(5, max(1, $fromWeek));
    }
    $remaining = max(0.0, (float) $current['weekly'] - $moveWeekly);
    $pdo->beginTransaction();
    try {
        allocation_upsert_year(
            $pdo,
            $operationId,
            $year,
            $month !== null ? $remaining : $remaining,
            'weekly',
            $month !== null ? (float) $current['weekly'] : null,
            $month,
            $week
        );
        $pdo->prepare(
            'INSERT INTO operations (
                project_id, part_id, phase_id, machine_id, cycle_time_seconds, volume_value, volume_unit, nests_count,
                oee_override, capacity_percent, opf, sap, description, split_from_operation_id,
                alt_cycle_time_seconds, alt_nests_count, alt_oee_override, alt_comment, use_alternative_in_calculator
             ) VALUES (?, ?, ?, ?, ?, 0, \'weekly\', ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 0)'
        )->execute([
            (int) $op['project_id'],
            (int) $op['part_id'],
            (int) $op['phase_id'],
            $targetId,
            $cycle,
            max(1, $nests),
            $oee,
            (float) $op['capacity_percent'],
            (int) $op['opf'],
            $op['sap'],
            $op['description'],
            $operationId,
        ]);
        $childId = (int) $pdo->lastInsertId();
        allocation_seed_years($pdo, (int) $op['project_id'], $operationId, $childId, $year, $moveWeekly, 'weekly', $month, $week);
        $targetLabel = trim((string) $targetRow['internal_number']) !== '' ? (string) $targetRow['internal_number'] : '#' . $targetId;
        write_note(
            $pdo,
            (int) $op['project_id'],
            'Automatyczna zmiana: przeniesiono wolumen operacji #' . $operationId . ' na maszynę ' . $targetLabel . ' (rok ' . $year . ').',
            user_login_label($auth['user']),
            (int) $op['part_id'],
            $targetId,
            $childId
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(400, ['success' => false, 'error' => 'Nie udało się zapisać alokacji.']);
    }
    json_response(200, ['success' => true]);
}

function allocation_alternatives(PDO $pdo, int $machineId): void
{
    require_permission($pdo, 'machines.view');
    $stmt = $pdo->prepare(
        'SELECT m.id, m.internal_number, m.sap_number, m.type
         FROM machine_alternatives a
         JOIN machines m ON m.id = a.alternative_machine_id
         WHERE a.machine_id = ?'
    );
    $stmt->execute([$machineId]);
    $rows = [];
    foreach ($stmt as $row) {
        $row['id'] = (int) $row['id'];
        $rows[] = $row;
    }
    json_response(200, $rows);
}

function allocation_add_alternative(PDO $pdo): void
{
    require_permission($pdo, 'machines.edit');
    $body = request_json();
    $machineId = (int) ($body['machine_id'] ?? 0);
    $alternativeId = (int) ($body['alternative_machine_id'] ?? 0);
    if ($machineId <= 0 || $alternativeId <= 0) {
        json_response(400, ['error' => 'Machine not found']);
    }
    if ($machineId === $alternativeId) {
        json_response(400, ['error' => 'Machine cannot be alternative to itself']);
    }
    try {
        $pdo->prepare('INSERT INTO machine_alternatives (machine_id, alternative_machine_id) VALUES (?, ?)')->execute([$machineId, $alternativeId]);
    } catch (Throwable) {
        json_response(400, ['error' => 'Alternative already added']);
    }
    $machine = $pdo->prepare('SELECT id, internal_number, sap_number, type, status FROM machines WHERE id = ?');
    $machine->execute([$alternativeId]);
    $row = $machine->fetch();
    if (!$row) {
        json_response(400, ['error' => 'Machine not found']);
    }
    $row['id'] = (int) $row['id'];
    json_response(201, $row);
}

function allocation_remove_alternative(PDO $pdo, int $machineId, int $alternativeId): void
{
    require_permission($pdo, 'machines.delete');
    $pdo->prepare('DELETE FROM machine_alternatives WHERE machine_id = ? AND alternative_machine_id = ?')->execute([$machineId, $alternativeId]);
    if ((int) $pdo->query('SELECT changes()')->fetchColumn() === 0) {
        json_response(404, ['error' => 'Not found']);
    }
    write_no_content();
}

function handle_allocation(string $method, string $path): bool
{
    if ($method === 'GET' && $path === '/allocation/overloaded') {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_overloaded($pdo);
    }
    if ($method === 'GET' && preg_match('#^/allocation/candidates/(\d+)$#', $path, $match) === 1) {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_candidates($pdo, (int) $match[1]);
    }
    if ($method === 'GET' && preg_match('#^/allocation/hint/(\d+)$#', $path, $match) === 1) {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_hint($pdo, (int) $match[1]);
    }
    if ($method === 'POST' && $path === '/allocation/execute') {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_execute($pdo);
    }
    if ($method === 'GET' && preg_match('#^/alternatives/machine/(\d+)$#', $path, $match) === 1) {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_alternatives($pdo, (int) $match[1]);
    }
    if ($method === 'POST' && $path === '/alternatives') {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_add_alternative($pdo);
    }
    if ($method === 'DELETE' && preg_match('#^/alternatives/(\d+)/(\d+)$#', $path, $match) === 1) {
        $pdo = open_database();
        bootstrap_admin($pdo);
        allocation_remove_alternative($pdo, (int) $match[1], (int) $match[2]);
    }
    return false;
}
