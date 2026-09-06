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

// Reserved for Phase 1.7 step 5 (dashboard data: GET /bookings widened to
// accept an authenticated byzo_customer request, auto-scoped server-side
// to their own customer_id) — not called anywhere yet in step 4, which
// only needs the logged-in state itself, not the booking list.
export const getMyBookings = () => request('/bookings');
