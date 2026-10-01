/*
 * Terrarium preview: draws an illustrated SVG of the customer's actual selection
 * (container shape, drainage/charcoal/soil/sand layers, every plant and figure × quantity).
 * Pure function of (cart, catalog) — no network, works for presets and custom builds alike.
 *
 *   TerrariumPreview.svg(cart, catalog) → '<svg …>' string
 *   cart = { glass: id, plants: {id: qty}, stones: {id: qty}, figures: {id: qty} }
 */
(function (global) {
  'use strict';

  const W = 400, H = 440, BOTTOM = 392;

  // deterministic pseudo-random (stable drawing for the same selection)
  function rng(seedStr) {
    let h = 2166136261;
    for (let i = 0; i < seedStr.length; i++) { h ^= seedStr.charCodeAt(i); h = Math.imul(h, 16777619); }
    return () => { h += 0x6D2B79F5; let t = h; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
  }
  const f = (n) => Math.round(n * 10) / 10;
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  // ------------------------------------------------------------------ container
  function glassShape(g) {
    const key = ((g && (g.code || '')) + ' ' + (g && g.name || '')).toUpperCase();
    const closed = !!(g && g.is_closed_ecosystem);
    if (/SPH|کروی|گوی/.test(key)) {
      return {
        kind: 'sphere', closed,
        clip: '<circle cx="200" cy="262" r="140"/>',
        outline: '<path d="M140 128 A140 140 0 1 0 260 128" fill="none" stroke="#9fc5bf" stroke-width="5" stroke-linecap="round"/>' +
                 '<ellipse cx="200" cy="126" rx="62" ry="9" fill="none" stroke="#9fc5bf" stroke-width="4"/>',
        highlight: '<path d="M108 210 A112 112 0 0 1 150 150" fill="none" stroke="#fff" stroke-opacity=".75" stroke-width="7" stroke-linecap="round"/>',
        xRange: (y) => { const dy = y - 262; const w = Math.sqrt(Math.max(0, 140 * 140 - dy * dy)); return [200 - w + 10, 200 + w - 10]; },
        bottom: 396, top: 132,
      };
    }
    if (/HEX|هندسی|شش/.test(key)) {
      const pts = '120,112 280,112 336,250 280,392 120,392 64,250';
      return {
        kind: 'hex', closed,
        clip: `<polygon points="${pts}"/>`,
        outline: `<polygon points="${pts}" fill="none" stroke="#c9a24a" stroke-width="5" stroke-linejoin="round"/>` +
                 '<path d="M120 112 L200 52 L280 112 M64 250 L336 250 M200 52 L200 112" fill="none" stroke="#c9a24a" stroke-width="4" stroke-linejoin="round"/>' +
                 '<path d="M120 112 L200 52 L280 112 Z" fill="#e8f4f2" fill-opacity=".35"/>',
        highlight: '<path d="M84 250 L128 136" stroke="#fff" stroke-opacity=".75" stroke-width="7" stroke-linecap="round"/>',
        xRange: (y) => { const t = y < 250 ? (y - 112) / 138 : (392 - y) / 142; const half = 80 + 56 * clamp(t, 0, 1); return [200 - half + 10, 200 + half - 10]; },
        bottom: 392, top: 116,
      };
    }
    if (/RECT|مستطیل|مکعب|BOX/.test(key)) {
      return {
        kind: 'rect', closed,
        clip: '<rect x="44" y="176" width="312" height="218" rx="4"/>',
        outline: '<rect x="40" y="172" width="320" height="226" rx="5" fill="none" stroke="#8fc3b6" stroke-width="5"/>' +
                 '<path d="M40 172 L70 148 L390 148 L360 172 M390 148 L390 372 L360 398" fill="#e8f4f2" fill-opacity=".25" stroke="#8fc3b6" stroke-width="4" stroke-linejoin="round"/>',
        highlight: '<path d="M58 196 L58 360" stroke="#fff" stroke-opacity=".75" stroke-width="7" stroke-linecap="round"/>',
        xRange: () => [54, 346],
        bottom: 394, top: 180,
      };
    }
    // cylinder (default)
    return {
      kind: 'cylinder', closed,
      clip: '<rect x="96" y="74" width="208" height="320" rx="14"/>',
      outline: '<path d="M92 70 L92 382 Q92 398 108 398 L292 398 Q308 398 308 382 L308 70" fill="none" stroke="#9fc5bf" stroke-width="5" stroke-linejoin="round"/>' +
               (closed ? '' : '<ellipse cx="200" cy="70" rx="108" ry="8" fill="none" stroke="#9fc5bf" stroke-width="4"/>'),
      highlight: '<path d="M112 110 L112 340" stroke="#fff" stroke-opacity=".75" stroke-width="7" stroke-linecap="round"/>',
      xRange: () => [106, 294],
      bottom: 394, top: 78,
    };
  }

  // ------------------------------------------------------------------ layers
  function layerStyle(s) {
    const n = (s.name || '') + ' ' + (s.type || '');
    if (/ذغال|charcoal|substrate/i.test(n)) return { fill: '#1f1f22', h: 12, dots: '#3a3a40' };
    if (/کوارتز|سفید|quartz|decorative|شن/i.test(n)) return { fill: '#f1ede4', h: 10, dots: '#d9d2c3', top: true };
    if (/آتشفشان|volcan|drainage|زهکش/i.test(n)) return { fill: '#3b3d42', h: 26, dots: '#5b5e66', pebbles: true };
    return { fill: '#9b9184', h: 14, dots: '#b8ad9f', pebbles: true };
  }

  function pebbles(x0, x1, y0, y1, color, r, rand, size) {
    let out = '';
    const n = Math.round(((x1 - x0) * (y1 - y0)) / (size * size * 2.2));
    for (let i = 0; i < n; i++) {
      out += `<ellipse cx="${f(x0 + rand() * (x1 - x0))}" cy="${f(y0 + rand() * (y1 - y0))}" rx="${f(size * (0.6 + rand() * 0.6))}" ry="${f(size * (0.45 + rand() * 0.4))}" fill="${color}"/>`;
    }
    return out;
  }

  // ------------------------------------------------------------------ plants
  function plantKind(p) {
    const n = (p.name || '') + ' ' + (p.scientific_name || '');
    if (/سرخس|fern|nephrolepis/i.test(n)) return 'fern';
    if (/خزه|moss|leucobryum/i.test(n)) return 'moss';
    if (/کاکتوس|cact|mammillaria/i.test(n)) return 'cactus';
    if (/هاورتیا|ساکولنت|haworthia|succulent|echeveria/i.test(n)) return 'succulent';
    if (/فیتونیا|fittonia/i.test(n)) return 'fittonia';
    if (p.moisture_level === 'low') return 'succulent';
    if (p.moisture_level === 'high') return 'fern';
    return 'leafy';
  }

  function drawFern(x, y, s, rand) {
    let out = '';
    const fronds = 7;
    for (let i = 0; i < fronds; i++) {
      const a = (-80 + (160 / (fronds - 1)) * i + (rand() - 0.5) * 12) * Math.PI / 180;
      const len = (70 + rand() * 30) * s * (1 - Math.abs(a) / 3.2);
      const ex = x + Math.sin(a) * len, ey = y - Math.cos(a) * len;
      const cx = x + Math.sin(a) * len * 0.35, cy = y - Math.cos(a) * len * 0.9;
      out += `<path d="M${f(x)} ${f(y)} Q${f(cx)} ${f(cy)} ${f(ex)} ${f(ey)}" stroke="#2f7d3a" stroke-width="${f(2 * s)}" fill="none"/>`;
      for (let k = 1; k <= 9; k++) {
        const t = k / 10;
        const px = (1 - t) * (1 - t) * x + 2 * (1 - t) * t * cx + t * t * ex;
        const py = (1 - t) * (1 - t) * y + 2 * (1 - t) * t * cy + t * t * ey;
        const l = 13 * s * (1 - t * 0.7);
        const ang = Math.atan2(ey - cy, ex - cx);
        for (const side of [-1, 1]) {
          const la = ang + side * 1.1;
          out += `<ellipse cx="${f(px + Math.cos(la) * l / 2)}" cy="${f(py + Math.sin(la) * l / 2)}" rx="${f(l / 2)}" ry="${f(2.6 * s)}" transform="rotate(${f(la * 180 / Math.PI)} ${f(px + Math.cos(la) * l / 2)} ${f(py + Math.sin(la) * l / 2)})" fill="${k % 2 ? '#4fa04a' : '#5cb553'}"/>`;
        }
      }
    }
    return out;
  }

  function drawMoss(x, y, s, rand) {
    let out = '';
    const r = 22 * s;
    for (let i = 0; i < 4; i++) {
      const cx = x + (i - 1.5) * r * 0.75 + (rand() - 0.5) * 6, rr = r * (0.7 + rand() * 0.45);
      out += `<ellipse cx="${f(cx)}" cy="${f(y - rr * 0.45)}" rx="${f(rr)}" ry="${f(rr * 0.75)}" fill="${i % 2 ? '#78b83f' : '#8ccc4a'}"/>`;
    }
    for (let i = 0; i < 26; i++) {
      out += `<circle cx="${f(x + (rand() - 0.5) * r * 3.2)}" cy="${f(y - rand() * r * 0.95)}" r="${f(1.4 * s + rand())}" fill="#5f9a2f" fill-opacity=".7"/>`;
    }
    return out;
  }

  function drawCactus(x, y, s, rand) {
    let out = '';
    const parts = [[0, 62], [-17, 40], [16, 46]];
    for (const [dx, hgt] of parts) {
      const h = hgt * s, w = 15 * s, cx = x + dx * s;
      out += `<rect x="${f(cx - w)}" y="${f(y - h)}" width="${f(w * 2)}" height="${f(h)}" rx="${f(w)}" fill="#4f9a5a"/>`;
      out += `<path d="M${f(cx)} ${f(y - h + 4)} L${f(cx)} ${f(y - 2)}" stroke="#3d7f47" stroke-width="${f(1.5 * s)}"/>`;
      for (let k = 0; k < 7; k++) {
        const sy = y - h + 6 + rand() * (h - 10), sx = cx + (rand() < 0.5 ? -1 : 1) * w * (0.4 + rand() * 0.5);
        out += `<circle cx="${f(sx)}" cy="${f(sy)}" r="${f(1.3 * s)}" fill="#f4f1de"/>`;
      }
      if (dx === 0) out += `<circle cx="${f(cx)}" cy="${f(y - h + 2)}" r="${f(4.5 * s)}" fill="#e86a92"/>`;
    }
    return out;
  }

  function drawSucculent(x, y, s) {
    let out = '';
    const leaves = 11;
    for (let i = 0; i < leaves; i++) {
      const a = -90 + (180 / (leaves - 1)) * i;
      const len = (34 + (i % 2) * 8) * s * (1 - Math.abs(a) / 260);
      const rad = a * Math.PI / 180;
      const tx = x + Math.sin(rad) * len, ty = y - Math.cos(rad) * len;
      const nx = Math.cos(rad) * 6 * s, ny = Math.sin(rad) * 6 * s;
      out += `<path d="M${f(x - nx)} ${f(y - ny)} L${f(tx)} ${f(ty)} L${f(x + nx)} ${f(y + ny)} Z" fill="${i % 2 ? '#2f6b4a' : '#3b7d57'}"/>`;
      for (let k = 1; k <= 3; k++) {
        const t = k / 4.4;
        out += `<path d="M${f(x + (tx - x) * t - nx * (1 - t) * 0.7)} ${f(y + (ty - y) * t - ny * (1 - t) * 0.7)} L${f(x + (tx - x) * t + nx * (1 - t) * 0.7)} ${f(y + (ty - y) * t + ny * (1 - t) * 0.7)}" stroke="#e8f0e6" stroke-width="${f(1.4 * s)}"/>`;
      }
    }
    return out;
  }

  function drawLeafCluster(x, y, s, rand, pink) {
    let out = '';
    const leaves = 9;
    for (let i = 0; i < leaves; i++) {
      const a = (-75 + (150 / (leaves - 1)) * i + (rand() - 0.5) * 10) * Math.PI / 180;
      const len = (26 + rand() * 12) * s;
      const cx = x + Math.sin(a) * len * 0.75, cy = y - Math.cos(a) * len * 0.75 - 6 * s;
      const deg = a * 180 / Math.PI;
      out += `<ellipse cx="${f(cx)}" cy="${f(cy)}" rx="${f(10 * s)}" ry="${f(17 * s)}" transform="rotate(${f(deg)} ${f(cx)} ${f(cy)})" fill="${pink ? (i % 2 ? '#c4587a' : '#b04a6c') : (i % 2 ? '#3f8f46' : '#4ea455')}"/>`;
      out += `<path d="M${f(x)} ${f(y)} L${f(cx + Math.sin(a) * 12 * s)} ${f(cy - Math.cos(a) * 12 * s)}" stroke="${pink ? '#f6d3df' : '#9ad49e'}" stroke-width="${f(1.2 * s)}"/>`;
      if (pink) {
        for (const side of [-1, 1]) {
          const vx = cx + Math.cos(a) * side * 6 * s, vy = cy + Math.sin(a) * side * 6 * s;
          out += `<path d="M${f(cx)} ${f(cy)} L${f(vx)} ${f(vy - 4 * s)}" stroke="#f6d3df" stroke-width="${f(0.9 * s)}"/>`;
        }
      }
    }
    return out;
  }

  function drawPlant(kind, x, y, s, rand) {
    switch (kind) {
      case 'fern': return drawFern(x, y, s, rand);
      case 'moss': return drawMoss(x, y, s, rand);
      case 'cactus': return drawCactus(x, y, s, rand);
      case 'succulent': return drawSucculent(x, y, s);
      case 'fittonia': return drawLeafCluster(x, y, s, rand, true);
      default: return drawLeafCluster(x, y, s, rand, false);
    }
  }

  // ------------------------------------------------------------------ figures
  function drawFigure(fig, x, y, s) {
    const n = fig.name || '';
    if (/کلبه|خانه|house|cabin/i.test(n)) {
      return `<rect x="${f(x - 16 * s)}" y="${f(y - 24 * s)}" width="${f(32 * s)}" height="${f(24 * s)}" fill="#a86b3c"/>` +
        `<path d="M${f(x - 21 * s)} ${f(y - 22 * s)} L${f(x)} ${f(y - 42 * s)} L${f(x + 21 * s)} ${f(y - 22 * s)} Z" fill="#6f3f1f"/>` +
        `<rect x="${f(x - 4 * s)}" y="${f(y - 13 * s)}" width="${f(8 * s)}" height="${f(13 * s)}" fill="#4a2a14"/>` +
        `<rect x="${f(x + 7 * s)}" y="${f(y - 19 * s)}" width="${f(6 * s)}" height="${f(6 * s)}" fill="#f6d27a"/>`;
    }
    if (/قارچ|mushroom/i.test(n)) {
      return `<rect x="${f(x - 3.5 * s)}" y="${f(y - 14 * s)}" width="${f(7 * s)}" height="${f(14 * s)}" rx="${f(3 * s)}" fill="#f4ead8"/>` +
        `<path d="M${f(x - 13 * s)} ${f(y - 12 * s)} Q${f(x)} ${f(y - 34 * s)} ${f(x + 13 * s)} ${f(y - 12 * s)} Z" fill="#d93a32"/>` +
        `<circle cx="${f(x - 5 * s)}" cy="${f(y - 19 * s)}" r="${f(2.2 * s)}" fill="#fff"/><circle cx="${f(x + 5 * s)}" cy="${f(y - 21 * s)}" r="${f(1.8 * s)}" fill="#fff"/>`;
    }
    if (/روباه|fox/i.test(n)) {
      return `<ellipse cx="${f(x)}" cy="${f(y - 8 * s)}" rx="${f(13 * s)}" ry="${f(8 * s)}" fill="#e07a2f"/>` +
        `<path d="M${f(x + 10 * s)} ${f(y - 8 * s)} Q${f(x + 26 * s)} ${f(y - 16 * s)} ${f(x + 22 * s)} ${f(y - 2 * s)} Z" fill="#e07a2f"/>` +
        `<circle cx="${f(x + 22 * s)}" cy="${f(y - 4 * s)}" r="${f(2.5 * s)}" fill="#fff"/>` +
        `<path d="M${f(x - 18 * s)} ${f(y - 14 * s)} L${f(x - 9 * s)} ${f(y - 30 * s)} L${f(x - 3 * s)} ${f(y - 14 * s)} Z" fill="#e07a2f"/>` +
        `<circle cx="${f(x - 10 * s)}" cy="${f(y - 15 * s)}" r="${f(8 * s)}" fill="#e07a2f"/>` +
        `<path d="M${f(x - 18 * s)} ${f(y - 13 * s)} L${f(x - 10 * s)} ${f(y - 8 * s)} L${f(x - 4 * s)} ${f(y - 13 * s)} Z" fill="#fff"/>` +
        `<circle cx="${f(x - 12 * s)}" cy="${f(y - 17 * s)}" r="${f(1.2 * s)}" fill="#222"/>`;
    }
    return `<path d="M${f(x)} ${f(y - 28 * s)} L${f(x + 5 * s)} ${f(y - 16 * s)} L${f(x + 17 * s)} ${f(y - 14 * s)} L${f(x + 8 * s)} ${f(y - 6 * s)} L${f(x + 11 * s)} ${f(y + 5 * s)} L${f(x)} ${f(y - 1 * s)} L${f(x - 11 * s)} ${f(y + 5 * s)} L${f(x - 8 * s)} ${f(y - 6 * s)} L${f(x - 17 * s)} ${f(y - 14 * s)} L${f(x - 5 * s)} ${f(y - 16 * s)} Z" fill="#e9c46a"/>`;
  }

  // ------------------------------------------------------------------ main
  function svg(cart, catalog, opts = {}) {
    const byId = (arr) => Object.fromEntries((arr || []).map((x) => [x.id, x]));
    const G = byId(catalog.glass_sizes), P = byId(catalog.plants), S = byId(catalog.stones), F = byId(catalog.figures);
    const glass = G[cart.glass];
    const id = 'tp' + Math.random().toString(36).slice(2, 8);
    const seed = JSON.stringify(cart);
    const rand = rng(seed);
    const shape = glassShape(glass);

    // layers (bottom → top): drainage/substrate stones, soil, decorative sand on top
    const stones = Object.entries(cart.stones || {}).filter(([sid, q]) => S[sid] && q > 0).map(([sid, q]) => ({ s: S[sid], q, st: layerStyle(S[sid]) }));
    const under = stones.filter((x) => !x.st.top).sort((a, b) => (b.st.pebbles ? 1 : 0) - (a.st.pebbles ? 1 : 0));
    const over = stones.filter((x) => x.st.top);
    let y = shape.bottom;
    let layers = '';
    for (const L of under) {
      const h = clamp(L.st.h * Math.sqrt(L.q), 8, 70);
      layers += `<rect x="0" y="${f(y - h)}" width="${W}" height="${f(h + 2)}" fill="${L.st.fill}"/>`;
      layers += pebbles(40, 360, y - h, y, L.st.dots, 0, rand, L.st.pebbles ? 6 : 2.5);
      y -= h;
    }
    const soilH = 34;
    layers += `<path d="M0 ${f(y + 2)} L0 ${f(y - soilH + 4)} Q100 ${f(y - soilH - 4)} 200 ${f(y - soilH + 2)} T400 ${f(y - soilH)} L400 ${f(y + 2)} Z" fill="#5a3d2b"/>`;
    layers += pebbles(40, 360, y - soilH + 6, y, '#6d4b36', 0, rand, 2.2);
    y -= soilH;
    for (const L of over) {
      const h = clamp(L.st.h * Math.sqrt(L.q), 6, 30);
      layers += `<path d="M0 ${f(y + 4)} Q120 ${f(y - h)} 200 ${f(y - h * 0.6)} T400 ${f(y - h * 0.4)} L400 ${f(y + 6)} L0 ${f(y + 6)} Z" fill="${L.st.fill}"/>`;
      layers += pebbles(40, 360, y - h * 0.5, y + 3, L.st.dots, 0, rand, 1.8);
      y -= h * 0.5;
    }
    const ground = y + 2;

    // plants: tall ones at the back, moss & small ones in front
    const items = [];
    for (const [pid, q] of Object.entries(cart.plants || {})) {
      const p = P[pid]; if (!p || q < 1) continue;
      const kind = plantKind(p);
      const s = clamp(Math.sqrt((p.volume_occupancy_ml || 250) / 250), 0.75, 1.3) * (kind === 'moss' ? 1.3 : 1.6);
      for (let i = 0; i < Math.min(q, 6); i++) items.push({ kind, s, back: kind === 'fern' || kind === 'cactus' });
    }
    const figs = [];
    for (const [fid, q] of Object.entries(cart.figures || {})) {
      const fg = F[fid]; if (!fg || q < 1) continue;
      for (let i = 0; i < Math.min(q, 4); i++) figs.push(fg);
    }
    const [x0, x1] = shape.xRange(ground - 10);
    const back = items.filter((i) => i.back), front = items.filter((i) => !i.back);
    const spread = (list, inset) => list.map((it, i) => ({ ...it, x: list.length === 1 ? (x0 + x1) / 2 + (rand() - 0.5) * 20 : x0 + inset + ((x1 - x0 - inset * 2) * i) / (list.length - 1) }));
    let plantsSvg = '';
    for (const it of spread(back, 46)) plantsSvg += drawPlant(it.kind, it.x, ground - 4, it.s, rand);
    for (const it of spread(front, 32)) plantsSvg += drawPlant(it.kind, it.x, ground + 2, it.s, rand);
    let figSvg = '';
    figs.forEach((fg, i) => {
      const x = x0 + 24 + ((x1 - x0 - 48) * (i + 0.5)) / figs.length + (rand() - 0.5) * 16;
      figSvg += drawFigure(fg, x, ground + 6, 1.35);
    });

    // closed glass: condensation + cork lid
    let extra = '';
    if (shape.closed) {
      for (let i = 0; i < 40; i++) extra += `<circle cx="${f(110 + rand() * 180)}" cy="${f(shape.top + 6 + rand() * 120)}" r="${f(0.8 + rand() * 1.8)}" fill="#fff" fill-opacity=".65"/>`;
    }
    const lid = shape.closed
      ? (shape.kind === 'cylinder'
        ? '<rect x="86" y="38" width="228" height="38" rx="8" fill="#c79a63"/><rect x="86" y="38" width="228" height="38" rx="8" fill="url(#' + id + 'cork)"/>'
        : shape.kind === 'sphere' ? '<ellipse cx="200" cy="124" rx="64" ry="14" fill="#c79a63"/>' : '')
      : '';
    const empty = !glass
      ? `<text x="200" y="250" text-anchor="middle" font-size="18" fill="#7a8a85" font-family="inherit">یک ظرف انتخاب کنید</text>`
      : '';

    const title = opts.title ? `<title>${String(opts.title).replace(/[<&>]/g, '')}</title>` : '';
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" class="terrarium-svg" role="img" aria-label="پیش‌نمایش تراریوم">${title}
      <defs>
        <clipPath id="${id}c">${shape.clip}</clipPath>
        <linearGradient id="${id}g" x1="0" x2="1"><stop offset="0" stop-color="#e3f3f0" stop-opacity=".55"/><stop offset=".5" stop-color="#ffffff" stop-opacity=".15"/><stop offset="1" stop-color="#d7ece8" stop-opacity=".5"/></linearGradient>
        <pattern id="${id}cork" width="6" height="6" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1" fill="#a87b48"/><circle cx="5" cy="4.5" r=".8" fill="#dcb581"/></pattern>
      </defs>
      <ellipse cx="200" cy="${BOTTOM + 30}" rx="150" ry="12" fill="#000" fill-opacity=".08"/>
      ${glass ? `<g clip-path="url(#${id}c)"><rect x="0" y="0" width="${W}" height="${H}" fill="url(#${id}g)"/>${layers}${plantsSvg}${figSvg}${extra}</g>${shape.outline}${shape.highlight}${lid}` : empty}
    </svg>`;
  }

  // single-item illustration for option cards
  function item(kind, x, catalog) {
    const rand = rng(String(x.id || x.name));
    let body = '';
    if (kind === 'glass') {
      return svg({ glass: x.id, plants: {}, stones: {}, figures: {} }, catalog || { glass_sizes: [x] });
    }
    if (kind === 'plants') {
      const k = plantKind(x);
      body = `<ellipse cx="60" cy="88" rx="34" ry="7" fill="#6b4a33"/><ellipse cx="60" cy="86" rx="30" ry="5" fill="#7d5a3f"/>` +
        drawPlant(k, 60, 86, k === 'moss' ? 1.25 : k === 'fern' ? 0.72 : 0.9, rand);
    } else if (kind === 'stones') {
      const st = layerStyle(x);
      body = `<path d="M18 90 Q60 40 102 90 Z" fill="${st.fill}"/>` + pebbles(26, 94, 62, 88, st.dots, 0, rand, 4.5) +
        (st.pebbles ? pebbles(30, 90, 70, 88, st.fill, 0, rand, 6) : '');
    } else {
      body = `<ellipse cx="60" cy="88" rx="30" ry="5" fill="#cfe3d2"/>` + drawFigure(x, 60, 86, 1.7);
    }
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 100" class="item-svg" aria-hidden="true">${body}</svg>`;
  }

  global.TerrariumPreview = { svg, item };
})(window);
