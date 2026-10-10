<?php
/** Modul Stock Opname — buat > pilih lokasi/unit > scan aset > cocokkan > validasi > laporan */
$pageTitle = 'Stock Opname';
$activeNav = 'opname';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);

$action = $_GET['aksi'] ?? ($_POST['aksi'] ?? 'list');
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

function opnameRecalc(int $opnameId): void
{
    $o = qOne("SELECT * FROM stock_opname WHERE id=?", [$opnameId]);
    if (!$o) return;
    // data target: aset aktif pada filter
    $where = "status NOT IN ('DIHAPUS')"; $pr = [];
    if ($o['unit_filter'])  { $where .= " AND unit=?";  $pr[] = $o['unit_filter']; }
    if ($o['lokasi_filter']) { $where .= " AND lokasi_id IN (SELECT id FROM lokasi WHERE nama LIKE ? OR parent_id IN (SELECT id FROM lokasi WHERE nama LIKE ?))"; $pr[] = '%' . $o['lokasi_filter'] . '%'; $pr[] = '%' . $o['lokasi_filter'] . '%'; }
    $total = (int)scalar("SELECT COUNT(*) FROM aset WHERE $where", $pr);
    $scanned = qAll("SELECT hasil, COUNT(*) c FROM opname_item WHERE opname_id=? GROUP BY hasil", [$opnameId]);
    $cocok = $tidak = $tfound = 0;
    foreach ($scanned as $s) {
        if ($s['hasil'] === 'COCOK') $cocok += $s['c'];
        elseif ($s['hasil'] === 'TIDAK COCOK') $tidak += $s['c'];
        elseif ($s['hasil'] === 'TIDAK DITEMUKAN') $tfound += $s['c'];
    }
    // aset yang tidak di-scan dianggap TIDAK DITEMUKAN saat validasi selesai
    q("UPDATE stock_opname SET total_data=?, total_terverifikasi=?, total_cocok=?, total_tidak_cocok=?, total_tidak_ditemukan=? WHERE id=?",
      [$total, $cocok + $tidak, $cocok, $tidak, max(0, $total - $cocok - $tidak) + $tfound, $opnameId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'buat') {
        $judul = trim($_POST['judul'] ?? '');
        if (!$judul) { flash('Judul wajib diisi.', 'error'); go('modules/opname.php?aksi=baru'); }
        $no = generateNumber(db(), 'stock_opname', 'nomor_opname', 'SOP');
        q("INSERT INTO stock_opname (nomor_opname,judul,tanggal_mulai,lokasi_filter,unit_filter,pelaksana,status)
           VALUES (?,?,?,?,?,?, 'BERJALAN')", [
            $no, $judul, $_POST['tanggal_mulai'] ?: date('Y-m-d'),
            trim($_POST['lokasi_filter'] ?? '') ?: null,
            trim($_POST['unit_filter'] ?? '') ?: null,
            authId(),
        ]);
        logActivity('Stock Opname', 'Buat', $no);
        flash('Opname ' . $no . ' dibuat. Mulai scan aset.');
        go('modules/opname.php?id=' . db()->lastInsertId() . '&scan=1');
    }

    if ($action === 'scan') {
        $o = qOne("SELECT * FROM stock_opname WHERE id=?", [$id]);
        if (!$o || $o['status'] !== 'BERJALAN') { flash('Opname tidak aktif.', 'error'); go("modules/opname.php?id=$id"); }
        $kode = trim($_POST['kode'] ?? '');
        if ($kode === '') { go("modules/opname.php?id=$id&scan=1"); }
        $a = qOne("SELECT * FROM aset WHERE kode_aset=?", [$kode]);
        if (!$a) {
            // catat sebagai temuan tak dikenal
            q("INSERT INTO opname_item (opname_id,aset_id,kode_scan,hasil,catatan) VALUES (?,NULL,?, 'TIDAK DITEMUKAN','Kode tidak dikenali sistem')", [$id, $kode]);
            flash("Kode '$kode' tidak dikenali.", 'error');
        } else {
            $dup = qOne("SELECT id FROM opname_item WHERE opname_id=? AND aset_id=?", [$id, $a['id']]);
            if ($dup) { flash('Aset sudah di-scan pada sesi ini.', 'info'); }
            else {
                $kondisi = in_array($_POST['kondisi_hasil'] ?? '', ['BAIK', 'RUSAK RINGAN', 'RUSAK BERAT'], true) ? $_POST['kondisi_hasil'] : $a['kondisi'];
                $hasil = $kondisi === $a['kondisi'] && $a['status'] === 'AKTIF' ? 'COCOK' : 'TIDAK COCOK';
                q("INSERT INTO opname_item (opname_id,aset_id,kode_scan,hasil,kondisi_hasil,catatan) VALUES (?,?,?,?,?,?)",
                  [$id, $a['id'], $kode, $hasil, $kondisi, trim($_POST['catatan'] ?? '') ?: null]);
                flash($a['kode_aset'] . ' tercatat — ' . ($hasil === 'COCOK' ? 'sesuai data' : 'ada ketidaksesuaian'), $hasil === 'COCOK' ? 'success' : 'info');
            }
        }
        opnameRecalc($id);
        go("modules/opname.php?id=$id&scan=1");
    }

    if ($action === 'hapus_item') {
        $it = qOne("SELECT oi.*, o.status os FROM opname_item oi JOIN stock_opname o ON o.id=oi.opname_id WHERE oi.id=?", [$id]);
        if ($it && $it['os'] === 'BERJALAN') {
            q("DELETE FROM opname_item WHERE id=?", [$id]);
            opnameRecalc($it['opname_id']);
            flash('Item scan dihapus.');
            go('modules/opname.php?id=' . $it['opname_id'] . '&scan=1');
        }
        go('modules/opname.php');
    }

    if ($action === 'validasi') {
        $o = qOne("SELECT * FROM stock_opname WHERE id=?", [$id]);
        if (!$o || $o['status'] !== 'BERJALAN') { flash('Status tidak valid.', 'error'); go("modules/opname.php?id=$id"); }
        q("UPDATE stock_opname SET status='VALIDASI' WHERE id=?", [$id]);
        logActivity('Stock Opname', 'Ke Validasi', $o['nomor_opname']);
        flash('Hasil scan masuk tahap validasi.');
        go("modules/opname.php?id=$id");
    }

    if ($action === 'selesai') {
        $o = qOne("SELECT * FROM stock_opname WHERE id=?", [$id]);
        if (!$o || $o['status'] !== 'VALIDASI') { flash('Status tidak valid.', 'error'); go("modules/opname.php?id=$id"); }
        q("UPDATE stock_opname SET status='SELESAI', tanggal_selesai=CURDATE(), catatan=? WHERE id=?", [trim($_POST['catatan'] ?? ''), $id]);
        opnameRecalc($id);
        logActivity('Stock Opname', 'Selesai', $o['nomor_opname']);
        flash('Stock opname diselesaikan & laporan tersedia.');
        go("modules/opname.php?id=$id");
    }
}

/* ---------- detail / scanning ---------- */
if ($id > 0 && !in_array($action, ['baru', 'list'], true)):
    $o = qOne("SELECT * FROM stock_opname WHERE id=?", [$id]);
    if (!$o) { flash('Opname tidak ditemukan.', 'error'); go('modules/opname.php'); }
    $items = qAll("SELECT oi.*, a.nama AS aset_nama, a.unit, a.kondisi AS kondisi_data, l.nama lokasi_nama
                   FROM opname_item oi LEFT JOIN aset a ON a.id=oi.aset_id LEFT JOIN lokasi l ON l.id=a.lokasi_id
                   WHERE oi.opname_id=? ORDER BY oi.id DESC", [$id]);
    opnameRecalc($id); $o = qOne("SELECT * FROM stock_opname WHERE id=?", [$id]);
    $pct = $o['total_data'] ? round(($o['total_cocok'] + $o['total_tidak_cocok']) / $o['total_data'] * 100) : 0;
?>
<div class="page-head">
  <div class="grow"><h1><?= e($o['nomor_opname']) ?></h1>
    <div class="small muted"><?= e($o['judul']) ?> &bull; <?= tgl($o['tanggal_mulai']) ?><?= $o['tanggal_selesai'] ? ' s/d ' . tgl($o['tanggal_selesai']) : '' ?>
      &bull; Pelaksana: <?= e(userName($o['pelaksana'])) ?></div></div>
  <div class="btn-group">
    <?= badge($o['status']) ?>
    <?php if ($o['status'] === 'SELESAI'): ?><a class="btn btn-primary" href="<?= u('modules/opname_pdf.php?id=' . $id) ?>"><?= iconSvg('download') ?> Laporan PDF</a><?php endif; ?>
    <a class="btn btn-outline" href="<?= u('modules/opname.php') ?>">&larr; Daftar</a>
  </div>
</div>

<div class="card"><div class="card-body">
  <div class="grid g4 mb-2">
    <div class="stat"><div class="s-ic i-slate"><?= iconSvg('box') ?></div><div><div class="v"><?= (int)$o['total_data'] ?></div><div class="l">Data Aset Target</div></div></div>
    <div class="stat"><div class="s-ic i-green"><?= iconSvg('check') ?></div><div><div class="v"><?= (int)$o['total_cocok'] ?></div><div class="l">Cocok</div></div></div>
    <div class="stat"><div class="s-ic i-amber"><?= iconSvg('alert') ?></div><div><div class="v"><?= (int)$o['total_tidak_cocok'] ?></div><div class="l">Tidak Cocok</div></div></div>
    <div class="stat"><div class="s-ic i-red"><?= iconSvg('search') ?></div><div><div class="v"><?= (int)$o['total_tidak_ditemukan'] ?></div><div class="l">Tidak Ditemukan*</div></div></div>
  </div>
  <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
  <div class="small muted mt">Progres scan <?= $pct ?>% dari <?= (int)$o['total_data'] ?> aset target. *Termasuk aset target yang belum di-scan.</div>
</div></div>

<?php if ($o['status'] === 'BERJALAN'): ?>
<form method="post" action="<?= u('modules/opname.php') ?>">
<?= csrfField() ?><input type="hidden" name="aksi" value="scan"><input type="hidden" name="id" value="<?= $id ?>">
<div class="scan-box">
  <div class="field"><label>Scan / Ketik Kode Aset</label>
    <input class="input" id="scanInput" name="kode" placeholder="AST-RIT-IT-2026-000001" autocomplete="off" autofocus></div>
  <div class="field" style="max-width:220px"><label>Kondisi Terlihat</label>
    <select class="input" name="kondisi_hasil"><option value="">Sama dengan data</option><option>BAIK</option><option>RUSAK RINGAN</option><option>RUSAK BERAT</option></select></div>
  <div class="field" style="max-width:260px"><label>Catatan</label><input class="input" name="catatan" placeholder="Opsional"></div>
  <button class="btn" style="background:#fff;color:#0f766e;font-weight:800"><?= iconSvg('scan') ?> Scan</button>
</div>
</form>
<?php elseif ($o['status'] === 'VALIDASI' && isPengadaan()): ?>
<div class="card"><div class="card-head"><h3>Validasi Akhir</h3></div><div class="card-body">
  <form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="selesai"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label>Catatan Hasil Opname</label><textarea class="input" name="catatan" rows="3" placeholder="Kesimpulan, tindak lanjut aset tidak ditemukan..."></textarea></div>
    <button class="btn btn-success mt"><?= iconSvg('check') ?> Selesaikan &amp; Terbitkan Laporan</button>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-head"><h3>Hasil Scan (<?= count($items) ?>)</h3></div><div class="card-body p0">
<table class="tbl">
  <thead><tr><th>Waktu</th><th>Kode Scan</th><th>Aset</th><th>Lokasi/Unit</th><th>Kondisi Hasil</th><th>Hasil</th><th>Catatan</th><?php if ($o['status'] === 'BERJALAN'): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$items): ?><tr><td colspan="8" class="empty">Belum ada aset yang di-scan.</td></tr><?php endif; ?>
  <?php foreach ($items as $x): ?>
  <tr>
    <td class="small"><?= tgl($x['scanned_at'], 'd/m H:i') ?></td>
    <td class="mono"><?= e($x['kode_scan']) ?></td>
    <td><?= $x['aset_id'] ? e($x['aset_nama']) : '<span class="muted">Tak dikenal</span>' ?></td>
    <td class="small"><?= e($x['lokasi_nama'] ?? '-') ?> / <?= e($x['unit'] ?? '-') ?></td>
    <td><?= $x['kondisi_hasil'] ? badge($x['kondisi_hasil']) : '-' ?><?= $x['kondisi_hasil'] && $x['kondisi_data'] && $x['kondisi_hasil'] !== $x['kondisi_data'] ? ' <span class="small muted">(data: ' . e($x['kondisi_data']) . ')</span>' : '' ?></td>
    <td><?= badge($x['hasil'] ?? '-') ?></td>
    <td class="small"><?= e($x['catatan'] ?? '-') ?></td>
    <?php if ($o['status'] === 'BERJALAN'): ?>
    <td class="text-right"><form method="post" class="inline-form" data-confirm="Hapus hasil scan ini?"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus_item"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-xs btn-ghost">✕</button></form></td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>

<?php if ($o['status'] === 'BERJALAN' && isPengadaan()): ?>
<form method="post" data-confirm="Kunci hasil scan dan lanjut ke validasi?"><?= csrfField() ?><input type="hidden" name="aksi" value="validasi"><input type="hidden" name="id" value="<?= $id ?>">
<button class="btn btn-primary"><?= iconSvg('check') ?> Selesai Scan &rarr; Validasi</button></form>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- baru ---------- */
if ($action === 'baru'):
    $lokasi = qAll("SELECT id,nama FROM lokasi WHERE tipe IN ('GEDUNG','LANTAI') ORDER BY nama");
?>
<div class="page-head"><h1>Buat Stock Opname</h1>
  <a class="btn btn-outline" href="<?= u('modules/opname.php') ?>">&larr; Batal</a></div>
<form method="post"><?= csrfField() ?><input type="hidden" name="aksi" value="buat">
<div class="card"><div class="card-body">
  <div class="form-grid">
    <div class="field full"><label>Judul <span class="req">*</span></label><input class="input" name="judul" required placeholder="Stock Opname Triwulan IV 2026"></div>
    <div class="field"><label>Tanggal Mulai</label><input class="input" type="date" name="tanggal_mulai" value="<?= date('Y-m-d') ?>"></div>
    <div class="field"><label>Filter Lokasi</label>
      <select class="input" name="lokasi_filter"><option value="">Semua Lokasi</option>
        <?php foreach ($lokasi as $l): ?><option><?= e($l['nama']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Filter Unit/Bidang</label>
      <select class="input" name="unit_filter"><option value="">Semua Unit</option>
        <?php foreach (unitOptions() as $x): ?><option><?= e($x) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="mt"><button class="btn btn-primary"><?= iconSvg('scan') ?> Buat &amp; Mulai Scan</button></div>
</div></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
/* ---------- list ---------- */
$rows = qAll("SELECT * FROM stock_opname ORDER BY id DESC LIMIT 100");
$bulanIni = (int)scalar("SELECT COUNT(*) FROM stock_opname WHERE DATE_FORMAT(tanggal_mulai,'%Y-%m')=?", [date('Y-m')]);
?>
<div class="page-head">
  <div class="grow"><h1>Stock Opname</h1>
    <div class="small muted"><?= $bulanIni ? 'Opname bulan ini sudah dibuat.' : 'Belum ada stock opname bulan ini.' ?></div></div>
  <a class="btn btn-primary" href="<?= u('modules/opname.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Buat Stock Opname</a>
</div>

<div class="card"><div class="card-body p0">
<div class="table-wrap" style="border:0;border-radius:0">
<table class="tbl">
  <thead><tr><th>Nomor</th><th>Judul</th><th>Periode</th><th>Filter</th><th class="num">Target</th><th class="num">Cocok</th><th class="num">Bermasalah</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">Belum ada opname.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
  <tr>
    <td class="mono"><a href="<?= u('modules/opname.php?id=' . $x['id']) ?>"><?= e($x['nomor_opname']) ?></a></td>
    <td><?= e($x['judul']) ?></td>
    <td class="small"><?= tgl($x['tanggal_mulai']) ?><?= $x['tanggal_selesai'] ? ' – ' . tgl($x['tanggal_selesai']) : '' ?></td>
    <td class="small"><?= e(trim(implode(' • ', array_filter([$x['lokasi_filter'], $x['unit_filter']]))) ?: 'Semua') ?></td>
    <td class="num"><?= (int)$x['total_data'] ?></td>
    <td class="num text-muted"><?= (int)$x['total_cocok'] ?></td>
    <td class="num"><?= (int)($x['total_tidak_cocok'] + $x['total_tidak_ditemukan']) ?></td>
    <td><?= badge($x['status']) ?></td>
    <td class="text-right"><a class="btn btn-sm btn-outline" href="<?= u('modules/opname.php?id=' . $x['id']) ?>"><?= iconSvg('eye') ?></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
