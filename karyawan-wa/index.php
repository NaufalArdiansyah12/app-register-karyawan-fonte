<?php
/* ============================================================================
   INDEX — LANDING / REDIRECT
   Redirect otomatis ke dashboard sesuai status session:
   - Admin        -> admin/index.php
   - Karyawan     -> absensi/index.php
   - Belum login  -> auth/login.php
   ============================================================================ */

session_start();

if (isset($_SESSION['user_id'])) {
    $tujuan = ($_SESSION['role'] === 'admin') ? 'admin/index.php' : 'absensi/index.php';
    header('Location: ' . $tujuan);
    exit;
}

header('Location: auth/login.php');
exit;
