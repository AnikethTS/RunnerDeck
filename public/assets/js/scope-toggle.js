(() => {
  const scopeSelect = document.querySelector('select[name="scope"]');
  if (!scopeSelect) return;
  const form = scopeSelect.closest('form');
  if (!form) return;
  const orgField = form.querySelector('[id$="-org-field"]');
  const repoField = form.querySelector('[id$="-repo-field"]');
  if (!orgField || !repoField) return;
  const update = () => {
    const isRepo = scopeSelect.value === 'repo';
    orgField.hidden = isRepo;
    repoField.hidden = !isRepo;
  };
  scopeSelect.addEventListener('change', update);
  update();
})();
