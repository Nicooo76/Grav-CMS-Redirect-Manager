import { chromium, type FullConfig } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { chmodSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { baseSiteDir, storageStatePath, uiDir, type Credentials, type RunState } from './support/env';
import { seedSite } from './support/seed';
import { buildSite, createAccount, freePort, phpBinary, startServer } from './support/site';

function password(): string {
  return randomBytes(12).toString('hex') + 'Aa1';
}

async function login(baseUrl: string, who: { username: string; password: string }, out: string): Promise<void> {
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ locale: 'en-US', viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.goto(`${baseUrl}/admin`);
    await page.locator('input[type=password]').first().waitFor({ timeout: 30_000 });
    await page.locator('input[type=text], input[name=username], input[autocomplete=username]').first().fill(who.username);
    await page.locator('input[type=password]').first().fill(who.password);
    await page.locator('button[type=submit]').first().click();
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30_000 });
    // the plugin page itself proves the account may use it
    await page.goto(`${baseUrl}/admin/plugin/redirect-manager`);
    await page.locator('grav-redirect-manager--page').waitFor({ timeout: 30_000 });
    await context.storageState({ path: out });
  } finally {
    await browser.close();
  }
}

export default async function globalSetup(_config: FullConfig): Promise<void> {
  const workDir = mkdtempSync(join(tmpdir(), 'rm-ui-'));
  const siteDir = join(workDir, 'site');
  const credentialsFile = process.env.RM_CREDENTIALS_FILE || join(workDir, 'credentials.json');
  const stateFile = join(workDir, 'state.json');
  process.env.RM_UI_STATE = stateFile;
  const started = Date.now();

  buildSite(baseSiteDir(), siteDir);

  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  const server = await startServer(siteDir, port);
  const state: RunState = { workDir, siteDir, baseUrl, port, phpBin: phpBinary(), credentialsFile, serverPid: server.pid ?? 0, apiBase: `${baseUrl}/api/v1` };
  // written first, so the teardown can stop the server even when the rest of the setup fails
  writeFileSync(stateFile, JSON.stringify(state, null, 2));

  const creds: Credentials = {
    admin: { username: 'rmadmin', password: password() },
    readonly: { username: 'rmreader', password: password() },
  };
  writeFileSync(credentialsFile, JSON.stringify(creds), { mode: 0o600 });
  chmodSync(credentialsFile, 0o600);

  await createAccount(siteDir, creds.admin.username, creds.admin.password, { api: { login: true, super: true }, site: { login: true } });
  await createAccount(siteDir, creds.readonly.username, creds.readonly.password, { api: { access: true, redirects: { read: true } }, site: { login: true } });

  await seedSite(siteDir, baseUrl, workDir, join(uiDir, 'fixtures', 'seed-rules.json'), creds.admin);

  await login(baseUrl, creds.admin, storageStatePath('admin', workDir));
  await login(baseUrl, creds.readonly, storageStatePath('readonly', workDir));
  console.log(`[setup] fresh test site ${siteDir} on ${baseUrl} ready in ${((Date.now() - started) / 1000).toFixed(1)} s`);
}
