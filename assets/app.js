(function () {
  // Live character counter. The first N @usernames are free, matching the server.
  var t = document.getElementById('body'), c = document.getElementById('count');
  if (t && c) {
    var max = +c.dataset.max, free = +c.dataset.free, re = /(?<!\w)@[A-Za-z0-9_]{3,15}(?!\w) ?/g;
    var upd = function () {
      var s = t.value.replace(/\s+/g, ' ').trim(), ex = 0;
      (s.match(re) || []).slice(0, free).forEach(function (m) { ex += m.length; });
      var n = max - (Array.from(s).length - ex);
      c.textContent = n; c.className = n < 0 ? 'neg' : n < 20 ? 'warn' : '';
    };
    t.addEventListener('input', upd); upd();
    t.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') t.form.requestSubmit();
    });
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
