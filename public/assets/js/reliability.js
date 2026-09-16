import { badge } from './utils.js';

const MISMATCH_THRESHOLD = 3;
const mismatchStreaks = {};

function isMismatched(runner) {
  if (!runner.github) return false;
  return (runner.github.status === 'online') !== runner.local_running;
}

export function updateMismatchStreaks(runners) {
  runners.forEach((r) => {
    mismatchStreaks[r.id] = isMismatched(r) ? (mismatchStreaks[r.id] || 0) + 1 : 0;
  });
}

export function mismatchWarning(runner) {
  if ((mismatchStreaks[runner.id] || 0) < MISMATCH_THRESHOLD) return '';
  return `<div class="row-flag">${badge('local/GitHub status disagree', 'warning')}</div>`;
}

export function crashWarning(runner) {
  if (!runner.crash_flagged) return '';
  return `<div class="row-flag">${badge('crashed unexpectedly', 'critical')}</div>`;
}
