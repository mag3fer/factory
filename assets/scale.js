// Scale station: reads the Anheng indicator over RS-232 (Web Serial), computes tare/net, saves and prints.
(() => {
  const $ = id => document.getElementById(id);
  const station = $('station');
  const CSRF = station.dataset.csrf;
  const BAG = parseFloat(station.dataset.bag || '0');
  const BAUD = parseInt(station.dataset.baud || '9600', 10);

  let liveGross = null;      // last reading from the scale (kg)
  let stable = false;
  let history = [];          // recent readings for stability detection
  let port = null, reader = null;
  let needRemoval = false;   // after saving from the scale, wait until the carton is lifted off (prevents double save)

  // ---------- remember selections between cartons ----------
  const KEEP = ['finished', 'raw', 'cone', 'conesCount', 'pack'];
  KEEP.forEach(id => {
    try { const v = localStorage.getItem('scale.' + id); if (v !== null) $(id).value = v; } catch (e) {}
    $(id).addEventListener('change', () => { try { localStorage.setItem('scale.' + id, $(id).value); } catch (e) {} recalc(); });
  });
  $('conesCount').addEventListener('input', recalc);
  $('manualGross').addEventListener('input', recalc);

  function tareOf() {
    const coneOpt = $('cone').selectedOptions[0], packOpt = $('pack').selectedOptions[0];
    const coneW = parseFloat(coneOpt?.dataset.tare || '0');
    const packW = parseFloat(packOpt?.dataset.tare || '0');
    const n = Math.max(0, parseInt($('conesCount').value || '0', 10));
    const tare = n * coneW + packW + n * BAG;
    $('tareDetail').textContent = `الفوارغ = ${n} كونة × ${coneW.toFixed(3)} + تعبئة ${packW.toFixed(3)} + ${n} كيس × ${BAG.toFixed(3)} = ${tare.toFixed(3)} كيلو`;
    return Math.round(tare * 1000) / 1000;
  }

  function currentGross() {
    const manual = parseFloat($('manualGross').value);
    if (!isNaN(manual) && manual > 0) return { value: manual, source: 'manual' };
    if (liveGross !== null && stable && !needRemoval) return { value: liveGross, source: 'scale' };
    return null;
  }

  function recalc() {
    const tare = tareOf();
    $('tare').textContent = tare.toFixed(3);
    const g = currentGross();
    const net = g ? Math.round((g.value - tare) * 1000) / 1000 : 0;
    $('net').textContent = net.toFixed(3);
    $('btnSave').disabled = !(g && net > 0 && $('finished').value);
    $('btnSave').textContent = needRemoval && !g ? 'ارفع الكرتونة من على الميزان…' : (!$('finished').value ? 'اختر النمرة الأول' : 'حفظ وطباعة الستيكر');
  }

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

  function onReading(r) {
    liveGross = r.value;
    if (needRemoval && r.value < 0.5) needRemoval = false;
    history.push(r.value);
    if (history.length > 6) history.shift();
    const spread = Math.max(...history) - Math.min(...history);
    stable = !r.unstableFlag && history.length >= 4 && spread <= 0.05;
    const d = $('display');
    d.textContent = r.value.toFixed(2);
    d.classList.remove('offline');
    d.classList.toggle('unstable', !stable);
    recalc();
  }

  async function readLoop() {
    const decoder = new TextDecoderStream();
    port.readable.pipeTo(decoder.writable).catch(() => {});
    reader = decoder.readable.getReader();
    let buf = '';
    setStatus('الميزان متصل', 'bg-success');
    try {
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += value;
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
      setStatus('انقطع الاتصال بالميزان', 'bg-danger');
    }
    $('display').classList.add('offline');
    stable = false;
    recalc();
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
      if (port) { try { await reader?.cancel(); await port.close(); } catch (e) {} port = null; }
      await openPort(await navigator.serial.requestPort());
    } catch (e) {
      setStatus('لم يتم التوصيل: ' + e.message, 'bg-warning text-dark');
    }
  });

  // reconnect automatically to a port the user already allowed
  if ('serial' in navigator) {
    navigator.serial.getPorts().then(ports => { if (ports.length) openPort(ports[0]).catch(e => setStatus('البورت مشغول — اقفل برنامج الميزان القديم', 'bg-warning text-dark')); });
  }

  // ---------- save & print ----------
  async function post(data) {
    const body = new URLSearchParams({ csrf: CSRF, ...data });
    const res = await fetch('api_package.php', { method: 'POST', body });
    return res.json();
  }

  function printLabel(id) {
    const f = $('printFrame');
    f.onload = () => {
      try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {}
      post({ action: 'printed', id });
    };
    f.src = 'label.php?id=' + id + '&t=' + Date.now();
  }

  function showMsg(html, cls) { $('msg').innerHTML = `<div class="alert alert-${cls} py-2 mb-0">${html}</div>`; }

  let saving = false;
  $('btnSave').addEventListener('click', async () => {
    if (saving) return;
    const g = currentGross();
    if (!g) return;
    saving = true;
    $('btnSave').disabled = true;
    try {
      const r = await post({
        action: 'save', finished_item_id: $('finished').value, raw_item_id: $('raw').value,
        cone_item_id: $('cone').value, cones_count: $('conesCount').value, pack_item_id: $('pack').value,
        gross: g.value.toFixed(3), weight_source: g.source,
      });
      if (!r.ok) { showMsg(r.error, 'danger'); return; }
      showMsg(`تم الحفظ — الباركود <b>${r.serial}</b> — الصافي <b>${Number(r.net).toFixed(3)}</b> كيلو`, 'success');
      printLabel(r.id);
      addRecent(r, g.value);
      $('manualGross').value = '';
      if (g.source === 'scale') needRemoval = true;
    } catch (e) {
      showMsg('مفيش اتصال بالسيرفر — الكرتونة ما اتسجلتش. جرّب تاني.', 'danger');
    } finally {
      saving = false;
      recalc();
    }
  });

  function addRecent(r, gross) {
    const fin = $('finished').selectedOptions[0].textContent.split(' — ')[0];
    const tr = document.createElement('tr');
    const t = new Date();
    tr.innerHTML = `<td class="num">${r.serial}</td><td>${fin}</td><td>${$('conesCount').value}</td><td class="num">${gross.toFixed(2)}</td>
      <td class="num fw-bold">${Number(r.net).toFixed(2)}</td><td>${String(t.getHours()).padStart(2, '0')}:${String(t.getMinutes()).padStart(2, '0')}</td>
      <td><button class="btn btn-sm btn-outline-secondary reprint" data-id="${r.id}">طباعة</button></td>`;
    $('recent').querySelector('tbody').prepend(tr);
    $('todayN').textContent = parseInt($('todayN').textContent, 10) + 1;
    $('todayNet').textContent = (parseFloat($('todayNet').textContent.replace(/,/g, '')) + Number(r.net)).toFixed(1);
  }

  $('recent').addEventListener('click', e => {
    const b = e.target.closest('.reprint');
    if (b) printLabel(b.dataset.id);
  });

  // keyboard: Enter saves (for the scale operator)
  document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !$('btnSave').disabled && document.activeElement.tagName !== 'SELECT') { e.preventDefault(); $('btnSave').click(); }
  });

  recalc();
})();
