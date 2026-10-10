<?php
/**
 * =========================================================
 * Sistem Pengadaan Barang/Jasa - PT. Lampung Jasa Utama
 * https://pengadaan.lju.co.id
 * Konfigurasi utama aplikasi
 * =========================================================
 */

// ---------- Informasi Aplikasi ----------
define('APP_NAME', 'SIPBJ');
define('APP_TITLE', 'Sistem Pengadaan Barang/Jasa');
define('APP_COMPANY', 'PT. Lampung Jasa Utama');
define('APP_URL', getenv('APP_BASE_URL') ?: 'https://pengadaan.lju.co.id');
define('APP_VERSION', '1.0.0');

// ---------- Portal SSO ----------
define('SSO_PORTAL', 'https://lju.co.id/dashboard/');
define('SSO_DOMAIN', getenv('APP_COOKIE_DOMAIN') !== false ? getenv('APP_COOKIE_DOMAIN') : '.lju.co.id');

// ---------- Lingkungan (development / production) ----------
define('APP_ENV', getenv('APP_ENV') ?: 'production');
// Di dev (HTTP lokal), cookie Secure/domain global bisa ditolak browser -> set APP_COOKIE_SECURE=false
define('APP_COOKIE_SECURE', filter_var(getenv('APP_COOKIE_SECURE') ?? 'true', FILTER_VALIDATE_BOOLEAN));

// ---------- Aset & Logo Perusahaan ----------
define('LOGO_BASE', 'https://lju.co.id/assets/images/logo/');
define('LOGO_FILE', 'logo.png');                       // logo utama (header/sidebar)
define('AVATAR_BASE', 'https://dms.lju.co.id/uploads/avatars/');
define('DEFAULT_AVATAR', LOGO_BASE . LOGO_FILE);

// ---------- Database ----------
// Database utama aplikasi (pengadaan)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pengadaan_lju');

// Database SSO/users (database berbeda, satu mesin MySQL yang sama)
define('DB_SSO_NAME', 'sso_lju');

// ---------- Path ----------
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('UPLOAD_QR_PATH', UPLOAD_PATH . '/qr');
define('UPLOAD_FOTO_PATH', UPLOAD_PATH . '/foto');
define('UPLOAD_BUKTI_PATH', UPLOAD_PATH . '/bukti');
define('LIB_DOMPDF', ROOT_PATH . '/lib/dompdf/vendor/autoload.php');

// ---------- DomPDF cache ----------
define('DOMPDF_CACHE', ROOT_PATH . '/tmp');

// ---------- Zona waktu & encoding ----------
date_default_timezone_set('Asia/Jakarta');
mb_internal_encoding('UTF-8');

// ---------- Folder uploads ----------
foreach ([UPLOAD_PATH, UPLOAD_QR_PATH, UPLOAD_FOTO_PATH, UPLOAD_BUKTI_PATH, DOMPDF_CACHE] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}
