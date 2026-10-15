<?php
$CFG = require __DIR__ . '/config.php';

// Stay logged in for 30 days. Sessions live in our own folder (shared hosts clear the common
// session folder after ~24 minutes) and the cookie is renewed on every visit.
const SESSION_DAYS = 30;
$sessDir = dirname($CFG['db_file']) . '/sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '100');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['lifetime' => SESSION_DAYS * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && isset($_SESSION['uid'])) {   // rolling expiry
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), ['expires' => time() + SESSION_DAYS * 86400, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
}

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
        bio TEXT NOT NULL DEFAULT \'\',
        website TEXT NOT NULL DEFAULT \'\',
        email TEXT NOT NULL DEFAULT \'\',
        email_ok INTEGER NOT NULL DEFAULT 0,
        twofa INTEGER NOT NULL DEFAULT 0,
        is_suspended INTEGER NOT NULL DEFAULT 0,
        sess INTEGER NOT NULL DEFAULT 0,
        pending_email TEXT NOT NULL DEFAULT \'\',
        renamed_at INTEGER NOT NULL DEFAULT 0,
        is_admin INTEGER NOT NULL DEFAULT 0,
        avatar_v INTEGER NOT NULL DEFAULT 0,
        avatar_ext TEXT NOT NULL DEFAULT \'jpg\',
        created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        body TEXT NOT NULL,
        reply_to INTEGER REFERENCES posts(id) ON DELETE SET NULL,
        repost_of INTEGER REFERENCES posts(id) ON DELETE CASCADE,
        created INTEGER NOT NULL)');
    // Upgrade databases created by earlier versions.
    $have = fn($t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('website', $have('users')))    $pdo->exec('ALTER TABLE users ADD COLUMN website TEXT NOT NULL DEFAULT \'\'');
    if (!in_array('avatar_ext', $have('users'))) $pdo->exec('ALTER TABLE users ADD COLUMN avatar_ext TEXT NOT NULL DEFAULT \'jpg\'');
    if (!in_array('reply_to', $have('posts')))   $pdo->exec('ALTER TABLE posts ADD COLUMN reply_to INTEGER REFERENCES posts(id) ON DELETE SET NULL');
    foreach (['email TEXT NOT NULL DEFAULT \'\'', 'email_ok INTEGER NOT NULL DEFAULT 0', 'twofa INTEGER NOT NULL DEFAULT 0',
              'is_suspended INTEGER NOT NULL DEFAULT 0', 'sess INTEGER NOT NULL DEFAULT 0', 'pending_email TEXT NOT NULL DEFAULT \'\'', 'renamed_at INTEGER NOT NULL DEFAULT 0', 'is_admin INTEGER NOT NULL DEFAULT 0'] as $def) {
        if (!in_array(strtok($def, ' '), $have('users'))) $pdo->exec("ALTER TABLE users ADD COLUMN $def");
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_email ON users(lower(email)) WHERE email <> \'\'');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        kind TEXT NOT NULL, hash TEXT NOT NULL,
        expires INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0,
        attempts INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tokens_hash ON tokens(hash)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS throttle (k TEXT NOT NULL, t INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS throttle_k ON throttle(k, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS posts_user ON posts(user_id, id)');
    if (!in_array('repost_of', $have('posts'))) $pdo->exec('ALTER TABLE posts ADD COLUMN repost_of INTEGER REFERENCES posts(id) ON DELETE CASCADE');
    if (!in_array('edited_at', $have('posts'))) $pdo->exec('ALTER TABLE posts ADD COLUMN edited_at INTEGER');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS reposts_once ON posts(user_id, repost_of) WHERE repost_of IS NOT NULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reactions (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        val INTEGER NOT NULL, created INTEGER NOT NULL,
        PRIMARY KEY (user_id, post_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS reactions_post ON reactions(post_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS posts_reply ON posts(reply_to)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS post_tags (
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        tag TEXT NOT NULL,
        PRIMARY KEY (post_id, tag))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tags_tag ON post_tags(tag, post_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS relations (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        target_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        kind TEXT NOT NULL, created INTEGER NOT NULL,
        PRIMARY KEY (user_id, target_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS relations_target ON relations(target_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        post_id INTEGER, user_id INTEGER,
        reason TEXT NOT NULL, note TEXT NOT NULL DEFAULT \'\',
        snapshot TEXT NOT NULL DEFAULT \'\', target_name TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'open\', handled_by TEXT NOT NULL DEFAULT \'\',
        created INTEGER NOT NULL, handled_at INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS reports_status ON reports(status, id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        kind TEXT NOT NULL,
        post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
        is_read INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS notif_once ON notifications(user_id, actor_id, kind, COALESCE(post_id, 0))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS notif_user ON notifications(user_id, id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reserved_names (
        name TEXT PRIMARY KEY COLLATE NOCASE, note TEXT NOT NULL DEFAULT \'\', created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT \'\')');
    $pdo->exec('CREATE TABLE IF NOT EXISTS cms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL, title TEXT NOT NULL, slug TEXT NOT NULL,
        body TEXT NOT NULL DEFAULT \'\', excerpt TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'draft\', published_at INTEGER NOT NULL DEFAULT 0,
        created INTEGER NOT NULL, updated INTEGER NOT NULL,
        author_id INTEGER, author_name TEXT NOT NULL DEFAULT \'\',
        h1 TEXT NOT NULL DEFAULT \'\', meta_title TEXT NOT NULL DEFAULT \'\', meta_desc TEXT NOT NULL DEFAULT \'\',
        robots_index INTEGER NOT NULL DEFAULT 1, robots_follow INTEGER NOT NULL DEFAULT 1, robots_extra TEXT NOT NULL DEFAULT \'\',
        canonical TEXT NOT NULL DEFAULT \'\',
        og_title TEXT NOT NULL DEFAULT \'\', og_desc TEXT NOT NULL DEFAULT \'\', og_image TEXT NOT NULL DEFAULT \'\',
        twitter_card TEXT NOT NULL DEFAULT \'\', focus_kw TEXT NOT NULL DEFAULT \'\',
        schema_type TEXT NOT NULL DEFAULT \'auto\', schema_json TEXT NOT NULL DEFAULT \'\',
        toc INTEGER NOT NULL DEFAULT 0, show_footer INTEGER NOT NULL DEFAULT 0, nav_label TEXT NOT NULL DEFAULT \'\',
        featured_image TEXT NOT NULL DEFAULT \'\', featured_alt TEXT NOT NULL DEFAULT \'\')');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS cms_slug ON cms(type, slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS cms_list ON cms(type, status, published_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS cms_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cms_id INTEGER NOT NULL REFERENCES cms(id) ON DELETE CASCADE,
        title TEXT NOT NULL, body TEXT NOT NULL, saved_at INTEGER NOT NULL, saved_by TEXT NOT NULL DEFAULT \'\')');
    $pdo->exec('CREATE INDEX IF NOT EXISTS cms_rev ON cms_revisions(cms_id, id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS redirects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_path TEXT NOT NULL COLLATE NOCASE UNIQUE, to_url TEXT NOT NULL,
        code INTEGER NOT NULL DEFAULT 301, hits INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT \'\', created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS bookmarks (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        created INTEGER NOT NULL, PRIMARY KEY (user_id, post_id))');
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

// ---- Email, tokens, throttling ---------------------------------------------
function site_url(): string {
    global $CFG;
    if (!empty($CFG['site_url'])) return rtrim($CFG['site_url'], '/');
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/** Version stamp for a static file (its modified time), so browsers refetch it after an update. */
function asset_v(string $f): int { return (int)@filemtime(__DIR__ . '/' . $f); }

/** Which build is live: the first line of build.txt, which each update zip carries. */
function build_id(): string {
    static $b = null;
    return $b ??= is_file(__DIR__ . '/build.txt') ? trim((string)strtok((string)file_get_contents(__DIR__ . '/build.txt'), "\n")) : 'dev';
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0'; }

function send_mail(string $to, string $subject, string $body): bool {
    global $CFG;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) return false;
    $from = $CFG['mail_from'];
    if ($CFG['mail_mode'] === 'log') {
        return file_put_contents(dirname($CFG['db_file']) . '/outbox.log', "To: $to\nSubject: $subject\n\n$body\n-----\n", FILE_APPEND) !== false;
    }
    $headers = "From: {$CFG['site_name']} <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nX-Mailer: Wuphf";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', str_replace("\n", "\r\n", $body), $headers, '-f' . $from);
}

/** Create a token (old unused ones of the same kind for the user are cancelled). Returns the raw value to email. */
function make_token(int $uid, string $kind, int $ttl, ?string $raw = null): string {
    q('UPDATE tokens SET used = 1 WHERE user_id = ? AND kind = ? AND used = 0', [$uid, $kind]);
    $raw ??= bin2hex(random_bytes(24));
    q('INSERT INTO tokens (user_id, kind, hash, expires, created) VALUES (?,?,?,?,?)', [$uid, $kind, hash('sha256', $raw), time() + $ttl, time()]);
    return $raw;
}
function find_token(string $raw, string $kind): ?array {
    if ($raw === '') return null;
    return q('SELECT * FROM tokens WHERE hash = ? AND kind = ? AND used = 0 AND expires > ?', [hash('sha256', $raw), $kind, time()])->fetch() ?: null;
}
function burn_token(int $id): void { q('UPDATE tokens SET used = 1 WHERE id = ?', [$id]); }

/** True while under $max events for $key in the last $window seconds; records this one. */
function rate_ok(string $key, int $max, int $window): bool {
    q('DELETE FROM throttle WHERE t < ?', [time() - 86400]);
    if ((int)q('SELECT COUNT(*) FROM throttle WHERE k = ? AND t > ?', [$key, time() - $window])->fetchColumn() >= $max) return false;
    q('INSERT INTO throttle (k, t) VALUES (?,?)', [$key, time()]);
    return true;
}
function throttle_count(string $key, int $window): int {
    return (int)q('SELECT COUNT(*) FROM throttle WHERE k = ? AND t > ?', [$key, time() - $window])->fetchColumn();
}

function needs_confirm(?array $u): bool {
    global $CFG;
    return $u && $CFG['require_confirm'] && $u['email'] !== '' && !(int)$u['email_ok'];
}

function send_confirmation(array $u): bool {
    global $CFG;
    $raw = make_token((int)$u['id'], 'confirm', 172800);
    return send_mail($u['email'], 'Confirm your email for ' . $CFG['site_name'],
        "Hi @{$u['username']},\n\nConfirm your email address to start posting on {$CFG['site_name']}:\n\n"
        . site_url() . "/?p=confirm&t=$raw\n\nThis link expires in 48 hours. If you did not create this account, you can ignore this email.\n");
}

function send_login_code(array $u): bool {
    global $CFG;
    $code = sprintf('%06d', random_int(0, 999999));
    make_token((int)$u['id'], 'login', 600, $code);
    return send_mail($u['email'], "Your {$CFG['site_name']} login code: $code",
        "Your {$CFG['site_name']} login code is $code\n\nIt expires in 10 minutes. If you did not try to log in, change your password.\n");
}

function me(): ?array {
    static $u = false;
    if ($u === false) {
        $u = empty($_SESSION['uid']) ? null
            : (q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null);
        // Suspended accounts, and sessions from before a password change, are signed out.
        if ($u && ((int)$u['is_suspended'] || (int)($_SESSION['sv'] ?? 0) !== (int)$u['sess'])) $u = null;
        if (!$u) { unset($_SESSION['uid']); }
    }
    return $u;
}

/** Start a logged-in session for $row. */
function sign_in(array $row): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['sv'] = (int)$row['sess'];
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

/** Rebuild a GIF from its image data only. Keeps every frame, timing and the loop setting;
 *  drops comments, plain text and unknown extensions, and anything after the trailer.
 *  Returns null if the file is not a well-formed GIF. */
function clean_gif(string $d): ?string {
    $n = strlen($d);
    if ($n < 14 || !in_array(substr($d, 0, 6), ['GIF87a', 'GIF89a'], true)) return null;
    $flags = ord($d[10]);
    $gct = ($flags & 0x80) ? 3 * (1 << (($flags & 7) + 1)) : 0;
    $pos = 13 + $gct;
    if ($pos > $n) return null;
    $out = substr($d, 0, $pos);
    $frames = 0;
    $skip = function (int $p) use ($d, $n): ?int {          // walk data sub-blocks, return the offset after them
        while (true) {
            if ($p >= $n) return null;
            $len = ord($d[$p]); $p += 1 + $len;
            if ($len === 0) return $p;
        }
    };
    while ($pos < $n) {
        $b = ord($d[$pos]);
        if ($b === 0x3B) return $frames > 0 ? $out . "\x3B" : null;
        if ($b === 0x21) {                                    // extension
            if ($pos + 2 > $n) return null;
            $label = ord($d[$pos + 1]);
            $end = $skip($pos + 2); if ($end === null) return null;
            $blk = substr($d, $pos, $end - $pos);
            if ($label === 0xF9 || ($label === 0xFF && strlen($blk) <= 24 && str_contains($blk, 'NETSCAPE2.0'))) $out .= $blk;
            $pos = $end; continue;
        }
        if ($b === 0x2C) {                                    // frame
            if ($pos + 11 > $n) return null;
            $f = ord($d[$pos + 9]);
            $lct = ($f & 0x80) ? 3 * (1 << (($f & 7) + 1)) : 0;
            $end = $skip($pos + 10 + $lct + 1); if ($end === null) return null;
            $out .= substr($d, $pos, $end - $pos); $pos = $end;
            if (++$frames > 300) return null;
            continue;
        }
        return null;
    }
    return null;
}

/** Validate an uploaded image and store it. GIFs are kept as-is so they stay animated;
 *  JPEG/PNG are re-encoded to a 128x128 JPEG. Returns an error message or null. */
function save_avatar(int $uid, array $f): ?string {
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'Upload failed.';
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) return 'Use a JPEG, PNG or GIF.';
    $dir = __DIR__ . '/avatars/';
    if ($info[2] === IMAGETYPE_GIF) {
        if ($f['size'] > 2 * 1024 * 1024) return 'Animated GIFs must be under 2 MB.';
        if ($info[0] > 800 || $info[1] > 800) return 'GIFs can be at most 800x800 pixels.';
        $gif = clean_gif((string)file_get_contents($f['tmp_name']));
        if ($gif === null) return 'That GIF looks invalid.';
        if (file_put_contents($dir . $uid . '.gif', $gif) === false) return 'Could not save the photo (is avatars/ writable?).';
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
    (SELECT COUNT(*) FROM reactions x WHERE x.post_id = p.id AND x.val = 1) AS likes,
    (SELECT COUNT(*) FROM reactions x WHERE x.post_id = p.id AND x.val = -1) AS dislikes,
    (SELECT COUNT(*) FROM posts rp WHERE rp.repost_of = p.id) AS reposts,
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
    $last = (int)q('SELECT MAX(created) FROM posts WHERE user_id = ? AND repost_of IS NULL', [$u['id']])->fetchColumn();
    return max(0, (int)$CFG['min_post_gap'] - (time() - $last));
}

const ICONS = [
    'reply' => '<path d="M21 11.5a8.5 8.5 0 0 1-12.4 7.55L3 20.5l1.45-5.2A8.5 8.5 0 1 1 21 11.5Z"/>',
    'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    'down'  => '<path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/>',
    'repeat'=> '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    'share' => '<path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/>',
    'bell'  => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'bookmark' => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
    'more'  => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
    'sun'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'moon'  => '<path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5a8.5 8.5 0 1 0 10.7 10.7Z"/>',
];
function icon(string $n, string $cls = ''): string {
    return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ICONS[$n] . '</svg>';
}

/** Ids the viewer follows (one query per request). */
function following_ids(?array $viewer): array {
    static $ids = null;
    if (!$viewer) return [];
    return $ids ??= array_map('intval', q('SELECT followee FROM follows WHERE follower = ?', [$viewer['id']])->fetchAll(PDO::FETCH_COLUMN));
}

/** What the viewer has done to these posts: reaction (1 / -1) and reposts. */
function viewer_state(?array $viewer, array $ids): array {
    $react = []; $reposted = []; $marks = [];
    if ($viewer && $ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (q("SELECT post_id, val FROM reactions WHERE user_id = ? AND post_id IN ($in)", [$viewer['id']])->fetchAll() as $r) $react[(int)$r['post_id']] = (int)$r['val'];
        foreach (q("SELECT repost_of FROM posts WHERE user_id = ? AND repost_of IN ($in)", [$viewer['id']])->fetchAll(PDO::FETCH_COLUMN) as $id) $reposted[(int)$id] = true;
        foreach (q("SELECT post_id FROM bookmarks WHERE user_id = ? AND post_id IN ($in)", [$viewer['id']])->fetchAll(PDO::FETCH_COLUMN) as $id) $marks[(int)$id] = true;
    }
    return [$react, $reposted, $marks];
}

/** Fresh counts and the viewer's own state for one post, as sent back to the page after a click. */
function post_state(int $pid, array $u): array {
    $c = q('SELECT (SELECT COUNT(*) FROM reactions WHERE post_id = ?1 AND val = 1) AS l,
                   (SELECT COUNT(*) FROM reactions WHERE post_id = ?1 AND val = -1) AS d,
                   (SELECT COUNT(*) FROM posts WHERE repost_of = ?1) AS r', [$pid])->fetch();
    return ['pid' => $pid, 'likes' => (int)$c['l'], 'dislikes' => (int)$c['d'], 'reposts' => (int)$c['r'],
            'mine' => (int)q('SELECT val FROM reactions WHERE user_id = ? AND post_id = ?', [$u['id'], $pid])->fetchColumn(),
            'reposted' => (bool)q('SELECT 1 FROM posts WHERE user_id = ? AND repost_of = ?', [$u['id'], $pid])->fetch()];
}

function json_out(array $d, int $code = 200): never {
    http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); exit;
}

/** Seconds left in the author's edit window for a post (0 when closed). */
function edit_left(array $p): int {
    global $CFG;
    return max(0, (int)($CFG['edit_window'] ?? 300) - (time() - (int)$p['created']));
}

function permalink(int $id): string { return site_url() . '/?p=status&id=' . $id; }

/** One small action button: a form so it works without JavaScript, upgraded in place by app.js. */
function act_button(?array $viewer, string $kind, int $pid, int $count, bool $on, string $label, string $icon, string $route, string $v = ''): string {
    $inner = icon($icon) . '<span class="n">' . ($count ? $count : '') . '</span>';
    if (!$viewer) return '<a class="act" href="?p=login" aria-label="' . h($label) . ' (log in)" title="' . h($label) . '">' . $inner . '</a>';
    return '<form method="post" action="?p=' . $route . '" class="inl actf"><input type="hidden" name="csrf" value="' . csrf() . '">'
        . '<input type="hidden" name="id" value="' . $pid . '">' . ($v !== '' ? '<input type="hidden" name="v" value="' . $v . '">' : '')
        . '<button class="act' . ($on ? ' on' : '') . '" data-kind="' . $kind . '" data-pid="' . $pid . '" aria-pressed="' . ($on ? 'true' : 'false')
        . '" aria-label="' . h($label) . '" title="' . h($label) . '">' . $inner . '</button></form>';
}

function render_posts(array $rows, ?array $viewer, bool $thread = false): void {
    $following = following_ids($viewer);
    // A repost row stands for the original post; load the originals in one query.
    $origIds = array_values(array_unique(array_filter(array_column($rows, 'repost_of'))));
    $orig = [];
    if ($origIds) foreach (q(POST_SELECT . ' WHERE p.id IN (' . implode(',', array_map('intval', $origIds)) . ')')->fetchAll() as $o) $orig[(int)$o['id']] = $o;
    $shown = [];
    foreach ($rows as $r) $shown[] = $r['repost_of'] ? (int)$r['repost_of'] : (int)$r['id'];
    [$react, $reposted, $marks] = viewer_state($viewer, $shown);
    $hide = visible_hidden($viewer);

    foreach ($rows as $row) {
        $by = null; $p = $row;
        if ($row['repost_of']) {
            if (!isset($orig[(int)$row['repost_of']])) continue;
            $p = $orig[(int)$row['repost_of']]; $by = $row['username'];
        }
        // Skip posts from people the viewer blocked or muted, who blocked them, or who are suspended.
        if (in_array((int)$p['user_id'], $hide, true) || in_array((int)$row['user_id'], $hide, true)) continue;
        $pid = (int)$p['id'];
        echo '<article class="post" id="p' . (int)$row['id'] . '">';
        echo '<a href="?p=user&amp;u=' . h($p['username']) . '">' . avatar($p, 40) . '</a>';
        echo '<div class="pb">';
        if ($by !== null) echo '<div class="rpby">' . icon('repeat') . '<a href="?p=user&amp;u=' . h($by) . '">@' . h($by) . '</a> reposted</div>';
        echo '<div class="phd"><a class="un" href="?p=user&amp;u=' . h($p['username']) . '">@' . h($p['username']) . '</a> <a class="t" href="?p=status&amp;id=' . $pid . '">' . h(ago((int)$p['created'])) . '</a>' . (!empty($p['edited_at']) ? ' <span class="edited" title="Edited">edited</span>' : '');
        $author = (int)$p['user_id'];
        if ($viewer && $author !== (int)$viewer['id'] && !in_array($author, $following, true)) {
            echo '<form method="post" action="?p=follow" class="inl folf" data-uid="' . $author . '">'
                . '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="id" value="' . $author
                . '"><input type="hidden" name="at" value="' . (int)$row['id'] . '"><button class="fol" title="Follow @' . h($p['username']) . '">Follow</button></form>';
        }
        if ($viewer && (int)$viewer['id'] === (int)$row['user_id']) {
            echo '<form method="post" action="?p=delete" class="inl delf" onsubmit="return confirm(\'' . ($by !== null ? 'Undo this repost?' : 'Delete this post?') . '\')">'
                . '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="id" value="' . (int)$row['id']
                . '"><button class="lnk del" title="Delete">' . ($by !== null ? 'undo' : 'delete') . '</button></form>';
        }
        echo post_menu($p, $viewer, isset($marks[$pid]));
        echo '</div>';
        if ($p['reply_to'] && $p['reply_user'] && !$thread) {
            echo '<div class="rl">Replying to <a href="?p=status&amp;id=' . (int)$p['reply_to'] . '">@' . h($p['reply_user']) . '</a></div>';
        }
        echo '<p>' . render_body($p['body']) . '</p>';
        $left = ($by === null && $viewer && (int)$viewer['id'] === $author) ? edit_left($p) : 0;
        if ($left > 0) {
            echo '<details class="editbox" data-left="' . $left . '"><summary>Edit <span class="ecd">' . intdiv($left, 60) . ':' . sprintf('%02d', $left % 60) . '</span></summary>'
                . '<form method="post" action="?p=edit" class="editf"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="id" value="' . $pid . '">'
                . '<textarea name="body" rows="3" maxlength="' . (int)hard_max() . '" data-max="' . (int)$GLOBALS['CFG']['max_chars'] . '" data-free="' . (int)$GLOBALS['CFG']['free_mentions'] . '">' . h($p['body']) . '</textarea>'
                . '<div class="row"><span class="ecount"></span><button type="button" class="btn ghost ecancel">Cancel</button><button class="btn">Save</button></div></form></details>';
        }
        $n = (int)$p['replies'];
        echo '<div class="acts"><a class="act" href="?p=status&amp;id=' . $pid . '#reply" aria-label="Reply" title="Reply">' . icon('reply') . '<span class="n">' . ($n ?: '') . '</span></a>'
            . act_button($viewer, 'repost', $pid, (int)$p['reposts'], isset($reposted[$pid]), 'Repost', 'repeat', 'repost')
            . act_button($viewer, 'like', $pid, (int)$p['likes'], ($react[$pid] ?? 0) === 1, 'Like', 'heart', 'react', '1')
            . act_button($viewer, 'dislike', $pid, (int)$p['dislikes'], ($react[$pid] ?? 0) === -1, 'Dislike', 'down', 'react', '-1')
            . '<button type="button" class="act" data-share="' . h(permalink($pid)) . '" data-text="' . h(mb_substr($p['body'], 0, 100)) . '" data-body="' . h($p['body']) . '" data-user="' . h($p['username']) . '" data-pid="' . $pid . '" data-hue="' . (crc32(strtolower($p['username'])) % 360) . '" data-when="' . h(date('M j, Y', (int)$p['created'])) . '" data-av="' . ((int)$p['avatar_v'] > 0 ? h('avatars/' . (int)$p['uid'] . '.' . ($p['avatar_ext'] === 'gif' ? 'gif' : 'jpg') . '?v=' . (int)$p['avatar_v']) : '') . '" data-embed="' . h(site_url() . '/?p=embed&id=' . $pid) . '" data-embedjs="' . h(site_url() . '/assets/embed.js') . '" aria-label="Share" title="Share">' . icon('share') . '</button>'
            . '</div>';
        echo '</div></article>';
    }
}

function page_head(string $title, array $meta = []): void {
    global $CFG; $u = me();
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<base href="<?= h(request_base()) ?>">
<?php seo_head($title, $meta); ?>
<link rel="stylesheet" href="assets/style.css?v=<?= asset_v('assets/style.css') ?>">
<link rel="manifest" href="manifest.webmanifest">
<link rel="alternate" type="application/rss+xml" title="<?= h($CFG['site_name']) ?>" href="?p=rss">
<?php if (is_admin($u)) echo '<meta name="wuphf-build" content="' . h(build_id()) . '">'; ?>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f4f4f5" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
<script>try{var t=localStorage.getItem('wuphf-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Wuphf">
<?= seo_custom_html('head') ?>
</head><body>
<header><div class="bar"><a class="logo" href="./"><?= h($CFG['site_name']) ?></a><nav class="nav">
<?php if ($u): $unread = unread_count($u); ?>
<a href="?p=user&amp;u=<?= h($u['username']) ?>">@<?= h($u['username']) ?></a>
<a href="?p=public">Public</a>
<a href="?p=settings">Settings</a>
<?php if (is_admin($u)): ?><a href="?p=admin">Admin</a><?php endif; ?>
<form method="post" action="?p=logout" class="inl"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="lnk">Log out</button></form>
<?php else: ?>
<a href="?p=public">Public</a>
<a href="?p=login">Log in</a><?php if ($CFG['allow_signup']): ?> <a href="?p=register">Sign up</a><?php endif; ?>
<?php endif; ?>
<span class="icons">
<?php if ($u): ?>
<a class="icon-btn nbell" href="?p=notifications" aria-label="Notifications<?= $unread ? ' (' . $unread . ' new)' : '' ?>" title="Notifications"><?= icon('bell') ?><?php if ($unread): ?><span class="badge"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
<a class="icon-btn" href="?p=bookmarks" aria-label="Bookmarks" title="Bookmarks"><?= icon('bookmark') ?></a>
<?php endif; ?>
<button type="button" class="icon-btn" id="theme" aria-label="Switch between light and dark mode" title="Light / dark"><?= icon('moon', 'moon') ?><?= icon('sun', 'sun') ?></button>
</span>
</nav>
<form class="search" action="./" method="get" role="search"><input type="hidden" name="p" value="search">
<input type="search" name="q" value="<?= h(($_GET['p'] ?? '') === 'search' ? ($_GET['q'] ?? '') : '') ?>" placeholder="Search" maxlength="60" aria-label="Search"></form>
</div></header>
<main class="col<?= !empty($GLOBALS['wide']) ? ' wide' : '' ?>">
<?php if ($m = flash()): ?><div class="flash <?= h($m['k']) ?>" role="status"><?= h($m['m']) ?></div><?php endif;
if (needs_confirm($u)): ?><div class="flash banner" role="status">Confirm your email to start posting. We sent a link to <b><?= h($u['email']) ?></b>.
<form method="post" action="?p=resend" class="inl"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="lnk">Resend link</button></form></div><?php endif;
}

function page_foot(): void {
    $links = '<a href="?p=page&amp;n=rules">Rules</a><a href="?p=page&amp;n=privacy">Privacy</a><a href="?p=rss">RSS</a>';
    $links = '<a href="' . h(blog_href()) . '">' . h(setting('seo_blog_title') ?: 'Blog') . '</a>' . $links;
    $pages = '';
    foreach (footer_pages() as $fp) $pages .= '<a href="' . h(cms_href($fp)) . '">' . h($fp['nav_label'] !== '' ? $fp['nav_label'] : $fp['title']) . '</a>';
    $extra = '';
    foreach ($GLOBALS['extra_js'] ?? [] as $js) $extra .= '<script src="assets/' . h($js) . '?v=' . asset_v('assets/' . $js) . '"></script>';
    echo '</main><footer class="foot">' . ($pages !== '' ? '<nav class="flinks fpages" aria-label="Pages">' . $pages . '</nav>' : '') . '<nav class="flinks" aria-label="Site">' . $links . '</nav>Built by <a href="https://modularitynet.com" rel="nofollow noopener" target="_blank">Modularity Networks</a></footer>'
        . (is_admin(me()) ? '<div class="build">Build ' . h(build_id()) . '</div>' : '') . '<div id="toast" role="status" aria-live="polite"></div>'
        . '<script src="assets/app.js?v=' . asset_v('assets/app.js') . '"></script>' . $extra . seo_custom_html('foot') . '</body></html>';
}

function compose_box(int $replyTo = 0, string $prefill = ''): void {
    global $CFG;
    $prompts = require __DIR__ . '/prompts.php';
    $ph = $replyTo ? 'Write your reply' : $prompts[array_rand($prompts)]; ?>
<form method="post" action="?p=post" class="compose" id="<?= $replyTo ? 'reply' : 'compose' ?>">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<?php if ($replyTo): ?><input type="hidden" name="reply_to" value="<?= $replyTo ?>"><?php endif; ?>
<?= avatar(me(), 40) ?>
<div class="cbody">
<textarea name="body" id="body" rows="<?= $replyTo ? 2 : 3 ?>" maxlength="<?= hard_max() ?>"
 placeholder="<?= h($ph) ?>" required><?= h($prefill) ?></textarea>
<div class="row"><span class="hintkey">Enter to send</span><span id="count" data-max="<?= (int)$CFG['max_chars'] ?>" data-free="<?= (int)$CFG['free_mentions'] ?>" data-cd="<?= cooldown_left(me()) ?>" data-gap="<?= (int)$CFG['min_post_gap'] ?>"><?= (int)$CFG['max_chars'] ?></span><button class="btn" id="sendbtn" data-label="<?= $replyTo ? 'Reply' : 'Post' ?>"><?= $replyTo ? 'Reply' : 'Post' ?></button></div>
</div>
</form><?php
}

require __DIR__ . '/extras.php';
require __DIR__ . '/cms.php';
require __DIR__ . '/cms_admin.php';
require __DIR__ . '/schedule.php';
