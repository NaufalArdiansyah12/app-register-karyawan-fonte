<?php
/* ============================================================================
   INCLUDES — SIDEBAR (Neo Brutalism)
   Menu navigasi samping untuk halaman admin & karyawan (Neo Brutalism).
   Harus dipanggil SETELAH session_start() dan role guard.
   Semua halaman dengan sidebar berada tepat satu folder di bawah root app.
   ============================================================================ */

if (count(get_included_files()) === 1) { http_response_code(403); exit('Akses langsung tidak diizinkan.'); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { return; }

$nbRole = (string) ($_SESSION['role'] ?? 'karyawan');
$nbDir  = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$nbPage = basename($_SERVER['SCRIPT_NAME'] ?? '');

$nbMenu = [];
if ($nbRole === 'admin') {
    $nbMenu = [
        ['Dashboard',        '../admin/index.php',        'bi-grid-1x2-fill',        ($nbDir === 'admin' && $nbPage === 'index.php')],
        ['Kelola Karyawan',  '../karyawan/index.php',     'bi-people-fill',          ($nbDir === 'karyawan')],
        ['Kelola Presensi',  '../absensi/index.php',      'bi-calendar2-check-fill', ($nbDir === 'absensi' && $nbPage === 'index.php')],
        ['Absen Masuk',      '../absensi/absen_masuk.php', 'bi-box-arrow-in-right',  ($nbPage === 'absen_masuk.php')],
        ['Absen Pulang',     '../absensi/absen_pulang.php','bi-box-arrow-out-right', ($nbPage === 'absen_pulang.php')],
        ['Pengaturan Jam',   '../admin/pengaturan_jam.php','bi-gear-fill',           ($nbDir === 'admin' && $nbPage === 'pengaturan_jam.php')],
    ];
} else {
    $nbMenu = [
        ['Presensi Saya',    '../absensi/index.php',      'bi-alarm-fill',           ($nbDir === 'absensi' && $nbPage === 'index.php')],
        ['Absen Masuk',      '../absensi/absen_masuk.php', 'bi-box-arrow-in-right',  ($nbPage === 'absen_masuk.php')],
        ['Absen Pulang',     '../absensi/absen_pulang.php','bi-box-arrow-out-right', ($nbPage === 'absen_pulang.php')],
    ];
}

$nbNama = trim((string) ($_SESSION['nama'] ?? 'Pengguna'));
$nbWords = preg_split('/\s+/', $nbNama) ?: ['Pengguna'];
$nbInitials = strtoupper(mb_substr($nbWords[0], 0, 1)) . (isset($nbWords[1]) ? strtoupper(mb_substr($nbWords[1], 0, 1)) : '');

$nbUserHtml = '<div class="nb-user-chip">'
    . '<div class="nb-user-avatar">' . htmlspecialchars($nbInitials) . '</div>'
    . '<div class="d-flex flex-column">'
    . '<span class="nb-user-name">' . htmlspecialchars($nbNama) . '</span>'
    . '<span class="nb-user-role">' . htmlspecialchars($nbRole === 'admin' ? 'Admin' : 'Karyawan') . '</span>'
    . '</div>'
    . '</div>';

$nbMenuHtml = '';
foreach ($nbMenu as $nbItem) {
    $nbMenuHtml .= '<a class="nb-menu-item' . ($nbItem[3] ? ' active' : '') . '" href="' . htmlspecialchars($nbItem[1]) . '">'
        . '<i class="bi ' . $nbItem[2] . '"></i><span>' . htmlspecialchars($nbItem[0]) . '</span></a>';
}

$nbMenuBody = '                        ' . $nbMenuHtml . '
                        ';
?>
<!-- ============ SIDEBAR DESKTOP (Neo Brutalism) ============ -->
<aside class="nb-sidebar">
    <div class="nb-sidebar-head">
        <div class="nb-logo"><i class="bi bi-shield-lock"></i></div>
        <div>
            <div class="nb-brand">SIAKWA</div>
            <div class="nb-brand-sub">Absensi Karyawan</div>
        </div>
    </div>
    <div class="nb-sidebar-body">
        <span class="nb-menu-label">Menu</span>
<?= $nbMenuBody ?>
    </div>
    <div class="nb-sidebar-foot">
        <?= $nbUserHtml ?>
        <a class="nb-logout" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
    </div>
</aside>

<!-- ============ TOPBAR MOBILE ============ -->
<div class="nb-topbar d-lg-none">
    <button class="nb-tb-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#nbOffcanvas" aria-label="Buka menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="nb-tb-brand"><i class="bi bi-shield-lock me-1"></i>SIAKWA</div>
</div>

<!-- ============ OFFCANVAS MOBILE ============ -->
<div class="offcanvas offcanvas-start nb-offcanvas d-lg-none" tabindex="-1" id="nbOffcanvas" aria-labelledby="nbOffcanvasLabel">
    <div class="offcanvas-header">
        <div class="d-flex align-items-center gap-2" id="nbOffcanvasLabel">
            <div class="nb-logo"><i class="bi bi-shield-lock"></i></div>
            <div>
                <div class="nb-brand">SIAKWA</div>
                <div class="nb-brand-sub">Absensi Karyawan</div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <span class="nb-menu-label">Menu</span>
<?= $nbMenuBody ?>
        <div class="nb-sidebar-foot">
            <?= $nbUserHtml ?>
            <a class="nb-logout" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
        </div>
    </div>
</div>
