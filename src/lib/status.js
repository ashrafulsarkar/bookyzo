import { i18n } from './i18n';

/**
 * Words for the status values the REST API returns (BYZO_Booking,
 * BYZO_Bookable_Item and BYZO_Slot's own lists), so a badge never shows a
 * raw key like `no_show` and each can be translated. An unknown value is
 * shown as it came, underscores as spaces.
 */
const LABELS = {
  // Bookings.
  pending: i18n.__('Pending', 'bookyzo'),
  confirmed: i18n.__('Confirmed', 'bookyzo'),
  completed: i18n.__('Completed', 'bookyzo'),
  cancelled: i18n.__('Cancelled', 'bookyzo'),
  no_show: i18n.__('No-show', 'bookyzo'),
  rejected: i18n.__('Rejected', 'bookyzo'),
  // Bookable items (and add-on records that use the same words).
  active: i18n.__('Active', 'bookyzo'),
  inactive: i18n.__('Inactive', 'bookyzo'),
  archived: i18n.__('Archived', 'bookyzo'),
  // Times.
  open: i18n.__('Open', 'bookyzo'),
  full: i18n.__('Full', 'bookyzo'),
  blocked: i18n.__('Blocked', 'bookyzo'),
  // Payments.
  unpaid: i18n.__('Unpaid', 'bookyzo'),
  paid_manual: i18n.__('Paid manually', 'bookyzo'),
  paid_online: i18n.__('Paid online', 'bookyzo'),
  refunded: i18n.__('Refunded', 'bookyzo'),
};

export function statusLabel(status) {
  if (!status) return '';
  return LABELS[status] || String(status).replace(/_/g, ' ');
}
