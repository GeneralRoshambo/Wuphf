<?php
require __DIR__ . '/lib.php';

$route = $_GET['p'] ?? 'home';
$u     = me();

// ---- POST actions -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            flash('Welcome to ' . $CFG['site_name'] . '!', 'ok'); go('./');
        }
        flash($err); go('?p=register');

    case 'login':
        $row = q('SELECT * FROM users WHERE username = ?', [trim($_POST['username'] ?? '')])->fetch();
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
        $body  = clean((string)($_POST['body'] ?? ''));
        $reply = (int)($_POST['reply_to'] ?? 0);
        $back  = $reply ? '?p=status&id=' . $reply : './';
        $wait  = cooldown_left($u);
        if ($reply && !q('SELECT 1 FROM posts WHERE id = ?', [$reply])->fetch()) { flash('That post no longer exists.'); go('./'); }
        if ($body === '') flash('Say something first.');
        elseif (len($body) > hard_max() || counted_len($body) > $CFG['max_chars'])
            flash('Too long: ' . $CFG['max_chars'] . ' characters max (@usernames are free).');
        elseif (has_link($body)) flash('Links are not allowed.');
        elseif ($wait > 0) flash('Easy there. You can post again in ' . $wait . ' second' . ($wait == 1 ? '' : 's') . '.');
        else {
            q('INSERT INTO posts (user_id, body, reply_to, created) VALUES (?,?,?,?)', [$u['id'], $body, $reply ?: null, time()]);
            $id = (int)db()->lastInsertId();
            foreach (extract_tags($body) as $t) q('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?,?)', [$id, $t]);
            if ($reply) flash('Reply posted.', 'ok');
        }
        go($back);

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
        if (has_link($bio)) { flash('Links are not allowed in your bio. Use the profile link field instead.'); go('?p=settings'); }
        [$site, $err] = clean_website((string)($_POST['website'] ?? ''));
        if ($err) { flash($err); go('?p=settings'); }
        if (!empty($_FILES['photo']['name'])) {
            if ($e = save_avatar((int)$u['id'], $_FILES['photo'])) { flash($e); go('?p=settings'); }
        }
        q('UPDATE users SET bio = ?, website = ? WHERE id = ?', [$bio, $site, $u['id']]);
        flash('Profile saved.', 'ok'); go('?p=settings');
    }
    http_response_code(404); exit('Not found');
}

// ---- Pages --------------------------------------------------------------
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
    $nPosts = (int)q('SELECT COUNT(*) FROM posts WHERE user_id = ?', [$prof['id']])->fetchColumn();
    $nFollowing = (int)q('SELECT COUNT(*) FROM follows WHERE follower = ?', [$prof['id']])->fetchColumn();
    $nFollowers = (int)q('SELECT COUNT(*) FROM follows WHERE followee = ?', [$prof['id']])->fetchColumn();
    $isF = $u && q('SELECT 1 FROM follows WHERE follower = ? AND followee = ?', [$u['id'], $prof['id']])->fetch();
    page_head('@' . $prof['username']); ?>
<section class="profile">
<?= avatar($prof, 96) ?>
<div><h1>@<?= h($prof['username']) ?></h1>
<?php if ($prof['bio'] !== ''): ?><p><?= render_body($prof['bio']) ?></p><?php endif; ?>
<?php if ($prof['website'] !== ''): ?><p class="site"><a href="<?= h($prof['website']) ?>" target="_blank" rel="nofollow noopener noreferrer ugc"><?= h(preg_replace('~^https?://(www\.)?~i', '', rtrim($prof['website'], '/'))) ?></a></p><?php endif; ?>
<p class="stats"><b><?= $nPosts ?></b> posts · <b><?= $nFollowing ?></b> following · <b><?= $nFollowers ?></b> followers</p>
<?php if ($u && (int)$u['id'] !== (int)$prof['id']): ?>
<form method="post" action="?p=follow"><input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="id" value="<?= (int)$prof['id'] ?>">
<?php if ($isF): ?><input type="hidden" name="unfollow" value="1"><button class="btn alt">Unfollow</button>
<?php else: ?><button class="btn">Follow</button><?php endif; ?></form>
<?php elseif ($u): ?><a class="btn alt" href="?p=settings">Edit profile</a><?php endif; ?></div></section>
<?php $ptab = ($_GET['tab'] ?? '') === 'replies' ? 'replies' : 'posts';
    $base = 'p=user&amp;u=' . h($prof['username']) . '&amp;';
    tab_bar(['posts' => 'Posts', 'replies' => 'Replies'], $ptab, $base);
    if ($ptab === 'replies') feed('p.reply_to IS NOT NULL AND p.user_id = ?', [$prof['id']], $base . 'tab=replies&amp;', 'No replies yet.', true);
    else feed('p.reply_to IS NULL AND p.user_id = ?', [$prof['id']], $base, 'No posts yet.');
    page_foot(); break;

case 'status':
    $id = (int)($_GET['id'] ?? 0);
    $post = q(POST_SELECT . ' WHERE p.id = ?', [$id])->fetch();
    if (!$post) { http_response_code(404); page_head('Not found'); echo '<h1>That post is gone</h1>'; page_foot(); break; }
    $chain = []; $cur = $post;
    for ($i = 0; $i < 10 && $cur['reply_to']; $i++) {
        $cur = q(POST_SELECT . ' WHERE p.id = ?', [$cur['reply_to']])->fetch();
        if (!$cur) break;
        array_unshift($chain, $cur);
    }
    page_head('@' . $post['username'] . ': ' . mb_substr($post['body'], 0, 30));
    echo '<p class="crumb"><a href="javascript:history.back()">&larr; Back</a></p>';
    $depth = chain_open($chain, $u);
    echo '<div class="focus">'; render_posts([$post], $u, true); echo '</div>';
    echo '<div id="reply">';
    if ($u) compose_box($id, '@' . $post['username'] . ' ');
    else echo '<p class="empty"><a href="?p=login">Log in</a> to reply.</p>';
    echo '</div>';
    render_tree($id, $u);
    chain_close($depth);
    page_foot(); break;

case 'tag':
    $tag = mb_strtolower(trim((string)($_GET['t'] ?? '')), 'UTF-8');
    page_head('#' . $tag); echo '<h1>#' . h($tag) . '</h1>';
    feed('p.id IN (SELECT post_id FROM post_tags WHERE tag = ?)', [$tag], 'p=tag&amp;t=' . h(urlencode($tag)) . '&amp;', 'Nothing tagged #' . $tag . ' yet.');
    page_foot(); break;

case 'search':
    $qs = trim((string)($_GET['q'] ?? ''));
    if ($qs !== '' && $qs[0] === '#' && len($qs) > 1) go('?p=tag&t=' . urlencode(ltrim($qs, '#')));
    page_head('Search'); ?>
<h1>Search</h1>
<form class="card" action="./" method="get" role="search"><input type="hidden" name="p" value="search">
<input type="search" name="q" value="<?= h($qs) ?>" placeholder="Search people and posts, or try #hashtag" maxlength="60" autofocus></form>
<?php if ($qs !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], ltrim($qs, '@')) . '%';
        $people = q("SELECT * FROM users WHERE username LIKE ? ESCAPE '\\' OR bio LIKE ? ESCAPE '\\' ORDER BY username LIMIT 10", [$like, $like])->fetchAll();
        if ($people) {
            echo '<h2>People</h2>';
            foreach ($people as $pp) {
                echo '<a class="card person" href="?p=user&amp;u=' . h($pp['username']) . '">' . avatar($pp, 40)
                    . '<span><b>@' . h($pp['username']) . '</b>' . ($pp['bio'] !== '' ? '<br><small>' . h($pp['bio']) . '</small>' : '') . '</span></a>';
            }
        }
        echo '<h2>Posts</h2>';
        feed("p.body LIKE ? ESCAPE '\\'", [$like], 'p=search&amp;q=' . h(urlencode($qs)) . '&amp;', 'No posts match "' . $qs . '".');
    }
    page_foot(); break;

case 'settings':
    if (!$u) go('?p=login');
    page_head('Settings'); ?>
<h1>Settings</h1>
<form method="post" action="?p=settings" enctype="multipart/form-data" class="card" data-saving>
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="center" id="avprev"><?= avatar($u, 96) ?></div>
<label>Profile photo (JPEG, PNG, or an animated GIF up to 1 MB)
<input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/gif"></label>
<label>Bio (<?= (int)$CFG['max_chars'] ?> chars, no links)
<textarea name="bio" rows="3" maxlength="<?= (int)$CFG['max_chars'] ?>"><?= h($u['bio']) ?></textarea></label>
<label>Profile link (shown on your profile only)
<input type="text" inputmode="url" autocapitalize="off" name="website" value="<?= h($u['website']) ?>" maxlength="100" placeholder="https://example.com"></label>
<button class="btn" id="savebtn">Save</button>
</form>
<?php page_foot(); break;

case 'public':
    page_head('Public feed'); ?>
<h1>Public feed</h1>
<p class="sub">Everything posted on <?= h($CFG['site_name']) ?>, newest first. <?php if (!$u): ?><a href="?p=register">Join in</a>.<?php endif; ?></p>
<?php $ptab = ($_GET['tab'] ?? '') === 'replies' ? 'replies' : 'posts';
    tab_bar(['posts' => 'Posts', 'replies' => 'Replies'], $ptab, 'p=public&amp;');
    if ($ptab === 'replies') feed('p.reply_to IS NOT NULL', [], 'p=public&amp;tab=replies&amp;', 'No replies yet.', true);
    else feed('p.reply_to IS NULL', [], 'p=public&amp;', 'No posts yet.');
    page_foot(); break;

default: // home
    $tab = $_GET['tab'] ?? 'following';
    if (!in_array($tab, ['following', 'all', 'replies'], true) || !$u) $tab = $u ? 'following' : 'all';
    page_head('Home');
    if ($u) {
        compose_box();
        tab_bar(['following' => 'Following', 'all' => 'Everyone', 'replies' => 'Replies'], $tab, '');
    } else echo '<p class="hero">Short thoughts. 140 characters. No links, no noise. <a href="?p=register">Join in</a>.</p>';
    if ($tab === 'replies') {
        echo '<p class="sub">Conversations you are in: replies to your posts and your own replies.</p>';
        feed('p.reply_to IS NOT NULL AND (p.user_id = ?1 OR p.reply_to IN (SELECT id FROM posts WHERE user_id = ?1))', [$u['id']],
             'tab=replies&amp;', 'No replies yet. Reply to someone, or wait for a reply to your posts.', true);
    } elseif ($tab === 'following') {
        feed('p.reply_to IS NULL AND (p.user_id = ?1 OR p.user_id IN (SELECT followee FROM follows WHERE follower = ?1))', [$u['id']], '',
             'Nothing here yet. Post something, or follow people from the Everyone tab.');
    } else feed('p.reply_to IS NULL', [], $u ? 'tab=all&amp;' : '');
    page_foot();
}
