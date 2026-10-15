<?php
// Wuphf configuration. Edit to taste.
$config = [
    'site_name'    => 'Wuphf',
    'max_chars'    => 140,
    'allow_signup' => true,          // set false to close registration
    'per_page'     => 30,
    'db_file'      => __DIR__ . '/data/wuphf.sqlite',
    'free_mentions'=> 5,             // @usernames that don't count toward the limit
    'site_url'     => 'https://wuphf.lol',   // used for links in emails (no trailing slash)
    'mail_from'    => 'noreply@wuphf.lol',  // must be an address on your domain
    'mail_mode'    => getenv('WUPHF_MAIL_MODE') ?: 'mail',   // 'mail' = PHP mail(); 'log' = write to data/outbox.log (testing)
    'require_confirm' => true,       // unconfirmed accounts cannot post or follow
    'force_2fa'    => false,         // true = everyone with a confirmed email gets a login code
    'admins'       => [],            // usernames allowed to use the admin pages, e.g. ['wuphf'] (set in config.local.php)
    'admin_email'  => '',            // optional: get an email when someone reports a post or account
    'contact_email'=> '',            // optional: shown on the Rules and Privacy pages
    'cron_key'     => '',            // optional: set a secret and call /?p=cron&key=SECRET from cron for exact-time scheduled posts and digests
    'edit_window'  => 300,           // seconds an author can edit a post after posting
    'min_post_gap' => 5,             // seconds a user must wait between posts
];

// Server-specific overrides live in config.local.php, which deploys never touch.
if (is_file(__DIR__ . '/config.local.php')) $config = array_replace($config, require __DIR__ . '/config.local.php');
return $config;
