<?php
/**
 * Layout global EPMS (UI-02): sidebar kiri + header atas + kawasan kandungan.
 * Ditutup oleh includes/footer.php. Halaman login / tukar kata laluan / install mempunyai layout sendiri.
 * @var string $pageTitle
 */
$pageTitle = $pageTitle ?? '';
$active = basename($_SERVER['SCRIPT_NAME'], '.php');
if (in_array($active, ['penyaluran_form', 'penyaluran_lihat'], true)) $active = 'penyaluran';

// Menu ikut modul sebenar sahaja (fail => [label, ikon]), disusun ikut aliran kerja harian (UI-05):
// pantau → urus penyaluran → rujuk direktori → laporan → pentadbiran sistem
$menu = [
    'Utama'                => ['index' => ['Dashboard', 'speedometer2']],
    'Pengurusan Penyaluran' => ['penyaluran' => ['Rekod Penyaluran', 'table']],
    'Direktori'            => ['ppk' => ['Direktori PPK', 'building']],
    'Laporan & Analisis'   => ['laporan' => ['Laporan', 'file-earmark-bar-graph']],
    'Pentadbiran Sistem'   => ['pengguna' => ['Pengguna', 'people'], 'backup' => ['Backup & Restore', 'database-check']],
];
$menuAktif = null;
foreach ($menu as $item) if (isset($item[$active])) $menuAktif = $item[$active][0];

$me = current_user() ?? [];
$namaPengguna = (string)($me['nama'] ?? '');
$inisial = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($namaPengguna)) ?: [], 0, 2)))) ?: '?';
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ? "$pageTitle · " : '') . APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/app.css?v=<?= (int)@filemtime(__DIR__ . "/../assets/app.css") ?>" rel="stylesheet">
<script>
  // Pulihkan keadaan sidebar sebelum paparan pertama (elak "kelipan"). Tablet & laptop (768–1439px) lalai: mod ikon.
  try { var s = localStorage.getItem('epms-sidebar');
        if (s === 'mini' || (s === null && innerWidth >= 768 && innerWidth < 1440)) document.documentElement.classList.add('sidebar-mini'); } catch (e) {}
</script>
</head>
<body class="app-body">
<a class="visually-hidden-focusable app-skip" href="#kandungan">Langkau ke kandungan</a>

<aside id="sidebar" class="app-sidebar offcanvas-md offcanvas-start no-print" tabindex="-1" aria-label="Menu utama">
  <div class="app-sidebar-panel">
    <div class="app-sidebar-brand">
      <a href="index.php" class="app-brand-link" title="<?= e(APP_NAME . ' – ' . APP_TITLE) ?>">
        <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME . ' – ' . APP_TITLE) ?>" class="app-logo-sidebar">
        <span class="app-brand-nama"><?= e(APP_TITLE) ?></span>
      </a>
      <button type="button" class="btn-close d-md-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Tutup menu"></button>
    </div>

    <nav class="app-sidebar-nav">
      <?php foreach ($menu as $kumpulan => $item): ?>
        <div class="app-nav-kumpulan"><span class="app-nav-tajuk"><?= e($kumpulan) ?></span>
          <ul class="list-unstyled m-0">
            <?php foreach ($item as $file => [$label, $icon]): $isActive = $active === $file; ?>
              <li><a class="app-nav-link<?= $isActive ? ' active' : '' ?>" href="<?= $file ?>.php"<?= $isActive ? ' aria-current="page"' : '' ?>
                     data-epms-tip="<?= e($label) ?>"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><span class="app-nav-label"><?= e($label) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="app-sidebar-user">
      <span class="app-avatar" aria-hidden="true"><?= e($inisial) ?></span>
      <span class="app-user-teks"><span class="app-user-nama"><?= e($namaPengguna) ?></span><span class="app-user-id">@<?= e($me['username'] ?? '') ?></span></span>
    </div>
  </div>
</aside>

<div class="app-main">
  <header class="app-topbar no-print">
    <button type="button" class="btn app-toggle" id="togolSidebar" aria-controls="sidebar" aria-expanded="true" aria-label="Tunjuk / sembunyi menu">
      <i class="bi bi-list" aria-hidden="true"></i></button>

    <nav aria-label="breadcrumb" class="app-breadcrumb">
      <ol class="breadcrumb m-0">
        <li class="breadcrumb-item d-none d-sm-block"><a href="index.php"><?= e(APP_NAME) ?></a></li>
        <?php if ($menuAktif && $menuAktif !== $pageTitle): ?>
          <li class="breadcrumb-item d-none d-sm-block"><a href="<?= $active ?>.php"><?= e($menuAktif) ?></a></li>
        <?php endif; ?>
        <li class="breadcrumb-item active" aria-current="page"><?= e($pageTitle) ?></li>
      </ol>
    </nav>

    <div class="dropdown ms-auto">
      <button class="btn app-profil dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu profil">
        <span class="app-avatar" aria-hidden="true"><?= e($inisial) ?></span>
        <span class="d-none d-sm-inline app-profil-nama"><?= e($namaPengguna) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <li><div class="dropdown-header"><div class="fw-semibold text-body"><?= e($namaPengguna) ?></div><div class="small">@<?= e($me['username'] ?? '') ?></div></div></li>
        <li><a class="dropdown-item" href="pengguna.php#tukar-katalaluan"><i class="bi bi-key me-2" aria-hidden="true"></i>Tukar Kata Laluan</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" action="logout.php" class="m-0"><?= csrf_field() ?>
          <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Log Keluar</button></form></li>
      </ul>
    </div>
  </header>

<main id="kandungan" class="app-content container-fluid">
<?php foreach (flashes() as [$t, $m]): ?>
  <div class="alert alert-<?= e($t) ?> alert-dismissible fade show no-print"><?= e($m) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
