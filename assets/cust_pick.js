// Searchable customer picker on top of the plain <select id="customer"> (the select stays the source of truth:
// picking sets its value and fires "change", so the rest of the sale screen works as before).
(() => {
  const sel = document.getElementById('customer');
  if (!sel) return;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  // Arabic-friendly search: أ/إ/آ = ا, ة = ه, ى = ي, no tashkeel
  const norm = s => String(s || '').toLowerCase().replace(/[ً-ْ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').trim();
  const COLORS = ['#06b6d4', '#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#14b8a6', '#ec4899'];
  const color = s => COLORS[[...String(s)].reduce((h, c) => h + c.charCodeAt(0), 0) % COLORS.length];
  const dmy = d => d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : '';

  const list = [...sel.options].filter(o => o.value).map(o => ({
    id: o.value, name: o.dataset.name || o.textContent, code: o.dataset.code || '', phone: o.dataset.phone || '',
    bal: parseFloat(o.dataset.bal || '0') || 0, n: parseInt(o.dataset.n || '0', 10), last: o.dataset.last || '',
  }));
  list.forEach(c => { c.key = norm([c.name, c.code, c.phone].join(' ')); });
  const recent = list.filter(c => c.last).sort((a, b) => b.last.localeCompare(a.last)).slice(0, 4);

  const wrap = sel.closest('.sl-ig') || sel.parentElement;
  wrap.classList.add('cp');
  sel.classList.add('cp-native');
  const btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'cp-btn'; btn.setAttribute('aria-haspopup', 'listbox');
  const pop = document.createElement('div');
  pop.className = 'cp-pop'; pop.hidden = true;
  pop.innerHTML = `<div class="cp-search"><i class="bi bi-search"></i><input type="text" placeholder="ابحث بالاسم أو الكود أو التليفون" autocomplete="off"><kbd>Esc</kbd></div>
    <div class="cp-list" role="listbox"></div><div class="cp-foot"><span><b>${list.length}</b> عميل</span><span>↑ ↓ للتنقل — Enter للاختيار</span></div>`;
  wrap.append(btn, pop);
  const input = pop.querySelector('input'), box = pop.querySelector('.cp-list');
  let active = -1, shown = [];

  const balTag = c => c.bal > 0.5 ? `<span class="cp-bal due">عليه ${fmt(c.bal)}</span>` : c.bal < -0.5 ? `<span class="cp-bal cr">له ${fmt(-c.bal)}</span>` : '<span class="cp-bal zero">رصيده صفر</span>';
  const avatar = c => `<span class="cp-av" style="--c:${color(c.name)}">${esc([...c.name.trim()][0] || '؟')}</span>`;
  const row = (c, i) => `<div class="cp-row${String(c.id) === sel.value ? ' sel' : ''}" role="option" data-i="${i}" data-id="${c.id}" style="--d:${Math.min(i, 10) * 22}ms">
      ${avatar(c)}<div class="cp-main"><b>${esc(c.name)}</b><small>${c.code ? `<span class="num">${esc(c.code)}</span>` : ''}${c.phone ? ` · <span class="num" dir="ltr">${esc(c.phone)}</span>` : ''}</small></div>
      <div class="cp-side">${balTag(c)}<small>${c.n ? `${c.n} فاتورة · آخر ${dmy(c.last)}` : 'لسه مفيش فواتير'}</small></div></div>`;

  function draw() {
    const q = norm(input.value);
    let html = '', i = 0;
    shown = [];
    const add = (title, arr) => {
      if (!arr.length) return;
      html += `<div class="cp-sec">${title}</div>`;
      arr.forEach(c => { shown.push(c); html += row(c, i++); });
    };
    if (q) add('نتايج البحث', list.filter(c => c.key.includes(q)));
    else { add('<i class="bi bi-clock-history"></i> آخر اتعامل معاهم', recent); add('<i class="bi bi-people"></i> كل العملاء', list); }
    box.innerHTML = html || `<div class="cp-empty"><i class="bi bi-person-x"></i>مفيش عميل بالاسم ده</div>`;
    active = shown.length ? Math.max(0, shown.findIndex(c => String(c.id) === sel.value)) : -1;
    mark();
  }
  function mark() {
    box.querySelectorAll('.cp-row').forEach(r => r.classList.toggle('act', Number(r.dataset.i) === active));
    box.querySelector('.cp-row.act')?.scrollIntoView({ block: 'nearest' });
  }
  function paint() {
    const c = list.find(x => String(x.id) === sel.value);
    btn.innerHTML = c
      ? `${avatar(c)}<span class="cp-main"><b>${esc(c.name)}</b><small>${c.code ? `<span class="num">${esc(c.code)}</span> · ` : ''}${c.n ? `${c.n} فاتورة` : 'عميل جديد'}</small></span>${balTag(c)}<i class="bi bi-chevron-down cp-caret"></i>`
      : `<span class="cp-ph"><i class="bi bi-person-plus"></i> اختار العميل…</span><i class="bi bi-chevron-down cp-caret"></i>`;
    btn.classList.toggle('empty', !c);
  }
  function openPop() { pop.hidden = false; wrap.classList.add('open'); input.value = ''; draw(); setTimeout(() => input.focus(), 30); }
  function closePop() { pop.hidden = true; wrap.classList.remove('open'); }
  function choose(c) {
    sel.value = c.id; paint(); closePop();
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    btn.classList.remove('picked'); void btn.offsetWidth; btn.classList.add('picked');
  }

  btn.addEventListener('click', () => (pop.hidden ? openPop() : closePop()));
  input.addEventListener('input', draw);
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(shown.length - 1, active + 1); mark(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); mark(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (shown[active]) choose(shown[active]); }
    else if (e.key === 'Escape') { closePop(); btn.focus(); }
  });
  box.addEventListener('mousemove', e => { const r = e.target.closest('.cp-row'); if (r && Number(r.dataset.i) !== active) { active = Number(r.dataset.i); mark(); } });
  box.addEventListener('click', e => { const r = e.target.closest('.cp-row'); if (r) choose(shown[Number(r.dataset.i)]); });
  document.addEventListener('click', e => { if (!pop.hidden && !wrap.contains(e.target)) closePop(); });
  sel.addEventListener('change', paint);
  paint();
})();
