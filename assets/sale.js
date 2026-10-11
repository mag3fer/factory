// Sales invoice / return screen: scan carton barcodes, price per item, manual lines, save.
(() => {
  const $ = id => document.getElementById(id);
  const root = $('sale'), TYPE = root.dataset.type, CSRF = root.dataset.csrf;
  const pkgs = new Map();      // package id -> package
  const prices = {};           // item id -> price (user-entered or prefilled)
  const fmt = (v, d = 2) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function api(action, extra = {}) {
    const body = new URLSearchParams({ csrf: CSRF, action, type: TYPE, customer_id: $('customer').value, ...extra });
    const res = await fetch('api_sale.php', { method: 'POST', body });
    return res.json();
  }

  let audio;
  function beep(ok) {
    try {
      audio = audio || new AudioContext();
      const o = audio.createOscillator(), g = audio.createGain();
      o.type = 'square'; o.frequency.value = ok ? 1700 : 300; g.gain.value = .12;
      o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + (ok ? .09 : .3));
    } catch (e) {}
  }
  const msg = (html, cls) => { $('scanMsg').innerHTML = html ? `<div class="alert alert-${cls} py-2 mb-0">${html}</div>` : ''; };

  // ---------- customer ----------
  $('customer').addEventListener('change', async () => {
    $('custBalance').textContent = '';
    if (!$('customer').value) return render();
    const r = await api('balance');
    if (r.ok) {
      const b = r.balance;
      $('custBalance').innerHTML = `رصيد العميل: <b class="num ${b > 0 ? 'text-danger' : 'text-success'}">${fmt(Math.abs(b))}</b> ${b > 0 ? 'عليه' : b < 0 ? 'له' : ''}`;
    }
    $('scan').focus();
    render();
  });

  // ---------- scanning ----------
  $('scan').addEventListener('keydown', async e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const serial = $('scan').value.trim();
    $('scan').value = '';
    if (!serial) return;
    if (!$('customer').value) { beep(false); msg('اختر العميل الأول', 'warning'); return; }
    if ([...pkgs.values()].some(p => p.serial === serial)) { beep(false); msg(`الكرتونة ${esc(serial)} متسجلة في الفاتورة دي بالفعل`, 'warning'); return; }
    try {
      const r = await api('lookup', { serial });
      if (!r.ok) { beep(false); msg(esc(r.error), 'danger'); return; }
      const p = r.package;
      pkgs.set(p.id, p);
      if (prices[p.item_id] === undefined) {
        if (TYPE === 'return' && p.sold_price !== null) prices[p.item_id] = p.sold_price;
        else { const lp = await api('last_price', { item_id: p.item_id }); prices[p.item_id] = lp.ok && lp.price !== null ? lp.price : ''; }
      }
      beep(true);
      msg(`<i class="bi bi-check-circle"></i> ${esc(p.serial)} — ${esc(p.code)} — <b class="num">${fmt(p.net, 3)}</b> كيلو`, 'success');
      render(p.id);
    } catch (err) { beep(false); msg('مفيش اتصال بالسيرفر', 'danger'); }
  });

  $('pkgTable').addEventListener('click', e => {
    const b = e.target.closest('.del'); if (!b) return;
    pkgs.delete(Number(b.dataset.id)); render();
  });

  // ---------- manual lines ----------
  $('addManual').addEventListener('click', () => { $('manualTable').querySelector('tbody').appendChild($('manualTpl').content.cloneNode(true)); render(); });
  $('manualTable').addEventListener('input', render);
  $('manualTable').addEventListener('change', render);
  $('manualTable').addEventListener('click', e => { if (e.target.closest('.m-del')) { e.target.closest('tr').remove(); render(); } });

  $('priceTable').addEventListener('input', e => {
    if (!e.target.classList.contains('p-price')) return;
    prices[e.target.dataset.item] = e.target.value;
    totals();
  });
  ['discount', 'paid'].forEach(id => $(id).addEventListener('input', totals));

  // ---------- rendering ----------
  function groups() {
    const g = {};
    pkgs.forEach(p => {
      g[p.item_id] = g[p.item_id] || { item_id: p.item_id, code: p.code, name: p.name, n: 0, kg: 0 };
      g[p.item_id].n++; g[p.item_id].kg += p.net;
    });
    return Object.values(g);
  }

  function render(newId) {
    const tb = $('pkgTable').querySelector('tbody');
    tb.innerHTML = '';
    let i = 0, kg = 0;
    [...pkgs.values()].reverse().forEach(p => {
      i++; kg += p.net;
      const tr = document.createElement('tr');
      if (p.id === newId) tr.className = 'pkg-row-new';
      tr.innerHTML = `<td>${pkgs.size - i + 1}</td><td class="num">${esc(p.serial)}</td><td>${esc(p.code)}</td><td class="small">${esc(p.name)}</td><td>${p.cones}</td>
        <td class="num fw-bold">${fmt(p.net, 3)}</td><td><button class="btn btn-sm btn-outline-danger del" data-id="${p.id}"><i class="bi bi-x"></i></button></td>`;
      tb.appendChild(tr);
    });
    $('pkgCount').textContent = pkgs.size;
    $('pkgKg').textContent = fmt(kg, 3);

    const pt = $('priceTable').querySelector('tbody');
    const focused = document.activeElement?.dataset?.item;
    pt.innerHTML = '';
    groups().forEach(g => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td><b>${esc(g.code)}</b><div class="small text-muted">${g.n} كرتونة</div></td><td class="num">${fmt(g.kg, 3)}</td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm num p-price" data-item="${g.item_id}" value="${prices[g.item_id] ?? ''}"></td>
        <td class="num g-amount" data-item="${g.item_id}">0.00</td>`;
      pt.appendChild(tr);
    });
    if (focused) pt.querySelector(`[data-item="${focused}"].p-price`)?.focus();
    totals();
  }

  function totals() {
    let sub = 0, ok = true;
    groups().forEach(g => {
      const pr = parseFloat(prices[g.item_id]);
      if (isNaN(pr) || pr < 0) ok = false;
      const amt = isNaN(pr) ? 0 : Math.round(g.kg * pr * 100) / 100;
      sub += amt;
      const cell = $('priceTable').querySelector(`.g-amount[data-item="${g.item_id}"]`);
      if (cell) cell.textContent = fmt(amt);
    });
    let manualCount = 0;
    $('manualTable').querySelectorAll('tbody tr').forEach(tr => {
      const item = tr.querySelector('.m-item').value, q = parseFloat(tr.querySelector('.m-qty').value), pr = parseFloat(tr.querySelector('.m-price').value);
      const amt = !isNaN(q) && !isNaN(pr) ? Math.round(q * pr * 100) / 100 : 0;
      tr.querySelector('.m-amount').textContent = fmt(amt);
      sub += amt;
      if (item || !isNaN(q)) { manualCount++; if (!item || isNaN(q) || q <= 0 || isNaN(pr) || pr < 0) ok = false; }
    });
    sub = Math.round(sub * 100) / 100;
    const disc = Math.max(0, parseFloat($('discount').value) || 0);
    const total = Math.max(0, Math.round((sub - disc) * 100) / 100);
    $('subtotal').textContent = fmt(sub);
    $('total').textContent = fmt(total);
    $('btnSave').disabled = !($('customer').value && (pkgs.size || manualCount) && ok && disc <= sub);
  }

  // ---------- save ----------
  $('btnSave').addEventListener('click', async () => {
    $('btnSave').disabled = true;
    const manual = [...$('manualTable').querySelectorAll('tbody tr')].map(tr => ({
      item_id: tr.querySelector('.m-item').value, qty: tr.querySelector('.m-qty').value, price: tr.querySelector('.m-price').value,
    })).filter(m => m.item_id);
    const data = {
      inv_date: $('invDate').value, notes: $('notes').value, discount: $('discount').value, paid: $('paid').value, pay_method: $('payMethod').value,
      packages: [...pkgs.keys()], prices, manual,
    };
    try {
      const r = await api('save', { data: JSON.stringify(data) });
      if (!r.ok) { $('saveMsg').innerHTML = `<div class="alert alert-danger py-2">${esc(r.error)}</div>`; $('btnSave').disabled = false; return; }
      location.href = 'sale_view.php?id=' + r.id + '&new=1';
    } catch (e) {
      $('saveMsg').innerHTML = '<div class="alert alert-danger py-2">مفيش اتصال بالسيرفر — جرّب تاني</div>';
      $('btnSave').disabled = false;
    }
  });

  window.addEventListener('beforeunload', e => { if (pkgs.size && !$('btnSave').disabled) { e.preventDefault(); e.returnValue = ''; } });
  render();
})();
