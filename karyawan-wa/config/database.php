<?php
/**
 * ============================================================================
 *  KONEKSI DATABASE — MYSQLI (Object-Oriented + Prepared Statements)
 *  Wajib di-include pada awal halaman yang memerlukan database.
 * ============================================================================
 */

require_once __DIR__ . '/config.php';

/* Setel zona waktu ke Indonesia Barat (WIB) */
date_default_timezone_set('Asia/Jakarta');

/* Buat koneksi MySQLi */
$koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($koneksi->connect_error) {
    die('Koneksi database gagal: ' . htmlspecialchars($koneksi->connect_error));
}

/* Pastikan komunikasi menggunakan charset utf8mb4 (mendukung emoji) */
$koneksi->set_charset('utf8mb4');

/* Sinkronkan zona waktu sesi MySQL dengan WIB (+07:00) */
$koneksi->query("SET time_zone = '+07:00'");

/* Inisialisasi tabel pengaturan dinamis jika belum ada */
$koneksi->query("CREATE TABLE IF NOT EXISTS pengaturan (
    kunci VARCHAR(50) NOT NULL PRIMARY KEY,
    nilai VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (!function_exists('getPengaturan')) {
    function getPengaturan(mysqli $koneksi, string $kunci, string $default = ''): string {
        $stmt = $koneksi->prepare("SELECT nilai FROM pengaturan WHERE kunci = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $kunci);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res && isset($res['nilai']) && $res['nilai'] !== '') {
                return $res['nilai'];
            }
        }
        return $default;
    }
}

if (!function_exists('setPengaturan')) {
    function setPengaturan(mysqli $koneksi, string $kunci, string $nilai): bool {
        $stmt = $koneksi->prepare("INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = ?");
        if ($stmt) {
            $stmt->bind_param('sss', $kunci, $nilai, $nilai);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        }
        return false;
    }
}
