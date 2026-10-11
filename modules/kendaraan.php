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
        if ($data[0] === '' || $data[1] === '') { flash('TNKB dan Merk/Type wajib diisi.', 'error'); go('modules/kendaraan.php' . ($id ? "?edit=$id" : '?aksi=baru')); }
        $dup = qOne("SELECT id FROM kendaraan WHERE tnkb=? AND id<>?", [$data[0], $id]);
        if ($dup) { flash("TNKB {$data[0]} sudah terdaftar.", 'error'); go('modules/kendaraan.php' . ($id ? "?edit=$id" : '?aksi=baru')); }

        if ($id) {
            $old = qOne("SELECT * FROM kendaraan WHERE id=?", [$id]);
            if (!$old) { flash('Kendaraan tidak ditemukan.', 'error'); go('modules/kendaraan.php'); }
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
    if ($sisa < 0)  return '<span class="badge badge-red">LEWAT JADWAL ' . abs($sisa) . ' KM</span>';
    if ($sisa <= 500) return '<span class="badge badge-amber">MENDEKATI SERVIS (' . $sisa . ' KM)</span>';
    return '<span class="badge badge-green">Servis OK</span>';
}
?>
<?php if ($detail): ?>
<div class="page-head">
  <div class="grow"><h1><?= e($detail['tnkb']) ?> — <?= e($detail['merk_type']) ?></h1>
    <div class="small muted">Detail kendaraan &amp; riwayat perawatan.</div></div>
  <div class="btn-group">
    <?php if (isPengadaan()): ?><a class="btn btn-outline" href="<?= u('modules/kendaraan.php?edit=' . $detail['id']) ?>"><?= iconSvg('pencil') ?> Edit</a><?php endif; ?>
    <a class="btn btn-primary" href="<?= u('modules/servis.php?kendaraan_id=' . $detail['id']) ?>"><?= iconSvg('plus') ?> Catat Servis</a>
    <a class="btn btn-outline" href="<?= u('modules/kendaraan.php') ?>">&larr; Daftar</a>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Identitas Kendaraan</h3></div>
    <div class="card-body">
      <dl class="kv">
        <dt>Merk/Type</dt><dd><?= e($detail['merk_type']) ?></dd>
        <dt>Tahun</dt><dd><?= e($detail['tahun'] ?? '-') ?></dd>
        <dt>Warna</dt><dd><?= e($detail['warna'] ?? '-') ?></dd>
        <dt>Nomor Rangka</dt><dd class="mono"><?= e($detail['nomor_rangka'] ?? '-') ?></dd>
        <dt>Nomor Mesin</dt><dd class="mono"><?= e($detail['nomor_mesin'] ?? '-') ?></dd>
        <dt>KM Terakhir</dt><dd><?= number_format((float)$detail['km_terakhir'], 0, ',', '.') ?> KM</dd>
        <dt>Jadwal Servis</dt><dd><?= $detail['jadwal_servis_km'] ? number_format((float)$detail['jadwal_servis_km'], 0, ',', '.') . ' KM' : '-' ?></dd>
        <dt>Kondisi</dt><dd><?= badge($detail['kondisi']) ?></dd>
        <dt>Status</dt><dd><?= badge($detail['status']) ?> <?= servoAlert($detail) ?></dd>
        <dt>Lokasi Parkir</dt><dd><?= e($detail['lokasi'] ?? '-') ?></dd>
        <dt>Penanggung Jawab</dt><dd><?= e(userName($detail['penanggung_jawab'])) ?></dd>
        <dt>Keterangan</dt><dd><?= e($detail['keterangan'] ?? '-') ?></dd>
      </dl>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>Riwayat Perawatan</h3>
        <a class="btn btn-sm btn-primary" href="<?= u('modules/servis.php?kendaraan_id=' . $detail['id']) ?>"><?= iconSvg('plus') ?> Catat Servis</a></div>
      <div class="card-body p0">
        <div class="table-wrap" style="border:0;border-radius:0">
        <table class="tbl">
          <thead><tr><th>Tanggal</th><th>Jenis</th><th class="num">KM</th><th>Vendor</th><th class="num">Biaya</th></tr></thead>
          <tbody>
          <?php $riw = qAll("SELECT * FROM perawatan_kendaraan WHERE kendaraan_id=? ORDER BY tanggal DESC LIMIT 15", [$detail['id']]);
          if (!$riw): ?><tr><td colspan="5" class="empty">Belum ada riwayat servis.</td></tr><?php endif;
          foreach ($riw as $r): ?>
            <tr><td><?= tgl($r['tanggal']) ?></td><td><a href="<?= u('modules/servis.php?id=' . $r['id']) ?>"><?= e($r['jenis']) ?></a></td>
              <td class="num"><?= number_format((float)$r['km'], 0, ',', '.') ?></td><td><?= e($r['vendor'] ?? '-') ?></td>
              <td class="num"><?= rupiah($r['biaya']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Peminjaman Terakhir</h3></div>
      <div class="card-body p0">
        <div class="table-wrap" style="border:0;border-radius:0">
        <table class="tbl">
          <thead><tr><th>No. Pinjam</th><th>Pemohon</th><th>Tanggal</th><th>Status</th></tr></thead>
          <tbody>
          <?php $pj = qAll("SELECT * FROM peminjaman_kendaraan WHERE kendaraan_id=? ORDER BY id DESC LIMIT 8", [$detail['id']]);
          if (!$pj): ?><tr><td colspan="4" class="empty">Belum ada peminjaman.</td></tr><?php endif;
          foreach ($pj as $x): ?>
            <tr><td class="mono small"><a href="<?= u('modules/peminjaman.php?id=' . $x['id']) ?>"><?= e($x['nomor_pinjam']) ?></a></td>
              <td><?= e(userName($x['pemohon'])) ?></td>
              <td class="small"><?= tgl($x['tanggal_pinjam']) ?> — <?= tgl($x['tanggal_kembali']) ?></td>
              <td><?= badge($x['status']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="page-head">
  <div class="grow"><h1>Data Kendaraan</h1>
    <p class="small muted">Armada perusahaan beserta kondisi, penanggung jawab, dan pengingat jadwal servis.</p></div>
  <?php if (isPengadaan()): ?><a class="btn btn-primary" href="<?= u('modules/kendaraan.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Tambah Kendaraan</a><?php endif; ?>
</div>

<div class="grid g4" style="margin-bottom:20px">
  <div class="stat"><div class="s-ic i-green"><?= iconSvg('car') ?></div><div><div class="v"><?= $jml('TERSEDIA') ?></div><div class="l">Tersedia</div></div></div>
  <div class="stat"><div class="s-ic i-blue"><?= iconSvg('calendar') ?></div><div><div class="v"><?= $jml('DIPINJAM') + $jml('DIGUNAKAN') ?></div><div class="l">Dipinjam / Digunakan</div></div></div>
  <div class="stat"><div class="s-ic i-amber"><?= iconSvg('wrench') ?></div><div><div class="v"><?= $jml('SERVICE') ?></div><div class="l">Sedang Servis</div></div></div>
  <div class="stat"><div class="s-ic i-red"><?= iconSvg('alert') ?></div><div><div class="v"><?= $jml('RUSAK') ?></div><div class="l">Rusak</div></div></div>
</div>

<?php if (isPengadaan() && ($edit || ($_GET['aksi'] ?? '') === 'baru')): ?>
<div class="card">
  <div class="card-head"><h3><?= $edit ? 'Edit Kendaraan — ' . e($edit['tnkb']) : 'Tambah Kendaraan Baru' ?></h3>
    <a class="btn btn-sm btn-ghost" href="<?= u('modules/kendaraan.php') ?>">Tutup &times;</a></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="form-grid">
        <div class="field"><label>TNKB <span class="req">*</span></label><input class="input mono" type="text" name="tnkb" required maxlength="30" value="<?= e($edit['tnkb'] ?? '') ?>" placeholder="BE 1234 XYZ"></div>
        <div class="field"><label>Merk / Type <span class="req">*</span></label><input class="input" type="text" name="merk_type" required maxlength="100" value="<?= e($edit['merk_type'] ?? '') ?>" placeholder="Toyota Innova Zenix"></div>
        <div class="field"><label>Tahun</label><input class="input" type="number" name="tahun" min="1980" max="2100" value="<?= e($edit['tahun'] ?? '') ?>"></div>
        <div class="field"><label>Warna</label><input class="input" type="text" name="warna" maxlength="40" value="<?= e($edit['warna'] ?? '') ?>"></div>
        <div class="field"><label>Nomor Rangka</label><input class="input mono" type="text" name="nomor_rangka" maxlength="60" value="<?= e($edit['nomor_rangka'] ?? '') ?>"></div>
        <div class="field"><label>Nomor Mesin</label><input class="input mono" type="text" name="nomor_mesin" maxlength="60" value="<?= e($edit['nomor_mesin'] ?? '') ?>"></div>
        <div class="field"><label>KM Terakhir</label><input class="input" type="number" name="km_terakhir" min="0" value="<?= e($edit['km_terakhir'] ?? '0') ?>"></div>
        <div class="field"><label>Jadwal Servis Berikutnya (KM)</label><input class="input" type="number" name="jadwal_servis_km" min="0" value="<?= e($edit['jadwal_servis_km'] ?? '') ?>" placeholder="cth: 50000"></div>
        <div class="field"><label>Lokasi Parkir</label><input class="input" type="text" name="lokasi" maxlength="100" value="<?= e($edit['lokasi'] ?? '') ?>" placeholder="Gudang / Garasi Kantor"></div>
        <div class="field"><label>Kondisi</label>
          <select class="input" name="kondisi"><?php foreach (['BAIK','RUSAK RINGAN','RUSAK BERAT'] as $o): ?><option <?= ($edit['kondisi'] ?? 'BAIK')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Status</label>
          <select class="input" name="status"><?php foreach (['TERSEDIA','DIPINJAM','DIGUNAKAN','SERVICE','RUSAK'] as $o): ?><option <?= ($edit['status'] ?? 'TERSEDIA')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Penanggung Jawab</label>
          <select class="input" name="penanggung_jawab"><option value="0">— Pilih —</option>
            <?php foreach ($users as $uu): ?><option value="<?= $uu['id'] ?>" <?= ((int)($edit['penanggung_jawab'] ?? 0)) === (int)$uu['id'] ? 'selected' : '' ?>><?= e($uu['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field full"><label>Keterangan</label><input class="input" type="text" name="keterangan" maxlength="255" value="<?= e($edit['keterangan'] ?? '') ?>"></div>
      </div>
      <div class="mt actions">
        <button class="btn btn-primary"><?= iconSvg('check') ?> <?= $edit ? 'Simpan Perubahan' : 'Tambah Kendaraan' ?></button>
        <?php if ($edit): ?>
        <form method="post" data-confirm="Hapus kendaraan ini beserta riwayat servisnya?">
          <?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <button class="btn btn-danger"><?= iconSvg('trash') ?> Hapus</button>
        </form>
        <a class="btn btn-outline" href="<?= u('modules/kendaraan.php') ?>">Batal</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar" style="margin:0">
    <input class="input" type="text" name="cari" placeholder="Cari TNKB / merk…" value="<?= e($cari) ?>">
    <select class="input" name="status">
      <option value="">Semua Status</option>
      <?php foreach (['TERSEDIA','DIPINJAM','DIGUNAKAN','SERVICE','RUSAK'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Filter</button>
    <a class="btn btn-sm btn-ghost" href="<?= u('modules/kendaraan.php') ?>">Reset</a>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
  <div class="table-wrap" style="border:0;border-radius:0">
  <table class="tbl">
    <thead><tr><th>TNKB</th><th>Merk/Type</th><th>Tahun</th><th class="num">KM</th><th>Jadwal Servis</th><th>Lokasi</th><th>Penanggung Jawab</th><th>Kondisi</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="10" class="empty"><?= iconSvg('car') ?><br>Belum ada kendaraan.</td></tr><?php endif;
    foreach ($list as $k): ?>
      <tr>
        <td class="mono"><b><a href="<?= u('modules/kendaraan.php?id=' . $k['id']) ?>"><?= e($k['tnkb']) ?></a></b></td>
        <td><?= e($k['merk_type']) ?><?php if ($k['warna']): ?><div class="small muted"><?= e($k['warna']) ?></div><?php endif; ?></td>
        <td><?= e($k['tahun'] ?? '-') ?></td>
        <td class="num"><?= number_format((float)$k['km_terakhir'], 0, ',', '.') ?></td>
        <td><?= servoAlert($k) ?: '<span class="muted small">-</span>' ?></td>
        <td class="small"><?= e($k['lokasi'] ?? '-') ?></td>
        <td class="small"><?= e(userName($k['penanggung_jawab'])) ?></td>
        <td><?= badge($k['kondisi']) ?></td>
        <td><?= badge($k['status']) ?></td>
        <td class="text-right">
          <div class="actions" style="justify-content:flex-end">
            <a class="btn btn-sm btn-outline" href="<?= u('modules/kendaraan.php?id=' . $k['id']) ?>" title="Detail"><?= iconSvg('eye') ?></a>
            <?php if (isPengadaan()): ?>
              <a class="btn btn-sm btn-outline" href="<?= u('modules/kendaraan.php?edit=' . $k['id']) ?>" title="Edit"><?= iconSvg('pencil') ?></a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
