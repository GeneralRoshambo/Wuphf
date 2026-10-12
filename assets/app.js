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
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-share]') : null; if (!b) return;
  var url = b.dataset.share;
  if (navigator.share) { navigator.share({ title: document.title, text: b.dataset.text, url: url }).catch(function () {}); return; }
  copyText(url).then(function () { toast('Link copied'); }, function () { toast(url); });
});

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
