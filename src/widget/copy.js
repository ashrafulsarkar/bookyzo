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
const COPY = {
  appointment: {
    pickHeading: 'Choose a service',
    pickSub: "Select what you'd like to book.",
    slotHeading: 'Pick a time',
    detailsHeading: 'Your details',
    submit: 'Confirm booking',
    submitting: 'Booking…',
    confirmHeading: 'Booking received',
    subjectLabel: 'Service',
    // Party size is meaningless for a 1:1 appointment — the widget hides
    // the input entirely for this type (see StepDetails).
    quantityLabel: null,
    remaining: (n) => `${n} left`,
    soldOut: 'Fully booked',
  },
  table: {
    pickHeading: 'Choose a table',
    pickSub: "Select where you'd like to sit.",
    slotHeading: 'Pick a time',
    detailsHeading: 'Your details',
    submit: 'Reserve table',
    submitting: 'Reserving…',
    confirmHeading: 'Reservation received',
    subjectLabel: 'Table',
    quantityLabel: 'Party size',
    remaining: (n) => `${n} seats left`,
    soldOut: 'Fully booked',
  },
  event: {
    pickHeading: 'Choose an event',
    pickSub: "Select what you'd like to attend.",
    slotHeading: 'Choose a date',
    detailsHeading: 'Your details',
    submit: 'Get tickets',
    submitting: 'Booking…',
    confirmHeading: 'Tickets booked',
    subjectLabel: 'Event',
    quantityLabel: 'Tickets',
    remaining: (n) => `${n} tickets left`,
    soldOut: 'Sold out',
  },
  slot: {
    pickHeading: 'Choose a session',
    pickSub: "Select what you'd like to book.",
    slotHeading: 'Pick a time',
    detailsHeading: 'Your details',
    submit: 'Reserve spot',
    submitting: 'Reserving…',
    confirmHeading: 'Booking received',
    subjectLabel: 'Session',
    quantityLabel: 'Spots',
    remaining: (n) => `${n} spots left`,
    soldOut: 'Fully booked',
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
  pickHeading: 'Choose a service',
  pickSub: "Select what you'd like to book.",
};
