<?php
/* ============================================================================
   AUTH — REGISTER
   Form & proses registrasi karyawan baru. Setelah insert berhasil, trigger
   pengiriman WhatsApp (1) ke karyawan yang mendaftar & (2) ke admin.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../' . ($_SESSION['role'] === 'admin' ? 'admin/index.php' : 'absensi/index.php'));
    exit;
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$pesanError = '';
$pesanSukses = '';
$errors = [];

function normalisasiNoWA(string $nomor): string {
    $nomor = preg_replace('/[^0-9]/', '', $nomor);
    if (str_starts_with($nomor, '08')) {
        $nomor = '62' . substr($nomor, 1);
    } elseif (str_starts_with($nomor, '8')) {
        $nomor = '62' . $nomor;
    }
    return $nomor;
}

$waPesanKaryawan = '';
$waPesanAdmin    = '';
$statusWaKaryawan = false;
$statusWaAdmin    = false;

if (isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

    $nama       = trim($_POST['nama'] ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $password   = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi_password'] ?? '';
    $noWa       = normalisasiNoWA($_POST['no_wa'] ?? '');
    $jabatan    = trim($_POST['jabatan'] ?? '');

    if ($nama === '' || mb_strlen($nama) < 3) { $errors[] = 'Nama lengkap wajib diisi (min. 3 karakter).'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Format email tidak valid.'; }

    $stmt = $koneksi->prepare('SELECT id FROM karyawan WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) { $errors[] = 'Email sudah terdaftar. Silakan gunakan email lain atau login.'; }
    $stmt->close();

    if (strlen($password) < 8) { $errors[] = 'Password minimal 8 karakter.'; }
    if ($password !== $konfirmasi) { $errors[] = 'Konfirmasi password tidak cocok.'; }
    if ($noWa === '' || !preg_match('/^62\d{8,14}$/', $noWa)) { $errors[] = 'Format nomor WhatsApp tidak valid. Contoh: 08123456789 atau 628123456789.'; }
    if ($jabatan === '') { $errors[] = 'Jabatan wajib diisi.'; }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $departemenDefault = '-';
        $roleDefault       = 'karyawan';
        $statusDefault     = 'aktif';

        $stmt = $koneksi->prepare('INSERT INTO karyawan (nama, email, password, no_wa, jabatan, departemen, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssss', $nama, $email, $hash, $noWa, $jabatan, $departemenDefault, $roleDefault, $statusDefault);
        $stmt->execute();
        $stmt->close();

        $waktuDaftar = date('d-m-Y H:i');

        // 1. Kirim WhatsApp ke nomor karyawan pendaftar
        $waPesanKaryawan = "🎉 *PENDAFTARAN AKUN BERHASIL*\n\n"
            . "Halo *{$nama}*,\n"
            . "Selamat! Pendaftaran akun Anda di *" . APP_NAME . "* telah berhasil didaftarkan.\n\n"
            . "📋 *Rincian Akun Terdaftar:*\n"
            . "👤 Nama       : {$nama}\n"
            . "📧 Email      : {$email}\n"
            . "💼 Jabatan    : {$jabatan}\n"
            . "📱 No. WA     : {$noWa}\n"
            . "🕒 Waktu      : {$waktuDaftar} WIB\n\n"
            . "Silakan login menggunakan email dan password yang telah Anda daftarkan untuk mulai melakukan presensi kerja harian.\n\n"
            . "Terima kasih dan selamat beraktivitas! 🙏\n_" . APP_NAME . "_";
        $statusWaKaryawan = kirimWA($noWa, $waPesanKaryawan);

        // 2. Kirim WhatsApp ke nomor admin sebagai notifikasi sistem
        if (ADMIN_WA !== '' && ADMIN_WA !== $noWa) {
            $waPesanAdmin = "🆕 *PENDAFTARAN KARYAWAN BARU*\n\n"
                . "Ada karyawan baru yang mendaftar melalui form registrasi:\n\n"
                . "👤 Nama    : {$nama}\n"
                . "📧 Email   : {$email}\n"
                . "💼 Jabatan : {$jabatan}\n"
                . "📱 No. WA  : {$noWa}\n"
                . "🕒 Waktu   : {$waktuDaftar} WIB\n\n"
                . "Akun langsung aktif dengan role Karyawan.";
            $statusWaAdmin = kirimWA(ADMIN_WA, $waPesanAdmin);
        }

        $pesanSukses = 'Registrasi berhasil! ' . ($statusWaKaryawan 
            ? "Notifikasi WhatsApp telah berhasil dikirim ke nomor Anda (<strong>{$noWa}</strong>). Silakan login." 
            : "Akun Anda sudah aktif. Namun notifikasi WhatsApp gagal terkirim.");
    } else {
        $pesanError = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(APP_NAME) ?> — Registrasi Karyawan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
</head>
<body class="auth-page">
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="logo-badge"><i class="bi bi-person-plus-fill"></i></div>
                <h1 class="auth-title">Daftar Akun</h1>
                <p class="auth-subtitle">Registrasi Karyawan Baru SIAKWA</p>
            </div>

            <?php if ($pesanError !== ''): ?>
                <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= $pesanError ?></div>
            <?php endif; ?>
            <?php if ($pesanSukses !== ''): ?>
                <div class="alert alert-success mb-3"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($pesanSukses) ?></div>
            <?php endif; ?>

            <?php if ($pesanSukses === ''): ?>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="mb-3">
                    <label class="form-label" for="nama">Nama Lengkap</label>
                    <input type="text" class="form-control" id="nama" name="nama" placeholder="Contoh: Budi Santoso" value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="nama@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Min. 8 karakter" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="konfirmasi_password">Konfirmasi</label>
                        <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" placeholder="Ulangi" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="no_wa">Nomor WhatsApp</label>
                    <input type="text" class="form-control" id="no_wa" name="no_wa" placeholder="08xxxxxxxxxx / 628xxxxxxxxxx" value="<?= htmlspecialchars($_POST['no_wa'] ?? '') ?>" required>
                    <div class="form-text small mt-1">Format: 08... atau 628... (terhubung ke Fonnte).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="jabatan">Jabatan</label>
                    <input type="text" class="form-control" id="jabatan" name="jabatan" placeholder="Contoh: Software Engineer" value="<?= htmlspecialchars($_POST['jabatan'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-person-plus me-1"></i>Registrasi Sekarang</button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                Sudah punya akun? <a href="login.php">Login di sini</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
