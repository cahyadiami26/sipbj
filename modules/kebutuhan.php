<?php
/** Modul Pengajuan Kebutuhan — workflow DRAFT>DIAJUKAN>DIVERIFIKASI>DISETUJUI>PROSES/MASUK RENCANA>DITERIMA */
$pageTitle = 'Pengajuan Kebutuhan';
$activeNav = 'kebutuhan';
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
        $row = $isEdit ? qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]) : null;
        if ($isEdit && (!$row || $row['status'] !== 'DRAFT' || ($row['pengajuan_oleh'] != authId() && !isAdmin()))) {
            flash('Kebutuhan tidak dapat diubah.', 'error'); go('modules/kebutuhan.php');
        }
        $judul    = trim($_POST['judul'] ?? '');
        $unit     = $_POST['unit_pemohon'] ?? '';
        $prioritas= $_POST['prioritas'] ?? 'SEDANG';
        $alasan   = trim($_POST['alasan'] ?? '');
        $names = $_POST['item_nama'] ?? []; $specs = $_POST['item_spesifikasi'] ?? [];
        $sats = $_POST['item_satuan'] ?? []; $qtys = $_POST['item_jumlah'] ?? []; $prices = $_POST['item_harga'] ?? [];
        $items = [];
        foreach ($names as $i => $nm) {
            if (trim((string)$nm) === '') continue;
            $jml = max(1, (int)($qtys[$i] ?? 1)); $hrg = (float)($prices[$i] ?? 0);
            $items[] = [trim($nm), trim((string)($specs[$i] ?? '')), trim((string)($sats[$i] ?? 'unit')) ?: 'unit', $jml, $hrg, $jml*$hrg];
        }
        if (!$judul || !$unit || !$items) { flash('Judul, unit, dan minimal satu item wajib diisi.', 'error'); go('modules/kebutuhan.php?aksi=' . ($isEdit ? "edit&id=$id" : 'baru')); }

        $total = array_sum(array_column($items, 5));
        if ($isEdit) {
            q("UPDATE kebutuhan SET judul=?,unit_pemohon=?,prioritas=?,alasan=?,total_estimasi=? WHERE id=?", [$judul,$unit,$prioritas,$alasan,$total,$id]);
            q("DELETE FROM kebutuhan_item WHERE kebutuhan_id=?", [$id]);
        } else {
            $no = generateNumber(db(), 'kebutuhan', 'nomor_kebutuhan', 'KBT');
            q("INSERT INTO kebutuhan (nomor_kebutuhan,judul,unit_pemohon,pengajuan_oleh,tanggal_pengajuan,prioritas,alasan,total_estimasi,status) VALUES (?,?,?,?,?,?,?,?,'DRAFT')",
              [$no,$judul,$unit,authId(),date('Y-m-d'),$prioritas,$alasan,$total]);
            $id = (int)db()->lastInsertId();
        }
        $st = db()->prepare("INSERT INTO kebutuhan_item (kebutuhan_id,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total) VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) { $st->execute(array_merge([$id], $it)); }
        logActivity('Kebutuhan', $isEdit ? 'Update draft' : 'Buat draft', 'KBT #' . $id);
        flash('Kebutuhan tersimpan sebagai DRAFT.');
        go('modules/kebutuhan.php?id=' . $id);
    }

    if ($action === 'ajukan') {
        $row = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DRAFT' || ($row['pengajuan_oleh'] != authId() && !isAdmin())) { flash('Hanya pembuat (draft) yang dapat mengajukan.','error'); go("modules/kebutuhan.php?id=$id"); }
        if (!canAjukanKebutuhan()) { flash('Hanya Kepala Bidang / Kepala Divisi yang dapat mengajukan kebutuhan.','error'); go("modules/kebutuhan.php?id=$id"); }
        q("UPDATE kebutuhan SET status='DIAJUKAN' WHERE id=?", [$id]);
        logActivity('Kebutuhan','Diajukan',$row['nomor_kebutuhan']);
        flash('Kebutuhan diajukan ke Bagian Pengadaan.'); go("modules/kebutuhan.php?id=$id");
    }

    if ($action === 'verifikasi') {
        requireRole(['admin','pengadaan']);
        $row = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DIAJUKAN') { flash('Status tidak valid untuk verifikasi.','error'); go("modules/kebutuhan.php?id=$id"); }
        q("UPDATE kebutuhan SET status='DIVERIFIKASI', verifikasi_oleh=?, verifikasi_catatan=?, verifikasi_tgl=NOW() WHERE id=?", [authId(), trim($_POST['catatan'] ?? ''), $id]);
        logActivity('Kebutuhan','Verifikasi',$row['nomor_kebutuhan']);
        flash('Kebutuhan terverifikasi.'); go("modules/kebutuhan.php?id=$id");
    }

    if ($action === 'setujui') {
        $row = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DIVERIFIKASI') { flash('Status tidak valid untuk persetujuan.','error'); go("modules/kebutuhan.php?id=$id"); }
        $note = trim($_POST['catatan'] ?? '');
        // Level 1: Kepala Divisi dari unit pemohon (jika pengaju adalah Kepala Bidang)
        if (authPosition() === 'Kepala Divisi' && !in_array(authPosition(), ['Direktur Operasional'], true)) {
            if (strcasecmp($row['unit_pemohon'], (string)authDivision()) !== 0 && !isAdmin()) { flash('Anda bukan kepala divisi untuk unit ini.','error'); go("modules/kebutuhan.php?id=$id"); }
            q("UPDATE kebutuhan SET persetujuan_bidang_oleh=?, persetujuan_bidang_catatan=?, persetujuan_bidang_tgl=NOW() WHERE id=?", [authId(),$note,$id]);
            logActivity('Kebutuhan','Persetujuan Kepala Divisi',$row['nomor_kebutuhan']);
            flash('Persetujuan Kepala Divisi tercatat. Lanjut ke Direktur Operasional.'); go("modules/kebutuhan.php?id=$id");
        }
        // Level final: Direktur Operasional (atau admin)
        requireRole(['admin','pimpinan']);
        $jadwalLangsung = ($_POST['jalur'] ?? 'langsung') === 'rencana';
        $newStatus = $jadwalLangsung ? 'MASUK RENCANA PENGADAAN' : 'DISETUJUI';
        q("UPDATE kebutuhan SET status=?, persetujuan_dirut_oleh=?, persetujuan_dirut_catatan=?, persetujuan_dirut_tgl=NOW() WHERE id=?", [$newStatus,authId(),$note,$id]);
        if ($jadwalLangsung) buatRencanaDariKebutuhan($id);
        logActivity('Kebutuhan','Disetujui DirOps',$row['nomor_kebutuhan'] . ' -> ' . $newStatus);
        flash($jadwalLangsung ? 'Disetujui & dimasukkan ke Rencana Pengadaan (draft dibuat).' : 'Disetujui Direktur Operasional. Finalisasi anggaran oleh Keuangan & Umum.');
        go("modules/kebutuhan.php?id=$id");
    }

    if ($action === 'finalisasi') {
        if (!(isAdmin() || authDivision() === 'Keuangan' || in_array(authDepartment(), ['Keuangan & Umum'], true))) {
            flash('Finalisasi anggaran hanya oleh Divisi Keuangan & Umum.','error'); go("modules/kebutuhan.php?id=$id");
        }
        $row = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if (!$row || $row['status'] !== 'DISETUJUI') { flash('Status tidak valid.','error'); go("modules/kebutuhan.php?id=$id"); }
        q("UPDATE kebutuhan SET status='DALAM PROSES PENGADAAN', finalisasi_keuangan_oleh=?, finalisasi_keuangan_catatan=?, finalisasi_keuangan_tgl=NOW() WHERE id=?", [authId(),trim($_POST['catatan'] ?? ''),$id]);
        logActivity('Kebutuhan','Finalisasi anggaran',$row['nomor_kebutuhan']);
        flash('Anggaran final. Kebutuhan masuk proses pengadaan.'); go("modules/kebutuhan.php?id=$id");
    }

    if ($action === 'tolak') {
        requireRole(['admin','pimpinan','pengadaan']);
        $row = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if (!$row) { go('modules/kebutuhan.php'); }
        q("UPDATE kebutuhan SET status='DITOLAK', catatan=CONCAT_WS(' | ', catatan, ?) WHERE id=?", ['Ditolak: ' . trim($_POST['catatan'] ?? ''), $id]);
        logActivity('Kebutuhan','Ditolak',$row['nomor_kebutuhan']);
        flash('Kebutuhan ditolak.'); go("modules/kebutuhan.php?id=$id");
    }

    if ($action === 'hapus') {
        $row = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if ($row && $row['status'] === 'DRAFT' && ($row['pengajuan_oleh'] == authId() || isAdmin())) {
            q("DELETE FROM kebutuhan WHERE id=?", [$id]);
            logActivity('Kebutuhan','Hapus draft',$row['nomor_kebutuhan']);
            flash('Draft dihapus.');
        } else flash('Hanya pembuat yang dapat menghapus draft.','error');
        go('modules/kebutuhan.php');
    }
}

/** Buat draft rencana pengadaan dari item kebutuhan */
function buatRencanaDariKebutuhan(int $kebutuhanId): void
{
    $k = qOne("SELECT * FROM kebutuhan WHERE id=?", [$kebutuhanId]);
    $no = generateNumber(db(), 'rencana_pengadaan', 'nomor_rencana', 'RP');
    q("INSERT INTO rencana_pengadaan (nomor_rencana,judul,tanggal,unit_pemohon,prioritas,anggaran,estimasi_total,status,diajukan_oleh,kebutuhan_ids,catatan)
       VALUES (?,?,?,?,?,?,?, 'DRAFT', ?, ?, ?)", [
        $no, 'Rencana Pengadaan — ' . $k['judul'], date('Y-m-d'), $k['unit_pemohon'], $k['prioritas'],
        $k['total_estimasi'], $k['total_estimasi'], authId(), (string)$kebutuhanId,
        'Otomatis dari kebutuhan ' . $k['nomor_kebutuhan']]);
    $rid = (int)db()->lastInsertId();
    q("INSERT INTO rencana_item (rencana_id,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total,asal_kebutuhan_id)
       SELECT ?,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total,? FROM kebutuhan_item WHERE kebutuhan_id=?",
       [$rid, $kebutuhanId, $kebutuhanId]);
}

/* ================= TAMPILAN ================= */
$steps = ['DRAFT','DIAJUKAN','DIVERIFIKASI','DISETUJUI','DALAM PROSES PENGADAAN','DITERIMA'];

if ($action === 'detail' || ($action === 'list' && $id > 0 && !isset($_GET['q']) && !isset($_GET['status']))) {
    if ($id) {
        $k = qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]);
        if (!$k) { flash('Data tidak ditemukan.','error'); go('modules/kebutuhan.php'); }
        $items = qAll("SELECT * FROM kebutuhan_item WHERE kebutuhan_id=?", [$id]);
        $curIdx = array_search($k['status'], $steps);
        $isMine = $k['pengajuan_oleh'] == authId();
        ?>
        <div class="pagehead">
          <div class="grow">
            <h1><?= e($k['nomor_kebutuhan']) ?> <?= badge($k['status']) ?></h1>
            <p><?= e($k['judul']) ?> &bull; Unit <?= e($k['unit_pemohon']) ?> &bull; Diajukan <?= e(tgl($k['tanggal_pengajuan'])) ?> oleh <?= e(userName($k['pengajuan_oleh'])) ?></p>
          </div>
          <a class="btn btn-outline" href="kebutuhan_pdf.php?id=<?= $id ?>"><?= iconSvg('download') ?> PDF</a>
          <?php if ($k['status']==='DRAFT' && ($isMine||isAdmin())): ?>
            <a class="btn btn-primary" href="?aksi=edit&id=<?= $id ?>"><?= iconSvg('pencil') ?> Edit</a>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="aksi" value="ajukan"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn-success" data-confirm="Ajukan kebutuhan ini ke Bagian Pengadaan?"><?= iconSvg('check') ?> Ajukan</button></form>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn-danger" data-confirm="Hapus draft?">Hapus</button></form>
          <?php endif; ?>
          <a class="btn btn-ghost" href="kebutuhan.php"><?= iconSvg('back') ?> Kembali</a>
        </div>

        <div class="card">
          <div class="steps">
            <?php foreach ($steps as $i => $stp):
                $cls = $k['status']==='MASUK RENCANA PENGADAAN' && $stp==='DISETUJUI' ? 'done' :
                       (($curIdx!==false && $i < $curIdx) || $k['status']===$stp ? 'done' : ($i===$curIdx?'current':''));
                if ($k['status']===$stp) $cls='current done';?>
              <div class="step <?= $cls ?>"><span class="dotn"><?= $i+1 ?></span><?= e(str_replace('_',' ',$stp)) ?></div>
              <?php if ($i < count($steps)-1): ?><span class="step arrow">&rarr;</span><?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php if ($k['status']==='MASUK RENCANA PENGADAAN'): ?><p class="small text-muted">Alur: <b>Masuk Rencana Pengadaan</b> — draft rencana otomatis dibuat dengan item-item ini.</p><?php endif; ?>
          <?php if ($k['status']==='DITOLAK'): ?><div class="flash flash-error"><?= iconSvg('alert') ?> Dokumen ditolak. <?= e($k['catatan']) ?></div><?php endif; ?>
        </div>

        <div class="grid g2">
          <div class="card">
            <div class="card-title"><?= iconSvg('file') ?> Informasi Pengajuan</div>
            <dl class="kv">
              <dt>Prioritas</dt><dd><span class="prio-<?= $k['prioritas'] ?>"><?= e($k['prioritas']) ?></span></dd>
              <dt>Pengaju</dt><dd><?= e(userName($k['pengajuan_oleh'])) ?> (<?= e($k['unit_pemohon']) ?>)</dd>
              <dt>Total Estimasi</dt><dd><?= rupiah($k['total_estimasi']) ?></dd>
              <dt>Alasan</dt><dd style="font-weight:500"><?= nl2br(e($k['alasan'])) ?></dd>
            </dl>
            <div class="divider"></div>
            <ul class="timeline">
              <li><div class="tl-t">Draft dibuat</div><div class="tl-s"><?= e(userName($k['pengajuan_oleh'])) ?> &bull; <?= e(tgl($k['created_at'],'d/m/Y H:i')) ?></div></li>
              <?php if ($k['verifikasi_tgl']): ?><li><div class="tl-t">Diverifikasi Bagian Pengadaan</div><div class="tl-s"><?= e(userName($k['verifikasi_oleh'])) ?> &bull; <?= e(tgl($k['verifikasi_tgl'],'d/m/Y H:i')) ?></div><?php if($k['verifikasi_catatan']):?><div class="tl-note"><?= e($k['verifikasi_catatan']) ?></div><?php endif; ?></li><?php endif; ?>
              <?php if ($k['persetujuan_bidang_tgl']): ?><li><div class="tl-t">Disetujui Kepala Divisi</div><div class="tl-s"><?= e(userName($k['persetujuan_bidang_oleh'])) ?> &bull; <?= e(tgl($k['persetujuan_bidang_tgl'],'d/m/Y H:i')) ?></div><?php if($k['persetujuan_bidang_catatan']):?><div class="tl-note"><?= e($k['persetujuan_bidang_catatan']) ?></div><?php endif; ?></li><?php endif; ?>
              <?php if ($k['persetujuan_dirut_tgl']): ?><li><div class="tl-t">Disetujui Direktur Operasional</div><div class="tl-s"><?= e(userName($k['persetujuan_dirut_oleh'])) ?> &bull; <?= e(tgl($k['persetujuan_dirut_tgl'],'d/m/Y H:i')) ?></div><?php if($k['persetujuan_dirut_catatan']):?><div class="tl-note"><?= e($k['persetujuan_dirut_catatan']) ?></div><?php endif; ?></li><?php endif; ?>
              <?php if ($k['finalisasi_keuangan_tgl']): ?><li><div class="tl-t">Finalisasi Anggaran (Keuangan & Umum)</div><div class="tl-s"><?= e(userName($k['finalisasi_keuangan_oleh'])) ?> &bull; <?= e(tgl($k['finalisasi_keuangan_tgl'],'d/m/Y H:i')) ?></div><?php if($k['finalisasi_keuangan_catatan']):?><div class="tl-note"><?= e($k['finalisasi_keuangan_catatan']) ?></div><?php endif; ?></li><?php endif; ?>
              <?php if ($k['status']==='DITERIMA'): ?><li><div class="tl-t">Barang Diterima</div><div class="tl-s">Selesai — lihat modul penerimaan</div></li><?php endif; ?>
            </ul>
          </div>

          <div class="card">
            <div class="card-title"><?= iconSvg('box') ?> Aksi Workflow</div>
            <?php $showCatatan = fn($name) => '<textarea class="input" name="catatan" rows="2" placeholder="Catatan opsional"></textarea>';
            if ($k['status']==='DIAJUKAN' && isPengadaan()): ?>
              <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $id ?>">
                <div class="field mb-2"><label>Catatan Verifikasi</label><?= $showCatatan('v') ?></div>
                <button class="btn btn-primary"><?= iconSvg('check') ?> Tandai Terverifikasi</button>
                <a class="btn btn-outline" href="?aksi=detail&id=<?= $id ?>" onclick="return confirm('Tolak dokumen ini?')">Tolak</a></form>
            <?php elseif ($k['status']==='DIVERIFIKASI'): ?>
              <?php $needKadiv = authPosition()==='Kepala Divisi' && strcasecmp($k['unit_pemohon'],(string)authDivision())===0 && !$k['persetujuan_bidang_oleh'];
                    $byPengajuKabid = false; ?>
              <?php if ($needKadiv): ?>
                <form method="post" class="mb-2"><?= csrfField() ?><input type="hidden" name="aksi" value="setujui"><input type="hidden" name="id" value="<?= $id ?>">
                  <div class="field mb-2"><label>Catatan Persetujuan (Kepala Divisi)</label><?= $showCatatan('kb') ?></div>
                  <button class="btn btn-primary"><?= iconSvg('check') ?> Setujui sebagai Kepala Divisi</button></form>
              <?php endif; ?>
              <?php if (isPimpinan() && (!$needKadiv || $k['persetujuan_bidang_oleh'] || authPosition()!=='Kepala Divisi')): ?>
                <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="setujui"><input type="hidden" name="id" value="<?= $id ?>">
                  <div class="field mb-2"><label>Jalur Setelah Disetujui</label>
                    <select class="input" name="jalur">
                      <option value="langsung">Langsung Procurement (perlu finalisasi anggaran Keuangan &amp; Umum)</option>
                      <option value="rencana">Masuk Rencana Pengadaan (buat draft rencana otomatis)</option>
                    </select></div>
                  <div class="field mb-2"><label>Catatan Persetujuan (Direktur Operasional)</label><?= $showCatatan('do') ?></div>
                  <button class="btn btn-success"><?= iconSvg('check') ?> Setujui (DirOps)</button>
                  <a class="btn btn-outline" href="?aksi=detail&id=<?= $id ?>">Tolak</a></form>
              <?php elseif ($needKadiv): ?>
                <p class="text-muted small">Menunggu persetujuan Kepala Divisi <?= e($k['unit_pemohon']) ?>, lalu Direktur Operasional.</p>
              <?php endif; ?>
            <?php elseif ($k['status']==='DISETUJUI'): ?>
              <?php if (isAdmin() || authDivision()==='Keuangan' || in_array(authDepartment(),['Keuangan & Umum'],true)): ?>
                <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="finalisasi"><input type="hidden" name="id" value="<?= $id ?>">
                  <div class="field mb-2"><label>Catatan Finalisasi Anggaran</label><?= $showCatatan('f') ?></div>
                  <button class="btn btn-primary"><?= iconSvg('check') ?> Finalisasi Anggaran &rarr; Dalam Proses Pengadaan</button></form>
              <?php else: ?><p class="text-muted small">Menunggu finalisasi anggaran oleh Divisi Keuangan &amp; Umum.</p><?php endif; ?>
            <?php elseif ($k['status']==='DALAM PROSES PENGADAAN'): ?>
              <p class="text-muted small">Sedang dalam proses pengadaan. Penerimaan barang dicatat petugas pada modul <a href="penerimaan.php">Penerimaan Barang</a>.</p>
            <?php elseif ($k['status']==='MASUK RENCANA PENGADAAN'): ?>
              <p class="text-muted small">Item telah dimasukkan ke <a href="rencana.php">Rencana Pengadaan</a> (status draft).</p>
            <?php else: ?><p class="text-muted small">Tidak ada aksi tersedia pada status ini.</p><?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-title"><?= iconSvg('clipboard') ?> Item Kebutuhan</div>
          <div class="table-wrap"><table class="tbl">
            <thead><tr><th>#</th><th>Barang/Jasa</th><th>Spesifikasi</th><th>Satuan</th><th class="num">Jumlah</th><th class="num">Estimasi Harga</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i => $it): ?>
              <tr><td><?= $i+1 ?></td><td><b><?= e($it['nama_barang']) ?></b></td><td class="text-muted"><?= e($it['spesifikasi']) ?></td>
                <td><?= e($it['satuan']) ?></td><td class="num"><?= (int)$it['jumlah'] ?></td>
                <td class="num"><?= rupiah($it['estimasi_harga']) ?></td><td class="num"><?= rupiah($it['estimasi_total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="6" class="text-right">Total Estimasi</th><th class="num"><?= rupiah($k['total_estimasi']) ?></th></tr></tfoot>
          </table></div>
        </div>
        <?php require __DIR__ . '/../includes/footer.php'; exit;
    }
}

if ($action === 'baru' || $action === 'edit') {
    $k = $action==='edit' ? qOne("SELECT * FROM kebutuhan WHERE id=?", [$id]) : null;
    $items = $k ? qAll("SELECT * FROM kebutuhan_item WHERE kebutuhan_id=?", [$id]) : [];
    ?>
    <div class="pagehead"><div class="grow"><h1><?= $k ? 'Edit Draft Kebutuhan' : 'Pengajuan Kebutuhan Baru' ?></h1>
      <p>Isi detail kebutuhan unit Anda. Simpan sebagai draft, lalu ajukan.</p></div>
      <a class="btn btn-ghost" href="kebutuhan.php"><?= iconSvg('back') ?> Kembali</a></div>

    <form method="post" class="card">
      <?= csrfField() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $k?$id:'' ?>">
      <div class="form-grid">
        <div class="field full"><label>Judul Pengajuan <span class="req">*</span></label>
          <input class="input" name="judul" required maxlength="200" value="<?= e($k['judul'] ?? '') ?>" placeholder="cth: Pengadaan Laptop Staff IT"></div>
        <div class="field"><label>Unit Pemohon (Nama Bidang) <span class="req">*</span></label>
          <select class="input" name="unit_pemohon" required>
            <option value="">— pilih —</option>
            <?php foreach (unitOptions() as $un): ?><option <?= ($k['unit_pemohon']??authDivision())===$un?'selected':'' ?>><?= e($un) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Prioritas</label>
          <select class="input" name="prioritas"><?php foreach(['RENDAH','SEDANG','TINGGI','SEGERA'] as $p): ?>
            <option value="<?= $p ?>" <?= ($k['prioritas']??'SEDANG')===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?></select></div>
        <div class="field full"><label>Alasan / Justifikasi <span class="req">*</span></label>
          <textarea class="input" name="alasan" required placeholder="Mengapa kebutuhan ini penting..."><?= e($k['alasan'] ?? '') ?></textarea></div>
      </div>

      <div class="divider"></div>
      <div class="card-title"><?= iconSvg('box') ?> Daftar Item</div>
      <div class="table-wrap" style="border:0">
        <table class="tbl" data-itemtable="item" data-total-target="grandTotal">
          <thead><tr><th style="width:34px">#</th><th>Nama Barang/Jasa</th><th>Spesifikasi</th><th style="width:100px">Satuan</th><th style="width:90px">Jumlah</th><th style="width:150px">Estimasi Harga</th><th style="width:130px">Total</th><th style="width:40px"></th></tr></thead>
          <tbody>
          <?php if (!$items) $items = [['nama_barang'=>'','spesifikasi'=>'','satuan'=>'unit','jumlah'=>1,'estimasi_harga'=>0]];
                foreach ($items as $it): ?>
            <tr>
              <td class="rowno"></td>
              <td><input class="input" name="item_nama[]" value="<?= e($it['nama_barang']) ?>" required></td>
              <td><input class="input" name="item_spesifikasi[]" value="<?= e($it['spesifikasi'] ?? '') ?>"></td>
              <td><input class="input" name="item_satuan[]" value="<?= e($it['satuan']) ?>"></td>
              <td><input class="input" type="number" min="1" name="item_jumlah[]" data-qty value="<?= (int)$it['jumlah'] ?>"></td>
              <td><input class="input" type="number" min="0" step="100" name="item_harga[]" data-price value="<?= (float)$it['estimasi_harga'] ?>" style="text-align:right"></td>
              <td class="num" data-rowtotal><?= rupiah($it['estimasi_total']) ?></td>
              <td><button type="button" class="btn btn-xs btn-ghost" data-delrow>✕</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="actions mt-2">
        <button type="button" class="btn btn-outline" data-addrow><?= iconSvg('plus') ?> Tambah Item</button>
        <span class="chip" style="margin-left:auto">Total: <b id="grandTotal"><?= rupiah($k['total_estimasi'] ?? 0) ?></b></span>
      </div>
      <div class="divider"></div>
      <button class="btn btn-primary"><?= iconSvg('check') ?> <?= $k ? 'Simpan Perubahan' : 'Simpan Draft' ?></button>
      <a class="btn btn-ghost" href="kebutuhan.php">Batal</a>
    </form>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

/* ---------- LIST ---------- */
$qw = trim($_GET['q'] ?? ''); $fs = $_GET['status'] ?? '';
$where = '1=1'; $params = [];
if (!in_array(appRole(), ['admin','pengadaan','pimpinan'], true)) { $where .= ' AND (pengajuan_oleh=? OR unit_pemohon=?)'; $params[] = authId(); $params[] = authDivision(); }
if ($qw) { $where .= ' AND (nomor_kebutuhan LIKE ? OR judul LIKE ? OR unit_pemohon LIKE ?)'; $params = array_merge($params, ["%$qw%","%$qw%","%$qw%"]); }
if ($fs) { $where .= ' AND status=?'; $params[] = $fs; }
$rows = qAll("SELECT * FROM kebutuhan WHERE $where ORDER BY id DESC LIMIT 200", $params);
?>
<div class="pagehead">
  <div class="grow"><h1>Daftar Pengajuan Kebutuhan</h1><p>Mekanisme: Draft → Diajukan → Diverifikasi → Disetujui → Proses Pengadaan → Diterima</p></div>
  <?php if (canAjukanKebutuhan()): ?><a class="btn btn-primary" href="?aksi=baru"><?= iconSvg('plus') ?> Ajukan Kebutuhan</a><?php endif; ?>
</div>

<form class="filter-bar" method="get">
  <input class="input" name="q" placeholder="Cari nomor / judul / unit..." value="<?= e($qw) ?>">
  <select class="input" name="status" data-autosubmit>
    <option value="">Semua Status</option>
    <?php foreach (['DRAFT','DIAJUKAN','DIVERIFIKASI','DISETUJUI','DALAM PROSES PENGADAAN','MASUK RENCANA PENGADAAN','DITERIMA','DITOLAK'] as $stt): ?>
      <option <?= $fs===$stt?'selected':'' ?>><?= $stt ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-outline"><?= iconSvg('search') ?> Filter</button>
</form>

<div class="table-wrap"><table class="tbl">
  <thead><tr><th>Nomor</th><th>Judul</th><th>Unit</th><th>Tanggal</th><th>Prioritas</th><th class="num">Estimasi</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8"><div class="empty"><?= iconSvg('clipboard') ?><div>Belum ada pengajuan kebutuhan.</div></div></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mono"><?= e($r['nomor_kebutuhan']) ?></td>
      <td><a href="?aksi=detail&id=<?= $r['id'] ?>"><b><?= e($r['judul']) ?></b></a></td>
      <td><?= e($r['unit_pemohon']) ?></td>
      <td><?= e(tgl($r['tanggal_pengajuan'])) ?></td>
      <td><span class="prio-<?= $r['prioritas'] ?>"><?= e($r['prioritas']) ?></span></td>
      <td class="num"><?= rupiah($r['total_estimasi']) ?></td>
      <td><?= badge($r['status']) ?></td>
      <td class="text-right"><a class="btn btn-xs btn-outline" href="?aksi=detail&id=<?= $r['id'] ?>">Detail</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
