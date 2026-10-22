// Sales invoice / return screen: scan carton barcodes, price per item, manual lines, save.
(() => {
  const $ = id => document.getElementById(id);
  const root = $('sale'), TYPE = root.dataset.type, CSRF = root.dataset.csrf;
  const pkgs = new Map();      // package id -> package
  const prices = {};           // item id -> price (user-entered or prefilled)
  const isDelivery = () => !!($('kindDelivery') && $('kindDelivery').checked);   // priceless delivery note instead of an invoice
  document.querySelectorAll('input[name="kind"]').forEach(r => r.addEventListener('change', () => {
    root.classList.toggle('delivery', isDelivery());
    $('btnSave').innerHTML = isDelivery() ? '<i class="bi bi-printer"></i> حفظ وطباعة الإذن' : '<i class="bi bi-check2-circle"></i> حفظ الفاتورة';
    totals();
  }));
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
      // a pallet code brings all of its cartons that are still in stock
      const list = r.packages || [r.package];
      let added = 0;
      for (const p of list) {
        if (pkgs.has(p.id)) continue;
        if (r.pallet) p.pallet = r.pallet.serial;
        pkgs.set(p.id, p);
        added++;
        if (prices[p.item_id] === undefined) {
          if (TYPE === 'return' && p.sold_price !== null) prices[p.item_id] = p.sold_price;
          else { const lp = await api('last_price', { item_id: p.item_id }); prices[p.item_id] = lp.ok && lp.price !== null ? lp.price : ''; }
        }
      }
      beep(true);
      if (r.pallet) {
        const sum = list.reduce((s, x) => s + x.net, 0);
        const partial = r.pallet.available < r.pallet.capacity;
        msg(`<i class="bi bi-boxes"></i> بالتة ${esc(r.pallet.serial)} — ضفت <b>${added}</b> كرتونة (<b class="num">${fmt(sum, 2)}</b> كيلو)`
          + (partial ? ` — البالتة فيها ${r.pallet.available} متاحة من ${r.pallet.capacity}` : ''), partial ? 'warning' : 'success');
      } else {
        const p = list[0];
        msg(`<i class="bi bi-check-circle"></i> ${esc(p.serial)} — ${esc(p.code)} — <b class="num">${fmt(p.net, 3)}</b> كيلو`, 'success');
      }
      render(list[list.length - 1].id);
    } catch (err) { beep(false); msg('مفيش اتصال بالسيرفر', 'danger'); }
  });

  // ---------- pick from stock (pallets / cartons) without scanning ----------
  async function addPackages(list, pallet) {
    let added = 0, sum = 0;
    for (const p of list) {
      if (pkgs.has(p.id)) continue;
      p.pallet = p.pallet || pallet || null;
      pkgs.set(p.id, p); added++; sum += p.net;
      if (prices[p.item_id] === undefined) {
        const lp = await api('last_price', { item_id: p.item_id });
        prices[p.item_id] = lp.ok && lp.price !== null ? lp.price : '';
      }
    }
    render(list.length ? list[list.length - 1].id : undefined);
    return { added, sum };
  }
  let stock = [];
  const open = new Set();   // expanded groups
  let chip = 'all';
  const unit = g => g.sack ? 'شكارة' : 'كرتونة';
  const dmy = t => t ? `${t.slice(8, 10)}/${t.slice(5, 7)}` : '';
  async function loadStock() {
    $('pickList').innerHTML = '<div class="sp-skel">' + '<div></div>'.repeat(4) + '</div>';
    $('pickSum').innerHTML = '';
    const r = await api('stock_list');
    if (!r.ok) { $('pickList').innerHTML = `<div class="alert alert-danger">${esc(r.error)}</div>`; return; }
    stock = r.groups; drawStock(true);
  }
  function drawStock(first) {
    const f = $('pickFilter').value.trim().toLowerCase();
    let html = '', nG = 0, nP = 0, kgAll = 0, i = 0;
    stock.forEach((g, gi) => {
      if (chip === 'carton' && g.sack) return;
      if (chip === 'sack' && !g.sack) return;
      if (chip === 'std' && g.mixed) return;
      if (chip === 'mixed' && !g.mixed) return;
      const pk = g.packages.filter(p => !pkgs.has(p.id));
      if (chip === 'full' && !(g.pallet && g.capacity && pk.length >= g.capacity)) return;
      const match = !f || [g.pallet, g.code, g.name, g.spec, g.lot, g.grade].some(x => String(x || '').toLowerCase().includes(f));
      const shown = match ? pk : pk.filter(p => p.serial.includes(f) || String(p.seq || '') === f);
      if (!shown.length) return;
      const kg = shown.reduce((s, p) => s + p.net, 0), cones = shown.reduce((s, p) => s + p.cones, 0);
      nG++; nP += shown.length; kgAll += kg;
      const cap = g.capacity || 0, pct = cap ? Math.min(100, Math.round(shown.length / cap * 100)) : 100;
      const complete = cap && shown.length >= cap;
      const isOpen = open.has(gi) || (!!f && !match);
      const days = dmy(g.from) === dmy(g.to) ? dmy(g.from) : `${dmy(g.from)} — ${dmy(g.to)}`;
      html += `<div class="sp-card ${g.sack ? 'is-sack' : 'is-ctn'} ${first ? 'enter' : ''}" style="--d:${Math.min(i++, 12) * 40}ms" data-g="${gi}">
        <div class="sp-top">
          <div class="sp-id">
            <div class="sp-ic"><i class="bi ${g.pallet ? 'bi-boxes' : 'bi-box'}"></i></div>
            <div><div class="sp-pal">${g.pallet ? `بالتة <b dir="ltr">${esc(g.pallet)}</b>` : `${unit(g) === 'شكارة' ? 'شكاير' : 'كراتين'} سايبة`}</div>
              <div class="sp-tags"><span class="t-pk">${unit(g)}</span><span class="${g.mixed ? 't-mix' : 't-std'}">${g.mixed ? 'متعدد' : 'نظامي'}</span>${g.pallet ? (complete ? '<span class="t-full">كاملة</span>' : `<span class="t-part">ناقصة ${cap - shown.length}</span>`) : ''}</div></div>
          </div>
          <div class="sp-fill" title="${shown.length} من ${cap || shown.length}"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.5" class="trk"/><circle cx="18" cy="18" r="15.5" class="bar" style="--p:${pct}"/></svg><b>${shown.length}${cap ? `<small>/${cap}</small>` : ''}</b></div>
        </div>
        <div class="sp-item"><b class="num">${esc(g.code)}</b><span>${esc(g.spec)}</span><small>${esc(g.name)}</small></div>
        <div class="sp-stats">
          <div><b class="num">${fmt(kg, 1)}</b><span>كيلو صافي</span></div>
          <div><b class="num">${cones}</b><span>كونة</span></div>
          <div><b class="num">${fmt(kg / shown.length, 2)}</b><span>متوسط ال${unit(g)}</span></div>
          <div><b class="num">${esc(days)}</b><span>اتنتجت</span></div>
        </div>
        ${g.lot || g.grade || g.cone ? `<div class="sp-meta">${g.lot ? `<span><i class="bi bi-upc"></i> لوط <b dir="ltr">${esc(g.lot)}</b></span>` : ''}${g.grade ? `<span><i class="bi bi-award"></i> درجة <b>${esc(g.grade)}</b></span>` : ''}${g.cone ? `<span><i class="bi bi-record-circle"></i> ${esc(g.cone)}</span>` : ''}</div>` : ''}
        <div class="sp-act">
          <button type="button" class="btn btn-success pk-all" data-g="${gi}"><i class="bi bi-plus-lg"></i> ${g.pallet ? 'ضيف البالتة كلها' : 'ضيفهم كلهم'} <small>(${shown.length} ${unit(g)})</small></button>
          <button type="button" class="btn btn-outline-secondary pk-toggle" data-g="${gi}"><i class="bi ${isOpen ? 'bi-chevron-up' : 'bi-ui-checks-grid'}"></i> ${isOpen ? 'إخفاء' : 'اختار بالواحدة'}</button>
        </div>`;
      if (isOpen) {
        html += `<div class="sp-pick"><div class="sp-pick-h"><label class="form-check-label"><input type="checkbox" class="form-check-input pk-chkall" data-g="${gi}"> اختار الكل</label><span class="small text-muted pk-selinfo" data-g="${gi}"></span></div>
          <div class="sp-grid">` + shown.map(p => `<label class="sp-c"><input type="checkbox" class="pk-chk" data-g="${gi}" data-id="${p.id}">
            <span class="sp-c-in">${p.seq ? `<em>${p.seq}</em>` : ''}<b class="num">${fmt(p.net, 2)}</b><small class="num">${esc(p.serial)}</small><small>${p.cones} كونة · قائم ${fmt(p.gross, 2)}</small></span></label>`).join('') + `</div>
          <button type="button" class="btn btn-primary btn-sm pk-sel mt-2" data-g="${gi}" disabled><i class="bi bi-plus-lg"></i> إضافة المحدد</button></div>`;
      }
      html += '</div>';
    });
    $('pickSum').innerHTML = nG ? `<span><b>${nG}</b> ${chip === 'sack' ? 'مجموعة شكاير' : 'بالتة / مجموعة'}</span><span><b>${nP}</b> كرتونة / شكارة</span><span><b class="num">${fmt(kgAll, 1)}</b> كيلو متاح</span>` : '';
    $('pickList').innerHTML = html || `<div class="sp-empty"><i class="bi bi-inbox"></i>مفيش حاجة متاحة${f || chip !== 'all' ? ' بالفلتر ده' : ' في المخزن'}</div>`;
  }
  if ($('btnPick')) {
    $('btnPick').addEventListener('click', () => {
      if (!$('customer').value) { beep(false); msg('اختر العميل الأول', 'warning'); return; }
      $('pickPanel').classList.toggle('d-none');
      $('btnPick').classList.toggle('active', !$('pickPanel').classList.contains('d-none'));
      if (!$('pickPanel').classList.contains('d-none')) loadStock();
    });
    $('pickReload').addEventListener('click', loadStock);
    $('pickFilter').addEventListener('input', () => drawStock());
    $('pickChips').addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      chip = b.dataset.f;
      $('pickChips').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      $('pickList').scrollTop = 0;
      drawStock(true);
    });
    const selInfo = g => {
      const picked = [...$('pickList').querySelectorAll(`.pk-chk[data-g="${g}"]:checked`)].map(c => Number(c.dataset.id));
      const sel = stock[g].packages.filter(p => picked.includes(p.id));
      $('pickList').querySelector(`.pk-selinfo[data-g="${g}"]`).textContent = sel.length ? `${sel.length} ${unit(stock[g])} — ${fmt(sel.reduce((s, p) => s + p.net, 0), 2)} كيلو` : '';
      const btn = $('pickList').querySelector(`.pk-sel[data-g="${g}"]`); if (btn) btn.disabled = !sel.length;
    };
    $('pickList').addEventListener('change', e => {
      const g = e.target.dataset.g;
      if (e.target.classList.contains('pk-chkall')) $('pickList').querySelectorAll(`.pk-chk[data-g="${g}"]`).forEach(c => { c.checked = e.target.checked; });
      if (e.target.classList.contains('pk-chk') || e.target.classList.contains('pk-chkall')) selInfo(g);
    });
    $('pickList').addEventListener('click', async e => {
      const b = e.target.closest('button'); if (!b) return;
      const gi = Number(b.dataset.g);
      if (b.classList.contains('pk-toggle')) { open.has(gi) ? open.delete(gi) : open.add(gi); drawStock(); return; }
      let list = null;
      if (b.classList.contains('pk-all')) list = stock[gi].packages.filter(p => !pkgs.has(p.id));
      if (b.classList.contains('pk-sel')) {
        const ids = [...$('pickList').querySelectorAll(`.pk-chk[data-g="${gi}"]:checked`)].map(c => Number(c.dataset.id));
        list = stock[gi].packages.filter(p => ids.includes(p.id));
      }
      if (!list || !list.length) return;
      b.disabled = true;
      const card = b.closest('.sp-card');
      const r = await addPackages(list, stock[gi].pallet);
      beep(true);
      msg(`<i class="bi bi-boxes"></i> ضفت <b>${r.added}</b> ${unit(stock[gi])} (<b class="num">${fmt(r.sum, 2)}</b> كيلو)${stock[gi].pallet ? ' من بالتة <b dir="ltr">' + esc(stock[gi].pallet) + '</b>' : ''} — إجمالي الفاتورة دلوقتي <b>${pkgs.size}</b>`, 'success');
      if (card) { card.classList.add('added'); await new Promise(res => setTimeout(res, 450)); }
      drawStock();
    });
  }

  $('palChips').addEventListener('click', e => {
    if (e.target.id === 'clearAll') { if (confirm('تشيل كل الكراتين من الفاتورة؟')) pkgs.clear(); }
    else { const b = e.target.closest('.pal-del'); if (!b) return; [...pkgs].forEach(([id, p]) => { if (p.pallet === b.dataset.p) pkgs.delete(id); }); }
    render(); if ($('pickList') && !$('pickPanel').classList.contains('d-none')) drawStock();
  });
  $('pkgTable').addEventListener('click', e => {
    const b = e.target.closest('.del'); if (!b) return;
    pkgs.delete(Number(b.dataset.id)); render(); if ($('pickList') && !$('pickPanel').classList.contains('d-none')) drawStock();
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
    const pal = {};
    pkgs.forEach(p => { if (p.pallet) { pal[p.pallet] = pal[p.pallet] || { n: 0, kg: 0 }; pal[p.pallet].n++; pal[p.pallet].kg += p.net; } });
    $('palChips').innerHTML = Object.entries(pal).map(([s, v]) => `<span class="badge text-bg-primary fs-6 fw-normal me-1 mb-1">بالتة ${esc(s)} — ${v.n} كرتونة (${fmt(v.kg, 1)} كيلو) <button type="button" class="btn btn-sm btn-light py-0 px-1 ms-1 pal-del" data-p="${esc(s)}" title="شيل البالتة">✕</button></span>`).join('')
      + (pkgs.size ? '<button type="button" class="btn btn-sm btn-outline-danger mb-1" id="clearAll">شيل كل الكراتين</button>' : '');
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
    const free = isDelivery();    // no prices on a delivery note
    let totalKg = 0;
    groups().forEach(g => {
      totalKg += g.kg;
      const pr = free ? 0 : parseFloat(prices[g.item_id]);
      if (isNaN(pr) || pr < 0) ok = false;
      const amt = isNaN(pr) ? 0 : Math.round(g.kg * pr * 100) / 100;
      sub += amt;
      const cell = $('priceTable').querySelector(`.g-amount[data-item="${g.item_id}"]`);
      if (cell) cell.textContent = fmt(amt);
    });
    let manualCount = 0;
    $('manualTable').querySelectorAll('tbody tr').forEach(tr => {
      const item = tr.querySelector('.m-item').value, q = parseFloat(tr.querySelector('.m-qty').value), pr = free ? 0 : parseFloat(tr.querySelector('.m-price').value);
      if (!isNaN(q)) totalKg += q;
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
    if ($('kgBig')) $('kgBig').textContent = fmt(totalKg, 3);
    $('btnSave').disabled = !($('customer').value && (pkgs.size || manualCount) && ok && disc <= sub);
  }

  // ---------- save ----------
  $('btnSave').addEventListener('click', async () => {
    $('btnSave').disabled = true;
    const manual = [...$('manualTable').querySelectorAll('tbody tr')].map(tr => ({
      item_id: tr.querySelector('.m-item').value, qty: tr.querySelector('.m-qty').value, price: tr.querySelector('.m-price').value,
    })).filter(m => m.item_id);
    const data = {
      kind: isDelivery() ? 'delivery' : 'invoice', receiver: $('receiver').value, contact_phone: $('contactPhone').value, organizer: $('organizer').value, driver_name: $('driverName').value, car_no: $('carNo').value,
      inv_date: $('invDate').value, notes: $('notes').value, discount: $('discount').value, paid: $('paid').value, pay_method: $('payMethod').value,
      packages: [...pkgs.keys()], prices, manual,
    };
    try {
      const r = await api('save', { data: JSON.stringify(data) });
      if (!r.ok) { $('saveMsg').innerHTML = `<div class="alert alert-danger py-2">${esc(r.error)}</div>`; $('btnSave').disabled = false; return; }
      location.href = 'sale_view.php?id=' + r.id + '&new=1' + (isDelivery() ? '&print=1' : '');
    } catch (e) {
      $('saveMsg').innerHTML = '<div class="alert alert-danger py-2">مفيش اتصال بالسيرفر — جرّب تاني</div>';
      $('btnSave').disabled = false;
    }
  });

  window.addEventListener('beforeunload', e => { if (pkgs.size && !$('btnSave').disabled) { e.preventDefault(); e.returnValue = ''; } });
  render();
})();
