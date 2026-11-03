/** Komunikat zwracany przez API, gdy numer SAP detalu już istnieje. Alias i free text mogą się powtarzać. */
export const DESIGNATION_DUPLICATE_ERROR =
  'Detal o takim numerze SAP już istnieje i nie został utworzony kolejny';

export function isDesignationDuplicateError(message: string | undefined | null): boolean {
  if (!message) return false;
  return (
    message === DESIGNATION_DUPLICATE_ERROR ||
    message.includes('numerze SAP już istnieje') ||
    (message.includes('już istnieje') && message.includes('nie został utworzony'))
  );
}
