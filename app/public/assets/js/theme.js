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

function initHelpTips() {
  const float = document.createElement('div');
  float.id = 'help-float';
  float.setAttribute('role', 'tooltip');
  float.hidden = true;
  document.body.appendChild(float);

  let pinned = null;
  let current = null;

  function place(anchor) {
    const gap = 8;
    const pad = 8;
    const r = anchor.getBoundingClientRect();
    float.style.left = '0px';
    float.style.top = `${window.innerHeight}px`;
    float.hidden = false;
    const tw = float.offsetWidth;
    const th = float.offsetHeight;
    let top = r.bottom + gap;
    if (top + th > window.innerHeight - pad && r.top - th - gap >= pad) {
      top = r.top - th - gap;
    }
    let left = r.left + r.width / 2 - tw / 2;
    left = Math.max(pad, Math.min(left, window.innerWidth - tw - pad));
    float.style.top = `${Math.round(top)}px`;
    float.style.left = `${Math.round(left)}px`;
  }

  function show(anchor) {
    const text = anchor.getAttribute('data-help') || '';
    if (text === '') {
      return;
    }
    current = anchor;
    float.textContent = text;
    place(anchor);
  }

  function hide() {
    if (pinned) {
      return;
    }
    current = null;
    float.hidden = true;
  }

  function unpin() {
    if (pinned) {
      pinned.classList.remove('help-open');
      pinned = null;
    }
    current = null;
    float.hidden = true;
  }

  document.addEventListener('pointerover', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const anchor = target ? target.closest('[data-help]') : null;
    if (!anchor || pinned) {
      return;
    }
    show(anchor);
  });

  document.addEventListener('pointerout', (event) => {
    if (pinned) {
      return;
    }
    const next = event.relatedTarget instanceof Element ? event.relatedTarget : null;
    if (current && next && current.contains(next)) {
      return;
    }
    const leaving = event.target instanceof Element ? event.target.closest('[data-help]') : null;
    if (leaving === current) {
      hide();
    }
  });

  document.addEventListener('focusin', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const anchor = target && target.matches('[data-help]') ? target : null;
    if (anchor && !pinned) {
      show(anchor);
    }
  });

  document.addEventListener('focusout', () => {
    if (!pinned) {
      hide();
    }
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const tip = target ? target.closest('.help-tip') : null;
    if (tip) {
      event.preventDefault();
      if (pinned === tip) {
        unpin();
        return;
      }
      unpin();
      pinned = tip;
      tip.classList.add('help-open');
      show(tip);
      return;
    }
    if (pinned) {
      unpin();
    }
  });

  window.addEventListener('scroll', () => {
    if (current && !float.hidden) {
      place(current);
    }
  }, true);
  window.addEventListener('resize', () => {
    if (current && !float.hidden) {
      place(current);
    }
  });
}

initHelpTips();
