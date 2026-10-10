<?php
/** Dashboard - gambaran kondisi Bagian Umum-Pengadaan */
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$s = fn($sql, $p = []) => (int)scalar($sql, $p);

/* ---- Kartu statistik ---- */
$stats = [
    ['label' => 'Total Aset',            'v' => $s("SELECT COUNT(*) FROM aset WHERE status IN ('AKTIF','RUSAK','DIPINDAHKAN')"), 'i' => 'shield',  'c' => 'teal',   'u' => 'modules/aset.php'],
    ['label' => 'Total Inventaris',      'v' => $s("SELECT COUNT(*) FROM aset"),                                              'i' => 'box',     'c' => 'blue',   'u' => 'modules/aset.php'],
    ['label' => 'Total Item ATK',        'v' => $s("SELECT COUNT(*) FROM atk_barang WHERE is_active=1"),                      'i' => 'pencil',  'c' => 'cyan',   'u' => 'modules/atk.php'],
    ['label' => 'Permintaan ATK Menunggu','v' => $s("SELECT COUNT(*) FROM atk_permintaan WHERE status='MENUNGGU PROSES'"),    'i' => 'clipboard','c' => 'amber', 'u' => 'modules/atk_permintaan.php'],
    ['label' => 'Pengadaan Berjalan',    'v' => $s("SELECT COUNT(*) FROM kebutuhan WHERE status='DALAM PROSES PENGADAAN'")
                                          + $s("SELECT COUNT(*) FROM rencana_pengadaan WHERE status='DALAM PROSES PENGADAAN'"),'i' => 'cart',   'c' => 'violet', 'u' => 'modules/rencana.php'],
    ['label' => 'Barang Belum Diterima', 'v' => $s("SELECT COUNT(*) FROM kebutuhan WHERE status IN ('DISETUJUI','FINAL ANGGARAN','DALAM PROSES PENGADAAN') AND id NOT IN (SELECT DISTINCT kebutuhan_id FROM penerimaan WHERE kebutuhan_id IS NOT NULL)"), 'i' => 'truck', 'c' => 'red', 'u' => 'modules/penerimaan.php'],
    ['label' => 'Kerusakan Belum Ditangani','v' => $s("SELECT COUNT(*) FROM kerusakan WHERE status IN ('DILAPORKAN','DIVERIFIKASI','DIAGNOSIS')"), 'i' => 'alert', 'c' => 'red', 'u' => 'modules/kerusakan.php'],
    ['label' => 'Perbaikan Berjalan',    'v' => $s("SELECT COUNT(*) FROM perbaikan WHERE status IN ('BARU','DALAM PERBAIKAN')") + $s("SELECT COUNT(*) FROM kerusakan WHERE status='DALAM PERBAIKAN'"), 'i' => 'wrench', 'c' => 'violet', 'u' => 'modules/perbaikan.php'],
    ['label' => 'Kendaraan Tersedia',    'v' => $s("SELECT COUNT(*) FROM kendaraan WHERE status='TERSEDIA'"),                 'i' => 'car',     'c' => 'green',  'u' => 'modules/kendaraan.php'],
    ['label' => 'Kendaraan Digunakan',   'v' => $s("SELECT COUNT(*) FROM kendaraan WHERE status IN ('DIPINJAM','DIGUNAKAN')"),'i' => 'calendar','c' => 'blue',   'u' => 'modules/peminjaman.php'],
    ['label' => 'Kendaraan Servis',      'v' => $s("SELECT COUNT(*) FROM kendaraan WHERE status='SERVICE'"),                  'i' => 'wrench',  'c' => 'amber',  'u' => 'modules/servis.php'],
    ['label' => 'Tiket Fasilitas Aktif', 'v' => $s("SELECT COUNT(*) FROM fasilitas_tiket WHERE status NOT IN ('SELESAI','DITOLAK')"), 'i' => 'lamp', 'c' => 'slate', 'u' => 'modules/fasilitas.php'],
];

/* ---- Notifikasi pekerjaan ---- */
$alerts = [];
$n = $s("SELECT COUNT(*) FROM atk_permintaan WHERE status='MENUNGGU PROSES'");
if ($n) $alerts[] = ['warn', "$n Permintaan ATK menunggu distribusi", 'modules/atk_permintaan.php'];
$n = $s("SELECT COUNT(*) FROM kerusakan WHERE status IN ('DILAPORKAN','DIVERIFIKASI')");
if ($n) $alerts[] = ['danger', "$n Laporan kerusakan belum ditindaklanjuti", 'modules/kerusakan.php'];
$n = $s("SELECT COUNT(*) FROM kendaraan WHERE jadwal_servis_km IS NOT NULL AND km_terakhir >= jadwal_servis_km - 1500 AND status != 'SERVICE'");
if ($n) $alerts[] = ['warn', "$n Kendaraan mendekati jadwal servis", 'modules/servis.php'];
$n = $s("SELECT COUNT(*) FROM kebutuhan WHERE status='DALAM PROSES PENGADAAN'");
if ($n) $alerts[] = ['info', "$n Pengadaan belum diterima", 'modules/penerimaan.php'];
$opnameBulanIni = $s("SELECT COUNT(*) FROM stock_opname WHERE DATE_FORMAT(tanggal_mulai,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')");
if (!$opnameBulanIni && in_array(appRole(), ['admin','pengadaan'], true)) {
    $alerts[] = ['warn', 'Stock opname bulan ini belum dilakukan', 'modules/opname.php'];
}
$n = $s("SELECT COUNT(*) FROM fasilitas_tiket WHERE status='LAPORAN BARU'");
if ($n) $alerts[] = ['info', "$n Tiket fasilitas baru menunggu verifikasi", 'modules/fasilitas.php'];

/* ---- Grafik nilai pengadaan per bulan (6 bln) ---- */
$chart = qAll("SELECT DATE_FORMAT(tanggal_pengajuan,'%Y-%m') ym, SUM(total_estimasi) v FROM kebutuhan
               WHERE tanggal_pengajuan >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym");
$bulanNama = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun','07'=>'Jul','08'=>'Agu','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
$maxV = 0; foreach ($chart as $c) $maxV = max($maxV, (float)$c['v']);

/* ---- Persetujuan menunggu saya ---- */
$toDo = [];
$roleNow = appRole();
if (in_array($roleNow, ['admin','pengadaan'], true)) {
    $toDo[] = ['Diverifikasi kebutuhan', "SELECT id,nomor_kebutuhan nama,tanggal_pengajuan tgl FROM kebutuhan WHERE status='DIAJUKAN'", 'modules/kebutuhan.php?aksi=verifikasi&id='];
}
if (in_array($roleNow, ['admin','pimpinan'], true) || authPosition() === 'Direktur Operasional') {
    $toDo[] = ['Disetujui Direktur Operasional', "SELECT id,nomor_kebutuhan nama,tanggal_pengajuan tgl FROM kebutuhan WHERE status='DIVERIFIKASI'", 'modules/kebutuhan.php?aksi=setujui&id='];
}
if (authPosition() === 'Kepala Divisi' && !in_array($roleNow, ['admin','pengadaan'], true)) {
    $toDo[] = ['Disetujui Kepala Divisi', "SELECT id,nomor_kebutuhan nama,tanggal_pengajuan tgl FROM kebutuhan WHERE status='DIVERIFIKASI' AND unit_pemohon=?", 'modules/kebutuhan.php?aksi=setujui&id='];
}
if (in_array($roleNow, ['admin','pengadaan'], true)) {
    $toDo[] = ['ATK menunggu proses', "SELECT id,nomor_permintaan nama,tanggal tgl FROM atk_permintaan WHERE status='MENUNGGU PROSES'", 'modules/atk_permintaan.php?id='];
    $toDo[] = ['Kerusakan menunggu verifikasi', "SELECT id,nomor_laporan nama,tanggal_lapor tgl FROM kerusakan WHERE status='DILAPORKAN'", 'modules/kerusakan.php?id='];
    $toDo[] = ['Peminjaman kendaraan menunggu', "SELECT p.id,p.nomor_pinjam nama,p.tanggal_pinjam tgl FROM peminjaman_kendaraan p WHERE p.status='MENUNGGU'", 'modules/peminjaman.php?id='];
}

/* ---- Aktivitas terbaru ---- */
$logs = qAll("SELECT * FROM activity_log ORDER BY id DESC LIMIT 8");
?>

<div class="grid g4" style="margin-bottom:20px">
  <?php foreach ($stats as $st): ?>
    <a class="stat" href="<?= u($st['u']) ?>" style="text-decoration:none;color:inherit">
      <div class="s-ic i-<?= $st['c'] ?>"><?= iconSvg($st['i']) ?></div>
      <div><div class="v"><?= number_format($st['v']) ?></div><div class="l"><?= e($st['label']) ?></div></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grid g2">
  <div>
    <div class="card">
      <div class="card-title"><?= iconSvg('bell') ?> Notifikasi Pekerjaan</div>
      <?php if (!$alerts): ?>
        <div class="empty"><?= iconSvg('check') ?><div>Tidak ada tenggakan. Semua terkendali 👍</div></div>
      <?php else: foreach ($alerts as $a): ?>
        <a class="alert-item <?= $a[0] ?>" href="<?= u($a[2]) ?>" style="color:inherit;text-decoration:none">
          <?= iconSvg('alert') ?><span><b><?= e($a[1]) ?></b></span>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <div class="card">
      <div class="card-title"><?= iconSvg('check') ?> Perlu Tindakan Saya</div>
      <?php $anyTodo = false; foreach ($toDo as $td):
          $params = str_contains($td[1], '?') ? [authDivision()] : [];
          $rows = qAll($td[1], $params);
          if (!$rows) continue; $anyTodo = true; ?>
        <div style="margin-bottom:12px">
          <div class="small text-muted" style="font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px"><?= e($td[0]) ?> (<?= count($rows) ?>)</div>
          <?php foreach (array_slice($rows, 0, 3) as $r): ?>
            <a class="alert-item info" href="<?= u($td[2] . $r['id']) ?>" style="color:inherit;text-decoration:none">
              <?= iconSvg('file') ?><span><b><?= e($r['nama']) ?></b><small><?= e(tgl($r['tgl'])) ?></small></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; if (!$anyTodo): ?>
        <div class="empty"><?= iconSvg('check') ?><div>Tidak ada antrian persetujuan untuk Anda.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-title"><?= iconSvg('chart') ?> Nilai Pengajuan Kebutuhan (6 Bulan)</div>
      <?php if (!$chart): ?><div class="empty">Belum ada data.</div><?php else: ?>
      <div class="mini-chart">
        <?php foreach ($chart as $c):
            $h = $maxV ? max(4, (int)round((float)$c['v'] / $maxV * 110)) : 4;
            $bl = $bulanNama[substr($c['ym'], 5, 2)] . ' ' . substr($c['ym'], 2, 2); ?>
          <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end">
            <div class="bar" style="height:<?= $h ?>px" title="<?= rupiah($c['v']) ?>"><span><?= $c['v'] >= 1e6 ? round($c['v']/1e6,1).'jt' : number_format($c['v']/1000,0,',','.')+'rb' ?></span></div>
            <div class="lbl"><?= $bl ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="divider"></div>
      <div class="grid g3">
        <?php
          $totAktif = $s("SELECT COALESCE(SUM(nilai_perolehan),0) FROM aset WHERE status='AKTIF'");
          $procVal  = $s("SELECT COALESCE(SUM(total_estimasi),0) FROM kebutuhan WHERE status IN ('DIAJUKAN','DIVERIFIKASI','DISETUJUI','DALAM PROSES PENGADAAN')");
          $biayaSrv = $s("SELECT COALESCE(SUM(biaya),0) FROM perbaikan WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        ?>
        <div><div class="small text-muted">Nilai Aset Aktif</div><div style="font-weight:800;font-size:15px"><?= rupiah($totAktif) ?></div></div>
        <div><div class="small text-muted">Proc. Pengadaan</div><div style="font-weight:800;font-size:15px"><?= rupiah($procVal) ?></div></div>
        <div><div class="small text-muted">Biaya Perbaikan 30 Hr</div><div style="font-weight:800;font-size:15px"><?= rupiah($biayaSrv) ?></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><?= iconSvg('box') ?> Kondisi Aset Terkini</div>
      <?php
        $kond = qAll("SELECT kondisi, COUNT(*) n FROM aset WHERE status!='DIHAPUS' GROUP BY kondisi");
        $totA = array_sum(array_column($kond, 'n')) ?: 1;
        foreach ($kond as $k): $pc = round($k['n'] / $totA * 100); ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px">
            <b><?= e($k['kondisi']) ?></b><span class="text-muted"><?= $k['n'] ?> (<?= $pc ?>%)</span>
          </div>
          <div class="progress"><span style="width:<?= $pc ?>%"></span></div>
        </div>
      <?php endforeach; ?>
      <div class="legend">
        <span><i style="background:#dc2626"></i>Rusak Berat: <?= $s("SELECT COUNT(*) FROM aset WHERE kondisi='RUSAK BERAT' AND status!='DIHAPUS'") ?></span>
        <span><i style="background:#d97706"></i>Rusak Ringan: <?= $s("SELECT COUNT(*) FROM aset WHERE kondisi='RUSAK RINGAN' AND status!='DIHAPUS'") ?></span>
        <span><i style="background:#0d9488"></i>Baik: <?= $s("SELECT COUNT(*) FROM aset WHERE kondisi='BAIK' AND status!='DIHAPUS'") ?></span>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><?= iconSvg('bell') ?> Aktivitas Terbaru</div>
      <?php if (!$logs): ?>
        <div class="empty">Belum ada aktivitas.</div>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($logs as $lg): ?>
            <li>
              <div class="tl-t"><?= e($lg['aksi']) ?> <span class="text-muted small">— <?= e($lg['modul']) ?></span></div>
              <div class="tl-s"><?= e($lg['user_name']) ?> &bull; <?= e(tgl($lg['created_at'], 'd/m/Y H:i')) ?><?= $lg['detail'] ? ' &bull; ' . e($lg['detail']) : '' ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
