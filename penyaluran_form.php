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

if ($id) {
    $st = db()->prepare('SELECT * FROM penyaluran WHERE id = ?');
    $st->execute([$id]);
    $rec = $st->fetch() ?: redirect($urlKembali);
}
$asal = $rec;   // nilai tersimpan (rekod lama) — untuk membenarkan data lama yang tidak diubah

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Semasa edit, medan yang tiada dalam borang kekal dengan nilai asal (tidak dikosongkan)
    foreach ($fields as $k) {
        if (array_key_exists($k, $_POST) || !$id) $rec[$k] = trim((string)($_POST[$k] ?? ''));
        else $rec[$k] = (string)($rec[$k] ?? '');
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
    if (!in_array($rec['jenis'], ['ASAL', 'SAMBUNG'], true)) $errors[] = 'Jenis tidak sah.';
    if (!in_array($rec['status'], ['AKTIF', 'TAMAT'], true)) $errors[] = 'Status tidak sah.';

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

    if (!$errors) {
        $vals = array_map(fn($k) => $rec[$k] === '' ? null : $rec[$k], $fields);
        $vals[] = musim_susun($rec['musim']);
        if ($id) {
            $sql = 'UPDATE penyaluran SET ' . implode(', ', array_map(fn($k) => "$k = ?", $fields)) . ', musim_susun = ? WHERE id = ?';
            $vals[] = $id;
        } else {
            $sql = 'INSERT INTO penyaluran (' . implode(', ', $fields) . ', musim_susun) VALUES (' . rtrim(str_repeat('?, ', count($fields) + 1), ', ') . ')';
        }
        db()->prepare($sql)->execute($vals);
        flash($id ? 'Rekod telah dikemas kini.' : 'Rekod baharu telah ditambah.');
        redirect($urlKembali);
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
      <div class="col-md-6 col-xl-3">
        <label class="form-label" for="jenis">Jenis</label>
        <select name="jenis" id="jenis" class="form-select<?= $tak('jenis') ?>"<?= $aria('jenis') ?>><?= $opt('ASAL', $rec['jenis'], 'Asal') . $opt('SAMBUNG', $rec['jenis'], 'Sambung Perjanjian') ?></select><?= $fb('jenis') ?>
      </div>
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
      <div class="col-md-4 col-xl-3">
        <label class="form-label" for="status">Status</label>
        <select name="status" id="status" class="form-select<?= $tak('status') ?>"<?= $aria('status') ?>><?= $opt('AKTIF', $rec['status']) . $opt('TAMAT', $rec['status']) ?></select><?= $fb('status') ?>
      </div>
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
