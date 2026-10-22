<?php
/* ============================================================================
   KARYAWAN — TAMBAH (Handler POST)
   Proses penambahan karyawan baru oleh admin.
   Otomatis mengirimkan notifikasi WhatsApp berisi kredensial akun dan ucapan
   selamat datang ke nomor WhatsApp karyawan yang bersangkutan via Fonnte.
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

$nama       = trim($_POST['nama'] ?? '');
$email      = strtolower(trim($_POST['email'] ?? ''));
$password   = $_POST['password'] ?? '';
$noWa       = normalisasiNoWA($_POST['no_wa'] ?? '');
$jabatan    = trim($_POST['jabatan'] ?? '');
$departemen = trim($_POST['departemen'] ?? '-');
$status     = $_POST['status'] ?? 'aktif';
$role       = $_POST['role'] ?? 'karyawan';
$errors     = [];

if ($nama === '' || mb_strlen($nama) < 3) {
    $errors[] = 'Nama lengkap minimal 3 karakter.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format email tidak valid.';
}

$stmt = $koneksi->prepare('SELECT id FROM karyawan WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $errors[] = 'Email sudah terdaftar untuk karyawan lain.';
}
$stmt->close();

if ($password === '') {
    $password = bin2hex(random_bytes(4));
}
if (strlen($password) < 8) {
    $errors[] = 'Password minimal 8 karakter.';
}
if ($noWa === '' || !preg_match('/^62\d{8,13}$/', $noWa)) {
    $errors[] = 'Format nomor WhatsApp tidak valid. Contoh: 08123456789 atau 628123456789.';
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

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $koneksi->prepare('INSERT INTO karyawan (nama, email, password, no_wa, jabatan, departemen, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->bind_param('ssssssss', $nama, $email, $hash, $noWa, $jabatan, $departemen, $role, $status);
$stmt->execute();
$newId = $stmt->insert_id;
$stmt->close();

$waktuDaftar = date('d-m-Y H:i');

/* Susun Pesan WhatsApp ke Karyawan yang baru didaftarkan */
$waPesan = "🎉 *SELAMAT DATANG DI " . APP_NAME . "*\n\n"
    . "Halo *{$nama}*,\n"
    . "Akun kepegawaian Anda telah berhasil didaftarkan oleh Administrator sistem:\n\n"
    . "📋 *Rincian Akun Anda:*\n"
    . "👤 Nama        : {$nama}\n"
    . "📧 Email       : {$email}\n"
    . "🔑 Password    : *{$password}*\n"
    . "💼 Jabatan     : {$jabatan}\n"
    . "🏢 Departemen  : {$departemen}\n"
    . "📱 WhatsApp    : {$noWa}\n"
    . "🕒 Terdaftar   : {$waktuDaftar} WIB\n\n"
    . "Silakan gunakan email dan password di atas untuk masuk ke sistem presensi karyawan.\n"
    . "Mohon simpan informasi ini dengan baik.\n\n"
    . "Semangat beraktivitas & salam sukses! 🙏\n_" . APP_NAME . "_";

$statusWa = kirimWA($noWa, $waPesan);

/* Notifikasi singkat juga ke admin log WA jika nomor berbeda */
if (ADMIN_WA !== '' && ADMIN_WA !== $noWa) {
    $waPesanAdmin = "🆕 *PENDAFTARAN KARYAWAN BARU*\n\n"
        . "Karyawan baru berhasil ditambahkan oleh Admin:\n"
        . "👤 {$nama} ({$jabatan} - {$departemen})\n"
        . "📧 {$email}\n"
        . "📱 {$noWa}\n"
        . "🕒 {$waktuDaftar} WIB";
    kirimWA(ADMIN_WA, $waPesanAdmin);
}

$_SESSION['flash'] = [
    'tipe' => $statusWa ? 'success' : 'warning',
    'pesan' => "Karyawan \"{$nama}\" berhasil ditambahkan! "
        . ($statusWa 
            ? "Notifikasi WhatsApp & kredensial akun telah terkirim ke nomor {$noWa}." 
            : "Namun notifikasi WhatsApp gagal terkirim ke {$noWa} (periksa kuota/status Fonnte).")
];

header('Location: index.php');
exit;
