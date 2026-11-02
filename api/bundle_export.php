<?php
declare(strict_types=1);

require_once __DIR__ . '/bundle_import.php';
require_once __DIR__ . '/data_import.php';

/*
 * "Pobierz paczkę ZIP (szablon)" — strona eksportu do dotąd istniejącego tylko-importowego
 * mechanizmu „Paczka capacity” (bundle_import.php). Format pliku jest w 100% zgodny z tym,
 * co bundle_import_package()/bundle_patch_scenario_files()/bundle_extract_call_offs() już
 * umieją wczytać: .zip zawierający jeden plik .xlsx (arkusz na tabelę, bez kont/ról/haseł),
 * MANIFEST.json wskazujący nazwę tego pliku, scenarios/<id>.json dla snapshotów scenariuszy
 * (komórka w arkuszu "scenarios" dostaje marker __FILE__:scenarios/<id>.json) oraz katalog
 * call-offs/ z plikami źródłowymi porównań Call-offs.
 */

const BUNDLE_EXPORT_EXCEL_NAME = 'capacity_baza_szablon.xlsx';
const BUNDLE_EXPORT_FULL_FILENAME = 'capacity_baza_szablon.zip';
const BUNDLE_EXPORT_PARTIAL_FILENAME = 'capacity_baza_szablon_wybrane.zip';

function handle_bundle_export(string $method, string $path, PDO $pdo): bool
{
    if ($method !== 'GET' || $path !== '/admin/capacity-bundle-template.xlsx') {
        return false;
    }
    require_permission($pdo, 'admin_database.view');
    @set_time_limit(300);
    $only = bundle_only_tables($_GET['onlyTables'] ?? null);
    try {
        [$bytes, $partial] = bundle_export_build($pdo, $only);
    } catch (Throwable $e) {
        json_response(500, ['error' => $e->getMessage()]);
    }
    $filename = $partial ? BUNDLE_EXPORT_PARTIAL_FILENAME : BUNDLE_EXPORT_FULL_FILENAME;
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    return true;
}

/** @return array{0: string, 1: bool} [bajty .zip, czy eksport był częściowy] */
function bundle_export_build(PDO $pdo, array $only): array
{
    $order = bundle_import_order($pdo);
    $allTables = bundle_without_auth($order);
    $partial = $only !== [];
    if ($partial) {
        $requested = array_flip($only);
        $tables = array_values(array_filter($allTables, static fn(string $n): bool => isset($requested[$n])));
        if ($tables === []) {
            // Nieznane/nieobecne nazwy tabel w eksporcie częściowym — zamiast błędu, eksportuj pełną paczkę.
            $tables = $allTables;
            $partial = false;
        }
    } else {
        $tables = $allTables;
    }

    $scenarioFiles = [];
    $sheets = [];
    foreach ($tables as $table) {
        $cols = bundle_columns($pdo, $table);
        if ($cols === []) {
            continue;
        }
        $rows = [];
        foreach ($pdo->query('SELECT * FROM ' . bundle_ident($table)) as $dbRow) {
            $row = [];
            foreach ($cols as $col) {
                $value = $dbRow[$col] ?? null;
                if ($table === 'scenarios' && $col === 'snapshot' && is_string($value) && $value !== '') {
                    $zipPath = 'scenarios/' . (string) ($dbRow['id'] ?? (count($scenarioFiles) + 1)) . '.json';
                    $scenarioFiles[$zipPath] = json_encode(['snapshot' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $value = '__FILE__:' . $zipPath;
                }
                $row[] = $value;
            }
            $rows[] = $row;
        }
        $sheetName = strlen($table) > 31 ? substr($table, 0, 31) : $table;
        $sheets[] = ['name' => $sheetName, 'headers' => $cols, 'rows' => $rows];
    }
    $sheets[] = ['name' => BUNDLE_README_SHEET, 'headers' => ['Instrukcja'], 'rows' => bundle_export_instruction_rows($partial, $tables)];

    $excelBytes = data_import_xlsx_build($sheets);

    $tmp = tempnam(sys_get_temp_dir(), 'capbz');
    if ($tmp === false) {
        throw new RuntimeException('Nie udało się utworzyć pliku tymczasowego.');
    }
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Nie udało się utworzyć archiwum ZIP.');
    }
    $zip->addFromString(BUNDLE_EXPORT_EXCEL_NAME, $excelBytes);
    $zip->addFromString('MANIFEST.json', json_encode([
        'excel' => BUNDLE_EXPORT_EXCEL_NAME,
        'exported_at' => gmdate('c'),
        'partial' => $partial,
        'tables' => $tables,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach ($scenarioFiles as $zipPath => $json) {
        $zip->addFromString($zipPath, $json);
    }
    $includeCallOffs = !$partial || in_array('call_off_comparisons', $tables, true) || in_array('call_off_volumes', $tables, true);
    if ($includeCallOffs) {
        bundle_export_add_call_offs($zip);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    if ($bytes === false) {
        throw new RuntimeException('Nie udało się odczytać wygenerowanego archiwum ZIP.');
    }
    return [$bytes, $partial];
}

function bundle_export_add_call_offs(ZipArchive $zip): void
{
    $root = dirname(database_path()) . DIRECTORY_SEPARATOR . 'call-offs';
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $zip->addFile($file->getPathname(), 'call-offs/' . $rel);
    }
}

function bundle_export_instruction_rows(bool $partial, array $tables): array
{
    $rows = [
        ['Paczka Capacity — eksport danych (Excel + scenarios + call-offs). Konta, role i hasła NIE są eksportowane.'],
        ['Ten plik .zip można wgrać z powrotem w Ustawienia administracyjne → Import paczki .zip.'],
        [''],
    ];
    $rows[] = $partial
        ? ['Eksport częściowy — tabele: ' . implode(', ', $tables)]
        : ['Eksport pełny — wszystkie tabele danych (bez kont/ról/haseł).'];
    return $rows;
}
