-- Istniejące detale z tym samym numerem SAP dostają numer porządkowy w nawiasie: „123 (1)”, „123 (2)”.
-- Unikalne numery SAP zostają bez zmian. Alias i free text nie są ruszane.
-- Placeholdery (brak, n/a, na, -) nie są numerem SAP i nie dostają numeru porządkowego.
WITH ranked AS (
  SELECT
    id,
    TRIM(sap_number) AS raw,
    ROW_NUMBER() OVER (PARTITION BY LOWER(TRIM(sap_number)) ORDER BY id) AS n,
    COUNT(*) OVER (PARTITION BY LOWER(TRIM(sap_number))) AS cnt
  FROM part_designations
  WHERE sap_number IS NOT NULL
    AND TRIM(sap_number) <> ''
    AND LOWER(TRIM(sap_number)) NOT IN ('brak', 'n/a', 'na', '-', '—')
)
UPDATE part_designations
SET
  sap_number = ranked.raw || ' (' || ranked.n || ')',
  designation = CASE
    WHEN part_designations.designation IS NOT NULL
     AND (
       part_designations.designation = ranked.raw
       OR part_designations.designation LIKE ranked.raw || ' | %'
     )
    THEN ranked.raw || ' (' || ranked.n || ')' || SUBSTR(part_designations.designation, LENGTH(ranked.raw) + 1)
    ELSE part_designations.designation
  END
FROM ranked
WHERE part_designations.id = ranked.id
  AND ranked.cnt > 1;
