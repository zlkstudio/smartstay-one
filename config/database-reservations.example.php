<?php
// Reservations — reservation_status, reservation_status_log (baza confirmată: smartconcept_reservations)
// Copy to config/database-reservations.php on the server (gitignored, chmod 600).
declare(strict_types=1);

return [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'smartconcept_reservations',
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME',
];
