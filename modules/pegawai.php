<?php
/** Data Pegawai — sinkron/read-only dari tabel users (DB SSO) */
$pageTitle = 'Data Pegawai';
$activeNav = 'pegawai';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
requireRole(['admin', 'pengadaan', 'pimpinan']);
require_once __DIR__ . '/../includes/header.php';

/* ---------- aksi: update nomor HP (kolom phone jika ada, fallback rfid->simpan lokal) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'set_hp') {
    verifyCsrf();
    $id  = (int)($_POST['id'] ?? 0);
    $hp  = trim($_POST['nomor_hp'] ?? '');
    // simpan mapping HP lokal agar tidak mengubah struktur DB SSO
    q("CREATE TABLE IF NOT EXISTS pegawai_extra (
         user_id INT PRIMARY KEY, nomor_hp VARCHAR(30) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    q("INSERT INTO pegawai_extra (user_id,nomor_hp) VALUES (?,?)
       ON DUPLICATE KEY UPDATE nomor_hp=VALUES(nomor_hp)", [$id, $hp]);
    logActivity('Pegawai', 'Update No. HP user #' . $id, $hp);
    flash('Nomor HP tersimpan.');
    go('modules/pegawai.php?' . http_build_query(array_intersect_key($_GET, array_flip(['cari','divisi','bidang','status']))));
}

/* ---------- filter ---------- */
$cari   = trim($_GET['cari'] ?? '');
$divisi = trim($_GET['divisi'] ?? '');   // department -> Nama Divisi (aplikasi)
$bidang = trim($_GET['bidang'] ?? '');   // division   -> Nama Bidang (aplikasi)
$status = $_GET['status'] ?? '';

$where = ['1=1']; $pr = [];
if ($cari !== '')   { $where[] = '(name LIKE ? OR username LIKE ? OR email LIKE ? OR nik LIKE ?)'; $pr = array_merge($pr, ["%$cari%","%$cari%","%$cari%","%$cari%"]); }
if ($divisi !== '') { $where[] = 'department=?'; $pr[] = $divisi; }
if ($bidang !== '') { $where[] = 'division=?';  $pr[] = $bidang; }
if ($status !== '') { $where[] = 'is_active=?'; $pr[] = $status === 'AKTIF' ? 1 : 0; }
$w = implode(' AND ', $where);

try {
    $st = ssoDb()->prepare("SELECT * FROM users WHERE $w ORDER BY name");
    $st->execute($pr);
    $pegawai = $st->fetchAll();
} catch (Throwable $e) {
    $pegawai = [];
    flash('Gagal membaca data pegawai dari database SSO: ' . $e->getMessage(), 'error');
}
// mapping HP lokal
$hpMap = [];
try {
    foreach (qAll("SELECT user_id,nomor_hp FROM pegawai_extra") as $x) $hpMap[$x['user_id']] = $x['nomor_hp'];
} catch (Throwable $e) {}

$optDivisi = ['Direksi','Keuangan & Umum','Bisnis','Sekretariat','Pengawas Internal'];
$optBidang = unitOptions();
$totalAktif = count(array_filter($pegawai, fn($p) => (int)$p['is_active'] === 1));
?>
<div class="page-head">
  <div class="grow"><h1>Data Pegawai</h1>
    <p class="small muted">Terhubung langsung (sync) dengan tabel <code>users</code> pada database SSO. Data induk dikelola melalui portal LJU.</p></div>
</div>

<div class="grid g3" style="margin-bottom:20px">
  <div class="stat"><div class="s-ic i-teal"><?= iconSvg('users') ?></div><div><div class="v"><?= count($pegawai) ?></div><div class="l">Total Hasil Ditampilkan</div></div></div>
  <div class="stat"><div class="s-ic i-green"><?= iconSvg('check') ?></div><div><div class="v"><?= $totalAktif ?></div><div class="l">Status Aktif</div></div></div>
  <div class="stat"><div class="s-ic i-red"><?= iconSvg('alert') ?></div><div><div class="v"><?= count($pegawai) - $totalAktif ?></div><div class="l">Nonaktif</div></div></div>
</div>

<div class="card">
  <div class="card-head"><h3>Daftar Pegawai</h3></div>
  <div class="card-body">
    <form method="get" class="filter-bar" style="margin:0">
      <input class="input" type="text" name="cari" placeholder="Cari nama / NIP / email…" value="<?= e($cari) ?>">
      <select class="input" name="bidang"><option value="">Semua Bidang</option>
        <?php foreach ($optBidang as $o): ?><option value="<?= e($o) ?>" <?= $bidang===$o?'selected':'' ?>><?= e($o) ?></option><?php endforeach; ?>
      </select>
      <select class="input" name="divisi"><option value="">Semua Divisi</option>
        <?php foreach ($optDivisi as $o): ?><option value="<?= e($o) ?>" <?= $divisi===$o?'selected':'' ?>><?= e($o) ?></option><?php endforeach; ?>
      </select>
      <select class="input" name="status"><option value="">Semua Status</option>
        <option value="AKTIF" <?= $status==='AKTIF'?'selected':'' ?>>Aktif</option>
        <option value="NONAKTIF" <?= $status==='NONAKTIF'?'selected':'' ?>>Nonaktif</option>
      </select>
      <button class="btn btn-sm btn-primary"><?= iconSvg('search') ?> Filter</button>
      <a class="btn btn-sm btn-ghost" href="<?= u('modules/pegawai.php') ?>">Reset</a>
    </form>
  </div>
  <div class="card-body p0">
  <div class="table-wrap" style="border:0;border-radius:0">
    <table class="tbl">
      <thead><tr>
        <th>Pegawai</th><th>NIP / ID</th><th>Jabatan</th><th>Nama Bidang</th><th>Nama Divisi</th>
        <th>Email</th><th>No. HP</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$pegawai): ?>
        <tr><td colspan="9" class="empty">Tidak ada pegawai sesuai filter.</td></tr>
      <?php endif; foreach ($pegawai as $p): ?>
        <tr>
          <td>
            <div class="user-cell">
              <?php $av = !empty($p['avatar']) ? AVATAR_BASE . rawurlencode($p['avatar']) : DEFAULT_AVATAR; ?>
              <img class="avatar avatar-sm" src="<?= e($av) ?>" alt="" onerror="this.outerHTML='&lt;div class=\\'avatar-fallback avatar-sm\\'&gt;<?= e(initials($p['name'])) ?>&lt;/div&gt;'">
              <div><b><?= e($p['name']) ?></b><div class="muted small">@<?= e($p['username']) ?></div></div>
            </div>
          </td>
          <td class="mono small"><?= e($p['nik'] ?: ('-' . $p['id'])) ?></td>
          <td><?= e($p['position'] ?: '-') ?></td>
          <td><?= e($p['division'] ?: '-') ?></td>
          <td><?= e($p['department'] ?: '-') ?></td>
          <td class="small"><?= e($p['email'] ?: '-') ?></td>
          <td class="small"><?= e($hpMap[$p['id']] ?? '-') ?></td>
          <td><?= $p['is_active'] ? '<span class="badge badge-green">AKTIF</span>' : '<span class="badge badge-slate">NONAKTIF</span>' ?></td>
          <td class="text-right" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline" onclick="toggleHp(<?= (int)$p['id'] ?>)">Set HP</button>
          </td>
        </tr>
        <tr id="hprow-<?= (int)$p['id'] ?>" style="display:none">
          <td colspan="9">
            <form method="post" class="inline-form">
              <?= csrfField() ?><input type="hidden" name="aksi" value="set_hp"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <span class="small muted">Nomor HP <?= e($p['name']) ?>:</span>
              <input class="input" type="text" name="nomor_hp" value="<?= e($hpMap[$p['id']] ?? '') ?>" placeholder="08xxxxxxxxxx" style="max-width:240px">
              <button class="btn btn-sm btn-primary"><?= iconSvg('check') ?> Simpan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>
<script>
function toggleHp(id){ var r=document.getElementById('hprow-'+id); r.style.display = r.style.display==='none' ? '' : 'none'; }
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
