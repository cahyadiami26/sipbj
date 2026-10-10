<?php
/** Batch Print QR Code Aset — pilih aset, generate file QR (library online), cetak label massal */
$pageTitle = 'QR Code & Cetak Massal';
$activeNav = 'qr';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin','pengadaan']);

/* ---------- aksi generate ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate') {
    verifyCsrf();
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if (!$ids) { flash('Pilih minimal satu aset.','error'); go('modules/qr_batch.php'); }
    $made = 0;
    foreach ($ids as $aid) {
        $a = qOne("SELECT * FROM aset WHERE id=?", [$aid]);
        if (!$a) continue;
        $fn = 'qr_' . preg_replace('/[^A-Za-z0-9_-]/','_',$a['kode_aset']) . '.png';
        $qr = generateQrImage($a['kode_aset'], $fn);
        q("UPDATE aset SET qr_file=? WHERE id=?", [$qr, $aid]);
        $made++;
    }
    logActivity('QR Batch', "Generate $made QR", null);
    flash("$made QR code dibuat/diperbarui.");
    $qs = implode(',', $ids);
    go('modules/qr_batch.php?print=1&ids[]=' . implode('&ids[]=', $ids));
}

/* ---------- mode print ---------- */
$printIds = array_map('intval', $_GET['ids'] ?? []);
if (($_GET['print'] ?? '') === '1' && $printIds):
$rows = qAll("SELECT * FROM aset WHERE id IN (" . implode(',', array_fill(0,count($printIds),'?')) . ") ORDER BY FIELD(id," . implode(',', $printIds) . ")", $printIds);
?>
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
<title>Cetak Label QR — <?= count($rows) ?> aset</title>
<link rel="stylesheet" href="<?= u('assets/css/style.css') ?>?v=<?= APP_VERSION ?>">
<style>
 body{background:#fff;padding:16px}
 .no-print{margin-bottom:14px;display:flex;gap:10px;align-items:center}
 @media print{ .no-print{display:none!important} .label-grid{page-break-inside:auto} body{padding:0} }
</style></head><body>
<div class="no-print">
  <button class="btn btn-primary" onclick="window.print()"><?= iconSvg('print') ?> Cetak Sekarang</button>
  <a class="btn btn-ghost" href="<?= u('modules/qr_batch.php') ?>">← Kembali ke pemilihan</a>
  <span class="muted small"><?= count($rows) ?> label • ukuran ± 5×7 cm per label</span>
</div>
<div class="label-grid">
<?php foreach ($rows as $a): 
  $qf = $a['qr_file'] ? u('uploads/qr/'.rawurlencode($a['qr_file'])) : ''; ?>
  <div class="qr-label">
    <?php if ($qf): ?><img src="<?= e($qf) ?>" alt="qr"><?php else: ?><div class="qr-missing">QR belum dibuat</div><?php endif; ?>
    <div class="kode"><?= e($a['kode_aset']) ?></div>
    <div class="nama"><?= e($a['nama']) ?></div>
    <div class="sub"><?= e($a['unit']) ?><?= $a['merk_type'] ? ' • '.e($a['merk_type']) : '' ?></div>
  </div>
<?php endforeach; ?>
</div>
<script>window.addEventListener('load',()=>{ /* auto-print opsional: window.print(); */ });</script>
</body></html>
<?php exit; endif;

/* ---------- UI pemilihan ---------- */
$s = trim($_GET['cari'] ?? '');
$fUnit = $_GET['unit'] ?? '';
$sql = "SELECT a.*, l.nama lokasi_nama FROM aset a LEFT JOIN lokasi l ON l.id=a.lokasi_id WHERE a.status NOT IN ('DIHAPUS')";
$pr = [];
$conj = " AND";
if ($s) { $sql .= " AND (a.kode_aset LIKE ? OR a.nama LIKE ?)"; $pr[]="%$s%"; $pr[]="%$s%"; }
if ($fUnit) { $sql .= " AND a.unit=?"; $pr[]=$fUnit; }
$sql .= " ORDER BY a.kode_aset LIMIT 500";
$rows = qAll($sql, $pr);
$preselect = array_map('intval', $_GET['ids'] ?? []);
$belumQr = (int)scalar("SELECT COUNT(*) FROM aset WHERE (qr_file IS NULL OR qr_file='') AND status<>'DIHAPUS'");
?>
<div class="page-head">
  <h1>QR Code &amp; Cetak Massal</h1>
  <div class="small muted"><?= $belumQr ?> aset belum memiliki QR tersimpan.</div>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <input class="input grow" name="cari" placeholder="Cari kode/nama aset..." value="<?= e($s) ?>">
    <select class="input" name="unit" onchange="this.form.submit()">
      <option value="">Semua Unit</option>
      <?php foreach (unitOptions() as $x): ?><option <?= $fUnit===$x?'selected':'' ?>><?= e($x) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline"><?= iconSvg('search') ?> Filter</button>
  </form>
</div></div>

<form method="post" action="<?= u('modules/qr_batch.php') ?>" onsubmit="return confirmPrint()">
<?= csrfField() ?><input type="hidden" name="aksi" value="generate">
<div class="card"><div class="card-head">
  <h3>Pilih Aset (<?= count($rows) ?>)</h3>
  <div class="btn-group">
    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(true)">Pilih Semua</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(false)">Kosongkan</button>
    <button class="btn btn-sm btn-primary"><?= iconSvg('qr') ?> Generate QR &amp; Buka Label Cetak</button>
  </div>
</div><div class="card-body p0">
<table class="tbl">
  <thead><tr><th style="width:36px"><input type="checkbox" onclick="toggleAll(this.checked)"></th><th>Kode Aset</th><th>Nama</th><th>Unit</th><th>Lokasi</th><th>QR</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada aset.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td><input type="checkbox" name="ids[]" value="<?= $x['id'] ?>" <?= in_array($x['id'],$preselect)?'checked':'' ?> class="pick"></td>
    <td class="mono"><?= e($x['kode_aset']) ?></td>
    <td><?= e($x['nama']) ?></td>
    <td><?= e($x['unit']) ?></td>
    <td><?= e($x['lokasi_nama'] ?: '-') ?></td>
    <td><?= $x['qr_file'] ? '<span class="badge badge-green">ADA</span>' : '<span class="badge badge-slate">BELUM</span>' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<p class="small muted mt">File QR disimpan otomatis di folder <code>uploads/qr</code>. Gunakan tombol browser pada halaman label untuk mencetak (disarankan kertas stiker 5×7 cm).</p>
</form>

<script>
function toggleAll(v){ document.querySelectorAll('.pick').forEach(c=>c.checked=v); }
function confirmPrint(){
  const n = document.querySelectorAll('.pick:checked').length;
  if(!n){ alert('Pilih minimal satu aset.'); return false; }
  return confirm(n + ' aset akan di-generate QR & dibuka pada mode cetak. Lanjutkan?');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
