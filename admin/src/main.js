import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';

const mountEl = document.getElementById('byzo-admin-app');

if (mountEl) {
  createApp(App).use(router).mount(mountEl);
}
