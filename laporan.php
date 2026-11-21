<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/laporan_lib.php';
require_login();

$jenis = isset(LAPORAN[$_GET['jenis'] ?? '']) ? $_GET['jenis'] : 'ringkasan';
$skop  = isset(SKOP[$_GET['skop'] ?? '']) ? $_GET['skop'] : 'kumulatif';
$opt = [
    'wilayah' => (int)($_GET['wilayah'] ?? 0) ?: null,
    'model'   => $_GET['model'] ?? '',
    'metrik'  => $_GET['metrik'] ?? 'rm',
];
$rep = jana_laporan($jenis, $skop, $opt);

if (($_GET['eksport'] ?? '') === 'xlsx') {
    require __DIR__ . '/includes/xlsx.php';
    SimpleXlsx::download($rep, APP_NAME . '_' . $jenis . '_' . $skop . '_' . date('Ymd') . '.xlsx');
}

$pageTitle = 'Laporan';
$qs = fn(array $over) => http_build_query(array_merge($_GET, $over));
require __DIR__ . '/includes/header.php';
?>
<div class="op-kepala no-print">
  <div>
    <h1 class="page-title">Laporan</h1>
    <p class="op-sub">Laporan penyaluran PPSB ikut PPK, model, keluasan dan musim. Eksport ke Excel atau cetak / PDF.</p>
  </div>
  <div class="op-tindakan">
    <a class="btn btn-sipadi btn-sm" href="?<?= e($qs(['eksport' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Eksport Excel</a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Cetak / PDF</button>
  </div>
</div>

<ul class="nav nav-tabs mb-3 no-print">
  <?php foreach (LAPORAN as $k => $t): ?>
    <li class="nav-item"><a class="nav-link <?= $k === $jenis ? 'active' : '' ?>" href="?<?= e(http_build_query(['jenis' => $k, 'skop' => $skop])) ?>"><?= e($t) ?></a></li>
  <?php endforeach; ?>
</ul>

<form class="card card-body mb-3 no-print" aria-label="Penapis laporan">
  <input type="hidden" name="jenis" value="<?= e($jenis) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-sm-6 col-lg-3">
      <label class="form-label small mb-1" for="lapSkop">Skop</label>
      <select name="skop" id="lapSkop" class="form-select form-select-sm">
        <?php foreach (SKOP as $k => $t): ?><option value="<?= $k ?>" <?= $k === $skop ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6 col-lg-2">
      <label class="form-label small mb-1" for="lapWilayah">Wilayah</label>
      <select name="wilayah" id="lapWilayah" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach (senarai_wilayah() as $w): ?><option value="<?= $w['id'] ?>" <?= $opt['wilayah'] == $w['id'] ? 'selected' : '' ?>><?= e($w['nama']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($jenis === 'musim'): ?>
    <div class="col-sm-6 col-lg-2">
      <label class="form-label small mb-1" for="lapModel">Model</label>
      <select name="model" id="lapModel" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach (MODELS as $k => $t): ?><option value="<?= $k ?>" <?= $opt['model'] === $k ? 'selected' : '' ?>><?= "$k - " . e($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6 col-lg-2">
      <label class="form-label small mb-1" for="lapMetrik">Metrik</label>
      <select name="metrik" id="lapMetrik" class="form-select form-select-sm">
        <?php foreach (METRIK as $k => $t): ?><option value="<?= $k ?>" <?= $opt['metrik'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-sipadi btn-sm">Papar</button></div>
  </div>
</form>

<?php if ($jenis === 'ringkasan'): ?>
<style>
  /* Laporan Ringkasan (30 lajur) tidak muat A4 — cetak pada A3 landskap. Laporan lain kekal A4 (app.css). */
  @media print { @page { size: A3 landscape; margin: 8mm; } }
</style>
<?php endif; ?>
<div class="card<?= $jenis === 'ringkasan' ? ' cetak-lebar' : '' ?>">
  <div class="card-body">
    <div class="text-center mb-3">
      <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME . ' – ' . APP_TITLE) ?>" class="app-logo-cetak">
      <h2 class="h5 mb-0"><?= e($rep['title']) ?></h2>
      <div class="text-muted small"><?= e($rep['subtitle']) ?> · Dijana <?= date('d/m/Y H:i') ?></div>
    </div>
    <?= render_report($rep) ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
