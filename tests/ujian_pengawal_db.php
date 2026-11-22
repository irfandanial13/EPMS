<?php
/**
 * Ujian pengawal pangkalan data ujian (tests/lib/pengawal_db.php). TIADA sambungan MariaDB:
 * PDO perakam berasaskan SQLite dalam memori; satu ujian sambungan sengaja menghala ke port 1 (tiada pendengar).
 *   C:\xampp\php\php.exe tests\ujian_pengawal_db.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }
require __DIR__ . '/lib/pengawal_db.php';
require __DIR__ . '/lib/semak.php';

/** Lontar UjianDitolak (dan mesej mengandungi $jangka)? */
function ditolak(callable $f, string $jangka = ''): bool
{
    try { $f(); return false; } catch (UjianDitolak $e) { return $jangka === '' || str_contains($e->getMessage(), $jangka); }
}
function tetap_env(array $nilai): void { foreach ($nilai as $k => $v) putenv($v === null ? $k : "$k=$v"); }
tetap_env(['EPMS_UJIAN_DB' => null, 'EPMS_UJIAN_USER' => null, 'EPMS_UJIAN_PASS' => null, 'EPMS_UJIAN_HOST' => null, 'EPMS_UJIAN_PORT' => null]);

echo "Nama DB ujian (EPMS_UJIAN_DB)\n";
semak('pemboleh ubah tiada → ditolak (tiada lalai)', ditolak(fn() => ujian_nama_db(), 'tidak ditetapkan'));
tetap_env(['EPMS_UJIAN_DB' => '']);
semak('pemboleh ubah kosong → ditolak', ditolak(fn() => ujian_nama_db(), 'kosong'));
tetap_env(['EPMS_UJIAN_DB' => 'epms_ujian_f42']);
semak('epms_ujian_f42 daripada persekitaran → diterima', ujian_nama_db() === 'epms_ujian_f42');
foreach (['epms', 'sipadi', 'EPMS', 'Sipadi'] as $n) semak("'$n' → ditolak", ditolak(fn() => ujian_nama_db($n)));
foreach (['sipadi_ujian_f42' => 'tidak sah', 'epms_ujian_' => 'tidak sah', 'epms_ujian' => 'tidak sah', 'EPMS_UJIAN_F42' => 'tidak sah', 'epms_ujian_F42' => 'tidak sah',
          'epms_ujian_f42;drop' => 'tidak sah', 'epms_ujian_f-42' => 'tidak sah', 'epms_ujian_f42 ' => 'tidak sah', "epms_ujian_f42\n" => 'tidak sah',
          'epms_dev' => 'tidak sah', 'xepms_ujian_f42' => 'tidak sah', 'epms_ujian_a.b' => 'tidak sah', ' epms_ujian_f42' => 'tidak sah'] as $n => $j)
    semak('nama tidak sah ' . json_encode($n) . ' → ditolak', ditolak(fn() => ujian_nama_db($n), $j));
foreach (['epms_ujian_f42', 'epms_ujian_http', 'epms_ujian_migrasi', 'epms_ujian_tmp_12345', 'epms_ujian_f4_3'] as $n) semak("'$n' → diterima", ujian_nama_db($n) === $n);
semak('DB_NAME produksi (dibaca daripada config.php sebagai teks) disenaraikan', in_array('sipadi', ujian_db_produksi(), true) && in_array('epms', ujian_db_produksi(), true));
semak('DB_NAME produksi yang SEPADAN corak ujian tetap ditolak (cth DB_NAME = epms_ujian_utama)', ditolak(fn() => ujian_nama_db('epms_ujian_utama', ['epms_ujian_utama']), 'produksi'));

echo "\nAkaun ujian\n";
tetap_env(['EPMS_UJIAN_USER' => null]);
semak('EPMS_UJIAN_USER tiada → ditolak', ditolak(fn() => ujian_akaun(), 'tidak ditetapkan'));
foreach (['root', 'ROOT', 'Root'] as $u) { tetap_env(['EPMS_UJIAN_USER' => $u]); semak("akaun '$u' → ditolak", ditolak(fn() => ujian_akaun(), 'ditolak')); }
tetap_env(['EPMS_UJIAN_USER' => 'epms_ujian', 'EPMS_UJIAN_PASS' => 'x']);
semak("akaun 'epms_ujian' → diterima", ujian_akaun()['user'] === 'epms_ujian');

echo "\nujian_sambung(): semua semakan SEBELUM sambungan\n";
tetap_env(['EPMS_UJIAN_DB' => 'sipadi', 'EPMS_UJIAN_USER' => 'epms_ujian']);
semak('DB sipadi → ditolak sebelum sambungan (tiada PDOException)', ditolak(fn() => ujian_sambung(), "'sipadi'"));
tetap_env(['EPMS_UJIAN_DB' => 'epms_ujian_f42', 'EPMS_UJIAN_USER' => 'root']);
semak('akaun root → ditolak sebelum sambungan', ditolak(fn() => ujian_sambung(), 'ditolak'));
tetap_env(['EPMS_UJIAN_DB' => 'epms_ujian_f42', 'EPMS_UJIAN_USER' => 'epms_ujian', 'EPMS_UJIAN_HOST' => '127.0.0.1', 'EPMS_UJIAN_PORT' => '1']);
try { ujian_sambung(); $ok = false; $mesej = 'tiada ralat'; }
catch (PDOException $e) { $ok = true; $mesej = $e->getMessage(); }
catch (UjianDitolak $e) { $ok = false; $mesej = $e->getMessage(); }
semak('nama & akaun sah → cubaan sambungan HANYA ke hos/port ujian (port 1 tertutup → PDOException)', $ok, substr($mesej, 0, 90));

echo "\nPengesahan SELECT DATABASE() (PDO perakam, tiada MariaDB)\n";
class StmtPalsu extends PDOStatement { protected function __construct() {} public function nextRowset(): bool { return false; } }
class RekamPDO extends PDO {
    public array $log = [];
    public ?string $tukarKe = null;      // simulasi pertukaran DB oleh operasi/fail
    public function __construct(public ?string $db) { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['StmtPalsu']); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        $this->log[] = $q;
        if ($q === 'SELECT DATABASE()') return parent::query('SELECT ' . ($this->db === null ? 'NULL' : $this->quote($this->db)));
        if ($this->tukarKe !== null) $this->db = $this->tukarKe;
        return parent::query('SELECT 1');
    }
}
$p = new RekamPDO('epms_ujian_f42');
ujian_sahkan_db($p, 'epms_ujian_f42', 'ujian');
semak('DB sepadan → lulus', $p->log === ['SELECT DATABASE()']);
semak('DB sebenar berbeza (epms_ujian_lain) → ditolak', ditolak(fn() => ujian_sahkan_db(new RekamPDO('epms_ujian_lain'), 'epms_ujian_f42', 'ujian'), "'epms_ujian_lain'"));
semak('DB sebenar = sipadi → ditolak', ditolak(fn() => ujian_sahkan_db(new RekamPDO('sipadi'), 'epms_ujian_f42', 'ujian'), "'sipadi'"));
semak('tiada DB terpilih → ditolak', ditolak(fn() => ujian_sahkan_db(new RekamPDO(null), 'epms_ujian_f42', 'ujian'), "'tiada'"));
semak('jangkaan sendiri = sipadi → ditolak walaupun DB sebenar sepadan', ditolak(fn() => ujian_sahkan_db(new RekamPDO('sipadi'), 'sipadi', 'ujian'), "'sipadi'"));
$p = new RekamPDO('epms_ujian_f42'); $p->tukarKe = 'sipadi';
semak('operasi menukar DB (ujian_jalan) → dikesan selepas operasi', ditolak(fn() => ujian_jalan($p, 'epms_ujian_f42', 'migrasi', fn(PDO $x) => $x->query('SELECT 2')), "selepas migrasi ialah 'sipadi'"));

echo "\nImport SQL\n";
$tmp = sys_get_temp_dir() . '/epms_ujian_pengawal_' . getmypid();
mkdir($tmp);
file_put_contents("$tmp/selamat.sql", "CREATE TABLE IF NOT EXISTS t (id INT);\nINSERT INTO t VALUES (1);");
file_put_contents("$tmp/bahaya.sql", "CREATE TABLE t (id INT);\nUSE sipadi;\nDELETE FROM penyaluran;");
file_put_contents("$tmp/silang.sql", "INSERT INTO sipadi.penyaluran VALUES (1);");
$p = new RekamPDO('epms_ujian_f42');
ujian_import_sql($p, "$tmp/selamat.sql", 'epms_ujian_f42');
semak('fail selamat: SELECT DATABASE() → import → SELECT DATABASE()', $p->log === ['SELECT DATABASE()', file_get_contents("$tmp/selamat.sql"), 'SELECT DATABASE()']);
foreach (['bahaya.sql' => 'USE', 'silang.sql' => 'pangkalan data lain'] as $f => $j) {
    $p = new RekamPDO('epms_ujian_f42');
    semak("$f ditolak SEBELUM sebarang arahan dihantar", ditolak(fn() => ujian_import_sql($p, "$tmp/$f", 'epms_ujian_f42'), $j) && $p->log === []);
}
$p = new RekamPDO('epms_ujian_f42'); $p->tukarKe = 'sipadi';
semak('pertukaran DB semasa import (tidak dikesan oleh pemeriksa) → dikesan selepas import', ditolak(fn() => ujian_import_sql($p, "$tmp/selamat.sql", 'epms_ujian_f42'), 'selepas import'));
$p = new RekamPDO('sipadi');
semak('DB salah sebelum import → kandungan tidak dihantar', ditolak(fn() => ujian_import_sql($p, "$tmp/selamat.sql", 'epms_ujian_f42')) && $p->log === ['SELECT DATABASE()']);
array_map('unlink', glob("$tmp/*")); rmdir($tmp);

echo "\nCorak GRANT akaun ujian (garis bawah dilepaskan)\n";
/** Padanan LIKE MySQL (% _ dan \ melepaskan) — untuk membuktikan corak keistimewaan. */
function like_mysql(string $corak, string $nama): bool
{
    $re = '';
    for ($i = 0; $i < strlen($corak); $i++) {
        $c = $corak[$i];
        if ($c === '\\' && $i + 1 < strlen($corak)) { $re .= preg_quote($corak[++$i], '/'); continue; }
        $re .= $c === '%' ? '.*' : ($c === '_' ? '.' : preg_quote($c, '/'));
    }
    return (bool)preg_match("/^$re\$/sD", $nama);
}
$corak = trim(ujian_corak_grant(), '`');
semak('ujian_corak_grant() = `epms\_ujian\_%`', ujian_corak_grant() === '`epms\\_ujian\\_%`');
foreach (['epms_ujian_f42' => true, 'epms_ujian_tmp_1' => true, 'epms' => false, 'sipadi' => false, 'epms_dev' => false, 'epmsXujianXf42' => false, 'epms_ujianXf42' => false] as $n => $jangka)
    semak("corak " . ($jangka ? 'merangkumi' : 'TIDAK merangkumi') . " '$n'", like_mysql($corak, $n) === $jangka);
semak("bandingan: corak luas 'epms%' merangkumi produksi 'epms' (sebab ia dilarang)", like_mysql('epms%', 'epms') && like_mysql('epms_ujian_%', 'epmsXujianXf42'));

tamat();
