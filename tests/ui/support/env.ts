import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const uiDir = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const repoRoot = resolve(uiDir, '..', '..');

/** Where global setup records what it built (site directory, URL, credentials file). Exported to the workers. */
export function stateFile(): string {
  const file = process.env.RM_UI_STATE;
  if (!file) throw new Error('RM_UI_STATE is not set: the tests must run through `npx playwright test` (global setup).');
  return file;
}

export interface RunState {
  workDir: string;
  siteDir: string;
  baseUrl: string;
  port: number;
  phpBin: string;
  credentialsFile: string;
  serverPid: number;
  /** the API prefix of the site */
  apiBase: string;
}

let cached: RunState | undefined;
export function runState(): RunState {
  if (!cached) cached = JSON.parse(readFileSync(stateFile(), 'utf8')) as RunState;
  return cached;
}

/** Browser storage state (Admin 2 session) of a user, written by global setup into the run's work directory. */
export function storageStatePath(who: 'admin' | 'readonly', workDir = runState().workDir): string {
  return join(workDir, `auth-${who}.json`);
}

export interface Credentials {
  admin: { username: string; password: string };
  readonly: { username: string; password: string };
}

export function credentials(): Credentials {
  const file = runState().credentialsFile;
  if (!existsSync(file)) throw new Error('The credentials file is missing: ' + file);
  return JSON.parse(readFileSync(file, 'utf8')) as Credentials;
}

/** Default base site: the one scripts/setup-test-site.sh creates. */
export function baseSiteDir(): string {
  return process.env.RM_SITE_DIR ? resolve(process.env.RM_SITE_DIR) : join(repoRoot, '.grav', process.env.RM_GRAV_VERSION || '2.2.2');
}
