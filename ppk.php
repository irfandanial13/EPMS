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

$pageTitle = 'Direktori PPK';
require __DIR__ . '/includes/header.php';
$wajib = '<span class="op-wajib" aria-hidden="true">*</span>';
?>
<div class="op-kepala">
  <div>
    <h1 class="page-title">Direktori PPK</h1>
    <p class="op-sub">Pertubuhan Peladang Kawasan ikut wilayah, bilangan rekod dan jumlah penyaluran.</p>
  </div>
  <div class="op-tindakan no-print">
    <?php if ($edit): ?><a class="btn btn-outline-secondary btn-sm" href="ppk.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah PPK baharu</a><?php endif; ?>
    <a class="btn btn-sipadi btn-sm" href="penyaluran.php"><i class="bi bi-table" aria-hidden="true"></i> Rekod Penyaluran</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <form method="post" class="card op-borang" data-validasi aria-label="<?= $edit ? 'Edit PPK' : 'Tambah PPK' ?>">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div class="op-seksyen op-seksyen-tunggal">
        <div class="op-seksyen-kepala"><span class="op-seksyen-ikon"><i class="bi bi-building" aria-hidden="true"></i></span>
          <div><h2><?= $edit ? 'Edit PPK' : 'Tambah PPK' ?></h2><p><?= $edit ? 'Kemas kini maklumat ' . e($edit['kod']) . '.' : 'Daftar PPK baharu.' ?></p></div></div>
        <div>
          <div class="mb-3"><label class="form-label" for="ppkKod">Kod <?= $wajib ?></label><input name="kod" id="ppkKod" class="form-control" placeholder="A-I" required maxlength="10" value="<?= e($edit['kod'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label" for="ppkNama">Nama <?= $wajib ?></label><input name="nama" id="ppkNama" class="form-control" required maxlength="150" value="<?= e($edit['nama'] ?? '') ?>"></div>
          <div><label class="form-label" for="ppkWilayah">Wilayah <?= $wajib ?></label>
            <select name="wilayah_id" id="ppkWilayah" class="form-select" required>
              <?php foreach (senarai_wilayah() as $w): ?><option value="<?= $w['id'] ?>" <?= ($edit['wilayah_id'] ?? '') == $w['id'] ? 'selected' : '' ?>><?= e($w['nama']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
      </div>
      <div class="op-bar-tindakan">
        <span class="op-bar-nota"><span class="op-wajib">*</span> Medan wajib</span>
        <div class="d-flex gap-2"><?php if ($edit): ?><a href="ppk.php" class="btn btn-outline-secondary">Batal</a><?php endif; ?><button class="btn btn-sipadi"><i class="bi bi-save" aria-hidden="true"></i> Simpan</button></div>
      </div>
    </form>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-wrap table-scroll">
      <table class="table table-sm table-hover table-data mb-0">
        <thead><tr><th class="text-start">Kod</th><th class="text-start">Nama</th><th class="text-start">Wilayah</th><th class="num">Rekod</th><th class="num">Aktif</th><th class="num">Penyaluran (RM)</th><th><span class="visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($list as $p): ?>
          <tr>
            <td><?= e($p['kod']) ?></td><td><?= e($p['nama']) ?></td><td><?= e($p['wilayah']) ?></td>
            <td class="num"><a href="penyaluran.php?ppk=<?= $p['id'] ?>"><?= nint($p['n']) ?></a></td>
            <td class="num"><?= nint($p['aktif']) ?></td><td class="num"><?= rm($p['rm']) ?></td>
            <td class="text-nowrap"><div class="op-tindakan-baris">
              <a class="btn op-btn-ikon op-btn-edit" href="?edit=<?= $p['id'] ?>" title="Edit" aria-label="Edit PPK <?= e($p['kod']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <?php if (!$p['n']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Padam PPK ini?')"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="padam"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn op-btn-ikon op-btn-padam" title="Padam" aria-label="Padam PPK <?= e($p['kod']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
