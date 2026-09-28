<?php
// Reservations — reservation_status, reservation_status_log. Numele bazei: rulează php bin/doctor.php --find-reservations
// Copy to config/database-reservations.php on the server (gitignored, chmod 600).
declare(strict_types=1);

return [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'CONFIRM_WITH_bin_doctor.php',
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME',
];
