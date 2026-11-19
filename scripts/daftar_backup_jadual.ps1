# Daftar / kemas kini tugas Task Scheduler "EPMS_Daily_Backup" (tetapan diluluskan pemilik, Fasa 3.1 A5; dinamakan semula daripada SIPADI_Daily_Backup dalam Fasa 1.5 Task 1).
#   powershell -ExecutionPolicy Bypass -File scripts\daftar_backup_jadual.ps1
# - Setiap hari 20:00 (waktu tempatan Windows: UTC+08:00, Asia/Kuala_Lumpur)
# - Akaun Windows semasa, hanya apabila pengguna log masuk (tiada kata laluan disimpan)
# - Jalankan secepat mungkin jika jadual terlepas; tiada pelaksanaan bertindih; had masa 1 jam
# Tiada kata laluan DB dalam tugas: skrip membaca config.php.
$ErrorActionPreference = 'Stop'
$nama  = 'EPMS_Daily_Backup'
$lama  = 'SIPADI_Daily_Backup'   # nama lama; tidak dipadam secara automatik oleh skrip ini
$akar  = Split-Path -Parent $PSScriptRoot
$bat   = Join-Path $PSScriptRoot 'backup_epms.bat'
$akaun = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

if (-not (Test-Path $bat)) { throw "Skrip tidak dijumpai: $bat" }

$tindakan = New-ScheduledTaskAction -Execute $bat -WorkingDirectory $akar
$pencetus = New-ScheduledTaskTrigger -Daily -At '20:00'
$prinsipal = New-ScheduledTaskPrincipal -UserId $akaun -LogonType Interactive -RunLevel Limited
$tetapan  = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
              -ExecutionTimeLimit (New-TimeSpan -Hours 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$tugas = New-ScheduledTask -Action $tindakan -Trigger $pencetus -Principal $prinsipal -Settings $tetapan `
           -Description 'EPMS: backup harian pangkalan data (scripts\backup_epms.bat). Retention 30 hari untuk backup berjadual sahaja.'

Register-ScheduledTask -TaskName $nama -InputObject $tugas -Force | Out-Null
Write-Output "Tugas '$nama' didaftarkan untuk ${akaun}: setiap hari 20:00 -> $bat"

if (Get-ScheduledTask -TaskName $lama -ErrorAction SilentlyContinue) {
    Write-Warning "Tugas lama '$lama' masih wujud (pendua). Selepas mengesahkan '$nama', buang dengan: schtasks /Delete /TN `"$lama`" /F"
}
