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

export const getPublicResources = () => request('/resources/public');
export const getSlots = (resourceId) => request(`/slots?resource_id=${resourceId}`);
export const createBooking = (payload) => request('/bookings', { method: 'POST', body: JSON.stringify(payload) });
export const createBookingPaymentIntent = (bookingId, customerEmail) =>
  request(`/bookings/${bookingId}/payment-intent`, { method: 'POST', body: JSON.stringify({ customer_email: customerEmail }) });
