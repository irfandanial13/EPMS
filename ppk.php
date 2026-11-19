<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($aksi === 'simpan') {
        $kod = strtoupper(trim($_POST['kod'] ?? ''));
        $nama = strtoupper(trim($_POST['nama'] ?? ''));
        $wid = (int)($_POST['wilayah_id'] ?? 0);
        if ($kod === '' || $nama === '' || !$wid) {
            flash('Kod, nama dan wilayah wajib diisi.', 'danger');
        } else {
            try {
                if ($id) {
                    db()->prepare('UPDATE ppk SET kod=?, nama=?, wilayah_id=? WHERE id=?')->execute([$kod, $nama, $wid, $id]);
                } else {
                    db()->prepare('INSERT INTO ppk (kod, nama, wilayah_id, susunan) SELECT ?, ?, ?, COALESCE(MAX(susunan),0)+1 FROM ppk')->execute([$kod, $nama, $wid]);
                }
                flash('PPK telah disimpan.');
            } catch (PDOException $e) {
                flash('Kod PPK telah wujud.', 'danger');
            }
        }
    } elseif ($aksi === 'padam') {
        $st = db()->prepare('SELECT COUNT(*) FROM penyaluran WHERE ppk_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() > 0) {
            flash('PPK ini masih mempunyai rekod penyaluran dan tidak boleh dipadam.', 'danger');
        } else {
            db()->prepare('DELETE FROM ppk WHERE id = ?')->execute([$id]);
            flash('PPK telah dipadam.');
        }
    }
    redirect('ppk.php');
}

$list = db()->query("SELECT p.*, w.nama wilayah, COUNT(x.id) n, SUM(x.status='AKTIF') aktif,
                            COALESCE(SUM(x.sewaan + x.modal_operasi),0) rm
                     FROM ppk p JOIN wilayah w ON w.id = p.wilayah_id LEFT JOIN penyaluran x ON x.ppk_id = p.id
                     GROUP BY p.id ORDER BY p.wilayah_id, p.susunan")->fetchAll();
$edit = null;
foreach ($list as $p) if ($p['id'] == ($_GET['edit'] ?? 0)) $edit = $p;

$pageTitle = 'PPK';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-3"><i class="bi bi-building"></i> Pertubuhan Peladang Kawasan (PPK)</h1>
<div class="row g-3">
  <div class="col-lg-4">
    <form method="post" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <h2 class="h6"><?= $edit ? 'Edit PPK' : 'Tambah PPK' ?></h2>
      <div class="mb-2"><label class="form-label small">Kod</label><input name="kod" class="form-control" placeholder="A-I" required value="<?= e($edit['kod'] ?? '') ?>"></div>
      <div class="mb-2"><label class="form-label small">Nama</label><input name="nama" class="form-control" required value="<?= e($edit['nama'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label small">Wilayah</label>
        <select name="wilayah_id" class="form-select" required>
          <?php foreach (senarai_wilayah() as $w): ?><option value="<?= $w['id'] ?>" <?= ($edit['wilayah_id'] ?? '') == $w['id'] ? 'selected' : '' ?>><?= e($w['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="d-flex gap-2"><button class="btn btn-sipadi">Simpan</button><?php if ($edit): ?><a href="ppk.php" class="btn btn-outline-secondary">Batal</a><?php endif; ?></div>
    </form>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-wrap">
      <table class="table table-sm table-hover table-data mb-0">
        <thead><tr><th class="text-start">Kod</th><th class="text-start">Nama</th><th class="text-start">Wilayah</th><th class="num">Rekod</th><th class="num">Aktif</th><th class="num">Penyaluran (RM)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $p): ?>
          <tr>
            <td><?= e($p['kod']) ?></td><td><?= e($p['nama']) ?></td><td><?= e($p['wilayah']) ?></td>
            <td class="num"><a href="penyaluran.php?ppk=<?= $p['id'] ?>"><?= nint($p['n']) ?></a></td>
            <td class="num"><?= nint($p['aktif']) ?></td><td class="num"><?= rm($p['rm']) ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-primary py-0" href="?edit=<?= $p['id'] ?>"><i class="bi bi-pencil"></i></a>
              <?php if (!$p['n']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Padam PPK ini?')"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="padam"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
