<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/penyaluran_lib.php';
require_login();

/** Model yang menggunakan medan Kadar Sewa & Sewaan (EP: nilai penyaluran disimpan dalam medan sewaan — Fasa 2.1). */
const MODEL_SEWAAN = ['SP', 'EP'];

$id = (int)($_GET['id'] ?? 0);
// A3: penapis senarai asal, dibersihkan; digunakan untuk kembali selepas Simpan/Batal
$kembali = qs_penapis((string)($_POST['kembali'] ?? $_GET['kembali'] ?? ''));
$urlKembali = url_senarai($kembali);
$fields = ['ppk_id', 'jenis', 'musim', 'no_blok', 'projek', 'model', 'bil_peladang', 'luas_ha', 'kump', 'kadar_sewa',
           'sewaan', 'modal_operasi', 'musim_pelaksanaan', 'bil_musim', 'fasa', 'musim_tamat', 'status', 'tindakan', 'catatan'];
$rec = array_fill_keys($fields, '');
$rec['jenis'] = 'ASAL';
$rec['status'] = 'AKTIF';
$rec['model'] = 'LKPPK';

// Fasa 4.3: keadaan rekod (pautan, penanda, token) untuk paparan. Semasa simpan, keadaan dibaca semula di bawah kunci.
$keadaan = null;
if ($id) {
    $keadaan = keadaan_rekod_edit(db(), $id) ?: redirect($urlKembali);
    $rec = $keadaan['rekod'];
}
$asal = $rec;   // nilai tersimpan (rekod lama) — untuk membenarkan data lama yang tidak diubah
// Perjanjian Fasa 2/3: rekod dalam aliran sambungan — jenis & status kekal nilai tersimpan (pelayan berkuasa)
$terkunci = (bool)($keadaan['terkunci'] ?? false);
// Fasa 4.1: Perjanjian Sambungan AKTIF boleh ditamatkan (AKTIF → TAMAT); jenis & pautan kekal dikunci
$bolehTamat = (bool)($keadaan['boleh_tamat'] ?? false);
$pautan = $id ? pautan_rekod(db(), [$id]) : ['pendahulu' => [], 'sambungan' => []];
// Keadaan panel pendahulu (rekod baharu): dipulihkan daripada POST jika borang dipaparkan semula
$pendahuluPilihan = [];
$tiadaRekodDipilih = false;

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Semasa edit, medan yang tiada dalam borang kekal dengan nilai asal (tidak dikosongkan)
    foreach ($fields as $k) {
        if (array_key_exists($k, $_POST) || !$id) $rec[$k] = trim((string)($_POST[$k] ?? ''));
        else $rec[$k] = (string)($rec[$k] ?? '');
    }
    // Rekod terkunci: jenis & status kekal nilai tersimpan (SAMBUNG hanya ditetapkan oleh aliran sambungan)
    // (Fasa 4.1: status sambungan AKTIF diambil daripada borang dan disahkan terhadap status_dibenar)
    if ($terkunci) { $rec['jenis'] = (string)$asal['jenis']; if (!$bolehTamat) $rec['status'] = (string)$asal['status']; }
    // Perjanjian Sambungan baharu sentiasa AKTIF (O-3); medan status tidak dipaparkan dalam mod ini
    if (!$id && $rec['jenis'] === 'SAMBUNG') $rec['status'] = 'AKTIF';
    if (!$id) {
        $rawPendahulu = $_POST['pendahulu_id'] ?? null;
        $pendahuluPilihan = is_array($rawPendahulu) ? rekod_ringkas_ikut_id(db(), array_filter($rawPendahulu, 'is_string')) : [];
        $tiadaRekodDipilih = ($_POST['pendahulu_tiada_rekod'] ?? '') === '1';
    }

    // A4: validasi pelayan (hanya untuk rekod yang ditambah / diedit; data lama tidak diubah)
    $label = ['bil_peladang' => 'Bil Peladang', 'luas_ha' => 'Luas (Ha)', 'kadar_sewa' => 'Kadar Sewa / Ha',
              'sewaan' => 'Sewaan / Pajakan', 'modal_operasi' => 'Modal Operasi'];
    $luasKosong = $rec['luas_ha'] === '';
    if ($luasKosong) $errors[] = 'Luas (Ha) wajib diisi.';
    $tidakSah = [];
    foreach ($label as $k => $t) {
        $rec[$k] = str_replace(',', '', $rec[$k]);
        if ($rec[$k] === '') $rec[$k] = 0;
        if (!is_numeric($rec[$k]) || $rec[$k] < 0) { $errors[] = "$t mesti nombor yang sah (tidak negatif)."; $tidakSah[$k] = true; }
    }
    if (!isset($tidakSah['bil_peladang']) && !preg_match('/^\d{1,9}$/', (string)$rec['bil_peladang'])) $errors[] = 'Bil Peladang mesti nombor bulat (tanpa perpuluhan).';
    if (!$luasKosong && !isset($tidakSah['luas_ha']) && (float)$rec['luas_ha'] <= 0) $errors[] = 'Luas (Ha) mesti lebih besar daripada 0.';

    if (!(int)$rec['ppk_id']) $errors[] = 'Sila pilih PPK.';
    elseif (!in_array((int)$rec['ppk_id'], array_map('intval', array_column(senarai_ppk(), 'id')), true)) $errors[] = 'PPK tidak sah.';

    // Musim: 1/YYYY atau 2/YYYY. Rekod lama dengan format lain dibenarkan selagi musimnya tidak diubah.
    $musimLamaTidakDiubah = $id && $rec['musim'] === (string)$asal['musim'] && musim_susun($rec['musim']) > 0;
    if ($rec['musim'] === '') $errors[] = 'Musim wajib diisi.';
    elseif (!musim_format_sah($rec['musim']) && !$musimLamaTidakDiubah) $errors[] = 'Musim mesti dalam format 1/YYYY atau 2/YYYY (contoh 1/2026). Musim 0, 3 dan ke atas tidak diterima.';

    if ($rec['model'] === '') $errors[] = 'Sila pilih Model.';
    elseif (!isset(MODELS[$rec['model']])) $errors[] = 'Model tidak sah.';
    // Rekod terkunci: jenis/status tidak ditulis (nilai DB dikekalkan), jadi nilai tersimpan luar biasa tidak menyekat simpanan
    if (!$terkunci && !in_array($rec['jenis'], ['ASAL', 'SAMBUNG'], true)) $errors[] = 'Jenis tidak sah.';
    if (!in_array($rec['status'], $id ? $keadaan['status_dibenar'] : STATUS_MANUAL, true)) $errors[] = 'Status tidak sah.';

    // A2: Kadar Sewa & Sewaan hanya untuk model SP/EP. Bagi model lain, nilai yang dihantar ditolak;
    // nilai lama yang tidak dihantar (medan dinyahaktif selepas tukar model) dikosongkan kepada 0.
    if (isset(MODELS[$rec['model']]) && !in_array($rec['model'], MODEL_SEWAAN, true)) {
        foreach (['kadar_sewa' => 'Kadar Sewa / Ha', 'sewaan' => 'Sewaan / Pajakan'] as $k => $t) {
            if (array_key_exists($k, $_POST) && is_numeric($rec[$k]) && (float)$rec[$k] != 0) {
                $errors[] = "$t hanya boleh diisi untuk model SP dan EP.";
            }
            $rec[$k] = 0;
        }
    }

    // Perjanjian Sambungan baharu: semakan input pautan awal (sama seperti backend) dan pengesahan pengguna (Q3).
    // simpan_rekod_baharu() kekal sebagai pengesahan muktamad.
    if (!$id && $rec['jenis'] === 'SAMBUNG' && !$errors) {
        $errors = sahkan_input_sambungan('SAMBUNG', $_POST['pendahulu_id'] ?? null, $_POST['pendahulu_tiada_rekod'] ?? null)['errors'];
        if (!$errors && ($_POST['sahkan_sambungan'] ?? '') !== '1') $errors[] = 'Sila sahkan Perjanjian Sambungan sebelum menyimpan.';
    }

    if (!$errors) {
        $vals = array_map(fn($k) => $rec[$k] === '' ? null : $rec[$k], $fields);
        $vals[] = musim_susun($rec['musim']);
        if ($id) {
            // Fasa 4.3: transaksi — kunci rekod, semak semula pautan/status terhadap token borang, tulis lajur dibenarkan sahaja
            $token = is_string($_POST['keadaan'] ?? null) ? $_POST['keadaan'] : null;
            $hasil = simpan_edit_rekod(db(), $id, array_combine([...$fields, 'musim_susun'], $vals), $token);
            if ($hasil['ok']) {
                flash('Rekod telah dikemas kini.');
                redirect($urlKembali);
            }
            $errors = $hasil['errors'];
        } else {
            // Rekod baharu: Perjanjian Asal atau Sambungan (pautan + status pendahulu, atomik)
            $hasil = simpan_rekod_baharu(db(), array_combine([...$fields, 'musim_susun'], $vals),
                $_POST['pendahulu_id'] ?? null, $_POST['pendahulu_tiada_rekod'] ?? null, (int)(current_user()['id'] ?? 0) ?: null);
            if ($hasil['ok']) {
                flash('Rekod baharu telah ditambah.');
                foreach ($hasil['amaran'] as $a) flash($a, 'warning');
                redirect($urlKembali);
            }
            $errors = $hasil['errors'];
        }
    }
}

$pageTitle = $id ? 'Edit Rekod' : 'Tambah Rekod';
require __DIR__ . '/includes/header.php';
$opt = fn($val, $cur, $label = null) => '<option value="' . e($val) . '"' . ((string)$val === (string)$cur ? ' selected' : '') . '>' . e($label ?? $val) . '</option>';

// UI-04: padankan mesej ralat pelayan (tidak diubah) kepada medan untuk paparan sebaris — paparan sahaja
$petaRalat = ['Kadar Sewa' => 'kadar_sewa', 'Sewaan' => 'sewaan', 'Modal Operasi' => 'modal_operasi', 'Bil Peladang' => 'bil_peladang',
              'Luas' => 'luas_ha', 'PPK' => 'ppk_id', 'Musim' => 'musim', 'Model' => 'model', 'Jenis' => 'jenis', 'Status' => 'status'];
$ralatMedan = [];
foreach ($errors as $er) foreach ($petaRalat as $kunci => $medan) if (str_contains($er, $kunci)) { $ralatMedan[$medan][] = $er; break; }
$tak = fn(string $k) => isset($ralatMedan[$k]) ? ' is-invalid' : '';
$fb = fn(string $k) => isset($ralatMedan[$k]) ? '<div class="invalid-feedback" id="ralat-' . $k . '">' . e(implode(' ', $ralatMedan[$k])) . '</div>' : '';
$aria = fn(string $k) => isset($ralatMedan[$k]) ? ' aria-invalid="true" aria-describedby="ralat-' . $k . '"' : '';
$wajib = '<span class="op-wajib" aria-hidden="true">*</span>';
$wilayahNama = array_column(senarai_wilayah(), 'nama', 'id');
$ppkIkutWilayah = [];
foreach (senarai_ppk() as $p) $ppkIkutWilayah[$p['wilayah_id']][] = $p;
?>
<div class="op-kepala">
  <div>
    <h1 class="page-title"><?= $pageTitle ?></h1>
    <p class="op-sub"><?= $id ? 'Kemas kini maklumat rekod penyaluran.' : 'Isi maklumat rekod penyaluran baharu.' ?>
      Medan bertanda <span class="op-wajib">*</span> wajib diisi.
      <?php if ($id): ?><span class="op-meta">ID #<?= (int)$id ?><?= !empty($asal['updated_at']) ? ' · dikemas kini ' . e(date('d/m/Y H:i', strtotime($asal['updated_at']))) : '' ?></span><?php endif; ?></p>
  </div>
  <a href="<?= e($urlKembali) ?>" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali</a>
</div>
<?php if ($errors): ?><div role="alert" tabindex="-1" id="ringkasanRalat" class="op-ralat alert alert-danger"><b><i class="bi bi-exclamation-octagon" aria-hidden="true"></i> Rekod tidak disimpan. Sila betulkan:</b><ul class="mb-0 mt-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="card op-borang" id="borangRekod" aria-label="<?= e($pageTitle) ?>" data-validasi>
  <?= csrf_field() ?>
  <input type="hidden" name="kembali" value="<?= e($kembali) ?>">
<?php if ($id): ?>
  <input type="hidden" name="keadaan" value="<?= e($keadaan['token']) ?>">
<?php endif; ?>
<?php if (!$id): $modSambung = $rec['jenis'] === 'SAMBUNG'; ?>
  <input type="hidden" name="sahkan_sambungan" id="sahkanSambungan" value="0">

  <section class="op-seksyen" aria-labelledby="tajukJenis">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><div><h2 id="tajukJenis">Jenis Perjanjian</h2><p>Perjanjian baharu, atau sambungan kepada perjanjian terdahulu.</p></div></div>
    <div><div class="op-jenis-pilihan<?= $tak('jenis') ?>" role="radiogroup" aria-labelledby="tajukJenis">
      <label class="op-jenis-kad"><input type="radio" class="form-check-input" name="jenis" value="ASAL"<?= $modSambung ? '' : ' checked' ?>>
        <span><b>Perjanjian Asal</b><small>Perjanjian pertama. Tiada perjanjian terdahulu.</small></span></label>
      <label class="op-jenis-kad"><input type="radio" class="form-check-input" name="jenis" value="SAMBUNG"<?= $modSambung ? ' checked' : '' ?>>
        <span><b>Perjanjian Sambungan</b><small>Menyambung perjanjian terdahulu, yang akan ditanda SAMBUNG.</small></span></label>
    </div><?= $fb('jenis') ?></div>
  </section>

  <section class="op-seksyen" id="panelPendahulu" data-mod="SAMBUNG" aria-labelledby="tajukPendahulu"<?= $modSambung ? '' : ' hidden' ?>>
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span><div><h2 id="tajukPendahulu">Perjanjian Terdahulu</h2><p>Pilih perjanjian terdahulu yang terkini (satu atau lebih). Perjanjian TAMAT tidak boleh dipilih.</p></div></div>
    <div>
      <div class="row g-2 align-items-end" role="search" aria-label="Cari perjanjian terdahulu">
        <div class="col-md-6 col-xl-4"><label class="form-label" for="cpPpk">PPK</label>
          <select id="cpPpk" class="form-select form-select-sm"><option value="">Semua PPK</option>
            <?php foreach ($ppkIkutWilayah as $wid => $senarai): ?>
              <optgroup label="<?= e($wilayahNama[$wid] ?? '') ?>"><?php foreach ($senarai as $p) echo $opt($p['id'], $rec['ppk_id'], "{$p['kod']} {$p['nama']}"); ?></optgroup>
            <?php endforeach; ?>
          </select></div>
        <div class="col-md-6 col-xl-3"><label class="form-label" for="cpProjek">Projek</label><input id="cpProjek" class="form-control form-control-sm" maxlength="100" autocomplete="off"></div>
        <div class="col-md-4 col-xl-2"><label class="form-label" for="cpBlok">No Blok</label><input id="cpBlok" class="form-control form-control-sm" maxlength="100" autocomplete="off"></div>
        <div class="col-md-4 col-xl-2"><label class="form-label" for="cpModel">Model</label>
          <select id="cpModel" class="form-select form-select-sm"><option value="">Semua</option><?php foreach (MODELS as $k => $t) echo $opt($k, '', $k); ?></select></div>
        <div class="col-md-4 col-xl-1"><button type="button" id="cpButang" class="btn btn-sipadi btn-sm w-100"><i class="bi bi-search" aria-hidden="true"></i> Cari</button></div>
      </div>
      <div id="cpStatus" class="small text-muted mt-2" aria-live="polite">Skop lalai: PPK pada borang. Pilih "Semua PPK" untuk mencari di PPK lain.</div>
      <div class="op-pendahulu-hasil mt-2" id="cpHasilBalut" hidden>
        <table class="table table-sm table-data mb-0">
          <thead><tr><th class="text-start">PPK</th><th class="text-start">Projek</th><th class="text-start">No Blok</th><th>Model</th><th>Musim</th><th>Musim Tamat</th>
            <th class="num">Peladang</th><th class="num">Luas (Ha)</th><th>Status</th><th><span class="visually-hidden">Pilih</span></th></tr></thead>
          <tbody id="cpHasil"></tbody>
        </table>
      </div>
      <h3 class="op-subtajuk mt-3">Dipilih (<span id="bilDipilih">0</span>)</h3>
      <ul id="senaraiDipilih" class="op-pendahulu-dipilih list-unstyled mb-0" aria-live="polite"></ul>
      <p id="kosongDipilih" class="small text-muted mb-0">Tiada perjanjian terdahulu dipilih.</p>
      <div class="form-check op-tiada-rekod mt-3">
        <input class="form-check-input" type="checkbox" name="pendahulu_tiada_rekod" value="1" id="tiadaRekod"<?= $tiadaRekodDipilih ? ' checked' : '' ?>>
        <label class="form-check-label" for="tiadaRekod"><b><?= e(LABEL_TIADA_REKOD) ?></b><span class="d-block small text-muted">Perjanjian terdahulu tiada dalam EPMS. Tidak boleh digabung dengan pilihan perjanjian terdahulu.</span></label>
      </div>
      <div id="ralatPendahulu" class="op-ralat-panel mt-2" role="alert" hidden></div>
    </div>
  </section>
<?php elseif ($terkunci || $rec['jenis'] === 'SAMBUNG'):
    $pdhl = $pautan['pendahulu'][$id] ?? []; $smbg = $pautan['sambungan'][$id] ?? [];
    $kp = keadaan_pautan($asal, count($pdhl)); ?>

  <section class="op-seksyen" aria-labelledby="tajukPautan">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span><div><h2 id="tajukPautan">Pautan Perjanjian</h2><p>Hubungan dengan perjanjian terdahulu dan sambungan.</p></div></div>
    <div><dl class="op-pautan mb-0">
      <dt>Perjanjian terdahulu</dt>
      <dd><?php if ($kp === 'tiada_rekod'): ?><span class="st-badge st-kuning"><?= e(LABEL_TIADA_REKOD) ?></span>
        <?php elseif ($kp === 'lama'): ?><span class="st-badge st-kelabu">Sambungan lama: belum dipautkan</span><span class="d-block small text-muted mt-1">Rekod sejarah (import Excel) tanpa pautan. Ini berbeza daripada "<?= e(LABEL_TIADA_REKOD) ?>".</span>
        <?php elseif ($pdhl): ?><ul class="op-pautan-senarai"><?php foreach ($pdhl as $pr): ?><li><a href="penyaluran_form.php?id=<?= (int)$pr['id'] ?>"><?= e(teks_rekod_pautan($pr)) ?></a> <?= lencana_status($pr['status']) ?></li><?php endforeach; ?></ul>
        <?php else: ?><span class="text-muted">-</span><?php endif; ?></dd>
      <dt>Disambung oleh</dt>
      <dd><?php if ($smbg): ?><ul class="op-pautan-senarai"><?php foreach ($smbg as $sr): ?><li><a href="penyaluran_form.php?id=<?= (int)$sr['id'] ?>"><?= e(teks_rekod_pautan($sr)) ?></a> <?= lencana_status($sr['status']) ?></li><?php endforeach; ?></ul>
        <?php else: ?><span class="text-muted">-</span><?php endif; ?></dd>
    </dl>
    <?php if ($terkunci): ?><p class="small text-muted mb-0 op-pautan-nota-kunci"><i class="bi bi-lock" aria-hidden="true"></i> <?= $keadaan['luar_biasa'] && !$pdhl && !$smbg && (int)$asal['pendahulu_tiada_rekod'] !== 1
        ? 'Jenis atau status rekod ini bukan nilai biasa dan dikekalkan seperti tersimpan. Hubungi pentadbir sistem untuk pembetulan.'
        : ($bolehTamat ? 'Jenis dan pautan rekod ini ditetapkan oleh aliran sambungan dan tidak boleh diubah di sini. Status boleh ditukar kepada TAMAT apabila perjanjian sambungan ini tamat.'
        : 'Jenis dan status rekod ini ditetapkan oleh aliran sambungan dan tidak boleh diubah di sini.') ?></p><?php endif; ?></div>
  </section>
<?php endif; ?>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-building" aria-hidden="true"></i></span><div><h2>PPK &amp; Projek</h2><p>Lokasi dan maklumat projek.</p></div></div>
    <div class="row g-3">
      <div class="col-md-6 col-xl-5">
        <label class="form-label" for="ppk_id">PPK <?= $wajib ?></label>
        <select name="ppk_id" id="ppk_id" class="form-select<?= $tak('ppk_id') ?>" required<?= $aria('ppk_id') ?>>
          <option value="">— Pilih PPK —</option>
          <?php foreach ($ppkIkutWilayah as $wid => $senarai): ?>
            <optgroup label="<?= e($wilayahNama[$wid] ?? '') ?>"><?php foreach ($senarai as $p) echo $opt($p['id'], $rec['ppk_id'], "{$p['kod']} {$p['nama']}"); ?></optgroup>
          <?php endforeach; ?>
        </select><?= $fb('ppk_id') ?>
      </div>
      <?php if ($id && $terkunci): ?>
      <div class="col-md-6 col-xl-3">
        <span class="form-label d-block">Jenis</span>
        <div class="form-control-plaintext"><?= $asal['jenis'] === 'SAMBUNG' ? '<span class="st-badge st-biru">Sambung Perjanjian</span>' : '<span class="st-badge st-kelabu">Asal</span>' ?>
          <span class="medan-auto-tag"><i class="bi bi-lock" aria-hidden="true"></i> Dikunci</span></div>
      </div>
      <?php elseif ($id): ?>
      <div class="col-md-6 col-xl-3">
        <label class="form-label" for="jenis">Jenis</label>
        <select name="jenis" id="jenis" class="form-select<?= $tak('jenis') ?>"<?= $aria('jenis') ?>><?= $opt('ASAL', $rec['jenis'], 'Asal') . $opt('SAMBUNG', $rec['jenis'], 'Sambung Perjanjian') ?></select><?= $fb('jenis') ?>
      </div>
      <?php endif; ?>
      <div class="col-md-4 col-xl-4">
        <label class="form-label" for="kump">Kump</label>
        <input name="kump" id="kump" class="form-control" maxlength="20" placeholder="BARU / A / B ..." value="<?= e($rec['kump']) ?>">
      </div>
      <div class="col-md-4 col-xl-5">
        <label class="form-label" for="no_blok">No Blok</label>
        <input name="no_blok" id="no_blok" class="form-control" maxlength="100" value="<?= e($rec['no_blok']) ?>">
      </div>
      <div class="col-md-4 col-xl-7">
        <label class="form-label" for="projek">Projek</label>
        <input name="projek" id="projek" class="form-control" maxlength="150" value="<?= e($rec['projek']) ?>">
      </div>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-calendar3" aria-hidden="true"></i></span><div><h2>Musim &amp; Model</h2><p>Musim mula dan model penyaluran.</p></div></div>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label" for="musim">Musim mula <?= $wajib ?></label>
        <input name="musim" id="musim" class="form-control<?= $tak('musim') ?>" required placeholder="1/2026" value="<?= e($rec['musim']) ?>" list="musimList"<?= $aria('musim') ?>
          <?php if (!$id || musim_format_sah((string)$asal['musim'])): ?>pattern="[12]/[0-9]{4}" title="Format 1/YYYY atau 2/YYYY, contoh 1/2026"<?php endif; ?>>
        <datalist id="musimList"><?php foreach (senarai_musim() as $m) echo '<option value="' . e($m) . '">'; ?></datalist>
        <?= $fb('musim') ?: '<div class="form-text">Format 1/YYYY atau 2/YYYY.</div>' ?>
      </div>
      <div class="col-md-8">
        <label class="form-label" for="model">Model <?= $wajib ?></label>
        <select name="model" id="model" class="form-select<?= $tak('model') ?>" required<?= $aria('model') ?>>
          <?php foreach (MODELS as $k => $t) echo $opt($k, $rec['model'], "$k - $t"); ?>
        </select><?= $fb('model') ?>
      </div>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-people" aria-hidden="true"></i></span><div><h2>Peladang &amp; Keluasan</h2><p>Bilangan peladang dan keluasan (hektar).</p></div></div>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label" for="bil">Bil Peladang</label>
        <input name="bil_peladang" id="bil" type="number" min="0" step="1" inputmode="numeric" class="form-control<?= $tak('bil_peladang') ?>" value="<?= e($rec['bil_peladang']) ?>" title="Nombor bulat"<?= $aria('bil_peladang') ?>>
        <?= $fb('bil_peladang') ?: '<div class="form-text">Nombor bulat.</div>' ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="luas">Luas (Ha) <?= $wajib ?></label>
        <input name="luas_ha" id="luas" type="number" min="0.001" step="0.001" inputmode="decimal" class="form-control<?= $tak('luas_ha') ?>" required value="<?= e($rec['luas_ha']) ?>" title="Mesti lebih besar daripada 0"<?= $aria('luas_ha') ?>>
        <?= $fb('luas_ha') ?: '<div class="form-text">Mesti lebih besar daripada 0.</div>' ?>
      </div>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span><div><h2>Sewaan &amp; Modal Operasi</h2><p>Kadar Sewa dan Sewaan hanya untuk model SP dan EP.</p></div></div>
    <div class="row g-3">
      <div class="col-md-4 sp-only">
        <label class="form-label" for="kadar">Kadar Sewa / Ha (RM)</label>
        <input name="kadar_sewa" id="kadar" type="number" min="0" step="0.01" inputmode="decimal" class="form-control<?= $tak('kadar_sewa') ?>" value="<?= e($rec['kadar_sewa']) ?>"<?= $aria('kadar_sewa') ?>><?= $fb('kadar_sewa') ?>
      </div>
      <div class="col-md-8 col-xl-4 sp-only">
        <label class="form-label" for="sewaan">Sewaan / Pajakan (RM)</label>
        <div class="input-group has-validation">
          <input name="sewaan" id="sewaan" type="number" min="0" step="0.01" inputmode="decimal" class="form-control<?= $tak('sewaan') ?>" value="<?= e($rec['sewaan']) ?>"<?= $aria('sewaan') ?>>
          <button type="button" class="btn btn-outline-secondary" id="kiraSewa" title="Luas × Kadar × Bil Musim"><i class="bi bi-calculator" aria-hidden="true"></i> Kira</button>
          <?= $fb('sewaan') ?>
        </div>
        <div class="form-text">Kira = Luas × Kadar × Bil Musim.</div>
      </div>
      <div class="col-md-8 col-xl-4">
        <label class="form-label" for="mo">Modal Operasi (RM)</label>
        <div class="input-group has-validation">
          <input name="modal_operasi" id="mo" type="number" min="0" step="0.01" inputmode="decimal" class="form-control<?= $tak('modal_operasi') ?>" value="<?= e($rec['modal_operasi']) ?>"<?= $aria('modal_operasi') ?>>
          <select id="kadarMo" class="form-select op-kadar-mo" title="Kira: Luas × kadar" aria-label="Kira Modal Operasi ikut kadar (RM/Ha)">
            <option value="">Kira…</option><option>3000</option><option>3500</option><option>4200</option>
          </select>
          <?= $fb('modal_operasi') ?>
        </div>
        <div class="form-text">Kira = Luas × kadar (RM/Ha).</div>
      </div>
      <div class="col-12">
        <div class="op-jumlah" aria-live="polite" aria-readonly="true" title="Dikira automatik daripada Sewaan + Modal Operasi; tidak boleh diedit"><span>Jumlah penyaluran rekod ini</span><strong id="jumlahRekod">RM 0.00</strong><small id="jumlahPecahan"></small>
          <span class="medan-auto-tag"><i class="bi bi-lock" aria-hidden="true"></i> Dikira automatik</span></div>
      </div>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span><div><h2>Pelaksanaan</h2><p>Tempoh dan fasa penyaluran.</p></div></div>
    <div class="row g-3">
      <div class="col-md-6 col-xl-4">
        <label class="form-label" for="musim_pelaksanaan">Musim Pelaksanaan</label>
        <input name="musim_pelaksanaan" id="musim_pelaksanaan" class="form-control" placeholder="1/2026-2/2028" value="<?= e($rec['musim_pelaksanaan']) ?>">
      </div>
      <div class="col-md-6 col-xl-2">
        <label class="form-label" for="bilMusim">Bil Musim</label>
        <input name="bil_musim" id="bilMusim" class="form-control" maxlength="10" value="<?= e($rec['bil_musim']) ?>">
      </div>
      <div class="col-md-6 col-xl-3">
        <label class="form-label" for="fasa">Fasa Penyaluran</label>
        <input name="fasa" id="fasa" class="form-control" placeholder="F2/26" value="<?= e($rec['fasa']) ?>">
      </div>
      <div class="col-md-6 col-xl-3">
        <label class="form-label" for="musim_tamat">Musim Tamat</label>
        <input name="musim_tamat" id="musim_tamat" class="form-control" placeholder="2/2028" value="<?= e($rec['musim_tamat']) ?>">
      </div>
    </div>
  </section>

  <section class="op-seksyen">
    <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-flag" aria-hidden="true"></i></span><div><h2>Status &amp; Catatan</h2><p>Status projek dan nota tindakan.</p></div></div>
    <div class="row g-3">
      <?php if ($bolehTamat): ?>
      <div class="col-md-4 col-xl-3">
        <label class="form-label" for="status">Status <span class="small text-muted fw-normal">(semasa: <?= lencana_status($asal['status']) ?>)</span></label>
        <select name="status" id="status" class="form-select<?= $tak('status') ?>"<?= isset($ralatMedan['status']) ? ' aria-invalid="true"' : '' ?> aria-describedby="<?= isset($ralatMedan['status']) ? 'ralat-status ' : '' ?>notaTamatSambungan"><?= $opt('AKTIF', $rec['status']) . $opt('TAMAT', $rec['status']) ?></select><?= $fb('status') ?>
        <div class="form-text" id="notaTamatSambungan">TAMAT: perjanjian sambungan ini telah tamat. Pautan dan status perjanjian terdahulu tidak berubah. TAMAT tidak boleh dikembalikan kepada AKTIF melalui borang.</div>
      </div>
      <?php elseif ($terkunci): ?>
      <div class="col-md-4 col-xl-3">
        <span class="form-label d-block">Status</span>
        <div class="form-control-plaintext"><?= lencana_status($asal['status']) ?> <span class="medan-auto-tag"><i class="bi bi-lock" aria-hidden="true"></i> Dikunci</span></div>
      </div>
      <?php else: $sambungBaharu = !$id && $rec['jenis'] === 'SAMBUNG'; ?>
      <div class="col-md-4 col-xl-3"<?= $id ? '' : ' data-mod="ASAL"' ?><?= $sambungBaharu ? ' hidden' : '' ?>>
        <label class="form-label" for="status">Status</label>
        <select name="status" id="status" class="form-select<?= $tak('status') ?>"<?= $aria('status') ?><?= $sambungBaharu ? ' disabled' : '' ?>><?= $opt('AKTIF', $rec['status']) . $opt('TAMAT', $rec['status']) ?></select><?= $fb('status') ?>
      </div>
      <?php if (!$id): ?>
      <div class="col-md-4 col-xl-3" data-mod="SAMBUNG"<?= $sambungBaharu ? '' : ' hidden' ?>>
        <span class="form-label d-block">Status</span>
        <div class="form-control-plaintext"><?= lencana_status('AKTIF') ?> <span class="medan-auto-tag"><i class="bi bi-lock" aria-hidden="true"></i> Automatik</span></div>
      </div>
      <?php endif; endif; ?>
      <div class="col-md-8 col-xl-9">
        <label class="form-label" for="tindakan">Tindakan &amp; Status</label>
        <input name="tindakan" id="tindakan" class="form-control" maxlength="255" value="<?= e($rec['tindakan']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="catatan">Catatan</label>
        <input name="catatan" id="catatan" class="form-control" value="<?= e($rec['catatan']) ?>">
      </div>
    </div>
  </section>

  <div class="op-bar-tindakan">
    <span class="op-bar-nota"><span class="op-wajib">*</span> Medan wajib</span>
    <div class="d-flex gap-2">
      <a href="<?= e($urlKembali) ?>" class="btn btn-outline-secondary">Batal</a>
      <button class="btn btn-sipadi" id="butangSimpan"><i class="bi bi-save" aria-hidden="true"></i> <?= $id ? 'Simpan Perubahan' : 'Simpan Rekod' ?></button>
    </div>
  </div>
</form>
<?php if (!$id): ?>
<!-- Perjanjian Fasa 3: pengesahan sebelum Perjanjian Sambungan disimpan (Q3) -->
<div class="modal fade" id="modalSambungan" tabindex="-1" aria-labelledby="modalSambunganTajuk" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h6" id="modalSambunganTajuk"><i class="bi bi-link-45deg" aria-hidden="true"></i> Sahkan Perjanjian Sambungan</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
    <div class="modal-body">
      <p class="mb-2">Perjanjian baharu akan direkodkan sebagai <span class="st-badge st-hijau">AKTIF</span>.</p>
      <div id="msPendahulu"><p class="mb-1">Perjanjian terdahulu yang dipilih akan ditukar kepada status <span class="st-badge st-kuning">SAMBUNG</span> dan pautan sambungan akan disimpan:</p>
        <ul id="msSenarai" class="small mb-2"></ul></div>
      <div id="msTiada" hidden><p class="mb-2">Tiada perjanjian terdahulu dipautkan. Rekod akan ditanda <b><?= e(LABEL_TIADA_REKOD) ?></b>.</p></div>
      <div id="msAmaran" class="alert alert-warning small py-2 mb-2" hidden></div>
      <p class="small text-muted mb-0">Status dan pautan ini tidak boleh diundur melalui borang.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" id="msBatal">Batal</button>
      <button type="button" class="btn btn-sipadi btn-sm" id="msSahkan"><i class="bi bi-check2-circle" aria-hidden="true"></i> Sahkan &amp; Simpan</button></div>
  </div></div>
</div>
<script type="application/json" id="dataPendahulu"><?= json_encode(['dipilih' => $pendahuluPilihan, 'dihantar' => $_SERVER['REQUEST_METHOD'] === 'POST'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<?php
$extraScripts = <<<'JS'
<script>
const $ = id => document.getElementById(id);
// A2: medan Kadar/Sewaan hanya untuk SP & EP. Bagi model lain: dikosongkan dan dinyahaktif (tidak dihantar).
function toggleSP() {
  const sp = $('model').value === 'SP' || $('model').value === 'EP';
  document.querySelectorAll('.sp-only').forEach(el => {
    el.style.display = sp ? '' : 'none';
    el.querySelectorAll('input, button').forEach(i => {
      i.disabled = !sp;
      if (!sp && i.tagName === 'INPUT') i.value = '';
    });
  });
}
$('model').addEventListener('change', toggleSP); toggleSP();
$('kiraSewa').addEventListener('click', () => {
  const v = (+$('luas').value || 0) * (+$('kadar').value || 0) * (parseInt($('bilMusim').value) || 1);
  $('sewaan').value = v.toFixed(2);
});
$('kadarMo').addEventListener('change', e => {
  if (e.target.value) $('mo').value = ((+$('luas').value || 0) * +e.target.value).toFixed(2);
  e.target.value = '';
});

// Perjanjian Fasa 3: jenis perjanjian, carian & pilihan pendahulu, pengesahan (rekod baharu sahaja).
// Paparan sahaja — pelayan (simpan_rekod_baharu) kekal sebagai pengesahan muktamad.
// Didaftar SEBELUM pengendali "elak hantar berganda" supaya Batal dalam dialog tidak mengunci butang Simpan.
(function () {
  const borang = $('borangRekod'), panel = $('panelPendahulu');
  if (!panel) return;
  const ppkBorang = $('ppk_id'), cpPpk = $('cpPpk'), cpProjek = $('cpProjek'), cpBlok = $('cpBlok'), cpModel = $('cpModel'), cpButang = $('cpButang');
  const hasil = $('cpHasil'), hasilBalut = $('cpHasilBalut'), cpStatus = $('cpStatus');
  const senarai = $('senaraiDipilih'), kosong = $('kosongDipilih'), bil = $('bilDipilih'), tiada = $('tiadaRekod'), ralat = $('ralatPendahulu');
  const dipilih = new Map(), cache = new Map();
  let ppkCariDiubah = false, disahkan = false, dalamAutoisi = false;
  const LENCANA = { AKTIF: ['Aktif', 'st-hijau'], SAMBUNG: ['Disambung', 'st-kuning'], TAMAT: ['Tamat', 'st-kelabu'] };
  const el = (tag, cls, teks) => { const n = document.createElement(tag); if (cls) n.className = cls; if (teks != null) n.textContent = teks; return n; };
  const lencana = s => { const [l, c] = LENCANA[s] || [s, 'st-merah']; return el('span', 'st-badge ' + c, l); };
  const ha = v => Number(v).toLocaleString('en-MY', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  const jenis = () => (borang.querySelector('input[name="jenis"]:checked') || {}).value || 'ASAL';
  const amaran = r => {
    const a = [];
    if (r.status === 'SAMBUNG') a.push('Perjanjian ini telah pun disambung sebelum ini.');
    if (ppkBorang.value && String(r.ppk_id) !== ppkBorang.value) a.push('Perjanjian ini daripada PPK lain (' + r.ppk + ').');
    return a;
  };
  const ringkas = r => '#' + r.id + ' · ' + (r.projek || '-') + ' · ' + r.ppk + ' · ' + (r.no_blok || '-') + ' · ' + r.model + ' · musim ' + r.musim
    + (r.musim_tamat ? ' hingga ' + r.musim_tamat : '') + ' · ' + r.bil_peladang + ' peladang · ' + ha(r.luas_ha) + ' ha';

  function butang(b, r, kunci) {
    if (!r.boleh_pilih) { b.disabled = true; b.textContent = 'Tidak boleh'; b.title = r.sebab; return; }
    if (dipilih.has(r.id)) { b.disabled = true; b.textContent = 'Dipilih'; b.title = 'Sudah dipilih'; return; }
    b.disabled = kunci; b.textContent = 'Pilih'; b.title = kunci ? 'Tidak boleh dipilih bersama ' + tiada.labels[0].querySelector('b').textContent : 'Pilih sebagai perjanjian terdahulu';
  }
  // Autoisi (reka bentuk diluluskan): PPK, Projek, No Blok, Model, Kump, Kadar diisi daripada perjanjian terdahulu yang dipilih
  // HANYA jika nilainya sama bagi semua pendahulu. Nilai bercanggah → medan dikosongkan + nota. Peladang & Luas TIDAK disalin
  // (dipaparkan sebagai rujukan). Medan yang diisi sendiri oleh pengguna tidak ditimpa: medan diurus autoisi hanya jika
  // kosong atau masih mengandungi nilai autoisi terakhir. Paparan sahaja — tiada kesan pada logik pautan backend.
  const rm2 = v => 'RM ' + Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const MEDAN_AUTO = [
    ['ppk_id', r => String(r.ppk_id), r => r.ppk], ['projek', r => r.projek], ['no_blok', r => r.no_blok], ['model', r => r.model],
    ['kump', r => r.kump], ['kadar', r => Number(r.kadar_sewa) ? String(Number(r.kadar_sewa)) : '', r => Number(r.kadar_sewa) ? rm2(r.kadar_sewa) : ''],
  ];
  const IKON_NOTA = { auto: 'bi-magic', konflik: 'bi-exclamation-triangle', beza: 'bi-info-circle', rujukan: 'bi-clock-history' };
  function nota(medan, jenisNota, teks) {
    const col = medan.closest('[class*="col-"]');
    let n = col.querySelector('.op-autoisi-nota');
    if (!jenisNota) { if (n) n.remove(); return; }
    if (!n) { n = el('div'); n.id = medan.id + '-autoisi'; col.append(n); }
    n.className = 'op-autoisi-nota op-autoisi-' + jenisNota;
    n.dataset.jenis = jenisNota;
    n.replaceChildren(el('i', 'bi ' + IKON_NOTA[jenisNota]), document.createTextNode(' ' + teks));
  }
  const tetap = (f, v) => { if (f.value === v) return; f.value = v; f.dispatchEvent(new Event('change', { bubbles: true })); };
  // Tulis nilai autoisi; nilai sebelum autoisi pertama disimpan supaya boleh dipulihkan (cth Model lalai borang)
  const tulisAuto = (f, v) => { if (f.dataset.sebelumAuto === undefined) f.dataset.sebelumAuto = f.value; tetap(f, v); f.dataset.autoNilai = v; };
  function autoisi(s) {
    const rekod = [...dipilih.values()];
    dalamAutoisi = true;
    try {
      MEDAN_AUTO.forEach(([id, nilai, label = nilai]) => {
        const f = $(id);
        // Diurus autoisi jika: belum diubah pengguna, kosong, atau masih nilai autoisi terakhir
        const urus = !f.dataset.pengguna || f.value === '' || f.value === f.dataset.autoNilai;
        if (!s) { nota(f, null); return; }
        if (!rekod.length) {                                         // semua pendahulu dibuang: pulihkan nilai sebelum autoisi
          if (urus && f.dataset.autoNilai !== undefined) tetap(f, f.dataset.sebelumAuto ?? '');
          delete f.dataset.autoNilai; delete f.dataset.sebelumAuto; nota(f, null); return;
        }
        if (f.disabled) { nota(f, null); return; }                   // cth Kadar bagi model bukan SP/EP
        const nilaiUnik = [...new Set(rekod.map(nilai))];
        if (nilaiUnik.length > 1) {
          if (urus) tulisAuto(f, '');
          const senaraiNilai = [...new Set(rekod.map(r => label(r) || '(kosong)'))].join(' / ');
          nota(f, 'konflik', 'Perjanjian terdahulu yang dipilih mempunyai nilai berbeza: ' + senaraiNilai + '. Sila isi sendiri.');
          return;
        }
        const v = nilaiUnik[0];
        if (urus) { tulisAuto(f, v); nota(f, v === '' ? null : 'auto', 'Diisi daripada perjanjian terdahulu.'); }
        else if (f.value !== v) nota(f, 'beza', 'Nilai perjanjian terdahulu: ' + (label(rekod[0]) || '(kosong)') + '.');
        else nota(f, null);
      });
      [['bil', r => r.bil_peladang, v => String(v), ' peladang'], ['luas', r => r.luas_ha, ha, ' ha']].forEach(([id, ambil, fmt, unit]) => {
        const f = $(id);
        if (!s || !rekod.length) { nota(f, null); return; }
        const jumlah = rekod.reduce((a, r) => a + Number(ambil(r)), 0);
        nota(f, 'rujukan', 'Perjanjian terdahulu: ' + (rekod.length === 1 ? fmt(ambil(rekod[0]))
          : rekod.map(r => '#' + r.id + ' ' + fmt(ambil(r))).join(' + ') + ' = ' + fmt(jumlah)) + unit + ' (rujukan sahaja, tidak disalin).');
      });
    } finally { dalamAutoisi = false; }
    if (!ppkCariDiubah) cpPpk.value = ppkBorang.value;
  }

  function kemas() {
    const s = jenis() === 'SAMBUNG';
    autoisi(s);
    senarai.replaceChildren();
    dipilih.forEach(r => {
      const li = el('li'), teks = el('div', 'op-dipilih-teks', ringkas(r) + ' ');
      teks.append(lencana(r.status));
      li.append(teks);
      amaran(r).forEach(a => { const d = el('div', 'op-dipilih-amaran'); d.append(el('i', 'bi bi-exclamation-triangle'), document.createTextNode(' ' + a)); li.append(d); });
      const inp = el('input'); inp.type = 'hidden'; inp.name = 'pendahulu_id[]'; inp.value = r.id; inp.disabled = !s;
      const b = el('button', 'btn op-btn-ikon op-btn-padam'); b.type = 'button'; b.title = 'Buang pilihan'; b.disabled = !s;
      b.setAttribute('aria-label', 'Buang perjanjian terdahulu #' + r.id); b.append(el('i', 'bi bi-x-lg'));
      b.addEventListener('click', () => { dipilih.delete(r.id); kemas(); });
      li.append(inp, b);
      senarai.append(li);
    });
    bil.textContent = dipilih.size;
    kosong.hidden = dipilih.size > 0;
    if (dipilih.size) tiada.checked = false;                       // eksklusif bersama (O-5)
    tiada.disabled = !s || dipilih.size > 0;
    const kunci = !s || tiada.checked;
    [cpPpk, cpProjek, cpBlok, cpModel, cpButang].forEach(i => i.disabled = kunci);
    hasil.querySelectorAll('button[data-id]').forEach(b => butang(b, cache.get(+b.dataset.id), kunci));
    if (dipilih.size || tiada.checked) ralat.hidden = true;
  }
  function mod() {
    const s = jenis() === 'SAMBUNG';
    document.querySelectorAll('[data-mod]').forEach(blok => {
      const aktif = blok.dataset.mod === (s ? 'SAMBUNG' : 'ASAL');
      blok.hidden = !aktif;
      blok.querySelectorAll('input, select, textarea, button').forEach(i => i.disabled = !aktif);
    });
    kemas();
  }
  function paparHasil(rekod, lebih) {
    hasil.replaceChildren();
    rekod.forEach(r => {
      cache.set(r.id, r);
      const tr = el('tr', r.boleh_pilih ? '' : 'op-baris-dilumpuhkan');
      [[r.ppk, 'text-start'], [r.projek || '-', 'text-start'], [r.no_blok || '-', 'text-start'], [r.model], [r.musim], [r.musim_tamat || '-'],
       [String(r.bil_peladang), 'num'], [ha(r.luas_ha), 'num']].forEach(([t, c]) => tr.append(el('td', c || '', t)));
      const tdS = el('td'); tdS.append(lencana(r.status)); tr.append(tdS);
      const tdB = el('td'), b = el('button', 'btn btn-outline-primary btn-sm py-0'); b.type = 'button'; b.dataset.id = r.id;
      b.addEventListener('click', () => {
        if (dipilih.has(r.id) || !r.boleh_pilih || tiada.checked) return;   // elak pendua / TAMAT
        dipilih.set(r.id, r); kemas();
      });
      tdB.append(b); tr.append(tdB); hasil.append(tr);
    });
    hasilBalut.hidden = !rekod.length;
    const lain = cpPpk.value !== ppkBorang.value ? (cpPpk.value ? ' Carian di PPK lain daripada borang.' : ' Carian semua PPK.') : '';
    cpStatus.textContent = (rekod.length ? rekod.length + ' rekod ditemui' + (lebih ? ' (50 pertama; perincikan carian)' : '') + '.' : 'Tiada rekod sepadan.') + lain;
    kemas();
  }
  async function cari() {
    const qs = new URLSearchParams({ ppk: cpPpk.value, projek: cpProjek.value.trim(), no_blok: cpBlok.value.trim(), model: cpModel.value });
    cpStatus.textContent = 'Mencari…';
    cpButang.disabled = true;
    try {
      const res = await fetch('penyaluran_cari.php?' + qs, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await res.json();
      if (!j.ok) throw new Error(j.ralat || 'ralat tidak diketahui');
      paparHasil(j.rekod, j.lebih);
    } catch (err) {
      cpStatus.textContent = 'Carian gagal (' + err.message + '). Muat semula halaman jika sesi telah tamat.';
      hasilBalut.hidden = true;
    } finally { kemas(); }
  }

  const dataAwal = JSON.parse($('dataPendahulu').textContent || '{}');
  (dataAwal.dipilih || []).forEach(r => dipilih.set(r.id, r));
  // Medan yang diubah pengguna tidak ditimpa autoisi. Borang dipaparkan semula selepas hantar: nilai dihantar = nilai pengguna.
  MEDAN_AUTO.forEach(([id]) => {
    const f = $(id);
    if (dataAwal.dihantar && f.value !== '') f.dataset.pengguna = '1';
    ['input', 'change'].forEach(ev => f.addEventListener(ev, () => { if (!dalamAutoisi) f.dataset.pengguna = '1'; }));
  });
  cpPpk.value = ppkBorang.value;
  borang.querySelectorAll('input[name="jenis"]').forEach(r => r.addEventListener('change', mod));
  ppkBorang.addEventListener('change', () => { if (dalamAutoisi) return; if (!ppkCariDiubah) cpPpk.value = ppkBorang.value; kemas(); });
  // Medan autoisi yang ditukar sendiri oleh pengguna: kemas kini nota (perbezaan dengan perjanjian terdahulu)
  MEDAN_AUTO.forEach(([id]) => $(id).addEventListener('change', () => { if (!dalamAutoisi && id !== 'ppk_id') kemas(); }));
  cpPpk.addEventListener('change', () => { ppkCariDiubah = true; });
  cpButang.addEventListener('click', cari);
  [cpProjek, cpBlok].forEach(i => i.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); cari(); } }));
  tiada.addEventListener('change', kemas);
  mod();

  // Pengesahan sebelum simpan (Perjanjian Sambungan sahaja)
  const modalEl = $('modalSambungan'), modal = bootstrap.Modal.getOrCreateInstance(modalEl), sah = $('sahkanSambungan');
  borang.addEventListener('submit', e => {
    if (jenis() !== 'SAMBUNG' || disahkan) return;
    e.preventDefault(); e.stopImmediatePropagation();
    if (!dipilih.size && !tiada.checked) {
      ralat.textContent = 'Pilih sekurang-kurangnya satu perjanjian terdahulu, atau tandakan ' + tiada.labels[0].querySelector('b').textContent + '.';
      ralat.hidden = false; panel.scrollIntoView({ block: 'center' }); (cpProjek.disabled ? tiada : cpProjek).focus();
      return;
    }
    $('msPendahulu').hidden = !dipilih.size; $('msTiada').hidden = !tiada.checked;
    $('msSenarai').replaceChildren(...[...dipilih.values()].map(r => { const li = el('li', null, ringkas(r) + ' → '); li.append(el('span', 'st-badge st-kuning', 'SAMBUNG')); return li; }));
    const semua = [...dipilih.values()].flatMap(r => amaran(r).map(a => '#' + r.id + ': ' + a));
    $('msAmaran').hidden = !semua.length; $('msAmaran').textContent = semua.join(' ');
    modal.show();
  });
  modalEl.addEventListener('shown.bs.modal', () => $('msSahkan').focus());
  modalEl.addEventListener('hidden.bs.modal', () => { if (!disahkan) sah.value = '0'; });
  $('msSahkan').addEventListener('click', () => {
    disahkan = true; sah.value = '1'; modal.hide();
    if (borang.requestSubmit) borang.requestSubmit(); else borang.submit();
  });
})();

// UI-04 (paparan sahaja): ringkasan jumlah, elak hantar berganda, fokus pada ralat
(function () {
  const fmt = v => 'RM ' + Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function kemasJumlah() {
    const s = $('sewaan').disabled ? 0 : (+$('sewaan').value || 0), m = +$('mo').value || 0;
    $('jumlahRekod').textContent = fmt(s + m);
    $('jumlahPecahan').textContent = 'Sewaan ' + fmt(s) + ' + Modal ' + fmt(m);
  }
  ['sewaan', 'mo', 'model', 'kadarMo'].forEach(id => $(id).addEventListener('input', kemasJumlah));
  ['model', 'kadarMo'].forEach(id => $(id).addEventListener('change', kemasJumlah));
  $('kiraSewa').addEventListener('click', kemasJumlah);
  kemasJumlah();

  const borang = $('borangRekod');
  borang.addEventListener('submit', e => {
    const b = $('butangSimpan');
    if (borang.dataset.dihantar) { e.preventDefault(); return; }
    borang.dataset.dihantar = '1';
    b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Menyimpan…';
  });
  window.addEventListener('pageshow', ev => { if (ev.persisted) { delete borang.dataset.dihantar; $('butangSimpan').disabled = false; } });

  const ralat = borang.querySelector('.is-invalid');
  if (ralat) ralat.focus(); else if ($('ringkasanRalat')) $('ringkasanRalat').focus(); else if (!borang.querySelector('[name=ppk_id]').value) $('ppk_id').focus();
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
