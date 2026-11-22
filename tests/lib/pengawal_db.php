<?php
/**
 * Pengawal pangkalan data ujian EPMS — WAJIB digunakan oleh setiap ujian yang menyentuh pangkalan data.
 *
 * Peraturan (gagal tertutup — tiada nilai lalai):
 *  - Nama DB ujian HANYA daripada pemboleh ubah persekitaran EPMS_UJIAN_DB, mesti sepadan ^epms_ujian_[a-z0-9_]+$.
 *  - Ditolak walaupun sepadan corak: 'epms', 'sipadi', dan DB_NAME produksi (config.php / config.example.php / pemalar DB_NAME).
 *  - Akaun daripada EPMS_UJIAN_USER (wajib) & EPMS_UJIAN_PASS; akaun 'root' ditolak. Akaun disyorkan: 'epms_ujian'
 *    dengan keistimewaan HANYA pada `epms\_ujian\_%`.* (lihat tests/README.md). config.php produksi TIDAK dimuatkan.
 *  - SELECT DATABASE() disahkan selepas sambungan, sebelum & selepas setiap import / migrasi / operasi pengubah.
 *  - Fail SQL disemak dengan pemeriksa pemasang (includes/sql_selamat.php) sebelum diimport.
 * Fail ini tidak membuat sambungan dengan sendirinya; hanya ujian_sambung() menyambung, selepas semua semakan lulus.
 */
require_once __DIR__ . '/../../includes/sql_selamat.php';

const UJIAN_POLA_DB = '/^epms_ujian_[a-z0-9_]+$/D';
const UJIAN_DB_DILARANG = ['epms', 'sipadi'];
const UJIAN_AKAUN_DILARANG = ['root'];

/** Ujian dihentikan oleh pengawal keselamatan (tiada operasi pangkalan data dilakukan selepasnya). */
final class UjianDitolak extends RuntimeException {}

/**
 * Nama pangkalan data yang TIDAK BOLEH digunakan sebagai DB ujian: 'epms', 'sipadi', pemalar DB_NAME (jika ditakrif),
 * dan DB_NAME yang dibaca SEBAGAI TEKS daripada config.php / config.example.php projek (fail tidak dimuatkan; kelayakan tidak dibaca).
 */
function ujian_db_produksi(array $tambahan = []): array
{
    $nama = [...UJIAN_DB_DILARANG, ...$tambahan];
    if (defined('DB_NAME')) $nama[] = (string)constant('DB_NAME');
    foreach (['config.php', 'config.example.php'] as $f) {
        $p = __DIR__ . '/../../' . $f;
        if (is_file($p) && preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']*)'\s*\)/", (string)file_get_contents($p), $m)) $nama[] = $m[1];
    }
    return array_values(array_unique(array_map('strtolower', $nama)));
}

/** Sahkan nama DB ujian. $nilai null = baca EPMS_UJIAN_DB. Pulangkan nama yang sah atau lontar UjianDitolak. */
function ujian_nama_db(?string $nilai = null, array $produksiTambahan = []): string
{
    $db = $nilai ?? getenv('EPMS_UJIAN_DB');
    if ($db === false || $db === null) throw new UjianDitolak('EPMS_UJIAN_DB tidak ditetapkan. Tiada nama DB ujian lalai — tetapkan secara eksplisit (cth epms_ujian_f42).');
    if ($db === '') throw new UjianDitolak('EPMS_UJIAN_DB kosong.');
    if (!preg_match(UJIAN_POLA_DB, $db)) throw new UjianDitolak("Nama DB ujian '$db' tidak sah: mesti sepadan ^epms_ujian_[a-z0-9_]+\$.");
    if (in_array(strtolower($db), ujian_db_produksi($produksiTambahan), true)) throw new UjianDitolak("Nama DB ujian '$db' ialah pangkalan data produksi/dilarang.");
    return $db;
}

/** Akaun ujian daripada persekitaran. root ditolak; tiada lalai. @return array{user:string, pass:string} */
function ujian_akaun(): array
{
    $user = getenv('EPMS_UJIAN_USER');
    if ($user === false || $user === '') throw new UjianDitolak('EPMS_UJIAN_USER tidak ditetapkan. Gunakan akaun ujian terhad (disyorkan: epms_ujian).');
    if (in_array(strtolower($user), UJIAN_AKAUN_DILARANG, true)) throw new UjianDitolak("Akaun '$user' ditolak untuk ujian: gunakan akaun terhad kepada `epms\\_ujian\\_%`.");
    return ['user' => $user, 'pass' => (string)getenv('EPMS_UJIAN_PASS')];
}

/** Sahkan sambungan sedang menggunakan TEPAT $jangka (dan $jangka sendiri lulus semakan nama). */
function ujian_sahkan_db(PDO $pdo, string $jangka, string $peringkat): void
{
    ujian_nama_db($jangka);
    $semasa = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($semasa !== $jangka) {
        throw new UjianDitolak("Pangkalan data terpilih $peringkat ialah '" . ($semasa ?? 'tiada') . "', bukan '$jangka'. Ujian dihentikan.");
    }
}

/**
 * Sambung ke DB ujian (EPMS_UJIAN_DB) dengan akaun ujian; semua semakan nama & akaun dibuat SEBELUM sambungan.
 * Hos/port: EPMS_UJIAN_HOST (lalai 127.0.0.1), EPMS_UJIAN_PORT (lalai 3306).
 */
function ujian_sambung(?string $db = null): PDO
{
    $db = ujian_nama_db($db);
    $akaun = ujian_akaun();
    $host = getenv('EPMS_UJIAN_HOST') ?: '127.0.0.1';
    $port = (int)(getenv('EPMS_UJIAN_PORT') ?: 3306);
    fwrite(STDERR, "[pengawal] DB ujian: $db @ $host:$port (akaun {$akaun['user']})\n");
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $akaun['user'], $akaun['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    ujian_sahkan_db($pdo, $db, 'selepas sambungan');
    return $pdo;
}

/** Jalankan operasi yang mengubah pangkalan data dengan pengesahan DB sebelum & selepas. */
function ujian_jalan(PDO $pdo, string $db, string $peringkat, callable $operasi): mixed
{
    ujian_sahkan_db($pdo, $db, "sebelum $peringkat");
    $hasil = $operasi($pdo);
    ujian_sahkan_db($pdo, $db, "selepas $peringkat");
    return $hasil;
}

/** Import fail SQL ke DB ujian: kandungan disemak (tiada USE/CREATE DATABASE/db.jadual), DB disahkan sebelum & selepas. */
function ujian_import_sql(PDO $pdo, string $fail, string $db): void
{
    $sql = is_file($fail) ? file_get_contents($fail) : false;
    if ($sql === false) throw new UjianDitolak('Fail SQL tidak dijumpai: ' . basename($fail));
    $sebab = sql_arahan_dilarang($sql);
    if ($sebab !== null) throw new UjianDitolak('Fail SQL ' . basename($fail) . " ditolak: $sebab");
    ujian_jalan($pdo, $db, 'import ' . basename($fail), function (PDO $p) use ($sql) {
        $st = $p->query($sql);
        while ($st->nextRowset()) { /* setiap pernyataan */ }
        $st->closeCursor();
    });
}

/** Corak GRANT yang betul untuk akaun ujian (garis bawah dilepaskan supaya '_' tidak menjadi kad bebas). */
function ujian_corak_grant(): string
{
    return '`epms\\_ujian\\_%`';
}
