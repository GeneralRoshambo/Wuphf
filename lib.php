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
        avatar_v INTEGER NOT NULL DEFAULT 0,
        created INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        body TEXT NOT NULL,
        created INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS posts_user ON posts(user_id, id)');
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

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
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
            . (int)$u['id'] . '.jpg?v=' . (int)$u['avatar_v'] . '">';
    }
    $hue = crc32(strtolower($name)) % 360;
    return '<span class="av ph" style="width:' . $size . 'px;height:' . $size . 'px;line-height:' . $size
        . 'px;font-size:' . intdiv($size, 2) . 'px;background:hsl(' . $hue . ',45%,45%)">'
        . h(strtoupper($name[0])) . '</span>';
}

/** Validate an uploaded image and store a 128x128 JPEG. Returns an error or null. */
function save_avatar(int $uid, array $f): ?string {
    if (!function_exists('imagecreatefromstring')) return 'Photo uploads need the PHP GD extension (enable it in cPanel > Select PHP Version).';
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'Upload failed.';
    if ($f['size'] > 2 * 1024 * 1024) return 'Photo must be under 2 MB.';
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) return 'Use a JPEG, PNG or GIF.';
    if ($info[0] * $info[1] > 25000000) return 'Image dimensions are too large.';
    $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    if (!$src) return 'Could not read that image.';
    $w = imagesx($src); $h = imagesy($src); $s = min($w, $h);
    $dst = imagecreatetruecolor(128, 128);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), 128, 128, $s, $s);
    $ok = imagejpeg($dst, __DIR__ . '/avatars/' . $uid . '.jpg', 88);
    imagedestroy($src); imagedestroy($dst);
    if (!$ok) return 'Could not save the photo (is avatars/ writable?).';
    q('UPDATE users SET avatar_v = ? WHERE id = ?', [time(), $uid]);
    return null;
}

function render_posts(array $rows, ?array $viewer): void {
    foreach ($rows as $p) {
        echo '<article class="post">';
        echo '<a href="?p=user&amp;u=' . h($p['username']) . '">' . avatar($p, 48) . '</a>';
        echo '<div class="pb"><div class="ph"><a class="un" href="?p=user&amp;u=' . h($p['username']) . '">@'
            . h($p['username']) . '</a> <span class="t">' . h(ago((int)$p['created'])) . '</span>';
        if ($viewer && (int)$viewer['id'] === (int)$p['user_id']) {
            echo '<form method="post" action="?p=delete" class="inl" onsubmit="return confirm(\'Delete this post?\')">'
                . '<input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="id" value="' . (int)$p['id']
                . '"><button class="lnk" title="Delete">delete</button></form>';
        }
        echo '</div><p>' . h($p['body']) . '</p></div></article>';
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
<a href="?p=user&amp;u=<?= h($u['username']) ?>">@<?= h($u['username']) ?></a>
<a href="?p=settings">Settings</a>
<form method="post" action="?p=logout" class="inl"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="lnk">Log out</button></form>
<?php else: ?>
<a href="?p=login">Log in</a><?php if ($CFG['allow_signup']): ?> <a href="?p=register">Sign up</a><?php endif; ?>
<?php endif; ?>
</nav></div></header>
<main class="wrap">
<?php if ($m = flash()): ?><div class="flash"><?= h($m) ?></div><?php endif;
}

function page_foot(): void { echo "</main><script src=\"assets/app.js\"></script></body></html>"; }

function compose_box(): void {
    global $CFG; ?>
<form method="post" action="?p=post" class="compose">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<textarea name="body" id="body" rows="3" maxlength="<?= (int)$CFG['max_chars'] ?>" placeholder="What's happening? (plain text, no links)" required></textarea>
<div class="row"><span id="count" data-max="<?= (int)$CFG['max_chars'] ?>"><?= (int)$CFG['max_chars'] ?></span><button class="btn">Wuphf</button></div>
</form><?php
}
