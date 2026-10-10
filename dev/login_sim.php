<?php
/** DEV ONLY: simulasi login SSO (bypass) untuk pengujian lokal. Hapus di produksi! */
if (!getenv('APP_ENV') || getenv('APP_ENV') !== 'development') { exit('Non-development env'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

// Mulai session dengan parameter yang sama seperti includes/auth.php agar cookie persisten
ini_set('session.cookie_domain', SSO_DOMAIN);
session_name('LJU_APPS');
session_set_cookie_params([
    'lifetime' => 86400, 'path' => '/', 'domain' => SSO_DOMAIN,
    'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

$uid = (int)($_GET['uid'] ?? 1);
try { $st = ssoDb()->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]); $u = $st->fetch(); }
catch (Throwable $e) { exit('SSO db error: ' . $e->getMessage()); }
if (!$u) exit('User tidak ada');

$_SESSION = [
    'logged_in' => true, 'user_id' => (int)$u['id'], 'user_name' => $u['name'],
    'username' => $u['username'], 'email' => $u['email'] ?? null, 'role' => $u['role'],
    'position' => $u['position'], 'division' => $u['division'], 'department' => $u['department'],
    'avatar' => $u['avatar'],
];
echo 'OK logged in as #' . $u['id'] . ' ' . $u['name'] . ' (' . ($u['position'] ?: '-') . ')';
