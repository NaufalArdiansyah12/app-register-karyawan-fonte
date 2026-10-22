<?php
/* ============================================================================
   ADMIN — PRESENSI PROSES (Handler POST)
   Mencatat presensi karyawan secara langsung oleh Admin, kemudian mengirimkan
   notifikasi WhatsApp resmi ke nomor karyawan yang bersangkutan.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Akses ditolak. Tindakan ini khusus administrator.'];
    header('Location: ../absensi/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan coba lagi.'];
    header('Location: index.php');
    exit;
}

$redirectTo = $_POST['redirect_to'] ?? 'index.php';
// Pastikan redirect tetap aman dalam folder admin atau absensi
if (!in_array($redirectTo, ['index.php', '../admin/index.php', '../absensi/index.php', '../karyawan/index.php'], true)) {
    $redirectTo = 'index.php';
}

$karyawanId = (int) ($_POST['karyawan_id'] ?? 0);
$tanggal    = trim($_POST['tanggal'] ?? date('Y-m-d'));
$jenis      = trim($_POST['jenis'] ?? 'masuk');
$jamMasuk   = trim($_POST['jam_masuk'] ?? '');
$jamPulang  = trim($_POST['jam_pulang'] ?? '');
$catatan    = trim($_POST['catatan'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

/* 1. Ambil data karyawan */
$stmt = $koneksi->prepare('SELECT id, nama, email, no_wa, jabatan, departemen FROM karyawan WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $karyawanId);
$stmt->execute();
$karyawan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$karyawan) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Karyawan yang dipilih tidak ditemukan.'];
    header('Location: ' . $redirectTo);
    exit;
}

/* 2. Cek apakah sudah ada catatan absensi untuk karyawan ini pada tanggal tersebut */
$stmt = $koneksi->prepare('SELECT id, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? AND tanggal = ? LIMIT 1');
$stmt->bind_param('is', $karyawanId, $tanggal);
$stmt->execute();
$absenLama = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* 3. Tentukan nilai jam_masuk, jam_pulang, dan status berdasarkan jenis */
$jamMasukFinal  = null;
$jamPulangFinal = null;
$statusFinal    = 'hadir';

$jamSekarang = date('H:i:s');
$jamBatasMasuk = getPengaturan($koneksi, 'jam_batas_masuk', defined('JAM_BATAS_MASUK') ? JAM_BATAS_MASUK : '08:00:00');
$jamPulangBatas = getPengaturan($koneksi, 'jam_pulang', defined('JAM_PULANG') ? JAM_PULANG : '17:00:00');

switch ($jenis) {
    case 'pulang':
        $jamPulangFinal = ($jamPulang !== '') ? (strlen($jamPulang) === 5 ? $jamPulang . ':00' : $jamPulang) : $jamSekarang;
        $jamMasukFinal  = $absenLama['jam_masuk'] ?? null;
        $statusFinal    = $absenLama['status'] ?? 'hadir';
        if ($catatan === '') {
            $catatan = $absenLama['catatan'] ?? 'Absen pulang dicatat oleh Admin';
        }
        break;

    case 'hadir_lengkap':
        $jamMasukFinal  = ($jamMasuk !== '')  ? (strlen($jamMasuk) === 5 ? $jamMasuk . ':00' : $jamMasuk) : '08:00:00';
        $jamPulangFinal = ($jamPulang !== '') ? (strlen($jamPulang) === 5 ? $jamPulang . ':00' : $jamPulang) : '17:00:00';
        $statusFinal    = ($jamMasukFinal <= $jamBatasMasuk) ? 'hadir' : 'terlambat';
        if ($catatan === '') {
            $catatan = 'Presensi lengkap dicatat oleh Admin';
        }
        break;

    case 'izin':
        $jamMasukFinal  = null;
        $jamPulangFinal = null;
        $statusFinal    = 'izin';
        if ($catatan === '') {
            $catatan = 'Izin (Dicatat oleh Admin)';
        }
        break;

    case 'sakit':
        $jamMasukFinal  = null;
        $jamPulangFinal = null;
        $statusFinal    = 'sakit';
        if ($catatan === '') {
            $catatan = 'Sakit (Dicatat oleh Admin)';
        }
        break;

    case 'masuk':
    default:
        $jamMasukFinal  = ($jamMasuk !== '') ? (strlen($jamMasuk) === 5 ? $jamMasuk . ':00' : $jamMasuk) : $jamSekarang;
        $jamPulangFinal = $absenLama['jam_pulang'] ?? null;
        $statusFinal    = ($jamMasukFinal <= $jamBatasMasuk) ? 'hadir' : 'terlambat';
        if ($catatan === '') {
            $catatan = 'Absen masuk dicatat oleh Admin';
        }
        break;
}

/* 4. Simpan ke database (UPDATE jika sudah ada, INSERT jika belum) */
if ($absenLama) {
    $stmt = $koneksi->prepare('UPDATE absensi SET jam_masuk = ?, jam_pulang = ?, status = ?, catatan = ? WHERE id = ?');
    $idAbsen = (int) $absenLama['id'];
    $stmt->bind_param('ssssi', $jamMasukFinal, $jamPulangFinal, $statusFinal, $catatan, $idAbsen);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $koneksi->prepare('INSERT INTO absensi (karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssss', $karyawanId, $tanggal, $jamMasukFinal, $jamPulangFinal, $statusFinal, $catatan);
    $stmt->execute();
    $stmt->close();
}

/* 5. Susun Pesan WhatsApp ke Karyawan */
$labelStatus = match($statusFinal) {
    'hadir'     => '✅ Hadir (Tepat Waktu)',
    'terlambat' => '⚠️ Terlambat (Batas ' . JAM_BATAS_MASUK . ')',
    'izin'      => '📝 Izin',
    'sakit'     => '🏥 Sakit',
    default     => ucfirst($statusFinal)
};

$infoWaktu = '';
if ($jamMasukFinal) {
    $infoWaktu .= "\n⏰ Jam Masuk : " . substr($jamMasukFinal, 0, 5) . " WIB";
}
if ($jamPulangFinal) {
    $infoWaktu .= "\n⏰ Jam Pulang : " . substr($jamPulangFinal, 0, 5) . " WIB";
}

$tanggalFmt = date('d-m-Y', strtotime($tanggal));
$waktuInput = date('d-m-Y H:i');

$waPesan = "🔔 *PEMBERITAHUAN PRESENSI KARYAWAN*\n\n"
    . "Halo *{$karyawan['nama']}*,\n"
    . "Presensi kerja Anda telah dicatat oleh Administrator sistem:\n\n"
    . "📅 Tanggal    : {$tanggalFmt}"
    . $infoWaktu . "\n"
    . "📊 Status     : {$labelStatus}\n"
    . "📝 Keterangan : {$catatan}\n\n"
    . "🕒 Dicatat pada: {$waktuInput} WIB\n\n"
    . "Jika ada ketidaksesuaian data, silakan konfirmasi ke admin/HRD.\n"
    . "Terima kasih. 🙏\n_" . APP_NAME . "_";

/* 6. Kirim WhatsApp via Fonnte */
$statusWa = kirimWA($karyawan['no_wa'], $waPesan);

/* 7. Flash response */
$_SESSION['flash'] = [
    'tipe' => $statusWa ? 'success' : 'warning',
    'pesan' => "Presensi {$karyawan['nama']} ({$tanggalFmt}) berhasil dicatat! "
        . ($statusWa 
            ? "Notifikasi WhatsApp terkirim ke {$karyawan['no_wa']}." 
            : "Namun notifikasi WhatsApp gagal dikirim (periksa kuota/status Fonnte).")
];

header('Location: ' . $redirectTo);
exit;

