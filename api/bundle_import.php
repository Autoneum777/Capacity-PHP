<?php
declare(strict_types=1);

const BUNDLE_README_SHEET = '_INSTRUKCJA';
const BUNDLE_AUTH_TABLES = [
    'users',
    'user_roles',
    'roles',
    'role_permissions',
    'sessions',
    'password_reset_tokens',
    'password_reset_requests',
];
const BUNDLE_SKIP = [
    'admin_settings',
    'sqlite_sequence',
    'sqlite_stat1',
    'sqlite_stat4',
    '_migrations',
    BUNDLE_README_SHEET,
];
const BUNDLE_IMPORT_ORDER = [
    'working_days',
    'machine_types',
    'process_phases',
    'machines',
    'part_designations',
    'nests',
    'nest_machines',
    'machine_alternatives',
    'projects',
    'project_volumes',
    'project_volumes_contract',
    'project_eop_extensions',
    'project_notes',
    'parts',
    'part_volume_by_year',
    'part_volume_contract_by_year',
    'part_volume_share_by_year',
    'part_volume_contract_share_by_year',
    'operations',
    'operation_volume_by_year',
    'operation_set_members',
    'call_off_comparisons',
    'call_off_volumes',
    'scenarios',
];
const BUNDLE_DATE_COLUMNS = ['sop', 'eop', 'eop_original', 'eop_extension', 'eop_before', 'eop_after'];

function bundle_import_request(PDO $pdo): void
{
    require_permission($pdo, 'admin_database.edit');
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 0 && $_POST === [] && $_FILES === []) {
        json_response(413, ['error' => 'Plik jest za duży dla limitu przesyłania PHP.']);
    }
    $confirm = trim((string) ($_POST['confirm'] ?? ''));
    if ($confirm !== 'IMPORTUJ_BAZE') {
        json_response(400, ['error' => 'Potwierdź import: wyślij pole formularza confirm o wartości dokładnie IMPORTUJ_BAZE.']);
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'Plik jest za duży dla limitu przesyłania PHP.'
            : 'Brak pliku .xlsx lub .zip (pole formularza: file).';
        json_response(400, ['error' => $message]);
    }
    $path = (string) ($file['tmp_name'] ?? '');
    if ($path === '' || !is_file($path)) {
        json_response(400, ['error' => 'Brak pliku .xlsx lub .zip (pole formularza: file).']);
    }
    $only = bundle_only_tables($_POST['onlyTables'] ?? null);
    try {
        if (bundle_is_package($path)) {
            $result = bundle_import_package($pdo, $path, $only);
        } else {
            $result = bundle_import_workbook($pdo, bundle_read_xlsx($path), $only, 0, 0);
        }
    } catch (Throwable $e) {
        json_response($e instanceof RuntimeException ? 400 : 500, ['error' => $e->getMessage()]);
    }
    if (!$result['ok']) {
        json_response(400, ['error' => $result['error']]);
    }
    json_response(200, $result);
}

function bundle_only_tables(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $parsed = json_decode($raw, true);
    $names = is_array($parsed) ? $parsed : preg_split('/[,;]/', $raw);
    $out = [];
    foreach ($names ?: [] as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $out[] = $name;
        }
    }
    return $out;
}

function bundle_is_auth_table(string $name): bool
{
    return in_array(strtolower(trim($name)), BUNDLE_AUTH_TABLES, true);
}

function bundle_is_package(string $path): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $contentTypes = $zip->locateName('[Content_Types].xml', ZipArchive::FL_NOCASE) !== false;
    $hasXlsx = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (!str_contains($name, '/') && str_ends_with(strtolower($name), '.xlsx')) {
            $hasXlsx = true;
            break;
        }
    }
    $zip->close();
    return !$contentTypes && $hasXlsx;
}

function bundle_import_package(PDO $pdo, string $path, array $only): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'error' => 'Niepoprawne archiwum ZIP.'];
    }
    $excelName = null;
    $manifest = $zip->getFromName('MANIFEST.json');
    if (is_string($manifest) && $manifest !== '') {
        $decoded = json_decode($manifest, true);
        $named = is_array($decoded) ? (string) ($decoded['excel'] ?? '') : '';
        if ($named !== '' && $zip->locateName($named) !== false) {
            $excelName = $named;
        }
    }
    if ($excelName === null) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (!str_contains($name, '/') && str_ends_with(strtolower($name), '.xlsx')) {
                $excelName = $name;
                break;
            }
        }
    }
    if ($excelName === null) {
        $zip->close();
        return ['ok' => false, 'error' => 'W archiwum ZIP nie znaleziono pliku .xlsx (np. capacity_baza_szablon.xlsx).'];
    }
    $temp = tempnam(sys_get_temp_dir(), 'capx');
    if ($temp === false) {
        $zip->close();
        return ['ok' => false, 'error' => 'Nie udało się przygotować pliku Excel z archiwum.'];
    }
    $xlsx = $temp . '.xlsx';
    rename($temp, $xlsx);
    $bytes = $zip->getFromName($excelName);
    if (!is_string($bytes) || $bytes === '') {
        $zip->close();
        @unlink($xlsx);
        return ['ok' => false, 'error' => 'Brak pliku „' . $excelName . '” w archiwum ZIP.'];
    }
    file_put_contents($xlsx, $bytes);
    try {
        $sheets = bundle_read_xlsx($xlsx);
    } catch (Throwable $e) {
        $zip->close();
        @unlink($xlsx);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    @unlink($xlsx);
    $patched = bundle_patch_scenario_files($sheets, $zip);
    $result = bundle_import_workbook($pdo, $sheets, $only, $patched, 0);
    if (!$result['ok']) {
        $zip->close();
        return $result;
    }
    $includeCallOffs = $only === []
        || in_array('call_off_comparisons', $only, true)
        || in_array('call_off_volumes', $only, true);
    if ($includeCallOffs) {
        try {
            $result['call_offs_restored'] = bundle_extract_call_offs($zip);
        } catch (Throwable) {
            $result['call_offs_restored'] = 0;
        }
    }
    $zip->close();
    return $result;
}

function bundle_patch_scenario_files(array &$sheets, ZipArchive $zip): int
{
    $rows = $sheets['scenarios'] ?? null;
    if (!is_array($rows)) {
        return 0;
    }
    $patched = 0;
    foreach ($rows as &$row) {
        $raw = $row['snapshot'] ?? null;
        if (!is_string($raw) || !str_starts_with($raw, '__FILE__:')) {
            continue;
        }
        $zipPath = ltrim(str_replace('\\', '/', substr($raw, strlen('__FILE__:'))), '/');
        $json = $zip->getFromName($zipPath);
        if (!is_string($json) || $json === '') {
            continue;
        }
        $parsed = json_decode($json, true);
        if (!is_array($parsed) || !array_key_exists('snapshot', $parsed)) {
            continue;
        }
        $snap = $parsed['snapshot'];
        $row['snapshot'] = is_string($snap) ? $snap : json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $patched++;
    }
    unset($row);
    $sheets['scenarios'] = $rows;
    return $patched;
}

function bundle_extract_call_offs(ZipArchive $zip): int
{
    $root = dirname(database_path()) . DIRECTORY_SEPARATOR . 'call-offs';
    $prefix = 'call-offs/';
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
            continue;
        }
        $rel = substr($name, strlen($prefix));
        if ($rel === '' || str_contains($rel, '..')) {
            continue;
        }
        $entries[] = [$name, $rel];
    }
    if ($entries === []) {
        return 0;
    }
    if (is_dir($root)) {
        bundle_clear_directory($root);
    }
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return 0;
    }
    $count = 0;
    foreach ($entries as [$name, $rel]) {
        $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            continue;
        }
        $bytes = $zip->getFromName($name);
        if (!is_string($bytes)) {
            continue;
        }
        file_put_contents($dest, $bytes);
        $count++;
    }
    return $count;
}

function bundle_clear_directory(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            bundle_clear_directory($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function bundle_import_workbook(PDO $pdo, array $sheets, array $only, int $scenarios, int $callOffs): array
{
    $order = bundle_import_order($pdo);
    $partial = $only !== [];
    if ($partial) {
        $allowed = array_flip(bundle_without_auth($order));
        foreach ($only as $name) {
            if (bundle_is_auth_table($name)) {
                return ['ok' => false, 'error' => 'Tabela „' . $name . '” (konta/hasła/role) nie jest importowana — pomiń ją. Konta na serwerze pozostają bez zmian.'];
            }
            if (!isset($allowed[$name])) {
                return ['ok' => false, 'error' => 'Niedozwolona lub nieznana tabela w imporcie częściowym: ' . $name];
            }
        }
        $requested = array_flip($only);
        $tables = [];
        foreach ($order as $name) {
            if (isset($requested[$name])) {
                $tables[] = $name;
            }
        }
        $tables = bundle_without_auth($tables);
        if ($tables === []) {
            return ['ok' => false, 'error' => 'Import częściowy: żadna z podanych nazw nie pasuje do tabel w bazie.'];
        }
    } else {
        $tables = bundle_without_auth($order);
    }

    $machineRows = $sheets['machines'] ?? [];
    if (in_array('machines', $tables, true) && is_array($machineRows)) {
        $duplicates = bundle_duplicate_machines($machineRows);
        if ($duplicates !== []) {
            return ['ok' => false, 'error' => 'Arkusz „machines”: powtórzony numer maszyny (internal_number) w pliku: ' . implode(', ', $duplicates) . '. Zostaw jeden wiersz na numer lub popraw dane.'];
        }
    }

    $counts = [];
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        for ($i = count($tables) - 1; $i >= 0; $i--) {
            $table = $tables[$i];
            if (bundle_skipped($table)) {
                continue;
            }
            $pdo->exec('DELETE FROM ' . bundle_ident($table));
        }
        foreach ($tables as $table) {
            if (bundle_skipped($table)) {
                $counts[$table] = 0;
                continue;
            }
            $sheetName = strlen($table) > 31 ? substr($table, 0, 31) : $table;
            $rows = $sheets[$sheetName] ?? $sheets[$table] ?? null;
            if (!is_array($rows) || $rows === []) {
                $counts[$table] = 0;
                continue;
            }
            $dbCols = array_flip(bundle_columns($pdo, $table));
            $first = null;
            foreach ($rows as $candidate) {
                if (is_array($candidate)) {
                    $first = $candidate;
                    break;
                }
            }
            if ($first === null) {
                $counts[$table] = 0;
                continue;
            }
            $cols = [];
            foreach (array_keys($first) as $column) {
                if (isset($dbCols[$column]) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) === 1) {
                    $cols[] = (string) $column;
                }
            }
            if ($cols === []) {
                $counts[$table] = 0;
                continue;
            }
            $sql = 'INSERT INTO ' . bundle_ident($table) . ' (' . implode(',', array_map('bundle_ident', $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $stmt = $pdo->prepare($sql);
            $inserted = 0;
            foreach ($rows as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $values = [];
                $empty = true;
                foreach ($cols as $column) {
                    $value = bundle_coerce($column, $raw[$column] ?? null);
                    if ($value !== null) {
                        $empty = false;
                    }
                    $values[] = $value;
                }
                if ($empty) {
                    continue;
                }
                $stmt->execute($values);
                $inserted++;
            }
            $counts[$table] = $inserted;
            if (in_array('id', $cols, true)) {
                $max = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . bundle_ident($table))->fetchColumn();
                $seq = $pdo->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?');
                $seq->execute([$max, $table]);
                if ($seq->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$table, $max]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    return [
        'ok' => true,
        'tables_imported' => $tables,
        'rows_counts' => $counts,
        'partial' => $partial,
        'call_offs_restored' => $callOffs,
        'scenario_snapshots_restored' => $scenarios,
    ];
}

function bundle_import_order(PDO $pdo): array
{
    $existing = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $row) {
        $existing[(string) $row['name']] = true;
    }
    $out = [];
    foreach (BUNDLE_IMPORT_ORDER as $name) {
        if (isset($existing[$name])) {
            $out[] = $name;
        }
    }
    $rest = array_keys($existing);
    sort($rest);
    foreach ($rest as $name) {
        if (!in_array($name, $out, true) && !bundle_skipped($name)) {
            $out[] = $name;
        }
    }
    return $out;
}

function bundle_without_auth(array $tables): array
{
    return array_values(array_filter($tables, static fn(string $name): bool => !bundle_skipped($name)));
}

function bundle_skipped(string $name): bool
{
    return in_array($name, BUNDLE_SKIP, true) || bundle_is_auth_table($name) || $name === BUNDLE_README_SHEET;
}

function bundle_ident(string $name): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
        throw new RuntimeException('Nieprawidłowa nazwa tabeli: ' . $name);
    }
    return $name;
}

function bundle_columns(PDO $pdo, string $table): array
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(' . bundle_ident($table) . ')') as $row) {
        $cols[] = (string) $row['name'];
    }
    return $cols;
}

function bundle_duplicate_machines(array $rows): array
{
    $counts = [];
    foreach ($rows as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $value = bundle_coerce('internal_number', $raw['internal_number'] ?? null);
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^\d+(\/\d+)?$/', $text) !== 1) {
            continue;
        }
        $counts[$text] = ($counts[$text] ?? 0) + 1;
    }
    $dups = [];
    foreach ($counts as $number => $count) {
        if ($count > 1) {
            $dups[] = (string) $number;
        }
    }
    sort($dups, SORT_NATURAL);
    return $dups;
}

function bundle_coerce(string $column, mixed $value): string|int|float|null
{
    if (in_array($column, BUNDLE_DATE_COLUMNS, true)) {
        return bundle_coerce_date($value);
    }
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value) || is_float($value)) {
        return $value;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^-?\d+$/', $text) === 1) {
        return (int) $text;
    }
    if (preg_match('/^-?\d+\.\d+$/', $text) === 1) {
        return (float) $text;
    }
    return $text;
}

function bundle_coerce_date(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        $serial = (int) floor((float) $value);
        if ($serial >= 25000 && $serial <= 80000) {
            $stamp = gmmktime(0, 0, 0, 1, 1, 1970) + ($serial - 25569) * 86400;
            return gmdate('d.m.Y', $stamp);
        }
        return (string) $value;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $match) === 1) {
        return $match[3] . '.' . $match[2] . '.' . $match[1];
    }
    if (preg_match('/^(\d{1,2})[./](\d{1,2})[./](\d{4})$/', $text, $match) === 1) {
        return sprintf('%02d.%02d.%s', (int) $match[1], (int) $match[2], $match[3]);
    }
    return $text;
}

function bundle_read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Niepoprawny plik Excel.');
    }
    $shared = bundle_shared_strings($zip);
    $sheets = [];
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbook) || !is_string($rels)) {
        $zip->close();
        throw new RuntimeException('Niepoprawny plik Excel.');
    }
    $targets = [];
    $relDom = new DOMDocument();
    $relDom->loadXML($rels);
    foreach ($relDom->getElementsByTagName('Relationship') as $rel) {
        $target = str_replace('\\', '/', $rel->getAttribute('Target'));
        $target = ltrim($target, '/');
        if (!str_starts_with($target, 'xl/')) {
            $target = 'xl/' . $target;
        }
        $targets[$rel->getAttribute('Id')] = $target;
    }
    $bookDom = new DOMDocument();
    $bookDom->loadXML($workbook);
    foreach ($bookDom->getElementsByTagName('sheet') as $sheet) {
        $name = $sheet->getAttribute('name');
        $id = $sheet->getAttribute('id');
        if ($id === '') {
            $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        }
        $target = $targets[$id] ?? '';
        if ($name === '' || $target === '' || $name === BUNDLE_README_SHEET) {
            continue;
        }
        $xml = $zip->getFromName($target);
        if (!is_string($xml)) {
            $sheets[$name] = [];
            continue;
        }
        $sheets[$name] = bundle_sheet_rows($xml, $shared);
    }
    $zip->close();
    return $sheets;
}

function bundle_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') {
        return [];
    }
    $strings = [];
    $reader = XMLReader::XML($xml);
    if ($reader === false) {
        return [];
    }
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
            $outer = $reader->readOuterXml();
            $strings[] = bundle_plain_text($outer);
        }
    }
    $reader->close();
    return $strings;
}

function bundle_plain_text(string $xml): string
{
    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $xml, $matches) === false || $matches[1] === []) {
        return '';
    }
    $text = '';
    foreach ($matches[1] as $part) {
        $text .= html_entity_decode($part, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    return $text;
}

function bundle_sheet_rows(string $xml, array $shared): array
{
    $reader = XMLReader::XML($xml);
    if ($reader === false) {
        return [];
    }
    $headers = null;
    $rows = [];
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
            continue;
        }
        $ref = (string) $reader->getAttribute('r');
        $type = (string) $reader->getAttribute('t');
        $outer = $reader->readOuterXml();
        if (!isset($currentRow)) {
            $currentRow = bundle_row_number($ref);
            $cells = [];
        }
        $rowNumber = bundle_row_number($ref);
        if ($rowNumber !== $currentRow) {
            bundle_push_row($headers, $rows, $cells);
            $currentRow = $rowNumber;
            $cells = [];
        }
        $cells[bundle_col_index($ref)] = bundle_cell_value($outer, $type, $shared);
    }
    if (isset($cells)) {
        bundle_push_row($headers, $rows, $cells);
    }
    $reader->close();
    return $rows;
}

function bundle_push_row(?array &$headers, array &$rows, array $cells): void
{
    if ($cells === []) {
        return;
    }
    ksort($cells);
    if ($headers === null) {
        $headers = [];
        foreach ($cells as $index => $value) {
            $name = trim((string) $value);
            if ($name !== '') {
                $headers[$index] = $name;
            }
        }
        return;
    }
    $row = [];
    $any = false;
    foreach ($headers as $index => $name) {
        $value = $cells[$index] ?? null;
        if ($value !== null && $value !== '') {
            $any = true;
        }
        $row[$name] = $value === '' ? null : $value;
    }
    if ($any) {
        $rows[] = $row;
    }
}

function bundle_cell_value(string $xml, string $type, array $shared): mixed
{
    if ($type === 'inlineStr') {
        return bundle_plain_text($xml);
    }
    if (preg_match('/<v[^>]*>(.*?)<\/v>/s', $xml, $match) !== 1) {
        return null;
    }
    $text = html_entity_decode($match[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if ($type === 's') {
        return $shared[(int) $text] ?? '';
    }
    if ($type === 'b') {
        return $text === '1';
    }
    if ($text === '') {
        return null;
    }
    if (is_numeric($text)) {
        return str_contains($text, '.') ? (float) $text : (int) $text;
    }
    return $text;
}

function bundle_col_index(string $ref): int
{
    if (preg_match('/^([A-Z]+)/', strtoupper($ref), $match) !== 1) {
        return 0;
    }
    $index = 0;
    foreach (str_split($match[1]) as $char) {
        $index = $index * 26 + (ord($char) - 64);
    }
    return $index - 1;
}

function bundle_row_number(string $ref): int
{
    if (preg_match('/(\d+)$/', $ref, $match) !== 1) {
        return 0;
    }
    return (int) $match[1];
}
