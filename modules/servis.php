<?php
/** Perawatan / Servis Kendaraan — catat riwayat + pengingat jadwal KM */
$pageTitle = 'Perawatan Kendaraan';
$activeNav = 'servis';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);

/* ---------- aksi ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'catat' && isPengadaan()) {
        $kid   = (int)($_POST['kendaraan_id'] ?? 0);
        $tglX  = $_POST['tanggal'] ?: date('Y-m-d');
        $jenis = trim($_POST['jenis'] ?? '');
        $km    = (int)($_POST['km'] ?? 0);
        $biaya = (float)($_POST['biaya'] ?? 0);
        $vendor= trim($_POST['vendor'] ?? '') ?: null;
        $catatan = trim($_POST['catatan'] ?? '') ?: null;
        $jadwalBaru = (int)($_POST['jadwal_servis_km_baru'] ?? 0) ?: null;
        $k = qOne("SELECT * FROM kendaraan WHERE id=?", [$kid]);
        if (!$k || $jenis === '') { flash('Kendaraan & jenis perawatan wajib diisi.', 'error'); go('modules/servis.php?aksi=baru&kendaraan_id=' . $kid); }
        q("INSERT INTO perawatan_kendaraan (kendaraan_id,tanggal,jenis,km,biaya,vendor,catatan,oleh) VALUES (?,?,?,?,?,?,?,?)",
          [$kid, $tglX, $jenis, $km, $biaya, $vendor, $catatan, authId()]);
        // update km & status & jadwal berikutnya
        $newKm = max((int)$k['km_terakhir'], $km);
        $statusSet = ($_POST['selesai'] ?? '1') === '1' ? 'TERSEDIA' : 'SERVICE';
        q("UPDATE kendaraan SET km_terakhir=?, status=?, jadwal_servis_km=? WHERE id=?",
          [$newKm, $statusSet, $jadwalBaru ?? $k['jadwal_servis_km'], $kid]);
        logActivity('Servis Kendaraan', "Catat '$jenis' untuk {$k['tnkb']}", 'KM ' . $km);
        flash('Riwayat perawatan tersimpan. Status kendaraan diperbarui.');
        go('modules/servis.php');
    }

    if ($aksi === 'hapus' && isAdmin()) {
        $id = (int)($_POST['id'] ?? 0);
        q("DELETE FROM perawatan_kendaraan WHERE id=?", [$id]);
        logActivity('Servis Kendaraan', 'Hapus riwayat #' . $id, null);
        flash('Riwayat dihapus.', 'info');
        go('modules/servis.php');
    }
}

/* ---------- data ---------- */
$filterKend = (int)($_GET['kendaraan_id'] ?? 0);
$list = qAll("SELECT pk.*, k.tnkb, k.merk_type FROM perawatan_kendaraan pk JOIN kendaraan k ON k.id=pk.kendaraan_id"
           . ($filterKend ? " WHERE pk.kendaraan_id=?" : "") . " ORDER BY pk.tanggal DESC, pk.id DESC",
           $filterKend ? [$filterKend] : []);
$kendaraans = qAll("SELECT * FROM kendaraan ORDER BY tnkb");

// pengingat servis
$dueSoon = array_filter($kendaraans, function ($k) {
    if (!$k['jadwal_servis_km']) return false;
    return ((int)$k['jadwal_servis_km'] - (int)$k['km_terakhir']) <= 1000;
});
$totalBiaya = array_sum(array_map(fn($x) => (float)$x['biaya'], $list));
$biayaBulanIni = array_sum(array_map(fn($x) => (float)$x['biaya'],
    array_filter($list, fn($x) => substr($x['tanggal'], 0, 7) === date('Y-m'))));

$preselect = $filterKend ?: (int)($_GET['kendaraan_id'] ?? 0);
$showForm = isPengadaan() && (($_GET['aksi'] ?? '') === 'baru' || $preselect > 0);
?>
<div class="page-head">
  <div class="grow"><h1>Perawatan &amp; Servis Kendaraan</h1>
    <p class="small muted">Catat seluruh riwayat servis dan pantau pengingat jadwal perawatan berdasarkan KM.</p></div>
  <?php if (isPengadaan()): ?>
    <a class="btn btn-primary" href="<?= u('modules/servis.php?aksi=baru' . ($preselect ? "&kendaraan_id=$preselect" : '')) ?>"><?= iconSvg('plus') ?> Catat Perawatan</a>
  <?php endif; ?>
</div>

<div class="grid g4" style="margin-bottom:20px">
  <div class="stat"><div class="s-ic i-teal"><?= iconSvg('file') ?></div><div><div class="v"><?= count($list) ?></div><div class="l">Entri Riwayat (filter)</div></div></div>
  <div class="stat"><div class="s-ic i-blue"><?= iconSvg('chart') ?></div><div><div class="v"><?= rupiah($totalBiaya) ?></div><div class="l">Total Biaya (filter)</div></div></div>
  <div class="stat"><div class="s-ic i-amber"><?= iconSvg('calendar') ?></div><div><div class="v"><?= rupiah($biayaBulanIni) ?></div><div class="l">Biaya Bulan Ini</div></div></div>
  <div class="stat"><div class="s-ic i-red"><?= iconSvg('alert') ?></div><div><div class="v"><?= count($dueSoon) ?></div><div class="l">Mendekati/Lewat Jadwal</div></div></div>
</div>

<?php if ($dueSoon): ?>
<div class="card">
  <div class="card-head"><h3>Pengingat Servis</h3></div>
  <div class="card-body">
    <?php foreach ($dueSoon as $k):
      $sisa = (int)$k['jadwal_servis_km'] - (int)$k['km_terakhir']; ?>
      <div class="alert-item <?= $sisa < 0 ? 'danger' : 'warn' ?>">
        <?= iconSvg('car') ?>
        <div style="flex:1">
          <b><?= e($k['tnkb']) ?> — <?= e($k['merk_type']) ?></b>
          <small>KM terakhir <?= number_format((float)$k['km_terakhir'], 0, ',', '.') ?> &rarr; jadwal <?= number_format((float)$k['jadwal_servis_km'], 0, ',', '.') ?>
            (<?= $sisa < 0 ? '<b>lewat ' . abs($sisa) . ' KM</b>' : 'sisa ' . $sisa . ' KM' ?>)</small>
        </div>
        <a class="btn btn-sm btn-outline" href="<?= u('modules/servis.php?aksi=baru&kendaraan_id=' . $k['id']) ?>">Catat Servis</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="card" id="formCatat">
  <div class="card-head"><h3>Catat Perawatan Baru</h3>
    <a class="btn btn-sm btn-ghost" href="<?= u('modules/servis.php') ?>">Tutup &times;</a></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="aksi" value="catat">
      <div class="form-grid">
        <div class="field"><label>Kendaraan <span class="req">*</span></label>
          <select class="input" name="kendaraan_id" required>
            <option value="">— Pilih Kendaraan —</option>
            <?php foreach ($kendaraans as $k): ?>
              <option value="<?= $k['id'] ?>" <?= $preselect === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['tnkb']) ?> — <?= e($k['merk_type']) ?> (KM <?= number_format((float)$k['km_terakhir'], 0, ',', '.') ?>)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Tanggal <span class="req">*</span></label>
          <input class="input" type="date" name="tanggal" required value="<?= date('Y-m-d') ?>"></div>
        <div class="field"><label>Jenis Perawatan <span class="req">*</span></label>
          <input class="input" type="text" name="jenis" required maxlength="100" placeholder="Ganti Oli / Servis Berkala / Tune Up…" list="jenisList">
          <datalist id="jenisList"><option>Ganti Oli</option><option>Servis Berkala</option><option>Ganti Ban</option><option>Tune Up</option><option>Perbaikan AC</option><option>Ganti Aki</option></datalist></div>
        <div class="field"><label>KM Saat Servis</label>
          <input class="input" type="number" name="km" min="0" placeholder="48500"></div>
        <div class="field"><label>Biaya (Rp)</label>
          <input class="input" type="number" name="biaya" min="0" step="1000" value="0"></div>
        <div class="field"><label>Vendor / Bengkel</label>
          <input class="input" type="text" name="vendor" maxlength="150" placeholder="ABC Service"></div>
        <div class="field"><label>Jadwal Servis Berikutnya (KM)</label>
          <input class="input" type="number" name="jadwal_servis_km_baru" min="0" placeholder="50000"></div>
        <div class="field"><label>&nbsp;</label>
          <label class="checkline" style="margin:0">
            <input type="checkbox" name="selesai" value="1" checked> Selesai (status kendaraan &rarr; Tersedia)</label>
          <div class="help">Jika tidak dicentang, status tetap SERVICE.</div></div>
        <div class="field full"><label>Catatan</label>
          <textarea class="input" name="catatan" rows="2" maxlength="1000"></textarea></div>
      </div>
      <div class="mt"><button class="btn btn-primary"><?= iconSvg('check') ?> Simpan Riwayat</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar" style="margin:0">
    <select class="input" name="kendaraan_id" onchange="this.form.submit()">
      <option value="0">Semua Kendaraan</option>
      <?php foreach ($kendaraans as $k): ?><option value="<?= $k['id'] ?>" <?= $filterKend === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['tnkb']) ?> — <?= e($k['merk_type']) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm btn-outline">Terapkan</button></noscript>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
  <div class="table-wrap" style="border:0;border-radius:0">
  <table class="tbl">
    <thead><tr><th>Tanggal</th><th>Kendaraan</th><th>Jenis</th><th class="num">KM</th><th>Vendor</th><th class="num">Biaya</th><th>Catatan</th><th>Oleh</th><th class="text-right">Aksi</th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="9" class="empty"><?= iconSvg('wrench') ?><br>Belum ada riwayat perawatan.</td></tr><?php endif;
    foreach ($list as $r): ?>
      <tr>
        <td><?= tgl($r['tanggal']) ?></td>
        <td><a class="mono small" href="<?= u('modules/kendaraan.php?id=' . $r['kendaraan_id']) ?>"><b><?= e($r['tnkb']) ?></b></a><div class="small muted"><?= e($r['merk_type']) ?></div></td>
        <td><?= e($r['jenis']) ?></td>
        <td class="num"><?= number_format((float)$r['km'], 0, ',', '.') ?></td>
        <td class="small"><?= e($r['vendor'] ?? '-') ?></td>
        <td class="num"><?= rupiah($r['biaya']) ?></td>
        <td class="small"><?= e(mb_strimwidth((string)$r['catatan'], 0, 60, '…')) ?: '-' ?></td>
        <td class="small"><?= e(userName($r['oleh'])) ?></td>
        <td class="text-right">
          <?php if (isAdmin()): ?>
          <form method="post" data-confirm="Hapus riwayat ini?">
            <?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-danger" title="Hapus"><?= iconSvg('trash') ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
