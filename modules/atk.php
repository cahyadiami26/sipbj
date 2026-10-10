<?php
/** Modul ATK — barang, stok masuk/keluar, stok minimum, riwayat transaksi */
$pageTitle = 'Stok & Transaksi ATK';
$activeNav = 'atk';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isPengadaanUser = isPengadaan();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$isPengadaanUser) { flash('Hanya Bagian Umum-Pengadaan yang dapat mengelola ATK.', 'error'); go('modules/atk.php'); }

    if ($action === 'simpan_barang') {
        $kode = trim($_POST['kode_barang'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        if (!$kode || !$nama) { flash('Kode & nama wajib diisi.', 'error'); go('modules/atk.php?aksi=' . ($id ? "edit&id=$id" : 'baru')); }
        $vals = [
            $kode, $nama, trim($_POST['kategori'] ?? ''), trim($_POST['satuan'] ?? 'pcs'),
            max(0, (int)($_POST['stok_minimum'] ?? 5)), (float)($_POST['harga_satuan'] ?? 0),
            trim($_POST['lokasi_simpan'] ?? ''), isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id > 0) {
            $old = qOne("SELECT * FROM atk_barang WHERE id=?", [$id]);
            if (!$old) { flash('Barang tidak ditemukan.', 'error'); go('modules/atk.php'); }
            q("UPDATE atk_barang SET kode_barang=?,nama=?,kategori=?,satuan=?,stok_minimum=?,harga_satuan=?,lokasi_simpan=?,is_active=? WHERE id=?", array_merge($vals, [$id]));
            logActivity('ATK', 'Update barang', $kode);
            flash('Barang ATK diperbarui.');
        } else {
            if (qOne("SELECT id FROM atk_barang WHERE kode_barang=?", [$kode])) { flash('Kode barang sudah dipakai.', 'error'); go('modules/atk.php?aksi=baru'); }
            q("INSERT INTO atk_barang (kode_barang,nama,kategori,satuan,stok_minimum,harga_satuan,lokasi_simpan,is_active,stok) VALUES (?,?,?,?,?,?,?,?,0)", array_merge($vals, []));
            $bid = (int)db()->lastInsertId();
            // saldo awal opsional
            $awal = (int)($_POST['saldo_awal'] ?? 0);
            if ($awal > 0) {
                q("UPDATE atk_barang SET stok=? WHERE id=?", [$awal, $bid]);
                q("INSERT INTO atk_transaksi (atk_barang_id,tipe,jumlah,ref_type,keterangan,oleh) VALUES (?, 'SALDO_AWAL', ?, 'manual','Saldo awal', ?)", [$bid, $awal, authId()]);
            }
            logActivity('ATK', 'Tambah barang', $kode);
            flash('Barang ATK ditambahkan.');
        }
        go('modules/atk.php');
    }

    if ($action === 'transaksi') {
        $barang = qOne("SELECT * FROM atk_barang WHERE id=?", [$id]);
        if (!$barang) { flash('Barang tidak ditemukan.', 'error'); go('modules/atk.php'); }
        $tipe   = $_POST['tipe'] ?? 'MASUK';
        $jumlah = max(1, (int)($_POST['jumlah'] ?? 0));
        $ket    = trim($_POST['keterangan'] ?? '');
        $unit   = trim($_POST['unit'] ?? '');
        if (!in_array($tipe, ['MASUK', 'KELUAR'], true)) { go('modules/atk.php'); }
        if ($tipe === 'KELUAR' && $jumlah > $barang['stok']) {
            flash('Stok tidak mencukupi (sisa ' . $barang['stok'] . ' ' . $barang['satuan'] . ').', 'error');
            go('modules/atk.php?id=' . $id);
        }
        q("INSERT INTO atk_transaksi (atk_barang_id,tipe,jumlah,ref_type,unit,keterangan,oleh) VALUES (?, ?, ?, 'manual', ?, ?, ?)",
          [$id, $tipe, $jumlah, $unit ?: null, $ket ?: null, authId()]);
        q("UPDATE atk_barang SET stok = stok " . ($tipe === 'MASUK' ? '+' : '-') . " ? WHERE id=?", [$jumlah, $id]);
        logActivity('ATK', 'Transaksi ' . $tipe, $barang['kode_barang'] . ' x' . $jumlah);
        flash(($tipe === 'MASUK' ? 'Stok masuk' : 'Stok keluar') . ' tercatat: ' . $jumlah . ' ' . $barang['satuan'] . '.');
        go('modules/atk.php?id=' . $id);
    }
}

/* ---------- detail barang + riwayat ---------- */
if ($id > 0 && !in_array($action, ['baru', 'edit', 'list'], true)):
    $b = qOne("SELECT * FROM atk_barang WHERE id=?", [$id]);
    if (!$b) { flash('Barang tidak ditemukan.', 'error'); go('modules/atk.php'); }
    $trx = qAll("SELECT * FROM atk_transaksi WHERE atk_barang_id=? ORDER BY id DESC LIMIT 200", [$id]);
    $totalMasuk = (float)scalar("SELECT COALESCE(SUM(jumlah),0) FROM atk_transaksi WHERE atk_barang_id=? AND tipe IN ('MASUK','SALDO_AWAL')", [$id]);
    $totalKeluar = (float)scalar("SELECT COALESCE(SUM(jumlah),0) FROM atk_transaksi WHERE atk_barang_id=? AND tipe='KELUAR'", [$id]);
?>
<div class="page-head">
  <div class="grow"><h1><?= e($b['nama']) ?> <span class="mono small muted"><?= e($b['kode_barang']) ?></span></h1>
    <div class="small muted"><?= e($b['kategori'] ?: '-') ?> &bull; Satuan: <?= e($b['satuan']) ?> &bull; Simpan: <?= e($b['lokasi_simpan'] ?: 'Gudang ATK') ?></div></div>
  <div class="btn-group">
    <?php if ($isPengadaanUser): ?><a class="btn btn-outline" href="<?= u('modules/atk.php?aksi=edit&id=' . $id) ?>"><?= iconSvg('pencil') ?> Edit</a><?php endif; ?>
    <a class="btn btn-outline" href="<?= u('modules/atk.php') ?>">&larr; Daftar</a>
  </div>
</div>

<div class="grid g3 mb-2">
  <div class="stat"><div class="s-ic i-teal"><?= iconSvg('box') ?></div><div><div class="v"><?= (int)$b['stok'] ?> <span class="small"><?= e($b['satuan']) ?></span></div><div class="l">Stok Tersedia</div></div></div>
  <div class="stat"><div class="s-ic i-green"><?= iconSvg('download') ?></div><div><div class="v"><?= number_format($totalMasuk) ?></div><div class="l">Total Masuk</div></div></div>
  <div class="stat"><div class="s-ic i-red"><?= iconSvg('truck') ?></div><div><div class="v"><?= number_format($totalKeluar) ?></div><div class="l">Total Keluar</div></div></div>
</div>
<?php if ((int)$b['stok'] <= (int)$b['stok_minimum']): ?>
<div class="flash flash-error"><?= iconSvg('alert') ?><span>Stok di bawah/equal minimum (<?= (int)$b['stok_minimum'] ?>). Pertimbangkan pengadaan ulang.</span></div>
<?php endif; ?>

<div class="grid g2">
  <?php if ($isPengadaanUser): ?>
  <div class="card"><div class="card-head"><h3>Catat Transaksi</h3></div><div class="card-body">
    <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="transaksi"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="form-grid">
        <div class="field"><label>Jenis <span class="req">*</span></label>
          <select class="input" name="tipe"><option value="MASUK">Stok Masuk (penerimaan/pembelian)</option><option value="KELUAR">Stok Keluar (pemakaian/distribusi)</option></select></div>
        <div class="field"><label>Jumlah (<?= e($b['satuan']) ?>) <span class="req">*</span></label><input class="input" type="number" min="1" name="jumlah" required></div>
        <div class="field"><label>Unit Penerima <span class="help">(keluar)</span></label><input class="input" name="unit" placeholder="Keuangan / IT / ..."></div>
        <div class="field full"><label>Keterangan</label><input class="input" name="keterangan" placeholder="No. faktur / keperluan..."></div>
      </div>
      <button class="btn btn-primary mt"><?= iconSvg('check') ?> Simpan Transaksi</button>
    </form>
  </div></div>
  <?php endif; ?>

  <div class="card"><div class="card-head"><h3>Riwayat Transaksi</h3></div><div class="card-body p0">
    <table class="tbl mini-tbl">
      <thead><tr><th>Tanggal</th><th>Jenis</th><th class="num">Jumlah</th><th>Unit/Ket.</th><th>Oleh</th></tr></thead>
      <tbody>
      <?php if (!$trx): ?><tr><td colspan="5" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
      <?php foreach ($trx as $t): ?>
      <tr>
        <td class="small"><?= tgl($t['created_at'], 'd/m/Y H:i') ?></td>
        <td><span class="badge badge-<?= $t['tipe'] === 'KELUAR' ? 'red' : ($t['tipe'] === 'MASUK' ? 'green' : 'slate') ?>"><?= e($t['tipe']) ?></span></td>
        <td class="num"><?= $t['tipe'] === 'KELUAR' ? '-' : '+' ?><?= (int)$t['jumlah'] ?></td>
        <td class="small"><?= e(trim(implode(' • ', array_filter([$t['unit'], $t['keterangan']]))) ?: '-') ?></td>
        <td class="small"><?= e(userName($t['oleh'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- form barang baru/edit ---------- */
if (in_array($action, ['baru', 'edit'], true)):
    if (!$isPengadaanUser) { flash('Akses khusus Bagian Umum-Pengadaan.', 'error'); go('modules/atk.php'); }
    $b = $action === 'edit' && $id ? qOne("SELECT * FROM atk_barang WHERE id=?", [$id]) : null;
    if ($action === 'edit' && !$b) { go('modules/atk.php'); }
?>
<div class="page-head"><h1><?= $b ? 'Edit Barang: ' . e($b['nama']) : 'Barang ATK Baru' ?></h1>
  <a class="btn btn-outline" href="<?= u('modules/atk.php') ?>">&larr; Batal</a></div>
<form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="simpan_barang"><?php if ($b): ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><?php endif; ?>
<div class="card"><div class="card-body">
  <div class="form-grid">
    <div class="field"><label>Kode Barang <span class="req">*</span></label><input class="input mono" name="kode_barang" required value="<?= e($b['kode_barang'] ?? '') ?>" placeholder="ATK-001"></div>
    <div class="field"><label>Nama Barang <span class="req">*</span></label><input class="input" name="nama" required value="<?= e($b['nama'] ?? '') ?>"></div>
    <div class="field"><label>Kategori</label><input class="input" name="kategori" value="<?= e($b['kategori'] ?? '') ?>" placeholder="Alat Tulis / Kertas / Toner"></div>
    <div class="field"><label>Satuan</label><input class="input" name="satuan" value="<?= e($b['satuan'] ?? 'pcs') ?>"></div>
    <div class="field"><label>Stok Minimum</label><input class="input" type="number" min="0" name="stok_minimum" value="<?= e($b['stok_minimum'] ?? '5') ?>"></div>
    <div class="field"><label>Harga Satuan (Rp)</label><input class="input" type="number" min="0" step="100" name="harga_satuan" value="<?= e($b['harga_satuan'] ?? '0') ?>"></div>
    <div class="field"><label>Lokasi Simpan</label><input class="input" name="lokasi_simpan" value="<?= e($b['lokasi_simpan'] ?? '') ?>" placeholder="Gudang ATK Lt.1"></div>
    <?php if (!$b): ?><div class="field"><label>Saldo Awal Stok</label><input class="input" type="number" min="0" name="saldo_awal" value="0"></div><?php endif; ?>
    <div class="field"><label>&nbsp;</label><label class="checkline" style="margin:0"><input type="checkbox" name="is_active" value="1" <?= ($b['is_active'] ?? 1) ? 'checked' : '' ?>> Aktif</label></div>
  </div>
  <div class="mt"><button class="btn btn-primary"><?= iconSvg('check') ?> Simpan</button></div>
</div></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- list ---------- */
$s = trim($_GET['cari'] ?? '');
$fKat = $_GET['kategori'] ?? '';
$lowOnly = isset($_GET['low']);
$sql = "SELECT b.*, COALESCE((SELECT COUNT(*) FROM atk_transaksi t WHERE t.atk_barang_id=b.id AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND t.tipe='KELUAR'),0) trx30 FROM atk_barang b WHERE 1";
$pr = [];
if ($s)     { $sql .= " AND (b.nama LIKE ? OR b.kode_barang LIKE ?)"; $pr[] = "%$s%"; $pr[] = "%$s%"; }
if ($fKat)  { $sql .= " AND b.kategori=?"; $pr[] = $fKat; }
if ($lowOnly) { $sql .= " AND b.stok <= b.stok_minimum"; }
$sql .= " ORDER BY b.nama";
$rows = qAll($sql, $pr);
$kats = qAll("SELECT DISTINCT kategori FROM atk_barang WHERE kategori IS NOT NULL AND kategori<>'' ORDER BY kategori");
$totalLow = (int)scalar("SELECT COUNT(*) FROM atk_barang WHERE is_active=1 AND stok<=stok_minimum");
$totalNilai = (float)scalar("SELECT COALESCE(SUM(stok*harga_satuan),0) FROM atk_barang WHERE is_active=1");
?>
<div class="page-head">
  <div class="grow"><h1>ATK — Stok &amp; Transaksi</h1>
    <div class="small muted"><?= count($rows) ?> item &bull; nilai persediaan <?= rupiah($totalNilai) ?> &bull; <?= $totalLow ?> item stok minimum</div></div>
  <?php if ($isPengadaanUser): ?>
  <div class="btn-group">
    <a class="btn btn-outline" href="<?= u('modules/atk_permintaan.php') ?>"><?= iconSvg('clipboard') ?> Permintaan ATK</a>
    <a class="btn btn-primary" href="<?= u('modules/atk.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Tambah Barang</a>
  </div>
  <?php endif; ?>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <input class="input grow" name="cari" placeholder="Cari nama / kode..." value="<?= e($s) ?>">
    <select class="input" name="kategori" onchange="this.form.submit()">
      <option value="">Semua Kategori</option>
      <?php foreach ($kats as $k): ?><option value="<?= e($k['kategori']) ?>" <?= $fKat === $k['kategori'] ? 'selected' : '' ?>><?= e($k['kategori']) ?></option><?php endforeach; ?>
    </select>
    <label class="checkline" style="margin:0"><input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?> onchange="this.form.submit()"> Hanya stok minimum</label>
    <button class="btn btn-outline"><?= iconSvg('search') ?> Filter</button>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
<div class="table-wrap" style="border:0;border-radius:0">
<table class="tbl">
  <thead><tr><th>Kode</th><th>Nama / Kategori</th><th class="num">Stok</th><th class="num">Min.</th><th class="num">Harga</th><th>Simpan</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty">Tidak ada barang.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): $low = (int)$x['stok'] <= (int)$x['stok_minimum']; ?>
  <tr>
    <td class="mono"><?= e($x['kode_barang']) ?></td>
    <td><strong><?= e($x['nama']) ?></strong><br><span class="small muted"><?= e($x['kategori'] ?: '-') ?></span></td>
    <td class="num"><strong <?= $low ? 'style="color:#dc2626"' : '' ?>><?= (int)$x['stok'] ?></strong> <span class="small muted"><?= e($x['satuan']) ?></span></td>
    <td class="num text-muted"><?= (int)$x['stok_minimum'] ?></td>
    <td class="num"><?= rupiah($x['harga_satuan'], false) ?></td>
    <td class="small"><?= e($x['lokasi_simpan'] ?: '-') ?></td>
    <td><?= $low ? '<span class="badge badge-red">STOK MINIMUM</span>' : ($x['is_active'] ? '<span class="badge badge-green">AKTIF</span>' : '<span class="badge badge-slate">NONAKTIF</span>') ?></td>
    <td class="text-right"><a class="btn btn-sm btn-outline" href="<?= u('modules/atk.php?id=' . $x['id']) ?>"><?= iconSvg('eye') ?> Riwayat</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
