import { defineConfig } from '@playwright/test';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const PORT = 8091;
// The test worker reloads this file and would otherwise mint a second temp
// root. The server keeps the directory created by the main process.
const pointer = path.join(tmpdir(), 'runnerdeck-e2e-active');
const tmpRoot = process.env.TEST_WORKER_INDEX !== undefined && existsSync(pointer)
  ? readFileSync(pointer, 'utf8').trim()
  : mkdtempSync(path.join(tmpdir(), 'runnerdeck-e2e-'));
if (process.env.TEST_WORKER_INDEX === undefined) {
  writeFileSync(pointer, tmpRoot);
}
const poolDir = path.join(tmpRoot, 'runners');
process.env.RUNNERDECK_POOL_DIR = poolDir;

export default defineConfig({
  testDir: './tests/e2e',
  // README / docs shots live next to the files GitHub and the landing page use.
  snapshotPathTemplate: '{testDir}/../../.github/screenshots/{arg}{ext}',
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
    ? [['./tests/e2e/progress-reporter.js'], ['list']]
    : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    viewport: { width: 1280, height: 800 },
    actionTimeout: 5_000,
    navigationTimeout: 10_000,
  },
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t app/public`,
    url: `http://127.0.0.1:${PORT}/`,
    reuseExistingServer: false,
    env: {
      RUNNERDECK_SETTINGS_FILE: path.join(tmpRoot, 'settings.json'),
      RUNNERDECK_HISTORY_FILE: path.join(tmpRoot, 'history.sqlite'),
      RUNNERDECK_POOL_DIR: poolDir,
    },
  },
});
