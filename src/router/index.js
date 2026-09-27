import { createRouter } from 'vue-router';
import Dashboard from '../views/Dashboard.vue';
import SetupWizard from '../views/setup/SetupWizard.vue';
import BookableItemsList from '../views/bookable-items/BookableItemsList.vue';
import CustomersList from '../views/customers/CustomersList.vue';
import BookingCalendar from '../views/calendar/BookingCalendar.vue';
import BookingsList from '../views/bookings/BookingsList.vue';
import EmailTemplates from '../views/settings/EmailTemplates.vue';
import PaymentSettings from '../views/settings/PaymentSettings.vue';
import GeneralSettings from '../views/settings/GeneralSettings.vue';
import AppearanceSettings from '../views/settings/AppearanceSettings.vue';
import GetPro from '../views/GetPro.vue';
import { api } from '../api/client';
import { createWpAdminHistory } from './history';
import { staffMode, STAFF_ROUTES } from '../lib/staff';

// Custom wp-admin history (see ./history.js) rather than either stock
// option. Each sidebar screen is a real registered admin page
// (`admin.php?page=bookyzo-bookings`, see BYZO_Plugin::routes()), so
// navigation rewrites the `page` parameter: WordPress keeps highlighting
// the correct left-nav row, every screen is bookmarkable, and no
// server-side handling of arbitrary paths is needed — which wp-admin has
// no hook for. Routes with no admin page of their own (/setup,
// /bookable-items/new, /bookable-items/:id/edit) ride on their section's
// page via a `view` parameter. PHP picks the boot route
// (window.byzoAdmin.initialRoute) from the requested slug; the router owns
// every navigation after that.
const routes = [
  { path: '/', name: 'dashboard', component: Dashboard },
  { path: '/setup', name: 'setup', component: SetupWizard },
  { path: '/bookable-items', name: 'bookable-items', component: BookableItemsList },
  // Add / Edit are one and the same popup wizard on the list screen
  // (BookableItemWizard.vue — no id creates, an id edits), so these two
  // routes render the list itself — BookableItemsList.vue reads
  // route.name/params to open it in the right mode. Kept as real routes (not local state) so a reload or a
  // shared link reopens the same dialog, and SetupWizard.vue's "Manage" /
  // "Add another" can navigate straight into either.
  { path: '/bookable-items/new', name: 'bookable-items.new', component: BookableItemsList },
  { path: '/bookable-items/:id/edit', name: 'bookable-items.edit', component: BookableItemsList },
  { path: '/calendar', name: 'calendar', component: BookingCalendar },
  { path: '/bookings', name: 'bookings', component: BookingsList },
  { path: '/customers', name: 'customers', component: CustomersList },
  { path: '/settings/email-templates', name: 'settings.email-templates', component: EmailTemplates },
  { path: '/settings/payments', name: 'settings.payments', component: PaymentSettings },
  // Business profile + booking-behavior toggles (Phase 1.5 steps 1/6/7),
  // previously wizard-only — GeneralSettings.vue is their ongoing home.
  { path: '/settings/general', name: 'settings.general', component: GeneralSettings },
  // Booking-widget visual options (FEATURES.md §A5) — writes the
  // byzo_appearance option, which BYZO_Public_Widget turns into CSS
  // custom property overrides on the public widget.
  { path: '/settings/appearance', name: 'settings.appearance', component: AppearanceSettings },
  // Only ever navigated to while unlicensed — BYZO_Plugin::routes() only
  // registers the matching 'bookyzo-get-pro' WP admin page (and therefore
  // this route as an initialRoute target) under that same condition, and
  // ByzoSidebar.vue only links to it then too. The route itself stays
  // registered unconditionally so a stale bookmark from before activating
  // a license doesn't 404 inside the SPA — GetPro.vue's own content is
  // harmless to view either way, it's just unreachable from the UI once licensed.
  { path: '/get-pro', name: 'get-pro', component: GetPro },
];

export const router = createRouter({
  history: createWpAdminHistory(),
  routes,
});

// Applies to every route except /setup itself — an owner who skipped or
// abandoned the wizard and navigates (or deep-links) anywhere else in
// the app later should still land on /setup, not a screen with nothing
// configured yet. Not just the PHP-side activation redirect in
// BYZO_Install_Redirect, which only fires once right after activation.
//
// The answer is cached for the session after the first check. Setup state
// only changes by finishing the wizard (handled below), so re-asking the
// server on EVERY navigation bought nothing and cost a blocking REST
// round-trip before each screen could render — the sidebar would sit on
// the old screen, seemingly unresponsive, until it came back. That delay
// is invisible on a fast host and very visible on a slow local one.
let setupNeeded = null;

/**
 * Called by the wizard when it finishes or is skipped, so the guard stops
 * redirecting without needing a full page reload to clear the cache.
 */
export function markSetupComplete() {
  setupNeeded = false;
}

router.beforeEach(async (to) => {
  // A staff login (lib/staff.js) only has their bookings and the
  // calendar; setup is the owner's business, never theirs.
  if (staffMode) {
    // An add-on's route may open itself to staff with meta.staff.
    return STAFF_ROUTES.includes(to.name) || !!to.meta?.staff || { name: 'bookings' };
  }

  if (to.name === 'setup') {
    return true;
  }

  if (setupNeeded === false) {
    return true;
  }

  try {
    const setup = await api.get('/setup');
    setupNeeded = Boolean(setup.needed);

    if (setupNeeded) {
      return { name: 'setup' };
    }
  } catch (error) {
    // If the setup-state check itself fails (e.g. transient REST error),
    // don't block navigation — fail open rather than stranding the user
    // on a route guard error. Left uncached so a later navigation retries.
  }

  return true;
});

/**
 * Self-heals a fresh page load that lands directly on a route an add-on
 * registered via registerExtension()'s router.addRoute() (DETAILED-SPEC.md
 * §3 "Shared Vue admin app"). Module/deferred scripts run in document
 * order, but this router resolves its very first navigation's match
 * SYNCHRONOUSLY against whatever's already in the route table at that
 * instant — for a direct page load (bookmark, reload, or a server-side
 * redirect like Pro's calendar-sync OAuth callback landing back on
 * /pro/calendar-sync) that's before the add-on's own deferred script has
 * had a chance to run addRoute(), so the very first match comes back
 * empty and <router-view> renders nothing. Purely SPA-internal navigation
 * (clicking a sidebar link) was never affected — by then every deferred
 * script has long since executed.
 *
 * router.isReady() resolves once the initial navigation (including its
 * async guards, e.g. the /setup check above) has fully settled — by then
 * every deferred/module script on the page has certainly already run, so
 * a retry at that point is guaranteed to see every add-on's routes.
 */
router.isReady().then(() => {
  if (router.currentRoute.value.matched.length === 0) {
    router.replace(router.currentRoute.value.fullPath);
  }
});
