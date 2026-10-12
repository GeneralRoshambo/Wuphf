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
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!rate_ok('signup:' . client_ip(), 10, 3600)) $err = 'Too many sign-ups from your network. Try again later.';
        elseif (!preg_match('/^[A-Za-z0-9_]{3,15}$/', $name)) $err = 'Username: 3-15 letters, numbers or underscores.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $err = 'Enter a valid email address.';
        elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
        elseif (q('SELECT 1 FROM users WHERE username = ?', [$name])->fetch()) $err = 'That username is taken.';
        elseif (q('SELECT 1 FROM users WHERE lower(email) = ?', [$email])->fetch()) $err = 'That email already has an account. Log in or reset your password.';
        else {
            q('INSERT INTO users (username, pass, email, created) VALUES (?,?,?,?)', [$name, password_hash($pw, PASSWORD_DEFAULT), $email, time()]);
            $nu = q('SELECT * FROM users WHERE id = ?', [(int)db()->lastInsertId()])->fetch();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$nu['id'];
            if (send_confirmation($nu)) flash('Welcome! We sent a confirmation link to ' . $email . '. Open it to start posting.', 'ok');
            else flash('Account created, but the confirmation email could not be sent. Use "Resend link" in a moment.');
            go('./');
        }
        flash($err); go('?p=register');

    case 'login':
        $ip = client_ip();
        if (throttle_count('login:' . $ip, 900) >= 10) { flash('Too many failed attempts. Wait a few minutes and try again.'); go('?p=login'); }
        $row = q('SELECT * FROM users WHERE username = ? OR (email <> \'\' AND lower(email) = lower(?))', [trim($_POST['username'] ?? ''), trim($_POST['username'] ?? '')])->fetch();
        $hash = $row['pass'] ?? '$2y$10$usesomesillystringforsaltuMHQxZfC8m1GkF0n2vGX5bKq6x1b2e';
        if (password_verify((string)($_POST['password'] ?? ''), $hash) && $row) {
            if ($row['email'] !== '' && (int)$row['email_ok'] && ((int)$row['twofa'] || $CFG['force_2fa'])) {
                $_SESSION['pending'] = ['uid' => (int)$row['id'], 't' => time()];
                if (send_login_code($row)) go('?p=verify');
                unset($_SESSION['pending']);
                flash('We could not email your login code. Try again in a moment.'); go('?p=login');
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            go('./');
        }
        q('INSERT INTO throttle (k, t) VALUES (?,?)', ['login:' . $ip, time()]);
        flash('Wrong username or password.'); go('?p=login');

    case 'verify':   // second step of login: the emailed code
        $pend = $_SESSION['pending'] ?? null;
        if (!$pend || time() - $pend['t'] > 900) { unset($_SESSION['pending']); flash('Your login timed out. Log in again.'); go('?p=login'); }
        $pu = q('SELECT * FROM users WHERE id = ?', [$pend['uid']])->fetch();
        if (!empty($_POST['resend'])) {
            if ($pu && rate_ok('2fa:' . $pu['id'], 3, 600) && send_login_code($pu)) flash('We sent a new code.', 'ok');
            else flash('Could not send another code right now. Wait a few minutes.');
            go('?p=verify');
        }
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $tok = q('SELECT * FROM tokens WHERE user_id = ? AND kind = \'login\' AND used = 0 AND expires > ? ORDER BY id DESC LIMIT 1', [$pend['uid'], time()])->fetch();
        if (!$tok || (int)$tok['attempts'] >= 5) { unset($_SESSION['pending']); flash('That code expired. Log in again.'); go('?p=login'); }
        if (hash_equals($tok['hash'], hash('sha256', $code))) {
            burn_token((int)$tok['id']);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$pend['uid']; unset($_SESSION['pending']);
            go('./');
        }
        q('UPDATE tokens SET attempts = attempts + 1 WHERE id = ?', [$tok['id']]);
        flash('Wrong code. ' . (4 - (int)$tok['attempts']) . ' tries left.'); go('?p=verify');

    case 'forgot':
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (rate_ok('forgot-ip:' . client_ip(), 10, 3600) && rate_ok('forgot:' . $email, 3, 3600)) {
            $fu = q('SELECT * FROM users WHERE email <> \'\' AND lower(email) = ?', [$email])->fetch();
            if ($fu) {
                $raw = make_token((int)$fu['id'], 'reset', 3600);
                send_mail($fu['email'], 'Reset your ' . $CFG['site_name'] . ' password',
                    "Hi @{$fu['username']},\n\nSomeone asked to reset your {$CFG['site_name']} password. To choose a new one, open:\n\n"
                    . site_url() . "/?p=reset&t=$raw\n\nThis link works once and expires in 1 hour. If you did not ask for this, ignore this email and your password stays the same.\n");
            }
        }
        // Same answer whether or not the address has an account.
        flash('If that email has an account, we sent a reset link. It expires in 1 hour.', 'ok'); go('?p=login');

    case 'reset':
        $tok = find_token((string)($_POST['t'] ?? ''), 'reset');
        if (!$tok) { flash('That reset link is invalid or has expired. Request a new one.'); go('?p=forgot'); }
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) { flash('Password must be at least 8 characters.'); go('?p=reset&t=' . urlencode($_POST['t'])); }
        if ($pw !== (string)($_POST['password2'] ?? '')) { flash('The two passwords do not match.'); go('?p=reset&t=' . urlencode($_POST['t'])); }
        q('UPDATE users SET pass = ?, email_ok = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $tok['user_id']]);
        q('UPDATE tokens SET used = 1 WHERE user_id = ?', [$tok['user_id']]);   // every outstanding link and code
        flash('Password updated. Log in with your new password.', 'ok'); go('?p=login');

    case 'confirm':
        $tok = find_token((string)($_POST['t'] ?? ''), 'confirm');
        if (!$tok) { flash('That confirmation link is invalid or has expired. Log in and use "Resend link".'); go('./'); }
        q('UPDATE users SET email_ok = 1 WHERE id = ?', [$tok['user_id']]);
        burn_token((int)$tok['id']);
        flash('Email confirmed. You can post now.', 'ok'); go('./');

    case 'logout':
        $_SESSION = []; session_destroy(); go('./');
    }

    if (!$u) go('?p=login');

    if (in_array($route, ['post', 'follow', 'react', 'repost'], true) && needs_confirm($u)) {
        flash('Confirm your email first. Use "Resend link" in the banner if you need a new one.');
        go($_SERVER['HTTP_REFERER'] ?? './');
    }

    switch ($route) {
    case 'resend':
        if (!needs_confirm($u)) go('./');
        if (rate_ok('resend:' . $u['id'], 3, 3600) && send_confirmation($u)) flash('Confirmation link sent to ' . $u['email'] . '.', 'ok');
        else flash('Could not send another link right now. Try again later.');
        go($_SERVER['HTTP_REFERER'] ?? './');

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

    case 'react':
    case 'repost':
        $pid = (int)($_POST['id'] ?? 0);
        $row = q('SELECT id, repost_of FROM posts WHERE id = ?', [$pid])->fetch();
        $ajax = !empty($_POST['ajax']);
        if (!$row) { if ($ajax) json_out(['error' => 'gone'], 404); flash('That post is gone.'); go('./'); }
        if ($row['repost_of']) $pid = (int)$row['repost_of'];            // always act on the original
        if (!rate_ok($route . ':' . $u['id'], 120, 3600)) { if ($ajax) json_out(['error' => 'slow'], 429); flash('Slow down a little.'); go($_SERVER['HTTP_REFERER'] ?? './'); }
        if ($route === 'react') {
            $v = (int)($_POST['v'] ?? 0);
            if ($v !== 1 && $v !== -1) json_out(['error' => 'bad'], 400);
            $cur = q('SELECT val FROM reactions WHERE user_id = ? AND post_id = ?', [$u['id'], $pid])->fetchColumn();
            if ($cur !== false && (int)$cur === $v) q('DELETE FROM reactions WHERE user_id = ? AND post_id = ?', [$u['id'], $pid]);   // same button again: undo
            else q('INSERT OR REPLACE INTO reactions (user_id, post_id, val, created) VALUES (?,?,?,?)', [$u['id'], $pid, $v, time()]);
        } else {
            if (q('SELECT 1 FROM posts WHERE user_id = ? AND repost_of = ?', [$u['id'], $pid])->fetch()) q('DELETE FROM posts WHERE user_id = ? AND repost_of = ?', [$u['id'], $pid]);
            else q("INSERT INTO posts (user_id, body, repost_of, created) VALUES (?, '', ?, ?)", [$u['id'], $pid, time()]);
        }
        if ($ajax) json_out(post_state($pid, $u));
        go(($_SERVER['HTTP_REFERER'] ?? './') . '#p' . $pid);

    case 'delete':
        q('DELETE FROM posts WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $u['id']]);
        go($_SERVER['HTTP_REFERER'] ?? './');

    case 'follow':
        $t = (int)($_POST['id'] ?? 0);
        if ($t !== (int)$u['id'] && q('SELECT 1 FROM users WHERE id = ?', [$t])->fetch()) {
            if (!empty($_POST['unfollow'])) q('DELETE FROM follows WHERE follower = ? AND followee = ?', [$u['id'], $t]);
            else q('INSERT OR IGNORE INTO follows (follower, followee) VALUES (?,?)', [$u['id'], $t]);
        }
        if (!empty($_POST['ajax'])) { http_response_code(204); exit; }
        $at = (int)($_POST['at'] ?? 0);
        go(($_SERVER['HTTP_REFERER'] ?? './') . ($at ? '#p' . $at : ''));

    case 'settings':
        $bio = clean((string)($_POST['bio'] ?? ''));
        if (len($bio) > $CFG['max_chars']) { flash('Bio is limited to ' . $CFG['max_chars'] . ' characters.'); go('?p=settings'); }
        if (has_link($bio)) { flash('Links are not allowed in your bio. Use the profile link field instead.'); go('?p=settings'); }
        [$site, $err] = clean_website((string)($_POST['website'] ?? ''));
        if ($err) { flash($err); go('?p=settings'); }
        if (!empty($_FILES['photo']['name'])) {
            if ($e = save_avatar((int)$u['id'], $_FILES['photo'])) { flash($e); go('?p=settings'); }
        }
        $tf = !empty($_POST['twofa']) ? 1 : 0;
        if ($tf && !(int)$u['email_ok']) { flash('Confirm your email before turning on login codes.'); go('?p=settings'); }
        q('UPDATE users SET bio = ?, website = ?, twofa = ? WHERE id = ?', [$bio, $site, $tf, $u['id']]);
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
<label><?= $reg ? 'Username' : 'Username or email' ?> <input name="username" required autocomplete="username" maxlength="<?= $reg ? 15 : 120 ?>" autofocus></label>
<?php if ($reg): ?><label>Email <input type="email" name="email" required autocomplete="email" maxlength="120"></label>
<p class="hint">We email you a link to confirm it. We use it for password resets and login codes, never for marketing.</p><?php endif; ?>
<label>Password <input type="password" name="password" required minlength="<?= $reg ? 8 : 1 ?>" autocomplete="<?= $reg ? 'new-password' : 'current-password' ?>"></label>
<button class="btn"><?= $reg ? 'Create account' : 'Log in' ?></button>
<?php if (!$reg): ?><p class="hint"><a href="?p=forgot">Forgot your password?</a></p><?php endif; ?>
</form>
<?php page_foot(); break;

case 'forgot':
    if ($u) go('./');
    page_head('Reset password'); ?>
<h1>Reset your password</h1>
<form method="post" action="?p=forgot" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<label>Email on your account <input type="email" name="email" required autocomplete="email" maxlength="120" autofocus></label>
<button class="btn">Send reset link</button>
<p class="hint">The link works once and expires in 1 hour.</p>
</form>
<?php page_foot(); break;

case 'reset':
    $tok = find_token((string)($_GET['t'] ?? ''), 'reset');
    page_head('Choose a new password');
    if (!$tok) { echo '<h1>Link expired</h1><p class="empty">That reset link is invalid or has expired. <a href="?p=forgot">Send a new one</a>.</p>'; page_foot(); break; } ?>
<h1>Choose a new password</h1>
<form method="post" action="?p=reset" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="t" value="<?= h($_GET['t']) ?>">
<label>New password <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></label>
<label>Repeat it <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
<button class="btn">Update password</button>
</form>
<?php page_foot(); break;

case 'confirm':
    $tok = find_token((string)($_GET['t'] ?? ''), 'confirm');
    page_head('Confirm email');
    if (!$tok) { echo '<h1>Link expired</h1><p class="empty">That confirmation link is invalid or has expired. Log in and choose "Resend link".</p>'; page_foot(); break; } ?>
<h1>Confirm your email</h1>
<form method="post" action="?p=confirm" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="t" value="<?= h($_GET['t']) ?>">
<p>One tap to finish setting up your account.</p>
<button class="btn">Confirm email</button>
</form>
<?php page_foot(); break;

case 'verify':
    $pend = $_SESSION['pending'] ?? null;
    if ($u || !$pend || time() - $pend['t'] > 900) { unset($_SESSION['pending']); go($u ? './' : '?p=login'); }
    $pu = q('SELECT email FROM users WHERE id = ?', [$pend['uid']])->fetch();
    $masked = preg_replace('/^(.).*?(@.*)$/', '$1***$2', $pu['email'] ?? '');
    page_head('Enter code'); ?>
<h1>Check your email</h1>
<form method="post" action="?p=verify" class="card">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<label>6-digit code sent to <?= h($masked) ?>
<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
<button class="btn">Log in</button>
</form>
<form method="post" action="?p=verify" class="card"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="resend" value="1">
<p class="hint">The code expires in 10 minutes. <button class="lnk">Send a new code</button></p></form>
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
    if ($post['repost_of']) go('?p=status&id=' . (int)$post['repost_of']);   // a repost's page is the original's
    $chain = []; $cur = $post;
    for ($i = 0; $i < 10 && $cur['reply_to']; $i++) {
        $cur = q(POST_SELECT . ' WHERE p.id = ?', [$cur['reply_to']])->fetch();
        if (!$cur) break;
        array_unshift($chain, $cur);
    }
    page_head('@' . $post['username'] . ': ' . mb_substr($post['body'], 0, 30),
        ['description' => $post['body'], 'url' => permalink($id), 'type' => 'article']);
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
<label>Profile photo (JPEG, PNG, or an animated GIF up to 2 MB)
<input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/gif"></label>
<label>Bio (<?= (int)$CFG['max_chars'] ?> chars, no links)
<textarea name="bio" rows="3" maxlength="<?= (int)$CFG['max_chars'] ?>"><?= h($u['bio']) ?></textarea></label>
<label>Email
<input type="text" value="<?= h($u['email'] !== '' ? $u['email'] : 'none on file') ?>" disabled>
<span class="hint"><?= $u['email'] === '' ? '' : ((int)$u['email_ok'] ? 'Confirmed.' : 'Not confirmed yet. Use the banner at the top to resend the link.') ?></span></label>
<label class="check"><input type="checkbox" name="twofa" value="1" <?= ((int)$u['twofa'] || $CFG['force_2fa']) ? 'checked' : '' ?> <?= ($CFG['force_2fa'] || !(int)$u['email_ok']) ? 'disabled' : '' ?>>
Ask for a login code by email each time I log in</label>
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
        echo '<p class="sub">Conversations from people you follow, plus replies to your posts and your own replies.</p>';
        feed('p.reply_to IS NOT NULL AND (p.user_id = ?1 OR p.user_id IN (SELECT followee FROM follows WHERE follower = ?1) OR p.reply_to IN (SELECT id FROM posts WHERE user_id = ?1))', [$u['id']],
             'tab=replies&amp;', 'No replies yet. Reply to someone, or wait for a reply to your posts.', true);
    } elseif ($tab === 'following') {
        feed('p.reply_to IS NULL AND (p.user_id = ?1 OR p.user_id IN (SELECT followee FROM follows WHERE follower = ?1))', [$u['id']], '',
             'Nothing here yet. Post something, or follow people from the Everyone tab.');
    } else feed('p.reply_to IS NULL', [], $u ? 'tab=all&amp;' : '');
    page_foot();
}
