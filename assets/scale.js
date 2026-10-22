// Scale station: reads the Anheng indicator over RS-232 (Web Serial), computes tare/net, saves and prints.
(() => {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const station = $('station');
  const CSRF = station.dataset.csrf;
  const BAUD = parseInt(station.dataset.baud || '9600', 10);

  // The Anheng indicator sends one frame ("12.00\r") each time a new load settles, nothing while
  // empty. So every frame is a ready-to-use stable weight. A weight that was already saved is
  // "used" and can't be saved again until a different weight arrives (guards against double saves,
  // also for indicators that stream the same value continuously).
  let liveGross = null;      // last weight received (kg)
  let fresh = false;         // liveGross not yet saved
  let usedValue = null;      // weight of the last saved carton
  let port = null, reader = null, keepReading = false;
  let awaitingNew = false;   // a carton was just saved: the save button stays red until a new weight is registered
  // production type: 0 = نظامي (fixed cone count), 1 = متعدد (cones change from carton to carton)
  let mixed = 0;
  try { mixed = localStorage.getItem('scale.mode') === '1' ? 1 : 0; } catch (e) {}
  // "item:mode" -> the operator chose to start a new pallet (applies to the next save); kept across F5
  const pendingNew = (() => { try { return JSON.parse(localStorage.getItem('scale.pendingNew') || '{}'); } catch (e) { return {}; } })();
  const persistPending = () => { try { localStorage.setItem('scale.pendingNew', JSON.stringify(pendingNew)); } catch (e) {} };

  // ---------- remember selections between cartons ----------
  const KEEP = ['finished', 'raw', 'packing', 'cone', 'conesCount'];
  KEEP.forEach(id => {
    try { const v = localStorage.getItem('scale.' + id); if (v !== null) $(id).value = v; } catch (e) {}
    $(id).addEventListener('change', () => { try { localStorage.setItem('scale.' + id, $(id).value); } catch (e) {} recalc(); });
  });
  // raw ⇄ finished: picking the raw on the machine shows only the yarn counts made from it — the
  // count's own raw link when it has one, else the same count as the raw's spec (POY 250D → 150/144);
  // picking a yarn count selects its raw.
  const fits = (fin, raw) => fin.dataset.raw !== '0'
    ? fin.dataset.raw === raw.value
    : !raw.dataset.spec || !fin.dataset.spec || fin.dataset.spec === raw.dataset.spec;
  function filterFinished() {
    const raw = $('raw').selectedOptions[0];
    [...$('finished').options].forEach(o => { if (o.value) o.hidden = !!raw?.value && !fits(o, raw); });
    const sel = $('finished').selectedOptions[0];
    if (sel && sel.hidden) { $('finished').value = ''; try { localStorage.setItem('scale.finished', ''); } catch (e) {} }
  }
  $('raw').addEventListener('change', () => { filterFinished(); recalc(); });
  $('finished').addEventListener('change', () => {
    const fin = $('finished').selectedOptions[0], cur = $('raw').selectedOptions[0];
    if (!fin?.value || (cur?.value && fits(fin, cur))) return;
    const raws = [...$('raw').options].filter(o => o.value);
    const pick = raws.find(o => o.value === fin.dataset.raw && fits(fin, o)) || raws.find(o => fits(fin, o));
    if (pick) { $('raw').value = pick.value; try { localStorage.setItem('scale.raw', pick.value); } catch (e) {} filterFinished(); }
  });
  filterFinished();
  $('conesCount').addEventListener('input', recalc);
  $('manualGross').addEventListener('input', () => { if (parseFloat($('manualGross').value) > 0) awaitingNew = false; recalc(); });

  // tare = cones × cone weight (from the chosen cone) + carton/sack + cones × bag
  let coneMissing = false;
  function tareOf() {
    const pt = $('packing').selectedOptions[0];
    const coneOpt = $('cone').selectedOptions[0];
    const coneItemW = parseFloat(coneOpt?.dataset.tare || '0');
    const coneW = coneItemW > 0 ? coneItemW : parseFloat(pt?.dataset.cone || '0');   // the packing type's cone weight is only a fallback for old types
    const packW = parseFloat(pt?.dataset.pack || '0');
    const bagW = parseFloat(pt?.dataset.bag || '0');
    const n = Math.max(0, parseInt($('conesCount').value || '0', 10));
    const tare = n * coneW + packW + n * bagW;
    coneMissing = n > 0 && coneW <= 0;
    $('tareDetail').textContent = !$('packing').value ? 'اختر كرتون أو شكارة عشان الفوارغ تتحسب'
      : coneMissing ? 'اختر لون الكونة — وزن الكونة مش معروف'
      : `الفوارغ = ${n} كونة × ${coneW.toFixed(3)} + كرتونة/شكارة ${packW.toFixed(3)} + ${n} كيس × ${bagW.toFixed(3)} = ${tare.toFixed(3)} كيلو`;
    return Math.round(tare * 1000) / 1000;
  }

  function currentGross() {
    const manual = parseFloat($('manualGross').value);
    if (!isNaN(manual) && manual > 0) return { value: manual, source: 'manual' };
    if (liveGross !== null && fresh) return { value: liveGross, source: 'scale' };
    return null;
  }

  // A weight that looks wrong gets a visible warning and a confirmation before saving.
  const USUAL = (() => { try { return JSON.parse(station.dataset.usual || '{}'); } catch (e) { return {}; } })();
  const MAX_KG = 150;
  let currentWarn = '';
  let warnHard = false;   // hard = impossible reading (blocks until confirmed); soft = just looks unusual
  function sanity(g, net) {
    warnHard = false;
    if (!g) return '';
    if (g.value > MAX_KG) { warnHard = true; return `الوزن أكبر من سعة الميزان (${MAX_KG} كيلو) — اتأكد من القراءة`; }
    if (net <= 0) { warnHard = true; return 'الوزن القائم أقل من الفوارغ — اتأكد من نوع التعبئة وعدد الكونات'; }
    const n = parseInt($('conesCount').value || '0', 10), u = USUAL[$('finished').value];
    if (n > 0 && u) {
      const pc = net / n;
      if (Math.abs(pc - u) / u > 0.25) return `الكونة طالعة ${pc.toFixed(2)} كيلو (عدد الكونات المسجّل: ${n}) والمعتاد للنمرة دي حوالي ${u.toFixed(2)} — اتأكد من عدد الكونات والنمرة`;
    }
    return '';
  }

  // Ideal gross weight = cones × target per cone (6 cones → 36 kg). The bar shows how far the
  // carton is from it: green within ok%, amber within warn%, red beyond.
  const T_PER_CONE = parseFloat(station.dataset.targetPerCone) || 6;
  const T_OK = parseFloat(station.dataset.targetOk) || 3, T_WARN = parseFloat(station.dataset.targetWarn) || 6;
  function showTarget(g) {
    const box = $('target'), n = parseInt($('conesCount').value || '0', 10);
    if (!g || n <= 0) { box.classList.add('d-none'); return; }
    const target = n * T_PER_CONE, diff = g.value - target, pct = Math.abs(diff) / target * 100;
    const lvl = pct <= T_OK ? 'ok' : pct <= T_WARN ? 'warn' : 'bad';
    box.className = 'target-bar mt-2 ' + lvl;
    $('tbLabel').textContent = `المثالي ${target.toFixed(2)} كيلو (${n} كونات) — ` + (lvl === 'ok' ? 'مظبوط' : diff < 0 ? 'ناقص' : 'زايد');
    $('tbDiff').textContent = (diff >= 0 ? '+' : '') + diff.toFixed(2) + ' kg (' + (diff >= 0 ? '+' : '-') + pct.toFixed(1) + '%)';
    // dot position: centre = ideal, edges = ±(2 × warn%)
    const pos = Math.max(0, Math.min(100, 50 + (diff / target * 100) / (T_WARN * 2) * 50));
    $('tbDot').style.left = pos + '%';
  }

  // The standard carton has STD_CONES cones. Any other count (e.g. a stray ↑/↓ press) is flagged with a
  // banner, and the first save at that count has to be confirmed.
  const STD_CONES = parseInt(station.dataset.stdCones || '6', 10);
  let ackCones = null;      // the non-standard count the operator already confirmed
  let lastConesBeep = null;
  function conesCheck() {
    const n = parseInt($('conesCount').value || '0', 10), off = !mixed && n !== STD_CONES;   // no cone alert in متعدد
    $('conesWarn').innerHTML = off
      ? `<div class="alert alert-warning py-2 mb-0 d-flex justify-content-between align-items-center cones-alert"><span>⚠ عدد الكونات <b>${n}</b> — الطبيعي <b>${STD_CONES}</b></span><button type="button" class="btn btn-sm btn-dark" id="btnStdCones">رجّع ${STD_CONES}</button></div>`
      : '';
    $('conesCount').classList.toggle('is-invalid', off);
    if (off && lastConesBeep !== n) { beep(700, 160, 2); }
    lastConesBeep = off ? n : null;
    if (!off) ackCones = null;   // back to standard: a later odd count must be confirmed again
  }
  $('conesWarn').addEventListener('click', e => {
    if (!e.target.closest('#btnStdCones')) return;
    $('conesCount').value = STD_CONES;
    try { localStorage.setItem('scale.conesCount', String(STD_CONES)); } catch (x) {}
    recalc();
  });

  function recalc() {
    conesCheck();
    showPallet();
    const tare = tareOf();
    drawMini('tare', tare);
    const g = currentGross();
    const net = g ? Math.round((g.value - tare) * 1000) / 1000 : 0;
    drawMini('net', Math.max(0, net));
    showTarget(g);
    currentWarn = sanity(g, net);
    $('warn').innerHTML = currentWarn ? `<div class="alert alert-warning py-2 mb-0">⚠ ${currentWarn}</div>` : '';
    const noCones = mixed && !(parseInt($('conesCount').value || '0', 10) > 0);
    $('btnSave').disabled = !(g && net > 0 && $('finished').value && $('packing').value && !coneMissing && !noCones);
    $('btnSave').textContent = !$('finished').value ? 'اختر النمرة الأول'
      : !$('packing').value ? 'اختر كرتون أو شكارة'
      : noCones ? 'اكتب عدد الكونات'
      : coneMissing ? 'اختر لون الكونة'
      : (awaitingNew ? 'تم الحفظ — استنى وزن جديد…' : 'حفظ وطباعة الستيكر');
    $('btnSave').classList.toggle('waiting', awaitingNew && !g);
    $('btnSave').classList.toggle('ready', !$('btnSave').disabled);
    $('ledSaved').classList.toggle('on', awaitingNew && !g);
    $('display').classList.toggle('unstable', liveGross !== null && !fresh);
    // two cartons in a row can weigh exactly the same; the indicator won't resend, so let the operator confirm
    $('btnSameWeight').classList.toggle('d-none', !(usedValue !== null && liveGross === usedValue && !fresh));
  }
  function sameWeight() {
    if ($('btnSameWeight').classList.contains('d-none')) return;
    if (!confirm(`متأكد إن فيه ${unitNoun()} جديدة على الميزان ووزنها ${liveGross.toFixed(2)} كيلو؟`)) return;
    usedValue = null; fresh = true; recalc();
  }
  $('btnSameWeight').addEventListener('click', sameWeight);

  // ---------- Web Serial ----------
  // Indicator frames vary by model: "ST,GS,+  12.34kg", "  12.34 kg", or reversed "=43.2100" (= 0012.34).
  function parseFrame(line) {
    const s = line.trim();
    if (!s) return null;
    let value;
    if (s.startsWith('=')) {
      value = parseFloat(s.slice(1).split('').reverse().join(''));
    } else {
      const m = s.replace(/\s+/g, '').match(/[-+]?\d+(\.\d+)?/);
      if (!m) return null;
      value = parseFloat(m[0]);
    }
    if (isNaN(value)) return null;
    const unstableFlag = /\bUS\b|US,/.test(s);
    return { value, unstableFlag };
  }

  // short beep like the old station: high tone = new weight ready, double tone = saved
  let audio = null;
  let volume = 0.85;  // 0..1, adjusted with the slider and remembered on this PC
  try { const v = parseFloat(localStorage.getItem('scale.volume')); if (!isNaN(v)) volume = Math.min(1, Math.max(0, v)); } catch (e) {}
  if ($('beepVol')) {   // the volume slider is only shown to admin/store
    $('beepVol').value = volume;
    $('beepVol').addEventListener('input', () => {
      volume = parseFloat($('beepVol').value);
      try { localStorage.setItem('scale.volume', String(volume)); } catch (e) {}
    });
    $('beepVol').addEventListener('change', () => beep());
    $('beepTest').addEventListener('click', () => beep(1200, 90, 2));
  }
  function beep(freq = 1800, ms = 120, times = 1) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      for (let i = 0; i < times; i++) {
        const o = audio.createOscillator(), g = audio.createGain();
        o.frequency.value = freq; o.type = 'square'; g.gain.value = 0.6 * volume * volume;   // squared so the low end of the slider is really quiet
        o.connect(g); g.connect(audio.destination);
        const t = audio.currentTime + i * (ms + 60) / 1000;
        o.start(t); o.stop(t + ms / 1000);
      }
    } catch (e) {}
  }

  // ---------- seven-segment readouts (gross, tare, net) ----------
  const SEG = { a: '14,4 50,4 56,10 50,16 14,16 8,10', g: '14,48 50,48 56,54 50,60 14,60 8,54', d: '14,92 50,92 56,98 50,104 14,104 8,98',
    f: '6,12 12,18 12,46 6,52 0,46 0,18', b: '58,12 64,18 64,46 58,52 52,46 52,18', e: '6,56 12,62 12,90 6,96 0,90 0,62', c: '58,56 64,62 64,90 58,96 52,90 52,62' };
  const DIGITS = { 0: 'abcdef', 1: 'bc', 2: 'abdeg', 3: 'abcdg', 4: 'bcfg', 5: 'acdfg', 6: 'acdefg', 7: 'abc', 8: 'abcdefg', 9: 'abcdfg', '-': 'g', ' ': '' };
  const PITCH = 80;
  // value -> <svg> with unlit "8" ghosts behind the lit segments, a slanted LCD look and a decimal point
  function segSvg(value, intN, decN) {
    const max = Math.pow(10, intN) - Math.pow(10, -decN);
    const t = Math.min(max, Math.max(0, value)).toFixed(decN);
    const [ip, fp] = t.split('.');
    const chars = (ip.padStart(intN, ' ') + fp).split('');
    const gap = 16, w = chars.length * PITCH + gap + 12;
    let h = `<svg viewBox="-6 -4 ${w} 116" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><g transform="skewX(-7) translate(14,0)">`;
    chars.forEach((c, i) => {
      const on = DIGITS[c] || '';
      h += `<g transform="translate(${i * PITCH + (i >= intN ? gap : 0)},0)">` + Object.keys(SEG).map(k => `<polygon points="${SEG[k]}" class="${on.includes(k) ? 'on' : 'off'}"/>`).join('') + '</g>';
    });
    h += `<circle cx="${(intN - 1) * PITCH + 64 + gap / 2 + 3}" cy="100" r="6.5" class="on"/></g></svg>`;
    return h;
  }
  // 30-step load bar like the one on real indicators (full scale = MAX_KG)
  const BAR_N = 30;
  (() => { const bar = $('sdBar'); if (bar) bar.innerHTML = '<i></i>'.repeat(BAR_N); })();
  function drawGross(v) {
    $('display').innerHTML = segSvg(v, 3, 2);
    const lit = Math.round(Math.min(1, v / MAX_KG) * BAR_N);
    const bar = $('sdBar');
    if (bar) [...bar.children].forEach((el, i) => el.classList.toggle('on', i < lit));
  }
  function drawMini(id, v) { $(id).innerHTML = segSvg(v, 3, 3); }
  drawGross(0);

  // the display counts up/down to the new weight like a real indicator settling
  let shown = 0, rollRaf = 0;
  function rollTo(target) {
    cancelAnimationFrame(rollRaf);
    const from = shown, t0 = performance.now(), dur = 450;
    const step = now => {
      const k = Math.min(1, (now - t0) / dur), ease = 1 - Math.pow(1 - k, 3);
      shown = k >= 1 ? target : from + (target - from) * ease;
      drawGross(shown);
      if (k < 1) rollRaf = requestAnimationFrame(step);
    };
    rollRaf = requestAnimationFrame(step);
  }

  function onReading(r) {
    if (r.unstableFlag) return;
    const wasFresh = fresh;
    if (r.value < 0.1) {             // empty scale: the next carton is new even if it weighs the same
      usedValue = null;
      liveGross = r.value; fresh = false;
    } else if (usedValue !== null && Math.abs(r.value - usedValue) < 0.005) {
      liveGross = r.value; fresh = false; // same carton still on the scale
    } else {
      liveGross = r.value; fresh = true; usedValue = null;
    }
    const d = $('display');
    rollTo(r.value);
    d.classList.remove('offline');
    $('ledStable').classList.toggle('on', r.value >= 0.1);
    $('ledZero').classList.toggle('on', r.value < 0.1);
    $('annStable').classList.toggle('on', r.value >= 0.1 && !r.unstableFlag);   // annunciators on the LCD itself
    $('annZero').classList.toggle('on', r.value < 0.1);
    $('annGross').classList.toggle('on', r.value >= 0.1);
    $('plate').classList.toggle('loaded', r.value >= 0.1);
    $('lastAt').textContent = 'آخر وزن وصل الساعة ' + new Date().toLocaleTimeString('ar-EG');
    if (fresh && !wasFresh) { awaitingNew = false; d.classList.remove('bump'); void d.offsetWidth; d.classList.add('bump'); beep(); }
    recalc();
  }

  // Non-fatal serial errors (framing/parity/overrun) end one reader; take a new one and keep going.
  async function readLoop() {
    const dec = new TextDecoder();
    let buf = '';
    keepReading = true;
    const myPort = port;
    setStatus('الميزان متصل', 'bg-success');
    while (keepReading && port === myPort && port?.readable) {
      reader = port.readable.getReader();
      try {
        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          buf += dec.decode(value, { stream: true });
          const parts = buf.split(/[\r\n\x02\x03]+/);
          buf = parts.pop();
          if (buf.length > 200) buf = '';
          for (const p of parts) {
            if (!p.trim()) continue;
            $('rawFrame').textContent = 'آخر قراءة خام: ' + JSON.stringify(p);
            const r = parseFrame(p);
            if (r) onReading(r);
          }
        }
      } catch (e) {
        console.warn('serial read error', e);
      } finally {
        reader.releaseLock();
      }
    }
    if (port !== myPort) return;   // the operator reconnected manually; the new loop owns the display now
    $('display').classList.add('offline');
    fresh = false;
    recalc();
    if (keepReading) {             // lost unexpectedly (cable, USB adapter): release the port and keep trying
      setStatus('انقطع الاتصال بالميزان — بحاول أوصل تاني…', 'bg-danger');
      beep(500, 300, 2);
      try { await port.close(); } catch (e) {}
      port = null;
      scheduleReconnect();
    }
  }

  let retryTimer = null;
  function scheduleReconnect() {
    clearTimeout(retryTimer);
    retryTimer = setTimeout(autoConnect, 3000);
  }
  async function autoConnect() {
    if (!('serial' in navigator) || (port && keepReading)) return;
    try {
      const ports = await navigator.serial.getPorts();
      if (!ports.length) return;
      await openPort(ports[0]);
    } catch (e) {
      port = null;
      setStatus('البورت مشغول أو الميزان مفصول — بحاول تاني…', 'bg-warning text-dark');
      scheduleReconnect();
    }
  }

  async function closePort() {
    keepReading = false;
    try { await reader?.cancel(); } catch (e) {}
    try { await port?.close(); } catch (e) {}
    port = null;
  }

  async function openPort(p) {
    port = p;
    await port.open({ baudRate: BAUD, dataBits: 8, stopBits: 1, parity: 'none' });
    readLoop();
  }

  function setStatus(text, cls) {
    const b = $('scaleStatus');
    b.textContent = text;
    b.className = 'badge ' + cls;
  }

  $('btnConnect').addEventListener('click', async () => {
    if (!('serial' in navigator)) {
      setStatus('المتصفح ده مش بيدعم قراءة الميزان', 'bg-danger');
      alert('قراءة الميزان محتاجة Google Chrome، وعنوان آمن (localhost أو HTTPS أو استثناء في إعدادات Chrome). اكتب الوزن يدوي لحد ما يتظبط.');
      return;
    }
    try {
      if (port) await closePort();
      await openPort(await navigator.serial.requestPort());
    } catch (e) {
      setStatus('لم يتم التوصيل: ' + e.message, 'bg-warning text-dark');
    }
  });

  // reconnect automatically to a port the user already allowed
  if ('serial' in navigator) {
    autoConnect();
    navigator.serial.addEventListener('connect', () => scheduleReconnect());   // adapter plugged back in
  }

  // ---------- save & print ----------
  async function post(data) {
    const body = new URLSearchParams({ csrf: CSRF, ...data });
    const res = await fetch('api_package.php', { method: 'POST', body });
    return res.json();
  }

  // Labels print one after another: each waits for the previous one to be handed to the printer.
  const printQueue = [];
  let printing = false;
  function pumpPrint() {
    if (printing || !printQueue.length) return;
    printing = true;
    const job = printQueue.shift(), f = $('printFrame');
    let finished = false;
    const done = () => { if (finished) return; finished = true; printing = false; pumpPrint(); };
    f.onload = () => {
      // printing the iframe needs it focused, which would leave the keyboard inside the hidden frame
      // (Enter then does nothing until the page is clicked): pass its keys on and take the focus back
      try { f.contentDocument.addEventListener('keydown', onKey); f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
      const back = () => { try { window.focus(); document.activeElement?.blur(); } catch (e) {} };
      back(); setTimeout(back, 300); setTimeout(back, 1200);
      if (job.after) job.after();
      setTimeout(done, 2000);
    };
    setTimeout(done, 10000);   // never stay stuck if a page fails to load
    f.src = job.url + (job.url.includes('?') ? '&' : '?') + 't=' + Date.now();
  }
  function printLabel(id) { printQueue.push({ url: 'label.php?id=' + id, after: () => post({ action: 'printed', id }) }); pumpPrint(); }

  // ---------- pallets: every PALLET_SIZE cartons of one count share a pallet number ----------
  const PALLETS = (() => { try { return JSON.parse(station.dataset.pallets || '{}'); } catch (e) { return {}; } })();
  const PALLET_SIZE = parseInt(station.dataset.palletSize || '20', 10);
  const modeName = () => mixed ? 'متعدد' : 'نظامي';
  // wording follows the packing chosen: شكارة vs كرتونة ('def' = with the article)
  const isSack = () => packName().startsWith('شكارة');
  const unitNoun = (a) => (a === 'def' ? 'ال' : '') + (isSack() ? 'شكارة' : 'كرتونة');
  // pallets are per count + type (نظامي/متعدد) + packing (كرتونة 20 / شكارة 15)
  const palKey = () => $('finished').value + ':' + mixed + ':' + ($('packing').value || 0);
  const palletSizeNow = () => parseInt($('packing').selectedOptions[0]?.dataset.pallet || PALLET_SIZE, 10) || PALLET_SIZE;
  const packName = () => $('packing').selectedOptions[0]?.textContent.trim() || '';
  function showPallet() {
    const box = $('palletInfo'), fid = $('finished').value;
    if (!fid) { box.classList.add('d-none'); return; }
    const k = palKey(), p = pendingNew[k] ? null : PALLETS[k];
    box.classList.remove('d-none');
    box.classList.toggle('mixed', !!mixed);
    const tag = `<span class="badge ${mixed ? 'bg-warning text-dark' : 'bg-primary'} me-1">${modeName()}</span>` + (packName() ? `<span class="badge bg-secondary me-1">${esc(packName())}</span>` : '');
    box.innerHTML = p
      ? `<i class="bi bi-boxes"></i> ${tag}بالتة <b dir="ltr">${esc(p.serial)}</b> — فيها <b>${p.count}</b> من ${p.capacity} — ${unitNoun('def')} الجاية <b>${p.count + 1}</b>`
      : `<i class="bi bi-boxes"></i> ${tag}هتبدأ بالتة ${modeName()} جديدة — ${unitNoun('def')} الجاية <b>1</b> من ${palletSizeNow()}`;
  }

  // ---------- نظامي / متعدد ----------
  function applyMode() {
    document.querySelectorAll('#modeSwitch button').forEach(b => b.classList.toggle('active', (b.dataset.mode === '1') === !!mixed));
    station.classList.toggle('mode-mixed', !!mixed);
    const c = $('conesCount');
    c.readOnly = !mixed;                       // نظامي: the cone count is fixed, no arrows and no alerts
    if (!mixed) c.value = STD_CONES;
    recalc();
  }
  document.querySelectorAll('#modeSwitch button').forEach(b => b.addEventListener('click', () => {
    const m = b.dataset.mode === '1' ? 1 : 0;
    if (m === mixed) return;
    mixed = m;
    try { localStorage.setItem('scale.mode', String(m)); } catch (e) {}
    applyMode();
    askResume();
  }));

  // An unfinished pallet of this count and type that is still in the warehouse: ask whether to continue it.
  // The answer is remembered for the rest of the day (pallet id -> date), so F5 or restarting Chrome does
  // not ask again; the next morning it asks once more.
  const todayStr = () => new Date().toLocaleDateString('en-CA');
  const decided = (() => { try { return JSON.parse(localStorage.getItem('scale.decided') || '{}'); } catch (e) { return {}; } })();
  const saveDecided = () => { try { localStorage.setItem('scale.decided', JSON.stringify(decided)); } catch (e) {} };
  let pcModal = null, pcCtx = null;
  function askResume() {
    const fid = $('finished').value, box = $('palletChoiceModal');
    if (!fid || !box || box.classList.contains('show')) return;
    const k = palKey(), p = PALLETS[k];
    if (!p || p.count < 1 || p.in_stock < 1 || pendingNew[k] || decided[p.id] === todayStr()) return;
    pcModal = pcModal || new bootstrap.Modal(box);
    const fin = $('finished').selectedOptions[0].textContent.split(' — ')[0];
    $('pcBody').innerHTML = `نمرة <b>${esc(fin)}</b> — <b>${modeName()}</b> — <b>${esc(packName())}</b><br>البالتة <b dir="ltr">${esc(p.serial)}</b> وصلت <b>${p.count}</b> من ${p.capacity}، وفيها <b>${p.in_stock}</b> ${unitNoun()} لسه في المخزن.<br>تكمّلها ولا تبدأ بالتة جديدة؟`;
    pcCtx = { k, id: p.id };
    pcModal.show();
  }
  if ($('pcContinue')) {
    $('pcContinue').addEventListener('click', () => { decided[pcCtx.id] = todayStr(); saveDecided(); pcModal.hide(); recalc(); });
    $('pcNew').addEventListener('click', () => { pendingNew[pcCtx.k] = true; persistPending(); decided[pcCtx.id] = todayStr(); saveDecided(); pcModal.hide(); recalc(); });
  }
  $('finished').addEventListener('change', askResume);
  $('packing').addEventListener('change', askResume);     // كرتونة ← → شكارة: each has its own pallets

  function showMsg(html, cls) { $('msg').innerHTML = `<div class="alert alert-${cls} py-2 mb-0">${html}</div>`; }

  let saving = false;
  $('btnSave').addEventListener('click', async () => {
    if (saving) return;
    const g = currentGross();
    if (!g) return;
    const nCones = parseInt($('conesCount').value || '0', 10);
    if (!mixed && nCones !== STD_CONES && ackCones !== nCones) {
      beep(400, 200);
      if (!confirm(`عدد الكونات ${nCones} مش ${STD_CONES} (الطبيعي). متأكد؟`)) return;
      ackCones = nCones;
    }
    // نظامي: Enter saves and prints straight away. An odd-looking (but possible) weight only shows the yellow
    // banner; the question is asked just for impossible readings, and always in متعدد.
    if (currentWarn && (warnHard || mixed)) {
      beep(400, 200);
      if (!confirm(currentWarn + `\n\nتحفظ ${unitNoun('def')} برضه؟`)) return;
    }
    saving = true;
    $('btnSave').disabled = true;
    try {
      const r = await post({
        action: 'save', finished_item_id: $('finished').value, raw_item_id: $('raw').value,
        cone_item_id: $('cone').value, cones_count: $('conesCount').value, packing_type_id: $('packing').value,
        gross: g.value.toFixed(3), weight_source: g.source,
        mixed: mixed ? 1 : 0, new_pallet: pendingNew[palKey()] ? 1 : 0,
      });
      if (!r.ok) { showMsg(r.error, 'danger'); return; }
      const pal = r.pallet;
      showMsg(`تم الحفظ — الباركود <b>${r.serial}</b> — الصافي <b>${Number(r.net).toFixed(3)}</b> كيلو${mixed ? ' <span class="badge bg-warning text-dark">متعدد</span>' : ''}`
        + (pal ? `<div class="saved-no">${unitNoun()} رقم <b>${pal.seq}</b> من ${pal.capacity} <small dir="ltr">— بالتة ${pal.serial}</small></div>` : '')
        + (pal?.full ? `<br><b>🎉 البالتة اتملت (${unitNoun('def')} الأخيرة اتطبعت زي أي ${unitNoun()})</b>` : ''), 'success');
      beep(1200, 90, 2);
      printLabel(r.id);
      if (pal) {
        const k = palKey();
        delete pendingNew[k]; persistPending();
        decided[pal.id] = todayStr(); saveDecided();     // this pallet is the one in use now: don't ask again today
        if (pal.full) { delete PALLETS[k]; setTimeout(() => beep(1500, 140, 3), 700); }
        else PALLETS[k] = { id: pal.id, serial: pal.serial, count: pal.count, in_stock: pal.count, capacity: pal.capacity, mixed: mixed };
      }
      addRecent(r, g.value);
      refreshStats();
      awaitingNew = true;
      $('manualGross').value = '';
      if (g.source === 'scale') { usedValue = g.value; fresh = false; }
    } catch (e) {
      showMsg(`مفيش اتصال بالسيرفر — ${unitNoun('def')} ما اتسجلتش. جرّب تاني.`, 'danger');
    } finally {
      saving = false;
      document.activeElement?.blur();   // so the next Enter isn't swallowed by a focused dropdown
      recalc();
    }
  });

  function addRecent(r, gross) {
    const fin = $('finished').selectedOptions[0].textContent.split(' — ')[0];
    const tr = document.createElement('tr');
    const t = new Date();
    const pal = r.pallet ? `<span class="pal-no">${r.pallet.seq}/${r.pallet.capacity}</span> <span class="small text-muted">${esc(r.pallet.serial)}</span>` : '—';
    tr.innerHTML = `<td class="num">${r.serial}</td><td class="num fw-bold text-nowrap" dir="ltr">${pal}</td><td>${fin}${isSack() ? ' <span class="badge bg-info text-dark">شكارة</span>' : ''}${mixed ? ' <span class="badge bg-warning text-dark">متعدد</span>' : ''}</td><td>${$('conesCount').value}</td><td class="num">${gross.toFixed(2)}</td>
      <td class="num fw-bold">${Number(r.net).toFixed(2)}</td><td>${String(t.getHours()).padStart(2, '0')}:${String(t.getMinutes()).padStart(2, '0')}</td>
      <td><button class="btn btn-sm btn-outline-secondary reprint" data-id="${r.id}">طباعة</button></td>`;
    $('recent').querySelector('tbody').prepend(tr);
    setToday(todayC + (isSack() ? 0 : 1), todayS + (isSack() ? 1 : 0));
    $('todayNet').textContent = (parseFloat($('todayNet').textContent.replace(/,/g, '')) + Number(r.net)).toFixed(1);
  }

  // today's per-count summary + last hour, refreshed after every save (also re-syncs the totals if
  // another station or the office voided something)
  // ---------- warehouse total card: everything on hand (not sold) right now ----------
  const nf = (v, d = 1) => Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  function renderWarehouse(w) {
    const box = $('warehouse');
    if (!box || !w) return;
    const maxKg = Math.max(...w.items.map(i => i.kg), 1);
    box.innerHTML = `
      <div class="wh-head"><span><i class="bi bi-boxes"></i> إجمالي المخزن حتى الآن <span class="wh-mini" dir="ltr">· ${nf(w.total.kg)} kg</span></span><span class="wh-right"><small><i class="bi bi-broadcast"></i> يتحدّث مع كل كرتونة</small><button type="button" class="wh-toggle" id="whToggle"></button></span></div>
      <div class="wh-main">
        <div class="wh-kg" dir="ltr">${nf(w.total.kg)}<small>كيلو صافي</small></div>
        <div class="wh-kpis">
          <div><b>${nf(w.total.cartons ?? w.total.n, 0)}</b><span>كرتونة</span></div>
          ${w.total.sacks ? `<div><b>${nf(w.total.sacks, 0)}</b><span>شكارة</span></div>` : ''}
          <div><b>${nf(w.total.pallets, 0)}</b><span>بالتة</span></div>
          <div><b>${nf(w.total.cones, 0)}</b><span>كونة</span></div>
        </div>
      </div>
      <div class="wh-items">${w.items.length ? w.items.map(i => `
        <div class="wh-row">
          <div class="wh-name"><b>${esc(i.code)}${i.mixed ? ' <em class="wh-tag">متعدد</em>' : ''}${i.sack ? ' <em class="wh-tag sack">شكاير</em>' : ''}</b><span>${esc(i.name)}</span></div>
          <div class="wh-bar"><i style="width:${Math.max(4, Math.round(i.kg / maxKg * 100))}%"></i></div>
          <div class="wh-num" dir="ltr">${nf(i.kg)} kg</div>
          <div class="wh-sub">${i.n} ${i.sack ? 'شكارة' : 'كرتونة'} · ${i.pallets} بالتة${i.open ? ` · الجارية ${i.open}/${i.cap || PALLET_SIZE}` : ''}</div>
        </div>`).join('') : '<div class="wh-empty">المخزن فاضي حالياً</div>'}</div>`;
    whApply();
  }
  // scale-only view (no sidebar / top bar / stock / summary): remembered per browser
  function focusApply(on) {
    document.getElementById('app')?.classList.toggle('scale-focus', on);
    if (on) focusStrip();
    const b = $('focusBtn');
    if (b) b.innerHTML = on ? '<i class="bi bi-fullscreen-exit"></i> رجوع للصفحة الكاملة' : '<i class="bi bi-arrows-fullscreen"></i> الميزان بس';
  }
  function focusStrip() {
    const el = $('focusStrip');
    if (!el) return;
    const parts = ($('finished').selectedOptions[0]?.textContent || '').split(' — ');
    const code = ($('finished').value ? parts[0] : '').trim() || '—';
    const name = parts.slice(1).join(' — ').trim();
    const k = palKey(), p = pendingNew[k] ? null : PALLETS[k];
    const pal = $('finished').value
      ? (p ? `<span class="fs-state cont">تكميل بالتة</span> <b dir="ltr">${esc(p.serial)}</b> — فيها <b>${p.count}</b> من ${p.capacity} — ${unitNoun('def')} الجاية <b>${p.count + 1}</b>`
           : `<span class="fs-state new">بالتة ${esc(modeName())} جديدة</span> ${unitNoun('def')} الجاية <b>1</b> من ${palletSizeNow()}`)
      : '';
    const tare = ($('tareDetail')?.textContent || '').trim();
    el.innerHTML = `<div class="fs-main"><span class="fs-code">${esc(code)}</span>${name ? `<span class="fs-name">${esc(name)}</span>` : ''}</div>`
      + `<div class="fs-pills"><span class="fs-pill ${mixed ? 'mix' : ''}">${esc(modeName())}</span><span class="fs-pill">${esc(packName())}</span><span class="fs-pill">${esc($('conesCount').value || 0)} كونة</span></div>`
      + (pal ? `<div class="fs-pal"><i class="bi bi-boxes"></i> ${pal}</div>` : '')
      + (tare ? `<div class="fs-tare">${esc(tare)}</div>` : '');
  }
  setInterval(() => { if (document.getElementById('app')?.classList.contains('scale-focus')) focusStrip(); }, 400);
  (function () {
    let on = false;
    try { on = localStorage.getItem('scaleFocus') === '1'; } catch (e) {}
    focusApply(on);
    $('focusBtn')?.addEventListener('click', () => {
      on = !on;
      try { localStorage.setItem('scaleFocus', on ? '1' : '0'); } catch (e) {}
      focusApply(on);
      $('focusBtn').blur();   // so a later Enter saves instead of re-clicking this button
    });
  })();
  function whApply() {
    const box = $('warehouse'), t = $('whToggle');
    if (!box) return;
    let hid = false;
    try { hid = localStorage.getItem('whHidden') === '1'; } catch (e) {}
    box.classList.toggle('wh-collapsed', hid);
    if (t) t.innerHTML = hid ? '<i class="bi bi-chevron-down"></i> إظهار' : '<i class="bi bi-chevron-up"></i> إخفاء';
  }
  $('warehouse')?.addEventListener('click', e => {
    if (!e.target.closest('#whToggle')) return;
    let hid = false;
    try { hid = localStorage.getItem('whHidden') === '1'; localStorage.setItem('whHidden', hid ? '0' : '1'); } catch (er) { $('warehouse').classList.toggle('wh-collapsed'); return whApply(); }
    whApply();
  });
  try { renderWarehouse(JSON.parse($('warehouse')?.dataset.wh || 'null')); } catch (e) {}

  const countText = (c, s) => [c > 0 || !s ? `${c} كرتونة` : '', s > 0 ? `${s} شكارة` : ''].filter(Boolean).join(' + ');
  let todayC = 0, todayS = 0;
  function setToday(c, s) { todayC = Number(c) || 0; todayS = Number(s) || 0; $('todayN').textContent = countText(todayC, todayS); }
  setToday($('todayN').dataset.c, $('todayN').dataset.s);

  async function refreshStats() {
    try {
      const r = await post({ action: 'stats' });
      if (!r.ok) return;
      renderWarehouse(r.warehouse);
      $('summary').querySelector('tbody').innerHTML = r.items.length
        ? r.items.map(i => `<tr><td>${esc(i.code)}${Number(i.mixed) ? ' <span class="badge bg-warning text-dark">متعدد</span>' : ''}</td><td>${i.n} ${Number(i.sack) ? 'شكارة' : 'كرتونة'}</td><td>${i.cones}</td><td class="num fw-bold">${Number(i.net).toFixed(1)}</td></tr>`).join('')
        : '<tr><td colspan="4" class="text-muted text-center">لسه مفيش إنتاج النهارده</td></tr>';
      $('hourN').textContent = countText(r.hour.cartons, r.hour.sacks);
      $('hourNet').textContent = Number(r.hour.net).toFixed(1);
      setToday(r.total.cartons, r.total.sacks);
      $('todayNet').textContent = Number(r.total.net).toFixed(1);
    } catch (e) {}
  }

  $('recent').addEventListener('click', e => {
    const b = e.target.closest('.reprint');
    if (b) printLabel(b.dataset.id);
  });

  // keyboard: Enter saves and prints from anywhere on the page (like the old station)
  function onKey(e) {
    const modalOpen = !!document.querySelector('.modal.show');
    if (!modalOpen && !e.repeat) {
      if (e.key === 'F2') { e.preventDefault(); sameWeight(); return; }
      if (e.key === 'F4') {   // reprint the newest carton that is not voided
        e.preventDefault();
        const row = [...$('recent').querySelectorAll('tbody tr')].find(tr => !tr.classList.contains('text-muted'));
        if (row) printLabel(row.querySelector('.reprint').dataset.id);
        return;
      }
      if (mixed && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && !/^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName)) {   // arrows only in متعدد
        e.preventDefault();
        const c = $('conesCount');
        c.value = Math.max(0, (parseInt(c.value || '0', 10)) + (e.key === 'ArrowUp' ? 1 : -1));
        try { localStorage.setItem('scale.conesCount', c.value); } catch (x) {}
        recalc();
        return;
      }
    }
    if (e.key !== 'Enter' || e.repeat) return;
    if (modalOpen) {   // Enter inside the weights dialog = save the dialog
      if (e.target.id === 'ptAdminPass') { e.preventDefault(); $('ptSave').click(); }
      return;
    }
    e.preventDefault();
    if (!$('btnSave').disabled) $('btnSave').click();
    else beep(400, 200);   // low tone: nothing to save yet
  }
  document.addEventListener('keydown', onKey);

  // ---------- edit packing types (needs the admin password on save) ----------
  // the dialog is only rendered for admin/store; the scale operator has no way to open it
  if ($('packingModal')) (() => {
  const ptData = JSON.parse($('ptData').textContent);
  const ptModal = new bootstrap.Modal($('packingModal'));

  function ptRow(t) {
    const packs = ['<option value="">—</option>'].concat(ptData.packs.map(p =>
      `<option value="${p.id}"${Number(t.pack_item_id) === Number(p.id) ? ' selected' : ''}>${esc(p.code + ' — ' + p.name)}</option>`)).join('');
    const num = (k, v) => `<input type="number" step="0.001" min="0" class="form-control form-control-sm num" data-k="${k}" value="${v ?? ''}">`;
    const tr = document.createElement('tr');
    tr.dataset.id = t.id || 0;
    tr.innerHTML = `<td><input class="form-control form-control-sm" data-k="name" value="${esc(t.name)}"></td>
      <td><select class="form-select form-select-sm" data-k="pack_item_id">${packs}</select></td>
      <td class="d-none">${num('cone_weight', t.cone_weight || 0)}</td><td>${num('pack_weight', t.pack_weight)}</td><td>${num('bag_weight', t.bag_weight)}</td>
      <td><input type="number" step="1" min="1" max="200" class="form-control form-control-sm num" style="width:80px" data-k="pallet_size" value="${t.pallet_size || 20}"></td>
      <td><input type="checkbox" class="form-check-input" data-k="active"${t.active == 0 ? '' : ' checked'}></td>`;
    return tr;
  }

  function fillPacking(types) {
    ptData.types = types;
    const sel = $('packing'), cur = sel.value;
    sel.innerHTML = '<option value="">— اختر —</option>' + types.filter(t => Number(t.active)).map(t =>
      `<option value="${t.id}" data-cone="${t.cone_weight}" data-pack="${t.pack_weight}" data-bag="${t.bag_weight}" data-pallet="${t.pallet_size}">${esc(t.name)}</option>`).join('');
    sel.value = cur;
    recalc();
  }

  $('btnEditPacking').addEventListener('click', e => {
    e.preventDefault();
    const tb = $('ptTable').querySelector('tbody');
    tb.innerHTML = '';
    ptData.types.filter(t => Number(t.active)).forEach(t => tb.appendChild(ptRow(t)));   // old inactive types stay in the database for history but are not edited here
    $('ptAdminPass').value = '';
    $('ptMsg').innerHTML = '';
    ptModal.show();
  });
  $('ptAdd').addEventListener('click', () => $('ptTable').querySelector('tbody').appendChild(ptRow({ id: 0, name: '', active: 1 })));

  $('ptSave').addEventListener('click', async () => {
    const rows = [...$('ptTable').querySelectorAll('tbody tr')].map(tr => {
      const r = { id: Number(tr.dataset.id) };
      tr.querySelectorAll('[data-k]').forEach(el => { r[el.dataset.k] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
      return r;
    });
    if (!$('ptAdminPass').value) { $('ptMsg').innerHTML = '<div class="text-danger">اكتب باسورد المدير</div>'; return; }
    $('ptSave').disabled = true;
    try {
      const body = new URLSearchParams({ csrf: CSRF, admin_password: $('ptAdminPass').value, rows: JSON.stringify(rows) });
      const r = await (await fetch('api_packing.php', { method: 'POST', body })).json();
      if (!r.ok) { $('ptMsg').innerHTML = `<div class="text-danger">${esc(r.error)}</div>`; return; }
      fillPacking(r.types);
      ptModal.hide();
      showMsg(`تم حفظ أوزان التعبئة (وافق: ${esc(r.approved_by)})`, 'info');
    } catch (e) {
      $('ptMsg').innerHTML = '<div class="text-danger">مفيش اتصال بالسيرفر</div>';
    } finally {
      $('ptSave').disabled = false;
      $('ptAdminPass').value = '';
    }
  });
  })();

  applyMode();                    // also runs recalc()
  setTimeout(askResume, 600);     // coming back to an unfinished pallet: ask whether to continue it
})();
