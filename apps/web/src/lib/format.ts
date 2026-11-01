const amount = new Intl.NumberFormat('en-PK', { maximumFractionDigits: 0 });

/**
 * Minor units (paisa) → "PKR 1,234".
 *
 * The currency symbol is spelled out rather than delegated to Intl's currency
 * style: Node and browsers disagree on PKR ("Rs" vs "PKR"), and a price that
 * changes shape depending on the runtime is not worth the shortcut.
 */
export function formatMoneyMinor(minor: number): string {
  return `PKR ${amount.format(Math.round(minor / 100))}`;
}

export const formatPkr = formatMoneyMinor;

export function formatDistance(meters: number): string {
  return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${meters} m`;
}

export function formatDuration(seconds: number): string {
  const minutes = Math.round(seconds / 60);

  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export function formatTime(iso: string | null): string {
  if (!iso) return '—';

  return new Date(iso).toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit' });
}

/** An idempotency key per booking attempt, reused for every retry of that attempt. */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();

  return `key-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
