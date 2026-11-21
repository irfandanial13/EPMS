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
<?php $wajib = '<span class="op-wajib" aria-hidden="true">*</span>'; ?>
<div class="op-kepala">
  <div>
    <h1 class="page-title">Pengguna</h1>
    <p class="op-sub">Akaun pentadbir sistem. Pengguna baharu wajib menukar kata laluan pada log masuk pertama.</p>
  </div>
  <div class="op-tindakan no-print">
    <a class="btn btn-sipadi btn-sm" href="#borang-tambah"><i class="bi bi-person-plus" aria-hidden="true"></i> Tambah Admin</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="table-wrap">
      <table class="table table-sm table-hover table-data mb-0">
        <thead><tr><th class="text-start">Nama Pengguna</th><th class="text-start">Nama</th><th class="text-start">Didaftar</th><th><span class="visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($list as $u): ?>
          <tr><td class="fw-semibold"><?= e($u['username']) ?></td><td><?= e($u['nama']) ?></td><td><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td>
            <td><div class="op-tindakan-baris"><?php if ($u['id'] != $me['id']): ?>
              <form method="post" onsubmit="return confirm('Padam pengguna ini?')"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="padam"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn op-btn-ikon op-btn-padam" title="Padam" aria-label="Padam pengguna <?= e($u['username']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
              <?php else: ?><span class="st-badge st-hijau">Anda</span><?php endif; ?></div></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-5">
    <form method="post" class="card op-borang" id="borang-tambah" data-validasi aria-label="Tambah Admin"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
      <div class="op-seksyen op-seksyen-tunggal">
        <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
          <div><h2>Tambah Admin</h2><p>Kata laluan sementara; wajib ditukar oleh pengguna.</p></div></div>
        <div>
          <div class="mb-3"><label class="form-label" for="tbUsername">Nama pengguna <?= $wajib ?></label>
            <input name="username" id="tbUsername" class="form-control" required maxlength="50" autocomplete="off"></div>
          <div class="mb-3"><label class="form-label" for="tbNama">Nama penuh <?= $wajib ?></label>
            <input name="nama" id="tbNama" class="form-control" required maxlength="100"></div>
          <div><label class="form-label" for="tbPassword">Kata laluan sementara <?= $wajib ?></label>
            <input name="password" id="tbPassword" type="password" class="form-control" minlength="8" required autocomplete="new-password">
            <div class="form-text">Min 8 aksara, huruf dan nombor.</div></div>
        </div>
      </div>
      <div class="op-bar-tindakan"><span class="op-bar-nota"><span class="op-wajib">*</span> Medan wajib</span>
        <button class="btn btn-sipadi"><i class="bi bi-person-plus" aria-hidden="true"></i> Tambah</button></div>
    </form>
    <form method="post" class="card op-borang" id="tukar-katalaluan" data-validasi aria-label="Tukar Kata Laluan Saya"><?= csrf_field() ?><input type="hidden" name="aksi" value="tukar">
      <div class="op-seksyen op-seksyen-tunggal">
        <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-key" aria-hidden="true"></i></span>
          <div><h2>Tukar Kata Laluan Saya</h2><p>Akaun @<?= e($me['username']) ?>.</p></div></div>
        <div>
          <div class="mb-3"><label class="form-label" for="tkLama">Kata laluan semasa <?= $wajib ?></label>
            <input name="lama" id="tkLama" type="password" class="form-control" required autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label" for="tkBaru">Kata laluan baharu <?= $wajib ?></label>
            <input name="baru" id="tkBaru" type="password" class="form-control" minlength="8" required autocomplete="new-password">
            <div class="form-text">Min 8 aksara, mengandungi huruf dan nombor.</div></div>
          <div><label class="form-label" for="tkSahkan">Sahkan kata laluan baharu <?= $wajib ?></label>
            <input name="sahkan" id="tkSahkan" type="password" class="form-control" minlength="8" required autocomplete="new-password"></div>
        </div>
      </div>
      <div class="op-bar-tindakan"><span class="op-bar-nota"><span class="op-wajib">*</span> Medan wajib</span>
        <button class="btn btn-sipadi"><i class="bi bi-key" aria-hidden="true"></i> Tukar</button></div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
