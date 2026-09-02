/**
 * Small date-range helpers for the booking calendar. Plain Date math, no
 * date library — the ranges here are simple (day/week/month bounds,
 * local time only, matching DETAILED-SPEC.md §5's site-local-not-UTC
 * decision) and don't need timezone conversion since the browser and
 * WordPress are assumed to agree on "today" for admin-side viewing.
 */

export function toDateKey(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function startOfWeek(date) {
  const d = new Date(date);
  d.setDate(d.getDate() - d.getDay());
  d.setHours(0, 0, 0, 0);
  return d;
}

export function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

export function endOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0);
}

export function addDays(date, days) {
  const d = new Date(date);
  d.setDate(d.getDate() + days);
  return d;
}

/**
 * The 6x7 grid a month view renders — starts on the Sunday on/before the
 * 1st, ends on the Saturday on/after the last day, so every row is a
 * full week (some days from adjacent months, dimmed by the caller).
 */
export function monthGridDays(date) {
  const first = startOfMonth(date);
  const last = endOfMonth(date);
  const gridStart = startOfWeek(first);
  const gridEnd = addDays(startOfWeek(last), 6);

  const days = [];
  let cursor = gridStart;
  while (cursor <= gridEnd) {
    days.push(cursor);
    cursor = addDays(cursor, 1);
  }
  return days;
}

export function isSameDay(a, b) {
  return toDateKey(a) === toDateKey(b);
}

export function formatDayLabel(date) {
  return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

export function formatMonthLabel(date) {
  return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

export function formatTime(date) {
  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}
