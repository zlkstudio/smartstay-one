<?php
// Push notifications (VAPID). Do NOT fill by hand: run `php bin/push-keys.php` on the server,
// which writes config/push.php (gitignored, chmod 600, in PROTECTED_CONFIGS).
declare(strict_types=1);

return [
    'public_key'      => 'GENERATED',
    'private_key_pem' => 'GENERATED',
    'subject'         => 'mailto:contact@radoiromeo.ro',
];
