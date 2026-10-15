<?php
// Pages, blog, SEO settings, redirects, sitemap and robots. Loaded at the end of lib.php.

// ---- Settings (stored in the database, edited on the Admin screens) -----------
const SETTING_DEFAULTS = [
    'reserved_redirect' => 'Ryan',
    'pretty_urls'       => '1',
    'seo_title_tpl'     => '%title% | %site%',
    'seo_home_title'    => '',
    'seo_home_desc'     => 'Short thoughts. 140 characters. No links.',
    'seo_default_image' => '',
    'seo_twitter'       => '',
    'seo_org_name'      => '',
    'seo_org_logo'      => '',
    'seo_social'        => '',
    'seo_verify_google' => '',
    'seo_verify_bing'   => '',
    'seo_head_html'     => '',
    'seo_foot_html'     => '',
    'seo_robots_extra'  => '',
    'seo_blog_title'    => 'Blog',
    'seo_blog_desc'     => '',
    'seo_blog_h1'       => '',
    'seo_per_page'      => '10',
    'seo_sitemap_profiles' => '1',
    'seo_noindex_site'  => '0',
];

function &settings_cache(): array {
    static $all = null;
    if ($all === null) { $all = []; foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) $all[$r['k']] = $r['v']; }
    return $all;
}
function setting(string $k): string { $all = &settings_cache(); return $all[$k] ?? (SETTING_DEFAULTS[$k] ?? ''); }
function set_setting(string $k, string $v): void {
    q('INSERT OR REPLACE INTO settings (k, v) VALUES (?,?)', [$k, $v]);
    $all = &settings_cache(); $all[$k] = $v;
}

// ---- URLs -------------------------------------------------------------------------
function pretty_urls(): bool { return setting('pretty_urls') === '1'; }

/** Directory the app lives in, as an absolute URL ending in "/". Relative links resolve against this (<base href>). */
function request_base(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/** Path of a CMS item relative to the site root: "about" or "blog/my-post". */
function cms_path(array $r): string { return $r['type'] === 'post' ? 'blog/' . $r['slug'] : $r['slug']; }

/** Link that works from any page (relative to <base>). */
function cms_href(array $r): string {
    if (pretty_urls()) return cms_path($r);
    return $r['type'] === 'post' ? '?p=article&slug=' . urlencode($r['slug']) : '?p=cms&slug=' . urlencode($r['slug']);
}
/** Absolute public URL, used for canonicals, the sitemap and structured data. */
function cms_url(array $r): string { return site_url() . '/' . (pretty_urls() ? cms_path($r) : cms_href($r)); }

function blog_href(int $page = 1): string {
    if (pretty_urls()) return $page > 1 ? 'blog/page/' . $page : 'blog';
    return '?p=blog' . ($page > 1 ? '&page=' . $page : '');
}
function blog_url(int $page = 1): string { return site_url() . '/' . blog_href($page); }

function profile_href(string $name): string { return pretty_urls() ? strtolower(rawurlencode($name)) : '?p=user&u=' . urlencode($name); }   // /ryan, always lower case
function profile_url(string $name): string { return site_url() . '/' . profile_href($name); }

// ---- Slugs ------------------------------------------------------------------------
function slugify(string $s): string {
    $t = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s;
    $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $t ?: $s));
    $t = trim($t, '-');
    return substr($t !== '' ? $t : 'item', 0, 80) ?: 'item';
}

/** Root-level words a page slug may not use: routes, folders and files that already answer at that address. */
const RESERVED_SLUGS = ['blog', 'sitemap', 'robots', 'assets', 'avatars', 'data', 'index', 'admin', 'login', 'register', 'api', 'feed', 'rss',
                        'static', 'favicon', 'sw', 'manifest', 'offline', 'build', 'backup', 'config', 'lib', 'extras', 'pages', 'prompts', 'cms', 'tools'];

/** Why a slug cannot be used, or null if it is fine. */
function slug_problem(string $type, string $slug, int $ignoreId = 0, bool $ignoreAutoRedirect = false): ?string {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return 'Slugs use lowercase letters, numbers and single hyphens only.';
    if (strlen($slug) > 80) return 'Slugs can be at most 80 characters.';
    if ($type === 'page') {
        if (in_array($slug, RESERVED_SLUGS, true) || is_file(__DIR__ . '/' . $slug) || is_dir(__DIR__ . '/' . $slug)) return "\"$slug\" is used by the site itself. Choose another slug.";
        if (q('SELECT 1 FROM users WHERE lower(username) = ?', [$slug])->fetch()) return 'That address belongs to a member. Choose another slug.';
    }
    if (q('SELECT 1 FROM cms WHERE type = ? AND slug = ? AND id != ?', [$type, $slug, $ignoreId])->fetch()) return 'Another ' . $type . ' already uses that slug.';
    $path = $type === 'post' ? 'blog/' . $slug : $slug;
    if (q("SELECT 1 FROM redirects WHERE from_path = ?1 AND (?2 = 0 OR note NOT LIKE 'Auto:%')", [$path, $ignoreAutoRedirect ? 1 : 0])->fetch()) return 'A redirect already uses that address. Delete the redirect first.';
    return null;
}

/** True if a member name would collide with a CMS page or a built-in address. */
function name_collides_with_site(string $name): bool {
    $n = strtolower($name);
    return in_array($n, RESERVED_SLUGS, true) || (bool)q("SELECT 1 FROM cms WHERE type = 'page' AND slug = ?", [$n])->fetch();
}

// ---- Markdown (safe: all raw HTML is escaped) ----------------------------------------
/** Is this URL safe to put in href/src? Returns the cleaned URL or '#'. */
function md_url(string $u): string {
    $u = trim(html_entity_decode($u, ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $u) && !preg_match('~^(https?:|mailto:|tel:)~i', $u)) return '#';
    if (preg_match('/[\x00-\x1f\s]/', $u)) $u = preg_replace('/[\x00-\x1f\s]+/', '%20', $u);
    return $u;
}

function md_is_external(string $url): bool {
    if (!preg_match('~^https?://~i', $url)) return false;
    $host = parse_url($url, PHP_URL_HOST); $mine = parse_url(site_url(), PHP_URL_HOST);
    return !$host || !$mine || strcasecmp(preg_replace('/^www\./i', '', $host), preg_replace('/^www\./i', '', $mine)) !== 0;
}

function md_inline(string $t, array &$info): string {
    $codes = [];
    $t = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) { $codes[] = '<code>' . h($m[1]) . '</code>'; return "\x01" . (count($codes) - 1) . "\x02"; }, $t);
    $t = h($t);
    // images: ![alt](url "title")
    $t = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;(.*?)&quot;)?\)/', function ($m) use (&$info) {
        $info['images']++; if (trim($m[1]) === '') $info['images_no_alt']++;
        return '<img src="' . h(md_url($m[2])) . '" alt="' . $m[1] . '"' . (isset($m[3]) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '') . ' loading="lazy">';
    }, $t);
    // links: [text](url "title"){nofollow sponsored ugc newtab}
    $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;(.*?)&quot;)?\)(?:\{([a-z ,]+)\})?/', function ($m) use (&$info) {
        $url = md_url($m[2]); $flags = preg_split('/[ ,]+/', strtolower($m[4] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $ext = md_is_external($url);
        $rel = [];
        foreach (['nofollow', 'sponsored', 'ugc'] as $f) if (in_array($f, $flags, true)) $rel[] = $f;
        $newtab = in_array('newtab', $flags, true);
        if ($ext || $newtab) $rel[] = 'noopener';
        if ($newtab) $rel[] = 'noreferrer';
        $ext ? $info['links_out']++ : $info['links_in']++;
        if (in_array('nofollow', $flags, true)) $info['links_nofollow']++;
        return '<a href="' . h($url) . '"' . (isset($m[3]) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '') . ($rel ? ' rel="' . implode(' ', array_unique($rel)) . '"' : '') . ($newtab ? ' target="_blank"' : '') . '>' . $m[1] . '</a>';
    }, $t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w])__(.+?)__(?![\w])/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*(?![\s*])(.+?)(?<![\s*])\*(?![\w*])/s', '<em>$1</em>', $t);
    $t = preg_replace('/(?<![\w])_(?![\s_])(.+?)(?<![\s_])_(?![\w])/s', '<em>$1</em>', $t);
    $t = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $t);
    return preg_replace_callback('/\x01(\d+)\x02/', fn($m) => $codes[(int)$m[1]], $t);
}

function md_blocks(array $lines, array &$info, array &$ids): string {
    $out = ''; $para = []; $i = 0; $n = count($lines);
    $flush = function () use (&$para, &$out, &$info) {
        if (!$para) return;
        $html = '';
        foreach ($para as $k => $ln) { $html .= md_inline(rtrim($ln), $info) . ($k < count($para) - 1 ? (preg_match('/ {2,}$/', $ln) ? "<br>\n" : ' ') : ''); }
        $out .= '<p>' . trim($html) . "</p>\n";
        if ($info['first_p'] === '') $info['first_p'] = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        $para = [];
    };
    while ($i < $n) {
        $l = $lines[$i];
        if (trim($l) === '') { $flush(); $i++; continue; }
        if (preg_match('/^```([\w+-]*)\s*$/', $l, $m)) {
            $flush(); $buf = []; $i++;
            while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) $buf[] = $lines[$i++];
            $i++;
            $out .= '<pre><code' . ($m[1] !== '' ? ' class="language-' . h($m[1]) . '"' : '') . '>' . h(implode("\n", $buf)) . "</code></pre>\n";
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.+?)(?:\s+\{#([A-Za-z][\w-]*)\})?\s*#*\s*$/', $l, $m)) {
            $flush();
            $lvl = strlen($m[1]);
            $inner = md_inline($m[2], $info);
            $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'));
            $id = !empty($m[3]) ? $m[3] : slugify($plain);
            $base = $id; $k = 2; while (isset($ids[$id])) $id = $base . '-' . $k++;
            $ids[$id] = true;
            $info['headings'][] = ['level' => $lvl, 'text' => $plain, 'id' => $id];
            $out .= "<h$lvl id=\"" . h($id) . "\">$inner</h$lvl>\n";
            $i++; continue;
        }
        if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $l)) { $flush(); $out .= "<hr>\n"; $i++; continue; }
        if (preg_match('/^\s*>\s?/', $l)) {
            $flush(); $buf = [];
            while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $mm)) { $buf[] = $mm[1]; $i++; }
            $out .= '<blockquote>' . md_blocks($buf, $info, $ids) . "</blockquote>\n";
            continue;
        }
        if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $l, $m)) {
            $flush(); $ordered = ctype_digit($m[1][0]); $items = [];
            $marker = $ordered ? '\d+[.)]' : '[-*+]';
            while ($i < $n && preg_match('/^\s*' . $marker . '\s+(.*)$/', $lines[$i], $mm)) {
                $items[] = $mm[1]; $i++;
                while ($i < $n && preg_match('/^ {2,}\S/', $lines[$i]) && !preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/', $lines[$i])) { $items[count($items) - 1] .= ' ' . trim($lines[$i]); $i++; }
            }
            $tag = $ordered ? 'ol' : 'ul';
            $out .= "<$tag>" . implode('', array_map(fn($it) => '<li>' . md_inline($it, $info) . '</li>', $items)) . "</$tag>\n";
            continue;
        }
        if (strpos($l, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[$i + 1])) {
            $flush();
            $cells = fn($row) => array_map('trim', explode('|', trim(trim($row), '|')));
            $head = $cells($l); $i += 2; $rows = [];
            while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) { $rows[] = $cells($lines[$i]); $i++; }
            $out .= '<div class="tablewrap"><table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . md_inline($c, $info) . '</th>', $head)) . '</tr></thead><tbody>';
            foreach ($rows as $r) $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . md_inline($c, $info) . '</td>', $r)) . '</tr>';
            $out .= "</tbody></table></div>\n";
            continue;
        }
        $para[] = $l; $i++;
    }
    $flush();
    return $out;
}

/** Markdown to HTML. $info receives headings, link and image counts, word count and the first paragraph. */
function md_render(string $src, array &$info = []): string {
    $info = ['headings' => [], 'links_in' => 0, 'links_out' => 0, 'links_nofollow' => 0, 'images' => 0, 'images_no_alt' => 0, 'words' => 0, 'first_p' => ''];
    $ids = [];
    $html = md_blocks(preg_split('/\R/', str_replace("\t", '    ', trim($src))), $info, $ids);
    preg_match_all('/[\p{L}\p{N}\']+/u', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), $w);
    $info['words'] = count($w[0]);
    return $html;
}

/** Table of contents for H2 and H3 headings; links are absolute to this page so <base href> does not matter. */
function md_toc(array $headings, string $pageUrl): string {
    $items = array_filter($headings, fn($x) => $x['level'] >= 2 && $x['level'] <= 3);
    if (count($items) < 2) return '';
    $o = '<nav class="toc" aria-label="Table of contents"><b>On this page</b><ul>';
    foreach ($items as $x) $o .= '<li class="toc-h' . (int)$x['level'] . '"><a href="' . h($pageUrl . '#' . $x['id']) . '">' . h($x['text']) . '</a></li>';
    return $o . '</ul></nav>';
}

// ---- SEO head ------------------------------------------------------------------------
const NOINDEX_ROUTES = ['login', 'register', 'forgot', 'reset', 'confirm', 'verify', 'settings', 'account', 'notifications', 'bookmarks', 'report', 'admin', 'search', 'follows', 'unsub'];

function seo_title(string $t): string {
    global $CFG;
    return str_replace(['%title%', '%site%'], [$t, $CFG['site_name']], setting('seo_title_tpl') !== '' ? setting('seo_title_tpl') : '%title% | %site%');
}

/** Collapse whitespace and cut at a word boundary. */
function seo_trim(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
}

/** Make a stored URL absolute: "/x" and "x" are taken relative to the site. */
function abs_url(string $u): string {
    $u = trim($u);
    if ($u === '' || preg_match('~^https?://~i', $u)) return $u;
    return site_url() . '/' . ltrim($u, '/');
}

function ld_json(array $d): string {
    return '<script type="application/ld+json">' . json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
function ld_org(): array {
    global $CFG;
    $o = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => setting('seo_org_name') ?: $CFG['site_name'], 'url' => site_url() . '/'];
    if (setting('seo_org_logo') !== '') $o['logo'] = abs_url(setting('seo_org_logo'));
    $same = array_values(array_filter(array_map('trim', preg_split('/\R/', setting('seo_social')))));
    if ($same) $o['sameAs'] = $same;
    return $o;
}
function ld_website(): array {
    global $CFG;
    return ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $CFG['site_name'], 'url' => site_url() . '/',
            'potentialAction' => ['@type' => 'SearchAction', 'target' => site_url() . '/?p=search&q={search_term_string}', 'query-input' => 'required name=search_term_string']];
}
function ld_breadcrumbs(array $crumbs): array {
    $items = [];
    foreach ($crumbs as $i => [$name, $url]) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** Output the SEO part of <head>. $meta keys: title_full, description, canonical|url, robots, og_title, og_desc, og_image,
 *  og_type, twitter_card, jsonld (list of arrays), article (published, modified, author). */
function seo_head(string $title, array $meta): void {
    global $CFG;
    $route = $_GET['p'] ?? 'home';
    $full = $meta['title_full'] ?? seo_title($title);
    $desc = trim($meta['description'] ?? setting('seo_home_desc'));
    $canon = $meta['canonical'] ?? $meta['url'] ?? null;
    $robots = $meta['robots'] ?? ((in_array($route, NOINDEX_ROUTES, true) || isset($_GET['before'])) ? 'noindex, follow' : '');
    if (setting('seo_noindex_site') === '1') $robots = 'noindex, nofollow';
    $image = abs_url($meta['og_image'] ?? '') ?: abs_url(setting('seo_default_image')) ?: site_url() . '/assets/og-default.png';
    $ogTitle = $meta['og_title'] ?? $full; $ogDesc = $meta['og_desc'] ?? $desc;
    $card = $meta['twitter_card'] ?? ($image ? 'summary_large_image' : 'summary');
    echo '<title>' . h($full) . "</title>\n";
    if ($desc !== '') echo '<meta name="description" content="' . h($desc) . "\">\n";
    if ($robots !== '') echo '<meta name="robots" content="' . h($robots) . "\">\n";
    if ($canon) echo '<link rel="canonical" href="' . h($canon) . "\">\n";
    echo '<meta property="og:site_name" content="' . h($CFG['site_name']) . "\">\n";
    echo '<meta property="og:title" content="' . h($ogTitle) . "\">\n";
    if ($ogDesc !== '') echo '<meta property="og:description" content="' . h($ogDesc) . "\">\n";
    echo '<meta property="og:type" content="' . h($meta['og_type'] ?? $meta['type'] ?? 'website') . "\">\n";
    if ($canon) echo '<meta property="og:url" content="' . h($canon) . "\">\n";
    if ($image) echo '<meta property="og:image" content="' . h($image) . "\">\n" . (strpos($image, 'p=card') !== false || strpos($image, 'og-default.png') !== false ? '<meta property="og:image:width" content="1200">' . "\n" . '<meta property="og:image:height" content="630">' . "\n" : '');
    if (!empty($meta['article'])) {
        $a = $meta['article'];
        echo '<meta property="article:published_time" content="' . h(gmdate('c', (int)$a['published'])) . "\">\n";
        echo '<meta property="article:modified_time" content="' . h(gmdate('c', (int)$a['modified'])) . "\">\n";
        if (!empty($a['author'])) echo '<meta property="article:author" content="' . h($a['author']) . "\">\n";
    }
    echo '<meta name="twitter:card" content="' . h($card) . "\">\n";
    if (setting('seo_twitter') !== '') echo '<meta name="twitter:site" content="@' . h(ltrim(setting('seo_twitter'), '@')) . "\">\n";
    echo '<meta name="twitter:title" content="' . h($ogTitle) . "\">\n";
    if ($ogDesc !== '') echo '<meta name="twitter:description" content="' . h($ogDesc) . "\">\n";
    if ($image) echo '<meta name="twitter:image" content="' . h($image) . "\">\n";
    if (setting('seo_verify_google') !== '') echo '<meta name="google-site-verification" content="' . h(setting('seo_verify_google')) . "\">\n";
    if (setting('seo_verify_bing') !== '') echo '<meta name="msvalidate.01" content="' . h(setting('seo_verify_bing')) . "\">\n";
    $ld = $meta['jsonld'] ?? [];
    if ($route === 'home' && empty($meta['jsonld'])) $ld = [ld_org(), ld_website()];
    foreach ($ld as $d) echo ld_json($d) . "\n";
    if (pretty_urls() && blog_count() > 0) echo '<link rel="alternate" type="application/rss+xml" title="' . h($CFG['site_name']) . ' blog" href="blog/feed.xml">' . "\n";
}

/** Raw HTML an admin pasted for the head or the end of the body (analytics, etc.). */
function seo_custom_html(string $where): string { return setting($where === 'head' ? 'seo_head_html' : 'seo_foot_html'); }

// ---- Content queries ---------------------------------------------------------------
function blog_count(): int {
    static $n = null;
    return $n ??= (int)q("SELECT COUNT(*) FROM cms WHERE type = 'post' AND status = 'published' AND published_at <= ?", [time()])->fetchColumn();
}

/** One item by slug. Admins also see drafts and scheduled items (preview). */
function cms_find(string $type, string $slug, bool $admin): ?array {
    $r = q('SELECT * FROM cms WHERE type = ? AND slug = ?', [$type, $slug])->fetch();
    if (!$r) return null;
    $live = $r['status'] === 'published' && (int)$r['published_at'] <= time();
    return ($live || $admin) ? $r : null;
}
function cms_is_live(array $r): bool { return $r['status'] === 'published' && (int)$r['published_at'] <= time(); }

function footer_pages(): array {
    static $rows = null;
    return $rows ??= q("SELECT * FROM cms WHERE type = 'page' AND show_footer = 1 AND status = 'published' AND published_at <= ? ORDER BY nav_label, title", [time()])->fetchAll();
}

function robots_directive(array $r): string {
    $parts = [(int)$r['robots_index'] ? 'index' : 'noindex', (int)$r['robots_follow'] ? 'follow' : 'nofollow'];
    foreach (preg_split('/[\s,]+/', strtolower((string)$r['robots_extra']), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('/^(noarchive|nosnippet|noimageindex|notranslate|max-snippet:-?\d+|max-image-preview:(none|standard|large)|max-video-preview:-?\d+)$/', $tok)) $parts[] = $tok;
    }
    return implode(', ', array_unique($parts));
}

/** Structured data for a page or post. */
function cms_jsonld(array $r, string $url, string $desc, string $image, string $authorName): array {
    $out = [];
    $type = $r['schema_type'] ?: 'auto';
    if ($type === 'none') return [];
    $isPost = $r['type'] === 'post';
    if ($r['schema_json'] !== '' && ($d = json_decode($r['schema_json'], true)) !== null && is_array($d)) {
        $out = isset($d[0]) && is_array($d[0]) ? $d : [$d];
    } else {
        if ($type === 'auto') $type = $isPost ? 'BlogPosting' : 'WebPage';
        if (!in_array($type, ['Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage'], true)) $type = 'WebPage';
        $headline = $r['h1'] !== '' ? $r['h1'] : $r['title'];
        $d = ['@context' => 'https://schema.org', '@type' => $type, 'headline' => mb_substr($headline, 0, 110), 'name' => $headline,
              'description' => $desc, 'url' => $url, 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
              'inLanguage' => 'en'];
        if ($image !== '') $d['image'] = [$image];
        if (in_array($type, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
            $d['datePublished'] = gmdate('c', (int)$r['published_at'] ?: (int)$r['created']);
            $d['dateModified'] = gmdate('c', (int)$r['updated']);
            $d['author'] = ['@type' => 'Person', 'name' => $authorName];
            $pub = ld_org(); unset($pub['@context']); if (isset($pub['sameAs'])) unset($pub['sameAs']);
            $d['publisher'] = $pub;
        }
        $out[] = $d;
    }
    $crumbs = [['Home', site_url() . '/']];
    if ($isPost) $crumbs[] = [setting('seo_blog_title') ?: 'Blog', blog_url()];
    $crumbs[] = [$r['h1'] !== '' ? $r['h1'] : $r['title'], $url];
    $out[] = ld_breadcrumbs($crumbs);
    return $out;
}

// ---- Redirects and routing ---------------------------------------------------------
/** The member that reserved names point to, or null. */
function reserved_target(): ?array {
    $n = setting('reserved_redirect');
    return $n === '' ? null : (q('SELECT * FROM users WHERE username = ? AND is_suspended = 0', [$n])->fetch() ?: null);
}

function redirect_to(string $url, int $code = 301): never {
    http_response_code($code);
    header('Location: ' . $url);
    exit;
}

/** Turn a request path (from the .htaccess catch-all) into a route, or send a redirect / 404. */
function resolve_path(string $raw): array {
    $path = trim(rawurldecode($raw), '/');
    if ($path === '') return ['route' => 'home', 'get' => []];
    if ($path === 'robots.txt') render_robots();
    if ($path === 'sitemap.xml') render_sitemap();
    if ($path === 'blog/feed.xml') blog_feed();
    if (preg_match('~^(assets|avatars|data|tools)/~', $path)) { http_response_code(404); header('Content-Type: text/plain'); exit('Not found'); }

    if ($r = q('SELECT * FROM redirects WHERE from_path = ?', [$path])->fetch()) {
        q('UPDATE redirects SET hits = hits + 1 WHERE id = ?', [$r['id']]);
        redirect_to(abs_url($r['to_url']), (int)$r['code'] === 302 ? 302 : 301);
    }
    if ($path === 'blog') return ['route' => 'blog', 'get' => []];
    if (preg_match('~^blog/page/(\d{1,4})$~', $path, $m)) return ['route' => 'blog', 'get' => ['page' => $m[1]]];
    if (preg_match('~^blog/([A-Za-z0-9-]+)$~', $path, $m)) {
        if ($m[1] !== strtolower($m[1]) && q("SELECT 1 FROM cms WHERE type = 'post' AND slug = ?", [strtolower($m[1])])->fetch()) redirect_to(site_url() . '/blog/' . strtolower($m[1]));
        return ['route' => 'article', 'get' => ['slug' => strtolower($m[1])]];
    }
    if (preg_match('~^[A-Za-z0-9_-]+$~', $path)) {
        $page = q("SELECT slug FROM cms WHERE type = 'page' AND slug = ?", [strtolower($path)])->fetch();
        if ($page) {
            if ($path !== $page['slug']) redirect_to(site_url() . '/' . $page['slug']);
            return ['route' => 'cms', 'get' => ['slug' => $page['slug']]];
        }
        $user = q('SELECT username FROM users WHERE username = ?', [$path])->fetch();
        if ($user) {
            if ($path !== strtolower($user['username'])) redirect_to(profile_url($user['username']));   // one address per person: lower case
            return ['route' => 'user', 'get' => ['u' => $user['username']]];
        }
        if (reserved_name($path) && ($t = reserved_target())) redirect_to(profile_url($t['username']), 302);
    }
    not_found();
}

// ---- Public pages --------------------------------------------------------------------
function cms_author(array $r): string {
    if ($r['author_name'] !== '') return $r['author_name'];
    return (string)q('SELECT username FROM users WHERE id = ?', [$r['author_id']])->fetchColumn();
}

/** A page or blog post. */
function page_cms_item(array $r, ?array $viewer): void {
    global $CFG;
    $live = cms_is_live($r); $post = $r['type'] === 'post';
    $info = []; $html = md_render($r['body'], $info);
    $url = cms_url($r);
    $h1 = $r['h1'] !== '' ? $r['h1'] : $r['title'];
    $desc = seo_trim($r['meta_desc'] !== '' ? $r['meta_desc'] : ($r['excerpt'] !== '' ? $r['excerpt'] : $info['first_p']), 160);
    $fullTitle = $r['meta_title'] !== '' ? str_replace(['%title%', '%site%'], [$r['title'], $CFG['site_name']], $r['meta_title']) : seo_title($r['title']);
    $author = $post ? cms_author($r) : '';
    $image = abs_url($r['og_image'] !== '' ? $r['og_image'] : $r['featured_image']);
    $meta = [
        'title_full' => $fullTitle, 'description' => $desc,
        'canonical' => $r['canonical'] !== '' ? abs_url($r['canonical']) : $url,
        'robots' => $live ? robots_directive($r) : 'noindex, nofollow',
        'og_title' => $r['og_title'] !== '' ? $r['og_title'] : ($r['meta_title'] !== '' ? str_replace(['%title%', '%site%'], [$r['title'], $CFG['site_name']], $r['meta_title']) : $r['title']),
        'og_desc' => $r['og_desc'] !== '' ? $r['og_desc'] : $desc,
        'og_image' => $image, 'og_type' => $post ? 'article' : 'website',
        'jsonld' => $live ? cms_jsonld($r, $url, $desc, $image, $author) : [],
    ];
    if ($r['twitter_card'] !== '') $meta['twitter_card'] = $r['twitter_card'];
    if ($post) $meta['article'] = ['published' => (int)$r['published_at'] ?: (int)$r['created'], 'modified' => (int)$r['updated'], 'author' => $author];
    page_head($r['title'], $meta);
    if (!$live) echo '<div class="flash">Preview. This ' . ($r['status'] === 'published' ? 'is scheduled for ' . h(date('M j, Y H:i', (int)$r['published_at'])) : 'is a draft') . ' and is not public yet.</div>';
    echo '<article class="cms prose">';
    echo '<nav class="crumbs" aria-label="Breadcrumb"><a href="./">Home</a>' . ($post ? ' <span aria-hidden="true">/</span> <a href="' . h(blog_href()) . '">' . h(setting('seo_blog_title') ?: 'Blog') . '</a>' : '') . '</nav>';
    echo '<div class="cms-head"><h1>' . h($h1) . '</h1>';
    if ($post) {
        $when = (int)$r['published_at'] ?: (int)$r['created'];
        echo '<p class="byline"><time datetime="' . h(gmdate('c', $when)) . '">' . h(date('M j, Y', $when)) . '</time>'
            . ($author !== '' ? ' &middot; ' . h($author) : '') . ' &middot; ' . max(1, (int)ceil($info['words'] / 200)) . ' min read</p>';
    }
    if ($r['featured_image'] !== '') echo '<figure class="feat"><img src="' . h(md_url($r['featured_image'])) . '" alt="' . h($r['featured_alt']) . '"></figure>';
    echo '</div>';
    if ((int)$r['toc']) echo md_toc($info['headings'], $url);
    echo '<div class="cms-body">' . $html . '</div>';
    if ($post && $live) {
        $when = (int)$r['published_at'];
        $prev = q("SELECT * FROM cms WHERE type = 'post' AND status = 'published' AND published_at <= ?1 AND (published_at < ?2 OR (published_at = ?2 AND id < ?3)) ORDER BY published_at DESC, id DESC LIMIT 1", [time(), $when, $r['id']])->fetch();
        $next = q("SELECT * FROM cms WHERE type = 'post' AND status = 'published' AND published_at <= ?1 AND (published_at > ?2 OR (published_at = ?2 AND id > ?3)) ORDER BY published_at ASC, id ASC LIMIT 1", [time(), $when, $r['id']])->fetch();
        if ($prev || $next) {
            echo '<nav class="postnav" aria-label="More posts">' . ($prev ? '<a rel="prev" href="' . h(cms_href($prev)) . '">&larr; ' . h($prev['title']) . '</a>' : '<span></span>')
                . ($next ? '<a rel="next" href="' . h(cms_href($next)) . '">' . h($next['title']) . ' &rarr;</a>' : '') . '</nav>';
        }
    }
    echo '</article>';
    if (is_admin($viewer)) echo '<p class="hint"><a href="?p=admin&amp;tab=content&amp;edit=' . (int)$r['id'] . '">Edit this ' . h($r['type']) . '</a></p>';
    page_foot();
}

function page_blog(?array $viewer): void {
    global $CFG;
    $per = max(1, min(50, (int)setting('seo_per_page') ?: 10));
    $pg = max(1, (int)($_GET['page'] ?? 1));
    $total = blog_count();
    $pages = max(1, (int)ceil($total / $per));
    if ($pg > $pages) not_found();
    $rows = q("SELECT * FROM cms WHERE type = 'post' AND status = 'published' AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), [time()])->fetchAll();
    $title = setting('seo_blog_title') ?: 'Blog';
    $h1 = setting('seo_blog_h1') ?: $title;
    $desc = setting('seo_blog_desc') ?: 'Latest posts from ' . $CFG['site_name'] . '.';
    $meta = [
        'title_full' => seo_title($title . ($pg > 1 ? " (Page $pg)" : '')), 'description' => $desc, 'canonical' => blog_url($pg),
        'robots' => $total ? '' : 'noindex, follow',
        'jsonld' => [['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => $title, 'url' => blog_url(), 'description' => $desc],
                     ld_breadcrumbs([['Home', site_url() . '/'], [$title, blog_url()]])],
    ];
    page_head($title, $meta);
    echo '<div class="prose blog"><nav class="crumbs" aria-label="Breadcrumb"><a href="./">Home</a></nav><h1>' . h($h1) . '</h1>';
    if ($setting = setting('seo_blog_desc')) echo '<p class="lede">' . h($setting) . '</p>';
    if (!$rows) echo '<p class="empty">No posts yet.' . (is_admin($viewer) ? ' <a href="?p=admin&amp;tab=content&amp;new=post">Write the first one</a>.' : '') . '</p>';
    foreach ($rows as $r) {
        $when = (int)$r['published_at'];
        $ex = $r['excerpt'] !== '' ? $r['excerpt'] : (function () use ($r) { $i = []; md_render($r['body'], $i); return seo_trim($i['first_p'], 200); })();
        echo '<article class="bpost">';
        if ($r['featured_image'] !== '') echo '<a class="bimg" href="' . h(cms_href($r)) . '" tabindex="-1" aria-hidden="true"><img src="' . h(md_url($r['featured_image'])) . '" alt="" loading="lazy"></a>';
        echo '<div><h2><a href="' . h(cms_href($r)) . '">' . h($r['title']) . '</a></h2><p class="byline"><time datetime="' . h(gmdate('c', $when)) . '">' . h(date('M j, Y', $when)) . '</time></p>'
            . '<p>' . h($ex) . '</p></div></article>';
    }
    if ($pages > 1) {
        echo '<nav class="postnav" aria-label="Pages">' . ($pg > 1 ? '<a rel="prev" href="' . h(blog_href($pg - 1)) . '">&larr; Newer</a>' : '<span></span>')
            . '<span class="hint">Page ' . $pg . ' of ' . $pages . '</span>' . ($pg < $pages ? '<a rel="next" href="' . h(blog_href($pg + 1)) . '">Older &rarr;</a>' : '<span></span>') . '</nav>';
    }
    echo '</div>';
    page_foot();
}

function blog_feed(): never {
    global $CFG;
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $rows = q("SELECT * FROM cms WHERE type = 'post' AND status = 'published' AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT 20", [time()])->fetchAll();
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
        . '<title>' . $x((setting('seo_blog_title') ?: 'Blog') . ' | ' . $CFG['site_name']) . '</title><link>' . $x(blog_url()) . '</link>'
        . '<atom:link href="' . $x(site_url() . '/blog/feed.xml') . '" rel="self" type="application/rss+xml"/>'
        . '<description>' . $x(setting('seo_blog_desc') ?: 'Latest posts from ' . $CFG['site_name']) . '</description>';
    foreach ($rows as $r) {
        $i = []; $html = md_render($r['body'], $i);
        echo '<item><title>' . $x($r['title']) . '</title><link>' . $x(cms_url($r)) . '</link><guid isPermaLink="true">' . $x(cms_url($r)) . '</guid>'
            . '<pubDate>' . gmdate('D, d M Y H:i:s', (int)$r['published_at']) . ' GMT</pubDate>'
            . '<description>' . $x($r['excerpt'] !== '' ? $r['excerpt'] : seo_trim($i['first_p'], 300)) . '</description>'
            . '<content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $html) . ']]></content:encoded></item>';
    }
    echo '</channel></rss>';
    exit;
}

function render_sitemap(): never {
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $urls = [[site_url() . '/', time()]];
    if (blog_count() > 0) $urls[] = [blog_url(), (int)q("SELECT MAX(updated) FROM cms WHERE type = 'post' AND status = 'published'")->fetchColumn() ?: time()];
    foreach (q("SELECT * FROM cms WHERE status = 'published' AND published_at <= ? AND robots_index = 1 ORDER BY type DESC, published_at DESC LIMIT 4000", [time()])->fetchAll() as $r) {
        $canon = $r['canonical'] !== '' ? abs_url($r['canonical']) : cms_url($r);
        if ($canon === cms_url($r)) $urls[] = [$canon, (int)$r['updated']];     // items that point elsewhere do not belong in the sitemap
    }
    if (setting('seo_sitemap_profiles') === '1') {
        foreach (q("SELECT u.username, MAX(p.created) AS last FROM users u JOIN posts p ON p.user_id = u.id AND p.reply_to IS NULL AND p.repost_of IS NULL
                    WHERE u.is_suspended = 0 GROUP BY u.id ORDER BY last DESC LIMIT 1000")->fetchAll() as $r) $urls[] = [profile_url($r['username']), (int)$r['last']];
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as [$loc, $mod]) echo '<url><loc>' . $x($loc) . '</loc><lastmod>' . gmdate('c', $mod) . '</lastmod></url>';
    echo '</urlset>';
    exit;
}

function render_robots(): never {
    header('Content-Type: text/plain; charset=utf-8');
    if (setting('seo_noindex_site') === '1') { echo "User-agent: *\nDisallow: /\n"; exit; }
    echo "User-agent: *\n";
    foreach (['admin', 'login', 'register', 'forgot', 'reset', 'confirm', 'verify', 'settings', 'account', 'notifications', 'bookmarks', 'report', 'search'] as $r) echo "Disallow: /?p=$r\n";
    echo "Disallow: /*?*before=\nDisallow: /data/\nDisallow: /backup.php\n";
    $extra = trim(setting('seo_robots_extra'));
    if ($extra !== '') echo $extra . "\n";
    echo "\nSitemap: " . site_url() . "/sitemap.xml\n";
    exit;
}
