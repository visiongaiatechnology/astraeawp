// STATUS: DIAMANT VGT SUPREME
'use strict';
(() => {
  const forms = document.querySelectorAll('form[data-confirm]');
  forms.forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.getAttribute('data-confirm') || 'Confirm this action?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });

  document.querySelectorAll('.av-copy[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = button.getAttribute('data-copy-target');
      if (!id) return;
      const target = document.getElementById(id);
      if (!target) return;
      const value = target.textContent || '';
      if (value === '') return;
      try {
        await navigator.clipboard.writeText(value);
        const prior = button.textContent;
        button.textContent = 'Copied';
        window.setTimeout(() => { button.textContent = prior; }, 1400);
      } catch (_error) {
        button.textContent = 'Copy failed';
      }
    });
  });
})();
