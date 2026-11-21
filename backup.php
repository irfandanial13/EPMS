<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/backup_lib.php';
require_login();

$me = current_user();
$hasil = null;   // keputusan restore untuk dipaparkan

/* ---------- Muat turun (log masuk wajib; fail tidak boleh diakses terus) ---------- */
if (isset($_GET['muat'])) {
    $fail = (string)$_GET['muat'];
    if (!backup_nama_sah($fail) || !is_file($p = backup_path($fail))) {
        http_response_code(404);
        exit('Fail backup tidak dijumpai.');
    }
    app_log("Backup dimuat turun: $fail", 'backup.log');
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $fail . '"');
    header('Content-Length: ' . filesize($p));
    header('X-Content-Type-Options: nosniff');
    readfile($p);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'buat') {
        try {
            $m = buat_backup('manual', $me['username'], trim($_POST['catatan'] ?? ''));
            catat_status_backup('manual', true, '', $m['fail']);
            flash("Backup berjaya dicipta: {$m['fail']}");
        } catch (Throwable $e) {
            app_log('Backup gagal: ' . $e->getMessage(), 'error.log');
            catat_status_backup('manual', false, $e->getMessage());
            flash('Backup gagal. Semak log storage/logs/error.log untuk butiran.', 'danger');
        }
        redirect('backup.php');
    }

    if ($aksi === 'pulih') {
        $fail = (string)($_POST['fail'] ?? '');
        $st = db()->prepare('SELECT password_hash FROM pengguna WHERE id = ?');
        $st->execute([$me['id']]);
        if (!backup_nama_sah($fail)) {
            flash('Fail backup tidak sah.', 'danger');
            redirect('backup.php');
        } elseif (($_POST['pengesahan'] ?? '') !== 'PULIHKAN') {
            flash('Sila taip PULIHKAN untuk mengesahkan restore.', 'danger');
            redirect('backup.php?pulih=' . urlencode($fail));
        } elseif (!password_verify($_POST['katalaluan'] ?? '', (string)$st->fetchColumn())) {
            app_log("Restore ditolak (kata laluan salah): $fail", 'backup.log');
            flash('Kata laluan tidak betul.', 'danger');
            redirect('backup.php?pulih=' . urlencode($fail));
        }
        try {
            $hasil = pulih_backup($fail, $me['username']);
        } catch (Throwable $e) {
            app_log('Restore gagal: ' . $e->getMessage(), 'error.log');
            $hasil = ['ok' => false, 'fail' => $fail, 'ralat' => $e->getMessage(), 'semakan' => []];
        }
        // Akaun semasa mungkin tiada / berbeza dalam backup yang dipulihkan
        $st = db()->prepare('SELECT id, username, nama, wajib_tukar FROM pengguna WHERE id = ? AND username = ?');
        $st->execute([$me['id'], $me['username']]);
        if ($u = $st->fetch()) {
            $_SESSION['user']['wajib_tukar'] = (int)$u['wajib_tukar'];
        } else {
            tamatkan_sesi();
            session_start();
            flash('Pangkalan data telah dipulihkan. Akaun anda tiada dalam backup tersebut — sila log masuk dengan akaun yang wujud dalam backup.', 'warning');
            redirect('login.php');
        }
    }
}

$list = senarai_backup();
$status = status_backup($list);
$umur = function (string $t): string {
    $s = max(0, time() - strtotime($t));
    return $s < 3600 ? floor($s / 60) . ' minit lalu' : ($s < 172800 ? floor($s / 3600) . ' jam lalu' : floor($s / 86400) . ' hari lalu');
};
$pilihPulih = null;
if (isset($_GET['pulih']) && backup_nama_sah((string)$_GET['pulih'])) {
    foreach ($list as $b) if ($b['fail'] === $_GET['pulih']) $pilihPulih = $b;
}
$saiz = fn($b) => $b >= 1048576 ? number_format($b / 1048576, 2) . ' MB' : number_format($b / 1024, 1) . ' KB';

$pageTitle = 'Backup & Restore';
require __DIR__ . '/includes/header.php';
?>
<div class="op-kepala">
  <div>
    <h1 class="page-title">Backup &amp; Restore</h1>
    <p class="op-sub">Salinan penuh pangkalan data, semakan integriti, muat turun dan pemulihan.</p>
  </div>
  <div class="op-tindakan no-print">
    <a class="btn btn-sipadi btn-sm" href="#borang-backup"><i class="bi bi-database-down" aria-hidden="true"></i> Backup Sekarang</a>
  </div>
</div>

<?php foreach ($status['amaran'] as [$jenisAmaran, $mesejAmaran]): ?>
<div class="alert alert-<?= $jenisAmaran ?> py-2 small mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e($mesejAmaran) ?></div>
<?php endforeach; ?>
<div class="row g-2 mb-3">
  <?php $t = $status['terkini']; $sah = $t ? semak_fail_backup($t['fail'])['ok'] : false; ?>
  <div class="col-md-4"><div class="card kpi p-2 h-100">
    <div class="label">Backup terakhir</div>
    <?php if ($t): ?>
      <div class="value fs-6"><?= e(date('d/m/Y H:i', strtotime($t['dicipta']))) ?></div>
      <div class="sub"><?= e($umur($t['dicipta'])) ?> · <?= e(BACKUP_JENIS[$t['jenis']] ?? $t['jenis']) ?> ·
        <?= $sah ? '<span class="st-badge st-hijau">Sah</span>' : '<span class="st-badge st-merah">Rosak</span>' ?></div>
    <?php else: ?><div class="value fs-6 text-danger">Tiada</div><?php endif; ?>
  </div></div>
  <?php $j = $status['jadual']; ?>
  <div class="col-md-4"><div class="card kpi p-2 h-100">
    <div class="label">Backup berjadual terakhir</div>
    <?php if ($j): ?>
      <div class="value fs-6"><?= e(date('d/m/Y H:i', strtotime($j['dicipta']))) ?></div>
      <div class="sub"><?= e($umur($j['dicipta'])) ?></div>
    <?php else: ?><div class="value fs-6 text-muted">Belum pernah</div><?php endif; ?>
  </div></div>
  <?php $c = $status['cubaan']; ?>
  <div class="col-md-4"><div class="card kpi p-2 h-100">
    <div class="label">Status cubaan terakhir</div>
    <?php if ($c): ?>
      <div class="value fs-6"><?= $c['ok'] ? '<span class="text-success">Berjaya</span>' : '<span class="text-danger">Gagal</span>' ?></div>
      <div class="sub"><?= e(date('d/m/Y H:i', strtotime($c['masa']))) ?> · <?= e(BACKUP_JENIS[$c['jenis']] ?? $c['jenis']) ?></div>
    <?php else: ?><div class="value fs-6 text-muted">Belum direkod</div><?php endif; ?>
  </div></div>
</div>

<?php if ($hasil): ?>
<div class="card mb-3 border-<?= $hasil['ok'] ? 'success' : 'danger' ?>  card-tegas">
  <div class="card-body">
    <h2 class="h6 <?= $hasil['ok'] ? 'text-success' : 'text-danger' ?>">
      <i class="bi bi-<?= $hasil['ok'] ? 'check-circle' : 'x-octagon' ?>"></i>
      Restore <?= $hasil['ok'] ? 'berjaya — semakan integriti LULUS' : (isset($hasil['ralat']) ? 'GAGAL' : 'selesai — semakan integriti GAGAL') ?>
    </h2>
    <div class="small mb-2">Fail: <code><?= e($hasil['fail']) ?></code>
      <?php if (!empty($hasil['pra_pulih'])): ?> · Backup keadaan sebelum restore: <code><?= e($hasil['pra_pulih']) ?></code><?php endif; ?></div>
    <?php if (isset($hasil['ralat'])): ?><div class="alert alert-danger small mb-0"><?= e($hasil['ralat']) ?></div><?php endif; ?>
    <?php if ($hasil['semakan']): ?>
    <table class="table table-sm table-data mb-2 table-ringkas">
      <thead><tr><th class="text-start">Jadual</th><th class="num">Dijangka</th><th class="num">Selepas restore</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($hasil['semakan'] as $t => $s): ?>
        <tr><td><?= e($t) ?></td><td class="num"><?= nint($s['jangka']) ?></td><td class="num"><?= $s['sebenar'] === null ? '-' : nint($s['sebenar']) ?></td>
          <td><?= $s['ok'] ? '<span class="st-badge st-hijau">Sepadan</span>' : '<span class="st-badge st-merah">Tidak sepadan</span>' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <?php if (!empty($hasil['migrasi'])): ?><div class="small text-muted">Migrasi skema dijalankan: <?= e(implode('; ', $hasil['migrasi'])) ?></div><?php endif; ?>
    <?php if (!empty($hasil['wajib_tukar'])): ?><div class="small text-muted">Akaun ditanda wajib tukar kata laluan: <?= e(implode(', ', $hasil['wajib_tukar'])) ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($pilihPulih): ?>
<div class="card mb-3 border-danger  card-tegas">
  <div class="card-body">
    <h2 class="h6 text-danger"><i class="bi bi-exclamation-triangle"></i> Sahkan restore</h2>
    <p class="small mb-2">Anda akan menggantikan <b>keseluruhan</b> pangkalan data semasa dengan backup
      <code><?= e($pilihPulih['fail']) ?></code> bertarikh <b><?= e($pilihPulih['dicipta']) ?></b>.
      Semua perubahan selepas tarikh itu akan hilang. Backup keadaan semasa akan dibuat secara automatik dahulu.</p>
    <?php if ($pilihPulih['jadual']): ?>
      <p class="small mb-2">Kandungan backup: <?php foreach ($pilihPulih['jadual'] as $t => $n) echo '<span class="st-badge st-kelabu me-1">' . e($t) . ': ' . nint($n) . '</span>'; ?></p>
    <?php endif; ?>
    <form method="post" class="row g-2 align-items-end" autocomplete="off" data-validasi aria-label="Sahkan restore">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="pulih"><input type="hidden" name="fail" value="<?= e($pilihPulih['fail']) ?>">
      <div class="col-sm-4"><label class="form-label small mb-1" for="plPengesahan">Taip <b>PULIHKAN</b></label>
        <input name="pengesahan" id="plPengesahan" class="form-control form-control-sm" required pattern="PULIHKAN" title="Taip PULIHKAN (huruf besar) untuk mengesahkan."></div>
      <div class="col-sm-4"><label class="form-label small mb-1" for="plKatalaluan">Kata laluan anda</label>
        <input name="katalaluan" id="plKatalaluan" type="password" class="form-control form-control-sm" required autocomplete="current-password"></div>
      <div class="col-sm-4 d-flex gap-2">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Teruskan restore? Tindakan ini menggantikan semua data semasa.')">Pulihkan</button>
        <a href="backup.php" class="btn btn-outline-secondary btn-sm">Batal</a></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <form method="post" class="card op-borang" id="borang-backup" aria-label="Buat backup sekarang">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="buat">
      <div class="op-seksyen op-seksyen-tunggal">
        <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-database-down" aria-hidden="true"></i></span>
          <div><h2>Buat backup sekarang</h2><p>Salinan penuh pangkalan data (semua jadual) disimpan dalam pelayan.</p></div></div>
        <div><label class="form-label" for="bkCatatan">Catatan <span class="text-muted fw-normal">(pilihan)</span></label>
          <input name="catatan" id="bkCatatan" class="form-control" maxlength="200"></div>
      </div>
      <div class="op-bar-tindakan"><span class="op-bar-nota">Mengambil beberapa saat.</span>
        <button class="btn btn-sipadi"><i class="bi bi-database-down" aria-hidden="true"></i> Backup Sekarang</button></div>
    </form>
    <div class="card card-body mt-3 small text-muted">
      <b class="text-body">Nota</b>
      <ul class="mb-0 ps-3">
        <li>Fail backup disimpan dalam <code>storage/backups</code> dan tidak boleh dicapai terus melalui pelayar.</li>
        <li>Backup berjadual: setiap hari 20:00 (Task Scheduler). Backup berjadual disimpan <?= (int)BACKUP_SIMPAN_JADUAL ?> hari; backup manual tidak dipadam secara automatik.</li>
        <li>Simpan salinan backup penting di lokasi lain (cakera luaran / pelayan lain).</li>
      </ul>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-wrap table-scroll">
      <table class="table table-sm table-hover table-data mb-0">
        <thead><tr><th class="text-start">Tarikh &amp; Masa</th><th class="text-start">Jenis</th><th class="text-start">Oleh</th>
          <th class="num">Saiz</th><th class="num">Rekod Penyaluran</th><th>Integriti</th><th><span class="visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">Tiada backup lagi.</td></tr><?php endif; ?>
        <?php foreach ($list as $b): $s = semak_fail_backup($b['fail']); ?>
          <tr>
            <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', strtotime($b['dicipta']))) ?>
              <?php if (!empty($b['catatan'])): ?><div class="text-muted text-xs"><?= e($b['catatan']) ?></div><?php endif; ?></td>
            <td><?= e(BACKUP_JENIS[$b['jenis']] ?? $b['jenis']) ?></td>
            <td><?= e($b['oleh']) ?></td>
            <td class="num"><?= $saiz($b['saiz']) ?></td>
            <td class="num"><?= isset($b['jadual']['penyaluran']) ? nint($b['jadual']['penyaluran']) : '-' ?></td>
            <td><?= $s['ok'] ? '<span class="st-badge st-hijau">Sah</span>' : '<span class="st-badge st-merah" title="' . e($s['mesej']) . '">Rosak</span>' ?></td>
            <td class="text-nowrap">
              <div class="op-tindakan-baris">
              <a class="btn op-btn-ikon op-btn-edit" href="?muat=<?= e(urlencode($b['fail'])) ?>" title="Muat turun" aria-label="Muat turun <?= e($b['fail']) ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
              <?php if ($s['ok']): ?><a class="btn op-btn-ikon op-btn-padam" href="?pulih=<?= e(urlencode($b['fail'])) ?>" title="Pulihkan" aria-label="Pulihkan <?= e($b['fail']) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a><?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
