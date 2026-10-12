<?php
// Wuphf configuration. Edit to taste.
return [
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
    'min_post_gap' => 5,             // seconds a user must wait between posts
];
