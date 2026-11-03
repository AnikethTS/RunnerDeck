import { defineConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const PORT = Number(process.env.E2E_PORT || 8092);
const tag = process.env.E2E_TAG || `rd-live-${Date.now()}`;
const scratch = path.join(process.env.RUNNER_TEMP || tmpdir(), `rd-live-${tag}`);
mkdirSync(scratch, { recursive: true });
const unix = (p) => p.split(path.sep).join('/');
process.env.E2E_POOL_DIR = unix(path.join(scratch, 'pool'));

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  timeout: 10 * 60_000,
  expect: { timeout: 30_000 },
  reporter: [
    ['../e2e/progress-reporter.js'],
    ['list'],
    ['html', { open: 'never', outputFolder: '../playwright-live-report' }],
  ],
  outputDir: '../test-results-live',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    viewport: { width: 1280, height: 900 },
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: `php -d max_execution_time=0 -S 127.0.0.1:${PORT} -t public`,
    cwd: '..',
    url: `http://127.0.0.1:${PORT}/health.php`,
    reuseExistingServer: false,
    timeout: 60_000,
    env: {
      PHP_CLI_SERVER_WORKERS: '4',
      RUNNER_ALLOW_RUNASROOT: '1',
      RUNNERDECK_SCOPE: 'repo',
      RUNNERDECK_REPO: process.env.E2E_REPO || '',
      RUNNERDECK_LABEL: tag,
      RUNNERDECK_POOL_CAP: '3',
      RUNNERDECK_POOL_DIR: unix(path.join(scratch, 'pool')),
      RUNNERDECK_SETTINGS_FILE: unix(path.join(scratch, 'settings.json')),
      RUNNERDECK_HISTORY_FILE: unix(path.join(scratch, 'history.sqlite')),
    },
  },
});
