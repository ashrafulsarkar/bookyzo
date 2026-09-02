import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// package.json/node_modules/configs live at the plugin root (one npm
// project for the whole plugin). Vue SOURCE lives in root-level src/ —
// never shipped to WordPress.org. Vue BUILD OUTPUT goes straight into
// admin/ (no nested dist/) — admin/ is exactly what a running site needs
// to render the admin panel (admin/.vite/manifest.json + admin/assets/*),
// and exactly what a future WordPress.org release-zip step (ROADMAP.md
// Phase 1 step 10) ships: everything else at the root (src/, node_modules,
// configs) is dev-only and gets excluded. BYZO_Admin_Assets (PHP) reads
// admin/.vite/manifest.json to resolve hashed filenames without either
// side hardcoding them.
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
    outDir: path.resolve(__dirname, 'admin'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/main.js'),
    },
  },
  // `npm run dev` serves straight from source with HMR — BYZO_Admin_Assets
  // (PHP) loads from this server directly when BYZO_DEV_MODE is defined
  // true in wp-config.php, skipping the built admin/ folder entirely.
  // cors: true because WordPress (a different origin, e.g.
  // http://localhost/test) is the page loading these modules, not Vite's
  // own dev-server page.
  server: {
    port: 5173,
    strictPort: true,
    cors: true,
  },
});
