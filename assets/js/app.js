document.addEventListener("DOMContentLoaded", () => {
  const body = document.body;

  // Sidebar toggle (mobile)
  document
    .querySelectorAll("[data-toggle-sidebar]")
    .forEach((b) => b.addEventListener("click", () => body.classList.toggle("sidebar-open")));
  document.querySelector(".main")?.addEventListener("click", () => body.classList.remove("sidebar-open"));

  // Modals: [data-open="modalId"] with optional data-row (JSON) and data-title
  document.querySelectorAll("[data-open]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const modal = document.getElementById(btn.dataset.open);
      if (!modal) return;
      const form = modal.querySelector("form");
      form.reset();
      if (form.elements.id) form.elements.id.value = "";
      const title = modal.querySelector(".modal-title");
      if (title) title.textContent = btn.dataset.title || title.textContent;
      if (btn.dataset.row) {
        const row = JSON.parse(btn.dataset.row);
        Object.entries(row).forEach(([k, v]) => {
          const el = form.elements[k];
          if (el && el.type !== "password" && el.type !== "file") el.value = v ?? "";
        });
      }
      modal.classList.add("show");
      modal.querySelector("input:not([type=hidden]),select,textarea")?.focus();
    });
  });
  const closeModals = () =>
    document.querySelectorAll(".modal.show").forEach((m) => m.classList.remove("show"));
  document.querySelectorAll("[data-close]").forEach((b) => b.addEventListener("click", closeModals));
  document.querySelectorAll(".modal").forEach((m) =>
    m.addEventListener("mousedown", (e) => {
      if (e.target === m) closeModals();
    }),
  );
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeModals();
  });

  // Live table search: <input data-search="tableId">
  document.querySelectorAll("input[data-search]").forEach((inp) => {
    inp.addEventListener("input", () => {
      const q = inp.value.toLowerCase();
      document.querySelectorAll("#" + inp.dataset.search + " tbody tr").forEach((tr) => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? "" : "none";
      });
    });
  });

  // Auto-hide success flash
  const f = document.querySelector("[data-flash].flash-ok");
  if (f)
    setTimeout(() => {
      f.style.transition = "opacity .4s";
      f.style.opacity = "0";
      setTimeout(() => f.remove(), 450);
    }, 4000);
});

// Topbar dropdowns (notifications, user menu)
document.addEventListener("DOMContentLoaded", () => {
  const dds = document.querySelectorAll("[data-dd]");
  dds.forEach((dd) =>
    dd.querySelector("[data-dd-toggle]")?.addEventListener("click", (e) => {
      e.stopPropagation();
      const open = !dd.classList.contains("open");
      dds.forEach((x) => x.classList.remove("open"));
      if (open) dd.classList.add("open");
    }),
  );
  document.addEventListener("click", () => dds.forEach((x) => x.classList.remove("open")));
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") dds.forEach((x) => x.classList.remove("open"));
  });
});

// Forms and buttons that ask first or print: no inline scripts needed.
function askFirst(el, onYes) {
  const d = el.dataset;
  const back = document.createElement("div");
  back.className = "cf-back";
  back.innerHTML =
    '<div class="cf-box" role="alertdialog" aria-modal="true"><h3></h3><p></p>' +
    '<div class="cf-actions"><button type="button" class="btn btn-outline" data-no></button>' +
    '<button type="button" class="btn btn-danger-solid" data-yes></button></div></div>';
  back.querySelector("h3").textContent = d.confirmTitle || "Are you sure?";
  back.querySelector("p").textContent = d.confirm;
  back.querySelector("[data-no]").textContent = d.confirmNo || "Cancel";
  back.querySelector("[data-yes]").textContent = d.confirmYes || "Yes, continue";
  const close = () => back.remove();
  back.addEventListener("click", (e) => {
    if (e.target === back || e.target.hasAttribute("data-no")) close();
    if (e.target.hasAttribute("data-yes")) { close(); onYes(); }
  });
  document.addEventListener("keydown", function esc(e) {
    if (e.key === "Escape") { close(); document.removeEventListener("keydown", esc); }
  });
  document.body.appendChild(back);
  back.querySelector("[data-no]").focus();
}
document.addEventListener("submit", (e) => {
  const f = e.target;
  if (!f.dataset.confirm || f.dataset.confirmed) return;
  e.preventDefault();
  askFirst(f, () => { f.dataset.confirmed = "1"; f.submit(); });
});
document.addEventListener("click", (e) => {
  if (e.target.closest("[data-print]")) window.print();
  const c = e.target.closest("a[data-confirm]");
  if (c) { e.preventDefault(); askFirst(c, () => (location.href = c.href)); }
});

// Bars and chart columns take their size from data attributes (keeps the styling in CSS/JS).
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-w]").forEach((el) => (el.style.width = el.dataset.w + "%"));
  requestAnimationFrame(() =>
    document.querySelectorAll("[data-h]").forEach((el) => (el.style.height = el.dataset.h + "%")),
  );
});

// Notification bell: ask the server every 20 seconds; show a toast when something new arrives.
document.addEventListener("DOMContentLoaded", () => {
  const bell = document.querySelector("[data-poll]");
  if (!bell) return;
  const badge = bell.querySelector("[data-bell-count]");
  const extra = parseInt(bell.dataset.alerts || "0", 10);
  let last = parseInt(bell.dataset.latest || "0", 10);
  const tick = async () => {
    try {
      const r = await fetch(bell.dataset.poll, { credentials: "same-origin", cache: "no-store" });
      if (!r.ok) return;
      const d = await r.json();
      const total = (d.unread || 0) + extra;
      badge.textContent = total > 99 ? "99+" : total;
      badge.hidden = total === 0;
      if (d.latest && d.latest.id > last) {
        last = d.latest.id;
        const t = document.createElement("div");
        t.className = "toast";
        const a = document.createElement("a");
        a.href = d.latest.link;
        a.textContent = d.latest.title;
        t.appendChild(a);
        if (d.latest.message) t.appendChild(document.createTextNode(" — " + d.latest.message));
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 8000);
      }
    } catch (_) {}
  };
  setInterval(tick, 20000);
});
