<?php
/* ============================================================================
   AUTH — LOGIN
   Proses login karyawan/admin dengan password_verify + session PHP.
   Session baru di-regenerate untuk menguatkan sid.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../' . ($_SESSION['role'] === 'admin' ? 'admin/index.php' : 'absensi/index.php'));
    exit;
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$pesanError = '';
$pesanInfo  = '';

if (isset($_GET['keluar']) && $_GET['keluar'] === '1') {
    $pesanInfo = 'Anda telah logout. Sampai jumpa kembali!';
}

if (isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $error    = '';

    if ($email === '' || $password === '') {
        $error = 'Email dan password wajib diisi.';
    } else {
        $stmt = $koneksi->prepare('SELECT id, nama, email, password, no_wa, jabatan, departemen, role, status FROM karyawan WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password']) && $user['status'] === 'aktif') {
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int) $user['id'];
            $_SESSION['nama']       = $user['nama'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['no_wa']      = $user['no_wa'];
            $_SESSION['jabatan']    = $user['jabatan'];
            $_SESSION['departemen'] = $user['departemen'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['status_akun'] = $user['status'];

            $stmt = $koneksi->prepare('UPDATE karyawan SET last_login = NOW() WHERE id = ?');
            $idUser = (int) $user['id'];
            $stmt->bind_param('i', $idUser);
            $stmt->execute();
            $stmt->close();

            if ($_SESSION['role'] === 'admin') {
                header('Location: ../admin/index.php');
            } else {
                header('Location: ../absensi/index.php');
            }
            exit;
        } elseif ($user) {
            $error = 'Akun Anda tidak aktif. Hubungi administrator.';
        } else {
            $error = 'Email atau password salah.';
        }
    }

    $pesanError = $error;
}

$tipeAlert = $pesanError !== '' ? 'danger' : '';
$tipeAlertInfo = ($pesanError === '' && $pesanInfo !== '') ? 'info' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(APP_NAME) ?> — Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
</head>
<body class="auth-page">
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="logo-badge"><i class="bi bi-shield-lock"></i></div>
                <h1 class="auth-title">Masuk ke SIAKWA</h1>
                <p class="auth-subtitle">Sistem Register &amp; Absensi Karyawan</p>
            </div>

            <?php if ($pesanError !== ''): ?>
                <div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($pesanError) ?></div>
            <?php elseif ($pesanInfo !== ''): ?>
                <div class="alert alert-info mb-3"><i class="bi bi-info-circle-fill me-1"></i><?= htmlspecialchars($pesanInfo) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="nama@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-box-arrow-in-right me-1"></i>Login</button>
            </form>

            <div class="auth-footer">
                Belum punya akun? <a href="register.php">Daftar di sini</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
