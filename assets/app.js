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
