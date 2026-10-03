<?php
/**
 * RINGKASAN FUNGSI APLIKASI — dokumen PDF untuk MIGRASI / serah terima.
 * =====================================================================
 *
 * Permintaan pemilik klinik: ingin ada "catatan kesimpulan fungsi web" berbentuk
 * PDF yang dapat diunduh dari Developer Settings, supaya AI agent di web lain
 * (atau developer baru) langsung memahami cara kerja aplikasi ini ketika diminta
 * menambah fitur / memperbaiki sesuatu.
 *
 * Prinsip penting:
 *   1. ISI DIAMBIL DARI DATA & KODE YANG SEDANG BERJALAN, bukan tulisan tangan.
 *      Jadi setiap kali diunduh ulang, dokumennya otomatis mengikuti keadaan
 *      terbaru: daftar tabel & kolom dari database, daftar modul dari berkas
 *      yang benar-benar ada, peran & hak akses dari tabel roles/permissions,
 *      status integrasi dari pengaturan, dan jumlah data terkini.
 *   2. RAHASIA TIDAK PERNAH DITULIS: kunci API, sandi SMTP, token WhatsApp, dan
 *      kunci gateway hanya dilaporkan sebagai "sudah diisi / belum diisi".
 *   3. Aturan bisnis penting ditulis sebagai daftar bernomor dengan rujukan
 *      berkas, supaya agent tahu DI MANA aturannya ditegakkan.
 *
 * Fungsi utama:
 *   app_summary_blocks()      → susunan blok dokumen (judul, paragraf, kv, tabel)
 *   app_summary_pdf_bytes()   → PDF A4 (MiniPdf) siap diunduh
 *   app_summary_note()        → catatan tambahan dari pemilik (disimpan di setelan)
 */
declare(strict_types=1);

/* Helper dari modul lain yang dipakai untuk melaporkan STATUS integrasi.
   Wajib `require_once` (bukan `require`) — memuat ulang modul yang sudah dimuat
   pernah membuat skrip CLI mati senyap karena "cannot redeclare". */
require_once __DIR__ . '/mailer.php';        // mail_status_text(), wa_api_configured()
require_once __DIR__ . '/backup_lib.php';    // backup_storage_info()
require_once __DIR__ . '/finance.php';       // finance_mode_label(), finance_calc_height_label()
require_once __DIR__ . '/retention.php';     // retention_enabled()
require_once __DIR__ . '/clinic.php';        // clinic_name()

/** Kunci setelan untuk catatan tambahan pemilik pada dokumen ini. */
function app_summary_note(): string
{
    return (string)setting('app_summary_note', '');
}

/**
 * Daftar modul aplikasi beserta kegunaannya.
 *
 * Keterangan diambil dari peta di bawah (ditulis sekali) dan dari berkas yang
 * BENAR-BENAR ada di folder aplikasi, sehingga modul baru otomatis muncul pada
 * bagian "Modul lain" walaupun belum didaftarkan di sini.
 */
function app_summary_modules(): array
{
    /* berkas => [nama modul, kegunaan singkat] */
    return [
        'dashboard.php' => ['Dashboard', 'Ringkasan KPI, grafik pendapatan pasien, stok menipis, dan pemberitahuan tugas otomatis (backup/retensi/email).'],
        'pasien.php' => ['Data Pasien', 'Master pasien per cabang: identitas, kontak/email, foto, status (Baru/Lama otomatis), kartu member, impor & ekspor.'],
        'pasien_detail.php' => ['Detail Pasien', 'Riwayat lengkap satu pasien: transaksi, rekam medis, reservasi, pemakaian bahan, kartu member (dengan filter periode).'],
        'reservasi.php' => ['Reservasi', 'Jadwal perawatan (multi-treatment), dokter/terapis, status, pengingat WhatsApp, lanjut ke transaksi & rekam medis.'],
        'rekam_medis.php' => ['Rekam Medis Elektronik', 'Daftar catatan klinis (SOAP), status penanganan, filter dokter/terapis/periode, amendment, hapus per periode.'],
        'rekam_medis_form.php' => ['Form Rekam Medis', 'Isi/ubah catatan klinis: SOAP, ICD-10 & ICD-9-CM (saran otomatis), foto & lampiran klinis, mode Lihat (baca-saja).'],
        'order_baru.php' => ['Order Baru', 'Kasir membuat transaksi: item treatment/skincare/paket, bahan treatment terpakai, diskon member, langkah pembayaran (wajib bayar dulu).'],
        'order.php' => ['Riwayat Order', 'Daftar transaksi dengan filter lengkap, status bayar, struk, email/WhatsApp, void/refund, ekspor.'],
        'order_detail.php' => ['Detail Transaksi', 'Rincian satu transaksi: item, diskon, pembayaran & kode unik, isi paket, bahan terpakai, ubah metode bayar, kirim struk.'],
        'struk.php' => ['Struk', 'Tampilan struk 80 mm siap cetak + PDF, kirim via WhatsApp/email, dan tautan struk publik bertoken (tanpa login).'],
        'laporan.php' => ['Laporan', 'Laporan kinerja klinik: KPI, tren harian/bulanan, treatment & skincare terlaris, kasir, metode bayar, pemakaian bahan & kartu member.'],
        'keuangan.php' => ['Keuangan (owner)', 'Omzet, HPP, laba kotor, biaya operasional per cakupan cabang, laba bersih, mode laporan dasar/lengkap, ekspor.'],
        'top5.php' => ['Top 5 Penjualan', 'Peringkat treatment & produk terlaris pada periode terpilih.'],
        'top10_pasien.php' => ['Top 10 Pasien', 'Pasien paling aktif/loyal berdasarkan nilai transaksi.'],
        'treatment.php' => ['Master Treatment', 'Daftar layanan per cabang: harga, HPP, durasi, kategori, paket treatment, impor/ekspor.'],
        'skincare.php' => ['Master Skincare', 'Produk yang dijual: harga, HPP (harga beli), stok minimum, paket produk.'],
        'bahan.php' => ['Bahan Treatment', 'Bahan habis pakai (mis. masker, serum) yang dipakai saat tindakan: stok, satuan, pemakaian di transaksi.'],
        'suppliers.php' => ['Supplier', 'Data pemasok produk & bahan beserta kontak dan jumlah item terkait.'],
        'inventory_movement.php' => ['Stok & Movement', 'Riwayat pergerakan stok (penjualan, pembelian, pemakaian bahan, koreksi) untuk penelusuran.'],
        'users.php' => ['Manajemen User', 'Akun staf, peran, cabang, dan hak akses tambahan (matriks peran × izin).'],
        'branches.php' => ['Cabang', 'Data cabang: kode (dipakai pada nomor dokumen), alamat, kontak, jam buka, status.'],
        'staff.php' => ['Dokter & Terapis', 'Data tenaga medis per cabang beserta jadwal praktik, nomor WhatsApp, dan foto.'],
        'icd.php' => ['Kamus ICD-10 / 9-CM', 'Penelusuran kamus kode diagnosis & tindakan resmi (12.084 + 3.882 kode) yang dipakai form rekam medis.'],
        'import.php' => ['Import Data', 'Impor Excel/CSV: pilih berkas → petakan kolom → pratinjau → jalankan, plus riwayat dan unduhan galat per baris.'],
        'export.php' => ['Ekspor', 'Mesin ekspor terpusat: CSV, Excel (.xlsx dengan foto/grafik), dan dokumen cetak/PDF untuk setiap modul.'],
        'audit_log.php' => ['Audit Log', 'Jejak semua tindakan penting (siapa, kapan, apa yang berubah), dengan pembersihan per rentang tanggal.'],
        'settings.php' => ['Pengaturan Sistem', 'Pengaturan operasional: identitas klinik, tema warna, kompresi foto, email, WhatsApp, pembayaran, kartu member.'],
        'developer.php' => ['Developer Settings', 'Pengaturan tingkat sistem (khusus Super Admin): nama klinik, retensi data, backup, mode pemeliharaan, integrasi, hapus/isi data, dan dokumen ini.'],
        'backup.php' => ['Backup Database', 'Salinan database terkompres (.sql.gz) + pemulihan, dijalankan manual atau otomatis harian.'],
        'ai_settings.php' => ['AI Settings', 'Pengaturan penyedia AI (Gemini/OpenAI) untuk menu AI Developer: kunci API, model, cakupan folder (aplikasi + skrip uji), ukuran/berkas maksimal, suite uji bawaan, dan uji koneksi. Khusus Super Admin.'],
        'ai_developer.php' => ['AI Developer', 'Asisten pengembangan: minta AI merevisi/memperbaiki/menambah fitur — AI membaca kode, menyusun usulan (patch), ditampilkan sebagai PRATINJAU (diff + pemeriksaan sintaks), diuji di folder STAGING, lalu DITERAPKAN hanya setelah disetujui Super Admin, dengan tombol pembatalan.'],
        'ai_worker.php' => ['Pekerja AI (CLI)', 'Menjalankan analisis AI & suite uji di latar belakang (hanya dari baris perintah; menolak dijalankan dari peramban).'],
        'maintenance.php' => ['Mode Pemeliharaan', 'Halaman pemberitahuan saat sistem dibatasi hanya-baca (mis. saat pemeliharaan), otomatis kembali normal.'],
        'profile.php' => ['Profil Saya', 'Ubah data akun sendiri dan kata sandi.'],
        'bayar.php' => ['Halaman Bayar', 'Halaman pembayaran pasien untuk tagihan gateway (QRIS/transfer) dan tombol cek status.'],
        'api.php' => ['API internal (JSON)', 'Pencarian/saran otomatis dan data pendukung antarmuka (pasien, treatment, skincare, paket, bahan, form reservasi).'],
        'media.php' => ['Media Rekam Medis', 'Penyaji foto/lampiran klinis dengan pemeriksaan login & cabang (URL penyimpanan tidak pernah dibuka ke peramban).'],
        'photo.php' => ['Foto Orang', 'Penyaji foto pasien/dokter/terapis dengan pemeriksaan login & cabang.'],
    ];
}

/** Bagian: identitas & keadaan sistem. */
function app_summary_identity(): array
{
    $rows = [
        ['Nama klinik', clinic_name()],
        ['Versi skema database', (string)setting('schema_version', '-')],
        ['Versi kode aplikasi', defined('APP_VERSION') ? (string)APP_VERSION : '-'],
        ['Zona waktu', date_default_timezone_get() . ' (jam server: ' . date('d/m/Y H:i') . ')'],
        ['Jumlah cabang', (string)count(branches())],
        ['Mode laporan keuangan', finance_mode_label()],
        ['Tema warna aktif', theme_current()['name'] . ' (dari ' . count(theme_list()) . ' pilihan)'],
        ['Kartu member', function_exists('member_card_enabled') && member_card_enabled() ? 'aktif' : 'nonaktif'],
        ['Mode pemeliharaan', maintenance_on() ? 'AKTIF (hanya baca untuk level non-Super Admin)' : 'nonaktif'],
        ['Batas penyimpanan backup', (int)setting('backup_max_mb', '500') . ' MB'
            . (function_exists('backup_storage_info') ? ' (terpakai ' . round((float)backup_storage_info()['mb'], 1) . ' MB)' : '')],
        ['Tinggi maksimal tabel perhitungan', function_exists('finance_calc_height_label') ? finance_calc_height_label() : '-'],
    ];
    $out = [['t' => 'p', 'text' => 'Dokumen ini dibuat OTOMATIS dari keadaan aplikasi saat diunduh: daftar tabel, modul, peran, '
        . 'status integrasi, dan aturan bisnis di bawah mengikuti kode & data yang sedang berjalan. Unduh ulang dokumen ini '
        . 'setiap kali ada fitur baru supaya tetap akurat. Rahasia (kunci API, sandi) tidak pernah ditulis di sini.']];
    $out[] = ['t' => 'kv', 'items' => $rows];
    return $out;
}

/** Bagian: modul & halaman (dari berkas yang benar-benar ada). */
function app_summary_modules_blocks(): array
{
    $map = app_summary_modules();
    $ada = [];
    foreach (glob(APP_DIR . '/*.php') ?: [] as $f) $ada[] = basename($f);
    sort($ada);
    $blocks = [['t' => 'p', 'text' => 'Aplikasi ini adalah satu sistem PHP tanpa framework (tanpa build step). '
        . 'Tidak ada routing: setiap berkas di bawah ini adalah satu halaman yang menyertakan '
        . '`includes/config.php` (bootstrap: pengaturan, sesi, hak akses, helper) dan `includes/layout.php` (kerangka halaman). '
        . 'Total ' . count($ada) . ' berkas halaman terdeteksi.']];
    $rows = [];
    foreach ($ada as $f) {
        if (!isset($map[$f])) continue;
        $rows[] = [$map[$f][0], $f, $map[$f][1]];
    }
    $blocks[] = ['t' => 'table', 'head' => ['Modul', 'Berkas', 'Kegunaan'], 'rows' => $rows];
    $lain = array_values(array_diff($ada, array_keys($map)));
    if ($lain) {
        $blocks[] = ['t' => 'p', 'text' => 'Berkas halaman lain (belum didaftarkan di peta ringkasan — biasanya berkas pendukung): '
            . implode(', ', array_map(fn($f) => $f, $lain)) . '.'];
    }
    return $blocks;
}

/** Bagian: peran & hak akses (dari database). */
function app_summary_roles_blocks(): array
{
    $roles = function_exists('roles_ordered') ? roles_ordered() : all('SELECT * FROM roles ORDER BY id');
    $rows = [];
    foreach ($roles as $r) {
        $permid = (int)($r['id'] ?? 0);
        $n = (int)scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$permid], 0);
        $users = (int)scalar('SELECT COUNT(*) FROM users WHERE role_id = ? AND status = "active"', [$permid], 0);
        $rows[] = [(string)($r['name'] ?? '-'), (string)($r['code'] ?? '-'), (string)$n . ' izin', (string)$users . ' akun aktif'];
    }
    $bl = [['t' => 'p', 'text' => 'Hak akses ditegakkan di SERVER pada setiap berkas (bukan hanya menyembunyikan tombol). '
        . 'Dua hal yang mudah tertukar: `is_owner_level()` (Super Admin + Direktur/Owner) dipakai untuk hal LINTAS CABANG '
        . '(cakupan cabang, pemilih cabang, kolom cabang pada laporan), sedangkan `is_super()` (hanya Super Admin) dipakai untuk '
        . 'tindakan sistem yang berisiko (hapus permanen, hapus semua data, restore backup, ubah nama klinik).']];
    $bl[] = ['t' => 'table', 'head' => ['Peran', 'Kode', 'Izin', 'Akun'], 'rows' => $rows];
    /* Cakupan izin beberapa kode penting. */
    $keys = ['finance.view', 'medical.manage', 'order.manage', 'backup.manage', 'maintenance.manage', 'system.integration'];
    $rows2 = [];
    foreach ($keys as $k) {
        $names = [];
        foreach ($roles as $r) {
            $ok = (int)scalar('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                               WHERE rp.role_id = ? AND p.code = ?', [(int)$r['id'], $k], 0) > 0;
            if ($ok) $names[] = (string)$r['name'];
        }
        $rows2[] = [$k, $names ? implode(', ', $names) : '(tidak ada)'];
    }
    $bl[] = ['t' => 'table', 'head' => ['Izin penting', 'Dimiliki oleh'], 'rows' => $rows2];
    return $bl;
}

/** Bagian: struktur basis data (tabel & kolom dari sqlite_master + PRAGMA). */
function app_summary_schema_blocks(): array
{
    $tables = all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $bl = [['t' => 'p', 'text' => 'Basis data: SQLite (' . strtoupper('sqlite') . ') dengan ' . count($tables) . ' tabel. '
        . 'PERHATIAN untuk agent: seluruh query WAJIB dibatasi cabang lewat helper `branch_sql()`/`bscope()`/`scope_branch()`, '
        . 'dan `PRAGMA foreign_keys = ON` aktif sehingga tabel anak harus dihapus lebih dulu sebelum induknya.']];
    $rows = [];
    foreach ($tables as $t) {
        $name = (string)$t['name'];
        $cols = all('PRAGMA table_info("' . str_replace('"', '', $name) . '")');
        $nRows = (int)scalar('SELECT COUNT(*) FROM "' . str_replace('"', '', $name) . '"', [], 0);
        $rows[] = [$name, (string)count($cols) . ' kolom', (string)num($nRows) . ' baris',
            implode(', ', array_map(fn($c) => (string)$c['name'], array_slice($cols, 0, 6)))
            . (count($cols) > 6 ? ', …' : '')];
    }
    $bl[] = ['t' => 'table', 'head' => ['Tabel', 'Kolom', 'Jumlah data', 'Kolom awal'], 'rows' => $rows];
    return $bl;
}

/** Bagian: status integrasi & pengaturan (TANPA rahasia). */
function app_summary_integration_blocks(): array
{
    $isi = fn(string $k) => trim((string)setting($k, '')) !== '';
    $bl = [['t' => 'p', 'text' => 'Sistem TIDAK PERNAH mengklaim terkirim/aktif bila belum dikonfigurasi — status di bawah mengikuti '
        . 'pengaturan nyata. Kunci/sandi hanya ditandai "sudah diisi" atau "belum diisi".']];
    $rows = [
        ['Email laporan/staf', (string)mail_status_text()],
        ['Email struk otomatis ke pasien', setting('email_receipt_auto') === '1' ? 'aktif' : 'nonaktif'],
        ['Penerima laporan', $isi('email_recipient') ? 'sudah diisi' : 'belum diisi'],
        ['Penerima laporan + keuangan (Direktur)', $isi('email_finance_recipient') ? 'sudah diisi' : 'belum diisi'],
        ['WhatsApp (API)', function_exists('wa_api_configured') && wa_api_configured() ? 'terkonfigurasi' : 'belum (mode tautan wa.me)'],
        ['Pengingat WA ke dokter', setting('wa_doctor_active', '1') === '1' ? 'aktif' : 'nonaktif'],
        ['Payment gateway', function_exists('pay_gateway_configured') && pay_gateway_configured()
            ? 'terkonfigurasi (' . (string)setting('pay_gateway', '-') . ' / ' . (string)setting('pay_gateway_env', '-') . ')'
            : 'belum dikonfigurasi (jalur manual + kode unik tetap jalan)'],
        ['QRIS manual (gambar)', $isi('pay_qris_file') ? 'ada' : 'belum ada'],
        ['Satu Sehat', $isi('satu_sehat_client_id') ? 'kredensial terisi (uji koneksi dari halaman Kamus ICD)' : 'belum ada kredensial'],
        ['Kamus ICD terpasang', (string)num((int)scalar('SELECT COUNT(*) FROM icd_codes', [], 0)) . ' kode'],
        ['Backup otomatis', setting('backup_auto', '0') === '1' ? 'aktif' : 'nonaktif'],
        ['Pembersihan data otomatis (retensi)', function_exists('retention_enabled') && retention_enabled('audit') ? 'aktif' : 'nonaktif'],
        ['Total data operasional', num((int)scalar('SELECT COUNT(*) FROM patients', [], 0)) . ' pasien · '
            . num((int)scalar('SELECT COUNT(*) FROM orders', [], 0)) . ' transaksi · '
            . num((int)scalar('SELECT COUNT(*) FROM medical_records', [], 0)) . ' rekam medis · '
            . num((int)scalar('SELECT COUNT(*) FROM appointments', [], 0)) . ' reservasi'],
    ];
    $bl[] = ['t' => 'kv', 'items' => $rows];
    return $bl;
}

/**
 * Bagian: aturan bisnis penting + lokasi kodenya.
 *
 * Ini bagian yang paling berguna untuk agent: aturan yang TIDAK boleh dilanggar
 * saat menambah fitur, beserta berkas tempat aturannya ditegakkan.
 */
function app_summary_rules(): array
{
    return [
        ['Transaksi WAJIB dibayar dulu', 'Semua jalur (kasir, halaman bayar gateway, webhook) melewati SATU fungsi `order_create()` di `includes/order_create.php` yang menolak bila pembayaran belum dikonfirmasi. Jangan membuat jalur penyimpanan transaksi baru di luar fungsi ini.'],
        ['Stok berkurang/bertambah lewat satu helper', 'Semua perubahan stok memakai `inv_apply()` (config.php) supaya tercatat di tabel `inventory_movements`. Bahan treatment & isi paket juga mengurangi stok, dan dikembalikan saat void/hapus.'],
        ['Harga & diskon selalu dihitung ulang di server', 'Nilai diskon/kartu member yang dikirim peramban diabaikan; server memakai `member_discount_calc()`.'],
        ['Batasan cabang', 'Setiap data operasional terikat cabang. Non-owner selalu dipin ke cabangnya (`assert_branch()` menolak akses silang, HTTP 403). Untuk pemakai lintas cabang, cabang transaksi mengikuti cabang DATA (pasien → reservasi → pemilih cabang).'],
        ['Biaya operasional punya CAKUPAN', 'Satu pos biaya punya mode: nominal per cabang (semua cabang terisi dihitung bersama) atau biaya bersama "Semua Cabang" yang dibagi rata ke tiap cabang saat laporan difilter (`includes/finance.php`).'],
        ['Penomoran dokumen', 'Semua nomor dibuat `seq_next()` (config.php): pasien `DP-<KODE>-1001001`, member `MC-…`, rekam medis `RM-…`, reservasi `RES-<KODE>-YYMMDD-…`, invoice `OR-<KODE>-YYMMDD-…` (seri per cabang, tidak reset harian).'],
        ['Pencegahan data ganda', 'Setiap form membawa token sekali-pakai `_once`; `verify_csrf()` memanggil `guard_single_submit()` sehingga pengiriman ulang (klik berkali-kali di jaringan lambat) tidak membuat data kedua. Di peramban dijaga `form.is-submitting` (app.js).'],
        ['Rekam medis terkunci = baca-saja', 'Baris dengan `locked_at` tidak dapat diubah; perbaikan hanya lewat Amendment (baris baru dengan `amended_from`).'],
        ['Bentuk tabel TIDAK diubah di layar kecil', 'Tabel tetap tabel dan dapat digeser (`.table-wrap`); jangan mengubahnya menjadi daftar kartu.'],
        ['Foto pengguna melalui proxy media', 'Unggahan pengguna dikirim ke media proxy platform dari sisi SERVER (token hanya dibaca di server); foto klinis disajikan lewat `media.php`/`photo.php` yang memeriksa login & cabang.'],
        ['Mode pemeliharaan terpusat', '`maintenance_gate()` di akhir `includes/config.php` memblokir aksi tulis untuk semua level kecuali Super Admin.'],
        ['Retensi & hapus data', 'Pembersihan otomatis/manual memakai `includes/retention.php`; `purge.php` (Hapus Semua Data) memakai pratinjau + verifikasi + konfirmasi 2 tahap + snapshot pengaman.'],
        ['Ekspor', 'Semua ekspor lewat `export.php` (CSV/Excel .xlsx/dokumen cetak). Excel asli disusun `includes/xlsx.php`, dokumen cetak `includes/report_document.php`, PDF dengan grafik `includes/report_pdf.php`.'],
        ['Dokumen & angka harus sinkron', 'Laporan di layar, dokumen cetak, Excel, dan email memakai SUMBER yang sama (`includes/reports.php` untuk kinerja, `includes/finance.php` untuk keuangan). Jangan menghitung ulang di halaman.'],
        ['Zona waktu', 'Server berjalan UTC, aplikasi memaksa `Asia/Jakarta` (config.php) baik untuk PHP maupun koneksi SQLite.'],
        ['AI Developer tidak boleh menulis kode sendiri', 'AI hanya menghasilkan teks patch (`includes/ai.php`). Penerapan dilakukan aplikasi setelah persetujuan Super Admin, selalu memakai pencocokan teks PERSIS ("search" harus ditemukan tepat sekali; kalau tidak, seluruh patch ditolak), dibatasi cakupan folder, diuji di folder staging, dan disertai salinan pengaman + tombol pembatalan.'],
        ['Kunci API rahasia', 'Kunci penyedia AI disimpan di setelan dan tidak pernah ditampilkan kembali/ditulis ke log. Jangan mengirimnya ke peramban.'],
    ];
}

/** Bagian: cara memverifikasi perubahan (untuk agent). */
function app_summary_tests(): array
{
    return [['t' => 'p', 'text' => 'Proyek ini punya kumpulan uji regresi yang dijalankan dengan '
        . '`bash naveena_dev/test/run_all.sh` (ratusan sampai ribuan pemeriksaan: peran & hak akses, '
        . 'perhitungan keuangan, cetak PDF/Excel, tampilan HP/tablet, dan pemeriksaan produksi baca-saja). '
        . 'Setiap kali menambah fitur: jalankan suite terkait (mis. `bash run_all.sh keuangan`) lalu jalankan '
        . 'lebih banyak suite sebelum menyerahkan hasil. Uji memakai database & server terpisah sehingga data '
        . 'produksi tidak tersentuh.']];
}

/** Susun seluruh blok dokumen ringkasan. */
function app_summary_blocks(): array
{
    $b = [];
    $b[] = ['t' => 'h1', 'text' => 'Ringkasan Fungsi Aplikasi — ' . clinic_name()];
    $b[] = ['t' => 'p', 'text' => 'Dokumen acuan untuk agent/developer: cara kerja aplikasi manajemen klinik ini, '
        . 'struktur datanya, aturan bisnis yang wajib dipatuhi, dan tempat aturan itu ditegakkan. Dicetak: '
        . tglIndo(date('Y-m-d')) . ' ' . date('H:i') . ' oleh ' . (string)(current_user()['name'] ?? '-') . '.'];
    $b[] = ['t' => 'h2', 'text' => '1. Identitas & keadaan sistem'];
    $b = array_merge($b, app_summary_identity());
    $b[] = ['t' => 'h2', 'text' => '2. Modul & halaman'];
    $b = array_merge($b, app_summary_modules_blocks());
    $b[] = ['t' => 'h2', 'text' => '3. Peran & hak akses'];
    $b = array_merge($b, app_summary_roles_blocks());
    $b[] = ['t' => 'h2', 'text' => '4. Struktur basis data'];
    $b = array_merge($b, app_summary_schema_blocks());
    $b[] = ['t' => 'h2', 'text' => '5. Integrasi & pengaturan (tanpa rahasia)'];
    $b = array_merge($b, app_summary_integration_blocks());
    $b[] = ['t' => 'h2', 'text' => '6. Aturan bisnis wajib (dan lokasi kodenya)'];
    $rows = [];
    foreach (app_summary_rules() as $i => $r) $rows[] = [(string)($i + 1), $r[0], $r[1]];
    $b[] = ['t' => 'table', 'head' => ['#', 'Aturan', 'Cara kerja / berkas terkait'], 'rows' => $rows];
    $b[] = ['t' => 'h2', 'text' => '7. Cara memverifikasi perubahan'];
    $b = array_merge($b, app_summary_tests());
    $note = app_summary_note();
    if (trim($note) !== '') {
        $b[] = ['t' => 'h2', 'text' => '8. Catatan tambahan dari pemilik klinik'];
        foreach (preg_split('/\r\n|\n|\r/', trim($note)) as $line) {
            if (trim($line) === '') { $b[] = ['t' => 'gap']; continue; }
            $b[] = ['t' => 'p', 'text' => $line];
        }
    }
    $b[] = ['t' => 'hr'];
    $b[] = ['t' => 'p', 'text' => 'Dokumen ini dihasilkan otomatis dari aplikasi yang sedang berjalan; '
        . 'unduh ulang setelah ada perubahan fitur agar isinya tetap sesuai.'];
    return $b;
}

/**
 * Bangun PDF ringkasan — A4 potret, HALAMAN BANYAK (otomatis).
 *
 * Berbeda dari dokumen laporan lain yang memakai satu halaman panjang, dokumen
 * acuan ini isinya banyak (daftar tabel, modul, aturan) sehingga dipakai halaman
 * A4 standar (595 × 842 pt) dengan pemenggalan otomatis oleh MiniPdf::ensure().
 * Tinggi halaman TETAP, jadi teks tidak pernah keluar dari area cetak.
 */
function app_summary_pdf_bytes(): string
{
    $pdf = new MiniPdf(595.28, 842, 40);
    app_summary_render($pdf, app_summary_blocks());
    return $pdf->output();
}

/** Gambar blok-blok ringkasan ke dokumen MiniPdf. */
function app_summary_render(MiniPdf $p, array $blocks): void
{
    $logo = function_exists('logo_pdf_path') ? logo_pdf_path() : '';
    if ($logo !== '' && is_file($logo)) {
        $p->imagePngCentered($logo, 90, 40);
        $p->gap(6);
    }
    foreach ($blocks as $b) {
        switch ($b['t']) {
            case 'h1':
                $p->line((string)$b['text'], 14, true, 0, true);
                $p->gap(3);
                break;
            case 'h2':
                $p->gap(6);
                $p->line((string)$b['text'], 10.5, true);
                $p->hr();
                $p->gap(1);
                break;
            case 'h3':
                $p->gap(3);
                $p->line((string)$b['text'], 9.5, true);
                break;
            case 'p':
                $p->paragraph((string)$b['text'], 8.6);
                break;
            case 'gap':
                $p->gap(4);
                break;
            case 'hr':
                $p->gap(4);
                $p->hr();
                break;
            case 'kv':
                foreach ($b['items'] as $it) $p->kvWrap((string)$it[0], (string)$it[1], 8.4, 0.42);
                break;
            case 'table':
                app_summary_table($p, $b['head'], $b['rows']);
                break;
        }
    }
}

/**
 * Tabel sederhana 2–4 kolom dengan pembungkusan teks.
 *
 * MiniPdf tidak punya tabel bawaan (dipakai untuk struk 80 mm), jadi di sini
 * kolom dihitung sendiri: teks dibungkus per kolom, tinggi baris = jumlah baris
 * terbanyak, lalu tiap sel digambar pada koordinat absolut agar TIDAK ada teks
 * yang terpotong atau saling menumpuk (bug yang pernah terjadi di dokumen lain).
 */
function app_summary_table(MiniPdf $p, array $head, array $rows): void
{
    $n = count($head);
    $total = $p->W - (2 * $p->margin);
    if ($n <= 2)       $w = [0.34, 0.66];
    elseif ($n === 3)  $w = [0.24, 0.22, 0.54];
    elseif ($n === 4)  $w = [0.20, 0.13, 0.15, 0.52];
    else               $w = array_fill(0, max(1, $n), 1 / max(1, $n));
    $cols = array_slice(array_map(fn($f) => $total * $f, $w), 0, $n);
    $left = [];
    $acc = 0.0;
    foreach ($cols as $c) { $left[] = $p->margin + $acc; $acc += $c; }

    $drawRow = function (array $cells, bool $bold) use ($p, $cols, $left): void {
        $lines = [];
        $maxLines = 1;
        foreach ($cells as $i => $c) {
            $lines[$i] = $p->wrap((string)$c, max(20.0, ($cols[$i] ?? 60) - 6), 8.0, $bold);
            $maxLines = max($maxLines, count($lines[$i]));
        }
        $rowH = ($maxLines * 10.2) + 3;
        $p->ensure($rowH + 2);
        $top = $p->y;
        foreach ($cells as $i => $c) {
            foreach ($lines[$i] as $k => $teks) {
                $p->textAt($left[$i] + 1, $top - 7 - ($k * 10.2), $teks, 8.0, $bold);
            }
        }
        $p->y = $top - $rowH;
        /* Garis pemisah baris (dipakai sebagai pembatas antar baris tabel). */
        $p->lineAt($p->margin, $p->y + 1, $p->margin + array_sum($cols), 0.82);
    };
    $drawRow($head, true);
    foreach ($rows as $r) $drawRow($r, false);
    $p->gap(4);
}
