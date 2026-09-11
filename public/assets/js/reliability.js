import { post } from './api.js';
import { badge } from './utils.js';

const MISMATCH_THRESHOLD = 3;
const mismatchStreaks = {};

const CRASH_MAX_ATTEMPTS = 3;
const CRASH_HEALTHY_RESET_MS = 2 * 60 * 1000;
const crashState = {};
const explicitlyStopped = new Set();

export function markExplicitlyStopped(id) {
  explicitlyStopped.add(id);
}

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

export function autoRestartEnabled() {
  return document.getElementById('auto-restart-toggle').checked;
}

export function trackCrashesAndMaybeRestart(runners) {
  const autoRestart = autoRestartEnabled();
  runners.forEach((r) => {
    const state = crashState[r.id] || (crashState[r.id] = {
      wasRunning: false, attempts: 0, healthySince: 0, flagged: false,
    });

    if (r.local_running) {
      if (!state.healthySince) state.healthySince = Date.now();
      if (Date.now() - state.healthySince > CRASH_HEALTHY_RESET_MS) {
        state.attempts = 0;
        state.flagged = false;
      }
    } else {
      state.healthySince = 0;
    }

    const crashed = state.wasRunning && !r.local_running && r.configured && !explicitlyStopped.has(r.id);
    if (crashed) {
      if (autoRestart && state.attempts < CRASH_MAX_ATTEMPTS) {
        state.attempts += 1;
        post('start', { runner: r.id }).catch(() => {});
      } else {
        state.flagged = true;
      }
    }

    explicitlyStopped.delete(r.id);
    state.wasRunning = r.local_running;
  });
}

export function crashWarning(runner) {
  const state = crashState[runner.id];
  if (!state || !state.flagged) return '';
  const label = autoRestartEnabled() ? 'crash-looping, auto-restart paused' : 'crashed unexpectedly';
  return `<div class="row-flag">${badge(label, 'critical')}</div>`;
}
