/**
 * Per-type wording for the public booking widget (DETAILED-SPEC.md §A5:
 * "Widget copy must be type-aware generally — no hardcoded 'appointment'
 * wording in steps/confirmation once four types are live").
 *
 * One map rather than conditionals scattered across the step components:
 * the four types differ in vocabulary far more than in structure, so
 * every string a visitor reads is looked up here and the components stay
 * shape-agnostic. Adding a fifth type later means adding a row, not
 * hunting for `type === 'event'` checks in five files.
 *
 * `appointment` doubles as the fallback for an unknown/missing type, so
 * a lookup can never render `undefined`.
 */
import { i18n } from '../lib/i18n';

const COPY = {
  appointment: {
    pickHeading: i18n.__('Choose a service', 'bookyzo'),
    pickSub: i18n.__("Select what you'd like to book.", 'bookyzo'),
    slotHeading: i18n.__('Pick a time', 'bookyzo'),
    detailsHeading: i18n.__('Your details', 'bookyzo'),
    submit: i18n.__('Confirm booking', 'bookyzo'),
    submitting: i18n.__('Booking…', 'bookyzo'),
    confirmHeading: i18n.__('Booking received', 'bookyzo'),
    subjectLabel: i18n.__('Service', 'bookyzo'),
    // Party size is meaningless for a 1:1 appointment — the widget hides
    // the input entirely for this type (see StepDetails).
    quantityLabel: null,
    remaining: (n) => i18n.sprintf(i18n._n('%s left', '%s left', n, 'bookyzo'), n),
    soldOut: i18n.__('Fully booked', 'bookyzo'),
  },
  table: {
    pickHeading: i18n.__('Choose a table', 'bookyzo'),
    pickSub: i18n.__("Select where you'd like to sit.", 'bookyzo'),
    slotHeading: i18n.__('Pick a time', 'bookyzo'),
    detailsHeading: i18n.__('Your details', 'bookyzo'),
    submit: i18n.__('Reserve table', 'bookyzo'),
    submitting: i18n.__('Reserving…', 'bookyzo'),
    confirmHeading: i18n.__('Reservation received', 'bookyzo'),
    subjectLabel: i18n.__('Table', 'bookyzo'),
    quantityLabel: i18n.__('Party size', 'bookyzo'),
    remaining: (n) => i18n.sprintf(i18n._n('%s seat left', '%s seats left', n, 'bookyzo'), n),
    soldOut: i18n.__('Fully booked', 'bookyzo'),
  },
  event: {
    pickHeading: i18n.__('Choose an event', 'bookyzo'),
    pickSub: i18n.__("Select what you'd like to attend.", 'bookyzo'),
    slotHeading: i18n.__('Choose a date', 'bookyzo'),
    detailsHeading: i18n.__('Your details', 'bookyzo'),
    submit: i18n.__('Get tickets', 'bookyzo'),
    submitting: i18n.__('Booking…', 'bookyzo'),
    confirmHeading: i18n.__('Tickets booked', 'bookyzo'),
    subjectLabel: i18n.__('Event', 'bookyzo'),
    quantityLabel: i18n.__('Tickets', 'bookyzo'),
    remaining: (n) => i18n.sprintf(i18n._n('%s ticket left', '%s tickets left', n, 'bookyzo'), n),
    soldOut: i18n.__('Sold out', 'bookyzo'),
  },
  slot: {
    pickHeading: i18n.__('Choose a session', 'bookyzo'),
    pickSub: i18n.__("Select what you'd like to book.", 'bookyzo'),
    slotHeading: i18n.__('Pick a time', 'bookyzo'),
    detailsHeading: i18n.__('Your details', 'bookyzo'),
    submit: i18n.__('Reserve spot', 'bookyzo'),
    submitting: i18n.__('Reserving…', 'bookyzo'),
    confirmHeading: i18n.__('Booking received', 'bookyzo'),
    subjectLabel: i18n.__('Session', 'bookyzo'),
    quantityLabel: i18n.__('Spots', 'bookyzo'),
    remaining: (n) => i18n.sprintf(i18n._n('%s spot left', '%s spots left', n, 'bookyzo'), n),
    soldOut: i18n.__('Fully booked', 'bookyzo'),
  },
};

export function copyFor(type) {
  return COPY[type] || COPY.appointment;
}

/**
 * The picker step lists every active item across all four types, so no
 * single type's wording fits — it gets neutral copy instead of, say,
 * "Choose a table" above a list containing an event.
 */
export const MIXED_COPY = {
  // A list with tables and events in it can't be headed "Choose a service"
  // (QA report 2026-09-22 §4.14).
  pickHeading: i18n.__('What would you like to book?', 'bookyzo'),
  pickSub: i18n.__('Pick one to see available times.', 'bookyzo'),
};

/**
 * Headings for the groups a mixed list is split into, in the order they're
 * shown — appointments first (the most common single-item site), then the
 * rest. A list of one type doesn't use these at all: its own copy already
 * says what it is.
 */
export const GROUP_LABELS = {
  appointment: i18n.__('Services', 'bookyzo'),
  table: i18n.__('Tables', 'bookyzo'),
  slot: i18n.__('Classes & sessions', 'bookyzo'),
  event: i18n.__('Events', 'bookyzo'),
};
export const GROUP_ORDER = ['appointment', 'table', 'slot', 'event'];
