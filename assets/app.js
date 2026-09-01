function qs(selector, parent = document) {
  return parent.querySelector(selector);
}

function qsa(selector, parent = document) {
  return [...parent.querySelectorAll(selector)];
}

const money = value => (Number(value) || 0).toFixed(2);
const roundMoney = value => Math.round((Number(value) + Number.EPSILON) * 100) / 100;
let priceHistoryRequest = 0;

function hidePriceHistory() {
  priceHistoryRequest++;
  const popup = qs('#price-history-popup');
  if (popup) popup.hidden = true;
}

function recalc() {
  let total = 0;

  qsa('.item-row').forEach((row, index) => {
    qs('.row-number', row).textContent = index + 1;
    const selected = Boolean(qs('.product-id', row).value);
    const qtyInput = qs('.qty', row);
    const rateInput = qs('.rate', row);
    const qty = Number(qtyInput.value);
    const rate = Number(rateInput.value);

    qtyInput.setCustomValidity(selected && (!Number.isFinite(qty) || qty <= 0) ? 'Enter a quantity greater than zero.' : '');
    rateInput.setCustomValidity(selected && (!Number.isFinite(rate) || rate < 0) ? 'Enter a valid non-negative rate.' : '');

    const amount = selected && qty > 0 && rate >= 0 ? roundMoney(qty * rate) : 0;
    qs('.amount', row).value = money(amount);
    total = roundMoney(total + amount);
  });

  const customer = qs('#bill-customer');
  const previous = Number(customer?.dataset.balance) || 0;
  const receivedInput = qs('#bill-received');
  const received = Number(receivedInput?.value) || 0;

  if (receivedInput) {
    receivedInput.setCustomValidity(received < 0 ? 'Amount received cannot be negative.' : received > total ? 'Amount received cannot exceed the current bill total.' : '');
  }
  if (qs('#previous-balance')) qs('#previous-balance').textContent = money(previous);
  if (qs('#subtotal')) qs('#subtotal').textContent = money(total);
  if (qs('#closing-balance')) qs('#closing-balance').textContent = money(roundMoney(previous + total - received));
  return total;
}

function validateProductRow(row, focusInvalid = true) {
  const productInput = qs('.product-search', row);
  const productId = qs('.product-id', row).value;
  if (!productInput.value.trim() && !productId) return true;
  if (!productId) {
    row.classList.add('row-error');
    productInput.setCustomValidity('Select a product from the suggestions.');
    if (focusInvalid) {
      productInput.focus();
      productInput.reportValidity();
    }
    return false;
  }
  productInput.setCustomValidity('');
  recalc();
  const invalid = [qs('.qty', row), qs('.rate', row)].find(input => !input.checkValidity());
  if (invalid) {
    row.classList.add('row-error');
    if (focusInvalid) {
      invalid.focus();
      invalid.reportValidity();
    }
    return false;
  }
  row.classList.remove('row-error');
  return true;
}

function focusNextProduct(row) {
  const productInput = qs('.product-search', row);
  const productId = qs('.product-id', row).value;
  if (!productId) {
    productInput.setCustomValidity('Select a product before continuing.');
    productInput.focus();
    productInput.reportValidity();
    return;
  }
  const rateInput = qs('.rate', row);
  if (rateInput.value.trim() === '') {
    rateInput.setCustomValidity('Enter the rate before continuing.');
    rateInput.focus();
    rateInput.reportValidity();
    return;
  }
  rateInput.setCustomValidity('');
  if (!validateProductRow(row)) return;
  const rows = qsa('.item-row');
  let next = rows[rows.indexOf(row) + 1];
  if (!next) next = addRow();
  const nextProduct = next && qs('.product-search', next);
  if (nextProduct) {
    setTimeout(() => {
      nextProduct.focus();
      nextProduct.select();
    }, 0);
  }
}

async function showPriceHistory(product) {
  const popup = qs('#price-history-popup');
  const tbody = qs('#price-popup-rows');
  const customer = qs('#bill-customer');
  if (!popup || !tbody || !customer?.value) {
    hidePriceHistory();
    return;
  }

  const requestId = ++priceHistoryRequest;
  qs('#price-popup-title').textContent = (customer.dataset.name || 'Customer') + ' · ' + product.english_name;
  tbody.innerHTML = '<tr><td colspan="4">Loading previous prices…</td></tr>';
  popup.hidden = false;

  try {
    const billId = qs('input[name="bill_id"]')?.value || 0;
    const url = '?api=product_history&customer_id=' + encodeURIComponent(customer.value)
      + '&product_id=' + encodeURIComponent(product.id)
      + '&exclude_bill_id=' + encodeURIComponent(billId);
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Unable to load price history');
    const history = await response.json();
    if (requestId !== priceHistoryRequest) return;
    if (!Array.isArray(history) || !history.length) {
      popup.hidden = true;
      return;
    }

    tbody.innerHTML = '';
    history.forEach(item => {
      const tr = document.createElement('tr');
      const date = String(item.created_at || '').slice(0, 10).split('-').reverse().join('-');
      ['#' + String(item.bill_no).padStart(4, '0'), date, String(Number(item.quantity)), money(item.rate)].forEach((value, index) => {
        const td = document.createElement('td');
        td.textContent = value;
        if (index > 1) td.className = 'right';
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  } catch (error) {
    if (requestId === priceHistoryRequest) popup.hidden = true;
  }
}

function addRow(data = {}, focus = false) {
  const box = qs('#items');
  const template = qs('#item-template');
  if (!box || !template) return null;

  const row = template.content.firstElementChild.cloneNode(true);
  box.appendChild(row);
  const productInput = qs('.product-search', row);
  productInput.required = false;

  if (data.product_id) {
    qs('.product-id', row).value = data.product_id;
    productInput.value = data.english_name || '';
    qs('.tamil-name', row).value = data.tamil_name || '';
    qs('.unit', row).value = data.unit || '';
    qs('.rate', row).value = data.default_rate ?? '';
  }

  qsa('input', row).forEach(input => input.addEventListener('input', () => {
    row.classList.remove('row-error');
    recalc();
  }));

  qs('.qty', row).addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      event.stopPropagation();
      if (!validateProductRow(row)) return;
      qs('.rate', row).focus();
      qs('.rate', row).select();
    }
  });

  const rateInput = qs('.rate', row);
  rateInput.addEventListener('input', hidePriceHistory);
  rateInput.addEventListener('change', hidePriceHistory);
  rateInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      event.stopPropagation();
      hidePriceHistory();
      focusNextProduct(row);
    }
  });

  qs('.remove', row).onclick = () => {
    hidePriceHistory();
    if (qsa('.item-row').length === 1) {
      qsa('input', row).forEach(input => { if (!input.classList.contains('qty')) input.value = ''; });
      qs('.qty', row).value = '1';
      productInput.setCustomValidity('');
      productInput.focus();
    } else {
      row.remove();
    }
    recalc();
  };

  bindProductSearch(row);
  recalc();
  if (focus) productInput.focus();
  return row;
}

function bindProductSearch(row) {
  const input = qs('.product-search', row);
  const menu = qs('.suggestions', row);
  let timer;
  let results = [];
  let active = -1;
  let requestNumber = 0;

  const clearSelection = () => {
    qs('.product-id', row).value = '';
    qs('.tamil-name', row).value = '';
    qs('.unit', row).value = '';
    qs('.rate', row).value = '';
    qs('.amount', row).value = '0.00';
    input.setCustomValidity('');
    hidePriceHistory();
    recalc();
  };

  const choose = product => {
    clearTimeout(timer);
    requestNumber++;
    qs('.product-id', row).value = product.id;
    input.value = product.english_name;
    input.setCustomValidity('');
    qs('.tamil-name', row).value = product.tamil_name || '';
    qs('.unit', row).value = product.unit || '';
    qs('.rate', row).value = product.default_rate ?? '';
    menu.innerHTML = '';
    results = [];
    active = -1;
    row.classList.remove('row-error');
    showPriceHistory(product);
    recalc();
    qs('.qty', row).focus();
    qs('.qty', row).select();
  };

  const mark = index => {
    const buttons = qsa('button', menu);
    if (!buttons.length) return;
    active = (index + buttons.length) % buttons.length;
    buttons.forEach((button, i) => button.classList.toggle('active', i === active));
    buttons[active].scrollIntoView({ block: 'nearest' });
  };

  const load = async (selectFirst = false) => {
    const term = input.value.trim();
    if (!term) {
      menu.innerHTML = '';
      results = [];
      return;
    }
    const currentRequest = ++requestNumber;
    try {
      const billId = qs('input[name="bill_id"]')?.value || 0;
      const url = '?api=products&q=' + encodeURIComponent(term)
        + '&customer_id=' + encodeURIComponent(qs('#bill-customer')?.value || 0)
        + '&exclude_bill_id=' + encodeURIComponent(billId);
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Unable to load products');
      const payload = await response.json();
      if (currentRequest !== requestNumber) return;
      results = Array.isArray(payload) ? payload : [];
      menu.innerHTML = '';
      active = -1;
      results.forEach(product => {
        const button = document.createElement('button');
        button.type = 'button';
        button.innerHTML = '<strong></strong>';
        qs('strong', button).textContent = product.english_name;
        button.addEventListener('mousedown', event => {
          event.preventDefault();
          choose(product);
        });
        menu.appendChild(button);
      });
      const exact = results.find(product => product.english_name.trim().toLowerCase() === term.toLowerCase());
      if (exact) choose(exact);
      else if (selectFirst && results[0]) choose(results[0]);
      else if (results.length) mark(0);
      else menu.innerHTML = '<span class="suggestion-error">No matching products</span>';
    } catch (error) {
      if (currentRequest === requestNumber) menu.innerHTML = '<span class="suggestion-error">Unable to load products</span>';
    }
  };

  input.addEventListener('input', () => {
    clearTimeout(timer);
    requestNumber++;
    clearSelection();
    timer = setTimeout(load, 160);
  });

  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      mark(active + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      mark(active - 1);
    } else if (event.key === 'Escape') {
      menu.innerHTML = '';
      results = [];
    } else if (event.key === 'Enter') {
      event.preventDefault();
      event.stopPropagation();
      if (!input.value.trim()) {
        menu.innerHTML = '';
        const received = qs('#bill-received');
        if (received) {
          received.focus();
          received.select();
        }
      } else if (qs('.product-id', row).value) {
        clearTimeout(timer);
        requestNumber++;
        menu.innerHTML = '';
        results = [];
        active = -1;
        const quantity = qs('.qty', row);
        quantity.focus();
        quantity.select();
      } else if (results.length) {
        choose(results[active >= 0 ? active : 0]);
      } else {
        load(true);
      }
    }
  });

  input.addEventListener('blur', () => setTimeout(() => {
    menu.innerHTML = '';
    results = [];
  }, 180));
}

document.addEventListener('DOMContentLoaded', () => {
  if (qs('#items') && qsa('.item-row').length === 0 && !window.__editingBill) addRow();

  const search = qs('#bill-customer-search');
  const customer = qs('#bill-customer');
  const customerMenu = qs('#customer-suggestions');

  if (search && customer && customerMenu) {
    if (customer.value && !customer.dataset.name) customer.dataset.name = search.value.split(' — ')[0];
    const clear = qs('.customer-clear');
    const buttons = qsa('button', customerMenu);
    let active = -1;
    const visible = () => buttons.filter(button => !button.hidden);

    const mark = index => {
      const list = visible();
      if (!list.length) return;
      active = (index + list.length) % list.length;
      buttons.forEach(button => button.classList.remove('active'));
      list[active].classList.add('active');
      list[active].scrollIntoView({ block: 'nearest' });
    };

    const focusProduct = () => {
      const inputs = qsa('.item-row .product-search');
      (inputs.find(input => !input.value.trim()) || inputs[0])?.focus();
    };

    const selectCustomer = button => {
      customer.value = button.dataset.id;
      customer.dataset.balance = button.dataset.balance;
      customer.dataset.name = button.dataset.name || '';
      search.value = (button.dataset.name || qs('strong', button).textContent) + ' — ' + (button.dataset.address || 'Address not available');
      search.setCustomValidity('');
      if (clear) clear.hidden = false;
      customerMenu.classList.remove('open');
      buttons.forEach(item => item.classList.remove('active'));
      active = -1;
      hidePriceHistory();
      recalc();
      setTimeout(focusProduct, 0);
    };

    const show = (reset = true) => {
      const term = !reset && customer.value ? '' : search.value.toLowerCase().trim();
      if (reset) {
        customer.value = '';
        customer.dataset.balance = '0';
        customer.dataset.name = '';
        if (clear) clear.hidden = true;
        search.setCustomValidity('Select a customer from the list.');
        hidePriceHistory();
      }
      buttons.forEach(button => {
        button.hidden = term !== '' && !button.dataset.search.includes(term);
        button.classList.remove('active');
      });
      active = -1;
      customerMenu.classList.add('open');
      if (visible().length) mark(0);
      recalc();
    };

    search.addEventListener('focus', () => show(false));
    search.addEventListener('input', () => show(true));
    search.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        mark(active + 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        mark(active - 1);
      } else if (event.key === 'Enter') {
        const list = visible();
        if (customerMenu.classList.contains('open') && list.length) {
          event.preventDefault();
          selectCustomer(list[active >= 0 ? active : 0]);
        }
      } else if (event.key === 'Escape') {
        customerMenu.classList.remove('open');
      }
    });
    buttons.forEach(button => button.addEventListener('mousedown', event => {
      event.preventDefault();
      selectCustomer(button);
    }));
    search.addEventListener('blur', () => setTimeout(() => customerMenu.classList.remove('open'), 180));
    clear?.addEventListener('mousedown', event => event.preventDefault());
    clear?.addEventListener('click', () => {
      search.value = '';
      customer.value = '';
      customer.dataset.balance = '0';
      customer.dataset.name = '';
      clear.hidden = true;
      search.setCustomValidity('');
      hidePriceHistory();
      recalc();
      search.focus();
      show(false);
    });
  }

  qs('.price-popup-close')?.addEventListener('click', hidePriceHistory);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') hidePriceHistory();
  });
  qs('#bill-received')?.addEventListener('input', recalc);

  const form = qs('#bill-form');
  let dirty = false;
  let submitting = false;
  form?.addEventListener('keydown', event => {
    if (event.key !== 'Enter' || event.isComposing) return;
    const target = event.target;
    const row = target.closest?.('.item-row');
    if (!row) return;

    if (target.classList.contains('qty')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (!qs('.product-id', row).value) {
        const product = qs('.product-search', row);
        product.setCustomValidity('Select a product before entering quantity.');
        product.focus();
        product.reportValidity();
        return;
      }
      if (!validateProductRow(row)) return;
      const rate = qs('.rate', row);
      rate.focus();
      rate.select();
      return;
    }

    if (target.classList.contains('rate')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      hidePriceHistory();
      focusNextProduct(row);
      return;
    }

    if (target.classList.contains('product-search') && !target.value.trim()) {
      event.preventDefault();
      event.stopImmediatePropagation();
      qs('.suggestions', row).innerHTML = '';
      const received = qs('#bill-received');
      received?.focus();
      received?.select();
    }
  }, true);
  form?.addEventListener('input', () => { dirty = true; });
  form?.addEventListener('change', () => { dirty = true; });
  form?.addEventListener('submit', event => {
    recalc();
    if (!customer?.value) {
      event.preventDefault();
      search?.setCustomValidity('Select a customer from the list.');
      search?.reportValidity();
      return;
    }

    const usedRows = qsa('.item-row').filter(row => qs('.product-search', row).value.trim() || qs('.product-id', row).value);
    if (!usedRows.length) {
      event.preventDefault();
      const first = qs('.item-row .product-search');
      first?.setCustomValidity('Add at least one product.');
      first?.focus();
      first?.reportValidity();
      return;
    }

    const invalidRow = usedRows.find(row => !validateProductRow(row, false));
    if (invalidRow) {
      event.preventDefault();
      validateProductRow(invalidRow, true);
      invalidRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    const receivedInput = qs('#bill-received');
    if (receivedInput && !receivedInput.checkValidity()) {
      event.preventDefault();
      receivedInput.focus();
      receivedInput.reportValidity();
      return;
    }

    if (submitting) {
      event.preventDefault();
      return;
    }
    submitting = true;
    dirty = false;
    const button = qs('.save-bill', form);
    if (button) {
      button.disabled = true;
      button.textContent = 'Saving…';
    }
  });

  window.addEventListener('beforeunload', event => {
    if (dirty && !submitting) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  const toggle = qs('.menu-toggle');
  toggle?.addEventListener('click', () => {
    const open = document.body.classList.toggle('nav-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? '×' : '☰';
  });
  qsa('nav a').forEach(link => link.addEventListener('click', () => document.body.classList.remove('nav-open')));
  recalc();
});

async function shareBill(title, url) {
  if (navigator.share) {
    try {
      await navigator.share({ title, text: title, url });
      return;
    } catch (error) {
      if (error.name === 'AbortError') return;
    }
  }
  window.open('https://wa.me/?text=' + encodeURIComponent(title + ' ' + url), '_blank');
}

function shareBillToCustomer(phone, title, url) {
  window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(title + ' ' + url), '_blank');
}

function billPdfOptions(filename) {
  return {
    margin: [6, 6, 6, 6],
    filename,
    image: { type: 'jpeg', quality: .98 },
    html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
    jsPDF: { unit: 'mm', format: 'a5', orientation: 'portrait' },
    pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.summary'] }
  };
}

async function downloadBillPdf(filename) {
  if (typeof html2pdf === 'undefined') {
    window.print();
    return;
  }
  await html2pdf().set(billPdfOptions(filename)).from(qs('.bill-print')).save();
}

async function shareBillPdf(filename) {
  if (typeof html2pdf === 'undefined') {
    window.print();
    return;
  }
  const worker = html2pdf().set(billPdfOptions(filename)).from(qs('.bill-print')).toPdf();
  const blob = await worker.outputPdf('blob');
  const file = new File([blob], filename, { type: 'application/pdf' });
  if (navigator.share && navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ title: filename, files: [file] });
      return;
    } catch (error) {
      if (error.name === 'AbortError') return;
    }
  }
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

async function downloadDocumentPdf(filename) {
  if (typeof html2pdf === 'undefined') {
    window.print();
    return;
  }
  await html2pdf().set(billPdfOptions(filename)).from(qs('.pdf-document')).save();
}

async function shareDocumentPdf(filename) {
  if (typeof html2pdf === 'undefined') {
    window.print();
    return;
  }
  const worker = html2pdf().set(billPdfOptions(filename)).from(qs('.pdf-document')).toPdf();
  const blob = await worker.outputPdf('blob');
  const file = new File([blob], filename, { type: 'application/pdf' });
  if (navigator.share && navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ title: filename, files: [file] });
      return;
    } catch (error) {
      if (error.name === 'AbortError') return;
    }
  }
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}
