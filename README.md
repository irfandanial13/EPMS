# EPMS — Estet Padi Management System

Sistem web (PHP + MySQL) berdasarkan fail Excel
`1. PENYALURAN PPSB (IKUT PPK) SEHINGGA FASA 2 TAHUN 2026.xlsx`.

> Dahulu dikenali sebagai **SIPADI**. Nama pangkalan data (`sipadi`), folder URL (`/SIPADI/`), cookie sesi dan nama fail backup (`sipadi_*.sql`) dikekalkan supaya integrasi dan backup sedia ada tidak terjejas. Logo rasmi: `assets/images/epms-logo.png`.

## Pemasangan baharu (XAMPP)

1. Hidupkan **Apache** dan **MySQL** di XAMPP Control Panel. Pastikan `extension=zip` aktif dalam `C:\xampp\php\php.ini` (diperlukan untuk eksport Excel). Restart Apache selepas mengubah php.ini.
2. Jalankan `C:\xampp\php\php.exe install.php` dari folder projek. Akses pemasang melalui pelayar disekat oleh `.htaccess` (sejak 19/11/2026).
3. Salin **kata laluan sementara** admin yang dipaparkan. Ia dipaparkan sekali sahaja.
4. Log masuk sebagai `admin`. Sistem akan memaksa anda menukar kata laluan.

Keselamatan pemasang:
- Pemasang **tidak akan** memadam atau menulis ganti pangkalan data yang sudah berisi.
- Selepas pemasangan, fail `storage/install.lock` dicipta dan pemasang dikunci.
- Untuk memasang semula, pentadbir pelayan perlu (1) membuat backup, (2) mengosongkan pangkalan data secara manual, dan (3) memadam `storage/install.lock`.

## Naik taraf sistem sedia ada

Selepas mengemas kini kod, buat backup dan jalankan migrasi skema:

```
C:\xampp\php\php.exe scripts\backup_epms.php
C:\xampp\php\php.exe scripts\migrasi_cli.php
```

Migrasi bersifat idempotent, jadi selamat dijalankan berulang kali. Ia tidak mengubah rekod penyaluran.

## Modul

| Modul | Fungsi |
|---|---|
| Dashboard | KPI dan carta ikut wilayah, model, musim, serta 10 PPK teratas (Kumulatif / Semasa) |
| Rekod Penyaluran | Senarai, penapis, tambah, edit, padam, eksport Excel |
| Laporan | Ringkasan PPK & Model, Modal Pusingan, Keluasan, Ikut Musim. Eksport .xlsx atau cetak/PDF |
| PPK | Senarai PPK ikut wilayah |
| Pengguna | Akaun admin dan tukar kata laluan |
| Backup | Backup manual, senarai backup, muat turun, restore dengan semakan integriti |

## Keselamatan

| Ciri | Tetapan (`config.php`) |
|---|---|
| Cookie sesi HttpOnly, SameSite=Lax, Secure apabila HTTPS, strict mode | automatik |
| Log keluar automatik selepas tidak aktif | `SESSION_IDLE_MINIT` (lalai 30) |
| Kunci log masuk selepas cubaan gagal | `LOGIN_MAKS_GAGAL` (5), `LOGIN_KUNCI_MINIT` (15) |
| Log keluar melalui POST + CSRF | — |
| Paksa tukar kata laluan (akaun lalai / akaun baharu) | medan `pengguna.wajib_tukar` |
| Kata laluan: min 8 aksara, huruf dan nombor, bukan nama pengguna | — |
| Ralat teknikal tidak dipaparkan; pengguna melihat nombor rujukan | log dalam `storage/logs/` |

Fail log dalam `storage/logs/`:
- `error.log`: ralat teknikal beserta nombor rujukan
- `auth.log`: log masuk, log keluar dan pertukaran kata laluan (kata laluan tidak pernah dilog)
- `backup.log`: backup, muat turun dan restore
- `epms_backup.log`: keputusan backup berjadual (sejarah sebelum 19/11/2026: `backup_jadual.log`)

Folder `storage/`, `database/` dan `scripts/` disekat daripada akses pelayar melalui `.htaccess`. Ini memerlukan `AllowOverride All`, iaitu tetapan lalai XAMPP untuk htdocs.

### Konfigurasi pangkalan data yang lebih selamat (disyorkan)

XAMPP secara lalai menggunakan `root` tanpa kata laluan. Untuk pelayan yang boleh dicapai oleh komputer lain:

1. Cipta akaun MySQL khas melalui phpMyAdmin → SQL, atau `C:\xampp\mysql\bin\mysql.exe -u root`:
   ```sql
   CREATE USER 'sipadi_app'@'localhost' IDENTIFIED BY '<kata-laluan-kuat>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, LOCK TABLES, TRIGGER
         ON sipadi.* TO 'sipadi_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
   Keistimewaan `CREATE/DROP/ALTER/LOCK TABLES` diperlukan untuk migrasi, backup dan restore.
2. Kemas kini `DB_USER` dan `DB_PASS` dalam `config.php`.
3. Tetapkan kata laluan untuk `root` MySQL, dan kemas kini `C:\xampp\phpMyAdmin\config.inc.php` jika phpMyAdmin digunakan.
4. Hadkan akses: jangan dedahkan port 3306 ke rangkaian, dan sekat phpMyAdmin kepada localhost sahaja.
5. Gunakan HTTPS jika sistem dicapai melalui rangkaian. Cookie Secure diaktifkan secara automatik apabila HTTPS digunakan.

## Backup & Restore

**Manual:** menu *Backup* → *Backup Sekarang*.
- Fail disimpan dalam `storage/backups/` sebagai `.sql` dengan metadata `.json` (tarikh, jenis, pengguna, saiz, SHA-256, bilangan rekod setiap jadual).
- Muat turun hanya melalui sistem, selepas log masuk.

**Restore:** klik ikon pulih pada backup → taip `PULIHKAN` → masukkan kata laluan anda. Sistem akan:
1. menyemak integriti fail (SHA-256 dan penanda lengkap mysqldump);
2. membuat backup automatik keadaan semasa (*pra-pulih*), supaya restore boleh diundur;
3. memulihkan pangkalan data;
4. menjalankan migrasi skema (untuk backup lama);
5. membandingkan bilangan rekod setiap jadual dengan metadata, dan memaparkan keputusan LULUS/GAGAL.

**Berjadual (Windows Task Scheduler)** — tetapan diluluskan pemilik:

| Perkara | Tetapan |
|---|---|
| Tugas | `EPMS_Daily_Backup` → `scripts\backup_epms.bat` (working directory: folder projek). Dahulu `SIPADI_Daily_Backup` / `backup_sipadi.bat` (diganti 19/11/2026) |
| Nama fail backup | Kekal `sipadi_*.sql` + `.json` (ikut nama DB; dikenali oleh senarai, restore dan retention) |
| Jadual | Setiap hari 20:00 (waktu Windows UTC+08:00, Asia/Kuala_Lumpur) |
| Akaun | Akaun Windows semasa, hanya apabila pengguna log masuk (tiada kata laluan disimpan) |
| Jadual terlepas | Dijalankan secepat mungkin apabila komputer tersedia |
| Bertindih | Tidak dibenarkan (Task Scheduler `IgnoreNew` + kunci fail dalam `backup_epms.php`) |
| Retention | `BACKUP_SIMPAN_JADUAL = 30` **hari**, backup berjadual sahaja |
| Amaran | `BACKUP_AMARAN_JAM = 48` |

Daftar atau kemas kini tugas (PowerShell, akaun pengguna biasa):

```
powershell -ExecutionPolicy Bypass -File scripts\daftar_backup_jadual.ps1
schtasks /Query /TN "EPMS_Daily_Backup" /V /FO LIST
schtasks /Run   /TN "EPMS_Daily_Backup"
```

Retention (dijalankan selepas setiap backup berjadual yang berjaya):
- Hanya backup **berjadual** yang lebih tua daripada 30 hari dipadam (fail `.sql` + `.json` pasangannya).
- Backup manual, pra-pulih dan pra-fasa tidak pernah dipadam.
- Fail tidak dipadam jika tarikhnya tidak dapat disahkan (nama fail dan metadata mesti sepadan), jika metadata tiada, atau jika fail gagal semakan integriti.
- Backup berjadual sah yang terbaru sentiasa disimpan.
- Setiap pemadaman dan sebabnya dicatat dalam `storage/logs/backup.log`.
- Simulasi tanpa pemadaman: `C:\xampp\php\php.exe scripts\backup_epms.php --simulasi`

Nota:
- MySQL mesti sedang berjalan pada waktu jadual.
- Salin backup penting secara berkala ke lokasi lain (cakera luaran atau pelayan lain).

## Definisi skop (sama seperti Excel)

- **Kumulatif** = *JUMLAH ASAL*: semua rekod asal, termasuk yang telah tamat.
- **Semasa** = *JUMLAH SEMASA*: rekod AKTIF, iaitu asal tolak tamat ditambah sambung perjanjian.

## Model

SP Sewaan/Pajakan · BP Bayaran Pengurusan · PK Perkongsian Keuntungan · TP Tanah PPK ·
LKPPK Ladang Kontrak PPK · LKSLB Ladang Kontrak Skim Ladang Benih · EP Entiti Pengurusan

## Fail

```
config.php              tetapan DB, keselamatan, backup
install.php             pemasang (dikunci selepas pemasangan)
login.php, logout.php, tukar_katalaluan.php
index.php               dashboard
penyaluran.php          senarai rekod + eksport
penyaluran_form.php     tambah/edit rekod
laporan.php             laporan + eksport
ppk.php, pengguna.php, backup.php
includes/               bootstrap (teras + keselamatan), header/footer, laporan_lib, xlsx,
                        migrasi (skema), backup_lib (backup/restore)
scripts/                backup_epms.php, backup_epms.bat, daftar_backup_jadual.ps1, migrasi_cli.php   (CLI sahaja)
database/               schema.sql, data.sql, import_excel.py
storage/                backups/, logs/, install.lock   (tidak boleh dicapai melalui pelayar)
assets/app.css
```

Nota: Bootstrap dan Chart.js dimuatkan dari CDN (jsdelivr), jadi pelayar memerlukan sambungan internet.
