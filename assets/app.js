(function () {
  var t = document.getElementById('body'), c = document.getElementById('count');
  if (!t || !c) return;
  var max = +c.dataset.max;
  function upd() {
    var n = max - Array.from(t.value.replace(/\s+/g, ' ')).length;
    c.textContent = n; c.className = n < 0 ? 'neg' : n < 20 ? 'warn' : '';
  }
  t.addEventListener('input', upd); upd();
  t.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') t.form.submit();
  });
})();
