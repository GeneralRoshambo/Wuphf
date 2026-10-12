<?php
require __DIR__ . '/lib.php';

$route = $_GET['p'] ?? 'home';
$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$u     = me();

// ---- POST actions -------------------------------------------------------
if ($post) {
    check_csrf();
    switch ($route) {
    case 'register':
        if (!$CFG['allow_signup']) { flash('Registration is closed.'); go('./'); }
        $name = trim($_POST['username'] ?? ''); $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]{3,15}$/', $name)) $err = 'Username: 3-15 letters, numbers or underscores.';
        elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
        elseif (q('SELECT 1 FROM users WHERE username = ?', [$name])->fetch()) $err = 'That username is taken.';
        else {
            q('INSERT INTO users (username, pass, created) VALUES (?,?,?)', [$name, password_hash($pw, PASSWORD_DEFAULT), time()]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            flash('Welcome to ' . $CFG['site_name'] . '!'); go('./');
        }
        flash($err); go('?p=register');

    case 'login':
        $row = q('SELECT * FROM users WHERE username = ?', [trim($_POST['username'] ?? '')])->fetch();
        // Always run a hash check so timing doesn't reveal valid usernames.
        $hash = $row['pass'] ?? '$2y$10$usesomesillystringforsaltuMHQxZfC8m1GkF0n2vGX5bKq6x1b2e';
        if (password_verify((string)($_POST['password'] ?? ''), $hash) && $row) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            go('./');
        }
        flash('Wrong username or password.'); go('?p=login');

    case 'logout':
        $_SESSION = []; session_destroy(); go('./');
    }

    if (!$u) go('?p=login');

    switch ($route) {
    case 'post':
        $body = clean((string)($_POST['body'] ?? ''));
        $last = (int)q('SELECT MAX(created) FROM posts WHERE user_id = ?', [$u['id']])->fetchColumn();
        if ($body === '') flash('Say something first.');
        elseif (len($body) > $CFG['max_chars']) flash('Too long: ' . $CFG['max_chars'] . ' characters max.');
        elseif (has_link($body)) flash('Links are not allowed.');
        elseif (time() - $last < $CFG['min_post_gap']) flash('Slow down a moment.');
        else q('INSERT INTO posts (user_id, body, created) VALUES (?,?,?)', [$u['id'], $body, time()]);
        go('./');

    case 'delete':
        q('DELETE FROM posts WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $u['id']]);
        go($_SERVER['HTTP_REFERER'] ?? './');

    case 'follow':
        $t = (int)($_POST['id'] ?? 0);
        if ($t !== (int)$u['id'] && q('SELECT 1 FROM users WHERE id = ?', [$t])->fetch()) {
            if (!empty($_POST['unfollow'])) q('DELETE FROM follows WHERE follower = ? AND followee = ?', [$u['id'], $t]);
            else q('INSERT OR IGNORE INTO follows (follower, followee) VALUES (?,?)', [$u['id'], $t]);
        }
        go($_SERVER['HTTP_REFERER'] ?? './');

    case 'settings':
        $bio = clean((string)($_POST['bio'] ?? ''));
        if (len($bio) > $CFG['max_chars']) { flash('Bio is limited to ' . $CFG['max_chars'] . ' characters.'); go('?p=settings'); }
        if (has_link($bio)) { flash('Links are not allowed in bios.'); go('?p=settings'); }
        q('UPDATE users SET bio = ? WHERE id = ?', [$bio, $u['id']]);
        if (!empty($_FILES['photo']['name'])) {
            if ($e = save_avatar((int)$u['id'], $_FILES['photo'])) { flash($e); go('?p=settings'); }
        }
        flash('Saved.'); go('?p=settings');
    }
    http_response_code(404); exit('Not found');
}

// ---- Pages --------------------------------------------------------------
$per = (int)$CFG['per_page'];
$before = (int)($_GET['before'] ?? 0);
$cur = $before > 0 ? 'AND p.id < ' . $before : '';
$sel = 'SELECT p.*, u.username, u.avatar_v FROM posts p JOIN users u ON u.id = p.user_id';

function more_link(array $rows, int $per, string $extra = ''): void {
    if (count($rows) > $per) echo '<p class="more"><a href="?' . $extra . 'before=' . (int)$rows[$per - 1]['id'] . '">Older &rarr;</a></p>';
}

switch ($route) {
case 'login':
case 'register':
    if ($u) go('./');
    $reg = $route === 'register';
    if ($reg && !$CFG['allow_signup']) { flash('Registration is closed.'); go('./'); }
    page_head($reg ? 'Sign up' : 'Log in'); ?>
<h1><?= $reg ? 'Sign up' : 'Log in' ?></h1>
<form method="post" action="?p=<?= $route ?>" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<label>Username <input name="username" required autocomplete="username" maxlength="15" autofocus></label>
<label>Password <input type="password" name="password" required minlength="<?= $reg ? 8 : 1 ?>" autocomplete="<?= $reg ? 'new-password' : 'current-password' ?>"></label>
<button class="btn"><?= $reg ? 'Create account' : 'Log in' ?></button>
</form>
<?php page_foot(); break;

case 'user':
    $prof = q('SELECT * FROM users WHERE username = ?', [$_GET['u'] ?? ''])->fetch();
    if (!$prof) { http_response_code(404); page_head('Not found'); echo '<h1>No such user</h1>'; page_foot(); break; }
    $rows = q("$sel WHERE p.user_id = ? $cur ORDER BY p.id DESC LIMIT " . ($per + 1), [$prof['id']])->fetchAll();
    $nPosts = (int)q('SELECT COUNT(*) FROM posts WHERE user_id = ?', [$prof['id']])->fetchColumn();
    $nFollowing = (int)q('SELECT COUNT(*) FROM follows WHERE follower = ?', [$prof['id']])->fetchColumn();
    $nFollowers = (int)q('SELECT COUNT(*) FROM follows WHERE followee = ?', [$prof['id']])->fetchColumn();
    $isF = $u && q('SELECT 1 FROM follows WHERE follower = ? AND followee = ?', [$u['id'], $prof['id']])->fetch();
    page_head('@' . $prof['username']); ?>
<section class="profile">
<?= avatar($prof, 96) ?>
<div><h1>@<?= h($prof['username']) ?></h1>
<?php if ($prof['bio'] !== ''): ?><p><?= h($prof['bio']) ?></p><?php endif; ?>
<p class="stats"><b><?= $nPosts ?></b> posts · <b><?= $nFollowing ?></b> following · <b><?= $nFollowers ?></b> followers</p>
<?php if ($u && (int)$u['id'] !== (int)$prof['id']): ?>
<form method="post" action="?p=follow"><input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="id" value="<?= (int)$prof['id'] ?>">
<?php if ($isF): ?><input type="hidden" name="unfollow" value="1"><button class="btn alt">Unfollow</button>
<?php else: ?><button class="btn">Follow</button><?php endif; ?></form>
<?php endif; ?></div></section>
<?php render_posts(array_slice($rows, 0, $per), $u);
    if (!$rows) echo '<p class="empty">No posts yet.</p>';
    more_link($rows, $per, 'p=user&amp;u=' . h($prof['username']) . '&amp;');
    page_foot(); break;

case 'settings':
    if (!$u) go('?p=login');
    page_head('Settings'); ?>
<h1>Settings</h1>
<form method="post" action="?p=settings" enctype="multipart/form-data" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="center"><?= avatar($u, 96) ?></div>
<label>Profile photo (JPEG/PNG/GIF, max 2 MB) <input type="file" name="photo" accept="image/jpeg,image/png,image/gif"></label>
<label>Bio (<?= (int)$CFG['max_chars'] ?> chars, no links)
<textarea name="bio" rows="3" maxlength="<?= (int)$CFG['max_chars'] ?>"><?= h($u['bio']) ?></textarea></label>
<button class="btn">Save</button>
</form>
<?php page_foot(); break;

default: // home
    $tab = ($u && ($_GET['tab'] ?? 'following') !== 'all') ? 'following' : 'all';
    if ($tab === 'following') {
        $rows = q("$sel WHERE (p.user_id = ?1 OR p.user_id IN (SELECT followee FROM follows WHERE follower = ?1)) $cur
                   ORDER BY p.id DESC LIMIT " . ($per + 1), [$u['id']])->fetchAll();
    } else {
        $rows = q("$sel WHERE 1=1 $cur ORDER BY p.id DESC LIMIT " . ($per + 1))->fetchAll();
    }
    page_head('Home');
    if ($u) {
        compose_box(); ?>
<div class="tabs"><a href="./" class="<?= $tab === 'following' ? 'on' : '' ?>">Following</a>
<a href="?tab=all" class="<?= $tab === 'all' ? 'on' : '' ?>">Everyone</a></div>
<?php } else { echo '<p class="hero">Short thoughts. 140 characters. No links, no noise. <a href="?p=register">Join in</a>.</p>'; }
    render_posts(array_slice($rows, 0, $per), $u);
    if (!$rows) echo '<p class="empty">' . ($tab === 'following' ? 'Nothing here yet. Post something, or follow people from the Everyone tab.' : 'No posts yet.') . '</p>';
    more_link($rows, $per, $tab === 'all' && $u ? 'tab=all&amp;' : '');
    page_foot();
}
