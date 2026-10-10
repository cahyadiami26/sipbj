<?php
/** Modul Pemeliharaan & Perbaikan — setiap aset memiliki riwayat pemeliharaan */
$pageTitle = 'Pemeliharaan & Perbaikan';
$activeNav = 'perbaikan';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'simpan') {
        $asetId = (int)($_POST['aset_id'] ?? 0) ?: null;
        $item   = trim($_POST['item'] ?? '');
        if (!$item) { flash('Item yang diperbaiki wajib diisi.', 'error'); go('modules/perbaikan.php?aksi=' . ($id ? "edit&id=$id" : 'baru')); }
        $data = [
            $asetId,
            trim($_POST['kerusakan_desc'] ?? ''),
            $_POST['tanggal'] ?: date('Y-m-d'),
            $item,
            trim($_POST['vendor'] ?? ''),
            trim($_POST['teknisi'] ?? ''),
            (float)($_POST['biaya'] ?? 0),
            trim($_POST['tindakan'] ?? ''),
            in_array($_POST['status'] ?? '', ['BARU', 'DALAM PERBAIKAN', 'SELESAI', 'GAGAL'], true) ? $_POST['status'] : 'BARU',
        ];
        if ($id > 0) {
            $old = qOne("SELECT * FROM perbaikan WHERE id=?", [$id]);
            if (!$old) { flash('Data tidak ditemukan.', 'error'); go('modules/perbaikan.php'); }
            q("UPDATE perbaikan SET aset_id=?,kerusakan_desc=?,tanggal=?,item=?,vendor=?,teknisi=?,biaya=?,tindakan=?,status=? WHERE id=?", array_merge($data, [$id]));
            // jika menjadi SELESAI -> tutup kerusakan terkait
            if ($old['status'] !== 'SELESAI' && $data[8] === 'SELESAI') {
                if ($old['kerusakan_id']) q("UPDATE kerusakan SET status='SELESAI', selesai_tgl=NOW(), hasil=? WHERE id=?", [$item, $old['kerusakan_id']]);
                if ($old['aset_id']) q("UPDATE aset SET kondisi='BAIK', status='AKTIF' WHERE id=? AND status='RUSAK'", [$old['aset_id']]);
            }
            logActivity('Perbaikan', 'Update', $old['nomor']);
            flash('Perbaikan diperbarui.');
            go('modules/perbaikan.php?id=' . $id);
        } else {
            $no = generateNumber(db(), 'perbaikan', 'nomor', 'PRK');
            q("INSERT INTO perbaikan (aset_id,kerusakan_desc,tanggal,item,vendor,teknisi,biaya,tindakan,status,dicatat_oleh)
               VALUES (?,?,?,?,?,?,?,?,?,?)", array_merge($data, [authId()]));
            $pid = (int)db()->lastInsertId();
            if ($asetId && $data[8] === 'SELESAI') q("UPDATE aset SET kondisi='BAIK', status='AKTIF' WHERE id=? AND status='RUSAK'", [$asetId]);
            logActivity('Perbaikan', 'Catat', $no);
            flash('Perbaikan ' . $no . ' dicatat.');
            go('modules/perbaikan.php?id=' . $pid);
        }
    }

    if ($action === 'selesai') {
        $p = qOne("SELECT * FROM perbaikan WHERE id=?", [$id]);
        if (!$p || $p['status'] === 'SELESAI') { flash('Status tidak valid.', 'error'); go("modules/perbaikan.php?id=$id"); }
        q("UPDATE perbaikan SET status='SELESAI', tindakan=CONCAT(COALESCE(tindakan,''), '\n[Selesai] ', ?) WHERE id=?", [trim($_POST['catatan'] ?? 'Perbaikan selesai'), $id]);
        if ($p['kerusakan_id']) q("UPDATE kerusakan SET status='SELESAI', selesai_tgl=NOW(), hasil=? WHERE id=?", [$p['item'], $p['kerusakan_id']]);
        if ($p['aset_id']) q("UPDATE aset SET kondisi='BAIK', status='AKTIF' WHERE id=? AND status='RUSAK'", [$p['aset_id']]);
        logActivity('Perbaikan', 'Selesai', $p['nomor']);
        flash('Perbaikan dinyatakan SELESAI. Kondisi aset dipulihkan.');
        go("modules/perbaikan.php?id=$id");
    }

    if ($action === 'gagal') {
        $p = qOne("SELECT * FROM perbaikan WHERE id=?", [$id]);
        if (!$p || $p['status'] === 'SELESAI') { flash('Status tidak valid.', 'error'); go("modules/perbaikan.php?id=$id"); }
        q("UPDATE perbaikan SET status='GAGAL', tindakan=CONCAT(COALESCE(tindakan,''), '\n[Gagal] ', ?) WHERE id=?", [trim($_POST['catatan'] ?? ''), $id]);
        if ($p['aset_id']) q("UPDATE aset SET kondisi='RUSAK BERAT' WHERE id=?", [$p['aset_id']]);
        logActivity('Perbaikan', 'Gagal', $p['nomor']);
        flash('Perbaikan ditandai GAGAL. Aset dapat diajukan penghapusan.');
        go("modules/perbaikan.php?id=$id");
    }
}

/* ---------- detail ---------- */
if ($id > 0 && !in_array($action, ['baru', 'edit', 'list'], true)):
    $p = qOne("SELECT p.*, a.kode_aset, a.nama AS aset_nama, a.unit FROM perbaikan p LEFT JOIN aset a ON a.id=p.aset_id WHERE p.id=?", [$id]);
    if (!$p) { flash('Data tidak ditemukan.', 'error'); go('modules/perbaikan.php'); }
    $k = $p['kerusakan_id'] ? qOne("SELECT * FROM kerusakan WHERE id=?", [$p['kerusakan_id']]) : null;
    $riwayatAset = qAll("SELECT * FROM perbaikan WHERE aset_id=? AND id<>? ORDER BY tanggal DESC", [$p['aset_id'], $p['id']]);
?>
<div class="page-head">
  <div class="grow"><h1><?= e($p['nomor']) ?></h1>
    <div class="small muted"><?= e($p['item']) ?> &bull; <?= tgl($p['tanggal']) ?></div></div>
  <div class="btn-group">
    <a class="btn btn-outline" href="<?= u('modules/perbaikan.php?aksi=edit&id=' . $p['id']) ?>"><?= iconSvg('pencil') ?> Edit</a>
    <a class="btn btn-outline" href="<?= u('modules/perbaikan.php') ?>">&larr; Daftar</a>
  </div>
</div>

<div class="grid g2">
  <div class="card"><div class="card-head"><h3>Data Perbaikan</h3><span><?= badge($p['status']) ?></span></div><div class="card-body">
    <dl class="kv">
      <dt>Aset</dt><dd><?php if ($p['aset_id']): ?><a class="mono" href="<?= u('modules/aset.php?id=' . $p['aset_id']) ?>"><?= e($p['kode_aset']) ?></a><br><?= e($p['aset_nama']) ?> (<?= e($p['unit']) ?>)<?php else: ?>-<?php endif; ?></dd>
      <dt>Tanggal</dt><dd><?= tgl($p['tanggal']) ?></dd>
      <dt>Item / Kerusakan</dt><dd><?= e($p['item']) ?><?php if ($p['kerusakan_desc']): ?><br><span class="small muted"><?= e($p['kerusakan_desc']) ?></span><?php endif; ?></dd>
      <dt>Vendor</dt><dd><?= e($p['vendor'] ?: '-') ?></dd>
      <dt>Teknisi</dt><dd><?= e($p['teknisi'] ?: '-') ?></dd>
      <dt>Biaya</dt><dd><strong><?= rupiah($p['biaya']) ?></strong></dd>
      <dt>Tindakan</dt><dd><?= nl2br(e($p['tindakan'] ?: '-')) ?></dd>
      <dt>Dicatat oleh</dt><dd><?= e(userName($p['dicatat_oleh'])) ?></dd>
    </dl>
    <?php if ($k): ?>
      <div class="divider"></div>
      <div class="small muted">Berasal dari laporan kerusakan:</div>
      <p><a class="mono" href="<?= u('modules/kerusakan.php?id=' . $k['id']) ?>"><?= e($k['nomor_laporan']) ?></a> <?= badge($k['status']) ?></p>
    <?php endif; ?>
  </div></div>

  <div>
    <?php if (isPengadaan() && !in_array($p['status'], ['SELESAI'], true)): ?>
    <div class="card"><div class="card-head"><h3>Finalisasi</h3></div><div class="card-body">
      <form method="post" class="mb"><?= csrfField() ?><input type="hidden" name="aksi" value="selesai"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="field"><label>Catatan Penyelesaian</label><textarea class="input" name="catatan" rows="2" placeholder="Hasil akhir pengujian..."></textarea></div>
        <button class="btn btn-success"><?= iconSvg('check') ?> Tandai SELESAI</button>
      </form>
      <form method="post" data-confirm="Tandai perbaikan gagal?"><?= csrfField() ?><input type="hidden" name="aksi" value="gagal"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="field"><label>Keterangan Kegagalan</label><input class="input" name="catatan" placeholder="Misal: komponen tidak tersedia"></div>
        <button class="btn btn-danger mt"><?= iconSvg('alert') ?> Tandai GAGAL</button>
      </form>
    </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-head"><h3>Riwayat Pemeliharaan Aset Ini</h3></div><div class="card-body p0">
      <table class="tbl mini-tbl">
        <thead><tr><th>Tanggal</th><th>Item</th><th>Biaya</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$riwayatAset): ?><tr><td colspan="4" class="empty">Belum ada riwayat lain.</td></tr><?php endif; ?>
        <?php foreach ($riwayatAset as $x): ?>
          <tr><td><?= tgl($x['tanggal']) ?></td>
              <td><a href="<?= u('modules/perbaikan.php?id=' . $x['id']) ?>"><?= e($x['item']) ?></a></td>
              <td class="num"><?= rupiah($x['biaya'], false) ?></td>
              <td><?= badge($x['status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- form baru / edit ---------- */
if (in_array($action, ['baru', 'edit'], true)):
    $p = $action === 'edit' && $id ? qOne("SELECT * FROM perbaikan WHERE id=?", [$id]) : null;
    if ($action === 'edit' && !$p) { flash('Data tidak ditemukan.', 'error'); go('modules/perbaikan.php'); }
    $asets = qAll("SELECT id,kode_aset,nama,unit FROM aset WHERE status<>'DIHAPUS' ORDER BY kode_aset");
?>
<div class="page-head"><h1><?= $p ? 'Edit Perbaikan: ' . e($p['nomor']) : 'Catat Perbaikan Baru' ?></h1>
  <a class="btn btn-outline" href="<?= u('modules/perbaikan.php') ?>">&larr; Batal</a></div>
<form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="simpan"><?php if ($p): ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><?php endif; ?>
<div class="card"><div class="card-body">
  <div class="form-grid">
    <div class="field"><label>Aset</label>
      <select class="input" name="aset_id"><option value="">— Non-aset / umum —</option>
      <?php foreach ($asets as $x): ?><option value="<?= $x['id'] ?>" <?= ($p && $p['aset_id'] == $x['id']) ? 'selected' : '' ?>><?= e($x['kode_aset']) ?> — <?= e($x['nama']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Tanggal <span class="req">*</span></label><input class="input" type="date" name="tanggal" value="<?= e($p['tanggal'] ?? date('Y-m-d')) ?>"></div>
    <div class="field full"><label>Item yang Diperbaiki <span class="req">*</span></label><input class="input" name="item" required value="<?= e($p['item'] ?? '') ?>" placeholder="AC Ruang Keuangan — Tidak dingin"></div>
    <div class="field full"><label>Deskripsi Kerusakan</label><input class="input" name="kerusakan_desc" value="<?= e($p['kerusakan_desc'] ?? '') ?>"></div>
    <div class="field"><label>Vendor</label><input class="input" name="vendor" value="<?= e($p['vendor'] ?? '') ?>" placeholder="ABC Service"></div>
    <div class="field"><label>Teknisi</label><input class="input" name="teknisi" value="<?= e($p['teknisi'] ?? '') ?>"></div>
    <div class="field"><label>Biaya (Rp)</label><input class="input" type="number" min="0" step="1000" name="biaya" value="<?= e($p['biaya'] ?? '0') ?>"></div>
    <div class="field"><label>Status</label>
      <select class="input" name="status">
        <?php foreach (['BARU', 'DALAM PERBAIKAN', 'SELESAI', 'GAGAL'] as $s): ?>
          <option <?= ($p['status'] ?? 'BARU') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?></select></div>
    <div class="field full"><label>Tindakan</label><textarea class="input" name="tindakan"><?= e($p['tindakan'] ?? '') ?></textarea></div>
  </div>
  <div class="mt"><button class="btn btn-primary"><?= iconSvg('wrench') ?> Simpan</button></div>
</div></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- list ---------- */
$fStatus = $_GET['status'] ?? '';
$sql = "SELECT p.*, a.kode_aset, a.nama AS aset_nama FROM perbaikan p LEFT JOIN aset a ON a.id=p.aset_id WHERE 1";
$pr = [];
if ($fStatus) { $sql .= " AND p.status=?"; $pr[] = $fStatus; }
$sql .= " ORDER BY FIELD(p.status,'DALAM PERBAIKAN','BARU','SELESAI','GAGAL'), p.tanggal DESC LIMIT 300";
$rows = qAll($sql, $pr);
$totalBiaya = (float)scalar("SELECT COALESCE(SUM(biaya),0) FROM perbaikan" . ($fStatus ? " WHERE status=" . db()->quote($fStatus) : ''));
$belum = (int)scalar("SELECT COUNT(*) FROM perbaikan WHERE status IN ('BARU','DALAM PERBAIKAN')");
?>
<div class="page-head">
  <div class="grow"><h1>Pemeliharaan &amp; Perbaikan</h1>
    <div class="small muted"><?= $belum ?> pekerjaan berjalan &bull; total biaya tercatat <?= rupiah($totalBiaya) ?></div></div>
  <a class="btn btn-primary" href="<?= u('modules/perbaikan.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Catat Perbaikan</a>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <select class="input" name="status" onchange="this.form.submit()">
      <option value="">Semua Status</option>
      <?php foreach (['BARU', 'DALAM PERBAIKAN', 'SELESAI', 'GAGAL'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-outline">Terapkan</button></noscript>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
<div class="table-wrap" style="border:0;border-radius:0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Aset</th><th>Item</th><th>Tanggal</th><th>Vendor</th><th class="num">Biaya</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty">Tidak ada data.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td class="mono"><a href="<?= u('modules/perbaikan.php?id=' . $x['id']) ?>"><?= e($x['nomor']) ?></a></td>
    <td><?= $x['kode_aset'] ? '<span class="mono small">' . e($x['kode_aset']) . '</span><br><span class="small muted">' . e($x['aset_nama']) . '</span>' : '<span class="muted">-</span>' ?></td>
    <td><?= e($x['item']) ?></td>
    <td><?= tgl($x['tanggal']) ?></td>
    <td><?= e($x['vendor'] ?: '-') ?></td>
    <td class="num"><?= rupiah($x['biaya'], false) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td class="text-right"><a class="btn btn-sm btn-outline" href="<?= u('modules/perbaikan.php?id=' . $x['id']) ?>"><?= iconSvg('eye') ?></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
