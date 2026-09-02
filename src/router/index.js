import { createRouter, createWebHashHistory } from 'vue-router';
import Dashboard from '../views/Dashboard.vue';
import SetupWizard from '../views/setup/SetupWizard.vue';
import { api } from '../api/client';

// Hash history (not HTML5 history) deliberately — the Vue app lives at a
// single wp-admin URL (admin.php?page=bookyzo) and internal navigation
// (Dashboard/Resources/Calendar/Bookings/Settings, per the bookyzo-design
// skill's layout conventions) must not require server-side route
// handling for arbitrary paths, which wp-admin has no hook for.
const routes = [
  { path: '/', name: 'dashboard', component: Dashboard },
  { path: '/setup', name: 'setup', component: SetupWizard },
  // Resources, Calendar, Bookings, Settings routes land in later Phase 1 steps.
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
});

// Covers normal navigation to the Dashboard (not just the PHP-side
// activation redirect in BYZO_Install_Redirect, which only fires once
// right after activation) — an owner who skipped or abandoned the
// wizard and comes back to admin.php?page=bookyzo later should still
// land on /setup, not a Dashboard with nothing configured yet.
router.beforeEach(async (to) => {
  if (to.name !== 'dashboard') {
    return true;
  }

  try {
    const setup = await api.get('/setup');
    if (setup.needed) {
      return { name: 'setup' };
    }
  } catch (error) {
    // If the setup-state check itself fails (e.g. transient REST error),
    // don't block navigation — fail open to the Dashboard rather than
    // stranding the user on a route guard error.
  }

  return true;
});
