import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { translatorComments, translatorCommentOutput } from './vite.translator-comments.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEV_SERVER_MARKER = path.resolve(__dirname, '.dev-server-running');

/**
 * Writes/removes a marker file the running `npm run dev` process is
 * live — BYZO_Admin_Assets (PHP) checks for this file's existence to
 * auto-switch between the Vite dev server and the built admin/ folder,
 * with no wp-config.php constant to remember to toggle. Covers normal
 * shutdown, Ctrl+C (SIGINT), and terminal close (SIGTERM) so the marker
 * doesn't get left behind pointing at a dev server that's no longer running.
 */
function devServerMarkerPlugin() {
  let removed = false;
  const cleanup = () => {
    if (removed) return;
    removed = true;
    try {
      fs.rmSync(DEV_SERVER_MARKER, { force: true });
    } catch {
      // Best-effort — a stale marker just means PHP tries the dev
      // server once, gets no response, and BYZO_Admin_Assets' own
      // fallback path (the "build not found" notice) still applies
      // since a stale marker with no manifest.json is the same
      // situation as no marker at all from the enqueue logic's view.
    }
  };

  return {
    name: 'byzo-dev-server-marker',
    configureServer(server) {
      fs.writeFileSync(DEV_SERVER_MARKER, String(process.pid));
      server.httpServer?.once('close', cleanup);
      process.once('exit', cleanup);
      process.once('SIGINT', () => {
        cleanup();
        process.exit();
      });
      process.once('SIGTERM', () => {
        cleanup();
        process.exit();
      });
    },
  };
}

// package.json/node_modules/configs live at the plugin root (one npm
// project for the whole plugin). Vue SOURCE lives in root-level src/ —
// never shipped to WordPress.org. Vue BUILD OUTPUT goes into
// assets/admin/ (no nested dist/) — every shipped static file lives under
// assets/, so the plugin root stays PHP-only plus that one folder, rather
// than a build-output directory sitting at the root next to includes/.
// assets/admin/ is exactly what a running site needs to render the admin
// panel (assets/admin/.vite/manifest.json + assets/admin/assets/*), and
// exactly what the WordPress.org release zip ships (ROADMAP.md Phase 1
// step 10): everything else at the root (src/, node_modules, configs) is
// dev-only and gets excluded. BYZO_Admin_Assets (PHP) reads
// assets/admin/.vite/manifest.json to resolve hashed filenames without
// either side hardcoding them. The widget build is the mirror of this at
// assets/widget/ (vite.widget.config.js).
export default defineConfig({
  root: 'src',
  plugins: [vue(), devServerMarkerPlugin(), translatorComments()],
  base: '',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: path.resolve(__dirname, 'assets/admin'),
    emptyOutDir: true,
    manifest: true,
    // One bundle on purpose (~590 kB): wp_set_script_translations() loads
    // a language pack's JSON per built file, so split chunks would come up
    // untranslated, and Pro's extension joins this one running app. The
    // limit sits just above today's size so real growth still warns.
    chunkSizeWarningLimit: 700,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/main.js'),
      output: translatorCommentOutput,
    },
  },
  // `npm run dev` serves straight from source with HMR — BYZO_Admin_Assets
  // (PHP) auto-detects this server is running via devServerMarkerPlugin's
  // .dev-server-running file and loads from it directly, skipping the
  // built admin/ folder entirely, no manual toggle needed. cors: true
  // because WordPress (a different origin, e.g. http://localhost/test)
  // is the page loading these modules, not Vite's own dev-server page.
  server: {
    port: 5173,
    strictPort: true,
    cors: true,
  },
});
