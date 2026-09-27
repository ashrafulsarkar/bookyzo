// Staff login mode (QA report 2026-09-22 §6.2.9): the signed-in user is
// linked to some bookable items and sees only those items' bookings, on
// the Bookings and Calendar screens. BYZO_Admin_Assets sets
// window.byzoAdmin.staff for them (null for admins); the REST routes
// enforce the same limits, this only shapes the UI.
export const staffMode = !!window.byzoAdmin?.staff;

// Route names a staff login may open; anything else goes to their bookings.
// An add-on's route with `meta: { staff: true }` is allowed too, and its
// nav item with `staff: true` joins their sidebar.
export const STAFF_ROUTES = ['bookings', 'calendar'];
