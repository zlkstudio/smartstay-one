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

    // WhatsApp la Plata menajerelor: numărul vine din contul ONE al menajerei (rol Menajeră).
    // Doar pentru menajerele fără cont: 'maid_phones' => ['ioana' => '07…'].
    'maid_phones' => [],

    // Guest App links generated in Rezervări (production URL, no trailing slash).
    'guest_app_url' => 'https://smartstay.ro/guest-app',

    // Guest App check-in state (status.json per rezervare), citit direct de pe disc pentru notificarea
    // „numerar pe masă" din Curățenie. Implicit: ~/shared/guest-app/data/checkin (ținta symlink-ului).
    // 'guest_app_checkin_dir' => '/home/smartconcept/shared/guest-app/data/checkin',

    // Notificări push către admini (cheile: php bin/push-keys.php → config/push.php).
    // Implicit: checklist trimis + orice modificare în Inventar. 'actions' adaugă altele din audit_log,
    // ex. 'reservation.status', 'housekeeping.assign'. notify_self: true = și pentru propriile acțiuni.
    'push' => ['actions' => [], 'notify_self' => false],

    // Housekeeping checklist e-mail (same addresses as housekeeping/config/app_config.php).
    'housekeeping' => [
        'report_to' => 'cleaning@smartconceptliving.ro',
        'from'      => 'no-reply@smartconceptliving.ro',
        'from_name' => 'SmartStay Cleaning System',
    ],

    // Apartments counted in the occupancy report (Rapoarte). Leave empty to use every apartment
    // that had a reservation in Previo in the last ~90 days. Parkings never count.
    'apartments' => [],

    // Rapoarte · venit: prețul rezervării din Previo e împărțit pe nopți. Dacă prețul include TVA,
    // pune cota aici (ex. 0.11) și venitul / ADR / RevPAR se afișează fără TVA, ca în Previo.
    // Verificare: php bin/report-check.php (compară cu Hotelgroup overview).
    'reports' => ['vat_rate' => 0],
];
