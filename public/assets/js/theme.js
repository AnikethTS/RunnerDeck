const THEME_KEY = 'runnerdeck-theme';

function readCookie() {
  const match = document.cookie.match(/(?:^|; )runnerdeck-theme=(dark|light)/);
  return match ? match[1] : null;
}

function storeTheme(theme) {
  const maxAge = theme ? 31536000 : 0;
  document.cookie = `${THEME_KEY}=${theme || ''}; Path=/; Max-Age=${maxAge}; SameSite=Lax`;
}

function getStoredTheme() {
  const fromCookie = readCookie();
  if (fromCookie) return fromCookie;
  try {
    const legacy = localStorage.getItem(THEME_KEY);
    if (legacy === 'dark' || legacy === 'light') {
      storeTheme(legacy);
      localStorage.removeItem(THEME_KEY);
      return legacy;
    }
  } catch {
    return null;
  }
  return null;
}

function systemPrefersDark() {
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function effectiveTheme(stored) {
  if (stored === 'dark' || stored === 'light') return stored;
  return systemPrefersDark() ? 'dark' : 'light';
}

function applyTheme(stored, sunIcon, moonIcon) {
  if (stored === 'dark' || stored === 'light') {
    document.documentElement.dataset.theme = stored;
  } else {
    delete document.documentElement.dataset.theme;
  }
  const dark = effectiveTheme(stored) === 'dark';
  sunIcon.toggleAttribute('hidden', dark);
  moonIcon.toggleAttribute('hidden', !dark);
}

export function initTheme() {
  const btn = document.getElementById('btn-theme-toggle');
  const sunIcon = document.getElementById('theme-icon-sun');
  const moonIcon = document.getElementById('theme-icon-moon');
  if (!btn || !sunIcon || !moonIcon) return;

  let stored = getStoredTheme();
  applyTheme(stored, sunIcon, moonIcon);

  btn.addEventListener('click', () => {
    stored = effectiveTheme(stored) === 'dark' ? 'light' : 'dark';
    storeTheme(stored);
    applyTheme(stored, sunIcon, moonIcon);
  });

  window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (!getStoredTheme()) applyTheme(null, sunIcon, moonIcon);
  });
}

initTheme();
