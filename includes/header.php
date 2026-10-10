<?php
/**
 * Header + sidebar. Sebelum include ini, definisikan:
 *   $pageTitle (string), $activeNav (key menu)
 */
require_once __DIR__ . '/helpers.php';
requireLogin();

$navItems = [
    ['group' => 'Utama', 'items' => [
        ['key' => 'dashboard',  'label' => 'Dashboard',            'url' => 'index.php',              'icon' => 'dashboard', 'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'notifikasi', 'label' => 'Notifikasi Saya',      'url' => 'modules/notifikasi.php', 'icon' => 'bell',      'roles' => ['admin','pengadaan','pimpinan','user']],
    ]],
    ['group' => 'Pengadaan', 'items' => [
        ['key' => 'kebutuhan',  'label' => 'Pengajuan Kebutuhan',  'url' => 'modules/kebutuhan.php',  'icon' => 'clipboard', 'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'rencana',    'label' => 'Rencana Pengadaan',    'url' => 'modules/rencana.php',    'icon' => 'cart',      'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'penerimaan', 'label' => 'Penerimaan Barang',    'url' => 'modules/penerimaan.php', 'icon' => 'truck',     'roles' => ['admin','pengadaan','pimpinan']],
    ]],
    ['group' => 'Inventaris & Aset', 'items' => [
        ['key' => 'aset',       'label' => 'Inventaris / Aset',    'url' => 'modules/aset.php',       'icon' => 'box',       'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'qr',         'label' => 'QR Code & Cetak',      'url' => 'modules/qr_batch.php',   'icon' => 'qr',        'roles' => ['admin','pengadaan']],
        ['key' => 'distribusi', 'label' => 'Distribusi Inventaris','url' => 'modules/distribusi.php', 'icon' => 'swap',      'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'mutasi',     'label' => 'Mutasi Aset',          'url' => 'modules/mutasi.php',     'icon' => 'swap',      'roles' => ['admin','pengadaan','pimpinan']],
        ['key' => 'penghapusan','label' => 'Penghapusan Aset',     'url' => 'modules/penghapusan.php','icon' => 'trash',     'roles' => ['admin','pengadaan','pimpinan']],
        ['key' => 'opname',     'label' => 'Stock Opname',         'url' => 'modules/opname.php',     'icon' => 'scan',      'roles' => ['admin','pengadaan','pimpinan']],
    ]],
    ['group' => 'Pemeliharaan', 'items' => [
        ['key' => 'kerusakan',  'label' => 'Laporan Kerusakan',    'url' => 'modules/kerusakan.php',  'icon' => 'alert',     'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'perbaikan',  'label' => 'Pemeliharaan & Perbaikan','url' => 'modules/perbaikan.php','icon' => 'wrench',   'roles' => ['admin','pengadaan','pimpinan']],
        ['key' => 'fasilitas',  'label' => 'Fasilitas Kantor',     'url' => 'modules/fasilitas.php',  'icon' => 'lamp',      'roles' => ['admin','pengadaan','pimpinan','user']],
    ]],
    ['group' => 'ATK', 'items' => [
        ['key' => 'atk',        'label' => 'Stok & Transaksi ATK', 'url' => 'modules/atk.php',        'icon' => 'pencil',    'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'atk_permintaan','label' => 'Permintaan ATK',    'url' => 'modules/atk_permintaan.php','icon' => 'clipboard','roles' => ['admin','pengadaan','pimpinan','user']],
    ]],
    ['group' => 'Kendaraan', 'items' => [
        ['key' => 'kendaraan',  'label' => 'Data Kendaraan',       'url' => 'modules/kendaraan.php',  'icon' => 'car',       'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'peminjaman', 'label' => 'Peminjaman Kendaraan', 'url' => 'modules/peminjaman.php', 'icon' => 'calendar',  'roles' => ['admin','pengadaan','pimpinan','user']],
        ['key' => 'servis',     'label' => 'Perawatan Kendaraan',  'url' => 'modules/servis.php',     'icon' => 'wrench',    'roles' => ['admin','pengadaan','pimpinan']],
    ]],
    ['group' => 'Master Data', 'items' => [
        ['key' => 'pegawai',    'label' => 'Data Pegawai',         'url' => 'modules/pegawai.php',    'icon' => 'users',     'roles' => ['admin','pengadaan','pimpinan']],
        ['key' => 'lokasi',     'label' => 'Data Lokasi',          'url' => 'modules/lokasi.php',     'icon' => 'building',  'roles' => ['admin','pengadaan','pimpinan']],
    ]],
    ['group' => 'Laporan', 'items' => [
        ['key' => 'laporan',    'label' => 'Laporan',              'url' => 'modules/laporan.php',    'icon' => 'chart',     'roles' => ['admin','pengadaan','pimpinan']],
    ]],
];

$roleNow = appRole();
$notifCount = 0;
try {
    $notifCount = (int)scalar("SELECT COUNT(*) FROM atk_permintaan WHERE pemohon=? AND status IN ('MENUNGGU PROSES','DISSETUJUI','MENUNGGU PENGAMBILAN')", [authId()])
                + (int)scalar("SELECT COUNT(*) FROM kerusakan WHERE pelapor=? AND status NOT IN ('SELESAI','DITOLAK')", [authId()])
                + (int)scalar("SELECT COUNT(*) FROM peminjaman_kendaraan WHERE pemohon=? AND status NOT IN ('DITERIMA','DITOLAK')", [authId()]);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? 'Dashboard') . ' — ' . APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" href="<?= e(LOGO_BASE . LOGO_FILE) ?>">
<link rel="stylesheet" href="<?= u('assets/css/style.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <img src="<?= e(LOGO_BASE . LOGO_FILE) ?>" alt="Logo LJU">
      <div>
        <div class="t1">SIPBJ LJU</div>
        <div class="t2">Pengadaan &bull; Inventaris</div>
      </div>
    </div>
    <nav class="nav">
      <?php foreach ($navItems as $g): 
          $vis = array_filter($g['items'], fn($i) => in_array($roleNow, $i['roles'], true));
          if (!$vis) continue; ?>
        <div class="nav-group">
          <div class="nav-label"><?= e($g['group']) ?></div>
          <?php foreach ($vis as $it): ?>
            <a href="<?= u($it['url']) ?>" class="<?= ($activeNav ?? '') === $it['key'] ? 'active' : '' ?>">
              <?= iconSvg($it['icon']) ?><span><?= e($it['label']) ?></span>
              <?php if ($it['key'] === 'notifikasi' && $notifCount > 0): ?><span class="pill"><?= $notifCount ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">PT. Lampung Jasa Utama<br>Sistem v<?= APP_VERSION ?></div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="hamburger" id="btnNav"><?= iconSvg('dashboard') ?></button>
      <div class="page-title"><?= e($pageTitle ?? 'Dashboard') ?></div>
      <div class="spacer"></div>
      <a class="bell" href="<?= u('modules/notifikasi.php') ?>" title="Notifikasi"><?= iconSvg('bell') ?><?php if ($notifCount > 0): ?><span class="dot"></span><?php endif; ?></a>
      <div class="userchip" id="userChip">
        <?php $av = avatarUrl(); ?>
        <img class="avatar" src="<?= e($av) ?>" alt="" onerror="this.outerHTML='&lt;div class=\\'avatar-fallback\\'&gt;<?= e(initials(authName())) ?>&lt;/div&gt;'">
        <div class="meta">
          <div class="nm"><?= e(authName()) ?></div>
          <div class="rl"><?= e(authPosition() ?: authRole()) ?> &bull; <?= e(authDivision() ?: '-') ?></div>
        </div>
        <div class="dropdown-menu">
          <div class="dd-head">
            <div style="font-weight:700"><?= e(authName()) ?></div>
            <div class="small text-muted"><?= e(authEmail() ?? '') ?></div>
            <div class="small"><span class="badge badge-teal"><?= e(ucfirst($roleNow)) ?></span></div>
          </div>
          <a href="<?= u('modules/profil.php') ?>"><?= iconSvg('users') ?> Profil Saya</a>
          <a href="<?= u('modules/notifikasi.php') ?>"><?= iconSvg('bell') ?> Notifikasi (<?= $notifCount ?>)</a>
          <a href="<?= SSO_PORTAL ?>"><?= iconSvg('back') ?> Portal LJU</a>
          <a href="<?= u('logout.php') ?>" data-confirm="Keluar dari aplikasi? (Anda akan diarahkan ke portal SSO)"><?= iconSvg('logout') ?> Keluar</a>
        </div>
      </div>
    </header>

    <div class="content">
      <?php foreach (getFlashes() as $f): ?>
        <div class="flash flash-<?= e($f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'error' : 'info')) ?>">
          <?= iconSvg($f['type'] === 'success' ? 'check' : 'alert') ?><span><?= e($f['msg']) ?></span>
        </div>
      <?php endforeach; ?>
