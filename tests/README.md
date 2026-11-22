# Ujian EPMS

Folder ini disekat daripada pelayar (`tests/.htaccess`). Jalankan melalui CLI sahaja.

## Suite tanpa pangkalan data

```
C:\xampp\php\php.exe tests\jalan_ujian.php
```

| Suite | Kandungan |
|---|---|
| `ujian_pengawal_db.php` | Pengawal DB ujian: `EPMS_UJIAN_DB` wajib, corak nama, penolakan `epms` / `sipadi` / `DB_NAME` produksi / akaun `root`, pengesahan `SELECT DATABASE()`, import SQL, corak GRANT |
| `ujian_backup.php` | Awalan backup `epms_` (baharu) + `sipadi_` (lama): nama, senarai, metadata, muat turun, restore (sehingga sebelum operasi DB), retention, kesetaraan dengan versi sebelum Task C |
| `ujian_sql_selamat.php` | Pemeriksa fail SQL (`includes/sql_selamat.php`) dan `install.php` hujung-ke-hujung dalam salinan sementara dengan config palsu (port 1, tiada pangkalan data) |

Suite ini tidak memuatkan `config.php` produksi dan tidak menyambung ke MariaDB. Fail sementara dicipta dalam folder temp sistem dan dibuang selepas ujian.

## Konvensyen nama pangkalan data

| Kegunaan | Nama |
|---|---|
| Produksi | `epms` (sasaran; pemasangan semasa masih `sipadi`) |
| Ujian | `epms_ujian_<suite>` — cth `epms_ujian_f42`, `epms_ujian_f43`, `epms_ujian_http`, `epms_ujian_migrasi` |
| Pembangunan (pilihan) | `epms_dev` |
| Ujian sementara | `epms_ujian_tmp_<pengenal>` |

## Peraturan untuk ujian yang menyentuh pangkalan data

Setiap ujian sedemikian **mesti** menggunakan `tests/lib/pengawal_db.php`:

- `EPMS_UJIAN_DB` wajib ditetapkan — **tiada nilai lalai**. Nama mesti sepadan `^epms_ujian_[a-z0-9_]+$`.
- Ditolak walaupun sepadan corak: `epms`, `sipadi`, dan `DB_NAME` produksi (dibaca sebagai teks daripada `config.php`; fail itu tidak dimuatkan).
- Akaun daripada `EPMS_UJIAN_USER` / `EPMS_UJIAN_PASS` (hos/port: `EPMS_UJIAN_HOST`, `EPMS_UJIAN_PORT`). Akaun `root` ditolak.
- `ujian_sambung()` mengesahkan `SELECT DATABASE()` selepas sambungan; `ujian_jalan()` dan `ujian_import_sql()` mengesahkannya sebelum dan selepas setiap import / migrasi / operasi pengubah.
- Fail SQL disemak dengan pemeriksa pemasang sebelum import (tiada `USE`, `CREATE/DROP/ALTER DATABASE`, atau `nama_db.jadual`).

```php
require __DIR__ . '/lib/pengawal_db.php';
$db  = ujian_nama_db();                 // daripada EPMS_UJIAN_DB, atau UjianDitolak
$pdo = ujian_sambung($db);              // semakan nama & akaun SEBELUM sambungan; SELECT DATABASE() selepas
ujian_import_sql($pdo, $failSql, $db);  // disemak & disahkan sebelum/selepas
ujian_jalan($pdo, $db, 'migrasi', fn(PDO $p) => pastikan_skema($p));
```

## Akaun MySQL ujian (disyorkan — BELUM dicipta)

Pentadbir pelayan (root) mencipta sekali, selepas kelulusan pemilik:

```sql
CREATE USER 'epms_ujian'@'localhost' IDENTIFIED BY '<kata-laluan-kuat>';
GRANT ALL PRIVILEGES ON `epms\_ujian\_%`.* TO 'epms_ujian'@'localhost';
```

- Garis bawah **mesti** dilepaskan (`\_`): tanpanya `_` ialah kad bebas satu aksara.
- Jangan gunakan corak luas seperti `` `epms%` `` — ia merangkumi pangkalan data produksi `epms`.
- Akaun ini tiada akses ke `sipadi` atau `epms`: arahan `USE sipadi` yang terlepas pun akan ditolak oleh pelayan.
- `CREATE DATABASE epms_ujian_*` dibenarkan oleh corak ini, jadi ujian boleh mencipta/memadam DB ujiannya sendiri.

## Suite regresi yang memerlukan pangkalan data

Suite regresi Fasa 2, Fasa 3 (HTTP & pelayar) dan Fasa 4.1/4.2/4.3 (pustaka, HTTP, pelayar, migrasi/backup) dijalankan sebelum ini di luar repositori menggunakan pangkalan data `sipadi_ujian_*` dan akaun `root`. Ia **belum** dipindahkan ke folder ini dan **tidak boleh dijalankan** sehingga:

1. akaun `epms_ujian` di atas dicipta (kelulusan pemilik diperlukan);
2. setiap suite ditukar kepada nama `epms_ujian_<suite>` dan menggunakan `pengawal_db.php`;
3. ujian HTTP/pelayar memulakan pelayan ujian sendiri (`127.0.0.1:<port ujian>`, `EPMS_UJIAN_DB` ditetapkan) dan membuktikan pangkalan data pelayan dengan rekod penanda sebelum sebarang POST.
