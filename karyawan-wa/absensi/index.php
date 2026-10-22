<?php
/* ============================================================================
   ABSENSI — INDEX
   Jika Admin    : Halaman Kelola Presensi Karyawan (monitoring absensi seluruh
                   karyawan, filter tanggal/status/karyawan, statistik harian,
                   input/koreksi manual via modal, export Excel & cetak).
   Jika Karyawan : Portal Presensi Mandiri (info profil, status absensi hari ini,
                   tombol absen masuk/pulang, modal izin/sakit, riwayat pribadi).
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/fonnte.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$userId  = (int) $_SESSION['user_id'];
$tanggalHariIni = date('Y-m-d');

function badgeStatus(string $status): string {
    $label = [
        'hadir'     => 'Hadir',
        'terlambat' => 'Terlambat',
        'izin'      => 'Izin',
        'sakit'     => 'Sakit'
    ];
    $kelas = [
        'hadir'     => 'badge-hadir',
        'terlambat' => 'badge-terlambat',
        'izin'      => 'badge-izin',
        'sakit'     => 'badge-sakit'
    ];
    $icon = [
        'hadir'     => 'bi-check-circle-fill',
        'terlambat' => 'bi-exclamation-triangle-fill',
        'izin'      => 'bi-file-earmark-medical-fill',
        'sakit'     => 'bi-heart-pulse-fill'
    ];
    $teks = $label[$status] ?? ucfirst($status);
    $cls  = $kelas[$status] ?? 'badge-dark';
    $ic   = $icon[$status] ?? 'bi-dot';
    return '<span class="badge ' . htmlspecialchars($cls) . '"><i class="bi ' . htmlspecialchars($ic) . ' me-1"></i>' . htmlspecialchars($teks) . '</span>';
}

function formatTanggalIndo(string $date): string {
    if (!$date || $date === '0000-00-00') return '-';
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts = strtotime($date);
    $w = (int) date('w', $ts);
    $d = (int) date('j', $ts);
    $m = (int) date('n', $ts);
    $y = date('Y', $ts);
    return ($hari[$w] ?? '') . ', ' . $d . ' ' . ($bulan[$m] ?? '') . ' ' . $y;
}

/* ============================================================================
   LOGIKA ROLE ADMIN: KELOLA PRESENSI KARYAWAN
   ============================================================================ */
if ($isAdmin) {
    // 1. Parameter Filter
    $dari       = $_GET['dari'] ?? $tanggalHariIni;
    $sampai     = $_GET['sampai'] ?? $tanggalHariIni;
    $karyawanId = (int) ($_GET['karyawan_id'] ?? 0);
    $statusFilt = trim($_GET['status'] ?? '');
    $keyword    = trim($_GET['q'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari))   { $dari   = $tanggalHariIni; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) { $sampai = $tanggalHariIni; }

    // 2. Export Excel jika diminta
    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        $kondisiExp = [];
        $paramExp   = [];
        $tipeExp    = '';

        $kondisiExp[] = 'a.tanggal BETWEEN ? AND ?';
        $paramExp[]   = $dari;
        $paramExp[]   = $sampai;
        $tipeExp     .= 'ss';

        if ($karyawanId > 0) {
            $kondisiExp[] = 'a.karyawan_id = ?';
            $paramExp[]   = $karyawanId;
            $tipeExp     .= 'i';
        }
        if ($statusFilt !== '') {
            $kondisiExp[] = 'a.status = ?';
            $paramExp[]   = $statusFilt;
            $tipeExp     .= 's';
        }
        if ($keyword !== '') {
            $kondisiExp[] = '(k.nama LIKE ? OR k.jabatan LIKE ? OR k.departemen LIKE ?)';
            $likeStr      = '%' . $keyword . '%';
            $paramExp[]   = $likeStr;
            $paramExp[]   = $likeStr;
            $paramExp[]   = $likeStr;
            $tipeExp     .= 'sss';
        }

        $sqlExp = 'SELECT a.*, k.nama, k.no_wa, k.jabatan, k.departemen, k.email 
                   FROM absensi a 
                   INNER JOIN karyawan k ON k.id = a.karyawan_id 
                   WHERE ' . implode(' AND ', $kondisiExp) . ' 
                   ORDER BY a.tanggal DESC, a.jam_masuk DESC, a.id DESC';

        $stmtExp = $koneksi->prepare($sqlExp);
        if ($tipeExp !== '') {
            $stmtExp->bind_param($tipeExp, ...$paramExp);
        }
        $stmtExp->execute();
        $dataExp = $stmtExp->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtExp->close();

        $filename = 'rekap_presensi_' . $dari . '_sd_' . $sampai . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                table { border-collapse: collapse; width: 100%; font-family: sans-serif; font-size: 12px; }
                th, td { border: 1px solid #111111; padding: 8px; text-align: left; }
                th { background-color: #ffd02f; color: #111111; font-weight: bold; }
                .text-center { text-align: center; }
            </style>
        </head>
        <body>
            <h3 style="margin-bottom:4px;">REKAPITULASI PRESENSI KARYAWAN</h3>
            <p style="margin-top:0; font-size:12px;">Periode: <?= htmlspecialchars($dari) ?> s/d <?= htmlspecialchars($sampai) ?> | Digenerate: <?= date('d-m-Y H:i:s') ?> WIB</p>
            <table>
                <thead>
                    <tr>
                        <th class="text-center">No</th>
                        <th>Tanggal</th>
                        <th>Nama Karyawan</th>
                        <th>Jabatan</th>
                        <th>Departemen</th>
                        <th>No. WhatsApp</th>
                        <th class="text-center">Jam Masuk</th>
                        <th class="text-center">Jam Pulang</th>
                        <th class="text-center">Status</th>
                        <th>Keterangan / Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($dataExp) > 0): ?>
                        <?php foreach ($dataExp as $idx => $row): ?>
                            <tr>
                                <td class="text-center"><?= $idx + 1 ?></td>
                                <td><?= htmlspecialchars($row['tanggal']) ?></td>
                                <td><strong><?= htmlspecialchars($row['nama']) ?></strong></td>
                                <td><?= htmlspecialchars($row['jabatan']) ?></td>
                                <td><?= htmlspecialchars($row['departemen']) ?></td>
                                <td>'<?= htmlspecialchars($row['no_wa']) ?></td>
                                <td class="text-center"><?= $row['jam_masuk'] ? htmlspecialchars(substr($row['jam_masuk'], 0, 5)) : '-' ?></td>
                                <td class="text-center"><?= $row['jam_pulang'] ? htmlspecialchars(substr($row['jam_pulang'], 0, 5)) : '-' ?></td>
                                <td class="text-center"><?= strtoupper(htmlspecialchars($row['status'])) ?></td>
                                <td><?= htmlspecialchars((string) ($row['catatan'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center">Tidak ada data presensi pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </body>
        </html>
        <?php
        exit;
    }

    // 3. Ambil List Karyawan Aktif untuk Dropdown Filter & Modal
    $listKaryawanAktif = $koneksi->query("SELECT id, nama, jabatan, departemen, no_wa FROM karyawan WHERE status = 'aktif' ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC);

    // 4. Statistik Hari Ini
    $stmtAktif = $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'aktif'");
    $totalKaryawanAktif = (int) ($stmtAktif->fetch_assoc()['c'] ?? 0);

    $stmtStat = $koneksi->prepare("SELECT 
        SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as jml_hadir,
        SUM(CASE WHEN status = 'terlambat' THEN 1 ELSE 0 END) as jml_terlambat,
        SUM(CASE WHEN status IN ('izin', 'sakit') THEN 1 ELSE 0 END) as jml_izin_sakit,
        COUNT(DISTINCT karyawan_id) as total_sudah_absen
        FROM absensi WHERE tanggal = ?");
    $stmtStat->bind_param('s', $tanggalHariIni);
    $stmtStat->execute();
    $statHariIni = $stmtStat->get_result()->fetch_assoc();
    $stmtStat->close();

    $jmlHadir       = (int) ($statHariIni['jml_hadir'] ?? 0);
    $jmlTerlambat   = (int) ($statHariIni['jml_terlambat'] ?? 0);
    $jmlIzinSakit   = (int) ($statHariIni['jml_izin_sakit'] ?? 0);
    $totalSudahHari = (int) ($statHariIni['total_sudah_absen'] ?? 0);
    $jmlBelumAbsen  = max(0, $totalKaryawanAktif - $totalSudahHari);

    // 5. Query Data Presensi sesuai Filter
    $kondisi = [];
    $param   = [];
    $tipe    = '';

    $kondisi[] = 'a.tanggal BETWEEN ? AND ?';
    $param[]   = $dari;
    $param[]   = $sampai;
    $tipe     .= 'ss';

    if ($karyawanId > 0) {
        $kondisi[] = 'a.karyawan_id = ?';
        $param[]   = $karyawanId;
        $tipe     .= 'i';
    }
    if ($statusFilt !== '') {
        $kondisi[] = 'a.status = ?';
        $param[]   = $statusFilt;
        $tipe     .= 's';
    }
    if ($keyword !== '') {
        $kondisi[] = '(k.nama LIKE ? OR k.jabatan LIKE ? OR k.departemen LIKE ?)';
        $likeStr   = '%' . $keyword . '%';
        $param[]   = $likeStr;
        $param[]   = $likeStr;
        $param[]   = $likeStr;
        $tipe     .= 'sss';
    }

    $sqlData = 'SELECT a.*, k.nama, k.no_wa, k.jabatan, k.departemen, k.email 
                FROM absensi a 
                INNER JOIN karyawan k ON k.id = a.karyawan_id 
                WHERE ' . implode(' AND ', $kondisi) . ' 
                ORDER BY a.tanggal DESC, a.jam_masuk DESC, a.id DESC';

    $stmtData = $koneksi->prepare($sqlData);
    if ($tipe !== '') {
        $stmtData->bind_param($tipe, ...$param);
    }
    $stmtData->execute();
    $listPresensi = $stmtData->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtData->close();
}

/* ============================================================================
   LOGIKA ROLE KARYAWAN: PORTAL PRESENSI MANDIRI
   ============================================================================ */
else {
    $stmtUser = $koneksi->prepare('SELECT id, nama, email, no_wa, jabatan, departemen, status FROM karyawan WHERE id = ? LIMIT 1');
    $stmtUser->bind_param('i', $userId);
    $stmtUser->execute();
    $user = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();

    // Absensi hari ini milik karyawan yang login
    $stmtAbsen = $koneksi->prepare('SELECT id, karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? AND tanggal = ? LIMIT 1');
    $stmtAbsen->bind_param('is', $userId, $tanggalHariIni);
    $stmtAbsen->execute();
    $absenHariIni = $stmtAbsen->get_result()->fetch_assoc();
    $stmtAbsen->close();

    // Riwayat 7 hari terakhir
    $stmtRiwayat = $koneksi->prepare('SELECT id, tanggal, jam_masuk, jam_pulang, status, catatan FROM absensi WHERE karyawan_id = ? ORDER BY tanggal DESC, id DESC LIMIT 10');
    $stmtRiwayat->bind_param('i', $userId);
    $stmtRiwayat->execute();
    $riwayat = $stmtRiwayat->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtRiwayat->close();

    // POST Izin / Sakit dari Karyawan
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $tipe = $_POST['tipe'] ?? '';
            $keterangan = trim($_POST['keterangan'] ?? '');

            if ($tipe === 'izin' || $tipe === 'sakit') {
                if ($keterangan === '' || mb_strlen($keterangan) < 5) {
                    $_SESSION['flash'] = ['tipe' => 'danger', 'pesan' => 'Keterangan izin/sakit wajib diisi (min. 5 karakter).'];
                } elseif ($absenHariIni) {
                    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Masih terdapat catatan absensi hari ini. Tidak dapat mengajukan izin/sakit.'];
                } else {
                    $stmtInsert = $koneksi->prepare('INSERT INTO absensi (karyawan_id, tanggal, jam_masuk, jam_pulang, status, catatan) VALUES (?, ?, NULL, NULL, ?, ?)');
                    $stmtInsert->bind_param('isss', $userId, $tanggalHariIni, $tipe, $keterangan);
                    $stmtInsert->execute();
                    $stmtInsert->close();

                    $namaKaryawan    = $user['nama'] ?? 'Karyawan';
                    $jabatanKaryawan = $user['jabatan'] ?? '-';
                    $noWaKaryawan    = $user['no_wa'] ?? '';
                    $waktu           = date('d-m-Y H:i');

                    $waPesanKaryawan = "🙏 *PENGAJUAN " . strtoupper($tipe) . " DICATAT*\n\n"
                        . "Halo *{$namaKaryawan}*,\n"
                        . "Pengajuan Anda hari ini ({$tanggalHariIni}) telah tersimpan:\n\n"
                        . "📌 Jenis      : *" . strtoupper($tipe) . "*\n"
                        . "📝 Keterangan : {$keterangan}\n\n"
                        . "🕒 {$waktu} WIB\n\n"
                        . "Semoga lekas sembuh / urusan berjalan lancar. 🙏\n_" . APP_NAME . "_";
                    $statusWaKaryawan = kirimWA($noWaKaryawan, $waPesanKaryawan);

                    $waPesanAdmin = "🔔 *NOTIFIKASI PENGAJUAN " . strtoupper($tipe) . "*\n\n"
                        . "Karyawan mengajukan " . strtoupper($tipe) . ":\n\n"
                        . "👤 Nama       : {$namaKaryawan}\n"
                        . "💼 Jabatan    : {$jabatanKaryawan}\n"
                        . "📅 Tanggal    : {$tanggalHariIni}\n"
                        . "📝 Keterangan : {$keterangan}";
                    $statusWaAdmin = kirimWA(ADMIN_WA, $waPesanAdmin);

                    $_SESSION['flash'] = [
                        'tipe' => $statusWaKaryawan ? 'success' : 'warning',
                        'pesan' => 'Pengajuan ' . $tipe . ' berhasil dicatat! ' . ($statusWaKaryawan ? 'Notifikasi WhatsApp telah dikirim ke nomor Anda.' : 'Notifikasi WhatsApp gagal dikirim.')
                    ];
                }
            }
        }
        header('Location: index.php');
        exit;
    }
}

// Flash notification
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$tipeAlert  = ($flash !== null) ? (string) $flash['tipe'] : '';
$pesanFlash = ($flash !== null) ? (string) ($flash['pesan'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdmin ? 'Kelola Presensi Karyawan' : 'Presensi Mandiri' ?> — <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
    <style>
        .filter-box {
            background: #ffffff;
            border: 3px solid #111111;
            border-radius: var(--nb-radius);
            box-shadow: var(--shadow-sm);
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .user-cell-avatar {
            width: 38px;
            height: 38px;
            background: var(--nb-pink);
            border: 2px solid #111111;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: .85rem;
            color: #111111;
            flex: none;
        }
        @media print {
            .nb-sidebar, .nb-topbar, .btn, .filter-box, .badge-dark, .modal, .footer-app { display: none !important; }
            .app-main { margin-left: 0 !important; padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #111111 !important; }
            .table { font-size: 11px !important; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main <?= $isAdmin ? 'app-main--1100' : 'app-main--1100' ?>">
        <div class="container container-app">
            <?php if ($tipeAlert !== '' && $pesanFlash !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($tipeAlert) ?> alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-info-circle-fill me-2"></i><?= htmlspecialchars($pesanFlash) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
            <!-- ============================================================ -->
            <!-- TAMPILAN ADMIN: KELOLA PRESENSI KARYAWAN                     -->
            <!-- ============================================================ -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h2 class="page-title">Kelola Presensi Karyawan</h2>
                    <div class="page-sub mb-0">Monitoring data absensi seluruh karyawan, rekapitulasi, &amp; input presensi manual.</div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="../admin/absen_masuk.php" class="btn btn-success">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Absen Masuk
                    </a>
                    <a href="../admin/absen_pulang.php" class="btn btn-warning">
                        <i class="bi bi-box-arrow-out-right me-1"></i>Absen Pulang
                    </a>
                    <a href="?export=excel&dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&karyawan_id=<?= (int)$karyawanId ?>&status=<?= urlencode($statusFilt) ?>&q=<?= urlencode($keyword) ?>" class="btn btn-outline-secondary" title="Download Excel">
                        <i class="bi bi-file-earmark-excel-fill me-1"></i>Export
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()" title="Cetak Rekap">
                        <i class="bi bi-printer-fill me-1"></i>Cetak
                    </button>
                    <span class="badge badge-dark fs-6 py-2 px-3">
                        <i class="bi bi-clock me-1 text-warning"></i><span id="jam-live">--:--:--</span> WIB
                    </span>
                </div>
            </div>

            <!-- Kartu Statistik Hari Ini -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg">
                    <div class="stat-card" style="border-left-color: var(--nb-cyan);">
                        <div class="stat-num text-dark"><?= $totalKaryawanAktif ?></div>
                        <div class="stat-label">Total Karyawan Aktif</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg">
                    <div class="stat-card green">
                        <div class="stat-num" style="color: #2b8a3e;"><?= $jmlHadir ?></div>
                        <div class="stat-label">Hadir Tepat Waktu</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg">
                    <div class="stat-card orange">
                        <div class="stat-num" style="color: #d9480f;"><?= $jmlTerlambat ?></div>
                        <div class="stat-label">Terlambat Hari Ini</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg">
                    <div class="stat-card purple">
                        <div class="stat-num" style="color: #6741d9;"><?= $jmlIzinSakit ?></div>
                        <div class="stat-label">Izin / Sakit</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg">
                    <div class="stat-card" style="border-left-color: var(--nb-red);">
                        <div class="stat-num text-danger"><?= $jmlBelumAbsen ?></div>
                        <div class="stat-label">Belum Absen Hari Ini</div>
                    </div>
                </div>
            </div>

            <!-- Form Filter Pencarian Neo-Brutalism -->
            <div class="filter-box">
                <form method="get" action="index.php" class="row g-2 align-items-end">
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label">Dari Tanggal</label>
                        <input type="date" name="dari" id="filter-dari" class="form-control" value="<?= htmlspecialchars($dari) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label">Sampai Tanggal</label>
                        <input type="date" name="sampai" id="filter-sampai" class="form-control" value="<?= htmlspecialchars($sampai) ?>">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label">Pilih Karyawan</label>
                        <select name="karyawan_id" class="form-select">
                            <option value="0">-- Semua Karyawan --</option>
                            <?php foreach ($listKaryawanAktif as $k): ?>
                                <option value="<?= (int) $k['id'] ?>" <?= $karyawanId === (int)$k['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($k['nama']) ?> (<?= htmlspecialchars($k['jabatan']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">-- Semua Status --</option>
                            <option value="hadir" <?= $statusFilt === 'hadir' ? 'selected' : '' ?>>Hadir</option>
                            <option value="terlambat" <?= $statusFilt === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                            <option value="izin" <?= $statusFilt === 'izin' ? 'selected' : '' ?>>Izin</option>
                            <option value="sakit" <?= $statusFilt === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-12">
                        <label class="form-label">Cari Nama / Jabatan</label>
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" placeholder="Kata kunci..." value="<?= htmlspecialchars($keyword) ?>">
                            <button type="submit" class="btn btn-primary px-3" title="Terapkan Filter">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top mt-2">
                        <div class="d-flex align-items-center gap-1 small fw-bold">
                            <span class="text-muted me-1">Preset:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setFilterPreset('hari-ini')">Hari Ini</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setFilterPreset('7-hari')">7 Hari Terakhir</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setFilterPreset('bulan-ini')">Bulan Ini</button>
                        </div>
                        <div>
                            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tabel Data Presensi Karyawan -->
            <div class="card mb-4">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-calendar2-range-fill me-2 text-warning"></i>Data Presensi Karyawan</span>
                    <span class="badge badge-dark"><?= count($listPresensi) ?> Catatan Ditemukan</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 48px;" class="text-center">No</th>
                                <th>Karyawan</th>
                                <th>Tanggal</th>
                                <th class="text-center">Jam Masuk</th>
                                <th class="text-center">Jam Pulang</th>
                                <th class="text-center">Status</th>
                                <th>Keterangan / Catatan</th>
                                <th class="text-end" style="width: 140px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($listPresensi) > 0): ?>
                                <?php foreach ($listPresensi as $idx => $row): ?>
                                    <?php 
                                        $namaWords = preg_split('/\s+/', trim($row['nama'])) ?: ['K'];
                                        $inisial = strtoupper(mb_substr($namaWords[0], 0, 1)) . (isset($namaWords[1]) ? strtoupper(mb_substr($namaWords[1], 0, 1)) : '');
                                        
                                        // Format nomor WA
                                        $cleanWa = preg_replace('/[^0-9]/', '', (string)$row['no_wa']);
                                        if (str_starts_with($cleanWa, '0')) {
                                            $cleanWa = '62' . substr($cleanWa, 1);
                                        }
                                    ?>
                                    <tr>
                                        <td class="text-center text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="user-cell-avatar"><?= htmlspecialchars($inisial) ?></div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama']) ?></div>
                                                    <div class="small text-muted d-flex align-items-center gap-2">
                                                        <span><?= htmlspecialchars($row['jabatan']) ?> &bull; <?= htmlspecialchars($row['departemen']) ?></span>
                                                        <?php if ($cleanWa !== ''): ?>
                                                            <a href="https://wa.me/<?= htmlspecialchars($cleanWa) ?>" target="_blank" class="text-success text-decoration-none fw-bold" title="Kirim Chat WhatsApp">
                                                                <i class="bi bi-whatsapp"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= formatTanggalIndo($row['tanggal']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($row['tanggal']) ?></div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['jam_masuk']): ?>
                                                <span class="fw-bold text-dark"><i class="bi bi-box-arrow-in-right text-success me-1"></i><?= htmlspecialchars(substr($row['jam_masuk'], 0, 5)) ?> WIB</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($row['jam_pulang']): ?>
                                                <span class="fw-bold text-dark"><i class="bi bi-box-arrow-out-right text-danger me-1"></i><?= htmlspecialchars(substr($row['jam_pulang'], 0, 5)) ?> WIB</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?= badgeStatus($row['status']) ?>
                                        </td>
                                        <td>
                                            <span class="small <?= $row['catatan'] ? 'text-dark' : 'text-muted' ?>">
                                                <?= htmlspecialchars((string) ($row['catatan'] ?? '-')) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-info px-2 py-1" 
                                                        title="Koreksi / Edit Presensi"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#modalPresensiAdmin"
                                                        data-karyawan-id="<?= (int)$row['karyawan_id'] ?>"
                                                        data-tanggal="<?= htmlspecialchars($row['tanggal']) ?>"
                                                        data-jam-masuk="<?= $row['jam_masuk'] ? htmlspecialchars(substr($row['jam_masuk'], 0, 5)) : '' ?>"
                                                        data-jam-pulang="<?= $row['jam_pulang'] ? htmlspecialchars(substr($row['jam_pulang'], 0, 5)) : '' ?>"
                                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                                        data-catatan="<?= htmlspecialchars((string)($row['catatan'] ?? '')) ?>"
                                                        onclick="isiModalKoreksi(this)">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="post" action="hapus.php" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus catatan presensi ini?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                    <input type="hidden" name="redirect_to" value="index.php?dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&karyawan_id=<?= (int)$karyawanId ?>&status=<?= urlencode($statusFilt) ?>&q=<?= urlencode($keyword) ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger px-2 py-1" title="Hapus Data">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center justify-content-center">
                                            <i class="bi bi-calendar-x display-4 text-muted mb-2"></i>
                                            <h5 class="fw-bold mb-1">Tidak Ada Data Presensi</h5>
                                            <p class="text-muted small mb-3">Tidak ditemukan catatan presensi karyawan dengan filter yang dipilih.</p>
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalPresensiAdmin">
                                                <i class="bi bi-plus-circle-fill me-1"></i>Input Presensi Baru
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php else: ?>
            <!-- ============================================================ -->
            <!-- TAMPILAN KARYAWAN: PORTAL PRESENSI MANDIRI                  -->
            <!-- ============================================================ -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h2 class="page-title">Presensi Mandiri</h2>
                    <div class="page-sub mb-0">Halo, <strong><?= htmlspecialchars($user['nama'] ?? $_SESSION['nama']) ?></strong> — Catat absensi kehadiran harian Anda.</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalIzin" <?= $absenHariIni ? 'disabled' : '' ?>>
                        <i class="bi bi-file-earmark-medical-fill me-1"></i>Ajukan Izin / Sakit
                    </button>
                    <span class="badge badge-dark fs-6 py-2 px-3">
                        <i class="bi bi-clock me-1 text-warning"></i><span id="jam-live">--:--:--</span> WIB
                    </span>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- Info Profil -->
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-person-badge-fill me-2 text-warning"></i>Informasi Profil Karyawan</div>
                        <div class="card-body">
                            <table class="table table-borderless mb-0">
                                <tr>
                                    <td class="text-muted" style="width:38%;">Nama Lengkap</td>
                                    <td><strong><?= htmlspecialchars($user['nama'] ?? '-') ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Email</td>
                                    <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Jabatan</td>
                                    <td><span class="badge badge-karyawan"><?= htmlspecialchars($user['jabatan'] ?? '-') ?></span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Departemen</td>
                                    <td><?= htmlspecialchars($user['departemen'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">No. WhatsApp</td>
                                    <td>
                                        <strong><?= htmlspecialchars($user['no_wa'] ?? '-') ?></strong>
                                        <i class="bi bi-patch-check-fill text-success ms-1" title="Terhubung untuk notifikasi WA"></i>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Status Presensi Hari Ini & Tombol Absen -->
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-calendar-check-fill me-2 text-warning"></i>Status Presensi Hari Ini</span>
                            <span class="badge badge-dark"><?= htmlspecialchars(date('d M Y')) ?></span>
                        </div>
                        <div class="card-body">
                            <?php if ($absenHariIni): ?>
                                <div class="row g-2 mb-3">
                                    <div class="col-sm-4">
                                        <div class="p-3 border" style="border:2.5px solid #111111; border-radius:10px; background:#ffffff; box-shadow:3px 3px 0 #111111;">
                                            <div class="text-muted small fw-bold text-uppercase">Status</div>
                                            <div class="mt-1"><?= badgeStatus($absenHariIni['status']) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="p-3 border" style="border:2.5px solid #111111; border-radius:10px; background:#ffffff; box-shadow:3px 3px 0 #111111;">
                                            <div class="text-muted small fw-bold text-uppercase">Jam Masuk</div>
                                            <div class="mt-1 fw-bold"><?= $absenHariIni['jam_masuk'] ? htmlspecialchars(substr((string) $absenHariIni['jam_masuk'], 0, 5)) . ' WIB' : '<span class="text-muted">-</span>' ?></div>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="p-3 border" style="border:2.5px solid #111111; border-radius:10px; background:#ffffff; box-shadow:3px 3px 0 #111111;">
                                            <div class="text-muted small fw-bold text-uppercase">Jam Pulang</div>
                                            <div class="mt-1 fw-bold"><?= $absenHariIni['jam_pulang'] ? htmlspecialchars(substr((string) $absenHariIni['jam_pulang'], 0, 5)) . ' WIB' : '<span class="text-muted">-</span>' ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($absenHariIni['catatan']): ?>
                                    <div class="alert alert-warning py-2 mb-3">
                                        <i class="bi bi-card-text me-1"></i><strong>Keterangan:</strong> <?= htmlspecialchars($absenHariIni['catatan']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-info mb-3">
                                    <i class="bi bi-info-circle-fill me-1"></i>Belum ada catatan presensi hari ini. Silakan klik <strong>Absen Masuk</strong> atau <strong>Ajukan Izin/Sakit</strong>.
                                </div>
                            <?php endif; ?>

                            <div class="row g-2 pt-2" style="border-top:2px dashed rgba(17,17,17,.25);">
                                <div class="col-md-6">
                                    <form method="post" action="masuk.php" onsubmit="return confirm('Lakukan Absen Masuk sekarang?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn btn-success w-100 py-2" <?= ($absenHariIni && !in_array($absenHariIni['status'], ['izin', 'sakit'], true)) ? 'disabled' : '' ?>>
                                            <i class="bi bi-box-arrow-in-right me-1"></i>Absen Masuk (Batas <?= JAM_BATAS_MASUK ?>)
                                        </button>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <form method="post" action="pulang.php" onsubmit="return confirm('Lakukan Absen Pulang sekarang?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn btn-warning w-100 py-2" <?= (!$absenHariIni || !$absenHariIni['jam_masuk'] || $absenHariIni['jam_pulang']) ? 'disabled' : '' ?>>
                                            <i class="bi bi-box-arrow-out-right me-1"></i>Absen Pulang
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Riwayat Presensi Pribadi Karyawan -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2 text-warning"></i>Riwayat Presensi Pribadi (10 Hari Terakhir)</span>
                    <span class="badge badge-dark"><?= count($riwayat) ?> Hari</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jam Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Status</th>
                                <th>Keterangan / Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($riwayat) > 0): ?>
                                <?php foreach ($riwayat as $r): ?>
                                    <tr>
                                        <td><strong><?= formatTanggalIndo($r['tanggal']) ?></strong></td>
                                        <td><?= $r['jam_masuk'] ? htmlspecialchars(substr((string) $r['jam_masuk'], 0, 5)) . ' WIB' : '<span class="text-muted">-</span>' ?></td>
                                        <td><?= $r['jam_pulang'] ? htmlspecialchars(substr((string) $r['jam_pulang'], 0, 5)) . ' WIB' : '<span class="text-muted">-</span>' ?></td>
                                        <td><?= badgeStatus($r['status']) ?></td>
                                        <td class="text-muted small"><?= htmlspecialchars((string) ($r['catatan'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="bi bi-calendar2-x fs-2 d-block mb-1"></i>
                                            Belum ada riwayat absensi.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <footer class="footer-app text-center py-3">© <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?> — Sistem Kelola Presensi &amp; WhatsApp Gateway</footer>
    </main>

    <!-- ============================================================ -->
    <!-- MODAL: AJUKAN IZIN / SAKIT (KARYAWAN)                        -->
    <!-- ============================================================ -->
    <?php if (!$isAdmin): ?>
    <div class="modal fade" id="modalIzin" tabindex="-1" aria-labelledby="modalIzinLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--nb-cyan);">
                    <h5 class="modal-title" id="modalIzinLabel"><i class="bi bi-file-earmark-medical-fill me-2"></i>Pengajuan Izin / Sakit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="index.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        
                        <?php if (isset($absenHariIni) && $absenHariIni): ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>Sudah ada catatan presensi hari ini. Tidak dapat mengajukan izin/sakit.
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Jenis Pengajuan <span class="text-danger">*</span></label>
                                <select class="form-select" name="tipe" required>
                                    <option value="izin">Izin</option>
                                    <option value="sakit">Sakit</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Keterangan / Alasan <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="keterangan" rows="4" placeholder="Jelaskan alasan izin atau sakit secara rinci..." required></textarea>
                                <div class="form-text small mt-1">Notifikasi pengajuan akan otomatis dikirimkan ke WhatsApp atasan &amp; konfirmasi ke nomor Anda.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <?php if (!isset($absenHariIni) || !$absenHariIni): ?>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill me-1"></i>Kirim Pengajuan</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- MODAL: INPUT / KOREKSI PRESENSI KARYAWAN (ADMIN)             -->
    <!-- ============================================================ -->
    <?php if ($isAdmin): ?>
    <div class="modal fade" id="modalPresensiAdmin" tabindex="-1" aria-labelledby="modalPresensiAdminLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--nb-yellow);">
                    <h5 class="modal-title" id="modalPresensiAdminLabel"><i class="bi bi-alarm-fill me-2"></i><span id="judul-modal-presensi">Input Presensi Karyawan</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="../admin/presensi_proses.php" id="form-presensi-admin">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="redirect_to" value="../absensi/index.php?dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&karyawan_id=<?= (int)$karyawanId ?>&status=<?= urlencode($statusFilt) ?>&q=<?= urlencode($keyword) ?>">

                        <div class="mb-3">
                            <label class="form-label">Pilih Karyawan <span class="text-danger">*</span></label>
                            <select name="karyawan_id" id="presensi-karyawan-id" class="form-select" required>
                                <option value="">-- Pilih Karyawan --</option>
                                <?php foreach ($listKaryawanAktif as $k): ?>
                                    <option value="<?= (int) $k['id'] ?>"><?= htmlspecialchars($k['nama']) ?> — <?= htmlspecialchars($k['jabatan']) ?> (<?= htmlspecialchars($k['no_wa']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                <input type="date" name="tanggal" id="presensi-tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Jenis / Status <span class="text-danger">*</span></label>
                                <select name="jenis" id="presensi-jenis" class="form-select" required onchange="togglePresensiInputs(this.value)">
                                    <option value="masuk">Absen Masuk Saja</option>
                                    <option value="pulang">Absen Pulang Saja</option>
                                    <option value="hadir_lengkap">Hadir Lengkap (Masuk &amp; Pulang)</option>
                                    <option value="izin">Izin</option>
                                    <option value="sakit">Sakit</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mb-3" id="row-waktu-presensi">
                            <div class="col-12" id="wrap-jam-masuk">
                                <label class="form-label">Jam Masuk</label>
                                <input type="time" name="jam_masuk" id="input-jam-masuk" class="form-control" value="<?= date('H:i') ?>">
                                <div class="form-text small">Batas Tepat Waktu: <?= JAM_BATAS_MASUK ?> WIB</div>
                            </div>
                            <div class="col-6 d-none" id="wrap-jam-pulang">
                                <label class="form-label">Jam Pulang</label>
                                <input type="time" name="jam_pulang" id="input-jam-pulang" class="form-control" value="17:00">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Keterangan / Catatan</label>
                            <input type="text" name="catatan" id="presensi-catatan" class="form-control" placeholder="Contoh: Input manual oleh Admin / Koreksi waktu">
                        </div>

                        <div class="alert alert-info py-2 px-3 small mb-0">
                            <i class="bi bi-whatsapp me-1 text-success fs-6"></i>Notifikasi hasil presensi akan <strong>langsung otomatis dikirimkan ke nomor WhatsApp karyawan</strong> bersangkutan via Fonnte.
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill me-1"></i>Simpan &amp; Kirim Notifikasi WA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePresensiInputs(jenis) {
            const wrapMasuk  = document.getElementById('wrap-jam-masuk');
            const wrapPulang = document.getElementById('wrap-jam-pulang');
            const rowWaktu   = document.getElementById('row-waktu-presensi');
            if (!rowWaktu) return;

            if (jenis === 'masuk') {
                rowWaktu.classList.remove('d-none');
                wrapMasuk.classList.remove('d-none');
                wrapMasuk.className = 'col-12';
                wrapPulang.classList.add('d-none');
            } else if (jenis === 'pulang') {
                rowWaktu.classList.remove('d-none');
                wrapMasuk.classList.add('d-none');
                wrapPulang.classList.remove('d-none');
                wrapPulang.className = 'col-12';
            } else if (jenis === 'hadir_lengkap') {
                rowWaktu.classList.remove('d-none');
                wrapMasuk.classList.remove('d-none');
                wrapMasuk.className = 'col-6';
                wrapPulang.classList.remove('d-none');
                wrapPulang.className = 'col-6';
            } else { // izin atau sakit
                rowWaktu.classList.add('d-none');
            }
        }

        function isiModalKoreksi(btn) {
            const kid = btn.getAttribute('data-karyawan-id');
            const tgl = btn.getAttribute('data-tanggal');
            const masuk = btn.getAttribute('data-jam-masuk');
            const pulang = btn.getAttribute('data-jam-pulang');
            const st = btn.getAttribute('data-status');
            const cat = btn.getAttribute('data-catatan');

            const judul = document.getElementById('judul-modal-presensi');
            if (judul) judul.textContent = 'Koreksi Presensi Karyawan';

            const selKaryawan = document.getElementById('presensi-karyawan-id');
            if (selKaryawan) selKaryawan.value = kid;

            const inputTgl = document.getElementById('presensi-tanggal');
            if (inputTgl) inputTgl.value = tgl;

            const inputMasuk = document.getElementById('input-jam-masuk');
            if (inputMasuk && masuk) inputMasuk.value = masuk;

            const inputPulang = document.getElementById('input-jam-pulang');
            if (inputPulang && pulang) inputPulang.value = pulang;

            const inputCat = document.getElementById('presensi-catatan');
            if (inputCat) inputCat.value = cat;

            const selJenis = document.getElementById('presensi-jenis');
            if (selJenis) {
                if (st === 'izin') {
                    selJenis.value = 'izin';
                } else if (st === 'sakit') {
                    selJenis.value = 'sakit';
                } else if (masuk && pulang) {
                    selJenis.value = 'hadir_lengkap';
                } else if (pulang && !masuk) {
                    selJenis.value = 'pulang';
                } else {
                    selJenis.value = 'masuk';
                }
                togglePresensiInputs(selJenis.value);
            }
        }

        function setFilterPreset(preset) {
            const inputDari = document.getElementById('filter-dari');
            const inputSampai = document.getElementById('filter-sampai');
            if (!inputDari || !inputSampai) return;

            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            const todayStr = `${yyyy}-${mm}-${dd}`;

            if (preset === 'hari-ini') {
                inputDari.value = todayStr;
                inputSampai.value = todayStr;
            } else if (preset === '7-hari') {
                const past = new Date();
                past.setDate(today.getDate() - 6);
                const pyyyy = past.getFullYear();
                const pmm = String(past.getMonth() + 1).padStart(2, '0');
                const pdd = String(past.getDate()).padStart(2, '0');
                inputDari.value = `${pyyyy}-${pmm}-${pdd}`;
                inputSampai.value = todayStr;
            } else if (preset === 'bulan-ini') {
                inputDari.value = `${yyyy}-${mm}-01`;
                inputSampai.value = todayStr;
            }
        }

        // Live Clock
        function tickJam() {
            const now = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date());
            const el = document.getElementById('jam-live');
            if (el) { el.textContent = now; }
        }
        setInterval(tickJam, 1000);
        tickJam();

        // Auto dismiss flash
        setTimeout(function() {
            document.querySelectorAll('.alert-dismissible').forEach(function(el) {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
                bsAlert.close();
            });
        }, 6000);
    </script>
</body>
</html>
