<?php
declare(strict_types=1);

require_once __DIR__ . '/bundle_import.php';
require_once __DIR__ . '/import_instructions.php';

/*
 * "Import danych (Excel)" — samodzielna wersja (v1/operacje-v3) zaprojektowana od zera dla backendu PHP.
 * Oryginalna wersja Node (`operacje-v2`) nie została przeniesiona; to jest nowy, prostszy odpowiednik,
 * zgodny z obecnym modelem danych. Zakres v1: maszyny, fazy procesu, projekty, katalog detali,
 * powiązania projekt↔detal (parts) i operacje. Wolumeny roczne, warianty kontraktowe, materiały
 * bazowe, RFQ i scenariusze pozostają poza zakresem — do tego służy już działająca „Paczka capacity”.
 *
 * Tryb DODAJ (merge): rekordy z pliku są tworzone/aktualizowane; nic nie jest usuwane.
 * Tryb ZASTĄP (replace): dotyczy WYŁĄCZNIE arkusza Operacje — po imporcie lista operacji odpowiada
 * plikowi (operacje spoza pliku są usuwane, kaskadowo usuwając ich override'y wolumenu per rok).
 * Maszyny / projekty / fazy / detale / części nigdy nie są usuwane przez ten import — to świadome
 * ograniczenie bezpieczeństwa (te tabele mają zależności poza zakresem v1: wolumeny, załączniki,
 * materiały bazowe itd., których usunięcie byłoby nieoczywistym efektem ubocznym).
 */

const DATA_IMPORT_SCHEMA_TAG = 'operacje-v3';
const DATA_IMPORT_TEMPLATE_FILENAME = 'capacity_szablon_import_danych_v3.xlsx';
const DATA_IMPORT_INSTRUCTION_CODE = 'CAPACITY_IMPORT_V3';
const DATA_IMPORT_CONFIRM_PHRASE = 'IMPORTUJ_DANE';
const DATA_IMPORT_CONFIRM_PHRASE_EN = 'IMPORT_DATA';
const MACHINES_IMPORT_CONFIRM_PHRASE = 'IMPORTUJ_MASZYNY';
const MACHINES_IMPORT_CONFIRM_PHRASE_EN = 'IMPORT_MACHINES';

function data_import_confirm_data(string $confirm): bool
{
    return $confirm === DATA_IMPORT_CONFIRM_PHRASE || $confirm === DATA_IMPORT_CONFIRM_PHRASE_EN;
}

function data_import_confirm_machines(string $confirm): bool
{
    return $confirm === MACHINES_IMPORT_CONFIRM_PHRASE || $confirm === MACHINES_IMPORT_CONFIRM_PHRASE_EN;
}
const MACHINES_IMPORT_TEMPLATE_FILENAME = 'maszyny_import.xlsx';

const DATA_IMPORT_MACHINES_HEADERS = ['numer_wewnetrzny', 'numer_sap', 'typ', 'status', 'lokalizacja'];
const DATA_IMPORT_PHASES_HEADERS = ['nazwa'];
const DATA_IMPORT_PROJECTS_HEADERS = ['klient', 'nazwa_projektu', 'sop', 'eop', 'status'];
const DATA_IMPORT_DESIGNATIONS_HEADERS = ['oznaczenie'];
const DATA_IMPORT_PARTS_HEADERS = ['klient', 'nazwa_projektu', 'oznaczenie', 'strona'];
const DATA_IMPORT_OPERATIONS_HEADERS = [
    'klient', 'nazwa_projektu', 'oznaczenie', 'strona', 'numer_wewnetrzny_maszyny', 'faza',
    'czas_cyklu_s', 'wolumen', 'jednostka_wolumenu', 'gniazda', 'oee_override',
    'procent_obciazenia', 'opf', 'sap', 'opis',
];

function handle_data_import(string $method, string $path, PDO $pdo): bool
{
    if ($method === 'GET' && $path === '/admin/capacity-data-template.xlsx') {
        require_permission($pdo, 'admin_settings.view');
        data_import_schema_header();
        $locale = import_request_locale();
        $bytes = data_import_xlsx_build(data_import_template_sheets($locale));
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . DATA_IMPORT_TEMPLATE_FILENAME . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        return true;
    }

    if ($method === 'GET' && $path === '/admin/capacity-data-template-info.json') {
        require_permission($pdo, 'admin_settings.view');
        json_response(200, [
            'schemaTag' => DATA_IMPORT_SCHEMA_TAG,
            'downloadFilename' => DATA_IMPORT_TEMPLATE_FILENAME,
            'sheets' => ['Maszyny', 'Fazy_procesu', 'Projekty', 'Detale', 'Czesci', 'Operacje'],
            'machinesSheetHeaders' => DATA_IMPORT_MACHINES_HEADERS,
            'instructionRow1MustInclude' => DATA_IMPORT_INSTRUCTION_CODE,
        ]);
    }

    if ($method === 'GET' && $path === '/admin/capacity-data-import-schema.txt') {
        require_permission($pdo, 'admin_settings.view');
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=UTF-8');
        echo DATA_IMPORT_SCHEMA_TAG . "\n" . DATA_IMPORT_TEMPLATE_FILENAME . "\n";
        return true;
    }

    if ($method === 'POST' && $path === '/admin/capacity-data-import') {
        data_import_run_request($pdo);
        return true;
    }

    if ($method === 'GET' && $path === '/admin/machines-import-template.xlsx') {
        require_permission($pdo, 'admin_settings.view');
        $locale = import_request_locale();
        $bytes = data_import_xlsx_build([
            ['name' => 'Maszyny', 'headers' => DATA_IMPORT_MACHINES_HEADERS, 'rows' => []],
            ['name' => BUNDLE_README_SHEET, 'headers' => [import_readme_header($locale)], 'rows' => machines_import_instruction_rows($locale)],
        ]);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . MACHINES_IMPORT_TEMPLATE_FILENAME . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        return true;
    }

    if ($method === 'POST' && $path === '/admin/machines-import') {
        machines_import_run_request($pdo);
        return true;
    }

    return false;
}

/* ---------------------------------------------------------------------- */
/* Szablon / arkusz z instrukcją                                          */
/* ---------------------------------------------------------------------- */

function data_import_schema_header(): void
{
    header('X-Capacity-Data-Import-Schema: ' . DATA_IMPORT_SCHEMA_TAG);
}

function data_import_template_sheets(string $locale = 'en'): array
{
    return [
        ['name' => 'Maszyny', 'headers' => DATA_IMPORT_MACHINES_HEADERS, 'rows' => []],
        ['name' => 'Fazy_procesu', 'headers' => DATA_IMPORT_PHASES_HEADERS, 'rows' => []],
        ['name' => 'Projekty', 'headers' => DATA_IMPORT_PROJECTS_HEADERS, 'rows' => []],
        ['name' => 'Detale', 'headers' => DATA_IMPORT_DESIGNATIONS_HEADERS, 'rows' => []],
        ['name' => 'Czesci', 'headers' => DATA_IMPORT_PARTS_HEADERS, 'rows' => []],
        ['name' => 'Operacje', 'headers' => DATA_IMPORT_OPERATIONS_HEADERS, 'rows' => []],
        ['name' => BUNDLE_README_SHEET, 'headers' => [import_readme_header($locale)], 'rows' => data_import_instruction_rows($locale)],
    ];
}

/* ---------------------------------------------------------------------- */
/* Minimalny generator .xlsx (bez zależności zewnętrznych)                */
/* ---------------------------------------------------------------------- */

function data_import_xlsx_build(array $sheets): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'capx');
    if ($tmp === false) {
        throw new RuntimeException('Nie udało się utworzyć pliku tymczasowego.');
    }
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Nie udało się utworzyć pliku Excel.');
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    foreach (array_values($sheets) as $i => $_) {
        $n = $i + 1;
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $contentTypes .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    $zip->addFromString(
        '_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>'
    );

    $zip->addFromString(
        'docProps/core.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Capacity</dc:title></cp:coreProperties>'
    );
    $zip->addFromString(
        'docProps/app.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Capacity</Application></Properties>'
    );

    $zip->addFromString(
        'xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>'
    );

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $workbookSheets = '';
    $n = 0;
    foreach ($sheets as $sheet) {
        $n++;
        $workbookRels .= '<Relationship Id="rIdSheet' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $workbookSheets .= '<sheet name="' . data_import_xml_escape((string) $sheet['name']) . '" sheetId="' . $n . '" r:id="rIdSheet' . $n . '"/>';
    }
    $workbookRels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbookRels .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $workbookSheets . '</sheets>'
        . '</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    $n = 0;
    foreach ($sheets as $sheet) {
        $n++;
        $xml = data_import_sheet_xml((array) $sheet['headers'], (array) $sheet['rows']);
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml);
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    if ($bytes === false) {
        throw new RuntimeException('Nie udało się odczytać pliku Excel.');
    }
    return $bytes;
}

function data_import_xml_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function data_import_col_letter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = intdiv($index - 1, 26);
    }
    return $letter;
}

function data_import_cell_xml(int $colIndex, int $rowIndex, mixed $value, int $styleIndex): string
{
    $ref = data_import_col_letter($colIndex) . $rowIndex;
    if ($value === null || $value === '') {
        return '<c r="' . $ref . '" s="' . $styleIndex . '"/>';
    }
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $ref . '" s="' . $styleIndex . '"><v>' . $value . '</v></c>';
    }
    $text = data_import_xml_escape((string) $value);
    return '<c r="' . $ref . '" t="inlineStr" s="' . $styleIndex . '"><is><t xml:space="preserve">' . $text . '</t></is></c>';
}

function data_import_sheet_xml(array $headers, array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';
    $rowIndex = 1;
    $cells = '';
    foreach (array_values($headers) as $i => $h) {
        $cells .= data_import_cell_xml($i, $rowIndex, $h, 1);
    }
    $xml .= '<row r="' . $rowIndex . '">' . $cells . '</row>';
    foreach ($rows as $row) {
        $rowIndex++;
        $cells = '';
        foreach (array_values($row) as $i => $value) {
            $cells .= data_import_cell_xml($i, $rowIndex, $value, 0);
        }
        $xml .= '<row r="' . $rowIndex . '">' . $cells . '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/* ---------------------------------------------------------------------- */
/* Pomocnicze — best-effort backup bazy przed trybem ZASTĄP                */
/* ---------------------------------------------------------------------- */

function data_import_try_backup(): ?string
{
    $dbPath = database_path();
    if (!is_file($dbPath)) {
        return null;
    }
    $dir = dirname($dbPath) . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $name = 'capacity_before_import_' . date('Ymd_His') . '.db';
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@copy($dbPath, $dest)) {
        return null;
    }
    return $name;
}

/* ---------------------------------------------------------------------- */
/* Import danych (Excel) — logika                                         */
/* ---------------------------------------------------------------------- */

function data_import_str(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function data_import_run_request(PDO $pdo): void
{
    require_permission($pdo, 'admin_database.edit');
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 0 && $_POST === [] && $_FILES === []) {
        json_response(413, ['error' => 'Plik jest za duży dla limitu przesyłania PHP.']);
    }
    $confirm = data_import_str($_POST['confirm'] ?? null);
    if (!data_import_confirm_data($confirm)) {
        json_response(400, ['error' => 'Confirm import: send form field confirm with exactly ' . DATA_IMPORT_CONFIRM_PHRASE_EN . ' or ' . DATA_IMPORT_CONFIRM_PHRASE . '.']);
    }
    $mode = data_import_str($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'Plik jest za duży dla limitu przesyłania PHP.'
            : 'Brak pliku .xlsx (pole formularza: file).';
        json_response(400, ['error' => $message]);
    }
    $path = (string) ($file['tmp_name'] ?? '');
    if ($path === '' || !is_file($path)) {
        json_response(400, ['error' => 'Brak pliku .xlsx (pole formularza: file).']);
    }
    try {
        $sheets = bundle_read_xlsx($path);
    } catch (Throwable $e) {
        json_response(400, ['error' => $e->getMessage()]);
    }
    try {
        $result = data_import_apply($pdo, $sheets, $mode);
    } catch (Throwable $e) {
        json_response($e instanceof RuntimeException ? 400 : 500, ['error' => $e->getMessage()]);
    }
    json_response(200, $result);
}

function data_import_apply(PDO $pdo, array $sheets, string $mode): array
{
    $counts = [
        'machines_created' => 0, 'machines_updated' => 0, 'machines_deleted' => 0,
        'phases_created' => 0,
        'projects_created' => 0, 'projects_updated' => 0, 'projects_deleted' => 0,
        'designations_created' => 0, 'designations_updated' => 0, 'designations_deleted' => 0,
        'parts_created' => 0, 'parts_deleted' => 0, 'parts_skipped' => 0,
        'volumes_upserted' => 0, 'volumes_deleted' => 0,
        'operations_created' => 0, 'operations_updated' => 0, 'operations_deleted' => 0,
    ];
    $warnings = [];
    $typesAdded = [];
    $backupFile = null;

    $pdo->beginTransaction();
    try {
        if ($mode === 'replace') {
            $backupFile = data_import_try_backup();
            if ($backupFile === null) {
                $warnings[] = 'Nie udało się utworzyć kopii zapasowej bazy przed trybem ZASTĄP (brak uprawnień do zapisu w katalogu backups?) — import przebiega bez backupu.';
            }
        }

        $projectCache = [];
        foreach ((array) ($sheets['Projekty'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            data_import_upsert_project($pdo, $row, $counts, $warnings, $i + 2, $projectCache);
        }

        foreach ((array) ($sheets['Detale'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            data_import_ensure_designation($pdo, data_import_str($row['oznaczenie'] ?? null), $counts);
        }

        $typeCache = [];
        foreach ((array) ($sheets['Maszyny'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            data_import_upsert_machine($pdo, $row, $counts, $warnings, $typesAdded, $i + 2);
        }

        $phaseCache = [];
        foreach ((array) ($sheets['Fazy_procesu'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = data_import_str($row['nazwa'] ?? null);
            if ($name !== '') {
                data_import_ensure_phase($pdo, $name, $counts, $phaseCache);
            }
        }

        $partCache = [];
        foreach ((array) ($sheets['Czesci'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            data_import_process_part_row($pdo, $row, $counts, $warnings, $i + 2, $projectCache, $partCache, true);
        }

        $touchedOperationIds = [];
        foreach ((array) ($sheets['Operacje'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $opId = data_import_process_operation_row(
                $pdo,
                $row,
                $counts,
                $warnings,
                $i + 2,
                $projectCache,
                $partCache,
                $phaseCache
            );
            if ($opId !== null) {
                $touchedOperationIds[$opId] = true;
            }
        }

        if ($mode === 'replace') {
            $existing = $pdo->query('SELECT id FROM operations')->fetchAll(PDO::FETCH_COLUMN);
            $toDelete = [];
            foreach ($existing as $id) {
                if (!isset($touchedOperationIds[(int) $id])) {
                    $toDelete[] = (int) $id;
                }
            }
            if ($toDelete !== []) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $pdo->prepare('DELETE FROM operations WHERE id IN (' . $placeholders . ')')->execute($toDelete);
                $counts['operations_deleted'] = count($toDelete);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'mode' => $mode,
        'backup_file' => $backupFile,
        'counts' => $counts,
        'warnings' => $warnings,
        'types_added' => array_keys($typesAdded),
    ];
}

function data_import_normalize_side(mixed $raw): ?string
{
    $side = strtoupper(data_import_str($raw));
    return in_array($side, ['RH', 'LH'], true) ? $side : null;
}

function data_import_ensure_designation(PDO $pdo, string $designation, array &$counts): void
{
    $designation = trim($designation);
    if ($designation === '') {
        return;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM part_designations WHERE designation = ?');
    $stmt->execute([$designation]);
    if (!$stmt->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO part_designations (designation) VALUES (?)')->execute([$designation]);
        $counts['designations_created']++;
    }
}

function data_import_upsert_project(PDO $pdo, array $row, array &$counts, array &$warnings, int $rowNum, array &$projectCache): void
{
    $client = data_import_str($row['klient'] ?? null);
    $name = data_import_str($row['nazwa_projektu'] ?? null);
    if ($client === '' || $name === '') {
        $warnings[] = "Projekty, wiersz {$rowNum}: pominięto — wymagane klient i nazwa_projektu.";
        return;
    }
    $sop = bundle_coerce_date($row['sop'] ?? null);
    $eop = bundle_coerce_date($row['eop'] ?? null);
    $statusRaw = strtoupper(data_import_str($row['status'] ?? 'active'));
    $status = $statusRaw === 'RFQ' ? 'RFQ' : (strtolower($statusRaw) === 'inactive' ? 'inactive' : 'active');

    $stmt = $pdo->prepare('SELECT id FROM projects WHERE client = ? AND name = ?');
    $stmt->execute([$client, $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE projects SET sop = COALESCE(?, sop), eop = COALESCE(?, eop), status = ? WHERE id = ?')
            ->execute([$sop, $eop, $status, $id]);
        $counts['projects_updated']++;
        $projectCache[$client . '|' . $name] = (int) $id;
        return;
    }
    if ($sop === null || $eop === null) {
        $warnings[] = "Projekty, wiersz {$rowNum}: nowy projekt „{$name}” wymaga sop i eop — pominięto.";
        return;
    }
    $pdo->prepare('INSERT INTO projects (client, name, sop, eop, status) VALUES (?,?,?,?,?)')
        ->execute([$client, $name, $sop, $eop, $status]);
    $id = (int) $pdo->lastInsertId();
    $counts['projects_created']++;
    $projectCache[$client . '|' . $name] = $id;
}

function data_import_resolve_project_id(PDO $pdo, string $client, string $name, array &$projectCache): ?int
{
    $key = $client . '|' . $name;
    if (isset($projectCache[$key])) {
        return $projectCache[$key];
    }
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE client = ? AND name = ?');
    $stmt->execute([$client, $name]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        return null;
    }
    $projectCache[$key] = (int) $id;
    return (int) $id;
}

function data_import_resolve_part(
    PDO $pdo,
    int $projectId,
    string $designation,
    ?string $side,
    array &$counts,
    array &$partCache,
    bool $autoCreate
): ?int {
    $key = $projectId . '|' . $designation . '|' . ($side ?? '');
    if (isset($partCache[$key])) {
        return $partCache[$key];
    }
    if ($side === null) {
        $stmt = $pdo->prepare('SELECT id FROM parts WHERE project_id = ? AND designation = ? AND side IS NULL');
        $stmt->execute([$projectId, $designation]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM parts WHERE project_id = ? AND designation = ? AND side = ?');
        $stmt->execute([$projectId, $designation, $side]);
    }
    $id = $stmt->fetchColumn();
    if ($id) {
        $partCache[$key] = (int) $id;
        return (int) $id;
    }
    if (!$autoCreate) {
        return null;
    }
    $pdo->prepare('INSERT INTO parts (project_id, designation, side) VALUES (?,?,?)')->execute([$projectId, $designation, $side]);
    $id = (int) $pdo->lastInsertId();
    $counts['parts_created']++;
    $partCache[$key] = $id;
    return $id;
}

function data_import_process_part_row(
    PDO $pdo,
    array $row,
    array &$counts,
    array &$warnings,
    int $rowNum,
    array &$projectCache,
    array &$partCache,
    bool $fromPartsSheet
): void {
    $client = data_import_str($row['klient'] ?? null);
    $name = data_import_str($row['nazwa_projektu'] ?? null);
    $designation = data_import_str($row['oznaczenie'] ?? null);
    if ($client === '' || $name === '' || $designation === '') {
        $counts['parts_skipped']++;
        $warnings[] = "Czesci, wiersz {$rowNum}: pominięto — wymagane klient, nazwa_projektu i oznaczenie.";
        return;
    }
    $projectId = data_import_resolve_project_id($pdo, $client, $name, $projectCache);
    if ($projectId === null) {
        $counts['parts_skipped']++;
        $warnings[] = "Czesci, wiersz {$rowNum}: nieznany projekt „{$client} / {$name}” — pominięto.";
        return;
    }
    data_import_ensure_designation($pdo, $designation, $counts);
    $side = data_import_normalize_side($row['strona'] ?? null);
    data_import_resolve_part($pdo, $projectId, $designation, $side, $counts, $partCache, true);
}

function data_import_ensure_phase(PDO $pdo, string $name, array &$counts, array &$phaseCache): int
{
    $name = trim($name);
    if (isset($phaseCache[$name])) {
        return $phaseCache[$name];
    }
    $stmt = $pdo->prepare('SELECT id FROM process_phases WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $phaseCache[$name] = (int) $id;
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO process_phases (name) VALUES (?)')->execute([$name]);
    $id = (int) $pdo->lastInsertId();
    $counts['phases_created']++;
    $phaseCache[$name] = $id;
    return $id;
}

function data_import_resolve_machine_type(PDO $pdo, string $type, array &$typesAdded): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM machine_types WHERE name = ?');
    $stmt->execute([$type]);
    if (!$stmt->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO machine_types (name) VALUES (?)')->execute([$type]);
        $typesAdded[$type] = true;
    }
}

function data_import_upsert_machine(
    PDO $pdo,
    array $row,
    array &$counts,
    array &$warnings,
    array &$typesAdded,
    int $rowNum
): void {
    $internal = data_import_str($row['numer_wewnetrzny'] ?? null);
    $sap = data_import_str($row['numer_sap'] ?? null);
    $type = data_import_str($row['typ'] ?? null);
    if ($internal === '' || $sap === '' || $type === '') {
        $warnings[] = "Maszyny, wiersz {$rowNum}: pominięto — wymagane numer_wewnetrzny, numer_sap i typ.";
        return;
    }
    if (strlen($internal) > 128) {
        $warnings[] = "Maszyny, wiersz {$rowNum}: numer_wewnetrzny za długi (maks. 128 znaków) — pominięto.";
        return;
    }
    $statusRaw = strtolower(data_import_str($row['status'] ?? 'active'));
    $status = $statusRaw === 'inactive' ? 'inactive' : ($statusRaw === 'rfq' ? 'RFQ' : 'active');
    $locationDigits = preg_replace('/\D/', '', data_import_str($row['lokalizacja'] ?? ''));
    $location = $locationDigits !== '' ? $locationDigits : null;

    data_import_resolve_machine_type($pdo, $type, $typesAdded);

    $stmt = $pdo->prepare('SELECT id FROM machines WHERE internal_number = ?');
    $stmt->execute([$internal]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE machines SET sap_number = ?, type = ?, status = ?, location = ? WHERE id = ?')
            ->execute([$sap, $type, $status, $location, $id]);
        $counts['machines_updated']++;
        return;
    }
    $pdo->prepare('INSERT INTO machines (internal_number, sap_number, type, status, location) VALUES (?,?,?,?,?)')
        ->execute([$internal, $sap, $type, $status, $location]);
    $counts['machines_created']++;
}

function data_import_process_operation_row(
    PDO $pdo,
    array $row,
    array &$counts,
    array &$warnings,
    int $rowNum,
    array &$projectCache,
    array &$partCache,
    array &$phaseCache
): ?int {
    $client = data_import_str($row['klient'] ?? null);
    $name = data_import_str($row['nazwa_projektu'] ?? null);
    $designation = data_import_str($row['oznaczenie'] ?? null);
    $machineNumber = data_import_str($row['numer_wewnetrzny_maszyny'] ?? null);
    $phaseName = data_import_str($row['faza'] ?? null);
    if ($client === '' || $name === '' || $designation === '' || $machineNumber === '' || $phaseName === '') {
        $warnings[] = "Operacje, wiersz {$rowNum}: pominięto — wymagane klient, nazwa_projektu, oznaczenie, numer_wewnetrzny_maszyny i faza.";
        return null;
    }
    $projectId = data_import_resolve_project_id($pdo, $client, $name, $projectCache);
    if ($projectId === null) {
        $warnings[] = "Operacje, wiersz {$rowNum}: nieznany projekt „{$client} / {$name}” — pominięto.";
        return null;
    }
    $side = data_import_normalize_side($row['strona'] ?? null);
    data_import_ensure_designation($pdo, $designation, $counts);
    $partId = data_import_resolve_part($pdo, $projectId, $designation, $side, $counts, $partCache, true);
    if ($partId === null) {
        $warnings[] = "Operacje, wiersz {$rowNum}: nie udało się dopasować/utworzyć części „{$designation}” — pominięto.";
        return null;
    }
    $machineStmt = $pdo->prepare('SELECT id FROM machines WHERE internal_number = ?');
    $machineStmt->execute([$machineNumber]);
    $machineId = $machineStmt->fetchColumn();
    if (!$machineId) {
        $warnings[] = "Operacje, wiersz {$rowNum}: maszyna „{$machineNumber}” nie istnieje (dodaj ją w arkuszu Maszyny) — pominięto.";
        return null;
    }
    $phaseId = data_import_ensure_phase($pdo, $phaseName, $counts, $phaseCache);

    $cycleRaw = $row['czas_cyklu_s'] ?? null;
    $cycle = is_numeric($cycleRaw) ? (int) round((float) $cycleRaw) : null;
    if ($cycle === null || $cycle <= 0) {
        $warnings[] = "Operacje, wiersz {$rowNum}: nieprawidłowy czas_cyklu_s — pominięto.";
        return null;
    }
    $volumeRaw = $row['wolumen'] ?? null;
    $volume = is_numeric($volumeRaw) ? (float) $volumeRaw : null;
    if ($volume === null || $volume < 0) {
        $warnings[] = "Operacje, wiersz {$rowNum}: nieprawidłowy wolumen — pominięto.";
        return null;
    }
    $unit = strtolower(data_import_str($row['jednostka_wolumenu'] ?? 'annual'));
    if (!in_array($unit, ['annual', 'monthly', 'weekly'], true)) {
        $warnings[] = "Operacje, wiersz {$rowNum}: nieznana jednostka_wolumenu „{$unit}” — przyjęto annual.";
        $unit = 'annual';
    }
    $nestsRaw = $row['gniazda'] ?? null;
    $nests = is_numeric($nestsRaw) ? max(1, (int) round((float) $nestsRaw)) : 1;
    $oeeRaw = $row['oee_override'] ?? null;
    $oee = is_numeric($oeeRaw) ? (float) $oeeRaw : null;
    $capacityRaw = $row['procent_obciazenia'] ?? null;
    $capacity = is_numeric($capacityRaw) ? (float) $capacityRaw : 100.0;
    if ($capacity <= 0 || $capacity > 100) {
        $capacity = 100.0;
    }
    $opfRaw = $row['opf'] ?? null;
    $opf = in_array((string) $opfRaw, ['1', 'true', 'TRUE', 'tak', 'TAK'], true) ? 1 : 0;
    $sap = data_import_str($row['sap'] ?? null);
    $description = data_import_str($row['opis'] ?? null);

    $stmt = $pdo->prepare(
        'SELECT id FROM operations WHERE project_id = ? AND part_id = ? AND machine_id = ? AND phase_id = ?'
    );
    $stmt->execute([$projectId, $partId, $machineId, $phaseId]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare(
            'UPDATE operations SET cycle_time_seconds = ?, volume_value = ?, volume_unit = ?, nests_count = ?,
                oee_override = ?, capacity_percent = ?, opf = ?, sap = ?, description = ? WHERE id = ?'
        )->execute([
            $cycle, $volume, $unit, $nests, $oee, $capacity, $opf,
            $sap !== '' ? $sap : null, $description !== '' ? $description : null, $existingId,
        ]);
        $counts['operations_updated']++;
        return (int) $existingId;
    }

    $pdo->prepare(
        'INSERT INTO operations (
            project_id, part_id, phase_id, machine_id, cycle_time_seconds, volume_value, volume_unit,
            nests_count, oee_override, capacity_percent, opf, sap, description
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $projectId, $partId, $phaseId, $machineId, $cycle, $volume, $unit,
        $nests, $oee, $capacity, $opf, $sap !== '' ? $sap : null, $description !== '' ? $description : null,
    ]);
    $counts['operations_created']++;
    return (int) $pdo->lastInsertId();
}

/* ---------------------------------------------------------------------- */
/* Import maszyn (osobny, mniejszy przycisk) — tylko DODAJ/AKTUALIZUJ     */
/* ---------------------------------------------------------------------- */

function machines_import_run_request(PDO $pdo): void
{
    require_permission($pdo, 'admin_database.edit');
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 0 && $_POST === [] && $_FILES === []) {
        json_response(413, ['error' => 'Plik jest za duży dla limitu przesyłania PHP.']);
    }
    $confirm = data_import_str($_POST['confirm'] ?? null);
    if (!data_import_confirm_machines($confirm)) {
        json_response(400, ['error' => 'Confirm import: send form field confirm with exactly ' . MACHINES_IMPORT_CONFIRM_PHRASE_EN . ' or ' . MACHINES_IMPORT_CONFIRM_PHRASE . '.']);
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'Plik jest za duży dla limitu przesyłania PHP.'
            : 'Brak pliku .xlsx (pole formularza: file).';
        json_response(400, ['error' => $message]);
    }
    $path = (string) ($file['tmp_name'] ?? '');
    if ($path === '' || !is_file($path)) {
        json_response(400, ['error' => 'Brak pliku .xlsx (pole formularza: file).']);
    }
    try {
        $sheets = bundle_read_xlsx($path);
    } catch (Throwable $e) {
        json_response(400, ['error' => $e->getMessage()]);
    }
    $rows = $sheets['Maszyny'] ?? null;
    if (!is_array($rows)) {
        json_response(400, ['error' => 'W pliku nie znaleziono arkusza „Maszyny”.']);
    }

    $counts = ['machines_created' => 0, 'machines_updated' => 0];
    $errors = [];
    $typesAdded = [];
    $skipped = 0;
    $backupFile = data_import_try_backup();

    $pdo->beginTransaction();
    try {
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $before = $counts;
            data_import_upsert_machine($pdo, $row, $counts, $errors, $typesAdded, $i + 2);
            if ($counts === $before) {
                $skipped++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(500, ['error' => $e->getMessage()]);
    }

    json_response(200, [
        'ok' => true,
        'created' => $counts['machines_created'],
        'updated' => $counts['machines_updated'],
        'skipped' => $skipped,
        'errors' => $errors,
        'types_added' => array_keys($typesAdded),
        'backup_file' => $backupFile,
        'backup_at' => $backupFile !== null ? date('Y-m-d H:i:s') : null,
    ]);
}
