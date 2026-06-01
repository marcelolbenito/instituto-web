/**
 * Paso 3 registrar cobro: recargo/descuento por forma de pago y tarjeta.
 */
(() => {
  const root = document.getElementById("cobro-medio-pago");
  if (!root) return;

  const subtotal = Number(root.dataset.subtotal || "0");
  const maxDesc = Number(root.dataset.maxDescuento || "0");
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
        if (descPct > maxDesc + 0.0001) {
          msg = "Descuento máximo " + maxDesc.toFixed(2).replace(".", ",") + "%.";
          descPct = maxDesc;
          inpDesc.value = String(maxDesc);
        }
      }
    }

    recImp = Math.round((subtotal * recPct) / 100 * 100) / 100;
    descImp = Math.round((subtotal * descPct) / 100 * 100) / 100;
    const total = Math.round((subtotal + recImp - descImp) * 100) / 100;

    if (totalEl) {
      totalEl.textContent =
        "$ " +
        total.toLocaleString("es-AR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (resumenEl) {
      const parts = ["Subtotal $ " + fmt(subtotal)];
      if (recImp > 0.00001) parts.push("Recargo medio +" + fmt(recImp) + " (" + recPct.toFixed(2) + "%)");
      if (descImp > 0.00001) parts.push("Descuento −" + fmt(descImp) + " (" + descPct.toFixed(2) + "%)");
      parts.push(msg);
      resumenEl.textContent = parts.filter(Boolean).join(" · ");
      resumenEl.classList.toggle("err", msg !== "");
    }
  }

  function fmt(n) {
    return n.toLocaleString("es-AR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
        opt.textContent = pl.cuotas + " cuota" + (pl.cuotas === 1 ? "" : "s") + " (" + Number(pl.recargo_pct).toFixed(2) + "%)";
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
  root.querySelectorAll("input").forEach((inp) => {
    if (inp !== inpDesc) inp.addEventListener("input", calcular);
  });

  toggleCampos();
})();
