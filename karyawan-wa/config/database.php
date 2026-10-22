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
