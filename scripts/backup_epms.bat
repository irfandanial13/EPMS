@echo off
REM ===================================================================
REM  Backup berjadual EPMS (dijalankan oleh tugas "EPMS_Daily_Backup")
REM  Jalankan secara manual: klik dua kali fail ini
REM
REM  Jadual yang diluluskan: setiap hari 20:00, akaun Windows semasa,
REM  hanya apabila pengguna log masuk, jalankan segera jika jadual terlepas.
REM  Daftar / kemas kini tugas (PowerShell, akaun pengguna biasa):
REM
REM    powershell -ExecutionPolicy Bypass -File scripts\daftar_backup_jadual.ps1
REM
REM  Semak:   schtasks /Query /TN "EPMS_Daily_Backup" /V /FO LIST
REM  Uji:     schtasks /Run   /TN "EPMS_Daily_Backup"
REM  Simulasi retention (tiada pemadaman):
REM           C:\xampp\php\php.exe scripts\backup_epms.php --simulasi
REM
REM  Nota: MySQL (XAMPP) mesti sedang berjalan pada waktu jadual.
REM  Keputusan dicatat dalam storage\logs\epms_backup.log dan backup.log
REM  (log lama sebelum Task 1: storage\logs\backup_jadual.log, disimpan sebagai sejarah)
REM ===================================================================

set PHP_EXE=C:\xampp\php\php.exe
cd /d "%~dp0.."

"%PHP_EXE%" scripts\backup_epms.php >> storage\logs\epms_backup.log 2>&1
if errorlevel 2 (
    echo Backup dilangkau: backup lain sedang berjalan.
    exit /b 2
)
if errorlevel 1 (
    echo Backup GAGAL. Lihat storage\logs\epms_backup.log
    exit /b 1
)
echo Backup berjaya.
exit /b 0
