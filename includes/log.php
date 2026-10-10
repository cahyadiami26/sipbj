<?php
/** Catat aktivitas user ke activity_log */
require_once __DIR__ . '/db.php';

function logActivity(string $modul, string $aksi, ?string $detail = null): void
{
    try {
        q("INSERT INTO activity_log (user_id,user_name,modul,aksi,detail,ip) VALUES (?,?,?,?,?,?)", [
            authId(), authName(), $modul, $aksi, $detail, $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* abaikan agar tidak mengganggu flow utama */ }
}

/** Ambil nama user dari DB SSO (fallback id) */
function userName($id): string
{
    if (!$id) return '-';
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        $st = ssoDb()->prepare('SELECT name FROM users WHERE id=?');
        $st->execute([$id]);
        $n = $st->fetchColumn();
    } catch (Throwable $e) { $n = null; }
    return $cache[$id] = ($n ?: ('User #' . $id));
}

/** Daftar unit (bidang) untuk dropdown */
function unitOptions(): array
{
    return ['Keuangan','Pengadaan','Keamanan','Infokom','SDM','Direksi','IT','Legal','Kesekretariatan',
            'Jasa Konstruksi','Pengangkutan & Pergudangan','Perdagangan','Energi & Migas'];
}
