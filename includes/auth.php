<?php
/**
 * Session SSO + proteksi halaman + pemetaan data user dari tabel users (DB SSO).
 *
 * Nomenklatur dalam aplikasi:
 *  - kolom `division`   pada tabel users  => "Nama Bidang"
 *  - kolom `department` pada tabel users  => "Nama Divisi"
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

/* ---------- 1. Konfigurasi session yang sama dengan portal SSO ---------- */
ini_set('session.cookie_domain', SSO_DOMAIN);
session_name('LJU_APPS');

$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 86400,
    'path'     => '/',
    'domain'   => SSO_DOMAIN,
    // Produksi: cookie Secure aktif saat HTTPS. Dev lokal (http): bisa dimatikan via APP_COOKIE_SECURE=false
    'secure'   => $isHttps && APP_COOKIE_SECURE,
    'httponly'  => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** URL absolut permintaan saat ini */
function currentUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'pengadaan.lju.co.id';
    return $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
}

/** Redirect ke portal SSO dengan parameter target */
function redirectSSO(): void
{
    $target  = APP_URL;
    $redirect = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: ' . SSO_PORTAL . '?target_system=' . urlencode($target)
        . '&redirect_after_login=' . urlencode($redirect));
    exit;
}

/**
 * Sinkronkan session dengan data terbaru pada tabel users (DB SSO).
 * Dipanggil setiap request agar role/jabatan/always up-to-date.
 */
function syncSessionFromUsers(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    try {
        $st = ssoDb()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$_SESSION['user_id']]);
        $userRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$userRow || (int)$userRow['is_active'] !== 1) {
            // User tidak aktif / dihapus -> paksa logout lokal
            redirectSSO();
        }
        $_SESSION['user_id']      = $userRow['id'];
        $_SESSION['user_nik']     = $userRow['nik'];
        $_SESSION['username']     = $userRow['username'];
        $_SESSION['user_name']    = $userRow['name'];
        $_SESSION['user_email']   = $userRow['email'];
        $_SESSION['role']         = $userRow['role'];
        $_SESSION['position']     = $userRow['position'];
        $_SESSION['division']     = $userRow['division'];     // => Nama Bidang
        $_SESSION['department']   = $userRow['department'];   // => Nama Divisi
        $_SESSION['avatar']       = $userRow['avatar'];
        $_SESSION['photo']        = $userRow['photo'];
        $_SESSION['signature']    = $userRow['signature'];
        $_SESSION['rfid']         = $userRow['rfid'];
        $_SESSION['webmail_user'] = $userRow['webmail_user'];
        $_SESSION['webmail_pass'] = $userRow['webmail_pass'];
    } catch (PDOException $e) {
        // DB SSO tidak terjangkau: pertahankan session yang sudah ada.
        error_log('SSO users sync failed: ' . $e->getMessage());
    }
}

/** Proteksi halaman: wajib login via SSO */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        redirectSSO();
    }
    syncSessionFromUsers();
}

/* ---------- Helper identitas & peran ---------- */

function authId(): int          { return (int)($_SESSION['user_id'] ?? 0); }
function authName(): string     { return $_SESSION['user_name'] ?? 'Pengguna'; }
function authEmail(): ?string   { return $_SESSION['user_email'] ?? null; }
function authUsername(): string { return $_SESSION['username'] ?? ''; }
function authRole(): string     { return $_SESSION['role'] ?? 'user'; }
function authPosition(): ?string{ return $_SESSION['position'] ?? null; }
function authDivision(): ?string{ return $_SESSION['division'] ?? null; }   // Nama Bidang
function authDepartment(): ?string{ return $_SESSION['department'] ?? null; } // Nama Divisi

function avatarUrl(): string
{
    $av = $_SESSION['avatar'] ?? null;
    return $av ? AVATAR_BASE . rawurlencode($av) : DEFAULT_AVATAR;
}

/** Role aplikasi: admin | pengadaan | pimpinan | user */
function appRole(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $role = authRole();
    $pos  = (string)authPosition();
    $div  = (string)authDivision();   // nomenklatur baru: bidang
    $dep  = (string)authDepartment(); // nomenklatur baru: divisi

    if ($role === 'admin') {
        return $cached = 'admin';
    }
    if (in_array($pos, ['Direktur Utama', 'Direktur Operasional'], true)) {
        return $cached = 'pimpinan';
    }
    if ($div === 'Pengadaan' || $dep === 'Bisnis' && $div === 'Pengadaan') {
        return $cached = 'pengadaan';
    }
    // Staf bagian Umum-Pengadaan (di luar jabatan direksi) dianggap pengelola pengadaan
    if ($div === 'Pengadaan') {
        return $cached = 'pengadaan';
    }
    return $cached = 'user';
}

function isAdmin(): bool       { return appRole() === 'admin'; }
function isPengadaan(): bool   { return in_array(appRole(), ['admin', 'pengadaan'], true); }
function isPimpinan(): bool    { return in_array(appRole(), ['admin', 'pimpinan'], true); }

/** Boleh mengajukan kebutuhan? (Kepala Bidang / Kepala Divisi) */
function canAjukanKebutuhan(): bool
{
    if (isAdmin() || isPengadaan()) return true;
    return in_array((string)authPosition(), ['Kepala Bidang', 'Kepala Divisi'], true);
}

/** Gate sederhana per halaman */
function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array(appRole(), $roles, true)) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>403</title>'
            . '<div style="font-family:system-ui;text-align:center;padding:80px">'
            . '<h1 style="color:#dc2626">403</h1><p>Anda tidak memiliki akses ke halaman ini.</p>'
            . '<a href="' . APP_URL . '/index.php" style="color:#0d9488">&larr; Kembali ke Dashboard</a></div>';
        exit;
    }
}

/** Token CSRF */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}
function verifyCsrf(): void
{
    $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$t || !hash_equals(csrfToken(), $t)) {
        http_response_code(419);
        exit('Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

/** Flash message */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
}
function getFlashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Redirect internal */
function go(string $url): void
{
    header('Location: ' . $url);
    exit;
}
