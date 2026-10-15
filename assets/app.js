(function () {
  // Light / dark toggle. Starts from the system setting; the choice is remembered.
  var btn = document.getElementById('theme'); if (!btn) return;
  var root = document.documentElement;
  var dark = function () {
    return root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  };
  btn.addEventListener('click', function () {
    var next = dark() ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('wuphf-theme', next); } catch (e) {}
  });
})();

(function () {
  // Compose box: live counter (first N @usernames are free, like the server),
  // Enter sends, and a cooldown after each post.
  var t = document.getElementById('body'), c = document.getElementById('count');
  if (t && c) {
    var cform = t.form, btn = document.getElementById('sendbtn'), label = btn.dataset.label;
    var max = +c.dataset.max, free = +c.dataset.free, re = /(?<!\w)@[A-Za-z0-9_]{3,15}(?!\w) ?/g;
    var cd = +c.dataset.cd, timer = null;
    var upd = function () {
      var s = t.value.replace(/\s+/g, ' ').trim(), ex = 0;
      (s.match(re) || []).slice(0, free).forEach(function (m) { ex += m.length; });
      var n = max - (Array.from(s).length - ex);
      c.textContent = n; c.className = n < 0 ? 'neg' : n < 20 ? 'warn' : '';
    };
    var tick = function () {
      if (cd > 0) { btn.disabled = true; btn.textContent = 'Wait ' + cd + 's'; cd--; timer = setTimeout(tick, 1000); }
      else { btn.disabled = false; btn.textContent = label; }
    };
    t.addEventListener('input', upd); upd(); tick();
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.shiftKey || e.isComposing) return;
      e.preventDefault();                       // posts are one line, so Enter always means send
      if (cd > 0 || btn.disabled || !t.value.trim()) return;
      cform.requestSubmit();
    });
    cform.addEventListener('submit', function (e) {
      if (cd > 0) { e.preventDefault(); return; }
      btn.disabled = true; btn.textContent = 'Sending\u2026';
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { clearTimeout(timer); cd = 0; tick(); } });
    if (location.hash === '#reply') { t.focus(); t.setSelectionRange(t.value.length, t.value.length); }
  }

  // Profile settings: instant photo preview and a visible "Saving..." state.
  var form = document.querySelector('form[data-saving]');
  if (form) {
    var photo = document.getElementById('photo'), prev = document.getElementById('avprev'), btn = document.getElementById('savebtn');
    var crop = null;   // {img, x, y, z, base} while a JPEG/PNG is being framed
    function stopCrop() { var c = document.getElementById('cropper'); if (c) c.remove(); crop = null; }
    function startCrop(f) {
      stopCrop();
      var url = URL.createObjectURL(f), im = new Image();
      im.onload = function () {
        var S = 240, box = document.createElement('div'); box.id = 'cropper'; box.className = 'cropper';
        box.innerHTML = '<div class="cropv" style="width:' + S + 'px;height:' + S + 'px"></div>'
          + '<input type="range" class="cropz" min="1" max="4" step="0.01" value="1" aria-label="Zoom">'
          + '<span class="hint">Drag to position, slide to zoom. This is how your photo will be cut.</span>';
        prev.parentNode.insertBefore(box, prev.nextSibling);
        var v = box.querySelector('.cropv'), z = box.querySelector('.cropz');
        im.className = 'cropimg'; im.draggable = false; v.appendChild(im);
        var base = S / Math.min(im.naturalWidth, im.naturalHeight);
        crop = { img: im, S: S, base: base, z: 1, x: 0, y: 0 };
        function place() {
          var s = base * crop.z, w = im.naturalWidth * s, h = im.naturalHeight * s;
          crop.x = Math.min(0, Math.max(S - w, crop.x)); crop.y = Math.min(0, Math.max(S - h, crop.y));
          im.style.width = w + 'px'; im.style.height = h + 'px'; im.style.transform = 'translate(' + crop.x + 'px,' + crop.y + 'px)';
        }
        crop.x = (S - im.naturalWidth * base) / 2; crop.y = (S - im.naturalHeight * base) / 2; place();
        z.addEventListener('input', function () {
          var old = base * crop.z, cx = (S / 2 - crop.x) / old, cy = (S / 2 - crop.y) / old;   // keep the centre fixed
          crop.z = parseFloat(z.value); var s = base * crop.z; crop.x = S / 2 - cx * s; crop.y = S / 2 - cy * s; place();
        });
        var drag = null;
        v.addEventListener('pointerdown', function (e) { drag = { x: e.clientX - crop.x, y: e.clientY - crop.y }; v.setPointerCapture(e.pointerId); });
        v.addEventListener('pointermove', function (e) { if (drag) { crop.x = e.clientX - drag.x; crop.y = e.clientY - drag.y; place(); } });
        v.addEventListener('pointerup', function () { drag = null; });
        v.addEventListener('pointercancel', function () { drag = null; });
      };
      im.src = url;
    }
    // Replace the chosen file with the framed square before the form goes out.
    function applyCrop(done) {
      if (!crop) return done();
      var S = crop.S, s = crop.base * crop.z, out = 512, cv = document.createElement('canvas'); cv.width = cv.height = out;
      var cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, out, out);
      cx.drawImage(crop.img, -crop.x / s, -crop.y / s, S / s, S / s, 0, 0, out, out);
      cv.toBlob(function (b) {
        try { var dt = new DataTransfer(); dt.items.add(new File([b], 'photo.jpg', { type: 'image/jpeg' })); photo.files = dt.files; } catch (e) {}
        done();
      }, 'image/jpeg', 0.9);
    }
    photo.addEventListener('change', function () {
      var f = photo.files[0]; stopCrop(); if (!f) return;
      if (/^image\/(jpeg|png)$/.test(f.type) && window.DataTransfer && window.HTMLCanvasElement) {
        prev.hidden = true; startCrop(f); return;
      }
      prev.hidden = false;   // GIFs keep their animation, so they are used as they are
      var img = new Image(); img.className = 'av'; img.width = img.height = 96; img.alt = '';
      img.src = URL.createObjectURL(f); prev.innerHTML = ''; prev.appendChild(img);
    });
    form.addEventListener('submit', function (e) {
      if (crop && !form.dataset.cropped) {
        e.preventDefault(); btn.disabled = true; btn.textContent = 'Saving\u2026'; form.classList.add('busy');
        applyCrop(function () { form.dataset.cropped = '1'; form.submit(); });
        return;
      }
      btn.disabled = true; btn.textContent = 'Saving\u2026'; form.classList.add('busy');
    });
    window.addEventListener('pageshow', function () { btn.disabled = false; btn.textContent = 'Save'; form.classList.remove('busy'); delete form.dataset.cropped; });
  }
})();

if ('serviceWorker' in navigator && window.isSecureContext) {
  window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js'); });
}

// Follow buttons beside posts: follow in place, then drop every button for that person.
document.addEventListener('submit', function (e) {
  var f = e.target.closest ? e.target.closest('form.folf') : null;
  if (!f || !window.fetch) return;
  e.preventDefault();
  var uid = f.dataset.uid, b = f.querySelector('button'), data = new FormData(f);
  data.append('ajax', '1'); b.disabled = true; b.textContent = 'Following';
  fetch(f.action, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
    if (!r.ok) throw 0;
    document.querySelectorAll('form.folf[data-uid="' + uid + '"]').forEach(function (x) { x.remove(); });
  }).catch(function () { b.disabled = false; b.textContent = 'Follow'; });
});

// Like, dislike and repost: update in place from the server's counts.
document.addEventListener('submit', function (e) {
  var f = e.target.closest ? e.target.closest('form.actf') : null;
  if (!f || !window.fetch) return;
  e.preventDefault();
  var data = new FormData(f); data.append('ajax', '1');
  fetch(f.action, { method: 'POST', body: data, credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) throw 0; return r.json(); })
    .then(function (s) {
      document.querySelectorAll('button.act[data-pid="' + s.pid + '"]').forEach(function (b) {
        var k = b.dataset.kind;
        var n = k === 'like' ? s.likes : k === 'dislike' ? s.dislikes : s.reposts;
        var on = k === 'like' ? s.mine === 1 : k === 'dislike' ? s.mine === -1 : s.reposted;
        b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
        b.querySelector('.n').textContent = n || '';
      });
    })
    .catch(function () { location.reload(); });   // shows the server's message, e.g. "confirm your email"
});

// Share: the device's share sheet where there is one, otherwise copy the link.
function toast(m) {
  var t = document.getElementById('toast'); if (!t) return;
  t.textContent = m; t.classList.add('show');
  clearTimeout(toast.t); toast.t = setTimeout(function () { t.classList.remove('show'); }, 2200);
}
function copyText(t) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(t);
  return new Promise(function (ok, no) {
    var a = document.createElement('textarea'); a.value = t; a.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(a); a.select();
    try { document.execCommand('copy') ? ok() : no(); } catch (x) { no(); }
    a.remove();
  });
}
// Share sheet: link, embed code, and a downloadable picture of the post.
(function () {
  var sheet = null, cv, ctx, st = { style: 'light', shape: 'wide' }, cur = null, avImg = null;
  var STYLES = {
    light:  { name: 'Light',  bg: ['#eef2ff', '#dbe6ff'], card: '#ffffff', fg: '#0e0e10', mute: '#6c6c75', accent: '#1d5bff', line: 'rgba(14,14,16,.08)' },
    dark:   { name: 'Dark',   bg: ['#0b0b0d', '#1a1a22'], card: '#17171b', fg: '#f4f4f5', mute: '#9a9aa5', accent: '#6f9bff', line: 'rgba(255,255,255,.09)' },
    blue:   { name: 'Blue',   bg: ['#1d5bff', '#0a2fa8'], card: 'rgba(255,255,255,.12)', fg: '#ffffff', mute: 'rgba(255,255,255,.72)', accent: '#ffffff', line: 'rgba(255,255,255,.22)' },
    sunset: { name: 'Sunset', bg: ['#ff8a5c', '#c2328f'], card: 'rgba(255,255,255,.16)', fg: '#ffffff', mute: 'rgba(255,255,255,.78)', accent: '#fff3c4', line: 'rgba(255,255,255,.26)' }
  };
  var FONT = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';

  function rr(c, x, y, w, h, r) { c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); }

  function layout(c, text, maxW, maxH, size) {   // wrap into coloured word runs, shrinking the type until it fits
    for (; size >= 22; size -= 2) {
      c.font = '600 ' + size + 'px ' + FONT;
      var lines = [], line = [], w = 0, space = c.measureText(' ').width;
      text.split('\n').forEach(function (para, pi) {
        if (pi) { lines.push(line); line = []; w = 0; }
        para.split(/\s+/).filter(Boolean).forEach(function (word) {
          var ww = c.measureText(word).width;
          if (w && w + space + ww > maxW) { lines.push(line); line = []; w = 0; }
          line.push({ t: word, w: ww, tag: /^[@#]/.test(word) }); w += (w ? space : 0) + ww;
        });
      });
      lines.push(line);
      if (lines.length * size * 1.32 <= maxH) return { lines: lines, size: size, space: space };
    }
    return { lines: lines, size: 22, space: space };
  }

  function draw() {
    if (!cur) return;
    var S = STYLES[st.style], wide = st.shape === 'wide', W = wide ? 1200 : 1080, H = wide ? 630 : 1080, k = 2;
    cv.width = W * k; cv.height = H * k; ctx = cv.getContext('2d'); ctx.setTransform(k, 0, 0, k, 0, 0);
    var g = ctx.createLinearGradient(0, 0, W, H); g.addColorStop(0, S.bg[0]); g.addColorStop(1, S.bg[1]); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    // soft glow
    var rg = ctx.createRadialGradient(W * .85, H * .1, 10, W * .85, H * .1, W * .6); rg.addColorStop(0, 'rgba(255,255,255,.22)'); rg.addColorStop(1, 'rgba(255,255,255,0)'); ctx.fillStyle = rg; ctx.fillRect(0, 0, W, H);
    var m = wide ? 56 : 64, cx = m, cy = m, cw = W - 2 * m, ch = H - 2 * m;
    ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.22)'; ctx.shadowBlur = 40; ctx.shadowOffsetY = 16;
    rr(ctx, cx, cy, cw, ch, 36); ctx.fillStyle = S.card; ctx.fill(); ctx.restore();
    rr(ctx, cx + .5, cy + .5, cw - 1, ch - 1, 36); ctx.strokeStyle = S.line; ctx.lineWidth = 1.5; ctx.stroke();
    var pad = wide ? 48 : 56, x = cx + pad, y = cy + pad, av = 76;
    // avatar
    ctx.save(); ctx.beginPath(); ctx.arc(x + av / 2, y + av / 2, av / 2, 0, 7); ctx.clip();
    if (avImg && avImg.naturalWidth) ctx.drawImage(avImg, x, y, av, av);
    else { ctx.fillStyle = 'hsl(' + (cur.hue) + ',45%,45%)'; ctx.fillRect(x, y, av, av); ctx.fillStyle = '#fff'; ctx.font = '700 38px ' + FONT; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(cur.user[0].toUpperCase(), x + av / 2, y + av / 2 + 2); }
    ctx.restore(); ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = S.fg; ctx.font = '800 32px ' + FONT; ctx.fillText('@' + cur.user, x + av + 20, y + 34);
    ctx.fillStyle = S.mute; ctx.font = '500 24px ' + FONT; ctx.fillText(cur.when, x + av + 20, y + 68);
    // quote mark
    ctx.fillStyle = S.accent; ctx.globalAlpha = .18; ctx.font = '900 150px Georgia,serif'; ctx.textAlign = 'right'; ctx.fillText('”', cx + cw - pad + 10, y + 100); ctx.globalAlpha = 1; ctx.textAlign = 'left';
    // body
    var top = y + av + 28, foot = 64, maxH = cy + ch - pad - foot - top, maxW = cw - 2 * pad;
    var L = layout(ctx, cur.body, maxW, maxH, wide ? 60 : 72), lh = L.size * 1.32, yy = top + L.size;
    ctx.font = '600 ' + L.size + 'px ' + FONT;
    L.lines.forEach(function (line, li) {
      var xx = x; line.forEach(function (wd) { ctx.fillStyle = wd.tag ? S.accent : S.fg; ctx.fillText(wd.t, xx, yy + li * lh); xx += wd.w + L.space; });
    });
    // footer: brand
    var fy = cy + ch - pad + 4;
    ctx.fillStyle = S.fg; ctx.font = '800 36px ' + FONT; ctx.fillText('Wuphf', x, fy);
    ctx.fillStyle = S.mute; ctx.font = '500 24px ' + FONT; ctx.textAlign = 'right'; ctx.fillText(location.host, cx + cw - pad, fy - 2); ctx.textAlign = 'left';
  }

  function build() {
    sheet = document.createElement('div'); sheet.id = 'sharesheet'; sheet.className = 'sheet'; sheet.hidden = true;
    sheet.innerHTML = '<div class="sheetbox" role="dialog" aria-modal="true" aria-label="Share this Wuphf">'
      + '<button type="button" class="sheetx" aria-label="Close">&times;</button><h2>Share this Wuphf</h2>'
      + '<canvas id="sharecv"></canvas>'
      + '<div class="chips" id="stylechips"></div>'
      + '<div class="chips" id="shapechips"><button type="button" data-shape="wide" class="on">Wide</button><button type="button" data-shape="square">Square</button></div>'
      + '<div class="sheetacts"><button type="button" class="btn" id="dlimg">Download image</button><button type="button" class="btn ghost" id="cplink">Copy link</button><button type="button" class="btn ghost" id="cpembed">Copy embed code</button><button type="button" class="btn ghost" id="nshare">Share…</button></div></div>';
    document.body.appendChild(sheet);
    cv = sheet.querySelector('#sharecv');
    var chips = sheet.querySelector('#stylechips');
    Object.keys(STYLES).forEach(function (k) { var b = document.createElement('button'); b.type = 'button'; b.dataset.style = k; b.textContent = STYLES[k].name; if (k === st.style) b.className = 'on'; chips.appendChild(b); });
    sheet.addEventListener('click', function (e) {
      var t = e.target;
      if (t === sheet || t.closest('.sheetx')) { close(); return; }
      if (t.dataset.style) { st.style = t.dataset.style; sel('#stylechips', 'style'); draw(); }
      else if (t.dataset.shape) { st.shape = t.dataset.shape; sel('#shapechips', 'shape'); draw(); }
      else if (t.id === 'dlimg') {
        draw(); cv.toBlob(function (b) { var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'wuphf-' + cur.user + '-' + cur.pid + '.png'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000); toast('Image saved'); }, 'image/png');
      }
      else if (t.id === 'cplink') copyText(cur.url).then(function () { toast('Link copied'); }, function () { toast(cur.url); });
      else if (t.id === 'cpembed') {
        var code = '<iframe class="wuphf-embed" src="' + cur.embed + '" width="550" height="230" style="border:0;max-width:100%" loading="lazy" title="Wuphf by @' + cur.user + '"></iframe>\n<script async src="' + cur.js + '"><\/script>';
        copyText(code).then(function () { toast('Embed code copied'); }, function () { toast('Could not copy'); });
      }
      else if (t.id === 'nshare') navigator.share({ title: document.title, text: cur.text, url: cur.url }).catch(function () {});
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) close(); });
    if (!navigator.share) sheet.querySelector('#nshare').remove();
  }
  function sel(id, key) { sheet.querySelectorAll(id + ' button').forEach(function (b) { b.classList.toggle('on', b.dataset[key] === st[key]); }); }
  function close() { sheet.hidden = true; document.body.classList.remove('noscroll'); }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-share]') : null; if (!b) return;
    var d = b.dataset;
    cur = { url: d.share, text: d.text, body: d.body || d.text, user: d.user || '', pid: d.pid, when: d.when || '', embed: d.embed, js: d.embedjs, hue: parseInt(d.hue, 10) || 0 };
    if (!cur.user || !HTMLCanvasElement.prototype.toBlob) {   // no data for a picture: fall back to the plain share
      if (navigator.share) { navigator.share({ title: document.title, text: d.text, url: d.share }).catch(function () {}); return; }
      copyText(d.share).then(function () { toast('Link copied'); }, function () { toast(d.share); }); return;
    }
    if (!sheet) build();
    sheet.hidden = false; document.body.classList.add('noscroll');
    avImg = null;
    if (d.av) { var im = new Image(); im.onload = function () { avImg = im; draw(); }; im.src = d.av; }
    draw();
    var fonts = document.fonts && document.fonts.ready; if (fonts) fonts.then(draw);
    sheet.querySelector('.btn').focus();
  });
})();

// Close an open three-dot menu when you click elsewhere or press Escape.
document.addEventListener('click', function (e) {
  document.querySelectorAll('details.menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') document.querySelectorAll('details.menu[open]').forEach(function (d) { d.removeAttribute('open'); });
});

// "Back": go back through your history when you arrived from another page of this site,
// otherwise the link's own address (the feed) is followed.
document.addEventListener('click', function (e) {
  var a = e.target.closest ? e.target.closest('a[data-back]') : null;
  if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
  try {
    var ref = new URL(document.referrer);
    if (ref.origin === location.origin && ref.href.split('#')[0] !== location.href.split('#')[0] && history.length > 1) {
      e.preventDefault(); history.back();
    }
  } catch (x) {}
});

// Permalink reply box: collapsed by default, opens from the Reply icon (#reply) or on click.
(function () {
  var d = document.getElementById('reply');
  if (!d || d.tagName !== 'DETAILS') return;
  function focusBox() { var t = d.querySelector('textarea'); if (t) { t.focus(); var n = t.value.length; try { t.setSelectionRange(n, n); } catch (e) {} } }
  function fromHash() { if (location.hash === '#reply') { d.open = true; focusBox(); } }
  d.addEventListener('toggle', function () { if (d.open) focusBox(); });
  window.addEventListener('hashchange', fromHash);
  fromHash();
})();

// Edit window: live countdown, then the edit box disappears. Enter saves, Escape cancels.
(function () {
  var boxes = document.querySelectorAll('details.editbox');
  if (!boxes.length) return;
  var t0 = Date.now();
  boxes.forEach(function (b) {
    var left = parseInt(b.getAttribute('data-left'), 10) || 0, cd = b.querySelector('.ecd'), ta = b.querySelector('textarea');
    var cancel = b.querySelector('.ecancel'), orig = ta.value, form = b.querySelector('form');
    function tick() {
      var r = left - Math.floor((Date.now() - t0) / 1000);
      if (r <= 0) { b.remove(); clearInterval(iv); return; }
      cd.textContent = Math.floor(r / 60) + ':' + ('0' + (r % 60)).slice(-2);
    }
    var iv = setInterval(tick, 1000); tick();
    b.addEventListener('toggle', function () { if (b.open) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); } });
    cancel.addEventListener('click', function () { ta.value = orig; b.open = false; });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
      else if (e.key === 'Escape') { ta.value = orig; b.open = false; }
    });
  });
})();

// @mention suggestions: type @ and a few letters in any post box, then pick with Enter, Tab or a click.
(function () {
  var pop = null, ta = null, items = [], idx = 0, timer = null, seq = 0, loggedIn = !!document.querySelector('a[href="?p=notifications"]');
  if (!loggedIn || !window.fetch) return;
  function token(t) {
    var before = t.value.slice(0, t.selectionStart), m = /(^|[^\w@])@([A-Za-z0-9_]{0,15})$/.exec(before);
    return m ? { q: m[2], start: before.length - m[2].length - 1 } : null;
  }
  function hide() { if (pop) pop.hidden = true; items = []; }
  function render() {
    if (!pop) { pop = document.createElement('div'); pop.className = 'mpop'; pop.setAttribute('role', 'listbox'); document.body.appendChild(pop);
      pop.addEventListener('mousedown', function (e) { var li = e.target.closest('[data-i]'); if (li) { e.preventDefault(); pick(+li.dataset.i); } }); }
    if (!items.length || !ta) { pop.hidden = true; return; }
    pop.innerHTML = ''; 
    items.forEach(function (u, n) {
      var li = document.createElement('div'); li.className = 'mi' + (n === idx ? ' on' : ''); li.dataset.i = n; li.setAttribute('role', 'option');
      var av = u.av ? '<img class="av" width="24" height="24" alt="" src="' + u.av + '">' : '<span class="av ph" style="width:24px;height:24px;line-height:24px;font-size:12px;background:hsl(' + u.hue + ',45%,45%)">' + u.u[0].toUpperCase() + '</span>';
      li.innerHTML = av + '<b></b>'; li.querySelector('b').textContent = '@' + u.u; pop.appendChild(li);
    });
    var r = ta.getBoundingClientRect(); pop.style.left = (r.left + window.scrollX) + 'px'; pop.style.top = (r.bottom + window.scrollY + 4) + 'px'; pop.style.minWidth = Math.min(240, r.width) + 'px'; pop.hidden = false;
  }
  function pick(n) {
    var u = items[n]; if (!u || !ta) return; var tk = token(ta); if (!tk) return hide();
    var after = ta.value.slice(ta.selectionStart);
    ta.value = ta.value.slice(0, tk.start) + '@' + u.u + ' ' + after.replace(/^[A-Za-z0-9_]*/, '');
    var pos = tk.start + u.u.length + 2; ta.setSelectionRange(pos, pos); ta.dispatchEvent(new Event('input', { bubbles: true })); hide(); ta.focus();
  }
  document.addEventListener('input', function (e) {
    var t = e.target; if (!t.matches || !t.matches('textarea[name=body], #body')) return;
    ta = t; var tk = token(t); clearTimeout(timer);
    if (!tk) return hide();
    var my = ++seq;
    timer = setTimeout(function () {
      fetch('?p=mentions&q=' + encodeURIComponent(tk.q), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : { users: [] }; }).then(function (d) {
        if (my !== seq || !token(ta)) return; items = d.users || []; idx = 0; render();
      }).catch(hide);
    }, 120);
  });
  // Capture phase so Enter picks a suggestion instead of sending the post.
  document.addEventListener('keydown', function (e) {
    if (!pop || pop.hidden || !items.length || e.target !== ta) return;
    if (e.key === 'ArrowDown') { idx = (idx + 1) % items.length; render(); }
    else if (e.key === 'ArrowUp') { idx = (idx + items.length - 1) % items.length; render(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { pick(idx); }
    else if (e.key === 'Escape') { hide(); }
    else return;
    e.preventDefault(); e.stopImmediatePropagation();
  }, true);
  document.addEventListener('click', function (e) { if (pop && !pop.contains(e.target) && e.target !== ta) hide(); });
  document.addEventListener('focusout', function (e) { if (e.target === ta) setTimeout(hide, 150); });
})();
