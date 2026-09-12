/**
 * Account bundle's own tiny fetch wrapper — unlike src/widget/api.js
 * (fully guest, no session, no nonce needed), this bundle runs for an
 * AUTHENTICATED byzo_customer WP session once step 5 widens GET /bookings
 * to accept one, so it sends WordPress's own REST cookie-auth nonce
 * (X-WP-Nonce) exactly like wp.apiFetch does for the admin app — just via
 * plain fetch, since this bundle isn't loaded in an admin/wp.apiFetch
 * context either. window.byzoAccount.nonce is reserved now (Phase 1.7
 * step 4) even though no authenticated route exists yet, so step 5 needs
 * no further PHP-side enqueue change.
 */

const BASE  = (window.byzoAccount && window.byzoAccount.restUrl) || '/wp-json/bookyzo/v1';
const NONCE = (window.byzoAccount && window.byzoAccount.nonce) || '';

async function request(path, options = {}) {
  const res = await fetch(`${BASE}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      ...(NONCE ? { 'X-WP-Nonce': NONCE } : {}),
    },
    credentials: 'same-origin',
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

// Phase 1.7 step 5 (dashboard data, DETAILED-SPEC.md §A11): GET /bookings
// is now widened server-side to accept an authenticated byzo_customer
// request, auto-scoped to their own customer_id — this call never passes
// any filter param, since the server-side scoping is what actually
// enforces "only your own rows," not a client-side filter that could be
// omitted or tampered with.
export const getMyBookings = () => request('/bookings');

// Reuses the SAME PUT /bookings/{id}/cancel-as-customer route
// BYZO_Booking::cancel_as_customer() backs — a second authorization path
// onto the exact cancellation_hours policy the guest token link already
// enforces, not a separate/looser rule for logged-in customers.
export const cancelMyBooking = (bookingId) => request(`/bookings/${bookingId}/cancel-as-customer`, { method: 'PUT' });

// Phase 2 step 18 (DETAILED-SPEC.md §3 B4) — a plain, browser-navigable
// download link, not a fetch() call: WordPress's REST cookie-auth accepts
// the nonce via a `_wpnonce` query param for exactly this case, so a
// normal `<a href>` works without any JS fetch/blob dance. Only ever
// rendered when window.byzoAccount.pdfInvoiceEnabled is true (Pro-only,
// gated server-side by the route itself either way).
// No literal ".pdf" in the route path — an unescaped "." in a REST route
// is a regex wildcard, not a literal dot (see
// BYZOP_REST_Invoice_Controller::register_routes()'s own note, same
// lesson DETAILED-SPEC.md §3 B9 already documents for the calendar
// feed). The downloaded filename's own ".pdf" extension comes from the
// Content-Disposition header the route sends instead.
export const getInvoiceUrl = (bookingId) => `${BASE}/bookings/${bookingId}/invoice?_wpnonce=${encodeURIComponent(NONCE)}`;
