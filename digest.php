<?php
// Email digests (opt-in), follower lists and @mention suggestions.

// ---- Secrets and links ------------------------------------------------------
function app_secret(): string {
    $s = setting('app_secret');
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(24)); set_setting('app_secret', $s); }
    return $s;
}
function unsub_token(int $uid, string $kind = 'digest'): string { return hash_hmac('sha256', ($kind === 'digest' ? 'unsub:' : 'unsub-' . $kind . ':') . $uid, app_secret()); }
function unsub_url(int $uid, string $kind = 'digest'): string { return site_url() . '/?p=unsub&u=' . $uid . ($kind === 'digest' ? '' : '&k=' . $kind) . '&t=' . unsub_token($uid, $kind); }

// ---- Digest -------------------------------------------------------------------
const DIGEST_PERIOD = ['daily' => 86400, 'weekly' => 604800];

function hsl_hex(int $h, float $s = .45, float $l = .45): string {
    $a = $s * min($l, 1 - $l);
    $f = function ($n) use ($h, $l, $a) { $k = fmod($n + $h / 30, 12); return $l - $a * max(-1, min($k - 3, 9 - $k, 1)); };
    return sprintf('#%02x%02x%02x', round($f(0) * 255), round($f(8) * 255), round($f(4) * 255));
}

/** Wrap content in the site's look: Modularity blue, rounded card, light and dark. */
function mail_shell(string $preheader, string $inner, string $foot): string {
    global $CFG;
    $name = h($CFG['site_name']);
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . $name . '</title>'
        . '<style>@media (prefers-color-scheme:dark){.bg{background:#0a0a0a!important}.card{background:#141414!important;border-color:#2a2a2c!important}.fg{color:#f2f2f3!important}.mute{color:#9a9aa3!important}.chip{background:#1e1e1f!important}.line{border-color:#2a2a2c!important}.lnk{color:#6f9bff!important}}'
        . '@media (max-width:600px){.wrap{width:100%!important}.pad{padding:20px!important}}</style></head>'
        . '<body class="bg" style="margin:0;padding:0;background:#f4f4f5">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . h($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg" style="background:#f4f4f5"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" class="wrap" style="width:560px;max-width:100%">'
        . '<tr><td style="padding:0 4px 14px"><a href="' . h(site_url()) . '/" class="fg" style="font:800 26px -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;letter-spacing:-.06em;color:#0e0e10;text-decoration:none">' . $name . '</a></td></tr>'
        . '<tr><td class="card pad" style="background:#ffffff;border:1px solid #e7e7ea;border-radius:16px;padding:28px;font:15px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#0e0e10">' . $inner . '</td></tr>'
        . '<tr><td align="center" class="mute" style="padding:18px 8px 0;font:12px/1.6 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#6c6c75">' . $foot
        . '<br>Built by <a class="lnk" href="https://modularitynet.com" rel="nofollow noopener" style="color:#6c6c75">Modularity Networks</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Build one digest as [plain text, html], or null when there is nothing worth sending. */
function digest_body(array $u, int $since): ?array {
    global $CFG;
    $hide = hidden_ids($u);
    $notes = q('SELECT n.kind, n.post_id, a.username, a.id AS aid, p.body FROM notifications n JOIN users a ON a.id = n.actor_id
                LEFT JOIN posts p ON p.id = n.post_id WHERE n.user_id = ? AND n.is_read = 0 AND n.created > ? ORDER BY n.id DESC LIMIT 200', [$u['id'], $since])->fetchAll();
    $notes = array_values(array_filter($notes, fn($n) => !in_array((int)$n['aid'], $hide, true)));
    $count = ['reply' => 0, 'mention' => 0, 'like' => 0, 'repost' => 0, 'follow' => 0];
    foreach ($notes as $n) if (isset($count[$n['kind']])) $count[$n['kind']]++;
    $ids = $hide ? implode(',', array_map('intval', $hide)) : '0';
    $top = q("SELECT p.id, p.body, usr.username, (SELECT COUNT(*) FROM reactions x WHERE x.post_id = p.id AND x.val = 1) AS likes
              FROM posts p JOIN users usr ON usr.id = p.user_id
              WHERE p.repost_of IS NULL AND p.created > ? AND p.user_id IN (SELECT followee FROM follows WHERE follower = ?) AND p.user_id NOT IN ($ids)
              ORDER BY likes DESC, p.id DESC LIMIT 3", [$since, $u['id']])->fetchAll();
    if (!array_sum($count) && !$top) return null;

    $label = ['reply' => ['reply', 'replies'], 'mention' => ['mention', 'mentions'], 'like' => ['like', 'likes'], 'repost' => ['repost', 'reposts'], 'follow' => ['new follower', 'new followers']];
    $parts = [];
    foreach ($count as $k => $n) if ($n) $parts[] = $n . ' ' . $label[$k][$n === 1 ? 0 : 1];
    $clip = fn($s, $n) => mb_substr(preg_replace('/\s+/', ' ', (string)$s), 0, $n);
    $link = fn($id) => site_url() . '/?p=status&id=' . (int)$id;
    $period = $u['digest'] === 'daily' ? 'daily' : 'weekly';
    $sans = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    // ---- plain text
    $t = 'Hi @' . $u['username'] . ",\n\n";
    if ($parts) $t .= 'While you were away: ' . implode(', ', $parts) . ".\n\n";
    // ---- html
    $hI = '<p class="fg" style="margin:0 0 6px;font:700 22px/1.25 ' . $sans . ';color:#0e0e10">Your ' . $period . ' digest</p>'
        . '<p class="mute" style="margin:0 0 20px;color:#6c6c75">Hi @' . h($u['username']) . ', here is what happened while you were away.</p>';
    if ($parts) {
        $hI .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 8px;border-collapse:separate;border-spacing:6px 0;margin-left:-6px;width:calc(100% + 12px)"><tr>';
        $cells = array_filter($count);
        $w = floor(100 / max(1, count($cells)));
        foreach ($cells as $k => $n)
            $hI .= '<td class="chip" align="center" width="' . $w . '%" style="background:#f0f0f2;border-radius:12px;padding:12px 4px"><div class="fg" style="font:800 24px/1.1 ' . $sans . ';color:#1d5bff">' . $n . '</div><div class="mute" style="font-size:12px;color:#6c6c75;padding-top:2px">' . h($label[$k][$n === 1 ? 0 : 1]) . '</div></td>';
        $hI .= '</tr></table>';
        $shown = 0; $items = '';
        foreach ($notes as $n) {
            if (!in_array($n['kind'], ['reply', 'mention'], true) || $shown >= 5) continue;
            $verb = $n['kind'] === 'reply' ? 'replied' : 'mentioned you';
            $t .= '@' . $n['username'] . ' ' . $verb . ($n['body'] ? ': "' . $clip($n['body'], 100) . '"' : '') . "\n" . $link($n['post_id']) . "\n\n";
            $items .= '<tr><td valign="top" width="36" style="padding:14px 12px 14px 0"><div style="width:32px;height:32px;line-height:32px;border-radius:16px;text-align:center;color:#fff;font:700 15px/32px ' . $sans . ';background:' . hsl_hex(crc32(strtolower($n['username'])) % 360) . '">' . h(strtoupper($n['username'][0])) . '</div></td>'
                . '<td class="line" style="padding:14px 0;border-top:1px solid #e7e7ea"><a class="fg" href="' . h($link($n['post_id'])) . '" style="text-decoration:none;color:#0e0e10"><b>@' . h($n['username']) . '</b> <span class="mute" style="color:#6c6c75">' . $verb . '</span>'
                . ($n['body'] ? '<br><span style="color:inherit">' . h($clip($n['body'], 100)) . '</span>' : '') . '</a></td></tr>';
            $shown++;
        }
        if ($items) $hI .= '<p class="mute" style="margin:22px 0 0;font:700 12px ' . $sans . ';letter-spacing:.06em;text-transform:uppercase;color:#6c6c75">Replies and mentions</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $items . '</table>';
    }
    if ($top) {
        $t .= "Popular with the people you follow:\n\n";
        $hI .= '<p class="mute" style="margin:24px 0 10px;font:700 12px ' . $sans . ';letter-spacing:.06em;text-transform:uppercase;color:#6c6c75">Popular with people you follow</p>';
        foreach ($top as $p) {
            $t .= '@' . $p['username'] . ': ' . $clip($p['body'], 140) . "\n" . $link($p['id']) . "\n\n";
            $hI .= '<a href="' . h($link($p['id'])) . '" style="text-decoration:none"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px"><tr><td class="line" style="border:1px solid #e7e7ea;border-radius:12px;padding:14px 16px">'
                . '<div class="fg" style="font-weight:700;color:#0e0e10">@' . h($p['username']) . '</div><div class="fg" style="padding-top:4px;font-size:16px;line-height:1.4;color:#0e0e10">' . h($clip($p['body'], 140)) . '</div>'
                . ((int)$p['likes'] ? '<div class="mute" style="padding-top:8px;font-size:12px;color:#6c6c75">' . (int)$p['likes'] . ' ' . ((int)$p['likes'] === 1 ? 'like' : 'likes') . '</div>' : '') . '</td></tr></table></a>';
        }
    }
    $hI .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 0"><tr><td style="background:#1d5bff;border-radius:999px"><a href="' . h(site_url()) . '/" style="display:inline-block;padding:12px 26px;color:#ffffff;font:700 15px ' . $sans . ';text-decoration:none">Open ' . h($CFG['site_name']) . '</a></td></tr></table>';
    $t .= 'Open Wuphf: ' . site_url() . "/\n\n--\nYou get this because email digests are on in your settings (" . site_url() . "/?p=settings).\nStop these emails: " . unsub_url((int)$u['id']) . "\n";
    $foot = 'You get this ' . $period . ' digest because it is on in your <a class="lnk" href="' . h(site_url()) . '/?p=settings" style="color:#6c6c75">settings</a>.<br><a class="lnk" href="' . h(unsub_url((int)$u['id'])) . '" style="color:#6c6c75">Unsubscribe</a>';
    return [$t, mail_shell($parts ? implode(', ', $parts) : 'Popular with the people you follow', $hI, $foot)];
}

/** Send digests that are due. Runs from normal page loads (at most every 10 minutes) or from the cron URL. */
function send_digests_due(bool $force = false): int {
    global $CFG;
    static $ran = false;
    if (!$force) {
        if ($ran) return 0; $ran = true;
        if (time() - (int)setting('digest_run') < 600) return 0;
        set_setting('digest_run', (string)time());
    }
    $sent = 0;
    $rows = q("SELECT * FROM users WHERE digest <> 'off' AND email_ok = 1 AND email <> '' AND is_suspended = 0
               AND digest_at + CASE digest WHEN 'daily' THEN 86100 ELSE 603600 END <= CAST(? AS INTEGER) ORDER BY digest_at LIMIT 15", [time()])->fetchAll();
    foreach ($rows as $u) {
        $since = (int)$u['digest_at'];
        q('UPDATE users SET digest_at = ? WHERE id = ?', [time(), $u['id']]);   // advance first so a failure never causes a repeat storm
        $mail = digest_body($u, $since);
        if ($mail === null) continue;
        if (send_mail($u['email'], 'Your ' . $CFG['site_name'] . ' ' . ($u['digest'] === 'daily' ? 'daily' : 'weekly') . ' digest', $mail[0], $mail[1])) $sent++;
    }
    return $sent;
}

// ---- Followers / following lists --------------------------------------------------
function page_follows(?array $viewer): void {
    global $CFG;
    $name = (string)($_GET['u'] ?? '');
    $prof = q('SELECT * FROM users WHERE username = ? AND is_suspended = 0', [$name])->fetch();
    if (!$prof) not_found('No such account', 'That account does not exist.');
    $tab = ($_GET['tab'] ?? '') === 'following' ? 'following' : 'followers';
    $per = 40; $page = max(1, (int)($_GET['page'] ?? 1));
    [$me, $them] = $tab === 'followers' ? ['followee', 'follower'] : ['follower', 'followee'];
    $hide = visible_hidden($viewer); $ids = $hide ? implode(',', array_map('intval', $hide)) : '0';
    $total = (int)q("SELECT COUNT(*) FROM follows f JOIN users x ON x.id = f.$them WHERE f.$me = ? AND x.id NOT IN ($ids)", [$prof['id']])->fetchColumn();
    $rows = q("SELECT x.* FROM follows f JOIN users x ON x.id = f.$them WHERE f.$me = ? AND x.id NOT IN ($ids) ORDER BY f.rowid DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$prof['id']])->fetchAll();
    page_head('@' . $prof['username'] . ': ' . ($tab === 'followers' ? 'Followers' : 'Following'));
    echo '<h1 class="sr">@' . h($prof['username']) . ' ' . ($tab === 'followers' ? 'followers' : 'following') . '</h1><p class="crumb"><a class="back" href="?p=user&amp;u=' . h($prof['username']) . '">&larr; @' . h($prof['username']) . '</a></p>';
    tab_bar(['followers' => 'Followers', 'following' => 'Following'], $tab, 'p=follows&amp;u=' . h($prof['username']) . '&amp;');
    $mine = $viewer ? following_ids($viewer) : [];
    foreach ($rows as $r) {
        echo '<div class="person"><a href="?p=user&amp;u=' . h($r['username']) . '">' . avatar($r, 40) . '</a>'
            . '<a class="pname" href="?p=user&amp;u=' . h($r['username']) . '"><b>@' . h($r['username']) . '</b>'
            . ($r['bio'] !== '' ? '<br><small>' . h(mb_substr($r['bio'], 0, 80)) . '</small>' : '') . '</a>';
        if ($viewer && (int)$viewer['id'] !== (int)$r['id'] && !in_array((int)$r['id'], $mine, true))
            echo '<form method="post" action="?p=follow" class="inl folf" data-uid="' . (int)$r['id'] . '"><input type="hidden" name="csrf" value="' . csrf() . '">'
                . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="fol">Follow</button></form>';
        echo '</div>';
    }
    if (!$rows) echo '<p class="empty">' . ($tab === 'followers' ? 'No followers yet.' : 'Not following anyone yet.') . '</p>';
    $qs = 'p=follows&amp;u=' . h($prof['username']) . '&amp;tab=' . $tab . '&amp;page=';
    if ($page > 1 || $total > $page * $per)
        echo '<nav class="postnav">' . ($page > 1 ? '<a href="?' . $qs . ($page - 1) . '">&larr; Newer</a>' : '<span></span>') . ($total > $page * $per ? '<a href="?' . $qs . ($page + 1) . '">Older &rarr;</a>' : '<span></span>') . '</nav>';
    page_foot();
}

// ---- @mention suggestions -----------------------------------------------------------
function mention_suggestions(array $viewer): never {
    $q = ltrim((string)($_GET['q'] ?? ''), '@');
    if (!preg_match('/^[A-Za-z0-9_]{0,15}$/', $q) || !rate_ok('mention:' . $viewer['id'], 600, 3600)) json_out(['users' => []]);
    $hide = hidden_ids($viewer); $ids = $hide ? implode(',', array_map('intval', $hide)) : '0';
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $rows = q("SELECT u.id, u.username, u.avatar_v, u.avatar_ext,
                      (SELECT 1 FROM follows f WHERE f.follower = ?1 AND f.followee = u.id) AS fol
               FROM users u WHERE u.username LIKE ?2 ESCAPE '\\' AND u.is_suspended = 0 AND u.id NOT IN ($ids)
               ORDER BY fol DESC, u.username COLLATE NOCASE LIMIT 6", [$viewer['id'], $like])->fetchAll();
    json_out(['users' => array_map(fn($r) => ['u' => $r['username'], 'av' => (int)$r['avatar_v'] > 0 ? 'avatars/' . (int)$r['id'] . '.' . ($r['avatar_ext'] === 'gif' ? 'gif' : 'jpg') . '?v=' . (int)$r['avatar_v'] : '',
        'hue' => crc32(strtolower($r['username'])) % 360], $rows)]);
}

// ---- "N new posts" pill -------------------------------------------------------------
function newposts_marker(string $scope): void { echo '<div id="newpill" class="newpill" data-scope="' . h($scope) . '" hidden></div>'; }

// ---- Styled transactional emails ----------------------------------------------------------
/** Send an email in the site's look. $o: preheader, heading, paras[] (plain text), code, button [label, url], note, foot (html). */
function send_styled(string $to, string $subject, array $o): bool {
    global $CFG;
    $sans = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $t = ($o['heading'] ?? '') . "\n\n"; $hI = '<p class="fg" style="margin:0 0 14px;font:700 22px/1.25 ' . $sans . ';color:#0e0e10">' . h($o['heading'] ?? '') . '</p>';
    foreach ($o['paras'] ?? [] as $p) { $t .= $p . "\n\n"; $hI .= '<p class="fg" style="margin:0 0 14px;color:#0e0e10">' . h($p) . '</p>'; }
    if (!empty($o['code'])) {
        $t .= $o['code'] . "\n\n";
        $hI .= '<p class="chip fg" style="margin:6px 0 18px;padding:14px 0;text-align:center;background:#f0f0f2;border-radius:12px;font:800 32px/1 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.3em;color:#1d5bff">' . h($o['code']) . '</p>';
    }
    if (!empty($o['button'])) {
        $t .= $o['button'][0] . ': ' . $o['button'][1] . "\n\n";
        $hI .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 18px"><tr><td style="background:#1d5bff;border-radius:999px"><a href="' . h($o['button'][1]) . '" style="display:inline-block;padding:12px 26px;color:#ffffff;font:700 15px ' . $sans . ';text-decoration:none">' . h($o['button'][0]) . '</a></td></tr></table>'
            . '<p class="mute" style="margin:0 0 14px;font-size:12px;color:#6c6c75;word-break:break-all">Or copy this link: ' . h($o['button'][1]) . '</p>';
    }
    if (!empty($o['note'])) { $t .= $o['note'] . "\n"; $hI .= '<p class="mute" style="margin:0;font-size:13px;color:#6c6c75">' . h($o['note']) . '</p>'; }
    $foot = $o['foot'] ?? 'You are getting this because of activity on your ' . h($CFG['site_name']) . ' account.';
    if (!empty($o['foot_text'])) $t .= "\n-- \n" . $o['foot_text'] . "\n";
    return send_mail($to, $subject, trim($t) . "\n", mail_shell($o['preheader'] ?? ($o['paras'][0] ?? ''), $hI, $foot));
}

/** Email someone straight away about a reply or mention, if they asked for that. */
function instant_email(int $to, int $actor, string $kind, int $postId): void {
    global $CFG;
    $u = q('SELECT * FROM users WHERE id = ? AND notify_email = 1 AND email_ok = 1 AND email <> \'\' AND is_suspended = 0', [$to])->fetch();
    if (!$u || !rate_ok('instant:' . $to, 6, 3600)) return;   // over the limit: the digest still covers it
    $a = q('SELECT username FROM users WHERE id = ?', [$actor])->fetchColumn();
    $body = (string)q('SELECT body FROM posts WHERE id = ?', [$postId])->fetchColumn();
    if (!$a || $body === '') return;
    $url = site_url() . '/?p=status&id=' . $postId;
    $what = $kind === 'reply' ? 'replied to you' : 'mentioned you';
    send_styled($u['email'], '@' . $a . ' ' . $what . ' on ' . $CFG['site_name'], [
        'preheader' => mb_substr($body, 0, 90), 'heading' => '@' . $a . ' ' . $what,
        'paras' => ['"' . mb_substr(preg_replace('/\s+/', ' ', $body), 0, 200) . '"'], 'button' => ['View on ' . $CFG['site_name'], $url],
        'foot' => 'You asked for instant emails about replies and mentions.<br><a class="lnk" href="' . h(unsub_url((int)$u['id'], 'instant')) . '" style="color:#6c6c75">Turn these off</a>',
        'foot_text' => 'Turn these emails off: ' . unsub_url((int)$u['id'], 'instant')]);
}
