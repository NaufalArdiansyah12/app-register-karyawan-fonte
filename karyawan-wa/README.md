# SIAKWA — Sistem Register, Manajemen Data Karyawan, dan Absensi
## dengan Integrasi WhatsApp Gateway (Fonnte)

Aplikasi web berbasis PHP 8 Native (tanpa framework) + MySQL + Bootstrap 5,
yang menyediakan **Register, Manajemen Data Karyawan, dan Absensi**, serta
**notifikasi WhatsApp** melalui **Fonnte WhatsApp API**.

---

## 1. FITUR UTAMA

- ✅ Registrasi karyawan baru (admin dapat menambahkan akun karyawan).
- ✅ Login karyawan / admin dengan session PHP & password hashing (BCRYPT).
- ✅ Dashboard admin: statistik hari ini, rekap status absensi, laporan absensi.
- ✅ Manajemen karyawan: daftar, tambah, edit, hapus (dengan konfirmasi & WA).
- ✅ Halaman presensi: status hari ini, absen masuk/pulang, pengajuan izin/sakit.
- ✅ Deteksi keterlambatan absen masuk berdasarkan jam batas masuk.
- ✅ Notifikasi WhatsApp via Fonnte (cURL) ke karyawan & admin.
- ✅ Export laporan absensi ke format Excel & PDF (print).

---

## 2. TEKNOLOGI

| Aspek | Teknologi |
|---|---|
| Backend | PHP 8.x (Native, tanpa framework) |
| Database | MySQL (`db_karyawan`) |
| Frontend | HTML5, Bootstrap 5.3 (CDN), JavaScript (vanilla) |
| API WhatsApp | Fonnte WhatsApp API (`https://api.fonnte.com/send`) via PHP cURL |
| Security | Password hashing BCRYPT, Prepared Statements, htmlspecialchars XSS, PHP Session, CSRF Token, confirm dialog |

---

## 3. STRUKTUR FOLDER

```text
karyawan-wa/
├── config/
│   ├── config.php          # Konstanta FONNTE_TOKEN, ADMIN_WA, jam batas masuk
│   └── database.php        # Koneksi MySQLi + load config.php + set timezone
├── api/
│   └── fonnte.php          # Fungsi kirimWA($target, $pesan) via cURL + logging
├── auth/
│   ├── register.php        # Form & proses registrasi + validasi + trigger 2 WA
│   ├── login.php           # Form & proses login session
│   └── logout.php          # Destroy session & redirect
├── karyawan/
│   ├── index.php           # Tabel daftar karyawan + tombol Tambah, Edit, Hapus
│   ├── tambah.php          # Form & proses tambah karyawan admin + trigger WA
│   ├── edit.php            # Form & proses update karyawan + trigger WA ke karyawan
│   └── hapus.php           # Proses ambil data -> kirim WA -> hapus dari DB
├── absensi/
│   ├── index.php           # Halaman presensi: info profil, status hari ini, tombol
│   │                       #   Absen Masuk/Pulang & form Izin/Sakit
│   ├── masuk.php           # Endpoint proses absen masuk + deteksi Terlambat + WA
│   └── pulang.php          # Endpoint proses absen pulang + WA ke karyawan & admin
├── admin/
│   └── index.php           # Dashboard statistik + Rekap status + Laporan + Filter +
│                           #   Export Excel/PDF
├── index.php               # Landing page / redirect otomatis ke login atau dashboard
├── database.sql            # File SQL pembuatan database dan tabel
└── README.md               # Dokumentasi ini
```

---

## 4. INSTALASI

1. **Prasyarat** : XAMPP / Laragon (Apache + MySQL + PHP), atau PHP 8.x + MySQL.

2. **Letakkan aplikasi** : Pindahkan folder `karyawan-wa` ke htdocs, misalnya:

   ```text
   C:\xampp\htdocs\karyawan-wa\
   ```

3. **Jalankan** :
   - Apache & MySQL (via XAMPP Control Panel).
   - Buka browser: `http://localhost/karyawan-wa/`

4. **Import database** : Buka `http://localhost/phpmyadmin`, buat database
   `db_karyawan`, lalu import file `karyawan-wa/database.sql`.

5. **Konfigurasi** : Edit file `karyawan-wa/config/config.php` :
   - `FONNTE_TOKEN` : token Fonnte (lihat bagian 6).
   - `ADMIN_WA` : nomor WhatsApp admin (format `6281234567890`, tanpa `0`, tanpa `+`).

6. **Ubah timezone PHP** : Pastikan `date_default_timezone_set('Asia/Jakarta')`
   aktif (sudah di-set di `config/database.php`).

---

## 5. AKUN DEFAULT

| Peran | Email | Password |
|---|---|---|
| Admin | `admin@siakwa.com` | `admin123` |
| Karyawan | `budi@siakwa.com` | `karyawan123` |

> **Catatan keamanan** : Ganti password default sebelum digunakan di lingkungan
> produksi.

---

## 6. KONFIGURASI WHATSAPP GATEWAY (FONNTE)

1. Login ke dashboard Fonnte : `https://md.fonnte.com`.
2. Pilih / tandai **device** yang terhubung.
3. Salin **token** dari dashboard.
4. Masukkan ke `karyawan-wa/config/config.php` pada konstanta `FONNTE_TOKEN`.
5. Sesuaikan `ADMIN_WA` dengan nomor admin.

Jika response dari Fonnte menyatakan gagal, periksa file log :
`karyawan-wa/logs/wa_log.txt`.

---

## 7. SKENARIO PENGUJIAN API / ALUR USABILITY

1. **Registrasi** :
   - Buka `auth/register.php`, isi data & submit.
   - Harus ada **2 notifikasi WA** : ke nomor karyawan & ke `ADMIN_WA`.

2. **Login** :
   - Admin → `admin/index.php`.
   - Karyawan → `absensi/index.php`.

3. **Absen Masuk** :
   - Sebelum `08:00:00` → status `hadir` (Tepat Waktu).
   - Sesudah `08:00:00` → status `terlambat`.
   - WA ke karyawan & admin.

4. **Absen Pulang** :
   - Hanya bisa setelah `jam_masuk` tercatat.
   - WA ke karyawan & admin.

5. **Izin / Sakit** :
   - Submit form dari halaman presensi.
   - Status di catat sebagai `izin` / `sakit`.

6. **CRUD Karyawan** :
   - Tambah → WA ke karyawan.
   - Edit → WA ke karyawan.
   - Hapus → konfirmasi `confirm()` → WA ke karyawan → hapus DB.

7. **Dashboard Admin** :
   - Lihat statistik, rekap, filter tanggal, export Excel & cetak PDF.

---

## 8. TROUBLESHOOTING

- **WA tidak terkirim** : periksa `FONNTE_TOKEN`, status device Fonnte
  (terhubung), dan file `logs/wa_log.txt`.
- **SSL error cURL** : pastikan CA bundle cocok / sertifikat valid
  (untuk XAMPP disarankan menyetel `CURLOPT_SSL_VERIFYPEER`).
- **Nomor WA** : format harus `62xxxxxxxxxx` (tanpa `0`, tanpa `+`).
- **Timezone** : pastikan jam server & `Asia/Jakarta`.

---

## 9. KEAMANAN (PRODUKSI)

- Password menggunakan `password_hash()` / `password_verify()`.
- Semua query menggunakan Prepared Statements.
- Output tampil menggunakan `htmlspecialchars()`.
- Session PHP untuk autentikasi & proteksi halaman.
- CSRF Token untuk semua aksi POST.
- Konfirmasi hapus via `confirm()`.
- Timezone `Asia/Jakarta`.
