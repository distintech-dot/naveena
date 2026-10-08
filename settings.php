<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/receipt.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/monthly_report.php';
require_once __DIR__ . '/includes/png.php';
require_once __DIR__ . '/includes/layout.php';
require_perm('settings.manage');
$user = current_user();

/* ---------------------------------------------------------------- *
 * PENGAMAN PENGATURAN TINGKAT SISTEM (permintaan pemilik klinik)
 *
 * Dua kelompok pengaturan bersifat sistem dan HANYA boleh diubah Super Admin,
 * supaya nilai yang sudah diisi tidak terubah tanpa sengaja oleh level lain
 * (Direktur/Owner) yang juga memegang izin `settings.manage`:
 *   1. Jalur pengiriman email (mode, penyedia API + kunci, SMTP) — kartu Email;
 *   2. Payment gateway / pembayaran otomatis (penyedia, lingkungan, kunci API).
 *
 * Penegakannya di SERVER (bukan hanya `disabled` di tampilan): handler `email`
 * dan `payment` mengabaikan kolom-kolom tersebut bila bukan Super Admin, dan
 * melaporkannya apa adanya. Pengaturan lain (rekening/QRIS, kode unik, tujuan &
 * jadwal email, pengirim, dan Email Struk ke Pasien) tetap dapat diubah.
 * ---------------------------------------------------------------- */
$lockSystemSettings = !is_super();
$lockAttr = $lockSystemSettings ? ' disabled' : '';
/** Badge penjelasan di bagian yang dikunci (ditampilkan sekali per bagian). */
function lock_note(bool $locked): string
{
    if (!$locked) return '';
    return '<div class="alert alert-warning mt-2" style="margin-bottom:0">'
        . '<strong>Bagian ini hanya dapat diubah oleh Super Admin.</strong> Nilainya ditampilkan apa adanya '
        . 'agar tidak terubah tanpa sengaja (mis. kunci API yang sudah diisi). Minta Super Admin bila perlu '
        . 'mengubahnya.</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'theme') {
            $key = (string)($_POST['theme'] ?? '');
            $all = theme_list();
            if (!isset($all[$key])) throw new RuntimeException('Pilihan tema tidak dikenal.');
            $before = theme_key();
            set_setting('theme', $key);
            audit('Ubah Tema Warna', 'Pengaturan', null, ['tema' => $before], ['tema' => $key],
                'Tema warna diubah menjadi ' . $all[$key]['name']);
            flash('Tema warna diubah ke "' . $all[$key]['name'] . '". Seluruh halaman langsung memakai tema ini.');
        }

        if ($act === 'logo') {
            if (empty($_FILES['logo']['name']) || ($_FILES['logo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Pilih berkas logo terlebih dahulu.');
            }
            $name = (string)$_FILES['logo']['name'];
            $tmp  = (string)$_FILES['logo']['tmp_name'];
            $size = (int)$_FILES['logo']['size'];
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                throw new RuntimeException('Format logo harus PNG, JPG, WEBP, GIF, atau SVG.');
            }
            if ($size > 10 * 1024 * 1024) throw new RuntimeException('Ukuran logo maksimal 10 MB.');
            $oldFile = (string)setting('logo_file');
            $oldUrl  = (string)setting('logo_url');

            /* Logo dikompres otomatis (perkecil + optimasi) sehingga pengguna boleh
               mengunggah PNG besar (mis. 4500×4500) tanpa masalah kecepatan.
               Disimpan di folder aplikasi (di luar area publik) sebagai salinan
               kerja: dipakai menampilkan logo (logo.php) dan ditempelkan ke PDF —
               PDF tidak bisa mengambil berkas dari URL. Bila penyimpanan media
               tersedia, salinan itu juga diunggah sebagai cadangan (logo_url). */
            $made = img_process_upload($tmp, $name, local_upload_dir(), 'logo', 'logo');
            $dest = $made['file'];
            $jpegToPng = true;   // keterangan saja: berkas bisa .png atau .jpg
            $url = '';
            if (media_token() !== '') {
                try {
                    $realExt = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
                    $url = media_upload(local_upload_dir() . '/' . $dest,
                        'logo-' . preg_replace('/[^a-z0-9]+/i', '-', pathinfo($name, PATHINFO_FILENAME)) . '.' . $realExt);
                } catch (Throwable $ex) {
                    $url = '';   // cadangan gagal bukan alasan membatalkan; salinan lokal tetap dipakai
                }
            }
            set_setting('logo_file', $dest);
            set_setting('logo_url', $url);
            // hapus logo lokal lama (bila ada) agar tidak menumpuk
            if ($oldFile !== '' && $oldFile !== setting('logo_file')) {
                $p = local_upload_dir() . '/' . basename($oldFile);
                if (is_file($p)) @unlink($p);
            }
            /* Bangun cache versi kecil sekali di sini supaya pembuatan struk/
               dokumen PDF berikutnya tidak perlu mendekode gambar besar
               (bisa puluhan detik). Kesalahan di sini tidak membatalkan upload. */
            $warm = 0;
            foreach ([175, 192, 240] as $th) {
                try { if (png_scaled_cached(local_upload_dir() . '/' . setting('logo_file'), $th) !== null) $warm++; }
                catch (Throwable $ex) { /* abaikan */ }
            }
            settings(true);
            audit('Ubah Logo Klinik', 'Pengaturan', null, ['logo' => $oldFile ?: $oldUrl],
                ['logo' => setting('logo_file') ?: setting('logo_url'), 'cache' => $warm], 'Logo klinik diperbarui');
            flash('Logo klinik berhasil diperbarui — ' . img_result_text($made)
                . '. Logo langsung dipakai di seluruh halaman, struk, dan dokumen PDF.');
        }
        if ($act === 'logo_warm') {
            $path = logo_local_path();
            if ($path === '') throw new RuntimeException('Belum ada logo yang tersimpan.');
            @set_time_limit(600);
            $done = 0;
            foreach ([175, 192, 240] as $th) {
                if (png_cache_exists($path, $th)) { $done++; continue; }
                if (png_scaled_cached($path, $th) !== null) $done++;
            }
            audit('Siapkan Versi Kecil Logo', 'Pengaturan', null, null, ['berhasil' => $done],
                'Cache versi kecil logo disiapkan untuk mempercepat struk & dokumen');
            flash($done > 0
                ? 'Versi kecil logo siap (' . $done . ' ukuran). Logo kini ikut tercetak di struk dan dokumen PDF dengan cepat.'
                : 'Gagal menyiapkan versi kecil logo. Coba unggah ulang logo dengan ukuran lebih kecil (maksimal 2000 px).',
                $done > 0 ? 'success' : 'warning');
        }
        if ($act === 'logo_remove') {
            $oldFile = (string)setting('logo_file');
            if ($oldFile !== '') {
                $p = local_upload_dir() . '/' . basename($oldFile);
                if (is_file($p)) @unlink($p);
            }
            set_setting('logo_file', '');
            set_setting('logo_url', '');
            settings(true);
            audit('Hapus Logo Klinik', 'Pengaturan', null, ['logo' => $oldFile], ['logo' => 'bawaan (SVG)'], 'Logo dikembalikan ke bawaan');
            flash('Logo dihapus. Halaman memakai logo bawaan sistem.');
        }

        if ($act === 'company') {
            /* Nama klinik SENGAJA tidak ikut di sini: penggantian nama klinik
               hanya boleh dilakukan Super Admin lewat aksi `clinic_name`
               (kartu "Nama Klinik"). Tanpa pemisahan ini, pemegang hak
               `settings.manage` (termasuk Direktur) dapat mengubah nama klinik
               lewat form Identitas Klinik — padahal aturannya hanya Super Admin. */
            foreach (['company_tagline', 'company_address', 'company_phone', 'company_email', 'receipt_footer'] as $k) {
                set_setting($k, (string)($_POST[$k] ?? ''));
            }
            audit('Ubah Pengaturan Klinik', 'Pengaturan', null, null, ['company_name' => setting('company_name')], 'Perubahan identitas klinik');
            flash('Identitas klinik disimpan. Nama klinik tetap: ' . setting('company_name') . '.');
        }
        if ($act === 'ui_scale') {
            /* Ukuran tampilan = preferensi tampilan semua pengguna. */
            if (!has_perm('settings.manage')) deny('Pengaturan ukuran tampilan hanya dapat diubah pemegang izin Pengaturan Sistem.');
            $v = (int)($_POST['ui_scale'] ?? 80);
            if (!array_key_exists($v, ui_scale_options())) $v = 80;
            $was = (int)round(ui_scale() * 100);
            set_setting('ui_scale', (string)$v);
            audit('Ubah Ukuran Tampilan', 'Pengaturan', null, ['skala' => $was], ['skala' => $v],
                'Ukuran tampilan diubah menjadi ' . $v . '%');
            flash('Ukuran tampilan disimpan: ' . $v . '%.');
        }
        if ($act === 'wallpaper') {
            /* GAMBAR LATAR WEB (permintaan pemilik): tautan gambar DARING (tanpa unggahan,
               hemat ruang), dipilih acak setiap halaman dimuat, dengan pilihan tempat
               pemakaian & tema gambar. */
            if (!has_perm('settings.manage')) {
                deny('Pengaturan gambar latar web hanya dapat diubah pemegang izin Pengaturan Sistem.');
            }
            $mode = (string)($_POST['wallpaper_mode'] ?? 'off');
            if (!isset(wallpaper_modes()[$mode])) $mode = 'off';
            $kat = (string)($_POST['wallpaper_category'] ?? 'campuran');
            if (!isset(wallpaper_categories()[$kat])) $kat = 'campuran';
            $urls = trim((string)($_POST['wallpaper_urls'] ?? ''));
            $was = wallpaper_mode();
            set_setting('wallpaper_mode', $mode);
            set_setting('wallpaper_category', $kat);
            set_setting('wallpaper_urls', $urls);
            $kustom = wallpaper_custom_urls();
            $terpakai = $kustom ? count($kustom) : count(wallpaper_default_urls($kat));
            audit('Ubah Gambar Latar Web', 'Pengaturan', null, ['mode' => $was],
                ['mode' => $mode, 'tema' => $kat, 'tautan' => count($kustom)],
                'Gambar latar web (tautan daring, acak tiap halaman dimuat)');
            $pesan = 'Pengaturan gambar latar disimpan: ' . wallpaper_modes()[$mode]
                . ' · tema ' . wallpaper_categories()[$kat] . ' · ' . num($terpakai) . ' gambar dipakai'
                . ($kustom ? ' (dari tautan Anda)' : ' (gambar bawaan)') . '.';
            if ($urls !== '' && !$kustom) {
                $pesan .= ' Catatan: tautan yang Anda isi tidak ada yang sah — hanya alamat http/https, '
                    . 'satu tautan per baris, yang diterima.';
                flash($pesan, 'warning');
            } else {
                flash($pesan);
            }
        }
        if ($act === 'member') {
            if (!has_perm('settings.manage')) {
                deny('Pengaturan Kartu Member hanya dapat diubah pemegang izin Pengaturan Sistem.');
            }
            $was = member_card_enabled();
            $on = ($_POST['member_card_active'] ?? '') === '1';
            set_setting('member_card_active', $on ? '1' : '0');
            set_setting('member_activate_amount', (string)max(0, qty_parse($_POST['member_activate_amount'] ?? 0)));
            set_setting('member_min_transaction', (string)max(0, qty_parse($_POST['member_min_transaction'] ?? 0)));
            $scope = (string)($_POST['member_discount_scope'] ?? 'both');
            set_setting('member_discount_scope', in_array($scope, ['both', 'treatment', 'skincare'], true) ? $scope : 'both');
            set_setting('member_card_price', (string)max(0, qty_parse($_POST['member_card_price'] ?? 0)));
            set_setting('member_card_note', trim((string)($_POST['member_card_note'] ?? '')));

            /* ---------- PERIODE AKUMULASI + PENURUNAN LEVEL (permintaan pemilik) ----------
               1/3/5 tahun atau 0 = tanpa peresetan; reset bersama pada 1 Januari.
               Saat periode berganti, level setiap member turun `member_downgrade_steps`
               tingkat (0 = level tidak diturunkan). */
            $periodBefore = member_period_years();
            $stepsBefore = member_downgrade_steps();
            $appliedBefore = (int)setting('member_period_applied', '0');
            $periodIn = (int)($_POST['member_period_years'] ?? 1);
            if (!array_key_exists($periodIn, member_period_options())) $periodIn = 1;
            set_setting('member_period_years', (string)$periodIn);
            $stepsIn = (int)($_POST['member_downgrade_steps'] ?? 0);
            set_setting('member_downgrade_steps', (string)max(0, min(3, $stepsIn)));
            if ((string)setting('member_period_anchor_year', '') === '' || $periodIn !== $periodBefore) {
                /* Jangkar periode dipasang ulang saat periodenya diubah supaya
                   batas berikutnya dihitung dari sekarang (tidak reset mendadak). */
                set_setting('member_period_anchor_year', (string)(int)date('Y'));
            }
            $rolloverMsg = '';
            if ($periodIn <= 0) {
                set_setting('member_period_applied', '');      // tanpa periode → tanpa reset
                $rolloverMsg = ' Periode dimatikan: akumulasi member TIDAK akan direset dan level tidak akan turun otomatis.';
            } else {
                $startYear = member_period_start_year();
                if ($appliedBefore <= 0 || $appliedBefore > $startYear) {
                    /* Aktivasi pertama / jangkar baru → tandai periode berjalan
                       TANPA menurunkan level siapa pun. */
                    set_setting('member_period_applied', (string)$startYear);
                    $rolloverMsg = ' Periode berlaku mulai ' . sprintf('%04d-01-01', $startYear)
                        . ' (reset berikutnya ' . member_period_next_reset() . ').';
                } elseif ($periodIn !== $periodBefore || member_downgrade_steps() !== $stepsBefore) {
                    $rolloverMsg = ' Periode berjalan ' . sprintf('%04d-01-01', $startYear)
                        . ' s.d. ' . member_period_end_date() . ', reset berikutnya ' . member_period_next_reset() . '.';
                }
            }

            /* Level member: label, persen diskon, ambang akumulasi per periode. */
            $labs = (array)($_POST['level_label'] ?? []);
            $pcts = (array)($_POST['level_pct'] ?? []);
            $mins = (array)($_POST['level_min'] ?? []);
            $keys = (array)($_POST['level_key'] ?? []);
            $levels = [];
            foreach ($labs as $idx => $label) {
                $pct = qty_parse($pcts[$idx] ?? 0);
                $min = qty_parse($mins[$idx] ?? 0);
                if ($pct <= 0) continue;                       // baris kosong dilewati
                $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($keys[$idx] ?? '')));
                if ($key === '') {
                    /* Turunkan kunci dari label (mis. "Member Gold" → "gold") supaya
                       level tetap dikenali walau formulir tidak mengirim kunci. */
                    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$label)), '_');
                    $slug = preg_replace('/^(member|level|tier)_/', '', $slug);
                    $key = $slug !== '' ? $slug : ('level' . ($idx + 1));
                }
                $levels[] = ['key' => $key, 'label' => trim((string)$label) ?: ('Level ' . ($idx + 1)),
                    'pct' => min(100, $pct), 'min_year' => max(0, $min)];
            }
            usort($levels, fn($a, $b) => $a['min_year'] <=> $b['min_year']);
            if (!$levels) throw new RuntimeException('Minimal satu level member harus diisi (persen diskon > 0).');
            set_setting('member_levels', json_encode($levels, JSON_UNESCAPED_UNICODE));
            /* Petunjuk untuk halaman lain (struk/kartu) disimpan terurai juga. */
            set_setting('member_card_auto_amount', (string)(float)$levels[0]['min_year']);

            /* Jalankan reset periode bila memang sudah lewat (mis. periode diubah
               ke 1 tahun padahal penandanya masih tahun lama), lalu sinkronkan level
               semua member dengan aturan baru (akumulasi periode berjalan). */
            $roll = member_rollover_run();
            $synced = $roll['berubah'];
            /* SENGAJA LINTAS CABANG: perubahan aturan kartu member berlaku untuk SEMUA
               cabang sehingga seluruh pemegang kartu disinkronkan. Penanda `cross-branch`
               dipakai alat audit isolasi (db_audit.php); saat pengalihan koneksi
               central/branch, operasi ini harus disisir per cabang (db_for_branch()). */
            foreach (all('/* cross-branch */ SELECT id FROM patients WHERE member_card = 1') as $row) {
                $r = member_sync_level((int)$row['id']);
                if ($r['changed'] || $r['activated']) $synced++;
            }
            audit('Ubah Pengaturan Kartu Member', 'Pengaturan', null,
                ['aktif' => $was, 'level' => count($levels),
                 'periode' => member_period_label($periodBefore), 'turun_tingkat' => $stepsBefore],
                ['aktif' => $on, 'level' => count($levels), 'aktivasi' => setting('member_activate_amount'),
                 'min_transaksi' => setting('member_min_transaction'), 'cakupan' => member_scope_text(),
                 'periode' => member_period_label(), 'turun_tingkat' => member_downgrade_steps(),
                 'reset_dijalankan' => $roll['ran'] ? 1 : 0],
                'Aturan kartu member & diskon berlevel diperbarui; ' . $synced . ' member disinkronkan'
                . ($roll['ran'] ? '; reset periode dijalankan (' . $roll['berubah'] . ' level turun)' : ''));
            flash('Pengaturan Kartu Member disimpan. ' . member_rules_text() . $rolloverMsg
                . ($roll['ran'] ? ' Reset periode dijalankan sekarang: ' . num($roll['berubah'])
                    . ' dari ' . num($roll['anggota']) . ' member turun level.' : ''),
                $roll['ran'] ? 'warning' : 'success');
        }

        if ($act === 'member_bg' || $act === 'member_bg_remove') {
            if (!has_perm('settings.manage')) {
                deny('Background kartu member hanya dapat diubah pemegang izin Pengaturan Sistem.');
            }
            $old = (string)setting('member_card_bg_file');
            if ($act === 'member_bg_remove') {
                if ($old !== '') {
                    $p = local_upload_dir() . '/' . basename($old);
                    if (is_file($p)) @unlink($p);
                }
                set_setting('member_card_bg_file', '');
                audit('Hapus Background Kartu Member', 'Pengaturan', null, ['file' => $old], null, 'Background kartu dikosongkan');
                flash('Background kartu member dihapus — kartu kembali memakai warna tema.');
            } else {
                if (empty($_FILES['bg']['name']) || ($_FILES['bg']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Pilih berkas gambar background terlebih dahulu.');
                }
                $name = (string)$_FILES['bg']['name'];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                    throw new RuntimeException('Background harus berupa gambar (PNG/JPG/WEBP/GIF).');
                }
                $made = member_card_bg_save((string)$_FILES['bg']['tmp_name'], $name);
                set_setting('member_card_bg_file', $made['file']);
                if ($old !== '' && $old !== setting('member_card_bg_file')) {
                    $p = local_upload_dir() . '/' . basename($old);
                    if (is_file($p)) @unlink($p);
                }
                audit('Ubah Background Kartu Member', 'Pengaturan', null, ['file' => $old],
                    ['file' => setting('member_card_bg_file')], 'Background kartu member diperbarui');
                $cv = member_card_canvas();
                flash('Background kartu member tersimpan — otomatis dipotong ' . $cv['w'] . '×' . $cv['h'] . ' px '
                    . '(rasio kartu ' . $cv['w'] / $cv['h'] * 100 / 100 . ') agar tidak gepeng.');
            }
        }

        if ($act === 'chart_colors') {
            /* WARNA GRAFIK — mode palet + warna seri yang punya makna.
               Palet kategorikal (antar cabang/treatment/metode) dibuat
               otomatis oleh includes/theme.php supaya selalu kontras dan
               tidak pernah mengulang warna; yang dapat diatur di sini adalah
               mode palet dan warna seri Treatment/Skincare/Total. */
            if (!has_perm('settings.manage')) deny('Pengaturan warna grafik hanya dapat diubah pemegang izin Pengaturan Sistem.');
            $mode = (string)($_POST['chart_palette_mode'] ?? 'kontras');
            set_setting('chart_palette_mode', in_array($mode, ['kontras', 'tema'], true) ? $mode : 'kontras');
            $defs = ['treatment' => '#C2185B', 'skincare' => '#2E7D32', 'total' => '#37474F',
                     'positif' => '#2E7D32', 'negatif' => '#C62828'];
            /* Pembaca nilai warna: mendukung dua bentuk nama kolom —
               `chart_color_<k>` (warna grafik) dan `stat_color_<nama>_<bagian>`
               (warna kartu statistik, dipanggil dengan "stat:<nama>:<bagian>"). */
            $hexIn = function (string $k, string $def): string {
                $field = str_starts_with($k, 'stat:')
                    ? 'stat_color_' . implode('_', array_slice(explode(':', $k), 1))
                    : 'chart_color_' . $k;
                $v = strtoupper(trim((string)($_POST[$field] ?? '')));
                if ($v === '' || !preg_match('/^#?[0-9A-F]{6}$/', $v)) return $def;
                return '#' . ltrim($v, '#');
            };
            foreach ($defs as $k => $def) set_setting('chart_color_' . $k, $hexIn($k, $def));
            /* WARNA KARTU STATISTIK (gold/pink/coklat) — diletakkan di bagian yang SAMA
               dengan warna grafik supaya semua pengaturan warna ada di satu tempat
               (permintaan pemilik: "konfigurasikan warna ini ke bagian terkait"). */
            foreach (stat_card_colors() as $nama => $w) {
                foreach (array_keys($w) as $bagian) {
                    set_setting('stat_color_' . $nama . '_' . $bagian,
                        $hexIn('stat:' . $nama . ':' . $bagian, $w[$bagian]));
                }
            }
            audit('Ubah Warna Grafik', 'Pengaturan', null, null, [
                'mode' => setting('chart_palette_mode'),
                'treatment' => setting('chart_color_treatment'),
                'skincare' => setting('chart_color_skincare'),
                'total' => setting('chart_color_total'),
                'kartu_gold' => setting('stat_color_gold_to'),
                'kartu_pink' => setting('stat_color_pink_to'),
                'kartu_coklat' => setting('stat_color_brown_to'),
            ], 'Pengaturan warna grafik & warna kartu statistik diperbarui (layar, Excel, PDF, dan email)');
            flash('Warna grafik & warna kartu statistik disimpan — berlaku di dashboard, laporan, dan menu '
                . 'Keuangan (kartu Treatment = gold, Skincare = pink, Laba Kotor = coklat). '
                . 'Grafik perbandingan antar cabang memakai palet kontras tinggi otomatis sehingga setiap '
                . 'cabang (berapa pun jumlahnya) memakai warna yang berbeda.');
        }
        /* Aksi 'system' (Data per halaman + petunjuk akun bawaan di halaman login)
           SUDAH DIPINDAH ke Developer Settings (ronde 37) supaya hanya Super Admin
           yang dapat mengubahnya. Lihat developer.php → kartu Pengaturan Umum. */
        if ($act === 'email') {
            foreach (['email_receipt_subject', 'email_receipt_body'] as $k) {
                set_setting($k, (string)($_POST[$k] ?? ''));
            }
            /* Kirim struk otomatis + masa berlaku tautan unduh struk. */
            set_setting('email_receipt_auto', ($_POST['email_receipt_auto'] ?? '') === '1' ? '1' : '0');
            $rld = (int)($_POST['receipt_link_days'] ?? 30);
            set_setting('receipt_link_days', (string)max(1, min(365, $rld > 0 ? $rld : 30)));
            /* Tujuan, jadwal, pengirim, dan pengaturan struk TETAP dapat diubah
               pemegang izin Pengaturan Sistem. */
            foreach (['email_recipient', 'email_reply_to', 'email_sender', 'email_sender_name', 'public_base_url',
                      'email_schedule_day', 'email_schedule_time', 'email_schedule_mode'] as $k) {
                set_setting($k, trim((string)($_POST[$k] ?? '')));
            }
            /* JALUR PENGIRIMAN (mode, penyedia API + kunci, SMTP) HANYA Super Admin —
               diperiksa di SERVER: level lain yang mengirim kolom itu diabaikan
               sepenuhnya sehingga nilai yang sudah diisi tidak pernah terubah. */
            if (is_super()) {
                foreach (['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user',
                          'email_api_provider', 'email_api_url', 'email_api_domain', 'email_mode'] as $k) {
                    set_setting($k, trim((string)($_POST[$k] ?? '')));
                }
                if (!in_array(setting('email_api_provider'), array_keys(email_providers()), true)) set_setting('email_api_provider', 'custom');
                if (!in_array(setting('email_mode'), ['auto', 'api', 'smtp'], true)) set_setting('email_mode', 'auto');
                if (($_POST['smtp_pass'] ?? '') !== '') set_setting('smtp_pass', (string)$_POST['smtp_pass']);
                if (($_POST['email_api_key'] ?? '') !== '') set_setting('email_api_key', (string)$_POST['email_api_key']);
            } else {
                audit('Ubah Jalur Pengiriman Email Ditolak', 'Pengaturan', null, null,
                    ['percobaan_oleh' => (string)($user['role_name'] ?? ''), 'email_mode' => (string)($_POST['email_mode'] ?? '')],
                    'Jalur pengiriman email hanya dapat diubah Super Admin — perubahan diabaikan');
            }
            set_setting('email_service_active', ($_POST['email_service_active'] ?? '') === '1' ? '1' : '0');
            foreach (['email_on_report', 'email_on_reservation'] as $flag) {
                set_setting($flag, ($_POST[$flag] ?? '') === '1' ? '1' : '0');
            }
            /* DUA PENERIMA LAPORAN BULANAN (permintaan pemilik):
               (1) laporan lengkap TANPA keuangan → staf/dokter/bebas (email_recipient)
               (2) laporan lengkap + KEUANGAN → Direktur/Owner (email_finance_recipient,
                   boleh lebih dari satu alamat dipisah koma/titik koma). */
            if (is_super()) {
                $finTo = preg_replace('/[\r\n]+/', ',', trim((string)($_POST['email_finance_recipient'] ?? '')));
                set_setting('email_finance_recipient', (string)$finTo);
                set_setting('email_finance_enabled', ($_POST['email_finance_enabled'] ?? '') === '1' ? '1' : '0');
            }
            if (trim((string)setting('email_sender')) === '') set_setting('email_sender', 'distintech@gmail.com');
            audit('Ubah Pengaturan Email', 'Pengaturan', null, null, ['status' => mail_status_text()], 'Perubahan konfigurasi email');
            flash('Konfigurasi email disimpan. Status: ' . mail_status_text()
                . (is_super() ? '' : ' Catatan: bagian <strong>Jalur Pengiriman</strong> (mode, penyedia API, SMTP) '
                    . 'tidak diubah karena hanya Super Admin yang boleh mengubahnya.'), is_super() ? 'success' : 'warning');
        }
        if ($act === 'payment') {
            foreach (['pay_bank_name', 'pay_bank_account', 'pay_bank_holder', 'pay_note'] as $k) {
                set_setting($k, trim((string)($_POST[$k] ?? '')));
            }
            set_setting('pay_unique_code', ($_POST['pay_unique_code'] ?? '') === '1' ? '1' : '0');
            $exp = (int)($_POST['pay_expire_minutes'] ?? 15);
            set_setting('pay_expire_minutes', (string)max(5, min(180, $exp > 0 ? $exp : 15)));
            /* PAYMENT GATEWAY (pembayaran otomatis) HANYA Super Admin — diperiksa di
               SERVER: kolom gateway dari level lain diabaikan sepenuhnya sehingga
               kunci API yang sudah diisi tidak pernah terubah/terhapus. */
            if (is_super()) {
                $gw = (string)($_POST['pay_gateway'] ?? 'none');
                set_setting('pay_gateway', array_key_exists($gw, pay_gateways()) ? $gw : 'none');
                set_setting('pay_gateway_env', ($_POST['pay_gateway_env'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox');
                /* Kunci API: dibiarkan tersimpan bila kolom dikosongkan (sama seperti
                   kunci email/SMTP) supaya tidak terhapus tanpa sengaja. */
                if (($_POST['pay_gateway_base_url'] ?? '') !== '') {
                    set_setting('pay_gateway_base_url', rtrim(trim((string)$_POST['pay_gateway_base_url']), '/'));
                } elseif (isset($_POST['pay_gateway_base_url'])) {
                    set_setting('pay_gateway_base_url', '');
                }
                foreach ([
                    'pay_gateway_server_key' => 'pay_gateway_server_key',
                    'pay_gateway_client_key' => 'pay_gateway_client_key',
                    'pay_gateway_merchant_id' => 'pay_gateway_merchant_id',
                ] as $form => $key) {
                    if (($_POST[$form] ?? '') !== '') set_setting($key, trim((string)$_POST[$form]));
                }
            } else {
                audit('Ubah Payment Gateway Ditolak', 'Pengaturan', null, null,
                    ['percobaan_oleh' => (string)($user['role_name'] ?? ''), 'pay_gateway' => (string)($_POST['pay_gateway'] ?? '')],
                    'Payment gateway hanya dapat diubah Super Admin — perubahan diabaikan');
            }
            audit('Ubah Pengaturan Pembayaran', 'Pengaturan', null, null,
                ['gateway' => pay_gateway_key(), 'status' => pay_gateway_status_text()],
                'Perubahan konfigurasi pembayaran');
            flash('Pengaturan pembayaran disimpan. Status: ' . pay_gateway_status_text()
                . (is_super() ? '' : ' Catatan: bagian <strong>Payment Gateway</strong> (penyedia, lingkungan, kunci API) '
                    . 'tidak diubah karena hanya Super Admin yang boleh mengubahnya.'), is_super() ? 'success' : 'warning');
        }
        if ($act === 'qris') {
            /* Unggah gambar QRIS statis klinik (untuk jalur manual). */
            if (empty($_FILES['qris']['name']) || ($_FILES['qris']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Pilih berkas gambar QRIS terlebih dahulu.');
            }
            $name = (string)$_FILES['qris']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                throw new RuntimeException('Gambar QRIS harus PNG, JPG, atau WEBP.');
            }
            if ((int)$_FILES['qris']['size'] > 5 * 1024 * 1024) throw new RuntimeException('Ukuran gambar QRIS maksimal 5 MB.');
            $res = img_process_upload((string)$_FILES['qris']['tmp_name'], $name, local_upload_dir(), 'qris', 'logo');
            $oldQris = (string)setting('pay_qris_file');
            if ($oldQris !== '' && $oldQris !== $res['file']) {
                $oldPath = local_upload_dir() . '/' . basename($oldQris);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            set_setting('pay_qris_file', (string)$res['file']);
            audit('Unggah QRIS Klinik', 'Pengaturan', null, null,
                ['file' => $res['file'], 'ukuran' => $res['bytes']], 'Gambar QRIS pembayaran diperbarui');
            flash('Gambar QRIS tersimpan (' . img_result_text($res) . ').');
        }
        if ($act === 'qris_delete') {
            $oldQris = (string)setting('pay_qris_file');
            if ($oldQris !== '') {
                $oldPath = local_upload_dir() . '/' . basename($oldQris);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            set_setting('pay_qris_file', '');
            audit('Hapus QRIS Klinik', 'Pengaturan', null, null, ['file' => $oldQris], 'Gambar QRIS dihapus');
            flash('Gambar QRIS dihapus.');
        }
        /* ---------- KEMBALI KE DEFAULT (permintaan pemilik) ----------
           Mengembalikan teks email struk / template WhatsApp ke BAKUANNYA.
           Bakuannya adalah rekaman template yang berlaku saat fitur ini dipasang
           (lihat includes/template_default.php), sehingga mengubah-ubah template
           tidak mengubah bakuannya. Konfirmasi 2 tahap di sisi tampilan
           (data-heavy-confirm) mencegah terpencet tanpa sengaja. */
        if ($act === 'email_reset_default' || $act === 'wa_reset_default') {
            if (!has_perm('settings.manage')) deny('Hanya pemegang izin Pengaturan Sistem yang dapat mengembalikan template ke bawaan.');
            $grup = $act === 'email_reset_default' ? 'email' : 'wa';
            $g = template_default_groups()[$grup];
            $r = template_default_restore($grup);
            audit('Kembalikan Template ke Default', 'Pengaturan', null,
                array_map(fn($v) => short_text($v, 120), $r['dari']),
                array_map(fn($v) => short_text($v, 120), $r['ke']),
                'Template ' . $g['label'] . ' dikembalikan ke bakuannya (' . count($r['ke']) . ' teks)');
            $nama = [];
            foreach (array_keys($r['ke']) as $k) {
                $nama[] = ['email_receipt_subject' => 'Subjek email struk', 'email_receipt_body' => 'Isi email struk',
                    'wa_template' => 'Template reservasi', 'wa_template_doctor' => 'Template pengingat dokter',
                    'wa_receipt_template' => 'Template struk WhatsApp'][$k] ?? $k;
            }
            flash('Template ' . $g['label'] . ' dikembalikan ke DEFAULT: ' . e(implode(', ', $nama))
                . '. Tekan "Simpan Konfigurasi" bila ingin menyimpan perubahan lain pada kartu ini.', 'success');
        }
        if ($act === 'wa') {
            foreach (['wa_api_url', 'wa_api_sender', 'wa_template', 'wa_sender_number', 'wa_receipt_template', 'wa_template_doctor'] as $k) {
                set_setting($k, (string)($_POST[$k] ?? ''));
            }
            set_setting('wa_receipt_link', ($_POST['wa_receipt_link'] ?? '0') === '1' ? '1' : '0');
            set_setting('wa_doctor_active', ($_POST['wa_doctor_active'] ?? '1') === '1' ? '1' : '0');
            if (($_POST['wa_api_token'] ?? '') !== '') set_setting('wa_api_token', (string)$_POST['wa_api_token']);
            set_setting('wa_api_active', ($_POST['wa_api_active'] ?? '') === '1' ? '1' : '0');
            audit('Ubah Pengaturan WhatsApp', 'Pengaturan', null, null, ['api_active' => setting('wa_api_active')], 'Perubahan konfigurasi WhatsApp');
            flash('Konfigurasi WhatsApp disimpan.');
        }
        if ($act === 'test_email') {
            $to = trim((string)($_POST['to'] ?? setting('email_recipient')));
            /* Pengaman kirim ulang: bila email uji SUDAH pernah terkirim ke alamat
               yang sama, minta konfirmasi lebih dulu (lewat formulir di peramban). */
            $ujiKey = 'Uji ' . strtolower($to);
            $ujiRiwayat = email_resend_notice($ujiKey, 'Email uji ke ' . $to);
            if ($ujiRiwayat['blocked'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
                flash($ujiRiwayat['block_msg'], 'warning');
                header('Location: settings.php?tab=email');
                exit;
            }
            if ($ujiRiwayat['needs'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
                flash($ujiRiwayat['notice'] . ' Bila memang ingin mengirim ulang, tekan tombol '
                    . '"Kirim Email Uji" sekali lagi.', 'warning');
                header('Location: settings.php?tab=email');
                exit;
            }
            $err = '';
            $sendPdf = ($_POST['with_pdf'] ?? '') === '1';
            $att = [];
            if ($sendPdf) {
                $mpdf = monthly_report_pdf(date('Y-m'));
                $att[] = ['name' => $mpdf['filename'], 'mime' => 'application/pdf', 'data' => $mpdf['bytes']];
            }
            $html = email_wrap_html(
                'Uji Pengiriman Email',
                '<p>Ini email uji dari <strong>' . e(clinic_name()) . ' Management System</strong>.</p>'
                . '<p>Bila email ini Anda terima, konfigurasi pengiriman sudah benar'
                . ($att ? ' (termasuk lampiran PDF)' : '') . '.</p>'
                . '<p class="muted">Dikirim: ' . e(tgl(date('Y-m-d H:i:s'), true)) . ' · '
                . e(mail_status_text()) . '</p>'
            );
            $ok = send_email($to, 'Uji Pengiriman Email — ' . clinic_name(), $html, $err, $att);
            email_record_result($ok, $ok ? 'Email uji terkirim' : (string)$err, $ok);
            q('INSERT INTO email_report_logs (recipient, period, status, message) VALUES (?,?,?,?)',
              [$to, $ujiKey, $ok ? 'sent' : 'failed', $ok ? 'Email uji terkirim' : (string)$err]);
            audit($ok ? 'Email Uji Terkirim' : 'Email Uji Gagal', 'Pengaturan', null, null,
                ['to' => $to, 'lampiran' => count($att), 'cara' => email_mode()], $ok ? 'Email uji berhasil dikirim' : (string)$err);
            flash($ok ? 'Email uji berhasil dikirim ke ' . $to . ($att ? ' beserta lampiran PDF.' : '.') : (string)$err,
                $ok ? 'success' : 'warning');
        }
        if ($act === 'send_report') {
            $month = (string)($_POST['month'] ?? '');
            /* Pengaman kirim ulang laporan bulan yang sama. */
            $lapRiwayat = email_resend_notice('Laporan ' . $month, 'Laporan bulan ' . $month);
            if ($lapRiwayat['blocked'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
                flash($lapRiwayat['block_msg'], 'warning');
                header('Location: settings.php?tab=email');
                exit;
            }
            if ($lapRiwayat['needs'] && (string)($_POST['confirm_resend'] ?? '') !== '1') {
                flash($lapRiwayat['notice'] . ' Bila memang ingin mengirim ulang, tekan tombol '
                    . '"Kirim Laporan Sekarang" sekali lagi.', 'warning');
                header('Location: settings.php?tab=email');
                exit;
            }
            /* Kirim ke SEMUA penerima yang diatur: laporan lengkap (tanpa keuangan)
               ke staf/dokter, dan laporan lengkap + keuangan ke Direktur/Owner. */
            $all = send_monthly_reports($month);
            $okN = count(array_filter($all, fn($r) => $r['ok']));
            /* Pesan menyebut penerima satu per satu beserta JENIS laporannya
               (lengkap tanpa keuangan / lengkap + keuangan) supaya jelas siapa
               menerima apa — dan tetap memuat frasa "terkirim ke <alamat>". */
            $lines = array_map(function ($r) {
                $jenis = !empty($r['with_finance']) ? 'lengkap + keuangan' : 'lengkap (tanpa keuangan)';
                if ($r['ok']) return 'terkirim ke ' . ($r['to'] !== '' ? $r['to'] : '(alamat kosong)') . ' (' . $jenis . ')';
                return 'GAGAL ke ' . ($r['to'] !== '' ? $r['to'] : '(alamat kosong)') . ' (' . $jenis . ')'
                    . ($r['error'] !== '' ? ': ' . $r['error'] : '');
            }, $all);
            flash('Laporan bulan ' . $month . ': ' . implode(' · ', $lines)
                . ($okN === count($all) ? '' : ' — periksa konfigurasi email.'), $okN === count($all) ? 'success' : 'warning');
        }
        if ($act === 'email_diag') {
            $diag = email_diagnostics();
            audit('Diagnosa Email', 'Pengaturan', null, null, $diag, 'Pemeriksaan jalur pengiriman email');
            flash('Diagnosa email dijalankan — lihat hasilnya di bawah.', 'info');
            $_SESSION['email_diag'] = $diag;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: settings.php');
    exit;
}
$logs = all('SELECT * FROM email_report_logs ORDER BY id DESC LIMIT 10');
$lastMonth = date('Y-m', strtotime('first day of last month'));
$preview = monthly_report_email($lastMonth);
$previewPdf = null;
try { $previewPdf = monthly_report_pdf($lastMonth); } catch (Throwable $e) { $previewPdf = ['error' => $e->getMessage()]; }
$emailDiag = $_SESSION['email_diag'] ?? null; unset($_SESSION['email_diag']);
$emailLast = email_last_result();

page_head('Pengaturan Sistem', 'settings');
?>
<div class="page-head">
  <div><h2>Pengaturan Sistem</h2><p class="muted">Tema, identitas klinik, email, WhatsApp, pembayaran, dan kartu member.
      Pengaturan tingkat sistem (nama klinik, penyimpanan data, kompresi foto, backup, pemeliharaan, integrasi)
      ada di <a href="developer.php">Developer Settings</a>.</p></div>
  <div class="page-actions">
    <a class="btn" href="users.php"><?= icon('user-cog') ?> Manajemen User</a>
    <?php if (has_perm('backup.manage')): ?>
      <a class="btn" href="backup.php"><?= icon('database') ?> Backup Database</a>
    <?php endif; ?>
    <?php if (is_super()): ?>
      <a class="btn btn-primary" href="developer.php"><?= icon('settings') ?> Developer Settings</a>
    <?php endif; ?>
  </div>
</div>

<?php
/* Pemilih tema punya kartu SENDIRI (bukan menumpuk di dalam kartu Identitas
   Klinik). Daftar dibuat berjajar banyak kolom + area yang dapat digulir, sehingga
   halaman Pengaturan tidak memanjang ke bawah. */
$activeTheme = theme_key();
$themeAll = theme_list();
?>
<?php
/* Kartu WARNA GRAFIK — pemilik klinik dapat mengatur mode palet dan warna seri
   yang punya makna (Treatment/Skincare/Total). Palet kategorikal (antar cabang,
   antar metode pembayaran, Top 5) dibangkitkan otomatis oleh includes/theme.php
   sehingga selalu kontras, TIDAK PERNAH mengulang warna walaupun cabangnya
   lebih dari 10, dan seragam di layar, Excel, PDF, serta email. */
$cgSeries = chart_series_colors();
$cgPreview = chart_colors(12);
$cgMin = [];
foreach ([2, 7, 12, 24] as $cgN) {
    $cgSub = chart_colors($cgN); $cgD = INF;
    for ($i = 0; $i < count($cgSub); $i++) {
        for ($j = $i + 1; $j < count($cgSub); $j++) {
            $cgD = min($cgD, chart_color_distance($cgSub[$i], $cgSub[$j]));
        }
    }
    $cgMin[$cgN] = (int)round($cgD);
}
?>
<div class="card" id="warnagrafik">
  <div class="card-head">
    <h3>Warna Grafik &amp; Kartu Statistik</h3>
    <span><?= badge('Palet kontras: ' . num(count(chart_palette_full())) . ' warna', 'green') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="chart_colors">
    <div class="card-body">
      <p class="muted">Grafik "perbandingan antar cabang" (dan semua grafik dengan banyak seri) memakai
        <strong>palet kontras tinggi <?= num(count(chart_palette_full())) ?> warna</strong> yang dibuat otomatis:
        warna ke-1 sampai ke-48 sudah disusun supaya selalu berbeda jauh, dan <strong>tidak pernah mengulang
        warna</strong> walau cabangnya lebih dari 10. Warna yang dapat Anda ubah di sini adalah warna
        <em>seri yang punya makna</em> (Treatment, Skincare, Total) — dipakai seragam di dashboard, laporan,
        Excel, PDF, dan laporan email.</p>

      <div class="section-title">Palet otomatis (contoh 12 warna pertama)</div>
      <div class="flex flex-wrap gap-sm mb-2">
        <?php foreach ($cgPreview as $cgI => $cgHex): ?>
          <span title="Seri ke-<?= $cgI + 1 ?>: <?= e($cgHex) ?>"
                style="display:inline-flex;align-items:center;justify-content:center;width:44px;height:30px;border-radius:8px;
                       background:<?= e($cgHex) ?>;color:#fff;font-size:.68rem;font-weight:700;border:1px solid rgba(0,0,0,.08)"><?= $cgI + 1 ?></span>
        <?php endforeach; ?>
      </div>
      <div class="notice">
        Jarak warna minimum antar seri pada palet ini (semakin besar = semakin jelas berbeda):
        <?php $cgTxt = []; foreach ($cgMin as $n => $d) $cgTxt[] = $n . ' seri = ' . $d; ?>
        <strong><?= e(implode(' · ', $cgTxt)) ?></strong>.
        Sebagai gambaran, dua warna dengan jarak di bawah ±60 mulai sulit dibedakan di layar HP.
        <?php if (count(chart_colors(48)) >= 48): ?>
          Untuk grafik dengan <strong>lebih dari 8 seri</strong>, garis ganjil otomatis diberi pola putus-putus
          sebagai pembeda tambahan (selain warna).
        <?php endif; ?>
      </div>

      <div class="section-title">Mode palet</div>
      <div class="form-grid g2">
        <div class="field"><label>Palet warna seri banyak</label>
          <select class="input" name="chart_palette_mode">
            <option value="kontras"<?= chart_palette_mode() === 'kontras' ? ' selected' : '' ?>>Kontras tinggi (disarankan)</option>
            <option value="tema"<?= chart_palette_mode() === 'tema' ? ' selected' : '' ?>>Ikuti warna tema lebih dulu</option>
          </select>
          <span class="hint">"Kontras tinggi" memakai 48 warna yang sengaja dibuat berjauhan (merah–cyan–kuning–hijau–biru, dst.).
            "Ikuti warna tema" menaruh warna tema di depan, lalu warna lain yang tetap berbeda.</span></div>
        <?php /* type=color dibuat setinggi kontrol lain (min 40px) supaya nyaman
                 disentuh di HP — uji responsif menuntut minimal 40px. */ ?>
        <div class="field"><label>Warna seri Treatment</label>
          <input class="input chart-color" type="color" name="chart_color_treatment" value="<?= e($cgSeries['treatment']) ?>"></div>
        <div class="field"><label>Warna seri Skincare</label>
          <input class="input chart-color" type="color" name="chart_color_skincare" value="<?= e($cgSeries['skincare']) ?>"></div>
        <div class="field"><label>Warna garis Total Pendapatan</label>
          <input class="input chart-color" type="color" name="chart_color_total" value="<?= e($cgSeries['total']) ?>"></div>
        <div class="field"><label>Warna nilai naik / turun (laporan cetak)</label>
          <div class="flex gap-sm">
            <input class="input chart-color" type="color" name="chart_color_positif" value="<?= e($cgSeries['positif']) ?>" title="naik">
            <input class="input chart-color" type="color" name="chart_color_negatif" value="<?= e($cgSeries['negatif']) ?>" title="turun">
          </div>
          <span class="hint">Kiri = pertumbuhan positif (hijau), kanan = negatif (merah).</span></div>
      </div>
      <div class="notice mt-2">Bawaan yang disarankan: Treatment <strong>magenta</strong> dan Skincare
        <strong>hijau tua</strong> — dua warna yang sangat kontras sehingga batangnya langsung terbaca
        (versi lama memakai hijau muda sehingga perbedaannya tipis).</div>

      <?php /* ---------- WARNA KARTU STATISTIK ----------
         Dipakai SERAGAM di dashboard, laporan, dan menu Keuangan:
           Penjualan/Pendapatan Treatment = gold · Penjualan/Pendapatan Skincare = pink ·
           Laba Kotor = coklat. Satu tempat pengaturan supaya tidak berbeda antar halaman. */ ?>
      <?php $scc = stat_card_colors(); ?>
      <div class="section-title">Warna Kartu Statistik</div>
      <p class="muted">Kartu berwarna dipakai seragam di <strong>Dashboard</strong>, <strong>Laporan</strong>,
        dan <strong>Keuangan</strong>. Angka &amp; label pada kartu memakai warna gelap sehingga tetap
        terbaca jelas di atas latar berwarna.</p>
      <div class="form-grid g3">
        <?php foreach ([['gold', 'Kartu Gold (Treatment)'], ['pink', 'Kartu Pink (Skincare)'], ['brown', 'Kartu Coklat (Laba Kotor)']] as [$sk, $slbl]): ?>
          <div class="field"><label><?= e($slbl) ?></label>
            <div class="flex gap-sm" style="align-items:center;flex-wrap:wrap">
              <?php foreach (['from' => 'Terang', 'to' => 'Utama'] as $bag => $blbl): ?>
                <label class="small muted" style="display:flex;align-items:center;gap:6px">
                  <input class="input chart-color" type="color"
                         name="stat_color_<?= e($sk) ?>_<?= e($bag) ?>"
                         value="<?= e($scc[$sk][$bag]) ?>" title="warna <?= e(strtolower($blbl)) ?>">
                  <?= e($blbl) ?>
                </label>
              <?php endforeach; ?>
              <span class="stat <?= e($sk) ?>" style="padding:6px 12px;gap:0">
                <span class="lbl" style="font-size:.66rem">Contoh</span>
                <span class="val" style="font-size:.95rem">Rp 1.250.000</span>
              </span>
            </div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Simpan Warna Grafik &amp; Kartu</button>
    </div>
  </form>
</div>

<div class="card" id="ukuran">
  <div class="card-head">
    <h3>Ukuran Tampilan</h3>
    <span><?= badge('Berlaku untuk semua pengguna', 'gray') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="ui_scale">
    <div class="card-body">
      <p class="muted">Mengatur besar-kecilnya SELURUH tampilan (teks, tabel, tombol, kartu) —
        sama seperti menekan <kbd>Ctrl</kbd> + <kbd>−</kbd> pada peramban, tetapi berlaku untuk
        semua orang yang memakai aplikasi ini. Bila di PC/laptop terasa terlalu besar, pilih
        <strong>80%</strong>.</p>
      <div class="form-grid g2">
        <div class="field"><label>Skala tampilan</label>
          <select class="input" name="ui_scale">
            <?php foreach (ui_scale_options() as $k => $lbl): ?>
              <option value="<?= (int)$k ?>"<?= (int)round(ui_scale() * 100) === (int)$k ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Ukuran di layar HP tetap dijaga minimal 15px agar tetap nyaman dibaca.</span></div>
        <div class="field"><label>Contoh tampilan sekarang</label>
          <div style="border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:#fff">
            <div style="font-size:1.05rem;font-weight:700">Judul contoh</div>
            <div class="small muted">Teks kecil — inilah ukuran tabel &amp; keterangan di seluruh aplikasi.</div>
          </div></div>
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Ukuran Tampilan</button>
    </div>
  </form>
</div>

<div class="card" id="tema">
  <div class="card-head">
    <h3>Tema Warna</h3>
    <span><?= badge('Aktif: ' . $themeAll[$activeTheme]['name'], 'pink') ?> · <?= num(count($themeAll)) ?> pilihan</span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="theme">
    <div class="card-body">
      <p class="muted mb-2">Pilih salah satu dari <?= num(count($themeAll)) ?> rekomendasi warna. Perubahan langsung berlaku di seluruh
        halaman (sidebar, tombol, grafik, struk, dan dokumen laporan). Warna status (berhasil/perhatian/bahaya) tidak ikut berubah
        agar maknanya tetap jelas.</p>
      <?php /* PEMILIH KELUARGA WARNA (ronde 35) — diletakkan DI ATAS daftar supaya
               pemilik tidak perlu menggulir jauh: pilih keluarga (mis. Hijau) maka
               daftar di bawah hanya menampilkan keluarga itu. Semua tombol memakai
               type="button" supaya TIDAK mengirim form tema. */ ?>
      <div class="theme-fam-picker" id="themeFamPicker" role="group" aria-label="Pilih keluarga warna">
        <button type="button" class="fam-btn active" data-fam="__all">Semua <span class="fam-n"><?= num(count($themeAll)) ?></span></button>
        <?php foreach (theme_grouped() as $fkey => $frows):
              $fmeta = theme_families()[$fkey] ?? ['label' => $fkey, 'desc' => ''];
              $fcolor = (string)($frows[array_key_first($frows)]['brandMid'] ?? '#888888');
              $adaAktif = false;
              foreach ($frows as $tk => $tv) if ($tk === $activeTheme) $adaAktif = true; ?>
          <button type="button" class="fam-btn<?= $adaAktif ? ' has-active' : '' ?>" data-fam="<?= e($fkey) ?>"
                  title="<?= e($fmeta['label'] . ' — ' . $fmeta['desc']) ?>">
            <i class="fam-dot" style="background:<?= e($fcolor) ?>"></i><?= e($fmeta['label']) ?>
            <span class="fam-n"><?= num(count($frows)) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="small muted mb-2" id="themeFamNote">Pilih keluarga warna untuk mempersempit daftar, atau
        <strong>Semua</strong> untuk menampilkan seluruh tema.</div>

      <div class="theme-picker" id="themePicker">
        <?php /* Ditata per KELUARGA WARNA: label di kiri, 4 pilihan berjajar ke kanan. */ ?>
        <?php foreach (theme_grouped() as $fkey => $frows): $fmeta = theme_families()[$fkey] ?? ['label' => $fkey, 'desc' => '']; ?>
          <div class="theme-family" data-fam="<?= e($fkey) ?>">
            <div class="theme-family-head">
              <span class="tf-label"><?= e($fmeta['label']) ?></span>
              <span class="tf-desc"><?= e($fmeta['desc']) ?></span>
              <span class="tf-count"><?= num(count($frows)) ?> warna</span>
            </div>
            <div class="theme-grid">
              <?php foreach ($frows as $tk => $tv): ?>
                <label class="theme-card<?= $activeTheme === $tk ? ' active' : '' ?>" title="<?= e($tv['name'] . ' — ' . $tv['desc']) ?>">
                  <input type="radio" name="theme" value="<?= e($tk) ?>"<?= $activeTheme === $tk ? ' checked' : '' ?> onchange="this.form.submit()">
                  <span class="theme-swatch" style="background:linear-gradient(135deg,<?= e($tv['brandMid']) ?>,<?= e($tv['brandDark']) ?>)">
                    <i style="background:<?= e($tv['accent']) ?>"></i>
                  </span>
                  <span class="theme-name"><?= e($tv['name']) ?></span>
                  <span class="theme-desc"><?= e($tv['desc']) ?></span>
                  <?php if ($activeTheme === $tk): ?><span class="theme-check">✓ dipakai</span><?php endif; ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="flex gap-sm mt-2">
        <button class="btn btn-primary btn-sm" type="submit">Terapkan Tema Terpilih</button>
        <?php if ($activeTheme !== 'magenta'): ?>
          <button class="btn btn-sm" type="submit" name="theme" value="magenta">Kembalikan ke Magenta</button>
        <?php endif; ?>
        <span class="muted small">Tema aktif sekarang: <strong><?= e(theme_current()['name']) ?></strong>.</span>
      </div>
    </div>
  </form>
</div>

<?php /* KARTU IDENTITAS KLINIK LEBAR PENUH (ronde 37, permintaan pemilik).
   Dulu kartu ini berdampingan dengan "Pengaturan Umum" dalam grid 2 kolom
   sehingga hanya separuh lebar; sekarang Pengaturan Umum pindah ke Developer
   Settings, jadi identitas klinik memakai lebar penuh (isi formulirnya tetap
  2 kolom di dalam .form-grid). */ ?>
<div class="card" id="identitas">
    <div class="card-head"><h3>Identitas Klinik</h3>
      <span class="muted small">logo, tagline, alamat &amp; kontak</span></div>
    <div class="card-body" style="border-bottom:1px solid var(--line)">
      <div class="section-title" style="margin-top:0">Logo Klinik</div>
      <div class="flex flex-wrap gap-lg">
        <div style="min-width:190px">
          <?php if (brand_logo_src() !== ''): ?>
            <div style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px;text-align:center">
              <img src="<?= e(brand_logo_src()) ?>" alt="Logo klinik" style="max-height:70px;max-width:200px">
            </div>
            <div class="small muted mt-1">
              <?= setting('logo_file') !== '' ? 'Tersimpan di penyimpanan aplikasi' : 'Tersimpan di penyimpanan media' ?>
            </div>
          <?php else: ?>
            <div style="background:#fff;border:1px dashed var(--line);border-radius:12px;padding:14px;text-align:center">
              <?= brand_block(true) ?>
            </div>
            <div class="small muted mt-1">Masih memakai logo bawaan sistem.</div>
          <?php endif; ?>
        </div>
        <div class="grow">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="logo">
            <div class="field"><label>Unggah Logo Baru</label>
              <input class="input" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" required>
              <span class="hint">PNG/JPG/WEBP/SVG, maksimal 5 MB. Disarankan PNG <strong>latar transparan</strong> agar rapi di sidebar berwarna —
              logo berlatar putih tetap tampil di dalam kotak putih.</span></div>
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?> Simpan Logo</button>
          </form>
          <?php
          /* JEBAKAN YANG SUDAH DIPERBAIKI: ukuran & status cache logo dihitung DI SINI
             (sebelum tombol "Siapkan Versi Kecil" diperiksa). Dulu perhitungannya berada
             SESUDAH pemeriksaan tombol itu sehingga variabelnya belum ada — tombol
             tersebut tidak pernah tampil, dan PHP menulis peringatan
             "Undefined variable $logoDim" pada setiap halaman Pengaturan dibuka. */
          $logoPath = logo_local_path();
          $logoDim = $logoPath !== '' ? png_dimensions((string)file_get_contents($logoPath)) : null;
          /* Peringatan hanya bila gambar besar DAN versi kecilnya belum tersimpan
             (cache). Bila cache sudah ada, semua dokumen memakai versi kecil itu
             sehingga tidak ada masalah kecepatan. */
          $logoCached = $logoPath !== '' && png_cache_exists($logoPath, 192);
          if ($logoDim && !$logoCached && ($logoDim['width'] > 2000 || $logoDim['height'] > 2000)): ?>
          <form method="post" class="mt-1"
                data-confirm="Siapkan versi kecil dari logo yang sudah terunggah? Proses ini memerlukan waktu sekitar 1–3 menit dan berjalan sekali saja.">
            <?= csrf_field() ?><input type="hidden" name="action" value="logo_warm">
            <button class="btn btn-sm" type="submit"><?= icon('database') ?> Siapkan Versi Kecil Logo (sekali saja)</button>
          </form>
          <?php endif; ?>
          <?php if (brand_logo_src() !== ''): ?>
          <form method="post" class="mt-1" data-confirm="Hapus logo dan kembalikan ke logo bawaan sistem?">
            <?= csrf_field() ?><input type="hidden" name="action" value="logo_remove">
            <button class="btn btn-sm" type="submit">Hapus Logo</button>
          </form>
          <?php endif; ?>
          <div class="notice mt-2">Logo ini otomatis dipakai di sidebar, halaman login, struk, dan dokumen laporan.</div>
          <?php if ($logoDim && !$logoCached && ($logoDim['width'] > 2000 || $logoDim['height'] > 2000)): ?>
            <div class="alert alert-warning mt-2">
              Logo saat ini berukuran <strong><?= num($logoDim['width']) ?>×<?= num($logoDim['height']) ?> px</strong> —
              jauh lebih besar dari yang dibutuhkan (600×600 px sudah sangat cukup). Gambar sebesar ini membuat
              pembuatan PDF lambat, sehingga <strong>logo tidak dicetak di struk/laporan PDF</strong> sampai
              versi kecilnya siap. Solusi tercepat: <strong>unggah ulang logo yang sudah dikecilkan</strong>
              (maksimal 2000×2000 px) — unggahan akan otomatis menyiapkan versi kecilnya.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="company">
      <div class="card-body">
        <div class="form-grid g2">
          <div class="field" style="grid-column:1/-1">
            <label>Nama Klinik</label>
            <input class="input" value="<?= e(clinic_name()) ?>" disabled>
            <span class="hint"><?php if (is_super()): ?>
              Nama klinik diubah pada kartu <strong>Nama Klinik (Identitas Sistem)</strong> di atas — satu tempat
              untuk seluruh aplikasi.
            <?php else: ?>
              Hanya <strong>Super Admin</strong> yang dapat mengubah nama klinik (kartu "Nama Klinik").
              Hubungi Super Admin bila perlu mengganti nama klinik.
            <?php endif; ?></span></div>
          <div class="field"><label>Tagline</label><input class="input" name="company_tagline" value="<?= e(setting('company_tagline')) ?>"></div>
          <div class="field"><label>Telepon</label><input class="input" name="company_phone" value="<?= e(setting('company_phone')) ?>"></div>
          <div class="field"><label>Email</label><input class="input" name="company_email" value="<?= e(setting('company_email')) ?>"></div>
          <div class="field" style="grid-column:1/-1"><label>Alamat Pusat</label><textarea class="input" name="company_address"><?= e(setting('company_address')) ?></textarea></div>
          <div class="field" style="grid-column:1/-1"><label>Catatan Kaki Struk</label><textarea class="input" name="receipt_footer"><?= e(setting('receipt_footer')) ?></textarea></div>
        </div>
      </div>
      <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)"><button class="btn btn-primary" type="submit">Simpan Identitas</button></div>
    </form>
  </div>

<?php /* ================= GAMBAR LATAR WEB (ronde 64) =================
   Permintaan pemilik: di bawah kartu Identitas Klinik, dapat mengubah gambar latar
   web yang OTOMATIS BERUBAH setiap halaman dimuat ulang. Gambar memakai TAUTAN
   DARING (bukan unggahan) supaya tidak memakai ruang penyimpanan aplikasi. */
$wpMode = wallpaper_mode();
$wpKat = wallpaper_category();
$wpCustom = wallpaper_custom_urls();
$wpPool = wallpaper_pool();
$wpContoh = array_slice($wpPool, 0, 6);
?>
<div class="card" id="wallpaper">
  <div class="card-head">
    <h3>Gambar Latar Web</h3>
    <span class="muted small">tautan gambar daring · berganti setiap halaman dimuat</span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="wallpaper">
    <div class="card-body">
      <div class="notice">
        Gambar latar diambil dari <strong>tautan gambar daring</strong> (bukan diunggah) sehingga
        <strong>tidak memakai ruang penyimpanan</strong> aplikasi. Setiap kali halaman dimuat ulang, gambar
        dipilih <strong>acak</strong> dari daftar — tampilannya berganti-ganti. Bila Anda mengisi tautan
        sendiri di bawah, tautan itulah yang dipakai.
      </div>
      <div class="form-grid g2 mt-2">
        <div class="field"><label>Tampilkan Gambar Latar Di</label>
          <select class="input" name="wallpaper_mode">
            <?php foreach (wallpaper_modes() as $k => $v): ?>
              <option value="<?= e($k) ?>"<?= $wpMode === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">“Halaman login &amp; ubah kata sandi” = hanya pada halaman masuk, lupa kata sandi,
            dan reset kata sandi. “Semua halaman web” juga berlaku untuk seluruh halaman aplikasi.</span></div>
        <div class="field"><label>Tema Gambar</label>
          <select class="input" name="wallpaper_category">
            <?php foreach (wallpaper_categories() as $k => $v): ?>
              <option value="<?= e($k) ?>"<?= $wpKat === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Tema menentukan kumpulan gambar bawaan (khusus Treatment, khusus Skincare, dst).
            “Kecantikan + Treatment + Skincare” menggabungkan ketiganya.</span></div>
        <div class="field" style="grid-column:1/-1"><label>Tautan Gambar Sendiri <span class="muted small">(opsional)</span></label>
          <textarea class="input" name="wallpaper_urls" rows="4"
            placeholder="https://contoh.com/gambar-1.jpg&#10;https://contoh.com/gambar-2.jpg"><?= e((string)setting('wallpaper_urls', '')) ?></textarea>
          <span class="hint">Satu tautan per baris. Hanya alamat <strong>http/https</strong> yang diterima
            (tautan lain diabaikan). Bila kolom ini diisi, gambar bawaan tema tidak dipakai — isi minimal
            dua tautan agar pergantiannya terasa. Kosongkan untuk kembali memakai gambar bawaan.</span></div>
      </div>
      <?php if ($wpContoh): ?>
      <div class="section-title">Contoh gambar yang dipakai sekarang
        (<?= num(count($wpPool)) ?> gambar · <?= $wpCustom ? 'dari tautan Anda' : 'bawaan tema ' . e(wallpaper_categories()[$wpKat]) ?>)</div>
      <div class="flex flex-wrap gap-sm">
        <?php foreach ($wpContoh as $u): ?>
          <img src="<?= e($u) ?>" alt="Contoh gambar latar" loading="lazy"
               style="width:150px;height:84px;object-fit:cover;border-radius:10px;border:1px solid var(--line)">
        <?php endforeach; ?>
      </div>
      <p class="muted small mt-1">Pratinjau memuat <?= num(count($wpContoh)) ?> dari <?= num(count($wpPool)) ?> gambar
        (dimuat langsung dari sumbernya — bila tidak muncul, kemungkinan tautannya sedang tidak dapat diakses).</p>
      <?php else: ?>
        <div class="alert alert-warning mt-2">Belum ada gambar yang dapat dipakai pada tema ini.</div>
      <?php endif; ?>
      <div class="notice mt-2">
        Gambar bawaan berasal dari <strong>Unsplash</strong> dan dipakai lewat tautan langsung ke CDN mereka
        (gratis dipakai, tanpa mengunduh). Karena berasal dari luar, tampilannya bergantung pada koneksi
        internet pengguna — bila ingin sepenuhnya mandiri, isi kolom tautan sendiri dengan gambar dari server Anda.
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Gambar Latar</button>
    </div>
  </form>
</div>

<?php /* CATATAN (ronde 37): dulu di sini ada </div> penutup grid 2 kolom yang
   membungkus Identitas Klinik + Pengaturan Umum. Setelah Pengaturan Umum pindah
   ke Developer Settings, tag penutup itu MENUTUP <main> lebih awal sehingga
   kartu Email/Pembayaran/WhatsApp/Member keluar dari area konten (dijaga uji
   `settings_lock_check.js`). Jangan menambahkan penutup tambahan di sini. */ ?>

<div class="card">
  <div class="card-head"><h3>Email Laporan Otomatis</h3>
    <span><?= mail_configured() ? badge('Siap mengirim', 'green') : badge('Belum dikonfigurasi', 'yellow') ?></span>
  </div>

  <div class="card-body">
    <div class="alert alert-<?= mail_configured() ? 'info' : 'warning' ?>">
      <strong><?= e(mail_status_text()) ?></strong>
      <?php if (!mail_configured()): ?>
        <br>Selama konfigurasi belum lengkap, sistem <strong>tidak</strong> akan mengirim email dan akan menyatakan hal itu apa adanya.
      <?php endif; ?>
    </div>

    <!-- ===== PENTING: keterbatasan jaringan server ===== -->
    <div class="alert alert-warning">
      <strong>Catatan penting soal server ini:</strong> jaringan server <strong>memblokir port SMTP</strong>
      (25, 465, 587), sehingga pengiriman lewat SMTP Gmail <u>tidak dapat</u> dilakukan dari server ini
      (sudah saya uji: koneksi ke <code>smtp.gmail.com</code> timeout). Yang <strong>bisa</strong> dipakai adalah
      penyedia email berbasis <strong>HTTPS API</strong> (port 443 terbuka) — mis. Resend, Brevo, SendGrid, atau Mailgun.
      <br><br>
      Karena pengirim <code>distintech@gmail.com</code> tidak bisa dipakai lewat SMTP di sini, isi kolom
      <strong>Alamat Pengirim</strong> dengan alamat pada domain yang Anda verifikasi di penyedia tersebut
      (mis. <code>laporan@emailklinik.id</code>), lalu isi <strong>Reply-To</strong> dengan
      <code>distintech@gmail.com</code> supaya balasan tetap masuk ke Gmail Anda.
      Bila nanti Anda memindahkan aplikasi ke hosting yang mengizinkan SMTP (mis. cPanel/Hostinger),
      jalur SMTP di bawah sudah siap dipakai dengan Gmail (butuh <em>App Password</em> Gmail).
    </div>

    <?php if ($emailDiag): ?>
      <div class="alert alert-<?= !empty($emailDiag['connect_ok']) ? 'success' : 'error' ?>">
        <strong>Hasil diagnosa (<?= e($emailDiag['mode']) ?>):</strong><br>
        <?= e($emailDiag['connect']) ?><br>
        Pengirim: <?= e((string)$emailDiag['sender']) ?> · Tujuan: <?= e((string)$emailDiag['recipient']) ?>
        · Terkonfigurasi: <?= !empty($emailDiag['configured']) ? 'ya' : 'belum' ?>
      </div>
    <?php endif; ?>

    <?php if ($emailLast): ?>
      <div class="notice mb-2">
        Uji/percobaan terakhir: <strong><?= e(tgl($emailLast['at'], true)) ?></strong> —
        <?= $emailLast['ok'] ? badge('Berhasil', 'green') : badge('Gagal', 'red') ?>
        <div class="small"><?= e($emailLast['msg']) ?></div>
        <?php if (setting('email_last_success_at') !== ''): ?>
          <div class="small">Pengiriman sukses terakhir: <?= e(tgl(setting('email_last_success_at'), true)) ?></div>
        <?php endif; ?>
        <?php if (setting('email_last_sent_period') !== ''): ?>
          <div class="small">Periode laporan terakhir terkirim: <?= e(setting('email_last_sent_period')) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="email">
    <div class="card-body" style="border-top:1px solid var(--line)">
      <div class="section-title" style="margin-top:0">Tujuan &amp; Jadwal</div>
      <div class="form-grid g3">
        <div class="field"><label>Layanan Email Otomatis</label>
          <select class="input" name="email_service_active">
            <option value="0"<?= setting('email_service_active') === '0' ? ' selected' : '' ?>>Nonaktif (hanya kirim manual)</option>
            <option value="1"<?= setting('email_service_active') === '1' ? ' selected' : '' ?>>Aktif (kirim otomatis sesuai jadwal)</option>
          </select>
          <span class="hint">Mengatur pengiriman <strong>otomatis</strong>. Tombol "Kirim Email Uji" dan
            "Kirim Laporan Sekarang" tetap berfungsi meskipun otomatis dimatikan, supaya Anda bisa memverifikasi konfigurasi.</span></div>
        <div class="field"><label>Email Tujuan — Laporan Lengkap (tanpa keuangan)</label>
          <input class="input" name="email_recipient" value="<?= e(setting('email_recipient')) ?>" placeholder="nama@emailklinik.id">
          <span class="hint">Penerima laporan bulanan <strong>tanpa angka keuangan</strong> — untuk
            <strong>staf/dokter</strong> atau alamat bebas. Boleh lebih dari satu, pisahkan dengan koma.
            Isi lampiran: PDF + Excel berisi tabel &amp; grafik penjualan.</span></div>
        <div class="field" style="grid-column:1/-1">
          <div class="section-title" style="margin:0 0 2px">Laporan Keuangan ke Direktur/Owner<?= $lockSystemSettings ? ' <span class="badge badge-yellow">Khusus Super Admin</span>' : '' ?></div>
          <span class="muted small">Bagian ini mengatur siapa yang menerima angka <strong>HPP &amp; laba bersih</strong> —
            karena itu hanya dapat diubah Super Admin.</span>
        </div>
        <div class="field"><label>Email Tujuan — Laporan Lengkap + KEUANGAN</label>
          <input class="input" name="email_finance_recipient"<?= $lockAttr ?>
                 value="<?= e(setting('email_finance_recipient')) ?>" placeholder="direktur@emailklinik.id">
          <span class="hint">Penerima laporan <strong>lengkap + keuangan</strong> (HPP, laba kotor, biaya operasional,
            laba bersih) — khusus <strong>Direktur/Owner</strong>. Boleh lebih dari satu, pisahkan dengan koma.
            Lampiran PDF + Excel memuat lembar &amp; grafik keuangan.</span></div>
        <?= $lockSystemSettings ? '<div style="grid-column:1/-1">' . lock_note(true) . '</div>' : '' ?>
        <div class="field"><label>Kirim Juga Laporan Keuangan?</label>
          <select class="input" name="email_finance_enabled"<?= $lockAttr ?>>
            <option value="0"<?= setting('email_finance_enabled', '0') === '0' ? ' selected' : '' ?>>Tidak — hanya laporan lengkap biasa</option>
            <option value="1"<?= setting('email_finance_enabled', '0') === '1' ? ' selected' : '' ?>>Ya — kirim juga laporan keuangan ke alamat di atas</option>
          </select>
          <span class="hint">Hanya untuk mode laporan <strong>Lengkap</strong>; pengiriman keuangan otomatis memakai
            mode "Laporan Lengkap" agar laba kotor &amp; biaya operasional ikut terhitung.
            Bila kedua alamat sama, hanya SATU email (berisi keuangan) yang dikirim.</span></div>
        <div class="field"><label>Jadwal Pengiriman</label>
          <select class="input" name="email_schedule_mode">
            <option value="auto"<?= setting('email_schedule_mode', 'auto') === 'auto' ? ' selected' : '' ?>>Otomatis (saat aplikasi dibuka)</option>
            <option value="manual"<?= setting('email_schedule_mode') === 'manual' ? ' selected' : '' ?>>Manual saja</option>
          </select></div>
        <div class="field"><label>Tanggal Kirim</label>
          <input class="input" type="number" min="1" max="28" name="email_schedule_day" value="<?= e(setting('email_schedule_day')) ?>">
          <span class="hint">Setiap awal bulan, laporan bulan sebelumnya dikirim.</span></div>
        <div class="field"><label>Jam Kirim (WIB)</label>
          <input class="input" type="time" name="email_schedule_time" value="<?= e(setting('email_schedule_time')) ?>"></div>
        <div class="field"><label>Uji Otomatis</label>
          <span class="hint">Bila "Otomatis" dipilih, sistem memeriksa jadwal setiap kali petugas membuka aplikasi
            dan mengirim laporan bulan sebelumnya satu kali saja (tanpa perlu cron).</span></div>
      </div>
    </div>

    <div class="card-body" style="border-top:1px solid var(--line)">
      <div class="section-title" style="margin-top:0">Pengirim</div>
      <div class="form-grid g3">
        <div class="field"><label>Nama Pengirim</label>
          <input class="input" name="email_sender_name" value="<?= e(setting('email_sender_name')) ?>" placeholder="<?= e(clinic_name()) ?>"></div>
        <div class="field"><label>Alamat Pengirim <span class="req">*</span></label>
          <input class="input" name="email_sender" value="<?= e(setting('email_sender')) ?>" placeholder="laporan@emailklinik.id">
          <span class="hint">Harus alamat pada domain yang <strong>diverifikasi</strong> di penyedia email.</span></div>
        <div class="field"><label>Reply-To (balasan masuk ke sini)</label>
          <input class="input" name="email_reply_to" value="<?= e(setting('email_reply_to')) ?>" placeholder="distintech@gmail.com">
          <span class="hint">Isi <code>distintech@gmail.com</code> agar balasan tetap masuk ke Gmail Anda.</span></div>
      </div>
    </div>

    <div class="card-body" style="border-top:1px solid var(--line)">
      <div class="section-title" style="margin-top:0">Jalur Pengiriman<?= $lockSystemSettings ? ' <span class="badge badge-yellow">Khusus Super Admin</span>' : '' ?></div>
      <?= lock_note($lockSystemSettings) ?>
      <div class="form-grid g3">
        <div class="field"><label>Mode Pengiriman</label>
          <select class="input" name="email_mode" id="emailMode"<?= $lockAttr ?>>
            <option value="auto"<?= setting('email_mode', 'auto') === 'auto' ? ' selected' : '' ?>>Otomatis (API bila diisi, jika tidak SMTP)</option>
            <option value="api"<?= setting('email_mode') === 'api' ? ' selected' : '' ?>>HTTP API (disarankan di server ini)</option>
            <option value="smtp"<?= setting('email_mode') === 'smtp' ? ' selected' : '' ?>>SMTP langsung</option>
          </select></div>
        <div class="field"><label>Penyedia API</label>
          <select class="input" name="email_api_provider" id="emailProvider"<?= $lockAttr ?>>
            <?php foreach (email_providers() as $pk => $pv): ?>
              <option value="<?= e($pk) ?>" data-url="<?= e($pv['url']) ?>"<?= setting('email_api_provider', 'resend') === $pk ? ' selected' : '' ?>><?= e($pv['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>URL Endpoint API</label>
          <input class="input" name="email_api_url" id="emailApiUrl" value="<?= e(setting('email_api_url')) ?>" placeholder="https://api.resend.com/emails"<?= $lockAttr ?>>
          <span class="hint">Kosongkan untuk memakai URL bawaan penyedia yang dipilih.</span></div>
        <div class="field"><label>API Key</label>
          <input class="input" type="password" name="email_api_key" placeholder="<?= setting('email_api_key') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>"<?= $lockAttr ?>></div>
        <div class="field"><label>Domain Mailgun (bila dipakai)</label>
          <input class="input" name="email_api_domain" value="<?= e(setting('email_api_domain')) ?>" placeholder="mg.emailklinik.id"<?= $lockAttr ?>></div>
        <div class="field"><label>&nbsp;</label>
          <div class="notice">Port HTTPS (443) terbuka, jadi jalur ini yang paling mungkin berhasil di server ini.</div></div>
      </div>

      <div class="section-title">SMTP (untuk hosting yang mengizinkan SMTP)<?= $lockSystemSettings ? ' <span class="badge badge-yellow">Khusus Super Admin</span>' : '' ?></div>
      <div class="form-grid g3">
        <div class="field"><label>Host SMTP</label><input class="input" name="smtp_host" value="<?= e(setting('smtp_host')) ?>" placeholder="smtp.gmail.com"<?= $lockAttr ?>></div>
        <div class="field"><label>Port</label><input class="input" name="smtp_port" value="<?= e(setting('smtp_port')) ?>"<?= $lockAttr ?>></div>
        <div class="field"><label>Keamanan</label>
          <select class="input" name="smtp_secure"<?= $lockAttr ?>>
            <?php foreach (['tls' => 'TLS (STARTTLS, 587)', 'ssl' => 'SSL (465)', 'none' => 'Tanpa enkripsi'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= setting('smtp_secure') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Pengguna SMTP</label><input class="input" name="smtp_user" value="<?= e(setting('smtp_user')) ?>" placeholder="distintech@gmail.com"<?= $lockAttr ?>></div>
        <div class="field"><label>Kata Sandi / App Password</label>
          <input class="input" type="password" name="smtp_pass" placeholder="<?= setting('smtp_pass') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>"<?= $lockAttr ?>>
          <span class="hint">Gmail mewajibkan <em>App Password</em> (bukan kata sandi biasa).</span></div>
      </div>

      <div class="section-title">Email Struk ke Pasien</div>
      <p class="muted mb-2">Subjek &amp; isi email yang dipakai tombol <strong>Kirim Struk ke Email</strong> pada detail transaksi
        (setelah kasir selesai input order) dan Riwayat Order. Isi otomatis dari data transaksi; variabel yang tersedia:
        <code>{nama}</code> <code>{invoice}</code> <code>{tanggal}</code> <code>{total}</code>
        <code>{subtotal}</code> <code>{diskon}</code> <code>{metode}</code> <code>{cabang}</code> <code>{klinik}</code>
        <code>{link}</code>.</p>
      <div class="form-grid g2">
        <div class="field"><label>Kirim Struk Otomatis ke Email Pasien</label>
          <select class="input" name="email_receipt_auto">
            <option value="0"<?= setting('email_receipt_auto') !== '1' ? ' selected' : '' ?>>Tidak — kirim manual lewat tombol Email</option>
            <option value="1"<?= setting('email_receipt_auto') === '1' ? ' selected' : '' ?>>Ya — kirim begitu transaksi disimpan</option>
          </select>
          <span class="hint">Hanya berjalan bila <strong>layanan email sudah dikonfigurasi</strong> dan pasien punya email.
            Bila layanan email belum diisi, sistem memberi tahu apa adanya dan struk tetap bisa dikirim manual.</span></div>
        <div class="field"><label>Masa Berlaku Tautan Unduh Struk (hari)</label>
          <input class="input" type="text" inputmode="numeric" name="receipt_link_days"
                 value="<?= e(setting('receipt_link_days', '30')) ?>">
          <span class="hint">Digunakan untuk variabel <code>{link}</code> — tautan unduh struk PDF (juga dipakai tombol
            "Buka di Aplikasi Email" pada HP/PC). Tautan bertanda tangan &amp; otomatis kedaluwarsa.</span></div>
        <div class="field" style="grid-column:1/-1"><label>Alamat Publik Aplikasi <span class="muted small">(opsional)</span></label>
          <input class="input" name="public_base_url" value="<?= e(setting('public_base_url', '')) ?>"
                 placeholder="https://hudafa.vibecoder.co.id/naveena">
          <span class="hint">Dipakai untuk menyusun tautan struk pada email. Kosongkan saja — sistem otomatis memakai alamat
            yang benar (termasuk bila Anda memasang domain sendiri). Isi hanya bila tautan perlu dipaksa ke alamat tertentu.</span></div>
        <?php
        /* Nilai awal kolom: bila pengaturan masih kosong, tampilkan template
           BAWAAN yang sudah terisi supaya bisa langsung diedit (permintaan
           pemilik klinik) — bukan kolom kosong tanpa contoh. */
        $tplSubject = setting('email_receipt_subject');
        if (trim($tplSubject) === '') $tplSubject = receipt_email_default_subject();
        $tplBody = setting('email_receipt_body');
        if (trim($tplBody) === '') $tplBody = receipt_email_default_body();
        ?>
        <div class="field" style="grid-column:1/-1"><label>Subjek Email Struk</label>
          <input class="input" name="email_receipt_subject" value="<?= e($tplSubject) ?>"></div>
        <div class="field" style="grid-column:1/-1"><label>Isi Email Struk <span class="muted small">(sudah terisi template bawaan — boleh diedit)</span></label>
          <textarea class="input" name="email_receipt_body" rows="10"><?= e($tplBody) ?></textarea>
          <span class="hint">Isi bawaan sudah menyertakan tautan <code>{link}</code>; bila Anda menulis isi sendiri,
            sertakan <code>{link}</code> agar pasien tetap bisa mengunduh struk PDF-nya.</span></div>
      </div>
    </div>

    <?php /* Tombol "Kembali ke Default" — di SEBELAH KIRI tombol simpan (permintaan
             pemilik). Tombol berada di form utama tetapi diarahkan ke form terpisah
             lewat atribut `form=` (form bersarang tidak diperbolehkan). Konfirmasi
             2 tahap mencegah terpencet tanpa sengaja. */ ?>
    <?php $tplEmailBeda = template_default_changed('email'); ?>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn" type="submit" form="emailResetForm"<?= $tplEmailBeda ? '' : ' disabled title="Teks sudah sama dengan default"' ?>>
        <?= icon('refresh') ?> Kembali ke Default
      </button>
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Konfigurasi Email</button>
    </div>
      </form>
  <?php /* Formulir terpisah untuk mengembalikan teks email ke default. */ ?>
  <form method="post" id="emailResetForm" data-heavy-kind="reset"
        data-heavy-confirm="KEMBALIKAN TEKS EMAIL KE DEFAULT"
        data-heavy-warning="Subjek &amp; isi email struk akan dikembalikan ke template DEFAULT (bakuannya). Perubahan yang Anda tulis pada kedua kolom itu akan DIGANTI. Template lain pada kartu ini (tujuan, jadwal, pengirim, tombol struk otomatis) TIDAK berubah."
        data-heavy-confirm2="PERINGATAN KEDUA (terakhir): kembalikan teks email ke default sekarang?">
    <?= csrf_field() ?><input type="hidden" name="action" value="email_reset_default">
  </form>
  <div class="card-body" style="border-top:1px solid var(--line)">
    <div class="flex flex-wrap gap-lg mb-2">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="email_diag">
        <button class="btn" type="submit"><?= icon('shield') ?> Diagnosa Jalur Email</button>
      </form>
      <form method="post" class="flex gap-sm" style="align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="test_email">
        <div class="field"><label>Kirim Email Uji Ke</label>
          <input class="input input-sm" name="to" value="<?= e(setting('email_recipient')) ?>"></div>
        <label class="check"><input type="checkbox" name="with_pdf" value="1" checked> <span class="small">sertakan lampiran PDF</span></label>
        <button class="btn btn-sm btn-primary" type="submit">Kirim Email Uji</button>
        <?php $ujiInfo = email_send_history('Uji ' . strtolower((string)setting('email_recipient')));
              if ($ujiInfo['count'] > 0): ?>
          <span class="small muted">Terakhir dikirim <?= e(tgl((string)$ujiInfo['last'], true)) ?>
            (<?= num($ujiInfo['count']) ?>×) — akan dikonfirmasi bila dikirim lagi.</span>
        <?php endif; ?>
      </form>
    </div>
    <form method="post" class="flex gap-sm flex-wrap" style="align-items:flex-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="send_report">
      <div class="field"><label>Kirim Laporan Bulan</label>
        <input class="input input-sm" type="month" name="month" value="<?= e($lastMonth) ?>"></div>
      <button class="btn btn-sm btn-primary" type="submit">Kirim Laporan Sekarang</button>
      <span class="muted small">Dikirim dengan <strong>2 lampiran</strong>: PDF lengkap + grafik, dan Excel (.xlsx) lengkap + grafik.
        <?php $lapInfo = email_send_history('Laporan ' . $lastMonth);
              if ($lapInfo['count'] > 0): ?>
          <br>Laporan <?= e($lastMonth) ?> sudah dikirim <?= num($lapInfo['count']) ?>×
          (terakhir <?= e(tgl((string)$lapInfo['last'], true)) ?>) — akan dikonfirmasi bila dikirim lagi.
        <?php endif; ?></span>
    </form>
  </div>

  <div class="card-body" style="border-top:1px solid var(--line)">
    <div class="section-title" style="margin-top:0">Pratinjau Isi Email (<?= e($preview['month']) ?>)</div>
    <div class="notice" style="background:#fff;max-height:420px;overflow:auto">
      <?= $preview['html'] ?>
    </div>
    <?php if ($previewPdf && empty($previewPdf['error'])): ?>
      <p class="small muted mt-1">Lampiran email siap — <strong>PDF</strong>: <?= e($previewPdf['filename']) ?>
        (<?= num(round(strlen($previewPdf['bytes']) / 1024, 1), 1) ?> KB, <?= num((int)($previewPdf['charts'] ?? 0)) ?> grafik)<?php
        $px = null; try { $px = monthly_report_xlsx($lastMonth); } catch (Throwable $e) { $px = ['error' => $e->getMessage()]; }
        if ($px && empty($px['error'])): ?> &nbsp;+&nbsp; <strong>Excel</strong>: <?= e($px['filename']) ?>
        (<?= num(round(strlen($px['bytes']) / 1024, 1), 1) ?> KB, <?= num((int)($px['charts'] ?? 0)) ?> grafik)<?php endif; ?>.</p>
    <?php elseif ($previewPdf): ?>
      <p class="small mt-1" style="color:var(--danger)">Lampiran gagal dibuat: <?= e($previewPdf['error']) ?></p>
    <?php endif; ?>

    <div class="section-title">Riwayat Pengiriman Email</div>
    <?php if (!$logs): ?>
      <p class="muted">Belum ada percobaan pengiriman email.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Waktu</th><th>Tujuan</th><th>Periode</th><th>Status</th><th>Pesan</th></tr></thead>
        <tbody><?php foreach ($logs as $l): ?>
          <tr><td class="small"><?= e(tgl($l['created_at'], true)) ?></td><td class="small"><?= e($l['recipient']) ?></td>
            <td class="small"><?= e($l['period']) ?></td>
            <td><?= badge($l['status'] === 'sent' ? 'Terkirim' : 'Gagal', $l['status'] === 'sent' ? 'green' : 'red') ?></td>
            <td class="small"><?= e($l['message']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<?php
/* ---------------- PEMBAYARAN ----------------
   Kartu sendiri supaya rapi: data rekening/QRIS klinik (jalur manual) dan
   kunci API payment gateway (jalur otomatis). Tanpa kredensial, jalur otomatis
   nonaktif dan sistem memakai jalur manual — statusnya ditulis apa adanya. */
$payInfo = pay_clinic_info();
?>
<div class="card" id="pembayaran">
  <div class="card-head">
    <h3>Pembayaran (Transfer, QRIS &amp; Payment Gateway)</h3>
    <span class="muted"><?= e(pay_gateway_status_text()) ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="payment">
    <div class="card-body">
      <div class="section-title" style="margin-top:0">Data Rekening &amp; QRIS Klinik (jalur manual)</div>
      <p class="muted mb-2">Dipakai saat kasir memilih metode <strong>Transfer</strong> atau <strong>QRIS</strong>:
        data ini muncul di layar kasir dan pada halaman pembayaran pasien.</p>
      <div class="form-grid g2">
        <div class="field"><label>Nama Bank</label>
          <input class="input" name="pay_bank_name" value="<?= e($payInfo['bank_name']) ?>" placeholder="mis. BCA / Mandiri / BRI"></div>
        <div class="field"><label>Nomor Rekening</label>
          <input class="input" name="pay_bank_account" value="<?= e($payInfo['bank_account']) ?>" placeholder="mis. 1234567890"></div>
        <div class="field"><label>Nama Pemilik Rekening</label>
          <input class="input" name="pay_bank_holder" value="<?= e($payInfo['bank_holder']) ?>" placeholder="mis. <?= e(clinic_name()) ?>"></div>
        <div class="field"><label>Catatan untuk Pasien</label>
          <input class="input" name="pay_note" value="<?= e($payInfo['note']) ?>" placeholder="mis. Mohon kirim bukti transfer"></div>
      </div>

      <div class="section-title">Kode Unik &amp; Masa Berlaku</div>
      <div class="form-grid g2">
        <div class="field"><label>Kode Unik 3 Digit</label>
          <select class="input" name="pay_unique_code">
            <option value="1"<?= setting('pay_unique_code', '1') === '1' ? ' selected' : '' ?>>Ya — total transfer ditambah 3 digit unik (mis. 123)</option>
            <option value="0"<?= setting('pay_unique_code', '1') !== '1' ? ' selected' : '' ?>>Tidak</option>
          </select>
          <span class="hint">Kode unik memudahkan mencocokkan nominal transfer dengan mutasi rekening/QRIS.</span></div>
        <div class="field"><label>Masa Berlaku Pembayaran Otomatis (menit)</label>
          <input class="input" type="text" inputmode="numeric" name="pay_expire_minutes"
                 value="<?= e(setting('pay_expire_minutes', '15')) ?>">
          <span class="hint">5–180 menit. Berlaku untuk QRIS/VA otomatis dari gateway.</span></div>
      </div>

      <div class="section-title">Payment Gateway (pembayaran OTOMATIS)<?= $lockSystemSettings ? ' <span class="badge badge-yellow">Khusus Super Admin</span>' : '' ?></div>
      <?= lock_note($lockSystemSettings) ?>
      <div class="form-grid g2">
        <div class="field"><label>Gateway</label>
          <select class="input" name="pay_gateway"<?= $lockAttr ?>>
            <?php foreach (pay_gateways() as $gk => $gl): ?>
              <option value="<?= e($gk) ?>"<?= pay_gateway_key() === $gk ? ' selected' : '' ?>><?= e($gl) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Lingkungan</label>
          <select class="input" name="pay_gateway_env"<?= $lockAttr ?>>
            <option value="sandbox"<?= pay_gateway_env() === 'sandbox' ? ' selected' : '' ?>>Sandbox (uji coba)</option>
            <option value="production"<?= pay_gateway_env() === 'production' ? ' selected' : '' ?>>Production (sungguhan)</option>
          </select></div>
        <div class="field" style="grid-column:1/-1"><label>Server Key / Secret Key</label>
          <input class="input" type="password" name="pay_gateway_server_key"
                 placeholder="<?= setting('pay_gateway_server_key') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>"<?= $lockAttr ?>>
          <span class="hint">Midtrans: <em>Server Key</em> dari Dashboard → Settings → Access Keys.
            Xendit: <em>Secret Key</em> dari Dashboard → Settings → API Keys. Disimpan di server, tidak pernah ditampilkan ke browser pasien.</span></div>
        <div class="field"><label>Client Key <span class="muted small">(opsional, Midtrans)</span></label>
          <input class="input" type="password" name="pay_gateway_client_key"
                 placeholder="<?= setting('pay_gateway_client_key') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>"<?= $lockAttr ?>></div>
        <div class="field"><label>Merchant ID <span class="muted small">(opsional)</span></label>
          <input class="input" name="pay_gateway_merchant_id" value="<?= e(setting('pay_gateway_merchant_id')) ?>"<?= $lockAttr ?>></div>
        <div class="field" style="grid-column:1/-1"><label>URL API Kustom <span class="muted small">(opsional)</span></label>
          <input class="input" name="pay_gateway_base_url" value="<?= e(setting('pay_gateway_base_url')) ?>"
                 placeholder="kosongkan untuk memakai alamat resmi Midtrans/Xendit"<?= $lockAttr ?>>
          <span class="hint">Isi hanya bila memakai endpoint khusus (mis. server uji internal). Format:
            <code>https://alamat-server</code> — path <code>/v2/charge</code> dan <code>/v2/&lt;ref&gt;/status</code> ditambahkan otomatis.</span></div>
      </div>
      <div class="notice mt-2">
        <strong>Status:</strong> <?= e(pay_gateway_status_text()) ?>
        <div class="small muted mt-1">URL notifikasi (daftarkan di dashboard gateway):
          <code><?= e(pay_webhook_url()) ?></code></div>
        <?php if (pay_gateway_key() !== 'none'): ?>
          <div class="small mt-1">Bila kunci API belum diisi, kasir tetap dapat memakai jalur manual
            (transfer/QRIS klinik + kode unik) — sistem tidak pernah mengaku "otomatis" bila belum siap.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit">Simpan Pengaturan Pembayaran</button>
    </div>
  </form>

  <div class="card-body" style="border-top:1px solid var(--line)">
    <div class="section-title" style="margin-top:0">Gambar QRIS Klinik</div>
    <div class="flex flex-wrap gap-lg" style="align-items:flex-start">
      <div>
        <?php if ($payInfo['qris_file'] !== ''): ?>
          <img src="qris.php?v=<?= e(substr(md5($payInfo['qris_file']), 0, 6)) ?>" alt="QRIS klinik"
               style="max-width:220px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:6px">
        <?php else: ?>
          <div class="notice" style="max-width:260px">Belum ada gambar QRIS. Unggah gambar QRIS statis
            dari bank/aplikasi pembayaran agar pasien bisa memindai langsung.</div>
        <?php endif; ?>
      </div>
      <div>
        <form method="post" enctype="multipart/form-data" class="flex gap-sm flex-wrap" style="align-items:flex-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="qris">
          <div class="field"><label>Unggah Gambar QRIS</label>
            <input class="input input-sm" type="file" name="qris" accept="image/png,image/jpeg,image/webp" required></div>
          <button class="btn btn-sm btn-primary" type="submit">Unggah QRIS</button>
        </form>
        <?php if ($payInfo['qris_file'] !== ''): ?>
          <form method="post" class="mt-2">
            <?= csrf_field() ?><input type="hidden" name="action" value="qris_delete">
            <button class="btn btn-sm btn-danger" type="submit"
                    data-confirm="Hapus gambar QRIS klinik?">Hapus Gambar QRIS</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>



<div class="card" id="wa">
  <div class="card-head"><h3>WhatsApp</h3>
    <span><?= wa_api_configured() ? badge('API terkonfigurasi', 'green') : badge('Deep link (belum pakai API)', 'yellow') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="wa">
    <div class="card-body">
      <div class="alert alert-<?= wa_api_configured() ? 'info' : 'warning' ?>">
        <strong><?= e(wa_receipt_status_text()) ?></strong>
        <?php if (!wa_api_configured()): ?>
          <br>Pengiriman struk memakai deep link <strong>wa.me</strong> dari nomor pengirim di bawah. WhatsApp <strong>tidak
          mengizinkan lampiran dokumen lewat tautan</strong> — jadi sistem juga mengunduh struk PDF dan (opsional)
          menyertakan tautannya pada pesan. Petugas cukup menekan kirim di WhatsApp.
        <?php endif; ?>
      </div>
      <div class="form-grid g3">
        <div class="field"><label>Nomor WhatsApp Pengirim (nomor klinik)</label>
          <input class="input" name="wa_sender_number" value="<?= e(setting('wa_sender_number')) ?>" placeholder="6281xxxxxxxxx">
          <span class="hint">Nomor resmi yang aktif di WhatsApp Web/aplikasi klinik.</span></div>
        <div class="field"><label>Status WhatsApp API</label>
          <select class="input" name="wa_api_active">
            <option value="0"<?= setting('wa_api_active') === '0' ? ' selected' : '' ?>>Nonaktif</option>
            <option value="1"<?= setting('wa_api_active') === '1' ? ' selected' : '' ?>>Aktif</option>
          </select></div>
        <div class="field"><label>Default: Sertakan Tautan PDF Struk</label>
          <select class="input" name="wa_receipt_link">
            <option value="1"<?= setting('wa_receipt_link') === '1' ? ' selected' : '' ?>>Ya (tercentang otomatis)</option>
            <option value="0"<?= setting('wa_receipt_link') === '0' ? ' selected' : '' ?>>Tidak</option>
          </select></div>
        <div class="field"><label>Nomor Pengirim API</label>
          <input class="input" name="wa_api_sender" value="<?= e(setting('wa_api_sender')) ?>" placeholder="6281xxxxxxxxx"></div>
        <div class="field" style="grid-column:1/-1"><label>URL Endpoint API</label>
          <input class="input" name="wa_api_url" value="<?= e(setting('wa_api_url')) ?>" placeholder="https://provider.example/send?to={number}&text={message}">
          <span class="hint">Placeholder <code>{number}</code> &amp; <code>{message}</code>. Bila URL tidak memuat <code>{message}</code>, sistem mengirim payload JSON.</span></div>
        <div class="field" style="grid-column:1/-1"><label>API Token (opsional)</label>
          <input class="input" type="password" name="wa_api_token" placeholder="<?= setting('wa_api_token') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>"></div>
        <div class="field" style="grid-column:1/-1"><label>Template Pesan Reservasi</label>
          <textarea class="input" name="wa_template" rows="6"><?= e(setting('wa_template')) ?></textarea>
          <span class="hint">Variabel: {nama} {klinik} {cabang} {tanggal} {jam} {treatment} {dokter}
            — <code>{klinik}</code> otomatis menjadi <strong><?= e(clinic_name()) ?></strong> (nama klinik dari
            Pengaturan → Nama Klinik).</span></div>
        <div class="field"><label>Pengingat Jadwal ke Dokter</label>
          <select class="input" name="wa_doctor_active">
            <option value="1"<?= setting('wa_doctor_active', '1') === '1' ? ' selected' : '' ?>>Aktif (tombol WA Dokter muncul)</option>
            <option value="0"<?= setting('wa_doctor_active') === '0' ? ' selected' : '' ?>>Nonaktif</option>
          </select>
          <span class="hint">Berlaku untuk <strong>dokter saja</strong> — terapis tidak dikirimi pengingat (sesuai ketentuan klinik).</span></div>
        <div class="field" style="grid-column:1/-1"><label>&nbsp;</label>
          <div class="notice">Nomor WhatsApp dokter diambil dari menu <a href="staff.php">Dokter &amp; Terapis</a>.
            Bila nomornya kosong, tombol akan menampilkan peringatan agar dilengkapi lebih dahulu.</div></div>
        <div class="field" style="grid-column:1/-1"><label>Template Pesan Pengingat ke Dokter</label>
          <textarea class="input" name="wa_template_doctor" rows="6"><?= e(setting('wa_template_doctor')) ?></textarea>
          <span class="hint">Variabel: {dokter} {nama} {klinik} {cabang} {tanggal} {jam} {treatment}
            — <code>{klinik}</code> otomatis menjadi <strong><?= e(clinic_name()) ?></strong>.</span></div>
        <div class="field" style="grid-column:1/-1"><label>Template Pesan Struk</label>
          <textarea class="input" name="wa_receipt_template" rows="8"><?= e(setting('wa_receipt_template')) ?></textarea>
          <span class="hint">Variabel: {nama} {pasien} {klinik} {cabang} {invoice} {tanggal} {total} {metode} {alamat} {link}
            — <code>{klinik}</code> otomatis menjadi <strong><?= e(clinic_name()) ?></strong>.</span></div>
      </div>
    </div>
    <?php /* Lihat catatan pada kartu Email: tombol default di sebelah kiri tombol simpan,
             memakai form terpisah + konfirmasi 2 tahap. */ ?>
    <?php $tplWaBeda = template_default_changed('wa'); ?>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn" type="submit" form="waResetForm"<?= $tplWaBeda ? '' : ' disabled title="Teks sudah sama dengan default"' ?>>
        <?= icon('refresh') ?> Kembali ke Default
      </button>
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Konfigurasi WhatsApp</button>
    </div>
  </form>
  <?php /* Formulir terpisah untuk mengembalikan template WhatsApp ke default. */ ?>
  <form method="post" id="waResetForm" data-heavy-kind="reset"
        data-heavy-confirm="KEMBALIKAN TEMPLATE WHATSAPP KE DEFAULT"
        data-heavy-warning="Tiga template WhatsApp (reservasi, pengingat dokter, struk) akan dikembalikan ke template DEFAULT (bakuannya). Tulisan yang Anda ubah pada ketiganya akan DIGANTI. Pengaturan lain pada kartu ini (nomor pengirim, API, sertakan tautan PDF) TIDAK berubah."
        data-heavy-confirm2="PERINGATAN KEDUA (terakhir): kembalikan template WhatsApp ke default sekarang?">
    <?= csrf_field() ?><input type="hidden" name="action" value="wa_reset_default">
  </form>
</div>

<?php
$mLevels = member_levels();
$mOn = member_card_enabled();
$mPeriod = member_period_info();
$mAuto = member_activate_amount();
$mMin = member_min_transaction();
$mBg = member_card_bg_path();
$mCv = member_card_canvas();
?>
<div class="card" id="member">
  <div class="card-head">
    <h3>Kartu Member &amp; Diskon Berlevel</h3>
    <span><?= $mOn ? badge('Aktif', 'green') : badge('Nonaktif', 'gray') ?> · <?= num(count($mLevels)) ?> level</span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="member">
    <div class="card-body">
      <p class="muted">Pasien dapat memiliki <strong>kartu member digital</strong> (dapat diunduh &amp; dicetak).
        Saat petugas memilih <em>"Ada kartu member"</em> di Order Baru, potongan dihitung
        <strong>otomatis</strong> dari level member &amp; cakupan barang di bawah ini.</p>

      <div class="form-grid g2">
        <div class="field"><label>Fitur Kartu Member</label>
          <select class="input" name="member_card_active">
            <option value="1"<?= $mOn ? ' selected' : '' ?>>Aktif — semua ketentuan member berlaku</option>
            <option value="0"<?= $mOn ? '' : ' selected' ?>>Nonaktif — diskon &amp; kartu tidak ditampilkan</option>
          </select>
          <span class="hint">Bila nonaktif: Order Baru tanpa pilihan kartu, diskon member tidak dihitung, dan
            halaman pasien memberi tahu pasien bahwa fitur member sedang tidak aktif.</span></div>
        <div class="field"><label>Aktif Otomatis bila Transaksi ≥ (Rp)</label>
          <input class="input" type="text" inputmode="numeric" name="member_activate_amount" value="<?= e((string)(int)$mAuto) ?>">
          <span class="hint">Satu transaksi yang mencapai nilai ini membuat kartu member <strong>langsung aktif</strong>
            pada level awal, dan potongan member langsung berlaku pada transaksi tersebut. Isi 0 untuk mematikan.</span></div>
        <div class="field"><label>Diskon Berlaku bila Nilai Transaksi ≥ (Rp)</label>
          <input class="input" type="text" inputmode="numeric" name="member_min_transaction" value="<?= e((string)(int)$mMin) ?>">
          <span class="hint">Benefit member aktif: transaksi di bawah nilai ini belum mendapat potongan.</span></div>
        <div class="field"><label>Barang yang Mendapat Diskon</label>
          <select class="input" name="member_discount_scope">
            <?php foreach (['both' => 'Treatment & Skincare', 'treatment' => 'Treatment saja', 'skincare' => 'Skincare saja'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= member_scope() === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Dasar diskon hanya nilai barang pada cakupan ini (mis. "Treatment saja" → skincare tidak ikut dipotong).</span></div>
        <div class="field"><label>Periode Akumulasi Member</label>
          <select class="input" name="member_period_years">
            <?php foreach (member_period_options() as $pk => $pv): ?>
              <option value="<?= (int)$pk ?>"<?= member_period_years() === (int)$pk ? ' selected' : '' ?>><?= e($pv) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Periode &amp; reset berlaku <strong>bersama untuk semua member</strong> dan selalu jatuh pada
            <strong>1 Januari</strong>. Pilih "Tanpa periode" bila akumulasi tidak ingin pernah direset.
            <?php if ($mPeriod['start'] !== ''): ?>
              <br>Periode berjalan: <strong><?= e(tglIndo($mPeriod['start'])) ?> s.d. <?= e(tglIndo($mPeriod['end'])) ?></strong>
              · reset berikutnya <strong><?= e(tglIndo($mPeriod['next_reset'])) ?></strong>.
            <?php endif; ?></span></div>
        <div class="field"><label>Bila Periode Berakhir, Level Turun</label>
          <select class="input" name="member_downgrade_steps">
            <?php foreach ([0 => '0 tingkat — level tidak ikut turun (hanya akumulasi mulai dari nol)', 1 => '1 tingkat (mis. Diamond → Platinum)', 2 => '2 tingkat (mis. Diamond → Gold)', 3 => '3 tingkat (mis. Diamond → Silver)'] as $sk => $sv): ?>
              <option value="<?= (int)$sk ?>"<?= member_downgrade_steps() === (int)$sk ? ' selected' : '' ?>><?= e($sv) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Saat periode berakhir, level setiap member turun sebanyak ini (paling jauh sampai level
            terendah), dan akumulasi mulai dari nol. Level akan naik lagi otomatis bila akumulasi periode baru
            sudah melewati ambangnya. Nomor member &amp; kartu <strong>tidak berubah</strong>.</span></div>
        <div class="field"><label>Catatan pada Kartu</label>
          <input class="input" name="member_card_note" value="<?= e(setting('member_card_note')) ?>">
          <span class="hint">Tercetak di sisi belakang kartu member.</span></div>
        <div class="field"><label>Harga Beli Member (Rp) <span class="hint-inline">opsional</span></label>
          <input class="input" type="text" inputmode="numeric" name="member_card_price" value="<?= e((string)(int)setting('member_card_price', '0')) ?>">
          <span class="hint">Hanya informasi untuk petugas.</span></div>
      </div>

      <div class="section-title">Level Member (naik otomatis dari akumulasi transaksi per periode)</div>
      <p class="muted mb-2">Level dihitung dari <strong>akumulasi nilai transaksi pada periode di atas</strong>
        (<?= e($mPeriod['label']) ?>). Saat periode berakhir, akumulasi mulai dari nol
        <?= member_downgrade_steps() > 0
            ? 'dan level setiap member turun <strong>' . num(member_downgrade_steps()) . ' tingkat</strong>'
            : 'dan level member <strong>tidak</strong> ikut turun' ?> — nomor member &amp; kartu tidak berubah.
        Ambang level terendah sekaligus menjadi jalur pembukaan kartu bagi pasien yang belum punya.</p>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th style="width:26%">Nama level</th><th style="width:16%">Diskon (%)</th><th style="width:32%">Akumulasi minimal per periode (Rp)</th><th>Keterangan</th></tr></thead>
          <tbody>
          <?php foreach ($mLevels as $i => $l): ?>
            <tr>
              <td><input type="hidden" name="level_key[]" value="<?= e($l['key']) ?>">
                <input class="input input-sm" name="level_label[]" value="<?= e($l['label']) ?>"></td>
              <td><input class="input input-sm" type="text" inputmode="decimal" name="level_pct[]" value="<?= e(qty_text($l['pct'])) ?>"></td>
              <td><input class="input input-sm" type="text" inputmode="numeric" name="level_min[]" value="<?= e((string)(int)$l['min_year']) ?>"></td>
              <td class="small muted"><?= $i === 0 ? 'Level awal untuk kartu baru' : 'Level ' . num($i + 1) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php for ($k = 0; $k < 2; $k++): ?>
            <tr>
              <td><input type="hidden" name="level_key[]" value=""><input class="input input-sm" name="level_label[]" placeholder="mis. Member Titanium"></td>
              <td><input class="input input-sm" type="text" inputmode="decimal" name="level_pct[]" placeholder="mis. 25"></td>
              <td><input class="input input-sm" type="text" inputmode="numeric" name="level_min[]" placeholder="mis. 100000000"></td>
              <td class="small muted">baris kosong diabaikan</td>
            </tr>
          <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <div class="notice mt-2">
        <strong>Ringkasan aturan aktif:</strong> <?= e(member_rules_text()) ?>.
        Diskon selalu dihitung ulang di server dari data transaksi (tidak bisa dimanipulasi dari halaman).
        Menyimpan pengaturan ini juga <strong>menyinkronkan ulang level seluruh member</strong>.
      </div>
      <?php $mRoll = json_decode((string)setting('member_rollover_result', ''), true); ?>
      <?php if (is_array($mRoll)): ?>
        <div class="alert alert-info mt-2">
          <strong>Reset periode terakhir:</strong> <?= e(tgl((string)($mRoll['waktu'] ?? ''), true)) ?> —
          periode baru mulai <?= num((int)($mRoll['periode_baru'] ?? 0)) ?> (sebelumnya <?= num((int)($mRoll['periode_lama'] ?? 0)) ?>),
          <strong><?= num((int)($mRoll['berubah'] ?? 0)) ?></strong> dari <?= num((int)($mRoll['anggota'] ?? 0)) ?> member turun level
          (<?= num((int)($mRoll['steps'] ?? 0)) ?> tingkat) dan akumulasi mulai dari nol.
          Tercatat di Audit Log sebagai "Reset Periode Kartu Member".
        </div>
      <?php endif; ?>
      <?php if (!$mOn): ?>
        <div class="alert alert-warning mt-2">Fitur kartu member sedang <strong>nonaktif</strong> — pilihan kartu
          tidak muncul di Order Baru dan halaman pasien menampilkan pemberitahuan ke pasien.</div>
      <?php endif; ?>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('star') ?> Simpan Aturan Kartu Member</button>
    </div>
  </form>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="member_bg">
    <div class="card-body" style="border-top:1px solid var(--line)">
      <div class="section-title" style="margin-top:0">Background Kartu Member</div>
      <p class="muted mb-2">Unggah gambar background kartu (disarankan didominasi <strong>putih</strong> agar tulisan
        tetap terbaca). Gambar otomatis dikompres dan <strong>dipotong <?= num($mCv['w']) ?>×<?= num($mCv['h']) ?> px</strong>
        (rasio kartu standar 85,6 × 54 mm) sehingga ukuran/rasio gambar apa pun tidak akan gepeng.</p>
      <div class="flex flex-wrap gap-lg">
        <div style="min-width:230px">
          <?php if ($mBg !== ''): ?>
            <img src="member_bg.php?t=<?= time() ?>" alt="Background kartu" style="max-width:280px;border:1px solid var(--line);border-radius:12px">
            <div class="small muted mt-1"><?= num($mCv['w']) ?>×<?= num($mCv['h']) ?> px ·
              <?= num(round(((int)@filesize($mBg)) / 1024, 1), 1) ?> KB</div>
          <?php else: ?>
            <div class="notice">Belum ada background — kartu memakai warna tema sistem.</div>
          <?php endif; ?>
        </div>
        <div class="grow">
          <div class="field"><label>Pilih Gambar</label>
            <input class="input" type="file" name="bg" accept="image/*"></div>
          <div class="flex gap-sm mt-2">
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?> Unggah Background</button>
          </div>
        </div>
      </div>
    </div>
  </form>
  <?php if (setting('member_card_bg_file') !== ''): ?>
    <form method="post" data-confirm="Hapus background kartu member?">
      <?= csrf_field() ?><input type="hidden" name="action" value="member_bg_remove">
      <div class="card-body" style="border-top:1px solid var(--line)">
        <button class="btn btn-sm btn-danger" type="submit">Hapus Background</button>
      </div>
    </form>
  <?php endif; ?>
</div>
<script>
/* ============================================================================
 * PEMILIH KELUARGA WARNA (ronde 35)
 *
 *  1. Tombol keluarga di ATAS daftar tema menyaring baris keluarga yang tampil,
 *     sehingga pemilik tidak perlu menggulir jauh mencari warna tertentu.
 *  2. "Semua" mengembalikan seluruh daftar.
 *  3. Urutan keluarga TIDAK diubah (tetap Merah → … → Fuchsia) supaya daftar tetap
 *     dapat diprediksi; bila penyaringan menyembunyikan keluarga tema yang sedang
 *     dipakai, catatan di bawah pemilih memberi tombol pintas menuju keluarga itu.
 *  4. Tombol memakai type="button" sehingga tidak mengirim form tema.
 * ========================================================================== */
(function () {
  var picker = document.getElementById('themeFamPicker');
  var list = document.getElementById('themePicker');
  var note = document.getElementById('themeFamNote');
  if (!picker || !list || !note) return;
  var btns = Array.prototype.slice.call(picker.querySelectorAll('.fam-btn'));
  var rows = Array.prototype.slice.call(list.querySelectorAll('.theme-family'));
  var aktif = list.querySelector('.theme-card.active');
  var famAktif = aktif ? (aktif.closest('.theme-family') || {}).getAttribute('data-fam') : '';

  var labelKeluarga = function (fam) {
    var b = btns.filter(function (x) { return x.getAttribute('data-fam') === fam; })[0];
    return b ? b.textContent.replace(/\s*\d+\s*$/, '').trim() : fam;
  };
  var jumlahTema = function (fam) {
    var n = 0;
    rows.forEach(function (r) {
      if (fam !== '__all' && r.getAttribute('data-fam') !== fam) return;
      n += r.querySelectorAll('.theme-card').length;
    });
    return n;
  };
  var terapkan = function (fam) {
    rows.forEach(function (r) {
      r.style.display = (fam === '__all' || r.getAttribute('data-fam') === fam) ? '' : 'none';
    });
    btns.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-fam') === fam); });
    if (fam === '__all') {
      note.innerHTML = 'Pilih keluarga warna untuk mempersempit daftar, atau '
        + '<strong>Semua</strong> untuk menampilkan seluruh tema.';
      return;
    }
    var html = 'Menampilkan keluarga <strong>' + labelKeluarga(fam) + '</strong> ('
      + jumlahTema(fam) + ' tema). Klik <strong>Semua</strong> untuk melihat semua tema.';
    if (famAktif && famAktif !== fam) {
      html += ' <button type="button" class="fam-btn" data-fam="' + famAktif + '">'
        + 'Tema aktif: ' + labelKeluarga(famAktif) + '</button>';
    }
    note.innerHTML = html;
  };
  /* Penanganan klik dipasang pada KARTU tema (bukan hanya deretan tombol) supaya
     tombol pintas di catatan (di luar #themeFamPicker) juga berfungsi. */
  var kartu = document.getElementById('tema') || document;
  kartu.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.fam-btn') : null;
    if (!b) return;
    if (ev.target.closest('.theme-card')) return;          // jangan ganggu pemilihan tema
    ev.preventDefault();
    terapkan(b.getAttribute('data-fam'));
  });
})();
</script>
<?php page_foot(); ?>
