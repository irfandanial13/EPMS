<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$me = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'tambah') {
        $u = trim($_POST['username'] ?? '');
        $n = trim($_POST['nama'] ?? '');
        $pw = $_POST['password'] ?? '';
        $err = $u === '' || $n === '' ? ['Semua medan wajib diisi.'] : semak_katalaluan($pw, $pw, $u);
        if ($err) {
            flash(implode(' ', $err), 'danger');
        } else {
            try {
                // Kata laluan ditetapkan oleh admin lain, jadi pengguna baharu wajib menukarnya pada log masuk pertama
                db()->prepare('INSERT INTO pengguna (username, nama, password_hash, wajib_tukar) VALUES (?,?,?,1)')
                    ->execute([$u, $n, password_hash($pw, PASSWORD_DEFAULT)]);
                app_log("Pengguna ditambah: $u", 'auth.log');
                flash("Pengguna $u telah ditambah. Pengguna perlu menukar kata laluan pada log masuk pertama.");
            } catch (PDOException $e) {
                flash('Nama pengguna telah wujud.', 'danger');
            }
        }
    } elseif ($aksi === 'tukar') {
        $st = db()->prepare('SELECT password_hash FROM pengguna WHERE id = ?');
        $st->execute([$me['id']]);
        $hash = (string)$st->fetchColumn();
        $err = semak_katalaluan($_POST['baru'] ?? '', $_POST['sahkan'] ?? '', $me['username']);
        if (!password_verify($_POST['lama'] ?? '', $hash)) {
            flash('Kata laluan semasa tidak betul.', 'danger');
        } elseif ($err) {
            flash(implode(' ', $err), 'danger');
        } else {
            db()->prepare('UPDATE pengguna SET password_hash = ?, wajib_tukar = 0, katalaluan_ditukar = NOW() WHERE id = ?')
                ->execute([password_hash($_POST['baru'], PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            app_log('Kata laluan ditukar', 'auth.log');
            flash('Kata laluan telah ditukar.');
        }
    } elseif ($aksi === 'padam') {
        $id = (int)$_POST['id'];
        if ($id === (int)$me['id']) flash('Anda tidak boleh memadam akaun sendiri.', 'danger');
        else {
            db()->prepare('DELETE FROM pengguna WHERE id = ?')->execute([$id]);
            app_log("Pengguna dipadam: id $id", 'auth.log');
            flash('Pengguna telah dipadam.');
        }
    }
    redirect('pengguna.php');
}

$list = db()->query('SELECT id, username, nama, created_at FROM pengguna ORDER BY id')->fetchAll();
$pageTitle = 'Pengguna';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-3"><i class="bi bi-people"></i> Pengguna (Admin)</h1>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="table-wrap">
      <table class="table table-sm table-data mb-0">
        <thead><tr><th class="text-start">Nama Pengguna</th><th class="text-start">Nama</th><th class="text-start">Didaftar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $u): ?>
          <tr><td><?= e($u['username']) ?></td><td><?= e($u['nama']) ?></td><td><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td>
            <td><?php if ($u['id'] != $me['id']): ?>
              <form method="post" onsubmit="return confirm('Padam pengguna ini?')"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="padam"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form>
              <?php else: ?><span class="badge text-bg-success">Anda</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-5">
    <form method="post" class="card card-body mb-3"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
      <h2 class="h6">Tambah Admin</h2>
      <input name="username" class="form-control mb-2" placeholder="Nama pengguna" required>
      <input name="nama" class="form-control mb-2" placeholder="Nama penuh" required>
      <input name="password" type="password" class="form-control mb-1" placeholder="Kata laluan sementara" minlength="8" required autocomplete="new-password">
      <div class="form-text mb-3">Min 8 aksara, huruf dan nombor. Pengguna wajib menukarnya pada log masuk pertama.</div>
      <button class="btn btn-sipadi">Tambah</button>
    </form>
    <form method="post" class="card card-body" id="tukar-katalaluan"><?= csrf_field() ?><input type="hidden" name="aksi" value="tukar">
      <h2 class="h6">Tukar Kata Laluan Saya</h2>
      <input name="lama" type="password" class="form-control mb-2" placeholder="Kata laluan semasa" required>
      <input name="baru" type="password" class="form-control mb-1" placeholder="Kata laluan baharu" minlength="8" required autocomplete="new-password">
      <div class="form-text mb-2">Min 8 aksara, mengandungi huruf dan nombor.</div>
      <input name="sahkan" type="password" class="form-control mb-3" placeholder="Sahkan kata laluan baharu" minlength="8" required>
      <button class="btn btn-sipadi">Tukar</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
