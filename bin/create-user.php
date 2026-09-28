<?php
declare(strict_types=1);

// Creates an account from the shell — used once, for the first admin.
// Usage: php bin/create-user.php --name="Romeo" --email=contact@radoiromeo.ro [--phone=07…] [--role=admin]
// Prints a temporary password; it must be changed at first login.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use One\Audit;
use One\Auth\Access;
use One\Users\UserRepository;

$opts = getopt('', ['name:', 'email::', 'phone::', 'role::', 'maid::']);
$name = trim((string) ($opts['name'] ?? ''));
$email = mb_strtolower(trim((string) ($opts['email'] ?? '')));
$phone = isset($opts['phone']) ? normalize_phone((string) $opts['phone']) : '';
$role = (string) ($opts['role'] ?? 'admin');
$maid = (string) ($opts['maid'] ?? '');

$fail = static function (string $message): never {
    fwrite(STDERR, "❌ $message\n");
    exit(1);
};

if ($name === '') {
    $fail('Lipsește --name.');
}
if ($email === '' && $phone === '') {
    $fail('Dă --email sau --phone (cu ele se face autentificarea).');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('Email invalid.');
}
if (!array_key_exists($role, Access::ROLE_LABELS)) {
    $fail('Rol invalid. Folosește: ' . implode(', ', array_keys(Access::ROLE_LABELS)));
}
if ($role === 'user') {
    $fail('Pentru rolul "user" creează contul din aplicație, ca să alegi modulele.');
}
if ($role === 'maid' && !array_key_exists($maid, config('maids', []))) {
    $fail('Pentru menajeră dă --maid=' . implode('|', array_keys(config('maids', []))));
}
if ($email !== '' && UserRepository::identifierTaken('email', $email)) {
    $fail('Există deja un cont cu acest email.');
}
if ($phone !== '' && UserRepository::identifierTaken('phone', $phone)) {
    $fail('Există deja un cont cu acest telefon.');
}

$password = UserRepository::temporaryPassword();
$id = UserRepository::create([
    'name'     => $name,
    'email'    => $email ?: null,
    'phone'    => $phone ?: null,
    'role'     => $role,
    'maid_ref' => $role === 'maid' ? $maid : null,
], $password);
Audit::log(null, 'user.create', 'user', (string) $id, ['role' => $role, 'via' => 'cli']);

echo "✅ Cont creat: $name (" . Access::ROLE_LABELS[$role] . ")\n";
echo "   Utilizator:        " . ($email ?: '+' . $phone) . "\n";
echo "   Parolă temporară:  $password\n";
echo "   Se schimbă obligatoriu la prima intrare pe " . config('base_url') . "\n";
