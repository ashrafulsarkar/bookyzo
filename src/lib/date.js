/**
 * Small date-range helpers for the booking calendar. Plain Date math, no
 * date library — the ranges here are simple (day/week/month bounds,
 * local time only, matching DETAILED-SPEC.md §5's site-local-not-UTC
 * decision) and don't need timezone conversion since the browser and
 * WordPress are assumed to agree on "today" for admin-side viewing.
 */
import { locale } from './i18n';

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
  return date.toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' });
}

export function formatMonthLabel(date) {
  return date.toLocaleDateString(locale, { month: 'long', year: 'numeric' });
}

export function formatTime(date) {
  return date.toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
}

/**
 * An on-the-hour label for the calendar's hour rail. Drops the `:00`
 * every row would otherwise repeat — with the minutes in, "10:00 AM"
 * overflowed the rail's width and wrapped onto two lines while shorter
 * labels like "7:00 AM" didn't, leaving the rail visibly ragged. Locales
 * that don't use AM/PM get their own hour formatting from `Intl` here
 * exactly as before.
 */
export function formatHourLabel(date) {
  return date.toLocaleTimeString(locale, { hour: 'numeric' });
}

/**
 * Weekday names, Sunday first (index = JS getDay()), in the page's
 * language. `style` is Intl's: 'long' (Sunday) or 'short' (Sun).
 */
export function weekdayNames(style = 'long') {
  // 1 January 2023 was a Sunday.
  return Array.from({ length: 7 }, (_, i) => new Date(2023, 0, 1 + i).toLocaleDateString(locale, { weekday: style }));
}

/**
 * A booking's start/end as Dates on the clock people there go by: the
 * item's location when it has its own timezone (booking.local_zone set),
 * the site's otherwise. Wall-clock strings, read as-is.
 */
function wallClock(value) {
  return value ? new Date(String(value).replace(' ', 'T')) : null;
}

export function bookingStart(booking) {
  return wallClock(booking.local_zone ? booking.local_slot_start : booking.slot_start);
}

export function bookingEnd(booking) {
  return wallClock(booking.local_zone ? booking.local_slot_end : booking.slot_end);
}

/** " (+06)" after a time on another location's clock; "" otherwise. */
export function zoneSuffix(booking) {
  return booking.local_zone ? ` (${booking.local_zone})` : '';
}
