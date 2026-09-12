<template>
  <div class="bookyzo-account">
    <div class="bookyzo-account__card">
      <div v-if="errorMessage" class="bookyzo-account__error">{{ errorMessage }}</div>

      <DisabledView v-if="state === 'disabled'" />
      <DashboardView v-else-if="state === 'dashboard'" :logout-url="logoutUrl" :display-name="displayName" :pdf-invoice-enabled="pdfInvoiceEnabled" />
      <RegisterView v-else-if="mode === 'register'" :register-url="registerUrl" @switch="mode = 'login'" />
      <LoginView v-else :login-url="loginUrl" :lost-password-url="lostPasswordUrl" @switch="mode = 'register'" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import LoginView from './views/LoginView.vue';
import RegisterView from './views/RegisterView.vue';
import DashboardView from './views/DashboardView.vue';
import DisabledView from './views/DisabledView.vue';

// window.byzoAccount is emitted by BYZO_Account_Widget::enqueue_assets()
// (mirrors window.byzoWidget's shape/reasoning) — state/errorMessage are
// resolved server-side (is_user_logged_in(), customer_accounts_enabled,
// the ?error= query arg a failed admin-post redirect lands with) since
// only PHP knows the real WP session; Vue just renders whichever state
// it's handed, same "server decides state, Vue renders it" split
// BYZO_Customer_Auth::render() already used for the pre-Vue placeholder
// this bundle replaces.
const cfg = window.byzoAccount || {};

const state        = cfg.state || 'login';
const errorMessage = cfg.errorMessage || '';
const displayName  = cfg.displayName || '';
const registerUrl  = cfg.registerUrl || '';
const loginUrl     = cfg.loginUrl || '';
const logoutUrl    = cfg.logoutUrl || '';
const lostPasswordUrl = cfg.lostPasswordUrl || '';
const pdfInvoiceEnabled = !!cfg.pdfInvoiceEnabled;

// Client-side login/register toggle only matters when state is neither
// 'dashboard' nor 'disabled' — mirrors the old template's $_GET['mode']
// switch, but as a local ref so switching forms doesn't need a page
// reload now that this is a real SPA-like mount.
const mode = ref(cfg.mode === 'register' ? 'register' : 'login');
</script>
