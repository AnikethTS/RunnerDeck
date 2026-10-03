#!/usr/bin/env node
/**
 * Runs `npm audit` but does not fail solely on GHSA-vfj7-8cjw-p6xm.
 *
 * braces <=3.0.3 is the latest npm release and is flagged for stack
 * exhaustion on deeply nested patterns. `npm audit fix --force` would
 * install stylelint@7.7.0 (a major downgrade). Drop this allowlist once
 * a patched braces is on npm and the lockfile can pin it.
 *
 * https://github.com/advisories/GHSA-vfj7-8cjw-p6xm
 * https://github.com/micromatch/braces/issues/70
 */
import { spawnSync } from 'node:child_process';

const ALLOWED = new Set(['ghsa-vfj7-8cjw-p6xm']);

const result = spawnSync('npm', ['audit', '--json'], {
  encoding: 'utf8',
  maxBuffer: 20 * 1024 * 1024,
});

if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}

let report;
try {
  report = JSON.parse(result.stdout || '{}');
} catch {
  console.error(result.stderr || result.stdout || 'npm audit produced no JSON');
  process.exit(1);
}

const found = new Set();
for (const info of Object.values(report.vulnerabilities ?? {})) {
  for (const via of info.via ?? []) {
    if (typeof via !== 'object' || via === null) {
      continue;
    }
    const match = String(via.url ?? '').match(/GHSA-[a-z0-9-]+/i);
    if (match) {
      found.add(match[0].toLowerCase());
    }
  }
}

const extra = [...found].filter((id) => !ALLOWED.has(id)).sort();
if (extra.length > 0) {
  const text = spawnSync('npm', ['audit'], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
  process.stdout.write(text.stdout ?? '');
  process.stderr.write(text.stderr ?? '');
  process.exit(1);
}

if (found.size === 0) {
  console.log('npm audit: no known vulnerabilities');
  process.exit(0);
}

console.log(
  'npm audit: no blocking advisories (allowed until braces ships a patch: '
    + [...found].join(', ')
    + ')',
);
process.exit(0);
