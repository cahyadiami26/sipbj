<?php
/** Modul Laporan Kerusakan — DILAPORKAN > DIVERIFIKASI > DIAGNOSIS > DALAM PERBAIKAN > SELESAI */
$pageTitle = 'Laporan Kerusakan';
$activeNav = 'kerusakan';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'lapor') {
        $asetId = (int)($_POST['aset_id'] ?? 0) ?: null;
        $judul  = trim($_POST['judul'] ?? '');
        $desk   = trim($_POST['deskripsi'] ?? '');
        if (!$judul || !$desk) { flash('Judul dan deskripsi wajib diisi.', 'error'); go('modules/kerusakan.php?aksi=baru'); }
        $foto = uploadFile($_FILES['foto'] ?? [], UPLOAD_FOTO_PATH);
        $no = generateNumber(db(), 'kerusakan', 'nomor_laporan', 'KRK');
        q("INSERT INTO kerusakan (nomor_laporan,aset_id,judul,deskripsi,foto,pelapor,tanggal_lapor,status)
           VALUES (?,?,?,?,?,?,NOW(),'DILAPORKAN')", [$no, $asetId, $judul, $desk, $foto, authId()]);
        if ($asetId) q("UPDATE aset SET kondisi='RUSAK RINGAN' WHERE id=? AND kondisi='BAIK'", [$asetId]);
        logActivity('Kerusakan', 'Lapor', $no);
        flash('Laporan kerusakan #' . $no . ' terkirim ke Bagian Umum-Pengadaan.');
        go('modules/kerusakan.php?id=' . db()->lastInsertId());
    }

    if ($action === 'verifikasi') {
        requireRole(['admin', 'pengadaan']);
        $k = qOne("SELECT * FROM kerusakan WHERE id=?", [$id]);
        if (!$k || $k['status'] !== 'DILAPORKAN') { flash('Status tidak valid.', 'error'); go("modules/kerusakan.php?id=$id"); }
        q("UPDATE kerusakan SET status='DIVERIFIKASI', verifikasi_oleh=?, verifikasi_tgl=NOW() WHERE id=?", [authId(), $id]);
        logActivity('Kerusakan', 'Verifikasi', $k['nomor_laporan']);
        flash('Laporan terverifikasi. Lanjutkan ke diagnosis.');
        go("modules/kerusakan.php?id=$id");
    }

    if ($action === 'diagnosis') {
        requireRole(['admin', 'pengadaan']);
        $k = qOne("SELECT * FROM kerusakan WHERE id=?", [$id]);
        if (!$k || $k['status'] !== 'DIVERIFIKASI') { flash('Status tidak valid.', 'error'); go("modules/kerusakan.php?id=$id"); }
        $dx = trim($_POST['diagnosis'] ?? '');
        if ($dx === '') { flash('Diagnosis wajib diisi.', 'error'); go("modules/kerusakan.php?id=$id"); }
        q("UPDATE kerusakan SET status='DIAGNOSIS', diagnosis=?, diagnosis_oleh=?, diagnosis_tgl=NOW() WHERE id=?", [$dx, authId(), $id]);
        logActivity('Kerusakan', 'Diagnosis', $k['nomor_laporan']);
        flash('Diagnosis tersimpan. Buat perbaikan untuk menindaklanjuti.');
        go("modules/kerusakan.php?id=$id");
    }

    if ($action === 'buat_perbaikan') {
        requireRole(['admin', 'pengadaan']);
        $k = qOne("SELECT * FROM kerusakan WHERE id=?", [$id]);
        if (!$k || !in_array($k['status'], ['DIAGNOSIS', 'DIVERIFIKASI'], true)) { flash('Status tidak valid.', 'error'); go("modules/kerusakan.php?id=$id"); }
        $no = generateNumber(db(), 'perbaikan', 'nomor', 'PRK');
        q("INSERT INTO perbaikan (nomor,aset_id,kerusakan_id,tanggal,item,kerusakan_desc,vendor,teknisi,biaya,tindakan,status,dicatat_oleh)
           VALUES (?,?,?,CURDATE(),?,?,?,?,?,?, 'DALAM PERBAIKAN', ?)", [
            $no, $k['aset_id'], $k['id'], $k['judul'], $k['deskripsi'] ? mb_substr($k['deskripsi'], 0, 250) : null,
            trim($_POST['vendor'] ?? ''), trim($_POST['teknisi'] ?? ''), (float)($_POST['biaya'] ?? 0),
            trim($_POST['tindakan'] ?? ''), authId(),
        ]);
        $pid = (int)db()->lastInsertId();
        q("UPDATE kerusakan SET status='DALAM PERBAIKAN', perbaikan_id=? WHERE id=?", [$pid, $id]);
        if ($k['aset_id']) q("UPDATE aset SET status='RUSAK' WHERE id=?", [$k['aset_id']]);
        logActivity('Kerusakan', 'Buat Perbaikan', $k['nomor_laporan'] . ' -> ' . $no);
        flash('Perbaikan ' . $no . ' dibuat (DALAM PERBAIKAN).');
        go('modules/perbaikan.php?id=' . $pid);
    }

    if ($action === 'tolak') {
        requireRole(['admin', 'pengadaan']);
        $k = qOne("SELECT * FROM kerusakan WHERE id=?", [$id]);
        if (!$k || in_array($k['status'], ['SELESAI', 'DITOLAK'], true)) { flash('Status tidak valid.', 'error'); go("modules/kerusakan.php?id=$id"); }
        q("UPDATE kerusakan SET status='DITOLAK', hasil=? WHERE id=?", [trim($_POST['alasan'] ?? ''), $id]);
        logActivity('Kerusakan', 'Tolak', $k['nomor_laporan']);
        flash('Laporan ditolak.');
        go("modules/kerusakan.php?id=$id");
    }
}

/* ---------- detail ---------- */
if ($id > 0 && !in_array($action, ['baru', 'list'], true)):
    $k = qOne("SELECT k.*, a.kode_aset, a.nama AS aset_nama, a.unit AS aset_unit FROM kerusakan k LEFT JOIN aset a ON a.id=k.aset_id WHERE k.id=?", [$id]);
    if (!$k) { flash('Laporan tidak ditemukan.', 'error'); go('modules/kerusakan.php'); }
    $prb = $k['perbaikan_id'] ? qOne("SELECT * FROM perbaikan WHERE id=?", [$k['perbaikan_id']]) : null;
    $flow = ['DILAPORKAN', 'DIVERIFIKASI', 'DIAGNOSIS', 'DALAM PERBAIKAN', 'SELESAI'];
    $curIdx = array_search($k['status'], $flow);
?>
<div class="page-head">
  <div class="grow"><h1><?= e($k['nomor_laporan']) ?></h1>
    <div class="small muted"><?= e($k['judul']) ?> &bull; Dilaporkan oleh <?= e(userName($k['pelapor'])) ?>, <?= tgl($k['tanggal_lapor']) ?></div></div>
  <a class="btn btn-outline" href="<?= u('modules/kerusakan.php') ?>">&larr; Daftar</a>
</div>

<div class="card"><div class="card-body">
  <div class="steps">
    <?php foreach ($flow as $i => $f):
      $cls = $k['status'] === 'DITOLAK' ? '' : ($i < $curIdx ? 'done' : ($i === $curIdx ? 'current' : '')); ?>
      <div class="step <?= $cls ?>"><span class="dotn"><?= $i + 1 ?></span><span><?= e($f) ?></span><?= $i < count($flow) - 1 ? '<span class="arrow">&rarr;</span>' : '' ?></div>
    <?php endforeach; ?>
  </div>
  <?= badge($k['status']) ?>
</div></div>

<div class="grid g2">
  <div class="card"><div class="card-head"><h3>Detail Laporan</h3></div><div class="card-body">
    <dl class="kv">
      <dt>Aset</dt><dd><?php if ($k['aset_id']): ?><a href="<?= u('modules/aset.php?id=' . $k['aset_id']) ?>" class="mono"><?= e($k['kode_aset']) ?></a> — <?= e($k['aset_nama']) ?><?php else: ?>-<?php endif; ?></dd>
      <dt>Unit</dt><dd><?= e($k['aset_unit'] ?? '-') ?></dd>
      <dt>Kerusakan</dt><dd><?= nl2br(e($k['deskripsi'])) ?></dd>
      <dt>Foto</dt><dd><?= $k['foto'] ? '<img class="thumb" src="' . u('uploads/foto/' . rawurlencode($k['foto'])) . '" alt="foto">' : '-' ?></dd>
      <dt>Diagnosis</dt><dd><?= $k['diagnosis'] ? nl2br(e($k['diagnosis'])) . ' <span class="small muted">(' . e(userName($k['diagnosis_oleh'])) . ', ' . tgl($k['diagnosis_tgl'], 'd/m/Y H:i') . ')</span>' : '-' ?></dd>
      <dt>Hasil</dt><dd><?= e($k['hasil'] ?? '-') ?></dd>
    </dl>
    <?php if ($prb): ?>
      <div class="divider"></div>
      <div class="small muted">Terkait perbaikan:</div>
      <p><a href="<?= u('modules/perbaikan.php?id=' . $prb['id']) ?>" class="mono"><?= e($prb['nomor']) ?></a> — <?= e($prb['item']) ?> <?= badge($prb['status']) ?></p>
    <?php endif; ?>
  </div></div>

  <div class="card"><div class="card-head"><h3>Tindak Lanjut</h3></div><div class="card-body">
    <?php if ($k['status'] === 'DILAPORKAN' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-primary"><?= iconSvg('check') ?> Verifikasi Laporan</button></form>
      <div class="divider"></div>
      <form method="post" data-confirm="Yakin menolak laporan ini?"><?= csrfField() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label>Alasan Penolakan</label><textarea class="input" name="alasan" required></textarea></div>
        <button class="btn btn-danger"><?= iconSvg('alert') ?> Tolak Laporan</button></form>
    <?php elseif ($k['status'] === 'DIVERIFIKASI' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="diagnosis"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label>Hasil Diagnosis <span class="req">*</span></label><textarea class="input" name="diagnosis" placeholder="Contoh: kapasitor AC bocor, perlu penggantian" required></textarea></div>
        <button class="btn btn-primary"><?= iconSvg('search') ?> Simpan Diagnosis</button></form>
    <?php elseif ($k['status'] === 'DIAGNOSIS' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="buat_perbaikan"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid">
          <div class="field"><label>Vendor / Bengkel</label><input class="input" name="vendor" placeholder="ABC Service"></div>
          <div class="field"><label>Teknisi</label><input class="input" name="teknisi"></div>
          <div class="field"><label>Estimasi Biaya (Rp)</label><input class="input" type="number" min="0" step="1000" name="biaya" value="0"></div>
          <div class="field full"><label>Rencana Tindakan</label><textarea class="input" name="tindakan"></textarea></div>
        </div>
        <button class="btn btn-primary"><?= iconSvg('wrench') ?> Buat Perbaikan &amp; Mulai</button></form>
    <?php elseif ($k['status'] === 'DALAM PERBAIKAN'): ?>
      <p class="small muted">Sedang dalam penanganan. <?= $prb ? 'Pantau progres pada modul <a href="' . u('modules/perbaikan.php?id=' . $prb['id']) . '">Perbaikan</a>.' : '' ?></p>
    <?php elseif ($k['status'] === 'SELESAI'): ?>
      <div class="flash flash-success"><?= iconSvg('check') ?><span>Selesai <?= tgl($k['selesai_tgl'], 'd/m/Y H:i') ?><?= $k['hasil'] ? ' — ' . e($k['hasil']) : '' ?></span></div>
    <?php elseif ($k['status'] === 'DITOLAK'): ?>
      <div class="flash flash-error"><?= iconSvg('alert') ?><span>Laporan ditolak<?= $k['hasil'] ? ': ' . e($k['hasil']) : '' ?></span></div>
    <?php else: ?>
      <p class="small muted">Menunggu verifikasi Bagian Umum-Pengadaan.</p>
    <?php endif; ?>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- form lapor baru ---------- */
if ($action === 'baru'):
    $asets = qAll("SELECT id,kode_aset,nama,unit FROM aset WHERE status NOT IN ('DIHAPUS','HILANG') ORDER BY kode_aset");
?>
<div class="page-head"><h1>Laporan Kerusakan Baru</h1>
  <a class="btn btn-outline" href="<?= u('modules/kerusakan.php') ?>">&larr; Batal</a></div>
<form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="aksi" value="lapor">
<div class="card"><div class="card-body">
  <div class="form-grid">
    <div class="field"><label>Aset Terkait <span class="help">(opsional)</span></label>
      <select class="input" name="aset_id"><option value="">— Bukan aset inventaris —</option>
        <?php foreach ($asets as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['kode_aset']) ?> — <?= e($x['nama']) ?> (<?= e($x['unit']) ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Judul <span class="req">*</span></label><input class="input" name="judul" maxlength="200" required placeholder="Laptop tidak dapat menyala"></div>
    <div class="field full"><label>Deskripsi Kerusakan <span class="req">*</span></label><textarea class="input" name="deskripsi" required placeholder="Jelaskan kronologi & gejala kerusakan..."></textarea></div>
    <div class="field"><label>Foto Bukti</label><input class="input" type="file" name="foto" accept="image/*"><div class="help">JPG/PNG maks 5 MB.</div></div>
  </div>
  <div class="mt"><button class="btn btn-primary"><?= iconSvg('alert') ?> Kirim Laporan</button></div>
</div></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- list ---------- */
$fStatus = $_GET['status'] ?? '';
$fMine   = isset($_GET['mine']);
$sql = "SELECT k.*, a.kode_aset, a.nama AS aset_nama FROM kerusakan k LEFT JOIN aset a ON a.id=k.aset_id WHERE 1";
$pr = [];
if ($fStatus) { $sql .= " AND k.status=?"; $pr[] = $fStatus; }
if ($fMine)   { $sql .= " AND k.pelapor=?"; $pr[] = authId(); }
$sql .= " ORDER BY FIELD(k.status,'DILAPORKAN','DIVERIFIKASI','DIAGNOSIS','DALAM PERBAIKAN','SELESAI','DITOLAK'), k.id DESC LIMIT 300";
$rows = qAll($sql, $pr);
$cntOpen = (int)scalar("SELECT COUNT(*) FROM kerusakan WHERE status NOT IN ('SELESAI','DITOLAK')");
?>
<div class="page-head">
  <div class="grow"><h1>Laporan Kerusakan</h1>
    <div class="small muted"><?= $cntOpen ?> laporan belum selesai.</div></div>
  <a class="btn btn-primary" href="<?= u('modules/kerusakan.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Lapor Kerusakan</a>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <select class="input" name="status" onchange="this.form.submit()">
      <option value="">Semua Status</option>
      <?php foreach (['DILAPORKAN','DIVERIFIKASI','DIAGNOSIS','DALAM PERBAIKAN','SELESAI','DITOLAK'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <label class="checkline" style="margin:0"><input type="checkbox" name="mine" value="1" <?= $fMine ? 'checked' : '' ?> onchange="this.form.submit()"> Hanya laporan saya</label>
    <noscript><button class="btn btn-outline">Terapkan</button></noscript>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
<div class="table-wrap" style="border:0;border-radius:0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Aset</th><th>Judul Kerusakan</th><th>Pelapor</th><th>Tanggal</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Tidak ada laporan.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td class="mono"><a href="<?= u('modules/kerusakan.php?id=' . $x['id']) ?>"><?= e($x['nomor_laporan']) ?></a></td>
    <td><?= $x['kode_aset'] ? '<span class="mono small">' . e($x['kode_aset']) . '</span><br><span class="small muted">' . e($x['aset_nama']) . '</span>' : '<span class="muted">-</span>' ?></td>
    <td><?= e($x['judul']) ?></td>
    <td><?= e(userName($x['pelapor'])) ?></td>
    <td><?= tgl($x['tanggal_lapor']) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td class="text-right"><a class="btn btn-sm btn-outline" href="<?= u('modules/kerusakan.php?id=' . $x['id']) ?>"><?= iconSvg('eye') ?></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
