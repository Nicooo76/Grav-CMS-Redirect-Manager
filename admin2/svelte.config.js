import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

export default {
  preprocess: vitePreprocess(),
  compilerOptions: {
    // Styles are appended to the shadow root the app is mounted in, so the
    // bundle needs no separate CSS file (a Blob import does not fetch <link>).
    css: 'injected',
  },
};
