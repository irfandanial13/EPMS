<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/laporan_lib.php';
require __DIR__ . '/includes/penyaluran_lib.php';
require_login();

// Padam rekod — kembali ke senarai dengan penapis yang sama (parameter dibersihkan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'padam') {
    csrf_check();
    $idPadam = (int)($_POST['id'] ?? 0);
    // Perjanjian Fasa 3 (Q2): rekod yang dipautkan tidak boleh dipadam — mesej mesra, bukan ralat DB.
    // Semakan awal + tangkapan ralat FK 1451 (RESTRICT) sebagai sandaran jika pautan dicipta serentak.
    $mesejPautan = 'Rekod ini tidak boleh dipadam kerana dipautkan kepada perjanjian lain (sebagai perjanjian terdahulu atau sambungan). '
        . 'Pautan perlu dikekalkan supaya sejarah perjanjian lengkap.';
    if (rekod_ada_pautan(db(), $idPadam)) {
        flash($mesejPautan, 'danger');
    } else {
        try {
            db()->prepare('DELETE FROM penyaluran WHERE id = ?')->execute([$idPadam]);
            flash('Rekod telah dipadam.');
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1451) throw $e;
            flash($mesejPautan, 'danger');
        }
    }
    redirect(url_senarai((string)($_POST['qs'] ?? '')));
}

$f = array_map(fn($v) => is_string($v) ? trim($v) : '', $_GET);
[$where, $params] = filter_where($f);
$from = "FROM penyaluran x JOIN ppk p ON p.id = x.ppk_id JOIN wilayah w ON w.id = p.wilayah_id WHERE $where";
// Susunan: lajur senarai putih SUSUNAN_REKOD (pilihan pengguna) + susunan asal sebagai pemutus seri; senarai & eksport sama
$susun = isset(SUSUNAN_REKOD[$f['susun'] ?? '']) ? $f['susun'] : '';
$arah = ($f['arah'] ?? '') === 'desc' ? 'desc' : 'asc';
$order = sql_order_rekod($susun, $arah);

// Eksport Excel ikut penapis semasa
if (($f['eksport'] ?? '') === 'xlsx') {
    require __DIR__ . '/includes/xlsx.php';
    $st = db()->prepare("SELECT x.*, p.kod ppk_kod, p.nama ppk_nama, w.nama wilayah $from $order");
    $st->execute($params);
    $cols = [
        ['ID', 'int', 'id', 7], ['Wilayah', 'text', 'wilayah', 12], ['PPK', 'text', 'ppk_kod', 8], ['Nama PPK', 'text', 'ppk_nama', 18],
        ['Jenis', 'text', 'jenis', 10], ['Musim', 'text', 'musim', 9], ['No Blok', 'text', 'no_blok', 16],
        ['Projek', 'text', 'projek', 22], ['Model', 'text', 'model', 8], ['Bil Peladang', 'int', 'bil_peladang', 10],
        ['Luas (Ha)', 'ha', 'luas_ha', 11], ['Kump', 'text', 'kump', 7], ['Kadar Sewa/Ha (RM)', 'rm', 'kadar_sewa', 12],
        ['Sewaan/Pajakan (RM)', 'rm', 'sewaan', 14], ['Modal Operasi (RM)', 'rm', 'modal_operasi', 14],
        ['Musim Pelaksanaan', 'text', 'musim_pelaksanaan', 16], ['Bil Musim', 'text', 'bil_musim', 8],
        ['Fasa Penyaluran', 'text', 'fasa', 14], ['Musim Tamat', 'text', 'musim_tamat', 12], ['Status', 'text', 'status', 9],
        ['Tindakan & Status', 'text', 'tindakan', 24], ['Catatan', 'text', 'catatan', 24],
    ];
    $rows = [];
    foreach ($st as $r) $rows[] = ['cells' => array_map(fn($c) => $r[$c[2]], $cols), 'cls' => ''];
    // Subtajuk merekodkan carian & penapis yang digunakan supaya fail eksport boleh difahami tanpa skrin
    $ketPenapis = array_filter(['Carian: ' . ($f['q'] ?? '') => ($f['q'] ?? '') !== '', 'Wilayah #' . ($f['wilayah'] ?? '') => !empty($f['wilayah']),
        'PPK #' . ($f['ppk'] ?? '') => !empty($f['ppk']), 'Musim ' . ($f['musim'] ?? '') => !empty($f['musim']), 'Tahun ' . ($f['tahun'] ?? '') => !empty($f['tahun']),
        'Model ' . ($f['model'] ?? '') => !empty($f['model']), 'Status ' . ($f['status'] ?? '') => !empty($f['status']), 'Jenis ' . ($f['jenis'] ?? '') => !empty($f['jenis'])]);
    SimpleXlsx::download([
        'title' => 'SENARAI REKOD PENYALURAN ESTET PADI', 'sheet' => 'Penyaluran',
        'subtitle' => 'Dijana ' . date('d/m/Y H:i') . ' · ' . count($rows) . ' rekod' . ($ketPenapis ? ' · ' . implode(' · ', array_keys($ketPenapis)) : ' · semua rekod'),
        'head' => [array_map(fn($c) => ['t' => $c[0]], $cols)], 'fmt' => array_column($cols, 1),
        'widths' => array_column($cols, 3), 'rows' => $rows,
    ], APP_NAME . '_penyaluran_' . date('Ymd') . '.xlsx');
}

// Jumlah ikut penapis
$st = db()->prepare("SELECT COUNT(*) n, SUM(x.bil_peladang) bil, SUM(x.luas_ha) luas, SUM(x.sewaan) sewa, SUM(x.modal_operasi) mo $from");
$st->execute($params);
$tot = $st->fetch();
// Pecahan Sewaan/Modal ikut klasifikasi EP (sama seperti laporan Ringkasan). Jumlah kekal sewa + mo.
$aggModel = agregat_ikut_model($from, $params);
$pecahan = pecahan_sewa_modal($aggModel);
$epDalamModal = (float)($aggModel['EP']['sewa'] ?? 0);

$perPage = in_array((int)($f['saiz'] ?? 0), SAIZ_HALAMAN, true) ? (int)$f['saiz'] : 50;
$page = max(1, (int)($f['page'] ?? 1));
$pages = max(1, (int)ceil($tot['n'] / $perPage));
$page = min($page, $pages);
$st = db()->prepare("SELECT x.*, p.kod ppk_kod, p.nama ppk_nama $from $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();
// Perjanjian Fasa 3: pautan bagi rekod pada halaman ini (paling banyak $perPage rekod)
$pautan = pautan_rekod(db(), array_column($rows, 'id'));

$qsBase = qs_penapis(array_diff_key($f, ['page' => 1, 'eksport' => 1]));
$qsKembali = qs_penapis(['page' => $page > 1 ? (string)$page : ''] + $f);   // penapis + halaman semasa, dibawa ke borang & padam
$kembali = $qsKembali !== '' ? '&kembali=' . urlencode($qsKembali) : '';
$pageTitle = 'Rekod Penyaluran';
// UI-04: data rujukan untuk paparan sahaja (nama PPK & wilayah) — tiada query baharu pada rekod
$ppkList = senarai_ppk();
$wilayahList = senarai_wilayah();
$ppkInfo = array_column($ppkList, null, 'id');
$wilayahNama = array_column($wilayahList, 'nama', 'id');
$labelPenapis = array_filter([
    !empty($f['wilayah']) ? ($wilayahNama[(int)$f['wilayah']] ?? null) : null,
    !empty($f['ppk']) && isset($ppkInfo[(int)$f['ppk']]) ? $ppkInfo[(int)$f['ppk']]['kod'] . ' ' . $ppkInfo[(int)$f['ppk']]['nama'] : null,
    !empty($f['musim']) ? 'Musim ' . $f['musim'] : null,
    !empty($f['tahun']) && ctype_digit($f['tahun']) ? 'Tahun ' . $f['tahun'] : null,
    !empty($f['model']) && isset(MODELS[$f['model']]) ? 'Model ' . $f['model'] : null,
    !empty($f['status']) && isset(LENCANA_STATUS[$f['status']]) ? 'Status ' . LENCANA_STATUS[$f['status']][0] : null,
    !empty($f['jenis']) && in_array($f['jenis'], ['ASAL', 'SAMBUNG'], true) ? ($f['jenis'] === 'ASAL' ? 'Asal' : 'Sambung') : null,
    ($f['q'] ?? '') !== '' ? '"' . $f['q'] . '"' : null,
]);
$dari = $tot['n'] ? ($page - 1) * $perPage + 1 : 0;
$hingga = min($page * $perPage, (int)$tot['n']);
$urlHal = fn(int $pg) => '?' . e(ltrim($qsBase . '&page=' . $pg, '&'));
// Pengepala lajur boleh disusun: klik = menaik; klik semula = menurun. Penapis lain dikekalkan; halaman kembali ke 1.
$thSusun = function (string $kunci, string $label, string $kelas = '') use ($f, $susun, $arah): string {
    $aktif = $susun === $kunci;
    $arahBaru = $aktif && $arah === 'asc' ? 'desc' : 'asc';
    $qs = qs_penapis(array_merge(array_diff_key($f, ['page' => 1, 'eksport' => 1]), ['susun' => $kunci, 'arah' => $arahBaru]));
    $ikon = $aktif ? ($arah === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up op-susun-pasif';
    return '<th class="' . e($kelas) . '"' . ($aktif ? ' aria-sort="' . ($arah === 'asc' ? 'ascending' : 'descending') . '"' : '') . '><a class="op-susun" href="?' . e($qs)
        . '" title="Susun ikut ' . e($label) . '">' . e($label) . ' <i class="bi ' . $ikon . '" aria-hidden="true"></i></a></th>';
};
$senaraiTahun = db()->query('SELECT DISTINCT musim_susun DIV 10 FROM penyaluran WHERE musim_susun > 0 ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN);
require __DIR__ . '/includes/header.php';
?>
<div class="op-kepala">
  <div>
    <h1 class="page-title">Rekod Penyaluran</h1>
    <p class="op-sub">Lihat, cari, tapis dan susun semua rekod penyaluran Estet Padi; buka butiran rekod, tambah, edit dan eksport.</p>
  </div>
  <div class="op-tindakan no-print">
    <a class="btn btn-sipadi btn-sm" href="penyaluran_form.php<?= $qsKembali !== '' ? '?kembali=' . e(urlencode($qsKembali)) : '' ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah Rekod</a>
    <a class="btn btn-outline-primary btn-sm" href="?<?= e($qsBase . '&eksport=xlsx') ?>"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Eksport Excel</a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Cetak</button>
  </div>
</div>

<form class="card op-penapis mb-3 no-print" role="search" aria-label="Carian dan penapis rekod">
  <div class="op-carian">
    <label class="visually-hidden" for="cariRekod">Carian</label>
    <i class="bi bi-search" aria-hidden="true"></i>
    <input id="cariRekod" name="q" class="form-control" maxlength="100" placeholder="Cari PPK, projek, no blok, model, musim, fasa, tindakan atau catatan… (#ID untuk nombor rekod)" value="<?= e($f['q'] ?? '') ?>">
  </div>
  <?php if ($susun !== ''): ?><input type="hidden" name="susun" value="<?= e($susun) ?>"><input type="hidden" name="arah" value="<?= e($arah) ?>"><?php endif; ?>
  <?php if ($perPage !== 50): ?><input type="hidden" name="saiz" value="<?= $perPage ?>"><?php endif; ?>
  <div class="op-grid-penapis">
    <label class="op-medan"><span>Wilayah</span>
      <select name="wilayah" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach ($wilayahList as $w): ?><option value="<?= $w['id'] ?>" <?= ($f['wilayah'] ?? '') == $w['id'] ? 'selected' : '' ?>><?= e($w['nama']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="op-medan op-medan-lebar"><span>PPK</span>
      <select name="ppk" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach ($ppkList as $p): ?><option value="<?= $p['id'] ?>" <?= ($f['ppk'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['kod'] . ' ' . $p['nama']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="op-medan"><span>Musim</span>
      <select name="musim" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach (senarai_musim() as $m): ?><option <?= ($f['musim'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
      </select></label>
    <label class="op-medan"><span>Tahun</span>
      <select name="tahun" class="form-select form-select-sm" title="Tahun musim mula">
        <option value="">Semua</option>
        <?php foreach ($senaraiTahun as $t): ?><option value="<?= (int)$t ?>" <?= ($f['tahun'] ?? '') === (string)$t ? 'selected' : '' ?>><?= (int)$t ?></option><?php endforeach; ?>
      </select></label>
    <label class="op-medan"><span>Model</span>
      <select name="model" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach (MODELS as $k => $t): ?><option value="<?= $k ?>" <?= ($f['model'] ?? '') === $k ? 'selected' : '' ?>><?= "$k - " . e($t) ?></option><?php endforeach; ?>
      </select></label>
    <label class="op-medan"><span>Status</span>
      <select name="status" class="form-select form-select-sm">
        <option value="">Semua</option>
        <?php foreach (LENCANA_STATUS as $s => [$lbl]): ?><option value="<?= $s ?>" <?= ($f['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s === 'SAMBUNG' ? 'SAMBUNG (disambung)' : $s ?></option><?php endforeach; ?>
      </select></label>
    <label class="op-medan"><span>Jenis</span>
      <select name="jenis" class="form-select form-select-sm">
        <option value="">Semua</option>
        <option value="ASAL" <?= ($f['jenis'] ?? '') === 'ASAL' ? 'selected' : '' ?>>Asal</option>
        <option value="SAMBUNG" <?= ($f['jenis'] ?? '') === 'SAMBUNG' ? 'selected' : '' ?>>Sambung</option>
      </select></label>
    <div class="op-butang-penapis">
      <button class="btn btn-sipadi btn-sm"><i class="bi bi-funnel" aria-hidden="true"></i> Tapis</button>
      <a class="btn btn-outline-secondary btn-sm" href="penyaluran.php">Set semula</a>
    </div>
  </div>
  <?php if ($labelPenapis): ?>
    <div class="op-cip-baris"><span class="op-cip-label"><?= count($labelPenapis) ?> penapis aktif:</span>
      <?php foreach ($labelPenapis as $lp): ?><span class="dash-tapisan"><?= e($lp) ?></span><?php endforeach; ?></div>
  <?php endif; ?>
</form>

<div class="card op-ringkasan mb-3">
  <?php foreach ([
      ['Rekod', nint($tot['n'])], ['Bil Peladang', nint($tot['bil'])], ['Keluasan (Ha)', ha($tot['luas'])],
      ['Sewaan/Pajakan (RM)', rm($pecahan['sewa'])], ['Modal Operasi (RM)', rm($pecahan['mo'])], ['Jumlah Penyaluran (RM)', rm($tot['sewa'] + $tot['mo'])],
  ] as [$l, $v]): ?>
    <div class="kpi op-stat"><div class="label"><?= $l ?></div><div class="value fs-6"><?= $v ?></div></div>
  <?php endforeach; ?>
</div>
<?php if ($epDalamModal != 0): ?>
<div class="small text-muted mb-2 nota-kpi op-nota">Modal Operasi termasuk penyaluran Model EP RM <?= rm($epDalamModal) ?> (klasifikasi sama seperti laporan Ringkasan). Lajur jadual di bawah memaparkan medan asal setiap rekod.</div>
<?php endif; ?>

<div class="card op-jadual-kad">
  <div class="table-wrap table-scroll">
    <table class="table table-sm table-hover table-data op-jadual mb-0">
      <thead><tr>
        <th class="op-lajur-butiran no-print"><span class="visually-hidden">Butiran</span></th>
        <?= $thSusun('ppk', 'PPK', 'text-start') . $thSusun('jenis', 'Jenis') . $thSusun('musim', 'Musim') ?><th class="text-start">No Blok</th><?= $thSusun('projek', 'Projek', 'text-start') . $thSusun('model', 'Model') ?>
        <?= $thSusun('peladang', 'Bil Peladang', 'num') . $thSusun('luas', 'Luas (Ha)', 'num') ?><th class="num">Sewaan (RM)</th><th class="num">Modal Operasi (RM)</th><?= $thSusun('jumlah', 'Jumlah (RM)', 'num') ?>
        <th>Musim Pelaksanaan</th><th>Fasa</th><?= $thSusun('tamat', 'Tamat') . $thSusun('status', 'Status') ?><th class="op-lajur-tindakan no-print">Tindakan</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="17" class="op-kosong"><i class="bi bi-inbox" aria-hidden="true"></i><div>Tiada rekod sepadan dengan carian atau penapis.</div>
          <?php if ($labelPenapis): ?><a href="penyaluran.php">Set semula penapis</a><?php endif; ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $pi = $ppkInfo[$r['ppk_id']] ?? null; $bid = 'butiran-' . (int)$r['id'];
        $ringkas = $r['ppk_kod'] . ' · ' . $r['musim'] . ' · ' . ($r['projek'] ?: '-') . ' · ' . $r['model'];
        $pdhl = $pautan['pendahulu'][$r['id']] ?? []; $smbg = $pautan['sambungan'][$r['id']] ?? [];
        $kp = keadaan_pautan($r, count($pdhl));
        $notaJenis = ['tiada_rekod' => ['Tiada Dalam Rekod', LABEL_TIADA_REKOD], 'lama' => ['belum dipautkan', LABEL_SAMBUNGAN_LAMA],
                      'dipautkan' => ['← ' . count($pdhl) . ' terdahulu', 'Dipautkan kepada ' . count($pdhl) . ' perjanjian terdahulu']][$kp] ?? null; ?>
        <tr class="op-baris">
          <td class="op-lajur-butiran no-print"><button type="button" class="btn op-btn-ikon op-togol-butiran" data-bs-toggle="collapse" data-bs-target="#<?= $bid ?>"
              aria-expanded="false" aria-controls="<?= $bid ?>" title="Lihat butiran" aria-label="Lihat butiran rekod <?= e($ringkas) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>
          <td class="text-start op-ppk"><span class="op-ppk-kod"><?= e($r['ppk_kod']) ?></span><span class="op-ppk-nama"><?= e($r['ppk_nama']) ?></span></td>
          <td><?= $r['jenis'] === 'SAMBUNG' ? '<span class="st-badge st-biru">Sambung</span>' : '<span class="st-badge st-kelabu">Asal</span>' ?>
            <?php if ($notaJenis): ?><span class="op-pautan-nota op-pautan-<?= e($kp) ?>" title="<?= e($notaJenis[1]) ?>"><?= e($notaJenis[0]) ?></span><?php endif; ?></td>
          <td class="text-nowrap"><?= e($r['musim']) ?></td>
          <td class="text-start"><?= e($r['no_blok']) ?></td>
          <td class="text-start op-projek"><a href="penyaluran_lihat.php?id=<?= (int)$r['id'] . e($kembali) ?>" title="Buka butiran rekod #<?= (int)$r['id'] ?>"><?= e($r['projek'] ?: '(tiada nama projek)') ?></a></td>
          <td class="text-nowrap text-start"><span class="legend-dot" style="background:<?= MODEL_COLORS[$r['model']] ?? '#999' ?>"></span><?= e($r['model']) ?></td>
          <td class="num"><?= nint($r['bil_peladang']) ?></td>
          <td class="num"><?= ha($r['luas_ha']) ?></td>
          <td class="num"><?= (float)$r['sewaan'] ? rm($r['sewaan']) : '-' ?></td>
          <td class="num"><?= rm($r['modal_operasi']) ?></td>
          <td class="num fw-semibold"><?= rm((float)$r['sewaan'] + (float)$r['modal_operasi']) ?></td>
          <td class="text-nowrap"><?= e($r['musim_pelaksanaan']) ?></td>
          <td><?= e($r['fasa']) ?></td>
          <td class="text-nowrap"><?= e($r['musim_tamat']) ?></td>
          <td><?= lencana_status($r['status']) ?></td>
          <td class="op-lajur-tindakan no-print">
            <div class="op-tindakan-baris">
              <a class="btn op-btn-ikon op-btn-lihat" href="penyaluran_lihat.php?id=<?= $r['id'] . e($kembali) ?>" title="Lihat butiran" aria-label="Lihat butiran rekod <?= e($ringkas) ?>"><i class="bi bi-eye" aria-hidden="true"></i></a>
              <a class="btn op-btn-ikon op-btn-edit" href="penyaluran_form.php?id=<?= $r['id'] . e($kembali) ?>" title="Edit" aria-label="Edit rekod <?= e($ringkas) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline op-borang-padam" data-ringkas="<?= e($ringkas) ?>">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="padam"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="qs" value="<?= e($qsKembali) ?>">
                <button type="button" class="btn op-btn-ikon op-btn-padam" title="Padam" aria-label="Padam rekod <?= e($ringkas) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <tr class="op-baris-butiran no-print"><td colspan="17" class="p-0">
          <div class="collapse" id="<?= $bid ?>"><div class="op-butiran">
            <?php foreach ([
                ['Wilayah', $pi ? ($wilayahNama[$pi['wilayah_id']] ?? '-') : '-'], ['PPK', $r['ppk_kod'] . ' ' . $r['ppk_nama']],
                ['Jenis', $r['jenis'] === 'SAMBUNG' ? 'Sambung Perjanjian' : 'Asal'], ['Musim mula', $r['musim']],
                ['Model', $r['model'] . ' - ' . (MODELS[$r['model']] ?? '')], ['No Blok', $r['no_blok']], ['Projek', $r['projek']], ['Kump', $r['kump']],
                ['Bil Peladang', nint($r['bil_peladang'])], ['Luas (Ha)', ha($r['luas_ha'])],
                ['Kadar Sewa / Ha (RM)', (float)$r['kadar_sewa'] ? rm($r['kadar_sewa']) : '-'], ['Sewaan / Pajakan (RM)', (float)$r['sewaan'] ? rm($r['sewaan']) : '-'],
                ['Modal Operasi (RM)', rm($r['modal_operasi'])], ['Jumlah rekod ini (RM)', rm((float)$r['sewaan'] + (float)$r['modal_operasi'])],
                ['Musim Pelaksanaan', $r['musim_pelaksanaan']], ['Bil Musim', $r['bil_musim']], ['Fasa Penyaluran', $r['fasa']], ['Musim Tamat', $r['musim_tamat']],
                ['Status', $r['status']], ['Tindakan & Status', $r['tindakan']], ['Catatan', $r['catatan']],
            ] as [$lb, $nl]): ?>
              <div class="op-butiran-item"><dt><?= e($lb) ?></dt><dd><?= ($nl === null || $nl === '') ? '<span class="text-muted">-</span>' : e($nl) ?></dd></div>
            <?php endforeach; ?>
            <?php if ($kp || $smbg): ?>
              <div class="op-butiran-item op-butiran-pautan"><dt>Perjanjian terdahulu</dt><dd>
                <?php if ($kp === 'tiada_rekod'): ?><span class="st-badge st-kuning"><?= e(LABEL_TIADA_REKOD) ?></span>
                <?php elseif ($kp === 'lama'): ?><span class="st-badge st-kelabu"><?= e(LABEL_SAMBUNGAN_LAMA) ?></span>
                <?php elseif ($pdhl): ?><ul class="op-pautan-senarai"><?php foreach ($pdhl as $pr): ?><li><a href="penyaluran_lihat.php?id=<?= (int)$pr['id'] . e($kembali) ?>"><?= e(teks_rekod_pautan($pr)) ?></a> <?= lencana_status($pr['status']) ?></li><?php endforeach; ?></ul>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?></dd></div>
              <div class="op-butiran-item op-butiran-pautan"><dt>Disambung oleh</dt><dd>
                <?php if ($smbg): ?><ul class="op-pautan-senarai"><?php foreach ($smbg as $sr): ?><li><a href="penyaluran_lihat.php?id=<?= (int)$sr['id'] . e($kembali) ?>"><?= e(teks_rekod_pautan($sr)) ?></a> <?= lencana_status($sr['status']) ?></li><?php endforeach; ?></ul>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?></dd></div>
            <?php endif; ?>
          </div></div>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer op-kaki no-print">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <small class="text-muted"><?= $tot['n'] ? 'Memaparkan ' . nint($dari) . '–' . nint($hingga) . ' daripada ' . nint($tot['n']) . ' rekod' . ($pages > 1 ? " · halaman $page / $pages" : '') : '0 rekod' ?></small>
      <form class="d-flex align-items-center gap-1" aria-label="Saiz halaman">
        <?php foreach (array_diff_key($f, ['saiz' => 1, 'page' => 1, 'eksport' => 1]) as $k => $v): if (in_array($k, PENAPIS_KEKAL, true) && $v !== ''): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
        <label class="small text-muted" for="saizHalaman">Paparan</label>
        <select id="saizHalaman" name="saiz" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
          <?php foreach (SAIZ_HALAMAN as $sz): ?><option value="<?= $sz ?>"<?= $sz === $perPage ? ' selected' : '' ?>><?= $sz ?></option><?php endforeach; ?>
        </select><span class="small text-muted">rekod / halaman</span>
        <noscript><button class="btn btn-outline-secondary btn-sm">OK</button></noscript>
      </form>
    </div>
    <?php if ($pages > 1): ?>
    <nav aria-label="Halaman rekod">
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $urlHal(max(1, $page - 1)) ?>" aria-label="Halaman sebelumnya"><i class="bi bi-chevron-left" aria-hidden="true"></i></a></li>
        <?php
        $win = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($x) => $x >= 1 && $x <= $pages));
        sort($win);
        $prev = 0;
        foreach ($win as $pg):
          if ($pg - $prev > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          $prev = $pg; ?>
          <li class="page-item <?= $pg === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $urlHal($pg) ?>"<?= $pg === $page ? ' aria-current="page"' : '' ?>><?= $pg ?></a></li>
        <?php endforeach; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $urlHal(min($pages, $page + 1)) ?>" aria-label="Halaman seterusnya"><i class="bi bi-chevron-right" aria-hidden="true"></i></a></li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</div>

<!-- Pengesahan padam (UI-04): rekod dipadam hanya selepas pengesahan; borang POST + CSRF sedia ada digunakan -->
<div class="modal fade" id="modalPadam" tabindex="-1" aria-labelledby="modalPadamTajuk" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h6" id="modalPadamTajuk"><i class="bi bi-exclamation-triangle text-danger" aria-hidden="true"></i> Padam rekod?</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
    <div class="modal-body"><p class="mb-2">Rekod berikut akan dipadam secara kekal:</p><p class="op-padam-ringkas fw-semibold mb-2" id="padamRingkas"></p>
      <p class="small text-muted mb-0">Tindakan ini tidak boleh diundur kecuali melalui restore backup.</p></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="button" class="btn btn-danger btn-sm" id="sahPadam"><i class="bi bi-trash" aria-hidden="true"></i> Padam</button></div>
  </div></div>
</div>
<?php
$extraScripts = <<<'JS'
<script>
(function () {
  // Pengesahan padam: buka modal; hantar borang baris yang dipilih sekali sahaja
  const modalEl = document.getElementById('modalPadam'), modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  let borang = null;
  document.querySelectorAll('.op-borang-padam .op-btn-padam').forEach(b => b.addEventListener('click', () => {
    borang = b.closest('form');
    document.getElementById('padamRingkas').textContent = borang.dataset.ringkas;
    modal.show();
  }));
  modalEl.addEventListener('shown.bs.modal', () => document.getElementById('sahPadam').focus());
  document.getElementById('sahPadam').addEventListener('click', function () {
    if (!borang || this.disabled) return;
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Memadam…';
    borang.submit();
  });
  // Togol butiran: ikon berputar; panel selebar ruang jadual yang kelihatan (melekat di kiri semasa tatal mendatar)
  const wrap = document.querySelector('.op-jadual-kad .table-wrap');
  const lebarPanel = () => document.querySelectorAll('.op-butiran').forEach(el => el.style.width = wrap.clientWidth + 'px');
  document.querySelectorAll('.op-baris-butiran .collapse').forEach(c => {
    const btn = document.querySelector('[data-bs-target="#' + c.id + '"]');
    c.addEventListener('show.bs.collapse', () => { lebarPanel(); btn.classList.add('terbuka'); });
    c.addEventListener('hide.bs.collapse', () => btn.classList.remove('terbuka'));
  });
  window.addEventListener('resize', lebarPanel);
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
