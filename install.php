<?php
/**
 * Pemasang EPMS (pemasangan baharu sahaja).
 *
 *   C:\xampp\php\php.exe install.php      atau   http://localhost/SIPADI/install.php
 *
 * Keselamatan:
 *  - TIDAK akan memadam atau menulis ganti pangkalan data yang sudah mempunyai jadual.
 *  - Selepas pemasangan (atau jika sistem sudah dipasang), fail storage/install.lock dicipta
 *    dan pemasang dikunci. Untuk memasang semula, pentadbir pelayan perlu memadam fail itu
 *    secara manual DAN mengosongkan pangkalan data sendiri (selepas backup).
 *  - Akaun admin diberi kata laluan sementara rawak (dipaparkan sekali sahaja) dan wajib ditukar.
 *  - Pangkalan data dipilih HANYA oleh pemasang (DB_NAME). Fail SQL yang mencipta/memilih/mengubah pangkalan
 *    data atau merujuk pangkalan data lain ditolak sebelum sebarang sambungan; SELECT DATABASE() disahkan
 *    sebelum dan selepas setiap import.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/migrasi.php';

$isCli = PHP_SAPI === 'cli';
$lock = STORAGE_DIR . '/install.lock';

function papar(string $html, int $kod = 200): void
{
    if (PHP_SAPI === 'cli') { echo strip_tags(str_replace(['<br>', '</p>'], "\n", $html)), "\n"; exit($kod >= 400 ? 1 : 0); }
    http_response_code($kod);
    echo '<!doctype html><meta charset="utf-8"><title>Pasang ' . APP_NAME . '</title>'
        . '<div style="font-family:sans-serif;max-width:600px;margin:60px auto">' . $html . '</div>';
    exit;
}

function kunci(string $lock, string $sebab): void
{
    @file_put_contents($lock, date('Y-m-d H:i:s') . " $sebab\n");
}

/* ---------- Keselamatan import SQL ----------
 * Pangkalan data sasaran dipilih HANYA oleh pemasang (DB_NAME dalam config.php). Fail SQL tidak boleh
 * mencipta, memilih, mengubah atau memadam pangkalan data, atau merujuk jadual dalam pangkalan data lain.
 * Pangkalan data terpilih disahkan dengan SELECT DATABASE() sebelum dan selepas setiap import.
 */

/** Pemasangan dihentikan atas sebab keselamatan (mesej selamat dipaparkan kepada pentadbir). */
class InstallDihentikan extends RuntimeException {}

/** Nama pangkalan data yang dibenarkan untuk dipetik dengan backtick (tiada aksara khas). */
function nama_db_sah(string $db): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $db);
}

/**
 * Kod SQL sahaja: literal rentetan ('…', "…") dan komen biasa (-- , #, /* … *\/) dibuang supaya kata kunci
 * di dalamnya tidak dikira. Komen boleh-laksana MySQL (/*!NNNNN … *\/) DIKEKALKAN kerana pelayan melaksanakannya.
 */
function sql_kod_sahaja(string $sql): string
{
    $out = '';
    $n = strlen($sql);
    $bolehLaksana = 0;
    for ($i = 0; $i < $n;) {
        $c = $sql[$i];
        if ($c === "'" || $c === '"') {                                    // literal rentetan → diganti ''
            for ($i++; $i < $n; $i++) {
                if ($sql[$i] === '\\') { $i++; continue; }
                if ($sql[$i] === $c) { if (($sql[$i + 1] ?? '') === $c) { $i++; continue; } $i++; break; }
            }
            $out .= " '' ";
            continue;
        }
        if ($c === '`') {                                                  // pengecam dipetik → dikekalkan
            $j = strpos($sql, '`', $i + 1);
            $j = $j === false ? $n - 1 : $j;
            $out .= substr($sql, $i, $j - $i + 1);
            $i = $j + 1;
            continue;
        }
        if ($c === '#' || ($c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2])))) {
            $j = strpos($sql, "\n", $i);                                   // komen baris
            $i = $j === false ? $n : $j;
            $out .= ' ';
            continue;
        }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            if (($sql[$i + 2] ?? '') === '!') {                            // komen boleh-laksana: kandungan ialah kod
                for ($i += 3; $i < $n && ctype_digit($sql[$i]); $i++);
                $bolehLaksana++;
                $out .= ' ';
                continue;
            }
            $j = strpos($sql, '*/', $i + 2);                               // komen blok biasa
            $i = $j === false ? $n : $j + 2;
            $out .= ' ';
            continue;
        }
        if ($bolehLaksana && $c === '*' && ($sql[$i + 1] ?? '') === '/') { $bolehLaksana--; $i += 2; $out .= ' '; continue; }
        $out .= $c;
        $i++;
    }
    return $out;
}

/**
 * Semak fail SQL untuk import. @return ?string sebab ditolak, atau null jika selamat.
 * Ditolak: USE / CONNECT / \u (menukar pangkalan data), CREATE|DROP|ALTER DATABASE|SCHEMA,
 * dan rujukan jadual dengan nama pangkalan data (db.jadual) yang memintas pangkalan data terpilih.
 */
function sql_arahan_dilarang(string $sql): ?string
{
    $kod = sql_kod_sahaja($sql);
    foreach (explode(';', $kod) as $p) {
        $p = ltrim($p);
        if (preg_match('/^(USE|CONNECT)\b/i', $p) || str_starts_with($p, '\\u')) {
            return 'mengandungi arahan menukar pangkalan data (' . strtoupper(strtok($p, " \t\r\n`")) . ').';
        }
        if (preg_match('/^(CREATE(\s+OR\s+REPLACE)?|DROP|ALTER)\s+(DATABASE|SCHEMA)\b/i', $p, $m)) {
            return 'mengandungi arahan ' . strtoupper(preg_replace('/\s+/', ' ', $m[0])) . '.';
        }
    }
    if (preg_match('/\b(INTO|TABLE|UPDATE|FROM|JOIN|REFERENCES|EXISTS)\s+(`[^`]*`|[A-Za-z_]\w*)\s*\.\s*(`|[A-Za-z_])/i', $kod)) {
        return 'merujuk jadual dalam pangkalan data lain (nama_db.jadual).';
    }
    return null;
}

/** Pastikan sambungan sedang menggunakan pangkalan data $db; jika tidak, hentikan pemasangan. */
function pastikan_db_terpilih(PDO $pdo, string $db, string $peringkat): void
{
    $semasa = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($semasa !== $db) {
        throw new InstallDihentikan("Pangkalan data terpilih $peringkat ialah '" . ($semasa ?? 'tiada') . "', bukan '$db' (DB_NAME). Pemasangan dihentikan.");
    }
}

/** Import satu fail SQL ke pangkalan data $db yang SUDAH dipilih: semak kandungan, sahkan DB sebelum & selepas. */
function import_fail_sql(PDO $pdo, string $fail, string $db): void
{
    $nama = basename($fail);
    $sql = is_file($fail) ? file_get_contents($fail) : false;
    if ($sql === false) throw new InstallDihentikan("Fail $nama tidak dijumpai.");
    $sebab = sql_arahan_dilarang($sql);
    if ($sebab !== null) throw new InstallDihentikan("Fail $nama ditolak: $sebab");
    pastikan_db_terpilih($pdo, $db, "sebelum import $nama");
    // query() + nextRowset() supaya ralat dalam mana-mana pernyataan dikesan (exec() boleh terlepas ralat)
    $st = $pdo->query($sql);
    while ($st->nextRowset()) { /* teruskan ke pernyataan seterusnya */ }
    $st->closeCursor();
    pastikan_db_terpilih($pdo, $db, "selepas import $nama");
}

$failSql = [__DIR__ . '/database/schema.sql', __DIR__ . '/database/data.sql'];

// 1. Sudah dikunci
if (is_file($lock)) {
    papar('<h2>Pemasang dikunci</h2><p>' . APP_NAME . ' telah dipasang. Pemasang tidak boleh dijalankan semula.</p>'
        . '<p><a href="index.php">Ke halaman utama</a></p>', 403);
}

// 1b. Semakan keselamatan SEBELUM sebarang sambungan: nama DB_NAME dan kandungan fail SQL
if (!nama_db_sah((string)DB_NAME)) {
    app_log('install: dihentikan — DB_NAME tidak sah (hanya huruf, nombor dan _ dibenarkan)', 'error.log');
    papar('<h2>Pemasangan dihentikan</h2><p>DB_NAME dalam config.php tidak sah. Hanya huruf, nombor dan garis bawah (_) dibenarkan.</p>', 500);
}
foreach ($failSql as $f) {
    $sql = is_file($f) ? file_get_contents($f) : false;
    $sebab = $sql === false ? 'fail tidak dijumpai.' : sql_arahan_dilarang($sql);
    if ($sebab !== null) {
        app_log('install: dihentikan — ' . basename($f) . ": $sebab", 'error.log');
        papar('<h2>Pemasangan dihentikan</h2><p>Fail <code>database/' . e(basename($f)) . '</code> ditolak: ' . e($sebab)
            . '</p><p>Fail SQL tidak boleh mencipta, memilih atau mengubah pangkalan data. Pangkalan data dipilih oleh pemasang daripada DB_NAME.</p>', 500);
    }
}
unset($sql, $sebab);

// 2. Semak keadaan pangkalan data (tanpa memilih DB dahulu)
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $st->execute([DB_NAME]);
    $bilJadual = (int)$st->fetchColumn();
} catch (Throwable $e) {
    app_log('install: sambungan gagal: ' . $e->getMessage(), 'error.log');
    papar('<h2>Ralat</h2><p>Tidak dapat menyambung ke MySQL. Pastikan MySQL berjalan dan tetapan dalam config.php betul.</p>', 500);
}

// 3. Pangkalan data sudah wujud dan berisi -> jangan sentuh, kunci pemasang
if ($bilJadual > 0) {
    kunci($lock, 'dikesan pemasangan sedia ada');
    app_log("install: dihalang — pangkalan data '" . DB_NAME . "' sudah mempunyai $bilJadual jadual; pemasang dikunci");
    papar('<h2>Sistem sudah dipasang</h2><p>Pangkalan data <code>' . e(DB_NAME) . '</code> sudah mengandungi data. '
        . 'Pemasang <b>tidak</b> mengubah apa-apa dan kini telah dikunci.</p>'
        . '<p>Untuk menaik taraf skema, jalankan <code>php scripts/migrasi_cli.php</code>.</p>', 409);
}

// 4. Pemasangan baharu: minta pengesahan (web)
if (!$isCli && ($_POST['sahkan'] ?? '') !== 'YA') {
    papar('<h2>Pemasangan ' . APP_NAME . '</h2>
    <p>Pangkalan data <code>' . e(DB_NAME) . '</code> masih kosong. Pemasang akan mencipta jadual, mengimport data
    daripada Excel dan mencipta akaun <b>admin</b> dengan kata laluan sementara.</p>
    <form method="post"><input type="hidden" name="sahkan" value="YA">
    <button style="padding:10px 18px">Teruskan pemasangan</button></form>');
}

try {
    // 5. Pemasang sendiri mencipta & memilih DB_NAME (fail SQL tidak memilih pangkalan data)
    $db = (string)DB_NAME;
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $db . '`');
    pastikan_db_terpilih($pdo, $db, 'selepas memilih pangkalan data');
    // Semak semula di bawah pangkalan data terpilih: masih kosong (perlindungan sedia ada dikekalkan)
    $bilJadual = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    if ($bilJadual > 0) throw new InstallDihentikan("Pangkalan data '$db' sudah mempunyai $bilJadual jadual. Pemasangan dihentikan tanpa perubahan.");

    // 6. Import — setiap fail disemak semula dan pangkalan data terpilih disahkan sebelum & selepas import
    foreach ($failSql as $f) import_fail_sql($pdo, $f, $db);
    pastikan_skema($pdo);
    pastikan_db_terpilih($pdo, $db, 'selepas migrasi skema');

    $temp = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12) . random_int(10, 99);
    $pdo->prepare('INSERT INTO pengguna (username, nama, password_hash, wajib_tukar) VALUES (?,?,?,1)')
        ->execute(['admin', 'Pentadbir Sistem', password_hash($temp, PASSWORD_DEFAULT)]);
    $n = $pdo->query('SELECT COUNT(*) FROM penyaluran')->fetchColumn();

    kunci($lock, 'pemasangan baharu selesai');
    app_log("install: pemasangan baharu selesai, $n rekod diimport");

    papar('<h2>Pemasangan selesai</h2>'
        . "<p>$n rekod penyaluran diimport.</p>"
        . '<p>Log masuk dengan nama pengguna <b>admin</b> dan kata laluan sementara berikut '
        . '(dipaparkan <b>sekali sahaja</b>, salin sekarang):</p>'
        . '<p style="font-size:1.3em"><code>' . e($temp) . '</code></p>'
        . '<p>Anda akan diminta menukar kata laluan semasa log masuk pertama. Pemasang kini dikunci.</p>'
        . '<p><a href="login.php">Ke halaman log masuk</a></p>');
} catch (InstallDihentikan $e) {
    app_log('install: dihentikan — ' . $e->getMessage(), 'error.log');
    papar('<h2>Pemasangan dihentikan</h2><p>' . e($e->getMessage()) . '</p><p>Pemasang tidak dikunci. Semak log dan pangkalan data sebelum mencuba semula.</p>', 500);
} catch (Throwable $e) {
    ralat_umum($e);
}
