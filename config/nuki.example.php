<?php
// Nuki Web API + apartment → smartlock map. Copy to config/nuki.php (gitignored, chmod 600).
// Token: from ~/smartstay.ro/reservations/config/nuki.php · map: reservations/config/nuki-smartlocks.php
declare(strict_types=1);

return [
    'api_token'  => 'CHANGE_ME',
    'api_base'   => 'https://api.nuki.io',

    // Apartment number (as in Previo <object><name>) => smartlock id
    'smartlocks' => [
        '5'   => '0000000000',
        '99'  => '0000000000',
    ],
];
