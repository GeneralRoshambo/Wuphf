<?php
// Admin-only post scheduling. Nothing here is reachable or visible to normal members.
// Due posts are published by whichever request arrives next (no cron needed).

function schedule_table(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec('CREATE TABLE IF NOT EXISTS scheduled_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        body TEXT NOT NULL, run_at INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\', post_id INTEGER, note TEXT NOT NULL DEFAULT \'\',
        created_by INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL)');
    db()->exec('CREATE INDEX IF NOT EXISTS sched_due ON scheduled_posts(status, run_at)');
}

/** Publish everything that is due. Cheap when nothing is: one indexed lookup, at most once per request. */
function publish_due(): void {
    static $ran = false; if ($ran) return; $ran = true;
    global $CFG;
    schedule_table();
    $rows = q("SELECT * FROM scheduled_posts WHERE status = 'pending' AND run_at <= ? ORDER BY run_at, id LIMIT 20", [time()])->fetchAll();
    foreach ($rows as $r) {
        // Claim it first so two simultaneous requests cannot both publish it.
        if (q("UPDATE scheduled_posts SET status = 'publishing' WHERE id = ? AND status = 'pending'", [$r['id']])->rowCount() !== 1) continue;
        $author = q('SELECT id, is_suspended FROM users WHERE id = ?', [$r['user_id']])->fetch();
        if (!$author || (int)$author['is_suspended']) { q("UPDATE scheduled_posts SET status = 'failed', note = ? WHERE id = ?", ['Author unavailable', $r['id']]); continue; }
        q('INSERT INTO posts (user_id, body, created) VALUES (?,?,?)', [$r['user_id'], $r['body'], max((int)$r['run_at'], time() - 3600)]);
        $pid = (int)db()->lastInsertId();
        foreach (extract_tags($r['body']) as $t) q('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?,?)', [$pid, $t]);
        preg_match_all(MENTION_RE, $r['body'], $mm);
        foreach (array_slice(array_unique(array_map(fn($x) => strtolower(trim($x, '@ ')), $mm[0])), 0, (int)$CFG['free_mentions']) as $name) {
            $mu = q('SELECT id FROM users WHERE username = ?', [$name])->fetch();
            if ($mu && (int)$mu['id'] !== (int)$r['user_id']) notify((int)$mu['id'], (int)$r['user_id'], 'mention', $pid);
        }
        q("UPDATE scheduled_posts SET status = 'published', post_id = ? WHERE id = ?", [$pid, $r['id']]);
    }
}

/** POST handler for the Schedule tab. Returns [redirect, message, ok] or null when $do is not ours. */
function admin_schedule_post(string $do, array $u): ?array {
    global $CFG;
    if (!in_array($do, ['sched_add', 'sched_cancel'], true)) return null;
    if (!is_admin($u)) return ['./', 'Not allowed.', false];
    schedule_table();
    $back = '?p=admin&tab=schedule';
    if ($do === 'sched_cancel') {
        q("DELETE FROM scheduled_posts WHERE id = ? AND status IN ('pending','failed','published')", [(int)($_POST['sid'] ?? 0)]);
        return [$back, 'Removed.', true];
    }
    $body = clean((string)($_POST['body'] ?? ''));
    $_SESSION['sched_form'] = ['body' => $body, 'as' => (string)($_POST['as'] ?? ''), 'when' => (string)($_POST['when'] ?? '')];
    $ts = (int)($_POST['ts'] ?? 0);                                       // set by the browser from the local clock
    if ($ts <= 0 && trim((string)($_POST['when'] ?? '')) !== '') $ts = (int)strtotime((string)$_POST['when']);   // no-JS fallback: server time zone
    $as = trim((string)($_POST['as'] ?? ''));
    $author = $as === '' ? $u : q('SELECT * FROM users WHERE lower(username) = lower(?) AND is_suspended = 0', [ltrim($as, '@')])->fetch();
    if (!$author) return [$back, 'No active member is called @' . ltrim($as, '@') . '.', false];
    if ($body === '') return [$back, 'Write the post first.', false];
    if (len($body) > hard_max() || counted_len($body) > $CFG['max_chars']) return [$back, 'Too long: ' . $CFG['max_chars'] . ' characters max (@usernames are free).', false];
    if (has_link($body)) return [$back, 'Links are not allowed.', false];
    if ($ts < time() + 30) return [$back, 'Pick a time at least a minute in the future.', false];
    if ($ts > time() + 366 * 86400) return [$back, 'That is more than a year away.', false];
    if ((int)q("SELECT COUNT(*) FROM scheduled_posts WHERE status = 'pending'")->fetchColumn() >= 500) return [$back, 'Too many scheduled posts waiting.', false];
    q('INSERT INTO scheduled_posts (user_id, body, run_at, created_by, created) VALUES (?,?,?,?,?)', [$author['id'], $body, $ts, $u['id'], time()]);
    unset($_SESSION['sched_form']);
    return [$back, 'Scheduled for ' . date('M j, Y g:i A', $ts) . ' (server time).', true];
}

function admin_schedule_tab(array $u): void {
    schedule_table();
    global $CFG;
    $f = $_SESSION['sched_form'] ?? ['body' => '', 'as' => '', 'when' => '']; unset($_SESSION['sched_form']);
    $csrf = '<input type="hidden" name="csrf" value="' . csrf() . '">';
    echo '<form method="post" action="?p=admin" class="card" id="schedform">' . $csrf . '<input type="hidden" name="do" value="sched_add"><input type="hidden" name="ts" id="sched_ts" value="">'
        . '<h2>Schedule a post</h2><p class="hint">Only admins can see this. Posts go live on their own at the chosen time, the next time anyone loads the site.</p>'
        . '<label>Post <textarea name="body" rows="3" maxlength="' . (int)hard_max() . '" required>' . h($f['body']) . '</textarea></label>'
        . '<div class="two"><label>When <span class="hint">Your local time</span><input type="datetime-local" name="when" id="sched_when" value="' . h($f['when']) . '" required></label>'
        . '<label>Post as <span class="hint">Empty is you</span><input name="as" value="' . h($f['as']) . '" maxlength="15" autocapitalize="off" placeholder="@' . h($u['username']) . '"></label></div>'
        . '<button class="btn">Schedule</button></form>';
    echo '<script>(function(){var f=document.getElementById("schedform"),w=document.getElementById("sched_when"),t=document.getElementById("sched_ts");'
        . 'if(!f)return;f.addEventListener("submit",function(){var d=new Date(w.value);t.value=isNaN(d)?"":Math.floor(d/1000)});'
        . 'var n=new Date(Date.now()+3600000);n.setMinutes(0,0,0);if(!w.value){var p=function(x){return("0"+x).slice(-2)};w.value=n.getFullYear()+"-"+p(n.getMonth()+1)+"-"+p(n.getDate())+"T"+p(n.getHours())+":00"}})();</script>';
    $rows = q("SELECT s.*, a.username FROM scheduled_posts s JOIN users a ON a.id = s.user_id
               ORDER BY CASE WHEN s.status IN ('pending','publishing') THEN 0 ELSE 1 END, CASE WHEN s.status = 'pending' THEN s.run_at END ASC, s.run_at DESC LIMIT 100")->fetchAll();
    echo '<h2>Queue</h2>';
    if (!$rows) echo '<p class="empty">Nothing scheduled.</p>';
    foreach ($rows as $r) {
        $st = ['pending' => 'Scheduled', 'publishing' => 'Publishing', 'published' => 'Published', 'failed' => 'Failed'][$r['status']] ?? $r['status'];
        echo '<div class="arow"><div class="who"><span class="pill">' . h($st) . '</span> <b>@' . h($r['username']) . '</b> <small>' . h(date('M j, Y g:i A', (int)$r['run_at'])) . '</small><br>'
            . h($r['body']) . ($r['note'] !== '' ? '<br><small>' . h($r['note']) . '</small>' : '') . '</div><div class="ractions">'
            . ($r['post_id'] ? '<a class="btn sm alt" href="?p=status&amp;id=' . (int)$r['post_id'] . '">View</a>' : '')
            . '<form method="post" action="?p=admin" class="inl"' . ($r['status'] === 'pending' ? ' onsubmit="return confirm(\'Cancel this scheduled post?\')"' : '') . '>' . $csrf . '<input type="hidden" name="do" value="sched_cancel"><input type="hidden" name="sid" value="' . (int)$r['id'] . '"><button class="btn sm alt">' . ($r['status'] === 'pending' ? 'Cancel' : 'Remove') . '</button></form></div></div>';
    }
}
