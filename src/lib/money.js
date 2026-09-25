/**
 * One money formatter for all three bundles (admin, widget, account).
 *
 * Before this existed, every screen that rendered a price rolled its own:
 * `Number(price).toFixed(2)` in the bookable-items list, the dashboard's
 * revenue tile, the widget's service picker and the calendar's booking
 * detail — all four of which dropped the currency entirely, so a customer
 * about to pay saw a bare `65.00` — plus a `${currency} ${amount}` string
 * in the wizard review steps and an `Intl.NumberFormat` call in the
 * payment step. Six spellings of the same thing, three of them wrong.
 *
 * `Intl.NumberFormat` is the one that was already right: it places the
 * symbol where the locale expects it (`$65.00` vs `65,00 €`) and knows
 * which currencies have no minor units (JPY renders `¥65`, not `¥65.00`),
 * neither of which a hand-rolled symbol map gets right.
 */
import { i18n, locale } from './i18n';

/**
 * The site's configured ISO code, read from whichever bundle's bootstrap
 * object is present. Each app is a separate entry point on a different
 * page, so exactly one of these exists at runtime; the fallback matches
 * BYZO_Settings' own default.
 */
export function siteCurrency() {
  const boot = window.byzoWidget || window.byzoAdmin || window.byzoAccount;

  return (boot && boot.currency) || 'USD';
}

/**
 * Formats an amount as money.
 *
 * @param {number|string|null} amount   The amount. Null/undefined/'' render as `emptyLabel`.
 * @param {object}             options
 * @param {string}             [options.currency]   ISO code; defaults to the site's.
 * @param {string}             [options.emptyLabel] Shown when there is no amount at all. Default '—'.
 * @param {string}             [options.zeroLabel]  Shown when the amount is exactly 0. Default 'Free'.
 * @returns {string}
 */
export function formatMoney(amount, options = {}) {
  const { currency = siteCurrency(), emptyLabel = '—', zeroLabel = i18n.__('Free', 'bookyzo') } = options;

  if (amount === null || amount === undefined || amount === '') {
    return emptyLabel;
  }

  const value = Number(amount);

  if (!Number.isFinite(value)) {
    return emptyLabel;
  }

  // A zero price is a deliberate "this costs nothing" (a free class, a
  // restaurant table), not a missing value — reading `0.00` as a price
  // makes a customer look for a charge that isn't coming. Callers that
  // genuinely want the number (a revenue total that happens to be zero)
  // pass `zeroLabel: null`.
  if (value === 0 && zeroLabel) {
    return zeroLabel;
  }

  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value);
  } catch (error) {
    // An invalid/unknown ISO code makes Intl throw rather than degrade.
    // The settings screen validates the shape (3 letters) but not that
    // the code actually exists, so a typo'd `USB` must not blank out
    // every price on the site.
    return `${currency} ${value.toFixed(2)}`;
  }
}

/**
 * Formats a running total — same as `formatMoney` but a zero stays `0.00`
 * rather than becoming "Free", since "Free" is a statement about one
 * item's price, not about a sum that happens to be empty.
 *
 * @param {number|string|null} amount
 * @param {object}             [options]
 * @returns {string}
 */
export function formatTotal(amount, options = {}) {
  return formatMoney(amount ?? 0, { zeroLabel: null, emptyLabel: formatMoney(0, { zeroLabel: null }), ...options });
}
