<?php
/* ============================================================================
   ABSENSI — ABSEN PULANG MANDIRI
   Halaman khusus bagi pengguna yang sedang login (baik Karyawan maupun Admin)
   untuk mencatatkan kepulangan kerja DIRINYA SENDIRI.
   Waktu pulang OTOMATIS TERCATAT dari jam sistem saat tombol diklik.
   TIDAK BISA PULANG SEBELUM WAKTUNYA (Sistem mengunci otomatis tombol & menolak
   absen pulang sebelum jam pulang kerja tercapai).
   Notifikasi WhatsApp resmi dikirim langsung ke nomor WhatsApp pengguna via Fonnte.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId         = (int) $_SESSION['user_id'];
$tanggalHariIni = date('Y-m-d');
$waktuSekarang  = date('H:i:s');

// Ambil batas jam pulang dari database (dinamis)
$jamBatasPulang       = getPengaturan($koneksi, 'jam_pulang', defined('JAM_PULANG') ? JAM_PULANG : '17:00:00');
$jamBatasPulangTampil = substr($jamBatasPulang, 0, 5);

// Cek apakah saat ini sudah boleh pulang
$isBelumWaktunyaPulang = ($waktuSekarang < $jamBatasPulang);

function hitungDurasiKerja(?string $masuk, ?string $pulang): string {
    if (!$masuk || !$pulang) return '-';
    $t1 = strtotime($masuk);
    $t2 = strtotime($pulang);
    if ($t2 < $t1) return '-';
    $diff = $t2 - $t1;
    $jam = floor($diff / 3600);
    $menit = floor(($diff % 3600) / 60);
    return "{$jam} Jam {$menit} Menit";
}

// 1. Ambil data profil pengguna yang login
$stmtUser = $koneksi->prepare('SELECT id, nama, email, no_wa, jabatan, departemen, role, status FROM karyawan WHERE id = ? LIMIT 1');
$stmtUser->bind_param('i', $userId);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$user) {
    header('Location: ../auth/logout.php');
    exit;
}

// 2. Cek status absensi hari ini milik pengguna yang login
$stmtAbsen = $koneksi->prepare('SELECT id, tanggal, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? AND tanggal = ? LIMIT 1');
$stmtAbsen->bind_param('is', $userId, $tanggalHariIni);
$stmtAbsen->execute();
$absenHariIni = $stmtAbsen->get_result()->fetch_assoc();
$stmtAbsen->close();

/* ============================================================================
   PROSES POST: SIMPAN ABSEN PULANG MANDIRI (WAKTU OTOMATIS + HARD LOCK)
   ============================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan coba lagi.'];
        header('Location: absen_pulang.php');
        exit;
    }

    if (!$absenHariIni || !$absenHariIni['jam_masuk']) {
        $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Anda belum melakukan absen masuk hari ini. Silakan absen masuk terlebih dahulu.'];
        header('Location: absen_masuk.php');
        exit;
    }

    if ($absenHariIni['jam_pulang'] && $absenHariIni['jam_pulang'] !== '00:00:00') {
        $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Anda sudah melakukan absen pulang hari ini pada pukul ' . substr($absenHariIni['jam_pulang'], 0, 5) . ' WIB.'];
        header('Location: absen_pulang.php');
        exit;
    }

    // Waktu pulang otomatis diambil langsung dari jam server saat tombol diklik
    $jamPulangFormatted = date('H:i:s');
    $catatanPulang      = trim($_POST['catatan'] ?? '');

    // VALIDASI MUTLAK: TIDAK BISA PULANG SEBELUM WAKTUNYA
    if ($jamPulangFormatted < $jamBatasPulang) {
        $jamSekarangTampil = substr($jamPulangFormatted, 0, 5);
        $_SESSION['flash'] = [
            'tipe' => 'danger',
            'pesan' => "Akses Ditolak: Belum waktunya pulang! Jam kepulangan kantor adalah pukul {$jamBatasPulangTampil} WIB (Saat ini masih {$jamSekarangTampil} WIB). Anda tidak diizinkan pulang lebih awal."
        ];
        header('Location: absen_pulang.php');
        exit;
    }

    $catatanBaru = $absenHariIni['catatan'] ? $absenHariIni['catatan'] : 'Absen pulang mandiri via sistem';
    if ($catatanPulang !== '') {
        $catatanBaru .= ' | ' . $catatanPulang;
    }

    // Update jam_pulang di database
    $stmtUpdate = $koneksi->prepare('UPDATE absensi SET jam_pulang = ?, catatan = ? WHERE id = ?');
    $idAbsen = (int) $absenHariIni['id'];
    $stmtUpdate->bind_param('ssi', $jamPulangFormatted, $catatanBaru, $idAbsen);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    // Hitung durasi kerja
    $durasi = hitungDurasiKerja($absenHariIni['jam_masuk'], $jamPulangFormatted);
    $jamMasukTampil  = substr((string)$absenHariIni['jam_masuk'], 0, 5) . ' WIB';
    $jamPulangTampil = substr($jamPulangFormatted, 0, 5) . ' WIB';
    $tanggalFmt      = date('d-m-Y', strtotime($tanggalHariIni));
    $waktuInput      = date('d-m-Y H:i');

    // Susun Pesan WhatsApp ke Nomor Karyawan / Admin yang Login
    $waPesan = "🏠 *ABSEN PULANG BERHASIL*\n\n"
        . "Halo *{$user['nama']}*,\n"
        . "Presensi kepulangan kerja Anda hari ini telah berhasil dicatat:\n\n"
        . "📅 Tanggal      : {$tanggalFmt}\n"
        . "⏰ Jam Masuk    : {$jamMasukTampil}\n"
        . "⏰ Jam Pulang   : {$jamPulangTampil} (Sesuai Jadwal)\n"
        . "⏰ Jadwal Pulang: {$jamBatasPulangTampil} WIB\n"
        . "⏳ Durasi Kerja : {$durasi}\n"
        . "📝 Catatan      : {$catatanBaru}\n"
        . "🕒 Waktu        : {$waktuInput} WIB\n\n"
        . "Terima kasih banyak atas dedikasi dan kerja keras Anda hari ini. Hati-hati di perjalanan pulang! 🙏\n_" . APP_NAME . "_";

    // Kirim WhatsApp ke nomor pengguna sendiri
    $statusWa = kirimWA($user['no_wa'], $waPesan);

    // Kirim notifikasi ke Admin jika yang pulang adalah karyawan biasa
    if ($user['role'] !== 'admin' && ADMIN_WA !== '' && ADMIN_WA !== $user['no_wa']) {
        $waAdmin = "🔔 *INFORMASI ABSENSI PULANG*\n\n"
            . "Karyawan telah melakukan absen pulang:\n"
            . "👤 {$user['nama']} ({$user['jabatan']})\n"
            . "⏰ Masuk: {$jamMasukTampil} | Pulang: {$jamPulangTampil}\n"
            . "⏳ Durasi: {$durasi}";
        kirimWA(ADMIN_WA, $waAdmin);
    }

    $pesanFlashSukses = "Absen pulang berhasil dicatat pada {$jamPulangTampil} WIB! Durasi kerja: {$durasi}. "
        . ($statusWa 
            ? "Notifikasi WhatsApp telah dikirimkan ke nomor Anda ({$user['no_wa']})." 
            : "Namun notifikasi WhatsApp gagal terkirim (periksa status Fonnte).");

    $_SESSION['flash'] = [
        'tipe' => 'success',
        'pesan' => $pesanFlashSukses
    ];

    header('Location: absen_pulang.php');
    exit;
}

// 3. Riwayat absen pribadi 7 hari terakhir
$stmtRiwayat = $koneksi->prepare('SELECT id, tanggal, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? ORDER BY tanggal DESC, id DESC LIMIT 7');
$stmtRiwayat->bind_param('i', $userId);
$stmtRiwayat->execute();
$riwayat = $stmtRiwayat->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtRiwayat->close();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function badgeStatus(string $status): string {
    $label = ['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'izin' => 'Izin', 'sakit' => 'Sakit'];
    $kelas = ['hadir' => 'badge-hadir', 'terlambat' => 'badge-terlambat', 'izin' => 'badge-izin', 'sakit' => 'badge-sakit'];
    $icon  = ['hadir' => 'bi-check-circle-fill', 'terlambat' => 'bi-exclamation-triangle-fill', 'izin' => 'bi-file-earmark-medical-fill', 'sakit' => 'bi-heart-pulse-fill'];
    $teks = $label[$status] ?? ucfirst($status);
    $cls  = $kelas[$status] ?? 'badge-dark';
    $ic   = $icon[$status] ?? 'bi-dot';
    return '<span class="badge ' . htmlspecialchars($cls) . '"><i class="bi ' . htmlspecialchars($ic) . ' me-1"></i>' . htmlspecialchars($teks) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absen Pulang — <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
    <style>
        .tab-btn-pill {
            font-family: 'Archivo Black', sans-serif;
            text-transform: uppercase;
            letter-spacing: .02em;
            padding: 8px 16px;
            border: 2.5px solid #111111;
            border-radius: 10px;
            box-shadow: 3px 3px 0 #111111;
            font-size: .85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .tab-btn-pill.active {
            background: #111111;
            color: var(--nb-yellow);
        }
        .tab-btn-pill:not(.active) {
            background: #ffffff;
            color: #111111;
        }
        .tab-btn-pill:not(.active):hover {
            background: var(--nb-yellow-soft);
            color: #111111;
        }
        .lock-badge-box {
            background: #ffe3e3;
            border: 2.5px solid #111111;
            border-radius: 12px;
            box-shadow: 4px 4px 0 #111111;
            padding: 16px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main app-main--1100">
        <div class="container container-app">
            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['tipe'] ?? 'info') ?> alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-info-circle-fill me-2"></i><?= htmlspecialchars($flash['pesan'] ?? '') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Header Navigasi -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h2 class="page-title">Absen Pulang</h2>
                    <div class="page-sub mb-0">Halo, <strong><?= htmlspecialchars($user['nama']) ?></strong> — Catat kepulangan kerja Anda hari ini.</div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="absen_masuk.php" class="tab-btn-pill">
                        <i class="bi bi-box-arrow-in-right text-success"></i>Absen Masuk
                    </a>
                    <a href="absen_pulang.php" class="tab-btn-pill active">
                        <i class="bi bi-box-arrow-out-right text-warning"></i>Absen Pulang
                    </a>
                    <span class="badge badge-dark fs-6 py-2 px-3">
                        <i class="bi bi-clock me-1 text-warning"></i><span id="jam-live">--:--:--</span> WIB
                    </span>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- FORM ABSEN PULANG DIRI SENDIRI -->
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--nb-orange);">
                            <span><i class="bi bi-box-arrow-out-right me-2"></i>Formulir Absen Pulang Anda</span>
                            <span class="badge badge-dark"><?= date('d M Y') ?></span>
                        </div>
                        <div class="card-body">
                            <?php if (!$absenHariIni || !$absenHariIni['jam_masuk']): ?>
                                <div class="alert alert-warning p-3 mb-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="bi bi-exclamation-triangle-fill fs-1 text-warning"></i>
                                        <div>
                                            <h5 class="fw-bold mb-1">Belum Absen Masuk Hari Ini</h5>
                                            <div>Anda harus melakukan absen masuk terlebih dahulu sebelum dapat mencatatkan absen pulang.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center py-2">
                                    <a href="absen_masuk.php" class="btn btn-success">
                                        <i class="bi bi-box-arrow-in-right me-1"></i>Buka Halaman Absen Masuk Sekarang
                                    </a>
                                </div>
                            <?php elseif ($absenHariIni['jam_pulang'] && $absenHariIni['jam_pulang'] !== '00:00:00'): ?>
                                <div class="alert alert-success p-3 mb-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="bi bi-check-circle-fill fs-1 text-success"></i>
                                        <div>
                                            <h5 class="fw-bold mb-1">Anda Sudah Absen Pulang Hari Ini!</h5>
                                            <div>Jam Pulang: <strong><?= htmlspecialchars(substr($absenHariIni['jam_pulang'], 0, 5)) ?> WIB</strong> &bull; Durasi Kerja: <strong><?= hitungDurasiKerja($absenHariIni['jam_masuk'], $absenHariIni['jam_pulang']) ?></strong></div>
                                            <div class="small text-muted mt-1">Notifikasi WhatsApp kepulangan telah berhasil terkirim ke nomor Anda. Terima kasih atas dedikasi Anda hari ini!</div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Notifikasi Peringatan Terkunci Jika Belum Waktunya Pulang (Realtime) -->
                                <div id="box-peringatan-pulang" class="lock-badge-box mb-4 <?= $isBelumWaktunyaPulang ? '' : 'd-none' ?>">
                                    <div class="d-flex align-items-start gap-3">
                                        <i class="bi bi-shield-lock-fill fs-1 text-danger"></i>
                                        <div>
                                            <h5 class="fw-bold mb-1 text-danger">TIDAK BISA PULANG LEBIH AWAL</h5>
                                            <p class="mb-1 text-dark">
                                                Jam kepulangan kerja resmi adalah pukul <strong><?= htmlspecialchars($jamBatasPulangTampil) ?> WIB</strong>.
                                                Saat ini sistem masih mengunci proses absensi pulang.
                                            </p>
                                            <div class="small fw-bold text-muted">
                                                <i class="bi bi-hourglass-split me-1"></i>Waktu tersisa menuju jam pulang: <span id="countdown-pulang" class="text-danger fw-bold fs-6">Menghitung...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <form method="post" action="absen_pulang.php" id="form-absen-pulang" onsubmit="return handleValidasiPulangSubmit()">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                    <div class="p-3 mb-3 border rounded" style="border:2px solid #111111; background:#ffffff; box-shadow:3px 3px 0 #111111;">
                                        <div class="row g-2 align-items-center">
                                            <div class="col-sm-6">
                                                <div class="text-muted small fw-bold text-uppercase">Jam Masuk Anda</div>
                                                <div class="fw-bold fs-5 text-success">
                                                    <i class="bi bi-box-arrow-in-right me-1"></i><?= htmlspecialchars(substr($absenHariIni['jam_masuk'], 0, 5)) ?> WIB
                                                </div>
                                            </div>
                                            <div class="col-sm-6 text-sm-end">
                                                <div class="text-muted small fw-bold text-uppercase">Jadwal Pulang Resmi</div>
                                                <span class="badge badge-dark fs-6 mt-1"><?= htmlspecialchars($jamBatasPulangTampil) ?> WIB</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-3">
                                        <div class="col-sm-6">
                                            <label class="form-label">Tanggal Hari Ini</label>
                                            <input type="text" class="form-control fw-bold" value="<?= date('d M Y') ?>" disabled>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label">Waktu Pulang Saat Ini</label>
                                            <div class="p-2 border rounded d-flex align-items-center justify-content-between" style="border:2.5px solid #111111; background:#ffffff;">
                                                <span class="fw-bold fs-5 text-dark">
                                                    <i class="bi bi-clock-history text-danger me-1"></i><span id="jam-pulang-live">--:--:--</span> WIB
                                                </span>
                                                <span class="badge badge-dark">Otomatis</span>
                                            </div>
                                            <div class="form-text small">Jadwal Minimal Pulang: <strong><?= htmlspecialchars($jamBatasPulangTampil) ?> WIB</strong></div>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Keterangan / Catatan Pekerjaan (Opsional)</label>
                                        <input type="text" name="catatan" id="input-catatan" class="form-control" placeholder="Contoh: Menyelesaikan tugas harian / shift selesai">
                                    </div>

                                    <div class="alert alert-info py-2 px-3 small mb-4">
                                        <i class="bi bi-whatsapp me-1 text-success fs-6"></i>Jam pulang otomatis dicatat saat tombol ditekan dan <strong>langsung dikirim ke nomor WhatsApp Anda (<?= htmlspecialchars($user['no_wa']) ?>)</strong>.
                                    </div>

                                    <!-- Tombol Terkunci Saat Belum Waktunya Pulang -->
                                    <div id="wrap-btn-locked" class="<?= $isBelumWaktunyaPulang ? '' : 'd-none' ?>">
                                        <button type="button" class="btn btn-secondary w-100 py-3 fs-6" disabled style="opacity:0.75; cursor:not-allowed; background:#868e96; border-color:#111111;">
                                            <i class="bi bi-lock-fill me-2"></i>Belum Waktunya Pulang (Terkunci Hingga <?= htmlspecialchars($jamBatasPulangTampil) ?> WIB)
                                        </button>
                                        <div class="text-center text-muted small mt-2">
                                            Tombol akan terbuka secara otomatis begitu waktu menunjukkan pukul <?= htmlspecialchars($jamBatasPulangTampil) ?> WIB.
                                        </div>
                                    </div>

                                    <!-- Tombol Aktif Saat Sudah Waktunya Pulang -->
                                    <div id="wrap-btn-active" class="<?= $isBelumWaktunyaPulang ? 'd-none' : '' ?>">
                                        <button type="submit" id="btn-submit-pulang" class="btn btn-warning w-100 py-3 fs-6">
                                            <i class="bi bi-box-arrow-out-right me-2"></i>Lakukan Absen Pulang Sekarang
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- INFORMASI PROFIL DIRI -->
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header" style="background: var(--nb-yellow);">
                            <i class="bi bi-person-badge-fill me-2"></i>Informasi Diri Anda
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                                <?php
                                    $words = preg_split('/\s+/', trim($user['nama'])) ?: ['U'];
                                    $inisial = strtoupper(mb_substr($words[0], 0, 1)) . (isset($words[1]) ? strtoupper(mb_substr($words[1], 0, 1)) : '');
                                ?>
                                <div class="nb-user-avatar" style="width:52px; height:52px; font-size:1.3rem; background:var(--nb-yellow);"><?= htmlspecialchars($inisial) ?></div>
                                <div>
                                    <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($user['nama']) ?></h5>
                                    <div class="text-muted small"><?= htmlspecialchars($user['jabatan']) ?> &bull; <?= htmlspecialchars($user['departemen']) ?></div>
                                    <span class="badge badge-karyawan mt-1"><?= ucfirst(htmlspecialchars($user['role'])) ?></span>
                                </div>
                            </div>

                            <table class="table table-borderless mb-0">
                                <tr>
                                    <td class="text-muted" style="width:40%;">Email</td>
                                    <td><strong><?= htmlspecialchars($user['email']) ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">No. WhatsApp</td>
                                    <td>
                                        <strong><?= htmlspecialchars($user['no_wa']) ?></strong>
                                        <i class="bi bi-check-circle-fill text-success ms-1" title="Terkoneksi ke notifikasi WA"></i>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Status Akun</td>
                                    <td><span class="badge badge-aktif"><?= ucfirst(htmlspecialchars($user['status'])) ?></span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Jadwal Pulang</td>
                                    <td><span class="badge badge-dark"><?= htmlspecialchars($jamBatasPulangTampil) ?> WIB</span></td>
                                </tr>
                            </table>

                            <div class="alert alert-warning py-2 px-3 small mt-4 mb-0">
                                <i class="bi bi-shield-check me-1"></i>Halaman ini terproteksi untuk absensi akun pribadi Anda sendiri. Tidak diizinkan pulang sebelum jadwal.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIWAYAT ABSENSI PRIBADI -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2 text-warning"></i>Riwayat Kepulangan Kerja Anda (7 Hari Terakhir)</span>
                    <span class="badge badge-dark"><?= count($riwayat) ?> Hari</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th class="text-center">Jam Masuk</th>
                                <th class="text-center">Jam Pulang</th>
                                <th class="text-center">Durasi Kerja</th>
                                <th class="text-center">Status</th>
                                <th>Keterangan / Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($riwayat) > 0): ?>
                                <?php foreach ($riwayat as $r): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($r['tanggal']) ?></strong></td>
                                        <td class="text-center">
                                            <?= $r['jam_masuk'] ? htmlspecialchars(substr($r['jam_masuk'], 0, 5)) . ' WIB' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $r['jam_pulang'] ? '<span class="fw-bold text-danger"><i class="bi bi-box-arrow-out-right me-1"></i>' . htmlspecialchars(substr($r['jam_pulang'], 0, 5)) . ' WIB</span>' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-dark"><?= hitungDurasiKerja($r['jam_masuk'], $r['jam_pulang']) ?></span>
                                        </td>
                                        <td class="text-center"><?= badgeStatus($r['status']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars((string) ($r['catatan'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-calendar-x fs-2 d-block mb-1"></i>
                                        Belum ada riwayat absensi.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <footer class="footer-app text-center py-3">© <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?></footer>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const jamBatasPulangSetting = '<?= htmlspecialchars($jamBatasPulangTampil) ?>';
        const targetPulangTime = '<?= htmlspecialchars($jamBatasPulang) ?>';
        let currentJamFull = '';

        function cekDanSinkronWaktuPulang(now) {
            const currentHHMM = now.substring(0, 5).replace('.', ':');
            currentJamFull = now.replace('.', ':');

            const boxLock = document.getElementById('box-peringatan-pulang');
            const wrapLocked = document.getElementById('wrap-btn-locked');
            const wrapActive = document.getElementById('wrap-btn-active');
            const spanCountdown = document.getElementById('countdown-pulang');

            // Cek apakah waktu saat ini < jam batas pulang
            if (currentHHMM < jamBatasPulangSetting) {
                if (boxLock) boxLock.classList.remove('d-none');
                if (wrapLocked) wrapLocked.classList.remove('d-none');
                if (wrapActive) wrapActive.classList.add('d-none');

                // Hitung selisih waktu tersisa
                if (spanCountdown) {
                    const todayDate = new Date();
                    const [targetH, targetM, targetS] = targetPulangTime.split(':').map(Number);
                    const targetDate = new Date(todayDate.getFullYear(), todayDate.getMonth(), todayDate.getDate(), targetH, targetM, targetS || 0);
                    
                    const diffMs = targetDate - todayDate;
                    if (diffMs > 0) {
                        const sisaJam = Math.floor(diffMs / 3600000);
                        const sisaMenit = Math.floor((diffMs % 3600000) / 60000);
                        const sisaDetik = Math.floor((diffMs % 60000) / 1000);
                        spanCountdown.textContent = `${sisaJam} Jam ${sisaMenit} Menit ${sisaDetik} Detik lagi`;
                    } else {
                        spanCountdown.textContent = 'Waktu pulang telah tiba!';
                    }
                }
            } else {
                // Waktu pulang sudah tiba! Buka tombol otomatis
                if (boxLock) boxLock.classList.add('d-none');
                if (wrapLocked) wrapLocked.classList.add('d-none');
                if (wrapActive) wrapActive.classList.remove('d-none');
            }
        }

        function handleValidasiPulangSubmit() {
            const currentHHMM = currentJamFull.substring(0, 5);
            if (currentHHMM < jamBatasPulangSetting) {
                alert(
                    `⚠️ AKSES DITOLAK: BELUM WAKTUNYA PULANG!\n\n` +
                    `Jam kepulangan kerja resmi adalah pukul ${jamBatasPulangSetting} WIB.\n` +
                    `Anda tidak dapat melakukan absen pulang lebih awal sebelum jam tersebut.`
                );
                return false;
            }

            return confirm('Lakukan Absen Pulang sekarang? Jam kepulangan akan otomatis dicatat.');
        }

        function tickJam() {
            const now = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date());
            const el = document.getElementById('jam-live');
            if (el) { el.textContent = now; }

            const elPulang = document.getElementById('jam-pulang-live');
            if (elPulang) { 
                elPulang.textContent = now; 
                cekDanSinkronWaktuPulang(now);
            }
        }
        setInterval(tickJam, 1000);
        tickJam();

        setTimeout(function() {
            document.querySelectorAll('.alert-dismissible').forEach(function(el) {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
                bsAlert.close();
            });
        }, 6000);
    </script>
</body>
</html>
