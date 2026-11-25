/* Staff order form: items, player lists with sizes, optional measurements, totals.
   Data comes from #orderData. The player list uses roster.js. */
(function () {
  const data = JSON.parse(document.getElementById("orderData").textContent);
  const products = data.products;
  const CUR = data.currency;
  const wrap = document.getElementById("items");
  const totalEl = document.getElementById("grandTotal");
  let n = 0;

  const esc = (s) =>
    String(s ?? "").replace(
      /[&<>"']/g,
      (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );
  const money = (v) =>
    CUR + v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const priceOptions =
    '<option value="">Pick from the price list...</option>' +
    products
      .map((p) => `<option value="${p.id}">${esc(p.name)} (${money(parseFloat(p.unit_price))})</option>`)
      .join("");

  function dimRow(i, j, d) {
    return `<div class="dim-row dim-row-staff">
      <input list="dimNames" name="items[${i}][dims][${j}][name]" placeholder="e.g. Print Width" value="${esc(d.name)}">
      <input type="number" step="0.01" min="0" name="items[${i}][dims][${j}][value]" placeholder="Value" value="${esc(d.value)}">
      <input name="items[${i}][dims][${j}][unit]" placeholder="Unit" value="${esc(d.unit || "in")}">
      <button type="button" class="icon-btn" data-rm-dim title="Remove" aria-label="Remove">&times;</button></div>`;
  }

  function addItem(d = {}) {
    const i = n++;
    const sized = !!d.sized;
    const el = document.createElement("div");
    el.className = "item-card";
    el.dataset.index = i;
    el.dataset.dims = (d.dims || []).length;
    el.innerHTML = `
      <div class="item-top"><strong>Item <span class="num"></span></strong>
        <div class="toolbar">
          <select data-pick aria-label="Price list" class="w-auto-260">${priceOptions}</select>
          <button type="button" class="btn btn-sm btn-outline" data-rm-item>Remove item</button></div></div>
      <div class="item-grid">
        <div><label>Product *</label><input data-gt name="items[${i}][garment_type]" value="${esc(d.garment_type)}" placeholder="Jersey, shorts, mug..."></div>
        <div><label>Price each *</label><input type="number" min="0" step="0.01" name="items[${i}][unit_price]" value="${esc(d.unit_price ?? 0)}" data-price></div>
        <div data-qty-box ${sized ? "hidden" : ""}><label>Quantity *</label><input type="number" min="1" name="items[${i}][quantity]" value="${esc(d.quantity ?? 1)}" data-qty></div>
        <div><label>Pieces</label><div class="sub pt-9" data-pieces>0</div></div>
        <div><label>Subtotal</label><div class="sub pt-9" data-sub>${money(0)}</div></div>
        <div class="span-all"><label class="check"><input type="checkbox" name="items[${i}][sized]" value="1" data-sized ${sized ? "checked" : ""}> Sold by size: list every player with a size (XS to XXL)</label></div>
        <div class="span-all roster-wrap" data-roster-wrap ${sized ? "" : "hidden"}>
          <div class="roster">
            <div class="roster-head"><span>#</span><span>Player name</span><span>Jersey no.</span><span>Size</span><span></span></div>
            <div class="roster-rows"></div>
            <div class="roster-foot">
              <button type="button" class="btn btn-sm btn-outline" data-add-player>+ Add player</button>
              <button type="button" class="btn btn-sm btn-outline" data-add-5>+ Add 5 players</button>
              <strong class="roster-count"></strong>
            </div>
            <div class="roster-sizes"></div>
          </div>
        </div>
        <div class="span-all"><label>Design specifications</label>
          <textarea name="items[${i}][design_specifications]" placeholder="Colors, logo position, team name...">${esc(d.design_specifications)}</textarea></div>
      </div>
      <details class="more-measure" ${(d.dims || []).length ? "open" : ""}>
        <summary>Extra measurements (optional)</summary>
        <div class="dims">${(d.dims || []).map((x, j) => dimRow(i, j, x)).join("")}</div>
        <button type="button" class="btn btn-sm btn-outline" data-add-dim>+ Add measurement</button>
      </details>`;
    wrap.appendChild(el);

    // The player list is always mounted; it is only shown (and saved) for sized items.
    el._roster = window.Roster.mount(
      el.querySelector(".roster"),
      `items[${i}][players]`,
      d.players || [],
      calc,
    );
    syncSized(el);
    renumber();
    calc();
  }

  function isSized(card) {
    return card.querySelector("[data-sized]").checked;
  }

  // Players are only sent when the item is sold by size.
  function syncSized(card) {
    const sized = isSized(card);
    card.querySelector("[data-roster-wrap]").hidden = !sized;
    card.querySelector("[data-qty-box]").hidden = sized;
    card
      .querySelectorAll("[data-roster-wrap] input, [data-roster-wrap] select")
      .forEach((el) => (el.disabled = !sized));
  }

  function renumber() {
    wrap.querySelectorAll(".item-card").forEach((c, k) => (c.querySelector(".num").textContent = k + 1));
  }

  function calc() {
    let total = 0;
    wrap.querySelectorAll(".item-card").forEach((c) => {
      const price = parseFloat(c.querySelector("[data-price]").value) || 0;
      const qty = isSized(c)
        ? c._roster
          ? c._roster.count()
          : 0
        : parseInt(c.querySelector("[data-qty]").value, 10) || 0;
      const sub = Math.round(qty * price * 100) / 100;
      c.querySelector("[data-pieces]").textContent = qty;
      c.querySelector("[data-sub]").textContent = money(sub);
      total += sub;
    });
    totalEl.textContent = money(total);
  }

  wrap.addEventListener("input", calc);
  wrap.addEventListener("change", (e) => {
    const card = e.target.closest(".item-card");
    if (e.target.matches("[data-pick]")) {
      const p = products.find((x) => String(x.id) === e.target.value);
      if (!p) return;
      card.querySelector("[data-gt]").value = p.name;
      card.querySelector("[data-price]").value = parseFloat(p.unit_price).toFixed(2);
      card.querySelector("[data-sized]").checked = p.has_sizes === "1" || p.has_sizes === 1;
      e.target.value = "";
      syncSized(card);
    } else if (e.target.matches("[data-sized]")) {
      syncSized(card);
    }
    calc();
  });
  wrap.addEventListener("click", (e) => {
    const t = e.target;
    const card = t.closest(".item-card");
    if (t.matches("[data-add-dim]")) {
      const j = parseInt(card.dataset.dims, 10);
      card.dataset.dims = j + 1;
      card.querySelector(".dims").insertAdjacentHTML("beforeend", dimRow(card.dataset.index, j, {}));
    } else if (t.matches("[data-rm-dim]")) {
      t.closest(".dim-row").remove();
    } else if (t.matches("[data-rm-item]")) {
      if (wrap.children.length > 1) {
        card.remove();
        renumber();
        calc();
      }
    }
  });
  document.getElementById("addItem").addEventListener("click", () => addItem({}));

  if (data.items.length) data.items.forEach(addItem);
  else addItem({});
})();
