<?php
/* ============================================================================
   ABSENSI — ABSEN MASUK MANDIRI
   Halaman khusus bagi pengguna yang sedang login (baik Karyawan maupun Admin)
   untuk mencatatkan kehadiran masuk kerja DIRINYA SENDIRI.
   Waktu masuk OTOMATIS TERCATAT dari jam sistem saat tombol diklik.
   Pengguna tidak dapat memilih atau mengubah jam secara manual.
   Kategori Hadir / Terlambat ditentukan berdasarkan batas jam masuk dinamis.
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

// Ambil batas jam masuk dari database (dinamis)
$jamBatasMasuk       = getPengaturan($koneksi, 'jam_batas_masuk', defined('JAM_BATAS_MASUK') ? JAM_BATAS_MASUK : '08:00:00');
$jamBatasMasukTampil = substr($jamBatasMasuk, 0, 5);

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
   PROSES POST: SIMPAN ABSEN MASUK MANDIRI (WAKTU OTOMATIS)
   ============================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan coba lagi.'];
        header('Location: absen_masuk.php');
        exit;
    }

    // Waktu masuk otomatis diambil langsung dari jam server saat tombol diklik
    $jamMasukFormatted = date('H:i:s');
    $catatan           = trim($_POST['catatan'] ?? '');

    // Tentukan status kehadiran (Hadir Tepat Waktu atau Terlambat) berdasarkan jam batas dinamis
    $statusFinal = ($jamMasukFormatted <= $jamBatasMasuk) ? 'hadir' : 'terlambat';
    $labelStatus = ($statusFinal === 'hadir') 
        ? '✅ Hadir (Tepat Waktu)' 
        : '⚠️ Terlambat (Batas ' . $jamBatasMasukTampil . ' WIB)';

    if ($catatan === '') {
        $catatan = ($statusFinal === 'terlambat') 
            ? 'Absen masuk terlambat melewati batas ' . $jamBatasMasukTampil . ' WIB' 
            : 'Absen masuk mandiri via sistem';
    }

    // Cek apakah sudah ada catatan absensi hari ini
    if ($absenHariIni && $absenHariIni['jam_masuk']) {
        $_SESSION['flash'] = [
            'tipe' => 'warning',
            'pesan' => "Anda sudah melakukan absen masuk hari ini pada pukul " . substr($absenHariIni['jam_masuk'], 0, 5) . " WIB."
        ];
        header('Location: absen_masuk.php');
        exit;
    }

    if ($absenHariIni) {
        $stmtUpdate = $koneksi->prepare('UPDATE absensi SET jam_masuk = ?, status = ?, catatan = ? WHERE id = ?');
        $idLama = (int) $absenHariIni['id'];
        $stmtUpdate->bind_param('sssi', $jamMasukFormatted, $statusFinal, $catatan, $idLama);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    } else {
        $stmtInsert = $koneksi->prepare('INSERT INTO absensi (karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan) VALUES (?, ?, ?, NULL, ?, ?)');
        $stmtInsert->bind_param('issss', $userId, $tanggalHariIni, $jamMasukFormatted, $statusFinal, $catatan);
        $stmtInsert->execute();
        $stmtInsert->close();
    }

    // Susun Pesan WhatsApp ke Nomor Karyawan / Admin yang Login
    $tanggalFmt = date('d-m-Y', strtotime($tanggalHariIni));
    $jamTampil  = substr($jamMasukFormatted, 0, 5);
    $waktuInput = date('d-m-Y H:i');

    $waPesan = "✅ *ABSEN MASUK BERHASIL*\n\n"
        . "Halo *{$user['nama']}*,\n"
        . "Kehadiran masuk Anda hari ini telah berhasil dicatat:\n\n"
        . "📅 Tanggal    : {$tanggalFmt}\n"
        . "⏰ Jam Masuk  : {$jamTampil} WIB (Otomatis)\n"
        . "📊 Status     : {$labelStatus}\n"
        . "⏰ Batas Masuk: {$jamBatasMasukTampil} WIB\n"
        . "📝 Catatan    : {$catatan}\n"
        . "🕒 Waktu      : {$waktuInput} WIB\n\n"
        . "Semangat bekerja dan semoga aktivitas hari ini lancar! 🙏\n_" . APP_NAME . "_";

    // Kirim notifikasi WhatsApp ke nomor pengguna sendiri
    $statusWa = kirimWA($user['no_wa'], $waPesan);

    // Kirim notifikasi ke Admin jika yang absen adalah karyawan biasa
    if ($user['role'] !== 'admin' && ADMIN_WA !== '' && ADMIN_WA !== $user['no_wa']) {
        $waAdmin = "🔔 *INFORMASI ABSENSI MASUK*\n\n"
            . "Karyawan telah melakukan absen masuk:\n"
            . "👤 {$user['nama']} ({$user['jabatan']})\n"
            . "⏰ Jam: {$jamTampil} WIB\n"
            . "📊 Status: {$labelStatus}";
        kirimWA(ADMIN_WA, $waAdmin);
    }

    $_SESSION['flash'] = [
        'tipe' => $statusWa ? 'success' : 'warning',
        'pesan' => "Absen masuk berhasil dicatat pada {$jamTampil} WIB! Status: {$labelStatus}. "
            . ($statusWa 
                ? "Notifikasi WhatsApp telah dikirimkan ke nomor Anda ({$user['no_wa']})." 
                : "Namun notifikasi WhatsApp gagal terkirim (periksa status Fonnte).")
    ];

    header('Location: absen_masuk.php');
    exit;
}

// 3. Riwayat absen masuk pribadi 7 hari terakhir
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
    <title>Absen Masuk — <?= htmlspecialchars(APP_NAME) ?></title>
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
                    <h2 class="page-title">Absen Masuk</h2>
                    <div class="page-sub mb-0">Halo, <strong><?= htmlspecialchars($user['nama']) ?></strong> — Catat kehadiran masuk kerja Anda hari ini.</div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="absen_masuk.php" class="tab-btn-pill active">
                        <i class="bi bi-box-arrow-in-right text-success"></i>Absen Masuk
                    </a>
                    <a href="absen_pulang.php" class="tab-btn-pill">
                        <i class="bi bi-box-arrow-out-right text-warning"></i>Absen Pulang
                    </a>
                    <span class="badge badge-dark fs-6 py-2 px-3">
                        <i class="bi bi-clock me-1 text-warning"></i><span id="jam-live">--:--:--</span> WIB
                    </span>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- FORM ABSEN MASUK DIRI SENDIRI -->
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--nb-lime);">
                            <span><i class="bi bi-box-arrow-in-right me-2"></i>Formulir Absen Masuk Anda</span>
                            <span class="badge badge-dark"><?= date('d M Y') ?></span>
                        </div>
                        <div class="card-body">
                            <?php if ($absenHariIni && $absenHariIni['jam_masuk']): ?>
                                <div class="alert alert-success p-3 mb-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="bi bi-check-circle-fill fs-1 text-success"></i>
                                        <div>
                                            <h5 class="fw-bold mb-1">Anda Sudah Absen Masuk Hari Ini!</h5>
                                            <div>Jam Masuk: <strong><?= htmlspecialchars(substr($absenHariIni['jam_masuk'], 0, 5)) ?> WIB</strong> &bull; Status: <?= badgeStatus($absenHariIni['status']) ?></div>
                                            <div class="small text-muted mt-1">Batas waktu masuk kantor: <strong><?= htmlspecialchars($jamBatasMasukTampil) ?> WIB</strong>.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-3 border rounded text-center" style="border:2px dashed #111111; background:#ffffff;">
                                    <p class="mb-2 fw-bold">Sudah selesai beraktivitas hari ini?</p>
                                    <a href="absen_pulang.php" class="btn btn-warning">
                                        <i class="bi bi-box-arrow-out-right me-1"></i>Lanjut Ke Halaman Absen Pulang
                                    </a>
                                </div>
                            <?php else: ?>
                                <form method="post" action="absen_masuk.php" id="form-absen-masuk" onsubmit="return confirm('Lakukan Absen Masuk sekarang? Jam masuk akan otomatis dicatat sesuai waktu saat ini.')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                    <div class="row g-2 mb-3">
                                        <div class="col-sm-6">
                                            <label class="form-label">Tanggal Hari Ini</label>
                                            <input type="text" class="form-control fw-bold" value="<?= date('d M Y') ?>" disabled>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label">Waktu Masuk Saat Ini</label>
                                            <div class="p-2 border rounded d-flex align-items-center justify-content-between" style="border:2.5px solid #111111; background:#ffffff;">
                                                <span class="fw-bold fs-5 text-dark">
                                                    <i class="bi bi-clock-history text-success me-1"></i><span id="jam-masuk-live">--:--:--</span> WIB
                                                </span>
                                                <span class="badge badge-dark">Otomatis</span>
                                            </div>
                                            <div class="form-text small">Batas Tepat Waktu: <strong><?= htmlspecialchars($jamBatasMasukTampil) ?> WIB</strong></div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Kategori Status Saat Ini</label>
                                        <div class="p-2 border" style="border:2px solid #111111; border-radius:10px; background:#ffffff;">
                                            <span id="badge-deteksi-status" class="badge badge-hadir fs-6">
                                                <i class="bi bi-check-circle-fill me-1"></i>Hadir (Tepat Waktu)
                                            </span>
                                            <span class="small text-muted ms-2" id="info-deteksi-status">Waktu masuk memenuhi jam kerja.</span>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Keterangan / Catatan (Opsional)</label>
                                        <input type="text" name="catatan" class="form-control" placeholder="Contoh: Bekerja di kantor / dinas pagi">
                                    </div>

                                    <div class="alert alert-info py-2 px-3 small mb-4">
                                        <i class="bi bi-whatsapp me-1 text-success fs-6"></i>Jam masuk otomatis dicatat saat tombol ditekan dan <strong>langsung dikirim ke nomor WhatsApp Anda (<?= htmlspecialchars($user['no_wa']) ?>)</strong>.
                                    </div>

                                    <button type="submit" class="btn btn-success w-100 py-3 fs-6">
                                        <i class="bi bi-box-arrow-in-right me-2"></i>Lakukan Absen Masuk Sekarang
                                    </button>
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
                                <div class="nb-user-avatar" style="width:52px; height:52px; font-size:1.3rem;"><?= htmlspecialchars($inisial) ?></div>
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
                                    <td class="text-muted">Batas Masuk</td>
                                    <td><span class="badge badge-dark"><?= htmlspecialchars($jamBatasMasukTampil) ?> WIB</span></td>
                                </tr>
                            </table>

                            <div class="alert alert-warning py-2 px-3 small mt-4 mb-0">
                                <i class="bi bi-shield-check me-1"></i>Halaman ini terproteksi untuk absensi akun pribadi Anda sendiri.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIWAYAT ABSENSI PRIBADI -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2 text-warning"></i>Riwayat Absen Masuk Pribadi Anda (7 Hari Terakhir)</span>
                    <span class="badge badge-dark"><?= count($riwayat) ?> Hari</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th class="text-center">Jam Masuk</th>
                                <th class="text-center">Jam Pulang</th>
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
                                            <?= $r['jam_masuk'] ? '<span class="fw-bold text-success"><i class="bi bi-box-arrow-in-right me-1"></i>' . htmlspecialchars(substr($r['jam_masuk'], 0, 5)) . ' WIB</span>' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $r['jam_pulang'] ? '<span class="fw-bold text-danger"><i class="bi bi-box-arrow-out-right me-1"></i>' . htmlspecialchars(substr($r['jam_pulang'], 0, 5)) . ' WIB</span>' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-center"><?= badgeStatus($r['status']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars((string) ($r['catatan'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
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
        const batasMasukSetting = '<?= htmlspecialchars($jamBatasMasukTampil) ?>';

        function updateDeteksiStatusRealtime(jam) {
            const badge = document.getElementById('badge-deteksi-status');
            const info  = document.getElementById('info-deteksi-status');
            if (!badge) return;
            if (jam > batasMasukSetting) {
                badge.className = 'badge badge-terlambat fs-6';
                badge.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Terlambat';
                info.textContent = `Waktu saat ini melebihi batas masuk ${batasMasukSetting} WIB.`;
            } else {
                badge.className = 'badge badge-hadir fs-6';
                badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Hadir (Tepat Waktu)';
                info.textContent = `Waktu saat ini tepat sebelum batas ${batasMasukSetting} WIB.`;
            }
        }

        function tickJam() {
            const now = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date());
            const el = document.getElementById('jam-live');
            if (el) { el.textContent = now; }

            const elMasuk = document.getElementById('jam-masuk-live');
            if (elMasuk) { 
                elMasuk.textContent = now; 
                const jamHHMM = now.substring(0, 5).replace('.', ':');
                updateDeteksiStatusRealtime(jamHHMM);
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
