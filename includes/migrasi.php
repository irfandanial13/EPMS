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

    return $log;
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
