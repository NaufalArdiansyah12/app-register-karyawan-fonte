<?php
/* ============================================================================
   ADMIN — ABSEN PULANG KARYAWAN
   Halaman khusus bagi Admin untuk mencatatkan absensi pulang karyawan.
   Admin dapat mengetikkan nama karyawan (search/autocomplete/quick-click),
   menentukan jam pulang dan catatan.
   Setelah disimpan, sistem otomatis menghitung durasi kerja dan mengirimkan
   notifikasi resmi via WhatsApp ke nomor karyawan bersangkutan via Fonnte.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

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

$tanggalHariIni = date('Y-m-d');
$jamSekarang    = date('H:i');

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

/* ============================================================================
   PROSES POST: SIMPAN ABSEN PULANG OLEH ADMIN
   ============================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Token keamanan tidak valid. Silakan coba lagi.'];
        header('Location: absen_pulang.php');
        exit;
    }

    $karyawanId = (int) ($_POST['karyawan_id'] ?? 0);
    $tanggal    = trim($_POST['tanggal'] ?? $tanggalHariIni);
    $jamPulang  = trim($_POST['jam_pulang'] ?? $jamSekarang);
    $catatan    = trim($_POST['catatan'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = $tanggalHariIni;
    }
    if ($jamPulang !== '' && strlen($jamPulang) === 5) {
        $jamPulangFormatted = $jamPulang . ':00';
    } else {
        $jamPulangFormatted = date('H:i:s');
    }

    // 1. Ambil data profil karyawan
    $stmt = $koneksi->prepare('SELECT id, nama, email, no_wa, jabatan, departemen FROM karyawan WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $karyawanId);
    $stmt->execute();
    $karyawan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$karyawan) {
        $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Pilih karyawan yang valid terlebih dahulu.'];
        header('Location: absen_pulang.php');
        exit;
    }

    // 2. Periksa catatan absensi hari tersebut
    $stmt = $koneksi->prepare('SELECT id, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? AND tanggal = ? LIMIT 1');
    $stmt->bind_param('is', $karyawanId, $tanggal);
    $stmt->execute();
    $absenLama = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $jamMasukAda = $absenLama['jam_masuk'] ?? null;
    $statusAda   = $absenLama['status'] ?? 'hadir';

    if ($catatan === '') {
        $catatan = 'Absen pulang dicatat oleh Admin';
    }

    if ($absenLama) {
        $stmtUpdate = $koneksi->prepare('UPDATE absensi SET jam_pulang = ?, catatan = ? WHERE id = ?');
        $idLama = (int) $absenLama['id'];
        $stmtUpdate->bind_param('ssi', $jamPulangFormatted, $catatan, $idLama);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    } else {
        // Jika belum ada jam masuk sama sekali, tetap dicatat
        $statusFinal = 'hadir';
        $stmtInsert = $koneksi->prepare('INSERT INTO absensi (karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan) VALUES (?, ?, NULL, ?, ?, ?)');
        $stmtInsert->bind_param('issss', $karyawanId, $tanggal, $jamPulangFormatted, $statusFinal, $catatan);
        $stmtInsert->execute();
        $stmtInsert->close();
    }

    // 3. Susun Informasi Durasi & WhatsApp
    $durasi = hitungDurasiKerja($jamMasukAda, $jamPulangFormatted);
    $jamMasukTampil = $jamMasukAda ? substr($jamMasukAda, 0, 5) . ' WIB' : 'Tidak tercatat';
    $jamPulangTampil = substr($jamPulangFormatted, 0, 5) . ' WIB';
    $tanggalFmt = date('d-m-Y', strtotime($tanggal));
    $waktuInput = date('d-m-Y H:i');

    $labelStatus = match($statusAda) {
        'hadir'     => '✅ Hadir',
        'terlambat' => '⚠️ Terlambat',
        'izin'      => '📝 Izin',
        'sakit'     => '🏥 Sakit',
        default     => ucfirst($statusAda)
    };

    $waPesan = "🏠 *ABSEN PULANG DICATAT OLEH ADMIN*\n\n"
        . "Halo *{$karyawan['nama']}*,\n"
        . "Presensi kepulangan kerja Anda telah dicatat oleh Administrator sistem:\n\n"
        . "📅 Tanggal     : {$tanggalFmt}\n"
        . "⏰ Jam Masuk   : {$jamMasukTampil}\n"
        . "⏰ Jam Pulang  : {$jamPulangTampil}\n"
        . "⏳ Durasi Kerja: {$durasi}\n"
        . "📊 Status      : {$labelStatus}\n"
        . "📝 Keterangan  : {$catatan}\n"
        . "🕒 Dicatat     : {$waktuInput} WIB\n\n"
        . "Terima kasih banyak atas dedikasi dan kerja keras Anda hari ini. Hati-hati di perjalanan pulang! 🙏\n_" . APP_NAME . "_";

    // 4. Kirim WhatsApp via Fonnte
    $statusWa = kirimWA($karyawan['no_wa'], $waPesan);

    $_SESSION['flash'] = [
        'tipe' => $statusWa ? 'success' : 'warning',
        'pesan' => "Absen pulang untuk \"{$karyawan['nama']}\" ({$jamPulangTampil}) berhasil disimpan! Durasi kerja: {$durasi}. "
            . ($statusWa 
                ? "Notifikasi WhatsApp telah dikirim ke nomor {$karyawan['no_wa']}." 
                : "Namun notifikasi WhatsApp gagal terkirim (periksa status Fonnte).")
    ];

    header('Location: absen_pulang.php');
    exit;
}

/* ============================================================================
   DATA UNTUK TAMPILAN
   ============================================================================ */
// Semua karyawan aktif
$listKaryawan = $koneksi->query("SELECT id, nama, email, no_wa, jabatan, departemen FROM karyawan WHERE status = 'aktif' ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC);

// Karyawan yang SEDANG BEKERJA hari ini (Sudah Masuk tapi BELUM Pulang)
$stmtSedangBekerja = $koneksi->prepare("SELECT a.*, k.nama, k.jabatan, k.departemen, k.no_wa 
    FROM absensi a 
    INNER JOIN karyawan k ON k.id = a.karyawan_id 
    WHERE a.tanggal = ? AND a.jam_masuk IS NOT NULL AND (a.jam_pulang IS NULL OR a.jam_pulang = '00:00:00')
    ORDER BY a.jam_masuk ASC");
$stmtSedangBekerja->bind_param('s', $tanggalHariIni);
$stmtSedangBekerja->execute();
$listBelumPulang = $stmtSedangBekerja->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtSedangBekerja->close();

// Karyawan yang SUDAH PULANG hari ini
$stmtSudahPulang = $koneksi->prepare("SELECT a.*, k.nama, k.jabatan, k.departemen, k.no_wa 
    FROM absensi a 
    INNER JOIN karyawan k ON k.id = a.karyawan_id 
    WHERE a.tanggal = ? AND a.jam_pulang IS NOT NULL AND a.jam_pulang <> '00:00:00'
    ORDER BY a.jam_pulang DESC");
$stmtSudahPulang->bind_param('s', $tanggalHariIni);
$stmtSudahPulang->execute();
$listSudahPulang = $stmtSudahPulang->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtSudahPulang->close();

// Flash Notification
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Absen Pulang Karyawan — <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
    <style>
        .quick-badge-btn {
            background: #ffffff;
            border: 2px solid #111111;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: .8rem;
            font-weight: 700;
            color: #111111;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            box-shadow: 2px 2px 0 #111111;
            transition: all .12s ease;
            text-align: left;
        }
        .quick-badge-btn:hover {
            transform: translate(2px, 2px);
            box-shadow: 0 0 0 #111111;
            background: var(--nb-orange);
        }
        .preview-karyawan-card {
            background: var(--nb-surface);
            border: 2.5px solid #111111;
            border-radius: 10px;
            box-shadow: 3px 3px 0 #111111;
            padding: 14px 16px;
        }
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

            <!-- Header & Tab Navigasi Admin -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h2 class="page-title">Absen Pulang Karyawan</h2>
                    <div class="page-sub mb-0">Input dan catat kepulangan kerja karyawan oleh Administrator.</div>
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
                <!-- FORM INPUT ABSEN PULANG -->
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--nb-orange);">
                            <span><i class="bi bi-box-arrow-out-right me-2"></i>Formulir Absen Pulang Karyawan</span>
                            <span class="badge badge-dark"><?= date('d M Y') ?></span>
                        </div>
                        <div class="card-body">
                            <form method="post" action="absen_pulang.php" id="form-absen-pulang">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                <!-- Input Pencarian / Nama Karyawan -->
                                <div class="mb-3">
                                    <label class="form-label">Nama Karyawan <span class="text-danger">*</span></label>
                                    <div class="input-group mb-2">
                                        <input type="text" id="cari-nama-input" class="form-control" list="datalist-karyawan" placeholder="Ketik nama karyawan yang hendak pulang..." autocomplete="off" oninput="handleKaryawanKetik(this.value)">
                                        <button class="btn btn-outline-secondary" type="button" onclick="resetPilihanKaryawan()" title="Reset pilihan">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </div>

                                    <datalist id="datalist-karyawan">
                                        <?php foreach ($listKaryawan as $k): ?>
                                            <option value="<?= htmlspecialchars($k['nama']) ?>" data-id="<?= (int)$k['id'] ?>" data-jabatan="<?= htmlspecialchars($k['jabatan']) ?>" data-dept="<?= htmlspecialchars($k['departemen']) ?>" data-wa="<?= htmlspecialchars($k['no_wa']) ?>">
                                                <?= htmlspecialchars($k['nama']) ?> — <?= htmlspecialchars($k['jabatan']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </datalist>

                                    <!-- Select Fallback / Hidden Real Field -->
                                    <select name="karyawan_id" id="select-karyawan-id" class="form-select" required onchange="handleKaryawanSelect(this.value)">
                                        <option value="">-- Atau Pilih Dari Dropdown --</option>
                                        <?php foreach ($listKaryawan as $k): ?>
                                            <option value="<?= (int) $k['id'] ?>" data-nama="<?= htmlspecialchars($k['nama']) ?>" data-jabatan="<?= htmlspecialchars($k['jabatan']) ?>" data-dept="<?= htmlspecialchars($k['departemen']) ?>" data-wa="<?= htmlspecialchars($k['no_wa']) ?>">
                                                <?= htmlspecialchars($k['nama']) ?> &bull; <?= htmlspecialchars($k['jabatan']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Box Preview Karyawan Terpilih -->
                                <div id="preview-karyawan" class="preview-karyawan-card mb-3 d-none">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="user-cell-avatar" id="prev-avatar" style="width:44px; height:44px; font-size:1.1rem; background:var(--nb-yellow);">K</div>
                                        <div class="flex-grow-1">
                                            <h6 class="fw-bold mb-0 text-dark" id="prev-nama">-</h6>
                                            <div class="small text-muted" id="prev-detail">-</div>
                                            <div class="small mt-1 d-flex flex-wrap align-items-center gap-2">
                                                <span class="text-success fw-bold"><i class="bi bi-whatsapp me-1"></i><span id="prev-wa">-</span></span>
                                                <span class="badge badge-dark" id="prev-jam-masuk">Jam Masuk: -</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Waktu & Jam Pulang -->
                                <div class="row g-2 mb-3">
                                    <div class="col-sm-6">
                                        <label class="form-label">Tanggal Presensi <span class="text-danger">*</span></label>
                                        <input type="date" name="tanggal" class="form-control" value="<?= $tanggalHariIni ?>" required>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label">Jam Pulang <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="time" name="jam_pulang" id="input-jam-pulang" class="form-control" value="<?= $jamSekarang ?>" required>
                                            <button type="button" class="btn btn-outline-secondary" onclick="setJamSekarang()" title="Gunakan jam sekarang">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Keterangan / Catatan</label>
                                    <input type="text" name="catatan" class="form-control" placeholder="Contoh: Selesai shift sore / Lembur 1 jam">
                                </div>

                                <div class="alert alert-info py-2 px-3 small mb-4">
                                    <i class="bi bi-whatsapp me-1 text-success fs-6"></i>Notifikasi hasil absen pulang &amp; kalkulasi durasi kerja akan <strong>langsung dikirim ke nomor WhatsApp karyawan</strong> secara otomatis via Fonnte.
                                </div>

                                <button type="submit" class="btn btn-warning w-100 py-2 fs-6">
                                    <i class="bi bi-box-arrow-out-right me-1"></i>Simpan Absen Pulang &amp; Kirim Notifikasi WA
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- DAFTAR SEDANG BEKERJA (BELUM PULANG) -->
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--nb-cyan);">
                            <span><i class="bi bi-person-workspace me-2"></i>Sedang Bekerja (Belum Pulang)</span>
                            <span class="badge badge-dark"><?= count($listBelumPulang) ?> Orang</span>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">
                                <i class="bi bi-info-circle me-1"></i>Klik pada nama karyawan di bawah untuk langsung mengisi form absen pulang:
                            </p>

                            <?php if (count($listBelumPulang) > 0): ?>
                                <div class="d-flex flex-wrap gap-2" style="max-height: 400px; overflow-y: auto; padding: 4px;">
                                    <?php foreach ($listBelumPulang as $bp): ?>
                                        <button type="button" class="quick-badge-btn" 
                                                data-id="<?= (int)$bp['karyawan_id'] ?>"
                                                data-nama="<?= htmlspecialchars($bp['nama']) ?>"
                                                data-jabatan="<?= htmlspecialchars($bp['jabatan']) ?>"
                                                data-dept="<?= htmlspecialchars($bp['departemen']) ?>"
                                                data-wa="<?= htmlspecialchars($bp['no_wa']) ?>"
                                                data-masuk="<?= htmlspecialchars(substr((string)$bp['jam_masuk'], 0, 5)) ?>"
                                                onclick="pilihKaryawanQuick(this)">
                                            <i class="bi bi-box-arrow-out-right text-warning"></i>
                                            <div>
                                                <strong><?= htmlspecialchars($bp['nama']) ?></strong>
                                                <div class="text-muted" style="font-size: .7rem;">Masuk: <?= htmlspecialchars(substr((string)$bp['jam_masuk'], 0, 5)) ?> WIB</div>
                                            </div>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary py-3 mb-0 text-center">
                                    <i class="bi bi-moon-stars-fill display-6 d-block mb-2 text-warning"></i>
                                    <strong>Tidak Ada Karyawan Aktif yang Belum Pulang</strong><br>
                                    <span class="small text-muted">Seluruh karyawan yang masuk hari ini sudah absen pulang atau belum ada yang absen masuk.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABEL DAFTAR KARYAWAN YANG SUDAH PULANG HARI INI -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-check-all me-2 text-success"></i>Karyawan Yang Sudah Absen Pulang Hari Ini (<?= date('d M Y') ?>)</span>
                    <span class="badge badge-dark"><?= count($listSudahPulang) ?> Selesai</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 48px;" class="text-center">No</th>
                                <th>Karyawan</th>
                                <th class="text-center">Jam Masuk</th>
                                <th class="text-center">Jam Pulang</th>
                                <th class="text-center">Durasi Kerja</th>
                                <th>Keterangan / Catatan</th>
                                <th class="text-center">WhatsApp Karyawan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($listSudahPulang) > 0): ?>
                                <?php foreach ($listSudahPulang as $idx => $row): ?>
                                    <?php 
                                        $namaWords = preg_split('/\s+/', trim($row['nama'])) ?: ['K'];
                                        $inisial = strtoupper(mb_substr($namaWords[0], 0, 1)) . (isset($namaWords[1]) ? strtoupper(mb_substr($namaWords[1], 0, 1)) : '');
                                        $cleanWa = preg_replace('/[^0-9]/', '', (string)$row['no_wa']);
                                        if (str_starts_with($cleanWa, '0')) {
                                            $cleanWa = '62' . substr($cleanWa, 1);
                                        }
                                        $durasiKerja = hitungDurasiKerja($row['jam_masuk'], $row['jam_pulang']);
                                    ?>
                                    <tr>
                                        <td class="text-center text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="user-cell-avatar" style="width:36px; height:36px; font-size:.85rem; background:var(--nb-lime);"><?= htmlspecialchars($inisial) ?></div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama']) ?></div>
                                                    <div class="small text-muted"><?= htmlspecialchars($row['jabatan']) ?> &bull; <?= htmlspecialchars($row['departemen']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?= $row['jam_masuk'] ? '<span class="fw-bold">' . htmlspecialchars(substr($row['jam_masuk'], 0, 5)) . ' WIB</span>' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold text-danger fs-6">
                                                <i class="bi bi-box-arrow-out-right me-1"></i><?= htmlspecialchars(substr($row['jam_pulang'], 0, 5)) ?> WIB
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-dark"><?= $durasiKerja ?></span>
                                        </td>
                                        <td>
                                            <span class="small <?= $row['catatan'] ? 'text-dark' : 'text-muted' ?>">
                                                <?= htmlspecialchars((string) ($row['catatan'] ?? '-')) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($cleanWa !== ''): ?>
                                                <a href="https://wa.me/<?= htmlspecialchars($cleanWa) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Chat WhatsApp Karyawan">
                                                    <i class="bi bi-whatsapp text-success me-1"></i><?= htmlspecialchars($row['no_wa']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="bi bi-hourglass-split fs-2 d-block mb-1"></i>
                                        Belum ada karyawan yang tercatat absen pulang hari ini.
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
        const karyawanData = <?= json_encode(array_values($listKaryawan)) ?>;

        function updatePreview(k, jamMasuk) {
            const box = document.getElementById('preview-karyawan');
            if (!k) {
                box.classList.add('d-none');
                return;
            }
            box.classList.remove('d-none');
            document.getElementById('prev-nama').textContent = k.nama;
            document.getElementById('prev-detail').textContent = `${k.jabatan} • Departemen: ${k.departemen}`;
            document.getElementById('prev-wa').textContent = k.no_wa;
            
            const badgeMasuk = document.getElementById('prev-jam-masuk');
            if (jamMasuk) {
                badgeMasuk.textContent = `Jam Masuk: ${jamMasuk} WIB`;
                badgeMasuk.className = 'badge badge-hadir';
            } else {
                badgeMasuk.textContent = 'Belum Ada Jam Masuk';
                badgeMasuk.className = 'badge badge-dark';
            }

            const words = k.nama.trim().split(/\s+/);
            const inisial = words[0][0] + (words[1] ? words[1][0] : '');
            document.getElementById('prev-avatar').textContent = inisial.toUpperCase();
        }

        function handleKaryawanKetik(query) {
            query = query.trim().toLowerCase();
            if (!query) {
                document.getElementById('select-karyawan-id').value = '';
                updatePreview(null);
                return;
            }
            const match = karyawanData.find(k => k.nama.toLowerCase() === query || k.nama.toLowerCase().includes(query));
            if (match) {
                document.getElementById('select-karyawan-id').value = match.id;
                updatePreview(match, null);
            }
        }

        function handleKaryawanSelect(id) {
            const match = karyawanData.find(k => String(k.id) === String(id));
            if (match) {
                document.getElementById('cari-nama-input').value = match.nama;
                updatePreview(match, null);
            } else {
                document.getElementById('cari-nama-input').value = '';
                updatePreview(null);
            }
        }

        function pilihKaryawanQuick(btn) {
            const id = btn.getAttribute('data-id');
            const masuk = btn.getAttribute('data-masuk');
            const match = karyawanData.find(k => String(k.id) === String(id));
            if (match) {
                document.getElementById('cari-nama-input').value = match.nama;
                document.getElementById('select-karyawan-id').value = match.id;
                updatePreview(match, masuk);
                document.getElementById('input-jam-pulang').focus();
            }
        }

        function resetPilihanKaryawan() {
            document.getElementById('cari-nama-input').value = '';
            document.getElementById('select-karyawan-id').value = '';
            updatePreview(null);
        }

        function setJamSekarang() {
            const now = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date());
            const val = now.replace('.', ':');
            document.getElementById('input-jam-pulang').value = val;
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

