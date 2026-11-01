import { defineConfig } from '@playwright/test';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const PORT = 8091;
const tmpRoot = mkdtempSync(path.join(tmpdir(), 'runnerdeck-e2e-'));

export default defineConfig({
  testDir: './e2e',
  // README / docs shots live next to the files GitHub and the landing page use.
  snapshotPathTemplate: '{testDir}/../.github/screenshots/{arg}{ext}',
  expect: {
    toHaveScreenshot: {
      maxDiffPixelRatio: 0.03,
      animations: 'disabled',
      caret: 'hide',
    },
  },
  // The setup test in dashboard.spec.js writes real config that later
  // tests in the same file depend on — never run this suite in parallel.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  timeout: 20_000,
  maxFailures: process.env.CI ? 5 : 0,
  reporter: process.env.CI
    ? [['./e2e/progress-reporter.js'], ['list']]
    : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    viewport: { width: 1280, height: 800 },
    actionTimeout: 5_000,
    navigationTimeout: 10_000,
  },
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t public`,
    url: `http://127.0.0.1:${PORT}/`,
    reuseExistingServer: false,
    env: {
      RUNNERDECK_SETTINGS_FILE: path.join(tmpRoot, 'settings.json'),
      RUNNERDECK_HISTORY_FILE: path.join(tmpRoot, 'history.sqlite'),
      RUNNERDECK_POOL_DIR: path.join(tmpRoot, 'runners'),
    },
  },
});
