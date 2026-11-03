<?php
declare(strict_types=1);

/** Locale for Excel _INSTRUKCJA sheets (pl | en | de; unknown → en). */
function import_request_locale(): string
{
    $raw = strtolower(trim((string) ($_GET['locale'] ?? '')));
    return match ($raw) {
        'pl', 'en', 'de' => $raw,
        default => 'en',
    };
}

function import_readme_header(string $locale): string
{
    return match ($locale) {
        'pl' => 'Instrukcja',
        'de' => 'Anleitung',
        default => 'Instructions',
    };
}

function machines_import_instruction_rows(string $locale): array
{
    if ($locale === 'pl') {
        return [
            ['Arkusz Maszyny: numer_wewnetrzny (wymagany, unikalny — cyfry, np. 1134/1, lub opis tekstowy), numer_sap (wymagany), typ (wymagany), status (active/inactive/RFQ), lokalizacja (numer linii).'],
            ['Import tylko dodaje/aktualizuje — nic nie jest usuwane. Potwierdzenie: IMPORTUJ_MASZYNY lub IMPORT_MACHINES.'],
        ];
    }
    if ($locale === 'de') {
        return [
            ['Blatt Maszyny: numer_wewnetrzny (Pflicht, eindeutig), numer_sap (Pflicht), typ (Pflicht), status (active/inactive/RFQ), lokalizacja (Liniennr.).'],
            ['Import nur Hinzufügen/Aktualisieren — nichts wird gelöscht. Bestätigung: IMPORT_MACHINES oder IMPORTUJ_MASZYNY.'],
        ];
    }
    return [
        ['Sheet Maszyny: numer_wewnetrzny (required, unique), numer_sap (required), typ (required), status (active/inactive/RFQ), lokalizacja (line number).'],
        ['Import only adds/updates — nothing is deleted. Confirmation: IMPORT_MACHINES or IMPORTUJ_MASZYNY.'],
    ];
}

function data_import_instruction_rows(string $locale): array
{
    if ($locale === 'pl') {
        return [
            [DATA_IMPORT_INSTRUCTION_CODE],
            [DATA_IMPORT_TEMPLATE_FILENAME],
            [''],
            ['Maszyny: numer_wewnetrzny (wymagany, unikalny — klucz dopasowania), numer_sap (wymagany), typ (wymagany), status (active/inactive/RFQ), lokalizacja (numer linii).'],
            ['Fazy_procesu: nazwa. Opcjonalny arkusz — brakujące fazy użyte w Operacje są tworzone automatycznie.'],
            ['Projekty: klient i nazwa_projektu (wymagane, razem są kluczem dopasowania), sop i eop (dd.mm.rrrr — wymagane dla nowego projektu), status (active/inactive/RFQ).'],
            ['Detale: oznaczenie — katalog do podpowiedzi. Opcjonalny arkusz — brakujące oznaczenia użyte w Czesci/Operacje są tworzone automatycznie.'],
            ['Czesci: klient, nazwa_projektu, oznaczenie (wymagane), strona (RH/LH, opcjonalnie). Łączy detal z projektem.'],
            ['Operacje: klient, nazwa_projektu, oznaczenie, strona (jak w Czesci, opcjonalnie), numer_wewnetrzny_maszyny (musi już istnieć), faza, czas_cyklu_s, wolumen, jednostka_wolumenu (annual/monthly/weekly), gniazda, oee_override, procent_obciazenia, opf (0/1), sap, opis.'],
            [''],
            ['Tryb DODAJ: rekordy z pliku są tworzone/aktualizowane; reszta zostaje bez zmian.'],
            ['Tryb ZASTĄP: dotyczy wyłącznie arkusza Operacje — po imporcie lista operacji odpowiada plikowi (operacje spoza pliku są usuwane). Maszyny, projekty, fazy i detale NIE są usuwane w żadnym trybie.'],
            ['Przed trybem ZASTĄP system próbuje utworzyć kopię zapasową bazy (best-effort).'],
        ];
    }
    if ($locale === 'de') {
        return [
            [DATA_IMPORT_INSTRUCTION_CODE],
            [DATA_IMPORT_TEMPLATE_FILENAME],
            [''],
            ['Maszyny: numer_wewnetrzny (Pflicht, eindeutig), numer_sap (Pflicht), typ (Pflicht), status (active/inactive/RFQ), lokalizacja (Liniennr.).'],
            ['Fazy_procesu: nazwa. Optional — fehlende Phasen aus Operacje werden automatisch angelegt.'],
            ['Projekty: klient, nazwa_projektu (Pflicht, gemeinsam Schlüssel), sop/eop (TT.MM.JJJJ für neue Projekte), status (active/inactive/RFQ).'],
            ['Detale: oznaczenie — Katalog. Optional — fehlende Bezeichnungen werden bei Bedarf angelegt.'],
            ['Czesci: klient, nazwa_projektu, oznaczenie (Pflicht), strona (RH/LH, optional). Verknüpft Teil mit Projekt.'],
            ['Operacje: wie Czesci plus numer_wewnetrzny_maszyny (muss existieren), faza, czas_cyklu_s, wolumen, jednostka_wolumenu (annual/monthly/weekly), gniazda, oee_override, procent_obciazenia, opf (0/1), sap, opis.'],
            [''],
            ['Modus HINZUFÜGEN: Datensätze aus Datei werden erstellt/aktualisiert; Rest bleibt unverändert.'],
            ['Modus ERSETZEN: nur Blatt Operacje — Operationsliste entspricht der Datei (fehlende Operationen werden gelöscht). Maschinen, Projekte, Phasen, Teile werden nie gelöscht.'],
            ['Vor ERSETZEN wird versucht, ein Backup zu erstellen (best-effort).'],
        ];
    }
    return [
        [DATA_IMPORT_INSTRUCTION_CODE],
        [DATA_IMPORT_TEMPLATE_FILENAME],
        [''],
        ['Maszyny: numer_wewnetrzny (required, unique key), numer_sap (required), typ (required), status (active/inactive/RFQ), lokalizacja (line number).'],
        ['Fazy_procesu: nazwa. Optional — missing phases referenced in Operacje are created automatically.'],
        ['Projekty: klient and nazwa_projektu (required, combined key), sop/eop (dd.mm.yyyy for new projects), status (active/inactive/RFQ).'],
        ['Detale: oznaczenie — catalog. Optional — missing designations are created when needed.'],
        ['Czesci: klient, nazwa_projektu, oznaczenie (required), strona (RH/LH, optional). Links part to project.'],
        ['Operacje: as Czesci plus numer_wewnetrzny_maszyny (must exist), faza, czas_cyklu_s, wolumen, jednostka_wolumenu (annual/monthly/weekly), gniazda, oee_override, procent_obciazenia, opf (0/1), sap, opis.'],
        [''],
        ['APPEND mode: rows from file are created/updated; everything else stays unchanged.'],
        ['REPLACE mode: Operacje sheet only — operation list matches the file (operations not in file are removed). Machines, projects, phases and parts are never deleted.'],
        ['Before REPLACE the system tries to create a database backup (best-effort).'],
    ];
}

function bundle_export_instruction_rows(bool $partial, array $tables, string $locale): array
{
    if ($locale === 'pl') {
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
    if ($locale === 'de') {
        $rows = [
            ['Capacity-Paket — Datenexport (Excel + Szenarien + Call-offs). Konten, Rollen und Passwörter werden NICHT exportiert.'],
            ['Diese .zip-Datei kann unter Admin-Einstellungen → Paket importieren wieder hochgeladen werden.'],
            [''],
        ];
        $rows[] = $partial
            ? ['Teilexport — Tabellen: ' . implode(', ', $tables)]
            : ['Vollexport — alle Datentabellen (ohne Konten/Rollen/Passwörter).'];
        return $rows;
    }
    $rows = [
        ['Capacity package — data export (Excel + scenarios + call-offs). Accounts, roles and passwords are NOT exported.'],
        ['This .zip file can be re-uploaded under Admin settings → Upload package.'],
        [''],
    ];
    $rows[] = $partial
        ? ['Partial export — tables: ' . implode(', ', $tables)]
        : ['Full export — all data tables (no accounts/roles/passwords).'];
    return $rows;
}
