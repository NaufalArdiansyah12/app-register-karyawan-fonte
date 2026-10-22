<?php
/* ============================================================================
   KARYAWAN — HAPUS (Handler POST)
   Proses hapus karyawan oleh Admin.
   Sebelum record dihapus dari database, sistem mengirimkan notifikasi resmi
   ke nomor WhatsApp karyawan yang bersangkutan via Fonnte.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Akses ditolak. Tindakan ini khusus Admin.'];
    header('Location: ../absensi/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan coba lagi.'];
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

/* Tidak boleh menghapus akun sendiri */
if ($id === (int) $_SESSION['user_id']) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Anda tidak dapat menghapus akun admin Anda sendiri yang sedang aktif.'];
    header('Location: index.php');
    exit;
}

/* 1. Ambil data lengkap karyawan sebelum dihapus */
$stmt = $koneksi->prepare('SELECT id, nama, email, no_wa, jabatan, departemen FROM karyawan WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Data karyawan tidak ditemukan atau sudah dihapus.'];
    header('Location: index.php');
    exit;
}

$nama       = $row['nama'];
$email      = $row['email'];
$noWa       = $row['no_wa'];
$jabatan    = $row['jabatan'];
$departemen = $row['departemen'] ?? '-';
$waktu      = date('d-m-Y H:i');

/* 2. Susun pesan notifikasi WhatsApp resmi ke Karyawan */
$waPesan = "🗑️ *PEMBERITAHUAN PENGHAPUSAN AKUN*\n\n"
    . "Halo *{$nama}*,\n"
    . "Kami menginformasikan bahwa data akun kepegawaian Anda pada sistem *" . APP_NAME . "* telah dihapus/dinonaktifkan oleh Administrator:\n\n"
    . "📋 *Rincian Data yang Dihapus:*\n"
    . "👤 Nama       : {$nama}\n"
    . "📧 Email      : {$email}\n"
    . "💼 Jabatan    : {$jabatan}\n"
    . "🏢 Departemen : {$departemen}\n"
    . "📱 WhatsApp   : {$noWa}\n"
    . "🕒 Waktu      : {$waktu} WIB\n\n"
    . "Terima kasih banyak atas segala dedikasi, kerja sama, dan kontribusi Anda selama ini. 🙏\n\n"
    . "Apabila Anda membutuhkan informasi lebih lanjut, silakan hubungi bagian HRD / Administrator.\n_" . APP_NAME . "_";

/* 3. Kirim notifikasi WhatsApp ke nomor karyawan yang bersangkutan */
$waStatus = kirimWA($noWa, $waPesan);

/* Notifikasi singkat juga ke admin log WA jika nomor berbeda */
if (ADMIN_WA !== '' && ADMIN_WA !== $noWa) {
    $waPesanAdmin = "🔔 *LOG PENGHAPUSAN KARYAWAN*\n\n"
        . "Akun karyawan telah dihapus oleh Admin:\n"
        . "👤 {$nama} ({$jabatan} - {$departemen})\n"
        . "📱 {$noWa}\n"
        . "🕒 {$waktu} WIB";
    kirimWA(ADMIN_WA, $waPesanAdmin);
}

/* 4. Hapus data karyawan dari database (CASCADE akan otomatis membersihkan relasi absensi) */
$stmtDelete = $koneksi->prepare('DELETE FROM karyawan WHERE id = ?');
$stmtDelete->bind_param('i', $id);
$berhasil = $stmtDelete->execute();
$stmtDelete->close();

if ($berhasil) {
    $_SESSION['flash'] = [
        'tipe' => $waStatus ? 'success' : 'warning',
        'pesan' => "Data karyawan \"{$nama}\" berhasil dihapus dari sistem! "
            . ($waStatus 
                ? "Notifikasi WhatsApp resmi telah terkirim ke nomor {$noWa}." 
                : "Namun notifikasi WhatsApp gagal terkirim ke {$noWa} (periksa kuota/status Fonnte).")
    ];
} else {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => "Gagal menghapus data karyawan \"{$nama}\"."];
}

header('Location: index.php');
exit;
