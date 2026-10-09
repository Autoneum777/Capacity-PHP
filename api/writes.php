<?php
declare(strict_types=1);

function write_note(PDO $pdo, int $projectId, string $note, string $author, ?int $partId = null, ?int $machineId = null, ?int $operationId = null): void
{
    $pdo->prepare(
        'INSERT INTO project_notes (project_id, note_date, author, note, note_type, machine_id, part_id, operation_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$projectId, date('Y-m-d'), $author, $note, 'auto', $machineId, $partId, $operationId]);
}

function write_no_content(): void
{
    http_response_code(204);
    exit;
}

function write_machine_status(mixed $raw, string $fallback = 'active'): string
{
    $status = strtolower(trim((string) ($raw ?? $fallback)));
    if ($status === 'inactive') {
        return 'inactive';
    }
    if ($status === 'rfq') {
        return 'RFQ';
    }
    return 'active';
}

function write_usage(mixed $value): float
{
    if (!is_numeric($value)) {
        return 1.0;
    }
    return round(max(0.0, min(1.0, (float) $value)) * 10) / 10;
}

function write_dimension(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $number = (float) str_replace(',', '.', (string) $value);
    return is_finite($number) ? $number : null;
}

function write_internal_number(mixed $raw, bool $required): ?string
{
    $text = trim((string) ($raw ?? ''));
    if ($text === '') {
        if ($required) {
            json_response(400, ['error' => 'Podaj numer maszyny']);
        }
        return null;
    }
    if (preg_match('/^\d+(\/\d+)?$/', $text) !== 1) {
        json_response(400, ['error' => 'Numer maszyny: cyfry lub format np. 1134/1']);
    }
    return $text;
}

function write_line(mixed $raw, bool $required): ?string
{
    $digits = preg_replace('/\D/', '', (string) ($raw ?? '')) ?? '';
    if ($digits === '') {
        if ($required) {
            json_response(400, ['error' => 'Numer linii jest wymagany']);
        }
        return null;
    }
    return (string) (int) $digits;
}

function write_type_allowed(PDO $pdo, string $type): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM machine_types')->fetchColumn();
    if ($count === 0) {
        return;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM machine_types WHERE name = ?');
    $stmt->execute([$type]);
    if (!$stmt->fetchColumn()) {
        json_response(400, ['error' => 'Typ maszyny musi być jednym z typów zdefiniowanych w Administracja → Ustawienia bazy → Typy maszyn.']);
    }
}

function write_forward_volume_years(PDO $pdo, int $machineId): array
{
    $year = (int) date('Y');
    $stmt = $pdo->prepare(
        'SELECT DISTINCT v.year
         FROM operation_volume_by_year v
         JOIN operations o ON o.id = v.operation_id
         WHERE o.machine_id = ? AND v.year >= ?
           AND (COALESCE(v.volume_value, 0) > 0 OR COALESCE(v.volume_value_before, 0) > 0)
         ORDER BY v.year'
    );
    $stmt->execute([$machineId, $year]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function write_baseline(array $body, ?array $existing): array
{
    $flag = $body['is_baseline'] ?? ($existing['is_baseline'] ?? 0);
    $baseline = $flag === true || $flag === 1 || $flag === '1' || strtolower((string) $flag) === 'true';
    $hours = isset($body['baseline_available_hours_per_week']) ? (float) $body['baseline_available_hours_per_week'] : (float) ($existing['baseline_available_hours_per_week'] ?? 120);
    $weeks = isset($body['baseline_weeks_per_year']) ? (float) $body['baseline_weeks_per_year'] : (float) ($existing['baseline_weeks_per_year'] ?? 52);
    $pick = function (string $key) use ($body, $existing): ?float {
        if (array_key_exists($key, $body)) {
            return write_dimension($body[$key]);
        }
        return isset($existing[$key]) && is_numeric($existing[$key]) ? (float) $existing[$key] : null;
    };
    $unit = function (string $key, string $fallback) use ($body, $existing): string {
        $raw = array_key_exists($key, $body) ? $body[$key] : ($existing[$key] ?? '');
        $text = trim((string) $raw);
        return $text !== '' ? $text : $fallback;
    };
    if (!$baseline) {
        return [0, 120, 52, null, 'kg/h', null, 'kg/h', null, 'm/min', null, 'm/min', null, 'mm', null, 'mm', 650, null, 'szt'];
    }
    return [
        1,
        max(0, min(168, $hours)),
        max(1, min(53, (int) round($weeks))),
        $pick('baseline_max_throughput_kg_h'),
        $unit('baseline_max_throughput_unit', 'kg/h'),
        $pick('baseline_min_throughput_kg_h'),
        $unit('baseline_min_throughput_unit', 'kg/h'),
        $pick('baseline_max_speed_m_min'),
        $unit('baseline_max_speed_unit', 'm/min'),
        $pick('baseline_min_speed_m_min'),
        $unit('baseline_min_speed_unit', 'm/min'),
        $pick('baseline_max_blank_width_mm'),
        $unit('baseline_max_blank_width_unit', 'mm'),
        $pick('baseline_min_blank_width_mm'),
        $unit('baseline_min_blank_width_unit', 'mm'),
        max(0, $pick('baseline_min_length_mm') ?? 650),
        $pick('baseline_max_blanks_across'),
        $unit('baseline_max_blanks_across_unit', 'szt'),
    ];
}

function write_machine(PDO $pdo, ?int $id): void
{
    $body = request_json();
    $keys = array_keys($body);
    $statusOnly = $id !== null && $keys === ['status'];
    $locationOnly = $id !== null && $keys === ['location'];
    if ($statusOnly) {
        require_any_permission($pdo, ['machines.change_status', 'machines.edit']);
    } else {
        require_permission($pdo, 'machines.edit');
    }
    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM machines WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(404, ['error' => 'Not found']);
        }
    }
    if ($locationOnly) {
        $location = write_line($body['location'], false);
        $pdo->prepare('UPDATE machines SET location = ? WHERE id = ?')->execute([$location, $id]);
        json_response(200, machine_detail($pdo, (int) $id));
    }
    $internal = array_key_exists('internal_number', $body)
        ? write_internal_number($body['internal_number'], false)
        : ($existing['internal_number'] ?? null);
    if ($internal !== null) {
        $dup = $pdo->prepare('SELECT id FROM machines WHERE internal_number = ? AND id != ?');
        $dup->execute([$internal, $id ?? 0]);
        if ($dup->fetch()) {
            json_response(400, ['error' => 'Machine number already exists']);
        }
    }
    $sap = array_key_exists('sap_number', $body) ? trim((string) $body['sap_number']) : (string) ($existing['sap_number'] ?? '');
    if ($sap === '') {
        json_response(400, ['error' => 'sap_number is required']);
    }
    $type = array_key_exists('type', $body) ? trim((string) $body['type']) : (string) ($existing['type'] ?? '');
    if ($type === '') {
        json_response(400, ['error' => 'type is required']);
    }
    if ($id === null || array_key_exists('type', $body)) {
        write_type_allowed($pdo, $type);
    }
    $status = array_key_exists('status', $body) ? write_machine_status($body['status']) : write_machine_status($existing['status'] ?? 'active');
    if ($existing && $status === 'inactive' && strtolower((string) $existing['status']) !== 'inactive') {
        $years = write_forward_volume_years($pdo, (int) $id);
        if ($years !== []) {
            json_response(400, ['error' => 'Nie można dezaktywować maszyny: od bieżącej daty zostaje wolumen (lata: ' . implode(', ', $years) . '). Najpierw przenieś ten wolumen na aktywną maszynę.']);
        }
    }
    $location = array_key_exists('location', $body)
        ? write_line($body['location'], $id === null)
        : ($existing['location'] ?? null);
    $usage = array_key_exists('machine_usage', $body) ? write_usage($body['machine_usage']) : write_usage($existing['machine_usage'] ?? 1);
    $oee = array_key_exists('oee_override', $body)
        ? (is_numeric($body['oee_override'] ?? null) ? (float) $body['oee_override'] : null)
        : ($existing['oee_override'] ?? null);
    $baseline = write_baseline($body, $existing);
    $width = $baseline[0] === 1 ? null : (array_key_exists('width_mm', $body) ? write_dimension($body['width_mm']) : ($existing['width_mm'] ?? null));
    $depth = $baseline[0] === 1 ? null : (array_key_exists('depth_mm', $body) ? write_dimension($body['depth_mm']) : ($existing['depth_mm'] ?? null));
    $height = $baseline[0] === 1 ? null : (array_key_exists('height_mm', $body) ? write_dimension($body['height_mm']) : ($existing['height_mm'] ?? null));
    $stroke = $baseline[0] === 1 ? null : (array_key_exists('stroke_mm', $body) ? write_dimension($body['stroke_mm']) : ($existing['stroke_mm'] ?? null));
    $values = [$internal, $sap, $type, $oee, $status, $location, $usage, $width, $depth, $height, $stroke, ...$baseline];
    if ($id === null) {
        $pdo->prepare(
            'INSERT INTO machines (
                internal_number, sap_number, type, oee_override, status, location, machine_usage,
                width_mm, depth_mm, height_mm, stroke_mm,
                is_baseline, baseline_available_hours_per_week, baseline_weeks_per_year,
                baseline_max_throughput_kg_h, baseline_max_throughput_unit,
                baseline_min_throughput_kg_h, baseline_min_throughput_unit,
                baseline_max_speed_m_min, baseline_max_speed_unit,
                baseline_min_speed_m_min, baseline_min_speed_unit,
                baseline_max_blank_width_mm, baseline_max_blank_width_unit,
                baseline_min_blank_width_mm, baseline_min_blank_width_unit,
                baseline_min_length_mm, baseline_max_blanks_across, baseline_max_blanks_across_unit
            ) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')'
        )->execute($values);
        json_response(201, machine_detail($pdo, (int) $pdo->lastInsertId()));
    }
    $values[] = $id;
    $pdo->prepare(
        'UPDATE machines SET
            internal_number = ?, sap_number = ?, type = ?, oee_override = ?, status = ?, location = ?, machine_usage = ?,
            width_mm = ?, depth_mm = ?, height_mm = ?, stroke_mm = ?,
            is_baseline = ?, baseline_available_hours_per_week = ?, baseline_weeks_per_year = ?,
            baseline_max_throughput_kg_h = ?, baseline_max_throughput_unit = ?,
            baseline_min_throughput_kg_h = ?, baseline_min_throughput_unit = ?,
            baseline_max_speed_m_min = ?, baseline_max_speed_unit = ?,
            baseline_min_speed_m_min = ?, baseline_min_speed_unit = ?,
            baseline_max_blank_width_mm = ?, baseline_max_blank_width_unit = ?,
            baseline_min_blank_width_mm = ?, baseline_min_blank_width_unit = ?,
            baseline_min_length_mm = ?, baseline_max_blanks_across = ?, baseline_max_blanks_across_unit = ?
         WHERE id = ?'
    )->execute($values);
    json_response(200, machine_detail($pdo, $id));
}

function write_project_status(mixed $raw): string
{
    if ($raw === 'RFQ') {
        return 'RFQ';
    }
    if ($raw === 'inactive') {
        return 'inactive';
    }
    return 'active';
}

function write_project(PDO $pdo, ?int $id): void
{
    $body = request_json();
    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(404, ['error' => 'Not found']);
        }
    }
    $statusOnly = $id !== null && array_keys($body) === ['status'];
    if ($id === null) {
        $auth = require_any_permission($pdo, ['projects.edit', 'projects.create_rfq']);
        if (!in_array('projects.edit', $auth['permissions'], true) && write_project_status($body['status'] ?? '') !== 'RFQ') {
            json_response(403, ['error' => 'Brak uprawnień']);
        }
    } elseif ($statusOnly) {
        $auth = require_any_permission($pdo, ['projects.change_status', 'projects.edit', 'projects.create_rfq']);
    } else {
        $auth = require_any_permission($pdo, ['projects.edit', 'projects.create_rfq']);
        if (!in_array('projects.edit', $auth['permissions'], true) && (string) $existing['status'] !== 'RFQ') {
            json_response(403, ['error' => 'Brak uprawnień']);
        }
    }
    $author = user_login_label($auth['user']);
    $client = array_key_exists('client', $body) ? strtoupper(trim((string) $body['client'])) : strtoupper(trim((string) ($existing['client'] ?? '')));
    $name = array_key_exists('name', $body) ? trim((string) $body['name']) : (string) ($existing['name'] ?? '');
    $sop = array_key_exists('sop', $body) ? format_sop_eop($body['sop']) : format_sop_eop($existing['sop'] ?? '');
    $status = array_key_exists('status', $body) ? write_project_status($body['status']) : ($id === null ? 'active' : write_project_status($existing['status'] ?? 'active'));
    $extension = trim((string) ($body['eop_extension'] ?? ''));
    $eopOriginal = $existing['eop_original'] ?? null;
    if ($extension !== '') {
        $eop = format_sop_eop($extension);
        $eopOriginal = $existing['eop_original'] ?? $existing['eop'];
    } else {
        $eop = array_key_exists('eop', $body) ? format_sop_eop($body['eop']) : format_sop_eop($existing['eop'] ?? '');
    }
    if ($id === null) {
        $pdo->prepare('INSERT INTO projects (client, name, sop, eop, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$client, $name, $sop, $eop, $status]);
        json_response(201, project_detail($pdo, (int) $pdo->lastInsertId()));
    }
    $pdo->prepare('UPDATE projects SET client = ?, name = ?, sop = ?, eop = ?, eop_original = ?, status = ? WHERE id = ?')
        ->execute([$client, $name, $sop, $eop, $eopOriginal, $status, $id]);
    if ($extension !== '') {
        write_note($pdo, $id, 'Automatyczna zmiana: Przedłużenie EOP (poprzednia data ' . $existing['eop'] . ', nowa data ' . $eop . ').', $author);
        try {
            $pdo->prepare('INSERT INTO project_eop_extensions (project_id, eop_before, eop_after) VALUES (?, ?, ?)')
                ->execute([$id, $existing['eop'], $eop]);
        } catch (Throwable) {
        }
    }
    $changed = [];
    if ($client !== (string) $existing['client']) {
        $changed[] = 'klient: "' . $existing['client'] . '" → "' . $client . '"';
    }
    if ($name !== (string) $existing['name']) {
        $changed[] = 'nazwa: "' . $existing['name'] . '" → "' . $name . '"';
    }
    if ($sop !== format_sop_eop($existing['sop'])) {
        $changed[] = 'SOP: "' . $existing['sop'] . '" → "' . $sop . '"';
    }
    if ($eop !== format_sop_eop($existing['eop']) && $extension === '') {
        $changed[] = 'EOP: "' . $existing['eop'] . '" → "' . $eop . '"';
    }
    if ($status !== (string) $existing['status']) {
        $changed[] = 'status: "' . $existing['status'] . '" → "' . $status . '"';
    }
    if ($changed !== []) {
        write_note($pdo, $id, 'Automatyczna zmiana: ' . implode('; ', $changed), $author);
    }
    json_response(200, project_detail($pdo, $id));
}

function write_part(PDO $pdo, int $projectId, ?int $partId): void
{
    $body = request_json();
    $project = $pdo->prepare('SELECT id, status FROM projects WHERE id = ?');
    $project->execute([$projectId]);
    $projectRow = $project->fetch();
    if (!$projectRow) {
        json_response(404, ['error' => 'Not found']);
    }
    $auth = require_any_permission($pdo, ['projects.edit', 'projects.create_rfq']);
    if (!in_array('projects.edit', $auth['permissions'], true) && (string) $projectRow['status'] !== 'RFQ') {
        json_response(403, ['error' => 'Brak uprawnień']);
    }
    $author = user_login_label($auth['user']);
    if ($partId === null) {
        $designationId = isset($body['designation_id']) && is_numeric($body['designation_id']) ? (int) $body['designation_id'] : null;
        $designation = trim((string) ($body['designation'] ?? ''));
        if ($designationId) {
            $detail = $pdo->prepare('SELECT sap_number, alias, free_text, designation FROM part_designations WHERE id = ?');
            $detail->execute([$designationId]);
            $row = $detail->fetch();
            if ($row) {
                $designation = (string) ($row['sap_number'] ?: $row['alias'] ?: $row['free_text'] ?: $row['designation'] ?: $designation);
            }
        }
        $side = in_array($body['side'] ?? '', ['RH', 'LH'], true) ? $body['side'] : null;
        $pdo->prepare('INSERT INTO parts (project_id, designation, side, designation_id) VALUES (?, ?, ?, ?)')
            ->execute([$projectId, $designation !== '' ? $designation : '-', $side, $designationId]);
        $newId = (int) $pdo->lastInsertId();
        write_note($pdo, $projectId, 'Automatyczna zmiana: dodano detal "' . ($designation !== '' ? $designation : '-') . '".', $author, $newId);
        json_response(201, write_part_row($pdo, $newId));
    }
    $stmt = $pdo->prepare('SELECT * FROM parts WHERE id = ? AND project_id = ?');
    $stmt->execute([$partId, $projectId]);
    $part = $stmt->fetch();
    if (!$part) {
        json_response(404, ['error' => 'Not found']);
    }
    $designation = array_key_exists('designation', $body) ? trim((string) $body['designation']) : (string) $part['designation'];
    $side = array_key_exists('side', $body) ? (in_array($body['side'], ['RH', 'LH'], true) ? $body['side'] : null) : $part['side'];
    $mode = write_volume_mode($body['volume_mode'] ?? null, (string) ($part['volume_mode'] ?? 'project'));
    $contractMode = write_volume_mode($body['contract_volume_mode'] ?? null, (string) ($part['contract_volume_mode'] ?? 'project'));
    $pdo->prepare(
        'UPDATE parts SET designation = ?, side = ?, volume_mode = ?, volume_share_percent = ?, default_volume_value = ?, default_volume_unit = ?,
            contract_volume_mode = ?, contract_volume_share_percent = ?, contract_default_volume_value = ?, contract_default_volume_unit = ?
         WHERE id = ?'
    )->execute([
        $designation,
        $side,
        $mode,
        write_optional_number($body, 'volume_share_percent', $part['volume_share_percent'] ?? null),
        write_optional_number($body, 'default_volume_value', $part['default_volume_value'] ?? null),
        write_volume_unit($body, 'default_volume_unit', $part['default_volume_unit'] ?? null),
        $contractMode,
        write_optional_number($body, 'contract_volume_share_percent', $part['contract_volume_share_percent'] ?? null),
        write_optional_number($body, 'contract_default_volume_value', $part['contract_default_volume_value'] ?? null),
        write_volume_unit($body, 'contract_default_volume_unit', $part['contract_default_volume_unit'] ?? null),
        $partId,
    ]);
    write_share_years($pdo, 'part_volume_share_by_year', $partId, $body['volume_share_by_year'] ?? null);
    write_share_years($pdo, 'part_volume_contract_share_by_year', $partId, $body['contract_volume_share_by_year'] ?? null);
    write_note($pdo, $projectId, 'Automatyczna zmiana: zaktualizowano detal "' . $designation . '".', $author, $partId);
    json_response(200, write_part_row($pdo, $partId));
}

function write_volume_mode(mixed $value, string $fallback): string
{
    return in_array($value, ['project', 'share', 'override'], true) ? (string) $value : $fallback;
}

function write_optional_number(array $body, string $key, mixed $fallback): ?float
{
    if (!array_key_exists($key, $body)) {
        return is_numeric($fallback) ? (float) $fallback : null;
    }
    if ($body[$key] === null || $body[$key] === '') {
        return null;
    }
    return is_numeric($body[$key]) ? (float) $body[$key] : null;
}

function write_volume_unit(array $body, string $key, mixed $fallback): ?string
{
    if (!array_key_exists($key, $body)) {
        return is_string($fallback) ? $fallback : null;
    }
    return in_array($body[$key], ['annual', 'monthly', 'weekly'], true) ? (string) $body[$key] : null;
}

function write_share_years(PDO $pdo, string $table, int $partId, mixed $rows): void
{
    if (!is_array($rows)) {
        return;
    }
    $pdo->prepare('DELETE FROM ' . $table . ' WHERE part_id = ?')->execute([$partId]);
    $insert = $pdo->prepare('INSERT INTO ' . $table . ' (part_id, year, share_percent) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        if (!is_array($row) || !is_numeric($row['year'] ?? null) || !is_numeric($row['share_percent'] ?? null)) {
            continue;
        }
        $insert->execute([$partId, (int) $row['year'], max(0, min(100, (float) $row['share_percent']))]);
    }
}

function write_part_row(PDO $pdo, int $partId): array
{
    $stmt = $pdo->prepare(
        'SELECT pt.*, pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text
         FROM parts pt
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE pt.id = ?'
    );
    $stmt->execute([$partId]);
    $row = $stmt->fetch();
    $row['id'] = (int) $row['id'];
    $row['project_id'] = (int) $row['project_id'];
    $row['detail'] = $row['designation_id'] === null ? null : [
        'sap_number' => $row['detail_sap_number'],
        'alias' => $row['detail_alias'],
        'free_text' => $row['detail_free_text'],
    ];
    return $row;
}

function write_designation_text(mixed $value): ?string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '' || in_array(strtolower($text), ['-', '—', 'n/a', 'na', 'brak'], true)) {
        return null;
    }
    return $text;
}

function write_designation_key(?string $sap, ?string $alias, ?string $free): string
{
    $fold = fn(?string $value): string => function_exists('mb_strtolower') ? mb_strtolower((string) $value) : strtolower((string) $value);
    return $fold($sap) . "\u{0001}" . $fold($alias) . "\u{0001}" . $fold($free);
}

function write_designation_duplicate(PDO $pdo, ?string $sap, ?string $alias, ?string $free, ?int $excludeId): ?int
{
    $target = write_designation_key($sap, $alias, $free);
    foreach ($pdo->query('SELECT id, sap_number, alias, free_text, designation FROM part_designations') as $row) {
        if ($excludeId !== null && (int) $row['id'] === $excludeId) {
            continue;
        }
        $rowFree = write_designation_text($row['free_text']) ?? write_designation_text($row['designation']);
        if (write_designation_key(write_designation_text($row['sap_number']), write_designation_text($row['alias']), $rowFree) === $target) {
            return (int) $row['id'];
        }
    }
    return null;
}

function write_designation_value(?string $sap, ?string $alias, ?string $free): string
{
    $parts = array_values(array_filter([$sap, $alias, $free], fn(?string $part): bool => $part !== null && $part !== ''));
    if ($parts === []) {
        return '-';
    }
    return count($parts) === 1 ? $parts[0] : implode(' | ', $parts);
}

function write_designation(PDO $pdo, ?int $id): void
{
    require_permission($pdo, 'designations.edit');
    $body = request_json();
    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM part_designations WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(404, ['error' => 'Not found']);
        }
    }
    $sap = array_key_exists('sap_number', $body) ? write_designation_text($body['sap_number']) : write_designation_text($existing['sap_number'] ?? null);
    $alias = array_key_exists('alias', $body) ? write_designation_text($body['alias']) : write_designation_text($existing['alias'] ?? null);
    $free = array_key_exists('free_text', $body) ? write_designation_text($body['free_text']) : write_designation_text($existing['free_text'] ?? null);
    if ($sap === null && $alias === null && $free === null) {
        json_response(400, ['error' => 'Wypełnij co najmniej jedno pole (Nr SAP, Alias lub Free text)']);
    }
    if (write_designation_duplicate($pdo, $sap, $alias, $free, $id) !== null) {
        json_response(409, ['error' => 'Detal o takim oznaczeniu już istnieje i nie został utworzony kolejny']);
    }
    $designation = isset($body['designation']) && trim((string) $body['designation']) !== ''
        ? trim((string) $body['designation'])
        : write_designation_value($sap, $alias, $free);
    $slot = array_key_exists('slot_number', $body)
        ? (trim((string) ($body['slot_number'] ?? '')) !== '' ? trim((string) $body['slot_number']) : null)
        : ($existing['slot_number'] ?? null);
    if ($id === null) {
        $pdo->prepare('INSERT INTO part_designations (designation, sap_number, alias, free_text, slot_number) VALUES (?, ?, ?, ?, ?)')
            ->execute([$designation, $sap, $alias, $free, $slot]);
        $id = (int) $pdo->lastInsertId();
        $status = 201;
    } else {
        $pdo->prepare('UPDATE part_designations SET designation = ?, sap_number = ?, alias = ?, free_text = ?, slot_number = ? WHERE id = ?')
            ->execute([$designation, $sap, $alias, $free, $slot, $id]);
        $status = 200;
    }
    foreach (designation_rows($pdo) as $row) {
        if ((int) $row['id'] === $id) {
            json_response($status, $row);
        }
    }
    json_response($status, ['id' => $id, 'designation' => $designation, 'sap_number' => $sap, 'alias' => $alias, 'free_text' => $free, 'slot_number' => $slot, 'projects' => [], 'machine_lines' => []]);
}

function write_project_actor(PDO $pdo, int $projectId): array
{
    $auth = require_any_permission($pdo, ['projects.edit', 'projects.create_rfq']);
    $stmt = $pdo->prepare('SELECT id, status, sop, eop, eop_original FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        json_response(404, ['error' => 'Not found']);
    }
    if (!in_array('projects.edit', $auth['permissions'], true) && (string) $project['status'] !== 'RFQ') {
        json_response(403, ['error' => 'Brak uprawnień']);
    }
    $auth['project'] = $project;
    return $auth;
}

function write_delete_actor(PDO $pdo): array
{
    $auth = require_permission($pdo, 'projects.delete');
    if (!in_array('projects.edit', $auth['permissions'], true) && in_array('projects.create_rfq', $auth['permissions'], true)) {
        json_response(403, ['error' => 'Brak uprawnień do usuwania (uprawnienie RFQ nie obejmuje usuwania).']);
    }
    return $auth;
}

function write_volume_unit_value(mixed $value): string
{
    return in_array($value, ['annual', 'monthly', 'weekly'], true) ? (string) $value : 'annual';
}

function write_volume_origin(mixed $value): string
{
    return trim((string) ($value ?? '')) === 'default_all_years' ? 'default_all_years' : 'manual_year';
}

function write_flag(mixed $value): int
{
    return $value === true || $value === 1 || $value === '1' ? 1 : 0;
}

function write_clear_allocation_volumes(PDO $pdo, int $projectId): void
{
    $split = $pdo->prepare('SELECT 1 FROM operations WHERE project_id = ? AND split_from_operation_id IS NOT NULL LIMIT 1');
    $split->execute([$projectId]);
    if ($split->fetch()) {
        return;
    }
    $pdo->prepare(
        "DELETE FROM operation_volume_by_year
         WHERE operation_id IN (SELECT id FROM operations WHERE project_id = ?)
           AND COALESCE(source, 'manual') = 'allocation'"
    )->execute([$projectId]);
}

function write_project_volume_rows(PDO $pdo, string $table, int $projectId): array
{
    $stmt = $pdo->prepare(
        "SELECT year, volume_value, volume_unit, COALESCE(include_in_calculator_after_eop, 0) AS include_in_calculator_after_eop, volume_origin
         FROM {$table} WHERE project_id = ? ORDER BY year"
    );
    $stmt->execute([$projectId]);
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'year' => (int) $row['year'],
            'volume_value' => (float) $row['volume_value'],
            'volume_unit' => (string) $row['volume_unit'],
            'include_in_calculator_after_eop' => (int) $row['include_in_calculator_after_eop'],
            'volume_origin' => (string) $row['volume_origin'],
        ];
    }
    return $rows;
}

function write_replace_project_volumes(PDO $pdo, int $projectId, string $table, string $note): void
{
    $auth = write_project_actor($pdo, $projectId);
    $body = request_json();
    $volumes = is_array($body['volumes'] ?? null) ? $body['volumes'] : [];
    $prepared = [];
    foreach ($volumes as $volume) {
        if (!is_array($volume) || !is_numeric($volume['year'] ?? null) || !is_numeric($volume['volume_value'] ?? null)) {
            json_response(400, ['error' => 'Nieprawidłowy wolumen.']);
        }
        $prepared[] = [
            (int) $volume['year'],
            (float) $volume['volume_value'],
            write_volume_unit_value($volume['volume_unit'] ?? null),
            write_flag($volume['include_in_calculator_after_eop'] ?? 0),
            write_volume_origin($volume['volume_origin'] ?? null),
        ];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM {$table} WHERE project_id = ?")->execute([$projectId]);
        $insert = $pdo->prepare(
            "INSERT INTO {$table} (project_id, year, volume_value, volume_unit, include_in_calculator_after_eop, volume_origin)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($prepared as $row) {
            $insert->execute([$projectId, ...$row]);
        }
        write_clear_allocation_volumes($pdo, $projectId);
        write_note($pdo, $projectId, $note . ' (' . count($prepared) . ' rekordów).', user_login_label($auth['user']));
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response(200, write_project_volume_rows($pdo, $table, $projectId));
}

function write_mirror_project_volumes(PDO $pdo, int $projectId): void
{
    $auth = write_project_actor($pdo, $projectId);
    $direction = trim((string) (request_json()['direction'] ?? ''));
    if ($direction !== 'production_to_contract' && $direction !== 'contract_to_production') {
        json_response(400, ['error' => 'direction: production_to_contract | contract_to_production']);
    }
    $pdo->beginTransaction();
    try {
        if ($direction === 'production_to_contract') {
            $pdo->prepare('DELETE FROM project_volumes_contract WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare(
                'INSERT INTO project_volumes_contract (project_id, year, volume_value, volume_unit, include_in_calculator_after_eop, volume_origin)
                 SELECT project_id, year, volume_value, volume_unit, COALESCE(include_in_calculator_after_eop, 0), COALESCE(volume_origin, \'manual_year\')
                 FROM project_volumes WHERE project_id = ?'
            )->execute([$projectId]);
        } else {
            $pdo->prepare('DELETE FROM project_volumes WHERE project_id = ?')->execute([$projectId]);
            $pdo->prepare(
                'INSERT INTO project_volumes (project_id, year, volume_value, volume_unit, include_in_calculator_after_eop, volume_origin)
                 SELECT project_id, year, volume_value, volume_unit, COALESCE(include_in_calculator_after_eop, 0), COALESCE(volume_origin, \'manual_year\')
                 FROM project_volumes_contract WHERE project_id = ?'
            )->execute([$projectId]);
        }
        write_clear_allocation_volumes($pdo, $projectId);
        $label = $direction === 'production_to_contract' ? 'produkcja → kontrakt' : 'kontrakt → produkcja';
        write_note($pdo, $projectId, 'Automatyczna zmiana: skopiowano wolumeny projektu (' . $label . ').', user_login_label($auth['user']));
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response(200, ['ok' => true]);
}

function write_part_owned(PDO $pdo, int $projectId, int $partId): array
{
    $stmt = $pdo->prepare('SELECT * FROM parts WHERE id = ? AND project_id = ?');
    $stmt->execute([$partId, $projectId]);
    $part = $stmt->fetch();
    if (!$part) {
        json_response(404, ['error' => 'Not found']);
    }
    return $part;
}

function write_part_label(PDO $pdo, int $partId): string
{
    $stmt = $pdo->prepare(
        'SELECT pt.id, pt.designation, pd.sap_number, pd.alias, pd.free_text
         FROM parts pt
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE pt.id = ?'
    );
    $stmt->execute([$partId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'detal #' . $partId;
    }
    return detail_label($row, reference_mode($pdo));
}

function write_part_volume_rows(PDO $pdo, string $table, int $partId): array
{
    $stmt = $pdo->prepare("SELECT year, volume_value, volume_unit, volume_origin FROM {$table} WHERE part_id = ? ORDER BY year");
    $stmt->execute([$partId]);
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'year' => (int) $row['year'],
            'volume_value' => (float) $row['volume_value'],
            'volume_unit' => (string) $row['volume_unit'],
            'volume_origin' => (string) $row['volume_origin'],
        ];
    }
    return $rows;
}

function write_replace_part_volumes(PDO $pdo, int $projectId, int $partId, string $table, string $note): void
{
    $auth = write_project_actor($pdo, $projectId);
    write_part_owned($pdo, $projectId, $partId);
    $body = request_json();
    $volumes = is_array($body['volumes'] ?? null) ? $body['volumes'] : [];
    $prepared = [];
    foreach ($volumes as $volume) {
        if (!is_array($volume) || !is_numeric($volume['year'] ?? null) || !is_numeric($volume['volume_value'] ?? null)) {
            json_response(400, ['error' => 'Nieprawidłowy wolumen.']);
        }
        $prepared[] = [
            (int) $volume['year'],
            (float) $volume['volume_value'],
            write_volume_unit_value($volume['volume_unit'] ?? null),
            write_volume_origin($volume['volume_origin'] ?? null),
        ];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM {$table} WHERE part_id = ?")->execute([$partId]);
        $insert = $pdo->prepare("INSERT INTO {$table} (part_id, year, volume_value, volume_unit, volume_origin) VALUES (?, ?, ?, ?, ?)");
        foreach ($prepared as $row) {
            $insert->execute([$partId, ...$row]);
        }
        write_note($pdo, $projectId, $note . ' "' . write_part_label($pdo, $partId) . '" (' . count($prepared) . ' rekordów).', user_login_label($auth['user']), $partId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response(200, write_part_volume_rows($pdo, $table, $partId));
}

function write_mirror_part_volumes(PDO $pdo, int $projectId, int $partId): void
{
    $auth = write_project_actor($pdo, $projectId);
    write_part_owned($pdo, $projectId, $partId);
    $direction = trim((string) (request_json()['direction'] ?? ''));
    if ($direction !== 'production_to_contract' && $direction !== 'contract_to_production') {
        json_response(400, ['error' => 'direction: production_to_contract | contract_to_production']);
    }
    $pdo->beginTransaction();
    try {
        if ($direction === 'production_to_contract') {
            $pdo->prepare('DELETE FROM part_volume_contract_by_year WHERE part_id = ?')->execute([$partId]);
            $pdo->prepare(
                'INSERT INTO part_volume_contract_by_year (part_id, year, volume_value, volume_unit, volume_origin)
                 SELECT part_id, year, volume_value, volume_unit, COALESCE(volume_origin, \'manual_year\') FROM part_volume_by_year WHERE part_id = ?'
            )->execute([$partId]);
            $pdo->prepare('DELETE FROM part_volume_contract_share_by_year WHERE part_id = ?')->execute([$partId]);
            $pdo->prepare(
                'INSERT INTO part_volume_contract_share_by_year (part_id, year, share_percent)
                 SELECT part_id, year, share_percent FROM part_volume_share_by_year WHERE part_id = ?'
            )->execute([$partId]);
            $pdo->prepare(
                'UPDATE parts SET contract_volume_mode = volume_mode, contract_volume_share_percent = volume_share_percent,
                    contract_default_volume_value = default_volume_value, contract_default_volume_unit = default_volume_unit
                 WHERE id = ?'
            )->execute([$partId]);
        } else {
            $pdo->prepare('DELETE FROM part_volume_by_year WHERE part_id = ?')->execute([$partId]);
            $pdo->prepare(
                'INSERT INTO part_volume_by_year (part_id, year, volume_value, volume_unit, volume_origin)
                 SELECT part_id, year, volume_value, volume_unit, COALESCE(volume_origin, \'manual_year\') FROM part_volume_contract_by_year WHERE part_id = ?'
            )->execute([$partId]);
            $pdo->prepare('DELETE FROM part_volume_share_by_year WHERE part_id = ?')->execute([$partId]);
            $pdo->prepare(
                'INSERT INTO part_volume_share_by_year (part_id, year, share_percent)
                 SELECT part_id, year, share_percent FROM part_volume_contract_share_by_year WHERE part_id = ?'
            )->execute([$partId]);
            $pdo->prepare(
                'UPDATE parts SET volume_mode = contract_volume_mode, volume_share_percent = contract_volume_share_percent,
                    default_volume_value = contract_default_volume_value, default_volume_unit = contract_default_volume_unit
                 WHERE id = ?'
            )->execute([$partId]);
        }
        $label = $direction === 'production_to_contract' ? 'produkcja → kontrakt' : 'kontrakt → produkcja';
        write_note(
            $pdo,
            $projectId,
            'Automatyczna zmiana: skopiowano wolumeny (' . $label . ') detalu "' . write_part_label($pdo, $partId) . '".',
            user_login_label($auth['user']),
            $partId
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response(200, ['ok' => true]);
}

function write_sop_eop_years(string $sop, string $eop): array
{
    $sop = format_sop_eop($sop);
    $eop = format_sop_eop($eop);
    if (preg_match('/^(\d{1,2})\.(\d{4})$/', $sop, $start) !== 1 || preg_match('/^(\d{1,2})\.(\d{4})$/', $eop, $end) !== 1) {
        return ['years' => [], 'eopYear' => 0];
    }
    $from = (int) $start[2];
    $to = (int) $end[2];
    if ($to < $from) {
        return ['years' => [], 'eopYear' => 0];
    }
    return ['years' => range($from, $to), 'eopYear' => $to];
}

function write_sync_volumes(PDO $pdo, int $projectId, string $sop, string $eop): void
{
    $range = write_sop_eop_years($sop, $eop);
    $years = $range['years'];
    $eopYear = (int) $range['eopYear'];
    foreach (['project_volumes', 'project_volumes_contract'] as $table) {
        $existing = $pdo->prepare("SELECT year FROM {$table} WHERE project_id = ?");
        $existing->execute([$projectId]);
        $have = [];
        foreach ($existing as $row) {
            $have[(int) $row['year']] = true;
        }
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE project_id = ? AND year = ?");
        foreach (array_keys($have) as $year) {
            if ($year > $eopYear) {
                $delete->execute([$projectId, $year]);
            }
        }
        $insert = $pdo->prepare(
            "INSERT INTO {$table} (project_id, year, volume_value, volume_unit, include_in_calculator_after_eop, volume_origin)
             VALUES (?, ?, 0, 'annual', 0, 'manual_year')"
        );
        foreach ($years as $year) {
            if (!isset($have[$year])) {
                $insert->execute([$projectId, $year]);
            }
        }
    }
    if ($eopYear <= 0) {
        return;
    }
    $parts = $pdo->prepare('SELECT id FROM parts WHERE project_id = ?');
    $parts->execute([$projectId]);
    $partIds = [];
    foreach ($parts as $row) {
        $partIds[] = (int) $row['id'];
    }
    foreach ($partIds as $partId) {
        foreach (['part_volume_by_year', 'part_volume_contract_by_year', 'part_volume_share_by_year', 'part_volume_contract_share_by_year'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE part_id = ? AND year > ?")->execute([$partId, $eopYear]);
        }
    }
}

function write_add_volume_year(PDO $pdo, int $projectId, int $partId): void
{
    $auth = write_project_actor($pdo, $projectId);
    $body = request_json();
    $year = is_numeric($body['year'] ?? null) ? (int) $body['year'] : 0;
    if ($year < 1900 || $year > 2200) {
        json_response(400, ['error' => 'Nieprawidłowy rok.']);
    }
    $side = ($body['volumeSide'] ?? '') === 'contract' ? 'contract' : 'production';
    $project = $auth['project'];
    $part = write_part_owned($pdo, $projectId, $partId);
    $mode = $side === 'contract' ? (string) ($part['contract_volume_mode'] ?? 'project') : (string) ($part['volume_mode'] ?? 'project');
    if ($mode !== 'share' && $mode !== 'override') {
        json_response(400, ['error' => 'Dodawanie roku jest dostępne tylko dla trybu „% udział” lub „Własna wartość”.']);
    }
    $range = write_sop_eop_years((string) $project['sop'], (string) $project['eop']);
    if (in_array($year, $range['years'], true)) {
        json_response(400, ['error' => 'Ten rok jest już w tabeli.']);
    }
    $extends = (int) $range['eopYear'] > 0 && $year > (int) $range['eopYear'];
    $table = $mode === 'share'
        ? ($side === 'contract' ? 'part_volume_contract_share_by_year' : 'part_volume_share_by_year')
        : ($side === 'contract' ? 'part_volume_contract_by_year' : 'part_volume_by_year');
    $exists = $pdo->prepare("SELECT 1 FROM {$table} WHERE part_id = ? AND year = ?");
    $exists->execute([$partId, $year]);
    $already = (bool) $exists->fetch();
    $orphaned = $already && $year > (int) $range['eopYear'];
    if ($already && !$orphaned) {
        json_response(400, ['error' => 'Ten rok jest już w tabeli.']);
    }
    $pdo->beginTransaction();
    try {
        if (!$already && $mode === 'share') {
            $percent = $side === 'contract'
                ? (is_numeric($part['contract_volume_share_percent']) ? (float) $part['contract_volume_share_percent'] : 0)
                : (is_numeric($part['volume_share_percent']) ? (float) $part['volume_share_percent'] : 0);
            $pdo->prepare("INSERT INTO {$table} (part_id, year, share_percent) VALUES (?, ?, ?)")->execute([$partId, $year, $percent]);
            if ($side === 'production') {
                $contractPercent = is_numeric($part['contract_volume_share_percent']) ? (float) $part['contract_volume_share_percent'] : $percent;
                $other = $pdo->prepare('SELECT 1 FROM part_volume_contract_share_by_year WHERE part_id = ? AND year = ?');
                $other->execute([$partId, $year]);
                if (!$other->fetch()) {
                    $pdo->prepare('INSERT INTO part_volume_contract_share_by_year (part_id, year, share_percent) VALUES (?, ?, ?)')
                        ->execute([$partId, $year, $contractPercent]);
                }
            }
        } elseif (!$already) {
            $pdo->prepare("INSERT INTO {$table} (part_id, year, volume_value, volume_unit) VALUES (?, ?, 0, 'annual')")->execute([$partId, $year]);
            if ($side === 'production') {
                $other = $pdo->prepare('SELECT 1 FROM part_volume_contract_by_year WHERE part_id = ? AND year = ?');
                $other->execute([$partId, $year]);
                if (!$other->fetch()) {
                    $pdo->prepare('INSERT INTO part_volume_contract_by_year (part_id, year, volume_value, volume_unit) VALUES (?, ?, 0, \'annual\')')
                        ->execute([$partId, $year]);
                }
            }
        }
        $newEop = (string) $project['eop'];
        if ($extends) {
            $newEop = format_sop_eop('12.' . $year);
            $original = $project['eop_original'] ?? $project['eop'];
            $pdo->prepare('UPDATE projects SET eop = ?, eop_original = ? WHERE id = ?')->execute([$newEop, $original, $projectId]);
            try {
                $pdo->prepare('INSERT INTO project_eop_extensions (project_id, eop_before, eop_after) VALUES (?, ?, ?)')
                    ->execute([$projectId, $project['eop'], $newEop]);
            } catch (Throwable) {
            }
            write_sync_volumes($pdo, $projectId, (string) $project['sop'], $newEop);
        }
        $volumeLabel = $side === 'contract' ? 'wolumenie kontraktowym detalu' : 'wolumenie detalu';
        $note = 'dodano rok ' . $year . ' w ' . $volumeLabel . ' "' . write_part_label($pdo, $partId) . '" (zmiana z poziomu detalu)';
        if ($extends) {
            $note .= '; przedłużono EOP (poprzednia data ' . $project['eop'] . ', nowa data ' . $newEop . ')';
        }
        write_note($pdo, $projectId, 'Automatyczna zmiana: ' . $note . '.', user_login_label($auth['user']), $partId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response(200, ['year' => $year, 'eop' => format_sop_eop($newEop), 'eop_extended' => $extends]);
}

function write_delete_part(PDO $pdo, int $projectId, int $partId): void
{
    $auth = write_delete_actor($pdo);
    $project = $pdo->prepare('SELECT status FROM projects WHERE id = ?');
    $project->execute([$projectId]);
    if (!$project->fetch()) {
        json_response(404, ['error' => 'Not found']);
    }
    $label = write_part_label($pdo, $partId);
    $exists = $pdo->prepare('SELECT 1 FROM parts WHERE id = ? AND project_id = ?');
    $exists->execute([$partId, $projectId]);
    if (!$exists->fetch()) {
        json_response(404, ['error' => 'Not found']);
    }
    write_note($pdo, $projectId, 'Automatyczna zmiana: usunięto detal "' . $label . '".', user_login_label($auth['user']), $partId);
    $pdo->prepare('DELETE FROM parts WHERE id = ? AND project_id = ?')->execute([$partId, $projectId]);
    write_no_content();
}

function write_positive_ids(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }
    $ids = [];
    foreach ($values as $value) {
        if (is_numeric($value) && (int) $value > 0) {
            $ids[(int) $value] = true;
        }
    }
    return array_keys($ids);
}

function write_optional_float(array $body, string $key, mixed $fallback): ?float
{
    if (!array_key_exists($key, $body)) {
        return is_numeric($fallback) ? (float) $fallback : null;
    }
    if ($body[$key] === null || $body[$key] === '') {
        return null;
    }
    return is_numeric($body[$key]) ? (float) $body[$key] : null;
}

function write_rebalance_capacity(PDO $pdo, int $machineId, int $projectId, float $next): float
{
    $stmt = $pdo->prepare('SELECT capacity_percent FROM operations WHERE machine_id = ? AND project_id = ?');
    $stmt->execute([$machineId, $projectId]);
    $sum = 0.0;
    $count = 0;
    foreach ($stmt as $row) {
        $sum += (float) $row['capacity_percent'];
        $count++;
    }
    $total = $sum + $next;
    if (abs($total - 100) <= 0.01 || $total <= 100) {
        return $next;
    }
    $per = round(100 / ($count + 1), 2);
    $pdo->prepare('UPDATE operations SET capacity_percent = ? WHERE machine_id = ? AND project_id = ?')->execute([$per, $machineId, $projectId]);
    return $per;
}

function write_operation_row(PDO $pdo, int $operationId): array
{
    $stmt = $pdo->prepare(
        'SELECT o.*, ph.name AS phase_name, m.internal_number AS machine_number, m.sap_number AS machine_sap_number, m.type AS machine_type,
                pd.sap_number AS detail_sap, pd.alias AS detail_alias, pd.free_text AS detail_free, pt.designation AS detail_designation
         FROM operations o
         JOIN process_phases ph ON ph.id = o.phase_id
         JOIN parts pt ON pt.id = o.part_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         JOIN machines m ON m.id = o.machine_id
         WHERE o.id = ?'
    );
    $stmt->execute([$operationId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(404, ['error' => 'Not found']);
    }
    $mode = reference_mode($pdo);
    $row['id'] = (int) $row['id'];
    $row['project_id'] = (int) $row['project_id'];
    $row['part_id'] = (int) $row['part_id'];
    $row['machine_id'] = (int) $row['machine_id'];
    $row['phase_id'] = (int) $row['phase_id'];
    $row['is_set'] = (int) $row['is_set'];
    if ((int) $row['is_set'] === 1) {
        $members = $pdo->prepare(
            'SELECT osm.part_id, osm.quantity_per_set, pd.sap_number, pd.alias, pd.free_text, pt.designation
             FROM operation_set_members osm
             JOIN parts pt ON pt.id = osm.part_id
             LEFT JOIN part_designations pd ON pd.id = pt.designation_id
             WHERE osm.operation_id = ?
             ORDER BY osm.part_id'
        );
        $members->execute([$operationId]);
        $set = [];
        foreach ($members as $member) {
            $set[] = [
                'part_id' => (int) $member['part_id'],
                'quantity_per_set' => (int) $member['quantity_per_set'],
                'label' => detail_label($member, $mode),
            ];
        }
        $row['set_members'] = $set;
        $row['part_designation'] = 'Set: ' . implode(' + ', array_map(fn(array $member): string => $member['label'], $set));
    } else {
        $row['part_designation'] = detail_label([
            'sap_number' => $row['detail_sap'],
            'alias' => $row['detail_alias'],
            'free_text' => $row['detail_free'],
            'designation' => $row['detail_designation'],
            'id' => $row['part_id'],
        ], $mode);
    }
    unset($row['detail_sap'], $row['detail_alias'], $row['detail_free'], $row['detail_designation']);
    return $row;
}

function write_operation_fields(PDO $pdo, int $projectId, array $body, ?array $existing): array
{
    $isSet = array_key_exists('is_set', $body) ? ($body['is_set'] ? 1 : 0) : (int) ($existing['is_set'] ?? 0);
    $setIds = write_positive_ids($body['set_part_ids'] ?? null);
    $partId = array_key_exists('part_id', $body) ? (int) $body['part_id'] : (int) ($existing['part_id'] ?? 0);
    if ($isSet === 1 && $setIds === [] && $existing) {
        $members = $pdo->prepare('SELECT part_id FROM operation_set_members WHERE operation_id = ? ORDER BY part_id');
        $members->execute([(int) $existing['id']]);
        foreach ($members as $member) {
            $setIds[] = (int) $member['part_id'];
        }
    }
    if ($isSet === 1) {
        if (count($setIds) < 2) {
            json_response(400, ['error' => 'Set musi zawierać co najmniej 2 detale.']);
        }
        if ($partId <= 0) {
            json_response(400, ['error' => 'Dla setu wybierz detal źródłowy wolumenu.']);
        }
        if (!in_array($partId, $setIds, true)) {
            json_response(400, ['error' => 'Detal źródłowy wolumenu musi należeć do setu.']);
        }
    } elseif ($partId <= 0) {
        json_response(400, ['error' => 'Wybierz detal.']);
    }
    $machineId = array_key_exists('machine_id', $body) ? (int) $body['machine_id'] : (int) ($existing['machine_id'] ?? 0);
    if ($machineId <= 0) {
        json_response(400, ['error' => 'Wybierz maszynę z listy — pole „Maszyna” jest wymagane.']);
    }
    $machine = $pdo->prepare('SELECT id FROM machines WHERE id = ?');
    $machine->execute([$machineId]);
    if (!$machine->fetch()) {
        json_response(400, ['error' => 'Wybrana maszyna nie istnieje w bazie. Wybierz maszynę z listy.']);
    }
    $phaseId = array_key_exists('phase_id', $body) ? (int) $body['phase_id'] : (int) ($existing['phase_id'] ?? 0);
    $phase = $pdo->prepare('SELECT id FROM process_phases WHERE id = ?');
    $phase->execute([$phaseId]);
    if (!$phase->fetch()) {
        json_response(400, ['error' => 'Wybierz fazę procesu.']);
    }
    $owned = array_unique($isSet === 1 ? $setIds : [$partId]);
    $marks = implode(',', array_fill(0, count($owned), '?'));
    $check = $pdo->prepare("SELECT COUNT(*) FROM parts WHERE project_id = ? AND id IN ({$marks})");
    $check->execute([$projectId, ...$owned]);
    if ((int) $check->fetchColumn() !== count($owned)) {
        json_response(400, ['error' => 'Detal nie należy do tego projektu.']);
    }
    $altCycle = write_optional_float($body, 'alt_cycle_time_seconds', $existing['alt_cycle_time_seconds'] ?? null);
    $hasAlt = $altCycle !== null && $altCycle > 0;
    $useAlt = array_key_exists('use_alternative_in_calculator', $body)
        ? ($body['use_alternative_in_calculator'] ? 1 : 0)
        : (int) ($existing['use_alternative_in_calculator'] ?? 0);
    if (!$hasAlt) {
        $useAlt = 0;
        $altCycle = null;
    }
    $capacity = array_key_exists('capacity_percent', $body)
        ? (float) $body['capacity_percent']
        : (float) ($existing['capacity_percent'] ?? 100);
    if ($existing === null) {
        $capacity = write_rebalance_capacity($pdo, $machineId, $projectId, $capacity > 0 ? $capacity : 100);
    }
    return [
        'part_id' => $partId,
        'phase_id' => $phaseId,
        'machine_id' => $machineId,
        'cycle_time_seconds' => array_key_exists('cycle_time_seconds', $body) ? (float) $body['cycle_time_seconds'] : (float) ($existing['cycle_time_seconds'] ?? 0),
        'volume_value' => array_key_exists('volume_value', $body) ? (float) $body['volume_value'] : (float) ($existing['volume_value'] ?? 0),
        'volume_unit' => array_key_exists('volume_unit', $body) ? write_volume_unit_value($body['volume_unit']) : (string) ($existing['volume_unit'] ?? 'annual'),
        'nests_count' => max(1, (int) (array_key_exists('nests_count', $body) ? $body['nests_count'] : ($existing['nests_count'] ?? 1))),
        'oee_override' => write_optional_float($body, 'oee_override', $existing['oee_override'] ?? null),
        'capacity_percent' => $capacity,
        'opf' => !empty($body['opf']) ? 1 : 0,
        'sap' => array_key_exists('sap', $body) ? $body['sap'] : ($existing['sap'] ?? null),
        'description' => array_key_exists('description', $body) ? $body['description'] : ($existing['description'] ?? null),
        'is_set' => $isSet,
        'alt_cycle_time_seconds' => $altCycle,
        'alt_nests_count' => write_optional_float($body, 'alt_nests_count', $existing['alt_nests_count'] ?? null),
        'alt_oee_override' => write_optional_float($body, 'alt_oee_override', $existing['alt_oee_override'] ?? null),
        'alt_comment' => array_key_exists('alt_comment', $body)
            ? (trim((string) ($body['alt_comment'] ?? '')) !== '' ? trim((string) $body['alt_comment']) : null)
            : ($existing['alt_comment'] ?? null),
        'use_alternative_in_calculator' => $useAlt,
        'set_part_ids' => $isSet === 1 ? $setIds : [],
        'volume_explicit' => array_key_exists('volume_value', $body) || array_key_exists('volume_unit', $body),
    ];
}

function write_save_operation(PDO $pdo, int $projectId, ?int $operationId): void
{
    $auth = write_project_actor($pdo, $projectId);
    $body = request_json();
    $existing = null;
    if ($operationId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM operations WHERE id = ? AND project_id = ?');
        $stmt->execute([$operationId, $projectId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response(404, ['error' => 'Not found']);
        }
    }
    $pdo->beginTransaction();
    try {
    $fields = write_operation_fields($pdo, $projectId, $body, $existing);
    $setIds = $fields['set_part_ids'];
    $volumeExplicit = $fields['volume_explicit'];
    unset($fields['set_part_ids'], $fields['volume_explicit']);
        if ($operationId === null) {
            $columns = array_keys($fields);
            $pdo->prepare(
                'INSERT INTO operations (project_id, ' . implode(', ', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns) + 1, '?')) . ')'
            )->execute([$projectId, ...array_values($fields)]);
            $operationId = (int) $pdo->lastInsertId();
            $status = 201;
            $note = 'Automatyczna zmiana: dodano operację #' . $operationId . '.';
        } else {
            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = $column . ' = ?';
            }
            $pdo->prepare('UPDATE operations SET ' . implode(', ', $assignments) . ' WHERE id = ?')
                ->execute([...array_values($fields), $operationId]);
            $status = 200;
            $note = 'Automatyczna zmiana: zaktualizowano operację #' . $operationId . '.';
        }
        $pdo->prepare('DELETE FROM operation_set_members WHERE operation_id = ?')->execute([$operationId]);
        if ($setIds !== []) {
            $member = $pdo->prepare('INSERT INTO operation_set_members (operation_id, part_id, quantity_per_set) VALUES (?, ?, 1)');
            foreach ($setIds as $setPartId) {
                $member->execute([$operationId, $setPartId]);
            }
        }
        if ($volumeExplicit && $status === 200) {
            $pdo->prepare('DELETE FROM operation_volume_by_year WHERE operation_id = ?')->execute([$operationId]);
        }
        write_cleanup_orphan_parts($pdo, $projectId);
        write_note($pdo, $projectId, $note, user_login_label($auth['user']), (int) $fields['part_id'], (int) $fields['machine_id'], $operationId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response($status, write_operation_row($pdo, $operationId));
}

function write_cleanup_orphan_parts(PDO $pdo, int $projectId): void
{
    $pdo->prepare(
        'DELETE FROM parts
         WHERE project_id = ?
           AND id NOT IN (
             SELECT o.part_id FROM operations o WHERE o.project_id = ?
             UNION
             SELECT osm.part_id FROM operation_set_members osm
             JOIN operations o2 ON o2.id = osm.operation_id
             WHERE o2.project_id = ?
           )'
    )->execute([$projectId, $projectId, $projectId]);
}

function write_merge_child_volumes(PDO $pdo, int $parentId, int $childId): ?string
{
    $rows = $pdo->prepare(
        'SELECT year, volume_value, volume_unit, effective_from_month FROM operation_volume_by_year WHERE operation_id = ?'
    );
    $rows->execute([$childId]);
    $insert = $pdo->prepare(
        "INSERT INTO operation_volume_by_year (operation_id, year, volume_value, volume_unit, source) VALUES (?, ?, ?, ?, 'allocation')"
    );
    $update = $pdo->prepare('UPDATE operation_volume_by_year SET volume_value = volume_value + ? WHERE operation_id = ? AND year = ?');
    foreach ($rows as $row) {
        if ($row['effective_from_month'] !== null && $row['effective_from_month'] !== '') {
            return 'Wolumen tej operacji jest podzielony w trakcie miesiąca. Usunięcie zostaw do kroku alokacji.';
        }
        $value = (float) $row['volume_value'];
        if ($value <= 1e-9) {
            continue;
        }
        $parent = $pdo->prepare('SELECT volume_unit FROM operation_volume_by_year WHERE operation_id = ? AND year = ?');
        $parent->execute([$parentId, $row['year']]);
        $parentRow = $parent->fetch();
        if (!$parentRow) {
            $insert->execute([$parentId, (int) $row['year'], $value, $row['volume_unit']]);
            continue;
        }
        if ((string) $parentRow['volume_unit'] !== (string) $row['volume_unit']) {
            return 'Wolumen tej operacji ma inną jednostkę niż operacja źródłowa. Usunięcie zostaw do kroku alokacji.';
        }
        $update->execute([$value, $parentId, (int) $row['year']]);
    }
    return null;
}

function write_clear_parent_allocation(PDO $pdo, int $parentId): void
{
    $parent = $pdo->prepare('SELECT split_from_operation_id FROM operations WHERE id = ?');
    $parent->execute([$parentId]);
    $row = $parent->fetch();
    if (!$row || $row['split_from_operation_id'] !== null) {
        return;
    }
    $children = $pdo->prepare('SELECT 1 FROM operations WHERE split_from_operation_id = ? LIMIT 1');
    $children->execute([$parentId]);
    if ($children->fetch()) {
        return;
    }
    $pdo->prepare("DELETE FROM operation_volume_by_year WHERE operation_id = ? AND COALESCE(source, 'manual') = 'allocation'")
        ->execute([$parentId]);
}

function write_delete_operation(PDO $pdo, int $projectId, int $operationId): void
{
    $auth = write_delete_actor($pdo);
    $stmt = $pdo->prepare('SELECT id, split_from_operation_id, machine_id, part_id FROM operations WHERE id = ? AND project_id = ?');
    $stmt->execute([$operationId, $projectId]);
    $operation = $stmt->fetch();
    if (!$operation) {
        json_response(404, ['error' => 'Not found']);
    }
    $children = $pdo->prepare('SELECT 1 FROM operations WHERE split_from_operation_id = ? LIMIT 1');
    $children->execute([$operationId]);
    if ($children->fetch()) {
        json_response(400, ['error' => 'Operacja ma operacje potomne — najpierw usuń potomki lub operacje pośrednie z alokacji wolumenu.']);
    }
    $parentId = $operation['split_from_operation_id'] !== null ? (int) $operation['split_from_operation_id'] : null;
    $pdo->beginTransaction();
    try {
        if ($parentId !== null) {
            $parent = $pdo->prepare('SELECT id FROM operations WHERE id = ? AND project_id = ?');
            $parent->execute([$parentId, $projectId]);
            if ($parent->fetch()) {
                $problem = write_merge_child_volumes($pdo, $parentId, $operationId);
                if ($problem !== null) {
                    $pdo->rollBack();
                    json_response(400, ['error' => $problem]);
                }
            }
        }
        write_note(
            $pdo,
            $projectId,
            'Automatyczna zmiana: usunięto operację #' . $operationId . '.',
            user_login_label($auth['user']),
            $operation['part_id'] !== null ? (int) $operation['part_id'] : null,
            $operation['machine_id'] !== null ? (int) $operation['machine_id'] : null,
            $operationId
        );
        $pdo->prepare('DELETE FROM operations WHERE id = ? AND project_id = ?')->execute([$operationId, $projectId]);
        $pdo->prepare('DELETE FROM operation_volume_by_year WHERE operation_id = ?')->execute([$operationId]);
        write_cleanup_orphan_parts($pdo, $projectId);
        if ($parentId !== null) {
            write_clear_parent_allocation($pdo, $parentId);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => 'Failed']);
    }
    json_response(200, ['ok' => true, 'message' => null]);
}

function handle_writes(string $method, string $path): bool
{
    if ($method !== 'POST' && $method !== 'PUT' && $method !== 'DELETE') {
        return false;
    }
    $machineId = 0;
    $projectId = 0;
    $partId = 0;
    $operationId = 0;
    $designationId = 0;
    $route = '';
    if ($method === 'POST' && $path === '/machines') {
        $route = 'machine-create';
    } elseif ($method === 'PUT' && preg_match('#^/machines/(\d+)$#', $path, $match) === 1) {
        $route = 'machine-update';
        $machineId = (int) $match[1];
    } elseif ($method === 'POST' && $path === '/projects') {
        $route = 'project-create';
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)/volumes-contract$#', $path, $match) === 1) {
        $route = 'project-volumes-contract';
        $projectId = (int) $match[1];
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)/volumes$#', $path, $match) === 1) {
        $route = 'project-volumes';
        $projectId = (int) $match[1];
    } elseif ($method === 'POST' && preg_match('#^/projects/(\d+)/volumes-mirror$#', $path, $match) === 1) {
        $route = 'project-volumes-mirror';
        $projectId = (int) $match[1];
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)/parts/(\d+)/volumes-contract$#', $path, $match) === 1) {
        $route = 'part-volumes-contract';
        $projectId = (int) $match[1];
        $partId = (int) $match[2];
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)/parts/(\d+)/volumes$#', $path, $match) === 1) {
        $route = 'part-volumes';
        $projectId = (int) $match[1];
        $partId = (int) $match[2];
    } elseif ($method === 'POST' && preg_match('#^/projects/(\d+)/parts/(\d+)/volumes-mirror$#', $path, $match) === 1) {
        $route = 'part-volumes-mirror';
        $projectId = (int) $match[1];
        $partId = (int) $match[2];
    } elseif ($method === 'POST' && preg_match('#^/projects/(\d+)/parts/(\d+)/volume-year$#', $path, $match) === 1) {
        $route = 'part-volume-year';
        $projectId = (int) $match[1];
        $partId = (int) $match[2];
    } elseif ($method === 'DELETE' && preg_match('#^/projects/(\d+)/parts/(\d+)$#', $path, $match) === 1) {
        $route = 'part-delete';
        $projectId = (int) $match[1];
        $partId = (int) $match[2];
    } elseif ($method === 'POST' && preg_match('#^/projects/(\d+)/operations$#', $path, $match) === 1) {
        $route = 'operation-create';
        $projectId = (int) $match[1];
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)/operations/(\d+)$#', $path, $match) === 1) {
        $route = 'operation-update';
        $projectId = (int) $match[1];
        $operationId = (int) $match[2];
    } elseif ($method === 'DELETE' && preg_match('#^/projects/(\d+)/operations/(\d+)$#', $path, $match) === 1) {
        $route = 'operation-delete';
        $projectId = (int) $match[1];
        $operationId = (int) $match[2];
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)$#', $path, $match) === 1) {
        $route = 'project-update';
        $projectId = (int) $match[1];
    } elseif ($method === 'POST' && preg_match('#^/projects/(\d+)/parts$#', $path, $match) === 1) {
        $route = 'part-create';
        $projectId = (int) $match[1];
    } elseif ($method === 'PUT' && preg_match('#^/projects/(\d+)/parts/(\d+)$#', $path, $match) === 1) {
        $route = 'part-update';
        $projectId = (int) $match[1];
        $partId = (int) $match[2];
    } elseif ($method === 'POST' && $path === '/settings/designations') {
        $route = 'designation-create';
    } elseif ($method === 'PUT' && preg_match('#^/settings/designations/(\d+)$#', $path, $match) === 1) {
        $route = 'designation-update';
        $designationId = (int) $match[1];
    }
    if ($route === '') {
        return false;
    }
    $pdo = open_database();
    bootstrap_admin($pdo);
    if ($route === 'machine-create') {
        write_machine($pdo, null);
    }
    if ($route === 'machine-update') {
        write_machine($pdo, $machineId);
    }
    if ($route === 'project-create') {
        write_project($pdo, null);
    }
    if ($route === 'project-update') {
        write_project($pdo, $projectId);
    }
    if ($route === 'part-create') {
        write_part($pdo, $projectId, null);
    }
    if ($route === 'part-update') {
        write_part($pdo, $projectId, $partId);
    }
    if ($route === 'project-volumes') {
        write_replace_project_volumes($pdo, $projectId, 'project_volumes', 'Automatyczna zmiana: zaktualizowano wolumeny projektu');
    }
    if ($route === 'project-volumes-contract') {
        write_replace_project_volumes($pdo, $projectId, 'project_volumes_contract', 'Automatyczna zmiana: zaktualizowano wolumeny kontraktowe projektu');
    }
    if ($route === 'project-volumes-mirror') {
        write_mirror_project_volumes($pdo, $projectId);
    }
    if ($route === 'part-volumes') {
        write_replace_part_volumes($pdo, $projectId, $partId, 'part_volume_by_year', 'Automatyczna zmiana: zaktualizowano wolumeny detalu');
    }
    if ($route === 'part-volumes-contract') {
        write_replace_part_volumes($pdo, $projectId, $partId, 'part_volume_contract_by_year', 'Automatyczna zmiana: zaktualizowano wolumeny kontraktowe detalu');
    }
    if ($route === 'part-volumes-mirror') {
        write_mirror_part_volumes($pdo, $projectId, $partId);
    }
    if ($route === 'part-volume-year') {
        write_add_volume_year($pdo, $projectId, $partId);
    }
    if ($route === 'part-delete') {
        write_delete_part($pdo, $projectId, $partId);
    }
    if ($route === 'operation-create') {
        write_save_operation($pdo, $projectId, null);
    }
    if ($route === 'operation-update') {
        write_save_operation($pdo, $projectId, $operationId);
    }
    if ($route === 'operation-delete') {
        write_delete_operation($pdo, $projectId, $operationId);
    }
    if ($route === 'designation-create') {
        write_designation($pdo, null);
    }
    write_designation($pdo, $designationId);
    return true;
}
