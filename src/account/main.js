import { createApp } from 'vue';
import AccountApp from './AccountApp.vue';
import { installI18n } from '../lib/i18n';
import './account.css';

// Single mount point is the realistic case (one account/dashboard page per
// site), but querySelectorAll+forEach mirrors src/widget/main.js's own
// multi-instance-safe pattern rather than assuming exactly one on the page.
document.querySelectorAll('.bookyzo-account-mount').forEach((el) => {
  installI18n(createApp(AccountApp)).mount(el);
});
