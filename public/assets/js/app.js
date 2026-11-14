/* NextaLeadMap UI — vanilla JS + Alpine.js components. No inline scripts (strict CSP). */
(function () {
  'use strict';

  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const readJson = (id) => {
    const el = document.getElementById(id);
    if (!el) return {};
    try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
  };
  const post = (url, data) => fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data || {}),
  });

  // ───────────── Alpine components ─────────────
  document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.data('jobForm', () => {
      const d = readJson('job-form-data');
      return {
        keywords: d.keywords || '',
        mode: d.mode || 'city',
        city: d.city || '', cityLat: d.cityLat || '', cityLon: d.cityLon || '', cityCountry: '',
        lat: d.lat || '', lon: d.lon || '', radius: d.radius || '5',
        grid: d.grid || 1, deep: !!d.deep,
        provider: d.provider || '', perSearch: d.perSearch || 60, caps: d.caps || {},
        noWebsite: !!d.noWebsite,
        types: d.types || [], typeQuery: '',
        suggestions: [], hi: 0, geoMsg: '', _ctl: null,
        get cap() { return this.caps[this.provider] || 60; },
        get keywordList() { return this.keywords.split(/\r?\n/).map((s) => s.trim()).filter(Boolean); },
        get keywordCount() { return new Set(this.keywordList.map((k) => k.toLowerCase())).size; },
        get searches() { const g = this.mode === 'none' ? 1 : Number(this.grid || 1); return this.keywordCount * g * g; },
        get calls() { return this.searches * Math.ceil(Math.min(this.perSearch, this.cap) / 20); },
        get filteredTypes() {
          const q = this.typeQuery.trim().toLowerCase();
          const have = new Set(this.keywordList.map((k) => k.toLowerCase()));
          return this.types.filter((t) => !have.has(t.toLowerCase()) && (!q || t.toLowerCase().includes(q))).slice(0, q ? 60 : 24);
        },
        addKeyword(t) { this.keywords = (this.keywords.trim() ? this.keywords.trim() + '\n' : '') + t; },
        async lookupCity() {
          this.cityLat = ''; this.cityLon = ''; this.cityCountry = '';
          const q = this.city.trim();
          if (q.length < 2) { this.suggestions = []; return; }
          if (this._ctl) this._ctl.abort();
          this._ctl = new AbortController();
          try {
            const r = await fetch(d.citiesUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: this._ctl.signal });
            const j = await r.json();
            this.suggestions = j.data || []; this.hi = 0;
          } catch (e) { /* aborted */ }
        },
        move(n) { if (this.suggestions.length) this.hi = (this.hi + n + this.suggestions.length) % this.suggestions.length; },
        enter(ev) { if (this.suggestions.length) { ev.preventDefault(); this.pick(this.suggestions[this.hi]); } },
        pick(s) { this.city = s.label; this.cityLat = s.lat; this.cityLon = s.lon; this.cityCountry = s.country || ''; this.suggestions = []; },
        locate() {
          if (!navigator.geolocation) { this.geoMsg = 'Geolocation is not available in this browser.'; return; }
          this.geoMsg = 'Locating…';
          navigator.geolocation.getCurrentPosition(
            (p) => { this.lat = p.coords.latitude.toFixed(6); this.lon = p.coords.longitude.toFixed(6); this.geoMsg = 'Location set (±' + Math.round(p.coords.accuracy) + ' m)'; },
            (e) => { this.geoMsg = 'Could not get location: ' + e.message; },
            { enableHighAccuracy: true, timeout: 10000 }
          );
        },
      };
    });

    const BADGES = {
      pending: 'bg-slate-100 text-slate-700 ring-slate-200', running: 'bg-sky-50 text-sky-700 ring-sky-200',
      enriching: 'bg-violet-50 text-violet-700 ring-violet-200', done: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
      failed: 'bg-rose-50 text-rose-700 ring-rose-200', cancelled: 'bg-amber-50 text-amber-700 ring-amber-200',
    };

    Alpine.data('jobProgress', () => {
      const d = readJson('job-progress-data');
      return {
        status: d.status, percent: d.percent || 0, message: d.message || '', places: d.places || 0, emails: d.emails || 0,
        active: !!d.active, timer: null,
        get badge() {
          const s = BADGES[this.status] ? this.status : 'pending';
          return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ' + BADGES[s] + '">' + s.charAt(0).toUpperCase() + s.slice(1) + '</span>';
        },
        init() { if (this.active) this.timer = setInterval(() => this.poll(), 3000); },
        async poll() {
          try {
            const r = await fetch(d.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!r.ok) return;
            const j = await r.json();
            this.status = j.status; this.percent = j.progress.percent; this.message = j.progress.message;
            this.places = j.places_found; this.emails = j.emails_found;
            if (!['pending', 'running', 'enriching'].includes(j.status)) {
              clearInterval(this.timer); this.active = false;
              setTimeout(() => window.location.reload(), 800);
            }
          } catch (e) { /* network blip */ }
        },
      };
    });

    Alpine.data('providerTest', () => ({
      busy: false, result: null,
      async run(url) {
        this.busy = true; this.result = null;
        try {
          const r = await post(url, {});
          this.result = await r.json();
          if (this.result.error) this.result = { ok: false, message: this.result.error.message };
        } catch (e) { this.result = { ok: false, message: 'Request failed: ' + e.message }; }
        this.busy = false;
      },
    }));
  });

  // ───────────── Progressive enhancements ─────────────
  document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('submit', (ev) => {
      const f = ev.target;
      if (!(f instanceof HTMLFormElement)) return;
      if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) { ev.preventDefault(); return; }
      if (f.dataset.confirmDelete) {
        const action = f.querySelector('[name="action"]');
        if (action && action.value === 'delete' && !window.confirm(f.dataset.confirmDelete)) { ev.preventDefault(); return; }
      }
      const btn = f.querySelector('button:not([type="button"])');
      if (btn && !f.hasAttribute('target')) { setTimeout(() => { btn.disabled = true; btn.classList.add('opacity-60'); }, 0); }
    });

    document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.submit()));

    document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
      const t = document.querySelector(btn.dataset.copy);
      if (!t) return;
      try { await navigator.clipboard.writeText(t.value || t.textContent); } catch (e) { t.select && t.select(); document.execCommand('copy'); }
      const old = btn.innerHTML; btn.classList.add('text-emerald-600');
      setTimeout(() => { btn.classList.remove('text-emerald-600'); btn.innerHTML = old; }, 1200);
    }));

    document.querySelectorAll('[data-back]').forEach((a) => a.addEventListener('click', (ev) => {
      if (window.history.length > 1) { ev.preventDefault(); window.history.back(); }
    }));

    document.querySelectorAll('[data-select-all]').forEach((all) => all.addEventListener('change', () => {
      document.querySelectorAll('[data-select-item]').forEach((cb) => { cb.checked = all.checked; });
    }));

    document.querySelectorAll('[data-lead-status]').forEach((sel) => sel.addEventListener('change', async () => {
      sel.disabled = true;
      try {
        const r = await post(sel.dataset.leadStatus, { lead_status: sel.value });
        sel.classList.add(r.ok ? 'ring-emerald-400' : 'ring-rose-400');
      } catch (e) { sel.classList.add('ring-rose-400'); }
      sel.disabled = false;
      setTimeout(() => sel.classList.remove('ring-emerald-400', 'ring-rose-400'), 1200);
    }));
  });

  // ───────────── Dashboard charts ─────────────
  window.addEventListener('load', () => {
    const data = readJson('dashboard-data');
    if (!window.Chart || !data.labels) return;
    const Chart = window.Chart;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#64748b';
    const lc = document.getElementById('chart-leads');
    if (lc) {
      new Chart(lc, {
        type: 'line',
        data: {
          labels: data.labels,
          datasets: [
            { label: 'Leads', data: data.leads, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.12)', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 },
            { label: 'With email', data: data.emails, borderColor: '#10b981', backgroundColor: 'transparent', tension: .35, pointRadius: 0, borderWidth: 2 },
          ],
        },
        options: {
          maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
          scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0 } } },
        },
      });
    }
    const cc = document.getElementById('chart-categories');
    if (cc && data.categories && data.categories.values.length) {
      new Chart(cc, {
        type: 'doughnut',
        data: { labels: data.categories.labels, datasets: [{ data: data.categories.values, borderWidth: 0,
          backgroundColor: ['#4f46e5', '#7c3aed', '#0ea5e9', '#10b981', '#f59e0b', '#f43f5e', '#64748b', '#14b8a6'] }] },
        options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } } },
      });
    } else if (cc) {
      cc.parentElement.innerHTML = '<p class="grid h-full place-items-center text-sm text-slate-400">No data yet</p>';
    }
  });
})();
