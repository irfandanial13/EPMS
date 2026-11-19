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

// 1. Sudah dikunci
if (is_file($lock)) {
    papar('<h2>Pemasang dikunci</h2><p>' . APP_NAME . ' telah dipasang. Pemasang tidak boleh dijalankan semula.</p>'
        . '<p><a href="index.php">Ke halaman utama</a></p>', 403);
}

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
    foreach (['schema.sql', 'data.sql'] as $f) {
        // Hormati DB_NAME dalam config.php (fail SQL menggunakan nama 'sipadi')
        $sql = str_replace(['CREATE DATABASE IF NOT EXISTS sipadi ', 'USE sipadi;'],
                           ['CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` ', 'USE `' . DB_NAME . '`;'],
                           file_get_contents(__DIR__ . '/database/' . $f));
        // query() + nextRowset() supaya ralat dalam mana-mana pernyataan dikesan (exec() boleh terlepas ralat)
        $st = $pdo->query($sql);
        while ($st->nextRowset()) { /* teruskan ke pernyataan seterusnya */ }
        $st->closeCursor();
    }
    $pdo->exec('USE `' . DB_NAME . '`');
    pastikan_skema($pdo);

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
} catch (Throwable $e) {
    ralat_umum($e);
}
