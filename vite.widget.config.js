import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// Separate config from vite.config.js (the admin app) on purpose — the
// public booking widget (src/widget/) is a distinct, standalone Vue app
// that runs on arbitrary frontend pages/themes, not inside wp-admin.
// Builds straight into public/ (own manifest, own assets), mirroring
// how admin/ works for the admin app. Always built-mode only for now —
// no dev-server marker plugin here (BYZO_Public_Widget always reads
// public/.vite/manifest.json); this screen changes far less often than
// the admin app across Phase 1, so `npm run build:widget` after edits
// is an acceptable tradeoff rather than adding a second dev-server flow.
export default defineConfig({
  root: 'src',
  plugins: [vue()],
  base: '',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: path.resolve(__dirname, 'public'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/widget/main.js'),
    },
  },
});
