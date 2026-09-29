document.addEventListener('DOMContentLoaded', () => {
  const customerId = document.getElementById('payment-customer');
  const amount = document.getElementById('payment-amount');
  const current = document.getElementById('payment-balance');
  const remaining = document.getElementById('payment-remaining');
  const afterField = document.querySelector('.payment-after-field');
  const options = [...document.querySelectorAll('.payment-customer-options button')];

  if (!customerId || !amount || !current || !remaining || !afterField) return;

  const selectedBalance = () => {
    const selected = options.find(option => option.dataset.id === customerId.value);
    return Number(selected?.dataset.balance) || 0;
  };

  const formatBalance = value => value < 0
    ? `Advance ₹${Math.abs(value).toFixed(2)}`
    : `₹${value.toFixed(2)}`;

  const updateAdvance = () => {
    amount.removeAttribute('max');
    if (!customerId.value) {
      afterField.classList.remove('advance');
      return;
    }

    const before = selectedBalance();
    const after = before - (Number(amount.value) || 0);
    current.textContent = formatBalance(before);
    remaining.textContent = formatBalance(after);
    afterField.classList.toggle('advance', after < 0);
  };

  amount.addEventListener('input', updateAdvance);
  options.forEach(option => option.addEventListener('mousedown', () => {
    setTimeout(updateAdvance, 0);
  }));
  document.querySelector('.payment-customer-clear')?.addEventListener('click', () => {
    setTimeout(updateAdvance, 0);
  });
  document.getElementById('payment-customer-search')?.addEventListener('input', () => {
    setTimeout(updateAdvance, 0);
  });

  updateAdvance();
});
