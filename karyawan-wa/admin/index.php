<?php
/* ============================================================================
   ADMIN — INDEX
   Dashboard statistik, rekap status absensi hari ini, laporan absensi,
   filter tanggal, export Excel & PDF (print).
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Akses ditolak. Halaman ini khusus admin.'];
    header('Location: ../absensi/index.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ================= FILTER LAPORAN ================= */
$dari = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$keyword = trim($_GET['q'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) { $dari = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) { $sampai = date('Y-m-d'); }

$kondisi = [];
$param = [];
$tipe = '';

if ($dari !== '' && $sampai !== '') {
    $kondisi[] = 'a.tanggal BETWEEN ? AND ?';
    $param[] = $dari;
    $param[] = $sampai;
    $tipe = $tipe . 'ss';
}
if ($status !== '') {
    $kondisi[] = 'a.status = ?';
    $param[] = $status;
    $tipe = $tipe . 's';
}
if ($keyword !== '') {
    $kondisi[] = '(k.nama LIKE ? OR k.jabatan LIKE ?)';
    $like = '%' . $keyword . '%';
    $param[] = $like;
    $param[] = $like;
    $tipe = $tipe . 'ss';
}

$whereSql = $kondisi ? ' WHERE ' . implode(' AND ', $kondisi) : '';

/* ================= STATISTIK & REKAP ================= */
$tanggalHariIni = date('Y-m-d');

$stmt = $koneksi->prepare("SELECT COUNT(*) c FROM absensi WHERE tanggal = ? AND status = 'hadir'");
$stmt->bind_param('s', $tanggalHariIni);
$stmt->execute();
$jumlahHadir = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $koneksi->prepare("SELECT COUNT(*) c FROM absensi WHERE tanggal = ? AND status = 'terlambat'");
$stmt->bind_param('s', $tanggalHariIni);
$stmt->execute();
$jumlahTerlambat = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $koneksi->prepare('SELECT COUNT(*) c FROM absensi WHERE tanggal = ? AND status IN (?, ?)');
$izinStatus = 'izin';
$sakitStatus = 'sakit';
$stmt->bind_param('sss', $tanggalHariIni, $izinStatus, $sakitStatus);
$stmt->execute();
$jumlahIzinSakit = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$totalKaryawan = (int) $koneksi->query('SELECT COUNT(*) c FROM karyawan')->fetch_assoc()['c'];
$totalAktif = (int) $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'aktif'")->fetch_assoc()['c'];
$totalCuti = (int) $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'cuti'")->fetch_assoc()['c'];
$totalKeluar = (int) $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'keluar'")->fetch_assoc()['c'];

$stmt = $koneksi->prepare('SELECT COUNT(DISTINCT karyawan_id) c FROM absensi WHERE tanggal = ?');
$stmt->bind_param('s', $tanggalHariIni);
$stmt->execute();
$jumlahSudahAbsen = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$belumAbsen = max(0, $totalAktif - $jumlahSudahAbsen);

$rekap = [];
$stmt = $koneksi->prepare('SELECT status, COUNT(*) c FROM absensi WHERE tanggal = ? GROUP BY status');
$stmt->bind_param('s', $tanggalHariIni);
$stmt->execute();
$rekapRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($rekapRaw as $r) { $rekap[$r['status']] = (int) $r['c']; }

/* ================= LAPORAN DATA ================= */
$sqlLaporan = 'SELECT a.id, a.tanggal, a.jam_masuk, a.jam_pulang, a.status, a.catatan, k.nama, k.jabatan FROM absensi a INNER JOIN karyawan k ON k.id = a.karyawan_id' . $whereSql . ' ORDER BY a.tanggal DESC, a.id DESC LIMIT 5000';
$stmt = $koneksi->prepare($sqlLaporan);
if ($tipe !== '') { $stmt->bind_param($tipe, ...$param); }
$stmt->execute();
$laporan = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Ambil daftar karyawan aktif untuk modal presensi admin */
$listKaryawan = $koneksi->query("SELECT id, nama, jabatan, no_wa FROM karyawan WHERE status = 'aktif' ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC);

/* ================= EXPORT EXCEL ================= */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_absensi_' . $dari . '_sampai_' . $sampai . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<tr><th>No</th><th>Tanggal</th><th>Nama</th><th>Jabatan</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status</th><th>Keterangan</th></tr>';
    $no = 1;
    foreach ($laporan as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) $no++) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['tanggal']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['nama']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['jabatan']) . '</td>';
        echo '<td>' . ($r['jam_masuk'] ? htmlspecialchars(substr((string) $r['jam_masuk'], 0, 5)) : '-') . '</td>';
        echo '<td>' . ($r['jam_pulang'] ? htmlspecialchars(substr((string) $r['jam_pulang'], 0, 5)) : '-') . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['status']) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($r['catatan'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin — <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container container-app">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipe'] ?? 'info') ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flash['pesan'] ?? '') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
            <div>
                <h2 class="page-title">Dashboard Admin</h2>
                <div class="page-sub">Statistik &amp; rekap absensi — <?= htmlspecialchars($tanggalHariIni) ?></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="../absensi/absen_masuk.php" class="btn btn-success"><i class="bi bi-box-arrow-in-right me-1"></i>Absen Masuk</a>
                <a href="../absensi/absen_pulang.php" class="btn btn-warning"><i class="bi bi-box-arrow-out-right me-1"></i>Absen Pulang</a>
                <a href="../karyawan/index.php?action=tambah" class="btn btn-primary"><i class="bi bi-person-plus-fill me-1"></i>Tambah Karyawan</a>
            </div>
        </div>

        <div class="stat-row mb-4">
            <div class="stat-card"><div class="stat-num"><?= htmlspecialchars((string) $totalKaryawan) ?></div><div class="stat-label">Total Karyawan</div></div>
            <div class="stat-card green"><div class="stat-num"><?= htmlspecialchars((string) $jumlahHadir) ?></div><div class="stat-label">Hadir Hari Ini</div></div>
            <div class="stat-card orange"><div class="stat-num"><?= htmlspecialchars((string) $jumlahTerlambat) ?></div><div class="stat-label">Terlambat</div></div>
            <div class="stat-card blue"><div class="stat-num"><?= htmlspecialchars((string) $jumlahIzinSakit) ?></div><div class="stat-label">Izin / Sakit</div></div>
            <div class="stat-card purple"><div class="stat-num"><?= htmlspecialchars((string) $belumAbsen) ?></div><div class="stat-label">Belum Absen</div></div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="bi bi-bar-chart"></i> Rekap Status Hari Ini</div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge badge-hadir p-2">Hadir: <?= htmlspecialchars((string) ($rekap['hadir'] ?? 0)) ?></span>
                            <span class="badge badge-terlambat p-2">Terlambat: <?= htmlspecialchars((string) ($rekap['terlambat'] ?? 0)) ?></span>
                            <span class="badge badge-izin p-2">Izin: <?= htmlspecialchars((string) ($rekap['izin'] ?? 0)) ?></span>
                            <span class="badge badge-sakit p-2">Sakit: <?= htmlspecialchars((string) ($rekap['sakit'] ?? 0)) ?></span>
                            <span class="badge badge-dark p-2">Belum Absen: <?= htmlspecialchars((string) $belumAbsen) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="bi bi-people me-2"></i> Ringkasan Karyawan Aktif</div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr><td class="text-muted">Total Terdaftar</td><td><strong><?= htmlspecialchars((string) $totalKaryawan) ?></strong></td></tr>
                            <tr><td class="text-muted">Status Aktif</td><td><strong><?= htmlspecialchars((string) $totalAktif) ?></strong></td></tr>
                            <tr><td class="text-muted">Status Cuti</td><td><strong><?= htmlspecialchars((string) $totalCuti ?? 0) ?></strong></td></tr>
                            <tr><td class="text-muted">Status Keluar</td><td><strong><?= htmlspecialchars((string) $totalKeluar ?? 0) ?></strong></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4 no-print">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text me-2"></i> Laporan Absensi</span>
                <div>
                    <a href="index.php?export=excel&dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($keyword) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Export PDF / Cetak</button>
                </div>
            </div>
            <div class="card-body">
                <form method="get" action="index.php" class="row g-2 mb-3 no-print">
                    <div class="col-md-3">
                        <label class="form-label">Dari</label>
                        <input type="date" name="dari" class="form-control" value="<?= htmlspecialchars($dari) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sampai</label>
                        <input type="date" name="sampai" class="form-control" value="<?= htmlspecialchars($sampai) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">Semua</option>
                            <option value="hadir" <?= $status === 'hadir' ? 'selected' : '' ?>>Hadir</option>
                            <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                            <option value="izin" <?= $status === 'izin' ? 'selected' : '' ?>>Izin</option>
                            <option value="sakit" <?= $status === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cari</label>
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" placeholder="Nama / Jabatan" value="<?= htmlspecialchars($keyword) ?>">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-filter me-1"></i>Filter</button>
                        <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tanggal</th>
                                <th>Nama</th>
                                <th>Jabatan</th>
                                <th>Jam Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Status</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($laporan) > 0): ?>
                                <?php $no = 1; foreach ($laporan as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $no++) ?></td>
                                        <td><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                                        <td><?= htmlspecialchars((string) $r['nama']) ?></td>
                                        <td><?= htmlspecialchars((string) $r['jabatan']) ?></td>
                                        <td><?= $r['jam_masuk'] ? htmlspecialchars(substr((string) $r['jam_masuk'], 0, 5)) : '-' ?></td>
                                        <td><?= $r['jam_pulang'] ? htmlspecialchars(substr((string) $r['jam_pulang'], 0, 5)) : '-' ?></td>
                                        <td><span class="badge badge-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string) ($r['catatan'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="nb-empty">
                                            <i class="bi bi-calendar-x"></i>
                                            <p>Tidak ada data absensi pada rentang waktu tersebut.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div>

        <footer class="footer-app text-center no-print">© <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?></footer>
    </main>

    <!-- ============================================================ -->
    <!-- MODAL: INPUT PRESENSI KARYAWAN (ADMIN)                       -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalPresensiAdmin" tabindex="-1" aria-labelledby="modalPresensiAdminLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--nb-yellow);">
                    <h5 class="modal-title" id="modalPresensiAdminLabel"><i class="bi bi-alarm-fill me-2"></i>Input Presensi Karyawan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="presensi_proses.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="redirect_to" value="index.php">

                        <div class="mb-3">
                            <label class="form-label">Pilih Karyawan <span class="text-danger">*</span></label>
                            <select name="karyawan_id" id="presensi-karyawan-id" class="form-select" required>
                                <option value="">-- Pilih Karyawan --</option>
                                <?php foreach ($listKaryawan as $k): ?>
                                    <option value="<?= (int) $k['id'] ?>"><?= htmlspecialchars($k['nama']) ?> — <?= htmlspecialchars($k['jabatan']) ?> (<?= htmlspecialchars($k['no_wa']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Jenis Presensi <span class="text-danger">*</span></label>
                                <select name="jenis" id="presensi-jenis" class="form-select" required onchange="togglePresensiInputs(this.value)">
                                    <option value="masuk">Absen Masuk</option>
                                    <option value="pulang">Absen Pulang</option>
                                    <option value="hadir_lengkap">Hadir Lengkap</option>
                                    <option value="izin">Izin</option>
                                    <option value="sakit">Sakit</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mb-3" id="row-waktu-presensi">
                            <div class="col-6" id="wrap-jam-masuk">
                                <label class="form-label">Jam Masuk</label>
                                <input type="time" name="jam_masuk" id="input-jam-masuk" class="form-control" value="<?= date('H:i') ?>">
                                <div class="form-text small">Batas: <?= JAM_BATAS_MASUK ?></div>
                            </div>
                            <div class="col-6 d-none" id="wrap-jam-pulang">
                                <label class="form-label">Jam Pulang</label>
                                <input type="time" name="jam_pulang" id="input-jam-pulang" class="form-control" value="17:00">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Keterangan / Catatan</label>
                            <input type="text" name="catatan" class="form-control" placeholder="Contoh: Dicatat manual oleh HRD">
                        </div>

                        <div class="alert alert-info py-2 px-3 small mb-0">
                            <i class="bi bi-whatsapp me-1"></i>Notifikasi resmi hasil presensi akan <strong>langsung dikirim ke nomor WhatsApp karyawan</strong> bersangkutan via Fonnte.
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan &amp; Kirim Notifikasi WA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePresensiInputs(jenis) {
            const wrapMasuk  = document.getElementById('wrap-jam-masuk');
            const wrapPulang = document.getElementById('wrap-jam-pulang');
            const rowWaktu   = document.getElementById('row-waktu-presensi');

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

        setTimeout(function() {
            document.querySelectorAll('.alert-dismissible').forEach(function(el) {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
