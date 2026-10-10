<?php
/** Modul Rencana Pengadaan — DRAFT>DIAJUKAN>DISETUJUI (Kadiv Sekretariat/Umum&Keuangan -> DirOps -> Final Finance)>DALAM PROSES>DITERIMA */
$pageTitle = 'Rencana Pengadaan';
$activeNav = 'rencana';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

/* ================= POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'simpan') {
        $isEdit = $id > 0;
        $row = $isEdit ? qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]) : null;
        if ($isEdit && (!$row || $row['status'] !== 'DRAFT')) { flash('Rencana tidak dapat diubah.', 'error'); go('modules/rencana.php'); }

        $judul = trim($_POST['judul'] ?? '');
        $unit  = $_POST['unit_pemohon'] ?? '';
        $prioritas = $_POST['prioritas'] ?? 'SEDANG';
        $tanggal = $_POST['tanggal'] ?: date('Y-m-d');
        $anggaran = (float)($_POST['anggaran'] ?? 0);
        $sumber = trim($_POST['sumber_anggaran'] ?? '');
        $catatan = trim($_POST['catatan'] ?? '');
        $names = $_POST['item_nama'] ?? []; $specs = $_POST['item_spes'] ?? [];
        $sats = $_POST['item_sat'] ?? []; $qtys = $_POST['item_jml'] ?? []; $prices = $_POST['item_hrg'] ?? [];
        $items = [];
        foreach ($names as $i => $nm) {
            if (trim((string)$nm) === '') continue;
            $jml = max(1, (int)($qtys[$i] ?? 1)); $hrg = (float)($prices[$i] ?? 0);
            $items[] = [trim($nm), trim((string)($specs[$i] ?? '')), trim((string)($sats[$i] ?? 'unit')) ?: 'unit', $jml, $hrg, $jml*$hrg];
        }
        if (!$judul || !$unit || !$items) { flash('Judul, unit, dan minimal satu item wajib diisi.', 'error'); go('modules/rencana.php?aksi=' . ($isEdit ? "edit&id=$id" : 'baru')); }
        $total = array_sum(array_column($items, 5));

        if ($isEdit) {
            q("UPDATE rencana_pengadaan SET judul=?,unit_pemohon=?,prioritas=?,tanggal=?,anggaran=?,sumber_anggaran=?,estimasi_total=?,catatan=? WHERE id=?",
              [$judul,$unit,$prioritas,$tanggal,$anggaran,$sumber,$total,$catatan,$id]);
            q("DELETE FROM rencana_item WHERE rencana_id=?", [$id]);
        } else {
            $no = generateNumber(db(), 'rencana_pengadaan', 'nomor_rencana', 'RNP');
            q("INSERT INTO rencana_pengadaan (nomor_rencana,judul,tanggal,unit_pemohon,prioritas,anggaran,estimasi_total,sumber_anggaran,status,diajukan_oleh,catatan)
               VALUES (?,?,?,?,?,?,?,?, 'DRAFT', ?, ?)", [$no,$judul,$tanggal,$unit,$prioritas,$anggaran,$total,$sumber,authId(),$catatan]);
            $id = (int)db()->lastInsertId();
        }
        $st = db()->prepare("INSERT INTO rencana_item (rencana_id,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total) VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) { $st->execute(array_merge([$id], $it)); }
        logActivity('Rencana Pengadaan', $isEdit ? 'Update draft' : 'Buat draft', 'RNP #' . $id);
        flash('Rencana pengadaan tersimpan sebagai DRAFT.');
        go('modules/rencana.php?id=' . $id);
    }

    if ($action === 'ajukan') {
        requireRole(['admin','pengadaan']);
        $row = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DRAFT') { flash('Hanya draft yang dapat diajukan.','error'); go("modules/rencana.php?id=$id"); }
        q("UPDATE rencana_pengadaan SET status='DIAJUKAN', diajukan_oleh=?, diajukan_tgl=NOW() WHERE id=?", [authId(), $id]);
        logActivity('Rencana Pengadaan','Diajukan',$row['nomor_rencana']);
        flash('Rencana diajukan untuk persetujuan.'); go("modules/rencana.php?id=$id");
    }

    if ($action === 'approve_kadiv') {
        $row = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DIAJUKAN') { flash('Status tidak valid.','error'); go("modules/rencana.php?id=$id"); }
        // Approver: Kepala Divisi Kesekretariatan / Keuangan & Umum, admin, atau pengadaan
        if (!isAdmin() && !isPengadaan() && !(authPosition()==='Kepala Divisi' && in_array(authDepartment(), ['Sekretariat','Keuangan & Umum'], true))) {
            flash('Tidak berwenang menyetujui tahap ini.','error'); go("modules/rencana.php?id=$id");
        }
        q("UPDATE rencana_pengadaan SET approve_kadiv_oleh=?, approve_kadiv_catatan=?, approve_kadiv_tgl=NOW() WHERE id=?",
          [authId(), trim($_POST['catatan'] ?? ''), $id]);
        logActivity('Rencana Pengadaan','Disetujui Kepala Divisi',$row['nomor_rencana']);
        flash('Disetujui Kepala Divisi. Lanjut ke Direktur Operasional.'); go("modules/rencana.php?id=$id");
    }

    if ($action === 'approve_dop') {
        $row = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DIAJUKAN' || empty($row['approve_kadiv_oleh'])) { flash('Harus disetujui Kepala Divisi terlebih dahulu.','error'); go("modules/rencana.php?id=$id"); }
        if (!isPimpinan()) { flash('Hanya Direktur Operasional / pimpinan yang dapat menyetujui.','error'); go("modules/rencana.php?id=$id"); }
        if (($_POST['keputusan'] ?? 'setuju') === 'tolak') {
            q("UPDATE rencana_pengadaan SET status='DITOLAK', approve_dop_oleh=?, approve_dop_catatan=?, approve_dop_tgl=NOW() WHERE id=?",
              [authId(), trim($_POST['catatan'] ?? ''), $id]);
            logActivity('Rencana Pengadaan','Ditolak DirOps',$row['nomor_rencana']);
            flash('Rencana ditolak.'); go("modules/rencana.php?id=$id");
        }
        q("UPDATE rencana_pengadaan SET status='DISETUJUI', approve_dop_oleh=?, approve_dop_catatan=?, approve_dop_tgl=NOW() WHERE id=?",
          [authId(), trim($_POST['catatan'] ?? ''), $id]);
        logActivity('Rencana Pengadaan','Disetujui Direktur Operasional',$row['nomor_rencana']);
        flash('Disetujui Direktur Operasional. Finalisasi ke Finance.'); go("modules/rencana.php?id=$id");
    }

    if ($action === 'final_finance') {
        $row = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DISETUJUI') { flash('Status tidak valid.','error'); go("modules/rencana.php?id=$id"); }
        if (!isAdmin() && !(authPosition()==='Kepala Divisi' && authDepartment()==='Keuangan & Umum')) {
            flash('Hanya Kepala Divisi Umum & Keuangan yang mem-finalisasi.','error'); go("modules/rencana.php?id=$id");
        }
        q("UPDATE rencana_pengadaan SET status='DALAM PROSES PENGADAAN', final_finance_oleh=?, final_finance_catatan=?, final_finance_tgl=NOW() WHERE id=?",
          [authId(), trim($_POST['catatan'] ?? ''), $id]);
        logActivity('Rencana Pengadaan','Final finance - dalam proses',$row['nomor_rencana']);
        flash('Rencana difinalisasi dan masuk proses pengadaan.'); go("modules/rencana.php?id=$id");
    }

    if ($action === 'terima') {
        requireRole(['admin','pengadaan']);
        $row = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DALAM PROSES PENGADAAN') { flash('Status tidak valid.','error'); go("modules/rencana.php?id=$id"); }
        q("UPDATE rencana_pengadaan SET status='DITERIMA' WHERE id=?", [$id]);
        logActivity('Rencana Pengadaan','Selesai diterima',$row['nomor_rencana']);
        flash('Seluruh barang/jasa dari rencana ini telah diterima.'); go("modules/rencana.php?id=$id");
    }

    if ($action === 'hapus') {
        $row = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
        if ($row && $row['status'] === 'DRAFT' && (isAdmin() || isPengadaan())) {
            q("DELETE FROM rencana_pengadaan WHERE id=?", [$id]);
            logActivity('Rencana Pengadaan','Hapus draft',$row['nomor_rencana']);
            flash('Draft dihapus.');
        } else flash('Hanya draft yang dapat dihapus.','error');
        go('modules/rencana.php');
    }
}

/* ================= VIEW ================= */
$statuses = ['DRAFT','DIAJUKAN','DISETUJUI','DALAM PROSES PENGADAAN','DITERIMA','DITOLAK'];
$fStatus = $_GET['status'] ?? '';
$fUnit   = $_GET['unit'] ?? '';
$qc = "SELECT r.*, (SELECT COUNT(*) FROM rencana_item ri WHERE ri.rencana_id=r.id) AS jml_item
       FROM rencana_pengadaan r WHERE 1=1"; $pr = [];
if ($fStatus) { $qc .= " AND r.status=?"; $pr[] = $fStatus; }
if ($fUnit)   { $qc .= " AND r.unit_pemohon=?"; $pr[] = $fUnit; }
$qc .= " ORDER BY r.id DESC";

if ($action === 'detail' || $id > 0 && $action !== 'baru' && $action !== 'edit'):
    $r = qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]);
    if (!$r) { flash('Data tidak ditemukan.','error'); go('modules/rencana.php'); }
    $items = qAll("SELECT * FROM rencana_item WHERE rencana_id=? ORDER BY id", [$id]);
    $steps = ['DRAFT','DIAJUKAN','DISETUJUI','DALAM PROSES PENGADAAN','DITERIMA'];
    $curIdx = array_search($r['status'], $steps);
    ?>
    <div class="page-head">
      <a class="btn btn-ghost" href="<?= u('modules/rencana.php') ?>"><?= iconSvg('back') ?> Kembali</a>
      <h1><?= e($r['nomor_rencana']) ?> <?= badge($r['status']) ?></h1>
    </div>

    <?php if ($r['status'] !== 'DITOLAK'): ?>
    <div class="card mb"><div class="card-body">
      <div class="steps"><?php foreach ($steps as $i => $s): $done = $curIdx!==false && $i <= $curIdx; ?>
        <div class="step <?= $done?'done':'' ?>"><span class="dot"><?= $i+1 ?></span><span><?= e($s) ?></span></div>
      <?php endforeach; ?></div>
    </div></div>
    <?php endif; ?>

    <div class="grid-2">
      <div class="card"><div class="card-head"><h3>Informasi Rencana</h3></div><div class="card-body">
        <table class="kv">
          <tr><th>Judul</th><td><?= e($r['judul']) ?></td></tr>
          <tr><th>Tanggal</th><td><?= tgl($r['tanggal']) ?></td></tr>
          <tr><th>Unit Pemohon</th><td><?= e($r['unit_pemohon']) ?></td></tr>
          <tr><th>Prioritas</th><td><?= badge($r['prioritas']) ?></td></tr>
          <tr><th>Estimasi Total</th><td><b><?= rupiah($r['estimasi_total']) ?></b></td></tr>
          <tr><th>Anggaran</th><td><?= rupiah($r['anggaran']) ?></td></tr>
          <tr><th>Sumber Anggaran</th><td><?= e($r['sumber_anggaran'] ?: '-') ?></td></tr>
          <tr><th>Kebutuhan Asal</th><td><?= e($r['kebutuhan_ids'] ?: '-') ?></td></tr>
          <tr><th>Catatan</th><td><?= nl2br(e($r['catatan'] ?: '-')) ?></td></tr>
        </table>
      </div></div>

      <div class="card"><div class="card-head"><h3>Riwayat Persetujuan</h3></div><div class="card-body">
        <div class="timeline">
          <div class="tl-item"><div class="tl-dot b"></div><div><b>Diajukan</b> oleh Bagian Pengadaan<br><small><?= e(userName($r['diajukan_oleh'])) ?> · <?= tgl($r['diajukan_tgl'],'d/m/Y H:i') ?></small></div></div>
          <?php if ($r['approve_kadiv_oleh']): ?>
          <div class="tl-item"><div class="tl-dot g"></div><div><b>Disetujui Kepala Divisi</b> (Sekretariat / Umum &amp; Keuangan)<br><small><?= e(userName($r['approve_kadiv_oleh'])) ?> · <?= tgl($r['approve_kadiv_tgl'],'d/m/Y H:i') ?><?= $r['approve_kadiv_catatan'] ? ' — '.e($r['approve_kadiv_catatan']) : '' ?></small></div></div>
          <?php endif; ?>
          <?php if ($r['approve_dop_oleh']): ?>
          <div class="tl-item"><div class="tl-dot g"></div><div><b>Disetujui Direktur Operasional</b><br><small><?= e(userName($r['approve_dop_oleh'])) ?> · <?= tgl($r['approve_dop_tgl'],'d/m/Y H:i') ?><?= $r['approve_dop_catatan'] ? ' — '.e($r['approve_dop_catatan']) : '' ?></small></div></div>
          <?php endif; ?>
          <?php if ($r['final_finance_oleh']): ?>
          <div class="tl-item"><div class="tl-dot t"></div><div><b>Final Rencana (Finance)</b><br><small><?= e(userName($r['final_finance_oleh'])) ?> · <?= tgl($r['final_finance_tgl'],'d/m/Y H:i') ?><?= $r['final_finance_catatan'] ? ' — '.e($r['final_finance_catatan']) : '' ?></small></div></div>
          <?php endif; ?>
        </div>

        <?php $roleNow = appRole(); if ($r['status']==='DIAJUKAN' && !$r['approve_kadiv_oleh'] && (isAdmin()||isPengadaan()||(authPosition()==='Kepala Divisi'&&in_array(authDepartment(),['Sekretariat','Keuangan & Umum'],true)))): ?>
        <form method="post" class="mt">
          <?= csrfField() ?><input type="hidden" name="aksi" value="approve_kadiv"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="form-row"><input class="input" name="catatan" placeholder="Catatan persetujuan (opsional)"></div>
          <button class="btn btn-primary"><?= iconSvg('check') ?> Setujui (Kepala Divisi)</button>
        </form>
        <?php elseif ($r['status']==='DIAJUKAN' && $r['approve_kadiv_oleh'] && isPimpinan()): ?>
        <form method="post" class="mt">
          <?= csrfField() ?><input type="hidden" name="aksi" value="approve_dop"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="form-row"><input class="input" name="catatan" placeholder="Catatan (opsional)"></div>
          <button class="btn btn-primary" name="keputusan" value="setuju"><?= iconSvg('check') ?> Setujui (DirOps)</button>
          <button class="btn btn-danger" name="keputusan" value="tolak" onclick="return confirm('Tolak rencana ini?')">Tolak</button>
        </form>
        <?php elseif ($r['status']==='DISETUJUI' && (isAdmin()||(authPosition()==='Kepala Divisi'&&authDepartment()==='Keuangan & Umum'))): ?>
        <form method="post" class="mt">
          <?= csrfField() ?><input type="hidden" name="aksi" value="final_finance"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="form-row"><input class="input" name="catatan" placeholder="Catatan finalisasi anggaran"></div>
          <button class="btn btn-primary"><?= iconSvg('check') ?> Finalkan Rencana (Finance)</button>
        </form>
        <?php elseif ($r['status']==='DALAM PROSES PENGADAAN' && isPengadaan()): ?>
        <form method="post" class="mt" onsubmit="return confirm('Tandai seluruh realisasi rencana ini DITERIMA?')">
          <?= csrfField() ?><input type="hidden" name="aksi" value="terima"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-success"><?= iconSvg('check') ?> Tandai Selesai / Diterima</button>
        </form>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="card mt"><div class="card-head"><h3>Item Rencana (<?= count($items) ?>)</h3>
      <?php if (isPengadaan()): ?><a class="btn btn-sm btn-outline" target="_blank" href="<?= u('modules/rencana_pdf.php?id='.$id) ?>"><?= iconSvg('file') ?> PDF</a><?php endif; ?>
    </div><div class="card-body p0">
      <table class="tbl">
        <thead><tr><th>#</th><th>Barang/Jasa</th><th>Spesifikasi</th><th>Satuan</th><th class="num">Jumlah</th><th class="num">Estimasi Harga</th><th class="num">Estimasi Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
          <tr><td><?= $i+1 ?></td><td><b><?= e($it['nama_barang']) ?></b></td><td class="muted"><?= e($it['spesifikasi'] ?: '-') ?></td>
              <td><?= e($it['satuan']) ?></td><td class="num"><?= (int)$it['jumlah'] ?></td>
              <td class="num"><?= rupiah($it['estimasi_harga']) ?></td><td class="num"><b><?= rupiah($it['estimasi_total']) ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="6" class="num"><b>Total Estimasi</b></td><td class="num"><b><?= rupiah($r['estimasi_total']) ?></b></td></tr></tfoot>
      </table>
    </div></div>
    <?php
    require_once __DIR__ . '/../includes/footer.php'; exit;
endif;

/* ---- form baru / edit ---- */
if ($action === 'baru' || $action === 'edit') {
    $r = $action==='edit' ? qOne("SELECT * FROM rencana_pengadaan WHERE id=?", [$id]) : null;
    if ($action==='edit' && (!$r || $r['status']!=='DRAFT')) { flash('Hanya draft yang dapat diedit.','error'); go('modules/rencana.php'); }
    $items = $r ? qAll("SELECT * FROM rencana_item WHERE rencana_id=?", [$r['id']]) : [];
    ?>
    <div class="page-head"><h1><?= $r ? 'Edit Draft Rencana' : 'Rencana Pengadaan Baru' ?></h1></div>
    <form method="post" action="<?= u('modules/rencana.php') ?>">
      <?= csrfField() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $r['id'] ?? 0 ?>">
      <div class="grid-2">
        <div class="card"><div class="card-body">
          <div class="form-grid">
            <label class="fld span2">Judul Rencana<input class="input" name="judul" required value="<?= e($r['judul'] ?? '') ?>"></label>
            <label class="fld">Tanggal<input class="input" type="date" name="tanggal" value="<?= e($r['tanggal'] ?? date('Y-m-d')) ?>"></label>
            <label class="fld">Unit Pemohon
              <select class="input" name="unit_pemohon" required>
                <option value="">— pilih unit —</option>
                <?php foreach (unitOptions() as $un): ?><option <?= ($r['unit_pemohon']??'')===$un?'selected':'' ?>><?= e($un) ?></option><?php endforeach; ?>
              </select></label>
            <label class="fld">Prioritas<select class="input" name="prioritas"><?php foreach (['RENDAH','SEDANG','TINGGI','SEGERA'] as $p): ?><option <?= ($r['prioritas']??'SEDANG')===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?></select></label>
            <label class="fld">Anggaran (Rp)<input class="input" type="number" min="0" step="1000" name="anggaran" value="<?= e($r['anggaran'] ?? '') ?>"></label>
            <label class="fld span2">Sumber Anggaran<input class="input" name="sumber_anggaran" placeholder="APBN Perusahaan / TA 2026" value="<?= e($r['sumber_anggaran'] ?? '') ?>"></label>
            <label class="fld span2">Catatan<textarea class="input" name="catatan" rows="2"><?= e($r['catatan'] ?? '') ?></textarea></label>
          </div>
        </div></div>
        <div class="card"><div class="card-body">
          <h3 class="mb">Item Barang/Jasa</h3>
          <div id="items"></div>
          <button type="button" class="btn btn-sm btn-outline mt" onclick="addItem()">+ Tambah Item</button>
        </div></div>
      </div>
      <div class="mt"><button class="btn btn-primary"><?= iconSvg('check') ?> Simpan Draft</button>
        <a class="btn btn-ghost" href="<?= u('modules/rencana.php') ?>">Batal</a></div>
    </form>
    <script>
    const existing = <?= json_encode($items) ?>;
    function addItem(d){ d=d||{};
      const row=document.createElement('div'); row.className='dyn-row';
      row.innerHTML=`<input class="input" name="item_nama[]" placeholder="Nama barang/jasa *" value="${d.nama_barang||''}">
        <input class="input" name="item_spes[]" placeholder="Spesifikasi" value="${(d.spesifikasi||'').replace(/"/g,'&quot;')}">
        <input class="input" name="item_sat[]" placeholder="Satuan" value="${d.satuan||'unit'}" size="6">
        <input class="input num-in" type="number" name="item_jml[]" min="1" value="${d.jumlah||1}" style="width:80px">
        <input class="input num-in" type="number" name="item_hrg[]" min="0" step="100" value="${d.estimasi_harga||0}" style="width:130px">
        <button type="button" class="btn btn-sm btn-danger-x" onclick="this.parentElement.remove()">×</button>`;
      document.getElementById('items').appendChild(row);
    }
    (existing.length?existing:[null]).forEach(addItem);
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php'; exit;
}

/* ---- list ---- */
$rows = qAll($qc, $pr);
?>
<div class="page-head">
  <h1>Rencana Pengadaan</h1>
  <?php if (isPengadaan()): ?><a class="btn btn-primary" href="<?= u('modules/rencana.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Rencana Baru</a><?php endif; ?>
</div>

<div class="card mb"><div class="card-body">
  <form method="get" class="filter-bar">
    <select class="input" name="status" onchange="this.form.submit()">
      <option value="">Semua Status</option>
      <?php foreach ($statuses as $s): ?><option <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select>
    <select class="input" name="unit" onchange="this.form.submit()">
      <option value="">Semua Unit</option>
      <?php foreach (unitOptions() as $un): ?><option <?= $fUnit===$un?'selected':'' ?>><?= e($un) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm btn-outline">Filter</button></noscript>
  </form>
</div></div>

<div class="card"><div class="card-body p0">
  <table class="tbl">
    <thead><tr><th>Nomor</th><th>Judul</th><th>Unit</th><th>Tanggal</th><th>Prioritas</th><th class="num">Item</th><th class="num">Estimasi Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Belum ada rencana pengadaan.</td></tr><?php endif; ?>
    <?php foreach ($rows as $x): ?>
      <tr>
        <td><a class="link" href="<?= u('modules/rencana.php?id='.$x['id']) ?>"><?= e($x['nomor_rencana']) ?></a></td>
        <td><?= e($x['judul']) ?></td>
        <td><?= e($x['unit_pemohon']) ?></td>
        <td><?= tgl($x['tanggal']) ?></td>
        <td><?= badge($x['prioritas']) ?></td>
        <td class="num"><?= (int)$x['jml_item'] ?></td>
        <td class="num"><?= rupiah($x['estimasi_total']) ?></td>
        <td><?= badge($x['status']) ?></td>
        <td class="ta-r">
          <?php if ($x['status']==='DRAFT' && isPengadaan()): ?>
            <a class="btn btn-sm btn-outline" href="<?= u('modules/rencana.php?aksi=edit&id='.$x['id']) ?>">Edit</a>
          <?php endif; ?>
          <a class="btn btn-sm btn-ghost" href="<?= u('modules/rencana.php?id='.$x['id']) ?>">Detail</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
