/* Shared helpers for storefront and admin. Plain ES2020, no build step. */
(function (global) {
  'use strict';

  const TOKEN_KEY = 'terrarium_token';
  const USER_KEY = 'terrarium_user';

  const auth = {
    get token() { return localStorage.getItem(TOKEN_KEY); },
    get user() { try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { return null; } },
    set(token, user) { localStorage.setItem(TOKEN_KEY, token); localStorage.setItem(USER_KEY, JSON.stringify(user)); },
    setUser(user) { localStorage.setItem(USER_KEY, JSON.stringify(user)); },
    clear() { localStorage.removeItem(TOKEN_KEY); localStorage.removeItem(USER_KEY); },
  };

  class ApiError extends Error {
    constructor(status, body) {
      super((body && body.error && body.error.message) || 'خطا در ارتباط با سرور');
      this.status = status;
      this.code = body && body.error && body.error.code;
      this.details = (body && body.error && body.error.details) || {};
    }
  }

  /** JSON API call using relative URLs (same origin). */
  async function api(path, { method = 'GET', body, auth: useAuth = true } = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (useAuth && auth.token) headers.Authorization = 'Bearer ' + auth.token;
    let res;
    try {
      res = await fetch(path, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });
    } catch (e) {
      throw new ApiError(0, { error: { message: 'اتصال اینترنت برقرار نیست یا سرور در دسترس نیست.' } });
    }
    let data = null;
    try { data = await res.json(); } catch { /* non-JSON */ }
    if (res.status === 401 && useAuth && auth.token) {
      auth.clear();
      global.dispatchEvent(new CustomEvent('auth:expired'));
    }
    if (!res.ok || !data || data.success === false) throw new ApiError(res.status, data);
    return data.data !== undefined ? data.data : data;
  }

  const faNum = new Intl.NumberFormat('fa-IR');
  /** Prices are stored in Rials; displayed in Toman (Rial / 10). */
  const toman = (rial) => faNum.format(Math.round((rial || 0) / 10)) + ' تومان';
  const rial = (v) => faNum.format(v || 0) + ' ریال';
  const num = (v) => faNum.format(v || 0);

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function toast(msg, type) {
    let box = document.getElementById('toast');
    if (!box) { box = document.createElement('div'); box.id = 'toast'; document.body.appendChild(box); }
    const el = document.createElement('div');
    el.className = 'toast' + (type === 'err' ? ' err' : '');
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(() => el.remove(), 4500);
  }

  function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T') + 'Z');
    if (isNaN(d)) return s;
    return new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Tehran' }).format(d);
  }

  const ORDER_STATUS = {
    draft: 'پیش‌نویس',
    pending_payment: 'در انتظار پرداخت',
    paid: 'پرداخت‌شده',
    processing: 'در حال آماده‌سازی',
    completed: 'ارسال/تکمیل‌شده',
    cancelled: 'لغوشده',
    failed: 'ناموفق',
  };
  const GATEWAY_LABEL = { zarinpal: 'زرین‌پال', zibal: 'زیبال', idpay: 'آیدی‌پی', stripe: 'Stripe', test: 'درگاه آزمایشی' };
  const LEVEL = {
    light: { low: 'نور کم', medium: 'نور متوسط', bright: 'نور زیاد' },
    moisture: { low: 'رطوبت کم', medium: 'رطوبت متوسط', high: 'رطوبت زیاد' },
  };

  function debounce(fn, ms) {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  }

  global.T = { api, ApiError, auth, toman, rial, num, esc, toast, fmtDate, debounce, ORDER_STATUS, GATEWAY_LABEL, LEVEL };
})(window);
