<?php
/**
 * Create (or reset the password of) a back-office admin account. Run on the server:
 *   php tools/create_admin.php you@example.com "Your Name"
 * The password is asked for on the terminal (not echoed to history).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
define('ROOT_PATH', dirname(__DIR__));
foreach (['core', 'helpers', 'config'] as $d) {
    foreach (glob(ROOT_PATH . "/$d/*.php") as $f) require_once $f;
}
$email = strtolower(trim($argv[1] ?? ''));
$name  = trim($argv[2] ?? 'Admin');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage: php tools/create_admin.php email \"Name\"\n"); exit(1); }
fwrite(STDOUT, "Password (min 10 chars): ");
system('stty -echo 2>/dev/null'); $pw = trim((string) fgets(STDIN)); system('stty echo 2>/dev/null'); echo "\n";
if (strlen($pw) < 10) { fwrite(STDERR, "Password too short.\n"); exit(1); }
$db = Database::getInstance();
$hash = CommonHelper::hashPassword($pw);
if ($db->getRow('SELECT id FROM admins WHERE email = :e', [':e' => $email])) {
    $db->update('admins', ['password_hash' => $hash, 'is_active' => 1, 'name' => $name], 'email = :e', [':e' => $email]);
    echo "Updated $email\n";
} else {
    $db->insert('admins', ['name' => $name, 'email' => $email, 'password_hash' => $hash]);
    echo "Created $email\n";
}
