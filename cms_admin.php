<?php
// Admin screens for pages, blog posts, SEO settings and redirects. Loaded from lib.php.

function admin_csrf(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }

function item_status(array $r): array {
    if ($r['status'] !== 'published') return ['Draft', 'pill'];
    return (int)$r['published_at'] > time() ? ['Scheduled', 'pill'] : ['Published', 'pill ok'];
}

/** A URL field must be empty, http(s), or start with "/". */
function url_field_ok(string $u): bool {
    return $u === '' || (bool)preg_match('~^(https?://[^\s]+|/[^\s]*|[A-Za-z0-9_./-]+)$~', $u);
}

function admin_content(array $u): void {
    $type = in_array($_GET['type'] ?? '', ['page', 'post'], true) ? $_GET['type'] : '';
    if (isset($_GET['new']) || isset($_GET['edit'])) {
        $row = isset($_GET['edit']) ? q('SELECT * FROM cms WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;
        if (isset($_GET['edit']) && !$row) { echo '<p class="empty">That item does not exist.</p>'; return; }
        admin_content_edit($row, $row ? $row['type'] : (($_GET['new'] ?? '') === 'page' ? 'page' : 'post'));
        return;
    }
    echo '<div class="ractions" style="margin:12px 0"><a class="btn sm" href="?p=admin&amp;tab=content&amp;new=post">New blog post</a><a class="btn sm alt" href="?p=admin&amp;tab=content&amp;new=page">New page</a></div>';
    echo '<div class="tabs sub">';
    foreach (['' => 'All', 'post' => 'Posts', 'page' => 'Pages'] as $k => $l) echo '<a href="?p=admin&amp;tab=content' . ($k ? '&amp;type=' . $k : '') . '" class="' . ($type === $k ? 'on' : '') . '">' . $l . '</a>';
    echo '</div>';
    $rows = q('SELECT * FROM cms' . ($type ? ' WHERE type = ?' : '') . ' ORDER BY updated DESC LIMIT 200', $type ? [$type] : [])->fetchAll();
    if (!$rows) echo '<p class="empty">Nothing here yet. Write your first ' . ($type ?: 'post') . '.</p>';
    foreach ($rows as $r) {
        [$st, $cls] = item_status($r);
        $flags = [];
        if ($r['meta_desc'] === '') $flags[] = 'no meta description';
        if (!(int)$r['robots_index']) $flags[] = 'noindex';
        if ($r['canonical'] !== '') $flags[] = 'custom canonical';
        echo '<div class="arow"><div class="who"><a href="?p=admin&amp;tab=content&amp;edit=' . (int)$r['id'] . '"><b>' . h($r['title']) . '</b></a> <span class="' . $cls . '">' . $st . '</span> <span class="pill">' . h($r['type']) . '</span><br>'
            . '<small>/' . h(cms_path($r)) . ' &middot; updated ' . h(ago((int)$r['updated'])) . ($flags ? ' &middot; ' . h(implode(', ', $flags)) : '') . '</small></div><div class="ractions">'
            . '<a class="btn sm alt" href="' . h(cms_href($r)) . '">View</a><a class="btn sm alt" href="?p=admin&amp;tab=content&amp;edit=' . (int)$r['id'] . '">Edit</a></div></div>';
    }
}

function admin_content_edit(?array $r, string $type): void {
    $GLOBALS['extra_js'][] = 'editor.js';
    $new = !$r;
    $r = $r ?: ['id' => 0, 'type' => $type, 'title' => '', 'slug' => '', 'body' => '', 'excerpt' => '', 'status' => 'draft', 'published_at' => 0, 'author_name' => '', 'h1' => '', 'meta_title' => '',
                'meta_desc' => '', 'robots_index' => 1, 'robots_follow' => 1, 'robots_extra' => '', 'canonical' => '', 'og_title' => '', 'og_desc' => '', 'og_image' => '', 'twitter_card' => '',
                'focus_kw' => '', 'schema_type' => 'auto', 'schema_json' => '', 'toc' => 0, 'show_footer' => 0, 'nav_label' => '', 'featured_image' => '', 'featured_alt' => ''];
    $post = $type === 'post';
    // Show what was typed if the last save failed validation.
    $kept = $_SESSION['cms_form'] ?? null; unset($_SESSION['cms_form']);
    $keptWhen = null;
    if ($kept && (int)$kept['id'] === (int)$r['id']) {
        foreach (['title', 'slug', 'body', 'excerpt', 'status', 'author_name', 'h1', 'meta_title', 'meta_desc', 'robots_index', 'robots_follow', 'robots_extra', 'canonical', 'og_title',
                  'og_desc', 'og_image', 'twitter_card', 'focus_kw', 'schema_type', 'schema_json', 'nav_label', 'featured_image', 'featured_alt'] as $k) {
            if (isset($kept['post'][$k])) $r[$k] = (string)$kept['post'][$k];
        }
        $r['toc'] = !empty($kept['post']['toc']) ? 1 : 0; $r['show_footer'] = !empty($kept['post']['show_footer']) ? 1 : 0;
        $keptWhen = (string)($kept['post']['publish_at'] ?? '');
    }
    $f = fn($k) => h($r[$k]);
    $sel = fn($k, $opts) => implode('', array_map(fn($v, $l) => '<option value="' . h($v) . '"' . ((string)$r[$k] === (string)$v ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts));
    $when = $keptWhen !== null ? $keptWhen : ((int)$r['published_at'] > 0 ? date('Y-m-d\TH:i', (int)$r['published_at']) : '');
    echo '<p class="crumb"><a href="?p=admin&amp;tab=content">&larr; All content</a></p><h1>' . ($new ? 'New ' . $type : 'Edit ' . $type) . '</h1>';
    ?>
<form method="post" action="?p=admin" class="editor" id="editor" data-type="<?= h($type) ?>" data-site="<?= h(site_url()) ?>" data-sitename="<?= h($GLOBALS['CFG']['site_name']) ?>"
      data-pretty="<?= pretty_urls() ? '1' : '0' ?>" data-title-tpl="<?= h(setting('seo_title_tpl')) ?>" data-new="<?= $new ? '1' : '0' ?>">
<?= admin_csrf() ?><input type="hidden" name="do" value="cms_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
<div class="ed-grid">
<div class="ed-main">
<div class="card">
<label>Title <input name="title" id="f_title" value="<?= $f('title') ?>" required maxlength="200" autocomplete="off"></label>
<label>URL slug <span class="hint">the last part of the address</span>
<span class="slugrow"><input name="slug" id="f_slug" value="<?= $f('slug') ?>" maxlength="80" autocomplete="off" placeholder="made-from-the-title"><button type="button" class="btn sm alt" id="slug_gen">From title</button></span></label>
<p class="urlprev" id="url_prev"></p>
<div class="mdbar" id="mdbar" role="toolbar" aria-label="Formatting">
<button type="button" data-md="h2" title="Heading 2">H2</button><button type="button" data-md="h3" title="Heading 3">H3</button><button type="button" data-md="h4" title="Heading 4">H4</button>
<button type="button" data-md="b" title="Bold"><b>B</b></button><button type="button" data-md="i" title="Italic"><i>I</i></button>
<button type="button" data-md="link" title="Link">Link</button><button type="button" data-md="img" title="Image">Image</button>
<button type="button" data-md="ul" title="Bulleted list">List</button><button type="button" data-md="ol" title="Numbered list">1.</button>
<button type="button" data-md="quote" title="Quote">Quote</button><button type="button" data-md="code" title="Code">Code</button>
</div>
<label class="nolabel">Body <span class="hint">Markdown. Headings: <code>## H2</code>, <code>### H3</code>, <code>#### H4</code>. Links: <code>[text](url){nofollow sponsored newtab}</code></span>
<textarea name="body" id="f_body" rows="18" spellcheck="true"><?= $f('body') ?></textarea></label>
<p class="hint" id="wordcount"></p>
<label>Excerpt <span class="hint">Short summary for the blog list and as a fallback description</span>
<textarea name="excerpt" id="f_excerpt" rows="2" maxlength="400"><?= $f('excerpt') ?></textarea></label>
<div class="two"><label>Featured image URL <input name="featured_image" id="f_feat" value="<?= $f('featured_image') ?>" maxlength="500" placeholder="https://..."></label>
<label>Image alt text <input name="featured_alt" id="f_featalt" value="<?= $f('featured_alt') ?>" maxlength="200"></label></div>
</div>

<details class="ed-box" open><summary>Search appearance</summary>
<div class="serp" id="serp" aria-label="Search result preview"><div class="s-url" id="s_url"></div><div class="s-title" id="s_title"></div><div class="s-desc" id="s_desc"></div></div>
<label>Meta title <span class="counter" id="c_mt"></span><span class="hint">Leave empty to use the title with the site template. You can use %title% and %site%.</span>
<input name="meta_title" id="f_mt" value="<?= $f('meta_title') ?>" maxlength="120"></label>
<label>Meta description <span class="counter" id="c_md"></span>
<textarea name="meta_desc" id="f_md" rows="3" maxlength="320"><?= $f('meta_desc') ?></textarea></label>
<label>Page heading (H1) <span class="counter" id="c_h1"></span><span class="hint">Leave empty to use the title as the H1. Every page gets exactly one H1.</span>
<input name="h1" id="f_h1" value="<?= $f('h1') ?>" maxlength="200"></label>
<label>Focus keyword <span class="hint">Used only for the checklist on this screen</span><input name="focus_kw" id="f_kw" value="<?= $f('focus_kw') ?>" maxlength="80" autocomplete="off"></label>
</details>

<details class="ed-box"><summary>Indexing and canonical</summary>
<div class="two"><label>Search engines may index this <select name="robots_index"><?= $sel('robots_index', ['1' => 'Yes (index)', '0' => 'No (noindex)']) ?></select></label>
<label>Search engines may follow links <select name="robots_follow"><?= $sel('robots_follow', ['1' => 'Yes (follow)', '0' => 'No (nofollow)']) ?></select></label></div>
<label>Extra robots directives <span class="hint">Comma separated: noarchive, nosnippet, noimageindex, max-snippet:-1, max-image-preview:large</span><input name="robots_extra" value="<?= $f('robots_extra') ?>" maxlength="200"></label>
<label>Canonical URL <span class="hint">Only if this content lives somewhere else. Empty means this page's own address.</span><input name="canonical" id="f_canon" value="<?= $f('canonical') ?>" maxlength="500" placeholder="https://..."></label>
</details>

<details class="ed-box"><summary>Social sharing</summary>
<label>Share title <span class="hint">Empty uses the meta title or title</span><input name="og_title" value="<?= $f('og_title') ?>" maxlength="120"></label>
<label>Share description <textarea name="og_desc" rows="2" maxlength="320"><?= $f('og_desc') ?></textarea></label>
<label>Share image URL <span class="hint">1200 x 630 works well. Empty uses the featured image, then the site default.</span><input name="og_image" id="f_og" value="<?= $f('og_image') ?>" maxlength="500" placeholder="https://..."></label>
<label>Twitter / X card <select name="twitter_card"><?= $sel('twitter_card', ['' => 'Automatic', 'summary' => 'Small summary', 'summary_large_image' => 'Large image']) ?></select></label>
</details>

<details class="ed-box"><summary>Structured data (schema.org)</summary>
<label>Type <select name="schema_type"><?= $sel('schema_type', ['auto' => $post ? 'Automatic (BlogPosting)' : 'Automatic (WebPage)', 'Article' => 'Article', 'BlogPosting' => 'BlogPosting', 'NewsArticle' => 'NewsArticle', 'WebPage' => 'WebPage', 'AboutPage' => 'AboutPage', 'ContactPage' => 'ContactPage', 'FAQPage' => 'FAQPage (use custom JSON)', 'none' => 'None']) ?></select></label>
<label>Custom JSON-LD <span class="hint">Optional. Paste the JSON only (no script tags). It replaces the automatic markup; breadcrumbs are still added.</span>
<textarea name="schema_json" rows="6" spellcheck="false" class="mono"><?= $f('schema_json') ?></textarea></label>
</details>

<details class="ed-box" open><summary>Publishing</summary>
<div class="two"><label>Status <select name="status" id="f_status"><?= $sel('status', ['draft' => 'Draft', 'published' => 'Published']) ?></select></label>
<label>Publish date <span class="hint">Future date = scheduled. Empty = now.</span><input type="datetime-local" name="publish_at" value="<?= h($when) ?>"></label></div>
<?php if ($post): ?><label>Author name shown <span class="hint">Empty uses your username</span><input name="author_name" value="<?= $f('author_name') ?>" maxlength="80"></label><?php endif; ?>
<label class="check"><input type="checkbox" name="toc" value="1" <?= (int)$r['toc'] ? 'checked' : '' ?>> Show a table of contents (from H2 and H3)</label>
<?php if (!$post): ?>
<label class="check"><input type="checkbox" name="show_footer" value="1" <?= (int)$r['show_footer'] ? 'checked' : '' ?>> Link to this page in the site footer</label>
<label>Footer link text <span class="hint">Empty uses the title</span><input name="nav_label" value="<?= $f('nav_label') ?>" maxlength="40"></label>
<?php endif; ?>
</details>
</div>

<aside class="ed-side"><div class="card sticky">
<h2>SEO checklist</h2><ul class="checks" id="checks"></ul>
<h2>Heading outline</h2><ol class="outline" id="outline"></ol>
</div></aside>
</div>
<div class="ed-actions">
<button class="btn" name="after" value="stay">Save</button>
<button class="btn alt" name="after" value="view">Save and view</button>
<?php if (!$new): ?><a class="btn alt" href="<?= h(cms_href($r)) ?>">View</a><?php endif; ?>
</div>
</form>
<?php if (!$new): ?>
<?php $revs = q('SELECT * FROM cms_revisions WHERE cms_id = ? ORDER BY id DESC LIMIT 20', [$r['id']])->fetchAll(); if ($revs): ?>
<details class="ed-box"><summary>Earlier versions (<?= count($revs) ?>)</summary>
<?php foreach ($revs as $v): ?><div class="arow"><div class="who"><b><?= h($v['title']) ?></b><br><small><?= h(date('M j, Y H:i', (int)$v['saved_at'])) ?> by <?= h($v['saved_by']) ?> &middot; <?= (int)str_word_count($v['body']) ?> words</small></div>
<form method="post" action="?p=admin" class="inl" onsubmit="return confirm('Replace the current text with this version? The current text is kept as a version too.')"><?= admin_csrf() ?><input type="hidden" name="do" value="cms_restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="rev" value="<?= (int)$v['id'] ?>"><button class="btn sm alt">Restore</button></form></div><?php endforeach; ?>
</details>
<?php endif; ?>
<form method="post" action="?p=admin" class="card danger" onsubmit="return confirm('Delete this <?= h($type) ?> for good?')"><?= admin_csrf() ?><input type="hidden" name="do" value="cms_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<h2>Delete</h2><p class="hint">Removes it and its saved versions. Add a redirect on the Redirects tab if the address was shared.</p><button class="btn dangerbtn">Delete <?= h($type) ?></button></form>
<?php endif;
}

/** Validate and store a posted item. Returns [redirect-url, flash message, ok?].
 *  When validation fails the typed form is kept so the editor can show it again. */
function admin_cms_save(array $u): array {
    $res = admin_cms_save_inner($u);
    if (!$res[2]) $_SESSION['cms_form'] = ['id' => (int)($_POST['id'] ?? 0), 'post' => $_POST];
    return $res;
}

function admin_cms_save_inner(array $u): array {
    $id = (int)($_POST['id'] ?? 0);
    $type = ($_POST['type'] ?? '') === 'page' ? 'page' : 'post';
    $old = $id ? q('SELECT * FROM cms WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$old) return ['?p=admin&tab=content', 'That item no longer exists.', false];
    if ($old) $type = $old['type'];
    $back = '?p=admin&tab=content&' . ($id ? 'edit=' . $id : 'new=' . $type);
    $in = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $title = $in('title', 200);
    if ($title === '') return [$back, 'A title is required.', false];
    $slug = $in('slug', 80); $slug = $slug === '' ? slugify($title) : strtolower($slug);
    // A redirect left automatically by an earlier slug change should not block going back to that slug.
    if ($msg = slug_problem($type, $slug, $id, true)) return [$back, $msg, false];
    q("DELETE FROM redirects WHERE from_path = ? AND note LIKE 'Auto:%'", [$type === 'post' ? 'blog/' . $slug : $slug]);
    foreach (['canonical', 'og_image', 'featured_image'] as $k) if (!url_field_ok($in($k, 500))) return [$back, 'The ' . str_replace('_', ' ', $k) . ' field must be a full web address (https://...) or start with /.', false];
    $schemaJson = trim((string)($_POST['schema_json'] ?? ''));
    if ($schemaJson !== '') {
        if (mb_strlen($schemaJson) > 20000 || !is_array(json_decode($schemaJson, true))) return [$back, 'The custom JSON-LD is not valid JSON.', false];
        $schemaJson = json_encode(json_decode($schemaJson, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $status = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
    $pub = trim((string)($_POST['publish_at'] ?? '')) !== '' ? (int)strtotime((string)$_POST['publish_at']) : 0;
    $now = time();
    $publishedAt = $old ? (int)$old['published_at'] : 0;
    if ($status === 'published') $publishedAt = $pub ?: ($publishedAt ?: $now);
    $card = in_array($_POST['twitter_card'] ?? '', ['summary', 'summary_large_image'], true) ? $_POST['twitter_card'] : '';
    $schemaType = in_array($_POST['schema_type'] ?? '', ['auto', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'none'], true) ? $_POST['schema_type'] : 'auto';
    $vals = [
        'title' => $title, 'slug' => $slug, 'body' => mb_substr((string)($_POST['body'] ?? ''), 0, 200000), 'excerpt' => $in('excerpt', 400),
        'status' => $status, 'published_at' => $publishedAt, 'updated' => $now, 'author_name' => $in('author_name', 80),
        'h1' => $in('h1', 200), 'meta_title' => $in('meta_title', 120), 'meta_desc' => $in('meta_desc', 320),
        'robots_index' => ($_POST['robots_index'] ?? '1') === '0' ? 0 : 1, 'robots_follow' => ($_POST['robots_follow'] ?? '1') === '0' ? 0 : 1,
        'robots_extra' => $in('robots_extra', 200), 'canonical' => $in('canonical', 500),
        'og_title' => $in('og_title', 120), 'og_desc' => $in('og_desc', 320), 'og_image' => $in('og_image', 500), 'twitter_card' => $card,
        'focus_kw' => $in('focus_kw', 80), 'schema_type' => $schemaType, 'schema_json' => $schemaJson,
        'toc' => !empty($_POST['toc']) ? 1 : 0, 'show_footer' => $type === 'page' && !empty($_POST['show_footer']) ? 1 : 0, 'nav_label' => $in('nav_label', 40),
        'featured_image' => $in('featured_image', 500), 'featured_alt' => $in('featured_alt', 200),
    ];
    if ($old) {
        if ($old['title'] !== $vals['title'] || $old['body'] !== $vals['body']) {
            q('INSERT INTO cms_revisions (cms_id, title, body, saved_at, saved_by) VALUES (?,?,?,?,?)', [$id, $old['title'], $old['body'], $now, $u['username']]);
            q('DELETE FROM cms_revisions WHERE cms_id = ? AND id NOT IN (SELECT id FROM cms_revisions WHERE cms_id = ? ORDER BY id DESC LIMIT 20)', [$id, $id]);
        }
        // A published address that changes leaves a 301 behind, so old links and rankings follow.
        if ($old['slug'] !== $slug && $old['status'] === 'published' && (int)$old['published_at'] <= $now) {
            $from = $type === 'post' ? 'blog/' . $old['slug'] : $old['slug'];
            $to = $type === 'post' ? 'blog/' . $slug : $slug;
            q('INSERT OR REPLACE INTO redirects (from_path, to_url, code, note, created) VALUES (?,?,?,?,?)', [$from, '/' . $to, 301, 'Auto: slug changed', $now]);
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
        q("UPDATE cms SET $set WHERE id = ?", array_merge(array_values($vals), [$id]));
    } else {
        $vals += ['type' => $type, 'created' => $now, 'author_id' => $u['id']];
        q('INSERT INTO cms (' . implode(', ', array_keys($vals)) . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')', array_values($vals));
        $id = (int)db()->lastInsertId();
    }
    $row = q('SELECT * FROM cms WHERE id = ?', [$id])->fetch();
    $dest = ($_POST['after'] ?? '') === 'view' ? cms_href($row) : '?p=admin&tab=content&edit=' . $id;
    return [$dest, ucfirst($type) . ' saved' . ($status === 'published' ? ($publishedAt > $now ? ' and scheduled.' : ' and published.') : ' as a draft.'), true];
}

function admin_seo(): void {
    $v = fn($k) => h(setting($k));
    $chk = fn($k) => setting($k) === '1' ? ' checked' : '';
    echo '<form method="post" action="?p=admin" class="seoform">' . admin_csrf() . '<input type="hidden" name="do" value="seo_save">';
    echo '<div class="card"><h2>Titles and descriptions</h2>'
        . '<label>Title template <span class="hint">Used for pages without their own meta title. Variables: %title% and %site%</span><input name="seo_title_tpl" value="' . $v('seo_title_tpl') . '" maxlength="120"></label>'
        . '<label>Home page title <span class="hint">Empty uses the site name</span><input name="seo_home_title" value="' . $v('seo_home_title') . '" maxlength="120"></label>'
        . '<label>Home page description<textarea name="seo_home_desc" rows="2" maxlength="320">' . $v('seo_home_desc') . '</textarea></label>'
        . '<label>Blog title <input name="seo_blog_title" value="' . $v('seo_blog_title') . '" maxlength="80"></label>'
        . '<label>Blog page heading (H1) <span class="hint">Empty uses the blog title</span><input name="seo_blog_h1" value="' . $v('seo_blog_h1') . '" maxlength="120"></label>'
        . '<label>Blog description<textarea name="seo_blog_desc" rows="2" maxlength="320">' . $v('seo_blog_desc') . '</textarea></label>'
        . '<label>Posts per blog page <input type="number" name="seo_per_page" min="1" max="50" value="' . $v('seo_per_page') . '"></label></div>';
    echo '<div class="card"><h2>Social sharing and organization</h2>'
        . '<label>Default share image URL <span class="hint">Used when a page has no image of its own</span><input name="seo_default_image" value="' . $v('seo_default_image') . '" maxlength="500" placeholder="https://..."></label>'
        . '<label>Twitter / X handle <input name="seo_twitter" value="' . $v('seo_twitter') . '" maxlength="40" placeholder="@yourname"></label>'
        . '<label>Organization name <span class="hint">For structured data. Empty uses the site name.</span><input name="seo_org_name" value="' . $v('seo_org_name') . '" maxlength="120"></label>'
        . '<label>Organization logo URL<input name="seo_org_logo" value="' . $v('seo_org_logo') . '" maxlength="500" placeholder="https://..."></label>'
        . '<label>Social profile URLs <span class="hint">One per line, for the sameAs property</span><textarea name="seo_social" rows="3" maxlength="1000">' . $v('seo_social') . '</textarea></label></div>';
    echo '<div class="card"><h2>Search engine verification</h2>'
        . '<label>Google Search Console code <span class="hint">The content value of the google-site-verification tag</span><input name="seo_verify_google" value="' . $v('seo_verify_google') . '" maxlength="120"></label>'
        . '<label>Bing Webmaster code <input name="seo_verify_bing" value="' . $v('seo_verify_bing') . '" maxlength="120"></label></div>';
    echo '<div class="card"><h2>Crawling and URLs</h2>'
        . '<label class="check"><input type="checkbox" name="pretty_urls" value="1"' . $chk('pretty_urls') . '> Pretty URLs (/about, /blog/my-post, /Ryan). Turn off only if your host does not support rewrites.</label>'
        . '<label class="check"><input type="checkbox" name="seo_sitemap_profiles" value="1"' . $chk('seo_sitemap_profiles') . '> Include member profiles in the sitemap</label>'
        . '<label class="check"><input type="checkbox" name="seo_noindex_site" value="1"' . $chk('seo_noindex_site') . '> Hide the whole site from search engines (noindex and robots.txt block). Use while testing.</label>'
        . '<label>Extra robots.txt lines <span class="hint">Added below the standard rules, for example Disallow: /private/</span><textarea name="seo_robots_extra" rows="3" maxlength="2000" class="mono">' . $v('seo_robots_extra') . '</textarea></label>'
        . '<p class="hint">Check your files: <a href="robots.txt" target="_blank" rel="noopener">robots.txt</a> &middot; <a href="sitemap.xml" target="_blank" rel="noopener">sitemap.xml</a> &middot; <a href="blog/feed.xml" target="_blank" rel="noopener">blog feed</a></p></div>';
    echo '<div class="card"><h2>Custom code</h2><p class="hint">Pasted as-is on every page, for analytics or other tags. Only add code you trust.</p>'
        . '<label>Before &lt;/head&gt;<textarea name="seo_head_html" rows="4" maxlength="10000" spellcheck="false" class="mono">' . $v('seo_head_html') . '</textarea></label>'
        . '<label>Before &lt;/body&gt;<textarea name="seo_foot_html" rows="4" maxlength="10000" spellcheck="false" class="mono">' . $v('seo_foot_html') . '</textarea></label></div>';
    echo '<button class="btn">Save SEO settings</button></form>';
}

function admin_seo_save(): string {
    $text = ['seo_title_tpl' => 120, 'seo_home_title' => 120, 'seo_home_desc' => 320, 'seo_blog_title' => 80, 'seo_blog_h1' => 120, 'seo_blog_desc' => 320,
             'seo_default_image' => 500, 'seo_twitter' => 40, 'seo_org_name' => 120, 'seo_org_logo' => 500, 'seo_social' => 1000,
             'seo_verify_google' => 120, 'seo_verify_bing' => 120, 'seo_robots_extra' => 2000, 'seo_head_html' => 10000, 'seo_foot_html' => 10000];
    $tpl = trim((string)($_POST['seo_title_tpl'] ?? ''));
    if ($tpl !== '' && strpos($tpl, '%title%') === false) return 'The title template must contain %title%.';
    foreach (['seo_default_image', 'seo_org_logo'] as $k) if (!url_field_ok(trim((string)($_POST[$k] ?? '')))) return 'The ' . str_replace('_', ' ', $k) . ' must be a full web address (https://...) or start with /.';
    foreach ($text as $k => $max) set_setting($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max));
    set_setting('seo_per_page', (string)max(1, min(50, (int)($_POST['seo_per_page'] ?? 10))));
    foreach (['pretty_urls', 'seo_sitemap_profiles', 'seo_noindex_site'] as $k) set_setting($k, !empty($_POST[$k]) ? '1' : '0');
    return '';
}

function admin_redirects(): void {
    $rows = q('SELECT * FROM redirects ORDER BY id DESC LIMIT 500')->fetchAll();
    echo '<div class="card"><h2>Add a redirect</h2><p class="hint">Send an old address to a new one. Use 301 for permanent moves (passes search ranking) and 302 for temporary ones.</p>'
        . '<form method="post" action="?p=admin" class="addname">' . admin_csrf() . '<input type="hidden" name="do" value="redirect_add">'
        . '<input name="from" required maxlength="200" placeholder="old-page or blog/old-post" aria-label="Old path">'
        . '<input name="to" required maxlength="500" placeholder="/new-page or https://..." aria-label="New address">'
        . '<select name="code" aria-label="Type"><option value="301">301 permanent</option><option value="302">302 temporary</option></select>'
        . '<input name="note" maxlength="80" placeholder="note (optional)" aria-label="Note"><button class="btn sm">Add</button></form></div>';
    if (!$rows) echo '<p class="empty">No redirects yet. Changing the slug of a published page or post adds one automatically.</p>';
    foreach ($rows as $r) {
        echo '<div class="arow"><div class="who"><b>/' . h($r['from_path']) . '</b> &rarr; ' . h($r['to_url']) . ' <span class="pill">' . (int)$r['code'] . '</span><br>'
            . '<small>' . (int)$r['hits'] . ' hits' . ($r['note'] !== '' ? ' &middot; ' . h($r['note']) : '') . ' &middot; added ' . h(date('M j, Y', (int)$r['created'])) . '</small></div><div class="ractions">'
            . '<form method="post" action="?p=admin" class="inl">' . admin_csrf() . '<input type="hidden" name="do" value="redirect_delete"><input type="hidden" name="rid" value="' . (int)$r['id'] . '"><button class="btn sm alt">Delete</button></form></div></div>';
    }
}

/** Handle the CMS-related admin POST actions. Returns [url, message, ok] or null when $do is not ours. */
function admin_cms_post(string $do, array $u): ?array {
    switch ($do) {
    case 'cms_save': return admin_cms_save($u);
    case 'cms_delete':
        q('DELETE FROM cms WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        return ['?p=admin&tab=content', 'Deleted.', true];
    case 'cms_restore':
        $id = (int)($_POST['id'] ?? 0);
        $cur = q('SELECT * FROM cms WHERE id = ?', [$id])->fetch();
        $rev = q('SELECT * FROM cms_revisions WHERE id = ? AND cms_id = ?', [(int)($_POST['rev'] ?? 0), $id])->fetch();
        if (!$cur || !$rev) return ['?p=admin&tab=content', 'That version no longer exists.', false];
        q('INSERT INTO cms_revisions (cms_id, title, body, saved_at, saved_by) VALUES (?,?,?,?,?)', [$id, $cur['title'], $cur['body'], time(), $u['username']]);
        q('UPDATE cms SET title = ?, body = ?, updated = ? WHERE id = ?', [$rev['title'], $rev['body'], time(), $id]);
        return ['?p=admin&tab=content&edit=' . $id, 'Version restored.', true];
    case 'seo_save':
        $err = admin_seo_save();
        return ['?p=admin&tab=seo', $err !== '' ? $err : 'SEO settings saved.', $err === ''];
    case 'redirect_add':
        $from = trim(preg_replace('~^https?://[^/]+~i', '', trim((string)($_POST['from'] ?? ''))), '/ ');
        $to = trim((string)($_POST['to'] ?? ''));
        if ($from === '' || strpos($from, '?') !== false || strpos($from, '#') !== false || !preg_match('~^[A-Za-z0-9_./%-]+$~', $from)) return ['?p=admin&tab=redirects', 'The old path can only contain letters, numbers, hyphens, slashes and dots (no query string).', false];
        if (!preg_match('~^(https?://\S+|/\S*)$~', $to)) return ['?p=admin&tab=redirects', 'The new address must start with https:// or /.', false];
        if (trim($to, '/') === $from) return ['?p=admin&tab=redirects', 'A redirect cannot point to itself.', false];
        if (q("SELECT 1 FROM cms WHERE (type = 'page' AND slug = ?1) OR (type = 'post' AND 'blog/' || slug = ?1)", [$from])->fetch()) return ['?p=admin&tab=redirects', 'A page or post already uses that address. Change or delete it first.', false];
        q('INSERT OR REPLACE INTO redirects (from_path, to_url, code, note, created) VALUES (?,?,?,?,?)',
          [$from, $to, (int)($_POST['code'] ?? 301) === 302 ? 302 : 301, mb_substr(clean((string)($_POST['note'] ?? '')), 0, 80), time()]);
        return ['?p=admin&tab=redirects', 'Redirect saved.', true];
    case 'redirect_delete':
        q('DELETE FROM redirects WHERE id = ?', [(int)($_POST['rid'] ?? 0)]);
        return ['?p=admin&tab=redirects', 'Redirect deleted.', true];
    case 'names_target':
        $n = trim((string)($_POST['target'] ?? ''));
        if ($n !== '' && !q('SELECT 1 FROM users WHERE username = ?', [$n])->fetch()) return ['?p=admin&tab=names', 'No member is called "' . $n . '".', false];
        set_setting('reserved_redirect', $n);
        return ['?p=admin&tab=names', $n === '' ? 'Reserved names no longer redirect.' : 'Reserved names now redirect to @' . $n . '.', true];
    }
    return null;
}
