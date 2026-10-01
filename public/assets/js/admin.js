/* Admin panel — talks to /api/v1/admin/* with the admin's bearer token. */
(function () {
  'use strict';
  const { api, auth, toman, rial, num, esc, toast, fmtDate, ORDER_STATUS, GATEWAY_LABEL, LEVEL } = window.T;
  const $ = (s, el = document) => el.querySelector(s);
  const content = () => $('#content');

  const TYPE_LABEL = { glass_size: 'ظرف شیشه‌ای', plant: 'گیاه', stone: 'بستر و سنگ', figure: 'فیگور' };
  let currentTab = 'dashboard';
  let catalogType = 'glass_size';
  let catalogCache = null;

  // ------------------------------------------------------------------ auth

  function showLogin() {
    $('#login-view').classList.remove('hidden');
    $('#app-view').classList.add('hidden');
    $('#admin-user').innerHTML = '';
  }

  function showApp() {
    $('#login-view').classList.add('hidden');
    $('#app-view').classList.remove('hidden');
    $('#admin-user').innerHTML = `<button class="btn sm" id="btn-logout">${esc(auth.user.mobile)} · خروج</button>`;
    openTab(currentTab);
  }

  function bindLogin() {
    let mobile = '';
    $('#lg-mobile').addEventListener('submit', async (e) => {
      e.preventDefault();
      $('#lg-error').innerHTML = '';
      try {
        const r = await api('/api/v1/auth/otp/request', { method: 'POST', body: { mobile: e.target.mobile.value }, auth: false });
        mobile = r.mobile;
        $('#lg-mobile').classList.add('hidden');
        $('#lg-code').classList.remove('hidden');
        $('#lg-dev').innerHTML = r.code ? `<div class="alert warn">حالت توسعه: کد <b class="ltr">${esc(r.code)}</b></div>` : '';
        $('#lg-code [name=code]').focus();
      } catch (err) { $('#lg-error').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });
    $('#lg-code').addEventListener('submit', async (e) => {
      e.preventDefault();
      $('#lg-error').innerHTML = '';
      try {
        const r = await api('/api/v1/auth/otp/verify', { method: 'POST', body: { mobile, code: e.target.code.value }, auth: false });
        if (!r.user.is_admin) {
          $('#lg-error').innerHTML = '<div class="alert err">این شماره دسترسی مدیریت ندارد.</div>';
          return;
        }
        auth.set(r.token, r.user);
        showApp();
      } catch (err) { $('#lg-error').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });
    document.addEventListener('click', async (e) => {
      if (e.target.closest('#btn-logout')) {
        try { await api('/api/v1/auth/logout', { method: 'POST', body: {} }); } catch { /* ignore */ }
        auth.clear();
        location.reload();
      }
    });
    window.addEventListener('auth:expired', showLogin);
  }

  // ------------------------------------------------------------------ modal

  function modal(html, onMount) {
    const root = $('#modal-root');
    root.innerHTML = `<div class="modal-backdrop"><div class="modal">${html}</div></div>`;
    const close = () => { root.innerHTML = ''; };
    root.querySelector('.modal-backdrop').addEventListener('click', (e) => { if (e.target.classList.contains('modal-backdrop') || e.target.closest('[data-close]')) close(); });
    if (onMount) onMount(root.querySelector('.modal'), close);
    return close;
  }

  // ------------------------------------------------------------------ tabs

  function openTab(tab) {
    currentTab = tab;
    document.querySelectorAll('#nav button').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    content().innerHTML = '<p class="muted">در حال بارگذاری…</p>';
    ({ dashboard, orders, catalog, rules, system }[tab])().catch((e) => {
      content().innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
    });
  }

  // ---- dashboard
  async function dashboard() {
    const d = await api('/api/v1/admin/dashboard');
    const s = d.orders_by_status || {};
    content().innerHTML = `
      <h2>داشبورد</h2>
      <div class="grid grid-4">
        <div class="card stat"><div class="label">درآمد (سفارش‌های پرداخت‌شده)</div><div class="value">${toman(d.revenue_cents)}</div></div>
        <div class="card stat"><div class="label">در انتظار آماده‌سازی</div><div class="value">${num(s.paid || 0)}</div></div>
        <div class="card stat"><div class="label">در حال آماده‌سازی</div><div class="value">${num(s.processing || 0)}</div></div>
        <div class="card stat"><div class="label">کاربران</div><div class="value">${num(d.users)}</div></div>
      </div>
      <div class="grid grid-2 mt">
        <div class="card">
          <h3>سفارش‌ها بر اساس وضعیت</h3>
          ${Object.keys(ORDER_STATUS).map((k) => `<div class="summary-line"><span>${ORDER_STATUS[k]}</span><b>${num(s[k] || 0)}</b></div>`).join('')}
        </div>
        <div class="card">
          <h3>موجودی رو به اتمام (≤ ۵)</h3>
          ${d.low_stock.length ? d.low_stock.map((x) => `<div class="summary-line"><span>${esc(TYPE_LABEL[x.type])}: ${esc(x.name)}</span><span class="badge ${x.stock < 1 ? 'err' : 'warn'}">${num(x.stock)}</span></div>`).join('') : '<p class="muted">همه اقلام موجودی کافی دارند.</p>'}
        </div>
      </div>`;
  }

  // ---- orders
  let orderFilter = { status: 'paid', q: '' };
  async function orders() {
    const qs = new URLSearchParams();
    if (orderFilter.status) qs.set('status', orderFilter.status);
    if (orderFilter.q) qs.set('q', orderFilter.q);
    const list = await api('/api/v1/admin/orders?' + qs);
    content().innerHTML = `
      <div class="row between wrap gap mb"><h2 style="margin:0">سفارش‌ها</h2>
        <form id="order-search" class="row gap-sm"><input type="search" name="q" placeholder="شماره سفارش، موبایل یا نام" value="${esc(orderFilter.q)}"><button class="btn sm">جستجو</button></form>
      </div>
      <div class="tabs">${[['', 'همه'], ...Object.entries(ORDER_STATUS).filter(([k]) => k !== 'draft')].map(([k, v]) => `<button data-status="${k}" class="${orderFilter.status === k ? 'active' : ''}">${v}</button>`).join('')}</div>
      <div class="card"><div class="table-wrap"><table class="table">
        <thead><tr><th>شماره</th><th>مشتری</th><th>گیرنده</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
        <tbody>${list.length ? list.map((o) => `<tr>
          <td class="mono">${esc(o.order_number)}</td>
          <td class="ltr">${esc(o.user_mobile)}</td>
          <td>${esc(o.recipient_name)}</td>
          <td>${toman(o.total_price_cents)}</td>
          <td><span class="badge status-pill" data-status="${esc(o.status)}">${esc(ORDER_STATUS[o.status] || o.status)}</span></td>
          <td>${fmtDate(o.created_at)}</td>
          <td><button class="btn sm" data-order="${esc(o.id)}">جزئیات</button></td>
        </tr>`).join('') : '<tr><td colspan="7" class="muted">سفارشی یافت نشد.</td></tr>'}</tbody>
      </table></div></div>`;

    content().querySelectorAll('.tabs button').forEach((b) => b.onclick = () => { orderFilter.status = b.dataset.status; orders(); });
    $('#order-search').onsubmit = (e) => { e.preventDefault(); orderFilter.q = e.target.q.value.trim(); orders(); };
    content().querySelectorAll('[data-order]').forEach((b) => b.onclick = () => orderDetail(b.dataset.order));
  }

  async function orderDetail(id) {
    const o = await api('/api/v1/admin/orders/' + encodeURIComponent(id));
    const snap = (o.items[0] || {}).snapshot_data || {};
    const lines = (arr) => (arr || []).map((l) => `<div class="summary-line"><span>${esc(l.name)} × ${num(l.quantity)}</span><span>${toman(l.total_cents)}</span></div>`).join('');
    modal(`
      <div class="row between"><h2>سفارش <span class="ltr">${esc(o.order_number)}</span></h2><button class="btn ghost sm" data-close>✕</button></div>
      <div class="row gap wrap mb"><span class="badge status-pill" data-status="${esc(o.status)}">${esc(ORDER_STATUS[o.status])}</span><span class="muted">${fmtDate(o.created_at)}</span></div>
      <h3>اقلام (اسنپ‌شات قیمت زمان خرید)</h3>
      ${snap.glass_size ? `<div class="summary-line"><span>🫙 ${esc(snap.glass_size.name)}</span><span>${toman(snap.glass_size.price_cents)}</span></div>` : ''}
      ${lines(snap.plants)}${lines(snap.stones)}${lines(snap.figures)}
      <div class="summary-line"><span>ارسال</span><span>${toman(o.shipping_cents)}</span></div>
      <div class="summary-total"><span>جمع</span><span>${toman(o.total_price_cents)}</span></div>
      <h3 class="mt">ارسال</h3>
      <p>${esc(o.recipient_name)} — <span class="ltr">${esc(o.recipient_phone)}</span> (حساب: <span class="ltr">${esc(o.user_mobile || '')}</span>)<br>${esc(o.shipping_address)}${o.postal_code ? '<br>کد پستی: ' + esc(o.postal_code) : ''}${o.customer_note ? '<br><b>توضیحات:</b> ' + esc(o.customer_note) : ''}</p>
      <h3>تراکنش‌ها</h3>
      ${o.payments.length ? o.payments.map((p) => `<div class="summary-line"><span>${esc(GATEWAY_LABEL[p.gateway] || p.gateway)} · ${fmtDate(p.created_at)}</span><span><span class="badge ${p.status === 'success' ? 'ok' : p.status === 'failed' ? 'err' : 'warn'}">${esc(p.status)}</span> ${p.reference_id ? '<span class="mono">' + esc(p.reference_id) + '</span>' : ''}</span></div>`).join('') : '<p class="muted">—</p>'}
      ${o.allowed_transitions.length ? `<h3 class="mt">تغییر وضعیت</h3><div class="row gap wrap">${o.allowed_transitions.map((s) => `<button class="btn ${s === 'cancelled' ? 'danger' : 'primary'} sm" data-to="${s}">${esc(ORDER_STATUS[s])}</button>`).join('')}</div>` : ''}
    `, (el, close) => {
      el.querySelectorAll('[data-to]').forEach((b) => b.onclick = async () => {
        if (b.dataset.to === 'cancelled' && !confirm('سفارش لغو شود؟ در صورت پرداخت، بازگشت وجه باید دستی انجام شود.')) return;
        try {
          await api(`/api/v1/admin/orders/${encodeURIComponent(id)}/status`, { method: 'POST', body: { status: b.dataset.to } });
          toast('وضعیت سفارش به‌روزرسانی شد.');
          close();
          orders();
        } catch (e) { toast(e.message, 'err'); }
      });
    });
  }

  // ---- catalog
  async function catalog() {
    catalogCache = await api('/api/v1/admin/catalog');
    renderCatalog();
  }

  function renderCatalog() {
    const rows = catalogCache[catalogType] || [];
    const extra = {
      glass_size: (x) => `${num(x.usable_volume_ml)} ml · ${num(x.max_plant_capacity)} گیاه · ${x.is_closed_ecosystem ? 'دربسته' : 'درباز'}`,
      plant: (x) => `${num(x.volume_occupancy_ml)} ml · ${LEVEL.light[x.light_level]} · ${LEVEL.moisture[x.moisture_level]}${x.tolerates_closed_glass ? '' : ' · فقط درباز'}`,
      stone: (x) => `${num(x.volume_per_unit_ml)} ml/واحد`,
      figure: (x) => `${num(x.volume_occupancy_ml)} ml`,
    }[catalogType];
    content().innerHTML = `
      <div class="row between wrap gap mb"><h2 style="margin:0">قیمت و موجودی</h2><button class="btn primary sm" id="btn-new-item">+ افزودن ${TYPE_LABEL[catalogType]}</button></div>
      <div class="tabs">${Object.entries(TYPE_LABEL).map(([k, v]) => `<button data-type="${k}" class="${k === catalogType ? 'active' : ''}">${v}</button>`).join('')}</div>
      <div class="card"><p class="muted">قیمت‌ها به <b>ریال</b> وارد می‌شوند. تغییرات فوراً روی فروشگاه اعمال می‌شود (سفارش‌های قبلی تغییر نمی‌کنند).</p>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>عنوان</th><th>مشخصات</th><th>قیمت (ریال)</th><th>موجودی</th><th>فعال</th><th></th></tr></thead>
        <tbody>${rows.map((x) => `<tr data-id="${esc(x.id)}">
          <td><b>${esc(x.name)}</b>${x.code ? `<div class="mono muted">${esc(x.code)}</div>` : ''}</td>
          <td class="muted">${extra(x)}</td>
          <td><input type="number" min="0" step="1000" name="price_cents" value="${x.price_cents}"><div class="muted" style="font-size:.75rem">${toman(x.price_cents)}</div></td>
          <td><input type="number" min="0" step="1" name="stock_quantity" value="${x.stock_quantity}" style="width:90px"></td>
          <td><input type="checkbox" name="is_active" ${x.is_active ? 'checked' : ''}></td>
          <td><button class="btn sm primary" data-save>ذخیره</button></td>
        </tr>`).join('') || '<tr><td colspan="6" class="muted">موردی ثبت نشده است.</td></tr>'}</tbody>
      </table></div></div>`;

    content().querySelectorAll('.tabs button').forEach((b) => b.onclick = () => { catalogType = b.dataset.type; renderCatalog(); });
    content().querySelectorAll('[data-save]').forEach((b) => b.onclick = async () => {
      const tr = b.closest('tr');
      b.disabled = true;
      try {
        const updated = await api(`/api/v1/admin/catalog/${catalogType}/${encodeURIComponent(tr.dataset.id)}`, {
          method: 'POST',
          body: {
            price_cents: Number(tr.querySelector('[name=price_cents]').value),
            stock_quantity: Number(tr.querySelector('[name=stock_quantity]').value),
            is_active: tr.querySelector('[name=is_active]').checked,
          },
        });
        const list = catalogCache[catalogType];
        list[list.findIndex((x) => x.id === updated.id)] = updated;
        toast('ذخیره شد.');
        renderCatalog();
      } catch (e) { toast(e.message, 'err'); b.disabled = false; }
    });
    $('#btn-new-item').onclick = newItemModal;
  }

  function newItemModal() {
    const common = `
      <label class="field"><span>نام *</span><input type="text" name="name" required maxlength="150"></label>
      <div class="grid grid-2">
        <label class="field"><span>قیمت (ریال) *</span><input type="number" name="price_cents" min="0" step="1000" required></label>
        <label class="field"><span>موجودی</span><input type="number" name="stock_quantity" min="0" value="0"></label>
      </div>`;
    const fields = {
      glass_size: `
        <label class="field"><span>کد یکتا *</span><input type="text" name="code" required maxlength="50" class="ltr" style="width:100%"></label>
        <div class="grid grid-2">
          <label class="field"><span>حجم کل (ml) *</span><input type="number" name="total_volume_ml" min="1" required></label>
          <label class="field"><span>حجم مفید (ml) *</span><input type="number" name="usable_volume_ml" min="1" required></label>
          <label class="field"><span>حداکثر تعداد گیاه *</span><input type="number" name="max_plant_capacity" min="1" required></label>
          <label class="checkbox mt"><input type="checkbox" name="is_closed_ecosystem"> دربسته (اکوسیستم بسته)</label>
        </div>`,
      plant: `
        <label class="field"><span>نام علمی</span><input type="text" name="scientific_name" maxlength="150" class="ltr" style="width:100%"></label>
        <div class="grid grid-2">
          <label class="field"><span>حجم اشغالی (ml) *</span><input type="number" name="volume_occupancy_ml" min="1" required></label>
          <label class="field"><span>نیاز نوری *</span><select name="light_level"><option value="low">کم</option><option value="medium" selected>متوسط</option><option value="bright">زیاد</option></select></label>
          <label class="field"><span>نیاز رطوبتی *</span><select name="moisture_level"><option value="low">کم (ساکولنت/کاکتوس)</option><option value="medium" selected>متوسط</option><option value="high">زیاد (سرخس/خزه)</option></select></label>
          <label class="checkbox mt"><input type="checkbox" name="tolerates_closed_glass" checked> سازگار با ظرف دربسته</label>
        </div>`,
      stone: `
        <div class="grid grid-2">
          <label class="field"><span>نوع *</span><select name="type"><option value="drainage">زهکشی</option><option value="decorative">تزئینی</option><option value="substrate">بستر</option></select></label>
          <label class="field"><span>حجم هر واحد (ml) *</span><input type="number" name="volume_per_unit_ml" min="1" required></label>
        </div>`,
      figure: `<label class="field"><span>حجم اشغالی (ml) *</span><input type="number" name="volume_occupancy_ml" min="1" required></label>`,
    }[catalogType];

    modal(`
      <div class="row between"><h2>افزودن ${TYPE_LABEL[catalogType]}</h2><button class="btn ghost sm" data-close>✕</button></div>
      <form class="stack" id="new-item-form">${common}${fields}<div id="ni-err"></div><button class="btn primary">ثبت</button></form>
    `, (el, close) => {
      el.querySelector('form').onsubmit = async (e) => {
        e.preventDefault();
        const data = {};
        for (const input of e.target.elements) {
          if (!input.name) continue;
          if (input.type === 'checkbox') data[input.name] = input.checked;
          else if (input.type === 'number') { if (input.value !== '') data[input.name] = Number(input.value); }
          else data[input.name] = input.value;
        }
        try {
          await api(`/api/v1/admin/catalog/${catalogType}`, { method: 'POST', body: data });
          toast('ثبت شد.');
          close();
          catalog();
        } catch (err) { el.querySelector('#ni-err').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
      };
    });
  }

  // ---- rules
  async function rules() {
    const [list, cat] = await Promise.all([api('/api/v1/admin/rules'), catalogCache ? Promise.resolve(catalogCache) : api('/api/v1/admin/catalog')]);
    catalogCache = cat;
    const nameOf = (type, id) => {
      if (!id) return type === 'glass_size' ? 'همه ظرف‌ها' : '—';
      const x = (cat[type] || []).find((r) => r.id === id);
      return x ? x.name : '(حذف‌شده)';
    };
    content().innerHTML = `
      <div class="row between wrap gap mb"><h2 style="margin:0">قوانین سازگاری</h2><button class="btn primary sm" id="btn-new-rule">+ قانون جدید</button></div>
      <div class="alert info">قوانین داخلی همیشه فعال‌اند: ظرفیت گیاه، حجم مفید، سازگاری با ظرف دربسته و تضاد رطوبتی. قوانین زیر قوانین اضافه‌ای هستند که شما تعریف می‌کنید.</div>
      <div class="stack">${list.length ? list.map((r) => `
        <div class="card flat row between wrap gap">
          <div class="grow">
            <b>${r.target_type === 'plant' ? '🌿 گیاه ↔ گیاه' : '🫙 گیاه ↔ ظرف'}</b>:
            ${esc(nameOf('plant', r.source_id))} ✕ ${esc(nameOf(r.target_type, r.target_id))}
            <div class="muted">پیام به مشتری: ${esc(r.reason_message)}</div>
          </div>
          <div class="row gap-sm">
            <span class="badge ${r.is_active ? 'ok' : ''}">${r.is_active ? 'فعال' : 'غیرفعال'}</span>
            <button class="btn sm" data-toggle="${esc(r.id)}" data-active="${r.is_active ? 0 : 1}">${r.is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی'}</button>
            <button class="btn sm danger" data-del="${esc(r.id)}">حذف</button>
          </div>
        </div>`).join('') : '<div class="card"><p class="muted">هنوز قانون سفارشی تعریف نشده است.</p></div>'}</div>`;

    content().querySelectorAll('[data-toggle]').forEach((b) => b.onclick = async () => {
      try { await api(`/api/v1/admin/rules/${b.dataset.toggle}/toggle`, { method: 'POST', body: { active: b.dataset.active === '1' } }); rules(); }
      catch (e) { toast(e.message, 'err'); }
    });
    content().querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => {
      if (!confirm('این قانون حذف شود؟')) return;
      try { await api(`/api/v1/admin/rules/${b.dataset.del}/delete`, { method: 'POST', body: {} }); toast('حذف شد.'); rules(); }
      catch (e) { toast(e.message, 'err'); }
    });
    $('#btn-new-rule').onclick = () => newRuleModal(cat);
  }

  function newRuleModal(cat) {
    const plantOpts = (cat.plant || []).map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
    const glassOpts = '<option value="">همه ظرف‌ها</option>' + (cat.glass_size || []).map((g) => `<option value="${esc(g.id)}">${esc(g.name)}</option>`).join('');
    modal(`
      <div class="row between"><h2>قانون سازگاری جدید</h2><button class="btn ghost sm" data-close>✕</button></div>
      <form class="stack">
        <label class="field"><span>نوع قانون</span><select name="target_type"><option value="plant">دو گیاه با هم ناسازگارند</option><option value="glass_size">گیاه در این ظرف قرار نگیرد</option></select></label>
        <label class="field"><span>گیاه</span><select name="source_id">${plantOpts}</select></label>
        <label class="field"><span id="target-label">گیاه دوم</span><select name="target_id">${plantOpts}</select></label>
        <label class="field"><span>پیامی که به مشتری نمایش داده می‌شود *</span><textarea name="reason_message" maxlength="500" required placeholder="مثلاً: این دو گیاه نیاز نوری متفاوتی دارند."></textarea></label>
        <div id="nr-err"></div>
        <button class="btn primary">ثبت قانون</button>
      </form>
    `, (el, close) => {
      const f = el.querySelector('form');
      f.target_type.onchange = () => {
        const isPlant = f.target_type.value === 'plant';
        f.target_id.innerHTML = isPlant ? plantOpts : glassOpts;
        el.querySelector('#target-label').textContent = isPlant ? 'گیاه دوم' : 'ظرف';
      };
      f.onsubmit = async (e) => {
        e.preventDefault();
        try {
          await api('/api/v1/admin/rules', { method: 'POST', body: { source_type: 'plant', target_type: f.target_type.value, source_id: f.source_id.value, target_id: f.target_id.value, reason_message: f.reason_message.value } });
          toast('قانون ثبت شد و فوراً اعمال می‌شود.');
          close();
          rules();
        } catch (err) { el.querySelector('#nr-err').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
      };
    });
  }

  // ---- system
  async function system() {
    const s = await api('/api/v1/admin/system');
    const yes = (b, okText = 'OK', badText = 'مشکل') => `<span class="badge ${b ? 'ok' : 'err'}">${b ? okText : badText}</span>`;
    content().innerHTML = `
      <h2>وضعیت سیستم</h2>
      ${s.environment !== 'production' ? '<div class="alert warn">سایت در حالت <b>غیر production</b> اجرا می‌شود. در سرور اصلی مقدار <code>APP_ENV=production</code> را تنظیم کنید.</div>' : ''}
      ${s.debug ? '<div class="alert err">حالت Debug فعال است. در سرور اصلی <code>APP_DEBUG=false</code> باشد.</div>' : ''}
      <div class="grid grid-2">
        <div class="card">
          <h3>سرور</h3>
          <div class="summary-line"><span>محیط</span><b>${esc(s.environment)}</b></div>
          <div class="summary-line"><span>آدرس سایت (APP_URL)</span><span class="ltr">${esc(s.app_url)}</span></div>
          <div class="summary-line"><span>PHP</span><span class="ltr">${esc(s.php_version)}</span></div>
          <div class="summary-line"><span>وب‌سرور</span><span class="ltr">${esc(s.server_software)}</span></div>
          <div class="summary-line"><span>پایگاه داده</span><span>${yes(s.database.status === 'up', esc(s.database.driver || 'up'), 'قطع')}</span></div>
          ${s.database.pending_migrations && s.database.pending_migrations.length ? `<div class="alert warn">مایگریشن اجرا نشده: <code>php bin/console migrate</code></div>` : ''}
          <div class="summary-line"><span>دسترسی نوشتن storage</span>${yes(s.storage_writable)}</div>
          <div class="summary-line"><span>سرویس پیامک</span><b>${esc(s.sms_provider)}</b></div>
          <h3 class="mt">افزونه‌های PHP</h3>
          ${s.extensions.map((e) => `<div class="summary-line"><span class="ltr">${esc(e.name)}</span>${yes(e.loaded, 'فعال', 'غیرفعال')}</div>`).join('')}
        </div>
        <div class="card">
          <h3>درگاه‌های پرداخت</h3>
          ${s.gateways.map((g) => `<div class="summary-line"><span>${esc(GATEWAY_LABEL[g.name] || g.name)}${g.sandbox ? ' <span class="badge warn">sandbox</span>' : ''}</span>
            <span>${g.configured ? '<span class="badge ok">پیکربندی شده</span>' : '<span class="badge">بدون کلید</span>'} ${g.enabled ? '<span class="badge info">فعال در فروشگاه</span>' : ''}</span></div>`).join('')}
          <p class="muted mt">درگاه‌های قابل انتخاب برای مشتری: <b>${s.available_gateways.map((g) => esc(GATEWAY_LABEL[g] || g)).join('، ') || 'هیچ'}</b></p>
          <p class="muted">تنظیم کلیدها در فایل <code>.env</code> سرور انجام می‌شود.</p>
        </div>
      </div>
      <div class="card mt" id="bale-card"><h3>🤖 ربات بله</h3><p class="muted">در حال بررسی…</p></div>`;
    renderBale();
  }

  async function renderBale(data) {
    const el = $('#bale-card');
    if (!el) return;
    try { data = data || await api('/api/v1/admin/bale'); }
    catch (e) { el.innerHTML = `<h3>🤖 ربات بله</h3><div class="alert err">${esc(e.message)}</div>`; return; }
    const b = (v, okT = 'فعال', badT = 'غیرفعال') => `<span class="badge ${v ? 'ok' : ''}">${v ? okT : badT}</span>`;
    el.innerHTML = `
      <h3>🤖 ربات بله</h3>
      ${!data.bot_configured ? '<div class="alert warn">توکن ربات تنظیم نشده است. در <code>.env</code> مقدار <code>BALE_BOT_TOKEN</code> و <code>BALE_BOT_USERNAME</code> را از @botfather در بله وارد کنید.</div>' : ''}
      ${data.bot_error ? `<div class="alert err">خطای اتصال به بله: ${esc(data.bot_error)}</div>` : ''}
      <div class="grid grid-2">
        <div>
          <div class="summary-line"><span>ربات</span><span>${data.bot ? `<a href="${esc(data.link)}" target="_blank" rel="noopener" class="ltr">@${esc(data.bot.username)}</a>` : '—'}</span></div>
          <div class="summary-line"><span>وب‌هوک</span>${b(data.webhook_ok, 'متصل', 'متصل نیست')}</div>
          <div class="summary-line"><span>کاربران متصل</span><b>${num(data.linked_users)}</b></div>
        </div>
        <div>
          <div class="summary-line"><span>پرداخت با کیف پول بله</span><span>${b(data.payment_enabled)} ${data.wallet_test_mode ? '<span class="badge warn">توکن آزمایشی</span>' : ''}</span></div>
          <div class="summary-line"><span>ارسال کد ورود در بله (سفیر)</span>${b(data.otp_via_bale)}</div>
          <div class="summary-line"><span>سفیر (پیام بدون استارت)</span>${b(data.safir_configured, 'تنظیم‌شده', 'تنظیم نشده')}</div>
        </div>
      </div>
      ${data.bot_configured ? `<div class="row gap wrap mt"><button class="btn primary sm" id="btn-bale-setup">🔗 ${data.webhook_ok ? 'اتصال مجدد' : 'اتصال'} ربات به سایت</button>
        <span class="muted" style="font-size:.85rem">آدرس: <span class="ltr">${esc(data.webhook_expected)}</span></span></div>` : ''}
      ${data.payment_enabled ? '' : '<p class="muted mt">برای فعال شدن پرداخت: <code>BALE_WALLET_TOKEN</code> را تنظیم و <code>bale</code> را به <code>ENABLED_PAYMENT_GATEWAYS</code> اضافه کنید.</p>'}`;
    const btn = $('#btn-bale-setup');
    if (btn) btn.onclick = async () => {
      btn.disabled = true;
      try { const d = await api('/api/v1/admin/bale/setup', { method: 'POST', body: {} }); toast('ربات به سایت متصل شد ✅'); renderBale(d); }
      catch (e) { toast(e.message, 'err'); btn.disabled = false; }
    };
  }

  // ------------------------------------------------------------------ init

  async function init() {
    bindLogin();
    $('#nav').addEventListener('click', (e) => { const b = e.target.closest('button[data-tab]'); if (b) openTab(b.dataset.tab); });
    if (!auth.token) return showLogin();
    try {
      const me = await api('/api/v1/auth/me');
      auth.setUser(me);
      if (!me.is_admin) { auth.clear(); return showLogin(); }
      showApp();
    } catch { showLogin(); }
  }

  init();
})();
