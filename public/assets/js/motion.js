/* SmartStay ONE — motion tokens + primitives. The single source of truth for every animation.
   No library: springs are solved here and played through the Web Animations API, so transform and
   opacity run on the compositor. Every animate() call is interruptible — it starts from the element's
   current on-screen value and velocity instead of queueing behind the previous animation.
   motion.css carries the same springs as CSS linear() easings (fallbacks generated from these tokens;
   this file overwrites them at load so the two can never drift). */
(function () {
  'use strict';

  // ── Tokens ──────────────────────────────────────────────────────────────
  const tokens = {
    spring: {
      press:  { damping: 28, stiffness: 420, mass: 1 },
      settle: { damping: 22, stiffness: 260, mass: 0.8 },
      pop:    { damping: 14, stiffness: 320, mass: 1 }, // status chip only
    },
    ease: {
      exit:  { curve: [0.4, 0, 1, 1], duration: 160 },
      enter: { curve: [0.22, 1, 0.36, 1], duration: 200 },   // timed entrance (cards)
      out:   { curve: [0, 0, 0.2, 1], duration: 280 },       // screen change, ranked fills
      expo:  { fn: (t) => (t >= 1 ? 1 : 1 - Math.pow(2, -10 * t)), duration: 700 }, // count-up
    },
    stagger: { card: 35, cardMax: 280, filter: 30, bar: 40, rank: 50, kpi: 60 },
    distance: { card: 10, filter: 8, number: 6 },
    reduced: { duration: 120 },
  };

  const reduceQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  const reduced = () => !!(reduceQuery && reduceQuery.matches);
  const FPS = 60;

  // ── Spring solver (semi-implicit Euler, 240 Hz, sampled at 60 fps) ───────
  function solveSpring(cfg, from, to, v0) {
    const { damping: c, stiffness: k, mass: m } = cfg;
    const range = Math.abs(to - from) || 1;
    const restDelta = Math.max(0.0005, range * 0.0008);
    const restSpeed = restDelta * 12;
    const steps = 4, dt = 1 / (FPS * steps);
    let x = from, v = v0 || 0;
    const out = [x];
    for (let f = 1; f < FPS * 4; f++) {
      for (let s = 0; s < steps; s++) {
        const a = (-k * (x - to) - c * v) / m;
        v += a * dt;
        x += v * dt;
      }
      out.push(x);
      if (Math.abs(x - to) < restDelta && Math.abs(v) < restSpeed) break;
    }
    out[out.length - 1] = to;
    return out;
  }

  function bezier(p1x, p1y, p2x, p2y) {
    const cx = 3 * p1x, bx = 3 * (p2x - p1x) - cx, ax = 1 - cx - bx;
    const cy = 3 * p1y, by = 3 * (p2y - p1y) - cy, ay = 1 - cy - by;
    const sx = (t) => ((ax * t + bx) * t + cx) * t;
    const sy = (t) => ((ay * t + by) * t + cy) * t;
    const dx = (t) => (3 * ax * t + 2 * bx) * t + cx;
    return (x) => {
      if (x <= 0) return 0;
      if (x >= 1) return 1;
      let t = x;
      for (let i = 0; i < 6; i++) {
        const d = dx(t);
        if (Math.abs(d) < 1e-6) break;
        t -= (sx(t) - x) / d;
      }
      return sy(Math.min(1, Math.max(0, t)));
    };
  }
  const easeFn = (e) => e.fn || bezier.apply(null, e.curve);

  function solveTimed(timing, from, to) {
    const fn = easeFn(timing);
    const n = Math.max(1, Math.round((timing.duration / 1000) * FPS));
    const out = [];
    for (let i = 0; i <= n; i++) out.push(from + (to - from) * fn(i / n));
    return out;
  }

  // Spring as a CSS linear() easing, for CSS transitions (pills, press, thumbs).
  function cssSpring(cfg) {
    const pts = solveSpring(cfg, 0, 1, 0);
    const step = Math.max(1, Math.round(pts.length / 48));
    const list = [];
    for (let i = 0; i < pts.length; i += step) list.push(+pts[i].toFixed(4));
    if (list[list.length - 1] !== 1) list.push(1);
    return { easing: 'linear(' + list.join(', ') + ')', duration: Math.round((pts.length / FPS) * 1000) };
  }

  // ── animate(): interruptible transform / opacity animation ───────────────
  const TRANSFORM_KEYS = ['x', 'y', 'rotate', 'scale', 'scaleX', 'scaleY'];
  const DEFAULTS = { x: 0, y: 0, rotate: 0, scale: 1, scaleX: 1, scaleY: 1, opacity: 1 };

  function transformOf(v) {
    let t = '';
    if (v.x || v.y) t += `translate3d(${(v.x || 0).toFixed(2)}px, ${(v.y || 0).toFixed(2)}px, 0) `;
    if (v.rotate) t += `rotate(${v.rotate.toFixed(2)}deg) `;
    if (v.scale !== undefined && v.scale !== 1) t += `scale(${v.scale.toFixed(4)}) `;
    if (v.scaleX !== undefined && v.scaleX !== 1) t += `scaleX(${v.scaleX.toFixed(4)}) `;
    if (v.scaleY !== undefined && v.scaleY !== 1) t += `scaleY(${v.scaleY.toFixed(4)}) `;
    return t.trim() || 'none';
  }

  function stateOf(el) {
    if (!el.__motion) el.__motion = { values: {}, frames: null, anim: null };
    return el.__motion;
  }

  // Current on-screen value + velocity (units/s) of each key, read from the running animation.
  function current(el) {
    const st = stateOf(el);
    const values = Object.assign({}, st.values);
    const velocity = {};
    if (st.anim && st.frames && st.anim.playState === 'running') {
      const t = Math.max(0, (st.anim.currentTime || 0) - (st.delay || 0));
      const last = st.frames.length - 1;
      const i = Math.min(last, Math.floor((t / 1000) * FPS));
      Object.assign(values, st.frames[i]);
      if (i > 0) {
        for (const k in st.frames[i]) velocity[k] = (st.frames[i][k] - st.frames[i - 1][k]) * FPS;
      }
    }
    return { values, velocity };
  }

  function applyStatic(el, values) {
    const hasT = TRANSFORM_KEYS.some((k) => k in values);
    if (hasT) {
      const t = transformOf(values);
      el.style.transform = t === 'none' ? '' : t;
    }
    if ('opacity' in values) el.style.opacity = values.opacity >= 1 ? '' : String(values.opacity);
    if ('width' in values) el.style.width = values.width + 'px';
  }

  /**
   * animate(el, { y: [8, 0], opacity: [0, 1] }, { spring: 'settle' | {...}, timing: {curve,duration}, delay, per: { opacity: timing } })
   * Each key is [from, to] or just `to` (start from the current value). Returns a Promise.
   */
  function animate(el, props, opts) {
    opts = opts || {};
    const st = stateOf(el);
    const now = current(el);
    if (st.anim) { st.anim.onfinish = null; st.anim.cancel(); st.anim = null; }

    const ends = {};
    const tracks = {};
    let len = 1;
    let isReduced = reduced() && !opts.ignoreReduced;

    for (const key in props) {
      const p = props[key];
      const from = Array.isArray(p) ? p[0] : (key in now.values ? now.values[key] : DEFAULTS[key] ?? 0);
      const to = Array.isArray(p) ? p[1] : p;
      ends[key] = to;
      if (isReduced && key !== 'opacity') continue; // reduced motion: no translate / scale / overshoot
      const timing = isReduced ? { curve: [0, 0, 1, 1], duration: tokens.reduced.duration }
        : (opts.per && opts.per[key]) || opts.timing || null;
      const springCfg = typeof opts.spring === 'string' ? tokens.spring[opts.spring] : opts.spring;
      const v0 = Array.isArray(p) && !opts.keepVelocity ? (opts.velocity || {})[key] || 0 : now.velocity[key] || 0;
      tracks[key] = timing || !springCfg ? solveTimed(timing || tokens.ease.enter, from, to) : solveSpring(springCfg, from, to, v0);
      len = Math.max(len, tracks[key].length);
    }

    // Keys not animated (reduced motion) jump straight to their end value.
    const base = Object.assign({}, now.values);
    for (const key in ends) if (!tracks[key]) base[key] = ends[key];

    const keys = Object.keys(tracks);
    if (!keys.length) {
      st.values = Object.assign(base, ends);
      applyStatic(el, st.values);
      return Promise.resolve();
    }

    const frames = [];
    const keyframes = [];
    for (let i = 0; i < len; i++) {
      const f = {};
      for (const k of keys) f[k] = tracks[k][Math.min(i, tracks[k].length - 1)];
      frames.push(f);
      const v = Object.assign({}, base, f);
      const kf = {};
      if (TRANSFORM_KEYS.some((k) => k in v)) kf.transform = transformOf(v);
      if ('opacity' in v) kf.opacity = v.opacity;
      if ('width' in f) kf.width = f.width + 'px';
      keyframes.push(kf);
    }
    if (keyframes.length === 1) keyframes.push(keyframes[0]);

    const duration = Math.max(1, ((keyframes.length - 1) / FPS) * 1000);
    const anim = el.animate(keyframes, { duration, delay: opts.delay || 0, easing: 'linear', fill: 'both' });
    st.anim = anim;
    st.frames = frames;
    st.delay = opts.delay || 0;
    st.values = Object.assign(base, ends);

    return new Promise((resolve) => {
      anim.onfinish = () => {
        if (st.anim !== anim) return resolve();
        applyStatic(el, st.values);
        anim.cancel();
        st.anim = null;
        st.frames = null;
        resolve();
      };
      anim.oncancel = () => resolve();
    });
  }

  // Live value of one key (e.g. the y offset of a card mid-FLIP).
  function valueOf(el, key) {
    const v = current(el).values[key];
    return v === undefined ? (DEFAULTS[key] ?? 0) : v;
  }

  function stop(el) {
    const st = el.__motion;
    if (st && st.anim) {
      const now = current(el);
      st.anim.onfinish = null;
      st.anim.cancel();
      st.anim = null;
      st.values = now.values;
      applyStatic(el, now.values);
    }
  }

  // A single overshoot to `peak` and back to rest (e.g. scale 1 → 1.04 → 1): a velocity impulse on a
  // spring at rest, scaled so the first overshoot lands exactly on `peak`.
  function impulse(el, key, peak, springName) {
    if (reduced()) return Promise.resolve();
    const cfg = tokens.spring[springName || 'pop'];
    const rest = DEFAULTS[key] ?? 0;
    const probe = solveSpring(cfg, rest, rest, 1);
    const top = Math.max.apply(null, probe.map((v) => v - rest)) || 1;
    const v0 = (peak - rest) / top;
    stop(el);
    return animate(el, { [key]: [rest, rest] }, { spring: cfg, velocity: { [key]: v0 } });
  }

  // Write values directly (gesture-driven), cancelling any spring in flight.
  function set(el, values) {
    const st = stateOf(el);
    if (st.anim) { st.anim.onfinish = null; st.anim.cancel(); st.anim = null; st.frames = null; }
    st.values = Object.assign({}, st.values, values);
    applyStatic(el, st.values);
  }

  // Drop every inline motion style so CSS (e.g. :active) owns the element again.
  function reset(el) {
    const st = el.__motion;
    if (st && st.anim) { st.anim.onfinish = null; st.anim.cancel(); }
    el.__motion = null;
    el.style.transform = '';
    el.style.opacity = '';
  }

  // ── Haptics: Android vibrate; iOS 18+ through the native switch control ───
  let iosSwitch = null;
  function haptic() {
    try {
      if (navigator.vibrate) { navigator.vibrate(8); return; }
      if (!/iPhone|iPad|iPod/.test(navigator.userAgent)) return;
      if (!iosSwitch) {
        const id = 'one-haptic';
        iosSwitch = document.createElement('label');
        iosSwitch.setAttribute('for', id);
        iosSwitch.setAttribute('aria-hidden', 'true');
        iosSwitch.style.cssText = 'position:fixed;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden;';
        iosSwitch.innerHTML = `<input type="checkbox" switch id="${id}" tabindex="-1">`;
        document.body.appendChild(iosSwitch);
      }
      iosSwitch.click();
    } catch (e) { /* haptics are a nicety */ }
  }

  // ── Numbers: ro-RO formatting kept inside the animation ──────────────────
  // Parses "1.234,5 Lei", "72,4%", "12" → { value, decimals, prefix, suffix }.
  function parseNumber(text) {
    const m = String(text).match(/^(\D*?)(-?[\d.]*\d(?:,\d+)?)(.*)$/s);
    if (!m) return null;
    const decimals = m[2].includes(',') ? m[2].split(',')[1].length : 0;
    const value = parseFloat(m[2].replace(/\./g, '').replace(',', '.'));
    if (!isFinite(value)) return null;
    return { value, decimals, prefix: m[1], suffix: m[3] };
  }
  // grouping: true → "1.234" (PHP number_format), false → "1234" (Intl ro-RO default under 10 000).
  function formatNumber(v, decimals, grouping) {
    const s = Math.abs(v).toFixed(decimals).split('.');
    if (grouping !== false) s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (v < 0 ? '-' : '') + s.join(',');
  }

  // Count the first text node of `el` from `from` (default 0) to the number it already shows.
  function countUp(el, opts) {
    opts = opts || {};
    const node = Array.from(el.childNodes).find((n) => n.nodeType === 3 && n.nodeValue.trim());
    if (!node) return Promise.resolve();
    if (el.__count) cancelAnimationFrame(el.__count.raf);
    const target = el.__count ? el.__count.final : (el.__countTarget || node.nodeValue);
    el.__countTarget = null;
    el.setAttribute('data-counted', '');
    const parsed = parseNumber(target);
    if (!parsed) return Promise.resolve();
    const from = opts.from !== undefined ? opts.from : 0;
    const grouping = /\d\.\d{3}/.test(target) || parsed.value < 1000;
    const write = (v) => { node.nodeValue = parsed.prefix + formatNumber(v, parsed.decimals, grouping) + parsed.suffix; };
    if (reduced()) { node.nodeValue = target; return Promise.resolve(); }
    const duration = opts.duration || tokens.ease.expo.duration;
    const fn = tokens.ease.expo.fn;
    const delay = opts.delay || 0;
    write(from);
    return new Promise((resolve) => {
      const start = performance.now() + delay;
      const tick = (t) => {
        const p = Math.min(1, Math.max(0, (t - start) / duration));
        if (p >= 1) { node.nodeValue = target; el.__count = null; resolve(); return; }
        write(from + (parsed.value - from) * fn(p));
        el.__count.raf = requestAnimationFrame(tick);
      };
      el.__count = { final: target, raf: requestAnimationFrame(tick) };
    });
  }

  // Park a number at 0 (formatted) until countUp() runs — e.g. while its chart is off screen.
  function holdCount(el) {
    const node = Array.from(el.childNodes).find((n) => n.nodeType === 3 && n.nodeValue.trim());
    if (!node || reduced() || el.__countTarget) return;
    const parsed = parseNumber(node.nodeValue);
    if (!parsed) return;
    el.__countTarget = node.nodeValue;
    node.nodeValue = parsed.prefix + formatNumber(0, parsed.decimals) + parsed.suffix;
  }

  // ── Text swap: old text slides up, new text comes in from below (120 ms) ──
  function swapText(el, text, opts) {
    opts = opts || {};
    if (el.__swap) { clearTimeout(el.__swap.timer); el.__swap.finish(); }
    const original = opts.original !== undefined ? opts.original : el.textContent;
    const finish = () => { el.textContent = text; el.classList.remove('swap'); };
    if (reduced()) {
      el.textContent = text;
    } else {
      const h = el.getBoundingClientRect().height || 18;
      const out = document.createElement('span');
      const inn = document.createElement('span');
      out.className = 'swap-out';
      inn.className = 'swap-in';
      out.textContent = el.textContent;
      inn.textContent = text;
      el.textContent = '';
      el.classList.add('swap');
      el.append(out, inn);
      const timing = { curve: [0.2, 0, 0, 1], duration: 120 };
      animate(out, { y: [0, -h], opacity: [1, 0] }, { timing });
      animate(inn, { y: [h, 0], opacity: [0, 1] }, { timing }).then(() => { if (inn.parentNode === el) finish(); });
    }
    const state = { finish, timer: null };
    el.__swap = state;
    if (opts.revertAfter) {
      state.timer = setTimeout(() => { el.__swap = null; swapText(el, original); }, opts.revertAfter);
    } else {
      setTimeout(() => { if (el.__swap === state) el.__swap = null; }, 140);
    }
  }

  // ── Shared layout pill for chip groups: one thumb slides to the active chip ──
  function chipPill(container, activeSel) {
    activeSel = activeSel || '.is-active';
    let thumb = container.querySelector(':scope > .chip-thumb');
    if (!thumb) {
      thumb = document.createElement('span');
      thumb.className = 'chip-thumb';
      thumb.setAttribute('aria-hidden', 'true');
      container.prepend(thumb);
    }
    container.classList.add('has-pill');
    let placed = false;
    function sync(instant) {
      const active = container.querySelector(':scope > ' + activeSel);
      if (!active) { thumb.style.opacity = '0'; return; }
      const x = active.offsetLeft;
      const w = active.offsetWidth;
      if (instant || !placed || reduced()) {
        thumb.classList.add('no-transition');
        thumb.style.transform = `translateX(${x}px)`;
        thumb.style.width = w + 'px';
        thumb.style.opacity = '1';
        void thumb.offsetWidth;
        thumb.classList.remove('no-transition');
        placed = true;
        return;
      }
      thumb.style.transform = `translateX(${x}px)`;
      thumb.style.width = w + 'px';
    }
    window.addEventListener('resize', () => sync(true));
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => sync(true));
    sync(true);
    return { sync };
  }

  // ── CSS custom properties from the same tokens ─────────────────────────
  function writeCssVars() {
    const root = document.documentElement.style;
    const supportsLinear = window.CSS && window.CSS.supports && window.CSS.supports('transition-timing-function', 'linear(0, 1)');
    for (const name in tokens.spring) {
      const s = cssSpring(tokens.spring[name]);
      root.setProperty(`--dur-${name}`, s.duration + 'ms');
      if (supportsLinear) root.setProperty(`--spring-${name}`, s.easing);
    }
    root.setProperty('--ease-exit', `cubic-bezier(${tokens.ease.exit.curve.join(', ')})`);
    root.setProperty('--dur-exit', tokens.ease.exit.duration + 'ms');
  }
  writeCssVars();

  // iOS only applies :active while a touch listener exists — the press springs need it.
  document.addEventListener('touchstart', () => {}, { passive: true });

  window.MOTION = {
    tokens, reduced, animate, impulse, valueOf, set, stop, reset, haptic, countUp, holdCount, parseNumber, formatNumber,
    swapText, chipPill, cssSpring, solveSpring, bezier,
  };
})();
