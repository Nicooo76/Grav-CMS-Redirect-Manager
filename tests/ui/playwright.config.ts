import { defineConfig, devices } from '@playwright/test';

const ci = !!process.env.CI;

export default defineConfig({
  testDir: './specs',
  // The tests change the rules of one shared site and reset it between tests, so they run one after the other.
  workers: 1,
  fullyParallel: false,
  retries: ci ? 1 : 0,
  outputDir: process.env.RM_OUTPUT_DIR || 'test-results',
  timeout: 60_000,
  expect: { timeout: 8_000, toHaveScreenshot: { maxDiffPixelRatio: 0.005, animations: 'disabled' } },
  reporter: ci ? [['list'], ['html', { open: 'never' }]] : [['list']],
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  // Baselines are per platform: CI (linux) has its own or skips the visual tests, see README.md.
  snapshotPathTemplate: '{testDir}/__screenshots__/{platform}/{testFileName}/{arg}{ext}',
  use: {
    ...devices['Desktop Chrome'],
    viewport: { width: 1440, height: 900 },
    locale: 'en-US',
    colorScheme: 'light',
    reducedMotion: 'reduce',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
  },
  projects: [{ name: 'chromium' }],
});
