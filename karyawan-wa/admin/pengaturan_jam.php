<?php
/* ============================================================================
   ADMIN — PENGATURAN JAM KERJA & PRESENSI
   Halaman khusus bagi Administrator untuk mengatur batas jam absensi masuk
   dan jam minimal absensi pulang.
   Nilai ini akan disimpan secara dinamis ke database dan berlaku untuk seluruh
   karyawan dan admin.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Akses ditolak. Halaman ini khusus administrator.'];
    header('Location: ../absensi/index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Nilai saat ini (dari database atau fallback ke config)
$jamMasukSekarang  = getPengaturan($koneksi, 'jam_batas_masuk', defined('JAM_BATAS_MASUK') ? JAM_BATAS_MASUK : '08:00:00');
$jamPulangSekarang = getPengaturan($koneksi, 'jam_pulang', defined('JAM_PULANG') ? JAM_PULANG : '17:00:00');

// Ambil format HH:MM untuk input type="time"
$valJamMasuk  = substr($jamMasukSekarang, 0, 5);
$valJamPulang = substr($jamPulangSekarang, 0, 5);

function formatLabelJam(string $hhmm): string {
    if (!$hhmm) return '-';
    $parts = explode(':', $hhmm);
    $h = (int)($parts[0] ?? 0);
    $m = $parts[1] ?? '00';
    
    if ($h === 0) {
        return "00:{$m} (Tengah Malam)";
    } elseif ($h < 11) {
        return "{$hhmm} (Pukul {$h}:{$m} Pagi)";
    } elseif ($h < 15) {
        $jam12 = $h > 12 ? $h - 12 : $h;
        return "{$hhmm} (Pukul {$jam12}:{$m} Siang)";
    } elseif ($h < 18) {
        $jam12 = $h - 12;
        return "{$hhmm} (Pukul {$jam12}:{$m} Sore)";
    } else {
        $jam12 = $h - 12;
        return "{$hhmm} (Pukul {$jam12}:{$m} Malam)";
    }
}

/* ============================================================================
   PROSES POST: SIMPAN PENGATURAN JAM KERJA
   ============================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan coba lagi.'];
        header('Location: pengaturan_jam.php');
        exit;
    }

    $inputMasuk  = trim($_POST['jam_batas_masuk'] ?? '08:00');
    $inputPulang = trim($_POST['jam_pulang'] ?? '17:00');

    if (!preg_match('/^\d{2}:\d{2}$/', $inputMasuk) || !preg_match('/^\d{2}:\d{2}$/', $inputPulang)) {
        $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Format jam tidak valid. Gunakan format jam yang benar (HH:MM).'];
        header('Location: pengaturan_jam.php');
        exit;
    }

    $masukSimpan  = $inputMasuk . ':00';
    $pulangSimpan = $inputPulang . ':00';

    setPengaturan($koneksi, 'jam_batas_masuk', $masukSimpan);
    setPengaturan($koneksi, 'jam_pulang', $pulangSimpan);

    $labelMasukText  = formatLabelJam($inputMasuk);
    $labelPulangText = formatLabelJam($inputPulang);

    $_SESSION['flash'] = [
        'tipe' => 'success',
        'pesan' => "Pengaturan jam kerja berhasil diperbarui! Batas Masuk: {$labelMasukText} | Batas Pulang: {$labelPulangText}."
    ];

    header('Location: pengaturan_jam.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Jam Kerja — <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main app-main--900">
        <div class="container container-app">
            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['tipe'] ?? 'info') ?> alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-info-circle-fill me-2"></i><?= htmlspecialchars($flash['pesan'] ?? '') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <h2 class="page-title">Pengaturan Jam Kerja</h2>
                    <div class="page-sub mb-0">Atur batas toleransi jam masuk dan jam minimal kepulangan kerja karyawan.</div>
                </div>
                <span class="badge badge-dark fs-6 py-2 px-3">
                    <i class="bi bi-clock me-1 text-warning"></i><span id="jam-live">--:--:--</span> WIB
                </span>
            </div>

            <div class="row g-4 mb-4">
                <!-- FORM PENGATURAN -->
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header" style="background: var(--nb-yellow);">
                            <i class="bi bi-sliders me-2"></i>Konfigurasi Jam Presensi (Format 24 Jam)
                        </div>
                        <div class="card-body">
                            <form method="post" action="pengaturan_jam.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                <!-- Info Format 24 Jam -->
                                <div class="alert alert-info py-2 px-3 small mb-4">
                                    <i class="bi bi-info-circle-fill me-1 text-dark"></i>
                                    <strong>Perhatian:</strong> Sistem menggunakan format <strong>24 Jam</strong>.<br>
                                    &bull; Jam 8 pagi &rarr; <code>08:00</code><br>
                                    &bull; Jam 1 siang &rarr; <code>13:00</code> (bukan 01:00 yang berarti dini hari)<br>
                                    &bull; Jam 1:30 siang &rarr; <code>13:30</code><br>
                                    &bull; Jam 5 sore &rarr; <code>17:00</code>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">
                                        <i class="bi bi-box-arrow-in-right text-success me-1"></i>Batas Jam Masuk (Tepat Waktu) <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="time" name="jam_batas_masuk" id="input-jam-masuk" class="form-control form-control-lg fw-bold" value="<?= htmlspecialchars($valJamMasuk) ?>" required onchange="updateLabelJam('input-jam-masuk', 'preview-ket-masuk')">
                                        <span class="input-group-text bg-light fw-bold">WIB</span>
                                    </div>
                                    <div class="small fw-bold text-primary mt-1" id="preview-ket-masuk">
                                        <?= formatLabelJam($valJamMasuk) ?>
                                    </div>
                                    <div class="d-flex gap-1 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-masuk', '08:00', 'preview-ket-masuk')">08:00 Pagi</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-masuk', '08:30', 'preview-ket-masuk')">08:30 Pagi</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-masuk', '13:00', 'preview-ket-masuk')">13:00 Siang</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-masuk', '13:30', 'preview-ket-masuk')">13:30 Siang</button>
                                    </div>
                                    <div class="form-text mt-2 small">
                                        <i class="bi bi-info-circle me-1"></i>Karyawan yang melakukan absen masuk <strong>melebihi jam ini</strong> akan otomatis dikategorikan sebagai <strong class="text-danger">Terlambat</strong>.
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">
                                        <i class="bi bi-box-arrow-out-right text-warning me-1"></i>Jam Minimal Pulang Kerja <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="time" name="jam_pulang" id="input-jam-pulang" class="form-control form-control-lg fw-bold" value="<?= htmlspecialchars($valJamPulang) ?>" required onchange="updateLabelJam('input-jam-pulang', 'preview-ket-pulang')">
                                        <span class="input-group-text bg-light fw-bold">WIB</span>
                                    </div>
                                    <div class="small fw-bold text-danger mt-1" id="preview-ket-pulang">
                                        <?= formatLabelJam($valJamPulang) ?>
                                    </div>
                                    <div class="d-flex gap-1 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-pulang', '16:00', 'preview-ket-pulang')">16:00 Sore</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-pulang', '17:00', 'preview-ket-pulang')">17:00 Sore</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-pulang', '17:30', 'preview-ket-pulang')">17:30 Sore</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: .75rem;" onclick="setPreset('input-jam-pulang', '21:00', 'preview-ket-pulang')">21:00 Malam</button>
                                    </div>
                                    <div class="form-text mt-2 small">
                                        <i class="bi bi-lock-fill text-danger me-1"></i><strong>Karyawan tidak dapat melakukan absen pulang sebelum jam ini tercapai</strong> (tombol terkunci otomatis).
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-3 fs-6">
                                    <i class="bi bi-save-fill me-2"></i>Simpan Pengaturan Jam Kerja
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- PREVIEW KEBIJAKAN SAAT INI -->
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header" style="background: var(--nb-cyan);">
                            <i class="bi bi-calendar2-week-fill me-2"></i>Kebijakan Aktif Sistem
                        </div>
                        <div class="card-body">
                            <div class="p-3 mb-3 border rounded" style="border: 2.5px solid #111111; background: #ffffff; box-shadow: 3px 3px 0 #111111;">
                                <div class="small text-muted fw-bold text-uppercase">Batas Masuk Kerja</div>
                                <div class="fw-bold fs-4 text-success mt-1">
                                    <i class="bi bi-alarm me-1"></i><?= htmlspecialchars($valJamMasuk) ?> WIB
                                </div>
                                <div class="small fw-bold text-dark mt-1">
                                    <?= formatLabelJam($valJamMasuk) ?>
                                </div>
                                <div class="small text-muted mt-2">&le; <?= htmlspecialchars($valJamMasuk) ?> : Hadir Tepat Waktu<br>&gt; <?= htmlspecialchars($valJamMasuk) ?> : Terlambat</div>
                            </div>

                            <div class="p-3 mb-3 border rounded" style="border: 2.5px solid #111111; background: #ffffff; box-shadow: 3px 3px 0 #111111;">
                                <div class="small text-muted fw-bold text-uppercase">Jam Minimal Pulang</div>
                                <div class="fw-bold fs-4 text-danger mt-1">
                                    <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($valJamPulang) ?> WIB
                                </div>
                                <div class="small fw-bold text-dark mt-1">
                                    <?= formatLabelJam($valJamPulang) ?>
                                </div>
                                <div class="small text-muted mt-2">&lt; <?= htmlspecialchars($valJamPulang) ?> : <strong>Terkunci (Tidak Bisa Pulang)</strong><br>&ge; <?= htmlspecialchars($valJamPulang) ?> : Terbuka (Boleh Pulang)</div>
                            </div>

                            <div class="alert alert-warning py-2 px-3 small mb-0">
                                <i class="bi bi-shield-lock-fill me-1"></i>Karyawan tidak akan dapat menekan tombol absen pulang sebelum jam minimal tercapai.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer-app text-center py-3">© <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?></footer>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function labelJamIndo(hhmm) {
            if (!hhmm) return '';
            const parts = hhmm.split(':');
            const h = parseInt(parts[0], 10);
            const m = parts[1] || '00';
            if (h === 0) return `00:${m} (Tengah Malam)`;
            if (h < 11) return `${hhmm} (Pukul ${h}:${m} Pagi)`;
            if (h < 15) {
                const j12 = h > 12 ? h - 12 : h;
                return `${hhmm} (Pukul ${j12}:${m} Siang)`;
            }
            if (h < 18) {
                const j12 = h - 12;
                return `${hhmm} (Pukul ${j12}:${m} Sore)`;
            }
            const j12 = h - 12;
            return `${hhmm} (Pukul ${j12}:${m} Malam)`;
        }

        function updateLabelJam(inputId, targetId) {
            const val = document.getElementById(inputId).value;
            document.getElementById(targetId).textContent = labelJamIndo(val);
        }

        function setPreset(inputId, val, targetId) {
            document.getElementById(inputId).value = val;
            updateLabelJam(inputId, targetId);
        }

        function tickJam() {
            const now = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date());
            const el = document.getElementById('jam-live');
            if (el) { el.textContent = now; }
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
