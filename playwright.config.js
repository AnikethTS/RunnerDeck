import { defineConfig } from '@playwright/test';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const PORT = 8091;
const tmpRoot = mkdtempSync(path.join(tmpdir(), 'runnerdeck-e2e-'));

export default defineConfig({
  testDir: './e2e',
  // The setup test in dashboard.spec.js writes real config that later
  // tests in the same file depend on — never run this suite in parallel.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
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
