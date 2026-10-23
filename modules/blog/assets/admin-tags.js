// The tags screen's filter, as you type: hides the rows whose name and slug don't contain the filter (the same test
// the server makes for ?q=), keeps the count, the Clear link, the address and every form's q in step, clears on
// Escape, and focuses the filter on "/". Without this script the GET form filters on the server.
(() => {
  'use strict';
  const input = document.querySelector('[data-tag-filter]');
  if (!input) {
    return;
  }
  const rows = Array.from(document.querySelectorAll('[data-tag-row]'));
  const keys = rows.map((row) => `${row.dataset.name}\n${row.dataset.slug}`.toLowerCase());
  const table = document.querySelector('[data-tag-table]');
  const empty = document.querySelector('[data-tag-empty]');
  const count = document.getElementById('tag-filter-count');
  const clear = document.querySelector('[data-tag-clear]');
  let timer = 0;

  const announce = (text) => {
    // Delayed a little so a screen reader hears the settled count, not one per keystroke.
    clearTimeout(timer);
    timer = setTimeout(() => { count.textContent = text; }, 250);
  };

  const keepQ = (q) => {
    document.querySelectorAll('form[method="post"]').forEach((form) => {
      let field = form.querySelector('input[type="hidden"][name="q"]');
      if (q === '') {
        field?.remove();
        return;
      }
      if (!field) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'q';
        form.prepend(field);
      }
      field.value = q;
    });
    // From the Clear link (the tags screen itself): after a refused rename the address is the form's POST URL.
    const url = new URL(clear.href);
    if (q !== '') {
      url.searchParams.set('q', q);
    }
    window.history.replaceState(window.history.state, '', url);
  };

  // A rename lands with its tag kept in view (?tag=); once the filter is edited, that exception ends (keepQ
  // rewrites the address without it).
  const settle = () => {
    document.querySelectorAll('.admin-row-changed').forEach((row) => row.classList.remove('admin-row-changed'));
  };

  const apply = () => {
    settle();
    const q = input.value.trim();
    const needle = q.toLowerCase();
    let shown = 0;
    rows.forEach((row, i) => {
      const match = needle === '' || keys[i].includes(needle);
      row.hidden = !match;
      shown += match ? 1 : 0;
    });
    table.hidden = shown === 0;
    empty.hidden = shown !== 0;
    clear.hidden = q === '';
    announce(q === '' ? '' : count.dataset.format.replace('%shown%', String(shown)).replace('%total%', String(rows.length)));
    keepQ(q);
  };

  input.addEventListener('input', apply);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && input.value !== '') {
      event.preventDefault();
      input.value = '';
      apply();
    }
  });
  input.form.addEventListener('submit', (event) => {
    event.preventDefault();
    apply();
  });
  clear.addEventListener('click', (event) => {
    event.preventDefault();
    input.value = '';
    apply();
    input.focus();
  });
  document.addEventListener('keydown', (event) => {
    const target = event.target;
    const typing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement
      || (target instanceof HTMLElement && target.isContentEditable);
    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      input.focus();
      input.select();
    }
  });
})();
