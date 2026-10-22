<?php
/* ============================================================================
   ABSENSI — HAPUS (Khusus Admin)
   Menghapus data presensi karyawan dengan validasi CSRF & role admin.
   Mengirimkan notifikasi WhatsApp ke nomor karyawan bersangkutan via Fonnte.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Akses ditolak. Tindakan ini hanya untuk Admin.'];
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid.'];
    header('Location: index.php');
    exit;
}

$idAbsen = (int) ($_POST['id'] ?? 0);
if ($idAbsen <= 0) {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'ID data presensi tidak valid.'];
    header('Location: index.php');
    exit;
}

/* 1. Ambil data lengkap sebelum dihapus untuk info flash & WA */
$stmtCheck = $koneksi->prepare('SELECT a.id, a.tanggal, a.jam_masuk, a.jam_pulang, a.status, a.catatan, k.nama, k.no_wa, k.jabatan FROM absensi a INNER JOIN karyawan k ON k.id = a.karyawan_id WHERE a.id = ? LIMIT 1');
$stmtCheck->bind_param('i', $idAbsen);
$stmtCheck->execute();
$dataAbsen = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();

if (!$dataAbsen) {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Data presensi tidak ditemukan atau sudah dihapus.'];
    header('Location: index.php');
    exit;
}

$nama        = $dataAbsen['nama'];
$noWa        = $dataAbsen['no_wa'];
$tanggalFmt  = date('d-m-Y', strtotime($dataAbsen['tanggal']));
$statusAbsen = strtoupper($dataAbsen['status']);
$waktuHapus  = date('d-m-Y H:i');

/* 2. Kirim notifikasi WhatsApp ke Karyawan */
$waPesan = "⚠️ *PEMBERITAHUAN PEMBATALAN PRESENSI*\n\n"
    . "Halo *{$nama}*,\n"
    . "Catatan presensi kerja Anda untuk tanggal *{$tanggalFmt}* ({$statusAbsen}) telah dihapus/dibatalkan oleh Administrator sistem.\n\n"
    . "🕒 Dihapus pada: {$waktuHapus} WIB\n\n"
    . "Jika membutuhkan penyesuaian data atau klarifikasi, silakan segera konfirmasi ke Admin / HRD.\n"
    . "Terima kasih. 🙏\n_" . APP_NAME . "_";

$statusWa = kirimWA($noWa, $waPesan);

/* 3. Eksekusi Hapus dari Database */
$stmtDelete = $koneksi->prepare('DELETE FROM absensi WHERE id = ? LIMIT 1');
$stmtDelete->bind_param('i', $idAbsen);
$berhasil = $stmtDelete->execute();
$stmtDelete->close();

if ($berhasil) {
    $_SESSION['flash'] = [
        'tipe' => $statusWa ? 'success' : 'warning',
        'pesan' => "Data presensi {$nama} tanggal {$tanggalFmt} berhasil dihapus. "
            . ($statusWa 
                ? "Notifikasi WhatsApp telah dikirim ke nomor {$noWa}." 
                : "Namun notifikasi WhatsApp gagal terkirim ke {$noWa}.")
    ];
} else {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Gagal menghapus data presensi.'];
}

$redirectTo = $_POST['redirect_to'] ?? 'index.php';
header('Location: ' . $redirectTo);
exit;
