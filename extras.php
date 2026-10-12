<?php
// Moderation, notifications, account tools and other site-wide helpers.
// Loaded at the end of lib.php, so everything in lib.php is available here.

// ---- Who can see what -----------------------------------------------------
function is_admin(?array $u): bool {
    global $CFG;
    return $u && in_array(strtolower($u['username']), array_map('strtolower', (array)$CFG['admins']), true);
}

/** The viewer's own block / mute choices: user id => 'block' | 'mute'. */
function relations_of(?array $viewer): array {
    static $cache = [];
    $key = $viewer ? (int)$viewer['id'] : 0;
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        if ($viewer) foreach (q('SELECT target_id, kind FROM relations WHERE user_id = ?', [$viewer['id']])->fetchAll() as $r) $cache[$key][(int)$r['target_id']] = $r['kind'];
    }
    return $cache[$key];
}

/** Accounts whose posts this viewer does not see: blocked, muted, blocked-by, and suspended. */
function hidden_ids(?array $viewer): array {
    static $cache = [];
    $key = $viewer ? (int)$viewer['id'] : 0;
    if (isset($cache[$key])) return $cache[$key];
    $ids = array_map('intval', q('SELECT id FROM users WHERE is_suspended = 1')->fetchAll(PDO::FETCH_COLUMN));
    if ($viewer) {
        $ids = array_merge($ids, array_keys(relations_of($viewer)),
            array_map('intval', q("SELECT user_id FROM relations WHERE target_id = ? AND kind = 'block'", [$viewer['id']])->fetchAll(PDO::FETCH_COLUMN)));
    }
    return $cache[$key] = array_values(array_unique($ids));
}
/** hidden_ids(), except an account whose profile the viewer is looking at after muting it. */
function visible_hidden(?array $viewer): array { return array_values(array_diff(hidden_ids($viewer), [$GLOBALS['WUPHF_SHOW'] ?? 0])); }

function blocked_between(int $a, int $b): bool {
    return (bool)q("SELECT 1 FROM relations WHERE kind = 'block' AND ((user_id = ?1 AND target_id = ?2) OR (user_id = ?2 AND target_id = ?1))", [$a, $b])->fetch();
}

// ---- Notifications --------------------------------------------------------
function notify(int $to, int $actor, string $kind, ?int $post = null): void {
    if ($to === $actor) return;
    if (q('SELECT 1 FROM relations WHERE user_id = ? AND target_id = ?', [$to, $actor])->fetch()) return;   // they blocked or muted this person
    q('INSERT OR IGNORE INTO notifications (user_id, actor_id, kind, post_id, created) VALUES (?,?,?,?,?)', [$to, $actor, $kind, $post, time()]);
}
function unnotify(int $to, int $actor, string $kind, ?int $post = null): void {
    q('DELETE FROM notifications WHERE user_id = ? AND actor_id = ? AND kind = ? AND COALESCE(post_id, 0) = CAST(? AS INTEGER)', [$to, $actor, $kind, $post ?? 0]);
}
function unread_count(?array $u): int {
    static $n = null;
    if (!$u) return 0;
    return $n ??= (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']])->fetchColumn();
}

// ---- Small helpers --------------------------------------------------------
function not_found(string $title = 'Page not found', string $msg = 'That page does not exist, or it has been removed.'): never {
    http_response_code(404);
    page_head($title);
    echo '<h1>' . h($title) . '</h1><p class="empty">' . h($msg) . '</p>'
        . '<form class="card" action="./" method="get" role="search"><input type="hidden" name="p" value="search"><input type="search" name="q" placeholder="Search people and posts" maxlength="60"></form>'
        . '<p class="center"><a class="btn" href="./">Back to the feed</a></p>';
    page_foot();
    exit;
}

function trending_tags(int $n = 8): array {
    return q('SELECT t.tag, COUNT(*) AS c FROM post_tags t JOIN posts p ON p.id = t.post_id
              JOIN users u ON u.id = p.user_id AND u.is_suspended = 0
              WHERE p.created > ? GROUP BY t.tag ORDER BY c DESC, MAX(p.created) DESC LIMIT ' . (int)$n, [time() - 3 * 86400])->fetchAll();
}
function render_trending(): void {
    $tags = trending_tags();
    if (!$tags) return;
    echo '<div class="trend"><span class="lab">Trending</span>';
    foreach ($tags as $t) echo '<a class="chip" href="?p=tag&amp;t=' . h(urlencode($t['tag'])) . '">#' . h($t['tag']) . ' <small>' . (int)$t['c'] . '</small></a>';
    echo '</div>';
}

function who_to_follow(array $u, int $n = 5): array {
    return q("SELECT us.*, (SELECT COUNT(*) FROM follows f WHERE f.followee = us.id) AS fc FROM users us
              WHERE us.id != ?1 AND us.is_suspended = 0
                AND us.id NOT IN (SELECT followee FROM follows WHERE follower = ?1)
                AND us.id NOT IN (SELECT target_id FROM relations WHERE user_id = ?1)
                AND us.id NOT IN (SELECT user_id FROM relations WHERE target_id = ?1 AND kind = 'block')
                AND EXISTS (SELECT 1 FROM posts p WHERE p.user_id = us.id)
              ORDER BY fc DESC, us.id DESC LIMIT " . (int)$n, [$u['id']])->fetchAll();
}
function render_suggestions(array $u, string $heading = 'Who to follow'): void {
    $rows = who_to_follow($u);
    if (!$rows) return;
    echo '<h2>' . h($heading) . '</h2>';
    foreach ($rows as $r) {
        echo '<div class="person"><a href="?p=user&amp;u=' . h($r['username']) . '">' . avatar($r, 40) . '</a>'
            . '<a class="pname" href="?p=user&amp;u=' . h($r['username']) . '"><b>@' . h($r['username']) . '</b>'
            . ($r['bio'] !== '' ? '<br><small>' . h(mb_substr($r['bio'], 0, 80)) . '</small>' : '') . '</a>'
            . '<form method="post" action="?p=follow" class="inl folf" data-uid="' . (int)$r['id'] . '"><input type="hidden" name="csrf" value="' . csrf() . '">'
            . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="fol">Follow</button></form></div>';
    }
}

/** The three-dots menu on a post: bookmark, mute, block, report. */
function post_menu(array $p, ?array $viewer, bool $bookmarked): string {
    if (!$viewer) return '';
    $mine = (int)$p['user_id'] === (int)$viewer['id'];
    $rel = relations_of($viewer)[(int)$p['user_id']] ?? null;
    $csrf = '<input type="hidden" name="csrf" value="' . csrf() . '">';
    $item = fn($route, $fields, $label, $confirm = '') => '<form method="post" action="?p=' . $route . '"' . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '') . '>' . $csrf . $fields . '<button>' . $label . '</button></form>';
    $id = '<input type="hidden" name="id" value="' . (int)$p['id'] . '">';
    $uid = '<input type="hidden" name="id" value="' . (int)$p['user_id'] . '">';
    $out = '<details class="menu"><summary aria-label="More options" title="More">' . icon('more') . '</summary><div class="pop">';
    $out .= $item('bookmark', $id, $bookmarked ? 'Remove bookmark' : 'Bookmark');
    if (!$mine) {
        $n = h($p['username']);
        $out .= $item('rel', $uid . '<input type="hidden" name="do" value="' . ($rel === 'mute' ? 'unmute' : 'mute') . '">', $rel === 'mute' ? "Unmute @$n" : "Mute @$n");
        $out .= $item('rel', $uid . '<input type="hidden" name="do" value="' . ($rel === 'block' ? 'unblock' : 'block') . '">', $rel === 'block' ? "Unblock @$n" : "Block @$n", $rel === 'block' ? '' : "Block @$n? They will no longer see or interact with your posts.");
        $out .= '<a href="?p=report&amp;post=' . (int)$p['id'] . '">Report post</a>';
    }
    return $out . '</div></details>';
}

// ---- Account tools --------------------------------------------------------
function delete_user(int $id): void {
    foreach (['jpg', 'gif'] as $ext) @unlink(__DIR__ . '/avatars/' . $id . '.' . $ext);
    q('DELETE FROM users WHERE id = ?', [$id]);    // posts, reactions, follows, tokens, notifications and bookmarks go with it
}

function export_data(array $u): never {
    $one = fn($sql) => q($sql, [$u['id']])->fetchAll(PDO::FETCH_ASSOC);
    $data = [
        'exported_at' => gmdate('c'),
        'account' => ['username' => $u['username'], 'email' => $u['email'], 'bio' => $u['bio'], 'website' => $u['website'],
                      'joined' => gmdate('c', (int)$u['created']), 'login_codes_enabled' => (bool)$u['twofa']],
        'posts' => array_map(fn($r) => ['id' => (int)$r['id'], 'text' => $r['body'], 'reply_to' => $r['reply_to'], 'repost_of' => $r['repost_of'], 'posted' => gmdate('c', (int)$r['created'])],
                             $one('SELECT id, body, reply_to, repost_of, created FROM posts WHERE user_id = ? ORDER BY id')),
        'following' => array_column($one('SELECT u.username FROM follows f JOIN users u ON u.id = f.followee WHERE f.follower = ?'), 'username'),
        'followers' => array_column($one('SELECT u.username FROM follows f JOIN users u ON u.id = f.follower WHERE f.followee = ?'), 'username'),
        'likes_and_dislikes' => array_map(fn($r) => ['post_id' => (int)$r['post_id'], 'reaction' => (int)$r['val'] === 1 ? 'like' : 'dislike'], $one('SELECT post_id, val FROM reactions WHERE user_id = ?')),
        'bookmarks' => array_map('intval', array_column($one('SELECT post_id FROM bookmarks WHERE user_id = ?'), 'post_id')),
        'blocked_or_muted' => array_map(fn($r) => ['username' => $r['username'], 'kind' => $r['kind']], $one('SELECT u.username, r.kind FROM relations r JOIN users u ON u.id = r.target_id WHERE r.user_id = ?')),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="wuphf-export-' . $u['username'] . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** A consistent copy of the database. VACUUM INTO where available, a plain file copy otherwise. */
function backup_to(string $dest): bool {
    global $CFG;
    try { @unlink($dest); db()->exec('VACUUM INTO ' . db()->quote($dest)); if (is_file($dest)) return true; } catch (Throwable $e) {}
    return @copy($CFG['db_file'], $dest);
}

// ---- Pages ----------------------------------------------------------------
function page_notifications(array $u): void {
    $before = (int)($_GET['before'] ?? 0);
    $rows = q('SELECT n.*, a.username, a.id AS uid, a.avatar_v, a.avatar_ext, p.body FROM notifications n
               JOIN users a ON a.id = n.actor_id LEFT JOIN posts p ON p.id = n.post_id
               WHERE n.user_id = ?' . ($before ? ' AND n.id < ' . $before : '') . ' ORDER BY n.id DESC LIMIT 41', [$u['id']])->fetchAll();
    page_head('Notifications');
    echo '<h1>Notifications</h1>';
    $verbs = ['reply' => 'replied to your post', 'mention' => 'mentioned you', 'like' => 'liked your post', 'repost' => 'reposted your post', 'follow' => 'followed you'];
    foreach (array_slice($rows, 0, 40) as $n) {
        $href = $n['kind'] === 'follow' ? '?p=user&amp;u=' . h($n['username']) : '?p=status&amp;id=' . (int)$n['post_id'];
        echo '<a class="notif' . ($n['is_read'] ? '' : ' unread') . '" href="' . $href . '">' . avatar($n, 40) . '<span class="nb"><b>@' . h($n['username']) . '</b> '
            . h($verbs[$n['kind']] ?? $n['kind']) . ' <span class="t">' . h(ago((int)$n['created'])) . '</span>'
            . ($n['body'] ? '<span class="snip">' . h(mb_substr($n['body'], 0, 120)) . '</span>' : '') . '</span></a>';
    }
    if (!$rows) echo '<p class="empty">Nothing yet. Replies, mentions, likes, reposts and new followers show up here.</p>';
    if (count($rows) > 40) echo '<p class="more"><a href="?p=notifications&amp;before=' . (int)$rows[39]['id'] . '">Older &rarr;</a></p>';
    page_foot();
    q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$u['id']]);
}

function page_bookmarks(array $u): void {
    page_head('Bookmarks');
    echo '<h1>Bookmarks</h1>';
    feed('p.id IN (SELECT post_id FROM bookmarks WHERE user_id = ?)', [$u['id']], 'p=bookmarks&amp;', 'Nothing saved yet. Use the three dots on a post to bookmark it.');
    page_foot();
}

function page_report(array $u): void {
    $pid = (int)($_GET['post'] ?? 0); $uid = (int)($_GET['user'] ?? 0);
    if ($pid) {
        $t = q(POST_SELECT . ' WHERE p.id = ?', [$pid])->fetch();
        if (!$t) not_found('That post is gone');
        $what = 'a post by @' . $t['username'] . ': "' . mb_substr($t['body'], 0, 140) . '"';
    } else {
        $t = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
        if (!$t) not_found('No such account');
        $what = 'the account @' . $t['username'];
    }
    page_head('Report'); ?>
<h1>Report</h1>
<p class="sub">You are reporting <?= h($what) ?></p>
<form method="post" action="?p=report" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="post" value="<?= $pid ?>"><input type="hidden" name="user" value="<?= $pid ? 0 : $uid ?>">
<label>What is wrong?
<select name="reason" required>
<option value="">Choose one</option>
<option>Spam or scam</option><option>Harassment or bullying</option><option>Hate or threats</option>
<option>Pretending to be someone else</option><option>Sexual or graphic content</option><option>Illegal activity</option><option>Something else</option>
</select></label>
<label>Anything we should know? (optional)
<textarea name="note" rows="3" maxlength="300"></textarea></label>
<button class="btn">Send report</button>
<p class="hint">Reports are private. A moderator will look at it. You can also block or mute this account from the three-dot menu.</p>
</form>
<?php page_foot();
}

function page_account(array $u): void {
    page_head('Account'); ?>
<h1>Account</h1>
<form method="post" action="?p=account" class="card">
<h2>Change password</h2>
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="password">
<label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
<label>New password <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
<label>Repeat the new password <input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
<button class="btn">Change password</button>
<p class="hint">This also signs you out everywhere else.</p>
</form>
<form method="post" action="?p=account" class="card">
<h2>Change email</h2>
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="email">
<p class="hint">Now: <b><?= h($u['email'] !== '' ? $u['email'] : 'none') ?></b><?= $u['pending_email'] !== '' ? '. Waiting to confirm ' . h($u['pending_email']) : '' ?></p>
<label>New email <input type="email" name="email" required maxlength="120" autocomplete="email"></label>
<label>Your password <input type="password" name="current" required autocomplete="current-password"></label>
<button class="btn">Send confirmation link</button>
<p class="hint">Your email changes only after you open the link we send to the new address.</p>
</form>
<form method="post" action="?p=account" class="card">
<h2>Your data</h2>
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="export">
<p class="hint">Download your profile, posts, reactions, follows and bookmarks as a JSON file.</p>
<button class="btn alt">Download my data</button>
</form>
<form method="post" action="?p=account" class="card danger" onsubmit="return confirm('Delete your account for good? This cannot be undone.')">
<h2>Delete account</h2>
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="do" value="delete">
<p class="hint">Removes your profile, posts, replies, reactions and photo permanently.</p>
<label>Type your username (<?= h($u['username']) ?>) to confirm <input name="typed" required autocomplete="off"></label>
<label>Your password <input type="password" name="current" required autocomplete="current-password"></label>
<button class="btn dangerbtn">Delete my account</button>
</form>
<?php
    $rels = q('SELECT us.id, us.username, r.kind FROM relations r JOIN users us ON us.id = r.target_id WHERE r.user_id = ? ORDER BY r.created DESC', [$u['id']])->fetchAll();
    if ($rels) {
        echo '<div class="card"><h2>Blocked and muted</h2>';
        foreach ($rels as $r) {
            echo '<div class="arow"><div class="who"><b>@' . h($r['username']) . '</b> <span class="pill">' . h($r['kind']) . 'd</span></div>'
                . '<form method="post" action="?p=rel" class="inl"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="id" value="' . (int)$r['id']
                . '"><input type="hidden" name="do" value="un' . h($r['kind']) . '"><button class="btn sm alt">Undo</button></form></div>';
        }
        echo '</div>';
    }
    page_foot();
}

function page_static(string $n): void {
    global $CFG;
    $pages = require __DIR__ . '/pages.php';
    if (!isset($pages[$n])) not_found();
    page_head($pages[$n]['title']);
    echo '<h1>' . h($pages[$n]['title']) . '</h1><div class="prose">' . $pages[$n]['html'];
    if ($CFG['contact_email'] !== '') echo '<p>Questions: <a href="mailto:' . h($CFG['contact_email']) . '">' . h($CFG['contact_email']) . '</a></p>';
    echo '</div>';
    page_foot();
}

function page_admin(array $u): void {
    $tab = in_array($_GET['tab'] ?? '', ['users', 'backup'], true) ? $_GET['tab'] : 'reports';
    $open = (int)q("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
    page_head('Admin');
    echo '<h1>Admin</h1><div class="tabs">';
    foreach (['reports' => 'Reports' . ($open ? " ($open)" : ''), 'users' => 'Accounts', 'backup' => 'Backup'] as $k => $l)
        echo '<a href="?p=admin&amp;tab=' . $k . '" class="' . ($tab === $k ? 'on' : '') . '">' . h($l) . '</a>';
    echo '</div>';
    $csrf = '<input type="hidden" name="csrf" value="' . csrf() . '">';
    $btn = fn($do, $label, $fields = '', $confirm = '', $cls = 'alt') => '<form method="post" action="?p=admin" class="inl"' . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '')
        . '>' . $csrf . '<input type="hidden" name="do" value="' . $do . '">' . $fields . '<button class="btn sm ' . $cls . '">' . $label . '</button></form> ';
    if ($tab === 'reports') {
        $rows = q("SELECT r.*, u.username AS reporter FROM reports r JOIN users u ON u.id = r.reporter_id WHERE r.status = 'open' ORDER BY r.id DESC LIMIT 50")->fetchAll();
        if (!$rows) echo '<p class="empty">No open reports.</p>';
        foreach ($rows as $r) {
            $post = $r['post_id'] ? q(POST_SELECT . ' WHERE p.id = ?', [$r['post_id']])->fetch() : null;
            $tuid = $post ? (int)$post['user_id'] : (int)$r['user_id'];
            $tuser = $tuid ? q('SELECT * FROM users WHERE id = ?', [$tuid])->fetch() : null;
            echo '<div class="rep"><div class="rhead"><b>' . h($r['reason']) . '</b> <span class="t">by @' . h($r['reporter']) . ' &middot; ' . h(ago((int)$r['created'])) . '</span></div>';
            echo '<div class="rbody">' . ($r['post_id'] ? 'Post by @' . h($r['target_name']) . ': ' : 'Account @' . h($r['target_name'])) . '<blockquote>' . h($r['snapshot']) . '</blockquote>';
            if ($r['note'] !== '') echo '<p class="hint">Note: ' . h($r['note']) . '</p>';
            if ($post) echo '<a href="?p=status&amp;id=' . (int)$post['id'] . '">Open post</a> &middot; ';
            if ($tuser) echo '<a href="?p=user&amp;u=' . h($tuser['username']) . '">Open @' . h($tuser['username']) . '</a>';
            echo '</div><div class="ractions">';
            $rid = '<input type="hidden" name="rid" value="' . (int)$r['id'] . '">';
            if ($post) echo $btn('remove_post', 'Remove post', $rid . '<input type="hidden" name="pid" value="' . (int)$post['id'] . '">', 'Remove this post?', 'dangerbtn');
            if ($tuser && !(int)$tuser['is_suspended'] && !is_admin($tuser)) echo $btn('suspend', 'Suspend @' . h($tuser['username']), $rid . '<input type="hidden" name="uid" value="' . $tuid . '">', 'Suspend this account?', 'dangerbtn');
            echo $btn('dismiss', 'Dismiss', $rid), $btn('resolve', 'Mark handled', $rid), '</div></div>';
        }
    } elseif ($tab === 'users') {
        $q = trim((string)($_GET['q'] ?? ''));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $rows = q("SELECT us.*, (SELECT COUNT(*) FROM posts p WHERE p.user_id = us.id) AS np FROM users us
                   WHERE ?1 = '' OR us.username LIKE ?2 ESCAPE '\\' OR us.email LIKE ?2 ESCAPE '\\' ORDER BY us.id DESC LIMIT 50", [$q, $like])->fetchAll();
        echo '<form class="card" method="get" action="./"><input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="users"><input type="search" name="q" value="' . h($q) . '" placeholder="Search username or email"></form>';
        foreach ($rows as $r) {
            $uidf = '<input type="hidden" name="uid" value="' . (int)$r['id'] . '">';
            echo '<div class="arow"><div class="who"><a href="?p=user&amp;u=' . h($r['username']) . '"><b>@' . h($r['username']) . '</b></a>'
                . ((int)$r['is_suspended'] ? ' <span class="pill bad">suspended</span>' : '') . (is_admin($r) ? ' <span class="pill">admin</span>' : '')
                . '<br><small>' . h($r['email'] ?: 'no email') . ((int)$r['email_ok'] ? '' : ' (unconfirmed)') . ' &middot; ' . (int)$r['np'] . ' posts &middot; joined ' . h(date('M j, Y', (int)$r['created'])) . '</small></div><div class="ractions">';
            if (!is_admin($r)) {
                echo (int)$r['is_suspended'] ? $btn('unsuspend', 'Restore', $uidf) : $btn('suspend', 'Suspend', $uidf, 'Suspend this account?', 'dangerbtn');
                echo $btn('delete_user', 'Delete', $uidf, 'Delete this account and everything it posted?', 'dangerbtn');
            }
            echo '</div></div>';
        }
    } else {
        echo '<div class="card"><h2>Database backup</h2><p class="hint">Downloads a copy of the whole database (accounts, posts, everything). Keep it somewhere safe and private. '
            . 'To also keep automatic copies on the server, see the README (it uses <code>backup.php</code> with a cPanel cron job).</p>'
            . $btn('backup', 'Download backup now', '', '', '') . '</div>';
    }
    page_foot();
}

function rss_feed(): never {
    $by = trim((string)($_GET['u'] ?? ''));
    $args = []; $where = 'p.reply_to IS NULL AND p.repost_of IS NULL AND u.is_suspended = 0';
    if ($by !== '') { $where .= ' AND u.username = ?'; $args[] = $by; }
    $rows = q('SELECT p.id, p.body, p.created, u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE ' . $where . ' ORDER BY p.id DESC LIMIT 30', $args)->fetchAll();
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    global $CFG;
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . $x($CFG['site_name'] . ($by !== '' ? " · @$by" : '')) . '</title><link>' . $x(site_url()) . '</link>'
        . '<description>' . $x('Short posts from ' . $CFG['site_name']) . '</description>';
    foreach ($rows as $r) {
        echo '<item><title>' . $x('@' . $r['username'] . ': ' . mb_substr($r['body'], 0, 60)) . '</title><link>' . $x(permalink((int)$r['id'])) . '</link><guid>' . $x(permalink((int)$r['id']))
            . '</guid><pubDate>' . gmdate('D, d M Y H:i:s', (int)$r['created']) . ' GMT</pubDate><description>' . $x($r['body']) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}
