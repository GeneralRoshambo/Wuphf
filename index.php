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
            sign_in($nu);
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
            if ((int)$row['is_suspended']) { flash('This account is suspended. See the Rules page.'); go('?p=login'); }
            if ($row['email'] !== '' && (int)$row['email_ok'] && ((int)$row['twofa'] || $CFG['force_2fa'])) {
                $_SESSION['pending'] = ['uid' => (int)$row['id'], 't' => time()];
                if (send_login_code($row)) go('?p=verify');
                unset($_SESSION['pending']);
                flash('We could not email your login code. Try again in a moment.'); go('?p=login');
            }
            sign_in($row);
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
            unset($_SESSION['pending']);
            if (!$pu || (int)$pu['is_suspended']) { flash('This account is suspended. See the Rules page.'); go('?p=login'); }
            sign_in($pu);
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
        q('UPDATE users SET pass = ?, email_ok = 1, sess = sess + 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $tok['user_id']]);
        q('UPDATE tokens SET used = 1 WHERE user_id = ?', [$tok['user_id']]);   // every outstanding link and code
        flash('Password updated. Log in with your new password.', 'ok'); go('?p=login');

    case 'confirm':
        $raw = (string)($_POST['t'] ?? '');
        $tok = find_token($raw, 'confirm') ?: find_token($raw, 'email');
        if (!$tok) { flash('That confirmation link is invalid or has expired. Log in and use "Resend link".'); go('./'); }
        if ($tok['kind'] === 'email') {
            $cu = q('SELECT * FROM users WHERE id = ?', [$tok['user_id']])->fetch();
            try { q("UPDATE users SET email = pending_email, pending_email = '', email_ok = 1 WHERE id = ? AND pending_email <> ''", [$tok['user_id']]); }
            catch (Throwable $e) { burn_token((int)$tok['id']); flash('That email address is now used by another account.'); go('./'); }
            burn_token((int)$tok['id']);
            flash('Email updated.', 'ok'); go('./');
        }
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
        $parent = $reply ? q('SELECT id, user_id FROM posts WHERE id = ?', [$reply])->fetch() : null;
        if ($reply && !$parent) { flash('That post no longer exists.'); go('./'); }
        if ($parent && blocked_between((int)$u['id'], (int)$parent['user_id'])) { flash('You cannot reply to this account.'); go($back); }
        if ($body === '') flash('Say something first.');
        elseif (len($body) > hard_max() || counted_len($body) > $CFG['max_chars'])
            flash('Too long: ' . $CFG['max_chars'] . ' characters max (@usernames are free).');
        elseif (has_link($body)) flash('Links are not allowed.');
        elseif ($wait > 0) flash('Easy there. You can post again in ' . $wait . ' second' . ($wait == 1 ? '' : 's') . '.');
        else {
            q('INSERT INTO posts (user_id, body, reply_to, created) VALUES (?,?,?,?)', [$u['id'], $body, $reply ?: null, time()]);
            $id = (int)db()->lastInsertId();
            foreach (extract_tags($body) as $t) q('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?,?)', [$id, $t]);
            $told = [];
            if ($parent) { notify((int)$parent['user_id'], (int)$u['id'], 'reply', $id); $told[(int)$parent['user_id']] = true; }
            preg_match_all(MENTION_RE, $body, $mm);
            foreach (array_slice(array_unique(array_map(fn($x) => strtolower(trim($x, '@ ')), $mm[0])), 0, (int)$CFG['free_mentions']) as $name) {
                $mu = q('SELECT id FROM users WHERE username = ?', [$name])->fetch();
                if ($mu && empty($told[(int)$mu['id']])) { notify((int)$mu['id'], (int)$u['id'], 'mention', $id); $told[(int)$mu['id']] = true; }
            }
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
        $author = (int)q('SELECT user_id FROM posts WHERE id = ?', [$pid])->fetchColumn();
        if ($author && blocked_between((int)$u['id'], $author)) { if ($ajax) json_out(['error' => 'blocked'], 403); flash('You cannot interact with this account.'); go($_SERVER['HTTP_REFERER'] ?? './'); }
        if (!rate_ok($route . ':' . $u['id'], 120, 3600)) { if ($ajax) json_out(['error' => 'slow'], 429); flash('Slow down a little.'); go($_SERVER['HTTP_REFERER'] ?? './'); }
        if ($route === 'react') {
            $v = (int)($_POST['v'] ?? 0);
            if ($v !== 1 && $v !== -1) json_out(['error' => 'bad'], 400);
            $cur = q('SELECT val FROM reactions WHERE user_id = ? AND post_id = ?', [$u['id'], $pid])->fetchColumn();
            if ($cur !== false && (int)$cur === $v) q('DELETE FROM reactions WHERE user_id = ? AND post_id = ?', [$u['id'], $pid]);   // same button again: undo
            else q('INSERT OR REPLACE INTO reactions (user_id, post_id, val, created) VALUES (?,?,?,?)', [$u['id'], $pid, $v, time()]);
            unnotify($author, (int)$u['id'], 'like', $pid);
            if ($v === 1 && !($cur !== false && (int)$cur === 1)) notify($author, (int)$u['id'], 'like', $pid);
        } else {
            if (q('SELECT 1 FROM posts WHERE user_id = ? AND repost_of = ?', [$u['id'], $pid])->fetch()) { q('DELETE FROM posts WHERE user_id = ? AND repost_of = ?', [$u['id'], $pid]); unnotify($author, (int)$u['id'], 'repost', $pid); }
            else { q("INSERT INTO posts (user_id, body, repost_of, created) VALUES (?, '', ?, ?)", [$u['id'], $pid, time()]); notify($author, (int)$u['id'], 'repost', $pid); }
        }
        if ($ajax) json_out(post_state($pid, $u));
        go(($_SERVER['HTTP_REFERER'] ?? './') . '#p' . $pid);

    case 'delete':
        q('DELETE FROM posts WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $u['id']]);
        go($_SERVER['HTTP_REFERER'] ?? './');

    case 'follow':
        $t = (int)($_POST['id'] ?? 0);
        if ($t !== (int)$u['id'] && q('SELECT 1 FROM users WHERE id = ?', [$t])->fetch()) {
            if (!empty($_POST['unfollow'])) { q('DELETE FROM follows WHERE follower = ? AND followee = ?', [$u['id'], $t]); unnotify($t, (int)$u['id'], 'follow'); }
            elseif (blocked_between((int)$u['id'], $t)) { flash('You cannot follow this account.'); go($_SERVER['HTTP_REFERER'] ?? './'); }
            elseif (q('INSERT OR IGNORE INTO follows (follower, followee) VALUES (?,?)', [$u['id'], $t])->rowCount()) notify($t, (int)$u['id'], 'follow');
        }
        if (!empty($_POST['ajax'])) { http_response_code(204); exit; }
        $at = (int)($_POST['at'] ?? 0);
        go(($_SERVER['HTTP_REFERER'] ?? './') . ($at ? '#p' . $at : ''));

    case 'rel':     // block / mute and their undo
        $t = (int)($_POST['id'] ?? 0); $do = (string)($_POST['do'] ?? '');
        $tu = q('SELECT username FROM users WHERE id = ?', [$t])->fetch();
        if (!$tu || $t === (int)$u['id'] || !in_array($do, ['block', 'unblock', 'mute', 'unmute'], true)) go($_SERVER['HTTP_REFERER'] ?? './');
        $have = q('SELECT kind FROM relations WHERE user_id = ? AND target_id = ?', [$u['id'], $t])->fetchColumn();
        if ($do === 'block') {
            q("INSERT OR REPLACE INTO relations (user_id, target_id, kind, created) VALUES (?,?,'block',?)", [$u['id'], $t, time()]);
            q('DELETE FROM follows WHERE (follower = ?1 AND followee = ?2) OR (follower = ?2 AND followee = ?1)', [$u['id'], $t]);
            flash('Blocked @' . $tu['username'] . '. They can no longer follow, reply to or react to your posts, and you will not see theirs.', 'ok');
        } elseif ($do === 'mute') {
            if ($have !== 'block') q("INSERT OR REPLACE INTO relations (user_id, target_id, kind, created) VALUES (?,?,'mute',?)", [$u['id'], $t, time()]);
            flash('Muted @' . $tu['username'] . '. You will not see their posts. They are not told.', 'ok');
        } else {
            q('DELETE FROM relations WHERE user_id = ? AND target_id = ? AND kind = ?', [$u['id'], $t, $do === 'unblock' ? 'block' : 'mute']);
            flash(($do === 'unblock' ? 'Unblocked @' : 'Unmuted @') . $tu['username'] . '.', 'ok');
        }
        go($_SERVER['HTTP_REFERER'] ?? './');

    case 'bookmark':
        $bid = (int)($_POST['id'] ?? 0);
        if (q('SELECT 1 FROM posts WHERE id = ?', [$bid])->fetch()) {
            if (q('SELECT 1 FROM bookmarks WHERE user_id = ? AND post_id = ?', [$u['id'], $bid])->fetch()) q('DELETE FROM bookmarks WHERE user_id = ? AND post_id = ?', [$u['id'], $bid]);
            else q('INSERT INTO bookmarks (user_id, post_id, created) VALUES (?,?,?)', [$u['id'], $bid, time()]);
        }
        go(($_SERVER['HTTP_REFERER'] ?? './') . '#p' . $bid);

    case 'report':
        $reasons = ['Spam or scam', 'Harassment or bullying', 'Hate or threats', 'Pretending to be someone else', 'Sexual or graphic content', 'Illegal activity', 'Something else'];
        $rp = (int)($_POST['post'] ?? 0); $ru = (int)($_POST['user'] ?? 0);
        $reason = (string)($_POST['reason'] ?? '');
        if (!in_array($reason, $reasons, true)) { flash('Choose a reason.'); go($_SERVER['HTTP_REFERER'] ?? './'); }
        if (!rate_ok('report:' . $u['id'], 10, 3600)) { flash('You have sent a lot of reports. Try again later.'); go('./'); }
        if ($rp) {
            $tp = q('SELECT p.id, p.body, p.user_id, u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$rp])->fetch();
            if (!$tp) { flash('That post is gone.'); go('./'); }
            $snap = $tp['body']; $tname = $tp['username']; $ru = 0;
        } else {
            $tp = q('SELECT id, username, bio FROM users WHERE id = ?', [$ru])->fetch();
            if (!$tp) { flash('No such account.'); go('./'); }
            $snap = $tp['bio']; $tname = $tp['username'];
        }
        if (($rp && (int)$tp['user_id'] === (int)$u['id']) || (!$rp && $ru === (int)$u['id'])) { flash('You cannot report yourself.'); go('./'); }
        q('INSERT INTO reports (reporter_id, post_id, user_id, reason, note, snapshot, target_name, created) VALUES (?,?,?,?,?,?,?,?)',
          [$u['id'], $rp ?: null, $ru ?: null, $reason, mb_substr(clean((string)($_POST['note'] ?? '')), 0, 300), $snap, $tname, time()]);
        if ($CFG['admin_email'] !== '') send_mail($CFG['admin_email'], 'New report on ' . $CFG['site_name'], "@{$u['username']} reported " . ($rp ? 'a post' : 'an account') . " by @$tname: $reason\n\n" . site_url() . "/?p=admin\n");
        flash('Thanks. A moderator will look at it. You can also mute or block this account from its menu.', 'ok'); go('./');

    case 'account':
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'export') export_data($u);
        if (!password_verify((string)($_POST['current'] ?? ''), $u['pass'])) { flash('Your current password is not right.'); go('?p=account'); }
        if ($do === 'password') {
            $new = (string)($_POST['new'] ?? '');
            if (strlen($new) < 8) { flash('The new password must be at least 8 characters.'); go('?p=account'); }
            if ($new !== (string)($_POST['new2'] ?? '')) { flash('The two new passwords do not match.'); go('?p=account'); }
            q('UPDATE users SET pass = ?, sess = sess + 1 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            $_SESSION['sv'] = (int)$u['sess'] + 1;       // keep this device signed in, drop the others
            if ($u['email'] !== '') send_mail($u['email'], 'Your ' . $CFG['site_name'] . ' password was changed', "Hi @{$u['username']},\n\nYour password was just changed and other devices were signed out. If this was not you, reset your password right away: " . site_url() . "/?p=forgot\n");
            flash('Password changed. Other devices were signed out.', 'ok'); go('?p=account');
        }
        if ($do === 'email') {
            $ne = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($ne, FILTER_VALIDATE_EMAIL) || strlen($ne) > 120) { flash('Enter a valid email address.'); go('?p=account'); }
            if (q('SELECT 1 FROM users WHERE lower(email) = ? AND id != ?', [$ne, $u['id']])->fetch()) { flash('That email already has an account.'); go('?p=account'); }
            if (!rate_ok('emailchg:' . $u['id'], 5, 3600)) { flash('Too many tries. Wait a while.'); go('?p=account'); }
            q('UPDATE users SET pending_email = ? WHERE id = ?', [$ne, $u['id']]);
            $raw = make_token((int)$u['id'], 'email', 86400);
            send_mail($ne, 'Confirm your new email for ' . $CFG['site_name'], "Hi @{$u['username']},\n\nConfirm this as your new email address:\n\n" . site_url() . "/?p=confirm&t=$raw\n\nThe link works once and expires in 24 hours. If you did not ask for this, ignore it.\n");
            if ($u['email'] !== '') send_mail($u['email'], 'Email change requested on ' . $CFG['site_name'], "Someone (hopefully you) asked to change this account's email to $ne. Nothing changes until that address is confirmed. If this was not you, change your password.\n");
            flash('We sent a confirmation link to ' . $ne . '. Your email changes when you open it.', 'ok'); go('?p=account');
        }
        if ($do === 'delete') {
            if (strcasecmp(trim((string)($_POST['typed'] ?? '')), $u['username']) !== 0) { flash('Type your username exactly to confirm.'); go('?p=account'); }
            if (is_admin($u)) { flash('Remove yourself from the admins list in config.local.php before deleting this account.'); go('?p=account'); }
            delete_user((int)$u['id']);
            $_SESSION = []; session_destroy();
            session_start(); flash('Your account was deleted.', 'ok'); go('./');
        }
        go('?p=account');

    case 'admin':
        if (!is_admin($u)) { http_response_code(403); exit('Not allowed'); }
        $do = (string)($_POST['do'] ?? '');
        $rid = (int)($_POST['rid'] ?? 0);
        $done = fn() => $rid ? q("UPDATE reports SET status = ?, handled_by = ?, handled_at = ? WHERE id = ?", [$do === 'dismiss' ? 'dismissed' : 'resolved', $u['username'], time(), $rid]) : null;
        if ($do === 'backup') {
            $tmp = dirname($CFG['db_file']) . '/backup-' . bin2hex(random_bytes(6)) . '.sqlite';
            if (!backup_to($tmp)) { flash('Could not create a backup.'); go('?p=admin&tab=backup'); }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="wuphf-backup-' . date('Ymd-His') . '.sqlite"');
            header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
        }
        if ($do === 'remove_post') { q('DELETE FROM posts WHERE id = ?', [(int)($_POST['pid'] ?? 0)]); $done(); flash('Post removed.', 'ok'); }
        elseif ($do === 'suspend' || $do === 'unsuspend') {
            $tid = (int)($_POST['uid'] ?? 0); $tt = q('SELECT * FROM users WHERE id = ?', [$tid])->fetch();
            if ($tt && !is_admin($tt)) { q('UPDATE users SET is_suspended = ?, sess = sess + 1 WHERE id = ?', [$do === 'suspend' ? 1 : 0, $tid]); $done(); flash(($do === 'suspend' ? 'Suspended @' : 'Restored @') . $tt['username'] . '.', 'ok'); }
        }
        elseif ($do === 'delete_user') {
            $tid = (int)($_POST['uid'] ?? 0); $tt = q('SELECT * FROM users WHERE id = ?', [$tid])->fetch();
            if ($tt && !is_admin($tt)) { delete_user($tid); flash('Deleted @' . $tt['username'] . '.', 'ok'); }
        }
        elseif ($do === 'dismiss' || $do === 'resolve') { $done(); flash($do === 'dismiss' ? 'Report dismissed.' : 'Marked as handled.', 'ok'); }
        go($_SERVER['HTTP_REFERER'] ?? '?p=admin');

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
    $tok = find_token((string)($_GET['t'] ?? ''), 'confirm') ?: find_token((string)($_GET['t'] ?? ''), 'email');
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
    if (!$prof) not_found('No such account', 'There is no account with that username.');
    if ((int)$prof['is_suspended'] && !is_admin($u)) not_found('Account suspended', 'This account was suspended for breaking the Rules.');
    $myrel = $u ? (relations_of($u)[(int)$prof['id']] ?? null) : null;
    $theyBlocked = $u && q("SELECT 1 FROM relations WHERE user_id = ? AND target_id = ? AND kind = 'block'", [$prof['id'], $u['id']])->fetch();
    if ($myrel === 'mute') $GLOBALS['WUPHF_SHOW'] = (int)$prof['id'];     // a muted profile still shows its posts to you
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
<?php if ($myrel === 'block'): ?><p class="hint">You blocked this account.</p>
<?php elseif ($theyBlocked): ?><p class="hint">@<?= h($prof['username']) ?> has blocked you.</p>
<?php else: ?>
<form method="post" action="?p=follow" class="inl"><input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="id" value="<?= (int)$prof['id'] ?>">
<?php if ($isF): ?><input type="hidden" name="unfollow" value="1"><button class="btn alt">Unfollow</button>
<?php else: ?><button class="btn">Follow</button><?php endif; ?></form>
<?php endif; ?>
<details class="menu pmenu"><summary aria-label="More options" title="More"><?= icon('more') ?></summary><div class="pop">
<?php foreach ([[$myrel === 'mute' ? 'unmute' : 'mute', $myrel === 'mute' ? 'Unmute' : 'Mute', ''], [$myrel === 'block' ? 'unblock' : 'block', $myrel === 'block' ? 'Unblock' : 'Block', $myrel === 'block' ? '' : 'They will no longer see or interact with your posts.']] as [$do, $lab, $conf]): ?>
<form method="post" action="?p=rel"<?= $conf ? ' onsubmit="return confirm(\'' . h($lab . ' @' . $prof['username'] . '? ' . $conf) . '\')"' : '' ?>><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$prof['id'] ?>"><input type="hidden" name="do" value="<?= $do ?>"><button><?= $lab ?> @<?= h($prof['username']) ?></button></form>
<?php endforeach; ?>
<a href="?p=report&amp;user=<?= (int)$prof['id'] ?>">Report account</a>
</div></details>
<?php elseif ($u): ?><a class="btn alt" href="?p=settings">Edit profile</a><?php endif; ?>
<?php if ($myrel === 'mute'): ?><p class="hint">You muted this account. Their posts are hidden from your feeds but shown here.</p><?php endif; ?>
</div></section>
<?php $ptab = ($_GET['tab'] ?? '') === 'replies' ? 'replies' : 'posts';
    $base = 'p=user&amp;u=' . h($prof['username']) . '&amp;';
    tab_bar(['posts' => 'Posts', 'replies' => 'Replies'], $ptab, $base);
    if ($ptab === 'replies') feed('p.reply_to IS NOT NULL AND p.user_id = ?', [$prof['id']], $base . 'tab=replies&amp;', 'No replies yet.', true);
    else feed('p.reply_to IS NULL AND p.user_id = ?', [$prof['id']], $base, 'No posts yet.');
    page_foot(); break;

case 'status':
    $id = (int)($_GET['id'] ?? 0);
    $post = q(POST_SELECT . ' WHERE p.id = ?', [$id])->fetch();
    if (!$post) not_found('That post is gone', 'It may have been deleted.');
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
    if ($qs === '') { render_trending(); if ($u) render_suggestions($u); }
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
<p class="hint"><a href="?p=account">Password, email, your data and deleting your account &rarr;</a></p>
<?php page_foot(); break;

case 'notifications':
    if (!$u) go('?p=login');
    page_notifications($u); break;

case 'bookmarks':
    if (!$u) go('?p=login');
    page_bookmarks($u); break;

case 'report':
    if (!$u) go('?p=login');
    page_report($u); break;

case 'account':
    if (!$u) go('?p=login');
    page_account($u); break;

case 'admin':
    if (!is_admin($u)) not_found();
    page_admin($u); break;

case 'page':
    page_static((string)($_GET['n'] ?? '')); break;

case 'rss':
    rss_feed();

case 'public':
    page_head('Public feed'); ?>
<h1>Public feed</h1>
<p class="sub">Everything posted on <?= h($CFG['site_name']) ?>, newest first. <?php if (!$u): ?><a href="?p=register">Join in</a>.<?php endif; ?></p>
<?php render_trending(); ?>
<?php $ptab = ($_GET['tab'] ?? '') === 'replies' ? 'replies' : 'posts';
    tab_bar(['posts' => 'Posts', 'replies' => 'Replies'], $ptab, 'p=public&amp;');
    if ($ptab === 'replies') feed('p.reply_to IS NOT NULL', [], 'p=public&amp;tab=replies&amp;', 'No replies yet.', true);
    else feed('p.reply_to IS NULL', [], 'p=public&amp;', 'No posts yet.');
    page_foot(); break;

default:
    if ($route !== 'home') not_found();
    // home
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
             'Nothing here yet. Post something, or follow some people below.');
        if (!(int)q('SELECT COUNT(*) FROM follows WHERE follower = ?', [$u['id']])->fetchColumn()) render_suggestions($u);
    } else feed('p.reply_to IS NULL', [], $u ? 'tab=all&amp;' : '');
    page_foot();
}
