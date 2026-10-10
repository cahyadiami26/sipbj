<?php
/** Modul Penerimaan Barang/Jasa — checklist penerimaan, DITERIMA / DITERIMA SEBAGIAN / DITOLAK */
$pageTitle = 'Penerimaan Barang/Jasa';
$activeNav = 'penerimaan';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin','pengadaan']);

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'simpan') {
    verifyCsrf();
    $tanggal   = $_POST['tanggal'] ?: date('Y-m-d');
    $rencanaId = (int)($_POST['rencana_id'] ?? 0) ?: null;
    $kebutId   = (int)($_POST['kebutuhan_id'] ?? 0) ?: null;
    $nama      = trim($_POST['nama_barang'] ?? '');
    $vendor    = trim($_POST['vendor'] ?? '');
    $faktur    = trim($_POST['no_faktur'] ?? '');
    $tglFaktur = $_POST['tgl_faktur'] ?: null;
    $jmlDiterima = max(0,(int)($_POST['jumlah_diterima'] ?? 0));
    $jmlDiminta  = max(0,(int)($_POST['jumlah_diminta'] ?? 0));
    $harga     = (float)($_POST['harga_satuan'] ?? 0);
    $ck = ['ck_jumlah_sesuai','ck_spesifikasi_sesuai','ck_kondisi_baik','ck_dokumen_lengkap'];
    foreach ($ck as $c) $$c = isset($_POST[$c]) ? 1 : 0;
    $ket = trim($_POST['keterangan'] ?? '');

    if (!$nama || $jmlDiterima <= 0) { flash('Nama barang dan jumlah diterima wajib diisi.','error'); go('modules/penerimaan.php?aksi=baru'); }

    $allOk = $_POST['ck_jumlah_sesuai']==1 && $_POST['ck_spesifikasi_sesuai']==1 && $_POST['ck_kondisi_baik']==1 && $_POST['ck_dokumen_lengkap']==1;
    if (($_POST['keputusan'] ?? '') === 'tolak') $status = 'DITOLAK';
    elseif (!$allOk || ($jmlDiminta > 0 && $jmlDiterima < $jmlDiminta)) $status = 'DITERIMA SEBAGIAN';
    else $status = 'DITERIMA';

    $no = generateNumber(db(), 'penerimaan', 'nomor_penerimaan', 'PRC');
    q("INSERT INTO penerimaan (nomor_penerimaan,tanggal,rencana_id,kebutuhan_id,nama_barang,vendor,no_faktur,tgl_faktur,
        jumlah_diterima,jumlah_diminta,harga_satuan,total_nilai,ck_jumlah_sesuai,ck_spesifikasi_sesuai,ck_kondisi_baik,ck_dokumen_lengkap,status,diterima_oleh,keterangan)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$no,$tanggal,$rencanaId,$kebutId,$nama,$vendor,$faktur,$tglFaktur,$jmlDiterima,$jmlDiminta,$harga,$jmlDiterima*$harga,
       $ck_jumlah_sesuai,$ck_spesifikasi_sesuai,$ck_kondisi_baik,$ck_dokumen_lengkap,$status,authId(),$ket]);
    $pid = (int)db()->lastInsertId();

    // update status dokumen asal
    if ($status !== 'DITOLAK') {
        if ($kebutId) q("UPDATE kebutuhan SET status='DITERIMA' WHERE id=? AND status IN ('DALAM PROSES PENGADAAN','MASUK RENCANA PENGADAAN','DISETUJUI')", [$kebutId]);
        if ($rencanaId) {
            // rencana dianggap selesai bila semua item sudah ada penerimaannya
            $need = qOne("SELECT COUNT(*) c FROM rencana_item WHERE rencana_id=?", [$rencanaId])['c'] ?? 0;
            $done = qOne("SELECT COUNT(DISTINCT nama_barang) c FROM penerimaan WHERE rencana_id=?", [$rencanaId])['c'] ?? 0;
            if ($need && $done >= $need) q("UPDATE rencana_pengadaan SET status='DITERIMA' WHERE id=? AND status='DALAM PROSES PENGADAAN'", [$rencanaId]);
        }
    }
    logActivity('Penerimaan', $status, $no);
    flash("Penerimaan $no dicatat dengan status $status.");
    go('modules/penerimaan.php?id=' . $pid);
}

/* ---------- detail ---------- */
if ($id > 0):
$r = qOne("SELECT * FROM penerimaan WHERE id=?", [$id]);
if (!$r) { flash('Data tidak ditemukan.','error'); go('modules/penerimaan.php'); }
?>
<div class="page-head">
  <a class="btn btn-ghost" href="<?= u('modules/penerimaan.php') ?>"><?= iconSvg('back') ?> Kembali</a>
  <h1><?= e($r['nomor_penerimaan']) ?> <?= badge($r['status']) ?></h1>
  <a class="btn btn-sm btn-outline" target="_blank" href="<?= u('modules/penerimaan_pdf.php?id='.$id) ?>"><?= iconSvg('file') ?> PDF Berita Acara</a>
</div>
<div class="grid-2">
  <div class="card"><div class="card-head"><h3>Data Penerimaan</h3></div><div class="card-body">
    <table class="kv">
      <tr><th>Tanggal</th><td><?= tgl($r['tanggal']) ?></td></tr>
      <tr><th>Barang/Jasa</th><td><b><?= e($r['nama_barang']) ?></b></td></tr>
      <tr><th>Vendor</th><td><?= e($r['vendor'] ?: '-') ?></td></tr>
      <tr><th>No. Faktur</th><td><?= e($r['no_faktur'] ?: '-') ?> <?= $r['tgl_faktur'] ? '('.tgl($r['tgl_faktur']).')' : '' ?></td></tr>
      <tr><th>Jumlah Diminta</th><td><?= (int)$r['jumlah_diminta'] ?: '-' ?></td></tr>
      <tr><th>Jumlah Diterima</th><td><b><?= (int)$r['jumlah_diterima'] ?></b></td></tr>
      <tr><th>Harga Satuan</th><td><?= rupiah($r['harga_satuan']) ?></td></tr>
      <tr><th>Total Nilai</th><td><b><?= rupiah($r['total_nilai']) ?></b></td></tr>
      <tr><th>Dari Rencana</th><td><?= $r['rencana_id'] ? '<a class="link" href="'.u('modules/rencana.php?id='.$r['rencana_id']).'">'.e(scalar("SELECT nomor_rencana FROM rencana_pengadaan WHERE id=?",[$r['rencana_id']]) ?: '-').'</a>' : '-' ?></td></tr>
      <tr><th>Dari Kebutuhan</th><td><?= $r['kebutuhan_id'] ? '<a class="link" href="'.u('modules/kebutuhan.php?id='.$r['kebutuhan_id']).'">'.e(scalar("SELECT nomor_kebutuhan FROM kebutuhan WHERE id=?",[$r['kebutuhan_id']]) ?: '-').'</a>' : '-' ?></td></tr>
      <tr><th>Diterima Oleh</th><td><?= e(userName($r['diterima_oleh'])) ?></td></tr>
      <tr><th>Keterangan</th><td><?= nl2br(e($r['keterangan'] ?: '-')) ?></td></tr>
    </table>
  </div></div>
  <div class="card"><div class="card-head"><h3>Checklist Penerimaan</h3></div><div class="card-body">
    <?php
    $checks = [
      ['ck_jumlah_sesuai','Jumlah sesuai'],
      ['ck_spesifikasi_sesuai','Spesifikasi sesuai'],
      ['ck_kondisi_baik','Kondisi baik'],
      ['ck_dokumen_lengkap','Dokumen lengkap'],
    ];
    foreach ($checks as $k=>$lbl): $ok=(int)$r[$k]===1; ?>
      <div class="check-row <?= $ok?'ok':'bad' ?>">
        <span class="chk-box"><?= $ok?'✓':'✗' ?></span> <?= e($lbl) ?>
      </div>
    <?php endforeach; ?>
    <div class="mt small muted">Keputusan akhir: <b><?= e($r['status']) ?></b></div>
    <?php if ($r['status']!=='DIHAPUS'): ?>
    <div class="mt">
      <a class="btn btn-sm btn-primary" href="<?= u('modules/aset.php?aksi=baru&rc='.$r['id']) ?>"><?= iconSvg('box') ?> Catatkan ke Inventaris</a>
    </div>
    <?php endif; ?>
  </div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- form baru ---------- */
if ($action === 'baru'):
$rencanas = qAll("SELECT id,nomor_rencana,judul FROM rencana_pengadaan WHERE status IN ('DALAM PROSES PENGADAAN','DISETUJUI') ORDER BY id DESC");
$kebutuhans = qAll("SELECT id,nomor_kebutuhan,judul FROM kebutuhan WHERE status IN ('DALAM PROSES PENGADAAN','MASUK RENCANA PENGADAAN') ORDER BY id DESC");
?>
<div class="page-head"><h1>Catat Penerimaan Barang/Jasa</h1></div>
<form method="post" action="<?= u('modules/penerimaan.php') ?>">
<?= csrfField() ?><input type="hidden" name="aksi" value="simpan">
<div class="grid-2">
  <div class="card"><div class="card-head"><h3>Data Pengadaan</h3></div><div class="card-body">
    <div class="form-grid">
      <label class="fld">Tanggal<input class="input" type="date" name="tanggal" value="<?= date('Y-m-d') ?>"></label>
      <label class="fld">Dari Rencana Pengadaan
        <select class="input" name="rencana_id"><option value="">— langsung/tanpa rencana —</option>
        <?php foreach ($rencanas as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['nomor_rencana'].' — '.$x['judul']) ?></option><?php endforeach; ?></select></label>
      <label class="fld">Dari Kebutuhan
        <select class="input" name="kebutuhan_id"><option value="">—</option>
        <?php foreach ($kebutuhans as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['nomor_kebutuhan'].' — '.$x['judul']) ?></option><?php endforeach; ?></select></label>
      <label class="fld span2">Nama Barang/Jasa *<input class="input" name="nama_barang" required></label>
      <label class="fld">Vendor<input class="input" name="vendor"></label>
      <label class="fld">No. Faktur<input class="input" name="no_faktur"></label>
      <label class="fld">Tanggal Faktur<input class="input" type="date" name="tgl_faktur"></label>
      <label class="fld">Jumlah Diminta<input class="input" type="number" min="0" name="jumlah_diminta" value="0"></label>
      <label class="fld">Jumlah Diterima *<input class="input" type="number" min="1" name="jumlah_diterima" required value="1"></label>
      <label class="fld">Harga Satuan (Rp)<input class="input" type="number" min="0" step="100" name="harga_satuan" value="0"></label>
      <label class="fld span2">Keterangan<textarea class="input" name="keterangan" rows="2"></textarea></label>
    </div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Checklist Penerimaan</h3></div><div class="card-body">
    <label class="check-line"><input type="checkbox" name="ck_jumlah_sesuai" checked> Jumlah sesuai</label>
    <label class="check-line"><input type="checkbox" name="ck_spesifikasi_sesuai" checked> Spesifikasi sesuai</label>
    <label class="check-line"><input type="checkbox" name="ck_kondisi_baik" checked> Kondisi baik</label>
    <label class="check-line"><input type="checkbox" name="ck_dokumen_lengkap" checked> Dokumen lengkap</label>
    <hr class="sep">
    <p class="small muted">Jika ada checklist yang tidak tercentum / jumlah kurang → otomatis <b>DITERIMA SEBAGIAN</b>.</p>
    <div class="btn-group mt">
      <button class="btn btn-success" name="keputusan" value="terima"><?= iconSvg('check') ?> Simpan Penerimaan</button>
      <button class="btn btn-danger" name="keputusan" value="tolak" onclick="return confirm('Tolak seluruh barang ini?')">Tolak Barang</button>
    </div>
  </div></div>
</div>
<div class="mt"><a class="btn btn-ghost" href="<?= u('modules/penerimaan.php') ?>">Batal</a></div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* ---------- list ---------- */
$fStatus = $_GET['status'] ?? '';
$sql = "SELECT p.* FROM penerimaan p"; $pr = [];
if ($fStatus) { $sql .= " WHERE p.status=?"; $pr[] = $fStatus; }
$sql .= " ORDER BY p.id DESC LIMIT 300";
$rows = qAll($sql, $pr);
?>
<div class="page-head">
  <h1>Penerimaan Barang/Jasa</h1>
  <a class="btn btn-primary" href="<?= u('modules/penerimaan.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Catat Penerimaan</a>
</div>
<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <select class="input" name="status" onchange="this.form.submit()">
      <option value="">Semua Status</option>
      <?php foreach (['DITERIMA','DITERIMA SEBAGIAN','DITOLAK'] as $s): ?><option <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select>
  </form>
</div></div>
<div class="card"><div class="card-body p0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Tanggal</th><th>Barang/Jasa</th><th>Vendor</th><th class="num">Diterima</th><th class="num">Total Nilai</th><th>Checklist</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">Belum ada data penerimaan.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): $okCnt = (int)$x['ck_jumlah_sesuai']+(int)$x['ck_spesifikasi_sesuai']+(int)$x['ck_kondisi_baik']+(int)$x['ck_dokumen_lengkap']; ?>
  <tr>
    <td><a class="link" href="<?= u('modules/penerimaan.php?id='.$x['id']) ?>"><?= e($x['nomor_penerimaan']) ?></a></td>
    <td><?= tgl($x['tanggal']) ?></td>
    <td><?= e($x['nama_barang']) ?></td>
    <td><?= e($x['vendor'] ?: '-') ?></td>
    <td class="num"><?= (int)$x['jumlah_diterima'] ?><?= $x['jumlah_diminta']>0 ? ' / '.(int)$x['jumlah_diminta'] : '' ?></td>
    <td class="num"><?= rupiah($x['total_nilai']) ?></td>
    <td><span class="mini-progress"><span style="width:<?= $okCnt*25 ?>%"></span></span> <small><?= $okCnt ?>/4</small></td>
    <td><?= badge($x['status']) ?></td>
    <td class="ta-r"><a class="btn btn-sm btn-ghost" href="<?= u('modules/penerimaan.php?id='.$x['id']) ?>">Detail</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
