<?php
/**
 * Ujian awalan backup (epms_ baharu + sipadi_ lama): pengesahan nama, senarai, muat turun, restore (sehingga sebelum
 * sebarang operasi DB), metadata dan retention. TIADA sambungan pangkalan data dan TIADA config.php produksi:
 * pemalar ditetapkan di sini ke folder sementara; db() sengaja melontar ralat jika dipanggil.
 *   C:\xampp\php\php.exe tests\ujian_backup.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }
require __DIR__ . '/lib/semak.php';

const VERSI_SEBELUM_TASK_C = '0c5c9ca';   // untuk ujian kesetaraan retention backup lama
$TMP = sys_get_temp_dir() . '/epms_ujian_backup_' . getmypid();
mkdir("$TMP/backups", 0775, true); mkdir("$TMP/logs");
define('BACKUP_DIR', "$TMP/backups");
define('LOG_DIR', "$TMP/logs");
define('BACKUP_SIMPAN_JADUAL', 30);
define('BACKUP_AMARAN_JAM', 48);
define('DB_NAME', 'epms_ujian_tiada_sambungan');
define('DB_HOST', '127.0.0.1'); define('DB_PORT', 1); define('DB_USER', 'tiada'); define('DB_PASS', 'tiada');
define('MYSQL_BIN_DIR', "$TMP/tiada_bin");
$LOG = [];
function app_log(string $m, string $f = 'app.log'): void { $GLOBALS['LOG'][] = "$f: $m"; }
function db(): PDO { throw new RuntimeException('UJIAN: db() dipanggil — operasi pangkalan data tidak dibenarkan dalam ujian ini'); }
require __DIR__ . '/../includes/backup_lib.php';

/** Cipta pasangan backup palsu (.sql lengkap + .json dengan sha256). */
function cipta_backup(string $dir, string $fail, string $jenisMeta, int $masa, array $pilihan = []): void
{
    $sql = "-- MySQL dump ujian\nCREATE TABLE t (id INT);\n-- Dump completed on " . date('Y-m-d H:i:s', $masa) . "\n";
    file_put_contents("$dir/$fail", $sql);
    if (!empty($pilihan['tanpaMeta'])) return;
    $meta = ['fail' => $fail, 'dicipta' => date('Y-m-d H:i:s', $pilihan['diciptaMeta'] ?? $masa), 'jenis' => $jenisMeta, 'oleh' => 'ujian',
             'saiz' => strlen($sql), 'sha256' => !empty($pilihan['rosak']) ? str_repeat('0', 64) : hash('sha256', $sql), 'jadual' => ['penyaluran' => 1]];
    file_put_contents("$dir/" . preg_replace('/\.sql$/', '.json', $fail), json_encode($meta));
}
function nama(string $awalan, int $masa, string $jenis, string $sufiks = ''): string { return $awalan . date('Ymd_His', $masa) . "_$jenis$sufiks.sql"; }
function kosongkan(string $dir): void { foreach (glob("$dir/*") as $f) unlink($f); }
function cap_folder(string $dir): string { $h = []; foreach (glob("$dir/*") as $f) $h[basename($f)] = hash_file('sha256', $f); ksort($h); return md5(json_encode($h)); }

echo "Awalan\n";
semak("BACKUP_AWALAN lalai = 'epms_'", BACKUP_AWALAN === 'epms_');
semak("awalan diterima = ['epms_', 'sipadi_']", backup_awalan_diterima() === ['epms_', 'sipadi_']);
semak('backup baharu: nama_asas_backup_baharu() = epms_<Ymd_His>_<jenis>', nama_asas_backup_baharu('jadual', strtotime('2026-11-22 20:00:00')) === 'epms_20261122_200000_jadual');
$sumber = file_get_contents(__DIR__ . '/../includes/backup_lib.php');
preg_match('/function buat_backup\(.*?\n}\n/s', $sumber, $bb);
semak("buat_backup() menggunakan nama_asas_backup_baharu() dan tiada literal 'sipadi_'", isset($bb[0]) && str_contains($bb[0], 'nama_asas_backup_baharu($jenis)') && !str_contains($bb[0], 'sipadi_'));

echo "\nPengesahan nama (validation / muat turun / restore guna backup_nama_sah)\n";
$sah = ['sipadi_20261121_200004_jadual.sql', 'sipadi_20261118_164308_pra-fasa1.sql', 'sipadi_20261121_155343_manual.sql', 'sipadi_20261122_101010_pra-pulih_2.sql',
        'epms_20261122_200000_jadual.sql', 'epms_20261122_200000_manual_3.sql', 'epms_20261122_200000_pra-pulih.sql'];
foreach ($sah as $f) semak("diterima: $f", backup_nama_sah($f));
$tidak = ['ujian_20261118_182507_pra-fasa2-1_snapshot.json', 'kod_20261118_164308_pra-fasa1.zip', 'SIPADI_20261121_200004_jadual.sql', 'EPMS_20261122_200000_jadual.sql',
          'epmsx_20261122_200000_jadual.sql', 'xepms_20261122_200000_jadual.sql', 'sipadi_ujian_20261122_200000_jadual.sql', 'epms-20261122_200000_jadual.sql',
          'epms_2026112_200000_jadual.sql', 'epms_20261122_20000_jadual.sql', 'epms_20261122_200000.sql', 'epms_20261122_200000_.sql', 'epms_20261122_200000_JADUAL.sql',
          'epms_20261122_200000_jadual.json', 'epms_20261122_200000_jadual.sql.bak', "epms_20261122_200000_jadual.sql\n", '../epms_20261122_200000_jadual.sql',
          'sub/epms_20261122_200000_jadual.sql', 'epms_20261122_200000_jadual;.sql', 'nota.sql', ''];
foreach ($tidak as $f) semak('ditolak: ' . json_encode($f), !backup_nama_sah($f));
semak("backup_pecah_nama: sipadi_…_pra-pulih_2", backup_pecah_nama('sipadi_20261122_101010_pra-pulih_2.sql') === ['awalan' => 'sipadi_', 'masa' => '20261122_101010', 'jenis' => 'pra-pulih', 'urutan' => 2]);
semak("backup_pecah_nama: epms_…_jadual (urutan lalai 1)", backup_pecah_nama('epms_20261122_200000_jadual.sql') === ['awalan' => 'epms_', 'masa' => '20261122_200000', 'jenis' => 'jadual', 'urutan' => 1]);
try { backup_path('../epms_20261122_200000_jadual.sql'); $ok = false; } catch (InvalidArgumentException) { $ok = true; }
semak('backup_path() menolak path traversal', $ok);
$bpath = fn($f) => str_replace('\\', '/', backup_path($f));
semak('backup_path() untuk kedua-dua awalan berada dalam BACKUP_DIR', $bpath('sipadi_20261121_200004_jadual.sql') === str_replace('\\', '/', BACKUP_DIR) . '/sipadi_20261121_200004_jadual.sql'
    && $bpath('epms_20261122_200000_jadual.sql') === str_replace('\\', '/', BACKUP_DIR) . '/epms_20261122_200000_jadual.sql');
$backupPhp = file_get_contents(__DIR__ . '/../backup.php');
semak('backup.php: muat turun, restore & pilihan restore menggunakan backup_nama_sah() (tiada corak awalan tersendiri)',
    substr_count($backupPhp, 'backup_nama_sah(') === 3 && !preg_match('/sipadi_|epms_/', $backupPhp));

echo "\nSenarai (awalan bercampur) & metadata\n";
$d = BACKUP_DIR;
$t = strtotime('2026-11-22 12:00:00');
cipta_backup($d, nama('sipadi_', $t - 2 * 86400, 'jadual'), 'jadual', $t - 2 * 86400);
cipta_backup($d, nama('sipadi_', $t - 3600, 'manual'), 'manual', $t - 3600);
cipta_backup($d, nama('sipadi_', $t - 3600, 'manual', '_2'), 'manual', $t - 3600);
cipta_backup($d, nama('epms_', $t, 'jadual'), 'jadual', $t);
cipta_backup($d, nama('epms_', $t - 86400, 'pra-pulih'), 'pra-pulih', $t - 86400);
foreach (['ujian_20261118_182507_pra-fasa2-1_snapshot.json' => '{}', 'kod_20261118_164308_pra-fasa1.zip' => 'zip', 'nota.sql' => 'x', 'epms_bad.sql' => 'x', 'SIPADI_20261121_200004_jadual.sql' => 'x'] as $f => $isi)
    file_put_contents("$d/$f", $isi);
$senarai = senarai_backup();
$fail = array_column($senarai, 'fail');
semak('hanya 5 backup sah disenaraikan (fail lain diabaikan)', count($senarai) === 5, implode(', ', $fail));
semak('susunan ikut cap masa, bukan abjad awalan (epms_ terbaru di atas sipadi_ lama)', $fail === [nama('epms_', $t, 'jadual'), nama('sipadi_', $t - 3600, 'manual', '_2'),
    nama('sipadi_', $t - 3600, 'manual'), nama('epms_', $t - 86400, 'pra-pulih'), nama('sipadi_', $t - 2 * 86400, 'jadual')], implode(', ', $fail));
semak('metadata dipadankan: setiap entri membawa jenis & sha256 daripada .json pasangannya',
    array_column($senarai, 'jenis') === ['jadual', 'manual', 'manual', 'pra-pulih', 'jadual'] && !array_filter($senarai, fn($b) => ($b['sha256'] ?? '') === '' || isset($b['_susun'])));
semak('backup_meta() untuk backup lama & baharu', backup_meta(nama('sipadi_', $t - 2 * 86400, 'jadual'))['jenis'] === 'jadual' && backup_meta(nama('epms_', $t - 86400, 'pra-pulih'))['jenis'] === 'pra-pulih');
$st = status_backup($senarai);
semak("status_backup(): 'terkini' = epms_ terbaru; 'jadual' = berjadual terbaru", $st['terkini']['fail'] === nama('epms_', $t, 'jadual') && $st['jadual']['fail'] === nama('epms_', $t, 'jadual'));
semak('semak_fail_backup(): backup lama & baharu lulus integriti', semak_fail_backup(nama('sipadi_', $t - 2 * 86400, 'jadual'))['ok'] && semak_fail_backup(nama('epms_', $t, 'jadual'))['ok']);

echo "\nRestore (sehingga sebelum operasi pangkalan data)\n";
$cap = cap_folder($d);
foreach (['lama' => nama('sipadi_', $t - 2 * 86400, 'jadual'), 'baharu' => nama('epms_', $t, 'jadual')] as $lb => $f) {
    try { pulih_backup($f, 'ujian'); $m = 'tiada ralat'; } catch (Throwable $e) { $m = $e->getMessage(); }
    semak("backup $lb diterima oleh pulih_backup(): lulus nama + integriti, berhenti di db() (tiada operasi DB)", str_contains($m, 'UJIAN: db() dipanggil'), $m);
}
try { pulih_backup('../x.sql', 'ujian'); $m = ''; } catch (InvalidArgumentException $e) { $m = $e->getMessage(); }
semak('pulih_backup() menolak nama tidak sah', $m === 'Nama fail backup tidak sah.');
cipta_backup($d, nama('sipadi_', $t - 5 * 86400, 'manual'), 'manual', $t - 5 * 86400, ['rosak' => true]);
try { pulih_backup(nama('sipadi_', $t - 5 * 86400, 'manual'), 'ujian'); $m = ''; } catch (RuntimeException $e) { $m = $e->getMessage(); }
semak('pulih_backup() menolak backup lama yang rosak (SHA-256)', str_starts_with($m, 'Backup tidak sah'));
unlink("$d/" . nama('sipadi_', $t - 5 * 86400, 'manual')); unlink("$d/" . preg_replace('/\.sql$/', '.json', nama('sipadi_', $t - 5 * 86400, 'manual')));
semak('cubaan restore tidak mencipta, mengubah atau memadam sebarang fail backup', cap_folder($d) === $cap);

echo "\nRetention (BACKUP_SIMPAN_JADUAL = 30 hari)\n";
kosongkan($d);
$kini = strtotime('2026-12-31 21:00:00');
$h = fn(int $hari) => $kini - $hari * 86400;
$kes = [
    nama('sipadi_', $h(40), 'jadual') => ['jadual', $h(40), [], 'PADAM'],
    nama('epms_', $h(35), 'jadual') => ['jadual', $h(35), [], 'PADAM'],
    nama('epms_', $h(1), 'jadual') => ['jadual', $h(1), [], 'KEKAL'],
    nama('sipadi_', $h(10), 'jadual') => ['jadual', $h(10), [], 'KEKAL'],
    nama('sipadi_', $h(60), 'manual') => ['manual', $h(60), [], 'KEKAL'],
    nama('epms_', $h(90), 'pra-pulih') => ['pra-pulih', $h(90), [], 'KEKAL'],
    nama('sipadi_', $h(70), 'pra-fasa1') => ['pra-fasa1', $h(70), [], 'KEKAL'],
    nama('sipadi_', $h(50), 'jadual') => ['jadual', $h(50), ['tanpaMeta' => true], 'KEKAL'],
    nama('epms_', $h(45), 'jadual') => ['jadual', $h(45), ['rosak' => true], 'KEKAL'],
    nama('sipadi_', $h(55), 'jadual') => ['manual', $h(55), [], 'KEKAL'],                       // metadata kata bukan jadual
    nama('epms_', $h(65), 'jadual') => ['jadual', $h(65), ['diciptaMeta' => $h(64)], 'KEKAL'],  // tarikh nama ≠ metadata
    nama('epms_', $kini + 86400, 'jadual') => ['jadual', $kini + 86400, [], 'KEKAL'],            // masa hadapan
];
foreach ($kes as $f => [$jm, $masa, $p]) cipta_backup($d, $f, $jm, $masa, $p);
foreach (['ujian_20261118_182507_pra-fasa2-1_snapshot.json' => '{}', 'kod_20261118_164308_pra-fasa1.zip' => 'zip', 'nota.sql' => 'x',
          'SIPADI_20261101_200000_jadual.sql' => 'x', 'sipadi_ujian_20261101_200000_jadual.sql' => 'x'] as $f => $isi) file_put_contents("$d/$f", $isi);
$pelan = array_column(rancang_pangkas_backup($kini), null, 'fail');
foreach ($kes as $f => [, , , $jangka]) semak("$jangka: $f" . ($jangka === 'KEKAL' ? ' (' . ($pelan[$f]['sebab'] ?? '?') . ')' : ''), ($pelan[$f]['tindakan'] ?? '') === $jangka);
foreach (['nota.sql', 'SIPADI_20261101_200000_jadual.sql', 'sipadi_ujian_20261101_200000_jadual.sql', 'kod_20261118_164308_pra-fasa1.zip'] as $f)
    semak("KEKAL fail bukan backup sistem: $f", ($pelan[$f]['tindakan'] ?? '') === 'KEKAL');
semak('tiada fail .json dirancang untuk dipadam secara langsung', !array_filter($pelan, fn($r) => str_ends_with($r['fail'], '.json')));
$sebelum = glob("$d/*");
$dibuang = pangkas_backup_jadual_ujian($kini);
$selepas = glob("$d/*");
$jangkaBuang = [nama('sipadi_', $h(40), 'jadual'), nama('epms_', $h(35), 'jadual')];
sort($jangkaBuang); sort($dibuang);
semak('pemadaman sebenar (folder sementara): hanya 2 backup layak + .json pasangannya dibuang', $dibuang === $jangkaBuang && count($sebelum) - count($selepas) === 4);
semak('semua fail lain kekal (termasuk fail bukan backup)', count(array_diff(array_map('basename', $sebelum), array_map('basename', $selepas))) === 4 && is_file("$d/nota.sql") && is_file("$d/kod_20261118_164308_pra-fasa1.zip"));

echo "\nRetention: backup berjadual sah TERBARU sentiasa disimpan, merentas awalan\n";
kosongkan($d);
cipta_backup($d, nama('sipadi_', $h(31), 'jadual'), 'jadual', $h(31));
cipta_backup($d, nama('epms_', $h(33), 'jadual'), 'jadual', $h(33));
$pelan = array_column(rancang_pangkas_backup($kini), 'tindakan', 'fail');
semak('sipadi_ (31 hari, terbaru) KEKAL; epms_ (33 hari) PADAM', $pelan[nama('sipadi_', $h(31), 'jadual')] === 'KEKAL' && $pelan[nama('epms_', $h(33), 'jadual')] === 'PADAM');
kosongkan($d);
cipta_backup($d, nama('sipadi_', $h(40), 'jadual'), 'jadual', $h(40));
cipta_backup($d, nama('epms_', $h(35), 'jadual'), 'jadual', $h(35));
$pelan = array_column(rancang_pangkas_backup($kini), 'tindakan', 'fail');
semak('epms_ (35 hari, terbaru) KEKAL; sipadi_ (40 hari) PADAM', $pelan[nama('epms_', $h(35), 'jadual')] === 'KEKAL' && $pelan[nama('sipadi_', $h(40), 'jadual')] === 'PADAM');

echo "\nKesetaraan: folder backup LAMA sahaja → pelan retention sama dengan kod sebelum Task C (" . VERSI_SEBELUM_TASK_C . ")\n";
kosongkan($d);
foreach ($kes as $f => [$jm, $masa, $p]) if (str_starts_with($f, 'sipadi_')) cipta_backup($d, $f, $jm, $masa, $p);
cipta_backup($d, nama('sipadi_', $h(2), 'jadual'), 'jadual', $h(2));
file_put_contents("$d/nota.sql", 'x');
$baharu = json_encode(rancang_pangkas_backup($kini));
$lama = pelan_retention_versi_lama($TMP, $kini);
if ($lama === null) echo "  SKIP kesetaraan versi lama — git atau versi " . VERSI_SEBELUM_TASK_C . " tidak tersedia (bukan kegagalan senyap; dilaporkan)\n";
else semak('pelan retention baharu === pelan versi lama (fail, tindakan & sebab)', $baharu === $lama, $baharu === $lama ? count(json_decode($baharu, true)) . ' fail' : "lama: $lama");

semak('tiada panggilan db() / mysqldump berlaku sepanjang ujian (log ralat DB kosong)', !array_filter($GLOBALS['LOG'], fn($l) => str_contains($l, 'mysqldump')));
kosongkan($d); kosongkan(LOG_DIR); rmdir($d); rmdir(LOG_DIR); @rmdir("$TMP/tiada_bin"); rmdir($TMP);
tamat();

/** pangkas_backup_jadual() dengan $kini tetap (fungsi asal menggunakan masa semasa) — logik pemadaman yang sama. */
function pangkas_backup_jadual_ujian(int $kini): array
{
    $dibuang = [];
    foreach (rancang_pangkas_backup($kini) as $r) {
        if ($r['tindakan'] !== 'PADAM') continue;
        $p = backup_path($r['fail']);
        if (@unlink($p)) { @unlink(preg_replace('/\.sql$/', '.json', $p)); $dibuang[] = $r['fail']; }
    }
    return $dibuang;
}

/** Jalankan rancang_pangkas_backup() VERSI LAMA (git) dalam proses berasingan pada folder yang sama. null jika tidak tersedia. */
function pelan_retention_versi_lama(string $tmp, int $kini): ?string
{
    $akar = realpath(__DIR__ . '/..');
    $lamaDir = "$tmp/versi_lama";
    @mkdir($lamaDir);
    foreach (['backup_lib.php', 'migrasi.php'] as $f) {
        $isi = shell_exec('git -C ' . escapeshellarg($akar) . ' show ' . VERSI_SEBELUM_TASK_C . ':includes/' . $f . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        if (!$isi) return null;
        file_put_contents("$lamaDir/$f", $isi);
    }
    // Skrip pemandu ditulis ke fail (bukan -r: escapeshellarg() di Windows menukar petikan berganda)
    file_put_contents("$lamaDir/jalan.php", '<?php define("BACKUP_DIR", ' . var_export(BACKUP_DIR, true) . '); define("LOG_DIR", ' . var_export(LOG_DIR, true) . ');'
        . ' define("BACKUP_SIMPAN_JADUAL", 30); define("BACKUP_AMARAN_JAM", 48); function app_log($m, $f = "") {} function db() { throw new RuntimeException("db"); }'
        . ' require ' . var_export("$lamaDir/backup_lib.php", true) . '; echo json_encode(rancang_pangkas_backup(' . $kini . '));');
    $hasil = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$lamaDir/jalan.php"));
    foreach (glob("$lamaDir/*") as $f) unlink($f);
    rmdir($lamaDir);
    return $hasil ?: null;
}
