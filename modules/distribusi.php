<?php
/** Distribusi Inventaris: permintaan → persetujuan → serahkan → berita acara → status aset diperbarui */
$pageTitle = 'Distribusi Inventaris';
$activeNav = 'distribusi';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'ajukan') {
        $asetId = (int)($_POST['aset_id'] ?? 0);
        $unitT  = trim($_POST['unit_tujuan'] ?? '');
        $lokT   = (int)($_POST['lokasi_tujuan'] ?? 0) ?: null;
        $keperluan = trim($_POST['keperluan'] ?? '');
        $a = qOne("SELECT * FROM aset WHERE id=?", [$asetId]);
        if (!$a || !in_array($a['status'], ['AKTIF','DIPINDAHKAN'])) { flash('Aset tidak tersedia untuk didistribusikan.','error'); go('modules/distribusi.php?aksi=baru'); }
        $no = generateNumber(db(), 'distribusi', 'nomor_distribusi', 'DSB');
        q("INSERT INTO distribusi (nomor_distribusi,aset_id,permintaan_oleh,unit_tujuan,lokasi_tujuan,keperluan,status)
           VALUES (?,?,?,?,?,?, 'MENUNGGU PERSETUJUAN')", [$no,$asetId,authId(),$unitT,$lokT,$keperluan]);
        logActivity('Distribusi','Diajukan',$no);
        flash('Permintaan distribusi dibuat. Menunggu persetujuan Bagian Umum.');
        go('modules/distribusi.php?id=' . db()->lastInsertId());
    }

    if ($action === 'setujui' || $action === 'tolak_distribusi') {
        requireRole(['admin','pengadaan']);
        $d = qOne("SELECT * FROM distribusi WHERE id=?", [$id]);
        if (!$d || $d['status'] !== 'MENUNGGU PERSETUJUAN') { flash('Status tidak valid.','error'); go("modules/distribusi.php?id=$id"); }
        if ($action === 'tolak_distribusi') {
            q("UPDATE distribusi SET status='DITOLAK', setujui_oleh=?, setujui_tgl=NOW(), catatan=? WHERE id=?", [authId(), trim($_POST['catatan'] ?? ''), $id]);
            flash('Permintaan ditolak.');
        } else {
            q("UPDATE distribusi SET status='DISSETUJUI', setujui_oleh=?, setujui_tgl=NOW() WHERE id=?", [authId(), $id]);
            flash('Disetujui. Silakan serahkan barang dan buat berita acara.');
        }
        logActivity('Distribusi', $action==='setujui'?'Disetujui':'Ditolak', $d['nomor_distribusi']);
        go("modules/distribusi.php?id=$id");
    }

    if ($action === 'serahkan') {
        requireRole(['admin','pengadaan']);
        $d = qOne("SELECT * FROM distribusi WHERE id=?", [$id]);
        if (!$d || $d['status'] !== 'DISSETUJUI') { flash('Status tidak valid.','error'); go("modules/distribusi.php?id=$id"); }
        $ba = trim($_POST['berita_acara'] ?? '');
        q("UPDATE distribusi SET status='DISERAHKAN', serah_terima_oleh=?, berita_acara=?, catatan=? WHERE id=?",
          [authId(), $ba, trim($_POST['catatan'] ?? ''), $id]);
        // update aset -> DIPINDAHKAN ke tujuan
        q("UPDATE aset SET unit=?, lokasi_id=?, pengguna_id=?, status='DIPINDAHKAN' WHERE id=?",
          [$d['unit_tujuan'], $d['lokasi_tujuan'], $d['permintaan_oleh'], $d['aset_id']]);
        // histori mutasi
        $old = qOne("SELECT a.*, l.nama ln FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE a.id=?", [$d['aset_id']]);
        $lokTujuan = $d['lokasi_tujuan'] ? (string)scalar("SELECT nama FROM lokasi WHERE id=?", [$d['lokasi_tujuan']]) : '-';
        q("INSERT INTO mutasi_aset (aset_id,tanggal,unit_sebelum,lokasi_sebelum,pengguna_sebelum,unit_sesudah,lokasi_sesudah,pengguna_sesudah,alasan,oleh,status)
           VALUES (?,CURDATE(),?,?,?,?,?,?,?, ?, 'SELESAI')",
          [$d['aset_id'], $old['unit'], $old['ln'] ?: '-', userName($old['pengguna_id']),
           $d['unit_tujuan'], $lokTujuan, userName($d['permintaan_oleh']), 'Distribusi '.$d['nomor_distribusi'], authId()]);
        logActivity('Distribusi','Diserahkan + BA', $d['nomor_distribusi']);
        flash('Barang diserahkan. Berita acara tersimpan & status aset diperbarui.');
        go("modules/distribusi.php?id=$id");
    }

    if ($action === 'konfirmasi_terima') {
        $d = qOne("SELECT * FROM distribusi WHERE id=?", [$id]);
        if (!$d || $d['status'] !== 'DISERAHKAN') { flash('Status tidak valid.','error'); go("modules/distribusi.php?id=$id"); }
        if (authId() != $d['permintaan_oleh'] && !isAdmin()) { flash('Hanya pihak penerima yang dapat mengonfirmasi.','error'); go("modules/distribusi.php?id=$id"); }
        q("UPDATE distribusi SET status='DITERIMA', terima_tgl=NOW() WHERE id=?", [$id]);
        q("UPDATE aset SET status='AKTIF' WHERE id=?", [$d['aset_id']]);
        logActivity('Distribusi','Diterima penerima', $d['nomor_distribusi']);
        flash('Konfirmasi penerimaan tercatat. Aset kembali AKTIF di unit tujuan.');
        go("modules/distribusi.php?id=$id");
    }
}

/* ---------- detail ---------- */
if ($id > 0 && $action !== 'baru'):
$d = qOne("SELECT d.*, a.kode_aset, a.nama AS aset_nama, l.nama AS lokasi_asal, lt.nama AS lokasi_tujuan_nama
           FROM distribusi d
           LEFT JOIN aset a ON a.id=d.aset_id
           LEFT JOIN lokasi l ON l.id=a.lokasi_id
           LEFT JOIN lokasi lt ON lt.id=d.lokasi_tujuan WHERE d.id=?", [$id]);
if (!$d) { flash('Data tidak ditemukan.','error'); go('modules/distribusi.php'); }
$steps = ['MENUNGGU PERSETUJUAN','DISSETUJUI','DISERAHKAN','DITERIMA'];
$cur = array_search($d['status'], $steps);
?>
<div class="page-head">
  <a class="btn btn-ghost" href="<?= u('modules/distribusi.php') ?>"><?= iconSvg('back') ?> Kembali</a>
  <h1><?= e($d['nomor_distribusi']) ?> <?= badge($d['status']) ?></h1>
  <?php if ($d['status']==='DISERAHKAN'||$d['status']==='DITERIMA'): ?>
  <a class="btn btn-sm btn-outline" target="_blank" href="<?= u('modules/distribusi_pdf.php?id='.$id) ?>"><?= iconSvg('file') ?> PDF Berita Acara</a>
  <?php endif; ?>
</div>

<?php if ($d['status']!=='DITOLAK'): ?>
<div class="card mb"><div class="card-body"><div class="steps">
<?php foreach ($steps as $i=>$s): ?><div class="step <?= $cur!==false&&$i<=$cur?'done':'' ?>"><span class="dot"><?= $i+1 ?></span><span><?= e($s) ?></span></div><?php endforeach; ?>
</div></div></div>
<?php endif; ?>

<div class="grid-2">
  <div class="card"><div class="card-head"><h3>Data Distribusi</h3></div><div class="card-body">
    <table class="kv">
      <tr><th>Aset</th><td><b><a class="link" href="<?= u('modules/aset.php?id='.$d['aset_id']) ?>"><?= e($d['kode_aset']) ?></a></b> — <?= e($d['aset_nama']) ?></td></tr>
      <tr><th>Lokasi Asal</th><td><?= e($d['lokasi_asal'] ?: '-') ?></td></tr>
      <tr><th>Unit Tujuan</th><td><?= e($d['unit_tujuan']) ?></td></tr>
      <tr><th>Lokasi Tujuan</th><td><?= e($d['lokasi_tujuan_nama'] ?: '-') ?></td></tr>
      <tr><th>Dipinta Oleh</th><td><?= e(userName($d['permintaan_oleh'])) ?> · <?= tgl($d['created_at'],'d/m/Y H:i') ?></td></tr>
      <tr><th>Keperluan</th><td><?= nl2br(e($d['keperluan'] ?: '-')) ?></td></tr>
      <?php if ($d['setujui_oleh']): ?><tr><th>Disetujui</th><td><?= e(userName($d['setujui_oleh'])) ?> · <?= tgl($d['setujui_tgl'],'d/m/Y H:i') ?></td></tr><?php endif; ?>
      <?php if ($d['serah_terima_oleh']): ?><tr><th>Diserahkan</th><td><?= e(userName($d['serah_terima_oleh'])) ?></td></tr><?php endif; ?>
      <?php if ($d['terima_tgl']): ?><tr><th>Konfirmasi Diterima</th><td><?= tgl($d['terima_tgl'],'d/m/Y H:i') ?> oleh <?= e(userName($d['permintaan_oleh'])) ?></td></tr><?php endif; ?>
      <tr><th>Berita Acara</th><td><?= nl2br(e($d['berita_acara'] ?: '-')) ?></td></tr>
      <tr><th>Catatan</th><td><?= e($d['catatan'] ?: '-') ?></td></tr>
    </table>
  </div></div>

  <div class="card"><div class="card-head"><h3>Aksi</h3></div><div class="card-body">
    <?php if ($d['status']==='MENUNGGU PERSETUJUAN' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row"><input class="input" name="catatan" placeholder="Catatan (opsional)"></div>
        <button class="btn btn-primary" name="aksi" value="setujui"><?= iconSvg('check') ?> Setujui</button>
        <button class="btn btn-danger" name="aksi" value="tolak_distribusi" onclick="return confirm('Tolak permintaan ini?')">Tolak</button>
      </form>
    <?php elseif ($d['status']==='DISSETUJUI' && isPengadaan()): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="serahkan"><input type="hidden" name="id" value="<?= $id ?>">
        <label class="fld">Isi Berita Acara / Tanda Terima<textarea class="input" name="berita_acara" rows="4" required>Pada hari ini <?= tglIndo(date('Y-m-d')) ?>, barang <?= e($d['aset_nama']) ?> (<?= e($d['kode_aset']) ?>) telah diserahkan kepada <?= e(userName($d['permintaan_oleh'])) ?> untuk unit <?= e($d['unit_tujuan']) ?>.</textarea></label>
        <div class="form-row mt"><input class="input" name="catatan" placeholder="Catatan tambahan"></div>
        <button class="btn btn-success"><?= iconSvg('check') ?> Serahkan + Simpan Berita Acara</button>
      </form>
    <?php elseif ($d['status']==='DISERAHKAN' && (authId()==$d['permintaan_oleh']||isAdmin())): ?>
      <p class="small muted">Klik konfirmasi bila barang telah Anda terima dalam kondisi baik.</p>
      <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="konfirmasi_terima"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-primary"><?= iconSvg('check') ?> Konfirmasi Barang Diterima</button></form>
    <?php else: ?>
      <p class="muted small">Tidak ada aksi pada tahap ini.</p>
    <?php endif; ?>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- form baru ---------- */
if ($action === 'baru'):
$asetReady = qAll("SELECT a.*, l.nama lokasi_nama FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE a.status IN ('AKTIF','DIPINDAHKAN') AND a.kondisi='BAIK' ORDER BY a.kode_aset LIMIT 500");
$ruang = qAll("SELECT id,nama FROM lokasi WHERE tipe='RUANG' AND is_active=1 ORDER BY nama");
?>
<div class="page-head"><h1>Permintaan Distribusi Baru</h1></div>
<form method="post" action="<?= u('modules/distribusi.php') ?>">
<?= csrfField() ?><input type="hidden" name="aksi" value="ajukan">
<div class="card"><div class="card-body"><div class="form-grid">
  <label class="fld span2">Aset *
    <select class="input" name="aset_id" required>
      <option value="">— pilih aset tersedia —</option>
      <?php foreach ($asetReady as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['kode_aset'].' — '.$x['nama'].' ('.($x['lokasi_nama'] ?: 'tanpa lokasi').')') ?></option><?php endforeach; ?>
    </select></label>
  <label class="fld">Unit Tujuan *
    <select class="input" name="unit_tujuan" required>
      <option value="">— pilih —</option>
      <?php foreach (unitOptions() as $un): ?><option <?= authDivision()===$un?'selected':'' ?>><?= e($un) ?></option><?php endforeach; ?>
    </select></label>
  <label class="fld">Ruang/Lokasi Tujuan
    <select class="input" name="lokasi_tujuan"><option value="">—</option>
    <?php foreach ($ruang as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['nama']) ?></option><?php endforeach; ?></select></label>
  <label class="fld span2">Keperluan<textarea class="input" name="keperluan" rows="3" placeholder="Contoh: kebutuhan operasional divisi IT"></textarea></label>
</div>
<div class="mt"><button class="btn btn-primary"><?= iconSvg('check') ?> Ajukan Permintaan</button>
<a class="btn btn-ghost" href="<?= u('modules/distribusi.php') ?>">Batal</a></div>
</div></div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- list ---------- */
$fStatus = $_GET['status'] ?? '';
$sql = "SELECT d.*, a.kode_aset,a.nama aset_nama FROM distribusi d LEFT JOIN aset a ON a.id=d.aset_id";
$pr=[]; if ($fStatus) { $sql .= " WHERE d.status=?"; $pr[]=$fStatus; }
$sql .= " ORDER BY d.id DESC LIMIT 300";
$rows = qAll($sql,$pr);
$menunggu = (int)scalar("SELECT COUNT(*) FROM distribusi WHERE status='MENUNGGU PERSETUJUAN'");
?>
<div class="page-head">
  <h1>Distribusi Inventaris</h1>
  <a class="btn btn-primary" href="<?= u('modules/distribusi.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Permintaan Baru</a>
</div>
<?php if ($menunggu && isPengadaan()): ?><div class="alert warn mb">Ada <b><?= $menunggu ?></b> permintaan menunggu persetujuan Anda.</div><?php endif; ?>
<div class="card mb"><div class="card-body"><form method="get" class="filter-bar">
  <select class="input" name="status" onchange="this.form.submit()">
    <option value="">Semua Status</option>
    <?php foreach (['MENUNGGU PERSETUJUAN','DISSETUJUI','DISERAHKAN','DITERIMA','DITOLAK'] as $s): ?><option <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
  </select>
</form></div></div>
<div class="card"><div class="card-body p0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Aset</th><th>Peminta</th><th>Unit Tujuan</th><th>Tanggal</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada distribusi.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td><a class="link" href="<?= u('modules/distribusi.php?id='.$x['id']) ?>"><?= e($x['nomor_distribusi']) ?></a></td>
    <td class="mono"><?= e($x['kode_aset'] ?: '-') ?><br><small class="muted"><?= e($x['aset_nama'] ?: '') ?></small></td>
    <td><?= e(userName($x['permintaan_oleh'])) ?></td>
    <td><?= e($x['unit_tujuan']) ?></td>
    <td><?= tgl($x['created_at']) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td class="ta-r"><a class="btn btn-sm btn-ghost" href="<?= u('modules/distribusi.php?id='.$x['id']) ?>">Detail</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
