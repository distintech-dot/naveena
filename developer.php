<?php
/**
 * DEVELOPER SETTINGS — pengaturan tingkat SISTEM, khusus Super Admin.
 *
 * Dipisahkan dari "Pengaturan Sistem" (settings.php) karena sifatnya berbeda:
 * halaman itu untuk pengaturan operasional (identitas klinik, tema, email,
 * WhatsApp, pembayaran, kartu member), sedangkan halaman ini untuk hal yang
 * menyentuh seluruh sistem dan berisiko:
 *   - Nama klinik (mengubah branding di seluruh aplikasi),
 *   - Penyimpanan data: hapus otomatis (retensi) & hapus manual per periode,
 *   - Backup database, mode pemeliharaan,
 *   - Integrasi (kamus ICD & Satu Sehat),
 *   - Isi data demo & hapus semua data.
 *
 * Semua tindakan diperiksa di SERVER (bukan hanya tombolnya disembunyikan) dan
 * tindakan berisiko meminta konfirmasi bertahap + password Super Admin.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/backup_lib.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/platform.php';
/* Dokumen acuan (muat untuk tombol unduh); MiniPdf dipakai membuat PDF-nya. */
require_once __DIR__ . '/includes/pdf.php';
require_once __DIR__ . '/includes/app_summary.php';
require_once __DIR__ . '/includes/login_hint.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/login_security.php';
require_once __DIR__ . '/includes/twofa_ui.php';
$user = require_login();
if (!is_super() && !has_perm('backup.manage') && !has_perm('maintenance.manage')
    && !has_perm('system.integration')) {
    deny('Developer Settings hanya dapat dibuka oleh Super Admin.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {

        if ($act === 'github_save') {
            if (!is_super()) deny('Pengaturan GitHub hanya dapat diubah oleh Super Admin.');
            $repo = trim((string)($_POST['github_repo'] ?? ''));
            $branch = trim((string)($_POST['github_branch'] ?? 'main'));
            if ($repo !== '' && !preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?/?$~i', $repo)) {
                throw new RuntimeException('URL repository harus berupa https://github.com/pemilik/repository.');
            }
            if ($branch === '' || !preg_match('~^[A-Za-z0-9._/-]{1,120}$~', $branch) || strpos($branch, '..') !== false) {
                throw new RuntimeException('Nama branch GitHub tidak valid.');
            }
            set_setting('github_repo', $repo);
            set_setting('github_branch', $branch);
            if (trim((string)($_POST['github_token'] ?? '')) !== '') {
                set_setting('github_token', trim((string)$_POST['github_token']));
            }
            audit('Ubah Pengaturan GitHub', 'Developer Settings', null, null,
                ['repo' => $repo, 'branch' => $branch], 'Pengaturan repository GitHub diperbarui');
            flash('Pengaturan GitHub disimpan. Token yang dikosongkan tidak diubah.');
        }

        if ($act === 'system') {
            /* PINDAHAN dari Pengaturan Sistem (ronde 37): Data per halaman dan
               petunjuk akun bawaan di halaman login kini hanya Super Admin yang
               dapat mengubahnya — ditegakkan di server, bukan hanya di tampilan. */
            if (!is_super()) deny('Pengaturan Umum hanya dapat diubah oleh Super Admin.');
            $was = [
                'per_page' => setting('default_per_page'),
                'hint' => setting('show_login_hint', '1'),
                'roles' => setting('login_hint_roles', ''),
            ];
            $per = (string)($_POST['default_per_page'] ?? '');
            set_setting('default_per_page', in_array($per, ['10', '25', '50', '100'], true) ? $per : '25');
            set_setting('show_login_hint', ($_POST['show_login_hint'] ?? '') === '1' ? '1' : '0');
            /* Peran mana yang akunnya ditampilkan di halaman login. */
            $valid = array_keys(login_hint_roles_all());
            $pick = array_values(array_intersect($valid, array_map('strval', (array)($_POST['login_hint_roles'] ?? []))));
            set_setting('login_hint_roles', implode(',', $pick));
            $now = [
                'per_page' => setting('default_per_page'),
                'hint' => setting('show_login_hint', '1'),
                'roles' => setting('login_hint_roles', ''),
            ];
            audit('Ubah Pengaturan Umum', 'Developer Settings', null, $was, $now,
                'Pengaturan umum (data per halaman & akun bawaan di login) diperbarui');
            flash('Pengaturan umum disimpan. Akun bawaan di halaman login: '
                . ($now['hint'] === '1'
                    ? ($pick ? implode(', ', array_map(fn($r) => login_hint_roles_all()[$r], $pick)) : 'tidak ada peran dipilih')
                    : 'tidak ditampilkan')
                . '.');
        }
        if ($act === 'security') {
            /* PENGATURAN KEAMANAN LOGIN (ronde 38) — khusus Super Admin:
               lingkup wajib 2FA, durasi "ingat saya", dan batas tidak aktif. */
            if (!is_super()) deny('Pengaturan keamanan login hanya dapat diubah oleh Super Admin.');
            $was = login_security();
            $scope = (string)($_POST['second_factor_levels'] ?? 'super_admin');
            if (!array_key_exists($scope, twofa_level_options())) $scope = 'super_admin';
            set_setting('second_factor_levels', $scope);
            set_setting('login_security_enabled', ($_POST['login_security_enabled'] ?? '') === '1' ? '1' : '0');
            $rd = (int)($_POST['remember_days'] ?? 1);
            set_setting('remember_days', (string)(in_array($rd, [1, 3, 7], true) ? $rd : 1));
            $ih = (int)($_POST['idle_hours'] ?? 3);
            set_setting('idle_hours', (string)(in_array($ih, [1, 3, 5, 8], true) ? $ih : 3));
            $now = login_security();
            audit('Ubah Pengaturan Keamanan Login', 'Developer Settings', null,
                ['2fa' => $was['scope'], 'ingat_hari' => $was['remember_days'], 'tidak_aktif_jam' => $was['idle_hours']],
                ['2fa' => $now['scope'], 'ingat_hari' => $now['remember_days'], 'tidak_aktif_jam' => $now['idle_hours']],
                'Pengaturan keamanan login diperbarui');
            flash('Pengaturan keamanan login disimpan: 2FA '
                . twofa_level_options()[$now['scope']] . '; "Ingat saya" ' . $now['remember_days']
                . ' hari; keluar otomatis setelah ' . $now['idle_hours'] . ' jam tanpa aktivitas.');
        }
        if (strpos($act, 'twofa_') === 0) {
            /* Penyiapan 2FA untuk akun Super Admin yang sedang login. */
            if (!is_super()) deny('Penyiapan verifikasi 2 langkah hanya untuk Super Admin di halaman ini.');
            twofa_handle_post($user, $act);
            header('Location: developer.php#keamanan');
            exit;
        }
        if ($act === 'app_summary') {
            /* Dokumen acuan fungsi aplikasi (untuk migrasi/serah terima):
               khusus Super Admin karena memuat struktur basis data. */
            if (!is_super()) deny('Dokumen ringkasan fungsi hanya dapat diunduh oleh Super Admin.');
            $bytes = app_summary_pdf_bytes();
            $name = 'ringkasan-fungsi-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', clinic_name()))
                . '-' . date('Ymd-Hi') . '.pdf';
            audit('Unduh Ringkasan Fungsi', 'Developer Settings', null, null,
                ['ukuran' => strlen($bytes)], 'Dokumen ringkasan fungsi aplikasi diunduh (PDF)');
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit;
        }
        if ($act === 'app_summary_note') {
            if (!is_super()) deny('Catatan dokumen ringkasan hanya dapat diubah oleh Super Admin.');
            $note = trim((string)($_POST['app_summary_note'] ?? ''));
            if ((int)preg_match_all('/./us', $note) > 4000) {
                throw new RuntimeException('Catatan maksimal 4000 karakter.');
            }
            $old = app_summary_note();
            set_setting('app_summary_note', $note);
            audit('Ubah Catatan Ringkasan Fungsi', 'Developer Settings', null,
                ['panjang' => (int)preg_match_all('/./us', $old)], ['panjang' => (int)preg_match_all('/./us', $note)],
                'Catatan tambahan dokumen ringkasan fungsi diperbarui');
            flash('Catatan tambahan disimpan — akan ikut tercetak pada dokumen PDF saat diunduh.');
        }

        if ($act === 'retention') {
            /* Pengaturan retensi = tindakan tingkat sistem: khusus Super Admin. */
            if (!is_super()) deny('Pengaturan penyimpanan data hanya dapat diubah oleh Super Admin.');
            $was = ['menu' => [], 'aktivitas' => ['aktif' => activity_enabled(), 'periode' => activity_period()]];
            foreach (array_keys(retention_targets()) as $mk) {
                $keys = retention_setting_keys($mk);
                $valid = retention_period_options($mk);
                $was['menu'][$mk] = ['aktif' => retention_enabled($mk), 'periode' => retention_period($mk)];
                set_setting($keys['aktif'], ($_POST[$keys['aktif']] ?? '') === '1' ? '1' : '0');
                $p = (string)($_POST[$keys['periode']] ?? '');
                if (isset($valid[$p])) set_setting($keys['periode'], $p);
            }
            /* Aktivitas akun ("Aktivitas Saya Terbaru") — bagian dari kartu yang sama. */
            set_setting('activity_retention_active', ($_POST['activity_retention_active'] ?? '') === '1' ? '1' : '0');
            $ap = (string)($_POST['activity_retention_period'] ?? '');
            if (isset(activity_periods()[$ap])) set_setting('activity_retention_period', $ap);
            $now = ['menu' => [], 'aktivitas' => ['aktif' => activity_enabled(), 'periode' => activity_period()]];
            foreach (array_keys(retention_targets()) as $mk) {
                $now['menu'][$mk] = ['aktif' => retention_enabled($mk), 'periode' => retention_period($mk)];
            }
            audit('Ubah Pengaturan Penyimpanan Data', 'Pembersihan Data', null, $was, $now,
                'Pengaturan hapus otomatis data lama diperbarui');
            $msg = 'Pengaturan penyimpanan data disimpan. ';
            $aktifTxt = [];
            foreach ($now['menu'] as $mk => $cf) {
                if (!$cf['aktif']) continue;
                $aktifTxt[] = retention_meta($mk)['short'] . ' ' . retention_period_label($cf['periode']);
            }
            if ($now['aktivitas']['aktif']) {
                $aktifTxt[] = 'Aktivitas akun ' . retention_period_label($now['aktivitas']['periode']);
            }
            $msg .= $aktifTxt
                ? 'Aktif: ' . implode(', ', $aktifTxt) . ' — dijalankan otomatis saat ADA YANG LOGIN (semua level),'
                  . ' maksimal sekali sehari per bagian.'
                : 'Semua bagian masih NONAKTIF — tidak ada data yang dihapus otomatis.';
            if (($_POST['retention_run_now'] ?? '') === '1') {
                if (!$aktifTxt) {
                    $msg .= ' Pembersihan paksa tidak dijalankan karena belum ada menu yang aktif.';
                } else {
                    set_setting('retention_last_run', '');           // paksa berjalan sekarang
                    $r = retention_auto_run((int)$user['id']);
                    $msg .= $r
                        ? ' Pembersihan dijalankan sekarang: ' . num((int)$r['total']) . ' baris data lama dihapus (' . implode(', ',
                            array_map(fn($x) => $x['label'] . ' ' . num((int)$x['jumlah']), $r['rincian'])) . ').'
                        : ' Pembersihan dijalankan: tidak ada data yang melewati batas periode.';
                }
            }
            flash($msg);
        }

        if ($act === 'clinic_name') {
            /* PENGUBAHAN NAMA KLINIK — HANYA SUPER ADMIN (diperiksa di server,
               bukan sekadar tombolnya disembunyikan).

               PENTING (perbaikan): hasil setiap percobaan disimpan di sesi dan
               ditampilkan DI DALAM kartu Nama Klinik, bukan hanya sebagai pesan
               di puncak halaman. Sebelumnya pengalihan kembali ke #namaklinik
               membuat pesan di atas ikut tergulir keluar layar, sehingga
               pengguna yang menekan Simpan tidak melihat penjelasan apa pun —
               termasuk saat nama yang dikirim SAMA dengan nama yang sedang
               dipakai (tidak ada yang berubah, tetapi dulu tetap dilaporkan
               "berhasil"). Sekarang nama yang sama dilaporkan apa adanya,
               ditambah pemeriksaan halaman basi dan jejak audit untuk
               percobaan yang gagal. */
            if (!is_super()) {
                deny('Pengubahan nama klinik hanya dapat dilakukan oleh Super Admin.');
            }
            $attempt = trim((string)($_POST['clinic_name'] ?? ''));
            $current = clinic_name();
            $fromPage = trim((string)($_POST['clinic_name_now'] ?? ''));
            $result = ['kind' => 'error', 'attempt' => $attempt, 'old' => $current, 'new' => '',
                       'error' => '', 'page_name' => $fromPage];
            /* VERIFIKASI 2 LANGKAH: konfirmasi berat di antarmuka (modal kata
               kunci + dialog kedua) DAN password Super Admin di server. */
            $pass = (string)($_POST['password'] ?? '');
            if ($pass === '' || !password_verify($pass, (string)$user['password_hash'])) {
                audit('Ubah Nama Klinik Ditolak', 'Pengaturan', null, null,
                    ['percobaan' => $attempt],
                    'Password Super Admin salah saat mengubah nama klinik');
                $result['error'] = 'Password Super Admin tidak sesuai — nama klinik TIDAK diubah.';
                $_SESSION['clinic_last_result'] = $result;
                throw new RuntimeException($result['error']);
            }
            /* Nama tidak sah (terlalu pendek/panjang, memuat simbol tak didukung)
               dicatat juga di audit supaya dapat ditelusuri — dulu percobaan
               seperti ini tidak meninggalkan jejak apa pun. */
            $check = clinic_name_validate($attempt);
            if (!$check['ok']) {
                audit('Ubah Nama Klinik Gagal', 'Pengaturan', null, ['nama_klinik' => $current],
                    ['percobaan' => $attempt, 'alasan' => $check['error']],
                    'Nama klinik tidak diubah karena tidak lolos pemeriksaan');
                $result['error'] = $check['error'];
                $_SESSION['clinic_last_result'] = $result;
                throw new RuntimeException($check['error']);
            }
            /* Halaman yang sudah basi: nama klinik berubah di tab/perangkat lain
               setelah halaman ini dibuka. Tanpa pemeriksaan ini, penggantian tetap
               dijalankan dari nilai lama sehingga teks template yang harus ikut
               berubah bisa terlewat. */
            if ($fromPage !== '' && strcasecmp($fromPage, $current) !== 0) {
                audit('Ubah Nama Klinik Gagal', 'Pengaturan', null, ['nama_klinik' => $current],
                    ['percobaan' => $check['name'], 'halaman_dibuka_dengan' => $fromPage],
                    'Halaman Nama Klinik sudah basi — pengguna diminta memuat ulang');
                $result['error'] = 'Halaman ini dibuka saat nama klinik masih "' . $fromPage
                    . '", sedangkan nama yang berlaku sekarang "' . $current . '". Muat ulang halaman'
                    . ' (tekan F5 / tarik ke bawah) lalu ulangi penggantian nama.';
                $_SESSION['clinic_last_result'] = $result;
                throw new RuntimeException($result['error']);
            }
            /* NAMA SAMA = TIDAK ADA YANG BERUBAH. Ini kasus yang dulu dilaporkan
               sebagai "berhasil" sehingga tampak seperti fitur tidak bekerja. */
            if (strcasecmp($check['name'], $current) === 0) {
                audit('Ubah Nama Klinik (tidak ada perubahan)', 'Pengaturan', null,
                    ['nama_klinik' => $current], ['nama_klinik' => $check['name']],
                    'Nama yang dikirim sama dengan nama yang sedang dipakai');
                $result['kind'] = 'same';
                $result['new'] = $check['name'];
                $_SESSION['clinic_last_result'] = $result;
                flash('Nama klinik TIDAK berubah: nama yang dikirim sama dengan nama yang sedang '
                    . 'dipakai ("' . $current . '"). Ketik nama BARU pada kolom "Nama Klinik" '
                    . '(kolom menunjukkan nama yang akan disimpan) lalu simpan lagi.', 'warning');
            } else {
                $res = clinic_rename_apply($check['name'], [
                    /* Nilai '0' (tidak dicentang) dihormati; input tersembunyi di form
                       memastikan kunci ini selalu ada. */
                    'templates' => (string)($_POST['update_templates'] ?? '0') === '1',
                    'branches'  => (string)($_POST['update_branches'] ?? '0') === '1',
                    'reason'    => trim((string)($_POST['reason'] ?? '')),
                ], (int)$user['id']);
                if (!$res['ok']) {
                    $result['error'] = $res['error'];
                    $_SESSION['clinic_last_result'] = $result;
                    throw new RuntimeException($res['error']);
                }
                /* Laporan disimpan untuk ditampilkan sekali di kartu Nama Klinik
                   (apa saja yang ikut berubah & apa yang masih perlu diperiksa). */
                $_SESSION['clinic_last_rename'] = $res;
                $result['kind'] = 'ok';
                $result['new'] = $res['new'];
                $result['old'] = $res['old'];
                $_SESSION['clinic_last_result'] = $result;
                $msg = 'Nama klinik diubah: "' . $res['old'] . '" → "' . $res['new'] . '". '
                    . count($res['settings']) . ' teks pengaturan dan ' . count($res['branches'])
                    . ' nama cabang ikut diperbarui. Perubahan langsung berlaku di sidebar, login, '
                    . 'struk, dokumen PDF/Excel, email, dan pesan WhatsApp baru.';
                if ($res['leftovers']) {
                    $msg .= ' Ada ' . count($res['leftovers']) . ' tempat yang masih memuat nama lama '
                        . '(lihat daftar "Perlu diperiksa" di kartu Nama Klinik).';
                }
                flash($msg, $res['leftovers'] ? 'warning' : 'success');
            }
        }
        if ($act === 'upload_gc') {
            /* Bersihkan berkas gambar tak terpakai (logo/latar kartu lama + cache-nya).
               Khusus Super Admin, dan hanya menyentuh berkas di AKAR folder unggahan
               yang tidak dirujuk setelan/database — foto pasien/staf/rekam medis
               (di sub-folder) tidak pernah disentuh. */
            if (!is_super()) deny('Pembersihan berkas aplikasi hanya dapat dilakukan oleh Super Admin.');
            $before = upload_gc_scan(false);
            $res = upload_gc_purge();
            audit('Bersihkan Berkas Tidak Terpakai', 'Penyimpanan', null,
                ['berkas_tak_terpakai' => (int)($before['orphan_files'] ?? 0),
                 'ukuran_tak_terpakai' => (int)($before['orphan_bytes'] ?? 0)],
                ['terhapus' => $res['count'], 'dibebaskan' => $res['bytes'], 'gagal' => count($res['failed'])],
                'Berkas gambar tak terpakai dibersihkan dari folder unggahan');
            if ($res['count'] > 0) {
                flash(num($res['count']) . ' berkas tak terpakai dihapus — ' . upload_gc_size((int)$res['bytes'])
                    . ' penyimpanan dibebaskan. Logo, latar kartu, dan foto pasien/staf/rekam medis yang '
                    . 'sedang dipakai tidak disentuh.'
                    . ($res['failed'] ? ' ' . num(count($res['failed'])) . ' berkas gagal dihapus (mungkin sedang dipakai).' : ''),
                    'success');
            } else {
                flash('Tidak ada berkas tak terpakai yang perlu dibersihkan'
                    . ((int)($before['young_files'] ?? 0) > 0
                        ? ' — ' . num((int)$before['young_files']) . ' berkas baru (kurang dari 1 jam) sengaja dilewati agar unggahan yang baru disimpan tidak terhapus.'
                        : '.')
                    . ($res['failed'] ? ' ' . num(count($res['failed'])) . ' berkas gagal dihapus.' : ''), 'warning');
            }
        }
        if ($act === 'photo') {
            /* Kompresi foto = pengaturan tingkat sistem: khusus Super Admin. */
            if (!is_super()) deny('Pengaturan kompresi foto hanya dapat diubah oleh Super Admin.');
            $clamp = function ($v, $min, $max, $def) { $n = (int)$v; return ($n < $min || $n > $max) ? $def : $n; };
            set_setting('photo_max_patient', (string)$clamp($_POST['photo_max_patient'] ?? 480, 120, 1200, 480));
            set_setting('photo_max_staff', (string)$clamp($_POST['photo_max_staff'] ?? 480, 120, 1200, 480));
            set_setting('photo_max_medical', (string)$clamp($_POST['photo_max_medical'] ?? 1400, 400, 2400, 1400));
            set_setting('photo_max_logo', (string)$clamp($_POST['photo_max_logo'] ?? 800, 200, 2000, 800));
            set_setting('photo_quality', (string)$clamp($_POST['photo_quality'] ?? 80, 40, 95, 80));
            audit('Ubah Pengaturan Kompresi Foto', 'Pengaturan', null, null, [
                'pasien' => setting('photo_max_patient'), 'staf' => setting('photo_max_staff'),
                'rekam_medis' => setting('photo_max_medical'), 'logo' => setting('photo_max_logo'),
                'mutu' => setting('photo_quality'),
            ], 'Pengaturan kompresi gambar diperbarui');
            flash('Pengaturan kompresi foto disimpan.');
        }
        if ($act === 'maintenance') {
            /* Mode pemeliharaan = tindakan sistem. Hanya Super Admin
               (pemegang `maintenance.manage`) yang boleh menyalakannya. */
            if (!has_perm('maintenance.manage')) {
                deny('Mode Pemeliharaan hanya dapat diatur oleh Super Admin.');
            }
            $on = ($_POST['maintenance_mode'] ?? '') === '1';
            $was = maintenance_on();
            set_setting('maintenance_title', trim((string)($_POST['maintenance_title'] ?? '')));
            set_setting('maintenance_message', trim((string)($_POST['maintenance_message'] ?? '')));
            $until = trim((string)($_POST['maintenance_until'] ?? ''));
            if ($until !== '' && strtotime($until) === false) {
                throw new RuntimeException('Perkiraan selesai tidak dikenali. Gunakan format tanggal & jam yang benar.');
            }
            set_setting('maintenance_until', $until);
            set_setting('maintenance_note', trim((string)($_POST['maintenance_note'] ?? '')));
            set_setting('maintenance_contact_name', trim((string)($_POST['maintenance_contact_name'] ?? '')));
            set_setting('maintenance_contact_phone', trim((string)($_POST['maintenance_contact_phone'] ?? '')));
            if ($on && !$was) {
                set_setting('maintenance_started_at', date('Y-m-d H:i:s'));
            }
            set_setting('maintenance_mode', $on ? '1' : '0');
            audit('Ubah Mode Pemeliharaan', 'Pemeliharaan', null,
                ['aktif' => $was], ['aktif' => $on, 'sampai' => $until],
                $on ? 'Mode pemeliharaan DIAKTIFKAN — level selain Super Admin hanya dapat melihat data'
                    : 'Mode pemeliharaan dimatikan');
            flash($on
                ? 'Mode pemeliharaan AKTIF. Kasir & Admin/Dokter kini hanya bisa melihat data; Anda (Super Admin) tetap bebas.'
                : 'Mode pemeliharaan dimatikan — semua level dapat kembali mengelola data.');
        }
        if ($act === 'satu_sehat' || $act === 'satu_sehat_test' || $act === 'icd_reload') {
            /* Kredensial integrasi (Satu Sehat/Kemenkes) & pemuatan ulang kamus ICD
               adalah tindakan tingkat sistem — khusus Super Admin. */
            if (!has_perm('system.integration')) {
                deny('Konfigurasi integrasi sistem hanya dapat diubah oleh Super Admin.');
            }
        }
        if ($act === 'satu_sehat') {
            set_setting('satu_sehat_env', $_POST['satu_sehat_env'] === 'production' ? 'production' : 'sandbox');
            foreach (['satu_sehat_org_id', 'satu_sehat_client_id', 'satu_sehat_token_url', 'satu_sehat_fhir_url'] as $k) {
                set_setting($k, trim((string)($_POST[$k] ?? '')));
            }
            if (($_POST['satu_sehat_client_secret'] ?? '') !== '') {
                set_setting('satu_sehat_client_secret', (string)$_POST['satu_sehat_client_secret']);
            }
            $wantActive = ($_POST['satu_sehat_active'] ?? '') === '1';
            if ($wantActive && !satu_sehat_configured()) {
                set_setting('satu_sehat_active', '0');
                flash('Integrasi Satu Sehat belum dapat diaktifkan: Organization ID, Client ID, dan Client Secret harus diisi lebih dahulu.', 'warning');
            } else {
                set_setting('satu_sehat_active', $wantActive ? '1' : '0');
                flash('Konfigurasi Satu Sehat disimpan. Status: ' . satu_sehat_status_text());
            }
            audit('Ubah Pengaturan Satu Sehat', 'Pengaturan', null, null,
                ['active' => setting('satu_sehat_active'), 'env' => setting('satu_sehat_env')],
                'Perubahan konfigurasi integrasi Satu Sehat');
        }
        if ($act === 'satu_sehat_test') {
            $err = '';
            $token = null;
            $ok = satu_sehat_test_token($err, $token);
            audit($ok ? 'Uji Koneksi Satu Sehat Berhasil' : 'Uji Koneksi Satu Sehat Gagal', 'Pengaturan', null, null,
                ['env' => setting('satu_sehat_env')], $ok ? 'Token diterima dari Satu Sehat' : (string)$err);
            flash($ok
                ? 'Koneksi Satu Sehat berhasil — server menerima access token (berlaku sementara, tidak disimpan).'
                : 'Uji koneksi gagal: ' . $err, $ok ? 'success' : 'warning');
        }
        if ($act === 'icd_reload') {
            $n = 0;
            db()->exec('BEGIN IMMEDIATE');
            try {
                $n = seed_icd_dictionary(db(), true);
                db()->exec('COMMIT');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                throw new RuntimeException('Gagal memuat ulang kamus ICD: ' . $ex->getMessage());
            }
            audit('Muat Ulang Kamus ICD', 'Pengaturan', null, null, ['baris' => $n], 'Kamus ICD dimuat ulang dari data/*.tsv');
            flash($n > 0
                ? 'Kamus ICD dimuat ulang: ' . num($n) . ' baris (' . num(icd_count('icd10')) . ' ICD-10, ' . num(icd_count('icd9cm')) . ' ICD-9-CM).'
                : 'Kamus ICD tidak berubah (file data tidak ditemukan atau tabel sudah terisi).', $n > 0 ? 'success' : 'warning');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    $anchor = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['_anchor'] ?? ''));
    header('Location: developer.php' . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}


/* Data untuk kartu-kartu halaman ini */
$mInfo = maintenance_info();
$mOn = maintenance_on();
$ret = retention_overview();
$retLast = json_decode((string)setting('retention_last_result', ''), true);
/* Laporan penggantian nama klinik terakhir (ditampilkan sekali) */
$clinicReport = $_SESSION['clinic_last_rename'] ?? null;
/* Hasil percobaan terakhir (berhasil / tidak ada perubahan / gagal) — ditampilkan
   DI DALAM kartu Nama Klinik, karena pengalihan kembali ke #namaklinik membuat
   pesan di puncak halaman ikut tergulir ke luar layar. */
$clinicResult = $_SESSION['clinic_last_result'] ?? null;
if (gp('clear_clinic_report') === '1') {
    unset($_SESSION['clinic_last_rename'], $_SESSION['clinic_last_result']);
    $clinicReport = null;
    $clinicResult = null;
}
$clinicNow = clinic_name();
$clinicBranchNames = array_map(fn($b) => (string)$b['name'], branches());
$wipeCounts = [
    'Pasien' => (int)scalar('SELECT COUNT(*) FROM patients'),
    'Rekam medis' => (int)scalar('SELECT COUNT(*) FROM medical_records'),
    'Reservasi' => (int)scalar('SELECT COUNT(*) FROM appointments'),
    'Transaksi' => (int)scalar('SELECT COUNT(*) FROM orders'),
    'Master treatment' => (int)scalar('SELECT COUNT(*) FROM treatments'),
    'Master skincare' => (int)scalar('SELECT COUNT(*) FROM skincare_products'),
    'Bahan treatment' => (int)scalar('SELECT COUNT(*) FROM treatment_materials'),
    'Supplier' => (int)scalar('SELECT COUNT(*) FROM suppliers'),
    'Pergerakan stok' => (int)scalar('SELECT COUNT(*) FROM inventory_movements'),
    'Dokter & terapis' => (int)scalar('SELECT COUNT(*) FROM doctors') + (int)scalar('SELECT COUNT(*) FROM therapists'),
    'Audit log' => (int)scalar('SELECT COUNT(*) FROM audit_logs'),
];
$wipeTotal = array_sum($wipeCounts);

page_head('Developer Settings', 'developer');
?>
<div class="page-head">
  <div>
    <h2>Developer Settings</h2>
    <p class="muted">Pengaturan tingkat sistem — <strong>hanya Super Admin</strong>. Identitas sistem, penyimpanan data,
      backup, pemeliharaan, integrasi, dan pengisian data demo.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="settings.php"><?= icon('settings') ?> Pengaturan Sistem</a>
    <?php if (has_perm('backup.manage')): ?><a class="btn" href="backup.php"><?= icon('database') ?> Backup Database</a><?php endif; ?>
  </div>
</div>

<div class="alert alert-warning">
  <strong>Halaman khusus Super Admin.</strong> Semua tindakan di bawah menyentuh seluruh sistem (semua cabang dan
  semua pengguna). Tindakan berisiko wajib melalui <strong>konfirmasi bertahap (2x)</strong> dan sebagian meminta
  <strong>password Super Admin</strong>. Setiap tindakan tercatat di Audit Log.
</div>

<div class="card" id="ringkasan">
  <div class="card-head"><h3>Ringkasan Cepat</h3>
    <span class="muted small">angka dihitung langsung dari database saat ini</span></div>
  <div class="card-body">
    <div class="grid g4">
      <div class="stat"><span class="lbl">Ukuran Database</span>
        <span class="val"><?= num(round((is_file(DB_PATH) ? filesize(DB_PATH) : 0) / 1048576, 2), 2) ?> MB</span>
        <span class="sub"><?= e(basename(DB_PATH)) ?> <span class="muted">(basis data utama = central)</span></span></div>
      <div class="stat"><span class="lbl">Stok &amp; Movement</span>
        <span class="val"><?= num((int)scalar('SELECT COUNT(*) FROM inventory_movements')) ?></span>
        <span class="sub"><?= e(retention_menu_status_text('movement')) ?></span></div>
      <div class="stat"><span class="lbl">Audit Log</span>
        <span class="val"><?= num((int)scalar('SELECT COUNT(*) FROM audit_logs')) ?></span>
        <span class="sub"><?= e(retention_menu_status_text('audit')) ?></span></div>
      <div class="stat"><span class="lbl">Retensi Otomatis Aktif</span>
        <span class="val"><?= count(array_filter($ret, fn($x) => $x['aktif'])) ?>/<?= count($ret) ?></span>
        <span class="sub">menu · aktivitas akun <?= activity_enabled() ? 'aktif' : 'nonaktif' ?><?= setting('retention_last_at') !== '' ? ' · terakhir ' . e(tgl(setting('retention_last_at'), true)) : '' ?></span></div>
    </div>
    <div class="table-wrap mt-2">
      <table class="tbl">
        <thead><tr><th>Data Operasional</th><th class="num">Jumlah</th><th>Status retensi otomatis</th></tr></thead>
        <tbody>
        <?php foreach (['pasien' => 'Pasien Tidak Aktif', 'reservasi' => 'Reservasi', 'rekam_medis' => 'Rekam Medis', 'order' => 'Riwayat Order'] as $mk => $ml): ?>
          <tr><td class="small"><?= e($ml) ?></td>
            <td class="num"><?= num((int)scalar('SELECT COUNT(*) FROM ' . retention_meta($mk)['table'])) ?></td>
            <td class="small"><?= e(retention_menu_status_text($mk)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card" id="retensi">
  <div class="card-head">
    <h3>Penyimpanan Data — Hapus Otomatis</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="retention">
    <input type="hidden" name="_anchor" value="retensi">
    <div class="card-body">
      <p class="muted">Agar database tidak menumpuk, data lama dapat dihapus <strong>otomatis</strong>. Pemeriksaan
        dijalankan <strong>saat aplikasi dibuka</strong> (platform ini tidak menyediakan cron), maksimal
        <strong>sekali sehari</strong>, dan hanya untuk bagian yang diaktifkan di bawah.</p>
      <div class="notice">
        <strong>Siapa yang menjalankannya?</strong> <strong>Semua level pengguna</strong> — Kasir, Admin/Dokter,
        Direktur/Owner, maupun Super Admin. Begitu <strong>siapa pun login</strong> pada hari itu, pembersihan
        yang sudah diaktifkan langsung berjalan, sehingga tidak bergantung pada Super Admin (yang mungkin jarang
        masuk setiap hari). Hasilnya diberitahukan di Dashboard kepada pengguna yang sedang login.
        Saat <strong>mode pemeliharaan</strong> aktif, pembersihan ditahan untuk level selain Super Admin.
      </div>
      <div class="alert alert-info">
        <strong>Yang perlu diketahui sebelum mengaktifkan:</strong>
        <ul style="margin:6px 0 0;padding-left:18px">
          <li>Data yang dihapus <strong>hilang permanen</strong> dan tidak dapat dikembalikan dari dalam aplikasi —
            pastikan backup terbaru sudah ada (<a href="backup.php">Backup Database</a>).</li>
          <li><strong>Stok TIDAK dikembalikan</strong> ketika riwayat order lama dihapus: data itu sudah berumur
            bulanan/tahunan, sehingga stok yang berlaku sekarang adalah stok nyata saat ini.</li>
          <li>Laporan/Top 5 untuk periode yang datanya terhapus akan ikut kosong — memang itu tujuan pembersihan.</li>
          <li>Setiap penghapusan (otomatis maupun manual) dicatat di Audit Log beserta jumlah baris per tabel.</li>
        </ul>
      </div>

      <div class="section-title">1. Data Jejak — Stok &amp; Movement dan Audit Log</div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Data</th><th>Status</th><th>Hapus data lebih lama dari</th><th class="num">Siap dihapus</th></tr></thead>
          <tbody>
          <?php foreach ($ret as $mk => $r): if ($r['group'] !== 'log') continue; ?>
            <tr>
              <td class="small"><strong><?= e($r['label']) ?></strong>
                <div class="muted"><?= e($r['date_label']) ?></div></td>
              <td><select class="input input-sm" name="retention_<?= e($mk) ?>_active">
                  <option value="0"<?= $r['aktif'] ? '' : ' selected' ?>>Nonaktif</option>
                  <option value="1"<?= $r['aktif'] ? ' selected' : '' ?>>Aktif</option>
                </select></td>
              <td><select class="input input-sm" name="retention_<?= e($mk) ?>_period">
                  <?php foreach ($r['period_options'] as $pk => $pl): ?>
                    <option value="<?= e($pk) ?>"<?= $r['periode'] === $pk ? ' selected' : '' ?>><?= e($pl) ?></option>
                  <?php endforeach; ?>
                </select></td>
              <td class="num"><?= $r['aktif'] ? num($r['jumlah_siap_hapus']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="section-title">2. Data Operasional per Menu (periode boleh berbeda-beda)</div>
      <p class="muted small">Contoh: <strong>Reservasi 6 bulan</strong>, <strong>Rekam Medis 5 tahun</strong>,
        <strong>Riwayat Order 1 tahun</strong>. Setiap menu punya pilihan periode sendiri.</p>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Menu</th><th>Status</th><th>Hapus data lebih lama dari</th><th class="num">Siap dihapus</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($ret as $mk => $r): if ($r['group'] !== 'menu') continue; ?>
            <tr>
              <td class="small"><strong><?= e($r['label']) ?></strong>
                <div class="muted"><?= e($r['date_label']) ?></div></td>
              <td><select class="input input-sm" name="retention_<?= e($mk) ?>_active">
                  <option value="0"<?= $r['aktif'] ? '' : ' selected' ?>>Nonaktif</option>
                  <option value="1"<?= $r['aktif'] ? ' selected' : '' ?>>Aktif</option>
                </select></td>
              <td><select class="input input-sm" name="retention_<?= e($mk) ?>_period">
                  <?php foreach ($r['period_options'] as $pk => $pl): ?>
                    <option value="<?= e($pk) ?>"<?= $r['periode'] === $pk ? ' selected' : '' ?>><?= e($pl) ?></option>
                  <?php endforeach; ?>
                </select></td>
              <td class="num"><?= $r['aktif'] ? num($r['jumlah_siap_hapus']) : '—' ?></td>
              <td class="nowrap">
                <?php if (!empty($r['manual'])): ?>
                  <a class="btn btn-sm" href="<?= e($r['back']) ?>">Buka <?= e($r['short']) ?></a>
                <?php else: ?>
                  <span class="muted small">otomatis saja</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="notice mt-2">
        Selain jadwal otomatis, tombol <strong>Hapus Data per Periode</strong> (1 bulan … 7 tahun) tersedia langsung
        pada tiap menu (Reservasi, Rekam Medis, Riwayat Order) untuk penghapusan sewaktu-waktu. Tombol itu hanya
        tampil untuk Super Admin dan memerlukan konfirmasi 2 tahap + password.
      </div>

      <?php if ($retLast && !empty($retLast['rincian'])): ?>
      <div class="section-title">Hasil penghapusan otomatis terakhir</div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Menu</th><th>Periode</th><th class="num">Dihapus</th></tr></thead>
          <tbody>
          <?php foreach ($retLast['rincian'] as $mk => $info): ?>
            <tr><td class="small"><?= e((string)($info['label'] ?? $mk)) ?></td>
              <td class="small"><?= e((string)($info['periode'] ?? '-')) ?></td>
              <td class="num"><?= !empty($info['error']) ? 'GAGAL: ' . e((string)$info['error']) : num((int)($info['jumlah'] ?? 0)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="2">Total (<?= e((string)($retLast['tanggal'] ?? '-')) ?>)</th>
            <th class="num"><?= num((int)($retLast['total'] ?? 0)) ?></th></tr></tfoot>
        </table>
      </div>
      <?php endif; ?>

      <div class="section-title">3. Aktivitas Akun — "Aktivitas Saya Terbaru" (halaman Akun Saya)</div>
      <p class="muted small">Catatan aktivitas <strong>milik masing-masing pengguna</strong> (dari Audit Log, kolom
        pengguna) dibersihkan otomatis saat <strong>pengguna itu login</strong> — berlaku untuk semua level dan
        <strong>tidak pernah menyentuh aktivitas pengguna lain</strong>.</p>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Bagian</th><th>Status</th><th>Hapus aktivitas lebih lama dari</th><th>Menjalankan</th></tr></thead>
          <tbody>
            <tr>
              <td class="small"><strong>Aktivitas akun sendiri</strong>
                <div class="muted">catatan Audit Log milik tiap pengguna</div></td>
              <td><select class="input input-sm" name="activity_retention_active">
                  <option value="0"<?= activity_enabled() ? '' : ' selected' ?>>Nonaktif</option>
                  <option value="1"<?= activity_enabled() ? ' selected' : '' ?>>Aktif</option>
                </select></td>
              <td><select class="input input-sm" name="activity_retention_period">
                  <?php foreach (activity_periods() as $pk => $pl): ?>
                    <option value="<?= e($pk) ?>"<?= activity_period() === $pk ? ' selected' : '' ?>><?= e($pl) ?></option>
                  <?php endforeach; ?>
                </select></td>
              <td class="small"><?= e(activity_status_text()) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="notice mt-2">
        Tindakan penting <strong>tidak ikut dibersihkan</strong> walau sudah lama, supaya tetap dapat diaudit:
        <?= e(implode(', ', activity_protected_actions())) ?>.
      </div>

      <div class="form-grid g2 mt-2">
        <div class="field"><label>Terapkan sekarang</label>
          <label class="small" style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="retention_run_now" value="1">
            Jalankan pembersihan sekarang juga untuk menu yang aktif</label>
          <span class="hint">Menjalankan penghapusan data lama untuk menu yang aktif <em>sekarang juga</em>
            (tanpa menunggu login berikutnya). Aktivitas akun dibersihkan saat pengguna login.</span></div>
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Simpan Pengaturan Penyimpanan Data</button>
    </div>
  </form>
</div>

<div class="card" id="github">
  <div class="card-head">
    <h3>Push GitHub</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <form method="post" id="githubSettingsForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="github_save">
    <div class="card-body">
      <p class="muted">Simpan repository, branch, dan token GitHub. Push mengirim berkas aplikasi yang berubah ke repository; basis data, unggahan, dan berkas runtime dilewati.</p>
      <div class="form-grid g3">
        <div class="field"><label>Repository GitHub</label>
          <input class="input" name="github_repo" value="<?= e(setting('github_repo', '')) ?>" placeholder="https://github.com/pemilik/repository" required></div>
        <div class="field"><label>Branch</label>
          <input class="input" name="github_branch" value="<?= e(setting('github_branch', 'main')) ?>" required></div>
        <div class="field"><label>Personal Access Token</label>
          <input class="input" type="password" name="github_token" autocomplete="new-password"
                 placeholder="<?= setting('github_token', '') !== '' ? '•••••• (tersimpan; kosongkan untuk mempertahankan)' : 'belum diisi' ?>">
          <span class="hint">Token tidak ditampilkan kembali. Gunakan token dengan izin minimum untuk menulis repository ini.</span></div>
      </div>
      <div class="alert alert-info mt-2" data-github-status aria-live="polite" style="display:none"></div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Pengaturan GitHub</button>
      <button class="btn" type="button" data-github-push data-endpoint="api.php?a=push_github"><?= icon('upload') ?> Push GitHub</button>
    </div>
  </form>
</div>

<div class="card" id="namaklinik">
  <div class="card-head">
    <h3>Nama Klinik (Identitas Sistem)</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <form method="post" data-heavy-confirm="UBAH NAMA KLINIK"
        data-heavy-warning="Mengubah <strong>nama klinik</strong> yang dipakai di seluruh aplikasi: sidebar, halaman login, struk &amp; dokumen PDF/Excel, kartu member, email, dan pesan WhatsApp.<br><br>Nama cabang serta teks template yang memuat nama lama juga ikut diperbarui (bila dicentang di bawah). Data pasien/transaksi tidak berubah, dan nomor dokumen tidak terpengaruh."
        data-heavy-confirm2="PERINGATAN KEDUA (terakhir): nama klinik akan diubah dan berlaku di seluruh aplikasi. Lanjutkan?">
    <?= csrf_field() ?><input type="hidden" name="action" value="clinic_name">
    <input type="hidden" name="_anchor" value="namaklinik">
    <?php /* Nama klinik saat halaman ini DIBUKA — dipakai server untuk mendeteksi
             halaman basi (nama sudah diubah dari tab/perangkat lain). */ ?>
    <input type="hidden" name="clinic_name_now" value="<?= e($clinicNow) ?>">
    <div class="card-body">
      <p class="muted">Ini <strong>satu-satunya tempat</strong> pengaturan nama klinik. Mengubah nama di sini
        otomatis mengubah nama klinik di seluruh bagian aplikasi yang terintegrasi — termasuk teks template
        WhatsApp/email, catatan kaki struk, catatan kartu member, nama pengirim email, dan nama cabang yang
        memuat nama lama. Nomor dokumen (invoice, rekam medis, reservasi) <strong>tidak berubah</strong> karena
        memakai kode cabang, bukan nama klinik.</p>

      <?php /* Hasil percobaan terakhir — ditampilkan di sini (bukan hanya di puncak
               halaman) supaya selalu terlihat setelah tombol Simpan ditekan. */ ?>
      <?php if ($clinicResult): ?>
        <?php
        $cKind = (string)($clinicResult['kind'] ?? 'error');
        $cAlert = $cKind === 'ok' ? 'success' : ($cKind === 'same' ? 'warning' : 'error');
        ?>
        <div class="alert alert-<?= $cAlert ?>">
          <strong id="clinicResultHead"><?= $cKind === 'ok' ? 'Nama klinik BERHASIL diubah'
              : ($cKind === 'same' ? 'Tidak ada yang berubah — nama yang dikirim SAMA dengan nama saat ini'
                  : 'Nama klinik GAGAL diubah') ?></strong>
          <?php if ($cKind === 'ok'): ?>
            : "<?= e((string)$clinicResult['old']) ?>" → <strong><?= e((string)$clinicResult['new']) ?></strong>.
            Perubahan langsung berlaku di sidebar, login, struk, kartu member, dokumen PDF/Excel, email,
            dan pesan WhatsApp baru.
          <?php elseif ($cKind === 'same'): ?>
            : nama yang sedang dipakai adalah <strong><?= e((string)$clinicResult['old']) ?></strong>, dan itu
            juga nama yang tadi dikirim. Ketik nama BARU pada kolom "Nama Klinik" — sebelum dikonfirmasi,
            kotak peringatan akan menampilkan nama yang benar-benar akan disimpan.
          <?php else: ?>
            : <?= e((string)($clinicResult['error'] ?? '')) ?>
          <?php endif; ?>
          <a class="small" href="developer.php?clear_clinic_report=1#namaklinik">Tutup pemberitahuan ini</a>
        </div>
      <?php endif; ?>

      <?php if ($clinicReport): ?>
      <div class="alert alert-<?= $clinicReport['leftovers'] ? 'warning' : 'success' ?>">
        <strong>Penggantian nama klinik terakhir:</strong> "<?= e($clinicReport['old']) ?>" →
        <strong><?= e($clinicReport['new']) ?></strong>.
        <?= num(count($clinicReport['settings'])) ?> teks pengaturan dan
        <?= num(count($clinicReport['branches'])) ?> nama cabang ikut diperbarui.
        <a class="small" href="developer.php?clear_clinic_report=1#namaklinik">Tutup laporan ini</a>
        <div class="table-wrap mt-2">
          <table class="tbl">
            <thead><tr><th>Bagian yang diperbarui</th><th>Sesudah</th></tr></thead>
            <tbody>
              <?php foreach ($clinicReport['settings'] as $s): ?>
                <tr><td class="small"><?= e($s['label']) ?></td><td class="small"><?= e(short_text((string)$s['after'], 90)) ?></td></tr>
              <?php endforeach; ?>
              <?php foreach ($clinicReport['branches'] as $b): ?>
                <tr><td class="small">Nama cabang <?= e($b['code']) ?></td><td class="small"><?= e($b['after']) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$clinicReport['settings'] && !$clinicReport['branches']): ?>
                <tr><td class="small" colspan="2">Tidak ada teks pengaturan/nama cabang yang memuat nama lama.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="form-grid g2">
        <div class="field"><label>Nama Klinik <span class="req">*</span></label>
          <input class="input" id="clinicNameInput" name="clinic_name" maxlength="60" required
                 autocomplete="off" autocapitalize="words" spellcheck="false"
                 value="<?= e($clinicNow) ?>" placeholder="mis. Immoderma Skin Clinic">
          <span class="hint">2–60 karakter. Gunakan huruf, angka, spasi, dan tanda baca umum
            (mis. <code>&amp;</code>, <code>-</code>, <code>.</code>) agar tampil rapi di struk &amp; laporan PDF.</span>
          <span class="hint" id="clinicSaveHint">Nama yang akan disimpan:
            <strong id="clinicSaveValue"><?= e($clinicNow) ?></strong></span>
          <span class="hint" id="clinicSameWarn" style="display:none;color:#B3261E">
            Sama dengan nama saat ini — menekan Simpan tidak akan mengubah apa pun. Ketik nama baru dulu.</span></div>
        <div class="field"><label>Nama saat ini</label>
          <input class="input" value="<?= e($clinicNow) ?>" disabled>
          <span class="hint">Terakhir diubah:
            <?php $cnAudit = one("SELECT created_at, user_name FROM audit_logs WHERE action = 'Ubah Nama Klinik' ORDER BY id DESC LIMIT 1");
                  echo $cnAudit ? e(tgl($cnAudit['created_at'], true)) . ' oleh ' . e($cnAudit['user_name']) : 'tidak tercatat (belum pernah diubah)'; ?>
          </span></div>
      </div>

      <div class="section-title">Pratinjau langsung</div>
      <div class="grid g2">
        <div style="background:var(--sidebar-grad,linear-gradient(135deg,#8E0E42,#C2185B));border-radius:12px;padding:14px 16px;color:#fff">
          <div class="small" style="opacity:.85">Sidebar / halaman login</div>
          <div class="brand-text mt-1"><span class="brand-name" style="color:#fff" data-clinic-name><?= e($clinicNow) ?></span></div>
          <div class="small mt-1" style="opacity:.85">Judul tab: <span data-clinic-name><?= e($clinicNow) ?></span> · Dashboard</div>
        </div>
        <div style="background:#fff;border:1px dashed var(--line);border-radius:12px;padding:14px 16px">
          <div class="small muted">Kepala struk &amp; dokumen (bila logo belum diunggah)</div>
          <div class="mt-1"><strong data-clinic-name><?= e($clinicNow) ?></strong> Management System</div>
          <div class="small muted">Pengirim email: <span data-clinic-name><?= e($clinicNow) ?></span></div>
          <div class="small muted mt-1">Nama cabang: <span id="clinicBranchPreview"><?= e(implode(' · ', $clinicBranchNames)) ?></span></div>
        </div>
      </div>

      <div class="section-title">Ikut diperbarui otomatis</div>
      <div class="flex flex-wrap gap-sm" style="align-items:center">
        <?php /* Input tersembunyi memastikan pilihan "jangan diperbarui" benar-benar
                 terkirim (checkbox yang tidak dicentang TIDAK dikirim peramban). */ ?>
        <input type="hidden" name="update_templates" value="0">
        <label class="small" style="display:flex;gap:8px;align-items:center">
          <input type="checkbox" name="update_templates" value="1" checked>
          Teks template WA/email, catatan struk, catatan kartu member, nama pengirim email</label>
        <input type="hidden" name="update_branches" value="0">
        <label class="small" style="display:flex;gap:8px;align-items:center">
          <input type="checkbox" name="update_branches" value="1" checked>
          Nama cabang yang memuat nama lama</label>
      </div>
      <div class="section-title">Verifikasi</div>
      <div class="form-grid g2">
        <div class="field"><label>Password Super Admin <span class="req">*</span></label>
          <input class="input" type="password" name="password" required autocomplete="current-password">
          <span class="hint">Diperiksa di server. Password salah = nama klinik TIDAK berubah.</span></div>
        <div class="field"><label>Alasan perubahan (opsional, tercatat di audit log)</label>
          <input class="input" name="reason" placeholder="mis. rebranding klinik"></div>
      </div>

      <div class="section-title">Nama klinik dipakai di</div>
      <ul class="small muted" style="margin:0;padding-left:20px">
        <?php foreach (clinic_name_places() as $place): ?><li><?= e($place) ?></li><?php endforeach; ?>
      </ul>

      <?php if ($clinicReport && $clinicReport['leftovers']): ?>
      <div class="alert alert-warning mt-2">
        <strong>Perlu diperiksa sendiri (<?= num(count($clinicReport['leftovers'])) ?> tempat):</strong>
        bagian berikut masih memuat nama lama dan <strong>tidak diubah otomatis</strong> karena itu keputusan
        Anda (mis. domain email klinik).
        <div class="table-wrap mt-2">
          <table class="tbl">
            <thead><tr><th>Bagian</th><th>Isi sekarang</th></tr></thead>
            <tbody>
              <?php foreach ($clinicReport['leftovers'] as $lo): ?>
                <tr><td class="small"><?= e($lo['label']) ?></td><td class="small"><?= e($lo['text']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="notice mt-2">
        Dokumen yang <strong>sudah pernah dicetak/dikirim</strong> (struk lama, PDF laporan lama) tidak berubah.
      </div>

      <?php
      $logoCustom = (string)setting('logo_file') !== '' || (string)setting('logo_url') !== '';
      $logoBawaanIkutTampil = !$logoCustom && !brand_is_default_name();
      ?>
      <?php if ($logoBawaanIkutTampil): ?>
      <div class="alert alert-warning mt-2">
        <strong>Logo:</strong> logo bawaan aplikasi memuat tulisan merek awal, jadi
        <strong>tidak lagi ditampilkan</strong> setelah nama klinik diganti — sidebar/login kini memakai
        lambang netral + nama klinik baru. Unggah logo klinik Anda di kartu
        <a href="#identitas">Identitas Klinik</a> agar tampilan sepenuhnya sesuai merek baru.
      </div>
      <?php elseif ($logoCustom): ?>
      <div class="alert alert-info mt-2">
        <strong>Logo:</strong> Anda memakai logo yang diunggah sendiri sehingga <strong>tidak ikut berubah</strong>
        oleh penggantian nama ini — periksa/unggah ulang di kartu <a href="#identitas">Identitas Klinik</a>
        bila logo tersebut masih memuat tulisan merek lama.
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Simpan Nama Klinik (2x konfirmasi)</button>
    </div>
  </form>
</div>
<script>
(function () {
  var input = document.getElementById('clinicNameInput');
  if (!input) return;
  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-clinic-name]'));
  var branches = <?= js_json($clinicBranchNames) ?>;
  var current = <?= js_json($clinicNow) ?>;
  var esc = function (s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, function (m) { return '\\' + m; });
  };
  var clean = function (v) { return v.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, ''); };
  function apply() {
    var raw = clean(input.value);
    var v = raw || current;
    boxes.forEach(function (el) { el.textContent = v; });
    var list = document.getElementById('clinicBranchPreview');
    if (list && current) {
      var re = new RegExp(esc(current), 'gi');
      list.textContent = branches.map(function (n) { return n.replace(re, v); }).join(' · ');
    }
    /* Perlihatkan nama yang BENAR-BENAR akan disimpan, dan tandai bila namanya
       sama dengan nama saat ini (menekan Simpan tidak akan mengubah apa pun). */
    var shown = document.getElementById('clinicSaveValue');
    var warn = document.getElementById('clinicSameWarn');
    if (shown) shown.textContent = raw === '' ? current : raw;
    if (warn) {
      warn.style.display = (raw !== '' && raw.toLowerCase() === current.toLowerCase())
        ? 'inline' : 'none';
    }
  }
  input.addEventListener('input', apply);
  input.addEventListener('change', apply);
  apply();

  /* Kotak konfirmasi berat (dari app.js) berisi kata kunci; sebelum pengguna
     menekan "Lanjutkan", kotak itu juga menampilkan NAMA yang akan disimpan.
     Ini pengaman terhadap nilai kolom yang hilang/tertimpa peramban (mis. karena
     papan ketik HP belum menyelesaikan ketikan): pengguna melihat nama yang
     benar-benar akan dikirim sebelum menyetujui.

     PENTING: modal #confirmHeavy baru ada di akhir dokumen (dibuat page_foot),
     jadi penyiapan ini dijalankan setelah dokumen selesai dimuat. */
  function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function setupModal() {
    var modal = document.getElementById('confirmHeavy');
    if (!modal) return;
    function patch() {
      if (!modal.classList.contains('open')) return;
      /* Hanya untuk form Nama Klinik: kata kunci konfirmasi form ini unik
         ("UBAH NAMA KLINIK"), sedangkan modal yang sama dipakai form lain
         (HAPUS, ISI DATA DEMO, dst.) yang tidak boleh diberi keterangan ini. */
      var word = document.getElementById('confirmHeavyWord');
      if (!word || word.textContent.trim().toUpperCase() !== 'UBAH NAMA KLINIK') return;
      var text = document.getElementById('confirmHeavyText');
      if (!text || text.dataset.clinicPatched === '1') return;
      text.dataset.clinicPatched = '1';
      var raw = clean(input.value);
      var box = document.createElement('div');
      box.className = 'notice mt-2';
      box.id = 'confirmHeavyClinic';
      if (raw === '' || raw.toLowerCase() === current.toLowerCase()) {
        box.innerHTML = '<strong>Nama yang akan disimpan SAMA dengan nama saat ini: ' + escHtml(current)
          + '</strong>. Tidak ada yang akan berubah. Tutup kotak ini (Batalkan), ketik nama baru pada kolom '
          + '"Nama Klinik", lalu tekan Simpan lagi.';
      } else {
        box.innerHTML = 'Nama klinik yang akan disimpan: <strong>' + escHtml(raw)
          + '</strong> (dari "' + escHtml(current) + '"). Pastikan sudah benar sebelum menekan Lanjutkan.';
      }
      text.appendChild(box);
    }
    /* Modal dibuka/ditutup app.js dengan mengubah kelas .open — pantau itu. */
    if (window.MutationObserver) {
      new MutationObserver(function () {
        if (!modal.classList.contains('open')) return;
        var box = document.getElementById('confirmHeavyClinic');
        if (box) box.remove();
        var t = document.getElementById('confirmHeavyText');
        if (t) t.dataset.clinicPatched = '';
        patch();
      }).observe(modal, { attributes: true, attributeFilter: ['class'] });
    }
    if (input.form) input.form.addEventListener('submit', function () { patch(); });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupModal);
  } else {
    setupModal();
  }
})();
</script>

<?php if (is_super()): ?>
<div class="card" id="kompresi">
  <div class="card-head"><h3>Kompresi Foto &amp; Gambar</h3>
    <span><?= img_gd() ? badge('Kompresi otomatis aktif', 'green') : badge('GD tidak tersedia', 'yellow') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="photo">
    <div class="card-body">
      <div class="alert alert-<?= img_gd() ? 'info' : 'warning' ?>">
        <?php if (img_gd()): ?>
          Setiap foto yang diunggah <strong>diperkecil &amp; dikompres otomatis</strong> sesuai pengaturan di bawah,
          sehingga penyimpanan tetap hemat meskipun dipakai untuk ribuan pasien. Foto klinis (rekam medis) dibuat
          lebih besar karena detailnya penting untuk diagnosis.
          Foto diperbaiki orientasinya (EXIF) dan foto pasien/tenaga medis bersifat privat (hanya bisa dibuka user yang berhak).
        <?php else: ?>
          Kompresi otomatis memerlukan ekstensi GD yang tidak terdeteksi di server ini. Foto akan disimpan apa adanya
          setelah diperiksa ukurannya (bukan diklaim sudah dikompres).
        <?php endif; ?>
      </div>
      <div class="form-grid g3">
        <div class="field"><label>Foto Pasien — maksimal (px)</label>
          <input class="input" type="number" min="120" max="1200" name="photo_max_patient" value="<?= e(setting('photo_max_patient', '480')) ?>">
          <span class="hint">480 px ≈ 20–40 KB per foto. Untuk 5.000 pasien ≈ 100–200 MB bila semua berfoto.</span></div>
        <div class="field"><label>Foto Dokter/Terapis — maksimal (px)</label>
          <input class="input" type="number" min="120" max="1200" name="photo_max_staff" value="<?= e(setting('photo_max_staff', '480')) ?>"></div>
        <div class="field"><label>Foto Rekam Medis — maksimal (px)</label>
          <input class="input" type="number" min="400" max="2400" name="photo_max_medical" value="<?= e(setting('photo_max_medical', '1400')) ?>">
          <span class="hint">Perbesar bila foto klinis perlu detail lebih tinggi (berkas jadi lebih besar).</span></div>
        <div class="field"><label>Logo — maksimal (px)</label>
          <input class="input" type="number" min="200" max="2000" name="photo_max_logo" value="<?= e(setting('photo_max_logo', '800')) ?>"></div>
        <div class="field"><label>Mutu JPEG</label>
          <input class="input" type="number" min="40" max="95" name="photo_quality" value="<?= e(setting('photo_quality', '80')) ?>">
          <span class="hint">80 = keseimbangan baik antara ketajaman dan ukuran berkas.</span></div>
        <div class="field"><label>&nbsp;</label>
          <button class="btn btn-primary btn-block" type="submit">Simpan Pengaturan Kompresi</button></div>
      </div>
      <div class="notice mt-2">Berkas yang <strong>bukan gambar</strong> (mis. PDF pada lampiran rekam medis) tidak dikompres,
        hanya diperiksa tipe dan ukurannya.</div>
    </div>
  </form>
</div>
<?php endif; ?>

<?php
/* Kartu "Berkas Gambar Tidak Terpakai" — supaya pemilik klinik dapat
   membersihkan sendiri berkas logo/latar kartu lama (beserta cache PDF-nya)
   tanpa perlu meminta bantuan pengembang. */
$ugc = is_super() ? upload_gc_scan(true) : null;   // dengan daftar contoh berkas
?>
<?php if ($ugc): ?>
<div class="card" id="berkasgambar">
  <div class="card-head">
    <h3>Berkas Gambar Tidak Terpakai</h3>
    <span><?= $ugc['orphan_files'] > 0
        ? badge(num($ugc['orphan_files']) . ' berkas · ' . upload_gc_size((int)$ugc['orphan_bytes']), 'yellow')
        : badge('Bersih', 'green') ?></span>
  </div>
  <div class="card-body">
    <div class="alert alert-<?= $ugc['orphan_files'] > 0 ? 'warning' : 'info' ?>">
      <?php if ($ugc['orphan_files'] > 0): ?>
        Ada <strong><?= num($ugc['orphan_files']) ?> berkas</strong> (<?= e(upload_gc_size((int)$ugc['orphan_bytes'])) ?>)
        di folder unggahan yang <strong>tidak dipakai lagi</strong> — sisa logo/latar kartu member yang
        pernah diunggah lalu diganti, beserta berkas cache yang dibuat saat mencetak PDF.
        Menghapusnya <strong>tidak memengaruhi tampilan, struk, atau data apa pun</strong>.
      <?php else: ?>
        Tidak ada berkas tak terpakai. Folder unggahan hanya berisi berkas yang masih dipakai.
      <?php endif; ?>
    </div>

    <div class="grid g4">
      <div class="stat"><span class="lbl">Berkas dipakai</span><span class="val"><?= num($ugc['used_files']) ?></span>
        <span class="sub"><?= e(upload_gc_size((int)$ugc['used_bytes'])) ?></span></div>
      <div class="stat"><span class="lbl">Berkas tak terpakai</span><span class="val"><?= num($ugc['orphan_files']) ?></span>
        <span class="sub"><?= e(upload_gc_size((int)$ugc['orphan_bytes'])) ?></span></div>
      <div class="stat"><span class="lbl">Foto pasien / staf / RM</span>
        <span class="val"><?= num(array_sum($ugc['subdirs'])) ?></span>
        <span class="sub">tidak pernah disentuh</span></div>
      <div class="stat"><span class="lbl">Berkas baru (&lt; 1 jam)</span><span class="val"><?= num($ugc['young_files']) ?></span>
        <span class="sub">selalu dilewati</span></div>
    </div>

    <div class="notice mt-2">
      Yang dibersihkan hanya berkas di <strong>akar folder unggahan</strong> dengan nama berawalan
      <code><?= e(implode('</code>, <code>', $ugc['prefixes'])) ?></code> yang namanya <strong>tidak
      tercatat di Pengaturan</strong> (logo &amp; latar kartu member yang sedang dipakai selalu dipertahankan).
      Foto pasien, dokter, terapis, dan lampiran rekam medis berada di folder terpisah dan
      <strong>tidak pernah ikut terhapus</strong>. Berkas yang baru diubah kurang dari 1 jam juga
      dilewati, supaya unggahan yang sedang berjalan tidak terhapus sebelum setelannya tersimpan.
    </div>

    <?php if ($ugc['orphan_files'] > 0): ?>
    <form method="post" class="mt-2"
          data-confirm="Hapus <?= num($ugc['orphan_files']) ?> berkas tak terpakai (<?= e(upload_gc_size((int)$ugc['orphan_bytes'])) ?>)? Logo dan latar kartu yang sedang dipakai tetap dipertahankan, begitu juga semua foto pasien/staf/rekam medis.">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_gc">
      <input type="hidden" name="_anchor" value="berkasgambar">
      <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Bersihkan <?= num($ugc['orphan_files']) ?> Berkas Tak Terpakai</button>
    </form>
    <?php if ($ugc['orphan']): ?>
    <div class="table-wrap mt-2">
      <table class="tbl">
        <thead><tr><th>Nama Berkas</th><th>Jenis</th><th>Terakhir Diubah</th><th>Ukuran</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($ugc['orphan'], 0, 30) as $o): ?>
            <tr>
              <td class="small"><?= e($o['name']) ?></td>
              <td class="small"><?= $o['child'] ? 'cache turunan' : 'berkas induk' ?></td>
              <td class="small"><?= e(tgl(date('Y-m-d H:i:s', (int)$o['mtime']), true)) ?></td>
              <td class="small"><?= e(upload_gc_size((int)$o['bytes'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($ugc['orphan']) > 30): ?>
            <tr><td class="small" colspan="4">… dan <?= num(count($ugc['orphan']) - 30) ?> berkas lainnya (semua ikut dibersihkan).</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" id="satusehat">
  <div class="card-head">
    <h3>Kamus ICD &amp; Integrasi Satu Sehat</h3>
    <span><?= (satu_sehat_configured() && setting('satu_sehat_active') === '1') ? badge('Integrasi aktif', 'green') : badge('Belum terkonfigurasi', 'yellow') ?></span>
  </div>
  <div class="card-body">
    <div class="alert alert-<?= (satu_sehat_configured() && setting('satu_sehat_active') === '1') ? 'info' : 'warning' ?>">
      <strong><?= e(satu_sehat_status_text()) ?></strong>
      <br>Sistem ini <strong>tidak</strong> mengklaim sudah terhubung ke Satu Sehat selama kredensial belum diisi.
      Kolom ICD-10 &amp; ICD-9-CM pada Rekam Medis otomatis memberi saran kode dari kamus resmi yang tersimpan di server
      (<?= num(icd_count('icd10')) ?> kode ICD-10 — <?= num(icd_translated_count()) ?> di antaranya bernama Indonesia — plus <?= num(icd_count('icd9cm')) ?> kode ICD-9-CM), yaitu standar yang sama
      dipakai Satu Sehat/INA-CBG, sehingga kode yang dipilih sudah valid sebelum dikirim.
    </div>

    <div class="grid g4">
      <div class="stat"><span class="lbl">Kamus ICD-10</span><span class="val"><?= num(icd_count('icd10')) ?></span><span class="sub"><?= num(icd_translated_count()) ?> bernama Indonesia</span></div>
      <div class="stat"><span class="lbl">Kamus ICD-9-CM</span><span class="val"><?= num(icd_count('icd9cm')) ?></span><span class="sub">kode tindakan</span></div>
      <div class="stat"><span class="lbl">Verifikasi Kode</span><span class="val" style="font-size:1.05rem">Lokal</span><span class="sub">kode di luar kamus ditolak saat simpan</span></div>
      <div class="stat"><span class="lbl">Uji Koneksi Terakhir</span>
        <span class="val" style="font-size:1.05rem">
          <?php
          $last = one("SELECT * FROM audit_logs WHERE module = 'Pengaturan' AND action LIKE 'Uji Koneksi Satu Sehat%' ORDER BY id DESC LIMIT 1");
          echo $last ? e(strpos($last['action'], 'Berhasil') !== false ? 'Berhasil' : 'Gagal') : 'Belum pernah';
          ?>
        </span>
        <span class="sub"><?= $last ? e(tgl($last['created_at'], true)) : '—' ?></span></div>
    </div>
  </div>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="satu_sehat">
    <input type="hidden" name="_anchor" value="satusehat">
    <div class="card-body" style="border-top:1px solid var(--line)">
      <div class="form-grid g3">
        <div class="field"><label>Status Integrasi</label>
          <select class="input" name="satu_sehat_active">
            <option value="0"<?= setting('satu_sehat_active') === '0' ? ' selected' : '' ?>>Nonaktif</option>
            <option value="1"<?= setting('satu_sehat_active') === '1' ? ' selected' : '' ?>>Aktif</option>
          </select>
          <span class="hint">Tidak bisa diaktifkan sebelum kredensial lengkap.</span></div>
        <div class="field"><label>Lingkungan</label>
          <select class="input" name="satu_sehat_env" id="ss_env">
            <option value="sandbox"<?= setting('satu_sehat_env') === 'sandbox' ? ' selected' : '' ?>>Sandbox (uji coba)</option>
            <option value="production"<?= setting('satu_sehat_env') === 'production' ? ' selected' : '' ?>>Production (data asli)</option>
          </select></div>
        <div class="field"><label>Organization ID</label>
          <input class="input" name="satu_sehat_org_id" value="<?= e(setting('satu_sehat_org_id')) ?>" placeholder="dari akun Satu Sehat klinik"></div>
        <div class="field"><label>Client ID</label>
          <input class="input" name="satu_sehat_client_id" value="<?= e(setting('satu_sehat_client_id')) ?>"></div>
        <div class="field"><label>Client Secret</label>
          <input class="input" type="password" name="satu_sehat_client_secret" placeholder="<?= setting('satu_sehat_client_secret') !== '' ? '•••••• (tersimpan)' : 'belum diisi' ?>"></div>
        <div class="field"><label>URL Token</label>
          <input class="input" name="satu_sehat_token_url" value="<?= e(setting('satu_sehat_token_url')) ?>">
          <span class="hint">Dapat diubah bila Kemenkes mengganti endpoint.</span></div>
        <div class="field" style="grid-column:1/-1"><label>URL FHIR Base</label>
          <input class="input" name="satu_sehat_fhir_url" value="<?= e(setting('satu_sehat_fhir_url')) ?>"></div>
      </div>
      <div class="notice mt-2">Kredensial diterbitkan oleh Kemenkes melalui akun Satu Sehat fasilitas kesehatan Anda. Tanpa kredensial tersebut,
        pengiriman data ke API Satu Sehat tidak mungkin dilakukan oleh sistem manapun — termasuk sistem ini.</div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit">Simpan Konfigurasi</button>
    </div>
  </form>

  <div class="card-body" style="border-top:1px solid var(--line)">
    <div class="flex flex-wrap gap-lg">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="satu_sehat_test">
        <button class="btn" type="submit"><?= icon('shield') ?> Uji Koneksi ke Satu Sehat</button>
      </form>
      <form method="post" data-confirm="Muat ulang kamus ICD dari file data yang disertakan aplikasi?">
        <?= csrf_field() ?><input type="hidden" name="action" value="icd_reload">
        <button class="btn" type="submit"><?= icon('database') ?> Muat Ulang Kamus ICD</button>
      </form>
      <a class="btn" href="icd.php"><?= icon('search') ?> Buka Kamus ICD</a>
    </div>
    <p class="muted mt-2 mb-0">Sumber kamus: <?= e(setting('icd_source_note')) ?>.</p>
  </div>
</div>

<div class="card" id="pemeliharaan">
  <div class="card-head">
    <h3>Mode Pemeliharaan (Maintenance)</h3>
    <span><?= $mOn ? badge('Pemeliharaan AKTIF', 'yellow') : badge('Normal', 'green') ?></span>
  </div>
  <?php if ($mOn): ?>
    <div class="alert alert-warning" style="margin:16px 20px 0">
      <strong>Mode pemeliharaan sedang aktif.</strong> Kasir dan Admin/Dokter hanya dapat <strong>melihat</strong> data —
      tambah, ubah, hapus, impor, ekspor, kirim struk WhatsApp, dan kirim laporan email dinonaktifkan sementara.
      Super Admin (Anda) tetap dapat mengelola sistem sepenuhnya.
      <a class="btn btn-sm btn-leaf" style="margin-top:8px" href="maintenance.php" target="_blank"><?= icon('activity') ?> Lihat halaman yang dilihat pengguna</a>
    </div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="maintenance">
    <input type="hidden" name="_anchor" value="pemeliharaan">
    <div class="card-body">
      <p class="muted">Pakai saat memperbaiki data, memuat ulang kamus/stok, atau migrasi. Selama aktif, halaman yang
        dibuka level lain menampilkan pengumuman pemeliharaan dan semua aksi tulis ditolak di sisi server
        (bukan sekadar tombol disembunyikan).</p>
      <div class="form-grid g2">
        <div class="field"><label>Status Pemeliharaan</label>
          <select class="input" name="maintenance_mode">
            <option value="0"<?= $mOn ? '' : ' selected' ?>>Nonaktif — semua level dapat mengelola data</option>
            <option value="1"<?= $mOn ? ' selected' : '' ?>>Aktif — selain Super Admin hanya dapat MELIHAT</option>
          </select>
          <span class="hint">Mematikan mode ini langsung memulihkan hak kelola tanpa perlu login ulang.</span></div>
        <div class="field"><label>Perkiraan Selesai (opsional)</label>
          <input class="input" type="datetime-local" name="maintenance_until"
                 value="<?= e(($mInfo['until'] !== '' && strtotime($mInfo['until'])) ? date('Y-m-d\TH:i', strtotime($mInfo['until'])) : '') ?>">
          <span class="hint">Ditampilkan di halaman pemeliharaan sebagai estimasi (mis. "± 2 jam lagi").</span></div>
        <div class="field"><label>Judul Pengumuman</label>
          <input class="input" name="maintenance_title" value="<?= e($mInfo['title']) ?>"
                 placeholder="Sistem Sedang Dalam Pemeliharaan"></div>
        <div class="field"><label>Kontak yang Bisa Dihubungi</label>
          <input class="input" name="maintenance_contact_name" value="<?= e($mInfo['contact_name']) ?>" placeholder="mis. Admin <?= e(clinic_name()) ?>">
          <input class="input mt-1" name="maintenance_contact_phone" value="<?= e($mInfo['contact_phone']) ?>" placeholder="mis. 0812xxxxxxx (dibuat tautan WhatsApp)"></div>
        <div class="field" style="grid-column:1/-1"><label>Pesan untuk Pengguna</label>
          <textarea class="input" name="maintenance_message" rows="3"
                    placeholder="Kami sedang memperbarui data harga dan stok per <?= e(date('j M Y')) ?>. Terima kasih atas kesabaran Anda."><?= e($mInfo['message']) ?></textarea>
          <span class="hint">Kosongkan untuk memakai pesan bawaan yang sudah menjelaskan aturan mode lihat.</span></div>
        <div class="field" style="grid-column:1/-1"><label>Catatan Internal (opsional, ikut tampil)</label>
          <input class="input" name="maintenance_note" value="<?= e($mInfo['note']) ?>" placeholder="mis. perkiraan selesai pukul 15.00 WIB"></div>
      </div>
      <div class="notice mt-2">
        Yang <strong>tetap berjalan</strong> saat pemeliharaan: semua halaman tetap dapat dibuka (isi data terlihat),
        kotak pencarian &amp; filter tetap bekerja, foto/lampiran tetap dapat dibuka, dan Super Admin bebas penuh.
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Simpan Mode Pemeliharaan</button>
      <?php if ($mOn): ?><a class="btn" href="maintenance.php" target="_blank">Buka Halaman Pemeliharaan</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card" id="umum">
  <div class="card-head">
    <h3>Pengaturan Umum</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="system">
    <div class="card-body">
      <p class="muted">Kartu ini <strong>dipindah dari Pengaturan Sistem</strong> supaya hanya Super Admin
        yang dapat mengubahnya. Berisi pengaturan yang memengaruhi semua pengguna: jumlah baris data per
        halaman dan tampilan akun bawaan di halaman login.</p>
      <div class="form-grid g2">
        <div class="field"><label>Data Per Halaman (default)</label>
          <select class="input" name="default_per_page">
            <?php foreach ([10, 25, 50, 100] as $n): ?>
              <option value="<?= $n ?>"<?= setting('default_per_page') === (string)$n ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Banyaknya baris pada daftar/tabel sebelum dipisah per halaman.</span></div>
        <div class="field"><label>Tampilkan Akun Bawaan di Halaman Login</label>
          <select class="input" name="show_login_hint">
            <option value="1"<?= setting('show_login_hint', '1') === '1' ? ' selected' : '' ?>>Ya (memudahkan admin baru)</option>
            <option value="0"<?= setting('show_login_hint', '1') === '0' ? ' selected' : '' ?>>Tidak (lebih aman untuk produksi)</option>
          </select>
          <span class="hint">Hanya akun yang <strong>sandinya masih sandi bawaan</strong> yang ditampilkan —
            begitu sandinya diganti, akun itu otomatis hilang dari halaman login.</span></div>
      </div>

      <div class="section-title">Peran Akun Bawaan yang Ditampilkan</div>
      <div class="field">
        <label class="muted small">Centang peran yang akunnya ingin ditampilkan di halaman login</label>
        <div class="flex flex-wrap gap-sm" style="gap:14px">
          <?php $selRoles = login_hint_roles(); foreach (login_hint_roles_all() as $rc => $rl): ?>
            <label class="check"><input type="checkbox" name="login_hint_roles[]" value="<?= e($rc) ?>"
              <?= in_array($rc, $selRoles, true) ? 'checked' : '' ?>> <span><?= e($rl) ?></span></label>
          <?php endforeach; ?>
        </div>
        <span class="hint">Contoh: centang <strong>Direktur / Owner</strong> dan <strong>Kasir</strong> saja bila
          Super Admin &amp; Admin/Dokter tidak perlu ditampilkan. Bila tidak ada yang dicentang, daftar akun
          tidak ditampilkan sama sekali.</span>
      </div>

      <details class="mt-2">
        <summary class="small muted" style="cursor:pointer">Lihat akun bawaan yang SEDANG tampil di halaman login</summary>
        <?php $hintNow = login_hint_accounts(); ?>
        <?php if ($hintNow): ?>
          <div class="table-wrap mt-1"><table class="tbl">
            <thead><tr><th>Peran</th><th>Nama</th><th>Email</th><th>Cabang</th></tr></thead>
            <tbody>
              <?php foreach ($hintNow as $h): ?>
                <tr><td><?= e($h['role_label']) ?></td><td><?= e($h['name']) ?></td>
                  <td><?= e($h['email']) ?></td><td><?= e($h['branch'] !== '' ? $h['branch'] : '-') ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="small muted mt-1"><?= num(count($hintNow)) ?> akun masih memakai kata sandi bawaan
            (mereka inilah yang muncul di halaman login).</div>
        <?php else: ?>
          <div class="notice small mt-1">Tidak ada akun bawaan yang ditampilkan — entah karena petunjuk dimatikan,
            tidak ada peran yang dicentang, atau semua kata sandi bawaan sudah diganti.</div>
        <?php endif; ?>
      </details>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Pengaturan Umum</button>
    </div>
  </form>
</div>

<?php /* Kartu keamanan login & penyiapan 2FA hanya untuk Super Admin (dokumen
   dan pengaturannya menyentuh seluruh akun). */ ?>
<?php if (is_super()): ?>
<div class="card" id="keamanan">
  <div class="card-head">
    <h3>Keamanan Login</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?>
      <?= badge(mail_configured() ? 'Email siap' : 'Email belum dikonfigurasi', mail_configured() ? 'green' : 'yellow') ?></span>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="security">
    <div class="card-body">
      <p class="muted">Mengatur <strong>"Ingat saya"</strong>, <strong>keluar otomatis karena tidak aktif</strong>,
        dan <strong>level yang diwajibkan verifikasi 2 langkah</strong>. Pengaturan ini berlaku untuk semua
        pengguna.</p>
      <div class="form-grid g2">
        <div class="field"><label>Sistem keamanan sesi</label>
          <select class="input" name="login_security_enabled">
            <option value="1"<?= login_security()['enabled'] ? ' selected' : '' ?>>Aktif (disarankan)</option>
            <option value="0"<?= !login_security()['enabled'] ? ' selected' : '' ?>>Nonaktif (sesi tanpa batas)</option>
          </select>
          <span class="hint">Bila nonaktif: tidak ada keluar-otomatis dan "Ingat saya" tidak diperpanjang.</span></div>
        <div class="field"><label>Durasi "Ingat saya" (bila dicentang)</label>
          <select class="input" name="remember_days">
            <?php foreach ([1 => '1 hari', 3 => '3 hari', 7 => '7 hari'] as $v => $l): ?>
              <option value="<?= $v ?>"<?= login_security()['remember_days'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Setelah masa ini lewat, pengguna diminta masuk kembali.</span></div>
        <div class="field"><label>Keluar otomatis bila TIDAK dicentang (tidak aktif)</label>
          <select class="input" name="idle_hours">
            <?php foreach ([1 => '1 jam', 3 => '3 jam', 5 => '5 jam', 8 => '8 jam'] as $v => $l): ?>
              <option value="<?= $v ?>"<?= login_security()['idle_hours'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Dihitung dari aktivitas terakhir (membuka menu, menyimpan, dll).</span></div>
        <div class="field"><label>Wajib verifikasi 2 langkah untuk</label>
          <select class="input" name="second_factor_levels">
            <?php foreach (twofa_level_options() as $k => $l): ?>
              <option value="<?= e($k) ?>"<?= login_security()['scope'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">2FA baru benar-benar diminta setelah pemilik level itu menyiapkan
            Google Authenticator-nya (lihat kartu di bawah).</span></div>
      </div>
      <div class="notice small mt-2">
        <strong>Lupa kata sandi / kehilangan akses:</strong> halaman masuk kini punya tautan
        <em>"Lupa kata sandi?"</em> (kirim tautan ganti sandi ke email akun) dan
        akun yang mengaktifkan 2FA memegang <strong>kode pemulihan</strong> yang bekerja tanpa email.
        Bila email belum dikonfigurasi, gunakan alat reset darurat di server
        (<code>naveena_dev/tools/reset_password.php</code>).
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit"><?= icon('save') ?> Simpan Pengaturan Keamanan</button>
    </div>
  </form>
</div>

<?php /* Kartu penyiapan 2FA untuk akun Super Admin yang sedang login
   (komponen yang sama dipakai halaman Profil Saya). */ ?>
<?php twofa_render_card($user, 'developer.php', 'keamanan2fa'); ?>
<?php endif; /* is_super(): kartu keamanan login & 2FA */ ?>

<?php /* id sengaja `dokumenfungsi` (BUKAN `ringkasan`) — id itu sudah dipakai kartu
   "Ringkasan Cepat" di atas; id kembar membuat JS/uji mengambil kartu yang salah.
   Kartunya hanya dirender untuk Super Admin (dokumen memuat struktur basis data). */ ?>
<?php if (is_super()): ?>
<div class="card" id="dokumenfungsi">
  <div class="card-head">
    <h3>Ringkasan Fungsi Aplikasi (PDF)</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?> · <?= badge('Untuk migrasi / serah terima', 'green') ?></span>
  </div>
  <div class="card-body">
    <p class="muted">Dokumen acuan berisi <strong>kesimpulan fungsi aplikasi ini</strong> — dibuat untuk dibaca
      AI agent atau developer di sistem lain ketika aplikasi ini dimigrasikan atau dikembangkan lebih lanjut.
      Isinya <strong>diambil otomatis dari aplikasi yang sedang berjalan</strong>, jadi setiap kali diunduh ulang
      dokumennya sudah memuat keadaan terbaru: daftar modul &amp; kegunaannya, peran &amp; hak akses, struktur
      tabel basis data beserta jumlah datanya, status integrasi (email, WhatsApp, pembayaran, Satu Sehat),
      aturan bisnis wajib beserta berkas tempat aturannya ditegakkan, dan catatan tambahan dari Anda.</p>
    <div class="notice small">
      <strong>Rahasia tidak pernah ditulis:</strong> kunci API, sandi SMTP, token WhatsApp, dan kunci payment
      gateway hanya dilaporkan sebagai <em>“sudah diisi / belum diisi”</em>. Dokumen ini juga aman dibagikan
      ke developer baru karena tidak memuat data pasien.
    </div>
    <div class="flex gap-sm flex-wrap mt-2">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="app_summary">
        <button class="btn btn-primary" type="submit"><?= icon('download') ?> Unduh PDF Ringkasan Fungsi</button>
      </form>
      <a class="btn" href="<?= e(platform_link('pro.php')) ?>" target="_blank" rel="noopener">Panduan VibeCoder Pro</a>
      <span class="muted small">Ukuran dokumen ± 4 halaman A4; diunduh langsung dari server (tidak dikirim ke mana pun).</span>
    </div>

    <div class="section-title mt-3">Catatan tambahan dari pemilik klinik</div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="app_summary_note">
      <div class="field">
        <label>Catatan yang ikut dicetak pada dokumen</label>
        <textarea class="input" name="app_summary_note" rows="5"
          placeholder="mis. aturan khusus klinik, daftar fitur yang direncanakan, atau hal yang harus diperhatikan agent saat mengubah aplikasi ini (maksimal 4000 karakter)."><?= e(app_summary_note()) ?></textarea>
        <span class="hint">Bagian ini muncul sebagai <strong>Bab 8</strong> pada PDF. Boleh dikosongkan.</span>
      </div>
      <div class="flex gap-sm flex-wrap mt-1" style="align-items:center">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('save') ?> Simpan Catatan</button>
        <span class="muted small">Dokumen PDF selalu diperbarui otomatis saat diunduh — tidak perlu “memperbarui” dokumen secara manual.</span>
      </div>
    </form>
  </div>
</div>

<?php endif; /* is_super(): kartu dokumen ringkasan fungsi */ ?>

<div class="card" id="hapusdata" style="border-color:#F5C9C6">
  <div class="card-head">
    <h3 style="color:#B3261E">Hapus Semua Data (Kosongkan Sistem)</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <div class="card-body">
    <p class="muted">Mengosongkan <strong>seluruh data operasional klinik</strong> supaya aplikasi siap diisi data
      klinik baru — dipakai saat aplikasi ini <strong>diduplikasi untuk klinik lain</strong>. Fungsi aplikasi tetap
      berjalan penuh setelahnya (semua menu aktif, siap diisi data baru).</p>
    <div class="grid g2">
      <div class="field"><label>Akan dihapus</label>
        <div class="small"><?php foreach ($wipeCounts as $k => $v): ?>
          · <?= e($k) ?> <strong><?= num($v) ?></strong><br><?php endforeach; ?></div>
      </div>
      <div class="field"><label>Tetap tersimpan</label>
        <div class="small">
          · Manajemen user &amp; hak akses (login tetap bekerja)<br>
          · Pengaturan Sistem (identitas klinik, tema, email, WhatsApp, pembayaran, backup)<br>
          · Kamus ICD-10 &amp; ICD-9-CM<br>
          · Maksimal 2 cabang pertama (contoh; cabang selebihnya dihapus)<br>
          · Berkas backup yang sudah tersimpan
        </div>
      </div>
    </div>
    <div class="alert alert-warning mt-2">
      <strong>Tindakan ini permanen.</strong> Sistem membuat <strong>snapshot pengaman</strong> otomatis lebih dulu
      (dapat dipulihkan dari <a href="backup.php">Backup Database</a>), lalu meminta
      <strong>verifikasi identitas</strong> dan <strong>konfirmasi dua tahap</strong> sebelum menjalankan.
      Total data terdeteksi saat ini: <strong><?= num($wipeTotal) ?></strong> baris.
    </div>
  </div>
  <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
    <a class="btn" href="backup.php"><?= icon('database') ?> Backup Dulu</a>
    <a class="btn btn-danger" href="purge.php?menu=semua"><?= icon('trash') ?> Hapus Semua Data</a>
  </div>
</div>

<div class="card" id="datademo">
  <div class="card-head">
    <h3>Isi Data Demo</h3>
    <span><?= badge('Khusus Super Admin', 'pink') ?></span>
  </div>
  <div class="card-body">
    <p class="muted">Mengisi aplikasi dengan <strong>data contoh lengkap</strong> agar semua menu, laporan, dan
      grafik langsung terlihat hidup: 2 cabang, 30 pasien, master treatment/skincare/bahan treatment,
      rekam medis, reservasi, dan <strong>transaksi tiap cabang dari 1 November 2025 s.d. 5 Oktober 2026</strong>.</p>
    <div class="notice">
      Transaksi demo dibuat lewat jalur kasir yang sama (harga, stok, diskon member, dan nomor invoice dihitung
      server), sehingga laporan &amp; grafik menampilkan hasil perhitungan sungguhan — dan diisi
      <strong>pada rentang 1 November 2025 s.d. 5 Oktober 2026</strong>. Tombol ini bersifat
      <strong>melengkapi</strong>: pengisian ulang melewati hari yang sudah memiliki transaksi dan hanya mengisi
      hari kosong dalam rentang tersebut. Data demo dapat dihapus kembali dengan tombol <strong>Hapus Semua Data</strong> di atas —
      keduanya sudah terintegrasi.
    </div>
    <p class="muted small mt-2">Saat ini: <?= num((int)scalar('SELECT COUNT(*) FROM patients')) ?> pasien ·
      <?= num((int)scalar('SELECT COUNT(*) FROM orders')) ?> transaksi ·
      <?= num((int)scalar('SELECT COUNT(*) FROM medical_records')) ?> rekam medis ·
      <?= num((int)scalar('SELECT COUNT(*) FROM branches')) ?> cabang.</p>
  </div>
  <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
    <a class="btn btn-primary" href="demo_data.php"><?= icon('sparkles') ?> Isi Data Demo</a>
  </div>
</div>

<?php page_foot(); ?>
