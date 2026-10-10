<?php
/** Laporan PDF Stock Opname (kop, ringkasan, tabel hasil) */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pdf.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);

$id = (int)($_GET['id'] ?? 0);
$o = qOne("SELECT * FROM stock_opname WHERE id=?", [$id]);
if (!$o) { flash('Opname tidak ditemukan.', 'error'); go('modules/opname.php'); }

$items = qAll("SELECT oi.*, a.nama AS aset_nama, a.unit AS aset_unit, a.kondisi AS kondisi_data, a.status AS status_data, l.nama AS lokasi_nama
               FROM opname_item oi LEFT JOIN aset a ON a.id=oi.aset_id LEFT JOIN lokasi l ON l.id=a.lokasi_id
               WHERE oi.opname_id=? ORDER BY oi.hasil='COCOK', oi.kode_scan", [$id]);

// daftar aset target yang belum di-scan
$where = "status NOT IN ('DIHAPUS')"; $pr = [];
if ($o['unit_filter'])   { $where .= " AND unit=?"; $pr[] = $o['unit_filter']; }
if ($o['lokasi_filter']) { $where .= " AND lokasi_id IN (SELECT id FROM lokasi WHERE nama LIKE ? OR parent_id IN (SELECT id FROM lokasi WHERE nama LIKE ?))"; $pr[] = '%' . $o['lokasi_filter'] . '%'; $pr[] = '%' . $o['lokasi_filter'] . '%'; }
$belum = qAll("SELECT kode_aset,nama,unit FROM aset WHERE $where AND id NOT IN (SELECT aset_id FROM opname_item WHERE opname_id=? AND aset_id IS NOT NULL)", array_merge($pr, [$id]));

$body = pdfKop('LAPORAN STOCK OPNAME', $o['nomor_opname'] . ' — ' . $o['judul']);
$body .= '<table style="width:100%;font-size:9.5pt;margin-bottom:12px;"><tr>'
       . '<td>Tanggal Mulai</td><td>: ' . tglIndo($o['tanggal_mulai']) . '</td><td>Pelaksana</td><td>: ' . e(userName($o['pelaksana'])) . '</td></tr>'
       . '<tr><td>Tanggal Selesai</td><td>: ' . ($o['tanggal_selesai'] ? tglIndo($o['tanggal_selesai']) : '-') . '</td><td>Filter</td><td>: ' . e(trim(implode(' / ', array_filter([$o['lokasi_filter'], $o['unit_filter']]))) ?: 'Semua Aset') . '</td></tr></table>';

$body .= '<div style="background:#f1f5f9;border:1px solid #cbd5e1;padding:10px;margin-bottom:14px;font-size:9.5pt;">
  <strong>Ringkasan:</strong> Data target <b>' . (int)$o['total_data'] . '</b> aset &bull;
  Terverifikasi <b>' . (int)$o['total_terverifikasi'] . '</b> &bull;
  Cocok <b>' . (int)$o['total_cocok'] . '</b> &bull;
  Tidak Cocok <b>' . (int)$o['total_tidak_cocok'] . '</b> &bull;
  Tidak Ditemukan <b>' . (int)$o['total_tidak_ditemukan'] . '</b>
</div>';

$rows = [];
foreach ($items as $x) {
    $rows[] = [
        $x['kode_scan'],
        $x['aset_nama'] ?? '(tak dikenal)',
        trim(($x['lokasi_nama'] ?? '') . ($x['aset_unit'] ? ' / ' . $x['aset_unit'] : ''), ' /'),
        $x['kondisi_hasil'] ?? '-',
        $x['kondisi_data'] ?? '-',
        $x['hasil'] ?? '-',
        $x['catatan'] ?? '',
    ];
}
$body .= '<div style="font-size:10pt;font-weight:bold;margin:10px 0 6px;">A. Hasil Scan (' . count($items) . ' item)</div>';
$body .= pdfTable(['Kode Scan', 'Nama Aset', 'Lokasi/Unit', 'Kondisi Hasil', 'Kondisi Data', 'Hasil', 'Catatan'], $rows, ['left','left','left','center','center','center','left']);

$brows = [];
foreach ($belum as $x) $brows[] = [$x['kode_aset'], $x['nama'], $x['unit'] ?? '-'];
$body .= '<div style="font-size:10pt;font-weight:bold;margin:14px 0 6px;">B. Aset Target Belum Ter-Scan (' . count($belum) . ' item)</div>';
$body .= $brows ? pdfTable(['Kode Aset', 'Nama', 'Unit'], $brows) : '<div style="font-size:9pt;color:#059669;">— Semua aset target telah ter-scan —</div>';

if ($o['catatan']) {
    $body .= '<div style="font-size:10pt;font-weight:bold;margin:14px 0 6px;">C. Catatan Validasi</div><div style="font-size:9.5pt;border:1px solid #cbd5e1;padding:8px;">' . nl2br(e($o['catatan'])) . '</div>';
}

$ttdKabag = qOne("SELECT name,position FROM users WHERE position LIKE '%Kepala Bagian%' AND division IN ('Umum','Pengadaan') LIMIT 1");
$dirut    = qOne("SELECT name,position FROM users WHERE position LIKE '%Direktur Utama%' LIMIT 1");
$body .= pdfTtd(
    ['jabatan' => 'Pelaksana Opname', 'nama' => userName($o['pelaksana'])],
    ['jabatan' => 'Kabag Umum-Pengadaan', 'nama' => $ttdKabag['name'] ?? '____________', 'ttd_img' => !empty($ttdKabag['signature']) ? LOGO_BASE . 'assets/img/ttd.png' : ''],
);
$body .= '<div style="height:24px"></div>';
$body .= pdfTtd(
    ['jabatan' => '', 'nama' => ''],
    ['jabatan' => 'Mengetahui, Direktur Utama', 'nama' => $dirut['name'] ?? '____________'],
);

echo renderPdf(pdfWrap($body, 'Laporan Opname ' . $o['nomor_opname']), 'Opname-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $o['nomor_opname']) . '.pdf', 'A4', 'landscape');
