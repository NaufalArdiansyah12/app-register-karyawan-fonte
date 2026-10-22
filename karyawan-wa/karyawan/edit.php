<?php
/* ============================================================================
   KARYAWAN — EDIT (Handler POST)
   Proses update data karyawan oleh admin.
   Setiap perubahan data mengirimkan notifikasi resmi via WhatsApp ke nomor
   karyawan yang bersangkutan via Fonnte.
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
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan ulangi.'];
    header('Location: index.php');
    exit;
}

function normalisasiNoWA(string $nomor): string {
    $nomor = preg_replace('/[^0-9]/', '', $nomor);
    if (strpos($nomor, '08') === 0) {
        $nomor = '62' . substr($nomor, 1);
    }
    return ltrim($nomor, '0');
}

$id = (int) ($_POST['id'] ?? 0);

/* 1. Ambil data lama karyawan */
$stmt = $koneksi->prepare('SELECT id, nama, email, no_wa, jabatan, departemen, status, role FROM karyawan WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$dataLama = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dataLama) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Data karyawan tidak ditemukan.'];
    header('Location: index.php');
    exit;
}

/* 2. Tangkap input baru */
$nama         = trim($_POST['nama'] ?? '');
$email        = strtolower(trim($_POST['email'] ?? ''));
$noWaBaru     = normalisasiNoWA($_POST['no_wa'] ?? '');
$jabatan      = trim($_POST['jabatan'] ?? '');
$departemen   = trim($_POST['departemen'] ?? '-');
$status       = $_POST['status'] ?? 'aktif';
$role         = $_POST['role'] ?? 'karyawan';
$passwordBaru = $_POST['password'] ?? '';
$gantiPassword = ($passwordBaru !== '');
$errors       = [];

if ($nama === '' || mb_strlen($nama) < 3) {
    $errors[] = 'Nama lengkap minimal 3 karakter.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format email tidak valid.';
}

/* Validasi keunikan email */
$stmt = $koneksi->prepare('SELECT id FROM karyawan WHERE email = ? AND id <> ? LIMIT 1');
$stmt->bind_param('si', $email, $id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $errors[] = 'Email sudah digunakan oleh karyawan lain.';
}
$stmt->close();

if ($gantiPassword && strlen($passwordBaru) < 8) {
    $errors[] = 'Password baru minimal 8 karakter.';
}
if ($noWaBaru === '' || !preg_match('/^62\d{8,13}$/', $noWaBaru)) {
    $errors[] = 'Format nomor WhatsApp tidak valid. Gunakan format contoh: 08123456789 atau 628123456789.';
}
if ($jabatan === '') {
    $errors[] = 'Jabatan wajib diisi.';
}
if (!in_array($status, ['aktif', 'cuti', 'keluar'], true)) {
    $status = 'aktif';
}
if (!in_array($role, ['admin', 'karyawan'], true)) {
    $role = 'karyawan';
}

if (!empty($errors)) {
    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => implode(' | ', $errors)];
    header('Location: index.php');
    exit;
}

/* 3. Lakukan update ke database */
if ($gantiPassword) {
    $hash = password_hash($passwordBaru, PASSWORD_DEFAULT);
    $stmt = $koneksi->prepare('UPDATE karyawan SET nama=?, email=?, no_wa=?, jabatan=?, departemen=?, status=?, role=?, password=?, updated_at=NOW() WHERE id=?');
    $stmt->bind_param('ssssssssi', $nama, $email, $noWaBaru, $jabatan, $departemen, $status, $role, $hash, $id);
} else {
    $stmt = $koneksi->prepare('UPDATE karyawan SET nama=?, email=?, no_wa=?, jabatan=?, departemen=?, status=?, role=?, updated_at=NOW() WHERE id=?');
    $stmt->bind_param('sssssssi', $nama, $email, $noWaBaru, $jabatan, $departemen, $status, $role, $id);
}
$stmt->execute();
$stmt->close();

/* 4. Buat daftar item yang berubah untuk transparansi pesan */
$perubahan = [];
if ($dataLama['nama'] !== $nama) {
    $perubahan[] = "• Nama: {$dataLama['nama']} ➔ *{$nama}*";
}
if ($dataLama['email'] !== $email) {
    $perubahan[] = "• Email: {$dataLama['email']} ➔ *{$email}*";
}
if ($dataLama['no_wa'] !== $noWaBaru) {
    $perubahan[] = "• No. WA: {$dataLama['no_wa']} ➔ *{$noWaBaru}*";
}
if ($dataLama['jabatan'] !== $jabatan) {
    $perubahan[] = "• Jabatan: {$dataLama['jabatan']} ➔ *{$jabatan}*";
}
if ($dataLama['departemen'] !== $departemen) {
    $perubahan[] = "• Departemen: {$dataLama['departemen']} ➔ *{$departemen}*";
}
if ($dataLama['status'] !== $status) {
    $perubahan[] = "• Status: " . ucfirst($dataLama['status']) . " ➔ *" . ucfirst($status) . "*";
}
if ($dataLama['role'] !== $role) {
    $perubahan[] = "• Role: " . ucfirst($dataLama['role']) . " ➔ *" . ucfirst($role) . "*";
}
if ($gantiPassword) {
    $perubahan[] = "• Password Akun telah diubah/direset oleh Admin. (Password Baru: *{$passwordBaru}*)";
}

$rincianPerubahan = !empty($perubahan) ? implode("\n", $perubahan) : "• Pembaruan data profil oleh Admin.";
$waktuUpdate = date('d-m-Y H:i');

/* 5. Susun Pesan WhatsApp ke Karyawan */
$waPesan = "✏️ *PEMBARUAN DATA KARYAWAN*\n\n"
    . "Halo *{$nama}*,\n"
    . "Data akun/kepegawaian Anda di *" . APP_NAME . "* telah diperbarui oleh Administrator:\n\n"
    . "📋 *Rincian Perubahan Data:*\n"
    . $rincianPerubahan . "\n\n"
    . "📌 *Data Profil Terbaru:*\n"
    . "👤 Nama       : {$nama}\n"
    . "📧 Email      : {$email}\n"
    . "💼 Jabatan    : {$jabatan}\n"
    . "🏢 Departemen : {$departemen}\n"
    . "📊 Status     : " . ucfirst($status) . "\n"
    . "🕒 Waktu      : {$waktuUpdate} WIB\n\n"
    . "Jika Anda merasa tidak mengajukan perubahan ini atau terdapat ketidaksesuaian data, silakan segera konfirmasi ke Admin/HRD.\n\n"
    . "Terima kasih. 🙏\n_" . APP_NAME . "_";

/* 6. Kirim ke nomor WhatsApp karyawan yang bersangkutan */
$statusWa = kirimWA($noWaBaru, $waPesan);

/* Jika nomor WhatsApp-nya diganti, kirimkan juga info ke nomor lama sebagai proteksi keamanan */
if ($dataLama['no_wa'] !== $noWaBaru && $dataLama['no_wa'] !== '') {
    $waPesanLama = "🔔 *PENGALIHAN NOMOR WHATSAPP*\n\n"
        . "Halo *{$dataLama['nama']}*,\n"
        . "Nomor WhatsApp akun Anda di *" . APP_NAME . "* telah dialihkan ke nomor baru: *{$noWaBaru}*.\n\n"
        . "Jika ini bukan tindakan Anda/Admin yang sah, segera hubungi manajemen. Terima kasih. 🙏";
    kirimWA($dataLama['no_wa'], $waPesanLama);
}

/* 7. Flash response */
$_SESSION['flash'] = [
    'tipe' => $statusWa ? 'success' : 'warning',
    'pesan' => "Data karyawan \"{$nama}\" berhasil diperbarui! "
        . ($statusWa 
            ? "Notifikasi WhatsApp telah dikirimkan ke nomor {$noWaBaru}." 
            : "Namun notifikasi WhatsApp gagal terkirim (periksa kuota/status Fonnte).")
];

header('Location: index.php');
exit;
