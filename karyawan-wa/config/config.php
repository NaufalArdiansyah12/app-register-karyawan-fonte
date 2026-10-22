<?php
/**
 * ============================================================================
 *  KONFIGURASI APLIKASI — Sistem Register, Manajemen Data Karyawan &
 *  Absensi dengan Integrasi WhatsApp Gateway (Fonnte)
 * ============================================================================
 *  Semua konfigurasi sensitif dipisah ke file ini. Jangan commit file ini
 *  ke repository publik setelah diisi.
 */

/* ---------- 1. IDENTITAS APLIKASI ---------- */
define('APP_NAME', 'SIAKWA — Sistem Absensi Karyawan');

/* ---------- 2. KONEKSI DATABASE MYSQL ---------- */
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_karyawan');

/* ---------- 3. WHATSAPP GATEWAY — FONNTE ---------- */
define('FONNTE_TOKEN', 'Sd6zyqwhUgtn9C68X5Ss');
define('ADMIN_WA', '62881027646453'); // Format: 62 + nomor tanpa 0, tanpa '+'

/* ---------- 4. KEBIJAKAN JAM KERJA (WIB) ---------- */
define('JAM_BATAS_MASUK', '08:00:00');
define('JAM_PULANG', '17:00:00');

/* ---------- 5. PROTEKSI AKSES LANGSUNG ---------- */
if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit('Akses langsung ke file konfigurasi tidak diizinkan.');
}
