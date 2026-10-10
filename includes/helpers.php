<?php
/**
 * Helper tampilan & utilitas umum.
 */
require_once __DIR__ . '/auth.php';

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function rupiah($n, bool $prefix = true): string
{
    $v = (float)($n ?? 0);
    return ($prefix ? 'Rp ' : '') . number_format($v, 0, ',', '.');
}

function tgl(?string $dt, string $fmt = 'd/m/Y'): string
{
    if (!$dt) return '-';
    $t = strtotime($dt);
    return $t ? date($fmt, $t) : '-';
}

function tglIndo(?string $dt): string
{
    if (!$dt) return '-';
    $hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $t = strtotime($dt);
    if (!$t) return '-';
    return $hari[(int)date('w',$t)] . ', ' . date('j',$t) . ' ' . $bulan[(int)date('n',$t)] . ' ' . date('Y',$t);
}

/** Badge status dengan warna konsisten */
function badge(string $status): string
{
    $map = [
        // kebutuhan / rencana / permintaan
        'DRAFT' => 'slate', 'DIAJUKAN' => 'blue', 'DIVERIFIKASI' => 'amber', 'DIVALIDASI' => 'amber',
        'DISETUJUI' => 'teal', 'FINAL ANGGARAN' => 'teal', 'DALAM PROSES PENGADAAN' => 'violet',
        'MASUK RENCANA PENGADAAN' => 'violet', 'DITERIMA' => 'green', 'DITERIMA SEBAGIAN' => 'amber',
        'DITOLAK' => 'red', 'SELESAI' => 'green', 'DIPROSES' => 'blue',
        // kerusakan / tiket
        'LAPORAN BARU' => 'blue', 'DILAPORKAN' => 'blue', 'DIAGNOSIS' => 'amber',
        'DALAM PERBAIKAN' => 'violet', 'PENUGASAN' => 'amber', 'PEMERIKSAAN' => 'amber',
        // aset
        'AKTIF' => 'green', 'RUSAK' => 'red', 'DIPINDAHKAN' => 'blue', 'DIHAPUS' => 'slate',
        'HILANG' => 'red', 'BAIK' => 'green', 'RUSAK RINGAN' => 'amber', 'RUSAK BERAT' => 'red',
        // kendaraan
        'TERSEDIA' => 'green', 'DIPINJAM' => 'blue', 'DIGUNAKAN' => 'violet', 'SERVICE' => 'amber',
        // ATK
        'MENUNGGU PROSES' => 'amber', 'STOK TERSEDIA' => 'green', 'STOK KOSONG' => 'red',
        'MENUNGGU PENGAMBILAN' => 'blue', 'DISERAHKAN' => 'teal',
        // opname
        'BERJALAN' => 'blue', 'VALIDASI' => 'amber', 'CLOSED' => 'slate', 'COKOK' => 'green',
        'TIDAK COCOK' => 'red', 'TIDAK DITEMUKAN' => 'red',
    ];
    $s = strtoupper(trim($status));
    $c = $map[$s] ?? 'slate';
    return '<span class="badge badge-' . $c . '">' . e($s) . '</span>';
}

function iconSvg(string $name, string $cls = ''): string
{
    $icons = [
        'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'box'         => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'clipboard'   => '<rect x="8" y="2h8v4H8z" /><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
        'cart'         => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'truck'       => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'qr'          => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20v.01M20 20v.01M17 20v.01M20 17v.01"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'building'    => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
        'pencil'      => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'check'       => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'wrench'      => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'car'         => '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
        'file'        => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8M16 13H8M16 17H8"/>',
        'alert'       => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
        'bell'        => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'plus'        => '<path d="M5 12h14M12 5v14"/>',
        'back'        => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'print'       => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'swap'        => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
        'trash'       => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'lamp'        => '<path d="M8 2h8l4 8H4z"/><path d="M12 10v8"/><path d="M9 22h6"/>',
        'chart'       => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 13l4-4 4 4 5-6"/>',
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'scan'        => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
        'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'eye'         => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'shield'      => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C16.51 3.81 19 5 21 5a1 1 0 0 1 1 1z"/>',
    ];
    $body = $icons[$name] ?? $icons['box'];
    return '<svg class="ic ' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

/** Generate nomor dokumen otomatis: PREFIX-YYYYMM-0001 */
function generateNumber(PDO $pdo, string $table, string $column, string $prefix): string
{
    $ym = date('Ym');
    $like = $prefix . '-' . $ym . '-%';
    $st = $pdo->prepare("SELECT MAX(`$column`) FROM `$table` WHERE `$column` LIKE ?");
    $st->execute([$like]);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr($last, -4)) + 1 : 1;
    return $prefix . '-' . $ym . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/** Kode aset: AST-{RUANG}-{BIDANG/DIVISI}-{TAHUN}-{000001} */
function generateAssetCode(PDO $pdo, string $lokasiSlug, string $unitSlug): string
{
    $tahun = date('Y');
    $base = 'AST-' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $lokasiSlug) ?: 'GEN')
          . '-' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $unitSlug) ?: 'UMUM') . '-' . $tahun . '-';
    $st = $pdo->prepare("SELECT MAX(kode_aset) FROM aset WHERE kode_aset LIKE ?");
    $st->execute([$base . '%']);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr($last, -6)) + 1 : 1;
    return $base . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
}

/** Upload file aman -> nama file baru, simpan di folder tujuan */
function uploadFile(array $file, string $folder, array $allowExt = ['jpg','jpeg','png','webp','pdf'], int $maxMb = 5): ?string
{
    if (empty($file['name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > $maxMb * 1024 * 1024) {
        flash('Ukuran file melebihi batas ' . $maxMb . ' MB.', 'error');
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) {
        flash('Format file tidak diizinkan (' . implode(', ', $allowExt) . ').', 'error');
        return null;
    }
    if (!is_dir($folder)) @mkdir($folder, 0775, true);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name)) {
        flash('Gagal menyimpan file.', 'error');
        return null;
    }
    return $name;
}

/** QR code via library online (api.qrserver.com), disimpan ke uploads/qr */
function generateQrImage(string $text, string $filename, int $size = 240): string
{
    $path = UPLOAD_QR_PATH . '/' . $filename;
    if (!is_file($path)) {
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
             . '&data=' . rawurlencode($text) . '&qzone=2&color=0f172a&bgcolor=ffffff';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
        $img = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err || !$img) {
            // fallback placeholder teks
            $img = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
                 . '"><rect width="100%" height="100%" fill="#f1f5f9"/><text x="50%" y="50%" font-size="12" text-anchor="middle">QR gagal dibuat</text></svg>';
            $path = UPLOAD_QR_PATH . '/' . preg_replace('/\.png$/', '.svg', $filename);
        }
        file_put_contents($path, $img);
    }
    return basename($path);
}

/** URL halaman */
function u(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/** Inisial nama untuk avatar fallback */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: '?';
}
