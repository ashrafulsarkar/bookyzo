/**
 * Public widget's own tiny fetch wrapper — deliberately NOT wp.apiFetch
 * (that's an admin-app-only script dependency, not enqueued on the
 * frontend) and deliberately NO nonce. Resolves the Open Decision
 * DETAILED-SPEC.md §4 flagged before this screen was built ("nonce
 * isn't available to fully logged-out guest widget embeds cached by a
 * page cache — consider a short-lived REST nonce fetched on widget
 * mount, or a honeypot + rate limit by IP"): a nonce exists to protect
 * an authenticated user's cookie session against CSRF — a guest has no
 * session to protect, so no nonce is needed for these public routes at
 * all. Abuse is mitigated server-side instead (see BYZO_Public_Guard —
 * honeypot + per-IP rate limit on booking creation).
 */

const BASE = (window.byzoWidget && window.byzoWidget.restUrl) || '/wp-json/bookyzo/v1';

async function request(path, options = {}) {
  const res = await fetch(`${BASE}${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.message || 'Something went wrong. Please try again.');
    err.code = data.code;
    throw err;
  }
  return data;
}

export const getPublicBookableItems = (type) => request(`/bookable-items/public${type ? `?type=${encodeURIComponent(type)}` : ''}`);
export const getSlots = (bookableItemId) => request(`/slots?bookable_item_id=${bookableItemId}`);

// Phase 2 step 16 (DETAILED-SPEC.md §3 B11) — Pro-defined custom form
// fields for this bookable item, empty array when Pro is off/unconfigured.
export const getCustomFields = (bookableItemId) => request(`/custom-fields/public?bookable_item_id=${bookableItemId}`);

// Phase 2 step 17 (DETAILED-SPEC.md §3 B6) — Pro-defined add-ons for this
// bookable item, empty array when Pro is off/unconfigured.
export const getAddons = (bookableItemId) => request(`/addons/public?bookable_item_id=${bookableItemId}`);

// Phase 2 step 12 continued (DETAILED-SPEC.md §3 B7 Event) — Pro-defined
// ticket tiers for this bookable item, empty array when Pro is off/
// unconfigured.
export const getTicketTiers = (bookableItemId) => request(`/ticket-tiers/public?bookable_item_id=${bookableItemId}`);
export const createBooking = (payload) => request('/bookings', { method: 'POST', body: JSON.stringify(payload) });
export const createBookingPaymentIntent = (bookingId, customerEmail) =>
  request(`/bookings/${bookingId}/payment-intent`, { method: 'POST', body: JSON.stringify({ customer_email: customerEmail }) });

// Tells the server the card cleared. The server re-checks the intent with
// Stripe rather than believing this call, so it's safe to expose to a
// guest — see BYZO_REST_Bookings_Controller::confirm_payment().
export const confirmBookingPayment = (bookingId, customerEmail, paymentIntentId) =>
  request(`/bookings/${bookingId}/confirm-payment`, {
    method: 'POST',
    body: JSON.stringify({ customer_email: customerEmail, payment_intent_id: paymentIntentId }),
  });

// Records how the customer is paying (`stripe` when the card form opens,
// `in_person` for "Pay in person instead") so the server can tell an
// abandoned online payment from a deliberate pay-later booking — only the
// former is ever auto-released (BYZO_Booking_Cleanup).
export const setBookingPaymentMethod = (bookingId, customerEmail, paymentMethod) =>
  request(`/bookings/${bookingId}/payment-method`, {
    method: 'POST',
    body: JSON.stringify({ customer_email: customerEmail, payment_method: paymentMethod }),
  });

// Phase 5 (DETAILED-SPEC.md §3 B9) — only ever called when the selected
// item's wc_checkout_enabled flag is true, i.e. the separate
// bookyzo-woocommerce bridge plugin is active and enabled for this item;
// the route itself only exists then too. Harmless dead code on every
// other install, same as every other Pro-conditional call in this file.
export const startWooCommerceCheckout = (bookingId, customerEmail) =>
  request(`/wc-checkout/${bookingId}`, { method: 'POST', body: JSON.stringify({ customer_email: customerEmail }) });
