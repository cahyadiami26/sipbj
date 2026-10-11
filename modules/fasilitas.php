<?php
/** Fasilitas Kantor — tiket masalah: Laporan > Verifikasi > Penugasan > Perbaikan > Pemeriksaan > Selesai */
$pageTitle = 'Fasilitas Kantor';
$activeNav = 'fasilitas';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

/* ================= AKSI ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'lapor') { /* semua pegawai boleh lapor */
        $lokId  = (int)($_POST['lokasi_id'] ?? 0) ?: null;
        $lokTxt = trim($_POST['lokasi_text'] ?? '');
        $jenis  = $_POST['jenis'] ?? 'PERALATAN UMUM';
        $masalah= trim($_POST['masalah'] ?? '');
        $prio   = $_POST['prioritas'] ?? 'NORMAL';
        if ($masalah === '') { flash('Jelaskan masalah fasilitas.', 'error'); go('modules/fasilitas.php#formLapor'); }
        $no = generateNumber(db(), 'fasilitas_tiket', 'nomor_tiket', 'FK');
        q("INSERT INTO fasilitas_tiket (nomor_tiket,lokasi_id,lokasi_text,jenis,masalah,foto,prioritas,pelapor,tanggal_lapor,status)
           VALUES (?,?,?,?,?,?,?,?,?,'LAPORAN BARU')",
          [$no, $lokId, $lokTxt ?: null, $jenis, $masalah, null, $prio, authId(), date('Y-m-d')]);
        $tid = (int)db()->lastInsertId();
        if (!empty($_FILES['foto']['name'])) {
            $f = uploadFile($_FILES['foto'], UPLOAD_FOTO_PATH);
            if ($f) q("UPDATE fasilitas_tiket SET foto=? WHERE id=?", [$f, $tid]);
        }
        logActivity('Fasilitas', "Buat tiket $no", $jenis);
        flash("Tiket $no dibuat. Menunggu verifikasi Bagian Umum.");
        go('modules/fasilitas.php?id=' . $tid);
    }

    if ($aksi === 'verifikasi' && isPengadaan()) {
        $tid = (int)($_POST['id'] ?? 0);
        $t = qOne("SELECT * FROM fasilitas_tiket WHERE id=?", [$tid]);
        if ($t && $t['status'] === 'LAPORAN BARU') {
            $valid = ($_POST['hasil'] ?? 'terima') === 'terima';
            q("UPDATE fasilitas_tiket SET status=?, verifikasi_oleh=?, verifikasi_tgl=NOW() WHERE id=?",
              [$valid ? 'VERIFIKASI' : 'DITOLAK', authId(), $tid]);
            logActivity('Fasilitas', ($valid ? 'Verifikasi ' : 'Tolak ') . $t['nomor_tiket'], null);
            flash($valid ? 'Tiket diverifikasi. Lanjutkan ke penugasan.' : 'Tiket ditolak.');
        }
        go('modules/fasilitas.php?id=' . $tid);
    }

    if ($aksi === 'tugas' && isPengadaan()) {
        $tid = (int)($_POST['id'] ?? 0);
        $kepada = trim($_POST['ditugaskan_ke'] ?? '');
        $t = qOne("SELECT * FROM fasilitas_tiket WHERE id=?", [$tid]);
        if ($t && in_array($t['status'], ['VERIFIKASI', 'PENUGASAN'], true) && $kepada !== '') {
            q("UPDATE fasilitas_tiket SET status='PENUGASAN', ditugaskan_ke=?, penugasan_tgl=NOW() WHERE id=?", [$kepada, $tid]);
            logActivity('Fasilitas', 'Tugaskan ' . $t['nomor_tiket'], $kepada);
            flash('Tiket ditugaskan kepada ' . $kepada . '.');
        }
        go('modules/fasilitas.php?id=' . $tid);
    }

    if ($aksi === 'perbaikan' && isPengadaan()) {
        $tid = (int)($_POST['id'] ?? 0);
        $t = qOne("SELECT * FROM fasilitas_tiket WHERE id=?", [$tid]);
        if ($t && in_array($t['status'], ['PENUGASAN', 'PERBAIKAN'], true)) {
            q("UPDATE fasilitas_tiket SET status='PERBAIKAN', perbaikan_catatan=?, biaya=?, vendor=? WHERE id=?",
              [trim($_POST['catatan'] ?? '') ?: null, (float)($_POST['biaya'] ?? 0), trim($_POST['vendor'] ?? '') ?: null, $tid]);
            logActivity('Fasilitas', 'Perbaikan ' . $t['nomor_tiket'], null);
            flash('Progres perbaikan dicatat. Lanjutkan ke pemeriksaan.');
        }
        go('modules/fasilitas.php?id=' . $tid);
    }

    if ($aksi === 'selesai' && isPengadaan()) {
        $tid = (int)($_POST['id'] ?? 0);
        $t = qOne("SELECT * FROM fasilitas_tiket WHERE id=?", [$tid]);
        if ($t && in_array($t['status'], ['PERBAIKAN', 'PEMERIKSAAN'], true)) {
            // jika ada aset terkait & selesai -> perbaiki kondisi aset
            q("UPDATE fasilitas_tiket SET status='SELESAI', pemeriksaan_catatan=?, selesai_tgl=NOW() WHERE id=?",
              [trim($_POST['hasil_pemeriksaan'] ?? '') ?: null, $tid]);
            logActivity('Fasilitas', 'Selesaikan ' . $t['nomor_tiket'], null);
            flash('Tiket diselesaikan.');
        }
        go('modules/fasilitas.php?id=' . $tid);
    }
}

/* ================= DATA ================= */
$id = (int)($_GET['id'] ?? 0);
$detail = $id ? qOne("SELECT t.*, l.nama AS lokasi_nama FROM fasilitas_tiket t LEFT JOIN lokasi l ON l.id=t.lokasi_id WHERE t.id=?", [$id]) : null;
if ($id && !$detail) { flash('Tiket tidak ditemukan.', 'error'); go('modules/fasilitas.php'); }

$statusF = $_GET['status'] ?? '';
$where = ['1=1']; $pr = [];
if ($statusF !== '') { $where[] = 'status=?'; $pr[] = $statusF; }
$list = qAll("SELECT t.*, l.nama AS lokasi_nama FROM fasilitas_tiket t LEFT JOIN lokasi l ON l.id=t.lokasi_id WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(t.status,'LAPORAN BARU','VERIFIKASI','PENUGASAN','PERBAIKAN','PEMERIKSAAN','SELESAI','DITOLAK'), t.id DESC LIMIT 300", $pr);

try { $lok = qAll("SELECT id,nama FROM lokasi WHERE is_active=1 ORDER BY nama"); } catch (Throwable $e) { $lok = []; }
$cntOpen = (int)scalar("SELECT COUNT(*) FROM fasilitas_tiket WHERE status NOT IN ('SELESAI','DITOLAK')");
$cntDone = (int)scalar("SELECT COUNT(*) FROM fasilitas_tiket WHERE status='SELESAI'");

const FK_JENIS = ['AC','MEJA','KURSI','LAMPU','TOILET','RUANGAN','INSTALASI LISTRIK','AIR','PERALATAN UMUM','FASILITAS LAINNYA'];
const FK_FLOW  = ['LAPORAN BARU' => 'Laporan', 'VERIFIKASI' => 'Verifikasi', 'PENUGASAN' => 'Penugasan', 'PERBAIKAN' => 'Perbaikan', 'PEMERIKSAAN' => 'Pemeriksaan', 'SELESAI' => 'Selesai'];
?>
<?php if ($detail): $idxNow = array_search($detail['status'], array_keys(FK_FLOW)); ?>
<div class="page-head"><div>
  <a class="btn btn-sm btn-ghost" href="fasilitas.php"><?= iconSvg('back') ?> Kembali</a>
  <h1><?= iconSvg('lamp') ?> <?= e($detail['nomor_tiket']) ?></h1>
  <p class="muted"><?= e($detail['jenis']) ?> • <?= e($detail['lokasi_nama'] ?: $detail['lokasi_text'] ?: 'Lokasi tidak disebutkan') ?> • Dilaporkan oleh <b><?= e(userName($detail['pelapor'])) ?></b> pada <?= tglIndo($detail['tanggal_lapor']) ?></p>
</div><div><?= badge($detail['status']) ?> <span class="badge badge-<?= $detail['prioritas']==='SEGERA'?'red':($detail['prioritas']==='TINGGI'?'amber':'slate') ?>">Prioritas <?= e($detail['prioritas']) ?></span></div></div>

<div class="card"><div class="card-body">
  <div class="steps">
    <?php foreach (FK_FLOW as $st => $lbl): ?>
      <div class="step <?= $idxNow !== false && $detail['status'] !== 'DITOLAK' && array_search($st, array_keys(FK_FLOW)) < $idxNow ? 'done' : (array_search($st, array_keys(FK_FLOW)) == $idxNow ? 'active' : '') ?>">
        <div class="dot"><?= array_search($st, array_keys(FK_FLOW)) + 1 ?></div><span><?= e($lbl) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if ($detail['status'] === 'DITOLAK'): ?><div class="step fail"><div class="dot">✕</div><span>Ditolak</span></div><?php endif; ?>
  </div>
</div></div>

<div class="grid-2" style="grid-template-columns:1fr 360px;align-items:start">
  <div>
    <div class="card">
      <div class="card-head"><h3>Laporan Masalah</h3></div>
      <div class="card-body">
        <p><?= nl2br(e($detail['masalah'])) ?></p>
        <?php if ($detail['foto']): ?>
          <img class="thumb-lg" src="<?= u('uploads/foto/' . rawurlencode($detail['foto'])) ?>" alt="foto" style="max-width:340px;border-radius:10px;margin-top:8px">
        <?php endif; ?>
      </div>
    </div>

    <?php if ($detail['status'] === 'LAPORAN BARU' && isPengadaan()): ?>
    <div class="card"><div class="card-head"><h3>Verifikasi Tiket</h3></div><div class="card-body">
      <form method="post" class="inline-form">
        <?= csrfField() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <button class="btn btn-primary" name="hasil" value="terima"><?= iconSvg('check') ?> Terima &amp; Verifikasi</button>
        <button class="btn btn-danger-x" name="hasil" value="tolak" onclick="return confirm('Tolak tiket ini?')">Tolak</button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if (in_array($detail['status'], ['VERIFIKASI','PENUGASAN']) && isPengadaan()): ?>
    <div class="card"><div class="card-head"><h3>Penugasan</h3></div><div class="card-body">
      <form method="post" class="inline-form">
        <?= csrfField() ?><input type="hidden" name="aksi" value="tugas"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <input type="text" name="ditugaskan_ke" required placeholder="Petugas / teknisi / vendor…" value="<?= e($detail['ditugaskan_ke'] ?? '') ?>" style="width:300px">
        <button class="btn btn-primary"><?= iconSvg('check') ?> <?= $detail['status'] === 'PENUGASAN' ? 'Perbarui' : 'Tugaskan' ?></button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if (in_array($detail['status'], ['PENUGASAN','PERBAIKAN']) && isPengadaan()): ?>
    <div class="card"><div class="card-head"><h3>Catat Perbaikan</h3></div><div class="card-body">
      <form method="post" class="form-grid cols-3">
        <?= csrfField() ?><input type="hidden" name="aksi" value="perbaikan"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <label>Vendor <input type="text" name="vendor" maxlength="150" value="<?= e($detail['vendor'] ?? '') ?>"></label>
        <label>Biaya (Rp) <input type="number" name="biaya" min="0" step="1000" value="<?= e($detail['biaya'] ?? '0') ?>"></label>
        <label style="grid-column:span 2">Catatan Tindakan <input type="text" name="catatan" maxlength="500" value="<?= e($detail['perbaikan_catatan'] ?? '') ?>"></label>
        <div class="btn-row" style="grid-column:1/-1"><button class="btn btn-primary"><?= iconSvg('wrench') ?> Simpan Progres Perbaikan</button></div>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if (in_array($detail['status'], ['PERBAIKAN','PEMERIKSAAN']) && isPengadaan()): ?>
    <div class="card"><div class="card-head"><h3>Pemeriksaan Akhir &amp; Selesai</h3></div><div class="card-body">
      <form method="post" class="inline-form">
        <?= csrfField() ?><input type="hidden" name="aksi" value="selesai"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <input type="text" name="hasil_pemeriksaan" placeholder="Hasil pemeriksaan: berfungsi normal…" style="width:340px">
        <button class="btn btn-primary"><?= iconSvg('check') ?> Tandai SELESAI</button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="card"><div class="card-head"><h3>Informasi &amp; Riwayat</h3></div>
    <div class="card-body kv-grid">
      <div class="kv"><span>Jenis</span><b><?= e($detail['jenis']) ?></b></div>
      <div class="kv"><span>Lokasi</span><b><?= e($detail['lokasi_nama'] ?: $detail['lokasi_text'] ?: '-') ?></b></div>
      <div class="kv"><span>Ditugaskan ke</span><b><?= e($detail['ditugaskan_ke'] ?: '-') ?></b></div>
      <div class="kv"><span>Vendor</span><b><?= e($detail['vendor'] ?: '-') ?></b></div>
      <div class="kv"><span>Biaya</span><b><?= rupiah($detail['biaya']) ?></b></div>
      <div class="kv"><span>Selesai</span><b><?= $detail['selesai_tgl'] ? tgl($detail['selesai_tgl'], 'd/m/Y H:i') : '-' ?></b></div>
    </div>
    <div class="card-body timeline">
      <div class="tl-item"><b>Lapor</b><div class="muted small"><?= e(userName($detail['pelapor'])) ?> • <?= tgl($detail['tanggal_lapor'],'d/m/Y') ?></div></div>
      <?php if ($detail['verifikasi_tgl']): ?><div class="tl-item"><b>Verifikasi</b><div class="muted small"><?= e(userName($detail['verifikasi_oleh'])) ?> • <?= tgl($detail['verifikasi_tgl'],'d/m/Y H:i') ?></div></div><?php endif; ?>
      <?php if ($detail['penugasan_tgl']): ?><div class="tl-item"><b>Penugasan → <?= e($detail['ditugaskan_ke']) ?></b><div class="muted small"><?= tgl($detail['penugasan_tgl'],'d/m/Y H:i') ?></div></div><?php endif; ?>
      <?php if ($detail['perbaikan_catatan']): ?><div class="tl-item"><b>Perbaikan</b><div class="muted small"><?= e($detail['perbaikan_catatan']) ?></div></div><?php endif; ?>
      <?php if ($detail['selesai_tgl']): ?><div class="tl-item ok"><b>Selesai</b><div class="muted small"><?= e($detail['pemeriksaan_catatan'] ?: '') ?> • <?= tgl($detail['selesai_tgl'],'d/m/Y H:i') ?></div></div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<!-- ============ LIST ============ -->
<div class="page-head">
  <div><h1><?= iconSvg('lamp') ?> Fasilitas Kantor</h1>
    <p class="muted">Laporkan gangguan fasilitas (AC, lampu, toilet, instalasi, dll.) tanpa harus datang ke Bagian Umum.</p></div>
  <button class="btn btn-primary" onclick="document.getElementById('formLapor').scrollIntoView(true)"><?= iconSvg('plus') ?> Buat Laporan</button>
</div>

<div class="stats">
  <div class="stat"><div class="s-ic amber"><?= iconSvg('alert') ?></div><div><div class="s-val"><?= $cntOpen ?></div><div class="s-lbl">Tiket Aktif</div></div></div>
  <div class="stat"><div class="s-ic green"><?= iconSvg('check') ?></div><div><div class="s-val"><?= $cntDone ?></div><div class="s-lbl">Selesai</div></div></div>
  <div class="stat"><div class="s-ic teal"><?= iconSvg('file') ?></div><div><div class="s-val"><?= count($list) ?></div><div class="s-lbl">Total (filter)</div></div></div>
</div>

<div class="card" id="formLapor">
  <div class="card-head"><h3>Laporan Fasilitas Bermasalah</h3></div>
  <form method="post" enctype="multipart/form-data" class="card-body form-grid cols-3">
    <?= csrfField() ?><input type="hidden" name="aksi" value="lapor">
    <label>Lokasi (dari data)
      <select name="lokasi_id"><option value="0">— Pilih Lokasi —</option>
        <?php foreach ($lok as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['nama']) ?> (<?= e($l['tipe']) ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <label>atau Tulis Manual <input type="text" name="lokasi_text" maxlength="150" placeholder="cth: Ruang Rapat Lt.2"></label>
    <label>Jenis Fasilitas
      <select name="jenis"><?php foreach (FK_JENIS as $j): ?><option><?= $j ?></option><?php endforeach; ?></select>
    </label>
    <label>Prioritas
      <select name="prioritas"><?php foreach (['RENDAH','NORMAL','TINGGI','SEGERA'] as $pp): ?><option <?= $pp==='NORMAL'?'selected':'' ?>><?= $pp ?></option><?php endforeach; ?></select>
    </label>
    <label style="grid-column:span 2">Foto (opsional) <input type="file" name="foto" accept="image/*"></label>
    <label style="grid-column:1/-1">Masalah * <textarea name="masalah" rows="3" required placeholder="cth: AC ruang rapat tidak dingin sejak Senin…"></textarea></label>
    <div class="btn-row" style="grid-column:1/-1"><button class="btn btn-primary"><?= iconSvg('check') ?> Kirim Laporan</button></div>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h3>Daftar Tiket (<?= count($list) ?>)</h3>
    <form method="get" class="filter-bar">
      <select name="status"><option value="">Semua Status</option>
        <?php foreach (array_merge(array_keys(FK_FLOW), ['DITOLAK']) as $s): ?><option <?= $statusF===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Filter</button>
      <a class="btn btn-sm btn-ghost" href="fasilitas.php">Reset</a>
    </form>
  </div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>No. Tiket</th><th>Tanggal</th><th>Jenis</th><th>Lokasi</th><th>Masalah</th><th>Pelapor</th><th>Prioritas</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="9" class="empty">Belum ada tiket.</td></tr><?php endif;
    foreach ($list as $r): ?>
      <tr>
        <td class="mono small"><b><?= e($r['nomor_tiket']) ?></b></td>
        <td><?= tgl($r['tanggal_lapor']) ?></td>
        <td class="small"><?= e($r['jenis']) ?></td>
        <td class="small"><?= e($r['lokasi_nama'] ?: $r['lokasi_text'] ?: '-') ?></td>
        <td class="small"><?= e(mb_strimwidth((string)$r['masalah'], 0, 50, '…')) ?></td>
        <td class="small"><?= e(userName($r['pelapor'])) ?></td>
        <td><span class="badge badge-<?= $r['prioritas']==='SEGERA'?'red':($r['prioritas']==='TINGGI'?'amber':'slate') ?>"><?= e($r['prioritas']) ?></span></td>
        <td><?= badge($r['status']) ?></td>
        <td><a class="btn btn-sm btn-outline" href="fasilitas.php?id=<?= $r['id'] ?>"><?= iconSvg('eye') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
