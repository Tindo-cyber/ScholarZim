const dateFormat = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});

/** Backend sends LocalDate as "YYYY-MM-DD"; parse as local time, not UTC. */
export function parseIsoDate(iso: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!match) return null;
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

export function formatDeadline(iso: string | null): string {
  if (!iso) return 'No deadline';
  const date = parseIsoDate(iso);
  return date ? `Closes ${dateFormat.format(date)}` : 'No deadline';
}

export function formatDate(iso: string | null): string {
  if (!iso) return '—';
  const date = parseIsoDate(iso);
  return date ? dateFormat.format(date) : '—';
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-GB').format(value);
}
