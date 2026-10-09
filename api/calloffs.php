<?php
declare(strict_types=1);

function calloff_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM call_off_comparisons WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function calloff_public(array $row, int $count): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'date_from' => $row['date_from'],
        'date_to' => $row['date_to'],
        'notes' => $row['notes'] ?? null,
        'source_filename' => $row['source_filename'] ?? null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'archived_at' => $row['archived_at'] ?? null,
        'volume_row_count' => $count,
    ];
}

function calloff_list(PDO $pdo): void
{
    require_permission($pdo, 'call_offs.view');
    $archived = ($_GET['archived'] ?? '') === '1' || strtolower((string) ($_GET['archived'] ?? '')) === 'true';
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM call_off_volumes v WHERE v.comparison_id = c.id) AS volume_row_count
            FROM call_off_comparisons c
            WHERE ' . ($archived ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL') . '
            ORDER BY c.created_at DESC';
    $rows = [];
    foreach ($pdo->query($sql) as $row) {
        $rows[] = calloff_public($row, (int) $row['volume_row_count']);
    }
    json_response(200, $rows);
}

function calloff_get(PDO $pdo, int $id): void
{
    require_permission($pdo, 'call_offs.view');
    $row = calloff_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Nie znaleziono porównania']);
    }
    $count = $pdo->prepare('SELECT COUNT(*) AS rows, COUNT(DISTINCT sap_ref) AS saps, COUNT(DISTINCT part_id) AS parts, MIN(volume_date) AS min_date, MAX(volume_date) AS max_date FROM call_off_volumes WHERE comparison_id = ?');
    $count->execute([$id]);
    $stats = $count->fetch();
    $public = calloff_public($row, (int) ($stats['rows'] ?? 0));
    $public['source_file_available'] = false;
    $public['unmatched_report_available'] = false;
    $public['last_import'] = null;
    $public['stats'] = [
        'row_count' => (int) ($stats['rows'] ?? 0),
        'sap_count' => (int) ($stats['saps'] ?? 0),
        'part_count' => (int) ($stats['parts'] ?? 0),
        'min_date' => $stats['min_date'] ?? null,
        'max_date' => $stats['max_date'] ?? null,
    ];
    json_response(200, $public);
}

function calloff_monthly(PDO $pdo, int $id, int $yearFrom, int $yearTo): array
{
    $stmt = $pdo->prepare(
        'SELECT part_id, year, month, SUM(quantity) AS qty
         FROM call_off_volumes
         WHERE comparison_id = ? AND part_id IS NOT NULL AND year BETWEEN ? AND ?
         GROUP BY part_id, year, month'
    );
    $stmt->execute([$id, $yearFrom, $yearTo]);
    $map = [];
    foreach ($stmt as $row) {
        $map[(int) $row['part_id']][(int) $row['year']][(int) $row['month']] = (float) $row['qty'];
    }
    return $map;
}

function calloff_month_span(array $monthly, int $year): ?array
{
    $from = 13;
    $to = 0;
    foreach ($monthly as $byYear) {
        foreach ($byYear[$year] ?? [] as $month => $qty) {
            if ($qty <= 0 || $month < 1 || $month > 12) {
                continue;
            }
            $from = min($from, (int) $month);
            $to = max($to, (int) $month);
        }
    }
    return $from <= $to ? ['from' => $from, 'to' => $to] : null;
}

function calloff_quantity(array $monthly, int $partId, int $year, int $month): float
{
    return (float) ($monthly[$partId][$year][$month] ?? 0);
}

function calloff_machine_month_loads(PDO $pdo, int $year, int $month, array $monthly, array $statuses, array $types, ?array $onlyIds): array
{
    $machines = calculator_filtered_machines($pdo, $statuses, $types, $onlyIds);
    if ($machines === []) {
        return [];
    }
    $ops = calculator_merge_ops(calculator_operations($pdo, array_keys($machines)), calculator_rfq_operations($pdo));
    $ctx = calc_context($pdo);
    $baseSettings = calc_settings($pdo, $year);
    $shares = calc_allocation_shares($ctx['opVolumes'][$year] ?? [], $ctx['parentById'], $ctx['childrenByParent'], $year, $month, $baseSettings);
    $loads = [];
    $details = [];
    foreach ($machines as $machineId => $machine) {
        if ((int) ($machine['is_baseline'] ?? 0) === 1) {
            $loads[$machineId] = 0;
            $details[$machineId] = [];
            continue;
        }
        $settings = baseline_volume_settings($baseSettings, $machine);
        $machineOee = isset($machine['oee_override']) && is_numeric($machine['oee_override']) ? (float) $machine['oee_override'] : null;
        $availability = calc_machine_availability($machine, $settings, $machineOee);
        $required = 0.0;
        $byClient = [];
        foreach ($ops[$machineId] ?? [] as $op) {
            $partId = (int) ($op['part_id'] ?? 0);
            $qty = calloff_quantity($monthly, $partId, $year, $month);
            $share = (float) ($shares[(int) $op['operation_id']] ?? 1);
            if ($qty <= 0 || $share <= 0) {
                continue;
            }
            $weekly = calc_volume_to_weekly($qty * $share, 'monthly', $settings);
            $seconds = calc_operation_demand($machine, $op, $weekly, [])['seconds'];
            $required += $seconds;
            $client = trim((string) ($op['project_client'] ?? ''));
            $client = $client !== '' ? $client : '—';
            $projectId = (int) ($op['project_id'] ?? 0);
            $label = trim((string) ($op['detail_alias'] ?? $op['detail_sap_number'] ?? $op['detail_designation'] ?? ''));
            if ($label === '') {
                $label = 'detal #' . $partId;
            }
            if (!isset($byClient[$client]['projects'][$projectId])) {
                $byClient[$client]['projects'][$projectId] = ['project_name' => (string) ($op['project_name'] ?? ''), 'required' => 0.0, 'details' => []];
            }
            $byClient[$client]['required'] = ($byClient[$client]['required'] ?? 0) + $seconds;
            $byClient[$client]['projects'][$projectId]['required'] += $seconds;
            $byClient[$client]['projects'][$projectId]['details'][$label] = ($byClient[$client]['projects'][$projectId]['details'][$label] ?? 0) + $seconds;
        }
        $loads[$machineId] = $availability > 0 ? (int) round(($required / $availability) * 100) : ($required > 0 ? 100 : 0);
        $details[$machineId] = ['required' => $required, 'availability' => $availability, 'clients' => $byClient];
    }
    return ['loads' => $loads, 'details' => $details];
}

function calloff_year_loads(PDO $pdo, int $id, int $yearFrom, int $yearTo, array $statuses, array $types, ?array $onlyIds): array
{
    $monthly = calloff_monthly($pdo, $id, $yearFrom, $yearTo);
    $byYear = [];
    for ($year = $yearFrom; $year <= $yearTo; $year++) {
        $span = calloff_month_span($monthly, $year);
        $sums = [];
        $counts = [];
        if ($span !== null) {
            for ($month = $span['from']; $month <= $span['to']; $month++) {
                $monthLoads = calloff_machine_month_loads($pdo, $year, $month, $monthly, $statuses, $types, $onlyIds);
                foreach ($monthLoads['loads'] as $machineId => $load) {
                    $sums[$machineId] = ($sums[$machineId] ?? 0) + $load;
                    $counts[$machineId] = ($counts[$machineId] ?? 0) + 1;
                }
            }
        }
        $byYear[$year] = [];
        foreach ($sums as $machineId => $sum) {
            $byYear[$year][$machineId] = $counts[$machineId] > 0 ? (int) round($sum / $counts[$machineId]) : 0;
        }
    }
    return $byYear;
}

function calloff_source(PDO $pdo): PDO
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

function calloff_calculator(PDO $pdo, int $id): void
{
    require_permission($pdo, 'call_offs.view');
    $row = calloff_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Nie znaleziono porównania']);
    }
    $yearFrom = (int) ($_GET['yearFrom'] ?? substr((string) $row['date_from'], 0, 4));
    $yearTo = (int) ($_GET['yearTo'] ?? substr((string) $row['date_to'], 0, 4));
    $yearFrom = max(2000, min(2100, $yearFrom > 0 ? $yearFrom : (int) date('Y')));
    $yearTo = max($yearFrom, min(2100, $yearTo > 0 ? $yearTo : $yearFrom));
    if ($yearTo - $yearFrom > 15) {
        $yearTo = $yearFrom + 15;
    }
    $source = calloff_source($pdo);
    $contract = calculator_contract_flag();
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    $statuses = calculator_statuses();
    $onlyIds = calculator_scope_ids($source);
    $payload = calculator_machines($source, $yearFrom, $yearTo, $statuses, $types, $onlyIds, $contract);
    $loads = calloff_year_loads($source, $id, $yearFrom, $yearTo, $statuses, $types, $onlyIds);
    foreach ($payload as &$machine) {
        foreach ($machine['years'] as $year => &$cell) {
            $load = (int) ($loads[(int) $year][(int) $machine['machine_id']] ?? 0);
            $cell['call_off_load_percent'] = $load;
            $cell['call_off_annual_load_percent'] = $load;
            $cell['call_off_annual_required_sec_per_week'] = 0;
            $cell['call_off_annual_availability_sec_per_week'] = 0;
            $cell['call_off_detail_breakdown'] = [];
            $cell['call_off_material_breakdown'] = $cell['material_breakdown'] ?? [];
        }
        unset($cell);
    }
    unset($machine);
    $years = [];
    for ($year = $yearFrom; $year <= $yearTo; $year++) {
        if (calloff_month_span(calloff_monthly($pdo, $id, $year, $year), $year) !== null) {
            $years[] = $year;
        }
    }
    json_response(200, [
        'comparisonId' => $id,
        'yearFrom' => $yearFrom,
        'yearTo' => $yearTo,
        'date_from' => $row['date_from'],
        'date_to' => $row['date_to'],
        'volumeYears' => $years,
        'machines' => $payload,
    ]);
}

function calloff_period(PDO $pdo, int $id): void
{
    require_permission($pdo, 'call_offs.view');
    $row = calloff_row($pdo, $id);
    if (!$row) {
        json_response(404, ['error' => 'Nie znaleziono porównania']);
    }
    $year = (int) ($_GET['year'] ?? 0);
    if ($year < 2000 || $year > 2100) {
        json_response(400, ['error' => 'Invalid year']);
    }
    $source = calloff_source($pdo);
    $savedScenario = $_GET['scenarioId'] ?? null;
    unset($_GET['scenarioId']);
    $base = calculator_period_breakdown($source);
    if ($savedScenario !== null) {
        $_GET['scenarioId'] = $savedScenario;
    }
    $monthly = calloff_monthly($pdo, $id, $year, $year);
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    $statuses = calculator_statuses();
    $onlyIds = calculator_scope_ids($source);
    $byMonth = [];
    for ($month = 1; $month <= 12; $month++) {
        $byMonth[$month] = calloff_machine_month_loads($source, $year, $month, $monthly, $statuses, $types, $onlyIds)['loads'];
    }
    foreach ($base['machines'] as &$machine) {
        $machineId = (int) $machine['machine_id'];
        foreach ($machine['months'] as $month => &$cell) {
            $load = (int) ($byMonth[(int) $month][$machineId] ?? 0);
            $cell['call_off_load_percent'] = $load;
            $cell['call_off_detail_breakdown'] = [];
            $cell['call_off_material_breakdown'] = $cell['material_breakdown'] ?? [];
            foreach ($cell['weeks'] as &$week) {
                $week['call_off_load_percent'] = $load;
                $week['call_off_detail_breakdown'] = [];
                $week['call_off_material_breakdown'] = $week['material_breakdown'] ?? [];
            }
            unset($week);
        }
        unset($cell);
    }
    unset($machine);
    json_response(200, $base);
}

function calloff_markers(PDO $pdo, int $id): void
{
    require_permission($pdo, 'call_offs.view');
    if (!calloff_row($pdo, $id)) {
        json_response(404, ['error' => 'Nie znaleziono porównania']);
    }
    $savedScenario = $_GET['scenarioId'] ?? null;
    unset($_GET['scenarioId']);
    $markers = calculator_sop_eop_markers(calloff_source($pdo));
    if ($savedScenario !== null) {
        $_GET['scenarioId'] = $savedScenario;
    }
    json_response(200, $markers);
}

function calloff_breakdown(PDO $pdo, array $machines, int $year, ?int $month): array
{
    $comparisonId = (int) ($_GET['callOffComparisonId'] ?? 0);
    if ($comparisonId <= 0 || $machines === []) {
        return ['load_percent' => null, 'clients' => []];
    }
    $monthly = calloff_monthly($pdo, $comparisonId, $year, $year);
    $span = calloff_month_span($monthly, $year);
    if ($span === null) {
        return ['load_percent' => 0, 'clients' => []];
    }
    $monthFrom = $month ?? $span['from'];
    $monthTo = $month ?? $span['to'];
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    $statuses = calculator_statuses();
    $onlyIds = array_map('intval', array_keys($machines));
    $sum = 0;
    $count = 0;
    $clients = [];
    for ($item = $monthFrom; $item <= $monthTo; $item++) {
        $result = calloff_machine_month_loads($pdo, $year, $item, $monthly, $statuses, $types, $onlyIds);
        foreach ($result['loads'] as $load) {
            $sum += $load;
            $count++;
        }
        if ($month !== null) {
            foreach ($result['details'] as $detail) {
                $availability = (float) ($detail['availability'] ?? 0);
                foreach ($detail['clients'] as $client => $node) {
                    if (!isset($clients[$client])) {
                        $clients[$client] = ['required' => 0.0, 'projects' => []];
                    }
                    $clients[$client]['required'] += (float) ($node['required'] ?? 0);
                    $clients[$client]['availability'] = ($clients[$client]['availability'] ?? 0) + $availability;
                    foreach ($node['projects'] as $projectId => $project) {
                        if (!isset($clients[$client]['projects'][$projectId])) {
                            $clients[$client]['projects'][$projectId] = $project;
                            $clients[$client]['projects'][$projectId]['availability'] = $availability;
                        } else {
                            $clients[$client]['projects'][$projectId]['required'] += $project['required'];
                        }
                    }
                }
            }
        }
    }
    $toLoad = function (float $seconds, float $availability): float {
        if ($availability <= 0) {
            return $seconds > 0 ? 100.0 : 0.0;
        }
        return round(($seconds / $availability) * 10000) / 100;
    };
    $clientRows = [];
    foreach ($clients as $client => $node) {
        $projects = [];
        foreach ($node['projects'] as $projectId => $project) {
            $details = [];
            foreach ($project['details'] as $label => $required) {
                $details[] = [
                    'detail_label' => $label,
                    'load_percent' => $toLoad((float) $required, (float) ($project['availability'] ?? $node['availability'] ?? 0)),
                    'share_percent' => (float) ($node['required'] ?? 0) > 0 ? round(((float) $required / (float) $node['required']) * 10000) / 100 : 0,
                    'has_rfq' => false,
                ];
            }
            $projects[] = [
                'project_id' => $projectId,
                'project_name' => $project['project_name'],
                'load_percent' => $toLoad((float) $project['required'], (float) ($project['availability'] ?? $node['availability'] ?? 0)),
                'share_percent' => 0,
                'details' => $details,
            ];
        }
        $clientRows[] = [
            'client' => $client,
            'load_percent' => $toLoad((float) $node['required'], (float) ($node['availability'] ?? 0)),
            'share_percent' => 0,
            'projects' => $projects,
        ];
    }
    return [
        'load_percent' => $count > 0 ? (int) round($sum / $count) : 0,
        'clients' => $clientRows,
    ];
}

function handle_calloffs(string $method, string $path): bool
{
    if (!str_starts_with($path, '/call-offs')) {
        return false;
    }
    if ($method !== 'GET') {
        if ($method === 'POST' && ($path === '/call-offs' || str_contains($path, 'import-sales-fcst'))) {
            $pdo = open_database();
            bootstrap_admin($pdo);
            require_permission($pdo, 'call_offs.edit');
            json_response(400, ['error' => 'Import Sales Forecast będzie dostępny w kolejnym kroku.']);
        }
        return false;
    }
    $pdo = open_database();
    bootstrap_admin($pdo);
    if ($path === '/call-offs') {
        calloff_list($pdo);
    }
    if (preg_match('#^/call-offs/(\d+)$#', $path, $match) === 1) {
        calloff_get($pdo, (int) $match[1]);
    }
    if (preg_match('#^/call-offs/(\d+)/calculator$#' , $path, $match) === 1) {
        calloff_calculator($pdo, (int) $match[1]);
    }
    if (preg_match('#^/call-offs/(\d+)/calculator/period-breakdown$#', $path, $match) === 1) {
        calloff_period($pdo, (int) $match[1]);
    }
    if (preg_match('#^/call-offs/(\d+)/calculator/sop-eop-markers$#', $path, $match) === 1) {
        calloff_markers($pdo, (int) $match[1]);
    }
    return false;
}
