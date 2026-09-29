import { spawn, type ChildProcess } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync, copyFileSync, openSync } from 'node:fs';
import { createServer } from 'node:net';
import { join } from 'node:path';
import { parse, stringify } from 'yaml';

export interface RunResult {
  code: number;
  stdout: string;
  stderr: string;
}

export function phpBinary(): string {
  return process.env.RM_PHP_BIN || 'php';
}

/** A fixed set of pages, so page search, suggestions and the delete flow have something to work with. */
const PAGES: Record<string, { title: string; body?: string }> = {
  '03.shop': { title: 'Shop' },
  '03.shop/01.rucksaecke': { title: 'Rucksaecke' },
  '03.shop/02.zelte': { title: 'Zelte' },
  '03.shop/03.outlet': { title: 'Outlet' },
  '03.shop/03.outlet/01.sale': { title: 'Sale' },
  '04.blog': { title: 'Blog' },
  '04.blog/01.wintercamping-tipps': { title: 'Wintercamping Tipps' },
  '04.blog/02.packliste-fuer-camper': { title: 'Packliste fuer Camper' },
  '05.about': { title: 'About us' },
  '06.info': { title: 'Info' },
  '07.faq': { title: 'FAQ' },
  '08.kontakt': { title: 'Kontakt' },
};

export function writePages(siteDir: string): void {
  for (const [folder, page] of Object.entries(PAGES)) {
    const dir = join(siteDir, 'user', 'pages', folder);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'default.md'), `---\ntitle: ${page.title}\n---\n${page.body ?? 'Body of ' + page.title}\n`);
  }
}

/** A Grav site for one run: system, vendor, bin and the plugins are symlinked to the base site, everything mutable is copied. */
export function buildSite(base: string, dir: string): void {
  if (!existsSync(join(base, 'index.php'))) {
    throw new Error(`No Grav site at ${base}. Run scripts/setup-test-site.sh first (or set RM_SITE_DIR).`);
  }
  mkdirSync(dir, { recursive: true });
  for (const shared of ['system', 'vendor', 'bin', 'webserver-configs']) {
    if (existsSync(join(base, shared))) symlinkSync(join(base, shared), join(dir, shared));
  }
  copyFileSync(join(base, 'index.php'), join(dir, 'index.php'));
  for (const folder of ['cache', 'logs', 'tmp', 'backup', 'assets', 'images', 'user/plugins', 'user/data']) {
    mkdirSync(join(dir, folder), { recursive: true });
  }
  for (const folder of ['config', 'pages', 'accounts', 'themes']) {
    if (existsSync(join(base, 'user', folder))) cpSync(join(base, 'user', folder), join(dir, 'user', folder), { recursive: true });
  }
  for (const plugin of readdirSync(join(base, 'user', 'plugins'))) {
    symlinkSync(join(base, 'user', 'plugins', plugin), join(dir, 'user', 'plugins', plugin));
  }
  // The dev accounts of the base site are none of our business.
  for (const account of readdirSync(join(dir, 'user', 'accounts'))) rmSync(join(dir, 'user', 'accounts', account));
  writeSystemConfig(dir);
  writeApiConfig(dir);
  writePages(dir);
}

/** The API plugin allows 120 requests per minute and user; a test run (Admin 2 plus our own checks) needs more. */
function writeApiConfig(siteDir: string): void {
  mkdirSync(join(siteDir, 'user', 'config', 'plugins'), { recursive: true });
  writeFileSync(join(siteDir, 'user', 'config', 'plugins', 'api.yaml'), stringify({ rate_limit: { enabled: false } }));
}

export function writeSystemConfig(siteDir: string): void {
  const system = {
    home: { alias: '/home' },
    pages: { theme: 'quark2' },
    cache: { enabled: false },
    errors: { display: 0, log: true },
    debugger: { enabled: false },
  };
  mkdirSync(join(siteDir, 'user', 'config'), { recursive: true });
  writeFileSync(join(siteDir, 'user', 'config', 'system.yaml'), stringify(system));
}

export const defaultPluginConfig = {
  enabled: true,
  security: { allowed_hosts: ['example.org', '*.example.org'] },
  redirects: { default_status: 301 },
  auto_redirect: { enabled: true, status: 301, children: 'wildcard', on_delete: 'ask' },
  log: { retention_days: 90 },
  stats: { keep_days: 90 },
  // the live check is exercised by no test, and it would call the site itself
  checker: { enabled: false },
};

export function pluginConfigPath(siteDir: string): string {
  return join(siteDir, 'user', 'config', 'plugins', 'redirect-manager.yaml');
}

export function writePluginConfig(siteDir: string, config: Record<string, unknown> = defaultPluginConfig): void {
  mkdirSync(join(siteDir, 'user', 'config', 'plugins'), { recursive: true });
  const yaml = stringify(config);
  // an unchanged file keeps Grav's compiled config (keyed by file mtimes) valid
  if (existsSync(pluginConfigPath(siteDir)) && readFileSync(pluginConfigPath(siteDir), 'utf8') === yaml) return;
  writeFileSync(pluginConfigPath(siteDir), yaml);
  rmSync(join(siteDir, 'cache', 'compiled'), { recursive: true, force: true });
}

export function dataDir(siteDir: string): string {
  return join(siteDir, 'user', 'data', 'redirect-manager');
}

export function run(cmd: string, args: string[], cwd: string, timeoutMs = 120_000): Promise<RunResult> {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, { cwd, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (d) => (stdout += d));
    child.stderr.on('data', (d) => (stderr += d));
    const timer = setTimeout(() => {
      child.kill('SIGKILL');
      reject(new Error(`${cmd} ${args.join(' ')} timed out`));
    }, timeoutMs);
    child.on('error', (e) => {
      clearTimeout(timer);
      reject(e);
    });
    child.on('close', (code) => {
      clearTimeout(timer);
      resolve({ code: code ?? -1, stdout, stderr });
    });
  });
}

/** php bin/plugin redirect-manager <args> inside the site */
export function pluginCli(siteDir: string, args: string[]): Promise<RunResult> {
  return run(phpBinary(), ['bin/plugin', 'redirect-manager', ...args], siteDir);
}

export function gravBin(siteDir: string, args: string[]): Promise<RunResult> {
  return run(phpBinary(), ['bin/grav', ...args], siteDir);
}

/** Creates an account with the Login plugin's CLI and replaces its access map. Returns nothing: the caller keeps the password. */
export async function createAccount(siteDir: string, username: string, password: string, access: Record<string, unknown>): Promise<void> {
  const r = await run(
    phpBinary(),
    ['bin/plugin', 'login', 'new-user', '-n', '-u', username, '-p', password, '-e', `${username}@example.invalid`, '-P', 'a', '--admin-type=api', '-N', username, '-t', 'Test', '-s', 'enabled'],
    siteDir,
  );
  const file = join(siteDir, 'user', 'accounts', `${username}.yaml`);
  if (r.code !== 0 || !existsSync(file)) throw new Error(`Creating the account ${username} failed: ${(r.stdout + r.stderr).slice(0, 400).replaceAll(password, '***')}`);
  const account = (parse(readFileSync(file, 'utf8')) ?? {}) as Record<string, unknown>;
  account.access = access;
  writeFileSync(file, stringify(account));
}

/** RM_PORT_RANGE="8600-8699" moves the random choice out of the default range (parallel runs, the dev site). */
function portRange(): [number, number] {
  const m = /^(\d{2,5})-(\d{2,5})$/.exec((process.env.RM_PORT_RANGE ?? '').trim());
  return m && Number(m[1]) <= Number(m[2]) ? [Number(m[1]), Number(m[2])] : [8400, 8499];
}

export async function freePort(min = portRange()[0], max = portRange()[1]): Promise<number> {
  const preferred = process.env.RM_PORT ? [Number(process.env.RM_PORT)] : [];
  const candidates = [...preferred];
  if (!preferred.length) {
    for (let i = 0; i < 40; i++) candidates.push(min + Math.floor(Math.random() * (max - min + 1)));
  }
  for (const port of candidates) {
    const ok = await new Promise<boolean>((resolve) => {
      const s = createServer();
      s.once('error', () => resolve(false));
      s.listen(port, '127.0.0.1', () => s.close(() => resolve(true)));
    });
    if (ok) return port;
  }
  throw new Error(`No free port in ${min}-${max}`);
}

export async function startServer(siteDir: string, port: number): Promise<ChildProcess> {
  const log = openSync(join(siteDir, 'logs', 'server.log'), 'a');
  const child = spawn(phpBinary(), ['-d', 'error_reporting=-1', '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', `127.0.0.1:${port}`, 'system/router.php'], {
    cwd: siteDir,
    stdio: ['ignore', log, log],
    detached: true,
    // several PHP workers: Admin 2 loads its assets and API calls in parallel
    env: { ...process.env, PHP_CLI_SERVER_WORKERS: process.env.RM_PHP_WORKERS || '4' },
  });
  child.unref();
  const deadline = Date.now() + 20_000;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/`, { redirect: 'manual' });
      if (res.status > 0) return child;
    } catch {
      /* not up yet */
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('The PHP server did not start, see ' + join(siteDir, 'logs', 'server.log'));
}
