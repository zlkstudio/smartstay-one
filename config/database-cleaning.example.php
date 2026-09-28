<?php
// Housekeeping — cleaning_records, maid_assignments, checklist_submissions
// Copy to config/database-cleaning.php on the server (gitignored, chmod 600).
declare(strict_types=1);

return [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'smartconcept_cleaning',
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME',
];
