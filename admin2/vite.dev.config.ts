import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

// Dev harness: serves admin2/dev/index.html, which mimics the Admin 2 host.
export default defineConfig({
  plugins: [svelte()],
  define: { __REPO_ROOT__: JSON.stringify(resolve(root, '..')) },
  resolve: { alias: { $lib: resolve(root, 'src/lib') } },
  server: { port: 5199, strictPort: false, fs: { allow: [resolve(root, '..')] } },
});
