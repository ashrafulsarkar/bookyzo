import { reactive } from 'vue';
import { router } from '../router';

/**
 * Extension point for Pro (or any future add-on) to add routes/nav items
 * to this SAME running Vue app rather than shipping a second admin app —
 * see bookyzo-dev SKILL.md's "one Vue 3 admin app" rule and
 * DETAILED-SPEC.md §3.
 *
 * Pro ships its own small Vite bundle (own manifest, own JS file —
 * BYZOP_Admin_Assets) enqueued with Free's `byzo-admin` handle as a script
 * dependency. WordPress prints dependencies' <script> tags before the
 * dependent's, and neither is `async`, so Pro's module is guaranteed to
 * execute after this one has already run main.js and set
 * `window.byzoAdmin.registerExtension` — no ready-event/queue needed.
 *
 * `router.addRoute()` is safe to call after the router (and the app) are
 * already mounted — Vue Router re-resolves matches for any add made before
 * the next navigation.
 */
export const extraNavItems = reactive([]);

export function registerExtension(extension = {}) {
  (extension.routes || []).forEach((route) => router.addRoute(route));
  extraNavItems.push(...(extension.navItems || []));
}
