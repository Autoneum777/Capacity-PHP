/**
 * Tymczasowy znacznik, żeby odróżnić tę aplikację od wersji Node.
 * Wyłączenie: ustaw PHP_VERSION_MARKER na false.
 * Wtedy znika żółte tło i napis „wersja PHP”.
 */
export const PHP_VERSION_MARKER = false;

export const PHP_VERSION_PAGE_BG = '#fff6cc';
export const PHP_VERSION_HEADER_BG = '#fff3b0';

export function PhpVersionMark() {
  if (!PHP_VERSION_MARKER) return null;
  return (
    <div
      aria-hidden
      style={{
        position: 'fixed',
        top: 10,
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 10000,
        pointerEvents: 'none',
        fontSize: '1.75rem',
        fontWeight: 800,
        lineHeight: 1.1,
        color: '#7a5b00',
        letterSpacing: '0.04em',
        whiteSpace: 'nowrap',
        textShadow: '0 0 8px #fff6cc, 0 0 8px #fff6cc',
      }}
    >
      wersja PHP
    </div>
  );
}
