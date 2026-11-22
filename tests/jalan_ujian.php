<?php
/**
 * Jalankan semua suite ujian TANPA pangkalan data dalam tests/ (setiap suite dalam proses PHP berasingan).
 *   C:\xampp\php\php.exe tests\jalan_ujian.php
 * Suite yang memerlukan pangkalan data TIDAK dijalankan di sini — lihat tests/README.md.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }

$suite = ['ujian_pengawal_db.php', 'ujian_backup.php', 'ujian_sql_selamat.php', 'ujian_penyaluran_lib.php'];
$gagal = 0;
foreach ($suite as $s) {
    echo "=== $s\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$s"), $kod);
    echo "=== $s: " . ($kod === 0 ? 'LULUS' : "GAGAL (kod $kod)") . "\n\n";
    $gagal += $kod === 0 ? 0 : 1;
}
echo $gagal ? "$gagal suite GAGAL\n" : 'Semua ' . count($suite) . " suite LULUS\n";
exit($gagal ? 1 : 0);
