<?php
/** Modul Peminjaman Kendaraan — PENGAJUAN > DISETUJUI (Kadiv Ops) > DIGUNAKAN > DIKEMBALIKAN (driver) > DITERIMA (Kabag Umum-Pengadaan) */
$pageTitle = 'Peminjaman Kendaraan';
$activeNav = 'peminjaman';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

/* Kriteria approver sesuai SOP */
function canApprovePinjam(): bool
{
    return isAdmin() || authPosition() === 'Kepala Divisi' || in_array(authDivision(), ['Umum', 'Pengadaan'], true);
}
function isKabagUmumPengadaan(): bool
{
    return isAdmin() || in_array(authDivision(), ['Umum', 'Pengadaan'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'ajukan') {
        $kendId  = (int)($_POST['kendaraan_id'] ?? 0);
        $tglPinjam = $_POST['tanggal_pinjam'] ?: date('Y-m-d');
        $tglKembali = $_POST['tanggal_kembali'] ?: $tglPinjam;
        $kend = qOne("SELECT * FROM kendaraan WHERE id=?", [$kendId]);
        if (!$kend || strtotime($tglKembali) < strtotime($tglPinjam)) {
            flash('Data peminjaman tidak valid.', 'error'); go('modules/peminjaman.php?aksi=baru');
        }
        // cek bentrok jadwal
        $bentrok = qOne("SELECT nomor_pinjam FROM peminjaman_kendaraan
                         WHERE kendaraan_id=? AND status IN ('MENUNGGU','DISETUJUI','DIGUNAKAN')
                           AND NOT (tanggal_kembali < ? OR tanggal_pinjam > ?)", [$kendId, $tglPinjam, $tglKembali]);
        if ($bentrok) { flash('Kendaraan sudah dipinjam pada rentang tanggal tersebut (' . $bentrok['nomor_pinjam'] . ').', 'error'); go('modules/peminjaman.php?aksi=baru'); }

        $no = generateNumber(db(), 'peminjaman_kendaraan', 'nomor_pinjam', 'PKJ');
        q("INSERT INTO peminjaman_kendaraan (nomor_pinjam,kendaraan_id,pemohon,tanggal_pinjam,tanggal_kembali,tujuan,pengemudi,penumpang,status)
           VALUES (?,?,?,?,?,?,?,?,'MENUNGGU')", [
            $no, $kendId, authId(), $tglPinjam, $tglKembali,
            trim($_POST['tujuan'] ?? ''), trim($_POST['pengemudi'] ?? ''), trim($_POST['penumpang'] ?? ''),
        ]);
        logActivity('Peminjaman', 'Diajukan', $no . ' — ' . $kend['tnkb']);
        flash('Pengajuan ' . $no . ' dikirim ke Kepala Divisi Operasional.');
        go('modules/peminjaman.php?id=' . db()->lastInsertId());
    }

    if ($action === 'setuju' || $action === 'tolak') {
        $p = qOne("SELECT * FROM peminjaman_kendaraan WHERE id=?", [$id]);
        if (!$p || $p['status'] !== 'MENUNGGU') { flash('Status tidak valid.', 'error'); go("modules/peminjaman.php?id=$id"); }
        if (!canApprovePinjam()) { flash('Hanya Kepala Divisi Operasional yang dapat menyetujui.', 'error'); go("modules/peminjaman.php?id=$id"); }
        if ($action === 'tolak') {
            q("UPDATE peminjaman_kendaraan SET status='DITOLAK', setujui_oleh=?, setujui_tgl=NOW(), catatan_kembali=? WHERE id=?", [authId(), trim($_POST['alasan'] ?? ''), $id]);
            flash('Pengajuan ditolak.');
        } else {
            q("UPDATE peminjaman_kendaraan SET status='DISETUJUI', setujui_oleh=?, setujui_tgl=NOW() WHERE id=?", [authId(), $id]);
            q("UPDATE kendaraan SET status='DIPINJAM' WHERE id=?", [$p['kendaraan_id']]);
            flash('Disetujui. Pengambil kunci: Bagian Umum-Pengadaan.');
        }
        logActivity('Peminjaman', $action === 'tolak' ? 'Ditolak Kadiv Ops' : 'Disetujui Kadiv Ops', $p['nomor_pinjam']);
        go("modules/peminjaman.php?id=$id");
    }

    if ($action === 'ambil') { // -> DIGUNAKAN (serahkan kunci, catat km awal)
        $p = qOne("SELECT * FROM peminjaman_kendaraan WHERE id=?", [$id]);
        if (!$p || $p['status'] !== 'DISETUJUI') { flash('Status tidak valid.', 'error'); go("modules/peminjaman.php?id=$id"); }
        if (!isKabagUmumPengadaan()) { flash('Hanya Bagian Umum-Pengadaan yang menyerahkan kunci.', 'error'); go("modules/peminjaman.php?id=$id"); }
        q("UPDATE peminjaman_kendaraan SET status='DIGUNAKAN', km_awal=?, konfirmasi_tgl=NOW() WHERE id=?", [(int)($_POST['km_awal'] ?? 0) ?: null, $id]);
        q("UPDATE kendaraan SET status='DIGUNAKAN', km_terakhir=GREATEST(km_terakhir, COALESCE(?, km_terakhir)) WHERE id=?", [$_POST['km_awal'] ?: null, $p['kendaraan_id']]);
        logActivity('Peminjaman', 'Kunci Diserahkan', $p['nomor_pinjam']);
        flash('Kunci diserahkan — status DIGUNAKAN.');
        go("modules/peminjaman.php?id=$id");
    }

    if ($action === 'kembali') { // driver mengembalikan: km akhir + bukti BBM
        $p = qOne("SELECT * FROM peminjaman_kendaraan WHERE id=?", [$id]);
        if (!$p || $p['status'] !== 'DIGUNAKAN') { flash('Status tidak valid.', 'error'); go("modules/peminjaman.php?id=$id"); }
        $bukti = uploadFile($_FILES['bukti'] ?? [], UPLOAD_BUKTI_PATH, ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
        q("UPDATE peminjaman_kendaraan SET status='DIKEMBALIKAN', km_akhir=?, bbm=?, kondisi_kembali=?, catatan_kembali=?,
            bukti=COALESCE(?, bukti), konfirmasi_tgl=NOW() WHERE id=?", [
            (int)($_POST['km_akhir'] ?? 0) ?: null, (float)($_POST['bbm'] ?? 0),
            trim($_POST['kondisi_kembali'] ?? ''), trim($_POST['catatan'] ?? ''), $bukti, $id,
        ]);
        logActivity('Peminjaman', 'Dikembalikan Pemohon', $p['nomor_pinjam']);
        flash('Kendaraan dikembalikan. Menunggu pemeriksaan Bagian Umum-Pengadaan.');
        go("modules/peminjaman.php?id=$id");
    }

    if ($action === 'terima') { // Kabag Umum-Pengadaan memeriksa -> DITERIMA
        $p = qOne("SELECT * FROM peminjaman_kendaraan WHERE id=?", [$id]);
        if (!$p || $p['status'] !== 'DIKEMBALIKAN') { flash('Status tidak valid.', 'error'); go("modules/peminjaman.php?id=$id"); }
        if (!isKabagUmumPengadaan()) { flash('Hanya Bagian Umum-Pengadaan yang dapat menerima kembali.', 'error'); go("modules/peminjaman.php?id=$id"); }
        q("UPDATE peminjaman_kendaraan SET status='DITERIMA', konfirmasi_tgl=NOW(), catatan_kembali=CONCAT_WS(' | ', COALESCE(catatan_kembali,''), ?) WHERE id=?",
          [trim($_POST['catatan'] ?? 'Pemeriksaan OK'), $id]);
        q("UPDATE kendaraan SET status='TERSEDIA', km_terakhir=GREATEST(km_terakhir, COALESCE(km_akhir, km_terakhir)) WHERE id=?", [$p['kendaraan_id']]);
        logActivity('Peminjaman', 'Selesai Diterima', $p['nomor_pinjam']);
        flash('Peminjaman selesai — kendaraan kembali tersedia.');
        go("modules/peminjaman.php?id=$id");
    }

    if ($action === 'batal') {
        $p = qOne("SELECT * FROM peminjaman_kendaraan WHERE id=?", [$id]);
        if (!$p || !in_array($p['status'], ['MENUNGGU', 'DISETUJUI'], true)) { flash('Tidak dapat dibatalkan.', 'error'); go("modules/peminjaman.php?id=$id"); }
        if ($p['pemohon'] != authId() && !isAdmin()) { flash('Hanya pemohon yang dapat membatalkan.', 'error'); go("modules/peminjaman.php?id=$id"); }
        q("UPDATE peminjaman_kendaraan SET status='DITOLAK', catatan_kembali='Dibatalkan pemohon' WHERE id=?", [$id]);
        q("UPDATE kendaraan SET status='TERSEDIA' WHERE id=? AND status IN ('DISETUJUI','DIPINJAM')", [$p['kendaraan_id']]);
        logActivity('Peminjaman', 'Batal', $p['nomor_pinjam']);
        flash('Pengajuan dibatalkan.');
        go("modules/peminjaman.php?id=$id");
    }
}

/* ---------- detail ---------- */
if ($id > 0 && !in_array($action, ['baru', 'list'], true)):
    $p = qOne("SELECT pk.*, k.tnkb, k.merk_type, k.km_terakhir FROM peminjaman_kendaraan pk JOIN kendaraan k ON k.id=pk.kendaraan_id WHERE pk.id=?", [$id]);
    if (!$p) { flash('Data tidak ditemukan.', 'error'); go('modules/peminjaman.php'); }
    $flow = ['MENUNGGU' => 'Pengajuan', 'DISETUJUI' => 'Persetujuan Kadiv Ops', 'DIGUNAKAN' => 'Ambil Kunci (Umum-Pengadaan)', 'DIKEMBALIKAN' => 'Pengembalian (Driver)', 'DITERIMA' => 'Pemeriksaan Akhir'];
    $keys = array_keys($flow);
    $curIdx = array_search($p['status'], $keys);
?>
<div class="page-head">
  <div class="grow"><h1><?= e($p['nomor_pinjam']) ?></h1>
    <div class="small muted"><?= e($p['tnkb']) ?> — <?= e($p['merk_type']) ?> &bull; Pemohon: <?= e(userName($p['pemohon'])) ?></div></div>
  <a class="btn btn-outline" href="<?= u('modules/peminjaman.php') ?>">&larr; Daftar</a>
</div>

<div class="card"><div class="card-body">
  <div class="steps">
    <?php foreach ($keys as $i => $st): $cls = $p['status'] === 'DITOLAK' ? '' : ($i < $curIdx ? 'done' : ($i === $curIdx ? 'current' : '')); ?>
      <div class="step <?= $cls ?>"><span class="dotn"><?= $i + 1 ?></span><span><?= e($flow[$st]) ?></span><?= $i < count($keys) - 1 ? '<span class="arrow">&rarr;</span>' : '' ?></div>
    <?php endforeach; ?>
  </div>
  <?= badge($p['status']) ?>
</div></div>

<div class="grid g2">
  <div class="card"><div class="card-head"><h3>Data Peminjaman</h3></div><div class="card-body">
    <dl class="kv">
      <dt>Kendaraan</dt><dd><a href="<?= u('modules/kendaraan_detail.php?id=' . $p['kendaraan_id']) ?>"><?= e($p['tnkb']) ?></a> — <?= e($p['merk_type']) ?></dd>
      <dt>Tanggal Pinjam</dt><dd><?= tgl($p['tanggal_pinjam']) ?></dd>
      <dt>Rencana Kembali</dt><dd><?= tgl($p['tanggal_kembali']) ?></dd>
      <dt>Tujuan</dt><dd><?= nl2br(e($p['tujuan'] ?: '-')) ?></dd>
      <dt>Pengemudi</dt><dd><?= e($p['pengemudi'] ?: '-') ?></dd>
      <dt>Penumpang</dt><dd><?= e($p['penumpang'] ?: '-') ?></dd>
      <dt>Disetujui oleh</dt><dd><?= $p['setujui_oleh'] ? e(userName($p['setujui_oleh'])) . ' • ' . tgl($p['setujui_tgl'], 'd/m/Y H:i') : '-' ?></dd>
      <dt>KM Awal / Akhir</dt><dd><?= $p['km_awal'] !== null ? number_format($p['km_awal']) : '-' ?> / <?= $p['km_akhir'] !== null ? number_format($p['km_akhir']) : '-' ?><?= ($p['km_awal'] && $p['km_akhir']) ? ' <span class="small muted">(+' . number_format(max(0, $p['km_akhir'] - $p['km_awal'])) . ' km)</span>' : '' ?></dd>
      <dt>Bukti BBM</dt><dd><?= rupiah($p['bbm']) ?><?= $p['bukti'] ? ' &bull; <a href="' . u('uploads/bukti/' . rawurlencode($p['bukti'])) . '" target="_blank">lihat bukti</a>' : '' ?></dd>
      <dt>Kondisi Kembali</dt><dd><?= e($p['kondisi_kembali'] ?: '-') ?></dd>
      <dt>Catatan</dt><dd><?= nl2br(e($p['catatan_kembali'] ?: '-')) ?></dd>
    </dl>
  </div></div>

  <div class="card"><div class="card-head"><h3>Aksi</h3></div><div class="card-body">
    <?php if ($p['status'] === 'MENUNGGU'): ?>
      <?php if (canApprovePinjam()): ?>
        <form method="post" class="mb"><?= csrfField() ?><input type="hidden" name="aksi" value="setuju"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button class="btn btn-success"><?= iconSvg('check') ?> Setujui (Kadiv Operasional)</button></form>
        <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <div class="field"><label>Alasan Penolakan</label><textarea class="input" name="alasan" rows="2" required></textarea></div>
          <button class="btn btn-danger mt"><?= iconSvg('alert') ?> Tolak</button></form>
      <?php else: ?>
        <p class="small muted">Menunggu persetujuan <strong>Kepala Divisi Operasional</strong>.</p>
      <?php endif; ?>
      <?php if ($p['pemohon'] == authId() || isAdmin()): ?>
        <div class="divider"></div>
        <form method="post" data-confirm="Batalkan pengajuan?"><?= csrfField() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button class="btn btn-ghost"><?= iconSvg('trash') ?> Batalkan</button></form>
      <?php endif; ?>

    <?php elseif ($p['status'] === 'DISETUJUI'): ?>
      <?php if (isKabagUmumPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="ambil"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="field"><label>KM Awal Saat Serah Terima <span class="req">*</span></label><input class="input" type="number" min="0" name="km_awal" required value="<?= e($p['km_terakhir']) ?>"></div>
        <button class="btn btn-primary mt"><?= iconSvg('car') ?> Serahkan Kunci (→ DIGUNAKAN)</button></form>
      <?php else: ?><p class="small muted">Menyerahkan kunci: khusus <strong>Bagian Umum-Pengadaan</strong>. Status DISETUJUI.</p><?php endif; ?>

    <?php elseif ($p['status'] === 'DIGUNAKAN'): ?>
      <form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="aksi" value="kembali"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="form-grid">
          <div class="field"><label>KM Akhir <span class="req">*</span></label><input class="input" type="number" min="0" name="km_akhir" required placeholder="<?= number_format($p['km_awal'] ?? 0) ?>"></div>
          <div class="field"><label>Biaya BBM (Rp)</label><input class="input" type="number" min="0" step="1000" name="bbm" value="0"></div>
          <div class="field full"><label>Kondisi Saat Kembali</label><select class="input" name="kondisi_kembali"><option>BAIK</option><option>RUSAK RINGAN</option><option>RUSAK BERAT</option></select></div>
          <div class="field full"><label>Bukti BBM <span class="help">(foto struk, opsional)</span></label><input class="input" type="file" name="bukti" accept="image/*,.pdf"></div>
          <div class="field full"><label>Catatan</label><input class="input" name="catatan"></div>
        </div>
        <button class="btn btn-primary mt"><?= iconSvg('truck') ?> Kembalikan Kendaraan (→ DIKEMBALIKAN)</button></form>

    <?php elseif ($p['status'] === 'DIKEMBALIKAN'): ?>
      <?php if (isKabagUmumPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="terima"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <p class="small mb-2">Periksa fisik kendaraan &amp; kesesuaian KM/Bukti BBM, lalu tutup peminjaman.</p>
        <div class="field"><label>Hasil Pemeriksaan</label><input class="input" name="catatan" value="Pemeriksaan OK"></div>
        <button class="btn btn-success mt"><?= iconSvg('check') ?> Terima &amp; Selesaikan (→ DITERIMA)</button></form>
      <?php else: ?><p class="small muted">Menunggu pemeriksaan <strong>Bagian Umum-Pengadaan</strong>.</p><?php endif; ?>

    <?php elseif ($p['status'] === 'DITERIMA'): ?>
      <div class="flash flash-success"><?= iconSvg('check') ?><span>Peminjaman selesai &amp; kendaraan kembali tersedia.</span></div>
    <?php elseif ($p['status'] === 'DITOLAK'): ?>
      <div class="flash flash-error"><?= iconSvg('alert') ?><span>Pengajuan ditolak/dibatalkan<?= $p['catatan_kembali'] ? ': ' . e($p['catatan_kembali']) : '' ?></span></div>
    <?php endif; ?>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- form baru ---------- */
if ($action === 'baru'):
    $kends = qAll("SELECT * FROM kendaraan WHERE status IN ('TERSEDIA','DIGUNAKAN') ORDER BY tnkb");
?>
<div class="page-head"><h1>Ajukan Peminjaman Kendaraan</h1>
  <a class="btn btn-outline" href="<?= u('modules/peminjaman.php') ?>">&larr; Batal</a></div>
<form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="ajukan">
<div class="card"><div class="card-body">
  <div class="form-grid">
    <div class="field"><label>Kendaraan <span class="req">*</span></label>
      <select class="input" name="kendaraan_id" required>
        <option value="">— Pilih —</option>
        <?php foreach ($kends as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['tnkb']) ?> — <?= e($k['merk_type']) ?> (<?= e($k['status']) ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Keperluan / Tujuan <span class="req">*</span></label><textarea class="input" name="tujuan" required rows="2"></textarea></div>
    <div class="field"><label>Tanggal Pinjam <span class="req">*</span></label><input class="input" type="date" name="tanggal_pinjam" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label>Rencana Kembali <span class="req">*</span></label><input class="input" type="date" name="tanggal_kembali" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label>Pengemudi</label><input class="input" name="pengemudi" value="<?= e(authName()) ?>"></div>
    <div class="field"><label>Penumpang</label><input class="input" name="penumpang" placeholder="Daftar nama..."></div>
  </div>
  <div class="mt"><button class="btn btn-primary"><?= iconSvg('calendar') ?> Kirim ke Kadiv Operasional</button></div>
</div></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- list ---------- */
$fStatus = $_GET['status'] ?? '';
$fMine   = isset($_GET['mine']);
$sql = "SELECT pk.*, k.tnkb, k.merk_type FROM peminjaman_kendaraan pk JOIN kendaraan k ON k.id=pk.kendaraan_id WHERE 1";
$pr = [];
if ($fStatus) { $sql .= " AND pk.status=?"; $pr[] = $fStatus; }
if ($fMine)   { $sql .= " AND pk.pemohon=?"; $pr[] = authId(); }
$sql .= " ORDER BY FIELD(pk.status,'MENUNGGU','DISETUJUI','DIGUNAKAN','DIKEMBALIKAN','DITERIMA','DITOLAK'), pk.tanggal_pinjam DESC LIMIT 300";
$rows = qAll($sql, $pr);
$nWait = (int)scalar("SELECT COUNT(*) FROM peminjaman_kendaraan WHERE status='MENUNGGU'");
?>
<div class="page-head">
  <div class="grow"><h1>Peminjaman Kendaraan</h1>
    <div class="small muted"><?= $nWait ?> pengajuan menunggu persetujuan<?= canApprovePinjam() ? ' — perlu tindakan Anda' : '' ?>.</div></div>
  <a class="btn btn-primary" href="<?= u('modules/peminjaman.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Ajukan Peminjaman</a>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <select class="input" name="status" onchange="this.form.submit()">
      <option value="">Semua Status</option>
      <?php foreach (['MENUNGGU', 'DISETUJUI', 'DIGUNAKAN', 'DIKEMBALIKAN', 'DITERIMA', 'DITOLAK'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <label class="checkline" style="margin:0"><input type="checkbox" name="mine" value="1" <?= $fMine ? 'checked' : '' ?> onchange="this.form.submit()"> Hanya pengajuan saya</label>
    <noscript><button class="btn btn-outline">Terapkan</button></noscript>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
<div class="table-wrap" style="border:0;border-radius:0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Kendaraan</th><th>Pemohon</th><th>Tanggal</th><th>Tujuan</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada peminjaman.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td class="mono"><a href="<?= u('modules/peminjaman.php?id=' . $x['id']) ?>"><?= e($x['nomor_pinjam']) ?></a></td>
    <td><strong><?= e($x['tnkb']) ?></strong><br><span class="small muted"><?= e($x['merk_type']) ?></span></td>
    <td><?= e(userName($x['pemohon'])) ?></td>
    <td class="small"><?= tgl($x['tanggal_pinjam']) ?> → <?= tgl($x['tanggal_kembali']) ?></td>
    <td class="small" style="max-width:260px"><?= e(mb_strimwidth((string)$x['tujuan'], 0, 70, '…')) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td class="text-right"><a class="btn btn-sm btn-outline" href="<?= u('modules/peminjaman.php?id=' . $x['id']) ?>"><?= iconSvg('eye') ?></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
