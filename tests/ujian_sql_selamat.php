<?php
/**
 * Ujian pemeriksa import SQL (includes/sql_selamat.php) dan pemasang (install.php). TIADA sambungan pangkalan data:
 * pemasang dijalankan dalam salinan sementara dengan config PALSU (akaun palsu, port 1 tanpa pendengar, DB epms_ujian_pasang).
 *   C:\xampp\php\php.exe tests\ujian_sql_selamat.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }
require __DIR__ . '/../includes/sql_selamat.php';
require __DIR__ . '/lib/semak.php';
const VERSI_SEBELUM_TASK_A = 'd73cd73';
$akar = realpath(__DIR__ . '/..');

echo "Fail SQL projek\n";
$schema = file_get_contents("$akar/database/schema.sql");
semak('schema.sql: lulus pemeriksa; tiada CREATE DATABASE / USE / sipadi', sql_arahan_dilarang($schema) === null && !preg_match('/CREATE\s+DATABASE|\bUSE\b|sipadi/i', $schema));
if (is_file("$akar/database/data.sql")) {
    $data = file_get_contents("$akar/database/data.sql");
    semak('data.sql (tempatan, tidak dijejak): lulus pemeriksa; tiada USE', sql_arahan_dilarang($data) === null && !preg_match('/^\s*USE\b/im', $data));
} else {
    echo "  SKIP data.sql — fail tempatan (diabaikan .gitignore) tiada dalam salinan ini\n";
}
$asal = shell_exec('git -C ' . escapeshellarg($akar) . ' show ' . VERSI_SEBELUM_TASK_A . ':database/schema.sql 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
if ($asal) semak('schema.sql sebelum Task A (' . VERSI_SEBELUM_TASK_A . ') ditolak: CREATE DATABASE', str_contains((string)sql_arahan_dilarang($asal), 'CREATE DATABASE'));
else echo "  SKIP schema.sql versi lama — git tidak tersedia\n";

echo "\nPemeriksa SQL\n";
foreach (['USE sipadi;', "use `epms`;", "\n\tUSE x", 'INSERT INTO t VALUES (1);USE sipadi;', '/*!40000 USE sipadi */;', "INSERT INTO t VALUES ('a # b'); USE x;",
          "INSERT INTO t VALUES ('it''s'); USE x;", 'CONNECT sipadi;', '\\u sipadi', 'CREATE DATABASE epms;', 'CREATE SCHEMA x;', 'CREATE OR REPLACE DATABASE x;',
          'DROP DATABASE sipadi;', 'ALTER DATABASE x CHARACTER SET latin1;', 'INSERT INTO sipadi.penyaluran VALUES (1);', 'DELETE FROM `epms`.`ppk`;',
          'CREATE TABLE t (a INT, FOREIGN KEY (a) REFERENCES epms.ppk(id));'] as $sql)
    semak('ditolak: ' . json_encode($sql, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), sql_arahan_dilarang($sql) !== null);
foreach (["-- USE sipadi\nCREATE TABLE t (id INT);", "/* DROP DATABASE x; */ INSERT INTO t VALUES (1);", "INSERT INTO t VALUES ('USE sipadi; CREATE DATABASE x');",
          "INSERT INTO t VALUES (1.25, 'epms.ppk');", "/*!40101 SET NAMES utf8mb4 */;", "CREATE TABLE t (id INT, FOREIGN KEY (id) REFERENCES wilayah(id));"] as $sql)
    semak('diterima: ' . json_encode($sql, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), sql_arahan_dilarang($sql) === null);
foreach (['epms' => true, 'sipadi' => true, 'epms_ujian_f42' => true, 'a`b' => false, 'x;DROP' => false, '' => false, str_repeat('a', 65) => false] as $n => $ok)
    semak('nama_db_sah(' . json_encode(strlen($n) > 12 ? substr($n, 0, 6) . '…' : $n) . ') = ' . ($ok ? 'sah' : 'ditolak'), nama_db_sah($n) === $ok);

echo "\ninstall.php (statik)\n";
$pemasang = file_get_contents("$akar/install.php");
semak('install.php memuatkan includes/sql_selamat.php dan tidak mentakrif semula fungsinya', str_contains($pemasang, "require __DIR__ . '/includes/sql_selamat.php';")
    && !preg_match('/function\s+(nama_db_sah|sql_kod_sahaja|sql_arahan_dilarang)\s*\(/', $pemasang));
semak('install.php: tiada literal sipadi; sambungan awal tanpa dbname; CREATE + USE DB_NAME oleh pemasang; import_fail_sql', !str_contains($pemasang, 'sipadi')
    && preg_match("/new PDO\('mysql:host=' \. DB_HOST \. ';port=' \. DB_PORT \. ';charset=utf8mb4'/", $pemasang) && str_contains($pemasang, "\$pdo->exec('USE `' . \$db . '`')")
    && str_contains($pemasang, 'foreach ($failSql as $f) import_fail_sql($pdo, $f, $db)'));

echo "\ninstall.php (hujung-ke-hujung, salinan sementara, TIADA pangkalan data)\n";
$t = sys_get_temp_dir() . '/epms_ujian_pemasang_' . getmypid();
foreach (["$t/includes", "$t/database", "$t/storage/logs"] as $d) mkdir($d, 0775, true);
copy("$akar/install.php", "$t/install.php");
foreach (glob("$akar/includes/*.php") as $f) copy($f, "$t/includes/" . basename($f));
copy("$akar/database/schema.sql", "$t/database/schema.sql");
$dataSelamat = is_file("$akar/database/data.sql") ? file_get_contents("$akar/database/data.sql") : "SET NAMES utf8mb4;\nINSERT INTO ppk (wilayah_id, kod, nama, susunan) VALUES (1, 'A-I', 'ARAU', 1);\n";
file_put_contents("$t/database/data.sql", $dataSelamat);
$konfig = preg_replace(["/define\('DB_PORT', 3306\);/", "/define\('DB_NAME', '[^']*'\);/", "/define\('DB_USER', '[^']*'\);/", "/define\('DB_PASS', '[^']*'\);/"],
    ["define('DB_PORT', 1);", "define('DB_NAME', getenv('UJI_DB_NAME') ?: 'epms_ujian_pasang');", "define('DB_USER', 'akaun_palsu_ujian');", "define('DB_PASS', 'tiada');"],
    file_get_contents("$akar/config.example.php"), -1, $n);
semak('config palsu dibina daripada config.example.php (port 1, akaun palsu, epms_ujian_pasang)', $n === 4);
file_put_contents("$t/config.php", $konfig);
$pasang = function (array $env = []) use ($t): array {
    $p = proc_open([PHP_BINARY, 'install.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $paip, $t, $env + ['SystemRoot' => getenv('SystemRoot') ?: '', 'PATH' => getenv('PATH') ?: '']);
    $out = stream_get_contents($paip[1]) . stream_get_contents($paip[2]); $kod = proc_close($p);
    return [$kod, preg_replace('/\s+/', ' ', $out), is_file("$t/storage/install.lock")];
};
$logRalat = fn() => is_file("$t/storage/logs/error.log") ? file_get_contents("$t/storage/logs/error.log") : '';
touch("$t/storage/install.lock");
[$k, $o, $kunci] = $pasang();
semak('install.lock wujud → "Pemasang dikunci" (403)', $k === 1 && str_contains($o, 'Pemasang dikunci'));
unlink("$t/storage/install.lock");
$asal && file_put_contents("$t/database/schema.sql", $asal);
if ($asal) {
    [$k, $o, $kunci] = $pasang();
    semak('schema.sql lama (CREATE DATABASE + USE) → ditolak SEBELUM sambungan; pemasang tidak dikunci', $k === 1 && str_contains($o, 'ditolak: mengandungi arahan CREATE DATABASE') && !$kunci && !str_contains($logRalat(), 'sambungan gagal'));
}
copy("$akar/database/schema.sql", "$t/database/schema.sql");
file_put_contents("$t/database/data.sql", $dataSelamat . "\nINSERT INTO sipadi.penyaluran VALUES (1);\n");
[$k, $o, $kunci] = $pasang();
semak('data.sql dengan sipadi.penyaluran → ditolak sebelum sambungan', $k === 1 && str_contains($o, 'pangkalan data lain') && !$kunci && !str_contains($logRalat(), 'sambungan gagal'));
file_put_contents("$t/database/data.sql", $dataSelamat);
[$k, $o, $kunci] = $pasang(['UJI_DB_NAME' => 'epms;DROP']);
semak('DB_NAME tidak sah → ditolak sebelum sambungan', $k === 1 && str_contains($o, 'DB_NAME dalam config.php tidak sah') && !$kunci && !str_contains($logRalat(), 'sambungan gagal'));
[$k, $o, $kunci] = $pasang();
semak('fail selamat → sampai ke sambungan (port 1 ditolak); pemasang tidak dikunci', $k === 1 && str_contains($o, 'Tidak dapat menyambung ke MySQL') && !$kunci && str_contains($logRalat(), 'sambungan gagal'));
$hapus = function (string $d) use (&$hapus) { foreach (array_diff(scandir($d), ['.', '..']) as $f) is_dir("$d/$f") ? $hapus("$d/$f") : unlink("$d/$f"); rmdir($d); };
$hapus($t);
semak('salinan sementara dibuang', !is_dir($t));
tamat();
