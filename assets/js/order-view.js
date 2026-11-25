/* Order page: the first payment is either a down payment or a full payment. */
(function () {
  const form = document.querySelector("[data-pay]");
  if (!form) return;
  const amount = form.querySelector("[data-amount]");
  const hint = form.querySelector("[data-amount-hint]");
  const total = parseFloat(form.dataset.total);
  const balance = parseFloat(form.dataset.balance);
  const radios = form.querySelectorAll("[data-pay-type]");
  const money = (v) => v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function sync() {
    const type = radios.length ? form.querySelector("[data-pay-type]:checked").value : "Balance Payment";
    if (type === "Full Payment") {
      amount.value = balance.toFixed(2);
      amount.readOnly = true;
      hint.textContent = "Full payment is the whole total: " + money(balance) + ".";
    } else if (type === "Down Payment") {
      if (amount.readOnly) amount.value = "";
      amount.readOnly = false;
      amount.max = (balance - 0.01).toFixed(2);
      hint.textContent = "A down payment must be less than " + money(balance) + ".";
    } else {
      amount.readOnly = false;
      hint.textContent = "Up to the balance of " + money(balance) + ".";
    }
  }
  radios.forEach((r) => r.addEventListener("change", sync));
  sync();
})();
