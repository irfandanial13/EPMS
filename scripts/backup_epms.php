<?php
/**
 * Backup berjadual EPMS (baris arahan sahaja). Dipanggil oleh scripts\backup_epms.bat (tugas EPMS_Daily_Backup).
 *   C:\xampp\php\php.exe scripts\backup_epms.php              backup + retention
 *   C:\xampp\php\php.exe scripts\backup_epms.php --simulasi   dry-run retention sahaja (tiada backup, tiada pemadaman)
 * Nota: nama fail dump kekal sipadi_*.sql (ikut nama DB 'sipadi'; dikenali oleh senarai, restore dan retention).
 * Kod keluar: 0 = berjaya, 1 = gagal, 2 = dilangkau (backup lain sedang berjalan).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/backup_lib.php';

if (in_array('--simulasi', $argv, true)) {
    $pelan = rancang_pangkas_backup();
    echo 'SIMULASI retention (had ' . BACKUP_SIMPAN_JADUAL . " hari) — tiada fail dipadam\n";
    foreach ($pelan as $r) printf("  %-6s %-45s %s\n", $r['tindakan'], $r['fail'], $r['sebab']);
    $n = count(array_filter($pelan, fn($r) => $r['tindakan'] === 'PADAM'));
    echo "Jumlah: " . count($pelan) . " fail; akan dipadam: $n\n";
    exit(0);
}

// Elak pelaksanaan bertindih (cth tugas berjadual + jalankan manual serentak)
$kunci = fopen(LOG_DIR . '/backup_epms.lock', 'c');
if (!$kunci || !flock($kunci, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " DILANGKAU: backup lain sedang berjalan\n";
    app_log('Backup berjadual dilangkau: proses lain sedang berjalan', 'backup.log');
    exit(2);
}

try {
    $m = buat_backup('jadual', 'sistem', 'Backup berjadual');
    catat_status_backup('jadual', true, '', $m['fail']);
    echo date('Y-m-d H:i:s') . " OK {$m['fail']} ({$m['saiz']} bait, penyaluran: " . ($m['jadual']['penyaluran'] ?? '-') . ")\n";
} catch (Throwable $e) {
    app_log('Backup berjadual gagal: ' . $e->getMessage(), 'error.log');
    catat_status_backup('jadual', false, $e->getMessage());
    fwrite(STDERR, date('Y-m-d H:i:s') . ' GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}

// Retention hanya selepas backup baharu berjaya; ralat retention tidak menjejaskan status backup
try {
    $dibuang = pangkas_backup_jadual();
    echo '  retention: ' . count($dibuang) . ' fail dibuang (had ' . BACKUP_SIMPAN_JADUAL . " hari)\n";
    foreach ($dibuang as $f) echo "  dibuang (melebihi had simpanan): $f\n";
} catch (Throwable $e) {
    app_log('Retention gagal: ' . $e->getMessage(), 'error.log');
    echo "  retention: GAGAL (lihat error.log)\n";
}
exit(0);
