<?php
/**
 * PANDUAN UNTUK AI (ronde 47) — "playbook" aplikasi ini.
 * =====================================================
 *
 * Permintaan pemilik: "kasih panduan lengkap ke AI tentang struktur kode web saya,
 * cara menyelesaikan perintah seperti yang kamu lakukan, supaya AI Developer paham".
 *
 * Berkas ini menyimpan:
 *   1. `ai_app_index()`  — PETA BERKAS yang dibuat OTOMATIS dari aplikasi yang
 *      berjalan (nama berkas + jumlah baris + ringkasan dari komentar kepala
 *      berkasnya). Karena dibaca dari berkas nyata, peta ini selalu terkini —
 *      termasuk berkas baru yang baru saja ditambahkan.
 *   2. `ai_playbook()`   — ATURAN & CARA KERJA: tumpukan teknologi, konvensi wajib,
 *      helper penting, RESEP pengerjaan tugas yang sering diminta, jebakan yang
 *      sudah terbukti, dan bentuk jawaban yang benar.
 *
 * Dipakai `ai_system_prompt()` (aturan) dan `ai_pick_prompt()` (peta berkas),
 * sehingga kedua tahap AI (memilih berkas & menyusun patch) memakai panduan yang
 * sama. Panduan ini ikut dalam cakupan AI, jadi AI dapat memperbaruinya sendiri
 * bila diminta.
 */
declare(strict_types=1);

/**
 * Keterangan manual untuk beberapa berkas yang tidak punya komentar kepala dan
 * tidak memakai `page_head()` (sehingga tidak dapat ditebak otomatis).
 * Sengaja pendek: hanya berkas yang benar-benar tidak bisa dideskripsikan sendiri.
 */
function ai_app_known_files(): array
{
    return [
        'index.php'  => 'Titik masuk aplikasi — mengarahkan pengguna ke dashboard atau halaman masuk.',
        'login.php'  => 'Halaman masuk (email + kata sandi), termasuk "ingat saya" dan tautan lupa kata sandi.',
        'logout.php' => 'Keluar dari sesi dan menghapus token "ingat saya".',
        'api.php'    => 'Titik JSON untuk saran otomatis & data pendukung (pasien, treatment, ICD, paket, bahan).',
        'photo.php'  => 'Menyajikan foto orang (pasien/dokter/terapis) — wajib login & dibatasi cabang.',
        'media.php'  => 'Menyajikan lampiran rekam medis — wajib login & dibatasi cabang.',
        'logo.php'   => 'Menyajikan logo klinik (dipakai juga di halaman masuk).',
        'qris.php'   => 'Menyajikan GAMBAR QRIS klinik untuk langkah pembayaran (tanpa login).',
        'export.php' => 'Ekspor data ke CSV/Excel/PDF/dokumen cetak untuk seluruh menu.',
    ];
}

/**
 * PETA APLIKASI: daftar berkas penting + ringkasan singkat dari komentar kepalanya.
 *
 * @param bool $ringkas true = hanya nama + ringkasan (untuk prompt),
 *                      false = termasuk jumlah baris & daftar halaman.
 * @return array<int,array{file:string,lines:int,desc:string}>
 */
function ai_app_index(bool $ringkas = true): array
{
    $root = ai_root();
    $out = [];
    /* Berkas yang paling penting bagi AI: halaman & modul aplikasi. */
    $targets = [
        'naveena/*.php',
        'naveena/includes/*.php',
        'naveena/assets/js/*.js',
        'naveena/assets/css/*.css',
    ];
    foreach ($targets as $pola) {
        foreach (glob($root . '/' . $pola) ?: [] as $abs) {
            $rel = substr($abs, strlen($root) + 1);
            $rel = str_replace('\\', '/', $rel);
            /* Berkas internal AI tidak perlu dijelaskan panjang; tetap didaftar. */
            $ukuran = (int)@filesize($abs);
            if ($ukuran > 2 * 1048576) continue;                   // lewati berkas raksasa
            /* 45.000 huruf: cukup memuat komentar kepala DAN baris `page_head('Judul')` /
               `require_perm('izin')` yang dipakai sebagai keterangan cadangan (pada
               beberapa halaman, `page_head` baru muncul setelah ±40 KB kode). */
            $kepala = (string)@file_get_contents($abs, false, null, 0, 45000);
            $out[] = [
                'file' => $rel,
                'lines' => max(1, (int)@count(@file($abs) ?: [])),
                'desc' => ai_app_file_summary($kepala, $rel),
            ];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['file'], $b['file']));
    return $out;
}

/**
 * Ringkas keterangan sebuah berkas dari komentar kepalanya.
 * Mengambil kalimat pertama blok komentar /** … *​/ (atau baris komentar pertama),
 * lalu memendekkannya agar peta tetap ringkas.
 */
function ai_app_file_summary(string $kepala, string $rel = ''): string
{
    $kepala = str_replace(["\r\n", "\r"], "\n", $kepala);
    $teks = '';
    /* Komentar kepala HANYA dipakai bila memang berada di AWAL berkas (maks 400 huruf
       pertama). Tanpa batas ini, blok komentar KODE di tengah berkas (mis. catatan
       pada sebuah fungsi) ikut terbaca dan keterangannya jadi salah — pernah terjadi:
       order_baru.php terbaca "Pilihan ketiga: pemegang kartu tanpa memakai diskon." */
    $awal = trim((string)preg_replace('~^<\?php~', '', $kepala));
    /* Pola berjangkar ^ : blok komentar harus MULAI di awal berkas. Isinya boleh
       panjang (banyak berkas punya penjelasan puluhan baris). */
    if (preg_match('~^/\*\*(.*?)\*/~s', $awal, $m)) {
        $teks = $m[1];
    } elseif (preg_match('~^#\s*!.*?\n(.*)$~s', $awal, $m)) {
        $teks = $m[1];
    }
    $baris = [];
    foreach (preg_split('/\n/', (string)$teks) ?: [] as $l) {
        $l = trim($l);
        $l = trim((string)preg_replace('~^\*\s?~', '', $l));
        if ($l === '' || strpos($l, '@') === 0) continue;
        /* Buang baris hias (hanya tanda =, -, * atau _) yang sering ada pada judul
           komentar sehingga keterangannya tidak berisi deretan tanda. */
        /* Pembatas pola memakai `/` — karakter `~` di dalam kelas [] akan dianggap
           penutup pola oleh PHP dan membuat regex gagal (sudah terjadi). */
        if (preg_match('/^[=\-*_.\s]+$/', $l)) continue;
        /* Lewati kalimat pemanis yang tidak informatif. */
        if (preg_match('~^(dipakai oleh|catatan|jejak|daftar)\b~i', $l)) break;
        $baris[] = $l;
        if (count($baris) >= 2) break;
    }
    $desc = trim(implode(' ', $baris));
    $desc = (string)preg_replace('/\s+/', ' ', $desc);
    if ($desc === '') {
        /* CADANGAN: banyak halaman tidak berkomentar kepala. Yang paling berguna
           adalah JUDUL halaman (`page_head('…')`) dan IZIN yang diminta
           (`require_perm('…')`) — keduanya menerangkan berkas itu dengan baik. */
        $judul = '';
        if (preg_match("~page_head\(\s*'([^']{2,70})'~", $kepala, $mj)) {
            /* Judul sering digabung nama cabang ("Keuangan — " . $branch), sehingga
               hasilnya berakhiran " — " → rapikan agar keterangannya bersih. */
            $judul = trim((string)preg_replace('~\s*—\s*$~u', '', trim($mj[1])));
        }
        $izin = '';
        if (preg_match("~require_perm\(\s*'([a-z_.]+)'~i", $kepala, $mi)) $izin = $mi[1];
        if ($judul !== '') {
            $desc = 'Halaman "' . $judul . '"';
            if ($izin !== '') $desc .= ' (izin ' . $izin . ')';
            $desc .= '.';
        } elseif (isset(ai_app_known_files()[str_replace('naveena/', '', $rel)])) {
            $desc = ai_app_known_files()[str_replace('naveena/', '', $rel)];
        } else {
            $nama = basename($rel);
            $ext = strtolower((string)pathinfo($nama, PATHINFO_EXTENSION));
            if ($ext === 'css') {
                $desc = 'Gaya tampilan aplikasi (CSS utama) — ' . $rel . '.';
            } elseif ($ext === 'js') {
                $desc = 'Skrip sisi peramban aplikasi (interaksi, saran otomatis, grafik, geser tabel).';
            } else {
                $desc = (strpos($rel, 'includes/') !== false ? 'Modul ' : 'Halaman ')
                    . pathinfo($nama, PATHINFO_FILENAME)
                    . ($izin !== '' ? ' (izin ' . $izin . ')' : '') . '.';
            }
        }
    }
    if (ai_strlen($desc) > 150) $desc = mb_substr_ai_fallback($desc, 0, 147) . '…';
    return $desc;
}

/** Potong teks tanpa mbstring (aplikasi ini tidak memakai mb_*). */
function mb_substr_ai_fallback(string $s, int $mulai, int $panjang): string
{
    if (function_exists('mb_substr')) return (string)mb_substr($s, $mulai, $panjang, 'UTF-8');
    return (string)substr($s, $mulai, $panjang);
}

/**
 * PETA BERKAS dalam bentuk TEKS untuk prompt pemilihan berkas.
 * Ditulis ringkas: satu baris per berkas → "path — keterangan".
 */
function ai_app_index_text(int $maksBaris = 200): string
{
    $idx = ai_app_index();
    $baris = [];
    $n = 0;
    foreach ($idx as $f) {
        if (++$n > $maksBaris) { $baris[] = '… (masih ada ' . (count($idx) - $maksBaris) . ' berkas lain)'; break; }
        $baris[] = $f['file'] . ' — ' . $f['desc'];
    }
    return implode("\n", $baris);
}

/**
 * PANDUAN UTAMA untuk AI: aturan wajib, peta aplikasi, resep tugas, dan jebakan.
 *
 * Ditulis dalam bahasa Indonesia supaya gaya kode & komentar yang dihasilkan AI
 * konsisten dengan aplikasi (semua komentar aplikasi berbahasa Indonesia).
 */
function ai_playbook(): string
{
    $p = [];
    $p[] = "=== PANDUAN APLIKASI (WAJIB DIBACA SEBELUM MENJAWAB) ===";
    $p[] = "Aplikasi: sistem manajemen klinik kecantikan multi-cabang \"Naveena\".";
    $p[] = "Tumpukan: PHP 8 (tanpa framework), SQLite, JavaScript & CSS biasa. TIDAK ADA build step,";
    $p[] = "tidak ada composer/npm. Semua berkas ada di folder `naveena/` (halaman) dan";
    $p[] = "`naveena/includes/` (modul bersama). Halaman diakses lewat URL relatif.";
    $p[] = "";
    $p[] = "--- ATURAN WAJIB (melanggar = aplikasi rusak) ---";
    $p[] = "1. PATH ASET & TAUTAN HARUS RELATIF (`style.css`, `assets/js/app.js`, `pasien.php`).";
    $p[] = "   JANGAN memakai garis miring di depan (`/style.css`) dan JANGAN menyusun URL absolut";
    $p[] = "   dengan `app_public_base()`. Aplikasi disajikan di dalam sub-folder, sehingga URL";
    $p[] = "   absolut/berawalan \"/\" akan menunjuk ke halaman lain dan tampilan jadi rusak.";
    $p[] = "2. JANGAN menambah dependensi baru (tanpa composer/npm/CDN). Pustaka yang ada sudah";
    $p[] = "   di-vendor: `assets/vendor/chart.umd.min.js`.";
    $p[] = "3. Semua query WAJIB dibatasi cabang memakai `branch_sql()` / `bscope()` / `scope_branch()`";
    $p[] = "   (kecuali super admin/direktur memang melihat semua cabang). Jangan menulis query";
    $p[] = "   lintas cabang baru tanpa pembatasan ini.";
    $p[] = "4. Setiap form POST wajib memuat `<?= csrf_field() ?>`; server memanggil `verify_csrf()`.";
    $p[] = "   Token sekali-pakai `_once` sudah otomatis ikut di `csrf_field()` (anti kirim ganda).";
    $p[] = "5. Hak akses: `has_perm('kode.izin')` untuk memeriksa, `require_perm('kode.izin')` di awal";
    $p[] = "   halaman, `is_super()` (hanya Super Admin), `is_owner_level()` (Super Admin + Direktur,";
    $p[] = "   cakupan semua cabang), `deny('pesan')` untuk menolak.";
    $p[] = "6. Transaksi/penjualan HANYA boleh dibuat lewat `order_create(\$in, \$user, \$branchId)`";
    $p[] = "   (includes/order_create.php) — jangan menulis INSERT orders/order_items baru, karena";
    $p[] = "   fungsi itu yang mengurus harga, stok, diskon member, nomor invoice, dan audit.";
    $p[] = "7. Stok berubah HANYA lewat `inv_apply(\$kind, \$id, \$qty, \$reason, ...)`.";
    $p[] = "8. Tampilan mengikuti komponen yang sudah ada: `.card` + `.card-head` + `.card-body`,";
    $p[] = "   `.table-wrap` untuk tabel (agar bisa digeser di HP), `badge()`, `empty_state()`.";
    $p[] = "   Judul halaman: `page_head('Judul','kunci_menu')` … `page_foot()` (WAJIB sepasang).";
    $p[] = "   Halaman baru yang butuh sidebar juga memuat `includes/layout.php`.";
    $p[] = "9. Jangan pernah menulis kunci API/sandi/token ke kode atau ke keluaran.";
    $p[] = "10. Bila angka laporan dibutuhkan, pakai sumber bersama `report_bundle()` (includes/reports.php)";
    $p[] = "    dan `finance_summary()` (includes/finance.php) — jangan menghitung ulang dengan cara lain.";
    $p[] = "";
    $p[] = "--- HELPER PENTING (sudah tersedia — pakai ini, jangan bikin sendiri) ---";
    $p[] = "Database : q(sql, params) · one() · all() · scalar() · db()";
    $p[] = "Setelan  : setting('key', 'bawaan') · set_setting('key', value) (cache otomatis)";
    $p[] = "Teks/UI  : e() (escape HTML — WAJIB untuk semua keluaran) · money() · num() · tgl()";
    $p[] = "           badge(\$teks,\$warna) · icon('nama') · empty_state('pesan') · short_text()";
    $p[] = "Angka    : qty_text() / qty_unit() untuk jumlah/stok (boleh pecahan) · qty_parse() baca input";
    $p[] = "Sesi/auth: current_user() · user_branch() · has_perm() · is_super() · is_owner_level()";
    $p[] = "Cabang   : branch_sql(\$kolom) · bscope() · scope_branch() · branches() · resolve_branch_input()";
    $p[] = "Jejak    : audit(\$aksi, \$modul, \$refId, \$sebelum, \$sesudah, \$alasan) · flash(\$pesan, 'success')";
    $p[] = "Nomor    : next_patient_number() · next_member_number() · next_invoice() · next_medical_number()";
    $p[] = "Gambar   : img_process_upload() menyimpan + mengecilkan · local_upload_dir() folder unggahan";
    $p[] = "Tabel    : pagination() · per_page_inline() (di dalam form filter) · per_page_select() (di luar)";
    $p[] = "";
    $p[] = "--- CARA MENJAWAB PERINTAH PEMILIK (urutan yang benar) ---";
    $p[] = "a. PAHAMI maksudnya dari sudut pandang pemakai (kasir/pasien/pemilik), bukan dari istilah teknis.";
    $p[] = "b. CARI berkas yang tepat. Sebutan menu di layar hampir selalu sama dengan nama berkas:";
    $p[] = "   \"Order Baru\"→order_baru.php, \"Data Pasien\"→pasien.php, \"Rekam Medis\"→rekam_medis.php,";
    $p[] = "   \"Laporan\"→laporan.php, \"Keuangan\"→keuangan.php, \"Pengaturan\"→settings.php,";
    $p[] = "   \"Developer Settings\"→developer.php, \"Detail Pasien\"→pasien_detail.php,";
    $p[] = "   \"Riwayat Order\"→order.php, \"Struk\"→struk.php, \"AI Developer\"→ai_developer.php.";
    $p[] = "   Modul bersama ada di includes/ (mis. pembayaran→includes/payment.php).";
    $p[] = "c. BACA bagian yang akan diubah. `search` HARUS disalin PERSIS dari isi berkas yang dikirim";
    $p[] = "   (termasuk spasi/indentasi), dan potongannya harus UNIK (muncul satu kali).";
    $p[] = "d. UBAH SESEDIKIT MUNGKIN. Jangan merapikan kode lain, jangan mengganti gaya, jangan";
    $p[] = "   menyentuh bagian yang tidak diminta. Satu op = satu perubahan kecil.";
    $p[] = "e. BILA informasi kurang (berkas yang dibutuhkan belum dikirim), JANGAN MENEBAK:";
    $p[] = "   balas {\"read\":[\"path/berkas.php\"],\"plan\":\"perlu membaca berkas X\"} dan sistem akan";
    $p[] = "   mengirimkan berkas itu pada putaran berikutnya.";
    $p[] = "f. BILA memang tidak ada yang perlu diubah, balas {\"plan\":\"…\",\"ops\":[]} dengan penjelasan.";
    $p[] = "   Ini BUKAN kegagalan dan akan disampaikan apa adanya ke pemilik.";
    $p[] = "";
    $p[] = "--- RESEP TUGAS YANG SERING DIMINTA ---";
    $p[] = "• MENGUBAH URUTAN kartu/bagian: dua op per blok → (1) hapus blok dari posisi lama";
    $p[] = "  (\"search\" = seluruh blok persis, \"replace\" = \"\"), lalu (2) sisipkan blok yang SAMA pada";
    $p[] = "  posisi baru (\"search\" = penanda di sekitar posisi baru, \"replace\" = penanda + blok itu).";
    $p[] = "  Isi blok disalin apa adanya — jangan ditulis ulang — dan jangan pernah menghapus tanpa";
    $p[] = "  menyisipkan kembali.";
    $p[] = "• MENAMBAH KOLOM pada tabel daftar: sentuh dua tempat — `<th>` pada `<thead>` dan `<td>`";
    $p[] = "  pada baris `<tbody>`. Bila ada baris \"tidak ada data\", sesuaikan juga `colspan`-nya.";
    $p[] = "• MENAMBAH FILTER: (1) input di dalam form filter (`name=...`), (2) baca dengan `gp('...')`,";
    $p[] = "  (3) tambahkan syarat ke query (perhatikan urutan parameter), (4) pertahankan nilainya saat";
    $p[] = "  halaman dimuat ulang. JANGAN membuat `<form>` di dalam `<form>`.";
    $p[] = "• MENAMBAH SETELAN baru: tambahkan kunci bawaan di `seed_settings()` (includes/schema.php),";
    $p[] = "  baca dengan `setting('kunci','bawaan')`, dan simpan dengan `set_setting('kunci', \$v)`.";
    $p[] = "• MENAMBAH KOLOM BASIS DATA: tulis di `schema_ddl()` (instalasi baru) DAN di daftar `\$adds`";
    $p[] = "  `run_migrations()` (database lama). JANGAN menaruh `CREATE INDEX` kolom baru di";
    $p[] = "  `schema_ddl()` — indeks dijalankan sebelum migrasi, sehingga instalasi lama gagal.";
    $p[] = "• MENAMPILKAN GAMBAR UNGGAHAN: pakai berkas penyaji yang sudah ada (mis. `qris.php`, `logo.php`,";
    $p[] = "  `photo.php`, `media.php`) via URL RELATIF dengan penanda versi `?v=<md5 6 huruf>` supaya";
    $p[] = "  peramban tidak memakai gambar lama.";
    $p[] = "• MENAMBAH MENU SIDEBAR: tambahkan pada `nav_items()` (includes/layout.php) dengan";
    $p[] = "  pemeriksaan `has_perm(...)`, dan beri izin baru bila perlu.";
    $p[] = "";
    $p[] = "--- JEBAKAN YANG SUDAH TERBUKTI (jangan diulangi) ---";
    $p[] = "* Jangan memakai `mb_*` (tidak selalu tersedia) → gunakan helper `short_text()` / fungsi teks";
    $p[] = "  yang sudah ada. Jangan memakai `ctype_digit()` (tidak tersedia) → pakai `preg_match`.";
    $p[] = "* SQLite: tanggal & waktu memakai `datetime('now','localtime')` (zona waktu sudah diatur WIB).";
    $p[] = "* Jangan memakai `num()` untuk jumlah/stok (membulatkan) → pakai `qty_text()`.";
    $p[] = "* Modal wajib memuat `data-modal-close` pada tombol tutup; modal TIDAK menutup saat area";
    $p[] = "  gelap diklik (disengaja, agar isian tidak hilang).";
    $p[] = "* Tombol berisiko memakai `data-confirm=\"…\"` (konfirmasi 2 tahap: `data-heavy-confirm`).";
    $p[] = "* Grafik memakai Chart.js yang sudah dimuat; dokumen cetak (includes/report_document.php)";
    $p[] = "  TIDAK memuat app.js sehingga tidak boleh memanggil `Naveena.*`.";
    $p[] = "* PDF memakai penulis sendiri (`includes/pdf.php` + `includes/png.php`) — hanya mendukung";
    $p[] = "  font Helvetica dan gambar PNG (bukan JPEG).";
    $p[] = "";
    $p[] = "--- CARA MEMERIKSA PEKERJAAN ---";
    $p[] = "Pemilik dapat menjalankan suite uji otomatis di SALINAN aplikasi sebelum menerapkan.";
    $p[] = "Pilih suite yang paling dekat dengan perubahan: `sintaks-js` (tampilan/perubahan kecil),";
    $p[] = "`pembayaran-transaksi` (pembayaran/QRIS/struk), `keuangan` (laba/HPP/biaya),";
    $p[] = "`import-data` (impor Excel/CSV), `kartu-member` (diskon/kartu member),";
    $p[] = "`ui-rekam-medis` (rekam medis), `responsif-hp-tablet` (tata letak HP/PC),";
    $p[] = "`smoke-fungsional` (menyentuh banyak menu).";
    $p[] = "";
    $p[] = "--- BENTUK JAWABAN (patuhi persis) ---";
    $p[] = "Satu objek JSON saja:";
    $p[] = "{\"plan\":\"ringkasan rencana\",\"files\":[\"path/relatif.php\"],\"preview\":\"halaman.php\",";
    $p[] = " \"ops\":[{\"file\":\"path/relatif.php\",\"action\":\"replace\",\"search\":\"teks lama persis\",\"replace\":\"teks baru\"}],";
    $p[] = " \"suggestions\":[\"saran singkat untuk pemilik\"]}";
    $p[] = "Berkas baru: {\"file\":\"path/baru.php\",\"action\":\"create\",\"content\":\"isi lengkap\"}.";
    $p[] = "Bila perlu membaca berkas lain dulu: {\"plan\":\"…\",\"read\":[\"path/berkas.php\"],\"ops\":[]}.";
    return implode("\n", $p);
}
