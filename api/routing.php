<?php
declare(strict_types=1);

require_once __DIR__ . '/baseline.php';

function routing_norm_sap(string $value): string
{
    $text = trim($value);
    $text = preg_replace('/\s+/', '', $text) ?? $text;
    return preg_replace('/\.0+$/', '', $text) ?? $text;
}

function routing_parse_eu_number(string $raw): ?float
{
    $text = trim($raw);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $text) === 1 || preg_match('/^\d+,\d+$/', $text) === 1) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
    } elseif (str_contains($text, ',') && !str_contains($text, '.')) {
        $text = str_replace(',', '.', $text);
    }
    if (!is_numeric($text)) {
        return null;
    }
    return (float) $text;
}

function routing_parse_dimensions(string $description): ?array
{
    if (preg_match('/(\d{2,5})\s*[xX×]\s*(\d{2,5})/', $description, $match) !== 1) {
        return null;
    }
    $length = (float) $match[1];
    $width = (float) $match[2];
    if ($length <= 0 || $width <= 0) {
        return null;
    }
    return ['length' => $length, 'width' => $width, 'area' => $length * $width];
}

function routing_alias_from_description(?string $description): ?string
{
    $excluded = ['HIGH' => true, 'LOW' => true, 'INNER' => true, 'OUTER' => true, 'DASH' => true, 'RH' => true, 'LH' => true];
    $text = strtoupper((string) $description);
    if (preg_match_all('/\b[A-Z0-9]{3,10}\b/', $text, $match) < 1) {
        return null;
    }
    $aliases = [];
    foreach ($match[0] as $token) {
        if (preg_match('/[A-Z]/', $token) === 1 && preg_match('/\d/', $token) === 1 && !isset($excluded[$token])) {
            $aliases[] = $token;
        }
    }
    return $aliases === [] ? null : $aliases[array_key_last($aliases)];
}

function routing_grammage(string $code, string $description): ?float
{
    $text = $code . ' ' . $description;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*kg(?:\s*\/?\s*m(?:2|²))?/i', $text, $match) === 1) {
        return routing_parse_eu_number($match[1]);
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*g(?:\s*\/?\s*m(?:2|²))?/i', $text, $match) === 1) {
        $grams = routing_parse_eu_number($match[1]);
        return $grams === null ? null : $grams / 1000;
    }
    return null;
}

function routing_peek_bom_meta(array $lines, int $fromIndex): array
{
    $limit = min(count($lines), $fromIndex + 8);
    for ($i = $fromIndex; $i < $limit; $i++) {
        $line = str_replace("\u{00A0}", ' ', $lines[$i]);
        if ($i > $fromIndex && preg_match('/^\s*(Material\s+\d+|Mat\.\s*Comp\.|Sequence|Operation)\b/i', $line) === 1) {
            break;
        }
        if (preg_match('/\bAlt\.\s*(\d+)\b/i', $line, $altMatch) !== 1) {
            continue;
        }
        $alt = (int) $altMatch[1];
        $quantity = null;
        if (preg_match('/\bQty\s+([\d.,]+)/i', $line, $qtyMatch) === 1) {
            $parsed = routing_parse_eu_number($qtyMatch[1]);
            if ($parsed !== null && $parsed >= 0) {
                $quantity = $parsed / 1000;
            }
        }
        return ['alt' => $alt, 'quantityPerDetail' => $quantity];
    }
    return ['alt' => null, 'quantityPerDetail' => null];
}

function routing_decode_text(string $raw): string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $utf8Ok = preg_match('//u', $raw) === 1;
    if ($utf8Ok && !str_contains($raw, "\u{FFFD}") && preg_match('/Material\s+\d+/', $raw) === 1) {
        return $raw;
    }
    if (function_exists('iconv')) {
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $raw);
        if (is_string($converted) && $converted !== '' && preg_match('/Material\s+\d+/', $converted) === 1) {
            return $converted;
        }
    }
    return $raw;
}

function routing_parse_text(string $text): array
{
    $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
    $byFinishedGood = [];
    $capacityMaterials = [];
    $seenInFg = [];
    $currentFg = null;
    $finishedGoods = 0;

    foreach ($lines as $index => $original) {
        $line = str_replace("\u{00A0}", ' ', $original);
        if (preg_match('/^Material\s+(\d+)\b/i', $line, $mat) === 1) {
            $currentFg = routing_norm_sap($mat[1]);
            if ($currentFg !== '' && !isset($byFinishedGood[$currentFg])) {
                $byFinishedGood[$currentFg] = [];
                $seenInFg[$currentFg] = [];
                $finishedGoods++;
            }
            continue;
        }
        if ($currentFg === null || $currentFg === '') {
            continue;
        }
        if (preg_match('/^\s*Operation\b.*\bWork\s+Ctr\s+B03-002\b.*\bProdukcja\s+HL\b/i', $line) === 1) {
            $capacityMaterials[$currentFg] = true;
        }
        if (preg_match('/^\s*Mat\.\s*Comp\.\s+(\d+)\s+(\S+)\s*(.*)$/i', $line, $comp) !== 1) {
            continue;
        }
        $materialNumber = routing_norm_sap($comp[1]);
        $code = strtoupper(trim($comp[2]));
        $description = trim($comp[3]);
        if ($materialNumber === '' || $code === '') {
            continue;
        }
        $bomMeta = routing_peek_bom_meta($lines, $index + 1);
        $bomAlt = $bomMeta['alt'];
        if ($bomAlt !== null && $bomAlt !== 1) {
            continue;
        }
        $dedupeKey = $materialNumber . '|' . $code;
        if (isset($seenInFg[$currentFg][$dedupeKey])) {
            continue;
        }
        $seenInFg[$currentFg][$dedupeKey] = true;
        $dims = routing_parse_dimensions($code . ' ' . $description);
        $byFinishedGood[$currentFg][] = [
            'materialNumber' => $materialNumber,
            'code' => $code,
            'description' => $description,
            'bomQuantityPerDetail' => $bomMeta['quantityPerDetail'],
            'length' => $dims['length'] ?? null,
            'width' => $dims['width'] ?? null,
        ];
    }

    if ($byFinishedGood === []) {
        throw new RuntimeException('Routing SAP: nie znaleziono żadnego nagłówka „Material …”.');
    }

    return [
        'byFinishedGood' => $byFinishedGood,
        'capacityMaterials' => $capacityMaterials,
        'finishedGoods' => $finishedGoods,
    ];
}

function routing_components_via_subassemblies(array $index, string $finishedGoodSap, string $codePrefix, array $subassemblyPrefixes, int $maxDepth = 4): array
{
    $root = routing_norm_sap($finishedGoodSap);
    $prefix = strtoupper(trim($codePrefix));
    if ($root === '' || $prefix === '') {
        return [];
    }
    $subPrefixes = [];
    foreach ($subassemblyPrefixes as $item) {
        $value = strtoupper(trim((string) $item));
        if ($value !== '') {
            $subPrefixes[] = $value;
        }
    }
    $byFinishedGood = $index['byFinishedGood'];
    $found = [];
    $foundKeys = [];
    $visited = [$root => true];
    $queue = [['sap' => $root, 'depth' => 0, 'quantityFactor' => 1.0]];
    while ($queue !== []) {
        $current = array_shift($queue);
        foreach ($byFinishedGood[$current['sap']] ?? [] as $component) {
            $code = $component['code'];
            if ($code === $prefix || str_starts_with($code, $prefix)) {
                $key = $component['materialNumber'] . '|' . $code;
                if (!isset($foundKeys[$key])) {
                    $foundKeys[$key] = true;
                    $quantity = $component['bomQuantityPerDetail'];
                    $component['bomQuantityPerDetail'] = ($quantity ?? 1.0) * $current['quantityFactor'];
                    $found[] = $component;
                }
                continue;
            }
            if ($current['depth'] + 1 >= $maxDepth) {
                continue;
            }
            $isSub = false;
            foreach ($subPrefixes as $subPrefix) {
                if ($code === $subPrefix || str_starts_with($code, $subPrefix)) {
                    $isSub = true;
                    break;
                }
            }
            if (!$isSub) {
                continue;
            }
            $child = routing_norm_sap($component['materialNumber']);
            if ($child === '' || isset($visited[$child]) || !isset($byFinishedGood[$child])) {
                continue;
            }
            $visited[$child] = true;
            $quantity = $component['bomQuantityPerDetail'];
            $queue[] = [
                'sap' => $child,
                'depth' => $current['depth'] + 1,
                'quantityFactor' => $current['quantityFactor'] * ($quantity ?? 1.0),
            ];
        }
    }
    return $found;
}

function routing_import_materials(PDO $pdo, int $machineId): void
{
    require_permission($pdo, 'machines.edit');
    $check = $pdo->prepare(
        'SELECT id, is_baseline, baseline_max_blank_width_mm, baseline_max_blank_width_unit, baseline_min_length_mm
         FROM machines WHERE id = ?'
    );
    $check->execute([$machineId]);
    $machine = $check->fetch();
    if (!$machine) {
        json_response(404, ['error' => 'Not found']);
    }
    if ((int) $machine['is_baseline'] !== 1) {
        json_response(400, ['error' => 'Materiały są dostępne tylko dla maszyny bazowej.']);
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(400, ['error' => 'Brak pliku routing.txt.']);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_response(400, ['error' => 'Brak pliku routing.txt.']);
    }
    $raw = file_get_contents($tmp);
    if ($raw === false || $raw === '') {
        json_response(400, ['error' => 'Brak pliku routing.txt.']);
    }

    try {
        $routing = routing_parse_text(routing_decode_text($raw));
    } catch (RuntimeException $error) {
        json_response(400, ['error' => $error->getMessage()]);
    }

    $designationRows = $pdo->query(
        "SELECT id, sap_number FROM part_designations
         WHERE sap_number IS NOT NULL AND TRIM(CAST(sap_number AS TEXT)) != ''"
    )->fetchAll();
    $existing = $pdo->prepare('SELECT id, sap_number FROM machine_baseline_materials WHERE machine_id = ?');
    $existing->execute([$machineId]);
    $materialIdBySap = [];
    foreach ($existing as $row) {
        $sap = routing_norm_sap((string) ($row['sap_number'] ?? ''));
        if ($sap !== '') {
            $materialIdBySap[$sap] = (int) $row['id'];
        }
    }

    $insertMaterial = $pdo->prepare(
        'INSERT INTO machine_baseline_materials
            (machine_id, alias, description, sap_number, grammage_kg_m2, width_mm, length_mm,
             include_in_capacity, production_share_percent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 100)'
    );
    $updateMaterial = $pdo->prepare(
        'UPDATE machine_baseline_materials
         SET alias = ?, description = ?, grammage_kg_m2 = ?, width_mm = ?, length_mm = ?,
             include_in_capacity = ?
         WHERE id = ? AND machine_id = ?'
    );
    $insertLink = $pdo->prepare(
        'INSERT INTO machine_baseline_material_parts
            (material_id, designation_id, consumption_per_detail)
         VALUES (?, ?, ?)
         ON CONFLICT(material_id, designation_id) DO UPDATE SET
            consumption_per_detail = excluded.consumption_per_detail'
    );

    $matchedDetails = 0;
    $unmatchedDetails = 0;
    $detailsWithoutMaterial = 0;
    $created = 0;
    $updated = 0;
    $linksCreated = 0;
    $seenDetailMaterial = [];

    $pdo->beginTransaction();
    try {
        foreach ($designationRows as $designation) {
            $detailSap = routing_norm_sap((string) ($designation['sap_number'] ?? ''));
            if ($detailSap === '' || !isset($routing['byFinishedGood'][$detailSap])) {
                $unmatchedDetails++;
                continue;
            }
            $matchedDetails++;
            $components = routing_components_via_subassemblies($routing, $detailSap, 'S2102', ['C21Q1']);
            if ($components === []) {
                $detailsWithoutMaterial++;
            }
            foreach ($components as $component) {
                $materialSap = routing_norm_sap($component['materialNumber']);
                if ($materialSap === '') {
                    continue;
                }
                $width = $component['width'];
                $length = $component['length'];
                if ($width !== null && $length !== null) {
                    $oriented = baseline_orient($machine, (float) $width, (float) $length);
                    $width = $oriented['widthMm'];
                    $length = $oriented['lengthMm'];
                }
                $grammage = routing_grammage($component['code'], $component['description']);
                $include = isset($routing['capacityMaterials'][$materialSap]) ? 1 : 0;
                $alias = routing_alias_from_description($component['description']);
                $description = $component['description'] !== '' ? $component['description'] : null;
                if (!isset($materialIdBySap[$materialSap])) {
                    $insertMaterial->execute([
                        $machineId,
                        $alias,
                        $description,
                        $materialSap,
                        $grammage,
                        $width,
                        $length,
                        $include,
                    ]);
                    $materialId = (int) $pdo->lastInsertId();
                    $materialIdBySap[$materialSap] = $materialId;
                    $created++;
                } else {
                    $materialId = $materialIdBySap[$materialSap];
                    $updateMaterial->execute([
                        $alias,
                        $description,
                        $grammage,
                        $width,
                        $length,
                        $include,
                        $materialId,
                        $machineId,
                    ]);
                    $updated++;
                }
                $linkKey = $materialId . '|' . (int) $designation['id'];
                if (isset($seenDetailMaterial[$linkKey])) {
                    continue;
                }
                $seenDetailMaterial[$linkKey] = true;
                $quantity = $component['bomQuantityPerDetail'];
                $consumption = $quantity !== null && $quantity >= 0 ? $quantity : 1.0;
                $insertLink->execute([$materialId, (int) $designation['id'], $consumption]);
                $linksCreated += $insertLink->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(400, ['error' => 'Nie udało się zaimportować pliku routing.']);
    }

    json_response(200, [
        'routing_finished_goods' => $routing['finishedGoods'],
        'matched_details' => $matchedDetails,
        'unmatched_details' => $unmatchedDetails,
        'details_without_material' => $detailsWithoutMaterial,
        'materials_created' => $created,
        'materials_updated' => $updated,
        'links_created' => $linksCreated,
    ]);
}
