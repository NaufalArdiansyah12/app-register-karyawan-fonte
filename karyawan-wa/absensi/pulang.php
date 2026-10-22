<?php
/* ============================================================================
   ABSENSI — PULANG
   Endpoint proses absen pulang. Memastikan sudah ada jam_masuk sebelum
   mencatat jam_pulang. Trigger WA ke karyawan & admin.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid.'];
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$tanggalHariIni = date('Y-m-d');
$jamPulang = date('H:i:s');
$jamBatasPulang = getPengaturan($koneksi, 'jam_pulang', defined('JAM_PULANG') ? JAM_PULANG : '17:00:00');
$jamBatasPulangTampil = substr($jamBatasPulang, 0, 5);
$isPulangAwal = ($jamPulang < $jamBatasPulang);

$stmt = $koneksi->prepare('SELECT id, karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? AND tanggal = ? LIMIT 1');
$stmt->bind_param('is', $userId, $tanggalHariIni);
$stmt->execute();
$absen = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$absen) {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Belum melakukan absen masuk hari ini. Tidak dapat absen pulang.'];
    header('Location: index.php');
    exit;
}

if (!$absen['jam_masuk']) {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Anda belum melakukan absen masuk. Tidak dapat absen pulang.'];
    header('Location: index.php');
    exit;
}

if ($absen['jam_pulang']) {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Absen pulang hari ini sudah dicatat.'];
    header('Location: index.php');
    exit;
}

$catatanPulang = $absen['catatan'] ? $absen['catatan'] : 'Absen pulang via portal';
if ($isPulangAwal) {
    $catatanPulang .= ' [Pulang awal sebelum ' . $jamBatasPulangTampil . ' WIB]';
}

$stmtUpdate = $koneksi->prepare('UPDATE absensi SET jam_pulang = ?, catatan = ? WHERE id = ?');
$idAbsen = (int) $absen['id'];
$stmtUpdate->bind_param('ssi', $jamPulang, $catatanPulang, $idAbsen);
$stmtUpdate->execute();
$stmtUpdate->close();

/* Ambil data profil karyawan terbaru */
$stmtU = $koneksi->prepare('SELECT nama, no_wa, jabatan FROM karyawan WHERE id = ? LIMIT 1');
$stmtU->bind_param('i', $userId);
$stmtU->execute();
$rowUser = $stmtU->get_result()->fetch_assoc();
$stmtU->close();

$nama         = $rowUser['nama'] ?? $_SESSION['nama'];
$jabatan      = $rowUser['jabatan'] ?? $_SESSION['jabatan'];
$noWaKaryawan = $rowUser['no_wa'] ?? $_SESSION['no_wa'];

$waPesanKaryawan = "✅ *ABSEN PULANG BERHASIL*\n\n"
    . "Halo *{$nama}*,\n"
    . "Anda telah berhasil melakukan absen pulang hari ini:\n\n"
    . "📅 Tanggal : {$tanggalHariIni}\n"
    . "⏰ Jam     : {$jamPulang} WIB\n"
    . "📊 Status  : " . ucfirst((string) $absen['status']) . "\n\n"
    . "Terima kasih atas kerja keras Anda hari ini. Hati-hati di jalan! 🙏\n_" . APP_NAME . "_";
$statusWaKaryawan = kirimWA($noWaKaryawan, $waPesanKaryawan);

$waPesanAdmin = "🔔 *INFORMASI ABSENSI PULANG*\n\n"
    . "Karyawan melakukan absen *pulang*:\n\n"
    . "👤 Nama    : {$nama}\n"
    . "💼 Jabatan : {$jabatan}\n"
    . "📅 Tanggal : {$tanggalHariIni}\n"
    . "⏰ Jam     : {$jamPulang} WIB\n"
    . "📊 Status  : " . ucfirst((string) $absen['status']);
$statusWaAdmin = kirimWA(ADMIN_WA, $waPesanAdmin);

$_SESSION['flash'] = [
    'tipe' => $statusWaKaryawan ? 'success' : 'warning',
    'pesan' => 'Absen pulang berhasil! Jam pulang dicatat. '
        . ($statusWaKaryawan ? "Notifikasi WhatsApp telah dikirim ke nomor Anda ({$noWaKaryawan})." : "Notifikasi WhatsApp gagal dikirim.")
];

header('Location: index.php');
exit;
