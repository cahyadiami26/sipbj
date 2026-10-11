<?php
/** Data Kendaraan — CRUD + status + pengingat servis */
$pageTitle = 'Data Kendaraan';
$activeNav = 'kendaraan';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

/* ---------- aksi (admin/pengadaan) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'simpan' || $aksi === 'hapus') requireRole(['admin', 'pengadaan']);

    if ($aksi === 'simpan') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            trim($_POST['tnkb'] ?? ''), strtoupper(trim($_POST['merk_type'] ?? '')),
            (int)($_POST['tahun'] ?: 0) ?: null, trim($_POST['warna'] ?? '') ?: null,
            trim($_POST['nomor_rangka'] ?? '') ?: null, trim($_POST['nomor_mesin'] ?? '') ?: null,
            (int)($_POST['km_terakhir'] ?? 0), $_POST['kondisi'] ?? 'BAIK',
            trim($_POST['lokasi'] ?? '') ?: null, (int)($_POST['penanggung_jawab'] ?? 0) ?: authId(),
            $_POST['status'] ?? 'TERSEDIA',
            (int)($_POST['jadwal_servis_km'] ?? 0) ?: null, trim($_POST['keterangan'] ?? '') ?: null,
        ];
        if ($data[0] === '' || $data[1] === '') { flash('TNKB dan Merk/Type wajib diisi.', 'error'); go('modules/kendaraan.php'); }
        $dup = qOne("SELECT id FROM kendaraan WHERE tnkb=? AND id<>?", [$data[0], $id]);
        if ($dup) { flash("TNKB {$data[0]} sudah terdaftar.", 'error'); go('modules/kendaraan.php'); }

        if ($id) {
            $old = qOne("SELECT * FROM kendaraan WHERE id=?", [$id]);
            if (!$old) { flash('Kendaraan tidak ditemukan.', 'error'); go('modules/kendaraan.php'); }
            //jika km diisi lebih besar dari km terakhir peminjaman aktif, biarkan; update biasa
            q("UPDATE kendaraan SET tnkb=?,merk_type=?,tahun=?,warna=?,nomor_rangka=?,nomor_mesin=?,km_terakhir=?,kondisi=?,lokasi=?,penanggung_jawab=?,status=?,jadwal_servis_km=?,keterangan=? WHERE id=?",
              array_merge($data, [$id]));
            logActivity('Kendaraan', 'Update kendaraan ' . $data[0], null);
            flash('Data kendaraan diperbarui.');
        } else {
            q("INSERT INTO kendaraan (tnkb,merk_type,tahun,warna,nomor_rangka,nomor_mesin,km_terakhir,kondisi,lokasi,penanggung_jawab,status,jadwal_servis_km,keterangan) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", $data);
            logActivity('Kendaraan', 'Tambah kendaraan ' . $data[0], null);
            flash('Kendaraan baru ditambahkan.');
        }
        go('modules/kendaraan.php');
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $pinjamAktif = (int)scalar("SELECT COUNT(*) FROM peminjaman_kendaraan WHERE kendaraan_id=? AND status NOT IN ('DITERIMA','DITOLAK')", [$id]);
        if ($pinjamAktif) { flash('Tidak bisa dihapus: ada peminjaman aktif.', 'error'); go('modules/kendaraan.php'); }
        $k = qOne("SELECT tnkb FROM kendaraan WHERE id=?", [$id]);
        q("DELETE FROM perawatan_kendaraan WHERE kendaraan_id=?", [$id]);
        q("DELETE FROM kendaraan WHERE id=?", [$id]);
        logActivity('Kendaraan', 'Hapus kendaraan ' . ($k['tnkb'] ?? ''), null);
        flash('Kendaraan dihapus.');
        go('modules/kendaraan.php');
    }
}

/* ---------- data ---------- */
$cari = trim($_GET['cari'] ?? '');
$statusF = $_GET['status'] ?? '';
$where = ['1=1']; $pr = [];
if ($cari !== '')    { $where[] = '(tnkb LIKE ? OR merk_type LIKE ? OR lokasi LIKE ?)'; $pr = ["%$cari%","%$cari%","%$cari%"]; }
if ($statusF !== '') { $where[] = 'status=?'; $pr[] = $statusF; }
$list = qAll("SELECT * FROM kendaraan WHERE " . implode(' AND ', $where) . " ORDER BY tnkb", $pr);

$jml = fn($s) => count(array_filter($list, fn($k) => $k['status'] === $s));
$editId = (int)($_GET['edit'] ?? 0);
$edit   = $editId ? qOne("SELECT * FROM kendaraan WHERE id=?", [$editId]) : null;
$detailId = (int)($_GET['id'] ?? 0);
$detail = $detailId ? qOne("SELECT * FROM kendaraan WHERE id=?", [$detailId]) : null;

try { $stUsers = ssoDb()->query('SELECT id,name FROM users WHERE is_active=1 ORDER BY name'); $users = $stUsers->fetchAll(); }
catch (Throwable $e) { $users = []; }

function servoAlert(array $k): string
{
    if (!$k['jadwal_servis_km']) return '';
    $sisa = (int)$k['jadwal_servis_km'] - (int)$k['km_terakhir'];
    if ($sisa < 0)  return '<span class="badge badge-red">⚠ LEWAT JADWAL ' . abs($sisa) . ' KM</span>';
    if ($sisa <= 500) return '<span class="badge badge-amber">⚠ MENDEKATI SERVIS (' . $sisa . ' KM)</span>';
    return '<span class="badge badge-green">Servis OK</span>';
}
?>
<?php if ($detail): ?>
<div class="page-head"><div>
  <a class="btn btn-sm btn-ghost" href="kendaraan.php">&larr; <?= iconSvg('back') ?> Kembali</a>
  <h1><?= iconSvg('car') ?> <?= e($detail['tnkb']) ?> — <?= e($detail['merk_type']) ?></h1>
</div></div>
<div class="grid-2" style="grid-template-columns:1fr 1fr;align-items:start">
  <div class="card"><div class="card-head"><h3>Identitas Kendaraan</h3><a class="btn btn-sm btn-outline" href="kendaraan.php?edit=<?= $detail['id'] ?>"><?= iconSvg('pencil') ?> Edit</a></div>
    <div class="card-body kv-grid">
      <div class="kv"><span>Merk/Type</span><b><?= e($detail['merk_type']) ?></b></div>
      <div class="kv"><span>Tahun</span><b><?= e($detail['tahun'] ?? '-') ?></b></div>
      <div class="kv"><span>Warna</span><b><?= e($detail['warna'] ?? '-') ?></b></div>
      <div class="kv"><span>Nomor Rangka</span><b class="mono"><?= e($detail['nomor_rangka'] ?? '-') ?></b></div>
      <div class="kv"><span>Nomor Mesin</span><b class="mono"><?= e($detail['nomor_mesin'] ?? '-') ?></b></div>
      <div class="kv"><span>KM Terakhir</span><b><?= number_format((float)$detail['km_terakhir'], 0, ',', '.') ?> KM</b></div>
      <div class="kv"><span>Jadwal Servis</span><b><?= $detail['jadwal_servis_km'] ? number_format((float)$detail['jadwal_servis_km'], 0, ',', '.') . ' KM' : '-' ?></b></div>
      <div class="kv"><span>Kondisi</span><b><?= badge($detail['kondisi']) ?></b></div>
      <div class="kv"><span>Status</span><b><?= badge($detail['status']) ?> <?= servoAlert($detail) ?></b></div>
      <div class="kv"><span>Lokasi</span><b><?= e($detail['lokasi'] ?? '-') ?></b></div>
      <div class="kv"><span>Penanggung Jawab</span><b><?= e(userName($detail['penanggung_jawab'])) ?></b></div>
      <div class="kv"><span>Keterangan</span><b><?= e($detail['keterangan'] ?? '-') ?></b></div>
    </div>
  </div>
  <div class="card"><div class="card-head"><h3>Riwayat Perawatan &amp; Peminjaman</h3>
    <a class="btn btn-sm btn-primary" href="servis.php?kendaraan_id=<?= $detail['id'] ?>"><?= iconSvg('plus') ?> Catat Servis</a></div>
    <div class="table-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th>Jenis</th><th>KM</th><th>Vendor</th><th>Biaya</th></tr></thead><tbody>
      <?php $riw = qAll("SELECT * FROM perawatan_kendaraan WHERE kendaraan_id=? ORDER BY tanggal DESC LIMIT 15", [$detail['id']]);
      if (!$riw): ?><tr><td colspan="5" class="empty">Belum ada riwayat servis.</td></tr><?php endif;
      foreach ($riw as $r): ?>
        <tr><td><?= tgl($r['tanggal']) ?></td><td><?= e($r['jenis']) ?></td><td><?= number_format((float)$r['km'], 0, ',', '.') ?></td><td><?= e($r['vendor'] ?? '-') ?></td><td class="num"><?= rupiah($r['biaya']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-head"><h3 style="margin-top:8px">Peminjaman Terakhir</h3></div>
    <div class="table-wrap"><table class="tbl"><thead><tr><th>No. Pinjam</th><th>Pemohon</th><th>Tanggal</th><th>Status</th></tr></thead><tbody>
      <?php $pj = qAll("SELECT * FROM peminjaman_kendaraan WHERE kendaraan_id=? ORDER BY id DESC LIMIT 8", [$detail['id']]);
      if (!$pj): ?><tr><td colspan="4" class="empty">Belum ada peminjaman.</td></tr><?php endif;
      foreach ($pj as $x): ?>
        <tr><td class="mono small"><?= e($x['nomor_pinjam']) ?></td><td><?= e(userName($x['pemohon'])) ?></td><td><?= tgl($x['tanggal_pinjam']) ?>—<?= tgl($x['tanggal_kembali']) ?></td><td><?= badge($x['status']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="page-head">
  <div><h1><?= iconSvg('car') ?> Data Kendaraan</h1>
    <p class="muted">Armada perusahaan beserta kondisi, penanggung jawab, dan pengingat jadwal servis.</p></div>
  <?php if (isPengadaan()): ?><button class="btn btn-primary" onclick="document.getElementById('formKend').scrollIntoView()"><?= iconSvg('plus') ?> Tambah Kendaraan</button><?php endif; ?>
</div>

<div class="stats">
  <div class="stat"><div class="s-ic green"><?= iconSvg('car') ?></div><div><div class="s-val"><?= $jml('TERSEDIA') ?></div><div class="s-lbl">Tersedia</div></div></div>
  <div class="stat"><div class="s-ic blue"><?= iconSvg('calendar') ?></div><div><div class="s-val"><?= $jml('DIPINJAM') + $jml('DIGUNAKAN') ?></div><div class="s-lbl">Dipinjam / Digunakan</div></div></div>
  <div class="stat"><div class="s-ic amber"><?= iconSvg('wrench') ?></div><div><div class="s-val"><?= $jml('SERVICE') ?></div><div class="s-lbl">Sedang Servis</div></div></div>
  <div class="stat"><div class="s-ic red"><?= iconSvg('alert') ?></div><div><div class="s-val"><?= $jml('RUSAK') ?></div><div class="s-lbl">Rusak</div></div></div>
</div>

<div class="card">
  <div class="card-head">
    <h3>Daftar Armada (<?= count($list) ?>)</h3>
    <form method="get" class="filter-bar">
      <input type="text" name="cari" placeholder="Cari TNKB / merk…" value="<?= e($cari) ?>">
      <select name="status"><option value="">Semua Status</option>
        <?php foreach (['TERSEDIA','DIPINJAM','DIGUNAKAN','SERVICE','RUSAK'] as $s): ?><option <?= $statusF===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Filter</button>
      <a class="btn btn-sm btn-ghost" href="kendaraan.php">Reset</a>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>TNKB</th><th>Merk/Type</th><th>Tahun</th><th>KM</th><th>Jadwal Servis</th><th>Lokasi</th><th>Penanggung Jawab</th><th>Kondisi</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="10" class="empty">Belum ada kendaraan.</td></tr><?php endif;
      foreach ($list as $k): ?>
        <tr>
          <td class="mono"><b><?= e($k['tnkb']) ?></b></td>
          <td><?= e($k['merk_type']) ?> <span class="muted small"><?= e($k['warna'] ? ' • '.$k['warna'] : '') ?></span></td>
          <td><?= e($k['tahun'] ?? '-') ?></td>
          <td class="num"><?= number_format((float)$k['km_terakhir'], 0, ',', '.') ?></td>
          <td><?= servoAlert($k) ?: '<span class="muted small">-</span>' ?></td>
          <td class="small"><?= e($k['lokasi'] ?? '-') ?></td>
          <td class="small"><?= e(userName($k['penanggung_jawab'])) ?></td>
          <td><?= badge($k['kondisi']) ?></td>
          <td><?= badge($k['status']) ?></td>
          <td style="white-space:nowrap">
            <a class="btn btn-sm btn-outline" href="kendaraan.php?id=<?= $k['id'] ?>" title="Detail"><?= iconSvg('eye') ?></a>
            <?php if (isPengadaan()): ?>
              <a class="btn btn-sm btn-outline" href="kendaraan.php?edit=<?= $k['id'] ?>" title="Edit"><?= iconSvg('pencil') ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (isPengadaan()): ?>
<div class="card" id="formKend">
  <div class="card-head"><h3><?= $edit ? 'Edit Kendaraan — ' . e($edit['tnkb']) : 'Tambah Kendaraan Baru' ?></h3>
    <?php if ($edit): ?><a class="btn btn-sm btn-ghost" href="kendaraan.php">Batal</a><?php endif; ?></div>
  <form method="post" class="card-body form-grid cols-3">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>TNKB * <input type="text" name="tnkb" required maxlength="30" value="<?= e($edit['tnkb'] ?? '') ?>" placeholder="BE 1234 XYZ"></label>
    <label>Merk / Type * <input type="text" name="merk_type" required maxlength="100" value="<?= e($edit['merk_type'] ?? '') ?>" placeholder="Toyota Innova Zenix"></label>
    <label>Tahun <input type="number" name="tahun" min="1980" max="2100" value="<?= e($edit['tahun'] ?? '') ?>"></label>
    <label>Warna <input type="text" name="warna" maxlength="40" value="<?= e($edit['warna'] ?? '') ?>"></label>
    <label>Nomor Rangka <input type="text" name="nomor_rangka" maxlength="60" value="<?= e($edit['nomor_rangka'] ?? '') ?>"></label>
    <label>Nomor Mesin <input type="text" name="nomor_mesin" maxlength="60" value="<?= e($edit['nomor_mesin'] ?? '') ?>"></label>
    <label>KM Terakhir <input type="number" name="km_terakhir" min="0" value="<?= e($edit['km_terakhir'] ?? '0') ?>"></label>
    <label>Jadwal Servis Berikutnya (KM) <input type="number" name="jadwal_servis_km" min="0" value="<?= e($edit['jadwal_servis_km'] ?? '') ?>" placeholder="cth: 50000"></label>
    <label>Lokasi Parkir <input type="text" name="lokasi" maxlength="100" value="<?= e($edit['lokasi'] ?? '') ?>" placeholder="Gudang / Garasi Kantor"></label>
    <label>Kondisi
      <select name="kondisi"><?php foreach (['BAIK','RUSAK RINGAN','RUSAK BERAT'] as $o): ?><option <?= ($edit['kondisi'] ?? 'BAIK')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select>
    </label>
    <label>Status
      <select name="status"><?php foreach (['TERSEDIA','DIPINJAM','DIGUNAKAN','SERVICE','RUSAK'] as $o): ?><option <?= ($edit['status'] ?? 'TERSEDIA')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select>
    </label>
    <label>Penanggung Jawab
      <select name="penanggung_jawab"><option value="0">— Pilih —</option>
        <?php foreach ($users as $uu): ?><option value="<?= $uu['id'] ?>" <?= ((int)($edit['penanggung_jawab'] ?? 0)) === (int)$uu['id'] ? 'selected' : '' ?>><?= e($uu['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label style="grid-column:1/-1">Keterangan <input type="text" name="keterangan" maxlength="255" value="<?= e($edit['keterangan'] ?? '') ?>"></label>
    <div class="btn-row" style="grid-column:1/-1">
      <button class="btn btn-primary"><?= iconSvg('check') ?> <?= $edit ? 'Simpan Perubahan' : 'Tambah Kendaraan' ?></button>
      <?php if ($edit): ?>
      <form method="post" onsubmit="return confirm('Hapus kendaraan ini beserta riwayat servisnya?');" style="display:inline">
        <?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
        <button class="btn btn-danger-x"><?= iconSvg('trash') ?> Hapus</button>
      </form>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
