/* Storefront: configurator, OTP login, checkout, orders. */
(function () {
  'use strict';
  const { authMethod, baleLogin, api, auth, toman, num, esc, toast, fmtDate, debounce, ORDER_STATUS, GATEWAY_LABEL, LEVEL } = window.T;
  const $ = (s, el = document) => el.querySelector(s);

  const CART_KEY = 'terrarium_cart';
  const PRESET_KEY = 'terrarium_preset';
  const state = {
    catalog: null,
    cart: loadCart(),       // { glass: id, plants: {id: qty}, stones: {...}, figures: {...} }
    validation: null,
    validating: false,
    presetId: localStorage.getItem(PRESET_KEY) || null, // ready-made terrarium the cart started from
  };

  function loadCart() {
    try {
      const c = JSON.parse(localStorage.getItem(CART_KEY) || 'null');
      if (c && typeof c === 'object') return { glass: c.glass || null, plants: c.plants || {}, stones: c.stones || {}, figures: c.figures || {} };
    } catch { /* ignore */ }
    return { glass: null, plants: {}, stones: {}, figures: {} };
  }
  function saveCart() {
    localStorage.setItem(CART_KEY, JSON.stringify(state.cart));
    if (state.presetId) localStorage.setItem(PRESET_KEY, state.presetId); else localStorage.removeItem(PRESET_KEY);
  }

  // ------------------------------------------------------------------ presets (ready-made terrariums)

  const cartFromConfig = (cfg) => {
    const map = (arr) => Object.fromEntries((arr || []).filter((x) => x.quantity > 0).map((x) => [x.id, x.quantity]));
    return { glass: cfg.glass_size_id || null, plants: map(cfg.plants), stones: map(cfg.stones), figures: map(cfg.figures) };
  };
  const cartKey = (c) => JSON.stringify([c.glass, ...['plants', 'stones', 'figures'].map((k) => Object.entries(c[k] || {}).filter(([, q]) => q > 0).sort())]);
  const currentPreset = () => (state.catalog && state.presetId ? (state.catalog.presets || []).find((p) => p.id === state.presetId) : null) || null;
  const isUnchangedPreset = () => { const p = currentPreset(); return !!p && cartKey(cartFromConfig(p.configuration)) === cartKey(state.cart); };

  /** price + availability of a preset computed from the live catalog (prices may change after the preset was made) */
  function presetInfo(p) {
    const c = state.catalog;
    const find = (arr, id) => (arr || []).find((x) => x.id === id);
    const glass = find(c.glass_sizes, p.configuration.glass_size_id);
    let total = glass ? glass.price_cents : 0;
    let ok = !!glass && glass.stock_quantity > 0;
    const names = [];
    for (const [k, arr] of [['plants', c.plants], ['stones', c.stones], ['figures', c.figures]]) {
      for (const it of p.configuration[k] || []) {
        const x = find(arr, it.id);
        if (!x || x.stock_quantity < it.quantity) { ok = false; continue; }
        total += x.price_cents * it.quantity;
        if (k === 'plants') names.push(x.name + (it.quantity > 1 ? ' ×' + num(it.quantity) : ''));
      }
    }
    return { glass, total, ok, names };
  }

  function renderPresets() {
    const list = (state.catalog && state.catalog.presets) || [];
    const box = $('#presets');
    if (!box) return;
    $('#presets-section').classList.toggle('hidden', !list.length);
    box.innerHTML = list.map((p) => {
      const info = presetInfo(p);
      const photo = p.image_url
        ? `<img src="${esc(p.image_url)}" alt="${esc(p.name)}" loading="lazy">`
        : window.TerrariumPreview.svg(cartFromConfig(p.configuration), state.catalog);
      return `<article class="preset ${state.presetId === p.id ? 'active' : ''}">
        <div class="ph">${photo}</div>
        <div class="body">
          <div class="row between"><b>${esc(p.name)}</b>${info.glass && info.glass.is_closed_ecosystem ? '<span class="badge info">دربسته</span>' : '<span class="badge">درباز</span>'}</div>
          ${p.description ? `<div class="desc">${esc(p.description)}</div>` : ''}
          <div class="chips">${info.names.map((n) => `<span class="badge">${esc(n)}</span>`).join('')}</div>
          <div class="price">${toman(info.total)}</div>
          ${info.ok ? '' : '<div class="alert warn" style="padding:6px 10px;font-size:.8rem">بعضی اقلام ناموجود است؛ با «شخصی‌سازی» جایگزین کنید.</div>'}
          <div class="actions">
            <button class="btn primary sm" data-preset-buy="${esc(p.id)}" ${info.ok ? '' : 'disabled'}>🛒 خرید همین</button>
            <button class="btn sm" data-preset-edit="${esc(p.id)}">✏️ شخصی‌سازی</button>
          </div>
        </div>
      </article>`;
    }).join('') + `<article class="preset scratch">
        <div class="ph"><svg viewBox="0 0 120 120" width="55%"><rect x="34" y="14" width="52" height="12" rx="4" fill="#c79a63"/><path d="M36 26h48v70a10 10 0 0 1-10 10H46a10 10 0 0 1-10-10z" fill="#ffffff" fill-opacity=".7" stroke="#9fc5bf" stroke-width="3"/><path d="M60 50v28M46 64h28" stroke="#2f8a4a" stroke-width="6" stroke-linecap="round"/></svg></div>
        <div class="body"><b>ساخت از صفر</b><div class="desc">ظرف، گیاه، بستر و تزئینات را خودت انتخاب کن.</div>
          <div class="actions" style="grid-template-columns:1fr"><button class="btn sm" data-scratch>✨ شروع ساخت</button></div></div>
      </article>`;
  }

  function renderBasedOn() {
    const p = currentPreset();
    const box = $('#based-on');
    if (!p) { box.innerHTML = ''; return; }
    const same = isUnchangedPreset();
    box.innerHTML = `<div class="alert info based-on">
      <span>🌿 بر اساس <b>«${esc(p.name)}»</b> ${same ? '— بدون تغییر' : '<span class="badge warn">شخصی‌سازی‌شده</span>'}</span>
      <span class="row gap-sm">${same ? '' : `<button class="btn sm" data-preset-reset="${esc(p.id)}">↩️ بازگشت به حالت اولیه</button>`}<button class="btn ghost sm" data-scratch>شروع از صفر</button></span>
    </div>`;
  }

  async function applyPreset(id, then) {
    const p = (state.catalog.presets || []).find((x) => x.id === id);
    if (!p) return;
    state.presetId = p.id;
    state.cart = cartFromConfig(p.configuration);
    saveCart();
    renderCatalog();
    await validate();
    if (then === 'buy') {
      if (state.validation && state.validation.is_valid) openFinal();
      else { toast('این تراریوم فعلاً قابل سفارش نیست؛ آن را شخصی‌سازی کنید.', 'err'); scrollToBuilder(); }
    } else if (then === 'edit') {
      scrollToBuilder();
      toast(`«${p.name}» بارگذاری شد؛ هر بخشی را خواستی تغییر بده.`);
    }
  }

  function startScratch() {
    state.presetId = null;
    state.cart = { glass: null, plants: {}, stones: {}, figures: {} };
    saveCart();
    renderCatalog();
    validate();
    scrollToBuilder();
  }
  const scrollToBuilder = () => $('#builder-anchor').scrollIntoView({ behavior: 'smooth', block: 'start' });

  function renderLivePreview() {
    if (!state.catalog) return;
    const svg = window.TerrariumPreview.svg(state.cart, state.catalog);
    $('#live-preview').innerHTML = svg;
    const mini = $('#mb-preview'); if (mini) mini.innerHTML = state.cart.glass ? svg : '';
  }

  // ------------------------------------------------------------------ final preview (before checkout)

  // small vector icons (emoji fonts are missing on some systems)
  const ICON = {
    glass: '<svg viewBox="0 0 24 24" width="24" height="24"><rect x="6" y="3" width="12" height="3" rx="1" fill="#c79a63"/><path d="M6.5 6h11v13a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2z" fill="#e3f3f0" stroke="#7fb3ab"/><path d="M7 16h10v3a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 7 19z" fill="#5a3d2b"/></svg>',
    plant: '<svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 21V11" stroke="#2f7d3a" stroke-width="1.6"/><path d="M12 12C7 12 5 8 5 5c4 0 7 2 7 7zM12 14c0-5 3-8 7-8 0 4-2 8-7 8z" fill="#4fa04a"/></svg>',
    stone: '<svg viewBox="0 0 24 24" width="24" height="24"><ellipse cx="9" cy="15" rx="6" ry="4" fill="#5b5e66"/><ellipse cx="16" cy="16" rx="5" ry="3.4" fill="#8a8d94"/><ellipse cx="12" cy="11" rx="4" ry="3" fill="#3b3d42"/></svg>',
    figure: '<svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 3l2.4 5.6 6 .5-4.6 4 1.4 5.9L12 16l-5.2 3 1.4-5.9-4.6-4 6-.5z" fill="#e9c46a"/></svg>',
  };
  function itemThumb(x, kind) {
    return x && x.image_url ? `<img src="${esc(x.image_url)}" alt="" loading="lazy">` : `<span class="ic">${ICON[kind] || ''}</span>`;
  }

  function openFinal() {
    const v = state.validation;
    if (!v || !v.is_valid) return;
    const c = state.catalog;
    const p = currentPreset();
    const same = isUnchangedPreset();
    $('#final-render').innerHTML = window.TerrariumPreview.svg(state.cart, c);
    const showPhoto = same && p && p.image_url;
    $('#final-tabs').classList.toggle('hidden', !showPhoto);
    const setView = (view) => {
      $('#final-render').classList.toggle('hidden', view !== 'render');
      $('#final-photo').classList.toggle('hidden', view !== 'photo');
      document.querySelectorAll('#final-tabs .tab').forEach((t) => t.classList.toggle('active', t.dataset.view === view));
    };
    if (showPhoto) { $('#final-photo').src = p.image_url; $('#final-photo').alt = p.name; setView('photo'); }
    else setView('render');
    const note = $('#final-note');
    note.dataset.render = 'تصویر بالا پیش‌نمایش طراحی‌شده از ترکیب انتخابی شماست؛ چیدمان واقعی دست‌ساز است.';
    note.dataset.photo = showPhoto ? `عکس نمونه «${p.name}». هر تراریوم دست‌ساز است و ممکن است کمی با عکس فرق داشته باشد.` : '';
    note.textContent = showPhoto ? note.dataset.photo : note.dataset.render;
    const find = (arr, id) => (arr || []).find((x) => x.id === id);
    const glass = find(c.glass_sizes, state.cart.glass);
    let rows = `<div class="final-item">${itemThumb(glass, 'glass')}<span class="nm">${esc(glass.name)}</span><span>${toman(glass.price_cents)}</span></div>`;
    for (const [k, arr, emo] of [['plants', c.plants, 'plant'], ['stones', c.stones, 'stone'], ['figures', c.figures, 'figure']]) {
      for (const [id, q] of Object.entries(state.cart[k])) {
        const x = find(arr, id); if (!x || q < 1) continue;
        rows += `<div class="final-item">${itemThumb(x, emo)}<span class="nm">${esc(x.name)} × ${num(q)}</span><span>${toman(x.price_cents * q)}</span></div>`;
      }
    }
    $('#final-title').textContent = p ? (same ? `«${p.name}»` : `«${p.name}» (شخصی‌سازی‌شده)`) : 'نسخه نهایی تراریوم شما';
    $('#final-items').innerHTML = rows;
    $('#final-price').innerHTML = priceLines(v);
    $('#final-modal').classList.remove('hidden');
  }
  const closeFinal = () => $('#final-modal').classList.add('hidden');

  function bindPresets() {
    document.addEventListener('click', (e) => {
      let el;
      if ((el = e.target.closest('[data-preset-buy]'))) applyPreset(el.dataset.presetBuy, 'buy');
      else if ((el = e.target.closest('[data-preset-edit]'))) applyPreset(el.dataset.presetEdit, 'edit');
      else if ((el = e.target.closest('[data-preset-reset]'))) applyPreset(el.dataset.presetReset, null);
      else if (e.target.closest('[data-scratch]')) startScratch();
    });
    $('#final-modal').addEventListener('click', (e) => {
      if (e.target.id === 'final-modal' || e.target.closest('[data-close]')) closeFinal();
      const tab = e.target.closest('#final-tabs .tab');
      if (tab) {
        $('#final-render').classList.toggle('hidden', tab.dataset.view !== 'render');
        $('#final-photo').classList.toggle('hidden', tab.dataset.view !== 'photo');
        document.querySelectorAll('#final-tabs .tab').forEach((t) => t.classList.toggle('active', t === tab));
        $('#final-note').textContent = $('#final-note').dataset[tab.dataset.view] || '';
      }
    });
    $('#btn-final-ok').addEventListener('click', () => {
      closeFinal();
      if (!auth.token) openLogin(() => { location.hash = '#/checkout'; });
      else location.hash = '#/checkout';
    });
  }

  function configurationPayload() {
    const list = (o) => Object.entries(o).filter(([, q]) => q > 0).map(([id, quantity]) => ({ id, quantity }));
    return { glass_size_id: state.cart.glass, plants: list(state.cart.plants), stones: list(state.cart.stones), figures: list(state.cart.figures) };
  }

  // ------------------------------------------------------------------ catalog rendering

  const plantEmoji = (p) => (p.moisture_level === 'low' ? '🌵' : p.moisture_level === 'high' ? '🌿' : '🪴');
  const figureEmoji = ['🏡', '🍄', '🦊', '🐸', '⛩️', '✨'];

  function renderCatalog() {
    const c = state.catalog;
    if (!c) return;
    // drop cart entries that no longer exist
    const ids = (arr) => new Set(arr.map((x) => x.id));
    const g = ids(c.glass_sizes), p = ids(c.plants), s = ids(c.stones), f = ids(c.figures);
    if (state.cart.glass && !g.has(state.cart.glass)) state.cart.glass = null;
    for (const [k, set] of [['plants', p], ['stones', s], ['figures', f]]) {
      for (const id of Object.keys(state.cart[k])) if (!set.has(id)) delete state.cart[k][id];
    }

    $('#opt-glass').innerHTML = c.glass_sizes.length ? c.glass_sizes.map((x) => `
      <div class="option ${state.cart.glass === x.id ? 'selected' : ''} ${x.stock_quantity < 1 ? 'disabled' : ''}" data-glass="${esc(x.id)}" tabindex="0">
        ${x.image_url ? `<img class="thumb" src="${esc(x.image_url)}" alt="" loading="lazy">` : '<div class="emoji">🫙</div>'}
        <div class="name">${esc(x.name)}</div>
        <div class="meta">حجم مفید ${num(x.usable_volume_ml)} میلی‌لیتر · تا ${num(x.max_plant_capacity)} گیاه</div>
        <div class="tags">${x.is_closed_ecosystem ? '<span class="badge info">دربسته (مرطوب)</span>' : '<span class="badge">درباز</span>'}
          ${x.stock_quantity < 1 ? '<span class="badge err">ناموجود</span>' : ''}</div>
        <div class="price">${toman(x.price_cents)}</div>
      </div>`).join('') : '<p class="muted">فعلاً ظرفی موجود نیست.</p>';

    const qtyOption = (kind, x, emoji, meta, tags = '') => {
      const q = state.cart[kind][x.id] || 0;
      const out = x.stock_quantity < 1;
      return `<div class="option ${q ? 'selected' : ''} ${out ? 'disabled' : ''}" data-kind="${kind}" data-id="${esc(x.id)}">
        ${x.image_url ? `<img class="thumb" src="${esc(x.image_url)}" alt="" loading="lazy">` : `<div class="emoji">${emoji}</div>`}
        <div class="name">${esc(x.name)}</div>
        <div class="meta">${meta}</div>
        <div class="tags">${tags}${out ? '<span class="badge err">ناموجود</span>' : ''}</div>
        <div class="price">${toman(x.price_cents)}</div>
        ${q ? `<div class="qty"><button type="button" data-dec aria-label="کم کردن">−</button><span>${num(q)}</span><button type="button" data-inc aria-label="افزودن">+</button></div>` : ''}
      </div>`;
    };

    $('#opt-plants').innerHTML = c.plants.map((x) => qtyOption('plants', x, plantEmoji(x),
      `${x.scientific_name ? '<i class="ltr">' + esc(x.scientific_name) + '</i> · ' : ''}${num(x.volume_occupancy_ml)} ml`,
      `<span class="badge">${LEVEL.light[x.light_level] || ''}</span><span class="badge">${LEVEL.moisture[x.moisture_level] || ''}</span>${x.tolerates_closed_glass ? '' : '<span class="badge warn">فقط ظرف درباز</span>'}`
    )).join('') || '<p class="muted">فعلاً گیاهی موجود نیست.</p>';

    $('#opt-stones').innerHTML = c.stones.map((x) => qtyOption('stones', x, '🪨', `${num(x.volume_per_unit_ml)} ml در هر واحد`)).join('') || '<p class="muted">—</p>';
    $('#opt-figures').innerHTML = c.figures.map((x, i) => qtyOption('figures', x, figureEmoji[i % figureEmoji.length], `${num(x.volume_occupancy_ml)} ml`)).join('') || '<p class="muted">—</p>';
    renderLivePreview();
    renderBasedOn();
    document.querySelectorAll('.preset').forEach((el) => {
      const b = el.querySelector('[data-preset-edit]');
      el.classList.toggle('active', !!b && b.dataset.presetEdit === state.presetId);
    });
  }

  function onOptionClick(e) {
    const opt = e.target.closest('.option');
    if (!opt || opt.classList.contains('disabled')) return;
    if (opt.dataset.glass) {
      state.cart.glass = opt.dataset.glass;
    } else if (opt.dataset.kind) {
      const { kind, id } = opt.dataset;
      const cur = state.cart[kind][id] || 0;
      if (e.target.closest('[data-inc]')) state.cart[kind][id] = Math.min(cur + 1, 50);
      else if (e.target.closest('[data-dec]')) { if (cur <= 1) delete state.cart[kind][id]; else state.cart[kind][id] = cur - 1; }
      else if (!cur) state.cart[kind][id] = 1;
      else return; // clicking a selected card body does nothing; use − to remove
    }
    saveCart();
    renderCatalog();
    scheduleValidate();
  }

  // ------------------------------------------------------------------ live validation

  const scheduleValidate = debounce(validate, 250);

  async function validate() {
    if (!state.cart.glass) { state.validation = null; renderSummary(); return; }
    state.validating = true;
    renderSummary();
    try {
      state.validation = await api('/api/v1/configurator/validate', { method: 'POST', body: configurationPayload(), auth: false });
    } catch (e) {
      state.validation = { error: e.message };
      if (e.status === 422) { await loadCatalog(); }
    }
    state.validating = false;
    renderSummary();
  }

  function priceLines(v) {
    const p = v.price;
    return `
      <div class="summary-line"><span>ظرف</span><span>${toman(p.glass_price_cents)}</span></div>
      ${p.plants_total_cents ? `<div class="summary-line"><span>گیاهان</span><span>${toman(p.plants_total_cents)}</span></div>` : ''}
      ${p.stones_total_cents ? `<div class="summary-line"><span>بستر و سنگ</span><span>${toman(p.stones_total_cents)}</span></div>` : ''}
      ${p.figures_total_cents ? `<div class="summary-line"><span>تزئینات</span><span>${toman(p.figures_total_cents)}</span></div>` : ''}
      <div class="summary-line"><span>هزینه ارسال</span><span>${p.shipping_cents ? toman(p.shipping_cents) : 'رایگان'}</span></div>
      <div class="summary-total"><span>مبلغ قابل پرداخت</span><span>${toman(p.total_cents)}</span></div>`;
  }

  function renderSummary() {
    renderSummaryMain();
    const bar = $('#mobile-bar'); if (!bar) return;
    const v = state.validation, btn = $('#btn-checkout');
    bar.classList.toggle('hidden', !state.cart.glass);
    document.body.classList.toggle('has-mobile-bar', !!state.cart.glass);
    $('#mb-total').textContent = v && v.price ? toman(v.price.total_cents) : '—';
    $('#mb-status').textContent = !v ? 'در حال بررسی…' : v.error ? 'خطا' : v.is_valid ? '✓ سازگار' : '⚠ نیاز به اصلاح';
    $('#mb-status').className = 'mb-status ' + (v && v.is_valid ? 'ok' : 'err');
    $('#mb-go').disabled = btn.disabled;
  }
  function renderSummaryMain() {
    const box = $('#summary');
    const btn = $('#btn-checkout');
    const v = state.validation;
    if (!state.cart.glass) { box.innerHTML = '<p class="muted">ابتدا یک ظرف انتخاب کنید.</p>'; btn.disabled = true; return; }
    if (!v) { box.innerHTML = '<p class="muted">در حال بررسی…</p>'; btn.disabled = true; return; }
    if (v.error) { box.innerHTML = `<div class="alert err">${esc(v.error)}</div>`; btn.disabled = true; return; }

    const pct = v.volume.usable_ml ? Math.min(100, Math.round((v.volume.occupied_ml / v.volume.usable_ml) * 100)) : 0;
    const over = v.volume.occupied_ml > v.volume.usable_ml;
    box.innerHTML = `
      <div class="summary-line"><span>حجم اشغال‌شده</span><span>${num(v.volume.occupied_ml)} / ${num(v.volume.usable_ml)} ml</span></div>
      <div class="meter ${over ? 'over' : ''}"><div style="width:${pct}%"></div></div>
      <div class="summary-line mt"><span>تعداد گیاه</span><span>${num(v.plants.count)} از ${num(v.plants.max)}</span></div>
      ${v.violations.length ? `<div class="mt">${v.violations.map((x) => `<div class="alert err">⚠️ ${esc(x.message)}</div>`).join('')}</div>`
        : '<div class="alert ok mt">✅ ترکیب انتخابی سازگار است.</div>'}
      <div class="mt">${priceLines(v)}</div>`;
    btn.disabled = state.validating || !v.is_valid;
  }

  // ------------------------------------------------------------------ auth UI

  function renderAuthArea() {
    const u = auth.user;
    $('#auth-area').innerHTML = u
      ? `${u.is_admin ? '<a class="btn sm" href="/admin/">پنل مدیریت</a> ' : ''}<button class="btn sm" id="btn-logout" title="خروج">${esc(u.mobile)} · خروج</button>`
      : '<button class="btn primary sm" id="btn-login">ورود</button>';
  }

  let afterLogin = null;
  let resendTimer = null;
  let stopBale = null;
  function finishLogin(res) {
    auth.set(res.token, res.user);
    closeLogin();
    renderAuthArea();
    toast('خوش آمدید!');
    if (afterLogin) { const cb = afterLogin; afterLogin = null; cb(); }
  }
  function startBale() {
    const link = $('#login-bale-link');
    link.removeAttribute('href');
    link.textContent = 'در حال آماده‌سازی…';
    $('#login-bale-wait').classList.add('hidden');
    $('#login-bale-retry').classList.add('hidden');
    $('#login-error').innerHTML = '';
    if (stopBale) stopBale();
    stopBale = baleLogin({
      onLink: (url) => { link.href = url; link.textContent = '🤖 ورود با بله'; },
      onDone: finishLogin,
      onFail: (msg) => {
        $('#login-error').innerHTML = `<div class="alert err">${esc(msg)}</div>`;
        $('#login-bale-wait').classList.add('hidden');
        $('#login-bale-retry').classList.remove('hidden');
      },
    });
  }
  async function openLogin(cb) {
    afterLogin = cb || null;
    if (await authMethod() === 'bale') {
      $('#login-modal').classList.remove('hidden');
      $('#login-bale').classList.remove('hidden');
      $('#login-step-mobile').classList.add('hidden');
      $('#login-step-code').classList.add('hidden');
      startBale();
      return;
    }
    $('#login-modal').classList.remove('hidden');
    $('#login-step-mobile').classList.remove('hidden');
    $('#login-step-code').classList.add('hidden');
    $('#login-error').innerHTML = '';
    $('#dev-code').innerHTML = '';
    setTimeout(() => $('#login-step-mobile [name=mobile]').focus(), 50);
  }
  function closeLogin() { $('#login-modal').classList.add('hidden'); if (stopBale) { stopBale(); stopBale = null; } }

  async function requestCode(mobile) {
    $('#login-error').innerHTML = '';
    const res = await api('/api/v1/auth/otp/request', { method: 'POST', body: { mobile }, auth: false });
    $('#login-mobile').textContent = res.mobile;
    $('#login-step-mobile').classList.add('hidden');
    $('#login-step-code').classList.remove('hidden');
    $('#dev-code').innerHTML = res.code ? `<div class="alert warn">حالت توسعه: کد شما <b class="ltr">${esc(res.code)}</b> است.</div>` : '';
    const codeInput = $('#login-step-code [name=code]');
    codeInput.value = '';
    codeInput.focus();
    startResendCountdown(res.resend_in || 60);
    return res;
  }

  function startResendCountdown(sec) {
    const btn = $('#btn-resend');
    clearInterval(resendTimer);
    let left = sec;
    btn.disabled = true;
    btn.textContent = `ارسال مجدد (${num(left)})`;
    resendTimer = setInterval(() => {
      left -= 1;
      if (left <= 0) { clearInterval(resendTimer); btn.disabled = false; btn.textContent = 'ارسال مجدد'; }
      else btn.textContent = `ارسال مجدد (${num(left)})`;
    }, 1000);
  }

  function bindLogin() {
    $('#login-modal').addEventListener('click', (e) => { if (e.target.id === 'login-modal' || e.target.closest('[data-close]')) closeLogin(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLogin(); });

    $('#login-step-mobile').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('button[type=submit]');
      btn.disabled = true;
      try { await requestCode(e.target.mobile.value); }
      catch (err) { $('#login-error').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
      btn.disabled = false;
    });

    $('#login-step-code').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('button[type=submit]');
      btn.disabled = true;
      try {
        const res = await api('/api/v1/auth/otp/verify', { method: 'POST', body: { mobile: $('#login-mobile').textContent, code: e.target.code.value }, auth: false });
        auth.set(res.token, res.user);
        closeLogin();
        renderAuthArea();
        toast('خوش آمدید!');
        if (afterLogin) { const cb = afterLogin; afterLogin = null; cb(); }
      } catch (err) {
        $('#login-error').innerHTML = `<div class="alert err">${esc(err.message)}</div>`;
      }
      btn.disabled = false;
    });

    $('#login-bale-link').addEventListener('click', () => { $('#login-bale-wait').classList.remove('hidden'); });
    $('#login-bale-retry').addEventListener('click', startBale);
    $('#btn-change-mobile').addEventListener('click', () => openLogin(afterLogin));
    $('#btn-resend').addEventListener('click', async () => {
      try { await requestCode($('#login-mobile').textContent); toast('کد جدید ارسال شد.'); }
      catch (err) { $('#login-error').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });

    document.addEventListener('click', async (e) => {
      if (e.target.closest('#btn-login')) openLogin();
      if (e.target.closest('#btn-logout')) {
        try { await api('/api/v1/auth/logout', { method: 'POST', body: {} }); } catch { /* ignore */ }
        auth.clear();
        renderAuthArea();
        toast('از حساب خارج شدید.');
        if (location.hash.startsWith('#/orders') || location.hash === '#/checkout') location.hash = '#/';
      }
    });
    window.addEventListener('auth:expired', () => { renderAuthArea(); toast('نشست شما منقضی شد. دوباره وارد شوید.', 'err'); });
  }

  // ------------------------------------------------------------------ checkout

  function renderCheckout() {
    const v = state.validation;
    if (!state.cart.glass || !v || !v.is_valid) { location.hash = '#/'; return; }
    if (!auth.token) { location.hash = '#/'; openLogin(() => { location.hash = '#/checkout'; }); return; }

    $('#checkout-summary').innerHTML = priceLines(v);
    $('#checkout-preview').innerHTML = window.TerrariumPreview.svg(state.cart, state.catalog);
    const gws = (state.catalog && state.catalog.payment_gateways) || [];
    $('#gateway-select').innerHTML = gws.length
      ? gws.map((g) => `<option value="${esc(g)}">${esc(GATEWAY_LABEL[g] || g)}</option>`).join('')
      : '<option value="">درگاهی فعال نیست</option>';
    $('#btn-pay').disabled = !gws.length;
    const f = $('#checkout-form');
    if (!f.recipient_phone.value && auth.user) f.recipient_phone.value = auth.user.mobile;
    if (!gws.length) $('#checkout-error').innerHTML = '<div class="alert warn">در حال حاضر امکان پرداخت آنلاین وجود ندارد. لطفاً بعداً تلاش کنید.</div>';
  }

  function bindCheckout() {
    $('#btn-checkout').addEventListener('click', openFinal);
    $('#mb-go').addEventListener('click', openFinal);
    $('#mb-preview').addEventListener('click', () => $('#summary').scrollIntoView({ behavior: 'smooth', block: 'center' }));

    $('#checkout-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const btn = $('#btn-pay');
      $('#checkout-error').innerHTML = '';
      btn.disabled = true;
      btn.textContent = 'در حال انتقال به درگاه…';
      try {
        const res = await api('/api/v1/orders', {
          method: 'POST',
          body: {
            configuration: configurationPayload(),
            recipient_name: f.recipient_name.value,
            recipient_phone: f.recipient_phone.value,
            shipping_address: f.shipping_address.value,
            postal_code: f.postal_code.value,
            customer_note: f.customer_note.value,
            gateway: f.gateway.value,
          },
        });
        localStorage.removeItem(CART_KEY);
        localStorage.removeItem(PRESET_KEY);
        if (isBaleLink(res.payment_url)) {
          // Bale: the bot also messaged the customer; show the order page with a big "pay in Bale" button
          sessionStorage.setItem('bale_pay_' + res.order_id, res.payment_url);
          location.hash = '#/orders/' + res.order_id;
          btn.disabled = false;
          return;
        }
        window.location.href = res.payment_url;
        return;
      } catch (err) {
        let html = `<div class="alert err">${esc(err.message)}</div>`;
        if (err.details && Array.isArray(err.details.violations)) {
          html += err.details.violations.map((v) => `<div class="alert err">⚠️ ${esc(v.message)}</div>`).join('');
        }
        if (err.code === 'payment_gateway_unavailable' && err.details.order_id) {
          localStorage.removeItem(CART_KEY);
          html += `<a class="btn" href="#/orders/${esc(err.details.order_id)}">مشاهده سفارش و پرداخت مجدد</a>`;
        }
        if (err.status === 401) openLogin(() => f.requestSubmit());
        $('#checkout-error').innerHTML = html;
      }
      btn.disabled = false;
      btn.textContent = 'پرداخت و ثبت نهایی';
    });
  }

  // ------------------------------------------------------------------ orders

  const statusPill = (s) => `<span class="badge status-pill" data-status="${esc(s)}">${esc(ORDER_STATUS[s] || s)}</span>`;

  async function renderOrders() {
    const box = $('#orders-list');
    if (!auth.token) {
      box.innerHTML = '<div class="card"><p>برای مشاهده سفارش‌ها وارد شوید.</p><button class="btn primary" id="orders-login">ورود</button></div>';
      $('#orders-login').onclick = () => openLogin(() => renderOrders());
      return;
    }
    box.innerHTML = '<p class="muted">در حال بارگذاری…</p>';
    try {
      const orders = await api('/api/v1/orders');
      box.innerHTML = orders.length ? orders.map((o) => `
        <a class="card row between wrap gap" href="#/orders/${esc(o.id)}" style="color:inherit">
          <div><b class="ltr">${esc(o.order_number)}</b><div class="muted">${fmtDate(o.created_at)}</div></div>
          <div class="row gap">${statusPill(o.status)}<b>${toman(o.total_price_cents)}</b></div>
        </a>`).join('') : '<div class="card"><p>هنوز سفارشی ثبت نکرده‌اید.</p><a class="btn primary" href="#/">ساخت اولین تراریوم</a></div>';
    } catch (e) {
      box.innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    }
  }

  const isBaleLink = (u) => typeof u === 'string' && /^https:\/\/ble\.ir\//.test(u);

  function baleBox(url) {
    return `<div class="alert info stack" style="text-align:center">
      <b>💳 پرداخت با کیف پول بله</b>
      <span>پیام پرداخت در «بله» برایتان ارسال شد. می‌توانید از همان پیام یا دکمه زیر پرداخت کنید.</span>
      <a class="btn primary block" href="${esc(url)}" target="_blank" rel="noopener">باز کردن ربات بله و پرداخت</a>
      <button class="btn sm" onclick="location.reload()">پرداخت کردم، بررسی وضعیت</button>
    </div>`;
  }

  /** Draws the ordered terrarium from the order's frozen snapshot (works even if items were later removed). */
  function snapshotPreview(snap) {
    const g = snap.glass_size;
    const cat = {
      glass_sizes: [{ id: g.id, name: g.name, code: g.code, is_closed_ecosystem: !!g.is_closed }],
      plants: (snap.plants || []).map((x) => ({ id: x.id, name: x.name })),
      stones: (snap.stones || []).map((x) => ({ id: x.id, name: x.name, type: x.type })),
      figures: (snap.figures || []).map((x) => ({ id: x.id, name: x.name })),
    };
    const map = (arr) => Object.fromEntries((arr || []).map((x) => [x.id, x.quantity]));
    return window.TerrariumPreview.svg({ glass: g.id, plants: map(snap.plants), stones: map(snap.stones), figures: map(snap.figures) }, cat);
  }

  async function renderOrder(id) {
    const box = $('#order-detail');
    if (!auth.token) { openLogin(() => renderOrder(id)); box.innerHTML = ''; return; }
    box.innerHTML = '<p class="muted">در حال بارگذاری…</p>';
    try {
      const o = await api('/api/v1/orders/' + encodeURIComponent(id));
      $('#order-title').innerHTML = `سفارش <span class="ltr">${esc(o.order_number)}</span>`;
      const item = (o.items[0] || {}).snapshot || {};
      const lines = (arr) => (arr || []).map((l) => `<div class="summary-line"><span>${esc(l.name)} × ${num(l.quantity)}</span><span>${toman(l.total_cents)}</span></div>`).join('');
      const gws = (state.catalog && state.catalog.payment_gateways) || [];
      box.innerHTML = `
        <div class="layout">
          <div class="card">
            ${item.glass_size ? `<div class="preview-box sm mb-lg">${snapshotPreview(item)}</div>` : ''}
            <h3>اقلام</h3>
            ${item.glass_size ? `<div class="summary-line"><span>🫙 ${esc(item.glass_size.name)}</span><span>${toman(item.glass_size.price_cents)}</span></div>` : ''}
            ${lines(item.plants)}${lines(item.stones)}${lines(item.figures)}
            <div class="summary-line"><span>هزینه ارسال</span><span>${o.shipping_cents ? toman(o.shipping_cents) : 'رایگان'}</span></div>
            <div class="summary-total"><span>جمع کل</span><span>${toman(o.total_price_cents)}</span></div>
            <h3 class="mt-lg">اطلاعات ارسال</h3>
            <p>${esc(o.recipient_name)} — <span class="ltr">${esc(o.recipient_phone)}</span><br>${esc(o.shipping_address)}${o.postal_code ? '<br>کد پستی: ' + esc(o.postal_code) : ''}</p>
          </div>
          <aside class="card stack">
            <div class="row between"><span>وضعیت</span>${statusPill(o.status)}</div>
            <div class="row between"><span>تاریخ ثبت</span><span>${fmtDate(o.created_at)}</span></div>
            ${o.paid_at ? `<div class="row between"><span>تاریخ پرداخت</span><span>${fmtDate(o.paid_at)}</span></div>` : ''}
            ${o.payments.filter((p) => p.status === 'success').map((p) => `<div class="row between"><span>کد پیگیری</span><b class="ltr">${esc(p.reference_id)}</b></div>`).join('')}
            ${o.status === 'pending_payment' && sessionStorage.getItem('bale_pay_' + o.id) ? baleBox(sessionStorage.getItem('bale_pay_' + o.id)) : ''}
            ${o.status === 'pending_payment' && gws.length ? `
              <label class="field"><span>درگاه</span><select id="repay-gw">${gws.map((g) => `<option value="${esc(g)}" ${g === o.payment_gateway ? 'selected' : ''}>${esc(GATEWAY_LABEL[g] || g)}</option>`).join('')}</select></label>
              <button class="btn primary block" id="btn-repay">پرداخت سفارش</button>` : ''}
          </aside>
        </div>`;
      const repay = $('#btn-repay');
      if (repay) {
        repay.onclick = async () => {
          repay.disabled = true;
          try {
            const r = await api(`/api/v1/orders/${encodeURIComponent(o.id)}/pay`, { method: 'POST', body: { gateway: $('#repay-gw').value } });
            if (isBaleLink(r.payment_url)) {
              sessionStorage.setItem('bale_pay_' + o.id, r.payment_url);
              renderOrder(o.id);
              return;
            }
            window.location.href = r.payment_url;
          } catch (e) { toast(e.message, 'err'); repay.disabled = false; }
        };
      }
    } catch (e) {
      box.innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    }
  }

  // ------------------------------------------------------------------ payment result banner

  function showPaymentResult() {
    const q = new URLSearchParams(location.search);
    const status = q.get('payment');
    if (!status) return;
    const order = q.get('order');
    const ref = q.get('ref');
    const msg = q.get('msg') || '';
    const cls = status === 'success' ? 'ok' : status === 'failed' ? 'warn' : 'err';
    $('#result-banner').innerHTML = `
      <div class="alert ${cls}">
        <b>${status === 'success' ? '🎉 پرداخت موفق' : status === 'failed' ? 'پرداخت انجام نشد' : 'خطا در پرداخت'}</b>
        <div>${esc(msg)}</div>
        ${order ? `<div>شماره سفارش: <b class="ltr">${esc(order)}</b>${ref ? ` — کد پیگیری: <b class="ltr">${esc(ref)}</b>` : ''}</div>` : ''}
        <div class="mt"><a class="btn sm" href="#/orders">مشاهده سفارش‌ها</a></div>
      </div>`;
    history.replaceState(null, '', location.pathname + (location.hash && location.hash !== '#result' ? location.hash : '#/orders'));
  }

  // ------------------------------------------------------------------ router

  function route() {
    const h = location.hash || '#/';
    const views = ['builder', 'checkout', 'orders', 'order'];
    const show = (v) => views.forEach((x) => $('#view-' + x).classList.toggle('hidden', x !== v));
    let m;
    if (h === '#/checkout') { show('checkout'); renderCheckout(); }
    else if ((m = h.match(/^#\/orders\/([\w-]+)$/))) { show('order'); renderOrder(m[1]); }
    else if (h.startsWith('#/orders')) { show('orders'); renderOrders(); }
    else { show('builder'); }
    window.scrollTo({ top: 0 });
  }

  async function loadCatalog() {
    try {
      state.catalog = await api('/api/v1/catalog', { auth: false });
      if (state.presetId && !(state.catalog.presets || []).some((p) => p.id === state.presetId)) state.presetId = null;
      renderPresets();
      renderCatalog();
    } catch (e) {
      $('#opt-glass').innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    }
  }

  async function init() {
    showPaymentResult();
    renderAuthArea();
    bindLogin();
    bindCheckout();
    bindPresets();
    document.querySelector('#view-builder').addEventListener('click', onOptionClick);
    document.querySelector('#view-builder').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.classList.contains('option')) onOptionClick(e); });
    window.addEventListener('hashchange', route);
    await loadCatalog();
    await validate();
    route();
    if (auth.token) {
      api('/api/v1/auth/me').then((u) => { auth.setUser(u); renderAuthArea(); }).catch(() => {});
    }
  }

  init();
})();
