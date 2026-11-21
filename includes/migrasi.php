<?php
/**
 * Migrasi skema EPMS (idempotent — selamat dijalankan berulang kali).
 * Dipanggil oleh: install.php, scripts/migrasi_cli.php, dan selepas restore backup
 * (supaya backup lama yang belum ada medan baharu tetap serasi dengan kod semasa).
 *
 * Tidak mengubah rekod penyaluran, PPK atau wilayah.
 *
 * @return string[] senarai langkah yang dijalankan
 */
function pastikan_skema(PDO $pdo): array
{
    $log = [];

    // Fasa 1: medan wajib_tukar (paksa tukar kata laluan)
    $ada = $pdo->query("SHOW COLUMNS FROM pengguna LIKE 'wajib_tukar'")->fetch();
    if (!$ada) {
        $pdo->exec("ALTER TABLE pengguna ADD COLUMN wajib_tukar TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash");
        $log[] = 'Tambah medan pengguna.wajib_tukar';
    }
    $ada = $pdo->query("SHOW COLUMNS FROM pengguna LIKE 'katalaluan_ditukar'")->fetch();
    if (!$ada) {
        $pdo->exec("ALTER TABLE pengguna ADD COLUMN katalaluan_ditukar DATETIME NULL AFTER wajib_tukar");
        $log[] = 'Tambah medan pengguna.katalaluan_ditukar';
    }

    // Fasa 1: rekod cubaan log masuk (untuk had cubaan gagal)
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50)  NOT NULL,
        ip         VARCHAR(45)  NOT NULL,
        berjaya    TINYINT(1)   NOT NULL DEFAULT 0,
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_masa (username, created_at),
        INDEX idx_ip_masa (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Perjanjian Fasa 1 (O-3): status ketiga SAMBUNG = perjanjian yang telah disambung.
    // Nilai ditambah di HUJUNG senarai ENUM supaya nilai sedia ada (AKTIF, TAMAT) tidak berubah.
    $kol = $pdo->query("SHOW COLUMNS FROM penyaluran LIKE 'status'")->fetch();
    if ($kol && stripos((string)$kol['Type'], "'SAMBUNG'") === false) {
        $pdo->exec("ALTER TABLE penyaluran MODIFY status ENUM('AKTIF','TAMAT','SAMBUNG') NOT NULL DEFAULT 'AKTIF'");
        $log[] = 'Tambah nilai SAMBUNG pada penyaluran.status';
    }

    // Perjanjian Fasa 1 (O-5): penanda "Perjanjian Asal Tiada Dalam Rekod".
    // Lalai 0: rekod sedia ada (termasuk SAMBUNG sejarah yang belum dipautkan) tidak berubah.
    $ada = $pdo->query("SHOW COLUMNS FROM penyaluran LIKE 'pendahulu_tiada_rekod'")->fetch();
    if (!$ada) {
        $pdo->exec("ALTER TABLE penyaluran ADD COLUMN pendahulu_tiada_rekod TINYINT(1) NOT NULL DEFAULT 0 AFTER jenis");
        $log[] = 'Tambah medan penyaluran.pendahulu_tiada_rekod';
    }

    // Perjanjian Fasa 1 (O-2, O-4): pautan sambungan → perjanjian terdahulu terkini (banyak-ke-banyak).
    // RESTRICT: rekod yang terlibat dalam pautan tidak boleh dipadam (keputusan Q2).
    $ada = $pdo->query("SHOW TABLES LIKE 'penyaluran_sambungan'")->fetch();
    if (!$ada) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS penyaluran_sambungan (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            sambungan_id     INT NOT NULL,
            pendahulu_id     INT NOT NULL,
            status_pendahulu ENUM('AKTIF','TAMAT','SAMBUNG') NOT NULL,
            dicipta_oleh     INT NULL,
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pautan (sambungan_id, pendahulu_id),
            KEY idx_pendahulu (pendahulu_id),
            CONSTRAINT fk_smb_baru FOREIGN KEY (sambungan_id) REFERENCES penyaluran(id) ON DELETE RESTRICT,
            CONSTRAINT fk_smb_lama FOREIGN KEY (pendahulu_id) REFERENCES penyaluran(id) ON DELETE RESTRICT,
            CONSTRAINT chk_smb_bukan_diri CHECK (sambungan_id <> pendahulu_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Cipta jadual penyaluran_sambungan';
    }

    return $log;
}

/**
 * Perjanjian Fasa 2 — selepas restore: buang pautan sambungan YATIM sahaja, iaitu pautan yang
 * sambungan_id ATAU pendahulu_id tidak lagi wujud dalam penyaluran. Ini berlaku apabila backup
 * lama dipulihkan (dump memadam & mencipta semula penyaluran dengan FOREIGN_KEY_CHECKS=0,
 * tetapi tidak menyentuh penyaluran_sambungan). Pautan sah tidak disentuh.
 * Salinan penuh sebelum restore kekal dalam backup automatik pra-pulih.
 *
 * @return array{bilangan:int, id:int[]} ID baris pautan yang dibuang
 */
function bersih_pautan_yatim(PDO $pdo): array
{
    if (!$pdo->query("SHOW TABLES LIKE 'penyaluran_sambungan'")->fetch()) return ['bilangan' => 0, 'id' => []];
    $ids = $pdo->query('SELECT l.id FROM penyaluran_sambungan l
            LEFT JOIN penyaluran s ON s.id = l.sambungan_id
            LEFT JOIN penyaluran p ON p.id = l.pendahulu_id
            WHERE s.id IS NULL OR p.id IS NULL ORDER BY l.id')->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $ids);
    if ($ids) {
        $st = $pdo->prepare('DELETE FROM penyaluran_sambungan WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
    }
    return ['bilangan' => count($ids), 'id' => $ids];
}

/**
 * Tanda akaun yang masih menggunakan kata laluan lalai pemasangan supaya wajib tukar.
 * Hanya menyemak hash; kata laluan tidak disimpan atau dilog.
 */
function tanda_katalaluan_lalai(PDO $pdo): array
{
    $lalai = 'admin' . '123';
    $ditanda = [];
    foreach ($pdo->query('SELECT id, username, password_hash, wajib_tukar FROM pengguna') as $u) {
        if (!$u['wajib_tukar'] && password_verify($lalai, $u['password_hash'])) {
            $pdo->prepare('UPDATE pengguna SET wajib_tukar = 1 WHERE id = ?')->execute([$u['id']]);
            $ditanda[] = $u['username'];
        }
    }
    return $ditanda;
}
