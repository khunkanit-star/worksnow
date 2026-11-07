'use strict';
document.addEventListener('click', async (event) => {
  const menu = event.target.closest('[data-menu]');
  if (menu) { const open = document.body.classList.toggle('menu-open'); document.querySelectorAll('[data-menu]').forEach(button => button.setAttribute('aria-expanded', String(open))); }
  const print = event.target.closest('[data-print]');
  if (print) window.print();
  const confirm = event.target.closest('[data-confirm]');
  if (confirm && !window.confirm(confirm.dataset.confirm)) event.preventDefault();
  const copy = event.target.closest('[data-copy]');
  if (copy) {
    const link = new URL(copy.dataset.copy, window.location.href).href;
    try { await navigator.clipboard.writeText(link); copy.textContent = 'Copied!'; }
    catch { window.prompt('Copy this private tracking link:', link); }
  }
  const add = event.target.closest('[data-add-line]');
  if (add) {
    const list = add.closest('form').querySelector('[data-line-items]');
    if (list.querySelectorAll('input[name="item_name[]"]').length >= 20) return;
    const row = document.createElement('div'); row.className = 'line-item';
    row.innerHTML = '<input name="item_name[]" aria-label="Item description" placeholder="Service or material" maxlength="200"><input name="item_qty[]" aria-label="Quantity" type="number" min="1" max="999" value="1"><input name="item_price[]" aria-label="Unit price" type="number" min="0" step="0.01" value="0.00"><button type="button" class="icon-link" data-remove-line aria-label="Remove item">×</button>';
    list.append(row); row.querySelector('input').focus();
  }
  const remove = event.target.closest('[data-remove-line]');
  if (remove) remove.closest('.line-item').remove();
});
