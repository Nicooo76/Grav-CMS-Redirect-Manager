import { test as base, expect } from '@playwright/test';
import { App } from './app';
import { apiFor, type ApiCall } from './api';
import { runState, storageStatePath } from './env';
import { defaultPluginConfig, pluginCli, run, writePluginConfig } from './site';
import { restoreSeed } from './seed';

export { expect };

export interface SiteControl {
  dir: string;
  baseUrl: string;
  /** back to the seeded rules, 404 log, suggestions and pages, with the default plugin configuration (or this one) */
  reset(config?: Record<string, unknown>): void;
  /** replace the plugin configuration (merged over nothing: give the whole thing) */
  setConfig(config: Record<string, unknown>): void;
  cli(...args: string[]): ReturnType<typeof pluginCli>;
  /** a request to the public site, redirects are not followed */
  get(path: string, headers?: Record<string, string>): Promise<Response>;
  /** an ad hoc php command in the site directory */
  php(...args: string[]): ReturnType<typeof run>;
}

interface Fixtures {
  /** which Admin 2 account the page and the API client use: `test.use({ asUser: 'readonly' })` */
  asUser: 'admin' | 'readonly';
  site: SiteControl;
  api: ApiCall;
  app: App;
  /** runs before every test: the seeded state */
  seeded: void;
}

export const test = base.extend<Fixtures>({
  asUser: ['admin', { option: true }],

  storageState: async ({ asUser }, use) => {
    await use(storageStatePath(asUser));
  },

  site: async ({}, use) => {
    const state = runState();
    const control: SiteControl = {
      dir: state.siteDir,
      baseUrl: state.baseUrl,
      reset: (config = defaultPluginConfig) => restoreSeed(state.siteDir, state.workDir, config),
      setConfig: (config) => writePluginConfig(state.siteDir, config),
      cli: (...args) => pluginCli(state.siteDir, args),
      get: (path, headers = {}) => fetch(state.baseUrl + path, { redirect: 'manual', headers }),
      php: (...args) => run(state.phpBin, args, state.siteDir),
    };
    await use(control);
  },

  seeded: [
    async ({ site }, use) => {
      site.reset();
      await use();
    },
    { auto: true },
  ],

  api: async ({ seeded, asUser }, use) => {
    void seeded;
    await use(await apiFor(asUser));
  },

  app: async ({ page, site }, use) => {
    await use(new App(page, site.baseUrl));
  },
});
