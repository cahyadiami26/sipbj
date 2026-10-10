<?php
/** Modul Inventaris & Aset — kode unik AST-..., QR code, riwayat */
$pageTitle = 'Inventaris / Aset';
$activeNav = 'aset';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

/* ---------- POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'simpan') {
        requireRole(['admin','pengadaan']);
        $isEdit = $id > 0;
        $nama = trim($_POST['nama'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        $lokasiId = (int)($_POST['lokasi_id'] ?? 0) ?: null;
        $unit = $_POST['unit'] ?? '';
        $tgl  = $_POST['tanggal_perolehan'] ?: date('Y-m-d');
        $nilai= (float)($_POST['nilai_perolehan'] ?? 0);
        $merk = trim($_POST['merk_type'] ?? '');
        $sn   = trim($_POST['serial_number'] ?? '');
        $penggunaId = (int)($_POST['pengguna_id'] ?? 0) ?: null;
        $kondisi = $_POST['kondisi'] ?? 'BAIK';
        $status  = $_POST['status'] ?? 'AKTIF';
        $sumber  = trim($_POST['sumber'] ?? '');
        $ket     = trim($_POST['keterangan'] ?? '');
        $foto = uploadFile($_FILES['foto'] ?? [], UPLOAD_FOTO_PATH);

        if (!$nama || !$unit) { flash('Nama dan unit wajib diisi.','error'); go('modules/aset.php?aksi=' . ($isEdit?"edit&id=$id":'baru')); }

        if ($isEdit) {
            $old = qOne("SELECT * FROM aset WHERE id=?", [$id]);
            if (!$old) { flash('Aset tidak ditemukan.','error'); go('modules/aset.php'); }
            $sqlFoto = $foto ? ", foto=?" : "";
            $params = [$nama,$kategori,$merk,$sn,$tgl,$nilai,$lokasiId,$penggunaId,$unit,$kondisi,$status,$sumber,$ket];
            if ($foto) $params[] = $foto;
            $params[] = $id;
            q("UPDATE aset SET nama=?,kategori=?,merk_type=?,serial_number=?,tanggal_perolehan=?,nilai_perolehan=?,lokasi_id=?,pengguna_id=?,unit=?,kondisi=?,status=?,sumber=?,keterangan=?$sqlFoto WHERE id=?", $params);
            logActivity('Aset','Update', $old['kode_aset']);
            flash('Aset diperbarui.'); go('modules/aset.php?id='.$id);
        }

        // auto-kode dari lokasi & unit
        $lokasiNama = $lokasiId ? (string)scalar("SELECT nama FROM lokasi WHERE id=?", [$lokasiId]) : 'UMUM';
        $kode = generateAssetCode(db(), $lokasiNama, $unit);
        $qr = generateQrImage($kode, 'qr_' . preg_replace('/[^A-Za-z0-9_-]/','_',$kode) . '.png');

        q("INSERT INTO aset (kode_aset,nama,kategori,merk_type,serial_number,tanggal_perolehan,nilai_perolehan,lokasi_id,pengguna_id,unit,kondisi,status,sumber,keterangan,qr_file,foto)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$kode,$nama,$kategori,$merk,$sn,$tgl,$nilai,$lokasiId,$penggunaId,$unit,$kondisi,$status,$sumber,$ket,$qr,$foto]);
        $aid = (int)db()->lastInsertId();

        // kaitkan ke penerimaan bila dikirim dari sana
        $rcId = (int)($_POST['dari_penerimaan'] ?? 0);
        if ($rcId) {
            $rc = qOne("SELECT * FROM penerimaan WHERE id=?", [$rcId]);
            if ($rc) q("UPDATE aset SET sumber=? WHERE id=?", ['Penerimaan ' . $rc['nomor_penerimaan'], $aid]);
        }
        logActivity('Aset','Buat aset baru', $kode);
        flash('Aset baru dibuat: ' . $kode);
        go('modules/aset.php?id='.$aid);
    }

    if ($action === 'regen_qr') {
        requireRole(['admin','pengadaan']);
        $a = qOne("SELECT * FROM aset WHERE id=?", [$id]);
        if ($a) {
            $qr = generateQrImage($a['kode_aset'], 'qr_' . preg_replace('/[^A-Za-z0-9_-]/','_',$a['kode_aset']) . '.png');
            q("UPDATE aset SET qr_file=? WHERE id=?", [$qr, $id]);
            logActivity('Aset','Regenerate QR', $a['kode_aset']);
            flash('QR code dibuat ulang.');
        }
        go('modules/aset.php?id='.$id);
    }
}

/* ---------- detail ---------- */
if ($id > 0 && $action !== 'baru' && $action !== 'edit'):
$a = qOne("SELECT a.*, l.nama AS lokasi_nama FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE a.id=?", [$id]);
if (!$a) { flash('Aset tidak ditemukan.','error'); go('modules/aset.php'); }
$mutasi = qAll("SELECT * FROM mutasi_aset WHERE aset_id=? ORDER BY id DESC", [$id]); // nama 'oleh' via userName() (DB SSO)
$perbaikan = qAll("SELECT * FROM perbaikan WHERE aset_id=? ORDER BY tanggal DESC", [$id]);
$kerusakan = qAll("SELECT * FROM kerusakan WHERE aset_id=? ORDER BY id DESC", [$id]);
$qf = $a['qr_file'] ? u('uploads/qr/' . rawurlencode($a['qr_file'])) : '';
?>
<div class="page-head">
  <a class="btn btn-ghost" href="<?= u('modules/aset.php') ?>"><?= iconSvg('back') ?> Kembali</a>
  <h1><?= e($a['kode_aset']) ?> <?= badge($a['status']) ?> <?= badge($a['kondisi']) ?></h1>
  <?php if (isPengadaan()): ?>
  <div class="btn-group">
    <a class="btn btn-sm btn-outline" href="<?= u('modules/aset.php?aksi=edit&id='.$id) ?>"><?= iconSvg('pencil') ?> Edit</a>
    <a class="btn btn-sm btn-outline" target="_blank" href="<?= u('modules/qr_batch.php?ids[]='.$id) ?>"><?= iconSvg('print') ?> Cetak Label</a>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="aksi" value="regen_qr"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-ghost"><?= iconSvg('qr') ?> QR Ulang</button></form>
  </div>
  <?php endif; ?>
</div>

<div class="grid-2">
  <div class="card"><div class="card-head"><h3>Identitas Aset</h3></div><div class="card-body">
    <table class="kv">
      <tr><th>Nama</th><td><b><?= e($a['nama']) ?></b></td></tr>
      <tr><th>Kategori</th><td><?= e($a['kategori'] ?: '-') ?></td></tr>
      <tr><th>Merk/Type</th><td><?= e($a['merk_type'] ?: '-') ?></td></tr>
      <tr><th>Serial Number</th><td><?= e($a['serial_number'] ?: '-') ?></td></tr>
      <tr><th>Tanggal Perolehan</th><td><?= tgl($a['tanggal_perolehan']) ?></td></tr>
      <tr><th>Nilai Perolehan</th><td><b><?= rupiah($a['nilai_perolehan']) ?></b></td></tr>
      <tr><th>Lokasi</th><td><?= e($a['lokasi_nama'] ?: '-') ?></td></tr>
      <tr><th>Unit</th><td><?= e($a['unit']) ?></td></tr>
      <tr><th>Pengguna</th><td><?= e($a['pengguna_id'] ? userName($a['pengguna_id']) : '-') ?></td></tr>
      <tr><th>Sumber</th><td><?= e($a['sumber'] ?: '-') ?></td></tr>
      <tr><th>Keterangan</th><td><?= nl2br(e($a['keterangan'] ?: '-')) ?></td></tr>
    </table>
    <?php if ($a['foto']): ?><img class="thumb-lg mt" src="<?= u('uploads/foto/'.rawurlencode($a['foto'])) ?>" alt="foto aset"><?php endif; ?>
  </div></div>

  <div class="card"><div class="card-head"><h3>QR Code Aset</h3></div><div class="card-body center">
    <?php if ($qf): ?><img class="qr-frame" src="<?= e($qf) ?>" alt="QR <?= e($a['kode_aset']) ?>">
    <div class="mono small mt"><?= e($a['kode_aset']) ?></div>
    <p class="small muted mt">Tempelkan label QR pada fisik aset. Scan untuk melihat kartu aset.</p>
    <?php else: ?><p class="muted">QR belum tersedia (tidak ada koneksi saat pembuatan).</p><?php endif; ?>
  </div></div>
</div>

<div class="grid-2 mt">
  <div class="card"><div class="card-head"><h3>Riwayat Mutasi (<?= count($mutasi) ?>)</h3></div><div class="card-body p0">
    <table class="tbl"><thead><tr><th>Tanggal</th><th>Sebelum → Sesudah</th><th>Oleh</th></tr></thead><tbody>
    <?php if (!$mutasi): ?><tr><td colspan="3" class="empty">Belum ada mutasi.</td></tr><?php endif; ?>
    <?php foreach ($mutasi as $m): ?>
      <tr><td><?= tgl($m['tanggal']) ?></td>
        <td><small><?= e($m['unit_sebelum'] ?: '-').' / '.e($m['lokasi_sebelum'] ?: '-') ?> → <b><?= e($m['unit_sesudah'] ?: '-') ?> / <?= e($m['lokasi_sesudah'] ?: '-') ?></b><br>
        <span class="muted"><?= e($m['pengguna_sebelum'] ?: '-') ?> → <?= e($m['pengguna_sesudah'] ?: '-') ?></span></small></td>
        <td><?= e(userName($m['oleh'])) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div></div>

  <div class="card"><div class="card-head"><h3>Riwayat Pemeliharaan & Kerusakan</h3></div><div class="card-body p0">
    <table class="tbl"><thead><tr><th>Tanggal</th><th>Item</th><th>Status</th><th class="num">Biaya</th></tr></thead><tbody>
    <?php if (!$perbaikan && !$kerusakan): ?><tr><td colspan="4" class="empty">Tidak ada riwayat.</td></tr><?php endif; ?>
    <?php foreach ($perbaikan as $p): ?>
      <tr><td><?= tgl($p['tanggal']) ?></td><td><?= e($p['item']) ?> <small class="muted">(perbaikan)</small></td><td><?= badge($p['status']) ?></td><td class="num"><?= rupiah($p['biaya']) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($kerusakan as $k): ?>
      <tr><td><?= tgl($k['tanggal_lapor']) ?></td><td><?= e($k['judul']) ?> <small class="muted">(laporan)</small></td><td><?= badge($k['status']) ?></td><td class="num">-</td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- form baru/edit ---------- */
if ($action === 'baru' || $action === 'edit') {
    requireRole(['admin','pengadaan']);
    $a = $action==='edit' ? qOne("SELECT * FROM aset WHERE id=?", [$id]) : null;
    if ($action==='edit' && !$a) { go('modules/aset.php'); }
    $lokasis = qAll("SELECT id,nama,tipe FROM lokasi WHERE is_active=1 AND tipe='RUANG' ORDER BY nama");
    $pegawai = qAll("SELECT id,name FROM users WHERE is_active=1 ORDER BY name"); // via ssoDb? use ssoDb
    $pegawai = []; try { $pegawai = ssoDb()->query("SELECT id,name FROM users WHERE is_active=1 ORDER BY name")->fetchAll(); } catch (Throwable $e) {}
    $dariPenerimaan = (int)($_GET['rc'] ?? 0);
    $rc = $dariPenerimaan ? qOne("SELECT * FROM penerimaan WHERE id=?", [$dariPenerimaan]) : null;
    ?>
    <div class="page-head"><h1><?= $a ? 'Edit Aset: '.$a['kode_aset'] : 'Aset Baru' ?></h1></div>
    <form method="post" action="<?= u('modules/aset.php') ?>" enctype="multipart/form-data">
      <?= csrfField() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $a['id'] ?? 0 ?>">
      <input type="hidden" name="dari_penerimaan" value="<?= $dariPenerimaan ?: '' ?>">
      <?php if ($rc): ?><div class="alert info mb">Diinput dari penerimaan <b><?= e($rc['nomor_penerimaan']) ?></b>: <?= e($rc['nama_barang']) ?> (<?= rupiah($rc['harga_satuan']) ?>/unit).</div><?php endif; ?>
      <div class="grid-2">
        <div class="card"><div class="card-body"><div class="form-grid">
          <label class="fld span2">Nama Barang *<input class="input" name="nama" required value="<?= e($a['nama'] ?? ($rc['nama_barang'] ?? '')) ?>"></label>
          <label class="fld">Kategori<input class="input" name="kategori" placeholder="Peralatan IT" value="<?= e($a['kategori'] ?? '') ?>"></label>
          <label class="fld">Unit / Bidang *
            <select class="input" name="unit" required>
              <option value="">— pilih —</option>
              <?php foreach (unitOptions() as $un): ?><option <?= ($a['unit']??'')===$un?'selected':'' ?>><?= e($un) ?></option><?php endforeach; ?>
            </select></label>
          <label class="fld">Merk/Type<input class="input" name="merk_type" value="<?= e($a['merk_type'] ?? '') ?>"></label>
          <label class="fld">Serial Number<input class="input" name="serial_number" value="<?= e($a['serial_number'] ?? '') ?>"></label>
          <label class="fld">Tanggal Perolehan<input class="input" type="date" name="tanggal_perolehan" value="<?= e($a['tanggal_perolehan'] ?? ($rc['tanggal'] ?? date('Y-m-d'))) ?>"></label>
          <label class="fld">Nilai Perolehan (Rp)<input class="input" type="number" min="0" step="1000" name="nilai_perolehan" value="<?= e($a['nilai_perolehan'] ?? ($rc['harga_satuan'] ?? 0)) ?>"></label>
          <label class="fld">Lokasi
            <select class="input" name="lokasi_id"><option value="">— pilih ruang —</option>
            <?php foreach ($lokasis as $l): ?><option value="<?= $l['id'] ?>" <?= ($a['lokasi_id']??'')==$l['id']?'selected':'' ?>><?= e($l['nama']) ?></option><?php endforeach; ?></select></label>
          <label class="fld">Pengguna
            <select class="input" name="pengguna_id"><option value="">—</option>
            <?php foreach ($pegawai as $p): ?><option value="<?= $p['id'] ?>" <?= ($a['pengguna_id']??'')==$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
          <label class="fld">Kondisi<select class="input" name="kondisi"><?php foreach (['BAIK','RUSAK RINGAN','RUSAK BERAT'] as $k): ?><option <?= ($a['kondisi']??'BAIK')===$k?'selected':'' ?>><?= $k ?></option><?php endforeach; ?></select></label>
          <label class="fld">Status<select class="input" name="status"><?php foreach (['AKTIF','RUSAK','DIPINDAHKAN','DIHAPUS','HILANG'] as $k): ?><option <?= ($a['status']??'AKTIF')===$k?'selected':'' ?>><?= $k ?></option><?php endforeach; ?></select></label>
          <label class="fld span2">Sumber Perolehan<input class="input" name="sumber" placeholder="Pengadaan / Hibah / ..." value="<?= e($a['sumber'] ?? ($rc ? 'Penerimaan '.$rc['nomor_penerimaan'] : '')) ?>"></label>
          <label class="fld span2">Keterangan<textarea class="input" name="keterangan" rows="2"><?= e($a['keterangan'] ?? '') ?></textarea></label>
          <label class="fld span2">Foto<?php if (!empty($a['foto'])): ?> <small class="muted">(tersimpan: <?= e($a['foto']) ?>, biarkan kosong untuk mempertahankan)</small><?php endif; ?><input class="input" type="file" name="foto" accept="image/*"></label>
        </div></div></div>
        <div class="card"><div class="card-body">
          <h3 class="mb">Informasi</h3>
          <p class="small muted">Kode aset akan digenerate otomatis dengan format:</p>
          <code class="block-code">AST-{RUANG}-{BIDANG}-{TAHUN}-000001</code>
          <p class="small muted mt">QR code juga dibuat otomatis dan disimpan di folder <code>uploads/qr</code>.</p>
          <div class="mt"><button class="btn btn-primary"><?= iconSvg('check') ?> <?= $a?'Simpan Perubahan':'Simpan Aset' ?></button>
            <a class="btn btn-ghost" href="<?= u('modules/aset.php') ?>">Batal</a></div>
        </div></div>
      </div>
    </form>
    <?php require_once __DIR__ . '/../includes/footer.php'; exit;
}

/* ---------- list + pencarian ---------- */
$s = trim($_GET['cari'] ?? '');
$fStatus = $_GET['status'] ?? '';
$fUnit = $_GET['unit'] ?? '';
$sql = "SELECT a.*, l.nama lokasi_nama FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE 1=1"; $pr=[];
if ($s) { $sql .= " AND (a.kode_aset LIKE ? OR a.nama LIKE ? OR a.serial_number LIKE ?)"; $pr = ["%$s%","%$s%","%$s%"]; }
if ($fStatus) { $sql .= " AND a.status=?"; $pr[]=$fStatus; }
if ($fUnit) { $sql .= " AND a.unit=?"; $pr[]=$fUnit; }
$sql .= " ORDER BY a.id DESC LIMIT 500";
$rows = qAll($sql, $pr);
$totalSemua = (int)scalar("SELECT COUNT(*) FROM aset");
$totalAktif = (int)scalar("SELECT COUNT(*) FROM aset WHERE status='AKTIF'");
$nilaiTotal = (float)scalar("SELECT COALESCE(SUM(nilai_perolehan),0) FROM aset WHERE status<>'DIHAPUS'");
?>
<div class="page-head">
  <h1>Inventaris / Aset</h1>
  <div class="btn-group">
    <a class="btn btn-outline" href="<?= u('modules/qr_batch.php') ?>"><?= iconSvg('qr') ?> Batch Print QR</a>
    <?php if (isPengadaan()): ?><a class="btn btn-primary" href="<?= u('modules/aset.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Aset Baru</a><?php endif; ?>
  </div>
</div>

<div class="stats mb">
  <div class="stat"><div class="v"><?= $totalSemua ?></div><div class="l">Total Aset Terdaftar</div></div>
  <div class="stat"><div class="v"><?= $totalAktif ?></div><div class="l">Status AKTIF</div></div>
  <div class="stat"><div class="v"><?= rupiah($nilaiTotal, false) ?></div><div class="l">Nilai Perolehan (Rp)</div></div>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <input class="input grow" name="cari" placeholder="Cari kode aset / nama / serial number..." value="<?= e($s) ?>">
    <select class="input" name="status" onchange="this.form.submit()">
      <option value="">Semua Status</option>
      <?php foreach (['AKTIF','RUSAK','DIPINDAHKAN','DIHAPUS','HILANG'] as $x): ?><option <?= $fStatus===$x?'selected':'' ?>><?= $x ?></option><?php endforeach; ?>
    </select>
    <select class="input" name="unit" onchange="this.form.submit()">
      <option value="">Semua Unit</option>
      <?php foreach (unitOptions() as $x): ?><option <?= $fUnit===$x?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline"><?= iconSvg('search') ?> Cari</button>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
<table class="tbl">
  <thead><tr><th>Kode Aset</th><th>Nama</th><th>Unit</th><th>Lokasi</th><th>Pengguna</th><th class="num">Nilai</th><th>Kondisi</th><th>Status</th><th>QR</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10" class="empty">Tidak ada aset.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td class="mono"><a class="link" href="<?= u('modules/aset.php?id='.$x['id']) ?>"><?= e($x['kode_aset']) ?></a></td>
    <td><?= e($x['nama']) ?><br><small class="muted"><?= e($x['merk_type'] ?: $x['kategori'] ?: '') ?></small></td>
    <td><?= e($x['unit']) ?></td>
    <td><?= e($x['lokasi_nama'] ?: '-') ?></td>
    <td><?= e($x['pengguna_id'] ? userName($x['pengguna_id']) : '-') ?></td>
    <td class="num"><?= rupiah($x['nilai_perolehan']) ?></td>
    <td><?= badge($x['kondisi']) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td><?php if ($x['qr_file']): ?><a href="<?= u('uploads/qr/'.rawurlencode($x['qr_file'])) ?>" target="_blank" title="Lihat QR"><?= iconSvg('qr') ?></a><?php endif; ?></td>
    <td class="ta-r"><a class="btn btn-sm btn-ghost" href="<?= u('modules/aset.php?id='.$x['id']) ?>">Detail</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
