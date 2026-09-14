/* Queueoke shared client JS. Loaded by index.php, tv.php, remote.php. */
(function (global) {
  'use strict';

  // Pusher keys are injected by PHP via window.QUEUEOKE_CFG.
  const CFG = global.QUEUEOKE_CFG || { pusher_key: '', pusher_cluster: 'us2', api_base: 'api' };

  // ---------- Toast ----------
  function toast(msg, type = '', ms = 3200) {
    let host = document.getElementById('toasts');
    if (!host) { host = document.createElement('div'); host.id = 'toasts'; document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(40px)'; el.style.transition = 'all .25s';
      setTimeout(() => el.remove(), 250); }, ms);
  }

  // ---------- API fetch helpers ----------
  async function api(path, body = null, method = null) {
    const url = CFG.api_base + '/' + path;
    const opts = { method: method || (body ? 'POST' : 'GET'), headers: {} };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    try {
      const r = await fetch(url, opts);
      const j = await r.json().catch(() => ({ ok: false, error: 'bad-json' }));
      if (!r.ok || !j.ok) throw new Error(j.error || ('HTTP ' + r.status));
      return j;
    } catch (e) {
      toast(e.message, 'error');
      throw e;
    }
  }

  // ---------- Pusher ----------
  let pusher = null, channels = {};
  function getPusher() {
    if (pusher) return pusher;
    if (!global.Pusher) return null;
    pusher = new global.Pusher(CFG.pusher_key, { cluster: CFG.pusher_cluster, encrypted: true });
    return pusher;
  }
  function subscribe(roomId) {
    const p = getPusher(); if (!p) return null;
    const name = 'room-' + roomId;
    if (channels[name]) return channels[name];
    channels[name] = p.subscribe(name);
    return channels[name];
  }
  function bind(roomId, event, cb) {
    const ch = subscribe(roomId); if (!ch) return;
    ch.bind(event, cb);
  }

  // ---------- LocalStorage favorites (per-device, per spec) ----------
  const FAV_KEY = 'queueoke_favorites';
  function getFavorites() {
    try { return JSON.parse(localStorage.getItem(FAV_KEY) || '[]'); } catch { return []; }
  }
  function saveFavorites(list) { localStorage.setItem(FAV_KEY, JSON.stringify(list)); }
  function addFavorite(song) {
    const list = getFavorites();
    if (list.find(s => s.video_id === song.video_id)) return false;
    list.unshift({ video_id: song.video_id, title: song.title, thumbnail: song.thumbnail, duration_sec: song.duration_sec, added_at: Date.now() });
    saveFavorites(list.slice(0, 200));
    return true;
  }
  function removeFavorite(videoId) {
    const list = getFavorites().filter(s => s.video_id !== videoId);
    saveFavorites(list);
  }
  function isFavorite(videoId) { return getFavorites().some(s => s.video_id === videoId); }

  // ---------- Helpers ----------
  function fmtTime(sec) {
    if (!sec || sec < 0) return '';
    const m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function avatarColor(name) {
    let h = 0; for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h);
    const hue = Math.abs(h) % 360;
    return `hsl(${hue} 70% 60%)`;
  }
  function initials(name) {
    const p = name.trim().split(/\s+/); return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase() || '?';
  }
  function qs(name) {
    return new URLSearchParams(location.search).get(name);
  }
  function debounce(fn, ms) {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  }

  global.Q = { CFG, toast, api, subscribe, bind, getPusher,
    getFavorites, addFavorite, removeFavorite, isFavorite, saveFavorites,
    fmtTime, avatarColor, initials, qs, debounce };
})(window);
