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
            go('modules/lokasi.php');
        }
        //cegah duplikat kode
        $dup = qOne("SELECT id FROM lokasi WHERE kode=? AND id<>?", [$kode, $id]);
        if ($dup) { flash("Kode \"$kode\" sudah dipakai.", 'error'); go('modules/lokasi.php'); }
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

function renderTreeNode(array $node, array &$jmlAset): void
{
    $d = $node['data'];
    $iconMap = ['GEDUNG' => 'building', 'LANTAI' => 'swap', 'RUANG' => 'box'];
    ?>
    <div class="tree-node">
      <div class="tree-row lvl-<?= $d['tipe'] === 'GEDUNG' ? 1 : ($d['tipe'] === 'LANTAI' ? 2 : 3) ?>">
        <span class="tree-ic"><?= iconSvg($iconMap[$d['tipe']] ?? 'box') ?></span>
        <div class="tree-main">
          <div class="tree-name"><?= e($d['nama']) ?>
            <span class="badge badge-<?= $d['tipe'] === 'GEDUNG' ? 'teal' : ($d['tipe'] === 'LANTAI' ? 'blue' : 'slate') ?>"><?= e($d['tipe']) ?></span>
            <?php if (!$d['is_active']): ?><span class="badge badge-red">NONAKTIF</span><?php endif; ?>
          </div>
          <div class="tree-sub muted small">
            Kode: <b><?= e($d['kode']) ?></b>
            &bull; <?= $jmlAset[$d['id']] ?? 0 ?> aset
            <?= $d['keterangan'] ? ' &bull; ' . e($d['keterangan']) : '' ?>
          </div>
        </div>
        <div class="tree-act">
          <a class="btn btn-sm btn-outline" href="lokasi.php?edit=<?= $d['id'] ?>"><?= iconSvg('pencil') ?></a>
          <form method="post" onsubmit="return confirm('Hapus lokasi <?= e(addslashes($d['nama'])) ?>?');" style="display:inline">
            <?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $d['id'] ?>">
            <button class="btn btn-sm btn-danger-x" title="Hapus"><?= iconSvg('trash') ?></button>
          </form>
        </div>
      </div>
      <?php if ($node['children']): ?><div class="tree-children"><?php
        foreach ($node['children'] as $ch) renderTreeNode($ch, $jmlAset);
      ?></div><?php endif; ?>
    </div>
    <?php
}
?>
<div class="page-head">
  <div><h1><?= iconSvg('building') ?> Data Lokasi</h1>
    <p class="muted">Struktur hierarki gedung, lantai, dan ruang kerja.</p></div>
</div>

<div class="grid-2" style="grid-template-columns: 360px 1fr; align-items:start;">
  <!-- form -->
  <div class="card">
    <div class="card-head"><h3><?= $edit ? 'Edit Lokasi' : 'Tambah Lokasi' ?></h3></div>
    <form method="post" class="card-body form-grid">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <label>Nama Lokasi *
        <input type="text" name="nama" required maxlength="100" value="<?= e($edit['nama'] ?? '') ?>" placeholder="cth: Ruang Direktur">
      </label>
      <label>Kode *
        <input type="text" name="kode" required maxlength="20" value="<?= e($edit['kode'] ?? '') ?>" placeholder="cth: R-DIR" style="text-transform:uppercase">
      </label>
      <label>Tipe
        <select name="tipe" id="tipeLoc">
          <?php foreach (['GEDUNG', 'LANTAI', 'RUANG'] as $t): ?>
            <option value="<?= $t ?>" <?= ($edit['tipe'] ?? 'RUANG') === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Induk (opsional)
        <select name="parent_id">
          <option value="0">— Tanpa induk (Gedung) —</option>
          <?php foreach ($rows as $r): if ($edit && $r['id'] == $edit['id']) continue; ?>
            <option value="<?= $r['id'] ?>" <?= ((int)($edit['parent_id'] ?? 0)) === (int)$r['id'] ? 'selected' : '' ?>>
              <?= str_repeat('— ', $r['tipe'] === 'RUANG' ? 2 : ($r['tipe'] === 'LANTAI' ? 1 : 0)) ?>[<?= e($r['tipe']) ?>] <?= e($r['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Keterangan
        <input type="text" name="keterangan" maxlength="255" value="<?= e($edit['keterangan'] ?? '') ?>">
      </label>
      <?php if ($edit): ?>
        <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>> <span class="chk-box"></span>&nbsp;Aktif</label>
      <?php endif; ?>
      <div class="btn-row">
        <button class="btn btn-primary"><?= iconSvg('check') ?> <?= $edit ? 'Simpan Perubahan' : 'Tambah' ?></button>
        <?php if ($edit): ?><a class="btn btn-ghost" href="lokasi.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>

  <!-- tree -->
  <div class="card">
    <div class="card-head"><h3>Struktur Lokasi (<?= count($rows) ?>)</h3></div>
    <div class="card-body">
      <?php if (!$roots): ?>
        <p class="muted">Belum ada data lokasi.</p>
      <?php else: foreach ($roots as $n) renderTreeNode($n, $jmlAset); endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
