// Editor for pages and blog posts: Markdown toolbar, slug helper, search-result preview,
// character counters, SEO checklist and heading outline. Runs only on the editor screen.
(function () {
  var form = document.getElementById('editor'); if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var f = { title: $('f_title'), slug: $('f_slug'), body: $('f_body'), excerpt: $('f_excerpt'), mt: $('f_mt'), md: $('f_md'), h1: $('f_h1'),
            kw: $('f_kw'), feat: $('f_feat'), featalt: $('f_featalt'), og: $('f_og'), canon: $('f_canon'), status: $('f_status') };
  var isPost = form.dataset.type === 'post', site = form.dataset.site, siteName = form.dataset.sitename, pretty = form.dataset.pretty === '1';
  var tpl = form.dataset.titleTpl || '%title% | %site%';
  var slugTouched = form.dataset.new !== '1' || f.slug.value !== '';

  function slugify(s) {
    s = s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || '';
  }
  function words(s) { return (s.match(/[\p{L}\p{N}']+/gu) || []).length; }
  function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function cut(s, n) { s = s.replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n - 1).replace(/\s+\S*$/, '') + '…' : s; }

  // ---- Markdown toolbar -------------------------------------------------------------
  function surround(a, b, ph) {
    var t = f.body, s = t.selectionStart, e = t.selectionEnd, sel = t.value.slice(s, e) || ph;
    t.value = t.value.slice(0, s) + a + sel + b + t.value.slice(e);
    t.focus(); t.setSelectionRange(s + a.length, s + a.length + sel.length); update();
  }
  function linePrefix(prefix, numbered) {
    var t = f.body, s = t.selectionStart, e = t.selectionEnd, v = t.value;
    var ls = v.lastIndexOf('\n', s - 1) + 1, le = v.indexOf('\n', e); if (le < 0) le = v.length;
    var n = 0, block = v.slice(ls, le).split('\n').map(function (l) { n++; return (numbered ? n + '. ' : prefix) + l.replace(/^(#{1,6}\s+|[-*+]\s+|\d+[.)]\s+|>\s?)/, ''); }).join('\n');
    t.value = v.slice(0, ls) + block + v.slice(le); t.focus(); t.setSelectionRange(ls, ls + block.length); update();
  }
  var actions = {
    h2: function () { linePrefix('## '); }, h3: function () { linePrefix('### '); }, h4: function () { linePrefix('#### '); },
    b: function () { surround('**', '**', 'bold text'); }, i: function () { surround('*', '*', 'italic text'); },
    link: function () { surround('[', '](https://)', 'link text'); }, img: function () { surround('![', '](https://)', 'describe the image'); },
    ul: function () { linePrefix('- '); }, ol: function () { linePrefix('', true); }, quote: function () { linePrefix('> '); },
    code: function () { surround('`', '`', 'code'); }
  };
  $('mdbar').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-md]'); if (b) actions[b.dataset.md]();
  });

  // ---- Slug and address preview -----------------------------------------------------
  function slugVal() { return f.slug.value.trim() || slugify(f.title.value); }
  function address() {
    var s = slugVal() || 'your-slug';
    return pretty ? site + '/' + (isPost ? 'blog/' : '') + s : site + '/?p=' + (isPost ? 'article' : 'cms') + '&slug=' + s;
  }
  f.slug.addEventListener('input', function () { slugTouched = true; update(); });
  f.title.addEventListener('input', function () { if (!slugTouched) f.slug.value = slugify(f.title.value); update(); });
  $('slug_gen').addEventListener('click', function () { f.slug.value = slugify(f.title.value); slugTouched = true; update(); });

  // ---- Markdown analysis (mirrors the server rules) ---------------------------------
  function analyse(md) {
    var out = { headings: [], words: 0, imgs: 0, noAlt: 0, linksIn: 0, linksOut: 0, nofollow: 0, firstP: '', text: '' };
    var inCode = false, para = [], plain = [];
    md.split(/\r?\n/).forEach(function (l) {
      if (/^```/.test(l)) { inCode = !inCode; return; }
      if (inCode) return;
      var m = l.match(/^(#{1,6})\s+(.*?)\s*#*\s*$/);
      if (m) { out.headings.push({ level: m[1].length, text: m[2].replace(/\{#[^}]*\}\s*$/, '').replace(/[*_`]/g, '').trim() }); if (!out.firstP && para.length) out.firstP = para.join(' '); para = []; return; }
      if (l.trim() === '') { if (!out.firstP && para.length) out.firstP = para.join(' '); para = []; return; }
      if (!/^\s*([-*+]|\d+[.)])\s|^\s*>|^\s*\|/.test(l)) para.push(l.trim());
      plain.push(l);
    });
    if (!out.firstP && para.length) out.firstP = para.join(' ');
    var body = md.replace(/```[\s\S]*?```/g, '');
    (body.match(/!\[([^\]]*)\]\([^)]*\)/g) || []).forEach(function (x) { out.imgs++; if (/^!\[\s*\]/.test(x)) out.noAlt++; });
    (body.replace(/!\[[^\]]*\]\([^)]*\)/g, '').match(/\[[^\]]+\]\(([^)\s]+)[^)]*\)(\{[^}]*\})?/g) || []).forEach(function (x) {
      var url = (x.match(/\]\(([^)\s]+)/) || [])[1] || '';
      var ext = /^https?:\/\//i.test(url) && url.indexOf(site.replace(/^https?:\/\/(www\.)?/, '')) < 0;
      ext ? out.linksOut++ : out.linksIn++; if (/\{[^}]*nofollow/.test(x)) out.nofollow++;
    });
    out.text = body.replace(/[#>*_`\[\]()!-]/g, ' ');
    out.words = words(out.text);
    out.firstP = out.firstP.replace(/[*_`#>]|\[|\]\([^)]*\)/g, '').trim();
    return out;
  }

  // ---- Counters, preview, checklist, outline ----------------------------------------
  function counter(id, len, lo, hi) {
    var c = $(id); c.textContent = len + ' characters';
    c.className = 'counter ' + (len === 0 ? '' : len > hi ? 'bad' : len < lo ? 'warn' : 'ok');
  }
  function effTitle() {
    var t = f.title.value.trim(), mt = f.mt.value.trim();
    return mt ? mt.replace(/%title%/g, t).replace(/%site%/g, siteName) : tpl.replace(/%title%/g, t || 'Untitled').replace(/%site%/g, siteName);
  }
  function check(list, level, text) { list.push({ level: level, text: text }); }

  function update() {
    var a = analyse(f.body.value);
    var title = f.title.value.trim(), h1 = f.h1.value.trim() || title;
    var et = effTitle(), desc = f.md.value.trim() || f.excerpt.value.trim() || a.firstP;
    // address + SERP
    $('url_prev').textContent = address();
    $('s_url').textContent = site.replace(/^https?:\/\//, '') + ' › ' + (pretty ? (isPost ? 'blog › ' : '') + (slugVal() || 'your-slug') : '…');
    $('s_title').textContent = cut(et, 62);
    $('s_desc').textContent = desc ? cut(desc, 160) : 'No description yet. Search engines will pick text from the page.';
    counter('c_mt', et.length, 30, 60); counter('c_md', f.md.value.trim().length, 70, 160); counter('c_h1', h1.length, 10, 70);
    $('wordcount').textContent = a.words + ' words';

    // outline
    var ol = $('outline'); ol.innerHTML = '';
    var prev = 1, rows = [{ level: 1, text: h1 || '(no title)', page: true }].concat(a.headings), seenText = {};
    rows.forEach(function (hd, idx) {
      var li = document.createElement('li'), warn = '';
      li.className = 'lv' + hd.level;
      if (idx > 0) {
        if (hd.level === 1) warn = 'Extra H1. The page heading is already an H1.';
        else if (hd.level > prev + 1) warn = 'Skips from H' + prev + ' to H' + hd.level + '.';
        if (!hd.text) warn = 'Empty heading.';
        else if (seenText[hd.text.toLowerCase()]) warn = 'Duplicate heading.';
        seenText[hd.text.toLowerCase()] = 1; prev = hd.level;
      }
      li.innerHTML = '<span class="tag">H' + hd.level + '</span> ' + esc(hd.text || '') + (warn ? ' <em class="warn">' + warn + '</em>' : '');
      ol.appendChild(li);
    });

    // checklist
    var L = [], kw = f.kw.value.trim().toLowerCase(), slug = slugVal();
    var tl = et.length;
    check(L, tl === 0 ? 'bad' : tl > 60 ? 'bad' : tl < 30 ? 'warn' : 'ok', 'Title tag is ' + tl + ' characters' + (tl > 60 ? ' (may be cut off)' : tl < 30 ? ' (a little short)' : ' (good length)'));
    var dl = f.md.value.trim().length;
    check(L, dl === 0 ? 'bad' : dl > 160 ? 'warn' : dl < 70 ? 'warn' : 'ok', dl === 0 ? 'No meta description. Write one of 70 to 160 characters.' : 'Meta description is ' + dl + ' characters' + (dl > 160 ? ' (may be cut off)' : dl < 70 ? ' (a little short)' : ' (good length)'));
    check(L, h1 ? 'ok' : 'bad', h1 ? 'The page has one H1: "' + cut(h1, 50) + '"' : 'No H1. Add a title.');
    var extraH1 = a.headings.filter(function (x) { return x.level === 1; }).length;
    if (extraH1) check(L, 'bad', 'The body has ' + extraH1 + ' extra H1. Use ## for sections.');
    var h2 = a.headings.filter(function (x) { return x.level === 2; }).length;
    check(L, h2 ? 'ok' : (a.words > 300 ? 'warn' : 'info'), h2 ? h2 + ' H2 section' + (h2 > 1 ? 's' : '') : 'No H2 headings. Break longer text into sections.');
    var skip = false, p2 = 1; a.headings.forEach(function (x) { if (x.level > p2 + 1) skip = true; p2 = x.level; });
    if (skip) check(L, 'bad', 'Heading levels are skipped. Go H2, then H3, then H4 in order.');
    check(L, slug.length === 0 ? 'bad' : slug.length > 75 ? 'warn' : 'ok', slug ? 'Slug "' + slug + '"' + (slug.length > 75 ? ' is long' : '') : 'No slug yet');
    var minW = isPost ? 300 : 150;
    check(L, a.words >= minW ? 'ok' : 'warn', a.words + ' words' + (a.words >= minW ? '' : '. Aim for at least ' + minW + '.'));
    if (a.imgs) check(L, a.noAlt ? 'bad' : 'ok', a.imgs + ' image' + (a.imgs > 1 ? 's' : '') + (a.noAlt ? ', ' + a.noAlt + ' without alt text' : ' with alt text'));
    if (f.feat.value.trim() && !f.featalt.value.trim()) check(L, 'bad', 'Featured image has no alt text.');
    check(L, a.linksIn ? 'ok' : 'warn', a.linksIn ? a.linksIn + ' internal link' + (a.linksIn > 1 ? 's' : '') : 'No internal links. Link to related pages on your site.');
    if (a.linksOut) check(L, 'info', a.linksOut + ' external link' + (a.linksOut > 1 ? 's' : '') + (a.nofollow ? ' (' + a.nofollow + ' nofollow)' : ''));
    if (!f.og.value.trim() && !f.feat.value.trim()) check(L, 'info', 'No share image set. A default may apply from SEO settings.');
    if (form.elements.robots_index.value === '0') check(L, 'info', 'Set to noindex: search engines will not list this.');
    if (f.canon.value.trim()) check(L, 'info', 'Custom canonical points to ' + cut(f.canon.value.trim(), 50));
    if (kw) {
      var inT = function (s) { return s.toLowerCase().indexOf(kw) >= 0; };
      check(L, inT(et) ? 'ok' : 'warn', inT(et) ? 'Keyword is in the title tag' : 'Keyword is not in the title tag');
      check(L, inT(h1) ? 'ok' : 'warn', inT(h1) ? 'Keyword is in the H1' : 'Keyword is not in the H1');
      check(L, slug.indexOf(slugify(kw)) >= 0 ? 'ok' : 'warn', slug.indexOf(slugify(kw)) >= 0 ? 'Keyword is in the slug' : 'Keyword is not in the slug');
      check(L, inT(f.md.value) ? 'ok' : 'warn', inT(f.md.value) ? 'Keyword is in the meta description' : 'Keyword is not in the meta description');
      check(L, inT(a.firstP) ? 'ok' : 'warn', inT(a.firstP) ? 'Keyword is in the first paragraph' : 'Keyword is not in the first paragraph');
      check(L, a.headings.some(function (x) { return x.level >= 2 && inT(x.text); }) ? 'ok' : 'warn', a.headings.some(function (x) { return x.level >= 2 && inT(x.text); }) ? 'Keyword is in a subheading' : 'Keyword is not in any H2 to H4');
      var hits = (a.text.toLowerCase().split(kw).length - 1), dens = a.words ? hits * kw.split(/\s+/).length / a.words * 100 : 0;
      check(L, dens > 3 ? 'warn' : hits ? 'ok' : 'warn', 'Keyword appears ' + hits + ' time' + (hits === 1 ? '' : 's') + ' (' + dens.toFixed(1) + '%)' + (dens > 3 ? ', which may look stuffed' : ''));
    }
    var ul = $('checks'); ul.innerHTML = '';
    L.forEach(function (c) { var li = document.createElement('li'); li.className = c.level; li.textContent = c.text; ul.appendChild(li); });
  }

  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
  window.addEventListener('beforeunload', function (e) { if (form.dataset.dirty === '1') { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('input', function () { form.dataset.dirty = '1'; });
  form.addEventListener('submit', function () { form.dataset.dirty = '0'; });
})();
