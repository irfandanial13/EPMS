-- EPMS (Estet Padi Management System) : skema pangkalan data
-- Skema pangkalan data (MySQL / MariaDB)
-- SELAMAT: tiada DROP. Jadual sedia ada tidak akan dipadam atau ditulis ganti.
--
-- Fail ini TIDAK mencipta atau memilih pangkalan data. Ia hanya mencipta jadual dalam pangkalan data
-- yang telah dipilih oleh pemanggil (install.php memilih DB_NAME daripada config.php dan menolak fail
-- SQL yang mengandungi arahan mencipta / menukar pangkalan data).
-- Set aksara dinyatakan pada setiap jadual supaya tidak bergantung pada lalai pangkalan data.
-- Struktur selepas garis dasar (Fasa 1 Perjanjian, Fasa 4.2 audit) dicipta oleh pastikan_skema()
-- dalam includes/migrasi.php — sumber rujukan bagi perubahan skema selepas garis dasar.

CREATE TABLE IF NOT EXISTS wilayah (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    kod     VARCHAR(10)  NOT NULL UNIQUE,
    nama    VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ppk (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    wilayah_id  INT          NOT NULL,
    kod         VARCHAR(10)  NOT NULL UNIQUE,
    nama        VARCHAR(150) NOT NULL,
    susunan     INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_ppk_wilayah FOREIGN KEY (wilayah_id) REFERENCES wilayah(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS penyaluran (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    ppk_id             INT           NOT NULL,
    jenis              ENUM('ASAL','SAMBUNG') NOT NULL DEFAULT 'ASAL',
    musim              VARCHAR(20)   NOT NULL,
    musim_susun        INT           NOT NULL DEFAULT 0,
    no_blok            VARCHAR(100)  NULL,
    projek             VARCHAR(150)  NULL,
    model              VARCHAR(10)   NOT NULL,
    bil_peladang       INT           NOT NULL DEFAULT 0,
    luas_ha            DECIMAL(12,3) NOT NULL DEFAULT 0,
    kump               VARCHAR(20)   NULL,
    kadar_sewa         DECIMAL(12,2) NOT NULL DEFAULT 0,
    sewaan             DECIMAL(14,2) NOT NULL DEFAULT 0,
    modal_operasi      DECIMAL(14,2) NOT NULL DEFAULT 0,
    musim_pelaksanaan  VARCHAR(40)   NULL,
    bil_musim          VARCHAR(10)   NULL,
    fasa               VARCHAR(60)   NULL,
    musim_tamat        VARCHAR(60)   NULL,
    status             ENUM('AKTIF','TAMAT') NOT NULL DEFAULT 'AKTIF',
    tindakan           VARCHAR(255)  NULL,
    catatan            TEXT          NULL,
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pny_ppk FOREIGN KEY (ppk_id) REFERENCES ppk(id),
    INDEX idx_musim (musim_susun),
    INDEX idx_model (model),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pengguna (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    username            VARCHAR(50)  NOT NULL UNIQUE,
    nama                VARCHAR(100) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    wajib_tukar         TINYINT(1)   NOT NULL DEFAULT 0,
    katalaluan_ditukar  DATETIME     NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    berjaya    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_masa (username, created_at),
    INDEX idx_ip_masa (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO wilayah (kod, nama) VALUES
('I',   'WILAYAH I'),
('II',  'WILAYAH II'),
('III', 'WILAYAH III'),
('IV',  'WILAYAH IV');

-- Akaun admin dicipta oleh install.php (kata laluan sementara, wajib ditukar)
