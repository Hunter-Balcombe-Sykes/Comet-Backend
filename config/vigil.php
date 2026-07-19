<?php

return [
    'checks' => [
        'fs.public_folder' => true,
        'fs.malicious_js' => true,
        'fs.storage_dangerous' => true,
        'fs.permissions' => true,
        'fs.sensitive_exposure' => true,
        'cfg.php_ini' => true,
        'cfg.env' => true,
        'cfg.session' => true,
        'cfg.cors' => true,
        'http.headers' => true,
        'dep.composer_audit' => true,
        'ext.hardcoded_secrets' => true,
        'ext.debug_routes' => true,
        'ext.telescope_debugbar' => true,
        'ext.file_integrity' => false,
    ],

    'public_allowed_extensions' => [
        'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'pdf', 'map', 'txt',
    ],

    'storage_dangerous_extensions' => [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7',
        'exe', 'bat', 'cmd', 'sh', 'bash', 'bin', 'js', 'vbs', 'ps1',
    ],

    // Never write vigil_scans/vigil_check_results rows — this repo has no Laravel
    // migrations (Supabase SQL only) and CI has no Postgres. Scan output goes to
    // stdout/JSON only.
    'store_results' => false,
    'results_retention_days' => 90,

    'notifications' => [
        'enabled' => false,
        'channels' => ['mail'],
        'notify_on_severity' => ['critical', 'high'],
        'mail_to' => env('VIGIL_MAIL_TO', null),
    ],
];
