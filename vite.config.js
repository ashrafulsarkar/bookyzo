import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// package.json/node_modules/configs live at the plugin root (so there's
// one npm project for the whole plugin), but Vue source and build output
// stay under admin/ — root: 'admin' points Vite at that source tree while
// this config file itself stays put. Builds to admin/dist/ with a
// manifest.json (see BYZO_Admin_Assets in PHP) so wp_enqueue_script/style
// can resolve hashed filenames without either side hardcoding them.
export default defineConfig({
  root: 'admin',
  plugins: [vue()],
  base: '',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'admin/src'),
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'admin/src/main.js'),
    },
  },
});
