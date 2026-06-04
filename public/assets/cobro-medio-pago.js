/**
 * Paso 3 registrar cobro: abonos parciales, recargo/descuento por forma de pago.
 */
(() => {
  const root = document.getElementById("cobro-medio-pago");
  if (!root) return;

  const itemsTotal = Number(root.dataset.itemsTotal || "0");
  const maxDescPct = Number(root.dataset.maxDescuentoPct || "100");
  const formas = JSON.parse(root.dataset.formas || "[]");
  const tarjetas = JSON.parse(root.dataset.tarjetas || "[]");

  const selForma = root.querySelector('[name="forma_pago_id"]');
  const boxTarjeta = root.querySelector(".cobro-medio-tarjeta");
  const boxDebito = root.querySelector(".cobro-medio-debito");
  const boxEfectivo = root.querySelector(".cobro-medio-efectivo");
  const boxRef = root.querySelector(".cobro-medio-referencia");
  const boxDatos = root.querySelector(".cobro-medio-datos-tarjeta");
  const selTarjeta = root.querySelector('[name="tarjeta_id"]');
  const selCuotas = root.querySelector('[name="tarjeta_cuotas"]');
  const inpDesc = root.querySelector('[name="descuento_medio_pct"]');
  const totalEl = root.querySelector(".cobro-medio-total");
  const resumenEl = root.querySelector(".cobro-medio-resumen");
  const abonoInputs = document.querySelectorAll(".cobro-abono-input");

  /** Importe AR: acepta 94.791,67 (es-AR) o 94791.67 (punto decimal). */
  function parseMoney(raw) {
    const s = String(raw || "")
      .trim()
      .replace(/\s/g, "");
    if (s === "") return 0;
    if (s.includes(",")) {
      const n = Number(s.replace(/\./g, "").replace(",", "."));
      return Number.isFinite(n) ? n : 0;
    }
    const n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  function fmt(n) {
    return n.toLocaleString("es-AR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function subtotalAbonos() {
    let s = itemsTotal;
    abonoInputs.forEach((inp) => {
      s += parseMoney(inp.value);
    });
    return Math.round(s * 100) / 100;
  }

  function actualizarRestos() {
    abonoInputs.forEach((inp) => {
      const max = parseMoney(inp.dataset.max || inp.getAttribute("data-max") || "0");
      const ab = parseMoney(inp.value);
      const resto = Math.max(0, Math.round((max - ab) * 100) / 100);
      const tr = inp.closest("tr");
      const cel = tr ? tr.querySelector(".cobro-abono-resto") : null;
      if (cel) {
        cel.textContent = "$ " + fmt(resto);
        cel.classList.toggle("err", resto > 0.009);
        cel.classList.toggle("muted", resto <= 0.009);
      }
    });
  }

  function formaActual() {
    const id = Number(selForma?.value || 0);
    return formas.find((f) => Number(f.id) === id) || null;
  }

  function pctPlan(tarjetaId, cuotas) {
    const t = tarjetas.find((x) => Number(x.id) === Number(tarjetaId));
    if (!t || !t.planes) return null;
    const p = t.planes.find((pl) => Number(pl.cuotas) === Number(cuotas));
    return p ? Number(p.recargo_pct) : null;
  }

  function calcular() {
    const subtotal = subtotalAbonos();
    const f = formaActual();
    let recPct = 0;
    let descPct = 0;
    let recImp = 0;
    let descImp = 0;
    let msg = "";

    if (f) {
      if (f.usa_planes_tarjeta) {
        const tid = Number(selTarjeta?.value || 0);
        const cuo = Number(selCuotas?.value || 0);
        const p = pctPlan(tid, cuo);
        if (p === null && tid > 0 && cuo > 0) {
          msg = "Sin % configurado para esa tarjeta y cuotas.";
        } else if (p !== null) {
          recPct = p;
        }
      } else if (Number(f.recargo_pct) > 0) {
        recPct = Number(f.recargo_pct);
      }
      if (f.permite_descuento_pct && inpDesc) {
        descPct = Math.max(0, Number(inpDesc.value || 0));
        if (descPct > maxDescPct + 0.0001) {
          msg = "El descuento en efectivo no puede superar " + maxDescPct.toFixed(0) + "%.";
          descPct = maxDescPct;
          inpDesc.value = String(maxDescPct);
        }
      }
    }

    recImp = Math.round((subtotal * recPct) / 100 * 100) / 100;
    descImp = Math.round((subtotal * descPct) / 100 * 100) / 100;
    const total = Math.round((subtotal + recImp - descImp) * 100) / 100;

    if (totalEl) {
      totalEl.textContent = "$ " + fmt(total);
    }
    if (resumenEl) {
      const parts = ["Subtotal abonos $ " + fmt(subtotal)];
      if (recImp > 0.00001) parts.push("Recargo medio +" + fmt(recImp) + " (" + recPct.toFixed(2) + "%)");
      if (descImp > 0.00001) parts.push("Descuento −" + fmt(descImp) + " (" + descPct.toFixed(2) + "%)");
      parts.push(msg);
      resumenEl.textContent = parts.filter(Boolean).join(" · ");
      resumenEl.classList.toggle("err", msg !== "");
    }
    actualizarRestos();
  }

  function refrescarCuotas() {
    if (!selTarjeta || !selCuotas) return;
    const tid = Number(selTarjeta.value || 0);
    const t = tarjetas.find((x) => Number(x.id) === tid);
    const prev = selCuotas.value;
    selCuotas.innerHTML = '<option value="">— Cuotas —</option>';
    if (t && t.planes) {
      t.planes.forEach((pl) => {
        const opt = document.createElement("option");
        opt.value = String(pl.cuotas);
        opt.textContent =
          pl.cuotas + " cuota" + (pl.cuotas === 1 ? "" : "s") + " (" + Number(pl.recargo_pct).toFixed(2) + "%)";
        selCuotas.appendChild(opt);
      });
    }
    if (prev) selCuotas.value = prev;
  }

  function toggleCampos() {
    const f = formaActual();
    const show = (el, on) => {
      if (el) el.hidden = !on;
    };
    show(boxTarjeta, !!(f && f.usa_planes_tarjeta));
    show(boxEfectivo, !!(f && f.permite_descuento_pct));
    show(boxRef, !!(f && f.requiere_referencia));
    show(boxDatos, !!(f && f.pide_datos_tarjeta));
    show(boxDebito, !!(f && !f.usa_planes_tarjeta && f.pide_datos_tarjeta && Number(f.recargo_pct) >= 0));
    if (f && f.usa_planes_tarjeta) refrescarCuotas();
    calcular();
  }

  selForma?.addEventListener("change", toggleCampos);
  selTarjeta?.addEventListener("change", () => {
    refrescarCuotas();
    calcular();
  });
  selCuotas?.addEventListener("change", calcular);
  inpDesc?.addEventListener("input", calcular);
  abonoInputs.forEach((inp) => {
    inp.addEventListener("input", calcular);
    inp.addEventListener("blur", () => {
      const max = parseMoney(inp.dataset.max || "0");
      let v = parseMoney(inp.value);
      if (max > 0 && v > max + 0.009) v = max;
      if (v < 0) v = 0;
      if (v > 0) {
        const parts = v.toFixed(2).split(".");
        inp.value = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".") + "," + parts[1];
      } else {
        inp.value = "";
      }
      calcular();
    });
  });
  root.querySelectorAll("input").forEach((inp) => {
    if (inp !== inpDesc && !inp.classList.contains("cobro-abono-input")) {
      inp.addEventListener("input", calcular);
    }
  });

  toggleCampos();
})();
