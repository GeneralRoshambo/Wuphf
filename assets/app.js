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
    photo.addEventListener('change', function () {
      var f = photo.files[0]; if (!f) return;
      var img = new Image(); img.className = 'av'; img.width = img.height = 96; img.alt = '';
      img.src = URL.createObjectURL(f); prev.innerHTML = ''; prev.appendChild(img);
    });
    form.addEventListener('submit', function () {
      btn.disabled = true; btn.textContent = 'Saving\u2026'; form.classList.add('busy');
    });
    window.addEventListener('pageshow', function () { btn.disabled = false; btn.textContent = 'Save'; form.classList.remove('busy'); });
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
