<?php
/* ============================================================================
   KARYAWAN — INDEX
   Tabel daftar karyawan dengan modal Tambah & Edit. Khusus admin.
   ============================================================================ */

require_once __DIR__ . '/../config/database.php';

session_start();

if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['tipe' => 'warning', 'pesan' => 'Akses ditolak. Halaman ini khusus admin.'];
    header('Location: ../absensi/index.php'); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function badgeAkun(string $status): string {
    $teks  = ['aktif' => 'Aktif', 'cuti' => 'Cuti', 'keluar' => 'Keluar'][$status] ?? ucfirst($status);
    $kelas = ['aktif' => 'badge-aktif', 'cuti' => 'badge-cuti', 'keluar' => 'badge-keluar'][$status] ?? 'badge-aktif';
    return '<span class="badge ' . htmlspecialchars($kelas) . '">' . htmlspecialchars($teks) . '</span>';
}
function badgeRole(string $role): string {
    return '<span class="badge ' . ($role === 'admin' ? 'badge-admin' : 'badge-karyawan') . '">' . htmlspecialchars(ucfirst($role)) . '</span>';
}

/* ---------- DATA ---------- */
$q        = trim($_GET['q'] ?? '');
$halaman  = max(1, (int) ($_GET['halaman'] ?? 1));
$perPage  = 10;
$offset   = ($halaman - 1) * $perPage;
$kondisi  = [];
$tipe     = '';
$like     = '';

if ($q !== '') {
    $kondisi[] = '(nama LIKE ? OR email LIKE ? OR jabatan LIKE ?)';
    $like = '%' . $q . '%';
    $tipe = 'sss';
}

$whereSql = $kondisi ? ' WHERE ' . implode(' AND ', $kondisi) : '';

$sqlCount = 'SELECT COUNT(*) AS total FROM karyawan' . $whereSql;
$stmt = $koneksi->prepare($sqlCount);
if ($tipe !== '') { $stmt->bind_param('sss', $like, $like, $like); }
$stmt->execute();
$total = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalHalaman = max(1, (int) ceil($total / $perPage));
if ($halaman > $totalHalaman) { $halaman = $totalHalaman; }
$offset = ($halaman - 1) * $perPage;

$sqlData = 'SELECT id, nama, email, no_wa, jabatan, departemen, status, role, created_at FROM karyawan' . $whereSql . ' ORDER BY nama ASC LIMIT ? OFFSET ?';
$stmt = $koneksi->prepare($sqlData);
if ($tipe === '') {
    $stmt->bind_param('ii', $perPage, $offset);
} else {
    $stmt->bind_param($tipe . 'ii', $like, $like, $like, $perPage, $offset);
}
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalSemua  = (int) $koneksi->query('SELECT COUNT(*) c FROM karyawan')->fetch_assoc()['c'];
$totalAktif  = (int) $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'aktif'")->fetch_assoc()['c'];
$totalCuti   = (int) $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'cuti'")->fetch_assoc()['c'];
$totalKeluar = (int) $koneksi->query("SELECT COUNT(*) c FROM karyawan WHERE status = 'keluar'")->fetch_assoc()['c'];

/* Daftar semua karyawan aktif untuk modal presensi */
$listKaryawanAktif = $koneksi->query("SELECT id, nama, jabatan, no_wa FROM karyawan WHERE status = 'aktif' ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Karyawan — <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/neobrutalism.css">
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="container container-app">

            <!-- Flash Alert -->
            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['tipe'] ?? 'info') ?> alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-<?= ($flash['tipe'] ?? '') === 'success' ? 'check-circle-fill' : (($flash['tipe'] ?? '') === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-1"></i>
                    <?= htmlspecialchars($flash['pesan'] ?? '') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
                <div>
                    <h2 class="page-title">Kelola Karyawan</h2>
                    <div class="page-sub">Daftar akun, tambah, edit, atau input presensi karyawan.</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalPresensiAdmin">
                        <i class="bi bi-alarm-fill me-1"></i>Input Presensi
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTambah">
                        <i class="bi bi-person-plus-fill me-1"></i>Tambah Karyawan
                    </button>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="stat-row mb-4 no-print">
                <div class="stat-card"><div class="stat-num"><?= $totalSemua ?></div><div class="stat-label">Total</div></div>
                <div class="stat-card green"><div class="stat-num"><?= $totalAktif ?></div><div class="stat-label">Aktif</div></div>
                <div class="stat-card orange"><div class="stat-num"><?= $totalCuti ?></div><div class="stat-label">Cuti</div></div>
                <div class="stat-card" style="border-left-color:var(--nb-red)"><div class="stat-num"><?= $totalKeluar ?></div><div class="stat-label">Keluar</div></div>
            </div>

            <!-- Search -->
            <div class="card mb-4 no-print">
                <div class="card-body p-3">
                    <form method="get" action="index.php" class="d-flex gap-2">
                        <div class="input-group flex-grow-1">
                            <input type="text" name="q" class="form-control" placeholder="Cari nama, email, atau jabatan..." value="<?= htmlspecialchars($q) ?>">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                        </div>
                        <?php if ($q !== ''): ?>
                            <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span><i class="bi bi-people-fill me-2"></i>Daftar Karyawan</span>
                        <span class="badge badge-dark"><?= $total ?> data</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalTambah">
                        <i class="bi bi-person-plus-fill me-1"></i>Tambah Karyawan
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>Nama</th>
                                <th class="d-none d-md-table-cell">Email</th>
                                <th class="d-none d-lg-table-cell">No. WA</th>
                                <th>Jabatan</th>
                                <th class="d-none d-sm-table-cell">Role</th>
                                <th>Status</th>
                                <th style="width:140px" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($data) > 0): ?>
                                <?php $nomor = $offset + 1; foreach ($data as $row): ?>
                                    <tr>
                                        <td><?= $nomor++ ?></td>
                                        <td><strong><?= htmlspecialchars($row['nama']) ?></strong></td>
                                        <td class="d-none d-md-table-cell"><?= htmlspecialchars($row['email']) ?></td>
                                        <td class="d-none d-lg-table-cell"><?= htmlspecialchars($row['no_wa']) ?></td>
                                        <td><?= htmlspecialchars($row['jabatan']) ?></td>
                                        <td class="d-none d-sm-table-cell"><?= badgeRole($row['role']) ?></td>
                                        <td><?= badgeAkun($row['status']) ?></td>
                                        <td class="text-center">
                                            <div class="nb-action-group">
                                                <button type="button" class="btn btn-sm btn-info btn-absen"
                                                    data-id="<?= (int) $row['id'] ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama']) ?>"
                                                    data-bs-toggle="modal" data-bs-target="#modalPresensiAdmin"
                                                    title="Input Presensi untuk <?= htmlspecialchars($row['nama']) ?>"><i class="bi bi-alarm-fill"></i></button>
                                                <button type="button" class="btn btn-sm btn-warning btn-edit"
                                                    data-id="<?= (int) $row['id'] ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama']) ?>"
                                                    data-email="<?= htmlspecialchars($row['email']) ?>"
                                                    data-no_wa="<?= htmlspecialchars($row['no_wa']) ?>"
                                                    data-jabatan="<?= htmlspecialchars($row['jabatan']) ?>"
                                                    data-departemen="<?= htmlspecialchars($row['departemen']) ?>"
                                                    data-status="<?= htmlspecialchars($row['status']) ?>"
                                                    data-role="<?= htmlspecialchars($row['role']) ?>"
                                                    data-bs-toggle="modal" data-bs-target="#modalEdit"
                                                    title="Edit"><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="btn btn-sm btn-danger btn-hapus"
                                                    data-id="<?= (int) $row['id'] ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama']) ?>"
                                                    data-bs-toggle="modal" data-bs-target="#modalHapus"
                                                    title="Hapus"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8">
                                    <div class="nb-empty">
                                        <i class="bi bi-person-slash"></i>
                                        <p>Tidak ada data karyawan<?= $q !== '' ? ' untuk pencarian "' . htmlspecialchars($q) . '"' : '' ?>.</p>
                                    </div>
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalHalaman > 1): ?>
                    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2 no-print" style="border-top:3px solid #111111;">
                        <div class="small fw-bold text-muted">Hal. <?= $halaman ?> / <?= $totalHalaman ?> (<?= $total ?> data)</div>
                        <nav><ul class="pagination mb-0">
                            <?php if ($halaman > 1): ?>
                                <li class="page-item"><a class="page-link" href="index.php?q=<?= urlencode($q) ?>&halaman=<?= $halaman - 1 ?>"><i class="bi bi-chevron-left"></i></a></li>
                            <?php else: ?>
                                <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i></span></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalHalaman; $i++): ?>
                                <li class="page-item <?= $i === $halaman ? 'active' : '' ?>"><a class="page-link" href="index.php?q=<?= urlencode($q) ?>&halaman=<?= $i ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <?php if ($halaman < $totalHalaman): ?>
                                <li class="page-item"><a class="page-link" href="index.php?q=<?= urlencode($q) ?>&halaman=<?= $halaman + 1 ?>"><i class="bi bi-chevron-right"></i></a></li>
                            <?php else: ?>
                                <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-right"></i></span></li>
                            <?php endif; ?>
                        </ul></nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer class="footer-app text-center no-print">© <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?></footer>
    </main>

    <!-- ============================================================ -->
    <!-- MODAL: TAMBAH KARYAWAN                                       -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalTambah" tabindex="-1" aria-labelledby="modalTambahLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTambahLabel"><i class="bi bi-person-plus-fill me-2"></i>Tambah Karyawan Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="tambah.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <span class="form-section-title"><i class="bi bi-person"></i> Data Pribadi</span>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama" placeholder="Contoh: Budi Santoso" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" placeholder="nama@siakwa.com" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nomor WhatsApp <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="no_wa" placeholder="08xxxxxxxxxx" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jabatan <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="jabatan" placeholder="Contoh: Staff HRD" required>
                            </div>
                        </div>

                        <span class="form-section-title"><i class="bi bi-building"></i> Organisasi</span>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Departemen</label>
                                <input type="text" class="form-control" name="departemen" placeholder="Contoh: Human Capital" value="-">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="aktif" selected>Aktif</option>
                                    <option value="cuti">Cuti</option>
                                    <option value="keluar">Keluar</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="karyawan" selected>Karyawan</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>

                        <span class="form-section-title"><i class="bi bi-key"></i> Keamanan</span>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" placeholder="Min. 8 karakter" required>
                            </div>
                            <div class="col-md-6">
                                <div class="form-text mt-md-4 pt-md-2"><i class="bi bi-info-circle me-1"></i>Password akan dikirim ke WA karyawan.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan &amp; Kirim WA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: EDIT KARYAWAN                                         -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalEdit" tabindex="-1" aria-labelledby="modalEditLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditLabel"><i class="bi bi-pencil-fill me-2"></i>Edit Data Karyawan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="edit.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" id="edit-id">

                        <span class="form-section-title"><i class="bi bi-person"></i> Data Pribadi</span>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama" id="edit-nama" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" id="edit-email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nomor WhatsApp <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="no_wa" id="edit-no_wa" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jabatan <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="jabatan" id="edit-jabatan" required>
                            </div>
                        </div>

                        <span class="form-section-title"><i class="bi bi-building"></i> Organisasi</span>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Departemen</label>
                                <input type="text" class="form-control" name="departemen" id="edit-departemen">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit-status">
                                    <option value="aktif">Aktif</option>
                                    <option value="cuti">Cuti</option>
                                    <option value="keluar">Keluar</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role" id="edit-role">
                                    <option value="karyawan">Karyawan</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>

                        <span class="form-section-title"><i class="bi bi-key"></i> Reset Password (Opsional)</span>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Password Baru</label>
                                <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak diubah">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: KONFIRMASI HAPUS                                      -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalHapus" tabindex="-1" aria-labelledby="modalHapusLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--nb-red);">
                    <h5 class="modal-title" id="modalHapusLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="nb-confirm-delete mb-3">
                        <i class="bi bi-trash3-fill"></i>
                        <div>
                            <strong>Apakah Anda yakin?</strong><br>
                            <span>Data <strong id="hapus-nama">—</strong> akan dihapus permanen dan notifikasi WA akan dikirim.</span>
                        </div>
                    </div>
                    <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Tindakan ini tidak dapat dibatalkan. Semua data absensi terkait juga akan terhapus.</p>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <form method="post" action="hapus.php" id="formHapus">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="id" id="hapus-id">
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Ya, Hapus Sekarang</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

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
                <form method="post" action="../admin/presensi_proses.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="redirect_to" value="../karyawan/index.php">

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

        /* Select employee when clicking btn-absen */
        document.querySelectorAll('.btn-absen').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const selectEl = document.getElementById('presensi-karyawan-id');
                if (selectEl) {
                    selectEl.value = this.dataset.id;
                }
            });
        });

        /* Populate Edit Modal */
        document.querySelectorAll('.btn-edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('edit-id').value        = this.dataset.id;
                document.getElementById('edit-nama').value      = this.dataset.nama;
                document.getElementById('edit-email').value     = this.dataset.email;
                document.getElementById('edit-no_wa').value     = this.dataset.no_wa;
                document.getElementById('edit-jabatan').value   = this.dataset.jabatan;
                document.getElementById('edit-departemen').value= this.dataset.departemen;
                document.getElementById('edit-status').value    = this.dataset.status;
                document.getElementById('edit-role').value      = this.dataset.role;
            });
        });

        /* Populate Hapus Modal */
        document.querySelectorAll('.btn-hapus').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('hapus-id').value   = this.dataset.id;
                document.getElementById('hapus-nama').textContent = this.dataset.nama;
            });
        });

        /* Auto-dismiss flash alerts */
        setTimeout(function() {
            document.querySelectorAll('.alert-dismissible').forEach(function(el) {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
                bsAlert.close();
            });
        }, 5000);

        /* Auto-open Tambah Modal if URL has ?action=tambah */
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('action') === 'tambah') {
            const modalEl = document.getElementById('modalTambah');
            if (modalEl) {
                const modalTambah = new bootstrap.Modal(modalEl);
                modalTambah.show();
            }
        }
    </script>
</body>
</html>
