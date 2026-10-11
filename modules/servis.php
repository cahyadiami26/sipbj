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
        if (!$k || $jenis === '') { flash('Kendaraan & jenis perawatan wajib diisi.', 'error'); go('modules/servis.php'); }
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

$preselect = $filterKend ?: (int)($_GET['kendaraan_id_pre'] ?? 0);
?>
<div class="page-head">
  <div><h1><?= iconSvg('wrench') ?> Perawatan &amp; Servis Kendaraan</h1>
    <p class="muted">Catat seluruh riwayat servis dan pantau pengingat jadwal perawatan berdasarkan KM.</p></div>
</div>

<div class="stats">
  <div class="stat"><div class="s-ic teal"><?= iconSvg('file') ?></div><div><div class="s-val"><?= count($list) ?></div><div class="s-lbl">Entri Riwayat (filter)</div></div></div>
  <div class="stat"><div class="s-ic blue"><?= iconSvg('chart') ?></div><div><div class="s-val"><?= rupiah($totalBiaya) ?></div><div class="s-lbl">Total Biaya (filter)</div></div></div>
  <div class="stat"><div class="s-ic amber"><?= iconSvg('calendar') ?></div><div><div class="s-val"><?= rupiah($biayaBulanIni) ?></div><div class="s-lbl">Biaya Bulan Ini</div></div></div>
  <div class="stat"><div class="s-ic red"><?= iconSvg('alert') ?></div><div><div class="s-val"><?= count($dueSoon) ?></div><div class="s-lbl">Mendekati/Lewat Jadwal</div></div></div>
</div>

<?php if ($dueSoon): ?>
<div class="card alert-card">
  <div class="card-head"><h3>🚗 Pengingat Servis</h3></div>
  <div class="card-body list-plain">
    <?php foreach ($dueSoon as $k):
      $sisa = (int)$k['jadwal_servis_km'] - (int)$k['km_terakhir']; ?>
      <div class="row-line">
        <span class="<?= $sisa < 0 ? 'txt-red' : 'txt-amber' ?>">⚠ <b><?= e($k['tnkb']) ?></b> — <?= e($k['merk_type']) ?></span>
        <span class="muted small">KM terakhir: <?= number_format((float)$k['km_terakhir'], 0, ',', '.') ?> → jadwal: <?= number_format((float)$k['jadwal_servis_km'], 0, ',', '.') ?>
          (<?= $sisa < 0 ? '<b style="color:#dc2626">lewat ' . abs($sisa) . ' KM</b>' : 'sisa ' . $sisa . ' KM' ?>)</span>
        <a class="btn btn-sm btn-outline" href="servis.php?catat=<?= $k['id'] ?>">Catat Servis</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (isPengadaan()): ?>
<div class="card" id="formCatat">
  <div class="card-head"><h3>Catat Perawatan Baru</h3></div>
  <form method="post" class="card-body form-grid cols-4">
    <?= csrfField() ?><input type="hidden" name="aksi" value="catat">
    <label>Kendaraan *
      <select name="kendaraan_id" required>
        <option value="">— Pilih Kendaraan —</option>
        <?php foreach ($kendaraans as $k): ?>
          <option value="<?= $k['id'] ?>" <?= $preselect === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['tnkb']) ?> — <?= e($k['merk_type']) ?> (KM <?= number_format((float)$k['km_terakhir'], 0, ',', '.') ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Tanggal * <input type="date" name="tanggal" required value="<?= date('Y-m-d') ?>"></label>
    <label>Jenis Perawatan *
      <input type="text" name="jenis" required maxlength="100" placeholder="Ganti Oli / Servis Berkala / Tune Up…" list="jenisList">
      <datalist id="jenisList"><option>Ganti Oli</option><option>Servis Berkala</option><option>Ganti Ban</option><option>Tune Up</option><option>Perbaikan AC</option><option>Ganti Aki</option></datalist>
    </label>
    <label>KM Saat Servis <input type="number" name="km" min="0" placeholder="48500"></label>
    <label>Biaya (Rp) <input type="number" name="biaya" min="0" step="1000" value="0"></label>
    <label>Vendor / Bengkel <input type="text" name="vendor" maxlength="150" placeholder="ABC Service"></label>
    <label>Jadwal Servis Berikutnya (KM) <input type="number" name="jadwal_servis_km_baru" min="0" placeholder="50000"></label>
    <label class="check-row" style="align-self:end"><input type="checkbox" name="selesai" value="1" checked> <span class="chk-box"></span>&nbsp;Selesai (status → Tersedia)<br><span class="muted small">Jika tidak dicentang, status tetap SERVICE.</span></label>
    <label style="grid-column:1/-1">Catatan <textarea name="catatan" rows="2" maxlength="1000"></textarea></label>
    <div class="btn-row" style="grid-column:1/-1"><button class="btn btn-primary"><?= iconSvg('check') ?> Simpan Riwayat</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h3>Riwayat Perawatan (<?= count($list) ?>)</h3>
    <form method="get" class="filter-bar">
      <select name="kendaraan_id"><option value="0">Semua Kendaraan</option>
        <?php foreach ($kendaraans as $k): ?><option value="<?= $k['id'] ?>" <?= $filterKend===$k['id']?'selected':'' ?>><?= e($k['tnkb']) ?> — <?= e($k['merk_type']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Filter</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Tanggal</th><th>Kendaraan</th><th>Jenis</th><th>KM</th><th>Vendor</th><th>Biaya</th><th>Catatan</th><th>Oleh</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="9" class="empty">Belum ada riwayat perawatan.</td></tr><?php endif;
      foreach ($list as $r): ?>
        <tr>
          <td><?= tgl($r['tanggal']) ?></td>
          <td><b class="mono small"><?= e($r['tnkb']) ?></b><div class="muted small"><?= e($r['merk_type']) ?></div></td>
          <td><?= e($r['jenis']) ?></td>
          <td class="num"><?= number_format((float)$r['km'], 0, ',', '.') ?></td>
          <td class="small"><?= e($r['vendor'] ?? '-') ?></td>
          <td class="num"><?= rupiah($r['biaya']) ?></td>
          <td class="small"><?= e(mb_strimwidth((string)$r['catatan'], 0, 60, '…')) ?: '-' ?></td>
          <td class="small"><?= e(userName($r['oleh'])) ?></td>
          <td>
            <?php if (isAdmin()): ?>
            <form method="post" onsubmit="return confirm('Hapus riwayat ini?')">
              <?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm btn-danger-x" title="Hapus"><?= iconSvg('trash') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
  // auto-scroll ke form jika ada param ?catat=
  const p = new URLSearchParams(location.search);
  if (p.get('catat')) document.getElementById('formCatat')?.scrollIntoView();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
