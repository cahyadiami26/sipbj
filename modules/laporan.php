<?php
/** Laporan — rekap aktivitas & kinerja Bagian Umum-Pengadaan + ekspor CSV */
$pageTitle = 'Laporan';
$activeNav = 'laporan';
require_once __DIR__ . '/../includes/helpers.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);

/* ---------- periode ---------- */
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$range = [$from, $to];

/* ---------- ekspor CSV ---------- */
if (isset($_GET['export'])) {
    $jenis = $_GET['export'];
    $rows = []; $header = [];
    if ($jenis === 'kebutuhan') {
        $header = ['Nomor', 'Tanggal', 'Unit', 'Judul', 'Total Estimasi', 'Status'];
        $rows = qAll("SELECT nomor_kebutuhan, tanggal_pengajuan, unit_pemohon, judul, total_estimasi, status FROM kebutuhan WHERE tanggal_pengajuan BETWEEN ? AND ?", $range);
    } elseif ($jenis === 'penerimaan') {
        $header = ['Nomor', 'Tanggal', 'Barang', 'Vendor', 'Jumlah', 'Total Nilai', 'Status'];
        $rows = qAll("SELECT nomor_penerimaan, tanggal, nama_barang, vendor, jumlah_diterima, total_nilai, status FROM penerimaan WHERE tanggal BETWEEN ? AND ?", $range);
    } elseif ($jenis === 'kerusakan') {
        $header = ['Nomor', 'Tanggal', 'Judul', 'Pelapor', 'Status'];
        $rows = qAll("SELECT nomor_laporan, DATE(tanggal_lapor), judul, pelapor, status FROM kerusakan WHERE DATE(tanggal_lapor) BETWEEN ? AND ?", $range);
    } elseif ($jenis === 'atk') {
        $header = ['Nomor', 'Tanggal', 'Unit', 'Pemohon', 'Status'];
        $rows = qAll("SELECT nomor_permintaan, tanggal, unit_pemohon, pemohon, status FROM atk_permintaan WHERE tanggal BETWEEN ? AND ?", $range);
    } elseif ($jenis === 'fasilitas') {
        $header = ['Nomor Tiket', 'Tanggal', 'Jenis', 'Masalah', 'Status'];
        $rows = qAll("SELECT nomor_tiket, tanggal_lapor, jenis, masalah, status FROM fasilitas_tiket WHERE tanggal_lapor BETWEEN ? AND ?", $range);
    } elseif ($jenis === 'pinjam') {
        $header = ['Nomor', 'Tanggal Pinjam', 'Kembali', 'Pemohon', 'Status'];
        $rows = qAll("SELECT nomor_pinjam, tanggal_pinjam, tanggal_kembali, pemohon, status FROM peminjaman_kendaraan WHERE tanggal_pinjam BETWEEN ? AND ?", $range);
    } else { // aset
        $header = ['Kode Aset', 'Nama', 'Kategori', 'Unit', 'Kondisi', 'Status'];
        $rows = qAll("SELECT kode_aset, nama, kategori, unit, kondisi, status FROM aset WHERE status <> 'DIHAPUS'");
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_' . $jenis . '_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';');
    foreach ($rows as $r) fputcsv($out, array_values($r), ';');
    fclose($out); exit;
}

/* ---------- agregat ---------- */
$tryCount = function (string $sql, array $p = []) {
    try { return (int)scalar($sql, $p); } catch (Throwable $e) { return 0; }
};
$getFloat = function (string $sql, array $p = []) {
    try { return (float)scalar($sql, $p); } catch (Throwable $e) { return 0.0; }
};

$kebBulan      = $tryCount("SELECT COUNT(*) FROM kebutuhan WHERE tanggal_pengajuan BETWEEN ? AND ?", $range);
$selesaiKeb    = $tryCount("SELECT COUNT(*) FROM kebutuhan WHERE status='SELESAI' AND DATE(selesai_tgl) BETWEEN ? AND ?", $range);
$rencanaCount  = $tryCount("SELECT COUNT(*) FROM rencana_pengadaan WHERE tanggal BETWEEN ? AND ?", $range);
$rencanaTerima = $tryCount("SELECT COUNT(*) FROM rencana_pengadaan WHERE status='TERIMA BARANG' AND tanggal BETWEEN ? AND ?", $range);
$terimaPenerimaan = $tryCount("SELECT COUNT(*) FROM penerimaan WHERE status='TELAH DITERIMA' AND tanggal BETWEEN ? AND ?", $range);
$ckSesuai      = $tryCount("SELECT COUNT(*) FROM penerimaan WHERE status='TELAH DITERIMA' AND kesesuaian_ck='SESUAI' AND tanggal BETWEEN ? AND ?", $range);
$krkCount      = $tryCount("SELECT COUNT(*) FROM kerusakan WHERE DATE(tanggal_lapor) BETWEEN ? AND ?", $range);
$krkSelesai    = $tryCount("SELECT COUNT(*) FROM kerusakan WHERE status='SELESAI' AND DATE(selesai_tgl) BETWEEN ? AND ?", $range);
$perfSelesai   = $tryCount("SELECT COUNT(*) FROM perbaikan WHERE status='SELESAI' AND tanggal BETWEEN ? AND ?", $range);
$perfGagal     = $tryCount("SELECT COUNT(*) FROM perbaikan WHERE status='GAGAL' AND tanggal BETWEEN ? AND ?", $range);
$atkCount      = $tryCount("SELECT COUNT(*) FROM atk_permintaan WHERE tanggal BETWEEN ? AND ?", $range);
$atkSelesai    = $tryCount("SELECT COUNT(*) FROM atk_permintaan WHERE status='DITERIMA' AND DATE(terima_tgl) BETWEEN ? AND ?", $range);
$pinjamCount   = $tryCount("SELECT COUNT(*) FROM peminjaman_kendaraan WHERE tanggal_pinjam BETWEEN ? AND ?", $range);
$pinjamSetuju  = $tryCount("SELECT COUNT(*) FROM peminjaman_kendaraan WHERE status IN ('DIPINJAM','SELESAI') AND tanggal_pinjam BETWEEN ? AND ?", $range);
$fasCount      = $tryCount("SELECT COUNT(*) FROM fasilitas_tiket WHERE tanggal_lapor BETWEEN ? AND ?", $range);
$fasSelesai    = $tryCount("SELECT COUNT(*) FROM fasilitas_tiket WHERE status='SELESAI' AND DATE(selesai_tgl) BETWEEN ? AND ?", $range);
$distrCount    = $tryCount("SELECT COUNT(*) FROM distribusi WHERE created_at BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59']);
$opnameCount   = $tryCount("SELECT COUNT(*) FROM stock_opname WHERE tanggal_mulai BETWEEN ? AND ?", $range);
$nilaiSerah    = $getFloat("SELECT COALESCE(SUM(total_nilai),0) FROM penerimaan WHERE status='TELAH DITERIMA' AND tanggal BETWEEN ? AND ?", $range);

$statusKrk = [];
try {
  $statusKrk = qAll("SELECT status, COUNT(*) n FROM kerusakan WHERE DATE(tanggal_lapor) BETWEEN ? AND ? GROUP BY status ORDER BY n DESC", $range);
} catch (Throwable $e) {}
try {
  $perUnitKeb = qAll("SELECT unit_pemohon u, COUNT(*) n, COALESCE(SUM(total_estimasi),0) v FROM kebutuhan WHERE tanggal_pengajuan BETWEEN ? AND ? GROUP BY unit_pemohon ORDER BY v DESC LIMIT 8", $range);
} catch (Throwable $e) { $perUnitKeb = []; }
try {
  $perJenisFas = qAll("SELECT jenis, COUNT(*) n FROM fasilitas_tiket WHERE tanggal_lapor BETWEEN ? AND ? GROUP BY jenis ORDER BY n DESC", $range);
} catch (Throwable $e) { $perJenisFas = []; }
try {
  $perKategoriAs = qAll("SELECT kategori, COUNT(*) n FROM aset WHERE status<>'DIHAPUS' GROUP BY kategori ORDER BY n DESC LIMIT 8");
} catch (Throwable $e) { $perKategoriAs = []; }
try {
  $servisBiaya = (float)scalar("SELECT COALESCE(SUM(biaya),0) FROM perawatan_kendaraan WHERE tanggal BETWEEN ? AND ?", $range);
  $servisCount = (int)scalar("SELECT COUNT(*) FROM perawatan_kendaraan WHERE tanggal BETWEEN ? AND ?", $range);
} catch (Throwable $e) { $servisBiaya = 0; $servisCount = 0; }
try {
  $penolakan = [
    'Kebutuhan ditolak'   => (int)scalar("SELECT COUNT(*) FROM kebutuhan WHERE status='DITOLAK' AND DATE(ditolak_tgl) BETWEEN ? AND ?", $range),
    'ATK ditolak'         => (int)scalar("SELECT COUNT(*) FROM atk_permintaan WHERE status='DITOLAK' AND tanggal BETWEEN ? AND ?", $range),
    'Fasilitas ditolak'   => (int)scalar("SELECT COUNT(*) FROM fasilitas_tiket WHERE status='DITOLAK' AND tanggal_lapor BETWEEN ? AND ?", $range),
  ];
} catch (Throwable $e) { $penolakan = []; }
?>
<div class="page-head">
  <div class="grow"><h1>Laporan</h1>
    <p class="small muted">Rekap kinerja layanan Bagian Umum-Pengadaan per periode terpilih.</p></div>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar" style="margin:0">
    <label class="small muted">Periode</label>
    <input class="input" type="date" name="from" value="<?= e($from) ?>">
    <span class="muted">s/d</span>
    <input class="input" type="date" name="to" value="<?= e($to) ?>">
    <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Tampilkan</button>
  </form>
</div></div>

<div class="grid g4" style="margin-bottom:20px">
  <?php
  $cards = [
    ['blue',   'file',     $kebBulan,            'Pengajuan Kebutuhan'],
    ['teal',   'check',    $selesaiKeb,          'Kebutuhan Selesai'],
    ['violet', 'cart',     $rencanaCount,        'Rencana Pengadaan'],
    ['green',  'truck',    $terimaPenerimaan,    'Barang Diterima'],
    ['red',    'alert',    $krkCount,            'Laporan Kerusakan'],
    ['amber',  'wrench',   $perfSelesai,         'Perbaikan Selesai'],
    ['slate',  'clipboard',$atkCount,            'Permintaan ATK'],
    ['blue',   'car',      $pinjamCount,         'Peminjaman Kendaraan'],
    ['amber',  'lamp',     $fasCount,            'Tiket Fasilitas'],
    ['teal',   'box',      $opnameCount,         'Stock Opname'],
    ['green',  'chart',    rupiah($nilaiSerah),  'Serah Terima (Rp)'],
    ['red',    'wrench',   rupiah($servisBiaya), 'Biaya Servis (Rp)'],
  ];
  foreach ($cards as [$c, $i, $v, $l]): ?>
    <div class="stat"><div class="s-ic i-<?= $c ?>"><?= iconSvg($i) ?></div><div><div class="v"><?= is_string($v) ? e($v) : (int)$v ?></div><div class="l"><?= e($l) ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Ekspor Data (CSV)</h3></div>
    <div class="card-body"><div class="actions" style="gap:10px">
      <?php foreach ([
        'aset'       => 'Inventaris Aset',
        'kebutuhan'  => 'Pengajuan Kebutuhan',
        'penerimaan' => 'Penerimaan Barang',
        'atk'        => 'Permintaan ATK',
        'kerusakan'  => 'Laporan Kerusakan',
        'pinjam'     => 'Peminjaman Kendaraan',
        'fasilitas'  => 'Tiket Fasilitas',
      ] as $k => $lbl): ?>
        <a class="btn btn-outline" href="<?= u('modules/laporan.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '&export=' . $k) ?>">
          <?= iconSvg('download') ?> <?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
      <p class="muted small mt-2">Unduhan memakai periode tanggal yang sedang aktif (kecuali aset = seluruh data).</p>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Status Penyelesaian Kerusakan</h3></div>
    <div class="card-body">
      <?php $maxSt = max(array_map(fn($x) => (int)$x['n'], $statusKrk ?: [['n' => 1]])); ?>
      <?php if (!$statusKrk): ?><p class="muted">Tidak ada data pada periode ini.</p><?php endif;
      foreach ($statusKrk as $s): ?>
        <div class="bar-row">
          <span class="bar-lbl"><?= e($s['status']) ?></span>
          <div class="bar-track"><div class="bar-fill blue" style="width=<?= $maxSt ? round(100 * (int)$s['n'] / $maxSt) : 0 ?>%"></div></div>
          <span class="bar-val"><?= (int)$s['n'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Top Unit Pemohon Kebutuhan</h3></div>
    <div class="card-body">
      <?php $maxU = max(array_merge([1], array_map(fn($x) => (float)$x['v'], $perUnitKeb))); ?>
      <?php if (!$perUnitKeb): ?><p class="muted">Tidak ada pengajuan pada periode ini.</p><?php endif;
      foreach ($perUnitKeb as $u): ?>
        <div class="bar-row">
          <span class="bar-lbl" title="<?= e($u['u']) ?>"><?= e(mb_strimwidth((string)$u['u'], 0, 22, '…')) ?></span>
          <div class="bar-track"><div class="bar-fill teal" style="width=<?= round(100 * (float)$u['v'] / $maxU) ?>%"></div></div>
          <span class="bar-val"><?= (int)$u['n'] ?> • <?= rupiah($u['v']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Jenis Fasilitas &amp; Kategori Aset</h3></div>
    <div class="card-body">
      <b class="small muted">Tiket fasilitas per periode</b>
      <?php $maxF = max(array_merge([1], array_map(fn($x) => (int)$x['n'], $perJenisFas)));
      if (!$perJenisFas): ?><p class="muted small">Tidak ada tiket.</p><?php endif;
      foreach ($perJenisFas as $f): ?>
        <div class="bar-row">
          <span class="bar-lbl"><?= e($f['jenis']) ?></span>
          <div class="bar-track"><div class="bar-fill amber" style="width=<?= round(100 * (int)$f['n'] / $maxF) ?>%"></div></div>
          <span class="bar-val"><?= (int)$f['n'] ?></span>
        </div>
      <?php endforeach; ?>
      <b class="small muted" style="display:block;margin-top:14px">Aset per kategori (keseluruhan)</b>
      <?php $maxA = max(array_merge([1], array_map(fn($x) => (int)$x['n'], $perKategoriAs)));
      foreach ($perKategoriAs as $a): ?>
        <div class="bar-row">
          <span class="bar-lbl"><?= e(mb_strimwidth((string)$a['kategori'], 0, 22, '…')) ?></span>
          <div class="bar-track"><div class="bar-fill slate" style="width=<?= round(100 * (int)$a['n'] / $maxA) ?>%"></div></div>
          <span class="bar-val"><?= (int)$a['n'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Layanan Periode Ini</h3></div>
    <div class="card-body">
      <dl class="kv">
        <dt>Kebutuhan selesai</dt><dd><?= $selesaiKeb ?>/<?= $kebBulan ?> (<?= $kebBulan ? round(100 * $selesaiKeb / $kebBulan) : 0 ?>%)</dd>
        <dt>Rencana &rarr; diterima</dt><dd><?= $rencanaTerima ?>/<?= $rencanaCount ?></dd>
        <dt>Penerimaan sesuai CK</dt><dd><?= $ckSesuai ?>/<?= $terimaPenerimaan ?></dd>
        <dt>ATK terealisasi penuh</dt><dd><?= $atkSelesai ?>/<?= $atkCount ?></dd>
        <dt>Kerusakan selesai</dt><dd><?= $krkSelesai ?>/<?= $krkCount ?></dd>
        <dt>Perbaikan berhasil</dt><dd><?= $perfSelesai ?> (gagal: <?= $perfGagal ?>)</dd>
        <dt>Pinjaman disetujui</dt><dd><?= $pinjamSetuju ?>/<?= $pinjamCount ?></dd>
        <dt>Fasilitas beres</dt><dd><?= $fasSelesai ?>/<?= $fasCount ?></dd>
        <dt>Servis tercatat</dt><dd><?= $servisCount ?> kejadian</dd>
        <dt>Opname stok</dt><dd><?= $opnameCount ?> sesi</dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Penolakan / Revisi</h3></div>
    <div class="card-body">
      <?php if (!$penolakan): ?><p class="muted small">Tidak ada penolakan pada periode ini.</p><?php else: ?>
      <dl class="kv"><?php foreach ($penolakan as $k => $v): ?>
        <dt><?= e($k) ?></dt><dd><?= (int)$v ?></dd>
      <?php endforeach; ?></dl><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
