<?php
/**
 * Tukar kata laluan wajib (contoh: akaun lalai atau akaun yang baru dicipta oleh admin lain).
 * Pengguna tidak boleh ke halaman lain sehingga kata laluan ditukar.
 */
require __DIR__ . '/includes/bootstrap.php';
require_login(true);

$me = current_user();
if (empty($me['wajib_tukar'])) redirect('pengguna.php');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT password_hash FROM pengguna WHERE id = ?');
    $st->execute([$me['id']]);
    $hash = (string)$st->fetchColumn();
    $baru = $_POST['baru'] ?? '';

    if (!password_verify($_POST['lama'] ?? '', $hash)) {
        $errors[] = 'Kata laluan semasa tidak betul.';
    } else {
        $errors = semak_katalaluan($baru, $_POST['sahkan'] ?? '', $me['username']);
        if (!$errors && password_verify($baru, $hash)) $errors[] = 'Kata laluan baharu mesti berbeza daripada kata laluan semasa.';
    }
    if (!$errors) {
        db()->prepare('UPDATE pengguna SET password_hash = ?, wajib_tukar = 0, katalaluan_ditukar = NOW() WHERE id = ?')
            ->execute([password_hash($baru, PASSWORD_DEFAULT), $me['id']]);
        $_SESSION['user']['wajib_tukar'] = 0;
        session_regenerate_id(true);
        app_log('Kata laluan ditukar (wajib)', 'auth.log');
        flash('Kata laluan telah ditukar. Selamat datang ke ' . APP_NAME . '.');
        redirect('index.php');
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tukar Kata Laluan · <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/app.css?v=<?= (int)@filemtime(__DIR__ . "/assets/app.css") ?>" rel="stylesheet">
</head>
<body>
<div class="login-wrap px-3">
  <div class="card p-4 auth-card">
    <div class="text-center mb-3">
      <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME . ' – ' . APP_TITLE) ?>" class="app-logo-login app-logo-kecil mb-3">
      <h1 class="h5 mb-1">Tukar Kata Laluan</h1>
      <div class="text-muted small">Akaun <b><?= e($me['username']) ?></b> perlu menukar kata laluan sebelum meneruskan.</div>
    </div>
    <?php if ($errors): ?><div class="alert alert-danger py-2 small"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label small">Kata laluan semasa</label>
        <input name="lama" type="password" class="form-control" required autocomplete="current-password"></div>
      <div class="mb-2"><label class="form-label small">Kata laluan baharu</label>
        <input name="baru" type="password" class="form-control" required minlength="8" autocomplete="new-password">
        <div class="form-text">Sekurang-kurangnya 8 aksara, mengandungi huruf dan nombor.</div></div>
      <div class="mb-3"><label class="form-label small">Sahkan kata laluan baharu</label>
        <input name="sahkan" type="password" class="form-control" required minlength="8" autocomplete="new-password"></div>
      <button class="btn btn-sipadi w-100 mb-2">Tukar Kata Laluan</button>
    </form>
    <form method="post" action="logout.php" class="text-center"><?= csrf_field() ?>
      <button class="btn btn-link btn-sm text-muted">Log keluar</button></form>
  </div>
</div>
</body>
</html>
