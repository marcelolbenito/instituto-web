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
    table.parentNode.insertBefore(wrapper, table);
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
      const bodyHtml = rows.map((row) =>
        `<tr>${cols.map((c) => `<td>${(row.cells[c.index]?.textContent || "").trim()}</td>`).join("")}</tr>`
      ).join("");
      const w = window.open("", "_blank");
      if (!w) return;
      w.document.write(`
        <!doctype html>
        <html lang="es">
        <head>
          <meta charset="utf-8">
          <title>Impresión</title>
          <style>
            body{font-family:Arial,sans-serif;padding:14px;}
            table{width:100%;border-collapse:collapse;}
            th,td{border:1px solid #ccc;padding:6px 8px;font-size:12px;text-align:left;}
            th{background:#f2f2f2;}
          </style>
        </head>
        <body>
          <table><thead>${headHtml}</thead><tbody>${bodyHtml}</tbody></table>
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
  });
})();
// JS vanilla: formularios, fetch a endpoints PHP, etc.
