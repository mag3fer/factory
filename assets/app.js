// Shared UI behaviour: live clock, animated counters, sidebar close on mobile.
(() => {
  // Bootstrap modals must live directly under <body>: the animated .content wrapper creates a
  // stacking context that would put the dialog underneath the dark backdrop.
  document.querySelectorAll('.modal').forEach(m => { if (m.parentElement !== document.body) document.body.appendChild(m); });

  const clock = document.getElementById('clock');
  if (clock) {
    const tick = () => { clock.textContent = new Date().toLocaleString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }); };
    tick(); setInterval(tick, 30000);
  }

  // <span data-count="1234.5" data-dec="1"></span> counts up from 0 on load
  const fmt = (v, d) => v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  document.querySelectorAll('[data-count]').forEach(el => {
    const target = parseFloat(el.dataset.count) || 0, dec = parseInt(el.dataset.dec || '0', 10);
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = fmt(target, dec); return; }
    const t0 = performance.now(), dur = 900;
    const step = now => {
      const p = Math.min(1, (now - t0) / dur), ease = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(target * ease, dec);
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });

  document.addEventListener('click', e => {
    const app = document.getElementById('app');
    if (app?.classList.contains('sidebar-open') && !e.target.closest('.sidebar, .sidebar-toggle')) app.classList.remove('sidebar-open');
  });
})();
