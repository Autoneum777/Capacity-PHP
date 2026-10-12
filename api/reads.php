<?php
declare(strict_types=1);

require_once __DIR__ . '/baseline.php';

function setting_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT key, value FROM admin_settings') as $row) {
        $map[(string) $row['key']] = (string) $row['value'];
    }
    return $map;
}

function setting_bool(array $map, string $key, bool $fallback): bool
{
    if (!array_key_exists($key, $map) || trim($map[$key]) === '') {
        return $fallback;
    }
    $value = strtolower(trim($map[$key]));
    if ($value === '1' || $value === 'true') {
        return true;
    }
    if ($value === '0' || $value === 'false') {
        return false;
    }
    return $fallback;
}

function setting_num(array $map, string $key, float $fallback): float
{
    if (!array_key_exists($key, $map)) {
        return $fallback;
    }
    $n = (float) $map[$key];
    return is_finite($n) ? $n : $fallback;
}

function setting_color(array $map, string $key, string $fallback): string
{
    $value = trim($map[$key] ?? '');
    return preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value) === 1 ? $value : $fallback;
}

function setting_choice(array $map, string $key, array $allowed, string $fallback): string
{
    $value = strtolower(trim($map[$key] ?? ''));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function visual_settings(PDO $pdo): array
{
    $map = setting_map($pdo);
    $yearNow = (int) gmdate('Y');
    $yearFrom = (int) setting_num($map, 'visual_data_viz_default_year_from', $yearNow - 1);
    $yearTo = (int) setting_num($map, 'visual_data_viz_default_year_to', $yearNow + 10);
    $yearFrom = max(2000, min(2100, $yearFrom));
    $yearTo = max(2000, min(2100, $yearTo));
    if ($yearFrom > $yearTo) {
        [$yearFrom, $yearTo] = [$yearTo, $yearFrom];
    }
    $pageSize = (int) setting_num($map, 'visual_calculator_page_size', 25);
    if (!in_array($pageSize, [0, 25, 50], true)) {
        $pageSize = 25;
    }

    return [
        'show_alternative_borders' => setting_bool($map, 'visual_show_alternative_borders', true),
        'show_rfq_badge' => setting_bool($map, 'visual_show_rfq_badge', true),
        'colorize_load_cells' => setting_bool($map, 'visual_colorize_load_cells', true),
        'colorize_sum_row' => setting_bool($map, 'visual_colorize_sum_row', true),
        'colorize_avg_row' => setting_bool($map, 'visual_colorize_avg_row', true),
        'reference_display' => setting_choice($map, 'visual_reference_display', ['sap', 'alias', 'both'], 'both'),
        'machine_display' => setting_choice($map, 'visual_machine_display', ['sap', 'internal', 'both'], 'internal'),
        'data_viz_machine_bar_label' => setting_choice($map, 'visual_data_viz_machine_bar_label', ['sap', 'internal', 'both'], 'internal'),
        'ok_enabled' => setting_bool($map, 'visual_ok_enabled', true),
        'ok_from' => setting_num($map, 'visual_ok_from', 0),
        'ok_to' => setting_num($map, 'visual_ok_to', 79.99),
        'ok_color' => setting_color($map, 'visual_ok_color', '#c8e6c9'),
        'warn_enabled' => setting_bool($map, 'visual_warn_enabled', true),
        'warn_from' => setting_num($map, 'visual_warn_from', 80),
        'warn_to' => setting_num($map, 'visual_warn_to', 99.99),
        'warn_color' => setting_color($map, 'visual_warn_color', '#fff9c4'),
        'danger_enabled' => setting_bool($map, 'visual_danger_enabled', true),
        'danger_from' => setting_num($map, 'visual_danger_from', 100),
        'danger_to' => setting_num($map, 'visual_danger_to', 1000000),
        'danger_color' => setting_color($map, 'visual_danger_color', '#ffcdd2'),
        'contractual_calculator_frame_color' => setting_color($map, 'visual_contractual_calculator_frame_color', '#ff9800'),
        'calculator_page_size' => $pageSize,
        'data_viz_default_year_from' => $yearFrom,
        'data_viz_default_year_to' => $yearTo,
        'data_viz_color_production' => setting_color($map, 'visual_data_viz_color_production', '#8A9300'),
        'data_viz_color_contract' => setting_color($map, 'visual_data_viz_color_contract', '#E86A10'),
        'data_viz_color_scenario_production' => setting_color($map, 'visual_data_viz_color_scenario_production', '#008BC1'),
        'data_viz_color_scenario_contract' => setting_color($map, 'visual_data_viz_color_scenario_contract', '#F59B47'),
        'data_viz_color_call_off' => setting_color($map, 'visual_data_viz_color_call_off', '#0091EA'),
        'data_viz_color_delta_negative' => setting_color($map, 'visual_data_viz_color_delta_negative', '#E86A10'),
        'data_viz_color_delta_positive' => setting_color($map, 'visual_data_viz_color_delta_positive', '#8A9300'),
        'data_viz_color_ref_line_overload' => setting_color($map, 'visual_data_viz_color_ref_line_overload', '#E86A10'),
        'data_viz_color_ref_line_free' => setting_color($map, 'visual_data_viz_color_ref_line_free', '#8A9300'),
        'data_viz_compare_palette' => ['#8A9300', '#008BC1', '#E86A10', '#B8C400', '#00B0E8', '#F59B47', '#7A7B7A', '#66B9DA', '#B9BE66', '#F1A670'],
        'load_expansion_direction' => setting_choice($map, 'visual_load_expansion_direction', ['horizontal', 'vertical'], 'horizontal'),
        'show_sop_marker' => setting_bool($map, 'visual_show_sop_marker', true),
        'show_eop_marker' => setting_bool($map, 'visual_show_eop_marker', true),
        'period_month_header_color' => setting_color($map, 'visual_period_month_header_color', '#dbeafe'),
        'period_month_frame_color' => setting_color($map, 'visual_period_month_frame_color', '#3b82f6'),
        'period_week_header_color' => setting_color($map, 'visual_period_week_header_color', '#e0e7ff'),
        'period_week_frame_color' => setting_color($map, 'visual_period_week_frame_color', '#6366f1'),
        'workspace_capacity_page_bg' => setting_color($map, 'visual_workspace_capacity_page_bg', '#f7f8fa'),
        'workspace_capacity_main_bg' => setting_color($map, 'visual_workspace_capacity_main_bg', '#f7f8fa'),
        'workspace_capacity_header_bg' => setting_color($map, 'visual_workspace_capacity_header_bg', '#ffffff'),
        'workspace_capacity_accent' => setting_color($map, 'visual_workspace_capacity_accent', '#A4C400'),
        'workspace_capacity_banner_bg' => setting_color($map, 'visual_workspace_capacity_banner_bg', '#A4C400'),
        'workspace_scenarios_page_bg' => setting_color($map, 'visual_workspace_scenarios_page_bg', '#e4ecf7'),
        'workspace_scenarios_main_bg' => setting_color($map, 'visual_workspace_scenarios_main_bg', '#e8f0fa'),
        'workspace_scenarios_header_bg' => setting_color($map, 'visual_workspace_scenarios_header_bg', '#d8e4f5'),
        'workspace_scenarios_accent' => setting_color($map, 'visual_workspace_scenarios_accent', '#1565c0'),
        'workspace_scenarios_banner_bg' => setting_color($map, 'visual_workspace_scenarios_banner_bg', '#1565c0'),
        'workspace_calloffs_page_bg' => setting_color($map, 'visual_workspace_calloffs_page_bg', '#FFFDDA'),
        'workspace_calloffs_main_bg' => setting_color($map, 'visual_workspace_calloffs_main_bg', '#FFF8E3'),
        'workspace_calloffs_header_bg' => setting_color($map, 'visual_workspace_calloffs_header_bg', '#F0E8B8'),
        'workspace_calloffs_accent' => setting_color($map, 'visual_workspace_calloffs_accent', '#7A6510'),
        'workspace_calloffs_banner_bg' => setting_color($map, 'visual_workspace_calloffs_banner_bg', '#A68920'),
        'workspace_calloffs_import_bg' => setting_color($map, 'visual_workspace_calloffs_import_bg', '#FFFDDA'),
        'workspace_calloffs_import_border' => setting_color($map, 'visual_workspace_calloffs_import_border', '#D4C88A'),
        'workspace_calloffs_import_accent' => setting_color($map, 'visual_workspace_calloffs_import_accent', '#7A6510'),
        'workspace_calloffs_import_table_header_bg' => setting_color($map, 'visual_workspace_calloffs_import_table_header_bg', '#F0E8B8'),
    ];
}

function format_sop_eop(mixed $value): string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{1,2})$/', $text, $match) === 1) {
        $year = (int) $match[1];
        $month = (int) $match[2];
        if ($month >= 1 && $month <= 12 && $year >= 1900 && $year <= 2200) {
            return $month . '.' . $year;
        }
    }
    if (preg_match('/^(\d{1,2})\.(\d+)$/', $text, $match) === 1) {
        $month = (int) $match[1];
        $yearText = $match[2];
        $year = match (strlen($yearText)) {
            4 => (int) $yearText,
            3 => (int) ($yearText . '0'),
            2 => 2000 + (int) $yearText,
            default => 0,
        };
        if ($month >= 1 && $month <= 12 && $year >= 1900 && $year <= 2200) {
            return $month . '.' . $year;
        }
    }
    return $text;
}

function query_statuses(): array
{
    $raw = trim((string) ($_GET['statuses'] ?? $_GET['status'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
        $token = strtolower(trim($part));
        if (in_array($token, ['active', 'aktywne', 'aktywny'], true)) {
            $out[] = 'active';
        } elseif (in_array($token, ['inactive', 'nieaktywne', 'nieaktywny'], true)) {
            $out[] = 'inactive';
        } elseif ($token === 'rfq') {
            $out[] = 'RFQ';
        }
    }
    return array_values(array_unique($out));
}

function query_clients(): array
{
    $raw = trim((string) ($_GET['clients'] ?? $_GET['client'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
        $name = strtoupper(trim($part));
        if ($name !== '') {
            $out[$name] = true;
        }
    }
    return array_keys($out);
}

function project_clients(PDO $pdo): array
{
    $seen = [];
    foreach ($pdo->query("SELECT DISTINCT client FROM projects WHERE TRIM(client) <> ''") as $row) {
        $name = strtoupper(trim((string) $row['client']));
        if ($name !== '') {
            $seen[$name] = true;
        }
    }
    $clients = array_keys($seen);
    sort($clients, SORT_STRING);
    return $clients;
}

function project_rfq_tree(PDO $pdo): array
{
    $mode = reference_mode($pdo);
    $clients = [];
    foreach ($pdo->query(
        'SELECT p.id AS project_id, p.name AS project_name, p.client,
                pt.id AS part_id, pd.designation, pd.sap_number, pd.alias, pd.free_text,
                o.id AS operation_id, m.id AS machine_id, m.internal_number, m.sap_number AS machine_sap, m.location
         FROM projects p
         JOIN parts pt ON pt.project_id = p.id
         JOIN operations o ON o.part_id = pt.id
         JOIN machines m ON m.id = o.machine_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE p.status = \'RFQ\'
         ORDER BY p.client, p.name, pt.id, o.id'
    ) as $row) {
        $client = trim((string) ($row['client'] ?? ''));
        $projectId = (int) $row['project_id'];
        $partId = (int) $row['part_id'];
        $operationId = (int) $row['operation_id'];
        if (!isset($clients[$client])) {
            $clients[$client] = ['client' => $client, 'projects' => []];
        }
        if (!isset($clients[$client]['projects'][$projectId])) {
            $clients[$client]['projects'][$projectId] = [
                'id' => $projectId,
                'name' => (string) ($row['project_name'] ?? ''),
                'parts' => [],
            ];
        }
        if (!isset($clients[$client]['projects'][$projectId]['parts'][$partId])) {
            $clients[$client]['projects'][$projectId]['parts'][$partId] = [
                'id' => $partId,
                'label' => detail_label([
                    'sap_number' => $row['sap_number'],
                    'alias' => $row['alias'],
                    'free_text' => $row['free_text'],
                    'designation' => $row['designation'],
                    'id' => $partId,
                ], $mode),
                'operations' => [],
            ];
        }
        $known = false;
        foreach ($clients[$client]['projects'][$projectId]['parts'][$partId]['operations'] as $existing) {
            if ($existing['id'] === $operationId) {
                $known = true;
                break;
            }
        }
        if ($known) {
            continue;
        }
        $sap = trim((string) ($row['machine_sap'] ?? ''));
        $internal = trim((string) ($row['internal_number'] ?? ''));
        if ($sap !== '' && $internal !== '') {
            $label = $sap . ' (' . $internal . ')';
        } elseif ($sap !== '') {
            $label = $sap;
        } elseif ($internal !== '') {
            $label = $internal;
        } else {
            $label = 'M' . (int) $row['machine_id'];
        }
        $clients[$client]['projects'][$projectId]['parts'][$partId]['operations'][] = [
            'id' => $operationId,
            'label' => $label,
            'machine_id' => (int) $row['machine_id'],
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
        ];
    }
    $out = [];
    foreach ($clients as $client) {
        $projects = [];
        foreach ($client['projects'] as $project) {
            $project['parts'] = array_values($project['parts']);
            $projects[] = $project;
        }
        $out[] = ['client' => $client['client'], 'projects' => $projects];
    }
    return ['clients' => $out];
}

function project_rows(PDO $pdo): array
{
    $sql = 'SELECT id, client, name, sop, eop, status FROM projects WHERE 1=1';
    $params = [];
    $statuses = query_statuses();
    if ($statuses !== []) {
        $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($params, ...$statuses);
    }
    $clients = query_clients();
    if ($clients !== []) {
        $sql .= ' AND UPPER(TRIM(client)) IN (' . implode(',', array_fill(0, count($clients), '?')) . ')';
        array_push($params, ...$clients);
    }
    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (client LIKE ? OR name LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY client, name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = [];
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];
        $ids[] = $id;
        $projects[$id] = [
            'id' => $id,
            'client' => (string) $row['client'],
            'name' => (string) $row['name'],
            'sop' => format_sop_eop($row['sop']),
            'eop' => format_sop_eop($row['eop']),
            'status' => (string) $row['status'],
            'machines' => [],
            'parts' => [],
        ];
    }
    if ($ids === []) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $machineStmt = $pdo->prepare(
        "SELECT DISTINCT o.project_id, m.id AS machine_id, m.internal_number, m.sap_number,
                m.type AS machine_type, m.status AS machine_status
         FROM operations o
         JOIN machines m ON m.id = o.machine_id
         WHERE o.project_id IN ($marks)
         ORDER BY m.internal_number"
    );
    $machineStmt->execute($ids);
    foreach ($machineStmt->fetchAll() as $row) {
        $projectId = (int) $row['project_id'];
        if (!isset($projects[$projectId])) {
            continue;
        }
        $projects[$projectId]['machines'][] = [
            'machine_id' => (int) $row['machine_id'],
            'internal_number' => $row['internal_number'],
            'sap_number' => $row['sap_number'],
            'machine_type' => $row['machine_type'],
            'machine_status' => $row['machine_status'],
        ];
    }

    $partStmt = $pdo->prepare(
        "SELECT pt.project_id, pt.id, pt.designation,
                pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text
         FROM parts pt
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE pt.project_id IN ($marks)
         ORDER BY pt.id"
    );
    $partStmt->execute($ids);
    foreach ($partStmt->fetchAll() as $row) {
        $projectId = (int) $row['project_id'];
        if (!isset($projects[$projectId])) {
            continue;
        }
        $projects[$projectId]['parts'][] = [
            'id' => (int) $row['id'],
            'designation' => $row['designation'],
            'detail_sap_number' => $row['detail_sap_number'],
            'detail_alias' => $row['detail_alias'],
            'detail_free_text' => $row['detail_free_text'],
        ];
    }

    return array_values($projects);
}

function machine_rows(PDO $pdo): array
{
    $sql = 'SELECT id, internal_number, sap_number, type, oee_override, status, location,
                   COALESCE(machine_usage, 1) AS machine_usage,
                   width_mm, depth_mm, height_mm, stroke_mm,
                   is_baseline, baseline_available_hours_per_week, baseline_weeks_per_year,
                   baseline_max_throughput_kg_h, baseline_max_throughput_unit,
                   baseline_min_throughput_kg_h, baseline_min_throughput_unit,
                   baseline_max_speed_m_min, baseline_max_speed_unit,
                   baseline_min_speed_m_min, baseline_min_speed_unit,
                   baseline_max_blank_width_mm, baseline_max_blank_width_unit,
                   baseline_min_blank_width_mm, baseline_min_blank_width_unit,
                   baseline_min_length_mm, baseline_max_blanks_across, baseline_max_blanks_across_unit
            FROM machines WHERE 1=1';
    $params = [];
    $statuses = query_statuses();
    if ($statuses !== []) {
        $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = $statuses;
    }
    $sql .= ' ORDER BY internal_number';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['id'] = (int) $row['id'];
        $row['machine_usage'] = (float) $row['machine_usage'];
        $row['is_baseline'] = $row['is_baseline'] === null ? null : (int) $row['is_baseline'];
        $rows[] = $row;
    }
    return $rows;
}

function designation_rows(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, designation, sap_number, alias, free_text, slot_number
         FROM part_designations
         ORDER BY COALESCE(sap_number, alias, designation, free_text)'
    )->fetchAll();
    $designations = [];
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $ids[] = $id;
        $designations[$id] = [
            'id' => $id,
            'designation' => $row['designation'],
            'sap_number' => $row['sap_number'],
            'alias' => $row['alias'],
            'free_text' => $row['free_text'],
            'slot_number' => $row['slot_number'],
            'projects' => [],
            'machine_lines' => [],
        ];
    }
    if ($ids === []) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $projectStmt = $pdo->prepare(
        "SELECT DISTINCT pt.designation_id, pr.id AS project_id, pr.name AS project_name
         FROM parts pt
         INNER JOIN projects pr ON pr.id = pt.project_id
         WHERE pt.designation_id IN ($marks)"
    );
    $projectStmt->execute($ids);
    foreach ($projectStmt->fetchAll() as $row) {
        $designationId = (int) $row['designation_id'];
        if (!isset($designations[$designationId])) {
            continue;
        }
        $projectId = (int) $row['project_id'];
        $designations[$designationId]['projects'][$projectId] = [
            'id' => $projectId,
            'name' => (string) $row['project_name'],
        ];
    }
    foreach ($designations as &$designation) {
        $projects = array_values($designation['projects']);
        usort($projects, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        $designation['projects'] = $projects;
    }
    unset($designation);

    $lineSets = [];
    $lineSql = [
        "SELECT DISTINCT pt.designation_id, TRIM(m.location) AS line_ref
         FROM operations o
         JOIN machines m ON m.id = o.machine_id
         JOIN parts pt ON pt.id = o.part_id
         WHERE pt.designation_id IN ($marks)
           AND m.location IS NOT NULL AND LENGTH(TRIM(m.location)) > 0",
        "SELECT DISTINCT pt.designation_id, TRIM(m.location) AS line_ref
         FROM operations o
         JOIN machines m ON m.id = o.machine_id
         JOIN operation_set_members osm ON osm.operation_id = o.id
         JOIN parts pt ON pt.id = osm.part_id
         WHERE pt.designation_id IN ($marks)
           AND m.location IS NOT NULL AND LENGTH(TRIM(m.location)) > 0",
    ];
    foreach ($lineSql as $sql) {
        $lineStmt = $pdo->prepare($sql);
        $lineStmt->execute($ids);
        foreach ($lineStmt->fetchAll() as $row) {
            $designationId = (int) $row['designation_id'];
            $line = trim((string) $row['line_ref']);
            if ($line === '' || !isset($designations[$designationId])) {
                continue;
            }
            $lineSets[$designationId][$line] = true;
        }
    }
    foreach ($designations as $id => &$designation) {
        $lines = array_map('strval', array_keys($lineSets[$id] ?? []));
        sort($lines, SORT_STRING);
        $designation['machine_lines'] = $lines;
    }
    unset($designation);

    return array_values($designations);
}

function machine_types(PDO $pdo): array
{
    $names = [];
    foreach ($pdo->query('SELECT name FROM machine_types') as $row) {
        $name = trim((string) $row['name']);
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    foreach ($pdo->query("SELECT DISTINCT TRIM(type) AS name FROM machines WHERE type IS NOT NULL AND TRIM(type) != ''") as $row) {
        $name = trim((string) $row['name']);
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    $list = array_keys($names);
    sort($list, SORT_STRING);
    return $list;
}

function format_sap_number(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || (is_float($value) && is_finite($value))) {
        if (abs($value - round($value)) < 1e-9) {
            return (string) (int) round($value);
        }
        return (string) $value;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(-?\d+)\.0+$/', $raw, $match) === 1) {
        return $match[1];
    }
    if (is_numeric($raw)) {
        $number = (float) $raw;
        if (is_finite($number) && abs($number - round($number)) < 1e-9) {
            return (string) (int) round($number);
        }
    }
    return $raw;
}

function detail_label(array $detail, string $mode): string
{
    $sap = format_sap_number($detail['sap_number'] ?? null);
    $alias = trim((string) ($detail['alias'] ?? ''));
    $freeText = trim((string) ($detail['free_text'] ?? ''));
    $designation = trim((string) ($detail['designation'] ?? ''));
    $extra = $freeText !== '' ? $freeText : $designation;
    $id = isset($detail['id']) ? (int) $detail['id'] : 0;

    $withExtra = function (string $core) use ($extra, $sap, $alias): string {
        if ($extra !== '' && $extra !== $sap && $extra !== $alias) {
            return $core . ' (' . $extra . ')';
        }
        return $core;
    };

    if ($mode === 'both') {
        $sapDisp = $sap !== '' ? $sap : '—';
        $aliasDisp = $alias !== '' ? $alias : '—';
        if ($sap === '' && $alias === '') {
            if ($extra !== '') {
                return $sapDisp . ' | ' . $aliasDisp . ' (' . $extra . ')';
            }
            if ($id > 0) {
                return $sapDisp . ' | ' . $aliasDisp . ' (#' . $id . ')';
            }
            return $sapDisp . ' | ' . $aliasDisp;
        }
        $core = $sapDisp . ' | ' . $aliasDisp;
        if ($extra !== '' && $extra !== $sap && $extra !== $alias) {
            $core .= ' (' . $extra . ')';
        }
        return $core;
    }

    $primary = $mode === 'sap' ? $sap : $alias;
    if ($primary === '') {
        if ($sap === '' && $alias === '') {
            if ($extra !== '') {
                return $withExtra('—');
            }
            if ($id > 0) {
                return '— (#' . $id . ')';
            }
            return '—';
        }
        return $withExtra('—');
    }
    return $withExtra($primary);
}

function reference_mode(PDO $pdo): string
{
    return setting_choice(setting_map($pdo), 'visual_reference_display', ['sap', 'alias', 'both'], 'both');
}

function rows_by_id(PDO $pdo, string $sql, array $ids, string $key): array
{
    if ($ids === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(str_replace('__IN__', $marks, $sql));
    $stmt->execute($ids);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row[$key];
        unset($row[$key]);
        $grouped[$id][] = $row;
    }
    return $grouped;
}

function cast_number(mixed $value, bool $float): mixed
{
    if ($value === null || $value === '') {
        return null;
    }
    return $float ? (float) $value : (int) $value;
}

function cast_fields(array $row, array $ints, array $floats): array
{
    foreach ($ints as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = cast_number($row[$key], false);
        }
    }
    foreach ($floats as $key) {
        if (array_key_exists($key, $row)) {
            $row[$key] = cast_number($row[$key], true);
        }
    }
    return $row;
}

function volume_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $item = [
            'year' => (int) $row['year'],
            'volume_value' => (float) $row['volume_value'],
            'volume_unit' => (string) $row['volume_unit'],
        ];
        if (array_key_exists('volume_origin', $row)) {
            $item['volume_origin'] = $row['volume_origin'];
        }
        if (array_key_exists('include_in_calculator_after_eop', $row)) {
            $item['include_in_calculator_after_eop'] = (int) $row['include_in_calculator_after_eop'];
        }
        if (array_key_exists('source', $row)) {
            $item['source'] = $row['source'];
        }
        $out[] = $item;
    }
    return $out;
}

function share_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'year' => (int) $row['year'],
            'share_percent' => (float) $row['share_percent'],
        ];
    }
    return $out;
}

function phase_rows(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT id, name FROM process_phases ORDER BY name') as $row) {
        $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }
    return $rows;
}

function project_detail(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    $project = $stmt->fetch();
    if (!$project) {
        return null;
    }
    $project['id'] = (int) $project['id'];
    foreach (['sop', 'eop', 'eop_original', 'eop_extension'] as $field) {
        if (array_key_exists($field, $project) && $project[$field] !== null && $project[$field] !== '') {
            $project[$field] = format_sop_eop($project[$field]);
        }
    }

    $partStmt = $pdo->prepare(
        'SELECT pt.*, pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text
         FROM parts pt
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE pt.project_id = ?
         ORDER BY pt.id'
    );
    $partStmt->execute([$id]);
    $parts = $partStmt->fetchAll();
    $partIds = array_map(fn(array $row): int => (int) $row['id'], $parts);
    $volumeByPart = rows_by_id($pdo, 'SELECT part_id, year, volume_value, volume_unit, volume_origin FROM part_volume_by_year WHERE part_id IN (__IN__) ORDER BY year', $partIds, 'part_id');
    $contractByPart = rows_by_id($pdo, 'SELECT part_id, year, volume_value, volume_unit, volume_origin FROM part_volume_contract_by_year WHERE part_id IN (__IN__) ORDER BY year', $partIds, 'part_id');
    $shareByPart = rows_by_id($pdo, 'SELECT part_id, year, share_percent FROM part_volume_share_by_year WHERE part_id IN (__IN__) ORDER BY year', $partIds, 'part_id');
    $contractShareByPart = rows_by_id($pdo, 'SELECT part_id, year, share_percent FROM part_volume_contract_share_by_year WHERE part_id IN (__IN__) ORDER BY year', $partIds, 'part_id');

    $mode = reference_mode($pdo);
    $partRows = [];
    foreach ($parts as $part) {
        $partId = (int) $part['id'];
        $part = cast_fields(
            $part,
            ['id', 'project_id', 'designation_id'],
            ['volume_share_percent', 'default_volume_value', 'contract_volume_share_percent', 'contract_default_volume_value']
        );
        $part['volume_mode'] = $part['volume_mode'] ?? 'project';
        $part['contract_volume_mode'] = $part['contract_volume_mode'] ?? 'project';
        $part['volume_by_year'] = volume_rows($volumeByPart[$partId] ?? []);
        $part['volume_contract_by_year'] = volume_rows($contractByPart[$partId] ?? []);
        $part['volume_share_by_year'] = share_rows($shareByPart[$partId] ?? []);
        $part['volume_contract_share_by_year'] = share_rows($contractShareByPart[$partId] ?? []);
        $part['detail'] = $part['designation_id']
            ? [
                'sap_number' => $part['detail_sap_number'],
                'alias' => $part['detail_alias'],
                'free_text' => $part['detail_free_text'],
            ]
            : null;
        $partRows[] = $part;
    }

    $opStmt = $pdo->prepare(
        'SELECT o.*, ph.name AS phase_name,
                pd.sap_number AS _op_sap, pd.alias AS _op_alias, pd.free_text AS _op_free, pt.designation AS _op_pt_des,
                m.internal_number AS machine_number, m.sap_number AS machine_sap_number, m.type AS machine_type,
                m.status AS machine_status
         FROM operations o
         JOIN process_phases ph ON ph.id = o.phase_id
         JOIN parts pt ON pt.id = o.part_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         JOIN machines m ON m.id = o.machine_id
         WHERE o.project_id = ?
         ORDER BY o.id'
    );
    $opStmt->execute([$id]);
    $operations = $opStmt->fetchAll();
    $operationIds = array_map(fn(array $row): int => (int) $row['id'], $operations);
    $volumeByOperation = rows_by_id(
        $pdo,
        'SELECT operation_id, year, volume_value, volume_unit, source FROM operation_volume_by_year WHERE operation_id IN (__IN__) ORDER BY year',
        $operationIds,
        'operation_id'
    );
    $membersByOperation = rows_by_id(
        $pdo,
        'SELECT osm.operation_id, osm.part_id, osm.quantity_per_set,
                pd.sap_number AS m_sap, pd.alias AS m_alias, pd.free_text AS m_free, pt.designation AS m_des
         FROM operation_set_members osm
         JOIN parts pt ON pt.id = osm.part_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE osm.operation_id IN (__IN__)
         ORDER BY osm.part_id',
        $operationIds,
        'operation_id'
    );

    $operationRows = [];
    foreach ($operations as $operation) {
        $operationId = (int) $operation['id'];
        $operation = cast_fields(
            $operation,
            ['id', 'project_id', 'part_id', 'phase_id', 'machine_id', 'cycle_time_seconds', 'nests_count', 'opf', 'is_set', 'split_from_operation_id', 'alt_nests_count', 'use_alternative_in_calculator'],
            ['volume_value', 'oee_override', 'capacity_percent', 'alt_cycle_time_seconds', 'alt_oee_override']
        );
        $operation['volume_by_year'] = volume_rows($volumeByOperation[$operationId] ?? []);
        if ((int) ($operation['is_set'] ?? 0) === 1) {
            $members = [];
            foreach ($membersByOperation[$operationId] ?? [] as $member) {
                $members[] = [
                    'part_id' => (int) $member['part_id'],
                    'quantity_per_set' => (int) ($member['quantity_per_set'] ?? 1),
                    'label' => detail_label([
                        'sap_number' => $member['m_sap'],
                        'alias' => $member['m_alias'],
                        'free_text' => $member['m_free'],
                        'designation' => $member['m_des'],
                        'id' => $member['part_id'],
                    ], $mode),
                ];
            }
            $operation['set_members'] = $members;
            $operation['part_designation'] = 'Set: ' . implode(' + ', array_map(fn(array $member): string => $member['label'], $members));
        } else {
            $operation['part_designation'] = detail_label([
                'sap_number' => $operation['_op_sap'],
                'alias' => $operation['_op_alias'],
                'free_text' => $operation['_op_free'],
                'designation' => $operation['_op_pt_des'],
                'id' => $operation['part_id'],
            ], $mode);
        }
        unset($operation['_op_sap'], $operation['_op_alias'], $operation['_op_free'], $operation['_op_pt_des']);
        $operationRows[] = $operation;
    }

    $noteStmt = $pdo->prepare('SELECT * FROM project_notes WHERE project_id = ? ORDER BY note_date DESC');
    $noteStmt->execute([$id]);
    $notes = [];
    foreach ($noteStmt->fetchAll() as $note) {
        $notes[] = cast_fields($note, ['id', 'project_id', 'machine_id', 'part_id', 'operation_id'], []);
    }

    $extensionStmt = $pdo->prepare('SELECT eop_before, eop_after, created_at FROM project_eop_extensions WHERE project_id = ? ORDER BY created_at ASC');
    $extensionStmt->execute([$id]);
    $extensions = [];
    foreach ($extensionStmt->fetchAll() as $extension) {
        $extensions[] = [
            'eop_before' => format_sop_eop($extension['eop_before']),
            'eop_after' => format_sop_eop($extension['eop_after']),
            'created_at' => $extension['created_at'],
        ];
    }

    $projectVolumeStmt = $pdo->prepare('SELECT year, volume_value, volume_unit, COALESCE(include_in_calculator_after_eop, 0) AS include_in_calculator_after_eop, volume_origin FROM project_volumes WHERE project_id = ? ORDER BY year');
    $projectVolumeStmt->execute([$id]);
    $contractVolumeStmt = $pdo->prepare('SELECT year, volume_value, volume_unit, COALESCE(include_in_calculator_after_eop, 0) AS include_in_calculator_after_eop, volume_origin FROM project_volumes_contract WHERE project_id = ? ORDER BY year');
    $contractVolumeStmt->execute([$id]);

    $project['project_volumes'] = volume_rows($projectVolumeStmt->fetchAll());
    $project['project_volumes_contract'] = volume_rows($contractVolumeStmt->fetchAll());
    $project['parts'] = $partRows;
    $project['operations'] = $operationRows;
    $project['notes'] = $notes;
    $project['eop_extensions'] = $extensions;
    return $project;
}

function project_attachments(PDO $pdo, int $projectId): ?array
{
    $exists = $pdo->prepare('SELECT 1 FROM projects WHERE id = ?');
    $exists->execute([$projectId]);
    if (!$exists->fetchColumn()) {
        return null;
    }
    $storage = attachment_directory($pdo);
    $dir = $storage['absolute'];
    $stmt = $pdo->prepare(
        'SELECT id, project_id, description, original_filename, stored_filename, mime_type, size_bytes, uploaded_at, uploaded_by, is_shared
         FROM project_attachments
         WHERE project_id = ? OR is_shared = 1
         ORDER BY uploaded_at DESC, id DESC'
    );
    $stmt->execute([$projectId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = cast_fields($row, ['id', 'project_id', 'size_bytes', 'is_shared'], []);
    }
    return [
        'storage_configured' => $storage['configured'],
        'absolute_output_dir' => $dir,
        'attachments' => $rows,
    ];
}

function cast_machine_row(array $row): array
{
    $row = cast_fields(
        $row,
        ['id', 'is_baseline'],
        [
            'oee_override', 'machine_usage', 'width_mm', 'depth_mm', 'height_mm', 'stroke_mm',
            'baseline_available_hours_per_week', 'baseline_weeks_per_year',
            'baseline_max_throughput_kg_h', 'baseline_min_throughput_kg_h',
            'baseline_max_speed_m_min', 'baseline_min_speed_m_min',
            'baseline_max_blank_width_mm', 'baseline_min_blank_width_mm',
            'baseline_min_length_mm', 'baseline_max_blanks_across',
        ]
    );
    $row['machine_usage'] = $row['machine_usage'] ?? 1.0;
    return $row;
}

function machine_active_project_operations(PDO $pdo, int $id): ?array
{
    $exists = $pdo->prepare('SELECT 1 FROM machines WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetch()) {
        return null;
    }
    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM operations o
         INNER JOIN projects p ON p.id = o.project_id
         WHERE o.machine_id = ? AND p.status = 'active'"
    );
    $count->execute([$id]);
    $projects = $pdo->prepare(
        "SELECT DISTINCT p.id, p.client, p.name
         FROM operations o
         INNER JOIN projects p ON p.id = o.project_id
         WHERE o.machine_id = ? AND p.status = 'active'
         ORDER BY LOWER(p.client), LOWER(p.name)"
    );
    $projects->execute([$id]);
    $rows = [];
    foreach ($projects as $row) {
        $rows[] = ['id' => (int) $row['id'], 'client' => (string) $row['client'], 'name' => (string) $row['name']];
    }
    return ['count' => (int) $count->fetchColumn(), 'projects' => $rows];
}

function machine_detail(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, internal_number, sap_number, type, oee_override, status, location,
                COALESCE(machine_usage, 1) AS machine_usage,
                width_mm, depth_mm, height_mm, stroke_mm,
                is_baseline, baseline_available_hours_per_week, baseline_weeks_per_year,
                baseline_max_throughput_kg_h, baseline_max_throughput_unit,
                baseline_min_throughput_kg_h, baseline_min_throughput_unit,
                baseline_max_speed_m_min, baseline_max_speed_unit,
                baseline_min_speed_m_min, baseline_min_speed_unit,
                baseline_max_blank_width_mm, baseline_max_blank_width_unit,
                baseline_min_blank_width_mm, baseline_min_blank_width_unit,
                baseline_min_length_mm, baseline_max_blanks_across, baseline_max_blanks_across_unit
         FROM machines WHERE id = ?'
    );
    $stmt->execute([$id]);
    $machine = $stmt->fetch();
    if (!$machine) {
        return null;
    }
    $machine = cast_machine_row($machine);

    $altStmt = $pdo->prepare(
        'SELECT m.id, m.internal_number, m.sap_number, m.type
         FROM machine_alternatives a
         JOIN machines m ON m.id = a.alternative_machine_id
         WHERE a.machine_id = ?'
    );
    $altStmt->execute([$id]);
    $alternatives = [];
    foreach ($altStmt->fetchAll() as $row) {
        $row['id'] = (int) $row['id'];
        $alternatives[] = $row;
    }

    $projectStmt = $pdo->prepare(
        'SELECT DISTINCT p.id, p.client, p.name, p.status
         FROM projects p
         JOIN operations o ON o.project_id = p.id
         WHERE o.machine_id = ?'
    );
    $projectStmt->execute([$id]);
    $projects = [];
    foreach ($projectStmt->fetchAll() as $row) {
        $row['id'] = (int) $row['id'];
        $projects[] = $row;
    }

    $machine['alternatives'] = $alternatives;
    $machine['projects'] = $projects;
    return $machine;
}

function material_alias_from_description(?string $description): ?string
{
    $excluded = array_fill_keys(['HIGH', 'LOW', 'INNER', 'OUTER', 'DASH', 'RH', 'LH'], true);
    $text = strtoupper((string) $description);
    if (preg_match_all('/\b[A-Z0-9]{3,10}\b/', $text, $matches) < 1) {
        return null;
    }
    $aliases = [];
    foreach ($matches[0] as $token) {
        if (preg_match('/[A-Z]/', $token) === 1 && preg_match('/\d/', $token) === 1 && !isset($excluded[$token])) {
            $aliases[] = $token;
        }
    }
    return $aliases === [] ? null : $aliases[count($aliases) - 1];
}

function resolve_material_alias(?string $alias, ?string $description): ?string
{
    $trimmed = trim((string) $alias);
    if ($trimmed === '' || strtoupper($trimmed) === 'S2102') {
        return material_alias_from_description($description);
    }
    return $trimmed;
}

function blank_mass_kg(mixed $grammage, mixed $width, mixed $length): ?float
{
    if ($grammage === null || $width === null || $length === null) {
        return null;
    }
    $g = (float) $grammage;
    $w = (float) $width;
    $l = (float) $length;
    if ($g < 0 || $w < 0 || $l < 0) {
        return null;
    }
    return round($g * ($w / 1000) * ($l / 1000), 4);
}

function machine_material_designations(PDO $pdo, int $machineId): ?array
{
    $machine = machine_detail($pdo, $machineId);
    if ($machine === null) {
        return null;
    }
    if ((int) ($machine['is_baseline'] ?? 0) !== 1) {
        return ['error' => 'Materiały są dostępne tylko dla maszyny bazowej.'];
    }
    $mode = reference_mode($pdo);
    $rows = [];
    foreach ($pdo->query('SELECT id, sap_number, alias, free_text, designation FROM part_designations ORDER BY alias COLLATE NOCASE, sap_number COLLATE NOCASE') as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'sap_number' => $row['sap_number'],
            'alias' => $row['alias'],
            'free_text' => $row['free_text'],
            'designation' => $row['designation'],
            'label' => detail_label($row, $mode),
        ];
    }
    return $rows;
}

function machine_materials(PDO $pdo, int $machineId): ?array
{
    $check = $pdo->prepare(
        'SELECT id, is_baseline, baseline_max_throughput_kg_h, baseline_max_throughput_unit,
                baseline_max_speed_m_min, baseline_max_speed_unit, baseline_max_blank_width_mm,
                baseline_max_blank_width_unit, baseline_min_length_mm, baseline_max_blanks_across
         FROM machines WHERE id = ?'
    );
    $check->execute([$machineId]);
    $machine = $check->fetch();
    if (!$machine) {
        return null;
    }
    if ((int) $machine['is_baseline'] !== 1) {
        return ['error' => 'Materiały są dostępne tylko dla maszyny bazowej.'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, machine_id, alias, description, sap_number, grammage_kg_m2, width_mm, length_mm, created_at,
                include_in_capacity, production_share_percent, use_external_volume
         FROM machine_baseline_materials WHERE machine_id = ? ORDER BY id'
    );
    $stmt->execute([$machineId]);
    $materials = $stmt->fetchAll();
    $ids = array_map(fn(array $row): int => (int) $row['id'], $materials);
    $linked = rows_by_id(
        $pdo,
        'SELECT mp.material_id, d.id, d.sap_number, d.alias, d.free_text, d.designation, mp.consumption_per_detail
         FROM machine_baseline_material_parts mp
         JOIN part_designations d ON d.id = mp.designation_id
         WHERE mp.material_id IN (__IN__)
         ORDER BY d.alias COLLATE NOCASE, d.sap_number COLLATE NOCASE',
        $ids,
        'material_id'
    );
    $mode = reference_mode($pdo);
    $rows = [];
    foreach ($materials as $row) {
        $materialId = (int) $row['id'];
        $details = [];
        foreach ($linked[$materialId] ?? [] as $detail) {
            $details[] = [
                'id' => (int) $detail['id'],
                'sap_number' => $detail['sap_number'],
                'alias' => $detail['alias'],
                'free_text' => $detail['free_text'],
                'consumption_per_detail' => (float) ($detail['consumption_per_detail'] ?? 1),
                'label' => detail_label([
                    'id' => $detail['id'],
                    'sap_number' => $detail['sap_number'],
                    'alias' => $detail['alias'],
                    'free_text' => $detail['free_text'],
                    'designation' => $detail['designation'],
                ], $mode),
            ];
        }
        $row = cast_fields(
            $row,
            ['id', 'machine_id', 'include_in_capacity', 'use_external_volume'],
            ['grammage_kg_m2', 'width_mm', 'length_mm', 'production_share_percent']
        );
        $row['alias'] = resolve_material_alias($row['alias'], $row['description']);
        $row['details'] = $details;
        $row['designation_ids'] = array_map(fn(array $detail): int => $detail['id'], $details);
        $row['blank_mass_kg'] = blank_mass_kg($row['grammage_kg_m2'], $row['width_mm'], $row['length_mm']);
        $hourly = baseline_hourly_capacity($machine, $row);
        if (is_numeric($row['width_mm']) && is_numeric($row['length_mm'])) {
            $oriented = baseline_orient($machine, (float) $row['width_mm'], (float) $row['length_mm']);
            $row['width_mm'] = $oriented['widthMm'];
            $row['length_mm'] = $oriented['lengthMm'];
        }
        $row['blanks_across'] = $hourly['blanksAcross'] ?? null;
        $row['web_width_mm'] = $hourly['webWidthMm'] ?? null;
        $row['web_mass_kg'] = $hourly['webMassKg'] ?? null;
        $row['mass_capacity_per_hour'] = $hourly['massCapacityPerHour'] ?? null;
        $row['speed_capacity_per_hour'] = $hourly['speedCapacityPerHour'] ?? null;
        $row['actual_capacity_per_hour'] = $hourly['actualCapacityPerHour'] ?? null;
        $rows[] = $row;
    }
    return $rows;
}

function project_operation_copy_sources(PDO $pdo): array
{
    $needle = trim((string) ($_GET['q'] ?? ''));
    $requested = is_numeric($_GET['limit'] ?? null) ? (int) $_GET['limit'] : 0;
    $limit = $needle !== ''
        ? min(500, max(1, $requested > 0 ? $requested : 200))
        : min(10000, max(1, $requested > 0 ? $requested : 10000));
    $like = '%' . str_replace(['%', '_'], '', $needle) . '%';
    $sql = 'SELECT o.id, o.project_id, TRIM(COALESCE(pr.client, \'\')) AS project_client, TRIM(COALESCE(pr.name, \'\')) AS project_name,
            o.machine_id, o.phase_id, o.cycle_time_seconds, o.nests_count, o.oee_override, o.is_set,
            o.alt_cycle_time_seconds, o.alt_nests_count, o.alt_oee_override, o.alt_comment, o.use_alternative_in_calculator,
            ph.name AS phase_name, m.internal_number AS machine_internal, m.sap_number AS machine_sap, m.type AS machine_type,
            pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text,
            pt_src.designation_id AS part_designation_id
         FROM operations o
         JOIN projects pr ON pr.id = o.project_id
         JOIN process_phases ph ON ph.id = o.phase_id
         JOIN machines m ON m.id = o.machine_id
         LEFT JOIN parts pt_src ON pt_src.id = o.part_id
         LEFT JOIN part_designations pd ON pd.id = pt_src.designation_id';
    $params = [];
    if ($needle !== '') {
        $sql .= ' WHERE LOWER(pr.client) LIKE LOWER(?) OR LOWER(pr.name) LIKE LOWER(?) OR LOWER(ph.name) LIKE LOWER(?)
            OR LOWER(CAST(m.internal_number AS TEXT)) LIKE LOWER(?) OR LOWER(COALESCE(m.sap_number, \'\')) LIKE LOWER(?)
            OR LOWER(m.type) LIKE LOWER(?) OR CAST(o.id AS TEXT) LIKE ?
            OR LOWER(COALESCE(pd.sap_number, \'\')) LIKE LOWER(?) OR LOWER(COALESCE(pd.alias, \'\')) LIKE LOWER(?)
            OR LOWER(COALESCE(pd.free_text, \'\')) LIKE LOWER(?) OR LOWER(COALESCE(pd.designation, \'\')) LIKE LOWER(?)
            OR EXISTS (
              SELECT 1 FROM operation_set_members osm
              JOIN parts pt_m ON pt_m.id = osm.part_id
              LEFT JOIN part_designations pd_m ON pd_m.id = pt_m.designation_id
              WHERE osm.operation_id = o.id AND (
                LOWER(COALESCE(pd_m.sap_number, \'\')) LIKE LOWER(?) OR LOWER(COALESCE(pd_m.alias, \'\')) LIKE LOWER(?)
                OR LOWER(COALESCE(pd_m.free_text, \'\')) LIKE LOWER(?) OR LOWER(COALESCE(pd_m.designation, \'\')) LIKE LOWER(?)
              )
            )';
        $params = array_fill(0, 15, $like);
    }
    $sql .= ' ORDER BY o.id DESC LIMIT ?';
    $stmt = $pdo->prepare($sql);
    $index = 1;
    foreach ($params as $param) {
        $stmt->bindValue($index, $param);
        $index++;
    }
    $stmt->bindValue($index, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $setIds = [];
    foreach ($rows as $row) {
        if ((int) $row['is_set'] === 1) {
            $setIds[] = (int) $row['id'];
        }
    }
    $members = [];
    if ($setIds !== []) {
        $marks = implode(',', array_fill(0, count($setIds), '?'));
        $memberStmt = $pdo->prepare(
            "SELECT osm.operation_id, pt.designation_id
             FROM operation_set_members osm
             JOIN parts pt ON pt.id = osm.part_id
             WHERE osm.operation_id IN ({$marks})
             ORDER BY osm.part_id"
        );
        $memberStmt->execute($setIds);
        foreach ($memberStmt as $member) {
            $members[(int) $member['operation_id']][] = (int) $member['designation_id'];
        }
    }
    $mode = reference_mode($pdo);
    $operations = [];
    foreach ($rows as $row) {
        $detail = trim((string) preg_replace('/\s*\(#\d+\)\s*$/u', '', detail_label([
            'sap_number' => $row['detail_sap_number'],
            'alias' => $row['detail_alias'],
            'free_text' => $row['detail_free_text'],
        ], $mode)));
        $machine = (string) $row['machine_internal'];
        $type = trim((string) ($row['machine_type'] ?? ''));
        $setMark = (int) $row['is_set'] === 1 ? ' · set' : '';
        $setDesignationIds = $members[(int) $row['id']] ?? [];
        $operations[] = [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'project_client' => (string) $row['project_client'],
            'project_name' => (string) $row['project_name'],
            'machine_id' => (int) $row['machine_id'],
            'phase_id' => (int) $row['phase_id'],
            'cycle_time_seconds' => $row['cycle_time_seconds'],
            'nests_count' => $row['nests_count'],
            'oee_override' => $row['oee_override'],
            'is_set' => (int) $row['is_set'],
            'alt_cycle_time_seconds' => $row['alt_cycle_time_seconds'],
            'alt_nests_count' => $row['alt_nests_count'],
            'alt_oee_override' => $row['alt_oee_override'],
            'alt_comment' => $row['alt_comment'],
            'use_alternative_in_calculator' => (int) $row['use_alternative_in_calculator'],
            'phase_name' => (string) $row['phase_name'],
            'machine_internal' => $row['machine_internal'],
            'machine_sap' => $row['machine_sap'],
            'machine_type' => $row['machine_type'],
            'detail_sap_number' => $row['detail_sap_number'],
            'detail_alias' => $row['detail_alias'],
            'detail_free_text' => $row['detail_free_text'],
            'label' => ($detail !== '' ? $detail : '—') . ' · ' . ($machine !== '' ? $machine : '?') . ($type !== '' ? ' (' . $type . ')' : '') . ' · ' . ($row['phase_name'] ?: '—') . ' · ' . ($row['cycle_time_seconds'] ?? '?') . 's' . $setMark,
            'set_designation_ids' => (int) $row['is_set'] === 1 && count($setDesignationIds) >= 2 ? array_values(array_filter($setDesignationIds, fn(int $id): bool => $id > 0)) : null,
            'source_designation_id' => is_numeric($row['part_designation_id']) && (int) $row['part_designation_id'] > 0 ? (int) $row['part_designation_id'] : null,
        ];
    }
    return ['operations' => $operations];
}

function machine_operation_rows(PDO $pdo, int $machineId): array
{
    $source = $pdo;
    $scenarioId = (int) ($_GET['scenarioId'] ?? 0);
    if ($scenarioId > 0) {
        $source = scenario_calculator_database($pdo, $scenarioId);
        if ($source === null) {
            json_response(404, ['error' => 'Scenariusz nie znaleziony']);
        }
    }
    $stmt = $source->prepare(
        'SELECT o.id, o.project_id, o.part_id, o.phase_id, o.cycle_time_seconds, o.nests_count, o.oee_override,
                o.split_from_operation_id, o.alt_cycle_time_seconds, o.alt_nests_count, o.alt_oee_override,
                o.use_alternative_in_calculator, o.volume_value, o.volume_unit, ph.name AS phase_name,
                pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text,
                pt.designation AS detail_designation, p.name AS project_name, p.client, p.sop, p.eop
         FROM operations o
         JOIN process_phases ph ON ph.id = o.phase_id
         JOIN parts pt ON pt.id = o.part_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         JOIN projects p ON p.id = o.project_id
         WHERE o.machine_id = ?
         ORDER BY o.id'
    );
    $stmt->execute([$machineId]);
    $mode = reference_mode($pdo);
    $rows = [];
    foreach ($stmt as $row) {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        $row['part_id'] = (int) $row['part_id'];
        $row['phase_id'] = (int) $row['phase_id'];
        $row['machine_id'] = $machineId;
        $row['part_designation'] = detail_label([
            'sap_number' => $row['detail_sap_number'],
            'alias' => $row['detail_alias'],
            'free_text' => $row['detail_free_text'],
            'designation' => $row['detail_designation'],
            'id' => $row['part_id'],
        ], $mode);
        $rows[] = $row;
    }
    $year = is_numeric($_GET['year'] ?? null) ? (int) $_GET['year'] : 0;
    $contractFlag = strtolower((string) ($_GET['useContractualVolumes'] ?? ''));
    $contract = $contractFlag === '1' || $contractFlag === 'true';
    if ($year >= 2000 && $year <= 2100 && $rows !== []) {
        $stats = allocation_machine_ops($source, $machineId, $year, $contract);
        foreach ($rows as &$row) {
            $op = is_array($stats) ? ($stats['ops'][$row['id']] ?? null) : null;
            if ($op === null) {
                $row['effective_volume_value'] = 0;
                $row['effective_volume_unit'] = 'annual';
                $row['effective_volume_weekly'] = 0;
                continue;
            }
            $row['effective_volume_value'] = $op['volume_value'];
            $row['effective_volume_unit'] = $op['volume_unit'];
            $row['effective_volume_weekly'] = $op['weekly'];
        }
        unset($row);
    }
    $yearsFlag = strtolower((string) ($_GET['includeVolumeYears'] ?? ''));
    if (($yearsFlag === '1' || $yearsFlag === 'true') && $rows !== []) {
        $ctx = calc_context($source);
        $shareCache = [];
        foreach ($rows as &$row) {
            $row['volume_years'] = calc_positive_volume_years($source, $ctx, $row, $contract, $shareCache);
        }
        unset($row);
    }
    return $rows;
}

function machine_material_external_volumes(PDO $pdo, int $machineId, int $materialId): ?array
{
    $check = $pdo->prepare('SELECT id, is_baseline FROM machines WHERE id = ?');
    $check->execute([$machineId]);
    $machine = $check->fetch();
    if (!$machine) {
        return null;
    }
    if ((int) $machine['is_baseline'] !== 1) {
        return ['error' => 'Materiały są dostępne tylko dla maszyny bazowej.'];
    }
    $material = $pdo->prepare('SELECT id FROM machine_baseline_materials WHERE id = ? AND machine_id = ?');
    $material->execute([$materialId, $machineId]);
    if (!$material->fetch()) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT year, weekly_volume FROM machine_baseline_material_volumes WHERE material_id = ? ORDER BY year');
    $stmt->execute([$materialId]);
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = ['year' => (int) $row['year'], 'weekly_volume' => (float) $row['weekly_volume']];
    }
    return $rows;
}

function handle_reads(string $method, string $path): bool
{
    if ($method !== 'GET') {
        return false;
    }

    $projectId = 0;
    $projectRoute = '';
    if (preg_match('#^/projects/(\d+)$#', $path, $match) === 1) {
        $projectRoute = 'detail';
        $projectId = (int) $match[1];
    } elseif (preg_match('#^/projects/(\d+)/attachments$#', $path, $match) === 1) {
        $projectRoute = 'attachments';
        $projectId = (int) $match[1];
    } elseif ($path === '/projects/session/actor') {
        $projectRoute = 'actor';
    } elseif ($path === '/settings/phases') {
        $projectRoute = 'phases';
    }

    $machineId = 0;
    $materialVolumeId = 0;
    $machineRoute = '';
    if (preg_match('#^/machines/(\d+)$#', $path, $match) === 1) {
        $machineRoute = 'detail';
        $machineId = (int) $match[1];
    } elseif (preg_match('#^/machines/(\d+)/materials/(\d+)/external-volumes$#', $path, $match) === 1) {
        $machineRoute = 'material-volumes';
        $machineId = (int) $match[1];
        $materialVolumeId = (int) $match[2];
    } elseif (preg_match('#^/machines/(\d+)/materials$#', $path, $match) === 1) {
        $machineRoute = 'materials';
        $machineId = (int) $match[1];
    } elseif (preg_match('#^/machines/(\d+)/material-designations$#', $path, $match) === 1) {
        $machineRoute = 'designations';
        $machineId = (int) $match[1];
    } elseif (preg_match('#^/machines/(\d+)/operations$#', $path, $match) === 1) {
        $machineRoute = 'operations';
        $machineId = (int) $match[1];
    } elseif (preg_match('#^/machines/(\d+)/active-project-operation-count$#', $path, $match) === 1) {
        $machineRoute = 'active-ops';
        $machineId = (int) $match[1];
    }

    $capacityMachineId = 0;
    if (preg_match('#^/capacity/machine/(\d+)$#', $path, $match) === 1) {
        $capacityMachineId = (int) $match[1];
    }

    $routes = [
        '/settings/visual' => 'admin_settings.view',
        '/settings/behavior' => 'admin_settings.view',
        '/settings/designations' => 'designations.view',
        '/machines/types' => 'machines.view',
        '/machines' => 'machines.view',
        '/machine-groups' => 'machines.view',
        '/projects/rfq-filter-tree' => 'projects.view',
        '/projects/operations-copy-sources' => 'projects.view',
        '/projects/clients' => 'projects.view',
        '/projects' => 'projects.view',
        '/capacity/breakdown' => 'calculator.view',
        '/capacity/calculator/period-breakdown' => 'calculator.view',
        '/capacity/calculator/sop-eop-markers' => 'calculator.view',
        '/capacity/calculator' => 'calculator.view',
    ];
    if ($projectRoute === '' && $machineRoute === '' && $capacityMachineId === 0 && !array_key_exists($path, $routes)) {
        return false;
    }

    $pdo = open_database();
    bootstrap_admin($pdo);
    if ($capacityMachineId > 0) {
        require_permission($pdo, 'calculator.view');
        $machine = calculator_one_machine($pdo, $capacityMachineId);
        if ($machine === null) {
            json_response(404, ['error' => 'Machine not found or no capacity data']);
        }
        json_response(200, $machine);
    }
    if ($projectRoute === 'phases') {
        require_any_permission($pdo, ['admin_database.view', 'admin_settings.view']);
        json_response(200, phase_rows($pdo));
    }
    if ($projectRoute === 'actor') {
        $auth = require_permission($pdo, 'projects.view');
        json_response(200, ['login' => user_login_label($auth['user'])]);
    }
    if ($projectRoute !== '') {
        require_any_permission($pdo, ['projects.details', 'projects.edit', 'projects.create_rfq']);
        if ($projectRoute === 'attachments') {
            $attachments = project_attachments($pdo, $projectId);
            if ($attachments === null) {
                json_response(404, ['error' => 'Not found']);
            }
            json_response(200, $attachments);
        }
        $project = project_detail($pdo, $projectId);
        if ($project === null) {
            json_response(404, ['error' => 'Not found']);
        }
        json_response(200, $project);
    }
    if ($machineRoute !== '') {
        if ($machineRoute === 'operations') {
            require_any_permission($pdo, ['machines.view', 'machines.details', 'machines.edit', 'calculator.view']);
            json_response(200, machine_operation_rows($pdo, $machineId));
        }
        if ($machineRoute === 'active-ops') {
            require_any_permission($pdo, ['machines.change_status', 'machines.edit']);
            $active = machine_active_project_operations($pdo, $machineId);
            if ($active === null) {
                json_response(404, ['error' => 'Not found']);
            }
            json_response(200, $active);
        }
        require_any_permission($pdo, ['machines.details', 'machines.edit']);
        if ($machineRoute === 'material-volumes') {
            $volumes = machine_material_external_volumes($pdo, $machineId, $materialVolumeId);
            if ($volumes === null) {
                json_response(404, ['error' => 'Not found']);
            }
            if (isset($volumes['error'])) {
                json_response(400, $volumes);
            }
            json_response(200, $volumes);
        }
        if ($machineRoute === 'materials') {
            $materials = machine_materials($pdo, $machineId);
            if ($materials === null) {
                json_response(404, ['error' => 'Not found']);
            }
            if (isset($materials['error'])) {
                json_response(400, $materials);
            }
            json_response(200, $materials);
        }
        if ($machineRoute === 'designations') {
            $designations = machine_material_designations($pdo, $machineId);
            if ($designations === null) {
                json_response(404, ['error' => 'Not found']);
            }
            if (isset($designations['error'])) {
                json_response(400, $designations);
            }
            json_response(200, $designations);
        }
        $machine = machine_detail($pdo, $machineId);
        if ($machine === null) {
            json_response(404, ['error' => 'Not found']);
        }
        $scenarioId = (int) ($_GET['scenarioId'] ?? 0);
        if ($scenarioId > 0) {
            $machine = scenario_overlay_machines($pdo, $scenarioId, [$machine])[0];
        }
        json_response(200, $machine);
    }
    require_permission($pdo, $routes[$path]);

    if ($path === '/settings/visual') {
        json_response(200, visual_settings($pdo));
    }
    if ($path === '/settings/designations') {
        json_response(200, designation_rows($pdo));
    }
    if ($path === '/settings/behavior') {
        $map = setting_map($pdo);
        json_response(200, [
            'volumes_autosave_enabled' => setting_bool($map, 'volumes_autosave_enabled', true),
            'ocu_enabled' => setting_bool($map, 'ocu_enabled', false),
        ]);
    }
    if ($path === '/machines/types') {
        json_response(200, machine_types($pdo));
    }
    if ($path === '/machines') {
        $scenarioId = (int) ($_GET['scenarioId'] ?? 0);
        if ($scenarioId <= 0) {
            json_response(200, machine_rows($pdo));
        }
        $wanted = query_statuses();
        $savedStatus = $_GET['status'] ?? null;
        $savedStatuses = $_GET['statuses'] ?? null;
        unset($_GET['status'], $_GET['statuses']);
        $rows = scenario_overlay_machines($pdo, $scenarioId, machine_rows($pdo));
        if ($savedStatus !== null) {
            $_GET['status'] = $savedStatus;
        }
        if ($savedStatuses !== null) {
            $_GET['statuses'] = $savedStatuses;
        }
        if ($wanted !== []) {
            $allow = array_flip($wanted);
            $rows = array_values(array_filter($rows, fn(array $row): bool => isset($allow[(string) ($row['status'] ?? '')])));
        }
        json_response(200, $rows);
    }
    if ($path === '/machine-groups') {
        json_response(200, []);
    }
    if ($path === '/projects/clients') {
        json_response(200, project_clients($pdo));
    }
    if ($path === '/projects/rfq-filter-tree') {
        json_response(200, project_rfq_tree($pdo));
    }
    if ($path === '/projects/operations-copy-sources') {
        json_response(200, project_operation_copy_sources($pdo));
    }
    if ($path === '/projects') {
        json_response(200, project_rows($pdo));
    }
    if ($path === '/capacity/breakdown') {
        json_response(200, calculator_breakdown($pdo));
    }
    if ($path === '/capacity/calculator/period-breakdown') {
        json_response(200, calculator_period_breakdown($pdo));
    }
    if ($path === '/capacity/calculator/sop-eop-markers') {
        json_response(200, calculator_sop_eop_markers($pdo));
    }

    json_response(200, calculator_payload($pdo));
}
