<?php
/** Logout lokal: hapus session lalu arahkan kembali ke portal SSO. */
require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . SSO_PORTAL . '?target_system=' . urlencode(APP_URL));
exit;
