<?php
// Staff "Check-in form" toggle → Guest App (unlocks the guest's access code).
// Copy to config/checkin-sync.php (gitignored, chmod 600). Same values as
// ~/smartstay.ro/reservations/config/checkin-sync.php — admin_token must equal
// 'admin_token' in guest-app/config/checkin.php.
declare(strict_types=1);

return [
    'guest_app_base_url' => 'https://smartstay.ro/guest-app',
    'admin_token'        => 'CHANGE_ME',
];
