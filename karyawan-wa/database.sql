-- ============================================================================
--  DATABASE : db_karyawan
--  Sistem Register, Manajemen Data Karyawan, dan Absensi
--  WhatsApp Gateway : Fonnte
--  Charset : utf8mb4
-- ============================================================================

CREATE DATABASE IF NOT EXISTS db_karyawan
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE db_karyawan;

DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS karyawan;

CREATE TABLE karyawan (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    no_wa VARCHAR(20) NOT NULL,
    jabatan VARCHAR(50) NOT NULL,
    departemen VARCHAR(50) NOT NULL DEFAULT '-',
    role ENUM('admin', 'karyawan') NOT NULL DEFAULT 'karyawan',
    status ENUM('aktif', 'cuti', 'keluar') NOT NULL DEFAULT 'aktif',
    last_login DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_karyawan_email (email),
    KEY idx_karyawan_nama (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE absensi (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    karyawan_id INT UNSIGNED NOT NULL,
    tanggal DATE NOT NULL,
    jam_masuk TIME NULL DEFAULT NULL,
    jam_pulang TIME NULL DEFAULT NULL,
    status ENUM('hadir', 'terlambat', 'izin', 'sakit') NOT NULL,
    catatan VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_absensi_harian (karyawan_id, tanggal),
    CONSTRAINT fk_absensi_karyawan
        FOREIGN KEY (karyawan_id) REFERENCES karyawan(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Data awal : Admin (login : admin@siakwa.com / password : admin123)
--  Password di-hash menggunakan password_hash() dengan algoritma BCRYPT.
--  Ganti langsung setelah instalasi untuk keamanan produksi.
-- ----------------------------------------------------------------------------
INSERT INTO karyawan (nama, email, password, no_wa, jabatan, departemen, role, status) VALUES
('Administrator', 'admin@siakwa.com', '$2y$10$UsNDYfyl8wFS7/vKByFzSey16lJYgNSNjXitXAZxDF9VuabHOtKQ6', '6281234567890', 'Administrator', 'IT', 'admin', 'aktif');

-- ----------------------------------------------------------------------------
--  Data contoh : Karyawan (login : budi@siakwa.com / karyawan123)
--  Password di-hash menggunakan password_hash() dengan algoritma BCRYPT.
-- ----------------------------------------------------------------------------
INSERT INTO karyawan (nama, email, password, no_wa, jabatan, departemen, role, status) VALUES
('Budi Santoso', 'budi@siakwa.com', '$2y$10$FKTWvzLLnuPnSPgTrqtrceRpoN1aHYu.6DJoHJpERNycp9cMFr6KW', '6281210000001', 'Staff Gudang', 'Logistik', 'karyawan', 'aktif'),
('Siti Aminah', 'siti@siakwa.com', '$2y$10$7.AGc0c6Tj9u8WB9swNE3eGpCXnWH.W9tYNHsH7cu/CtghPm2ZXrG', '6281210000002', 'Staff HRD', 'Human Capital', 'karyawan', 'aktif');
