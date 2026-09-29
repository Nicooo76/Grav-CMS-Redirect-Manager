import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  plugins: [svelte({ hot: false })],
  // the browser build of Svelte, so components can be mounted in the tests (src/panel/Panel.test.ts)
  resolve: { alias: { $lib: resolve(root, 'src/lib') }, conditions: ['browser'] },
  test: {
    include: ['src/**/*.test.ts'],
    environment: 'happy-dom',
  },
});
