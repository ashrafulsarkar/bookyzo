import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { translatorComments, translatorCommentOutput } from './vite.translator-comments.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// Third standalone Vue bundle (Phase 1.7 step 4), same shape as
// vite.widget.config.js: src/account/ -> assets/account/, own manifest,
// always built-mode only — the account dashboard is expected to change
// about as rarely as the booking widget did, so `npm run build:account`
// after edits is the same accepted tradeoff, not a second dev-server flow.
export default defineConfig({
  root: 'src',
  plugins: [vue(), translatorComments()],
  base: '',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: path.resolve(__dirname, 'assets/account'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/account/main.js'),
      output: translatorCommentOutput,
    },
  },
});
