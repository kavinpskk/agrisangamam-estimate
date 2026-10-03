document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('.payment-form');
  if (!form) return;
  const search = document.getElementById('payment-customer-search');
  const customerId = document.getElementById('payment-customer');
  const menu = document.getElementById('payment-customer-options');
  const clear = form.querySelector('.payment-customer-clear');
  const amount = document.getElementById('payment-amount');
  const current = document.getElementById('payment-balance');
  const remaining = document.getElementById('payment-remaining');
  const beforeLabel = document.getElementById('payment-before-label');
  const afterLabel = document.getElementById('payment-after-label');
  const afterField = form.querySelector('.payment-after-field');
  const hint = form.querySelector('.payment-balance-hint');
  const options = [...menu.querySelectorAll('button[data-id]')];
  const empty = menu.querySelector('.payment-no-results');
  const save = form.querySelector('.payment-save');
  let matches = [];
  let active = -1;
  const normalize = value => value.normalize('NFKC').toLocaleLowerCase().trim().replace(/\s+/g, ' ');
  const money = cents => '₹' + (Math.abs(cents) / 100).toFixed(2);
  const selected = () => options.find(option => option.dataset.id === customerId.value);
  const updateBalance = () => {
    const option = selected();
    amount.removeAttribute('max');
    afterField.classList.remove('due', 'settled', 'advance');
    beforeLabel.textContent = 'Outstanding Before Payment';
    afterLabel.textContent = 'Balance After Payment';
    if (!option) {
      current.textContent = remaining.textContent = '—';
      hint.textContent = 'Select a customer';
      return;
    }
    const before = Math.round(Number(option.dataset.balance) * 100);
    const paid = Math.max(0, Math.round((Number(amount.value) || 0) * 100));
    const after = before - paid;
    beforeLabel.textContent = before < 0 ? 'Existing Advance' : 'Outstanding Before Payment';
    current.textContent = money(before);
    const validAmount = amount.value !== '' && amount.validity.valid;
    if (after < 0) {
      afterField.classList.add('advance');
      afterLabel.textContent = 'Advance After Payment';
      remaining.textContent = 'Advance ' + money(after);
      hint.textContent = 'Credit available for future bills';
    } else if (after > 0) {
      afterField.classList.add('due');
      remaining.textContent = 'Balance ' + money(after);
      hint.textContent = validAmount ? 'Still to be received' : 'Enter the payment amount';
    } else {
      if (validAmount) afterField.classList.add('settled');
      remaining.textContent = validAmount ? 'Paid Completely' : money(0);
      hint.textContent = validAmount ? 'No outstanding balance' : 'No outstanding balance · payment becomes advance';
    }
  };
  const close = () => {
    menu.classList.remove('open');
    search.setAttribute('aria-expanded', 'false');
    search.removeAttribute('aria-activedescendant');
  };
  const mark = index => {
    if (!matches.length) return;
    active = (index + matches.length) % matches.length;
    options.forEach(option => {
      const on = option === matches[active];
      option.classList.toggle('active', on);
      option.setAttribute('aria-selected', String(on));
    });
    search.setAttribute('aria-activedescendant', matches[active].id);
    const option = matches[active];
    if (option.offsetTop < menu.scrollTop) menu.scrollTop = option.offsetTop;
    else if (option.offsetTop + option.offsetHeight > menu.scrollTop + menu.clientHeight) {
      menu.scrollTop = option.offsetTop + option.offsetHeight - menu.clientHeight;
    }
  };
  const show = () => {
    const query = selected() ? '' : normalize(search.value);
    const tokens = query.split(' ').filter(Boolean);
    const rank = option => {
      const name = normalize(option.dataset.name);
      if (name === query) return 0;
      if (name.startsWith(query)) return 1;
      if (name.split(' ').some(word => word.startsWith(query))) return 2;
      if (name.includes(query)) return 3;
      return 4;
    };
    matches = options.filter(option => tokens.every(token => normalize(option.dataset.search).includes(token)))
      .sort((a, b) => rank(a) - rank(b) || a.dataset.name.localeCompare(b.dataset.name));
    options.forEach(option => {
      option.hidden = !matches.includes(option);
      option.classList.remove('active');
      option.setAttribute('aria-selected', 'false');
    });
    matches.forEach(option => menu.insertBefore(option, empty));
    empty.hidden = matches.length > 0;
    menu.classList.add('open');
    search.setAttribute('aria-expanded', 'true');
    search.removeAttribute('aria-activedescendant');
    menu.scrollTop = 0;
    active = -1;
    if (matches.length) mark(0);
  };
  const choose = option => {
    const changed = customerId.value !== option.dataset.id;
    customerId.value = option.dataset.id;
    search.value = option.dataset.name + (option.dataset.detail ? ' — ' + option.dataset.detail : '');
    search.setCustomValidity('');
    clear.hidden = false;
    if (changed) amount.value = '';
    close();
    updateBalance();
    amount.focus();
  };
  search.addEventListener('focus', show);
  search.addEventListener('input', () => {
    customerId.value = '';
    clear.hidden = true;
    search.setCustomValidity('Select a customer from the suggestions.');
    updateBalance();
    show();
  });
  search.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!menu.classList.contains('open')) show();
      else mark(active + (event.key === 'ArrowDown' ? 1 : -1));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (menu.classList.contains('open') && matches.length) choose(matches[Math.max(0, active)]);
      else if (selected()) amount.focus();
    } else if (event.key === 'Escape') close();
  });
  options.forEach(option => {
    option.addEventListener('mousedown', event => event.preventDefault());
    option.addEventListener('click', () => choose(option));
  });
  form.querySelector('.payment-customer-picker').addEventListener('focusout', event => {
    if (!event.currentTarget.contains(event.relatedTarget)) close();
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.payment-customer-picker')) close();
  });
  clear.addEventListener('click', () => {
    customerId.value = search.value = amount.value = '';
    search.setCustomValidity('');
    clear.hidden = true;
    updateBalance();
    search.focus();
    show();
  });
  amount.addEventListener('input', updateBalance);
  // Enter in a field advances focus; only the Save button submits.
  const enterOrder = [amount, document.getElementById('payment-date'), document.getElementById('payment-note'), save];
  form.addEventListener('keydown', event => {
    if (event.key !== 'Enter' || event.target === search || event.target === save) return;
    const index = enterOrder.indexOf(event.target);
    if (index < 0) return;
    event.preventDefault();
    enterOrder[index + 1]?.focus();
  });
  form.addEventListener('submit', event => {
    if (!selected()) {
      event.preventDefault();
      search.setCustomValidity('Select a customer from the suggestions.');
      search.reportValidity();
      return;
    }
    save.disabled = true;
    save.textContent = 'Saving…';
  });
  updateBalance();
});
