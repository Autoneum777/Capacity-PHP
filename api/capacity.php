<?php
declare(strict_types=1);

require_once __DIR__ . '/baseline.php';

function calc_parse_sop_eop_numeric(float $value): ?array
{
    if ($value <= 0) {
        return null;
    }
    $month = (int) floor($value);
    if ($month < 1 || $month > 12) {
        return null;
    }
    $year = (int) round(($value - $month) * 10000 + 1e-4);
    if ($year < 1900 || $year > 2200) {
        return null;
    }
    return ['month' => $month, 'year' => $year];
}

function calc_parse_sop_eop(mixed $value): ?array
{
    if (is_int($value) || is_float($value)) {
        return calc_parse_sop_eop_numeric((float) $value);
    }
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})$/', $text, $match) === 1) {
        $year = (int) $match[1];
        $month = (int) $match[2];
        if ($month >= 1 && $month <= 12 && $year >= 1900 && $year <= 2200) {
            return ['month' => $month, 'year' => $year];
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
            return ['month' => $month, 'year' => $year];
        }
    }
    if (preg_match('/^\d+\.\d+$/', $text) === 1) {
        return calc_parse_sop_eop_numeric((float) $text);
    }
    return null;
}

function calc_month_in_production(mixed $sop, mixed $eop, int $year, int $month): bool
{
    if ($month < 1 || $month > 12) {
        return false;
    }
    $sopP = calc_parse_sop_eop($sop);
    $eopP = calc_parse_sop_eop($eop);
    if ($sopP === null || $eopP === null) {
        return true;
    }
    if ($year < $sopP['year'] || $year > $eopP['year']) {
        return false;
    }
    if ($year === $sopP['year'] && $month < $sopP['month']) {
        return false;
    }
    if ($year === $eopP['year'] && $month > $eopP['month']) {
        return false;
    }
    return true;
}

function calc_production_months(mixed $sop, mixed $eop, int $year): int
{
    $sopP = calc_parse_sop_eop($sop);
    $eopP = calc_parse_sop_eop($eop);
    if ($sopP === null || $eopP === null || $year < $sopP['year'] || $year > $eopP['year']) {
        return 0;
    }
    if ($year === $sopP['year'] && $year === $eopP['year']) {
        return max(0, $eopP['month'] - $sopP['month'] + 1);
    }
    if ($year === $sopP['year']) {
        return 13 - $sopP['month'];
    }
    if ($year === $eopP['year']) {
        return $eopP['month'];
    }
    return 12;
}

function calc_week_count(int $year, int $month): int
{
    $count = 0;
    $cursor = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
    $days = (int) $cursor->format('t');
    for ($day = 0; $day < $days; $day++) {
        if ($cursor->modify('+' . $day . ' days')->format('N') === '1') {
            $count++;
        }
    }
    return max(1, $count);
}

function calc_weeks_in_year(int $year): int
{
    $total = 0;
    for ($month = 1; $month <= 12; $month++) {
        $total += calc_week_count($year, $month);
    }
    return max(1, $total);
}

function calc_weeks_before(int $year, int $fromMonth, int $fromWeek): int
{
    $month = min(12, max(1, $fromMonth));
    $week = min(calc_week_count($year, $month), max(1, $fromWeek));
    $count = 0;
    for ($m = 1; $m < $month; $m++) {
        $count += calc_week_count($year, $m);
    }
    return max(0, $count + $week - 1);
}

function calc_settings(PDO $pdo, int $year): array
{
    static $template = null;
    static $byYear = [];
    if (isset($byYear[$year])) {
        return $byYear[$year];
    }
    $stmt = $pdo->prepare(
        'SELECT working_days_year, oee_factor, shift_time_seconds, startup_shutdown_seconds, working_weeks_per_year, shifts_per_day
         FROM working_days WHERE year = ?'
    );
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) {
        $byYear[$year] = [
            'working_days_year' => (float) $row['working_days_year'],
            'oee_factor' => (float) $row['oee_factor'],
            'shift_time_seconds' => (float) $row['shift_time_seconds'],
            'startup_shutdown_seconds' => (float) $row['startup_shutdown_seconds'],
            'working_weeks_per_year' => (float) $row['working_weeks_per_year'],
            'shifts_per_day' => (float) $row['shifts_per_day'],
        ];
        return $byYear[$year];
    }
    if ($template === null) {
        $defaults = [
            'working_days_year' => 252.0,
            'oee_factor' => 0.85,
            'shift_time_seconds' => 450.0,
            'startup_shutdown_seconds' => 720.0,
            'working_weeks_per_year' => 48.0,
            'shifts_per_day' => 3.0,
        ];
        $keys = [
            'working_days_year' => 'capacity_default_working_days_year',
            'oee_factor' => 'capacity_default_oee_factor',
            'shift_time_seconds' => 'capacity_default_shift_time_seconds',
            'startup_shutdown_seconds' => 'capacity_default_startup_shutdown_seconds',
            'working_weeks_per_year' => 'capacity_default_working_weeks_per_year',
            'shifts_per_day' => 'capacity_default_shifts_per_day',
        ];
        $map = setting_map($pdo);
        foreach ($keys as $field => $key) {
            if (isset($map[$key]) && is_numeric($map[$key])) {
                $defaults[$field] = (float) $map[$key];
            }
        }
        $template = $defaults;
    }
    $byYear[$year] = $template;
    return $template;
}

function calc_volume_unit(mixed $unit): string
{
    return $unit === 'monthly' || $unit === 'weekly' ? (string) $unit : 'annual';
}

function calc_volume_to_weekly(float $value, string $unit, array $settings): float
{
    $weeks = max(1.0, (float) ($settings['working_weeks_per_year'] ?? 48));
    if ($unit === 'weekly') {
        return $value;
    }
    if ($unit === 'monthly') {
        return ($value * 12) / $weeks;
    }
    return $value / $weeks;
}

function calc_pick_period(array $row, int $year, ?int $month, ?int $week = null): array
{
    $fromRaw = $row['effective_from_month'] ?? null;
    if ($fromRaw === null || $fromRaw === '' || !is_numeric($fromRaw)) {
        return ['volume_value' => (float) ($row['volume_value'] ?? 0), 'volume_unit' => calc_volume_unit($row['volume_unit'] ?? 'annual')];
    }
    $fromMonth = min(12, max(1, (int) $fromRaw));
    $fromWeek = min(calc_week_count($year, $fromMonth), max(1, (int) ($row['effective_from_week'] ?? 1)));
    $after = (float) ($row['volume_value'] ?? 0);
    $before = isset($row['volume_value_before']) && is_numeric($row['volume_value_before']) ? (float) $row['volume_value_before'] : 0.0;
    $unit = calc_volume_unit($row['volume_unit'] ?? 'annual');
    if ($month === null) {
        $total = calc_weeks_in_year($year);
        $beforeWeeks = calc_weeks_before($year, $fromMonth, $fromWeek);
        $afterWeeks = max(0, $total - $beforeWeeks);
        return ['volume_value' => ($before * $beforeWeeks + $after * $afterWeeks) / $total, 'volume_unit' => $unit];
    }
    if ($week !== null) {
        if ($month < $fromMonth || ($month === $fromMonth && $week < $fromWeek)) {
            return ['volume_value' => $before, 'volume_unit' => $unit];
        }
        return ['volume_value' => $after, 'volume_unit' => $unit];
    }
    if ($month < $fromMonth) {
        return ['volume_value' => $before, 'volume_unit' => $unit];
    }
    if ($month > $fromMonth) {
        return ['volume_value' => $after, 'volume_unit' => $unit];
    }
    $weekCount = calc_week_count($year, $month);
    $beforeInMonth = max(0, $fromWeek - 1);
    $afterInMonth = max(0, $weekCount - $beforeInMonth);
    return [
        'volume_value' => ($before * $beforeInMonth + $after * $afterInMonth) / max(1, $weekCount),
        'volume_unit' => $unit,
    ];
}

function calc_origin(mixed $raw): string
{
    return trim((string) ($raw ?? '')) === 'default_all_years' ? 'default_all_years' : 'manual_year';
}

function calc_effective_volume(array $ctx, int $projectId, int $partId, int $year, bool $contract): ?array
{
    $part = $ctx['parts'][$partId] ?? null;
    $project = $ctx['projects'][$projectId] ?? null;
    $eopYear = $project ? (calc_parse_sop_eop($project['eop'])['year'] ?? null) : null;
    $afterEop = $eopYear !== null && $year > $eopYear;
    if ($contract) {
        $pvc = $ctx['projectContract'][$projectId][$year] ?? null;
        $pv = $ctx['projectVolumes'][$projectId][$year] ?? null;
        $flag = $pvc ?? $pv;
        $countAfter = $afterEop && $flag && (int) ($flag['include_in_calculator_after_eop'] ?? 0) === 1;
        $mode = $part['contract_volume_mode'] ?? 'project';
        $partVol = $ctx['partContract'][$partId][$year] ?? null;
        if ($mode === 'override') {
            if ($partVol) {
                return [
                    'volume_value' => (float) $partVol['volume_value'],
                    'volume_unit' => calc_volume_unit($partVol['volume_unit']),
                    'count_after_eop' => $countAfter,
                    'volume_origin' => calc_origin($partVol['volume_origin'] ?? null),
                ];
            }
            if ($part && $part['contract_default_volume_value'] !== null && $part['contract_default_volume_unit']) {
                return [
                    'volume_value' => (float) $part['contract_default_volume_value'],
                    'volume_unit' => calc_volume_unit($part['contract_default_volume_unit']),
                    'count_after_eop' => $countAfter,
                    'volume_origin' => 'default_all_years',
                ];
            }
            return ['volume_value' => 0.0, 'volume_unit' => 'annual', 'count_after_eop' => $countAfter, 'volume_origin' => 'manual_year'];
        }
        if ($mode === 'project' && $pvc && (float) $pvc['volume_value'] > 0) {
            return [
                'volume_value' => (float) $pvc['volume_value'],
                'volume_unit' => calc_volume_unit($pvc['volume_unit']),
                'count_after_eop' => $countAfter,
                'volume_origin' => calc_origin($pvc['volume_origin'] ?? null),
            ];
        }
        if ($mode === 'share' && $pvc && (float) $pvc['volume_value'] > 0) {
            $sharePct = $ctx['contractShare'][$partId][$year] ?? $part['contract_volume_share_percent'] ?? null;
            if ($sharePct !== null) {
                $share = max(0.0, min(100.0, (float) $sharePct)) / 100;
                return [
                    'volume_value' => (float) $pvc['volume_value'] * $share,
                    'volume_unit' => calc_volume_unit($pvc['volume_unit']),
                    'count_after_eop' => $countAfter,
                    'volume_origin' => calc_origin($pvc['volume_origin'] ?? null),
                ];
            }
        }
        return null;
    }

    $pv = $ctx['projectVolumes'][$projectId][$year] ?? null;
    $countAfter = $afterEop && $pv && (int) ($pv['include_in_calculator_after_eop'] ?? 0) === 1;
    $mode = $part['volume_mode'] ?? 'project';
    $partVol = $ctx['partVolumes'][$partId][$year] ?? null;
    if ($mode === 'override') {
        if ($partVol) {
            return [
                'volume_value' => (float) $partVol['volume_value'],
                'volume_unit' => calc_volume_unit($partVol['volume_unit']),
                'count_after_eop' => $countAfter,
                'volume_origin' => calc_origin($partVol['volume_origin'] ?? null),
            ];
        }
        if ($part && $part['default_volume_value'] !== null && $part['default_volume_unit']) {
            return [
                'volume_value' => (float) $part['default_volume_value'],
                'volume_unit' => calc_volume_unit($part['default_volume_unit']),
                'count_after_eop' => $countAfter,
                'volume_origin' => 'default_all_years',
            ];
        }
    }
    if ($mode === 'project' && $pv) {
        return [
            'volume_value' => (float) $pv['volume_value'],
            'volume_unit' => calc_volume_unit($pv['volume_unit']),
            'count_after_eop' => $countAfter,
            'volume_origin' => calc_origin($pv['volume_origin'] ?? null),
        ];
    }
    if ($mode === 'share' && $pv) {
        $sharePct = $ctx['share'][$partId][$year] ?? $part['volume_share_percent'] ?? null;
        if ($sharePct !== null) {
            $share = max(0.0, min(100.0, (float) $sharePct)) / 100;
            return [
                'volume_value' => (float) $pv['volume_value'] * $share,
                'volume_unit' => calc_volume_unit($pv['volume_unit']),
                'count_after_eop' => $countAfter,
                'volume_origin' => calc_origin($pv['volume_origin'] ?? null),
            ];
        }
    }
    return null;
}

function calc_allocation_shares(array $volumeMap, array $parentById, array $childrenByParent, int $year, ?int $month, array $settings, ?int $week = null): array
{
    $weeklyOf = function (?array $row) use ($year, $month, $week, $settings): float {
        if ($row === null) {
            return 0.0;
        }
        $picked = calc_pick_period($row, $year, $month, $week);
        return calc_volume_to_weekly($picked['volume_value'], $picked['volume_unit'], $settings);
    };
    $shares = [];
    $done = [];
    foreach (array_keys($volumeMap) as $opId) {
        $root = (int) $opId;
        for ($i = 0; $i < 10000; $i++) {
            $parent = $parentById[$root] ?? null;
            if ($parent === null) {
                break;
            }
            $root = $parent;
        }
        if (isset($done[$root])) {
            continue;
        }
        $done[$root] = true;
        $family = [$root];
        $queue = [$root];
        $seen = [$root => true];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($childrenByParent[$id] ?? [] as $child) {
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $family[] = $child;
                $queue[] = $child;
            }
        }
        if (count($family) <= 1) {
            continue;
        }
        $total = 0.0;
        $weeklies = [];
        foreach ($family as $id) {
            $weekly = $weeklyOf($volumeMap[$id] ?? null);
            $weeklies[$id] = $weekly;
            $total += $weekly;
        }
        if ($total <= 1e-9) {
            continue;
        }
        foreach ($weeklies as $id => $weekly) {
            $shares[$id] = $weekly / $total;
        }
    }
    return $shares;
}

function calc_active_in_period(array $op, int $year, ?int $month, bool $countAfter): bool
{
    $sopP = calc_parse_sop_eop($op['sop']);
    $eopP = calc_parse_sop_eop($op['eop']);
    if ($countAfter) {
        if ($eopP === null) {
            return true;
        }
        if ($year < $eopP['year'] || ($year === $eopP['year'] && ($month === null || $month <= $eopP['month']))) {
            return $month === null ? $year > $eopP['year'] : false;
        }
        return true;
    }
    if ($sopP === null || $eopP === null) {
        return true;
    }
    if ($year < $sopP['year'] || $year > $eopP['year']) {
        return false;
    }
    if ($month === null) {
        return true;
    }
    return calc_month_in_production($op['sop'], $op['eop'], $year, $month);
}

function calc_weekly_volume(float $value, string $unit, array $settings, array $op, int $year, string $origin, bool $countAfter): float
{
    $months = calc_production_months($op['sop'], $op['eop'], $year);
    if ($countAfter) {
        return calc_volume_to_weekly($value, $unit, $settings);
    }
    if ($months <= 0) {
        return 0.0;
    }
    if ($origin === 'manual_year') {
        if ($months < 12 && $unit === 'annual') {
            return calc_volume_to_weekly($value / $months, 'monthly', $settings);
        }
        return calc_volume_to_weekly($value, $unit, $settings);
    }
    return calc_volume_to_weekly($value, $unit, $settings) * ($months / 12);
}

function calc_resolve_operation(array $op, int $year, ?int $month, array $ctx, array $volumeMap, array $shares, array $settings, bool $contract, ?int $week = null): ?array
{
    $opId = (int) $op['operation_id'];
    $override = $volumeMap[$opId] ?? null;
    $picked = $override ? calc_pick_period($override, $year, $month, $week) : null;
    $source = strtolower(trim((string) ($override['source'] ?? '')));
    $isAllocation = $override && ($source === 'allocation' || ($op['split_from_operation_id'] !== null && $source === ''));
    if ($isAllocation && $picked && $op['project_id'] && $op['part_id'] && array_key_exists($opId, $shares)) {
        $effective = calc_effective_volume($ctx, (int) $op['project_id'], (int) $op['part_id'], $year, $contract);
        if ($effective) {
            return [
                'volume_value' => (float) $effective['volume_value'] * (float) $shares[$opId],
                'volume_unit' => $effective['volume_unit'],
                'volume_origin' => $effective['volume_origin'],
                'count_after_eop' => (bool) $effective['count_after_eop'],
            ];
        }
    }
    if ($op['split_from_operation_id'] !== null) {
        if (!$picked) {
            return ['volume_value' => 0.0, 'volume_unit' => 'weekly', 'volume_origin' => 'manual_year', 'count_after_eop' => false];
        }
        return [
            'volume_value' => $picked['volume_value'],
            'volume_unit' => $picked['volume_unit'],
            'volume_origin' => 'manual_year',
            'count_after_eop' => false,
        ];
    }
    if ($picked) {
        return [
            'volume_value' => $picked['volume_value'],
            'volume_unit' => $picked['volume_unit'],
            'volume_origin' => 'manual_year',
            'count_after_eop' => false,
        ];
    }
    if ($op['project_id'] && $op['part_id']) {
        $effective = calc_effective_volume($ctx, (int) $op['project_id'], (int) $op['part_id'], $year, $contract);
        if ($effective) {
            return $effective;
        }
    }
    return [
        'volume_value' => (float) $op['volume_value'],
        'volume_unit' => calc_volume_unit($op['volume_unit']),
        'volume_origin' => 'manual_year',
        'count_after_eop' => false,
    ];
}

function calc_cycle(array $op): array
{
    $alt = isset($op['alt_cycle_time_seconds']) && (float) $op['alt_cycle_time_seconds'] > 0;
    $useAlt = $alt && (int) ($op['use_alternative_in_calculator'] ?? 0) === 1;
    $cycle = $useAlt ? (float) $op['alt_cycle_time_seconds'] : (float) $op['cycle_time_seconds'];
    $nests = $useAlt && isset($op['alt_nests_count']) && (float) $op['alt_nests_count'] > 0
        ? (float) $op['alt_nests_count']
        : (float) ($op['nests_count'] ?? 1);
    return ['cycle' => $cycle, 'nests' => max(1.0, $nests), 'alt' => $alt, 'use_alt' => $useAlt];
}

function calc_availability(array $settings, ?float $machineOee): float
{
    $oee = ($machineOee !== null && $machineOee > 0) ? $machineOee : (float) $settings['oee_factor'];
    $shifts = max(1.0, (float) ($settings['shifts_per_day'] ?? 1));
    $seconds = ($settings['working_days_year'] / 52) * $settings['shift_time_seconds'] * 60 * $shifts * $oee;
    return max(0.0, (float) round($seconds - (float) ($settings['startup_shutdown_seconds'] ?? 0)));
}

function calc_machine_availability(array $machine, array $settings, ?float $machineOee): float
{
    $usage = max(0.1, min(1.0, (float) ($machine['machine_usage'] ?? 1)));
    if ((int) ($machine['is_baseline'] ?? 0) === 1) {
        $oee = ($machineOee !== null && $machineOee > 0) ? $machineOee : (float) $settings['oee_factor'];
        return baseline_availability_seconds($machine, $oee) / $usage;
    }
    return calc_availability($settings, $machineOee) / $usage;
}

function calc_material_bucket_add(array &$bucket, array $row, string $projectLabel, string $detailLabel): void
{
    $id = (int) $row['material_id'];
    if (!isset($bucket[$id])) {
        $alias = resolve_material_alias(
            isset($row['alias']) ? (string) $row['alias'] : null,
            isset($row['description']) ? (string) $row['description'] : null
        );
        $bucket[$id] = [
            'material_alias' => $alias,
            'material_sap' => $row['sap_number'],
            'material_width_mm' => $row['width_mm'],
            'material_length_mm' => $row['length_mm'],
            'material_grammage_kg_m2' => $row['grammage_kg_m2'],
            'required' => 0.0,
            'details' => [],
        ];
    }
    $bucket[$id]['required'] += (float) $row['required_seconds'];
    $key = $projectLabel . '|' . $detailLabel;
    if (!isset($bucket[$id]['details'][$key])) {
        $bucket[$id]['details'][$key] = ['project_label' => $projectLabel, 'detail_label' => $detailLabel, 'required' => 0.0];
    }
    $bucket[$id]['details'][$key]['required'] += (float) $row['required_seconds'];
}

function calc_material_breakdown(array $bucket, float $availability): array
{
    $out = [];
    foreach ($bucket as $material) {
        $details = [];
        foreach ($material['details'] as $detail) {
            $details[] = [
                'project_label' => $detail['project_label'],
                'detail_label' => $detail['detail_label'],
                'contribution_percent' => $availability > 0 ? round(($detail['required'] / $availability) * 10000) / 100 : 0,
            ];
        }
        usort($details, fn(array $a, array $b): int => $b['contribution_percent'] <=> $a['contribution_percent']);
        $out[] = [
            'material_alias' => $material['material_alias'],
            'material_sap' => $material['material_sap'],
            'material_width_mm' => $material['material_width_mm'],
            'material_length_mm' => $material['material_length_mm'],
            'material_grammage_kg_m2' => $material['material_grammage_kg_m2'],
            'contribution_percent' => $availability > 0 ? round(($material['required'] / $availability) * 10000) / 100 : 0,
            'details' => $details,
        ];
    }
    usort($out, fn(array $a, array $b): int => $b['contribution_percent'] <=> $a['contribution_percent']);
    return $out;
}

function calc_operation_demand(array $machine, array $op, float $weekly, array $materials): array
{
    if ((int) ($machine['is_baseline'] ?? 0) === 1) {
        $rows = baseline_seconds_per_material($machine, $op['detail_designation_id'] ?? null, $weekly, $materials);
        $seconds = 0.0;
        foreach ($rows as $row) {
            $seconds += (float) $row['required_seconds'];
        }
        return ['seconds' => $seconds, 'materials' => $rows, 'alt' => false, 'use_alt' => false];
    }
    $cycle = calc_cycle($op);
    return [
        'seconds' => $weekly * ($cycle['cycle'] / $cycle['nests']),
        'materials' => [],
        'alt' => $cycle['alt'],
        'use_alt' => $cycle['use_alt'],
    ];
}

/** Lata z wolumenem > 0 — SOP–EOP oraz jawne wiersze wolumenu, jak w wersji Node. */
function calc_positive_volume_years(PDO $pdo, array $ctx, array $op, bool $contract, ?array &$shareCache = null): array
{
    $opId = (int) ($op['operation_id'] ?? $op['id'] ?? 0);
    $projectId = (int) ($op['project_id'] ?? 0);
    $partId = (int) ($op['part_id'] ?? 0);
    $years = [];
    $sop = calc_parse_sop_eop($op['sop'] ?? null);
    $eop = calc_parse_sop_eop($op['eop'] ?? null);
    if ($sop !== null && $eop !== null && $eop['year'] >= $sop['year']) {
        for ($year = $sop['year']; $year <= $eop['year']; $year++) {
            $years[$year] = true;
        }
    }
    foreach (array_keys($ctx['projectVolumes'][$projectId] ?? []) as $year) {
        $years[(int) $year] = true;
    }
    foreach (array_keys($ctx['partVolumes'][$partId] ?? []) as $year) {
        $years[(int) $year] = true;
    }
    if ($contract) {
        foreach (array_keys($ctx['projectContract'][$projectId] ?? []) as $year) {
            $years[(int) $year] = true;
        }
        foreach (array_keys($ctx['partContract'][$partId] ?? []) as $year) {
            $years[(int) $year] = true;
        }
    }
    foreach ($ctx['opVolumes'] as $year => $map) {
        if (isset($map[$opId])) {
            $years[(int) $year] = true;
        }
    }
    $resolvedOp = $op;
    $resolvedOp['operation_id'] = $opId;
    $kept = [];
    if ($shareCache === null) {
        $shareCache = [];
    }
    ksort($years);
    foreach (array_keys($years) as $year) {
        if ($year < 1990 || $year > 2200) {
            continue;
        }
        $volumeMap = $ctx['opVolumes'][$year] ?? [];
        if (!isset($shareCache[$year])) {
            $settings = calc_settings($pdo, $year);
            $shareCache[$year] = [
                $settings,
                calc_allocation_shares($volumeMap, $ctx['parentById'], $ctx['childrenByParent'], $year, null, $settings),
            ];
        }
        [$settings, $shares] = $shareCache[$year];
        $resolved = calc_resolve_operation($resolvedOp, $year, null, $ctx, $volumeMap, $shares, $settings, $contract);
        if ($resolved !== null && abs((float) $resolved['volume_value']) > 1e-6) {
            $kept[] = $year;
        }
    }
    return $kept;
}

function calc_context(PDO $pdo): array
{
    $ctx = [
        'parts' => [],
        'projects' => [],
        'projectVolumes' => [],
        'projectContract' => [],
        'partVolumes' => [],
        'partContract' => [],
        'share' => [],
        'contractShare' => [],
        'opVolumes' => [],
        'parentById' => [],
        'childrenByParent' => [],
    ];
    foreach ($pdo->query('SELECT id, eop FROM projects') as $row) {
        $ctx['projects'][(int) $row['id']] = $row;
    }
    foreach ($pdo->query('SELECT id, volume_mode, volume_share_percent, default_volume_value, default_volume_unit, contract_volume_mode, contract_volume_share_percent, contract_default_volume_value, contract_default_volume_unit FROM parts') as $row) {
        $ctx['parts'][(int) $row['id']] = $row;
    }
    foreach ($pdo->query('SELECT project_id, year, volume_value, volume_unit, include_in_calculator_after_eop, volume_origin FROM project_volumes') as $row) {
        $ctx['projectVolumes'][(int) $row['project_id']][(int) $row['year']] = $row;
    }
    foreach ($pdo->query('SELECT project_id, year, volume_value, volume_unit, include_in_calculator_after_eop, volume_origin FROM project_volumes_contract') as $row) {
        $ctx['projectContract'][(int) $row['project_id']][(int) $row['year']] = $row;
    }
    foreach ($pdo->query('SELECT part_id, year, volume_value, volume_unit, volume_origin FROM part_volume_by_year') as $row) {
        $ctx['partVolumes'][(int) $row['part_id']][(int) $row['year']] = $row;
    }
    foreach ($pdo->query('SELECT part_id, year, volume_value, volume_unit, volume_origin FROM part_volume_contract_by_year') as $row) {
        $ctx['partContract'][(int) $row['part_id']][(int) $row['year']] = $row;
    }
    foreach ($pdo->query('SELECT part_id, year, share_percent FROM part_volume_share_by_year') as $row) {
        $ctx['share'][(int) $row['part_id']][(int) $row['year']] = $row['share_percent'];
    }
    foreach ($pdo->query('SELECT part_id, year, share_percent FROM part_volume_contract_share_by_year') as $row) {
        $ctx['contractShare'][(int) $row['part_id']][(int) $row['year']] = $row['share_percent'];
    }
    foreach ($pdo->query('SELECT operation_id, year, volume_value, volume_unit, volume_value_before, effective_from_month, effective_from_week, source FROM operation_volume_by_year') as $row) {
        $ctx['opVolumes'][(int) $row['year']][(int) $row['operation_id']] = $row;
    }
    foreach ($pdo->query('SELECT id, split_from_operation_id FROM operations') as $row) {
        $id = (int) $row['id'];
        $parent = $row['split_from_operation_id'] !== null ? (int) $row['split_from_operation_id'] : null;
        $ctx['parentById'][$id] = $parent;
        if ($parent !== null) {
            $ctx['childrenByParent'][$parent][] = $id;
        }
    }
    return $ctx;
}

function calc_csv_list(string $key): array
{
    $raw = trim((string) ($_GET[$key] ?? ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

function calculator_machine_rows(PDO $pdo, array $statuses, array $types, ?array $onlyIds): array
{
    if ($onlyIds !== null && $onlyIds === []) {
        return [];
    }
    $sql = 'SELECT id, internal_number, sap_number, type, oee_override, status, location, COALESCE(machine_usage, 1) AS machine_usage,
                   width_mm, depth_mm, height_mm, stroke_mm, is_baseline,
                   baseline_available_hours_per_week, baseline_weeks_per_year,
                   baseline_max_throughput_kg_h, baseline_max_throughput_unit,
                   baseline_max_speed_m_min, baseline_max_speed_unit,
                   baseline_max_blank_width_mm, baseline_max_blank_width_unit,
                   baseline_min_length_mm, baseline_max_blanks_across
            FROM machines WHERE 1=1';
    $params = [];
    if ($statuses !== []) {
        $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($params, ...$statuses);
    }
    if ($types !== []) {
        $sql .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        array_push($params, ...$types);
    }
    if ($onlyIds !== null) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
        array_push($params, ...$onlyIds);
    }
    foreach (['width' => 'width_mm', 'depth' => 'depth_mm', 'height' => 'height_mm', 'stroke' => 'stroke_mm'] as $prefix => $column) {
        $op = (string) ($_GET[$prefix . 'Op'] ?? '');
        $value = $_GET[$prefix . 'Value'] ?? null;
        if (($op === 'gte' || $op === 'lte') && is_numeric($value)) {
            $sql .= ' AND ' . $column . ' IS NOT NULL AND ' . $column . ($op === 'gte' ? ' >= ?' : ' <= ?');
            $params[] = (float) $value;
        }
    }
    $sql .= ' ORDER BY internal_number';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $machines = [];
    foreach ($stmt->fetchAll() as $row) {
        $machines[(int) $row['id']] = $row;
    }
    return $machines;
}

function calculator_include_rfq_ids(): array
{
    $ids = [];
    foreach (calc_csv_list('includeRfqOperationIds') as $part) {
        if (ctype_digit($part) && (int) $part > 0) {
            $ids[] = (int) $part;
        }
    }
    return array_values(array_unique($ids));
}

function calculator_rfq_operations(PDO $pdo): array
{
    $ids = calculator_include_rfq_ids();
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT o.id AS operation_id, o.project_id, o.part_id, o.machine_id, o.cycle_time_seconds, o.volume_value, o.volume_unit,
                o.nests_count, o.alt_cycle_time_seconds, o.alt_nests_count, o.use_alternative_in_calculator, o.split_from_operation_id,
                p.sop, p.eop, p.status AS project_status, p.client AS project_client, p.name AS project_name,
                pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text, pt.designation AS detail_designation
         FROM operations o
         JOIN projects p ON p.id = o.project_id
         JOIN parts pt ON pt.id = o.part_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE p.status = \'RFQ\' AND o.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $stmt->execute($ids);
    $ops = [];
    foreach ($stmt->fetchAll() as $op) {
        $op['operation_id'] = (int) $op['operation_id'];
        $op['project_id'] = (int) $op['project_id'];
        $op['part_id'] = (int) $op['part_id'];
        $op['machine_id'] = (int) $op['machine_id'];
        $op['split_from_operation_id'] = $op['split_from_operation_id'] !== null ? (int) $op['split_from_operation_id'] : null;
        $ops[(int) $op['machine_id']][] = $op;
    }
    return $ops;
}

function calculator_merge_ops(array $ops, array $extra): array
{
    foreach ($extra as $machineId => $list) {
        foreach ($list as $op) {
            $ops[$machineId][] = $op;
        }
    }
    return $ops;
}

function calculator_filtered_machines(PDO $pdo, array $statuses, array $types, ?array $onlyIds): array
{
    $machines = calculator_machine_rows($pdo, $statuses, $types, $onlyIds);
    $extraIds = array_keys(calculator_rfq_operations($pdo));
    if ($onlyIds !== null) {
        $extraIds = array_values(array_intersect($extraIds, $onlyIds));
    }
    $missing = [];
    foreach ($extraIds as $id) {
        if (!isset($machines[$id])) {
            $missing[] = $id;
        }
    }
    if ($missing === []) {
        return $machines;
    }
    foreach (calculator_machine_rows($pdo, [], $types, $missing) as $id => $row) {
        $machines[$id] = $row;
    }
    uasort($machines, fn(array $a, array $b): int => strcmp((string) $a['internal_number'], (string) $b['internal_number']));
    return $machines;
}

function calculator_line_key(mixed $location): string
{
    $text = trim((string) ($location ?? ''));
    return $text !== '' ? $text : '—';
}

function calculator_machines(PDO $pdo, int $yearFrom, int $yearTo, array $statuses, array $types, ?array $onlyIds, bool $contract): array
{
    $yearFrom = max(2000, min(2100, $yearFrom));
    $yearTo = max($yearFrom, min(2100, $yearTo));
    if ($yearTo - $yearFrom > 40) {
        $yearTo = $yearFrom + 40;
    }

    $machines = calculator_filtered_machines($pdo, $statuses, $types, $onlyIds);
    if ($machines === []) {
        return [];
    }

    $ops = [];
    foreach ($pdo->query(
        "SELECT o.id AS operation_id, o.project_id, o.part_id, o.machine_id, o.cycle_time_seconds, o.volume_value, o.volume_unit,
                o.nests_count, o.alt_cycle_time_seconds, o.alt_nests_count, o.use_alternative_in_calculator, o.split_from_operation_id,
                p.sop, p.eop, p.status AS project_status, p.client AS project_client, p.name AS project_name,
                pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text, pt.designation AS detail_designation
         FROM operations o
         JOIN projects p ON p.id = o.project_id
         JOIN parts pt ON pt.id = o.part_id
         LEFT JOIN part_designations pd ON pd.id = pt.designation_id
         WHERE p.status = 'active'"
    ) as $op) {
        $op['operation_id'] = (int) $op['operation_id'];
        $op['project_id'] = (int) $op['project_id'];
        $op['part_id'] = (int) $op['part_id'];
        $op['machine_id'] = (int) $op['machine_id'];
        $op['split_from_operation_id'] = $op['split_from_operation_id'] !== null ? (int) $op['split_from_operation_id'] : null;
        $ops[(int) $op['machine_id']][] = $op;
    }
    $ops = calculator_merge_ops($ops, calculator_rfq_operations($pdo));
    $ctx = calc_context($pdo);
    $mode = reference_mode($pdo);
    $baseline = baseline_bundle($pdo);
    $shareCache = [];
    for ($year = $yearFrom; $year <= $yearTo; $year++) {
        $settings = calc_settings($pdo, $year);
        $volumeMap = $ctx['opVolumes'][$year] ?? [];
        $shareCache[$year][0] = calc_allocation_shares($volumeMap, $ctx['parentById'], $ctx['childrenByParent'], $year, null, $settings);
        for ($month = 1; $month <= 12; $month++) {
            $shareCache[$year][$month] = calc_allocation_shares($volumeMap, $ctx['parentById'], $ctx['childrenByParent'], $year, $month, $settings);
        }
    }
    $out = [];

    foreach ($machines as $machineId => $machine) {
        $machineOee = isset($machine['oee_override']) && is_numeric($machine['oee_override']) ? (float) $machine['oee_override'] : null;
        $isBaseline = (int) ($machine['is_baseline'] ?? 0) === 1;
        $machineOps = $isBaseline ? ($baseline['ops'][$machineId] ?? []) : ($ops[$machineId] ?? []);
        $years = [];
        for ($year = $yearFrom; $year <= $yearTo; $year++) {
            $settings = baseline_volume_settings(calc_settings($pdo, $year), $machine);
            $availability = calc_machine_availability($machine, $settings, $machineOee);
            $volumeMap = $ctx['opVolumes'][$year] ?? [];
            $yearShares = $shareCache[$year][0];
            $required = 0.0;
            $altRelevant = 0;
            $altUsed = 0;
            $altUnused = 0;
            $hasRfq = false;
            $details = [];
            $materialBucket = [];
            foreach ($machineOps as $op) {
                $resolved = calc_resolve_operation($op, $year, null, $ctx, $volumeMap, $yearShares, $settings, $contract);
                if ($resolved === null || !calc_active_in_period($op, $year, null, $resolved['count_after_eop'])) {
                    continue;
                }
                $weekly = calc_weekly_volume($resolved['volume_value'], $resolved['volume_unit'], $settings, $op, $year, $resolved['volume_origin'], $resolved['count_after_eop']);
                $demand = calc_operation_demand($machine, $op, $weekly, $baseline['materials']);
                $seconds = $demand['seconds'];
                $required += $seconds;
                if (strtoupper((string) $op['project_status']) === 'RFQ' && $weekly > 1e-9) {
                    $hasRfq = true;
                }
                if ($demand['alt'] && $weekly > 1e-9) {
                    $altRelevant++;
                    if ($demand['use_alt']) {
                        $altUsed++;
                    } else {
                        $altUnused++;
                    }
                }
                if ($seconds > 1e-9) {
                    $label = detail_label([
                        'sap_number' => $op['detail_sap_number'],
                        'alias' => $op['detail_alias'],
                        'free_text' => $op['detail_free_text'],
                        'designation' => $op['detail_designation'],
                        'id' => $op['part_id'],
                    ], $mode);
                    $projectLabel = calc_project_label($op);
                    $key = $op['project_id'] . '|' . $label;
                    if (!isset($details[$key])) {
                        $details[$key] = ['project_label' => $projectLabel, 'detail_label' => $label, 'required' => 0.0, 'has_rfq' => false];
                    }
                    $details[$key]['required'] += $seconds;
                    if (strtoupper((string) $op['project_status']) === 'RFQ') {
                        $details[$key]['has_rfq'] = true;
                    }
                    foreach ($demand['materials'] as $materialRow) {
                        calc_material_bucket_add($materialBucket, $materialRow, $projectLabel, $label);
                    }
                }
            }
            foreach (baseline_external_rows($machine, $year, $baseline) as $materialRow) {
                $required += (float) $materialRow['required_seconds'];
                calc_material_bucket_add($materialBucket, $materialRow, '', 'Wolumen zewnętrzny');
            }
            $externalSeconds = 0.0;
            foreach (baseline_external_rows($machine, $year, $baseline) as $materialRow) {
                $externalSeconds += (float) $materialRow['required_seconds'];
            }
            $monthSum = 0.0;
            for ($month = 1; $month <= 12; $month++) {
                $monthShares = $shareCache[$year][$month];
                $monthRequired = $externalSeconds;
                foreach ($machineOps as $op) {
                    $resolved = calc_resolve_operation($op, $year, $month, $ctx, $volumeMap, $monthShares, $settings, $contract);
                    if ($resolved === null || !calc_active_in_period($op, $year, $month, $resolved['count_after_eop'])) {
                        continue;
                    }
                    $weekly = calc_weekly_volume($resolved['volume_value'], $resolved['volume_unit'], $settings, $op, $year, $resolved['volume_origin'], $resolved['count_after_eop']);
                    $monthRequired += calc_operation_demand($machine, $op, $weekly, $baseline['materials'])['seconds'];
                }
                $monthSum += $availability > 0 ? (int) round(($monthRequired / $availability) * 100) : ($monthRequired > 0 ? 100 : 0);
            }
            $border = 'none';
            if ($altRelevant > 0) {
                if ($altUsed === $altRelevant) {
                    $border = 'all_alt';
                } elseif ($altUnused === $altRelevant) {
                    $border = 'unused';
                } else {
                    $border = 'mixed';
                }
            }
            $breakdown = [];
            foreach ($details as $detail) {
                $breakdown[] = [
                    'project_label' => $detail['project_label'],
                    'detail_label' => $detail['detail_label'],
                    'contribution_percent' => $availability > 0 ? round(($detail['required'] / $availability) * 10000) / 100 : 0,
                    'share_percent' => $required > 0 ? round(($detail['required'] / $required) * 10000) / 100 : 0,
                    'has_rfq' => $detail['has_rfq'],
                ];
            }
            usort($breakdown, fn(array $a, array $b): int => $b['contribution_percent'] <=> $a['contribution_percent']);
            $yearCell = [
                'load_percent' => (int) round($monthSum / 12),
                'capacity_pcs_per_week' => 0,
                'required_sec_per_week' => (int) round($required),
                'availability_sec_per_week' => $availability,
                'alternative_border' => $border,
                'detail_breakdown' => $breakdown,
                'has_rfq' => $hasRfq,
            ];
            if ($isBaseline) {
                $yearCell['material_breakdown'] = calc_material_breakdown($materialBucket, $availability);
            }
            $years[$year] = $yearCell;
        }
        $out[] = [
            'machine_id' => $machineId,
            'internal_number' => $machine['internal_number'],
            'sap_number' => $machine['sap_number'],
            'type' => (string) $machine['type'],
            'is_baseline' => $isBaseline,
            'machine_status' => $machine['status'],
            'location' => trim((string) ($machine['location'] ?? '')) !== '' ? trim((string) $machine['location']) : null,
            'width_mm' => is_numeric($machine['width_mm']) ? (float) $machine['width_mm'] : null,
            'depth_mm' => is_numeric($machine['depth_mm']) ? (float) $machine['depth_mm'] : null,
            'height_mm' => is_numeric($machine['height_mm']) ? (float) $machine['height_mm'] : null,
            'stroke_mm' => is_numeric($machine['stroke_mm']) ? (float) $machine['stroke_mm'] : null,
            'years' => $years,
        ];
    }
    return $out;
}

function calculator_statuses(): array
{
    $raw = trim((string) ($_GET['machineStatuses'] ?? $_GET['machineStatus'] ?? 'active'));
    if ($raw === '' || strtolower($raw) === 'all' || strtolower($raw) === 'wszystkie') {
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

function calculator_machine_ids(PDO $pdo): ?array
{
    $clients = [];
    foreach (calc_csv_list('clients') ?: calc_csv_list('client') as $client) {
        $name = strtoupper(trim($client));
        if ($name !== '') {
            $clients[$name] = true;
        }
    }
    $ids = null;
    if ($clients !== []) {
        $names = array_keys($clients);
        $stmt = $pdo->prepare(
            'SELECT DISTINCT o.machine_id
             FROM operations o
             JOIN projects p ON p.id = o.project_id
             WHERE p.status = \'active\' AND UPPER(TRIM(p.client)) IN (' . implode(',', array_fill(0, count($names), '?')) . ')'
        );
        $stmt->execute($names);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $numbers = calc_csv_list('machines');
    if ($numbers !== []) {
        $matched = [];
        $stmt = $pdo->prepare('SELECT id FROM machines WHERE internal_number = ?');
        foreach ($numbers as $number) {
            if (preg_match('/^\d+(\/\d+)?$/', $number) !== 1) {
                continue;
            }
            $stmt->execute([$number]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $matched[] = (int) $id;
            }
        }
        $ids = $ids === null ? $matched : array_values(array_intersect($ids, $matched));
    }
    return $ids;
}

function calculator_source(PDO $pdo): PDO
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

function calculator_payload(PDO $pdo): array
{
    $pdo = calculator_source($pdo);
    $yearFrom = (int) ($_GET['yearFrom'] ?? 2026);
    $yearTo = (int) ($_GET['yearTo'] ?? 2036);
    $scenarioId = (int) ($_GET['scenarioId'] ?? 0);
    $contract = ($_GET['useContractualVolumes'] ?? '') === '1' || strtolower((string) ($_GET['useContractualVolumes'] ?? '')) === 'true';
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    return [
        'yearFrom' => max(2000, min(2100, $yearFrom)),
        'yearTo' => max(2000, min(2100, $yearTo)),
        'scenarioId' => $scenarioId > 0 ? $scenarioId : null,
        'machines' => calculator_machines($pdo, $yearFrom, $yearTo, calculator_statuses(), $types, calculator_machine_ids($pdo), $contract),
    ];
}

function calculator_one_machine(PDO $pdo, int $machineId): ?array
{
    $pdo = calculator_source($pdo);
    $yearFrom = (int) ($_GET['yearFrom'] ?? 2026);
    $yearTo = (int) ($_GET['yearTo'] ?? 2036);
    $contract = ($_GET['useContractualVolumes'] ?? '') === '1' || strtolower((string) ($_GET['useContractualVolumes'] ?? '')) === 'true';
    $rows = calculator_machines($pdo, $yearFrom, $yearTo, [], [], [$machineId], $contract);
    return $rows[0] ?? null;
}

function calculator_scope_ids(PDO $pdo): ?array
{
    $ids = calculator_machine_ids($pdo);
    $raw = trim((string) ($_GET['machineIds'] ?? ''));
    if ($raw === '') {
        return $ids;
    }
    $wanted = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
            $wanted[] = (int) $part;
        }
    }
    if ($wanted === []) {
        return $ids;
    }
    $wanted = array_values(array_unique($wanted));
    if ($ids === null) {
        return $wanted;
    }
    return array_values(array_intersect($ids, $wanted));
}

function calculator_contract_flag(): bool
{
    $raw = (string) ($_GET['useContractualVolumes'] ?? '');
    return $raw === '1' || strtolower($raw) === 'true';
}

function calc_project_label(array $op): string
{
    $client = trim((string) ($op['project_client'] ?? ''));
    $name = trim((string) ($op['project_name'] ?? ''));
    if ($client !== '' && $name !== '') {
        return $client . ' · ' . $name;
    }
    if ($name !== '') {
        return $name;
    }
    return $client !== '' ? $client : '—';
}

function calc_year_markers(array $ops, int $year): array
{
    $hasSop = false;
    $hasEop = false;
    $months = [];
    foreach ($ops as $op) {
        foreach (['sop' => 'has_sop', 'eop' => 'has_eop'] as $field => $flag) {
            $parsed = calc_parse_sop_eop($op[$field] ?? null);
            if ($parsed === null || (int) $parsed['year'] !== $year) {
                continue;
            }
            if ($flag === 'has_sop') {
                $hasSop = true;
            } else {
                $hasEop = true;
            }
            $month = (int) $parsed['month'];
            if (!isset($months[$month])) {
                $months[$month] = ['has_sop' => false, 'has_eop' => false];
            }
            $months[$month][$flag] = true;
        }
    }
    return ['has_sop' => $hasSop, 'has_eop' => $hasEop, 'months' => $months];
}

function calc_year_has_partial(array $volumeMap): bool
{
    foreach ($volumeMap as $row) {
        if (!is_array($row)) {
            continue;
        }
        $from = $row['effective_from_month'] ?? null;
        if ($from !== null && $from !== '' && is_numeric($from)) {
            return true;
        }
    }
    return false;
}

function calc_period_stats(
    array $machineOps,
    int $year,
    ?int $month,
    ?int $week,
    array $ctx,
    array $volumeMap,
    array $shares,
    array $settings,
    bool $contract,
    float $availability,
    string $mode,
    array $machine = [],
    array $baseline = []
): array {
    $required = 0.0;
    $details = [];
    $materialBucket = [];
    foreach ($machineOps as $op) {
        $resolved = calc_resolve_operation($op, $year, $month, $ctx, $volumeMap, $shares, $settings, $contract, $week);
        if ($resolved === null || !calc_active_in_period($op, $year, $month, $resolved['count_after_eop'])) {
            continue;
        }
        $weekly = calc_weekly_volume(
            $resolved['volume_value'],
            $resolved['volume_unit'],
            $settings,
            $op,
            $year,
            $resolved['volume_origin'],
            $resolved['count_after_eop']
        );
        $demand = $machine === []
            ? ['seconds' => $weekly * (calc_cycle($op)['cycle'] / calc_cycle($op)['nests']), 'materials' => []]
            : calc_operation_demand($machine, $op, $weekly, $baseline['materials'] ?? []);
        $seconds = $demand['seconds'];
        $required += $seconds;
        $label = detail_label([
            'sap_number' => $op['detail_sap_number'] ?? null,
            'alias' => $op['detail_alias'] ?? null,
            'free_text' => $op['detail_free_text'] ?? null,
            'designation' => $op['detail_designation'] ?? null,
            'id' => $op['part_id'],
        ], $mode);
        $key = $op['project_id'] . '|' . $label;
        if (!isset($details[$key])) {
            $details[$key] = [
                'project_label' => calc_project_label($op),
                'detail_label' => $label,
                'required' => 0.0,
                'volume' => 0.0,
                'has_rfq' => false,
            ];
        }
        $details[$key]['required'] += $seconds;
        $details[$key]['volume'] += $weekly;
        if (strtoupper((string) ($op['project_status'] ?? '')) === 'RFQ') {
            $details[$key]['has_rfq'] = true;
        }
        foreach ($demand['materials'] as $materialRow) {
            calc_material_bucket_add($materialBucket, $materialRow, $details[$key]['project_label'], $details[$key]['detail_label']);
        }
    }
    if ($machine !== [] && $baseline !== []) {
        foreach (baseline_external_rows($machine, $year, $baseline) as $materialRow) {
            $required += (float) $materialRow['required_seconds'];
            calc_material_bucket_add($materialBucket, $materialRow, '', 'Wolumen zewnętrzny');
        }
    }
    $breakdown = [];
    foreach ($details as $detail) {
        $breakdown[] = [
            'project_label' => $detail['project_label'],
            'detail_label' => $detail['detail_label'],
            'contribution_percent' => $availability > 0 ? round(($detail['required'] / $availability) * 10000) / 100 : 0,
            'share_percent' => $required > 0 ? round(($detail['required'] / $required) * 10000) / 100 : 0,
            'volume_quantity' => round($detail['volume'], 2),
            'has_rfq' => $detail['has_rfq'],
        ];
    }
    usort($breakdown, fn(array $a, array $b): int => $b['contribution_percent'] <=> $a['contribution_percent']);
    return [
        'load_percent' => $availability > 0 ? (int) round(($required / $availability) * 100) : ($required > 0 ? 100 : 0),
        'detail_breakdown' => $breakdown,
        'material_breakdown' => calc_material_breakdown($materialBucket, $availability),
    ];
}

function calc_month_from_weeks(array $weeks, float $workWeeks): array
{
    $loads = [];
    foreach ($weeks as $week) {
        $loads[] = (int) ($week['load_percent'] ?? 0);
    }
    $monthLoad = $loads === [] ? 0 : (int) round(array_sum($loads) / count($loads));
    $best = null;
    $bestDist = null;
    foreach ($weeks as $week) {
        $dist = abs((int) ($week['load_percent'] ?? 0) - $monthLoad);
        if ($best === null || $dist < $bestDist) {
            $best = $week;
            $bestDist = $dist;
        }
    }
    $details = [];
    $ww = max(1.0, $workWeeks);
    foreach ($best['detail_breakdown'] ?? [] as $item) {
        if (isset($item['volume_quantity']) && is_numeric($item['volume_quantity'])) {
            $item['volume_quantity'] = round(((float) $item['volume_quantity'] * $ww) / 12, 2);
        }
        $details[] = $item;
    }
    return [
        'load_percent' => $monthLoad,
        'detail_breakdown' => $details,
        'material_breakdown' => $best['material_breakdown'] ?? [],
    ];
}

function calculator_operations(PDO $pdo, ?array $machineIds): array
{
    $sql = "SELECT o.id AS operation_id, o.project_id, o.part_id, o.machine_id, o.cycle_time_seconds, o.volume_value, o.volume_unit,
                   o.nests_count, o.alt_cycle_time_seconds, o.alt_nests_count, o.use_alternative_in_calculator, o.split_from_operation_id,
                   p.sop, p.eop, p.status AS project_status, p.client AS project_client, p.name AS project_name,
                   pd.sap_number AS detail_sap_number, pd.alias AS detail_alias, pd.free_text AS detail_free_text, pt.designation AS detail_designation
            FROM operations o
            JOIN projects p ON p.id = o.project_id
            JOIN parts pt ON pt.id = o.part_id
            LEFT JOIN part_designations pd ON pd.id = pt.designation_id
            WHERE p.status = 'active'";
    $params = [];
    if ($machineIds !== null) {
        if ($machineIds === []) {
            return [];
        }
        $sql .= ' AND o.machine_id IN (' . implode(',', array_fill(0, count($machineIds), '?')) . ')';
        $params = $machineIds;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ops = [];
    foreach ($stmt->fetchAll() as $op) {
        $op['operation_id'] = (int) $op['operation_id'];
        $op['project_id'] = (int) $op['project_id'];
        $op['part_id'] = (int) $op['part_id'];
        $op['machine_id'] = (int) $op['machine_id'];
        $op['split_from_operation_id'] = $op['split_from_operation_id'] !== null ? (int) $op['split_from_operation_id'] : null;
        $ops[(int) $op['machine_id']][] = $op;
    }
    return $ops;
}

function calculator_period_breakdown(PDO $pdo): array
{
    $year = (int) ($_GET['year'] ?? 0);
    if ($year < 2000 || $year > 2100) {
        json_response(400, ['error' => 'Invalid year']);
    }
    $pdo = calculator_source($pdo);
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    $machines = calculator_filtered_machines($pdo, calculator_statuses(), $types, calculator_scope_ids($pdo));
    if ($machines === []) {
        return ['year' => $year, 'machines' => []];
    }
    $ops = calculator_merge_ops(calculator_operations($pdo, array_keys($machines)), calculator_rfq_operations($pdo));
    $baseline = baseline_bundle($pdo);
    $ctx = calc_context($pdo);
    $mode = reference_mode($pdo);
    $contract = calculator_contract_flag();
    $baseSettings = calc_settings($pdo, $year);
    $volumeMap = $ctx['opVolumes'][$year] ?? [];
    $eachWeek = calc_year_has_partial($volumeMap);
    $shareCache = [];
    $sharesFor = function (?int $month, ?int $week) use (&$shareCache, $volumeMap, $ctx, $year, $baseSettings): array {
        $key = ($month ?? 0) . ':' . ($week ?? 0);
        if (!isset($shareCache[$key])) {
            $shareCache[$key] = calc_allocation_shares(
                $volumeMap,
                $ctx['parentById'],
                $ctx['childrenByParent'],
                $year,
                $month,
                $baseSettings,
                $week
            );
        }
        return $shareCache[$key];
    };
    $out = [];
    foreach ($machines as $machineId => $machine) {
        $machineOee = isset($machine['oee_override']) && is_numeric($machine['oee_override']) ? (float) $machine['oee_override'] : null;
        $isBaseline = (int) ($machine['is_baseline'] ?? 0) === 1;
        $machineOps = $isBaseline ? ($baseline['ops'][$machineId] ?? []) : ($ops[$machineId] ?? []);
        $settings = baseline_volume_settings($baseSettings, $machine);
        $markers = calc_year_markers($machineOps, $year);
        $availability = calc_machine_availability($machine, $settings, $machineOee);
        $workWeeks = (float) ($settings['working_weeks_per_year'] ?? 48);
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $weekCount = calc_week_count($year, $month);
            $weeks = [];
            if ($eachWeek) {
                for ($week = 1; $week <= $weekCount; $week++) {
                    $weeks[$week] = calc_period_stats(
                        $machineOps,
                        $year,
                        $month,
                        $week,
                        $ctx,
                        $volumeMap,
                        $sharesFor($month, $week),
                        $settings,
                        $contract,
                        $availability,
                        $mode,
                        $machine,
                        $baseline
                    );
                }
            } else {
                $weekStats = calc_period_stats(
                    $machineOps,
                    $year,
                    $month,
                    null,
                    $ctx,
                    $volumeMap,
                    $sharesFor($month, null),
                    $settings,
                    $contract,
                    $availability,
                    $mode,
                    $machine,
                    $baseline
                );
                for ($week = 1; $week <= $weekCount; $week++) {
                    $weeks[$week] = $weekStats;
                }
            }
            $monthStats = calc_month_from_weeks($weeks, $workWeeks);
            $monthMarker = $markers['months'][$month] ?? ['has_sop' => false, 'has_eop' => false];
            $months[$month] = [
                'load_percent' => $monthStats['load_percent'],
                'weeks' => $weeks,
                'has_sop' => $monthMarker['has_sop'],
                'has_eop' => $monthMarker['has_eop'],
                'detail_breakdown' => $monthStats['detail_breakdown'],
                'material_breakdown' => $monthStats['material_breakdown'],
            ];
        }
        $out[] = [
            'machine_id' => $machineId,
            'has_sop' => $markers['has_sop'],
            'has_eop' => $markers['has_eop'],
            'months' => $months,
        ];
    }
    return ['year' => $year, 'machines' => $out];
}

function calculator_sop_eop_markers(PDO $pdo): array
{
    $yearFrom = max(2000, min(2100, (int) ($_GET['yearFrom'] ?? 2026)));
    $yearTo = max($yearFrom, min(2100, (int) ($_GET['yearTo'] ?? 2036)));
    if ($yearTo - $yearFrom > 40) {
        $yearTo = $yearFrom + 40;
    }
    $pdo = calculator_source($pdo);
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    $machines = calculator_filtered_machines($pdo, calculator_statuses(), $types, calculator_scope_ids($pdo));
    if ($machines === []) {
        return ['yearFrom' => $yearFrom, 'yearTo' => $yearTo, 'machines' => []];
    }
    $ops = calculator_merge_ops(calculator_operations($pdo, array_keys($machines)), calculator_rfq_operations($pdo));
    $baseline = baseline_bundle($pdo);
    $out = [];
    foreach ($machines as $machineId => $machine) {
        $isBaseline = (int) ($machine['is_baseline'] ?? 0) === 1;
        $machineOps = $isBaseline ? ($baseline['ops'][$machineId] ?? []) : ($ops[$machineId] ?? []);
        $years = [];
        for ($year = $yearFrom; $year <= $yearTo; $year++) {
            $markers = calc_year_markers($machineOps, $year);
            $years[$year] = [
                'has_sop' => $markers['has_sop'],
                'has_eop' => $markers['has_eop'],
                'months' => $markers['months'] === [] ? new stdClass() : $markers['months'],
            ];
        }
        $out[] = ['machine_id' => $machineId, 'years' => $years];
    }
    return ['yearFrom' => $yearFrom, 'yearTo' => $yearTo, 'machines' => $out];
}

function calculator_breakdown_series(PDO $pdo, array $machines, int $year, ?int $month, ?int $week, bool $contract): array
{
    if ($machines === []) {
        return ['load_percent' => null, 'clients' => []];
    }
    $ops = calculator_merge_ops(calculator_operations($pdo, array_keys($machines)), calculator_rfq_operations($pdo));
    $baseline = baseline_bundle($pdo);
    $ctx = calc_context($pdo);
    $mode = reference_mode($pdo);
    $baseSettings = calc_settings($pdo, $year);
    $volumeMap = $ctx['opVolumes'][$year] ?? [];
    $shares = calc_allocation_shares($volumeMap, $ctx['parentById'], $ctx['childrenByParent'], $year, $month, $baseSettings, $week);
    $totalRequired = 0.0;
    $totalAvailability = 0.0;
    $clients = [];
    foreach ($machines as $machineId => $machine) {
        $machineOee = isset($machine['oee_override']) && is_numeric($machine['oee_override']) ? (float) $machine['oee_override'] : null;
        $isBaseline = (int) ($machine['is_baseline'] ?? 0) === 1;
        $settings = baseline_volume_settings($baseSettings, $machine);
        $availability = calc_machine_availability($machine, $settings, $machineOee);
        $totalAvailability += $availability;
        $machineOps = $isBaseline ? ($baseline['ops'][$machineId] ?? []) : ($ops[$machineId] ?? []);
        foreach ($machineOps as $op) {
            $resolved = calc_resolve_operation($op, $year, $month, $ctx, $volumeMap, $shares, $settings, $contract, $week);
            if ($resolved === null || !calc_active_in_period($op, $year, $month, $resolved['count_after_eop'])) {
                continue;
            }
            $weekly = calc_weekly_volume($resolved['volume_value'], $resolved['volume_unit'], $settings, $op, $year, $resolved['volume_origin'], $resolved['count_after_eop']);
            $seconds = calc_operation_demand($machine, $op, $weekly, $baseline['materials'])['seconds'];
            if ($seconds <= 1e-9) {
                continue;
            }
            $totalRequired += $seconds;
            $client = trim((string) ($op['project_client'] ?? ''));
            $client = $client !== '' ? $client : '—';
            $projectId = (int) $op['project_id'];
            $projectName = trim((string) ($op['project_name'] ?? ''));
            $projectName = $projectName !== '' ? $projectName : '—';
            $label = detail_label([
                'sap_number' => $op['detail_sap_number'] ?? null,
                'alias' => $op['detail_alias'] ?? null,
                'free_text' => $op['detail_free_text'] ?? null,
                'designation' => $op['detail_designation'] ?? null,
                'id' => $op['part_id'],
            ], $mode);
            if (!isset($clients[$client])) {
                $clients[$client] = ['required' => 0.0, 'projects' => []];
            }
            $clients[$client]['required'] += $seconds;
            if (!isset($clients[$client]['projects'][$projectId])) {
                $clients[$client]['projects'][$projectId] = ['project_name' => $projectName, 'required' => 0.0, 'details' => []];
            }
            $clients[$client]['projects'][$projectId]['required'] += $seconds;
            if (!isset($clients[$client]['projects'][$projectId]['details'][$label])) {
                $clients[$client]['projects'][$projectId]['details'][$label] = ['required' => 0.0, 'has_rfq' => false];
            }
            $clients[$client]['projects'][$projectId]['details'][$label]['required'] += $seconds;
            if (strtoupper((string) ($op['project_status'] ?? '')) === 'RFQ') {
                $clients[$client]['projects'][$projectId]['details'][$label]['has_rfq'] = true;
            }
        }
        foreach (baseline_external_rows($machine, $year, $baseline) as $materialRow) {
            $totalRequired += (float) $materialRow['required_seconds'];
        }
    }
    $toLoad = function (float $seconds) use ($totalAvailability): float {
        if ($totalAvailability <= 0) {
            return $seconds > 0 ? 100.0 : 0.0;
        }
        return round(($seconds / $totalAvailability) * 10000) / 100;
    };
    $toShare = function (float $seconds) use ($totalRequired): float {
        return $totalRequired > 0 ? round(($seconds / $totalRequired) * 10000) / 100 : 0.0;
    };
    $clientRows = [];
    foreach ($clients as $client => $node) {
        $projects = [];
        foreach ($node['projects'] as $projectId => $project) {
            $details = [];
            foreach ($project['details'] as $label => $detail) {
                $details[] = [
                    'detail_label' => $label,
                    'load_percent' => $toLoad($detail['required']),
                    'share_percent' => $toShare($detail['required']),
                    'has_rfq' => $detail['has_rfq'],
                ];
            }
            usort($details, fn(array $a, array $b): int => $b['load_percent'] <=> $a['load_percent']);
            $projects[] = [
                'project_id' => $projectId,
                'project_name' => $project['project_name'],
                'load_percent' => $toLoad($project['required']),
                'share_percent' => $toShare($project['required']),
                'details' => $details,
            ];
        }
        usort($projects, fn(array $a, array $b): int => $b['load_percent'] <=> $a['load_percent']);
        $clientRows[] = [
            'client' => $client,
            'load_percent' => $toLoad($node['required']),
            'share_percent' => $toShare($node['required']),
            'projects' => $projects,
        ];
    }
    usort($clientRows, fn(array $a, array $b): int => $b['load_percent'] <=> $a['load_percent']);
    $load = null;
    if ($totalAvailability > 0) {
        $load = (int) round(($totalRequired / $totalAvailability) * 100);
    } elseif ($totalRequired > 0) {
        $load = 100;
    }
    return ['load_percent' => $load, 'clients' => $clientRows];
}

function calculator_breakdown(PDO $pdo): array
{
    $year = (int) ($_GET['year'] ?? 0);
    if ($year < 2000 || $year > 2100) {
        json_response(400, ['error' => 'Invalid year']);
    }
    $month = null;
    if (isset($_GET['month']) && $_GET['month'] !== '') {
        $month = (int) $_GET['month'];
        if ($month < 1 || $month > 12) {
            $month = null;
        }
    }
    $week = null;
    if (isset($_GET['week']) && $_GET['week'] !== '') {
        $week = (int) $_GET['week'];
        if ($week < 1 || $week > 6) {
            $week = null;
        }
    }
    if ($week !== null && $month === null) {
        json_response(400, ['error' => 'week requires month']);
    }
    $line = trim((string) ($_GET['line'] ?? ''));
    $hasMachine = isset($_GET['machineId']) && $_GET['machineId'] !== '';
    $machineId = $hasMachine ? (int) $_GET['machineId'] : 0;
    if ($line !== '' && $hasMachine) {
        json_response(400, ['error' => 'Provide line or machineId, not both']);
    }
    if ($line === '' && !$hasMachine) {
        json_response(400, ['error' => 'Provide line or machineId']);
    }
    $allowed = ['production', 'contract', 'scenario_production', 'scenario_contract', 'call_off'];
    $keys = [];
    foreach (preg_split('/[,;]+/', (string) ($_GET['series'] ?? '')) ?: [] as $part) {
        $part = trim($part);
        if (in_array($part, $allowed, true) && !in_array($part, $keys, true)) {
            $keys[] = $part;
        }
    }
    if ($keys === []) {
        json_response(400, ['error' => 'Missing series parameter']);
    }
    $scenarioId = (int) ($_GET['scenarioId'] ?? 0);
    $source = $scenarioId > 0 ? calculator_source($pdo) : $pdo;
    $types = calc_csv_list('types') ?: calc_csv_list('type');
    $onlyIds = calculator_scope_ids($source);
    if ($hasMachine) {
        $onlyIds = $onlyIds === null ? [$machineId] : array_values(array_intersect($onlyIds, [$machineId]));
    }
    $machines = calculator_filtered_machines($source, calculator_statuses(), $types, $onlyIds);
    if ($line !== '') {
        $machines = array_filter($machines, fn(array $machine): bool => calculator_line_key($machine['location'] ?? null) === $line);
    }
    $series = [];
    $production = null;
    $contract = null;
    foreach ($keys as $key) {
        if ($key === 'production' || $key === 'scenario_production') {
            $production ??= calculator_breakdown_series($source, $machines, $year, $month, $week, false);
            $series[$key] = $production;
        } elseif ($key === 'contract' || $key === 'scenario_contract') {
            $contract ??= calculator_breakdown_series($source, $machines, $year, $month, $week, true);
            $series[$key] = $contract;
        } elseif ($key === 'call_off') {
            $series[$key] = calloff_breakdown($source, $machines, $year, $month);
        } else {
            $series[$key] = ['load_percent' => null, 'clients' => []];
        }
    }
    return ['year' => $year, 'month' => $month, 'week' => $week, 'series' => $series];
}
