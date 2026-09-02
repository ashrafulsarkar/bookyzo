import { createRouter } from 'vue-router';
import Dashboard from '../views/Dashboard.vue';
import SetupWizard from '../views/setup/SetupWizard.vue';
import ResourcesList from '../views/resources/ResourcesList.vue';
import ResourceForm from '../views/resources/ResourceForm.vue';
import BookingCalendar from '../views/calendar/BookingCalendar.vue';
import BookingsList from '../views/bookings/BookingsList.vue';
import EmailTemplates from '../views/settings/EmailTemplates.vue';
import PaymentSettings from '../views/settings/PaymentSettings.vue';
import { api } from '../api/client';
import { createWpAdminHistory } from './history';

// Custom wp-admin history (see ./history.js) rather than either stock
// option. Each sidebar screen is a real registered admin page
// (`admin.php?page=bookyzo-bookings`, see BYZO_Plugin::routes()), so
// navigation rewrites the `page` parameter: WordPress keeps highlighting
// the correct left-nav row, every screen is bookmarkable, and no
// server-side handling of arbitrary paths is needed — which wp-admin has
// no hook for. Routes with no admin page of their own (/setup,
// /resources/new, /resources/:id/edit) ride on their section's page via a
// `view` parameter. PHP picks the boot route (window.byzoAdmin.initialRoute)
// from the requested slug; the router owns every navigation after that.
const routes = [
  { path: '/', name: 'dashboard', component: Dashboard },
  { path: '/setup', name: 'setup', component: SetupWizard },
  { path: '/resources', name: 'resources', component: ResourcesList },
  { path: '/resources/new', name: 'resources.new', component: ResourceForm },
  { path: '/resources/:id/edit', name: 'resources.edit', component: ResourceForm },
  { path: '/calendar', name: 'calendar', component: BookingCalendar },
  { path: '/bookings', name: 'bookings', component: BookingsList },
  { path: '/settings/email-templates', name: 'settings.email-templates', component: EmailTemplates },
  { path: '/settings/payments', name: 'settings.payments', component: PaymentSettings },
  // Business profile (currency/timezone/hours) lands in step 9.
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
