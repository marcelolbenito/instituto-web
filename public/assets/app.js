(() => {
  function asComparable(text) {
    const trimmed = text.trim();
    if (trimmed === "") return "";
    const normalizedNumber = trimmed.replace(/\./g, "").replace(",", ".");
    const num = Number(normalizedNumber);
    if (!Number.isNaN(num) && /^-?\d+([.,]\d+)?$/.test(trimmed.replace(/\s/g, ""))) {
      return num;
    }
    return trimmed.toLocaleLowerCase("es");
  }

  function initDataTable(table) {
    const tbody = table.tBodies[0];
    if (!tbody) return;

    const initialRows = Array.from(tbody.rows);
    if (initialRows.length === 0) return;

    table.classList.add("is-enhanced");

    const headers = Array.from(table.tHead ? table.tHead.rows[0].cells : []);
    const state = {
      query: "",
      page: 1,
      pageSize: 25,
      sortIndex: -1,
      sortDir: "asc",
    };

    const wrapper = document.createElement("div");
    wrapper.className = "data-table-wrap";
    // Mantener la tabla dentro de [data-print-report] (cuenta corriente, etc.)
    const printReportHost = table.closest("[data-print-report]");
    const mountParent = printReportHost || table.parentNode;
    mountParent.insertBefore(wrapper, table);
    wrapper.appendChild(table);

    const controls = document.createElement("div");
    controls.className = "data-table-controls";
    controls.innerHTML = `
      <label>Buscar <input type="search" class="dt-search" placeholder="Filtrar en la tabla"></label>
      <label>Filas
        <select class="dt-size">
          <option value="10">10</option>
          <option value="25" selected>25</option>
          <option value="50">50</option>
          <option value="100">100</option>
        </select>
      </label>
      <div class="data-table-actions">
        <button type="button" class="dt-print">Imprimir</button>
        <button type="button" class="dt-excel">Excel</button>
      </div>
    `;
    wrapper.insertBefore(controls, table);

    const footer = document.createElement("div");
    footer.className = "data-table-footer";
    footer.innerHTML = `
      <button type="button" class="dt-prev">Anterior</button>
      <span class="dt-status"></span>
      <button type="button" class="dt-next">Siguiente</button>
    `;
    wrapper.appendChild(footer);

    const search = controls.querySelector(".dt-search");
    const size = controls.querySelector(".dt-size");
    const prev = footer.querySelector(".dt-prev");
    const next = footer.querySelector(".dt-next");
    const status = footer.querySelector(".dt-status");
    const printBtn = controls.querySelector(".dt-print");
    const excelBtn = controls.querySelector(".dt-excel");

    search.addEventListener("input", () => {
      state.query = search.value.toLocaleLowerCase("es").trim();
      state.page = 1;
      render();
    });

    size.addEventListener("change", () => {
      state.pageSize = Number(size.value) || 25;
      state.page = 1;
      render();
    });

    prev.addEventListener("click", () => {
      state.page = Math.max(1, state.page - 1);
      render();
    });

    next.addEventListener("click", () => {
      state.page = state.page + 1;
      render();
    });

    headers.forEach((th, index) => {
      if (th.dataset.nosort === "1") return;
      th.classList.add("dt-sortable");
      th.addEventListener("click", () => {
        if (state.sortIndex === index) {
          state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        } else {
          state.sortIndex = index;
          state.sortDir = "asc";
        }
        render();
      });
    });

    function getProcessedRows() {
      let rows = initialRows.slice();
      if (state.query) {
        rows = rows.filter((row) =>
          row.textContent.toLocaleLowerCase("es").includes(state.query)
        );
      }
      if (state.sortIndex >= 0) {
        rows.sort((a, b) => {
          const left = asComparable(a.cells[state.sortIndex]?.textContent || "");
          const right = asComparable(b.cells[state.sortIndex]?.textContent || "");
          if (left < right) return state.sortDir === "asc" ? -1 : 1;
          if (left > right) return state.sortDir === "asc" ? 1 : -1;
          return 0;
        });
      }
      return rows;
    }

    function getExportColumns() {
      return headers
        .map((th, index) => ({ th, index }))
        .filter((x) => x.th.dataset.nosort !== "1");
    }

    function exportExcel() {
      const rows = getProcessedRows();
      const cols = getExportColumns();
      const lines = [];
      const csvEscape = (value) => {
        const text = String(value ?? "").replace(/"/g, '""');
        return `"${text}"`;
      };
      lines.push(cols.map((c) => csvEscape(c.th.textContent.trim())).join(";"));
      rows.forEach((row) => {
        lines.push(
          cols.map((c) => csvEscape((row.cells[c.index]?.textContent || "").trim())).join(";")
        );
      });
      const content = "\uFEFF" + lines.join("\n");
      const blob = new Blob([content], { type: "text/csv;charset=utf-8;" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      const pageNameRaw = (document.querySelector("h1")?.textContent || document.title || "tabla")
        .toLocaleLowerCase("es")
        .replace(/\s+/g, "_")
        .replace(/[^\w\-]+/g, "");
      const today = new Date();
      const dateTag = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
      a.href = url;
      a.download = `${pageNameRaw || "tabla"}_${dateTag}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    }

    function printTable() {
      const rows = getProcessedRows();
      const cols = getExportColumns();
      const headHtml = `<tr>${cols.map((c) => `<th>${c.th.textContent.trim()}</th>`).join("")}</tr>`;
      const bodyHtml = rows
        .map(
          (row) =>
            `<tr>${cols
              .map((c) => `<td>${(row.cells[c.index]?.textContent || "").trim()}</td>`)
              .join("")}</tr>`
        )
        .join("");

      const report =
        table.closest("[data-print-report]") ||
        table.closest(".data-table-wrap")?.closest("[data-print-report]") ||
        (table.dataset.printReportId
          ? document.getElementById(table.dataset.printReportId)
          : null) ||
        document.getElementById("cc-reporte");
      const headerHtml = report?.querySelector(".cc-reporte-encabezado")?.innerHTML || "";
      const docTitle =
        report?.dataset.printTitle ||
        document.querySelector("h1")?.textContent?.trim() ||
        "Impresión";
      const cssHref =
        document.querySelector('link[href*="app.css"]')?.getAttribute("href") || "assets/app.css";
      const movimientosTitulo =
        report?.querySelector("h2")?.textContent?.trim() || "Movimientos";

      const w = window.open("", "_blank");
      if (!w) return;
      w.document.write(`
        <!doctype html>
        <html lang="es">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>${docTitle.replace(/</g, "&lt;")}</title>
          <link rel="stylesheet" href="${cssHref}">
          <style>
            body.cc-print-body {
              font-family: system-ui, "Segoe UI", Arial, sans-serif;
              margin: 0;
              padding: 12mm 10mm;
              color: #111;
              background: #fff;
            }
            .cc-print-document .cc-print-nota { font-size: 0.8rem; margin-top: 0.75rem; }
            .cc-print-document .cc-reporte-movimientos-titulo {
              margin: 1rem 0 0.5rem;
              font-size: 1rem;
              color: #123a73;
            }
            .cc-print-document .table { width: 100%; border-collapse: collapse; font-size: 11px; }
            .cc-print-document .table th,
            .cc-print-document .table td {
              border: 1px solid #888;
              padding: 5px 7px;
            }
            .cc-print-document .table th { background: #eef3fa; }
            .cc-print-document .table .num,
            .cc-print-resumen .num { text-align: right; }
            @media print {
              body.cc-print-body { padding: 8mm; }
            }
          </style>
        </head>
        <body class="cc-print-body">
          <div class="cc-print-document">
            ${headerHtml}
            <h3 class="cc-reporte-movimientos-titulo">${movimientosTitulo.replace(/</g, "&lt;")}</h3>
            <table class="table"><thead>${headHtml}</thead><tbody>${bodyHtml}</tbody></table>
          </div>
        </body>
        </html>
      `);
      w.document.close();
      w.focus();
      w.print();
    }

    printBtn?.addEventListener("click", printTable);
    excelBtn?.addEventListener("click", exportExcel);

    function render() {
      headers.forEach((th, i) => {
        th.classList.remove("dt-sort-asc", "dt-sort-desc");
        if (i === state.sortIndex) {
          th.classList.add(state.sortDir === "asc" ? "dt-sort-asc" : "dt-sort-desc");
        }
      });

      const rows = getProcessedRows();

      const total = rows.length;
      const pages = Math.max(1, Math.ceil(total / state.pageSize));
      state.page = Math.min(state.page, pages);
      const start = (state.page - 1) * state.pageSize;
      const visible = rows.slice(start, start + state.pageSize);

      tbody.innerHTML = "";
      visible.forEach((row) => tbody.appendChild(row));

      const from = total === 0 ? 0 : start + 1;
      const to = Math.min(total, start + visible.length);
      status.textContent = `${from}-${to} de ${total}`;
      prev.disabled = state.page <= 1;
      next.disabled = state.page >= pages;
    }

    render();
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("table.js-data-table").forEach(initDataTable);

    document.querySelectorAll("[data-open-modal]").forEach((trigger) => {
      trigger.addEventListener("click", () => {
        const targetId = trigger.getAttribute("data-open-modal");
        if (!targetId) return;
        const modal = document.getElementById(targetId);
        if (!modal || typeof modal.showModal !== "function") return;
        modal.showModal();
      });
    });

    document.querySelectorAll("[data-close-modal]").forEach((trigger) => {
      trigger.addEventListener("click", () => {
        const targetId = trigger.getAttribute("data-close-modal");
        if (!targetId) return;
        const modal = document.getElementById(targetId);
        if (!modal || typeof modal.close !== "function") return;
        modal.close();
      });
    });

    document.querySelectorAll("[data-auto-open]").forEach((el) => {
      const targetId = el.getAttribute("data-auto-open");
      if (!targetId) return;
      const modal = document.getElementById(targetId);
      if (!modal || typeof modal.showModal !== "function") return;
      modal.showModal();
    });

    document.addEventListener("click", (ev) => {
      document.querySelectorAll(".nav-user-menu[open]").forEach((menu) => {
        if (!menu.contains(ev.target)) {
          menu.removeAttribute("open");
        }
      });
    });
  });
})();
// JS vanilla: formularios, fetch a endpoints PHP, etc.
