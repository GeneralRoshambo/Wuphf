<?php
$CFG = require __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

function db(): PDO {
    global $CFG;
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . $CFG['db_file']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        pass TEXT NOT NULL,
        bio TEXT NOT NULL DEFAULT "",
        website TEXT NOT NULL DEFAULT "",
        avatar_v INTEGER NOT NULL DEFAULT 0,
        avatar_ext TEXT NOT NULL DEFAULT "jpg",
        created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        body TEXT NOT NULL,
        reply_to INTEGER REFERENCES posts(id) ON DELETE SET NULL,
        created INTEGER NOT NULL)');
    // Upgrade databases created by earlier versions.
    $have = fn($t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('website', $have('users')))    $pdo->exec('ALTER TABLE users ADD COLUMN website TEXT NOT NULL DEFAULT ""');
    if (!in_array('avatar_ext', $have('users'))) $pdo->exec('ALTER TABLE users ADD COLUMN avatar_ext TEXT NOT NULL DEFAULT "jpg"');
    if (!in_array('reply_to', $have('posts')))   $pdo->exec('ALTER TABLE posts ADD COLUMN reply_to INTEGER REFERENCES posts(id) ON DELETE SET NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS posts_user ON posts(user_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS posts_reply ON posts(reply_to)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS post_tags (
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        tag TEXT NOT NULL,
        PRIMARY KEY (post_id, tag))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tags_tag ON post_tags(tag, post_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS follows (
        follower INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        followee INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        PRIMARY KEY (follower, followee))');
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower($s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null, $e = null) { preg_match_all('/./su', $s, $m); return implode('', array_slice($m[0], $a, $l)); } }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : preg_match_all('/./su', $s);
}

/** Collapse whitespace/control chars so a post is one plain line of text. */
function clean(string $s): string {
    $s = preg_replace('/[\p{C}\s]+/u', ' ', $s);
    return trim((string)$s);
}

/** Links are not allowed anywhere (posts, bios). */
function has_link(string $s): bool {
    if (preg_match('~(https?|ftp|mailto|data|javascript)\s*:|//|\bwww\s*\.~i', $s)) return true;
    $tlds = 'com|net|org|edu|gov|io|co|us|uk|ca|de|fr|es|it|nl|ru|cn|jp|in|br|au|info|biz|me|tv|xyz|app|dev|ly|gl|to|cc|sh|gg|link|site|online|store|tech|club|top|live|news|page|blog|ai|fm|am|be|ch|se|no|dk|fi|pl|nz|za|mx|ar|eu|asia|pro|name|mobi|ws|bz';
    return (bool)preg_match('~(?<![\w@])[a-z0-9][a-z0-9-]*(\.[a-z0-9-]+)*\.(' . $tlds . ')(?![a-z0-9])~i', $s);
}

function me(): ?array {
    static $u = false;
    if ($u === false) {
        $u = empty($_SESSION['uid']) ? null
            : (q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null);
        if (!$u) unset($_SESSION['uid']);
    }
    return $u;
}

function csrf(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function check_csrf(): void {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad request (CSRF). Go back and try again.');
    }
}

function flash(?string $msg = null, string $kind = ''): ?array {
    if ($msg !== null) { $_SESSION['flash'] = ['m' => $msg, 'k' => $kind]; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function go(string $url): never { header('Location: ' . $url); exit; }

function ago(int $t): string {
    $d = time() - $t;
    if ($d < 60) return $d . 's';
    if ($d < 3600) return intdiv($d, 60) . 'm';
    if ($d < 86400) return intdiv($d, 3600) . 'h';
    return date('M j, Y', $t);
}

function avatar(array $u, int $size = 48): string {
    $name = $u['username'];
    if ((int)$u['avatar_v'] > 0) {
        return '<img class="av" width="' . $size . '" height="' . $size . '" alt="" src="avatars/'
            . (int)($u['uid'] ?? $u['id']) . '.' . ($u['avatar_ext'] === 'gif' ? 'gif' : 'jpg') . '?v=' . (int)$u['avatar_v'] . '">';
    }
    $hue = crc32(strtolower($name)) % 360;
    return '<span class="av ph" style="width:' . $size . 'px;height:' . $size . 'px;line-height:' . $size
        . 'px;font-size:' . intdiv($size, 2) . 'px;background:hsl(' . $hue . ',45%,45%)">'
        . h(strtoupper($name[0])) . '</span>';
}

/** Validate an uploaded image and store it. GIFs are kept as-is so they stay animated;
 *  JPEG/PNG are re-encoded to a 128x128 JPEG. Returns an error message or null. */
function save_avatar(int $uid, array $f): ?string {
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'Upload failed.';
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) return 'Use a JPEG, PNG or GIF.';
    $dir = __DIR__ . '/avatars/';
    if ($info[2] === IMAGETYPE_GIF) {
        if ($f['size'] > 1024 * 1024) return 'Animated GIFs must be under 1 MB.';
        if ($info[0] > 512 || $info[1] > 512) return 'GIFs can be at most 512x512 pixels.';
        $raw = (string)file_get_contents($f['tmp_name']);
        if (strncmp($raw, 'GIF8', 4) !== 0 || str_contains($raw, '<?')) return 'That GIF looks invalid.';
        if (file_put_contents($dir . $uid . '.gif', $raw) === false) return 'Could not save the photo (is avatars/ writable?).';
        @unlink($dir . $uid . '.jpg'); $ext = 'gif';
    } else {
        if (!function_exists('imagecreatefromstring')) return 'JPEG/PNG uploads need the PHP GD extension (enable it in cPanel > Select PHP Version). GIFs work without it.';
        if ($f['size'] > 2 * 1024 * 1024) return 'Photo must be under 2 MB.';
        if ($info[0] * $info[1] > 25000000) return 'Image dimensions are too large.';
        $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if (!$src) return 'Could not read that image.';
        $w = imagesx($src); $h = imagesy($src); $s = min($w, $h);
        $dst = imagecreatetruecolor(128, 128);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), 128, 128, $s, $s);
        $ok = imagejpeg($dst, $dir . $uid . '.jpg', 88);
        imagedestroy($src); imagedestroy($dst);
        if (!$ok) return 'Could not save the photo (is avatars/ writable?).';
        @unlink($dir . $uid . '.gif'); $ext = 'jpg';
    }
    q('UPDATE users SET avatar_v = ?, avatar_ext = ? WHERE id = ?', [time(), $ext, $uid]);
    return null;
}

/** Profile link: http(s) only, no spaces, short. Returns [value, error]. */
function clean_website(string $w): array {
    $w = trim($w);
    if ($w === '') return ['', null];
    if (!preg_match('~^https?://~i', $w)) $w = 'https://' . $w;
    $p = filter_var($w, FILTER_VALIDATE_URL) ? parse_url($w) : false;
    if (!$p || empty($p['host']) || !str_contains($p['host'], '.') || strlen($w) > 100
        || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) return ['', 'That profile link is not a valid web address (max 100 characters).'];
    return [$w, null];
}

// ---- Text: mentions, hashtags, length ------------------------------------
const MENTION_RE = '/(?<!\w)@[A-Za-z0-9_]{3,15}(?!\w) ?/u'; // includes one trailing space

/** Length that counts toward the limit: the first N @usernames are free. */
function counted_len(string $s): int {
    global $CFG;
    preg_match_all(MENTION_RE, $s, $m);
    $free = 0;
    foreach (array_slice($m[0], 0, (int)$CFG['free_mentions']) as $x) $free += len($x);
    return len($s) - $free;
}

/** Longest raw post we will accept: the limit plus the free mentions at full size. */
function hard_max(): int { global $CFG; return $CFG['max_chars'] + $CFG['free_mentions'] * 17; }

function extract_tags(string $s): array {
    preg_match_all('/(?<!\w)#([\p{L}\p{N}_]{1,30})/u', $s, $m);
    $tags = [];
    foreach ($m[1] as $t) if (!ctype_digit($t)) $tags[mb_strtolower($t, 'UTF-8')] = true;
    return array_slice(array_keys($tags), 0, 10);
}

/** Escape a post body and turn @mentions and #hashtags into links. */
function render_body(string $raw): string {
    $out = '';
    foreach (preg_split('/((?<!\w)[#@][\p{L}\p{N}_]+)/u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
        if ($i % 2 === 0) { $out .= h($part); continue; }
        $w = substr($part, 1);
        if ($part[0] === '@' && preg_match('/^[A-Za-z0-9_]{3,15}$/', $w)) {
            $out .= '<a href="?p=user&amp;u=' . h($w) . '">@' . h($w) . '</a>';
        } elseif ($part[0] === '#' && len($w) <= 30 && !ctype_digit($w)) {
            $out .= '<a href="?p=tag&amp;t=' . h(urlencode(mb_strtolower($w, 'UTF-8'))) . '">#' . h($w) . '</a>';
        } else $out .= h($part);
    }
    return $out;
}

const POST_SELECT = 'SELECT p.*, u.id AS uid, u.username, u.avatar_v, u.avatar_ext,
    (SELECT COUNT(*) FROM posts r WHERE r.reply_to = p.id) AS replies,
    (SELECT u2.username FROM posts pp JOIN users u2 ON u2.id = pp.user_id WHERE pp.id = p.reply_to) AS reply_user
    FROM posts p JOIN users u ON u.id = p.user_id';

/** Run a feed query ($where uses ? placeholders) with keyset pagination and print it.
 *  $threads shows each reply under the post it answers. */
function feed(string $where, array $args, string $qs, string $empty = 'No posts yet.', bool $threads = false): void {
    global $CFG;
    $per = (int)$CFG['per_page'];
    $before = (int)($_GET['before'] ?? 0);
    $cur = $before > 0 ? ' AND p.id < ' . $before : '';
    $rows = q(POST_SELECT . " WHERE ($where)$cur ORDER BY p.id DESC LIMIT " . ($per + 1), $args)->fetchAll();
    $show = array_slice($rows, 0, $per);
    if ($threads) render_reply_threads($show, me()); else render_posts($show, me());
    if (!$rows) echo '<p class="empty">' . h($empty) . '</p>';
    if (count($rows) > $per) echo '<p class="more"><a href="?' . $qs . 'before=' . (int)$rows[$per - 1]['id'] . '">Older &rarr;</a></p>';
}

/** Posts above $id, root first (up to 12 levels). $cache is shared across calls. */
function ancestors(int $id, array &$cache): array {
    $chain = [];
    for ($i = 0; $i < 12 && $id; $i++) {
        if (!array_key_exists($id, $cache)) $cache[$id] = q(POST_SELECT . ' WHERE p.id = ?', [$id])->fetch() ?: null;
        if (!$cache[$id]) break;
        array_unshift($chain, $cache[$id]);
        $id = (int)$cache[$id]['reply_to'];
    }
    return $chain;
}

/** Replies tabs: one card per conversation. Each card shows the full chain from the root
 *  to every reply on this page, plus up to two follow-ups under each of those replies,
 *  and links to the whole thread. Newest activity first. */
function render_reply_threads(array $replies, ?array $viewer): void {
    $cache = [];
    $groups = [];
    foreach ($replies as $r) {
        $cache[(int)$r['id']] = $r;
        $chain = ancestors((int)$r['reply_to'], $cache);
        if (!$chain) continue;
        $root = (int)$chain[0]['id'];
        $g = $groups[$root] ?? ['hits' => [], 'inc' => [$root => true], 'max' => 0];
        foreach ($chain as $c) $g['inc'][(int)$c['id']] = true;
        $g['inc'][(int)$r['id']] = true;
        $g['hits'][(int)$r['id']] = true;
        $g['max'] = max($g['max'], (int)$r['id']);
        $groups[$root] = $g;
    }
    if (!$groups) return;
    uasort($groups, fn($a, $b) => $b['max'] <=> $a['max']);

    // Up to two follow-ups under every reply that is on this page.
    $hitIds = [];
    foreach ($groups as $g) foreach ($g['hits'] as $id => $_) $hitIds[] = $id;
    $seen = [];
    foreach (q(POST_SELECT . ' WHERE p.reply_to IN (' . implode(',', $hitIds) . ') ORDER BY p.id')->fetchAll() as $c) {
        $cache[(int)$c['id']] = $c;
        $pid = (int)$c['reply_to'];
        $seen[$pid] = ($seen[$pid] ?? 0) + 1;
        if ($seen[$pid] > 2) continue;
        foreach ($groups as $root => &$g) if (isset($g['hits'][$pid])) $g['inc'][(int)$c['id']] = true;
        unset($g);
    }

    foreach ($groups as $root => $g) {
        // Busy threads: keep the root, the path to the newest reply and its follow-ups,
        // then fill up to 12 posts with the most recent of the rest.
        if (count($g['inc']) > 12) {
            $keep = [$root => true, $g['max'] => true];
            foreach (ancestors((int)$cache[$g['max']]['reply_to'], $cache) as $c) $keep[(int)$c['id']] = true;
            foreach ($g['inc'] as $id => $_) if (isset($cache[$id]) && (int)$cache[$id]['reply_to'] === $g['max']) $keep[$id] = true;
            $rest = array_diff(array_keys($g['inc']), array_keys($keep));
            rsort($rest);
            // Add newest-first, but only posts whose parent is already kept, so the card stays connected.
            do {
                $added = false;
                foreach ($rest as $k => $id) {
                    if (count($keep) >= 12) break 2;
                    if (isset($keep[(int)($cache[$id]['reply_to'] ?? 0)])) { $keep[$id] = true; unset($rest[$k]); $added = true; }
                }
            } while ($added);
            $g['inc'] = $keep;
        }
        $kids = [];
        foreach (array_keys($g['inc']) as $id) {
            if ($id !== $root && isset($cache[$id])) $kids[(int)$cache[$id]['reply_to']][] = $cache[$id];
        }
        foreach ($kids as &$list) usort($list, fn($a, $b) => $a['id'] <=> $b['id']);
        unset($list);
        $total = (int)q('WITH RECURSIVE t(id) AS (SELECT id FROM posts WHERE reply_to = ?
                  UNION ALL SELECT p.id FROM posts p JOIN t ON p.reply_to = t.id) SELECT COUNT(*) FROM (SELECT id FROM t LIMIT 500)', [$root])->fetchColumn();
        $walk = function (array $node, int $depth) use (&$walk, $kids, $g, $viewer) {
            $id = (int)$node['id'];
            echo '<div class="tc' . ($depth >= 4 ? ' flatc' : '') . ($id === $g['max'] ? ' hit' : '') . '">';
            render_posts([$node], $viewer, true);
            if (!empty($kids[$id])) {
                echo '<div class="tcin">';
                foreach ($kids[$id] as $k) $walk($k, $depth + 1);
                echo '</div>';
            }
            echo '</div>';
        };
        echo '<div class="thread">';
        $walk($cache[$root], 0);
        echo '<a class="tview" href="?p=status&amp;id=' . $root . '">View full thread &middot; ' . $total . ' repl' . ($total == 1 ? 'y' : 'ies') . ' &rarr;</a></div>';
    }
}

/** Full conversation under a post, as branches. Each branch collapses; long sibling
 *  runs fold behind "show more"; very deep branches continue on their own page. */
function render_tree(int $rootId, ?array $viewer): void {
    $ids = q('WITH RECURSIVE t(id, d) AS (SELECT id, 1 FROM posts WHERE reply_to = ?
              UNION ALL SELECT p.id, t.d + 1 FROM posts p JOIN t ON p.reply_to = t.id WHERE t.d < 12)
              SELECT id FROM t LIMIT 400', [$rootId])->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $kids = [];
    foreach (q(POST_SELECT . ' WHERE p.id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY p.id')->fetchAll() as $r) {
        $kids[(int)$r['reply_to']][] = $r;
    }
    $count = function (int $id) use (&$count, $kids): int {
        $n = 0;
        foreach ($kids[$id] ?? [] as $k) $n += 1 + $count((int)$k['id']);
        return $n;
    };
    $plural = fn(int $n) => $n . ' repl' . ($n == 1 ? 'y' : 'ies');
    $walk = function (int $parent, int $depth) use (&$walk, $kids, $viewer, $count, $plural) {
        $list = $kids[$parent] ?? [];
        foreach ($list as $i => $r) {
            if ($i === 5 && count($list) > 5) echo '<details class="tmorebox"><summary>Show ' . (count($list) - 5) . ' more ' . (count($list) - 5 == 1 ? 'reply' : 'replies') . '</summary>';
            echo '<div class="tnode">';
            render_posts([$r], $viewer, true);
            $id = (int)$r['id'];
            if (!empty($kids[$id])) {
                $n = $count($id);
                if ($depth >= 5) {
                    echo '<a class="tcont" href="?p=status&amp;id=' . $id . '">Continue this thread (' . $plural($n) . ') &rarr;</a>';
                } else {
                    // Top two levels start open; deeper branches start folded.
                    echo '<details class="tkids"' . ($depth <= 2 ? ' open' : '') . '><summary><span class="sumtxt">' . $plural($n) . '</span></summary>';
                    $walk($id, $depth + 1);
                    echo '</details>';
                }
            }
            echo '</div>';
        }
        if (count($list) > 5) echo '</details>';
    };
    $walk($rootId, 1);
}

/** Open one nested level per ancestor (root first) so the page reads as a single tree.
 *  Call chain_close() with the returned count after printing the focus post and replies. */
function chain_open(array $chain, ?array $viewer): int {
    foreach ($chain as $i => $r) {
        echo '<div class="tc' . ($i >= 4 ? ' flatc' : '') . '">';
        render_posts([$r], $viewer, true);
        echo '<div class="tcin">';
    }
    return count($chain);
}
function chain_close(int $n): void { echo str_repeat('</div></div>', $n); }

/** Tab bar. $tabs = [key => label]; links keep $base and set tab=key. */
function tab_bar(array $tabs, string $cur, string $base): void {
    echo '<div class="tabs">';
    foreach ($tabs as $k => $label) {
        echo '<a href="?' . $base . 'tab=' . h($k) . '" class="' . ($cur === $k ? 'on' : '') . '">' . h($label) . '</a>';
    }
    echo '</div>';
}

function cooldown_left(?array $u): int {
    global $CFG;
    if (!$u) return 0;
    $last = (int)q('SELECT MAX(created) FROM posts WHERE user_id = ?', [$u['id']])->fetchColumn();
    return max(0, (int)$CFG['min_post_gap'] - (time() - $last));
}

function render_posts(array $rows, ?array $viewer, bool $thread = false): void {
    foreach ($rows as $p) {
        echo '<article class="post" id="p' . (int)$p['id'] . '">';
        echo '<a href="?p=user&amp;u=' . h($p['username']) . '">' . avatar($p, 48) . '</a>';
        echo '<div class="pb"><div class="ph"><a class="un" href="?p=user&amp;u=' . h($p['username']) . '">@'
            . h($p['username']) . '</a> <a class="t" href="?p=status&amp;id=' . (int)$p['id'] . '">' . h(ago((int)$p['created'])) . '</a>';
        if ($viewer && (int)$viewer['id'] === (int)$p['user_id']) {
            echo '<form method="post" action="?p=delete" class="inl" onsubmit="return confirm(\'Delete this post?\')">'
                . '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="id" value="' . (int)$p['id']
                . '"><button class="lnk del" title="Delete">delete</button></form>';
        }
        echo '</div>';
        if ($p['reply_to'] && $p['reply_user'] && !$thread) {
            echo '<div class="rl">Replying to <a href="?p=status&amp;id=' . (int)$p['reply_to'] . '">@' . h($p['reply_user']) . '</a></div>';
        }
        echo '<p>' . render_body($p['body']) . '</p>';
        echo '<div class="acts"><a href="?p=status&amp;id=' . (int)$p['id'] . '#reply">Reply'
            . ((int)$p['replies'] ? ' · ' . (int)$p['replies'] : '') . '</a></div>';
        echo '</div></article>';
    }
}

function page_head(string $title): void {
    global $CFG; $u = me();
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · <?= h($CFG['site_name']) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#1d6fb8">
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Wuphf">
</head><body>
<header><div class="wrap"><a class="logo" href="./"><?= h($CFG['site_name']) ?></a><nav>
<?php if ($u): ?>
<a href="?p=public">Public</a>
<a href="?p=user&amp;u=<?= h($u['username']) ?>">@<?= h($u['username']) ?></a>
<a href="?p=settings">Settings</a>
<form method="post" action="?p=logout" class="inl"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="lnk">Log out</button></form>
<?php else: ?>
<a href="?p=public">Public</a> <a href="?p=login">Log in</a><?php if ($CFG['allow_signup']): ?> <a href="?p=register">Sign up</a><?php endif; ?>
<?php endif; ?>
</nav>
<form class="search" action="./" method="get" role="search"><input type="hidden" name="p" value="search">
<input type="search" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search" maxlength="60" aria-label="Search"></form>
</div></header>
<main class="wrap">
<?php if ($m = flash()): ?><div class="flash <?= h($m['k']) ?>" role="status"><?= h($m['m']) ?></div><?php endif;
}

function page_foot(): void { echo "</main><script src=\"assets/app.js\"></script></body></html>"; }

function compose_box(int $replyTo = 0, string $prefill = ''): void {
    global $CFG;
    $prompts = require __DIR__ . '/prompts.php';
    $ph = $replyTo ? 'Write your reply' : $prompts[array_rand($prompts)]; ?>
<form method="post" action="?p=post" class="compose" id="<?= $replyTo ? 'reply' : 'compose' ?>">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<?php if ($replyTo): ?><input type="hidden" name="reply_to" value="<?= $replyTo ?>"><?php endif; ?>
<textarea name="body" id="body" rows="<?= $replyTo ? 2 : 3 ?>" maxlength="<?= hard_max() ?>"
 placeholder="<?= h($ph) ?>" required><?= h($prefill) ?></textarea>
<div class="row"><span class="hintkey">Enter to send</span><span id="count" data-max="<?= (int)$CFG['max_chars'] ?>" data-free="<?= (int)$CFG['free_mentions'] ?>" data-cd="<?= cooldown_left(me()) ?>" data-gap="<?= (int)$CFG['min_post_gap'] ?>"><?= (int)$CFG['max_chars'] ?></span><button class="btn" id="sendbtn" data-label="<?= $replyTo ? 'Reply' : 'Wuphf' ?>"><?= $replyTo ? 'Reply' : 'Wuphf' ?></button></div>
</form><?php
}
