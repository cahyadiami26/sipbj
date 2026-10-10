<?php
/** Penghapusan Aset — PENGAJUAN > VERIFIKASI (Kabag Pengadaan) > PERSETUJUAN (Kadiv Umum & Keuangan) > BERITA_ACARA > DIHAPUS */
$pageTitle = 'Penghapusan Aset';
$activeNav = 'penghapusan';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'ajukan') {
        $asetId = (int)($_POST['aset_id'] ?? 0);
        $alasan = $_POST['alasan'] ?? 'RUSAK BERAT';
        $uraian = trim($_POST['uraian'] ?? '');
        $a = qOne("SELECT * FROM aset WHERE id=?", [$asetId]);
        if (!$a || $a['status']==='DIHAPUS') { flash('Aset tidak valid.','error'); go('modules/penghapusan.php?aksi=baru'); }
        if (qOne("SELECT id FROM penghapusan_aset WHERE aset_id=? AND status NOT IN ('DIHAPUS','DITOLAK')", [$asetId])) {
            flash('Aset ini sudah dalam proses penghapusan.','error'); go('modules/penghapusan.php?aksi=baru');
        }
        $no = generateNumber(db(), 'penghapusan_aset', 'nomor_penghapusan', 'PHS');
        q("INSERT INTO penghapusan_aset (nomor_penghapusan,aset_id,alasan,uraian,diajukan_oleh,diajukan_tgl,status)
           VALUES (?,?,?,?,NOW(),'PENGAJUAN')", [$no,$asetId,$alasan,$uraian,authId()]);
        logActivity('Penghapusan','Diajukan',$no);
        flash('Pengajuan penghapusan dibuat.'); go('modules/penghapusan.php?id='.db()->lastInsertId());
    }

    if ($action === 'verifikasi') {
        requireRole(['admin','pengadaan']);
        $p = qOne("SELECT * FROM penghapusan_aset WHERE id=?", [$id]);
        if (!$p || $p['status'] !== 'PENGAJUAN') { flash('Status tidak valid.','error'); go("modules/penghapusan.php?id=$id"); }
        q("UPDATE penghapusan_aset SET status='VERIFIKASI', verifikasi_oleh=?, verifikasi_catatan=?, verifikasi_tgl=NOW() WHERE id=?",
          [authId(), trim($_POST['catatan'] ?? ''), $id]);
        logActivity('Penghapusan','Terverifikasi',$p['nomor_penghapusan']);
        flash('Terverifikasi Kabag Pengadaan. Menunggu persetujuan Kadiv Umum & Keuangan.'); go("modules/penghapusan.php?id=$id");
    }

    if ($action === 'approve' || $action === 'reject') {
        $p = qOne("SELECT * FROM penghapusan_aset WHERE id=?", [$id]);
        if (!$p || $p['status'] !== 'VERIFIKASI') { flash('Status tidak valid.','error'); go("modules/penghapusan.php?id=$id"); }
        // Approver: Kepala Divisi Keuangan & Umum / admin
        if (!isAdmin() && !(authPosition()==='Kepala Divisi' && authDepartment()==='Keuangan & Umum')) {
            flash('Hanya Kepala Divisi Umum & Keuangan yang dapat menyetujui.','error'); go("modules/penghapusan.php?id=$id");
        }
        if ($action==='reject') {
            q("UPDATE penghapusan_aset SET status='DITOLAK', persetujuan_oleh=?, persetujuan_catatan=?, persetujuan_tgl=NOW() WHERE id=?",
              [authId(), trim($_POST['catatan'] ?? ''), $id]);
            flash('Penghapusan ditolak.');
        } else {
            q("UPDATE penghapusan_aset SET status='PERSETUJUAN', persetujuan_oleh=?, persetujuan_catatan=?, persetujuan_tgl=NOW() WHERE id=?",
              [authId(), trim($_POST['catatan'] ?? ''), $id]);
            flash('Disetujui. Buat berita acara penghapusan untuk finalisasi.');
        }
        logActivity('Penghapusan', $action==='reject'?'Ditolak Kadiv':'Disetujui Kadiv', $p['nomor_penghapusan']);
        go("modules/penghapusan.php?id=$id");
    }

    if ($action === 'berita_acara') {
        requireRole(['admin','pengadaan']);
        $p = qOne("SELECT * FROM penghapusan_aset WHERE id=?", [$id]);
        if (!$p || !in_array($p['status'], ['PERSETUJUAN','BERITA_ACARA'])) { flash('Status tidak valid.','error'); go("modules/penghapusan.php?id=$id"); }
        $baNo = trim($_POST['berita_acara_no'] ?? '');
        $baTgl = $_POST['berita_acara_tgl'] ?: date('Y-m-d');
        if (!$baNo) { flash('Nomor berita acara wajib diisi.','error'); go("modules/penghapusan.php?id=$id"); }
        q("UPDATE penghapusan_aset SET status='DIHAPUS', berita_acara_no=?, berita_acara_tgl=? WHERE id=?", [$baNo,$baTgl,$id]);
        q("UPDATE aset SET status='DIHAPUS' WHERE id=?", [$p['aset_id']]);
        logActivity('Penghapusan','BA terbit - aset DIHAPUS', $p['nomor_penghapusan']);
        flash('Berita acara tersimpan. Aset resmi DIHAPUS dari inventaris.');
        go("modules/penghapusan.php?id=$id");
    }
}

/* ---------- detail ---------- */
if ($id > 0 && $action !== 'baru'):
$p = qOne("SELECT p.*, a.kode_aset,a.nama aset_nama,a.nilai_perolehan,a.kondisi FROM penghapusan_aset p JOIN aset a ON a.id=p.aset_id WHERE p.id=?", [$id]);
if (!$p) { flash('Data tidak ditemukan.','error'); go('modules/penghapusan.php'); }
$steps=['PENGAJUAN','VERIFIKASI','PERSETUJUAN','DIHAPUS'];
$cur = array_search($p['status']==='BERITA_ACARA'?'DIHAPUS':$p['status'], $steps);
?>
<div class="page-head">
  <a class="btn btn-ghost" href="<?= u('modules/penghapusan.php') ?>"><?= iconSvg('back') ?> Kembali</a>
  <h1><?= e($p['nomor_penghapusan']) ?> <?= badge($p['status']) ?></h1>
  <?php if ($p['status']==='DIHAPUS'): ?><a class="btn btn-sm btn-outline" target="_blank" href="<?= u('modules/penghapusan_pdf.php?id='.$id) ?>"><?= iconSvg('file') ?> PDF BA Penghapusan</a><?php endif; ?>
</div>

<div class="card mb"><div class="card-body"><div class="steps">
<?php foreach ($steps as $i=>$s): ?><div class="step <?= $cur!==false&&$i<=$cur?'done':'' ?>"><span class="dot"><?= $i+1 ?></span><span><?= e(ucwords(strtolower($s))) ?></span></div><?php endforeach; ?>
</div></div></div>

<div class="grid-2">
  <div class="card"><div class="card-head"><h3>Data Aset & Pengajuan</h3></div><div class="card-body">
    <table class="kv">
      <tr><th>Aset</th><td><b><a class="link" href="<?= u('modules/aset.php?id='.$p['aset_id']) ?>"><?= e($p['kode_aset']) ?></a></b> — <?= e($p['aset_nama']) ?></td></tr>
      <tr><th>Kondisi Terakhir</th><td><?= badge($p['kondisi']) ?></td></tr>
      <tr><th>Nilai Perolehan</th><td><?= rupiah($p['nilai_perolehan']) ?></td></tr>
      <tr><th>Alasan</th><td><?= badge($p['alasan']) ?></td></tr>
      <tr><th>Uraian</th><td><?= nl2br(e($p['uraian'] ?: '-')) ?></td></tr>
      <tr><th>Diajukan</th><td><?= e(userName($p['diajukan_oleh'])) ?> · <?= tgl($p['diajukan_tgl'],'d/m/Y H:i') ?></td></tr>
      <?php if ($p['verifikasi_oleh']): ?><tr><th>Verifikasi (Kabag Pengadaan)</th><td><?= e(userName($p['verifikasi_oleh'])) ?> · <?= tgl($p['verifikasi_tgl'],'d/m/Y H:i') ?><?= $p['verifikasi_catatan']?'<br><small>'.e($p['verifikasi_catatan']).'</small>':'' ?></td></tr><?php endif; ?>
      <?php if ($p['persetujuan_oleh']): ?><tr><th>Persetujuan (Kadiv U&K)</th><td><?= e(userName($p['persetujuan_oleh'])) ?> · <?= tgl($p['persetujuan_tgl'],'d/m/Y H:i') ?><?= $p['persetujuan_catatan']?'<br><small>'.e($p['persetujuan_catatan']).'</small>':'' ?></td></tr><?php endif; ?>
      <?php if ($p['berita_acara_no']): ?><tr><th>Berita Acara</th><td><?= e($p['berita_acara_no']) ?> · <?= tgl($p['berita_acara_tgl']) ?></td></tr><?php endif; ?>
    </table>
  </div></div>

  <div class="card"><div class="card-head"><h3>Aksi</h3></div><div class="card-body">
    <?php if ($p['status']==='PENGAJUAN' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row"><input class="input" name="catatan" placeholder="Catatan hasil pemeriksaan fisik"></div>
        <button class="btn btn-primary"><?= iconSvg('check') ?> Verifikasi (Layak Dihapus)</button></form>
    <?php elseif ($p['status']==='VERIFIKASI' && (isAdmin()||(authPosition()==='Kepala Divisi'&&authDepartment()==='Keuangan & Umum'))): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row"><input class="input" name="catatan" placeholder="Catatan persetujuan"></div>
        <button class="btn btn-success" name="aksi" value="approve"><?= iconSvg('check') ?> Setujui Penghapusan</button>
        <button class="btn btn-danger" name="aksi" value="reject" onclick="return confirm('Tolak penghapusan?')">Tolak</button></form>
    <?php elseif ($p['status']==='PERSETUJUAN' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="berita_acara"><input type="hidden" name="id" value="<?= $id ?>">
        <label class="fld">Nomor Berita Acara *<input class="input" name="berita_acara_no" required placeholder="mis. 045/BA-PH/LJU/X/2026"></label>
        <label class="fld">Tanggal BA<input class="input" type="date" name="berita_acara_tgl" value="<?= date('Y-m-d') ?>"></label>
        <button class="btn btn-primary mt"><?= iconSvg('file') ?> Terbitkan BA & Hapus Aset</button></form>
    <?php elseif ($p['status']==='DIHAPUS'): ?>
      <div class="alert ok">Aset telah dihapus secara administratif. Nilai <?= rupiah($p['nilai_perolehan']) ?> dikeluarkan dari neraca inventaris.</div>
    <?php elseif ($p['status']==='DITOLAK'): ?>
      <div class="alert error">Pengajuan ditolak pada tahap persetujuan.</div>
    <?php else: ?><p class="muted small">Menunggu pihak berwenang.</p><?php endif; ?>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- form baru ---------- */
if ($action === 'baru'):
$aset = qAll("SELECT a.* FROM aset a WHERE a.status NOT IN ('DIHAPUS') ORDER BY a.kode_aset LIMIT 500");
?>
<div class="page-head"><h1>Pengajuan Penghapusan Aset</h1></div>
<form method="post" action="<?= u('modules/penghapusan.php') ?>">
<?= csrfField() ?><input type="hidden" name="aksi" value="ajukan">
<div class="card"><div class="card-body"><div class="form-grid">
  <label class="fld span2">Aset *
    <select class="input" name="aset_id" required><option value="">— pilih aset —</option>
    <?php foreach ($aset as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['kode_aset'].' — '.$x['nama'].' ['.($x['kondisi']).']') ?></option><?php endforeach; ?></select></label>
  <label class="fld">Alasan Penghapusan *
    <select class="input" name="alasan" required>
      <?php foreach (['RUSAK BERAT','HILANG','TIDAK EKONOMIS','TIDAK DIPERGUNAKAN','LAINNYA'] as $r): ?><option><?= $r ?></option><?php endforeach; ?>
    </select></label>
  <label class="fld span2">Uraian<textarea class="input" name="uraian" rows="3" required placeholder="Jelaskan kondisi aset dan dasar penghapusan..."></textarea></label>
</div>
<div class="mt"><button class="btn btn-primary"><?= iconSvg('trash') ?> Ajukan Penghapusan</button>
<a class="btn btn-ghost" href="<?= u('modules/penghapusan.php') ?>">Batal</a></div>
</div></div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- list ---------- */
$fStatus = $_GET['status'] ?? '';
$sql = "SELECT p.*, a.kode_aset,a.nama aset_nama FROM penghapusan_aset p JOIN aset a ON a.id=p.aset_id";
$pr=[]; if ($fStatus) { $sql .= " WHERE p.status=?"; $pr[]=$fStatus; }
$sql .= " ORDER BY p.id DESC LIMIT 300";
$rows = qAll($sql,$pr);
?>
<div class="page-head">
  <h1>Penghapusan Aset</h1>
  <a class="btn btn-primary" href="<?= u('modules/penghapusan.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Pengajuan Baru</a>
</div>
<div class="card mb"><div class="card-body"><form method="get" class="filter-bar">
  <select class="input" name="status" onchange="this.form.submit()">
    <option value="">Semua Status</option>
    <?php foreach (['PENGAJUAN','VERIFIKASI','PERSETUJUAN','BERITA_ACARA','DIHAPUS','DITOLAK'] as $s): ?><option <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
  </select>
</form></div></div>
<div class="card"><div class="card-body p0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Aset</th><th>Alasan</th><th>Diajukan Oleh</th><th>Tanggal</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada pengajuan penghapusan.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td><a class="link" href="<?= u('modules/penghapusan.php?id='.$x['id']) ?>"><?= e($x['nomor_penghapusan']) ?></a></td>
    <td class="mono"><?= e($x['kode_aset']) ?><br><small class="muted"><?= e($x['aset_nama']) ?></small></td>
    <td><?= badge($x['alasan']) ?></td>
    <td><?= e(userName($x['diajukan_oleh'])) ?></td>
    <td><?= tgl($x['diajukan_tgl']) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td class="ta-r"><a class="btn btn-sm btn-ghost" href="<?= u('modules/penghapusan.php?id='.$x['id']) ?>">Detail</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
