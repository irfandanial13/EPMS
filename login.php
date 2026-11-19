<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('index.php');

/** Bilangan cubaan gagal dalam tempoh kunci (selepas log masuk berjaya terakhir). */
function bil_gagal(string $medan, string $nilai): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM login_attempts
        WHERE $medan = ? AND berjaya = 0 AND created_at > NOW() - INTERVAL ? MINUTE
          AND created_at > COALESCE((SELECT MAX(created_at) FROM login_attempts WHERE $medan = ? AND berjaya = 1), '1970-01-01')");
    $st->execute([$nilai, LOGIN_KUNCI_MINIT, $nilai]);
    return (int)$st->fetchColumn();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = mb_substr(trim($_POST['username'] ?? ''), 0, 50);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';

    // Had cubaan: ikut nama pengguna, dan ikut IP (had lebih longgar untuk rangkaian dikongsi)
    if (bil_gagal('username', $username) >= LOGIN_MAKS_GAGAL || bil_gagal('ip', $ip) >= LOGIN_MAKS_GAGAL * 4) {
        app_log("Log masuk disekat (terlalu banyak cubaan) untuk '$username'", 'auth.log');
        $error = 'Terlalu banyak cubaan gagal. Sila cuba lagi selepas ' . LOGIN_KUNCI_MINIT . ' minit.';
    } else {
        $st = db()->prepare('SELECT * FROM pengguna WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        // Hash palsu supaya masa semakan sama walaupun pengguna tiada
        $hash = $u['password_hash'] ?? '$2y$10$IIlVQ3CSAQb0DUhk54T5xOwAO1t.0NYM5/jbuM./M/qB7RLc96XZW';
        $ok = password_verify($_POST['password'] ?? '', $hash) && $u;

        db()->prepare('INSERT INTO login_attempts (username, ip, berjaya) VALUES (?, ?, ?)')->execute([$username, $ip, $ok ? 1 : 0]);
        if (random_int(1, 50) === 1) db()->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 90 DAY');

        if ($ok) {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE pengguna SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $u['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'nama' => $u['nama'],
                                 'wajib_tukar' => (int)$u['wajib_tukar']];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['csrf']);
            app_log('Log masuk berjaya', 'auth.log');
            redirect($u['wajib_tukar'] ? 'tukar_katalaluan.php' : 'index.php');
        }
        app_log("Log masuk gagal untuk '$username'", 'auth.log');
        $baki = LOGIN_MAKS_GAGAL - bil_gagal('username', $username);
        $error = 'Nama pengguna atau kata laluan tidak sah.'
            . ($baki > 0 && $baki <= 2 ? " Baki cubaan sebelum dikunci: $baki." : '');
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log Masuk · <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>
<div class="login-wrap px-3">
  <div class="card p-4 auth-card">
    <div class="text-center mb-4">
      <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME . ' – ' . APP_TITLE) ?>" class="app-logo-login mb-3">
      <h1 class="visually-hidden"><?= e(APP_NAME . ' – ' . APP_TITLE) ?></h1>
      <div class="text-muted small">Log masuk untuk meneruskan</div>
    </div>
    <?php foreach (flashes() as [$t, $m]): ?><div class="alert alert-<?= e($t) ?> py-2 small"><?= e($m) ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Nama Pengguna</label>
        <input name="username" class="form-control" required autofocus maxlength="50" value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Kata Laluan</label>
        <input name="password" type="password" class="form-control" required autocomplete="current-password">
      </div>
      <button class="btn btn-sipadi w-100">Log Masuk</button>
    </form>
  </div>
</div>
</body>
</html>
