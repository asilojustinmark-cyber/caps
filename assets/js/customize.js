/* Customer "customize" page: the player list, optional measurements and the live total. */
(function () {
  const data = JSON.parse(document.getElementById("customizeData").textContent);
  const totalEl = document.getElementById("liveTotal");
  const qtyEl = document.getElementById("liveQty");
  const qtyInput = document.querySelector("[data-qty]");
  const money = (v) =>
    data.currency + v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = (s) =>
    String(s ?? "").replace(
      /[&<>"']/g,
      (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );

  function show(qty) {
    qtyEl.textContent = qty + (qty === 1 ? " piece" : " pieces");
    totalEl.textContent = money(qty * data.price);
  }

  if (data.sized) {
    window.Roster.mount(document.getElementById("roster"), "players", data.players, show);
  } else {
    const update = () => show(Math.max(0, parseInt(qtyInput.value, 10) || 0));
    qtyInput.addEventListener("input", update);
    update();
  }

  // Optional measurements
  const dims = document.getElementById("dimRows");
  let n = 0;
  function addDim(d = {}) {
    const i = n++;
    dims.insertAdjacentHTML(
      "beforeend",
      `<div class="dim-row dim-row-shop">
        <input list="dimNames" name="dims[${i}][name]" placeholder="e.g. Print Width" value="${esc(d.name)}">
        <input type="number" step="0.01" min="0" name="dims[${i}][value]" placeholder="Value" value="${esc(d.value)}">
        <select name="dims[${i}][unit]">${["in", "cm", "ft", "mm"]
          .map((u) => `<option ${d.unit === u ? "selected" : ""}>${u}</option>`)
          .join("")}</select>
        <button type="button" class="icon-btn" data-rm-dim title="Remove" aria-label="Remove">&times;</button>
      </div>`,
    );
  }
  document.getElementById("addDim").addEventListener("click", () => addDim({ unit: "in" }));
  dims.addEventListener("click", (e) => {
    if (e.target.matches("[data-rm-dim]")) e.target.closest(".dim-row").remove();
  });
  (data.dims || []).forEach(addDim);
})();
