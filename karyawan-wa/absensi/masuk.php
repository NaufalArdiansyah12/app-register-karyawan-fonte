<?php
/* ============================================================================
   ABSENSI — MASUK
   Endpoint proses absen masuk. Deteksi status (Tepat Waktu / Terlambat)
   berdasarkan JAM_BATAS_MASUK. Trigger WA ke karyawan & admin.
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
$jamSekarang = date('H:i:s');
$jamBatasMasuk = getPengaturan($koneksi, 'jam_batas_masuk', defined('JAM_BATAS_MASUK') ? JAM_BATAS_MASUK : '08:00:00');
$jamBatasMasukTampil = substr($jamBatasMasuk, 0, 5);

$statusAbsen = ($jamSekarang <= $jamBatasMasuk) ? 'hadir' : 'terlambat';
$labelStatus = ($statusAbsen === 'hadir') ? 'Tepat Waktu ✅' : 'Terlambat ⚠️ (batas ' . $jamBatasMasukTampil . ' WIB)';

$stmt = $koneksi->prepare('SELECT id, karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? AND tanggal = ? LIMIT 1');
$stmt->bind_param('is', $userId, $tanggalHariIni);
$stmt->execute();
$absen = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($absen) {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Anda sudah melakukan catatan absensi hari ini.'];
    header('Location: index.php');
    exit;
}

$stmtInsert = $koneksi->prepare('INSERT INTO absensi (karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan) VALUES (?, ?, ?, NULL, ?, NULL)');
$stmtInsert->bind_param('isss', $userId, $tanggalHariIni, $jamSekarang, $statusAbsen);
$stmtInsert->execute();
$stmtInsert->close();

/* Ambil data profil karyawan terbaru */
$stmtU = $koneksi->prepare('SELECT nama, no_wa, jabatan FROM karyawan WHERE id = ? LIMIT 1');
$stmtU->bind_param('i', $userId);
$stmtU->execute();
$rowUser = $stmtU->get_result()->fetch_assoc();
$stmtU->close();

$nama         = $rowUser['nama'] ?? $_SESSION['nama'];
$jabatan      = $rowUser['jabatan'] ?? $_SESSION['jabatan'];
$noWaKaryawan = $rowUser['no_wa'] ?? $_SESSION['no_wa'];

$waPesanKaryawan = "✅ *ABSEN MASUK BERHASIL*\n\n"
    . "Halo *{$nama}*,\n"
    . "Anda telah berhasil melakukan absen masuk hari ini:\n\n"
    . "📅 Tanggal : {$tanggalHariIni}\n"
    . "⏰ Jam     : {$jamSekarang} WIB\n"
    . "📊 Status  : {$labelStatus}\n\n"
    . "Semangat beraktivitas! 🙏\n_" . APP_NAME . "_";
$statusWaKaryawan = kirimWA($noWaKaryawan, $waPesanKaryawan);

$waPesanAdmin = "🔔 *INFORMASI ABSENSI MASUK*\n\n"
    . "Karyawan melakukan absen *masuk*:\n\n"
    . "👤 Nama    : {$nama}\n"
    . "💼 Jabatan : {$jabatan}\n"
    . "📅 Tanggal : {$tanggalHariIni}\n"
    . "⏰ Jam     : {$jamSekarang} WIB\n"
    . "📊 Status  : {$labelStatus}";
$statusWaAdmin = kirimWA(ADMIN_WA, $waPesanAdmin);

$_SESSION['flash'] = [
    'tipe' => $statusWaKaryawan ? 'success' : 'warning',
    'pesan' => 'Absen masuk berhasil! Status: ' . $labelStatus . '. '
        . ($statusWaKaryawan ? "Notifikasi WhatsApp telah dikirim ke nomor Anda ({$noWaKaryawan})." : "Notifikasi WhatsApp gagal dikirim.")
];

header('Location: index.php');
exit;
