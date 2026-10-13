// Optional: include this script once on your page and Wuphf embeds (iframes with class "wuphf-embed") resize to fit.
(function () {
  addEventListener('message', function (e) {
    var d = e.data; if (!d || d.wuphf !== 'h') return;
    var fs = document.querySelectorAll('iframe.wuphf-embed');
    for (var i = 0; i < fs.length; i++) if (fs[i].contentWindow === e.source) fs[i].style.height = (d.h + 4) + 'px';
  });
})();
