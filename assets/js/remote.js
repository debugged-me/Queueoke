/* Queueoke mobile remote logic. */
(function () {
  'use strict';
  const Q = window.Q, CFG = window.QUEUEOKE_CFG;
  const ROOM = CFG.room_id, GUEST = CFG.guest_id, NICK = CFG.nick;
  let queue = [], nowPlaying = null, approved = CFG.status === 'approved';

  // ---------- Approval gate ----------
  function setApproved(state) {
    approved = state;
    const el = document.getElementById('conn-status');
    if (state) {
      el.className = 'conn-status live';
      el.innerHTML = '<span class="dot"></span> live';
    } else {
      el.className = 'conn-status waiting';
      el.innerHTML = '<span class="dot"></span> waiting';
    }
    document.getElementById('pending-screen').classList.toggle('hidden', state);
    document.getElementById('app').classList.toggle('hidden', !state);
  }
  setApproved(approved);

  // ---------- Tabs ----------
  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
      document.querySelectorAll('.pane').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      document.getElementById('pane-' + t.dataset.tab).classList.add('active');
      if (t.dataset.tab === 'favs') renderFavs();
    });
  });

  // ---------- Helpers ----------
  function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  function songRow(song, opts = {}) {
    const isFav = Q.isFavorite(song.video_id);
    return `<div class="result" data-vid="${song.video_id}">
      <img src="${song.thumbnail || ''}" alt="">
      <div class="meta">
        <div class="t">${esc(song.title)}</div>
        <div class="s">${esc(song.channel || '')}${song.duration_sec ? ' · ' + Q.fmtTime(song.duration_sec) : ''}${song.is_karaoke ? ' <span class="badge badge-karaoke">karaoke</span>' : ''}</div>
      </div>
      <div class="acts">
        <button class="icon-btn fav-btn ${isFav ? 'fav-on' : ''}" title="Save to favorites">⭐</button>
        <button class="icon-btn add" title="Add to queue">＋</button>
      </div>
    </div>`;
  }
  function bindRow(row) {
    const vid = row.dataset.vid;
    const title = row.querySelector('.t').textContent;
    const thumb = row.querySelector('img').src;
    row.querySelector('.add').addEventListener('click', async () => {
      try {
        await Q.api('queue.php', { room_id: ROOM, guest_id: GUEST, video_id: vid, title, thumbnail: thumb });
        Q.toast('Added to queue 🎶', 'success', 1600);
      } catch {}
    });
    row.querySelector('.fav-btn').addEventListener('click', () => {
      if (Q.isFavorite(vid)) { Q.removeFavorite(vid); Q.toast('Removed from favorites'); }
      else { Q.addFavorite({ video_id: vid, title, thumbnail: thumb }); Q.toast('Saved to favorites ⭐', 'success', 1600); }
      row.querySelector('.fav-btn').classList.toggle('fav-on');
    });
  }

  // ---------- Search ----------
  const searchInput = document.getElementById('search-input');
  const doSearch = Q.debounce(async (q) => {
    if (!q.trim()) { document.getElementById('search-results').innerHTML = '<div class="muted small">Type to search millions of karaoke tracks.</div>'; return; }
    document.getElementById('search-results').innerHTML = '<div class="muted small">Searching...</div>';
    try {
      const r = await Q.api('search.php?q=' + encodeURIComponent(q));
      const host = document.getElementById('search-results');
      if (!r.songs.length) { host.innerHTML = '<div class="muted small">No results.</div>'; return; }
      host.innerHTML = r.songs.map(s => songRow(s)).join('');
      host.querySelectorAll('.result').forEach(bindRow);
    } catch {}
  }, 350);
  searchInput.addEventListener('input', (e) => doSearch(e.target.value));

  // ---------- Queue view ----------
  function renderQueue() {
    const np = document.getElementById('now-playing');
    if (nowPlaying) {
      np.innerHTML = `<div class="qitem playing">
        <div class="pos">▶</div><img src="${nowPlaying.thumbnail || ''}">
        <div class="meta"><div class="t">${esc(nowPlaying.title)}</div><div class="s">🎤 ${esc(nowPlaying.nickname)}</div></div></div>`;
    } else { np.innerHTML = '<div class="muted small">Nothing playing yet.</div>'; }

    const up = document.getElementById('upcoming');
    const upcoming = queue.filter(s => s.status === 'queued');
    if (!upcoming.length) { up.innerHTML = '<div class="muted small">Queue is empty. Go search and add some songs!</div>'; return; }
    up.innerHTML = upcoming.map((s, i) => `<div class="qitem" data-id="${s.id}">
      <div class="pos">${i + 1}</div><img src="${s.thumbnail || ''}">
      <div class="meta"><div class="t">${esc(s.title)}</div><div class="s">🎤 ${esc(s.nickname)}</div></div>
      <button class="vote-btn" data-voted="false">👍 ${s.votes || 0}</button>
    </div>`).join('');
    up.querySelectorAll('.vote-btn').forEach(b => {
      b.addEventListener('click', async () => {
        if (b.dataset.voted === 'true') return;
        const id = parseInt(b.closest('.qitem').dataset.id, 10);
        try { await Q.api('vote.php', { room_id: ROOM, guest_id: GUEST, song_id: id }); b.dataset.voted = 'true'; b.classList.add('voted'); }
        catch {}
      });
    });
  }

  // ---------- AI DJ ----------
  document.querySelectorAll('.mood').forEach(m => {
    m.addEventListener('click', async () => {
      document.querySelectorAll('.mood').forEach(x => x.classList.remove('active'));
      m.classList.add('active');
      const host = document.getElementById('dj-results');
      host.innerHTML = '<div class="muted small">🤖 DJ is thinking...</div>';
      try {
        const r = await Q.api('dj.php', { mood: m.dataset.mood });
        // For each suggestion, search YouTube to get a real video id + thumbnail.
        const rows = [];
        for (const s of r.suggestions.slice(0, 6)) {
          try {
            const sr = await Q.api('search.php?q=' + encodeURIComponent(s.title + ' ' + (s.artist || '')));
            if (sr.songs[0]) rows.push(songRow(sr.songs[0]));
          } catch {}
        }
        host.innerHTML = rows.length ? rows.join('') : '<div class="muted small">No results.</div>';
        host.querySelectorAll('.result').forEach(bindRow);
      } catch {}
    });
  });

  // ---------- Favorites ----------
  function renderFavs() {
    const list = Q.getFavorites();
    const host = document.getElementById('fav-list');
    if (!list.length) { host.innerHTML = '<div class="muted small">No favorites yet. Tap the ⭐ on a song to save it.</div>'; return; }
    host.innerHTML = list.map(s => `<div class="result" data-vid="${s.video_id}">
      <img src="${s.thumbnail || ''}" alt="">
      <div class="meta"><div class="t">${esc(s.title)}</div><div class="s">Saved on this phone</div></div>
      <div class="acts">
        <button class="icon-btn fav-btn fav-on" title="Remove">✕</button>
        <button class="icon-btn add" title="Add to queue">＋</button>
      </div></div>`).join('');
    host.querySelectorAll('.result').forEach(row => {
      const vid = row.dataset.vid, title = row.querySelector('.t').textContent, thumb = row.querySelector('img').src;
      row.querySelector('.add').addEventListener('click', async () => {
        try { await Q.api('queue.php', { room_id: ROOM, guest_id: GUEST, video_id: vid, title, thumbnail: thumb }); Q.toast('Added to queue 🎶', 'success', 1600); } catch {}
      });
      row.querySelector('.fav-btn').addEventListener('click', () => { Q.removeFavorite(vid); renderFavs(); });
    });
  }

  // ---------- Soundboard ----------
  document.querySelectorAll('.sfx').forEach(b => {
    b.addEventListener('click', async () => {
      try { await Q.api('react.php', { room_id: ROOM, guest_id: GUEST, reaction: b.dataset.r }); b.style.transform = 'scale(0.9)'; setTimeout(() => b.style.transform = '', 120); }
      catch {}
    });
  });

  // ---------- Pusher bindings ----------
  Q.bind(ROOM, 'guest-decision', (d) => {
    if (d.guest_id === GUEST) setApproved(d.decision === 'approved');
  });
  Q.bind(ROOM, 'song-added', (d) => { if (!queue.find(s => s.id === d.id)) queue.push(d); renderQueue(); });
  Q.bind(ROOM, 'song-skipped', () => { queue = queue.filter(s => s.status !== 'playing'); nowPlaying = null; renderQueue(); });
  Q.bind(ROOM, 'song-voted', (d) => { const s = queue.find(x => x.id === d.song_id); if (s) { s.votes = d.votes; renderQueue(); } });
  Q.bind(ROOM, 'queue-reordered', (d) => {
    const order = d.ordered_ids || [];
    queue.sort((a, b) => { const ia = order.indexOf(a.id), ib = order.indexOf(b.id); if (ia === -1) return 1; if (ib === -1) return -1; return ia - ib; });
    renderQueue();
  });
  Q.bind(ROOM, 'now-playing', (d) => {
    if (d.song) {
      nowPlaying = d.song;
      queue.forEach(s => s.status = (s.id === d.song.id) ? 'playing' : (s.status === 'playing' ? 'queued' : s.status));
    } else {
      nowPlaying = null;
      queue = queue.filter(s => s.status !== 'playing');
    }
    renderQueue();
  });

  // ---------- Initial state ----------
  (async function init() {
    try {
      const s = await Q.api('state.php?room_id=' + ROOM);
      queue = s.queue || [];
      nowPlaying = queue.find(x => x.status === 'playing') || null;
      renderQueue();
    } catch {}
  })();
})();
