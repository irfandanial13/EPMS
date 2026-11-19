<?php
/**
 * Jalankan migrasi skema EPMS (baris arahan sahaja).
 *   C:\xampp\php\php.exe scripts\migrasi_cli.php
 * Disyorkan: buat backup dahulu (scripts\backup_epms.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI sahaja'); }

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/migrasi.php';

$langkah = pastikan_skema(db());
echo $langkah ? implode("\n", $langkah) . "\n" : "Skema sudah terkini.\n";
$ditanda = tanda_katalaluan_lalai(db());
if ($ditanda) echo 'Akaun ditanda wajib tukar kata laluan: ' . implode(', ', $ditanda) . "\n";
app_log('Migrasi dijalankan: ' . (implode('; ', $langkah) ?: 'tiada perubahan') . ($ditanda ? '; wajib_tukar: ' . implode(',', $ditanda) : ''));
