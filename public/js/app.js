// ---- Confirmation dialog (Fluent 2 style) ----
// Replaces window.confirm for:
//   <form data-confirm="Question? Explanation.">            confirm on submit
//   <button data-confirm-btn="Question? Explanation.">      confirm a specific submit button (name/value is kept)
//   <a data-confirm="...">                                  confirm before following a link
// Optional on the same element or its form: data-confirm-title, data-confirm-ok, data-confirm-tone="danger|primary".
(function () {
  const DANGER = /\b(delete|remove|offboard|wipe|retire|disable|revoke|archive|reclaim|discard|forget|block|cancel this|switch off|make .*private|reject|unassign|lose)\b/i;
  let dlg;
  let lastFocus;

  function build() {
    dlg = document.createElement('div');
    dlg.className = 'tg-dialog-scrim';
    dlg.hidden = true;
    dlg.innerHTML =
      '<div class="tg-dialog" role="alertdialog" aria-modal="true" aria-labelledby="tgDlgTitle" aria-describedby="tgDlgBody">' +
      '<div class="tg-dialog-head"><span class="tg-dialog-icon" aria-hidden="true"></span><h2 id="tgDlgTitle"></h2></div>' +
      '<p id="tgDlgBody"></p>' +
      '<div class="tg-dialog-actions"><button type="button" class="btn btn-primary" data-act="ok"></button><button type="button" class="btn" data-act="cancel">Cancel</button></div>' +
      '</div>';
    document.body.appendChild(dlg);
  }

  /** Split "Question? Explanation." into a title and a body. */
  function parse(msg) {
    const text = String(msg || '').trim();
    const q = text.indexOf('?');
    if (q > 0 && q < 140) {
      return { title: text.slice(0, q + 1), body: text.slice(q + 1).trim() };
    }
    return { title: 'Please confirm', body: text };
  }

  function okLabel(trigger, tone) {
    const t = (trigger && trigger.textContent || '').replace(/\s+/g, ' ').trim();
    if (t && t.length <= 28 && !/^(ok|submit|save)$/i.test(t)) return t;
    return tone === 'danger' ? 'Yes, continue' : 'Confirm';
  }

  /** Show the dialog. Resolves true when confirmed. */
  window.tgConfirm = function (message, opts = {}) {
    if (!dlg) build();
    const { title, body } = parse(message);
    const tone = opts.tone || (DANGER.test(message) || (opts.trigger && /btn-(solid-)?danger/.test(opts.trigger.className)) ? 'danger' : 'primary');
    const box = dlg.querySelector('.tg-dialog');
    const ok = dlg.querySelector('[data-act=ok]');
    const cancel = dlg.querySelector('[data-act=cancel]');
    box.classList.toggle('is-danger', tone === 'danger');
    dlg.querySelector('#tgDlgTitle').textContent = opts.title || title;
    const p = dlg.querySelector('#tgDlgBody');
    p.textContent = body;
    p.hidden = !body;
    ok.textContent = opts.ok || okLabel(opts.trigger, tone);
    ok.className = 'btn ' + (tone === 'danger' ? 'btn-solid-danger' : 'btn-primary');
    lastFocus = document.activeElement;
    dlg.hidden = false;
    document.body.classList.add('tg-dialog-open');
    requestAnimationFrame(() => dlg.classList.add('open'));
    // Destructive actions start on Cancel; others on the primary action.
    (tone === 'danger' ? cancel : ok).focus();

    return new Promise((resolve) => {
      function close(result) {
        dlg.classList.remove('open');
        dlg.hidden = true;
        document.body.classList.remove('tg-dialog-open');
        dlg.removeEventListener('click', onClick);
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
        resolve(result);
      }
      function onClick(e) {
        if (e.target === dlg) return close(false);
        const act = e.target.closest('[data-act]');
        if (act) close(act.dataset.act === 'ok');
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(false); }
        if (e.key === 'Tab') {
          // Keep focus inside the dialog.
          e.preventDefault();
          (document.activeElement === ok ? cancel : ok).focus();
        }
      }
      dlg.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey, true);
    });
  };

  function attr(el, form, name) {
    return (el && el.dataset[name]) || (form && form.dataset[name]) || undefined;
  }

  function busy(form, submitter) {
    if (!form.dataset.busy) return;
    const btn = submitter || form.querySelector('button[type=submit], button:not([type])');
    if (btn) {
      btn.dataset.label = btn.textContent;
      btn.textContent = form.dataset.busy;
      setTimeout(() => (btn.disabled = true), 0); // after the browser has read the button's name/value
    }
  }

  // Buttons with their own question.
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-confirm-btn], button[data-confirm]');
    if (!btn || btn.disabled) return;
    const form = btn.form || btn.closest('form');
    if (btn.dataset.tgConfirmed) { delete btn.dataset.tgConfirmed; return; }
    e.preventDefault();
    tgConfirm(btn.dataset.confirmBtn || btn.dataset.confirm, { trigger: btn, title: attr(btn, form, 'confirmTitle'), ok: attr(btn, form, 'confirmOk'), tone: attr(btn, form, 'confirmTone') }).then((yes) => {
      if (!yes) return;
      if (form) {
        form.dataset.tgConfirmed = '1';
        form.requestSubmit ? form.requestSubmit(btn) : (btn.dataset.tgConfirmed = '1', btn.click());
      }
    });
  }, true);

  // Links with a question.
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-confirm]');
    if (!a) return;
    e.preventDefault();
    tgConfirm(a.dataset.confirm, { trigger: a, title: a.dataset.confirmTitle, ok: a.dataset.confirmOk, tone: a.dataset.confirmTone }).then((yes) => { if (yes) location.href = a.href; });
  }, true);

  // Forms with a question; busy state for long-running forms.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const submitter = e.submitter;
    if (form.dataset.tgConfirmed) {
      delete form.dataset.tgConfirmed;
      busy(form, submitter);
      return;
    }
    const msg = form.dataset.confirm;
    if (!msg) { busy(form, submitter); return; }
    e.preventDefault();
    tgConfirm(msg, { trigger: submitter || form.querySelector('button[type=submit], button:not([type])'), title: form.dataset.confirmTitle, ok: form.dataset.confirmOk, tone: form.dataset.confirmTone }).then((yes) => {
      if (!yes) return;
      form.dataset.tgConfirmed = '1';
      form.requestSubmit ? form.requestSubmit(submitter || undefined) : form.submit();
    });
  });
})();

// Client-side filtering of findings on the tenant page.
(function () {
  const root = document.getElementById('findings');
  if (!root) return;
  const controls = document.querySelectorAll('[data-filter]');
  const counter = document.getElementById('finding-count');

  function apply() {
    const f = {};
    controls.forEach((c) => (f[c.dataset.filter] = c.value.trim().toLowerCase()));
    let shown = 0;
    root.querySelectorAll('.finding').forEach((el) => {
      const d = el.dataset;
      const ok =
        (!f.status || d.status === f.status) &&
        (!f.severity || d.severity === f.severity) &&
        (!f.category || d.category === f.category) &&
        (!f.framework || (d.frameworks || '').split(' ').includes(f.framework)) &&
        (!f.q || d.search.includes(f.q));
      el.hidden = !ok;
      if (ok) shown++;
    });
    if (counter) counter.textContent = shown;
  }

  controls.forEach((c) => c.addEventListener('input', apply));
  apply();

  // Open the finding referenced by the URL hash (after a fix or annotation).
  if (location.hash) {
    const el = document.getElementById(location.hash.slice(1));
    if (el && el.tagName === 'DETAILS') {
      el.open = true;
      el.scrollIntoView({ block: 'center' });
    }
  }
})();

// Baseline page: select all tenants toggle.
document.querySelectorAll('[data-toggle-all]').forEach((box) => {
  box.addEventListener('change', () => {
    document.querySelectorAll(box.dataset.toggleAll).forEach((c) => (c.checked = box.checked));
  });
});

// ---- Microsoft popups (sign-in, admin consent) ----
// Buttons/links with data-ms-popup open Microsoft's account picker in a centred popup window.
// When the flow finishes, the popup posts a message and closes; this window then navigates.
(function () {
  function openPopup(url) {
    const w = 520, h = 680;
    const left = window.screenX + Math.max(0, (window.outerWidth - w) / 2);
    const top = window.screenY + Math.max(0, (window.outerHeight - h) / 2);
    const target = url + (url.includes('?') ? '&' : '?') + 'popup=1';
    const win = window.open(target, 'tg_microsoft', `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`);
    if (!win) {
      window.location.href = url; // popup blocked: use a full-page redirect instead
      return;
    }
    win.focus();
  }

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-ms-popup]');
    if (!el || el.hasAttribute('disabled') || el.getAttribute('aria-disabled') === 'true') return;
    e.preventDefault();
    openPopup(el.dataset.msPopup || el.getAttribute('href'));
  });

  window.addEventListener('message', (e) => {
    if (e.origin !== window.location.origin || !e.data || e.data.type !== 'tg-microsoft-done') return;
    window.location.href = e.data.redirect;
  });
})();

// ---- Settings: automatic app registration (device code sign-in) ----
(function () {
  const box = document.getElementById('ms-auto');
  if (!box) return;
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const startBtn = document.getElementById('ms-auto-start');
  const step = document.getElementById('ms-auto-step');
  const codeEl = document.getElementById('ms-auto-code');
  const openBtn = document.getElementById('ms-auto-open');
  const copyBtn = document.getElementById('ms-auto-copy');
  const msg = document.getElementById('ms-auto-msg');
  let timer = null;

  async function post(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
      body: JSON.stringify(body || {}),
    });
    return res.json().catch(() => ({ status: 'error', message: 'Unexpected response (' + res.status + ')' }));
  }

  function say(text, kind) {
    msg.textContent = text;
    msg.className = 'alert ' + (kind === 'error' ? 'alert-err' : kind === 'ok' ? 'alert-ok' : 'alert-warn');
    msg.hidden = false;
  }

  function poll(interval) {
    timer = setTimeout(async () => {
      const r = await post(box.dataset.poll);
      if (r.status === 'pending') return poll(r.interval || interval);
      if (r.status === 'done') {
        say('Done. App ' + r.client_id + ' was created. Reloading…', 'ok');
        setTimeout(() => location.reload(), 1200);
        return;
      }
      say(r.message || 'Setup failed.', 'error');
      startBtn.disabled = false;
      step.hidden = true;
    }, interval * 1000);
  }

  startBtn.addEventListener('click', async () => {
    clearTimeout(timer);
    startBtn.disabled = true;
    msg.hidden = true;
    const r = await post(box.dataset.start, { include_write: document.getElementById('ms-auto-write').checked, mode: box.dataset.mode || 'create' });
    if (r.status !== 'started') {
      say(r.message || 'Could not start sign-in.', 'error');
      startBtn.disabled = false;
      return;
    }
    codeEl.textContent = r.user_code;
    openBtn.dataset.url = r.verification_uri;
    step.hidden = false;
    say('Waiting for you to sign in with Microsoft…', 'warn');
    poll(r.interval || 5);
  });

  openBtn.addEventListener('click', () => {
    navigator.clipboard && navigator.clipboard.writeText(codeEl.textContent).catch(() => {});
    window.open(openBtn.dataset.url, 'tg_devicelogin', 'width=520,height=680');
  });
  copyBtn.addEventListener('click', () => {
    navigator.clipboard && navigator.clipboard.writeText(codeEl.textContent);
    copyBtn.textContent = 'Copied';
  });
})();

// ---- Baseline grid: per tenant × setting toggles ----
document.querySelectorAll('.matrix-form').forEach((form) => {
  const toggles = () => [...form.querySelectorAll('.cell-toggle:not(:disabled)')];
  const count = form.querySelector('[data-count]');
  const submit = form.querySelector('[data-submit]');

  function refresh() {
    const on = toggles().filter((t) => t.checked);
    toggles().forEach((t) => t.closest('td').classList.toggle('cell-on', t.checked));
    if (!count || !submit) return;
    count.textContent = on.length;
    submit.disabled = on.length === 0;
    submit.textContent = on.length ? `Apply ${on.length} selected` : 'Apply selected';
  }

  // Bulk helpers only switch on "not applied" items; risk-accepted items stay a deliberate, individual choice.
  function setMany(list) {
    const target = !list.every((t) => t.checked);
    list.forEach((t) => (t.checked = target));
    refresh();
  }
  const open = (t) => t.dataset.state === 'open';

  form.addEventListener('change', (e) => e.target.classList.contains('cell-toggle') && refresh());
  form.querySelectorAll('[data-select-row]').forEach((b) =>
    b.addEventListener('click', () => setMany(toggles().filter((t) => t.dataset.row === b.dataset.selectRow && open(t)))));
  form.querySelectorAll('[data-select-col]').forEach((b) =>
    b.addEventListener('click', () => setMany(toggles().filter((t) => t.dataset.col === b.dataset.selectCol && open(t)))));
  form.querySelector('[data-select-open]')?.addEventListener('click', () => setMany(toggles().filter(open)));
  form.querySelector('[data-clear]')?.addEventListener('click', () => {
    toggles().forEach((t) => (t.checked = false));
    refresh();
  });
  refresh();
});

// ---- v2 shell: theme, popovers, people search, alerts ----
(function () {
  const root = document.documentElement;
  const themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('tgTheme', next); } catch (e) {}
    });
  }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let bellLoaded = false;

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-pop]');
    if (trigger) {
      const pop = document.getElementById(trigger.dataset.pop);
      const wasOpen = pop.classList.contains('open');
      document.querySelectorAll('.pop.open').forEach((p) => p.classList.remove('open'));
      if (!wasOpen) {
        pop.classList.add('open');
        const input = pop.querySelector('input');
        if (input) input.focus();
        if (pop.id === 'bellPop' && !bellLoaded) loadBell();
      }
      return;
    }
    if (!e.target.closest('.pop-panel')) document.querySelectorAll('.pop.open').forEach((p) => p.classList.remove('open'));
    if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar, .only-mobile')) document.body.classList.remove('nav-open');
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('.pop.open').forEach((p) => p.classList.remove('open'));
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && document.getElementById('searchPop')) {
      e.preventDefault();
      document.querySelector('[data-pop="searchPop"]').click();
    }
  });

  const input = document.getElementById('searchInput');
  const out = document.getElementById('searchResults');
  let timer;
  if (input && window.TG) {
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { out.innerHTML = '<div class="pop-empty">Type at least two characters.</div>'; return; }
      out.innerHTML = '<div class="pop-empty">Searching all clients…</div>';
      timer = setTimeout(async () => {
        try {
          const res = await fetch(TG.search + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
          const data = await res.json();
          out.innerHTML = data.results.length
            ? data.results.map((r) => `<a class="pop-item" href="${esc(r.url)}"><strong>${esc(r.name)}</strong>${r.disabled ? ' <span class="badge s-na">Disabled</span>' : ''}<small>${esc(r.upn)}${r.title ? ' · ' + esc(r.title) : ''}</small><small>${esc(r.tenant)}</small></a>`).join('')
            : '<div class="pop-empty">Nobody found.</div>';
        } catch (err) {
          out.innerHTML = '<div class="pop-empty">Search failed. Try again.</div>';
        }
      }, 250);
    });
  }

  async function loadBell() {
    const list = document.getElementById('bellList');
    try {
      const res = await fetch(TG.notifications, { headers: { Accept: 'application/json' } });
      const data = await res.json();
      bellLoaded = true;
      list.innerHTML = data.items.length
        ? data.items.map((a) => `<a class="pop-item" href="${esc(a.url)}"><span class="badge ${a.outcome === 'failure' ? 's-fail' : 's-manual'}">${a.outcome === 'failure' ? 'Failed' : 'Warning'}</span> ${esc(a.message)}<small>${esc(a.tenant || 'TenantsGuard')} · ${esc(a.when)}</small></a>`).join('')
        : '<div class="pop-empty">No alerts. All quiet.</div>';
    } catch (err) {
      list.innerHTML = '<div class="pop-empty">Could not load alerts.</div>';
    }
  }
})();
