import { createRouter, createWebHashHistory } from 'vue-router';
import Dashboard from '../views/Dashboard.vue';

// Hash history (not HTML5 history) deliberately — the Vue app lives at a
// single wp-admin URL (admin.php?page=bookyzo) and internal navigation
// (Dashboard/Resources/Calendar/Bookings/Settings, per the bookyzo-design
// skill's layout conventions) must not require server-side route
// handling for arbitrary paths, which wp-admin has no hook for.
const routes = [
  { path: '/', name: 'dashboard', component: Dashboard },
  // Resources, Calendar, Bookings, Settings routes land in later Phase 1 steps.
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
});
