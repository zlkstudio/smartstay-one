<?php
// Copy to config/app.php on the server. app.php is gitignored and protected by deploy-one.sh.
declare(strict_types=1);

return [
    // 'production' hides error details; 'development' shows them (MAMP / local only).
    'env'            => 'production',

    // Public URL, no trailing slash. Staging: https://one-staging.smartstay.ro
    'base_url'       => 'https://one.smartstay.ro',

    'timezone'       => 'Europe/Bucharest',

    // "Ține-mă minte": sliding session, token rotated at most once per rotate_hours.
    'session_days'          => 90,
    'session_short_hours'   => 12,   // when "Ține-mă minte" is unticked
    'session_rotate_hours'  => 24,

    // Cookie Secure flag. Leave true on the server; false only for http://localhost on MAMP.
    'cookie_secure'  => true,

    // Housekeeping staff. Keys must match maid_name used in smartconcept_cleaning
    // and the keys in housekeeping/config/app_config.php.
    'maids' => [
        'ioana'    => 'Ioana',
        'stefana'  => 'Stefana',
        'cristina' => 'Cristina',
    ],

    // Legacy apps, linked from module pages during the transition.
    'legacy_urls' => [
        'reservations' => 'https://smartstay.ro/reservations/today.php',
        'housekeeping' => 'https://smartstay.ro/housekeeping/public/index.php',
        'inventory'    => 'https://smartstay.ro/inventory/',
    ],
];
