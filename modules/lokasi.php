<?php
/** Data Lokasi — hierarki Gedung > Lantai > Ruang */
$pageTitle = 'Data Lokasi';
$activeNav = 'lokasi';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);
require_once __DIR__ . '/../includes/header.php';

/* ---------- aksi ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id       = (int)($_POST['id'] ?? 0);
        $nama     = trim($_POST['nama'] ?? '');
        $kode     = strtoupper(trim($_POST['kode'] ?? ''));
        $tipe     = $_POST['tipe'] ?? 'RUANG';
        $parent   = (int)($_POST['parent_id'] ?? 0) ?: null;
        $ket      = trim($_POST['keterangan'] ?? '');
        if ($nama === '' || $kode === '') {
            flash('Nama dan kode lokasi wajib diisi.', 'error');
            go('modules/lokasi.php' . ($id ? "?edit=$id" : '?aksi=baru'));
        }
        //cegah duplikat kode
        $dup = qOne("SELECT id FROM lokasi WHERE kode=? AND id<>?", [$kode, $id]);
        if ($dup) { flash("Kode \"$kode\" sudah dipakai.", 'error'); go('modules/lokasi.php' . ($id ? "?edit=$id" : '?aksi=baru')); }
        //cegah self-parent
        if ($parent === $id) { flash('Induk tidak boleh dirinya sendiri.', 'error'); go('modules/lokasi.php'); }

        if ($id) {
            q("UPDATE lokasi SET nama=?, kode=?, tipe=?, parent_id=?, keterangan=?, is_active=? WHERE id=?",
              [$nama, $kode, $tipe, $parent, $ket ?: null, isset($_POST['is_active']) ? 1 : 0, $id]);
            logActivity('Lokasi', 'Update lokasi #' . $id, $nama);
            flash('Lokasi diperbarui.');
        } else {
            q("INSERT INTO lokasi (nama,kode,tipe,parent_id,keterangan,is_active) VALUES (?,?,?,?,?,1)",
              [$nama, $kode, $tipe, $parent, $ket ?: null]);
            logActivity('Lokasi', 'Tambah lokasi', $nama);
            flash('Lokasi baru ditambahkan.');
        }
        go('modules/lokasi.php');
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $usedAset = (int)scalar("SELECT COUNT(*) FROM aset WHERE lokasi_id=?", [$id]);
        $child    = (int)scalar("SELECT COUNT(*) FROM lokasi WHERE parent_id=?", [$id]);
        if ($usedAset || $child) {
            flash("Lokasi tidak bisa dihapus: {$usedAset} aset & {$child} sub-lokasi terkait.", 'error');
        } else {
            $l = qOne("SELECT nama FROM lokasi WHERE id=?", [$id]);
            q("DELETE FROM lokasi WHERE id=?", [$id]);
            logActivity('Lokasi', 'Hapus lokasi', $l['nama'] ?? null);
            flash('Lokasi dihapus.');
        }
        go('modules/lokasi.php');
    }
}

/* ---------- data tree ---------- */
$rows = qAll("SELECT * FROM lokasi ORDER BY FIELD(tipe,'GEDUNG','LANTAI','RUANG'), nama");
$byId = [];
foreach ($rows as $r) $byId[$r['id']] = ['data' => $r, 'children' => []];
$roots = [];
foreach ($byId as $id => &$node) {
    $p = $node['data']['parent_id'];
    if ($p && isset($byId[$p])) $byId[$p]['children'][] = &$node;
    else $roots[] = &$node;
}
unset($node);
$jmlAset = [];
foreach (qAll("SELECT lokasi_id, COUNT(*) c FROM aset WHERE status<>'DIHAPUS' GROUP BY lokasi_id") as $x) {
    $jmlAset[$x['lokasi_id']] = (int)$x['c'];
}

$editId = (int)($_GET['edit'] ?? 0);
$edit   = $editId ? qOne("SELECT * FROM lokasi WHERE id=?", [$editId]) : null;
$showForm = $edit || ($_GET['aksi'] ?? '') === 'baru';

$tipeBadge = ['GEDUNG' => 'teal', 'LANTAI' => 'blue', 'RUANG' => 'slate'];
$tipeIcon  = ['GEDUNG' => 'building', 'LANTAI' => 'swap', 'RUANG' => 'box'];

/* flatten pohon menjadi daftar baris dengan indentasi */
$treeRows = [];
function walkTree(array $node, array $byId, array $jmlAset, int $depth, array &$out): void
{
    $d = $node['data'];
    $out[] = [
        'id' => $d['id'], 'nama' => $d['nama'], 'kode' => $d['kode'], 'tipe' => $d['tipe'],
        'aktif' => (int)$d['is_active'], 'ket' => $d['keterangan'],
        'aset' => $jmlAset[$d['id']] ?? 0, 'pad' => $depth * 26,
    ];
    foreach ($node['children'] as $ch) walkTree($ch, $byId, $jmlAset, $depth + 1, $out);
}
foreach ($roots as $n) walkTree($n, $byId, $jmlAset, 0, $treeRows);
?>
<div class="page-head">
  <div class="grow">
    <h1>Data Lokasi</h1>
    <p class="small muted">Struktur hierarki gedung, lantai, dan ruang kerja.</p>
  </div>
  <div class="btn-group">
    <?php if (!$showForm): ?><a class="btn btn-primary" href="<?= u('modules/lokasi.php?aksi=baru') ?>"><?= iconSvg('plus') ?> Tambah Lokasi</a><?php endif; ?>
  </div>
</div>

<?php if ($showForm): ?>
<div class="card">
  <div class="card-head"><h3><?= $edit ? 'Edit Lokasi — ' . e($edit['nama']) : 'Tambah Lokasi' ?></h3>
    <a class="btn btn-sm btn-ghost" href="<?= u('modules/lokasi.php') ?>">Tutup &times;</a></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="form-grid">
        <div class="field"><label>Nama Lokasi <span class="req">*</span></label>
          <input class="input" type="text" name="nama" maxlength="100" required value="<?= e($edit['nama'] ?? '') ?>" placeholder="cth: Ruang Direktur"></div>
        <div class="field"><label>Kode <span class="req">*</span></label>
          <input class="input mono" type="text" name="kode" maxlength="20" required value="<?= e($edit['kode'] ?? '') ?>" placeholder="cth: R-DIR"></div>
        <div class="field"><label>Tipe</label>
          <select class="input" name="tipe">
            <?php foreach (['GEDUNG', 'LANTAI', 'RUANG'] as $t): ?>
              <option value="<?= $t ?>" <?= ($edit['tipe'] ?? 'RUANG') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Induk <span class="help">(opsional)</span></label>
          <select class="input" name="parent_id">
            <option value="0">— Tanpa induk (Gedung) —</option>
            <?php foreach ($rows as $r): if ($edit && $r['id'] == $edit['id']) continue; ?>
              <option value="<?= $r['id'] ?>" <?= ((int)($edit['parent_id'] ?? 0)) === (int)$r['id'] ? 'selected' : '' ?>>
                [<?= e($r['tipe']) ?>] <?= e($r['nama']) ?>
              </option>
            <?php endforeach; ?>
          </select></div>
        <div class="field full"><label>Keterangan</label>
          <input class="input" type="text" name="keterangan" maxlength="255" value="<?= e($edit['keterangan'] ?? '') ?>"></div>
        <?php if ($edit): ?>
        <div class="field"><label class="checkline" style="margin:0">
          <input type="checkbox" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>> Lokasi aktif</label></div>
        <?php endif; ?>
      </div>
      <div class="mt actions">
        <button class="btn btn-primary"><?= iconSvg('check') ?> <?= $edit ? 'Simpan Perubahan' : 'Tambah Lokasi' ?></button>
        <a class="btn btn-outline" href="<?= u('modules/lokasi.php') ?>">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mb"><div class="card-body">
  <div class="filter-bar" style="margin:0">
    <span class="chip"><?= count($rows) ?> lokasi terdaftar</span>
    <span class="chip">Gedung: <?= count(array_filter($rows, fn($r) => $r['tipe'] === 'GEDUNG')) ?></span>
    <span class="chip">Lantai: <?= count(array_filter($rows, fn($r) => $r['tipe'] === 'LANTAI')) ?></span>
    <span class="chip">Ruang: <?= count(array_filter($rows, fn($r) => $r['tipe'] === 'RUANG')) ?></span>
  </div>
</div></div>

<div class="card"><div class="card-body p0">
  <div class="table-wrap" style="border:0;border-radius:0">
  <table class="tbl">
    <thead><tr><th>Nama Lokasi</th><th>Kode</th><th>Tipe</th><th>Aset</th><th>Keterangan</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
    <tbody>
    <?php if (!$treeRows): ?>
      <tr><td colspan="7" class="empty"><?= iconSvg('building') ?><br>Belum ada data lokasi.<br><a class="btn btn-sm btn-primary mt-2" href="<?= u('modules/lokasi.php?aksi=baru') ?>">Tambah Sekarang</a></td></tr>
    <?php endif; ?>
    <?php foreach ($treeRows as $t): ?>
    <tr>
      <td>
        <span style="display:inline-flex;align-items:center;gap:9px;padding-left:<?= (int)$t['pad'] ?>px">
          <span class="dot-status <?= $t['tipe'] === 'GEDUNG' ? 'ds-teal' : ($t['tipe'] === 'LANTAI' ? 'ds-blue' : 'ds-slate') ?>"></span>
          <?= $t['pad'] > 0 ? '<span class="muted">&mdash; </span>' : '' ?>
          <b><?= e($t['nama']) ?></b>
        </span>
      </td>
      <td class="mono"><?= e($t['kode']) ?></td>
      <td><span class="badge badge-<?= $tipeBadge[$t['tipe']] ?? 'slate' ?>"><?= e($t['tipe']) ?></span></td>
      <td class="num"><?= (int)$t['aset'] ?></td>
      <td class="small muted"><?= e($t['ket'] ?: '-') ?></td>
      <td><?= $t['aktif'] ? '<span class="badge badge-green">AKTIF</span>' : '<span class="badge badge-red">NONAKTIF</span>' ?></td>
      <td class="text-right">
        <div class="actions" style="justify-content:flex-end">
          <a class="btn btn-sm btn-outline" href="<?= u('modules/lokasi.php?edit=' . $t['id']) ?>" title="Edit"><?= iconSvg('pencil') ?></a>
          <form method="post" data-confirm="Hapus lokasi &quot;<?= e($t['nama']) ?>&quot;?">
            <?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn btn-sm btn-danger" title="Hapus"><?= iconSvg('trash') ?></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
