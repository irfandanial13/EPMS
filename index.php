<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/laporan_lib.php';
require __DIR__ . '/includes/backup_lib.php';
require_login();

/* ---------- Penapis (baca sahaja; nilai disahkan terhadap data rujukan) ---------- */
$skop = ($_GET['skop'] ?? 'kumulatif') === 'semasa' ? 'semasa' : 'kumulatif';
$wilayahList = senarai_wilayah();
$musimList = senarai_musim();
$wilayahId = (int)($_GET['wilayah'] ?? 0);
if (!in_array($wilayahId, array_map('intval', array_column($wilayahList, 'id')), true)) $wilayahId = 0;
$musim = (string)($_GET['musim'] ?? '');
if (!in_array($musim, $musimList, true)) $musim = '';
// PPK: mesti wujud; jika wilayah dipilih, PPK mesti dalam wilayah itu (jika tidak, penapis PPK diabaikan)
$ppkList = senarai_ppk();
$ppkInfo = array_column($ppkList, null, 'id');
$ppkId = (int)($_GET['ppk'] ?? 0);
if (!isset($ppkInfo[$ppkId]) || ($wilayahId && (int)$ppkInfo[$ppkId]['wilayah_id'] !== $wilayahId)) $ppkId = 0;

$where = [skop_sql($skop)];
$params = [];
if ($wilayahId) { $where[] = 'p.wilayah_id = ?'; $params[] = $wilayahId; }
if ($musim !== '') { $where[] = 'x.musim = ?'; $params[] = $musim; }
if ($ppkId) { $where[] = 'x.ppk_id = ?'; $params[] = $ppkId; }
$from = 'FROM penyaluran x JOIN ppk p ON p.id = x.ppk_id WHERE ' . implode(' AND ', $where);

$pdo = db();
$q = function (string $sql) use ($pdo, $params) { $st = $pdo->prepare($sql); $st->execute($params); return $st; };

/* ---------- Data (query baca sahaja) ---------- */
$k = $q("SELECT COUNT(*) n, SUM(x.bil_peladang) bil, SUM(x.luas_ha) luas, SUM(x.sewaan) sewa, SUM(x.modal_operasi) mo,
                SUM(x.status='AKTIF') aktif, SUM(x.status='SAMBUNG') sambung, SUM(x.status='TAMAT') tamat, COUNT(DISTINCT x.ppk_id) ppk $from")->fetch();
$jumlahRm = (float)$k['sewa'] + (float)$k['mo'];
// Pecahan Sewaan/Modal ikut klasifikasi EP (sama seperti laporan Ringkasan, Fasa 2.1)
$pecahan = pecahan_sewa_modal(agregat_ikut_model($from, $params));

$byMusim = $q("SELECT x.musim, MIN(x.musim_susun) s, SUM(x.sewaan + x.modal_operasi) rm, COUNT(*) n
               $from GROUP BY x.musim ORDER BY s, x.musim")->fetchAll();
$byWilayah = $q("SELECT p.wilayah_id id, SUM(x.sewaan + x.modal_operasi) rm, COUNT(*) n, COUNT(DISTINCT x.ppk_id) ppk
                 $from GROUP BY p.wilayah_id ORDER BY p.wilayah_id")->fetchAll(PDO::FETCH_UNIQUE);
$byModel = $q("SELECT x.model, COUNT(*) n, SUM(x.bil_peladang) bil, SUM(x.luas_ha) luas, SUM(x.sewaan + x.modal_operasi) rm
               $from GROUP BY x.model ORDER BY rm DESC")->fetchAll();
$topPpk = $q("SELECT p.id, p.kod, p.nama, SUM(x.sewaan + x.modal_operasi) rm $from GROUP BY p.id ORDER BY rm DESC, p.kod LIMIT 10")->fetchAll();

// Status data & backup (sumber sebenar: jadual penyaluran/ppk dan fail backup)
$data = $pdo->query("SELECT COUNT(*) n, MAX(updated_at) kemaskini, MAX(musim_susun) susun FROM penyaluran")->fetch();
$musimTerkini = $data['susun'] ? ((int)$data['susun'] % 10) . '/' . intdiv((int)$data['susun'], 10) : '-';
$bilPpk = (int)$pdo->query('SELECT COUNT(*) FROM ppk')->fetchColumn();
$backup = status_backup(senarai_backup());

/* ---------- Pautan (parameter sama seperti penapis modul Rekod / Laporan) ---------- */
$wilayahNama = array_column($wilayahList, 'nama', 'id');
$tapisAsas = ($skop === 'semasa' ? ['status' => 'AKTIF'] : ['jenis' => 'ASAL'])
           + array_filter(['wilayah' => $wilayahId ?: null, 'ppk' => $ppkId ?: null, 'musim' => $musim !== '' ? $musim : null], fn($v) => $v !== null);
$urlRekod = fn(array $tambah = []) => 'penyaluran.php?' . http_build_query(array_merge($tapisAsas, $tambah));
$urlDash = fn(array $ubah) => '?' . http_build_query(array_filter(array_merge(['skop' => $skop, 'wilayah' => $wilayahId ?: '', 'ppk' => $ppkId ?: '', 'musim' => $musim], $ubah), fn($v) => $v !== '' && $v !== null));
$urlLaporan = 'laporan.php?' . http_build_query(array_filter(['jenis' => 'ringkasan', 'skop' => $skop, 'wilayah' => $wilayahId ?: null]));

$hari = ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'];
$bulan = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];
$tarikh = $hari[(int)date('w')] . ', ' . date('j') . ' ' . $bulan[(int)date('n')] . ' ' . date('Y');
$tapisan = array_filter([$wilayahId ? $wilayahNama[$wilayahId] : null, $ppkId ? $ppkInfo[$ppkId]['kod'] . ' ' . $ppkInfo[$ppkId]['nama'] : null, $musim !== '' ? "Musim $musim" : null]);
$adaData = (int)$k['n'] > 0;

$chartData = [
    'musim' => ['labels' => array_column($byMusim, 'musim'), 'rm' => array_map(fn($r) => round((float)$r['rm'], 2), $byMusim),
                'n' => array_map('intval', array_column($byMusim, 'n')),
                'url' => array_map(fn($r) => $urlRekod(['musim' => $r['musim']]), $byMusim)],
    'top'   => ['labels' => array_map(fn($r) => $r['kod'] . ' ' . $r['nama'], $topPpk), 'rm' => array_map(fn($r) => round((float)$r['rm'], 2), $topPpk),
                'url' => array_map(fn($r) => $urlRekod(['ppk' => $r['id']]), $topPpk)],
];

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="dash-kepala">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="dash-sub">Ringkasan operasi penyaluran PPSB · <?= $skop === 'semasa' ? 'Semasa (rekod aktif)' : 'Kumulatif (jumlah asal, termasuk yang tamat)' ?></p>
  </div>
  <div class="dash-tarikh"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e($tarikh) ?></div>
</div>

<form class="card dash-penapis no-print" method="get" aria-label="Penapis dashboard">
  <div class="btn-group btn-group-sm" role="group" aria-label="Skop">
    <a class="btn btn-outline-primary<?= $skop === 'kumulatif' ? ' active' : '' ?>" href="<?= e($urlDash(['skop' => 'kumulatif'])) ?>"<?= $skop === 'kumulatif' ? ' aria-current="true"' : '' ?>>Kumulatif</a>
    <a class="btn btn-outline-primary<?= $skop === 'semasa' ? ' active' : '' ?>" href="<?= e($urlDash(['skop' => 'semasa'])) ?>"<?= $skop === 'semasa' ? ' aria-current="true"' : '' ?>>Semasa</a>
  </div>
  <input type="hidden" name="skop" value="<?= e($skop) ?>">
  <label class="dash-medan"><span>Wilayah</span>
    <select name="wilayah" id="dashWilayah" class="form-select form-select-sm">
      <option value="">Semua wilayah</option>
      <?php foreach ($wilayahList as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $wilayahId === (int)$w['id'] ? ' selected' : '' ?>><?= e($w['nama']) ?></option><?php endforeach; ?>
    </select></label>
  <label class="dash-medan dash-medan-ppk"><span>PPK</span>
    <select name="ppk" id="dashPpk" class="form-select form-select-sm">
      <option value="">Semua PPK</option>
      <?php foreach ($wilayahList as $w): ?><optgroup label="<?= e($w['nama']) ?>" data-wilayah="<?= (int)$w['id'] ?>">
        <?php foreach ($ppkList as $p): if ((int)$p['wilayah_id'] !== (int)$w['id']) continue; ?><option value="<?= (int)$p['id'] ?>"<?= $ppkId === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['kod'] . ' ' . $p['nama']) ?></option><?php endforeach; ?>
      </optgroup><?php endforeach; ?>
    </select></label>
  <label class="dash-medan"><span>Musim mula</span>
    <select name="musim" class="form-select form-select-sm">
      <option value="">Semua musim</option>
      <?php foreach ($musimList as $m): ?><option<?= $musim === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
    </select></label>
  <button class="btn btn-sipadi btn-sm">Tapis</button>
  <?php if ($tapisan): ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($urlDash(['wilayah' => '', 'ppk' => '', 'musim' => ''])) ?>">Set semula</a>
    <span class="dash-tapisan"><i class="bi bi-funnel" aria-hidden="true"></i> <?= e(implode(' · ', $tapisan)) ?></span>
  <?php endif; ?>
</form>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Jumlah Penyaluran', 'RM ' . rm($jumlahRm), 'Sewaan RM ' . rm($pecahan['sewa']) . ' · Modal RM ' . rm($pecahan['mo']), 'cash-stack'],
      ['Bil Peladang', nint($k['bil']), nint($k['ppk']) . ' daripada ' . nint($bilPpk) . ' PPK terlibat', 'people'],
      ['Keluasan', ha($k['luas']) . ' ha', 'Purata ' . ($k['bil'] ? ha($k['luas'] / $k['bil']) : '0.000') . ' ha / peladang', 'map'],
      ['Rekod Projek', nint($k['n']), nint($k['aktif']) . ' aktif · ' . nint($k['sambung']) . ' disambung · ' . nint($k['tamat']) . ' tamat', 'clipboard-data'],
  ] as [$l, $v, $s, $i]): ?>
    <div class="col-sm-6 col-xl-3">
      <a class="card kpi dash-kpi h-100" href="<?= e($urlRekod()) ?>" title="Lihat rekod berkaitan">
        <div class="d-flex justify-content-between align-items-start"><div class="label"><?= $l ?></div><span class="dash-ikon"><i class="bi bi-<?= $i ?>" aria-hidden="true"></i></span></div>
        <div class="value"><?= $v ?></div>
        <div class="sub"><?= $s ?></div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!$adaData): ?>
  <div class="card card-body text-center text-muted py-5 mb-3"><i class="bi bi-inbox fs-2 mb-2" aria-hidden="true"></i>Tiada rekod penyaluran untuk penapis ini.
    <div class="mt-2"><a href="<?= e($urlDash(['wilayah' => '', 'ppk' => '', 'musim' => ''])) ?>">Set semula penapis</a></div></div>
<?php else: ?>
<div class="row g-3 mb-3">
  <div class="col-xl-8">
    <section class="card h-100"><div class="card-body">
      <div class="dash-tajuk"><h2 class="h6 mb-0">Penyaluran (RM) ikut musim mula</h2><span class="dash-nota">Klik bar untuk senarai rekod</span></div>
      <div class="chart-box"><canvas id="cMusim" role="img" aria-label="Carta penyaluran ikut musim mula"></canvas></div>
    </div></section>
  </div>
  <div class="col-xl-4">
    <section class="card h-100"><div class="card-body">
      <div class="dash-tajuk"><h2 class="h6 mb-0">Ikut wilayah</h2><a class="dash-nota" href="<?= e($urlLaporan) ?>">Laporan Ringkasan &rarr;</a></div>
      <ul class="dash-bar-senarai list-unstyled mb-0">
        <?php $maxW = max(array_map(fn($r) => (float)$r['rm'], $byWilayah) ?: [1]) ?: 1;
        foreach ($byWilayah as $wid => $r): ?>
          <li><a href="<?= e($urlRekod(['wilayah' => $wid])) ?>">
            <span class="dash-bar-baris"><span class="dash-bar-nama"><?= e($wilayahNama[$wid]) ?></span><span class="dash-bar-nilai">RM <?= rm($r['rm']) ?></span></span>
            <span class="dash-bar-trek"><span class="dash-bar-isi" style="width:<?= round((float)$r['rm'] / $maxW * 100, 1) ?>%"></span></span>
            <span class="dash-bar-meta"><?= nint($r['n']) ?> rekod · <?= nint($r['ppk']) ?> PPK · <?= number_format((float)$r['rm'] / ($jumlahRm ?: 1) * 100, 1) ?>%</span>
          </a></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($wilayahId): ?><p class="dash-nota dash-nota-balut mt-3 mb-0">Penapis wilayah aktif. <a class="dash-nota" href="<?= e($urlDash(['wilayah' => '', 'ppk' => ''])) ?>">Papar semua wilayah</a> untuk perbandingan.</p><?php endif; ?>
    </div></section>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <section class="card h-100"><div class="card-body">
      <div class="dash-tajuk"><h2 class="h6 mb-0">Ikut model</h2><span class="dash-nota">Klik baris untuk senarai rekod</span></div>
      <div class="table-wrap">
        <table class="table table-sm table-hover table-data dash-jadual mb-0">
          <thead><tr><th class="text-start">Model</th><th class="num">Rekod</th><th class="num dash-lajur-sek">Peladang</th><th class="num dash-lajur-sek">Keluasan (Ha)</th><th class="num">Penyaluran (RM)</th><th class="text-start dash-lajur-bahagian">Bahagian</th></tr></thead>
          <tbody>
          <?php foreach ($byModel as $r): $pct = (float)$r['rm'] / ($jumlahRm ?: 1) * 100; $u = $urlRekod(['model' => $r['model']]); ?>
            <tr class="dash-baris-pautan" data-href="<?= e($u) ?>">
              <td><span class="legend-dot" style="background:<?= MODEL_COLORS[$r['model']] ?? '#999' ?>"></span><a href="<?= e($u) ?>"><?= e($r['model']) ?></a><span class="dash-model-nama"><?= e(MODELS[$r['model']] ?? '') ?></span></td>
              <td class="num"><?= nint($r['n']) ?></td><td class="num dash-lajur-sek"><?= nint($r['bil']) ?></td><td class="num dash-lajur-sek"><?= ha($r['luas']) ?></td>
              <td class="num"><?= rm($r['rm']) ?></td>
              <td class="dash-lajur-bahagian"><span class="dash-bahagian"><span class="dash-bar-trek"><span class="dash-bar-isi" style="width:<?= round($pct, 1) ?>%"></span></span><span class="dash-pct"><?= number_format($pct, 1) ?>%</span></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td>Jumlah</td><td class="num"><?= nint($k['n']) ?></td><td class="num dash-lajur-sek"><?= nint($k['bil']) ?></td><td class="num dash-lajur-sek"><?= ha($k['luas']) ?></td><td class="num"><?= rm($jumlahRm) ?></td><td class="dash-lajur-bahagian">100.0%</td></tr></tfoot>
        </table>
      </div>
    </div></section>
  </div>
  <div class="col-xl-5">
    <section class="card h-100"><div class="card-body">
      <div class="dash-tajuk"><h2 class="h6 mb-0">10 PPK teratas (RM)</h2><span class="dash-nota">Klik bar untuk senarai rekod</span></div>
      <div class="chart-box chart-box-tinggi"><canvas id="cTop" role="img" aria-label="Carta 10 PPK teratas mengikut penyaluran"></canvas></div>
    </div></section>
  </div>
</div>
<?php endif; ?>

<section class="card mb-1"><div class="card-body">
  <h2 class="h6 mb-3">Maklumat penting</h2>
  <div class="row g-3">
    <div class="col-md-6">
      <div class="dash-info">
        <span class="dash-info-ikon"><i class="bi bi-database-check" aria-hidden="true"></i></span>
        <div class="flex-grow-1">
          <div class="dash-info-tajuk">Backup pangkalan data</div>
          <?php $t = $backup['terkini']; ?>
          <div class="dash-info-teks"><?= $t ? 'Terakhir: ' . e(date('d/m/Y H:i', strtotime($t['dicipta']))) . ' (' . e(BACKUP_JENIS[$t['jenis']] ?? $t['jenis']) . ')' : 'Tiada backup ditemui.' ?></div>
          <?php if ($backup['amaran']): foreach ($backup['amaran'] as [$jenisA, $mesejA]): ?>
            <div class="dash-info-amaran <?= $jenisA === 'danger' ? 'text-danger' : 'text-warning-emphasis' ?>"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= e($mesejA) ?></div>
          <?php endforeach; else: ?>
            <div class="dash-info-ok"><i class="bi bi-check-circle" aria-hidden="true"></i> Tiada amaran backup.</div>
          <?php endif; ?>
          <a class="dash-nota" href="backup.php">Halaman Backup &rarr;</a>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="dash-info">
        <span class="dash-info-ikon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span>
        <div class="flex-grow-1">
          <div class="dash-info-tajuk">Status data</div>
          <div class="dash-info-teks"><?= nint($data['n']) ?> rekod penyaluran dalam sistem · <?= nint($bilPpk) ?> PPK berdaftar</div>
          <div class="dash-info-teks">Musim mula terkini dalam data: <?= e($musimTerkini) ?></div>
          <div class="dash-info-teks">Kemas kini rekod terakhir: <?= $data['kemaskini'] ? e(date('d/m/Y H:i', strtotime($data['kemaskini']))) : '-' ?></div>
          <a class="dash-nota" href="penyaluran.php">Rekod Penyaluran &rarr;</a>
        </div>
      </div>
    </div>
  </div>
</div></section>
<?php
$json = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$extraScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  const D = $json;
  // Penapis PPK ikut wilayah: hanya PPK dalam wilayah dipilih dipaparkan; pilihan tidak sepadan dikosongkan
  (function () {
    const w = document.getElementById('dashWilayah'), p = document.getElementById('dashPpk');
    if (!w || !p) return;
    const tapis = () => {
      p.querySelectorAll('optgroup').forEach(g => {
        const ok = !w.value || g.dataset.wilayah === w.value;
        g.hidden = !ok; g.disabled = !ok;
      });
      const pilih = p.selectedOptions[0];
      if (pilih && pilih.parentElement.tagName === 'OPTGROUP' && pilih.parentElement.disabled) p.value = '';
    };
    w.addEventListener('change', tapis); tapis();
  })();
  // Baris jadual model boleh diklik (pautan sebenar kekal dalam sel pertama untuk papan kekunci)
  document.querySelectorAll('tr.dash-baris-pautan').forEach(tr => tr.addEventListener('click', e => { if (!e.target.closest('a')) location.href = tr.dataset.href; }));
  if (!window.Chart || !document.getElementById('cMusim')) return;
  const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  const fmtRM = v => 'RM ' + Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const shortRM = v => v >= 1e6 ? 'RM' + (v / 1e6).toFixed(v >= 1e7 ? 0 : 1) + 'J' : v >= 1e3 ? 'RM' + (v / 1e3).toFixed(0) + 'K' : 'RM' + v;
  const labelPendek = { autoSkip: false, callback: function (v) { const s = String(this.getLabelForValue(v)); return s.length > 24 ? s.slice(0, 23) + '…' : s; } };

  function lukisCarta() {
    // Token UI-01: warna, teks dan grid diambil daripada --epms-*; font Inter
    const hijau = css('--epms-primary'), hijauGelap = css('--epms-primary-hover'), grid = css('--epms-border'), teks = css('--epms-secondary');
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 12;
    Chart.defaults.color = teks;
    const paksi = { grid: { color: grid, drawTicks: false }, border: { display: false }, ticks: { padding: 6 } };
    const tanpaGrid = { grid: { display: false }, border: { color: grid }, ticks: { padding: 4 } };
    const tooltip = { backgroundColor: css('--epms-text'), padding: 10, cornerRadius: 6, displayColors: false, titleFont: { weight: '600' } };
    const bar = { backgroundColor: hijau, hoverBackgroundColor: hijauGelap, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 40 };
    const keUrl = urls => (e, el) => { if (el.length) location.href = urls[el[0].index]; };
    const kursor = (e, el, carta) => { carta.canvas.style.cursor = el.length ? 'pointer' : 'default'; };

    window.cartaMusim = new Chart(document.getElementById('cMusim'), { type: 'bar',
      data: { labels: D.musim.labels, datasets: [{ label: 'Penyaluran', data: D.musim.rm, ...bar }] },
      options: { maintainAspectRatio: false, onClick: keUrl(D.musim.url), onHover: kursor,
        plugins: { legend: { display: false }, tooltip: { ...tooltip, callbacks: {
          title: c => 'Musim ' + c[0].label, label: c => fmtRM(c.parsed.y), afterLabel: c => D.musim.n[c.dataIndex].toLocaleString('en-MY') + ' rekod' } } },
        scales: { x: { ...tanpaGrid, ticks: { ...tanpaGrid.ticks, maxRotation: 0, autoSkipPadding: 10 } },
                  y: { ...paksi, beginAtZero: true, ticks: { ...paksi.ticks, callback: shortRM, maxTicksLimit: 6 } } } } });

    if (document.getElementById('cTop')) window.cartaTop = new Chart(document.getElementById('cTop'), { type: 'bar',
      data: { labels: D.top.labels, datasets: [{ label: 'Penyaluran', data: D.top.rm, ...bar, maxBarThickness: 22 }] },
      options: { indexAxis: 'y', maintainAspectRatio: false, onClick: keUrl(D.top.url), onHover: kursor,
        plugins: { legend: { display: false }, tooltip: { ...tooltip, callbacks: { label: c => fmtRM(c.parsed.x) } } },
        scales: { x: { ...paksi, beginAtZero: true, ticks: { ...paksi.ticks, callback: shortRM, maxTicksLimit: 5, maxRotation: 0 } },
                  y: { ...tanpaGrid, ticks: { ...tanpaGrid.ticks, ...labelPendek } } } } });
  }
  // Lukis selepas Inter dimuatkan (UI-01: elak label diukur dengan font fallback)
  Promise.all([document.fonts.load('400 12px Inter'), document.fonts.load('600 12px Inter')]).then(lukisCarta, lukisCarta);
})();
</script>
HTML;
require __DIR__ . '/includes/footer.php';
