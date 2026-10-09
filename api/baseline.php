<?php
declare(strict_types=1);

function baseline_positive(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    return $number > 0 ? $number : null;
}

function baseline_machine_id(array $machine): int
{
    return (int) ($machine['machine_id'] ?? $machine['id'] ?? 0);
}

function baseline_blank_mass_kg(float $grammage, float $widthMm, float $lengthMm): ?float
{
    if ($grammage < 0 || $widthMm < 0 || $lengthMm < 0) {
        return null;
    }
    return round($grammage * ($widthMm / 1000) * ($lengthMm / 1000), 4);
}

function baseline_orient(array $machine, float $dimensionA, float $dimensionB): array
{
    $maxWidthRaw = baseline_positive($machine['baseline_max_blank_width_mm'] ?? null) ?? 1400.0;
    $maxWidth = strtolower((string) ($machine['baseline_max_blank_width_unit'] ?? 'mm')) === 'cm'
        ? $maxWidthRaw * 10
        : $maxWidthRaw;
    $minLength = baseline_positive($machine['baseline_min_length_mm'] ?? null) ?? 650.0;
    if ($dimensionA > $maxWidth && $dimensionB > $maxWidth) {
        return $dimensionA <= $dimensionB
            ? ['widthMm' => $dimensionA, 'lengthMm' => $dimensionB]
            : ['widthMm' => $dimensionB, 'lengthMm' => $dimensionA];
    }
    $candidates = [];
    foreach ([[$dimensionA, $dimensionB], [$dimensionB, $dimensionA]] as [$width, $length]) {
        if ($width <= $maxWidth && $length >= $minLength) {
            $candidates[] = ['widthMm' => $width, 'lengthMm' => $length];
        }
    }
    if ($candidates !== []) {
        usort($candidates, fn(array $a, array $b): int => $b['widthMm'] <=> $a['widthMm']);
        return $candidates[0];
    }
    return $dimensionA <= $maxWidth
        ? ['widthMm' => $dimensionA, 'lengthMm' => $dimensionB]
        : ['widthMm' => $dimensionB, 'lengthMm' => $dimensionA];
}

function baseline_blanks_across(array $machine, float $blankWidthMm): int
{
    $maxAcross = max(1, (int) floor(baseline_positive($machine['baseline_max_blanks_across'] ?? null) ?? 1));
    $rawMaxWidth = baseline_positive($machine['baseline_max_blank_width_mm'] ?? null);
    $maxWidth = $rawMaxWidth === null
        ? null
        : (strtolower((string) ($machine['baseline_max_blank_width_unit'] ?? 'mm')) === 'cm' ? $rawMaxWidth * 10 : $rawMaxWidth);
    if ($maxAcross <= 1 || $maxWidth === null || $blankWidthMm <= 0) {
        return 1;
    }
    return max(1, min($maxAcross, (int) floor($maxWidth / $blankWidthMm)));
}

function baseline_hourly_capacity(array $machine, array $material): ?array
{
    $grammage = baseline_positive($material['grammage_kg_m2'] ?? null);
    $dimensionA = baseline_positive($material['width_mm'] ?? null);
    $dimensionB = baseline_positive($material['length_mm'] ?? null);
    if ($grammage === null || $dimensionA === null || $dimensionB === null) {
        return null;
    }
    $oriented = baseline_orient($machine, $dimensionA, $dimensionB);
    $width = $oriented['widthMm'];
    $length = $oriented['lengthMm'];
    $rawThroughput = baseline_positive($machine['baseline_max_throughput_kg_h'] ?? null);
    $throughput = $rawThroughput === null
        ? null
        : (strtolower((string) ($machine['baseline_max_throughput_unit'] ?? 'kg/h')) === 't/h' ? $rawThroughput * 1000 : $rawThroughput);
    $rawSpeed = baseline_positive($machine['baseline_max_speed_m_min'] ?? null);
    $speed = $rawSpeed === null
        ? null
        : (strtolower((string) ($machine['baseline_max_speed_unit'] ?? 'm/min')) === 'm/s' ? $rawSpeed * 60 : $rawSpeed);
    if ($throughput === null && $speed === null) {
        return null;
    }
    $mass = baseline_blank_mass_kg($grammage, $width, $length);
    if ($mass === null || $mass <= 0) {
        return null;
    }
    $across = baseline_blanks_across($machine, $width);
    $massCapacity = $throughput === null ? null : $throughput / $mass;
    $speedCapacity = $speed === null ? null : (($speed * 60) / ($length / 1000)) * $across;
    $actual = min($massCapacity ?? INF, $speedCapacity ?? INF);
    if (!is_finite($actual) || $actual <= 0) {
        return null;
    }
    return [
        'massKg' => $mass,
        'blanksAcross' => $across,
        'webWidthMm' => $width * $across,
        'webMassKg' => $mass * $across,
        'massCapacityPerHour' => $massCapacity,
        'speedCapacityPerHour' => $speedCapacity,
        'actualCapacityPerHour' => $actual,
        'widthMm' => $width,
        'lengthMm' => $length,
    ];
}

function baseline_share(mixed $value): float
{
    if (!is_numeric($value)) {
        return 1.0;
    }
    return min(100.0, max(0.0, (float) $value)) / 100;
}

function baseline_seconds_per_material(array $machine, mixed $designationId, float $weeklyPieces, array $materials): array
{
    if ((int) ($machine['is_baseline'] ?? 0) !== 1 || $weeklyPieces <= 0) {
        return [];
    }
    $designation = (int) $designationId;
    if ($designation <= 0) {
        return [];
    }
    $rows = [];
    foreach ($materials[baseline_machine_id($machine) . '|' . $designation] ?? [] as $material) {
        $hourly = baseline_hourly_capacity($machine, $material);
        if ($hourly === null) {
            continue;
        }
        $consumption = is_numeric($material['consumption_per_detail'] ?? null) && (float) $material['consumption_per_detail'] >= 0
            ? (float) $material['consumption_per_detail']
            : 1.0;
        $seconds = ($weeklyPieces * $consumption * baseline_share($material['production_share_percent'] ?? null) / $hourly['actualCapacityPerHour']) * 3600;
        $rows[] = baseline_material_row($material, $hourly, $seconds);
    }
    return $rows;
}

function baseline_material_row(array $material, array $hourly, float $seconds): array
{
    return [
        'material_id' => (int) $material['material_id'],
        'alias' => $material['alias'] ?? null,
        'description' => $material['description'] ?? null,
        'sap_number' => $material['sap_number'] ?? null,
        'width_mm' => $hourly['widthMm'],
        'length_mm' => $hourly['lengthMm'],
        'grammage_kg_m2' => is_numeric($material['grammage_kg_m2'] ?? null) ? (float) $material['grammage_kg_m2'] : null,
        'required_seconds' => $seconds,
    ];
}

function baseline_availability_seconds(array $machine, float $oee): float
{
    $hours = baseline_positive($machine['baseline_available_hours_per_week'] ?? null) ?? 120.0;
    return $hours * 3600 * max(0.0, $oee);
}

function baseline_volume_settings(array $settings, array $machine): array
{
    if ((int) ($machine['is_baseline'] ?? 0) !== 1) {
        return $settings;
    }
    $raw = $machine['baseline_weeks_per_year'] ?? null;
    $settings['working_weeks_per_year'] = is_numeric($raw) ? max(1.0, min(53.0, (float) $raw)) : 52.0;
    return $settings;
}

function baseline_bundle(PDO $pdo): array
{
    $materials = [];
    foreach ($pdo->query(
        'SELECT bm.id AS material_id, bm.machine_id, bmp.designation_id,
                bm.alias, bm.sap_number, bm.description,
                bm.grammage_kg_m2, bm.width_mm, bm.length_mm,
                bmp.consumption_per_detail, bm.production_share_percent
         FROM machine_baseline_material_parts bmp
         JOIN machine_baseline_materials bm ON bm.id = bmp.material_id
         WHERE bm.include_in_capacity = 1'
    ) as $row) {
        $materials[(int) $row['machine_id'] . '|' . (int) $row['designation_id']][] = $row;
    }
    $external = [];
    foreach ($pdo->query(
        'SELECT bm.id AS material_id, bm.machine_id, bm.alias, bm.sap_number, bm.description,
                bm.grammage_kg_m2, bm.width_mm, bm.length_mm, bm.production_share_percent
         FROM machine_baseline_materials bm
         WHERE bm.use_external_volume = 1 AND bm.include_in_capacity = 1'
    ) as $row) {
        $external[(int) $row['machine_id']][] = $row;
    }
    $volumes = [];
    foreach ($pdo->query(
        'SELECT v.material_id, v.year, v.weekly_volume
         FROM machine_baseline_material_volumes v
         JOIN machine_baseline_materials bm ON bm.id = v.material_id
         WHERE bm.use_external_volume = 1 AND bm.include_in_capacity = 1'
    ) as $row) {
        $volumes[(int) $row['material_id']][(int) $row['year']] = (float) $row['weekly_volume'];
    }
    $byDesignation = [];
    foreach ($pdo->query(
        'SELECT DISTINCT bm.machine_id, bmp.designation_id
         FROM machine_baseline_material_parts bmp
         JOIN machine_baseline_materials bm ON bm.id = bmp.material_id
         JOIN machines m ON m.id = bm.machine_id
         WHERE m.is_baseline = 1 AND bm.include_in_capacity = 1'
    ) as $row) {
        $byDesignation[(int) $row['designation_id']][] = (int) $row['machine_id'];
    }
    $ops = [];
    if ($byDesignation !== []) {
        foreach ($pdo->query(
            'SELECT pt.id AS part_id, pt.project_id, pt.designation_id, pt.designation,
                    p.sop, p.eop, p.status AS project_status, p.client AS project_client, p.name AS project_name,
                    pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text
             FROM parts pt
             JOIN projects p ON p.id = pt.project_id
             LEFT JOIN part_designations pd ON pd.id = pt.designation_id
             WHERE p.status = \'active\' AND pt.designation_id IS NOT NULL'
        ) as $part) {
            $designationId = (int) $part['designation_id'];
            if (!isset($byDesignation[$designationId])) {
                continue;
            }
            foreach (array_unique($byDesignation[$designationId]) as $machineId) {
                $partId = (int) $part['part_id'];
                $ops[$machineId][] = [
                    'operation_id' => -($machineId * 1000000000 + $partId),
                    'project_id' => (int) $part['project_id'],
                    'part_id' => $partId,
                    'machine_id' => $machineId,
                    'cycle_time_seconds' => 0,
                    'volume_value' => 0,
                    'volume_unit' => 'annual',
                    'nests_count' => 1,
                    'alt_cycle_time_seconds' => null,
                    'alt_nests_count' => null,
                    'use_alternative_in_calculator' => 0,
                    'split_from_operation_id' => null,
                    'sop' => $part['sop'],
                    'eop' => $part['eop'],
                    'project_status' => $part['project_status'],
                    'project_client' => $part['project_client'],
                    'project_name' => $part['project_name'],
                    'detail_sap_number' => $part['detail_sap_number'],
                    'detail_alias' => $part['detail_alias'],
                    'detail_free_text' => $part['detail_free_text'],
                    'detail_designation' => $part['designation'],
                    'detail_designation_id' => $designationId,
                ];
            }
        }
    }
    return ['materials' => $materials, 'external' => $external, 'volumes' => $volumes, 'ops' => $ops];
}

function baseline_external_rows(array $machine, int $year, array $bundle): array
{
    if ((int) ($machine['is_baseline'] ?? 0) !== 1) {
        return [];
    }
    $rows = [];
    foreach ($bundle['external'][baseline_machine_id($machine)] ?? [] as $material) {
        $weekly = (float) ($bundle['volumes'][(int) $material['material_id']][$year] ?? 0);
        if ($weekly <= 0) {
            continue;
        }
        $hourly = baseline_hourly_capacity($machine, $material);
        if ($hourly === null) {
            continue;
        }
        $seconds = ($weekly * baseline_share($material['production_share_percent'] ?? null) / $hourly['actualCapacityPerHour']) * 3600;
        $row = baseline_material_row($material, $hourly, $seconds);
        $row['external'] = true;
        $rows[] = $row;
    }
    return $rows;
}
