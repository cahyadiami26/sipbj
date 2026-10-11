<?php
/** Notifikasi Saya — daftar semua item yang menunggu tindakan user saat ini */
$pageTitle = 'Notifikasi Saya';
$activeNav = 'notifikasi';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/header.php';

$uid   = authId();
$role  = appRole();
$isPeng = in_array($role, ['admin', 'pengadaan'], true);
$isPimp = in_array($role, ['admin', 'pimpinan'], true);

$groups = [];

/* ---------- A. Milik saya (menunggu aksi orang lain / saya) ---------- */
try {
  $rows = qAll("SELECT * FROM atk_permintaan WHERE pemohon=? AND status<>'DITERIMA' AND status<>'DITOLAK' ORDER BY id DESC", [$uid]);
  foreach ($rows as $r) $groups['atk'][] = [
    'title' => 'Permintaan ATK ' . $r['nomor_permintaan'],
    'desc'  => 'Status: ' . $r['status'] . ' — ' . mb_strimwidth((string)$r['keperluan'], 0, 80, '…'),
    'url'   => 'modules/atk_permintaan.php?id=' . $r['id'],
    'time'  => $r['tanggal'],
    'tone'  => $r['status'] === 'DISERAHKAN' ? 'amber' : 'blue',
    'action'=> $r['status'] === 'DISERAHKAN' ? 'Konfirmasi penerimaan' : 'Lihat detail',
  ];
} catch (Throwable $e) {}

try {
  $rows = qAll("SELECT * FROM kerusakan WHERE pelapor=? AND status NOT IN ('SELESAI','DITOLAK') ORDER BY id DESC", [$uid]);
  foreach ($rows as $r) $groups['kerusakan'][] = [
    'title' => 'Laporan Kerusakan ' . $r['nomor_laporan'],
    'desc'  => $r['judul'] . ' — Status: ' . $r['status'],
    'url'   => 'modules/kerusakan.php?id=' . $r['id'],
    'time'  => $r['tanggal_lapor'],
    'tone'  => 'amber',
    'action'=> 'Lihat progres',
  ];
} catch (Throwable $e) {}

try {
  $rows = qAll("SELECT pk.*, k.tnkb, k.merk_type FROM peminjaman_kendaraan pk JOIN kendaraan k ON k.id=pk.kendaraan_id
                WHERE pk.pemohon=? AND pk.status NOT IN ('DITERIMA','DITOLAK') ORDER BY pk.id DESC", [$uid]);
  foreach ($rows as $r) $groups['pinjam'][] = [
    'title' => 'Pengajuan Pinjam Kendaraan ' . $r['nomor_pinjam'],
    'desc'  => ($r['tnkb'] ?? '?') . ' ' . ($r['merk_type'] ?? '') . ' • ' . tgl($r['tanggal_pinjam']) . ' s.d. ' . tgl($r['tanggal_kembali']) . ' — ' . $r['status'],
    'url'   => 'modules/peminjaman.php?id=' . $r['id'],
    'time'  => $r['tanggal_pinjam'],
    'tone'  => 'blue',
    'action'=> 'Lihat status',
  ];
} catch (Throwable $e) {}

try {
  $rows = qAll("SELECT * FROM fasilitas_tiket WHERE pelapor=? AND status NOT IN ('SELESAI','DITOLAK') ORDER BY id DESC", [$uid]);
  foreach ($rows as $r) $groups['fasilitas'][] = [
    'title' => 'Tiket Fasilitas ' . $r['nomor_tiket'],
    'desc'  => $r['jenis'] . ' — ' . mb_strimwidth((string)$r['masalah'], 0, 80, '…') . ' (' . $r['status'] . ')',
    'url'   => 'modules/fasilitas.php?id=' . $r['id'],
    'time'  => $r['tanggal_lapor'],
    'tone'  => 'slate',
    'action'=> 'Lihat progres',
  ];
} catch (Throwable $e) {}

/* ---------- B. Antrian tugas (untuk pengelola / pimpinan) ---------- */
if ($isPeng || $isPimp) {
  try {
    $rows = qAll("SELECT * FROM kebutuhan WHERE status IN ('MENUNGGU VERIFIKASI','MENUNGGU PENGESAHAN KADIV','MENUNGGU PENGESAHAN DOP','REVISI DIKAJU') ORDER BY FIELD(status,'MENUNGGU VERIFIKASI','MENUNGGU PENGESAHAN KADIV','MENUNGGU PENGESAHAN DOP','REVISI DIKAJU'), id DESC");
    foreach ($rows as $r) $groups['antri_keb'][] = [
      'title' => 'Kebutuhan Menunggu Tindakan — ' . $r['nomor_kebutuhan'],
      'desc'  => $r['unit_pemohon'] . ' • ' . $r['judul'] . ' • ' . $r['status'],
      'url'   => 'modules/kebutuhan.php?id=' . $r['id'],
      'time'  => substr((string)$r['created_at'], 0, 10),
      'tone'  => 'red',
      'action'=> 'Proses sekarang',
    ];
  } catch (Throwable $e) {}

  if ($isPeng) try {
    $rows = qAll("SELECT * FROM rencana_pengadaan WHERE status='DIAJUKAN' ORDER BY id DESC");
    foreach ($rows as $r) $groups['antri_ren'][] = [
      'title' => 'Rencana Diajukan — ' . $r['nomor_rencana'],
      'desc'  => $r['judul'] . ' • Prioritas ' . $r['prioritas'],
      'url'   => 'modules/rencana.php?id=' . $r['id'],
      'time'  => $r['tanggal'], 'tone' => 'amber', 'action' => 'Verifikasi & approve',
    ];
  } catch (Throwable $e) {}

  if ($isPeng) try {
    $rows = qAll("SELECT * FROM atk_permintaan WHERE status='MENUNGGU PROSES' ORDER BY id DESC");
    foreach ($rows as $r) $groups['antri_atk'][] = [
      'title' => 'Permintaan ATK Perlu Verifikasi Stok — ' . $r['nomor_permintaan'],
      'desc'  => 'Unit ' . $r['unit_pemohon'] . ' oleh ' . userName($r['pemohon']),
      'url'   => 'modules/atk_permintaan.php?id=' . $r['id'],
      'time'  => $r['tanggal'], 'tone' => 'teal', 'action' => 'Verifikasi stok',
    ];
    $rows = qAll("SELECT * FROM atk_permintaan WHERE status='DISSETUJUI' ORDER BY id DESC");
    foreach ($rows as $r) $groups['antri_atk'][] = [
      'title' => 'ATK Siap Diserahkan — ' . $r['nomor_permintaan'],
      'desc'  => 'Unit ' . $r['unit_pemohon'],
      'url'   => 'modules/atk_permintaan.php?id=' . $r['id'],
      'time'  => $r['tanggal'], 'tone' => 'green', 'action' => 'Serahkan',
    ];
  } catch (Throwable $e) {}

  if ($isPeng) try {
    $rows = qAll("SELECT * FROM kerusakan WHERE status IN ('DILAPORKAN','DIVERIFIKASI','DIAGNOSIS') ORDER BY id DESC");
    foreach ($rows as $r) $groups['antri_krk'][] = [
      'title' => 'Kerusakan Menunggu Tindak Lanjut — ' . $r['nomor_laporan'],
      'desc'  => $r['judul'] . ' • ' . $r['status'],
      'url'   => 'modules/kerusakan.php?id=' . $r['id'],
      'time'  => $r['tanggal_lapor'], 'tone' => 'red', 'action' => 'Proses',
    ];
  } catch (Throwable $e) {}

  if ($isPeng) try {
    $rows = qAll("SELECT pk.*, k.tnkb FROM peminjaman_kendaraan pk JOIN kendaraan k ON k.id=pk.kendaraan_id WHERE pk.status='MENUNGGU APPROVE KABAG' ORDER BY pk.id DESC");
    foreach ($rows as $r) $groups['antri_pinjam'][] = [
      'title' => 'Peminjaman Kendaraan Menunggu Approve — ' . $r['nomor_pinjam'],
      'desc'  => ($r['tnkb'] ?? '') . ' oleh ' . userName($r['pemohon']),
      'url'   => 'modules/peminjaman.php?id=' . $r['id'],
      'time'  => $r['tanggal_pinjam'], 'tone' => 'amber', 'action' => 'Approve / Tolak',
    ];
    $rows = qAll("SELECT pk.*, k.tnkb FROM peminjaman_kendaraan pk JOIN kendaraan k ON k.id=pk.kendaraan_id WHERE pk.status='DIPINJAM' AND pk.tanggal_kembali < CURDATE() ORDER BY pk.tanggal_kembali");
    foreach ($rows as $r) $groups['antri_pinjam'][] = [
      'title' => '⚠ Kendaraan Belum Dikembalikan — ' . $r['nomor_pinjam'],
      'desc'  => ($r['tnkb'] ?? '') . ' • jatuh tempo ' . tgl($r['tanggal_kembali']),
      'url'   => 'modules/peminjaman.php?id=' . $r['id'],
      'time'  => $r['tanggal_kembali'], 'tone' => 'red', 'action' => 'Konfirmasi pengembalian',
    ];
  } catch (Throwable $e) {}

  if ($isPeng) try {
    $rows = qAll("SELECT * FROM fasilitas_tiket WHERE status IN ('LAPORAN BARU','VERIFIKASI','PENUGASAN','PERBAIKAN','PEMERIKSAAN') ORDER BY FIELD(status,'LAPORAN BARU','VERIFIKASI','PENUGASAN','PERBAIKAN','PEMERIKSAAN'), id DESC");
    foreach ($rows as $r) $groups['antri_fas'][] = [
      'title' => 'Tiket Fasilitas — ' . $r['nomor_tiket'],
      'desc'  => $r['jenis'] . ' • ' . mb_strimwidth((string)$r['masalah'], 0, 70, '…') . ' • ' . $r['status'],
      'url'   => 'modules/fasilitas.php?id=' . $r['id'],
      'time'  => $r['tanggal_lapor'], 'tone' => 'slate', 'action' => 'Proses tiket',
    ];
  } catch (Throwable $e) {}

  if ($isPeng) try {
    $rows = qAll("SELECT * FROM kendaraan WHERE jadwal_servis_km IS NOT NULL AND km_terakhir >= jadwal_servis_km - 500 AND status <> 'SERVICE' ORDER BY (jadwal_servis_km - km_terakhir)");
    foreach ($rows as $r) $groups['servis'][] = [
      'title' => 'Pengingat Servis — ' . $r['tnkb'],
      'desc'  => $r['merk_type'] . ' • KM ' . number_format((float)$r['km_terakhir'], 0, ',', '.') . ' / jadwal ' . number_format((float)$r['jadwal_servis_km'], 0, ',', '.'),
      'url'   => 'modules/servis.php?catat=' . $r['id'],
      'time'  => date('Y-m-d'), 'tone' => 'amber', 'action' => 'Catat servis',
    ];
  } catch (Throwable $e) {}

  if ($isPimp) try {
    $rows = qAll("SELECT * FROM rencana_pengadaan WHERE status IN ('DIAJUKAN','DALAM PROSES PENGADAAN') AND prioritas IN ('TINGGI','SEGERA') ORDER BY id DESC LIMIT 20");
    foreach ($rows as $r) $groups['pimpinan'][] = [
      'title' => 'Rencana Prioritas ' . $r['prioritas'] . ' — ' . $r['nomor_rencana'],
      'desc'  => $r['judul'] . ' • Estimasi ' . rupiah($r['estimasi_total']) . ' • ' . $r['status'],
      'url'   => 'modules/rencana.php?id=' . $r['id'],
      'time'  => $r['tanggal'], 'tone' => 'red', 'action' => 'Pantau',
    ];
  } catch (Throwable $e) {}
}

/* urutkan grup: antrian tugas lebih dulu untuk pengelola */
$orderOwn = ['antri_keb','antri_ren','antri_atk','antri_krk','antri_pinjam','antri_fas','servis','pimpinan','atk','kerusakan','pinjam','fasilitas'];
$labels = [
  'antri_keb' => '📋 Pengajuan Kebutuhan menunggu Anda',
  'antri_ren' => '🛒 Rencana Pengadaan menunggu persetujuan',
  'antri_atk' => '✏️ Permintaan ATK menunggu proses',
  'antri_krk' => '🔧 Laporan Kerusakan menunggu tindak lanjut',
  'antri_pinjam' => '🚗 Peminjaman Kendaraan perlu diproses',
  'antri_fas' => '💡 Tiket Fasilitas perlu ditangani',
  'servis'    => '🛠 Pengingat Servis Kendaraan',
  'pimpinan'  => '👔 Pemantauan Pimpinan',
  'atk'       => '✏️ Status permintaan ATK Anda',
  'kerusakan' => '🔧 Status laporan kerusakan Anda',
  'pinjam'    => '🚗 Status pengajuan kendaraan Anda',
  'fasilitas' => '💡 Status tiket fasilitas Anda',
];
$total = array_sum(array_map('count', $groups));
?>
<div class="page-head">
  <div><h1><?= iconSvg('bell') ?> Notifikasi Saya</h1>
    <p class="muted">Ringkasan seluruh item yang berkaitan dengan Anda dan antrean pekerjaan sesuai role <b><?= e($role) ?></b>.</p></div>
  <a class="btn btn-outline" href="<?= u('index.php') ?>"><?= iconSvg('dashboard') ?> Ke Dashboard</a>
</div>

<?php if (!$total): ?>
  <div class="card"><div class="card-body empty" style="padding:48px;text-align:center">
    <div style="font-size:34px;margin-bottom:8px">✅</div>
    Tidak ada notifikasi. Semua beres!
  </div></div>
<?php endif; ?>

<?php foreach ($orderOwn as $key): if (empty($groups[$key])) continue; $itemsG = $groups[$key]; ?>
<div class="card notif-card tone-<?= e($itemsG[0]['tone'] ?? 'slate') ?>">
  <div class="card-head"><h3><?= e($labels[$key] ?? $key) ?> <span class="pill"><?= count($itemsG) ?></span></h3></div>
  <div class="card-body list-plain">
    <?php foreach ($itemsG as $it): ?>
      <div class="row-line">
        <span class="dot-status ds-<?= e($it['tone']) ?>"></span>
        <div style="flex:1">
          <a class="notif-title" href="<?= u($it['url']) ?>"><?= e($it['title']) ?></a>
          <div class="muted small"><?= e($it['desc']) ?></div>
        </div>
        <span class="muted small" style="white-space:nowrap"><?= tgl($it['time']) ?></span>
        <a class="btn btn-sm btn-outline" href="<?= u($it['url']) ?>"><?= e($it['action']) ?> <?= iconSvg('next') ?></a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
