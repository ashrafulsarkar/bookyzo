import { createRouter, createWebHashHistory } from 'vue-router';
import Dashboard from '../views/Dashboard.vue';
import SetupWizard from '../views/setup/SetupWizard.vue';
import ResourcesList from '../views/resources/ResourcesList.vue';
import ResourceForm from '../views/resources/ResourceForm.vue';
import BookingCalendar from '../views/calendar/BookingCalendar.vue';
import BookingsList from '../views/bookings/BookingsList.vue';
import EmailTemplates from '../views/settings/EmailTemplates.vue';
import PaymentSettings from '../views/settings/PaymentSettings.vue';
import { api } from '../api/client';

// Hash history (not HTML5 history) deliberately — the Vue app lives at a
// single wp-admin URL (admin.php?page=bookyzo) and internal navigation
// (Dashboard/Resources/Calendar/Bookings/Settings, per the bookyzo-design
// skill's layout conventions) must not require server-side route
// handling for arbitrary paths, which wp-admin has no hook for.
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
  history: createWebHashHistory(),
  routes,
});

// Applies to every route except /setup itself — an owner who skipped or
// abandoned the wizard and navigates (or deep-links) anywhere else in
// the app later should still land on /setup, not a screen with nothing
// configured yet. Not just the PHP-side activation redirect in
// BYZO_Install_Redirect, which only fires once right after activation.
router.beforeEach(async (to) => {
  if (to.name === 'setup') {
    return true;
  }

  try {
    const setup = await api.get('/setup');
    if (setup.needed) {
      return { name: 'setup' };
    }
  } catch (error) {
    // If the setup-state check itself fails (e.g. transient REST error),
    // don't block navigation — fail open rather than stranding the user
    // on a route guard error.
  }

  return true;
});
