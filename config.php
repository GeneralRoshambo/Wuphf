<?php
// Wuphf configuration. Edit to taste.
return [
    'site_name'    => 'Wuphf',
    'max_chars'    => 140,
    'allow_signup' => true,          // set false to close registration
    'per_page'     => 30,
    'db_file'      => __DIR__ . '/data/wuphf.sqlite',
    'free_mentions'=> 5,             // @usernames that don't count toward the limit
    'min_post_gap' => 3,             // seconds a user must wait between posts
];
