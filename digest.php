<?php
// Email digests (opt-in), follower lists and @mention suggestions.

// ---- Secrets and links ------------------------------------------------------
function app_secret(): string {
    $s = setting('app_secret');
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(24)); set_setting('app_secret', $s); }
    return $s;
}
function unsub_token(int $uid): string { return hash_hmac('sha256', 'unsub:' . $uid, app_secret()); }
function unsub_url(int $uid): string { return site_url() . '/?p=unsub&u=' . $uid . '&t=' . unsub_token($uid); }

// ---- Digest -------------------------------------------------------------------
const DIGEST_PERIOD = ['daily' => 86400, 'weekly' => 604800];

/** Build the text of one digest, or null when there is nothing worth sending. */
function digest_body(array $u, int $since): ?string {
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
    $out = 'Hi @' . $u['username'] . ",\n\n";
    if ($parts) {
        $out .= 'While you were away: ' . implode(', ', $parts) . ".\n\n";
        $shown = 0;
        foreach ($notes as $n) {
            if (!in_array($n['kind'], ['reply', 'mention'], true) || $shown >= 5) continue;
            $verb = $n['kind'] === 'reply' ? 'replied' : 'mentioned you';
            $out .= '@' . $n['username'] . ' ' . $verb . ($n['body'] ? ': "' . mb_substr(preg_replace('/\s+/', ' ', $n['body']), 0, 100) . '"' : '') . "\n" . site_url() . '/?p=status&id=' . (int)$n['post_id'] . "\n\n";
            $shown++;
        }
    }
    if ($top) {
        $out .= "Popular with the people you follow:\n\n";
        foreach ($top as $p) $out .= '@' . $p['username'] . ': ' . mb_substr(preg_replace('/\s+/', ' ', $p['body']), 0, 140) . "\n" . site_url() . '/?p=status&id=' . (int)$p['id'] . "\n\n";
    }
    return $out . 'Open Wuphf: ' . site_url() . "/\n\n--\nYou get this because email digests are on in your settings (" . site_url() . "/?p=settings).\nStop these emails: " . unsub_url((int)$u['id']) . "\n";
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
        $body = digest_body($u, $since);
        if ($body === null) continue;
        if (send_mail($u['email'], 'Your ' . $CFG['site_name'] . ' ' . ($u['digest'] === 'daily' ? 'daily' : 'weekly') . ' digest', $body)) $sent++;
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
    echo '<p class="crumb"><a class="back" href="?p=user&amp;u=' . h($prof['username']) . '">&larr; @' . h($prof['username']) . '</a></p>';
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
