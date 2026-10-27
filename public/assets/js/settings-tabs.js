(() => {
  const nav = document.querySelector('.settings-tabs');
  if (!nav) {
    return;
  }
  const tabs = [...nav.querySelectorAll('[role="tab"]')];
  const panels = [...document.querySelectorAll('[data-tab-panel]')];
  const allowed = new Set(tabs.map((tab) => tab.getAttribute('data-tab') || ''));

  function show(id) {
    const tabId = allowed.has(id) ? id : 'github';
    tabs.forEach((tab) => {
      const on = tab.getAttribute('data-tab') === tabId;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
    });
    panels.forEach((panel) => {
      panel.hidden = panel.getAttribute('data-tab-panel') !== tabId;
    });
    if (window.location.hash.replace('#', '') !== tabId) {
      history.replaceState(null, '', '#' + tabId);
    }
  }

  const fromHash = window.location.hash.replace('#', '');
  const fromDom = nav.getAttribute('data-initial-tab') || '';
  show(allowed.has(fromHash) ? fromHash : fromDom);

  nav.addEventListener('click', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
    if (!tab || !nav.contains(tab)) {
      return;
    }
    event.preventDefault();
    show(tab.getAttribute('data-tab') || 'github');
  });

  nav.addEventListener('keydown', (event) => {
    const current = document.activeElement;
    if (!(current instanceof HTMLElement) || !tabs.includes(current)) {
      return;
    }
    const i = tabs.indexOf(current);
    let next = -1;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
      next = (i + 1) % tabs.length;
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
      next = (i - 1 + tabs.length) % tabs.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = tabs.length - 1;
    }
    if (next < 0) {
      return;
    }
    event.preventDefault();
    tabs[next].focus();
    show(tabs[next].getAttribute('data-tab') || 'github');
  });
})();
