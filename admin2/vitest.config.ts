import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  plugins: [svelte({ hot: false })],
  resolve: { alias: { $lib: resolve(root, 'src/lib') } },
  test: {
    include: ['src/**/*.test.ts'],
    environment: 'happy-dom',
  },
});
