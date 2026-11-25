/* Player list (roster): one row per player with name, jersey number and size.
   Used by the customer "customize" page and the staff order form.
   Window.Roster.mount(root, prefix, players, onChange) builds the rows and keeps the totals. */
(function () {
  const SIZES = ["XS", "S", "M", "L", "XL", "XXL"];
  const esc = (s) =>
    String(s ?? "").replace(
      /[&<>"']/g,
      (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );

  function rowHtml(prefix, i, p) {
    const sizeOptions =
      '<option value="">Size</option>' +
      SIZES.map((s) => `<option ${p.size === s ? "selected" : ""}>${s}</option>`).join("");
    return `<div class="roster-row">
      <span class="roster-n"></span>
      <input name="${prefix}[${i}][name]" maxlength="60" placeholder="Player name" value="${esc(p.name)}" aria-label="Player name">
      <input name="${prefix}[${i}][number]" maxlength="2" inputmode="numeric" placeholder="No." value="${esc(p.number)}" aria-label="Jersey number" data-number>
      <select name="${prefix}[${i}][size]" aria-label="Size">${sizeOptions}</select>
      <button type="button" class="icon-btn" data-rm-player title="Remove player" aria-label="Remove player">&times;</button>
    </div>`;
  }

  function mount(root, prefix, players, onChange) {
    const rows = root.querySelector(".roster-rows");
    const countEl = root.querySelector(".roster-count");
    const sizesEl = root.querySelector(".roster-sizes");
    let seq = 0;

    const filled = () =>
      Array.from(rows.querySelectorAll(".roster-row")).filter((r) =>
        Array.from(r.querySelectorAll("input,select")).some((el) => el.value.trim() !== ""),
      );

    function refresh() {
      rows.querySelectorAll(".roster-row").forEach((r, k) => (r.querySelector(".roster-n").textContent = k + 1));
      const list = filled();
      const per = Object.fromEntries(SIZES.map((s) => [s, 0]));
      list.forEach((r) => {
        const s = r.querySelector("select").value;
        if (s) per[s]++;
      });
      if (countEl) countEl.textContent = list.length + (list.length === 1 ? " player" : " players");
      if (sizesEl)
        sizesEl.innerHTML = SIZES.filter((s) => per[s] > 0)
          .map((s) => `<span class="chip"><strong>${s}</strong> &times;${per[s]}</span>`)
          .join("");
      if (onChange) onChange(list.length);
    }

    function add(p = {}) {
      rows.insertAdjacentHTML("beforeend", rowHtml(prefix, seq++, p));
      refresh();
      return rows.lastElementChild;
    }

    root.addEventListener("click", (e) => {
      const t = e.target;
      if (t.closest("[data-add-player]")) add().querySelector("input").focus();
      else if (t.closest("[data-add-5]")) {
        for (let i = 0; i < 5; i++) add();
        rows.lastElementChild.querySelector("input").focus();
      } else if (t.closest("[data-rm-player]")) {
        if (rows.children.length > 1) t.closest(".roster-row").remove();
        else t.closest(".roster-row").querySelectorAll("input,select").forEach((el) => (el.value = ""));
        refresh();
      }
    });
    root.addEventListener("input", (e) => {
      if (e.target.matches("[data-number]")) e.target.value = e.target.value.replace(/\D/g, "");
      refresh();
    });
    root.addEventListener("change", refresh);
    // Enter moves to the next player instead of submitting the whole form.
    root.addEventListener("keydown", (e) => {
      if (e.key !== "Enter" || !e.target.closest(".roster-row") || e.target.tagName === "BUTTON") return;
      e.preventDefault();
      const row = e.target.closest(".roster-row");
      const next = row.nextElementSibling || add();
      next.querySelector("input").focus();
    });

    (players && players.length ? players : [{}]).forEach(add);
    return { count: () => filled().length, add };
  }

  window.Roster = { mount, SIZES };
})();
