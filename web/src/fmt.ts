// Formato regional según la configuración de la empresa (zona horaria y formato de fecha).
export type Locale = { timezone: string; date_format: string };
let current: Locale = { timezone: 'America/Santiago', date_format: 'dd/mm/yyyy' };

export const setLocale = (l: Locale | undefined) => { if (l) current = l; };
export const getLocale = () => current;

/** "2026-10-30T02:08:22Z" → "30/10/2026 23:08" en la zona horaria configurada. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso.includes('T') || iso.endsWith('Z') ? iso : iso.replace(' ', 'T') + 'Z');
  if (isNaN(d.getTime())) return iso;
  let parts: Record<string, string>;
  try {
    parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
      timeZone: current.timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(d).map((p) => [p.type, p.value]));
  } catch { return iso; }
  const date = current.date_format === 'yyyy-mm-dd' ? `${parts.year}-${parts.month}-${parts.day}`
    : current.date_format === 'mm/dd/yyyy' ? `${parts.month}/${parts.day}/${parts.year}` : `${parts.day}/${parts.month}/${parts.year}`;
  return `${date} ${parts.hour}:${parts.minute}`;
}
