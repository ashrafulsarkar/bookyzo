import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Builds to admin/dist/ with a manifest.json (see BYZO_Admin_Assets in PHP)
// so wp_enqueue_script/style can resolve hashed filenames without either
// side hardcoding them. Free and Pro will eventually share this build
// setup — Pro's own admin/ folder can copy this config unchanged.
export default defineConfig({
  plugins: [vue()],
  base: '',
  build: {
    outDir: 'dist',
    manifest: true,
    rollupOptions: {
      input: 'src/main.js',
    },
  },
});
