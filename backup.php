<?php
// Keeps dated copies of the database. Run it from a cPanel cron job, for example daily:
//   /usr/local/bin/php /home/YOURUSER/public_html/backup.php
// Command line only; the web server refuses it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'CLI';
chdir(__DIR__);
require __DIR__ . '/lib.php';

$keep = 14;                                   // how many copies to keep
$dir = dirname($CFG['db_file']) . '/backups';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { fwrite(STDERR, "Cannot create $dir\n"); exit(1); }
$dest = $dir . '/wuphf-' . date('Ymd-His') . '.sqlite';
if (!backup_to($dest)) { fwrite(STDERR, "Backup failed\n"); exit(1); }
$files = glob($dir . '/wuphf-*.sqlite'); sort($files);
foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $old) @unlink($old);
echo "Saved $dest (" . round(filesize($dest) / 1024) . " KB), keeping " . min($keep, count($files)) . " copies\n";
