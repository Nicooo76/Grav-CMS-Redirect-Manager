import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

/**
 * Two builds, one config: `--mode page` and `--mode widget`.
 * Each produces exactly one self-contained ES module (no chunks, no external
 * imports, no import.meta.url) because Admin 2 loads plugin UI through a Blob
 * URL import, where relative imports cannot resolve.
 */
export default defineConfig(({ mode }) => {
  const widget = mode === 'widget';
  const out = widget ? 'widgets' : 'pages';
  return {
    plugins: [svelte()],
    define: { 'process.env.NODE_ENV': '"production"' },
    resolve: { alias: { $lib: resolve(root, 'src/lib') } },
    build: {
      target: 'es2022',
      // light-dark() and color-mix() must survive CSS minification untouched (lightningcss would lower them)
      cssTarget: ['chrome123', 'firefox120', 'safari17.5'],
      cssCodeSplit: false,
      emptyOutDir: false,
      outDir: resolve(root, '../admin-next', out),
      lib: {
        entry: resolve(root, widget ? 'src/entries/widget.ts' : 'src/entries/page.ts'),
        formats: ['es'],
        fileName: () => 'redirect-manager.js',
      },
      rollupOptions: {
        output: { codeSplitting: false, minify: true },
      },
    },
  };
});
