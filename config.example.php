<?php
// TEMPLAT config.php (Fasa 1.5 Task 4). Salin kepada config.php dan isi kelayakan sebenar.
// config.php TIDAK dijejak oleh Git kerana mengandungi kelayakan pangkalan data.
// Untuk keselamatan, gunakan akaun MySQL khas (bukan root) dengan kata laluan. Lihat README.md.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
// Nama pangkalan data. Konvensyen: produksi 'epms'; ujian 'epms_ujian_<suite>' (lihat tests/README.md).
// PENTING: pemasangan produksi semasa masih menggunakan 'sipadi' dalam config.php sebenar — JANGAN ubah
// config.php sebenar kepada 'epms' sehingga migrasi pangkalan data diluluskan dan dilaksanakan (tugas berasingan).
define('DB_NAME', 'epms');
define('DB_USER', 'GANTI_NAMA_PENGGUNA_DB');
define('DB_PASS', 'GANTI_KATA_LALUAN_DB');

define('APP_NAME', 'EPMS');
define('APP_TITLE', 'Estet Padi Management System');
define('APP_LOGO', 'assets/images/epms-logo.png');   // logo rasmi (fail asal, jangan diubah)
// Nota: pengecam dalaman lama (kelas CSS .btn-sipadi, cookie sesi SIPADISESS) dikekalkan — bukan paparan identiti.
// Backup lama 'sipadi_*' kekal diterima; backup baharu menggunakan BACKUP_AWALAN di bawah.

// Keselamatan
define('SESSION_IDLE_MINIT', 30);   // log keluar automatik selepas tidak aktif (minit)
define('LOGIN_MAKS_GAGAL', 5);      // bilangan cubaan gagal sebelum dikunci
define('LOGIN_KUNCI_MINIT', 15);    // tempoh kunci selepas melebihi had (minit)

// Storan (dilindungi oleh storage/.htaccess)
define('STORAGE_DIR', __DIR__ . '/storage');
define('BACKUP_DIR', STORAGE_DIR . '/backups');
define('LOG_DIR', STORAGE_DIR . '/logs');

// Backup
define('MYSQL_BIN_DIR', 'C:/xampp/mysql/bin');   // lokasi mysqldump.exe dan mysql.exe
define('BACKUP_SIMPAN_JADUAL', 30);              // HARI: backup berjadual lebih lama daripada ini dibuang (0 = simpan semua). Backup manual tidak dibuang.
define('BACKUP_AMARAN_JAM', 48);                 // amaran di halaman Backup jika tiada backup dalam tempoh ini (jam)
// define('BACKUP_AWALAN', 'epms_');             // pilihan: awalan nama fail backup baharu (lalai 'epms_'; 'sipadi_' lama sentiasa diterima)
// Jadual Task Scheduler yang diluluskan: tugas "EPMS_Daily_Backup" (dahulu SIPADI_Daily_Backup), setiap hari 20:00 (scripts/daftar_backup_jadual.ps1)
