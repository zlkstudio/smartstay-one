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
        '400' => '18045779828',   // Ap. 400
    ],

    // Cronometru curățenie: numele codului de tastatură al fiecărei menajere (cheile din config/app.php 'maids').
    // Implicit „<Nume> Menaj” — completează doar dacă în Nuki codul are alt nume.
    // 'maid_names' => ['ioana' => 'Ioana Menaj', 'cristina' => 'Cristina Menaj'],

    // Coduri care NU se șterg niciodată la check-out. Romeo, Ioana Menaj, Cristina Menaj și Entry Code
    // sunt protejate oricum, din cod; aici poți doar adăuga altele.
    // 'protected_names' => ['Stefana Menaj'],
];
