<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/laporan_lib.php';
require __DIR__ . '/includes/penyaluran_lib.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$kembali = qs_penapis((string)($_GET['kembali'] ?? ''));
$urlKembali = url_senarai($kembali);
if ($id <= 0) redirect($urlKembali);

$st = db()->prepare('SELECT x.*, p.kod ppk_kod, p.nama ppk_nama, w.id wilayah_id, w.kod wilayah_kod, w.nama wilayah_nama
    FROM penyaluran x JOIN ppk p ON p.id = x.ppk_id JOIN wilayah w ON w.id = p.wilayah_id WHERE x.id = ?');
$st->execute([$id]);
$rec = $st->fetch();
if (!$rec) { flash('Rekod penyaluran #' . $id . ' tidak dijumpai.', 'danger'); redirect($urlKembali); }

$rantaian = rantaian_perjanjian(db(), $id);
$pautan = $pautanNode = [];
foreach ($rantaian['pautan'] as $pasangan) {
    [$dari, $ke] = $pasangan;
    $pautanNode[$ke]['pendahulu'][] = $dari;
    $pautanNode[$dari]['sambungan'][] = $ke;
}
$bilPdhl = count($pautanNode[$id]['pendahulu'] ?? []);
$notaJenis = keadaan_pautan($rec, $bilPdhl);
$auditAda = audit_tersedia(db());
$sejarah = $auditAda ? sejarah_betul_jenis(db(), $id) : [];

$jumlah = (float)$rec['sewaan'] + (float)$rec['modal_operasi'];
$agg = agregat_ikut_model('FROM penyaluran x WHERE x.id = ?', [$id]);
$pecahan = pecahan_sewa_modal($agg);
$epDalamModal = (float)($agg['EP']['sewa'] ?? 0);

$chain = ['n' => count($rantaian['rekod']), 'bil' => 0, 'luas' => 0.0, 'sewa' => 0.0, 'mo' => 0.0];
$chainModel = [];
foreach ($rantaian['rekod'] as $rr) {
    $chain['bil'] += (int)$rr['bil_peladang'];
    $chain['luas'] += (float)$rr['luas_ha'];
    $chain['sewa'] += (float)$rr['sewaan'];
    $chain['mo'] += (float)$rr['modal_operasi'];
    $kod = (string)$rr['model'];
    $chainModel[$kod]['sewa'] = ($chainModel[$kod]['sewa'] ?? 0) + (float)$rr['sewaan'];
    $chainModel[$kod]['mo'] = ($chainModel[$kod]['mo'] ?? 0) + (float)$rr['modal_operasi'];
}
$modelOrder = array_merge(array_values(array_intersect(array_keys(MODELS), array_keys($chainModel))), array_values(array_diff(array_keys($chainModel), array_keys(MODELS))));

$tunjuk = fn($v) => ($v === null || trim((string)$v) === '') ? '<span class="text-muted">-</span>' : e($v);
$tarikh = fn($v) => $v ? e(date('d/m/Y H:i', strtotime((string)$v))) : '<span class="text-muted">-</span>';
$kembaliQs = $kembali !== '' ? '&amp;kembali=' . e(urlencode($kembali)) : '';
$pageTitle = 'Butiran Rekod #' . $id;
require __DIR__ . '/includes/header.php';
?>
<div class="op-kepala">
  <div>
    <h1 class="page-title">Butiran Rekod #<?= $id ?></h1>
    <p class="op-sub"><?= e($rec['ppk_kod'] . ' ' . $rec['ppk_nama']) ?> · <?= e($rec['projek'] ?: 'Tiada nama projek') ?> · musim <?= e($rec['musim']) ?></p>
  </div>
  <div class="op-tindakan no-print">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($urlKembali) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali ke senarai</a>
    <a class="btn btn-sipadi btn-sm" href="penyaluran_form.php?id=<?= $id . $kembaliQs ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit rekod</a>
  </div>
</div>

<div class="card op-detail">
  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><div><h2>Maklumat Rekod</h2><p>Butiran asal rekod penyaluran ini.</p></div></div>
    <div class="op-butiran op-butiran-ypad">
      <div class="op-butiran-item"><dt>ID Rekod</dt><dd>#<?= $id ?></dd></div>
      <div class="op-butiran-item"><dt>Wilayah</dt><dd><?= e($rec['wilayah_nama']) ?></dd></div>
      <div class="op-butiran-item"><dt>PPK</dt><dd><a href="penyaluran.php?ppk=<?= (int)$rec['ppk_id'] ?>"><?= e($rec['ppk_kod'] . ' ' . $rec['ppk_nama']) ?></a></dd></div>
      <div class="op-butiran-item"><dt>Jenis Perjanjian</dt><dd><?= e(LABEL_JENIS[$rec['jenis']] ?? $rec['jenis']) ?>
        <?php if ($notaJenis === 'tiada_rekod'): ?><span class="st-badge st-kuning"><?= e(LABEL_TIADA_REKOD) ?></span>
        <?php elseif ($notaJenis === 'lama'): ?><span class="st-badge st-kelabu"><?= e(LABEL_SAMBUNGAN_LAMA) ?></span>
        <?php elseif ($notaJenis === 'dipautkan'): ?><span class="st-badge st-biru">Dipautkan kepada <?= $bilPdhl ?> terdahulu</span><?php endif; ?></dd></div>
      <div class="op-butiran-item"><dt>Musim Mula</dt><dd><?= e($rec['musim']) ?></dd></div>
      <div class="op-butiran-item"><dt>Musim Tamat</dt><dd><?= $tunjuk($rec['musim_tamat']) ?></dd></div>
      <div class="op-butiran-item"><dt>Musim Pelaksanaan</dt><dd><?= $tunjuk($rec['musim_pelaksanaan']) ?></dd></div>
      <div class="op-butiran-item"><dt>Bil Musim</dt><dd><?= $tunjuk($rec['bil_musim']) ?></dd></div>
      <div class="op-butiran-item"><dt>Model</dt><dd><span class="legend-dot" style="background:<?= e(MODEL_COLORS[$rec['model']] ?? '#8A9490') ?>"></span><?= e($rec['model'] . ' - ' . (MODELS[$rec['model']] ?? $rec['model'])) ?></dd></div>
      <div class="op-butiran-item"><dt>No Blok</dt><dd><?= $tunjuk($rec['no_blok']) ?></dd></div>
      <div class="op-butiran-item"><dt>Projek</dt><dd><?= $tunjuk($rec['projek']) ?></dd></div>
      <div class="op-butiran-item"><dt>Kump</dt><dd><?= $tunjuk($rec['kump']) ?></dd></div>
      <div class="op-butiran-item"><dt>Fasa Penyaluran</dt><dd><?= $tunjuk($rec['fasa']) ?></dd></div>
      <div class="op-butiran-item"><dt>Status</dt><dd><?= lencana_status($rec['status']) ?></dd></div>
      <div class="op-butiran-item"><dt>Tindakan &amp; Status</dt><dd><?= $tunjuk($rec['tindakan']) ?></dd></div>
      <div class="op-butiran-item op-butiran-lebar"><dt>Catatan</dt><dd><?= $tunjuk($rec['catatan']) ?></dd></div>
      <div class="op-butiran-item"><dt>Dicipta</dt><dd><?= $tarikh($rec['created_at']) ?></dd></div>
      <div class="op-butiran-item"><dt>Dikemaskini</dt><dd><?= $tarikh($rec['updated_at']) ?></dd></div>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><div><h2>Ringkasan Kewangan Rekod</h2><p>Klasifikasi sama seperti Senarai Rekod dan laporan Ringkasan: penyaluran Model EP dikira dalam Modal Operasi. Jumlah tidak berubah.</p></div></div>
    <div class="op-ringkasan op-detail-ringkasan">
      <?php foreach ([
          ['Bil Peladang', nint($rec['bil_peladang'])], ['Keluasan (Ha)', ha($rec['luas_ha'])],
          ['Kadar Sewa/Ha (RM)', (float)$rec['kadar_sewa'] ? rm($rec['kadar_sewa']) : '-'],
          ['Sewaan/Pajakan (RM)', rm($pecahan['sewa'])], ['Modal Operasi (RM)', rm($pecahan['mo'])],
          ['Jumlah Penyaluran (RM)', rm($jumlah)],
      ] as [$lb, $nil]): ?>
        <div class="kpi op-stat"><div class="label"><?= e($lb) ?></div><div class="value fs-6"><?= e($nil) ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php if ($epDalamModal != 0): ?>
      <div class="small text-muted mb-2 nota-kpi op-nota">Modal Operasi termasuk penyaluran Model EP RM <?= rm($epDalamModal) ?> (dipindah daripada Sewaan/Pajakan, tidak digandakan). Jadual pecahan di bawah memaparkan medan asal setiap rekod.</div>
    <?php endif; ?>
    <h3 class="h6 mt-3 mb-2">Pecahan model dalam rantaian</h3>
    <div class="table-wrap op-detail-table">
      <table class="table table-sm table-data mb-0">
        <thead><tr><th class="text-start">Model</th><th class="num">Sewaan/Pajakan (RM)</th><th class="num">Modal Operasi (RM)</th><th class="num">Jumlah (RM)</th></tr></thead>
        <tbody>
        <?php $jumSewa = $jumMo = 0.0; foreach ($modelOrder as $kod):
          $barisSewa = (float)($chainModel[$kod]['sewa'] ?? 0); $barisMo = (float)($chainModel[$kod]['mo'] ?? 0);
          $jumSewa += $barisSewa; $jumMo += $barisMo; ?>
          <tr><td class="text-start"><span class="legend-dot" style="background:<?= e(MODEL_COLORS[$kod] ?? '#8A9490') ?>"></span><?= e($kod . ' - ' . (MODELS[$kod] ?? $kod)) ?></td>
            <td class="num"><?= rm($barisSewa) ?></td><td class="num"><?= rm($barisMo) ?></td><td class="num fw-semibold"><?= rm($barisSewa + $barisMo) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Rantaian (<?= nint($chain['n']) ?> rekod)</td><td class="num"><?= rm($jumSewa) ?></td><td class="num"><?= rm($jumMo) ?></td><td class="num"><?= rm($jumSewa + $jumMo) ?></td></tr></tfoot>
      </table>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span><div><h2>Garis Masa Perjanjian</h2><p>Rantaian perjanjian yang dipautkan kepada rekod ini, mengikut musim mula.</p></div></div>
    <?php if ($rantaian['terpotong']): ?>
      <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Rantaian melebihi had paparan 200 rekod. Sebahagian pautan tidak dipaparkan.</div>
    <?php endif; ?>
    <?php if (count($rantaian['rekod']) <= 1 && !$rantaian['pautan']): ?>
      <p class="text-muted mb-0">Tiada pautan sambungan direkodkan untuk rekod ini.</p>
    <?php endif; ?>
    <ol class="op-timeline">
      <?php foreach ($rantaian['rekod'] as $rid => $rr):
        $pautanNode[$rid]['pendahulu'] = $pautanNode[$rid]['pendahulu'] ?? [];
        $pautanNode[$rid]['sambungan'] = $pautanNode[$rid]['sambungan'] ?? [];
        $pautHtml = fn(array $ids, string $ikon, string $label) => $ids
            ? '<span class="op-timeline-paut"><i class="bi bi-' . $ikon . '" aria-hidden="true"></i> ' . $label . ': '
              . implode(' ', array_map(fn($n) => '<a href="penyaluran_lihat.php?id=' . (int)$n . $kembaliQs . '">#' . (int)$n . '</a>', $ids)) . '</span>'
            : '';
      ?>
        <li class="op-timeline-item<?= $rid === $id ? ' semasa' : '' ?>">
          <div class="op-timeline-masa"><span class="op-timeline-titik"></span><span><?= e($rr['musim'] ?: '-') ?></span></div>
          <div class="op-timeline-kad">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <a class="op-timeline-id" href="penyaluran_lihat.php?id=<?= (int)$rid . $kembaliQs ?>">#<?= (int)$rid ?></a>
              <?= lencana_status($rr['status']) ?>
              <span class="st-badge st-kelabu"><?= e(LABEL_JENIS[$rr['jenis']] ?? $rr['jenis']) ?></span>
              <?php if ($rid === $id): ?><span class="st-badge st-hijau">Rekod ini</span><?php endif; ?>
              <?php if ((int)$rr['pendahulu_tiada_rekod'] === 1): ?><span class="st-badge st-kuning"><?= e(LABEL_TIADA_REKOD) ?></span>
              <?php elseif ($rr['jenis'] === 'SAMBUNG' && !$pautanNode[$rid]['pendahulu']): ?><span class="st-badge st-kelabu"><?= e(LABEL_SAMBUNGAN_LAMA) ?></span><?php endif; ?>
            </div>
            <p class="mb-1 fw-semibold"><?= e(teks_rekod_pautan($rr)) ?></p>
            <p class="small text-muted mb-1">Pelaksanaan <?= e($rr['musim_pelaksanaan'] ?: '-') ?> · Bil Musim <?= e($rr['bil_musim'] ?: '-') ?> · Tamat <?= e($rr['musim_tamat'] ?: '-') ?></p>
            <div class="op-timeline-pautan">
              <?= $pautHtml($pautanNode[$rid]['pendahulu'], 'arrow-left', 'Terdahulu') ?>
              <?= $pautHtml($pautanNode[$rid]['sambungan'], 'arrow-right', 'Disambung oleh') ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
    <div class="op-ringkasan op-detail-ringkasan mt-3">
      <?php foreach ([
          ['Rekod dalam rantaian', nint($chain['n'])], ['Bil Peladang', nint($chain['bil'])], ['Keluasan (Ha)', ha($chain['luas'])],
          ['Sewaan/Pajakan (RM)', rm($chain['sewa'])], ['Modal Operasi (RM)', rm($chain['mo'])], ['Jumlah (RM)', rm($chain['sewa'] + $chain['mo'])],
      ] as [$lb, $nil]): ?>
        <div class="kpi op-stat"><div class="label"><?= e($lb) ?></div><div class="value fs-6"><?= e($nil) ?></div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($sejarah): ?>
  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div><h2>Sejarah Pembetulan Jenis</h2><p>Jejak audit pembetulan jenis perjanjian rekod ini.</p></div></div>
    <div class="table-wrap"><table class="table table-sm table-data mb-0">
      <thead><tr><th class="text-start">Tarikh / Masa</th><th>Jenis lama</th><th>Jenis baharu</th><th class="text-start">Pengguna</th><th class="text-start">Sebab</th></tr></thead>
      <tbody><?php foreach ($sejarah as $sb): ?>
        <tr><td class="text-start text-nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$sb['created_at']))) ?></td>
          <td><?= e(LABEL_JENIS[$sb['nilai_lama']] ?? $sb['nilai_lama']) ?></td><td><?= e(LABEL_JENIS[$sb['nilai_baru']] ?? $sb['nilai_baru']) ?></td>
          <td class="text-start"><?= e($sb['pengguna_nama'] ?? '-') ?></td><td class="text-start op-sebab-audit"><?= e($sb['sebab']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <?php endif; ?>
</div>

<div class="op-bar-tindakan no-print">
  <span class="op-bar-nota">Butiran ini hanya untuk dilihat. Gunakan Edit rekod untuk membuat perubahan.</span>
  <div class="d-flex gap-2">
    <a href="<?= e($urlKembali) ?>" class="btn btn-outline-secondary">Kembali</a>
    <a href="penyaluran_form.php?id=<?= $id . $kembaliQs ?>" class="btn btn-sipadi"><i class="bi bi-pencil" aria-hidden="true"></i> Edit rekod</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php';
