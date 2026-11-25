/* Stock In / Stock Out forms: show the unit of the chosen material and,
   on Stock Out, the order field only when the reason is "Used for an order". */
(function () {
  const mat = document.getElementById("material_id");
  const unit = document.getElementById("unitLabel");
  const onhand = document.getElementById("onHand");

  function showMaterial() {
    const o = mat.selectedOptions[0];
    unit.textContent = o && o.dataset.unit ? o.dataset.unit : "";
    onhand.textContent = o && o.dataset.unit ? "On hand now: " + o.dataset.qty + " " + o.dataset.unit : "";
  }
  if (mat) {
    mat.addEventListener("change", showMaterial);
    showMaterial();
  }

  const reason = document.getElementById("reason");
  const orderRow = document.getElementById("orderRow");
  if (reason && orderRow) {
    const sync = () => {
      const forOrder = reason.value === "order";
      orderRow.hidden = !forOrder;
      orderRow.querySelector("select").required = forOrder;
      if (!forOrder) orderRow.querySelector("select").value = "";
    };
    reason.addEventListener("change", sync);
    sync();
  }
})();
