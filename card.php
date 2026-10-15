<?php
// Server-drawn preview cards (1200x630 PNG) for link previews: ?p=card&id=POST, ?p=card&u=NAME, or ?p=card for the site.
// Uses PHP GD with FreeType and the bundled Inter font. Cards are cached in data/cards/.

const CARD_VERSION = 1;
const CARD_STYLES = [
    'light'  => ['bg' => [[238, 242, 255], [219, 230, 255]], 'card' => [255, 255, 255, 1.0], 'fg' => [14, 14, 16], 'mute' => [108, 108, 117], 'accent' => [29, 91, 255], 'line' => [14, 14, 16, .08]],
    'dark'   => ['bg' => [[11, 11, 13], [26, 26, 34]], 'card' => [23, 23, 27, 1.0], 'fg' => [244, 244, 245], 'mute' => [154, 154, 165], 'accent' => [111, 155, 255], 'line' => [255, 255, 255, .09]],
    'blue'   => ['bg' => [[29, 91, 255], [10, 47, 168]], 'card' => [255, 255, 255, .12], 'fg' => [255, 255, 255], 'mute' => [214, 224, 255], 'accent' => [255, 255, 255], 'line' => [255, 255, 255, .22]],
    'sunset' => ['bg' => [[255, 138, 92], [194, 50, 143]], 'card' => [255, 255, 255, .16], 'fg' => [255, 255, 255], 'mute' => [255, 232, 238], 'accent' => [255, 243, 196], 'line' => [255, 255, 255, .26]],
];

function card_ready(): bool {
    return function_exists('imagettftext') && function_exists('imagecreatetruecolor') && is_file(__DIR__ . '/assets/fonts/Inter-SemiBold.otf');
}
function card_font(string $w): string {
    return __DIR__ . '/assets/fonts/Inter-' . ['semi' => 'SemiBold', 'bold' => 'ExtraBold', 'med' => 'Medium'][$w] . '.otf';
}
/** Image URL for a post or profile preview: the drawn card when the server can draw it, else the site card. */
function card_image(array $q): string { return card_ready() ? card_url($q) : site_url() . '/assets/og-default.png'; }
function card_url(array $q = []): string { return site_url() . '/?p=card' . ($q ? '&' . http_build_query($q) : ''); }

function card_col($im, array $c, float $a = 1.0) { return imagecolorallocatealpha($im, $c[0], $c[1], $c[2], (int)round(127 * (1 - $a))); }

function card_rrect($im, int $x, int $y, int $w, int $h, int $r, int $col): void {
    // Non-overlapping pieces, so translucent colours do not double up where shapes meet.
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r - 1, $y + $h, $col);
    imagefilledrectangle($im, $x, $y + $r, $x + $r - 1, $y + $h - $r, $col);
    imagefilledrectangle($im, $x + $w - $r, $y + $r, $x + $w, $y + $h - $r, $col);
    $d = $r * 2;
    imagefilledarc($im, $x + $r, $y + $r, $d, $d, 180, 270, $col, IMG_ARC_PIE);
    imagefilledarc($im, $x + $w - $r - 1, $y + $r, $d, $d, 270, 360, $col, IMG_ARC_PIE);
    imagefilledarc($im, $x + $r, $y + $h - $r, $d, $d, 90, 180, $col, IMG_ARC_PIE);
    imagefilledarc($im, $x + $w - $r - 1, $y + $h - $r, $d, $d, 0, 90, $col, IMG_ARC_PIE);
}

function card_text_clean(string $s): string {
    $s = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u', '', $s);
    return trim(preg_replace('/[ \t]+/', ' ', $s));
}

/** Width in px of $t at $px pixels. */
function card_w(string $t, float $px, string $font): float {
    $b = imagettfbbox($px * .75, 0, $font, $t);
    return $b ? $b[2] - $b[0] : 0;
}

/** Wrap $text into lines of coloured words, shrinking the type until it fits. */
function card_layout(string $text, float $maxW, float $maxH, float $size): array {
    $font = card_font('semi');
    for (; $size >= 22; $size -= 2) {
        $space = card_w('n n', $size, $font) - card_w('nn', $size, $font);
        $lines = []; $line = []; $w = 0.0;
        foreach (explode("\n", $text) as $pi => $para) {
            if ($pi) { $lines[] = $line; $line = []; $w = 0.0; }
            foreach (preg_split('/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $ww = card_w($word, $size, $font);
                if ($w && $w + $space + $ww > $maxW) { $lines[] = $line; $line = []; $w = 0.0; }
                $line[] = ['t' => $word, 'w' => $ww, 'tag' => (bool)preg_match('/^[@#]/u', $word)];
                $w += ($w ? $space : 0) + $ww;
            }
        }
        $lines[] = $line;
        if (count($lines) * $size * 1.32 <= $maxH) return [$lines, $size, $space];
    }
    return [$lines, 22, $space];
}

/** $d: kind, name (header), sub (small line), body, hue, avatar (file path or ''), initial, style. Returns a GD image, 1200x630. */
function card_render(array $d): \GdImage {
    $S = CARD_STYLES[$d['style']] ?? CARD_STYLES['blue'];
    $W = 1200; $H = 630; $k = 2; $w = $W * $k; $h = $H * $k;
    $im = imagecreatetruecolor($w, $h); imagealphablending($im, true); imagesavealpha($im, true);

    // background: gradient and glow painted small, then scaled up smooth
    $sm = imagecreatetruecolor(120, 63);
    for ($y = 0; $y < 63; $y++) for ($x = 0; $x < 120; $x++) {
        $t = ($x / 120 * .55 + $y / 63 * .45);
        $g = fn($i) => $S['bg'][0][$i] + ($S['bg'][1][$i] - $S['bg'][0][$i]) * $t;
        $dx = $x / 120 - .85; $dy = ($y / 63 - .1) * .52; $glow = max(0, 1 - sqrt($dx * $dx + $dy * $dy) / .6) * .22;
        imagesetpixel($sm, $x, $y, imagecolorallocate($sm, (int)min(255, $g(0) + (255 - $g(0)) * $glow), (int)min(255, $g(1) + (255 - $g(1)) * $glow), (int)min(255, $g(2) + (255 - $g(2)) * $glow)));
    }
    imagecopyresampled($im, $sm, 0, 0, 0, 0, $w, $h, 120, 63); imagedestroy($sm);

    $m = 56 * $k; $cx = $m; $cy = $m; $cw = $w - 2 * $m; $ch = $h - 2 * $m; $r = 36 * $k;
    // soft shadow for the solid cards: stacked, slightly growing, very faint rounded rectangles
    if ($S['card'][3] >= 1.0) {
        for ($i = 14; $i >= 1; $i--) {
            $e = $i * 3 * $k / 2;
            card_rrect($im, (int)($cx - $e), (int)($cy - $e + 14 * $k), (int)($cw + 2 * $e), (int)($ch + 2 * $e), (int)($r + $e), imagecolorallocatealpha($im, 0, 0, 0, 125));
        }
    }
    card_rrect($im, $cx, $cy, $cw, $ch, $r, card_col($im, $S['card'], $S['card'][3]));
    // hairline
    imagesetthickness($im, 3);
    $line = card_col($im, $S['line'], $S['line'][3]);
    imagearc($im, $cx + $r, $cy + $r, $r * 2, $r * 2, 180, 270, $line); imagearc($im, $cx + $cw - $r, $cy + $r, $r * 2, $r * 2, 270, 360, $line);
    imagearc($im, $cx + $r, $cy + $ch - $r, $r * 2, $r * 2, 90, 180, $line); imagearc($im, $cx + $cw - $r, $cy + $ch - $r, $r * 2, $r * 2, 0, 90, $line);
    imageline($im, $cx + $r, $cy, $cx + $cw - $r, $cy, $line); imageline($im, $cx + $r, $cy + $ch, $cx + $cw - $r, $cy + $ch, $line);
    imageline($im, $cx, $cy + $r, $cx, $cy + $ch - $r, $line); imageline($im, $cx + $cw, $cy + $r, $cx + $cw, $cy + $ch - $r, $line);
    imagesetthickness($im, 1);

    $pad = 48 * $k; $x = $cx + $pad; $y = $cy + $pad; $av = 76 * $k;
    // avatar, clipped to a circle
    $a = imagecreatetruecolor($av, $av); imagealphablending($a, false); imagesavealpha($a, true);
    $src = null;
    if ($d['avatar'] !== '' && is_file($d['avatar'])) {
        $raw = (string)@file_get_contents($d['avatar']); $src = $raw !== '' ? @imagecreatefromstring($raw) : null;
    }
    if ($src) imagecopyresampled($a, $src, 0, 0, 0, 0, $av, $av, imagesx($src), imagesy($src));
    else {
        [$hr, $hg, $hb] = card_hsl((int)$d['hue']);
        imagefill($a, 0, 0, imagecolorallocate($a, $hr, $hg, $hb));
        $b = imagettfbbox(38 * 2 * .75, 0, card_font('bold'), $d['initial']);
        imagettftext($a, 38 * 2 * .75, 0, (int)(($av - ($b[2] - $b[0])) / 2 - $b[0]), (int)($av / 2 + 14 * 2), imagecolorallocate($a, 255, 255, 255), card_font('bold'), $d['initial']);
    }
    $clear = imagecolorallocatealpha($a, 0, 0, 0, 127); $rad = $av / 2;
    for ($j = 0; $j < $av; $j++) for ($i = 0; $i < $av; $i++) {
        $dd = hypot($i + .5 - $rad, $j + .5 - $rad);
        if ($dd > $rad) imagesetpixel($a, $i, $j, $clear);
        elseif ($dd > $rad - 1.5) { $c = imagecolorat($a, $i, $j); $al = (int)(127 * ($dd - ($rad - 1.5)) / 1.5); imagesetpixel($a, $i, $j, ($c & 0xFFFFFF) | ($al << 24)); }
    }
    imagealphablending($im, true); imagecopy($im, $a, $x, $y, 0, 0, $av, $av); imagedestroy($a);

    $fg = card_col($im, $S['fg']); $mute = card_col($im, $S['mute']); $acc = card_col($im, $S['accent']);
    $tx = $x + $av + 20 * $k;
    imagettftext($im, 32 * $k * .75, 0, $tx, $y + 34 * $k, $fg, card_font('bold'), $d['name']);
    imagettftext($im, 24 * $k * .75, 0, $tx, $y + 68 * $k, $mute, card_font('med'), $d['sub']);
    // quote mark
    $q = imagecolorallocatealpha($im, $S['accent'][0], $S['accent'][1], $S['accent'][2], 108);
    $qf = card_font('bold'); $qb = imagettfbbox(150 * $k * .75, 0, $qf, "\u{201D}");
    imagettftext($im, 150 * $k * .75, 0, (int)($cx + $cw - $pad + 10 * $k - ($qb[2] - $qb[0])), (int)($y + 150 * $k), $q, $qf, "\u{201D}");

    // body
    $top = $y + $av + 28 * $k; $foot = 64 * $k; $maxH = $cy + $ch - $pad - $foot - $top; $maxW = $cw - 2 * $pad;
    [$lines, $size, $space] = card_layout(card_text_clean($d['body']), $maxW, $maxH, 60 * $k);
    $lh = $size * 1.32; $yy = $top + $size; $bf = card_font('semi');
    foreach ($lines as $li => $ln) {
        $xx = $x;
        foreach ($ln as $wd) { imagettftext($im, $size * .75, 0, (int)$xx, (int)($yy + $li * $lh), $wd['tag'] ? $acc : $fg, $bf, $wd['t']); $xx += $wd['w'] + $space; }
    }
    // footer
    $fy = $cy + $ch - $pad + 4 * $k;
    imagettftext($im, 36 * $k * .75, 0, $x, $fy, $fg, card_font('bold'), 'Wuphf');
    $host = $d['host']; $hb = imagettfbbox(24 * $k * .75, 0, card_font('med'), $host);
    imagettftext($im, 24 * $k * .75, 0, (int)($cx + $cw - $pad - ($hb[2] - $hb[0])), $fy - 2 * $k, $mute, card_font('med'), $host);

    $out = imagecreatetruecolor($W, $H); imagecopyresampled($out, $im, 0, 0, 0, 0, $W, $H, $w, $h); imagedestroy($im);
    return $out;
}

function card_hsl(int $hue, float $s = .45, float $l = .45): array {
    $a = $s * min($l, 1 - $l);
    $f = function ($n) use ($hue, $l, $a) { $k = fmod($n + $hue / 30, 12); return (int)round(255 * ($l - $a * max(-1, min($k - 3, 9 - $k, 1)))); };
    return [$f(0), $f(8), $f(4)];
}

/** Everything the card needs for a post / profile / the site, or null when it should not be shown. */
function card_data(): ?array {
    global $CFG;
    $host = parse_url(site_url(), PHP_URL_HOST) ?: $CFG['site_name'];
    $style = in_array($_GET['s'] ?? '', array_keys(CARD_STYLES), true) ? $_GET['s'] : 'blue';
    $avatarOf = fn($u) => (int)$u['avatar_v'] > 0 ? __DIR__ . '/avatars/' . (int)$u['id'] . '.' . ($u['avatar_ext'] === 'gif' ? 'gif' : 'jpg') : '';
    if (isset($_GET['id'])) {
        $p = q(POST_SELECT . ' WHERE p.id = ?', [(int)$_GET['id']])->fetch();
        if ($p && $p['repost_of']) $p = q(POST_SELECT . ' WHERE p.id = ?', [$p['repost_of']])->fetch();
        if (!$p || (int)q('SELECT is_suspended FROM users WHERE id = ?', [$p['user_id']])->fetchColumn()) return null;
        return ['kind' => 'post', 'style' => $style, 'host' => $host, 'name' => '@' . $p['username'], 'sub' => date('M j, Y', (int)$p['created']), 'body' => $p['body'],
                'hue' => crc32(strtolower($p['username'])) % 360, 'initial' => strtoupper($p['username'][0]), 'avatar' => $avatarOf(['id' => $p['uid'], 'avatar_v' => $p['avatar_v'], 'avatar_ext' => $p['avatar_ext']]),
                'key' => implode('|', [$p['id'], $p['body'], $p['edited_at'], $p['username'], $p['avatar_v']])];
    }
    if (isset($_GET['u'])) {
        $u = q('SELECT * FROM users WHERE username = ? AND is_suspended = 0', [(string)$_GET['u']])->fetch();
        if (!$u) return null;
        $np = (int)q('SELECT COUNT(*) FROM posts WHERE user_id = ? AND repost_of IS NULL', [$u['id']])->fetchColumn();
        $nf = (int)q('SELECT COUNT(*) FROM follows WHERE followee = ?', [$u['id']])->fetchColumn();
        return ['kind' => 'user', 'style' => $style, 'host' => $host, 'name' => '@' . $u['username'], 'sub' => $np . ($np === 1 ? ' post' : ' posts') . '  ·  ' . $nf . ($nf === 1 ? ' follower' : ' followers'), 'body' => $u['bio'] !== '' ? $u['bio'] : 'Find me on ' . $CFG['site_name'] . '.',
                'hue' => crc32(strtolower($u['username'])) % 360, 'initial' => strtoupper($u['username'][0]), 'avatar' => $avatarOf($u), 'key' => implode('|', [$u['id'], $u['bio'], $u['username'], $u['avatar_v'], $np, $nf])];
    }
    return ['kind' => 'site', 'style' => 'blue', 'host' => $host, 'name' => $CFG['site_name'], 'sub' => $host, 'body' => setting('seo_home_desc') !== '' ? setting('seo_home_desc') : 'Short thoughts. 140 characters. No links, no noise.',
            'hue' => 222, 'initial' => 'W', 'avatar' => '', 'key' => 'site|' . setting('seo_home_desc')];
}

function serve_card(): never {
    global $CFG;
    $d = card_data();
    $fallback = __DIR__ . '/assets/og-default.png';
    $send = function (string $file, int $age) { header_remove('Set-Cookie'); header_remove('Expires'); header_remove('Pragma'); header('Content-Type: image/png'); header('Cache-Control: public, max-age=' . $age); header('Content-Length: ' . filesize($file)); readfile($file); exit; };
    if ($d === null) { http_response_code(404); if (is_file($fallback)) $send($fallback, 300); exit; }
    if (!card_ready()) { if (is_file($fallback)) $send($fallback, 3600); http_response_code(503); exit; }
    $dir = dirname($CFG['db_file']) . '/cards';
    $file = $dir . '/' . $d['kind'] . '-' . substr(md5(CARD_VERSION . '|' . $d['style'] . '|' . $d['key']), 0, 20) . '.png';
    if (!is_file($file)) {
        if (!rate_ok('card:' . client_ip(), 120, 3600)) { if (is_file($fallback)) $send($fallback, 60); http_response_code(429); exit; }
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $im = card_render($d);
        $ok = is_dir($dir) && @imagepng($im, $file, 6);
        if (!$ok) { header('Content-Type: image/png'); header('Cache-Control: public, max-age=300'); imagepng($im); exit; }
        if (random_int(1, 50) === 1) foreach (glob($dir . '/*.png') ?: [] as $f) if (filemtime($f) < time() - 30 * 86400) @unlink($f);   // tidy old cards now and then
    }
    $send($file, 86400);
}
