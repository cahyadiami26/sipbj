<?php
/** Permintaan ATK — workflow Unit Kerja > Bagian Umum (verifikasi stok) > Persetujuan > Distribusi > Konfirmasi */
$pageTitle = 'Permintaan ATK';
$activeNav = 'atk_permintaan';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';

/* ================= AKSI ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    /* --- buat permintaan baru (semua user) --- */
    if ($aksi === 'buat') {
        $unit  = trim($_POST['unit_pemohon'] ?? '') ?: (authDivision() ?: '-');
        $keperluan = trim($_POST['keperluan'] ?? '');
        $items = $_POST['item'] ?? []; // [ ['barang'=>id,'jumlah'=>n], ... ]
        $valid = [];
        foreach ($items as $it) {
            $bid = (int)($it['barang'] ?? 0); $jl = max(1, (int)($it['jumlah'] ?? 0));
            if ($bid && $jl) $valid[$bid] = ($valid[$bid] ?? 0) + $jl;
        }
        if (!$valid) { flash('Tambahkan minimal satu item ATK.', 'error'); go('modules/atk_permintaan.php#formBaru'); }
        $no = generateNumber(db(), 'atk_permintaan', 'nomor_permintaan', 'PMK');
        q("INSERT INTO atk_permintaan (nomor_permintaan,unit_pemohon,pemohon,tanggal,keperluan,status) VALUES (?,?,?,?,?,'MENUNGGU PROSES')",
          [$no, $unit, authId(), date('Y-m-d'), $keperluan ?: null]);
        $pid = (int)db()->lastInsertId();
        foreach ($valid as $bid => $jl) {
            q("INSERT INTO atk_permintaan_item (permintaan_id,atk_barang_id,jumlah_minta) VALUES (?,?,?)", [$pid, $bid, $jl]);
        }
        logActivity('ATK Permintaan', "Buat $no", implode(',', array_keys($valid)));
        flash("Permintaan $no dibuat. Menunggu verifikasi Bagian Umum.");
        go('modules/atk_permintaan.php?id=' . $pid);
    }

    /* --- verifikasi stok (Bagian Umum / pengadaan) --- */
    if ($aksi === 'verifikasi' && isPengadaan()) {
        $pid = (int)($_POST['id'] ?? 0);
        $p = qOne("SELECT * FROM atk_permintaan WHERE id=?", [$pid]);
        if (!$p || $p['status'] !== 'MENUNGGU PROSES') { flash('Permintaan tidak dapat diverifikasi.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        $items = qAll("SELECT pi.*, ab.stok, ab.nama FROM atk_permintaan_item pi JOIN atk_barang ab ON ab.id=pi.atk_barang_id WHERE pi.permintaan_id=?", [$pid]);
        $kurang = [];
        $setujuMap = $_POST['jumlah_setuju'] ?? [];
        foreach ($items as $it) {
            $disetui = max(0, min((int)$it['stok'], (int)($setujuMap[$it['atk_barang_id']] ?? $it['jumlah_minta'])));
            q("UPDATE atk_permintaan_item SET jumlah_setuju=? WHERE id=?", [$disetui, $it['id']]);
            if ($disetui < (int)$it['jumlah_minta']) $kurang[] = $it['nama'];
        }
        $catatan = trim($_POST['catatan'] ?? '');
        if ($kurang) {
            // sebagian/seluruh stok kurang -> tandai STOK KOSONG agar bisa diajukan sebagai rencana pengadaan
            q("UPDATE atk_permintaan SET status='STOK KOSONG', verifikasi_oleh=?, verifikasi_catatan=?, verifikasi_tgl=NOW() WHERE id=?",
              [authId(), $catatan ?: ('Stok kurang untuk: ' . implode(', ', $kurang)), $pid]);
            logActivity('ATK Permintaan', 'Verifikasi ' . $p['nomor_permintaan'], 'stok kurang: ' . implode(',', $kurang));
            flash('Diverifikasi: ada item yang stoknya kurang → STOK KOSONG. Gunakan tombol "Jadikan Rencana Pengadaan" pada detail bila perlu.', 'warn');
        } else {
            q("UPDATE atk_permintaan SET status='DISSETUJUI', verifikasi_oleh=?, verifikasi_catatan=?, verifikasi_tgl=NOW() WHERE id=?",
              [authId(), $catatan ?: null, $pid]);
            logActivity('ATK Permintaan', 'Setujui ' . $p['nomor_permintaan'], null);
            flash('Semua stok tersedia → DISSETUJUI. Silakan serahkan ke unit pemohon.');
        }
        go('modules/atk_permintaan.php?id=' . $pid);
    }

    /* --- serahkan (pengadaan): stok berkurang otomatis --- */
    if ($aksi === 'serahkan' && isPengadaan()) {
        $pid = (int)($_POST['id'] ?? 0);
        $p = qOne("SELECT * FROM atk_permintaan WHERE id=?", [$pid]);
        if (!$p || !in_array($p['status'], ['DISSETUJUI', 'MENUNGGU PENGAMBILAN'], true)) { flash('Hanya permintaan disetujui dapat diserahkan.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        $items = qAll("SELECT * FROM atk_permintaan_item WHERE permintaan_id=? AND jumlah_setuju>0", [$pid]);
        $ok = true;
        foreach ($items as $it) {
            $b = qOne("SELECT * FROM atk_barang WHERE id=?", [$it['atk_barang_id']]);
            if (!$b || (int)$b['stok'] < (int)$it['jumlah_setuju']) { $ok = false; break; }
        }
        if (!$ok) { flash('Stok tidak mencukupi saat penyerahan.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        db()->beginTransaction();
        try {
            foreach ($items as $it) {
                q("UPDATE atk_barang SET stok = stok - ? WHERE id=?", [$it['jumlah_setuju'], $it['atk_barang_id']]);
                $b = qOne("SELECT nama FROM atk_barang WHERE id=?", [$it['atk_barang_id']]);
                q("INSERT INTO atk_transaksi (atk_barang_id,tipe,jumlah,ref_type,ref_id,unit,keterangan,oleh) VALUES (?,'KELUAR',?, 'ATK_PERMINTAAN',?,?,?,?)",
                  [$it['atk_barang_id'], $it['jumlah_setuju'], $pid, $p['unit_pemohon'], 'Serah terima ' . $p['nomor_permintaan'] . ' — ' . $b['nama'], authId()]);
            }
            q("UPDATE atk_permintaan SET status='DISERAHKAN', distribusi_tgl=NOW() WHERE id=?", [$pid]);
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        logActivity('ATK Permintaan', 'Serahkan ' . $p['nomor_permintaan'], null);
        flash('Barang diserahkan. Stok otomatis berkurang. Menunggu konfirmasi penerima.');
        go('modules/atk_permintaan.php?id=' . $pid);
    }

    /* --- konfirmasi penerima (pemohon / pimpinan) --- */
    if ($aksi === 'terima') {
        $pid = (int)($_POST['id'] ?? 0);
        $p = qOne("SELECT * FROM atk_permintaan WHERE id=?", [$pid]);
        if (!$p || $p['status'] !== 'DISERAHKAN') { flash('Belum dapat dikonfirmasi.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        if (!($p['pemohon'] === authId() || isPimpinan())) { flash('Hanya pemohon/pimpinan yang mengonfirmasi.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        q("UPDATE atk_permintaan SET status='DITERIMA', terima_oleh=?, terima_tgl=NOW() WHERE id=?", [authId(), $pid]);
        logActivity('ATK Permintaan', 'Konfirmasi terima ' . $p['nomor_permintaan'], null);
        flash('Terima kasih, penerimaan ATK telah dikonfirmasi.');
        go('modules/atk_permintaan.php?id=' . $pid);
    }

    /* --- tolak (pengadaan) --- */
    if ($aksi === 'tolak' && isPengadaan()) {
        $pid = (int)($_POST['id'] ?? 0);
        $p = qOne("SELECT * FROM atk_permintaan WHERE id=?", [$pid]);
        if ($p && in_array($p['status'], ['MENUNGGU PROSES', 'STOK KOSONG'], true)) {
            q("UPDATE atk_permintaan SET status='DITOLAK', verifikasi_oleh=?, verifikasi_catatan=?, verifikasi_tgl=NOW() WHERE id=?",
              [authId(), trim($_POST['alasan'] ?? '') ?: null, $pid]);
            logActivity('ATK Permintaan', 'Tolak ' . $p['nomor_permintaan'], null);
            flash('Permintaan ditolak.');
        }
        go('modules/atk_permintaan.php?id=' . $pid);
    }

    /* --- buat rencana pengadaan dari kekurangan stok --- */
    if ($aksi === 'jadikan_rencana' && isPengadaan()) {
        $pid = (int)($_POST['id'] ?? 0);
        $p = qOne("SELECT * FROM atk_permintaan WHERE id=?", [$pid]);
        if (!$p || $p['status'] !== 'STOK KOSONG') { flash('Hanya permintaan STOK KOSONG yang dapat dijadikan rencana.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        $items = qAll("SELECT pi.*, ab.nama, ab.satuan, ab.harga_satuan,
                              (pi.jumlah_minta - pi.jumlah_setuju) AS kurang
                       FROM atk_permintaan_item pi JOIN atk_barang ab ON ab.id=pi.atk_barang_id
                       WHERE pi.permintaan_id=? AND pi.jumlah_minta > pi.jumlah_setuju", [$pid]);
        if (!$items) { flash('Tidak ada kekurangan item.', 'error'); go('modules/atk_permintaan.php?id=' . $pid); }
        $no = generateNumber(db(), 'rencana_pengadaan', 'nomor_rencana', 'RP');
        $estTotal = array_sum(array_map(fn($i) => (float)$i['harga_satuan'] * (int)$i['kurang'], $items));
        db()->beginTransaction();
        try {
            q("INSERT INTO rencana_pengadaan (nomor_rencana,judul,tanggal,unit_pemohon,prioritas,anggaran,estimasi_total,sumber_anggaran,status,catatan)
               VALUES (?,?,?,?,?,'RENDAH',0,?, 'DRAFT', ?)",
              [$no, 'Rencana Pengadaan ATK — kekurangan stok ' . $p['nomor_permintaan'], date('Y-m-d'),
               $p['unit_pemohon'], $estTotal, 'ATK / Bagian Umum',
               'Dibuat otomatis dari permintaan ATK ' . $p['nomor_permintaan']]);
            $rid = (int)db()->lastInsertId();
            foreach ($items as $i) {
                $tot = (float)$i['harga_satuan'] * (int)$i['kurang'];
                q("INSERT INTO rencana_item (rencana_id,nama_barang,spesifikasi,satuan,jumlah,estimasi_harga,estimasi_total) VALUES (?,?,?,?,?,?,?)",
                  [$rid, $i['nama'], 'Sisa kebutuhan dari permintaan ' . $p['nomor_permintaan'], $i['satuan'], (int)$i['kurang'], (float)$i['harga_satuan'], $tot]);
            }
            q("UPDATE atk_permintaan SET status='MASUK RENCANA PENGADAAN' WHERE id=?", [$pid]);
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        logActivity('ATK Permintaan', 'Buat rencana ' . $no, 'dari ' . $p['nomor_permintaan']);
        flash("Draft Rencana Pengadaan $no dibuat dari kekurangan stok. Silakan lengkapi & ajukan di modul Rencana Pengadaan.");
        go('modules/rencana.php?id=' . $rid);
    }
}

/* ================= DATA ================= */
$id = (int)($_GET['id'] ?? 0);
$detail = $id ? qOne("SELECT * FROM atk_permintaan WHERE id=?", [$id]) : null;
if ($id && !$detail) { flash('Permintaan tidak ditemukan.', 'error'); go('modules/atk_permintaan.php'); }

$listStatus = $_GET['status'] ?? '';
$mine = isset($_GET['mine']);
$where = ['1=1']; $pr = [];
if ($listStatus !== '') { $where[] = 'status=?'; $pr[] = $listStatus; }
if ($mine)              { $where[] = 'pemohon=?'; $pr[] = authId(); }
$list = qAll("SELECT * FROM atk_permintaan WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 200", $pr);

$barangList = qAll("SELECT * FROM atk_barang WHERE is_active=1 ORDER BY nama");
$cntMenunggu = (int)scalar("SELECT COUNT(*) FROM atk_permintaan WHERE status='MENUNGGU PROSES'");
$cntSiap     = (int)scalar("SELECT COUNT(*) FROM atk_permintaan WHERE status IN ('DISSETUJUI','MENUNGGU PENGAMBILAN')");
$cntSerah    = (int)scalar("SELECT COUNT(*) FROM atk_permintaan WHERE status='DISERAHKAN'");
?>
<?php if ($detail):
  $items = qAll("SELECT pi.*, ab.nama, ab.satuan, ab.stok FROM atk_permintaan_item pi JOIN atk_barang ab ON ab.id=pi.atk_barang_id WHERE pi.permintaan_id=?", [$detail['id']]);
  $steps = ['MENUNGGU PROSES','DISSETUJUI','DISERAHKAN','DITERIMA'];
  $idxNow = array_search($detail['status'], $steps);
  if ($detail['status'] === 'STOK KOSONG') $idxNow = 1;
?>
<div class="page-head"><div>
  <a class="btn btn-sm btn-ghost" href="atk_permintaan.php"><?= iconSvg('back') ?> Kembali</a>
  <h1><?= iconSvg('clipboard') ?> <?= e($detail['nomor_permintaan']) ?></h1>
  <p class="muted">Unit <b><?= e($detail['unit_pemohon']) ?></b> • Dipohon oleh <b><?= e(userName($detail['pemohon'])) ?></b> • <?= tglIndo($detail['tanggal']) ?></p>
</div><div><?= badge($detail['status']) ?></div></div>

<div class="card"><div class="card-body">
  <div class="steps">
    <?php foreach ($steps as $i => $s): ?>
      <div class="step <?= $idxNow !== false && $i < $idxNow ? 'done' : ($i == $idxNow ? 'active' : '') ?>">
        <div class="dot"><?= $i + 1 ?></div><span><?= e($s) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (in_array($detail['status'], ['DITOLAK'])): ?><div class="step fail"><div class="dot">✕</div><span>DITOLAK</span></div><?php endif; ?>
  </div>
</div></div>

<div class="grid-2" style="grid-template-columns:1fr 380px;align-items:start">
  <div>
    <div class="card">
      <div class="card-head"><h3>Item Permintaan</h3></div>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Barang</th><th>Minta</th><th>Disetujui</th><th>Stok Saat Ini</th><th>Satuan</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['nama']) ?></td>
            <td class="num"><?= (int)$it['jumlah_minta'] ?></td>
            <td class="num"><?= (int)$it['jumlah_setuju'] ?: '-' ?></td>
            <td class="num <?= (int)$it['stok'] < (int)$it['jumlah_minta'] ? 'txt-red' : '' ?>"><?= (int)$it['stok'] ?></td>
            <td class="small muted"><?= e($it['satuan']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php if ($detail['keperluan']): ?>
        <div class="card-body"><b class="small muted">Keperluan:</b><br><?= nl2br(e($detail['keperluan'])) ?></div>
      <?php endif; ?>
      <?php if ($detail['verifikasi_catatan']): ?>
        <div class="card-body"><b class="small muted">Catatan Verifikasi (<?= e(userName($detail['verifikasi_oleh'])) ?>, <?= tgl($detail['verifikasi_tgl'],'d/m/Y H:i') ?>):</b><br><?= nl2br(e($detail['verifikasi_catatan'])) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($detail['status'] === 'MENUNGGU PROSES' && isPengadaan()): ?>
    <div class="card">
      <div class="card-head"><h3>Verifikasi Stok &amp; Persetujuan</h3></div>
      <form method="post" class="card-body">
        <?= csrfField() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <p class="muted small">Sesuaikan jumlah yang disetujui (maks sesuai stok). Item yang tidak tercukupi akan ditandai untuk rencana pengadaan.</p>
        <table class="tbl"><thead><tr><th>Barang</th><th>Minta</th><th>Stok</th><th>Setujui</th></tr></thead><tbody>
          <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['nama']) ?></td><td class="num"><?= (int)$it['jumlah_minta'] ?></td><td class="num"><?= (int)$it['stok'] ?></td>
              <td><input type="number" name="jumlah_setuju[<?= $it['atk_barang_id'] ?>]" min="0" max="<?= (int)$it['stok'] ?>" value="<?= min((int)$it['jumlah_minta'], (int)$it['stok']) ?>" style="width:90px"></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <label>Catatan <textarea name="catatan" rows="2"></textarea></label>
        <div class="btn-row"><button class="btn btn-primary"><?= iconSvg('check') ?> Verifikasi</button></div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($detail['status'] === 'DISSETUJUI' && isPengadaan()): ?>
    <div class="card"><div class="card-body">
      <form method="post" onsubmit="return confirm('Serahkan barang dan kurangi stok?')">
        <?= csrfField() ?><input type="hidden" name="aksi" value="serahkan"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <button class="btn btn-primary"><?= iconSvg('truck') ?> Serahkan ke Unit (stok otomatis berkurang)</button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if ($detail['status'] === 'DISERAHKAN' && ($detail['pemohon'] === authId() || isPimpinan())): ?>
    <div class="card"><div class="card-body">
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="aksi" value="terima"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <button class="btn btn-primary"><?= iconSvg('check') ?> Konfirmasi Barang Diterima</button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if ($detail['status'] === 'STOK KOSONG' && isPengadaan()): ?>
    <div class="card"><div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <span class="muted small">Ada kekurangan stok. Anda dapat menggabungkannya ke rencana pengadaan.</span>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="aksi" value="jadikan_rencana"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <button class="btn btn-primary"><?= iconSvg('cart') ?> Jadikan Rencana Pengadaan</button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if (in_array($detail['status'], ['MENUNGGU PROSES','STOK KOSONG']) && isPengadaan()): ?>
    <div class="card"><div class="card-body">
      <form method="post" class="inline-form" onsubmit="return confirm('Tolak permintaan ini?')">
        <?= csrfField() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <input type="text" name="alasan" placeholder="Alasan penolakan…" style="width:280px">
        <button class="btn btn-danger-x"><?= iconSvg('alert') ?> Tolak</button>
      </form>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="card"><div class="card-head"><h3>Riwayat</h3></div>
    <div class="card-body timeline">
      <div class="tl-item"><b>Diajukan</b><div class="muted small"><?= e(userName($detail['pemohon'])) ?> • <?= tgl($detail['tanggal'],'d/m/Y H:i') ?></div></div>
      <?php if ($detail['verifikasi_tgl']): ?>
      <div class="tl-item"><b>Diverifikasi</b><div class="muted small"><?= e(userName($detail['verifikasi_oleh'])) ?> • <?= tgl($detail['verifikasi_tgl'],'d/m/Y H:i') ?></div></div><?php endif; ?>
      <?php if ($detail['distribusi_tgl']): ?>
      <div class="tl-item"><b>Diserahkan</b><div class="muted small"><?= tgl($detail['distribusi_tgl'],'d/m/Y H:i') ?></div></div><?php endif; ?>
      <?php if ($detail['terima_tgl']): ?>
      <div class="tl-item ok"><b>Diterima Penerima</b><div class="muted small"><?= e(userName($detail['terima_oleh'])) ?> • <?= tgl($detail['terima_tgl'],'d/m/Y H:i') ?></div></div><?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<!-- ============ LIST ============ -->
<div class="page-head">
  <div><h1><?= iconSvg('clipboard') ?> Permintaan ATK</h1>
    <p class="muted">Ajukan kebutuhan ATK unit kerja; Bagian Umum memverifikasi stok, mendistribusikan, dan penerima mengonfirmasi.</p></div>
  <button class="btn btn-primary" onclick="document.getElementById('formBaru').scrollIntoView(true)"><?= iconSvg('plus') ?> Buat Permintaan</button>
</div>

<div class="stats">
  <div class="stat"><div class="s-ic amber"><?= iconSvg('alert') ?></div><div><div class="s-val"><?= $cntMenunggu ?></div><div class="s-lbl">Menunggu Proses</div></div></div>
  <div class="stat"><div class="s-ic teal"><?= iconSvg('check') ?></div><div><div class="s-val"><?= $cntSiap ?></div><div class="s-lbl">Siap Diserahkan</div></div></div>
  <div class="stat"><div class="s-ic blue"><?= iconSvg('truck') ?></div><div><div class="s-val"><?= $cntSerah ?></div><div class="s-lbl">Menunggu Konfirmasi</div></div></div>
</div>

<div class="card" id="formBaru">
  <div class="card-head"><h3>Form Permintaan Baru</h3></div>
  <form method="post" class="card-body">
    <?= csrfField() ?><input type="hidden" name="aksi" value="buat">
    <div class="form-grid cols-3">
      <label>Unit Pemohon <input type="text" name="unit_pemohon" value="<?= e(authDivision() ?: '') ?>" readonly></label>
      <label>Tanggal <input type="date" value="<?= date('Y-m-d') ?>" readonly></label>
      <label>Keperluan <input type="text" name="keperluan" maxlength="255" placeholder="cth: kebutuhan cetak laporan bulanan"></label>
    </div>
    <table class="tbl dyn-table" style="margin-top:12px">
      <thead><tr><th style="width:60%">Jenis ATK</th><th>Jumlah</th><th></th></tr></thead>
      <tbody id="dynBody">
        <tr class="dyn-row">
          <td><select name="item[0][barang]" required>
            <option value="">— Pilih ATK —</option>
            <?php foreach ($barangList as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['nama']) ?> (stok: <?= (int)$b['stok'] ?> <?= e($b['satuan']) ?>)</option><?php endforeach; ?>
          </select></td>
          <td><input type="number" name="item[0][jumlah]" min="1" value="1" required></td>
          <td><button type="button" class="btn btn-sm btn-danger-x dyn-del"><?= iconSvg('trash') ?></button></td>
        </tr>
      </tbody>
    </table>
    <div class="btn-row" style="margin-top:10px">
      <button type="button" class="btn btn-outline" id="dynAdd"><?= iconSvg('plus') ?> Tambah Item</button>
      <button class="btn btn-primary"><?= iconSvg('check') ?> Ajukan Permintaan</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h3>Riwayat Permintaan (<?= count($list) ?>)</h3>
    <form method="get" class="filter-bar">
      <select name="status"><option value="">Semua Status</option>
        <?php foreach (['MENUNGGU PROSES','STOK TERSEDIA','STOK KOSONG','DISSETUJUI','MENUNGGU PENGAMBILAN','DISERAHKAN','DITERIMA','DITOLAK'] as $s): ?>
          <option <?= $listStatus===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
      <label class="check-row"><input type="checkbox" name="mine" value="1" <?= $mine?'checked':'' ?>> <span class="chk-box"></span>&nbsp;Milik saya saja</label>
      <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Filter</button>
    </form>
  </div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nomor</th><th>Tanggal</th><th>Unit</th><th>Pemohon</th><th>Keperluan</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="7" class="empty">Belum ada permintaan.</td></tr><?php endif;
    foreach ($list as $r): ?>
      <tr>
        <td class="mono small"><b><?= e($r['nomor_permintaan']) ?></b></td>
        <td><?= tgl($r['tanggal']) ?></td>
        <td><?= e($r['unit_pemohon']) ?></td>
        <td class="small"><?= e(userName($r['pemohon'])) ?></td>
        <td class="small"><?= e(mb_strimwidth((string)$r['keperluan'], 0, 40, '…')) ?: '-' ?></td>
        <td><?= badge($r['status']) ?></td>
        <td><a class="btn btn-sm btn-outline" href="atk_permintaan.php?id=<?= $r['id'] ?>"><?= iconSvg('eye') ?> Detail</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>

<script>
(function(){
  const body = document.getElementById('dynBody');
  const tplSel = body.querySelector('select').outerHTML;
  let n = 1;
  document.getElementById('dynAdd').addEventListener('click', () => {
    const tr = document.createElement('tr'); tr.className = 'dyn-row';
    tr.innerHTML = `<td>${tplSel.replace(/item\[0\]/g, 'item['+n+']')}</td>`
      + `<td><input type="number" name="item[${n}][jumlah]" min="1" value="1" required></td>`
      + `<td><button type="button" class="btn btn-sm btn-danger-x dyn-del">${'×'}</button></td>`;
    body.appendChild(tr); n++;
  });
  body.addEventListener('click', e => {
    if (e.target.closest('.dyn-del') && body.children.length > 1) e.target.closest('tr').remove();
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
