<?php
/** DEV ONLY: simulasi login SSO (bypass) untuk pengujian lokal. Hapus di produksi! */
if (!getenv('APP_ENV') || getenv('APP_ENV') !== 'development') { exit('Non-development env'); }
session_save_path('/tmp');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

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
