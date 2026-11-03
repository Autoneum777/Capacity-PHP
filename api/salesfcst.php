<?php
declare(strict_types=1);

require_once __DIR__ . '/bundle_import.php';

const SALESFCST_COL_DATE = 26;
const SALESFCST_COL_QTY = 27;
const SALESFCST_COL_SAP = 30;

function salesfcst_normalize_ref(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || (is_float($value) && abs($value - round($value)) < 1e-9)) {
        $raw = (string) (int) round((float) $value);
    } else {
        $raw = trim((string) $value);
        $raw = preg_replace('/\s*\(\d+\)\s*$/', '', $raw) ?? $raw;
        $raw = trim($raw);
        if (preg_match('/^(-?\d+)\.0+$/', $raw, $match) === 1) {
            $raw = $match[1];
        } elseif (is_numeric($raw)) {
            $number = (float) $raw;
            if (abs($number - round($number)) < 1e-9) {
                $raw = (string) (int) round($number);
            }
        }
    }
    $stripped = ltrim($raw, '0');
    return $stripped === '' ? ($raw === '' ? '' : '0') : $stripped;
}

function salesfcst_truncate_ref(string $ref): ?string
{
    $normalized = salesfcst_normalize_ref($ref);
    if ($normalized === '' || $normalized === '0' || strlen($normalized) <= 2) {
        return null;
    }
    $truncated = substr($normalized, 0, -2);
    return $truncated !== '' ? $truncated : null;
}

function salesfcst_excel_date(float $serial): ?DateTimeImmutable
{
    $days = (int) floor($serial) - 25569;
    if ($days < -20000 || $days > 80000) {
        return null;
    }
    $date = new DateTimeImmutable('1970-01-01');
    $shifted = $date->modify(($days >= 0 ? '+' : '') . $days . ' days');
    return $shifted instanceof DateTimeImmutable ? $shifted : null;
}

function salesfcst_parse_date(mixed $value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeImmutable) {
        return $value;
    }
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return salesfcst_excel_date((float) $value);
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (is_numeric($text) && (float) $text > 20000) {
        return salesfcst_excel_date((float) $text);
    }
    foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $text);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }
    $timestamp = strtotime($text);
    if ($timestamp === false) {
        return null;
    }
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
}

function salesfcst_date_key(DateTimeImmutable $date): string
{
    return $date->format('Y-m-d');
}

function salesfcst_qty(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (!is_numeric($value)) {
        return 0.0;
    }
    return (float) $value;
}

function salesfcst_first_sheet_xml(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Niepoprawny plik Excel.');
    }
    $shared = bundle_shared_strings($zip);
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbook) || !is_string($rels)) {
        $zip->close();
        throw new RuntimeException('Plik Excel nie zawiera arkuszy');
    }
    $targets = [];
    $relDom = new DOMDocument();
    $relDom->loadXML($rels);
    foreach ($relDom->getElementsByTagName('Relationship') as $rel) {
        $target = ltrim(str_replace('\\', '/', $rel->getAttribute('Target')), '/');
        if (!str_starts_with($target, 'xl/')) {
            $target = 'xl/' . $target;
        }
        $targets[$rel->getAttribute('Id')] = $target;
    }
    $bookDom = new DOMDocument();
    $bookDom->loadXML($workbook);
    $sheet = $bookDom->getElementsByTagName('sheet')->item(0);
    if (!$sheet instanceof DOMElement) {
        $zip->close();
        throw new RuntimeException('Plik Excel nie zawiera arkuszy');
    }
    $id = $sheet->getAttribute('id');
    if ($id === '') {
        $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
    }
    $target = $targets[$id] ?? '';
    $xml = $target !== '' ? $zip->getFromName($target) : false;
    $zip->close();
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('Plik Excel nie zawiera arkuszy');
    }
    return ['xml' => $xml, 'shared' => $shared];
}

function salesfcst_matrix(string $xml, array $shared): array
{
    $reader = XMLReader::XML($xml);
    if ($reader === false) {
        return [];
    }
    $rows = [];
    $currentRow = null;
    $cells = [];
    $push = function () use (&$rows, &$currentRow, &$cells): void {
        if ($currentRow !== null && $cells !== []) {
            $rows[$currentRow] = $cells;
        }
    };
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
            continue;
        }
        $ref = (string) $reader->getAttribute('r');
        $type = (string) $reader->getAttribute('t');
        $outer = $reader->readOuterXml();
        $rowNumber = bundle_row_number($ref);
        if ($currentRow === null) {
            $currentRow = $rowNumber;
        }
        if ($rowNumber !== $currentRow) {
            $push();
            $currentRow = $rowNumber;
            $cells = [];
        }
        $cells[bundle_col_index($ref)] = bundle_cell_value($outer, $type, $shared);
    }
    $push();
    $reader->close();
    ksort($rows);
    return $rows;
}

function salesfcst_rows_from_file(string $path): array
{
    $sheet = salesfcst_first_sheet_xml($path);
    return salesfcst_matrix($sheet['xml'], $sheet['shared']);
}

function salesfcst_detect_range(array $rows): array
{
    $min = null;
    $max = null;
    foreach ($rows as $number => $row) {
        if ((int) $number <= 1 || !is_array($row)) {
            continue;
        }
        $sap = salesfcst_normalize_ref($row[SALESFCST_COL_SAP] ?? null);
        if ($sap === '' || $sap === '0') {
            continue;
        }
        $date = salesfcst_parse_date($row[SALESFCST_COL_DATE] ?? null);
        if ($date === null || salesfcst_qty($row[SALESFCST_COL_QTY] ?? null) <= 0) {
            continue;
        }
        $key = salesfcst_date_key($date);
        if ($min === null || $key < $min) {
            $min = $key;
        }
        if ($max === null || $key > $max) {
            $max = $key;
        }
    }
    if ($min === null || $max === null) {
        throw new RuntimeException('Nie znaleziono w pliku żadnych wierszy z datą i ilością > 0');
    }
    return ['date_from' => $min, 'date_to' => $max];
}

function salesfcst_parse_rows(array $rows, string $dateFrom, string $dateTo): array
{
    $parsed = [];
    $skippedOutOfRange = 0;
    $skippedInvalid = 0;
    foreach ($rows as $number => $row) {
        if ((int) $number <= 1 || !is_array($row)) {
            continue;
        }
        $sap = salesfcst_normalize_ref($row[SALESFCST_COL_SAP] ?? null);
        if ($sap === '' || $sap === '0') {
            $skippedInvalid++;
            continue;
        }
        $date = salesfcst_parse_date($row[SALESFCST_COL_DATE] ?? null);
        if ($date === null) {
            $skippedInvalid++;
            continue;
        }
        $key = salesfcst_date_key($date);
        if ($key < $dateFrom || $key > $dateTo) {
            $skippedOutOfRange++;
            continue;
        }
        $quantity = salesfcst_qty($row[SALESFCST_COL_QTY] ?? null);
        if ($quantity <= 0) {
            continue;
        }
        $parsed[] = [
            'sap_ref' => $sap,
            'volume_date' => $key,
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'week' => (int) $date->format('W'),
            'quantity' => $quantity,
        ];
    }
    return [
        'rows' => $parsed,
        'skippedOutOfRange' => $skippedOutOfRange,
        'skippedInvalid' => $skippedInvalid,
    ];
}

function salesfcst_lookup(PDO $pdo): array
{
    $exact = [];
    $truncated = [];
    $stmt = $pdo->query(
        'SELECT p.id AS part_id, d.sap_number
         FROM parts p
         JOIN part_designations d ON d.id = p.designation_id
         WHERE d.sap_number IS NOT NULL AND TRIM(d.sap_number) <> \'\''
    );
    foreach ($stmt as $row) {
        $full = salesfcst_normalize_ref($row['sap_number']);
        if ($full === '' || $full === '0') {
            continue;
        }
        $partId = (int) $row['part_id'];
        $exact[$full][$partId] = $partId;
        $short = salesfcst_truncate_ref($full);
        if ($short !== null) {
            $truncated[$short][$partId] = $partId;
        }
    }
    return ['exact' => $exact, 'truncated' => $truncated];
}

function salesfcst_resolve(array $lookup, string $sap): ?array
{
    $normalized = salesfcst_normalize_ref($sap);
    if (isset($lookup['exact'][$normalized])) {
        return ['part_ids' => array_values($lookup['exact'][$normalized]), 'match' => 'exact'];
    }
    $short = salesfcst_truncate_ref($normalized);
    if ($short !== null && isset($lookup['truncated'][$short])) {
        return ['part_ids' => array_values($lookup['truncated'][$short]), 'match' => 'truncated'];
    }
    return null;
}

function salesfcst_storage_dir(int $comparisonId): string
{
    return dirname(database_path()) . DIRECTORY_SEPARATOR . 'call-offs' . DIRECTORY_SEPARATOR . $comparisonId;
}

function salesfcst_delete_files(int $comparisonId): void
{
    $dir = salesfcst_storage_dir($comparisonId);
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function salesfcst_safe_filename(string $name): string
{
    $base = basename(str_replace('\\', '/', $name));
    $clean = preg_replace('/[^\w.\- ()ąćęłńóśźżĄĆĘŁŃÓŚŹŻ+]/u', '_', $base) ?? '';
    $clean = substr($clean, 0, 180);
    return $clean !== '' ? $clean : 'SalesFcst.xlsx';
}

function salesfcst_save_source(int $comparisonId, string $tmp, string $original): string
{
    salesfcst_delete_files($comparisonId);
    $dir = salesfcst_storage_dir($comparisonId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie udało się zapisać pliku Sales Forecast.');
    }
    $stored = (string) (int) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(4)) . '_' . salesfcst_safe_filename($original);
    if (!copy($tmp, $dir . DIRECTORY_SEPARATOR . $stored)) {
        throw new RuntimeException('Nie udało się zapisać pliku Sales Forecast.');
    }
    return $stored;
}

function salesfcst_save_unmatched(int $comparisonId, array $rows): void
{
    $dir = salesfcst_storage_dir($comparisonId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie udało się zapisać raportu niedopasowanych referencji.');
    }
    $lines = ["\u{FEFF}sap_ref;row_count;total_quantity"];
    foreach ($rows as $row) {
        $lines[] = $row['sap_ref'] . ';' . $row['row_count'] . ';' . $row['total_quantity'];
    }
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'unmatched-sap-report.csv', implode("\n", $lines));
}

function salesfcst_source_path(int $comparisonId, ?string $stored): ?string
{
    $base = basename(str_replace('\\', '/', trim((string) $stored)));
    if ($base === '') {
        return null;
    }
    $path = salesfcst_storage_dir($comparisonId) . DIRECTORY_SEPARATOR . $base;
    return is_file($path) ? $path : null;
}

function salesfcst_unmatched_path(int $comparisonId): ?string
{
    $path = salesfcst_storage_dir($comparisonId) . DIRECTORY_SEPARATOR . 'unmatched-sap-report.csv';
    return is_file($path) ? $path : null;
}

function salesfcst_import(PDO $pdo, int $comparisonId, string $tmp, string $filename, string $dateFrom, string $dateTo, ?array $rows = null): array
{
    if ($rows === null) {
        $rows = salesfcst_rows_from_file($tmp);
    }
    $parsed = salesfcst_parse_rows($rows, $dateFrom, $dateTo);
    $lookup = salesfcst_lookup($pdo);
    $pdo->prepare('DELETE FROM call_off_volumes WHERE comparison_id = ?')->execute([$comparisonId]);
    $insert = $pdo->prepare(
        'INSERT INTO call_off_volumes (comparison_id, sap_ref, part_id, volume_date, year, month, week, quantity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $imported = 0;
    $matchedExact = 0;
    $matchedTruncated = 0;
    $unmatched = [];
    foreach ($parsed['rows'] as $row) {
        $resolved = salesfcst_resolve($lookup, $row['sap_ref']);
        if ($resolved === null) {
            if (!isset($unmatched[$row['sap_ref']])) {
                $unmatched[$row['sap_ref']] = ['row_count' => 0, 'total_quantity' => 0.0];
            }
            $unmatched[$row['sap_ref']]['row_count']++;
            $unmatched[$row['sap_ref']]['total_quantity'] += $row['quantity'];
            $insert->execute([
                $comparisonId, $row['sap_ref'], null, $row['volume_date'], $row['year'], $row['month'], $row['week'], $row['quantity'],
            ]);
        } else {
            if ($resolved['match'] === 'exact') {
                $matchedExact++;
            } else {
                $matchedTruncated++;
            }
            foreach ($resolved['part_ids'] as $partId) {
                $insert->execute([
                    $comparisonId, $row['sap_ref'], $partId, $row['volume_date'], $row['year'], $row['month'], $row['week'], $row['quantity'],
                ]);
            }
        }
        $imported++;
    }
    $report = [];
    foreach ($unmatched as $sap => $stat) {
        $report[] = [
            'sap_ref' => (string) $sap,
            'row_count' => $stat['row_count'],
            'total_quantity' => round($stat['total_quantity'], 3),
        ];
    }
    usort($report, fn(array $a, array $b): int => strnatcmp((string) $a['sap_ref'], (string) $b['sap_ref']));
    $stored = salesfcst_save_source($comparisonId, $tmp, $filename);
    salesfcst_save_unmatched($comparisonId, $report);
    $result = [
        'imported' => $imported,
        'skippedOutOfRange' => $parsed['skippedOutOfRange'],
        'skippedInvalid' => $parsed['skippedInvalid'],
        'unmatchedSap' => count($report),
        'matchedExact' => $matchedExact,
        'matchedTruncated' => $matchedTruncated,
        'unmatchedReport' => $report,
    ];
    $pdo->prepare(
        'UPDATE call_off_comparisons
         SET source_filename = ?, source_stored_filename = ?, last_import_json = ?, updated_at = ?
         WHERE id = ?'
    )->execute([
        $filename,
        $stored,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        date('Y-m-d H:i:s'),
        $comparisonId,
    ]);
    return $result;
}
