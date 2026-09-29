import { readFileSync, rmSync } from 'node:fs';
import type { RunState } from './support/env';

export default async function globalTeardown(): Promise<void> {
  const file = process.env.RM_UI_STATE;
  if (!file) return;
  let state: RunState;
  try {
    state = JSON.parse(readFileSync(file, 'utf8')) as RunState;
  } catch {
    return;
  }
  if (state.serverPid) {
    try {
      process.kill(-state.serverPid, 'SIGTERM');
    } catch {
      try {
        process.kill(state.serverPid, 'SIGTERM');
      } catch {
        /* already gone */
      }
    }
  }
  if (process.env.RM_KEEP_SITE) {
    console.log(`[teardown] kept ${state.workDir}`);
    return;
  }
  rmSync(state.workDir, { recursive: true, force: true });
  if (process.env.RM_CREDENTIALS_FILE) rmSync(process.env.RM_CREDENTIALS_FILE, { force: true });
}
