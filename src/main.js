import * as Vue from 'vue';
import * as VueRouterLib from 'vue-router';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { toast } from 'vue-sonner';
import { registerExtension, setNavBadge } from './lib/admin-extensions';
import { installI18n } from './lib/i18n';

const mountEl = document.getElementById('byzo-admin-app');

if (mountEl) {
  // Pro ships its own separate Vite bundle (BYZOP_Admin_Assets) that adds
  // routes/nav to THIS app rather than mounting a second one — see
  // lib/admin-extensions.js. A Vue single-file component only behaves
  // correctly when rendered by the exact same 'vue' runtime copy that
  // compiled it (composition-API internals like onMounted/inject rely on
  // module-level state), so Pro's build can't just bundle its own copy of
  // vue/vue-router — it marks both `external` and resolves them against
  // these globals instead (see bookyzo-pro/vite.config.js). Exposed before
  // mount so Pro's module (which loads after this one, see
  // BYZOP_Admin_Assets's `byzo-admin` script dependency) always finds them.
  window.Vue = Vue;
  window.VueRouterLib = VueRouterLib;

  // Exposed before mount so Pro's own module can register its routes/nav
  // items synchronously, whether or not it runs before or after the app
  // has actually mounted.
  window.byzoAdmin = window.byzoAdmin || {};
  window.byzoAdmin.registerExtension = registerExtension;
  // Live bits an add-on can use after it has registered (QA report
  // 2026-09-22 §6.2.8 — Pro's new-booking alerts): the app's own toasts,
  // a count badge on a nav item, and the router (to react to navigation).
  window.byzoAdmin.toast = toast;
  window.byzoAdmin.setNavBadge = setNavBadge;
  window.byzoAdmin.router = router;

  installI18n(createApp(App)).use(router).mount(mountEl);
}
