<?php
/** Mutasi Aset manual — pencatatan histori (unit/lokasi/pengguna) + update data aset */
$pageTitle = 'Mutasi Aset';
$activeNav = 'mutasi';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin','pengadaan','pimpinan']);

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'simpan') {
    verifyCsrf();
    $asetId = (int)($_POST['aset_id'] ?? 0);
    $a = qOne("SELECT a.*, l.nama ln FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE a.id=?", [$asetId]);
    if (!$a) { flash('Aset tidak ditemukan.','error'); go('modules/mutasi.php?aksi=baru'); }

    $unitBaru   = trim($_POST['unit_sesudah'] ?? '') ?: $a['unit'];
    $lokasiBaru = (int)($_POST['lokasi_sesudah_id'] ?? 0) ?: null;
    $pengguna   = (int)($_POST['pengguna_sesudah_id'] ?? 0) ?: null;
    $alasan     = trim($_POST['alasan'] ?? '');
    $tanggal    = $_POST['tanggal'] ?: date('Y-m-d');

    $lokNama = $lokasiBaru ? (string)scalar("SELECT nama FROM lokasi WHERE id=?", [$lokasiBaru]) : $a['ln'];

    q("INSERT INTO mutasi_aset (aset_id,tanggal,unit_sebelum,lokasi_sebelum,pengguna_sebelum,unit_sesudah,lokasi_sesudah,pengguna_sesudah,alasan,oleh,status)
       VALUES (?,?,?,?,?,?,?,?,?,?, 'SELESAI')",
      [$asetId,$tanggal,$a['unit'],$a['ln'] ?: '-', $a['pengguna_id'] ? userName($a['pengguna_id']) : '-',
       $unitBaru,$lokNama ?: '-',$pengguna ? userName($pengguna) : '-',$alasan,authId()]);

    q("UPDATE aset SET unit=?, lokasi_id=?, pengguna_id=? WHERE id=?", [$unitBaru,$lokasiBaru,$pengguna,$asetId]);
    logActivity('Mutasi Aset','Catat mutasi', $a['kode_aset']);
    flash('Mutasi tercatat dan data aset diperbarui.');
    go('modules/mutasi.php');
}

if ($action === 'baru'):
$aset = qAll("SELECT a.*, l.nama lokasi_nama FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE a.status NOT IN ('DIHAPUS') ORDER BY a.kode_aset LIMIT 500");
$ruang = qAll("SELECT id,nama FROM lokasi WHERE tipe='RUANG' AND is_active=1 ORDER BY nama");
try { $pegawai = ssoDb()->query("SELECT id,name FROM users WHERE is_active=1 ORDER BY name")->fetchAll(); } catch (Throwable $e) { $pegawai=[]; }
?>
<div class="page-head"><h1>Catat Mutasi Aset</h1></div>
<form method="post" action="<?= u('modules/mutasi.php') ?>">
<?= csrfField() ?><input type="hidden" name="aksi" value="simpan">
<div class="card"><div class="card-body"><div class="form-grid">
  <label class="fld span2">Aset *
    <select class="input" name="aset_id" required id="asetSel" onchange="isiAsal()">
      <option value="">— pilih aset —</option>
      <?php foreach ($aset as $x): ?>
      <option value="<?= $x['id'] ?>" data-unit="<?= e($x['unit']) ?>" data-lokasi="<?= e($x['lokasi_nama'] ?: '-') ?>"
        data-pengguna="<?= e($x['pengguna_id'] ? userName($x['pengguna_id']) : '-') ?>"><?= e($x['kode_aset'].' — '.$x['nama']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label class="fld">Tanggal Mutasi<input class="input" type="date" name="tanggal" value="<?= date('Y-m-d') ?>"></label>
  <label class="fld">Unit Sesudah *
    <select class="input" name="unit_sesudah" required><option value="">— pilih —</option>
    <?php foreach (unitOptions() as $un): ?><option><?= e($un) ?></option><?php endforeach; ?></select></label>
  <label class="fld">Lokasi/Ruang Sesudah
    <select class="input" name="lokasi_sesudah_id"><option value="">— tetap/tidak berubah —</option>
    <?php foreach ($ruang as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['nama']) ?></option><?php endforeach; ?></select></label>
  <label class="fld">Pengguna Sesudah
    <select class="input" name="pengguna_sesudah_id"><option value="">—</option>
    <?php foreach ($pegawai as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
  <label class="fld span2">Alasan Mutasi<textarea class="input" name="alasan" rows="2" placeholder="Reorganisasi, efisiensi, permintaan unit, dll."></textarea></label>
</div>
<div class="mt"><button class="btn btn-primary"><?= iconSvg('swap') ?> Simpan Mutasi</button>
<a class="btn btn-ghost" href="<?= u('modules/mutasi.php') ?>">Batal</a></div>
</div></div>
</form>
<script>
function isiAsal(){
  const o=document.getElementById('asetSel').selectedOptions[0];
  const box=document.getElementById('asalInfo');
  if(o && o.value){ box.innerHTML='<b>Kondisi saat ini:</b> Unit '+ (o.dataset.unit||'-') +' · Lokasi '+(o.dataset.lokasi||'-')+' · Pengguna '+(o.dataset.pengguna||'-'); }
  else box.innerHTML='';
}
</script>
<p class="small muted mt" id="asalInfo"></p>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif;

/* list histori */
$sql = "SELECT m.*, a.kode_aset,a.nama aset_nama FROM mutasi_aset m JOIN aset a ON a.id=m.aset_id ORDER BY m.id DESC LIMIT 300";
$rows = qAll($sql);
?>
<div class="page-head">
  <h1>Mutasi Aset</h1>
  <a class="btn btn-primary" href="<?= u('modules/mutasi.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Catat Mutasi</a>
</div>
<div class="card"><div class="card-body p0">
<table class="tbl">
  <thead><tr><th>Tanggal</th><th>Aset</th><th>Dari</th><th>Ke</th><th>Alasan</th><th>Oleh</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada riwayat mutasi.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td><?= tgl($x['tanggal']) ?></td>
    <td class="mono"><a class="link" href="<?= u('modules/aset.php?id='.$x['aset_id']) ?>"><?= e($x['kode_aset']) ?></a><br><small class="muted"><?= e($x['aset_nama']) ?></small></td>
    <td><small><?= e($x['unit_sebelum'] ?: '-') ?><br><?= e($x['lokasi_sebelum'] ?: '-') ?><br><?= e($x['pengguna_sebelum'] ?: '-') ?></small></td>
    <td><small><b><?= e($x['unit_sesudah'] ?: '-') ?></b><br><?= e($x['lokasi_sesudah'] ?: '-') ?><br><?= e($x['pengguna_sesudah'] ?: '-') ?></small></td>
    <td class="muted small"><?= e($x['alasan'] ?: '-') ?></td>
    <td><?= e(userName($x['oleh'])) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
