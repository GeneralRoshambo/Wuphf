<?php
// Admin extras: backup restore, account recovery (with an audit log) and site stats.

function admin_log_table(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec('CREATE TABLE IF NOT EXISTS admin_log (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, admin_name TEXT NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL DEFAULT \'\', note TEXT NOT NULL DEFAULT \'\', created INTEGER NOT NULL)');
}
function admin_log(array $u, string $action, string $target = '', string $note = ''): void {
    admin_log_table();
    q('INSERT INTO admin_log (admin_id, admin_name, action, target, note, created) VALUES (?,?,?,?,?,?)', [$u['id'], $u['username'], $action, $target, mb_substr($note, 0, 200), time()]);
}

// ---- Restore --------------------------------------------------------------------------
function backup_dir(): string { global $CFG; return dirname($CFG['db_file']) . '/backups'; }

/** Null when $path is a healthy Wuphf database, otherwise what is wrong with it. */
function validate_backup(string $path): ?string {
    $fh = @fopen($path, 'rb'); if (!$fh) return 'Could not read that file.';
    $head = fread($fh, 16); fclose($fh);
    if ($head !== "SQLite format 3\0") return 'That is not a SQLite database file.';
    try {
        $p = new PDO('sqlite:' . $path); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($p->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') return 'That database failed its integrity check.';
        $t = $p->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'posts'] as $need) if (!in_array($need, $t, true)) return 'That does not look like a Wuphf database (no ' . $need . ' table).';
    } catch (Throwable $e) { return 'That database could not be opened.'; }
    return null;
}

/** Replace the live database with $src. A copy of the current one is kept first. Returns an error or null. */
function restore_database(string $src): ?string {
    global $CFG;
    if ($e = validate_backup($src)) return $e;
    $dir = backup_dir(); if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safety = $dir . '/before-restore-' . date('Ymd-His') . '.sqlite';
    if (!backup_to($safety)) return 'Could not save a safety copy of the current database, so nothing was changed.';
    $live = $CFG['db_file']; $tmp = $live . '.restore-' . bin2hex(random_bytes(4));
    try { db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) {}
    if (!@copy($src, $tmp)) return 'Could not write the restored database (is data/ writable?).';
    if (!@rename($tmp, $live)) { @unlink($tmp); return 'Could not replace the database file.'; }
    foreach (['-wal', '-shm', '-journal'] as $s) @unlink($live . $s);
    return null;
}

function server_backups(): array {
    $out = [];
    foreach (glob(backup_dir() . '/*.sqlite') ?: [] as $f) $out[basename($f)] = ['size' => filesize($f), 'time' => filemtime($f)];
    uasort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

// ---- POST actions ------------------------------------------------------------------------
/** Returns [redirect, message, ok] when $do is one of ours, else null. */
function admin_tools_post(string $do, array $u): ?array {
    global $CFG;
    static $mine = ['restore_upload', 'restore_server', 'backup_server', 'rec_signout', 'rec_2fa_off', 'rec_link', 'rec_email'];
    if (!in_array($do, $mine, true)) return null;
    admin_log_table();
    $back = '?p=admin&tab=backup'; $ubk = '?p=admin&tab=users';
    if (str_starts_with($do, 'rec_')) {
        $tid = (int)($_POST['uid'] ?? 0); $t = q('SELECT * FROM users WHERE id = ?', [$tid])->fetch();
        if (!$t) return [$ubk, 'No such account.', false];
        if (is_admin($t) && (int)$t['id'] !== (int)$u['id']) return [$ubk, 'Recovery tools are not available for other admins.', false];
        $who = '@' . $t['username'];
        switch ($do) {
        case 'rec_signout':
            q('UPDATE users SET sess = sess + 1 WHERE id = ?', [$tid]); admin_log($u, 'signed out everywhere', $who);
            return [$ubk, $who . ' was signed out on all devices.', true];
        case 'rec_2fa_off':
            q('UPDATE users SET twofa = 0 WHERE id = ?', [$tid]); admin_log($u, 'turned off login codes', $who);
            return [$ubk, 'Login codes are off for ' . $who . ($CFG['force_2fa'] ? ' (note: force_2fa in config still applies).' : '.'), true];
        case 'rec_link':
            $raw = make_token($tid, 'reset', 3600); admin_log($u, 'made a password reset link', $who);
            return [$ubk, 'Reset link for ' . $who . ' (one use, 1 hour). Send it to them yourself: ' . site_url() . '/?p=reset&t=' . $raw, true];
        case 'rec_email':
            $ne = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($ne, FILTER_VALIDATE_EMAIL) || strlen($ne) > 120) return [$ubk, 'Enter a valid email address.', false];
            if (q('SELECT 1 FROM users WHERE lower(email) = ? AND id != ?', [$ne, $tid])->fetch()) return [$ubk, 'Another account already uses that email.', false];
            q("UPDATE users SET email = ?, email_ok = 1, pending_email = '', sess = sess + 1 WHERE id = ?", [$ne, $tid]);
            admin_log($u, 'set email (marked confirmed)', $who, $t['email'] . ' -> ' . $ne);
            return [$ubk, 'Email for ' . $who . ' set to ' . $ne . ' and marked confirmed. They were signed out everywhere.', true];
        }
    }
    if ($do === 'backup_server') {
        $dir = backup_dir(); if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $dest = $dir . '/wuphf-' . date('Ymd-His') . '.sqlite';
        return [$back, backup_to($dest) ? 'Saved a copy on the server: ' . basename($dest) : 'Could not save a copy.', is_file($dest)];
    }
    // restore (either way needs the word RESTORE typed)
    if (trim((string)($_POST['typed'] ?? '')) !== 'RESTORE') return [$back, 'Type RESTORE to confirm.', false];
    if ($do === 'restore_server') {
        $name = basename((string)($_POST['file'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]+\.sqlite$/', $name) || !is_file(backup_dir() . '/' . $name)) return [$back, 'That backup is not on the server.', false];
        $src = backup_dir() . '/' . $name;
    } else {
        $f = $_FILES['dbfile'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return [$back, ($f && $f['error'] === UPLOAD_ERR_INI_SIZE) ? 'That file is bigger than this server allows (' . ini_get('upload_max_filesize') . '). Put it in data/backups/ with FTP or the cPanel file manager and restore it from the list instead.' : 'Choose a backup file first.', false];
        }
        $src = $f['tmp_name'];
    }
    admin_log($u, 'restoring database', basename($src));
    if ($e = restore_database($src)) return [$back, $e, false];
    // The restored database has its own accounts, so this session may no longer be valid.
    return [$back, 'Backup restored. A copy of the previous database was saved in data/backups/. If you are asked to log in again, that is expected.', true];
}

// ---- Screens ---------------------------------------------------------------------------------
function admin_backup_tab(callable $btn): void {
    $csrf = '<input type="hidden" name="csrf" value="' . csrf() . '">';
    echo '<div class="card"><h2>Database backup</h2><p class="hint">A copy of the whole database (accounts, posts, everything). Keep downloaded copies somewhere safe and private. '
        . 'For automatic daily copies on the server, see the README (<code>backup.php</code> with a cPanel cron job).</p>'
        . $btn('backup', 'Download backup now', '', '', '') . $btn('backup_server', 'Save a copy on the server', '', '', '') . '</div>';
    echo '<div class="card"><h2>Restore a backup</h2><p class="hint"><b>This replaces everything on the site</b> with the contents of the backup. '
        . 'A copy of the current database is saved first, and everyone may need to log in again.</p>';
    $list = server_backups();
    if ($list) {
        echo '<p class="hint">Copies on the server:</p>';
        foreach (array_slice($list, 0, 15, true) as $name => $i) {
            echo '<form method="post" action="?p=admin" class="arow restorerow">' . $csrf . '<input type="hidden" name="do" value="restore_server"><input type="hidden" name="file" value="' . h($name) . '">'
                . '<div class="who"><b>' . h($name) . '</b><br><small>' . h(date('M j, Y g:i A', $i['time'])) . ' &middot; ' . round($i['size'] / 1024) . ' KB</small></div>'
                . '<div class="ractions"><input name="typed" class="mini" placeholder="type RESTORE" autocomplete="off" aria-label="Type RESTORE to confirm for ' . h($name) . '"><button class="btn sm dangerbtn">Restore</button></div></form>';
        }
    } else echo '<p class="empty">No copies on the server yet.</p>';
    echo '<form method="post" action="?p=admin" enctype="multipart/form-data" class="restoreup">' . $csrf . '<input type="hidden" name="do" value="restore_upload">'
        . '<label>Or upload a backup file <span class="hint">.sqlite from "Download backup" (this server accepts uploads up to ' . h(ini_get('upload_max_filesize')) . ')</span><input type="file" name="dbfile" accept=".sqlite,.db,application/octet-stream"></label>'
        . '<label>Type RESTORE to confirm <input name="typed" autocomplete="off"></label><button class="btn dangerbtn">Upload and restore</button></form></div>';
}

/** Extra recovery tools for one account row. */
function admin_recovery_menu(array $r, string $csrf): string {
    $f = fn($do, $label, $extra = '', $cls = 'alt', $confirm = '') => '<form method="post" action="?p=admin"' . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '') . '>' . $csrf . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="uid" value="' . (int)$r['id'] . '">' . $extra . '<button class="btn sm ' . $cls . '">' . $label . '</button></form>';
    $o = '<details class="recover"><summary>Recover account</summary><div class="recbox">'
        . $f('rec_link', 'Make a password reset link', '', 'alt', 'Create a one-time reset link for this account?')
        . $f('rec_signout', 'Sign out everywhere', '', 'alt', 'Sign this account out on all devices?')
        . ((int)$r['twofa'] ? $f('rec_2fa_off', 'Turn off login codes') : '')
        . '<form method="post" action="?p=admin" class="inl">' . $csrf . '<input type="hidden" name="do" value="rec_email"><input type="hidden" name="uid" value="' . (int)$r['id'] . '">'
        . '<input type="email" name="email" class="mini" placeholder="new email (marked confirmed)" maxlength="120" aria-label="New email for @' . h($r['username']) . '" required><button class="btn sm alt">Set email</button></form>'
        . '</div></details>';
    return $o;
}

function admin_log_view(): void {
    admin_log_table();
    $rows = q('SELECT * FROM admin_log ORDER BY id DESC LIMIT 15')->fetchAll();
    if (!$rows) return;
    echo '<h2>Recent admin actions</h2><div class="card">';
    foreach ($rows as $r) echo '<p class="hint" style="margin:4px 0">' . h(date('M j, g:i A', (int)$r['created'])) . ' &middot; <b>@' . h($r['admin_name']) . '</b> ' . h($r['action']) . ($r['target'] !== '' ? ' &middot; ' . h($r['target']) : '') . ($r['note'] !== '' ? ' (' . h($r['note']) . ')' : '') . '</p>';
    echo '</div>';
}

// ---- Stats --------------------------------------------------------------------------------------
function stats_bars(array $series, string $title, string $color = '#1d5bff'): string {
    $max = max(1, max($series)); $n = count($series); $w = 600; $h = 120; $bw = $w / $n;
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . ($h + 4) . '" class="spark" role="img" aria-label="' . h($title) . ': ' . h(implode(', ', $series)) . ' (oldest to newest)" preserveAspectRatio="none">';
    $i = 0;
    foreach ($series as $v) { $bh = $v / $max * $h; $svg .= '<rect x="' . round($i * $bw + 1.5, 2) . '" y="' . round($h - $bh + 2, 2) . '" width="' . round($bw - 3, 2) . '" height="' . round(max($bh, $v > 0 ? 2 : 0), 2) . '" rx="2" fill="' . $color . '"/>'; $i++; }
    return $svg . '</svg>';
}

function admin_stats_tab(): void {
    $now = time(); $days = 30;
    $count = fn($sql, $a = []) => (int)q($sql, $a)->fetchColumn();
    $tiles = [
        'Members' => $count('SELECT COUNT(*) FROM users WHERE is_suspended = 0'),
        'Confirmed emails' => $count('SELECT COUNT(*) FROM users WHERE email_ok = 1'),
        'Posts' => $count('SELECT COUNT(*) FROM posts WHERE reply_to IS NULL AND repost_of IS NULL'),
        'Replies' => $count('SELECT COUNT(*) FROM posts WHERE reply_to IS NOT NULL'),
        'Likes' => $count('SELECT COUNT(*) FROM reactions WHERE val = 1'),
        'Follows' => $count('SELECT COUNT(*) FROM follows'),
        'Digest subscribers' => $count("SELECT COUNT(*) FROM users WHERE digest <> 'off'"),
        'Instant-email subscribers' => $count('SELECT COUNT(*) FROM users WHERE notify_email = 1'),
    ];
    echo '<div class="tiles">';
    foreach ($tiles as $l => $v) echo '<div class="tile"><b>' . number_format($v) . '</b><span>' . h($l) . '</span></div>';
    echo '</div>';
    $day = fn($col, $table, $where = '') => (function () use ($col, $table, $where, $now, $days) {
        $rows = q("SELECT strftime('%Y-%m-%d', $col, 'unixepoch') d, COUNT(*) c FROM $table WHERE $col >= ? $where GROUP BY d", [$now - $days * 86400])->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = []; for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', $now - $i * 86400)] = (int)($rows[gmdate('Y-m-d', $now - $i * 86400)] ?? 0); return $out;
    })();
    $signups = $day('created', 'users'); $posts = $day('created', 'posts', 'AND repost_of IS NULL');
    $act = q("SELECT d, COUNT(DISTINCT u) c FROM (SELECT strftime('%Y-%m-%d', created, 'unixepoch') d, user_id u FROM posts WHERE created >= ?1
              UNION ALL SELECT strftime('%Y-%m-%d', created, 'unixepoch') d, user_id u FROM reactions WHERE created >= ?1) GROUP BY d", [$now - $days * 86400])->fetchAll(PDO::FETCH_KEY_PAIR);
    $active = []; for ($i = $days - 1; $i >= 0; $i--) $active[gmdate('Y-m-d', $now - $i * 86400)] = (int)($act[gmdate('Y-m-d', $now - $i * 86400)] ?? 0);
    $chart = function ($title, $series, $color, $note = '') {
        echo '<div class="card"><h2>' . h($title) . ' <small class="hint">last 30 days &middot; ' . array_sum($series) . ' total' . ($note ? ' &middot; ' . h($note) : '') . '</small></h2>' . stats_bars($series, $title, $color)
            . '<p class="hint axis"><span>' . h(date('M j', strtotime(array_key_first($series)))) . '</span><span>' . h(date('M j', strtotime(array_key_last($series)))) . '</span></p>'
            . '<details><summary class="hint">Show numbers</summary><p class="hint">' . h(implode('  ', array_map(fn($d, $v) => date('M j', strtotime($d)) . ': ' . $v, array_keys($series), $series))) . '</p></details></div>';
    };
    $chart('New members', $signups, '#1d5bff'); $chart('Posts', $posts, '#6f9bff'); $chart('Active members', $active, '#12a150', 'people who posted or reacted, per day (UTC)');
    $tags = q('SELECT t.tag, COUNT(*) c FROM post_tags t JOIN posts p ON p.id = t.post_id WHERE p.created > ? GROUP BY t.tag ORDER BY c DESC, t.tag LIMIT 8', [$now - 7 * 86400])->fetchAll();
    $top = q('SELECT p.id, p.body, u.username, (SELECT COUNT(*) FROM reactions x WHERE x.post_id = p.id AND x.val = 1) + (SELECT COUNT(*) FROM posts r WHERE r.reply_to = p.id) + (SELECT COUNT(*) FROM posts rp WHERE rp.repost_of = p.id) AS score
              FROM posts p JOIN users u ON u.id = p.user_id WHERE p.created > ? AND p.repost_of IS NULL AND p.reply_to IS NULL ORDER BY score DESC, p.id DESC LIMIT 5', [$now - 7 * 86400])->fetchAll();
    echo '<div class="card"><h2>This week</h2>';
    if ($tags) { echo '<p class="hint">Top hashtags</p><p>'; foreach ($tags as $t) echo '<a class="pill" href="?p=tag&amp;t=' . h(urlencode($t['tag'])) . '">#' . h($t['tag']) . ' &middot; ' . (int)$t['c'] . '</a> '; echo '</p>'; }
    if ($top) { echo '<p class="hint">Most engaged posts</p>'; foreach ($top as $p) if ((int)$p['score'] > 0) echo '<p><a href="?p=status&amp;id=' . (int)$p['id'] . '"><b>@' . h($p['username']) . '</b></a> ' . h(mb_substr($p['body'], 0, 90)) . ' <span class="hint">(' . (int)$p['score'] . ')</span></p>'; }
    if (!$tags && !$top) echo '<p class="empty">Nothing yet.</p>';
    echo '</div>';
}
