<?php
/**
 * RETENSI DATA — penghapusan otomatis & manual data lama.
 *
 * Dipakai untuk menjaga ukuran database tetap wajar:
 *   1. Data JEJAK (Stok & Movement dan Audit Log) — dihapus otomatis bila lebih
 *      lama dari 1 minggu / 2 minggu / 1 bulan / 3 bulan / 6 bulan / 1 tahun.
 *   2. Data OPERASIONAL per menu (Reservasi, Rekam Medis, Riwayat Order) —
 *      dihapus otomatis bila lebih lama dari 3 bulan … 7 tahun, DIATUR PER MENU
 *      (mis. reservasi 6 bulan, rekam medis 5 tahun, riwayat order 1 tahun).
 *   3. Tombol HAPUS MANUAL per periode (1 bulan … 7 tahun) pada tiap menu.
 *
 * Semua hanya untuk Super Admin: halaman/pengaturan di Developer Settings dan
 * tombol hapus manual tidak ditampilkan kepada level lain, dan penegakannya
 * ada di SERVER (bukan sekadar menyembunyikan tombol).
 *
 * PENTING (jujur ke pengguna): penghapusan retensi TIDAK mengembalikan stok.
 * Data yang dihapus sudah berumur bulanan/tahunan; stok yang berlaku sekarang
 * adalah stok nyata hasil opname/penjualan terakhir, jadi menambahkan kembali
 * kuantitas penjualan lama justru akan membuat stok salah. Pengembalian stok
 * hanya dilakukan oleh fitur "Hapus Semua Data" (yang memang mengosongkan
 * seluruh riwayat sekaligus).
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * Pilihan periode
 * ------------------------------------------------------------------ */

/** Periode untuk data jejak (stok movement & audit log). */
function retention_log_periods(): array
{
    return ['7d' => '1 minggu', '14d' => '2 minggu', '1m' => '1 bulan',
            '3m' => '3 bulan', '6m' => '6 bulan', '1y' => '1 tahun'];
}

/** Periode untuk data operasional per menu. */
function retention_menu_periods(): array
{
    return ['3m' => '3 bulan', '6m' => '6 bulan', '1y' => '1 tahun',
            '3y' => '3 tahun', '5y' => '5 tahun', '7y' => '7 tahun'];
}

/** Periode untuk tombol HAPUS MANUAL. */
function retention_manual_periods(): array
{
    return ['1m' => '1 bulan', '3m' => '3 bulan', '6m' => '6 bulan', '1y' => '1 tahun',
            '2y' => '2 tahun', '3y' => '3 tahun', '5y' => '5 tahun', '7y' => '7 tahun'];
}

/**
 * Periode untuk pembersihan AKTIVITAS AKUN ("Aktivitas Saya Terbaru" pada
 * halaman Akun Saya) — dijalankan setiap pengguna login.
 */
function activity_periods(): array
{
    return ['7d' => '7 hari', '14d' => '14 hari', '30d' => '1 bulan (30 hari)',
            '60d' => '2 bulan (60 hari)', '90d' => '3 bulan (90 hari)'];
}

function retention_period_label(?string $period): string
{
    $all = retention_manual_periods() + retention_log_periods() + activity_periods();
    return $all[(string)$period] ?? '—';
}

/**
 * Opsi periode yang SAH untuk sebuah menu. Sebagian menu punya daftarnya sendiri
 * (mis. "Pasien Tidak Aktif" hanya 3/5/7 tahun), selebihnya mengikuti kelompoknya.
 */
function retention_period_options(string $menu): array
{
    $meta = retention_meta($menu);
    if (!empty($meta['period_options'])) return $meta['period_options'];
    return $meta['group'] === 'log' ? retention_log_periods() : retention_menu_periods();
}

/** Periode bawaan sebuah menu (dipakai bila setelannya belum ada/tidak sah). */
function retention_default_period(string $menu): string
{
    $meta = retention_meta($menu);
    if (!empty($meta['default_period'])) return (string)$meta['default_period'];
    return $meta['group'] === 'log' ? '1m' : '1y';
}

/**
 * Batas waktu (timestamp lokal) untuk sebuah periode.
 * Contoh: '6m' → tepat 6 bulan sebelum hari ini.
 */
function retention_cutoff(string $period, ?int $ts = null): string
{
    $ts = $ts ?? time();
    $map = ['7d' => '-7 days', '14d' => '-14 days', '30d' => '-30 days', '60d' => '-60 days',
            '90d' => '-90 days', '1m' => '-1 month', '2m' => '-2 months',
            '3m' => '-3 months', '6m' => '-6 months', '1y' => '-1 year', '2y' => '-2 years',
            '3y' => '-3 years', '5y' => '-5 years', '7y' => '-7 years'];
    $mod = $map[$period] ?? null;
    if ($mod === null) throw new RuntimeException('Periode retensi tidak dikenal: ' . $period);
    return date('Y-m-d H:i:s', strtotime($mod, $ts));
}

/** Batas waktu dalam bentuk tanggal saja (untuk kolom tanggal seperti reservasi). */
function retention_cutoff_date(string $period, ?int $ts = null): string
{
    return substr(retention_cutoff($period, $ts), 0, 10);
}

/* ------------------------------------------------------------------ *
 * Definisi target penghapusan
 * ------------------------------------------------------------------ */

/**
 * Daftar menu yang mendukung retensi.
 *
 * Setiap langkah ditulis sebagai pernyataan DELETE dengan dua penanda:
 *   {CUT} = batas waktu (di-quote otomatis, aman dari injeksi)
 *   {BR}  = pembatas cabang (" AND <kolom> = <id>" atau " AND 1=1")
 * Urutan langkah WAJIB dari tabel anak ke induk karena PRAGMA foreign_keys=ON
 * (baris anak tidak boleh ditinggal saat induknya dihapus).
 */
function retention_targets(): array
{
    return [
        'movement' => [
            'label' => 'Stok & Movement', 'short' => 'Stok Movement', 'group' => 'log',
            'back' => 'inventory_movement.php', 'perm' => 'inventory.view',
            'date_label' => 'waktu pergerakan', 'table' => 'inventory_movements',
            'warn' => 'Menghapus riwayat pergerakan stok yang lebih lama dari periode ini. '
                . 'JUMLAH STOK SAAT INI TIDAK BERUBAH — hanya jejak asal-usulnya yang hilang.',
            'steps' => [
                ['label' => 'Pergerakan stok', 'sql' => 'DELETE FROM inventory_movements WHERE created_at < {CUT}{BR}'],
            ],
        ],
        'audit' => [
            'label' => 'Audit Log', 'short' => 'Audit Log', 'group' => 'log',
            'back' => 'audit_log.php', 'perm' => 'audit.view',
            'date_label' => 'waktu tindakan', 'table' => 'audit_logs',
            'warn' => 'Menghapus catatan Audit Log yang lebih lama dari periode ini. '
                . 'Tindakan penghapusan itu sendiri tetap dicatat (satu baris jejak pembersihan).',
            'steps' => [
                ['label' => 'Audit log', 'sql' => 'DELETE FROM audit_logs WHERE created_at < {CUT}{BR}'],
            ],
        ],
        'reservasi' => [
            'label' => 'Reservasi', 'short' => 'Reservasi', 'group' => 'menu',
            'back' => 'reservasi.php', 'perm' => 'reservation.view',
            'date_label' => 'tanggal reservasi', 'table' => 'appointments',
            'warn' => 'Menghapus reservasi yang tanggal reservasinya lebih lama dari periode ini '
                . 'beserta daftar treatment-nya. Data pasien, transaksi, dan rekam medis tidak berubah.',
            'steps' => [
                ['label' => 'Treatment reservasi',
                 'sql' => 'DELETE FROM appointment_treatments WHERE appointment_id IN (SELECT id FROM appointments WHERE date < {CUT}{BR})'],
                ['label' => 'Reservasi', 'sql' => 'DELETE FROM appointments WHERE date < {CUT}{BR}'],
            ],
        ],
        'rekam_medis' => [
            'label' => 'Rekam Medis', 'short' => 'Rekam Medis', 'group' => 'menu',
            'back' => 'rekam_medis.php', 'perm' => 'medical.view',
            'date_label' => 'tanggal periksa', 'table' => 'medical_records',
            'warn' => 'Menghapus rekam medis yang tanggal periksanya lebih lama dari periode ini '
                . 'beserta foto/lampiran klinisnya (berkas foto ikut dibuang dari penyimpanan lokal '
                . 'bila ada). Data pasien dan transaksi tidak berubah.',
            'steps' => [
                ['label' => 'Foto & lampiran klinis',
                 'sql' => 'DELETE FROM medical_record_photos WHERE medical_record_id IN (SELECT id FROM medical_records WHERE date < {CUT}{BR})'],
                ['label' => 'Rekam medis', 'sql' => 'DELETE FROM medical_records WHERE date < {CUT}{BR}'],
            ],
        ],
        'pasien' => [
            /* PASIEN TIDAK AKTIF — dihapus bila kunjungan/transaksi TERAKHIR-nya
               sudah lebih lama dari periode yang dipilih (3/5/7 tahun). Pasien yang
               belum pernah bertransaksi memakai tanggal pendaftarannya sebagai
               patokan, sehingga pasien baru tidak ikut terhapus. */
            'label' => 'Pasien Tidak Aktif', 'short' => 'Pasien Tidak Aktif', 'group' => 'menu',
            'back' => 'pasien.php', 'perm' => 'patient.view',
            'date_label' => 'kunjungan/transaksi terakhir', 'table' => 'patients',
            'period_options' => ['3y' => '3 tahun', '5y' => '5 tahun', '7y' => '7 tahun'],
            'default_period' => '5y',
            'manual' => false,       // tidak ada tombol hapus manual (hanya otomatis)
            'warn' => 'Menghapus pasien yang TIDAK AKTIF lagi: kunjungan/transaksi terakhirnya '
                . 'sudah lebih lama dari periode ini. Seluruh riwayat pasien tersebut (transaksi, '
                . 'pembayaran, rekam medis + fotonya, dan reservasi) IKUT TERHAPUS karena tidak '
                . 'mungkin berdiri sendiri tanpa pasiennya. Pasien yang masih punya kunjungan '
                . 'dalam periode ini TIDAK tersentuh. STOK TIDAK DIKEMBALIKAN (data sudah lama).',
            'steps' => [
                ['label' => 'Treatment reservasi',
                 'sql' => 'DELETE FROM appointment_treatments WHERE appointment_id IN (SELECT id FROM appointments WHERE patient_id IN ({PAT}))'],
                ['label' => 'Reservasi',
                 'sql' => 'DELETE FROM appointments WHERE patient_id IN ({PAT})'],
                ['label' => 'Item transaksi',
                 'sql' => 'DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE patient_id IN ({PAT}))'],
                ['label' => 'Pembayaran',
                 'sql' => 'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE patient_id IN ({PAT}))'],
                ['label' => 'Antrean pembayaran gateway',
                 'sql' => 'DELETE FROM pay_pending WHERE order_id IN (SELECT id FROM orders WHERE patient_id IN ({PAT}))'],
                ['label' => 'Transaksi',
                 'sql' => 'DELETE FROM orders WHERE patient_id IN ({PAT})'],
                ['label' => 'Foto rekam medis',
                 'sql' => 'DELETE FROM medical_record_photos WHERE medical_record_id IN (SELECT id FROM medical_records WHERE patient_id IN ({PAT}))'],
                ['label' => 'Rekam medis',
                 'sql' => 'DELETE FROM medical_records WHERE patient_id IN ({PAT})'],
                ['label' => 'Pasien tidak aktif',
                 'sql' => 'DELETE FROM patients WHERE id IN ({PAT})'],
            ],
        ],
        'order' => [
            'label' => 'Riwayat Order', 'short' => 'Riwayat Order', 'group' => 'menu',
            'back' => 'order.php', 'perm' => 'order.view',
            'date_label' => 'waktu transaksi', 'table' => 'orders',
            'warn' => 'Menghapus transaksi yang lebih lama dari periode ini beserta item dan '
                . 'pembayarannya. Laporan & Top 5 pada periode tersebut menjadi kosong. '
                . 'STOK TIDAK DIKEMBALIKAN (data sudah lama; stok yang berlaku sekarang adalah '
                . 'stok nyata saat ini).',
            'steps' => [
                ['label' => 'Item transaksi',
                 'sql' => 'DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE created_at < {CUT}{BR})'],
                ['label' => 'Pembayaran',
                 'sql' => 'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE created_at < {CUT}{BR})'],
                ['label' => 'Antrean pembayaran lama',
                 'sql' => 'DELETE FROM pay_pending WHERE order_id IN (SELECT id FROM orders WHERE created_at < {CUT}{BR})'],
                ['label' => 'Transaksi', 'sql' => 'DELETE FROM orders WHERE created_at < {CUT}{BR}'],
            ],
        ],
    ];
}

/** Metadata retensi sebuah menu (auto + manual). */
function retention_meta(string $menu): array
{
    $t = retention_targets();
    if (!isset($t[$menu])) throw new RuntimeException('Menu retensi tidak dikenal.');
    return $t[$menu];
}

/** Kolom cabang untuk pembatas {BR} pada tabel utama menu. */
function retention_branch_col(string $menu): string
{
    $map = ['movement' => 'branch_id', 'audit' => 'branch_id',
            'reservasi' => 'branch_id', 'rekam_medis' => 'branch_id', 'order' => 'branch_id'];
    return $map[$menu] ?? 'branch_id';
}

/** Isi penanda {BR} sesuai cakupan cabang terpilih (null = semua cabang). */
function retention_branch_sql(string $menu, ?int $branchId): string
{
    $col = retention_branch_col($menu);
    return $branchId === null ? ' AND 1=1' : ' AND ' . $col . ' = ' . (int)$branchId;
}

/* ------------------------------------------------------------------ *
 * Setelan (auto)
 * ------------------------------------------------------------------ */

/** Kunci setelan auto untuk sebuah menu. */
function retention_setting_keys(string $menu): array
{
    return ['aktif' => 'retention_' . $menu . '_active', 'periode' => 'retention_' . $menu . '_period'];
}

function retention_enabled(string $menu): bool
{
    return setting(retention_setting_keys($menu)['aktif'], '0') === '1';
}

/** Periode auto sebuah menu (dibersihkan ke daftar yang sah). */
function retention_period(string $menu): string
{
    $valid = retention_period_options($menu);
    $p = (string)setting(retention_setting_keys($menu)['periode'], '');
    if (!isset($valid[$p])) $p = retention_default_period($menu);
    return $p;
}

/** Ringkasan semua menu retensi (untuk pengaturan & dokumentasi). */
function retention_overview(): array
{
    $out = [];
    foreach (retention_targets() as $menu => $meta) {
        $keys = retention_setting_keys($menu);
        $out[$menu] = $meta + [
            'aktif' => retention_enabled($menu),
            'periode' => retention_period($menu),
            'keys' => $keys,
            'period_options' => retention_period_options($menu),
            'manual' => ($meta['manual'] ?? true) !== false,
            'jumlah_siap_hapus' => retention_enabled($menu)
                ? retention_count($menu, retention_period($menu), null)['total'] : 0,
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Pelaksanaan penghapusan
 * ------------------------------------------------------------------ */

/**
 * Hitung berapa baris yang AKAN terhapus (tanpa mengubah apa pun).
 *
 * @return array{total:int,detail:array<string,int>,cutoff:string}
 */
function retention_placeholders(string $menu, string $cut, ?int $branchId): array
{
    /* {PAT} = daftar id pasien yang TIDAK AKTIF (kunjungan terakhir lebih lama
       dari batas waktu). Pasien yang belum pernah bertransaksi memakai tanggal
       pendaftaran sebagai patokan. */
    $patBr = $branchId === null ? '' : ' AND p.branch_id = ' . (int)$branchId;
    $pat = 'SELECT p.id FROM patients p WHERE COALESCE('
        . '(SELECT MAX(o.created_at) FROM orders o WHERE o.patient_id = p.id), p.created_at) < '
        . db()->quote($cut) . $patBr;
    return [
        '{CUT}' => db()->quote($cut),
        '{BR}'  => retention_branch_sql($menu, $branchId),
        '{PAT}' => $pat,
    ];
}

function retention_count(string $menu, string $period, ?int $branchId = null, ?int $ts = null): array
{
    $meta = retention_meta($menu);
    $cut = retention_cutoff($period, $ts);
    $ph = retention_placeholders($menu, $cut, $branchId);
    $detail = [];
    foreach ($meta['steps'] as $st) {
        $sql = strtr($st['sql'], $ph);
        $cnt = preg_replace('/^DELETE FROM\s+(\w+)/i', 'SELECT COUNT(*) FROM $1', $sql, 1);
        $detail[$st['label']] = (int)scalar($cnt);
    }
    /* Total memakai langkah TERAKHIR (tabel utama menu) supaya angkanya mudah
       dipahami pengguna: "berapa data reservasi/rekam medis/transaksi" yang
       melewati batas, bukan jumlah seluruh baris turunan. */
    $lastLabel = (string)end($meta['steps'])['label'];
    $total = (int)($detail[$lastLabel] ?? 0);
    return ['total' => $total, 'detail' => $detail, 'cutoff' => $cut];
}

/**
 * Jalankan penghapusan data lama satu menu dalam SATU transaksi.
 *
 * @return array{ok:bool,menu:string,label:string,period:string,cutoff:string,total:int,
 *               detail:array<string,int>,files:int,error:string}
 */
function retention_purge(string $menu, string $period, ?int $branchId = null, ?int $ts = null): array
{
    $meta = retention_meta($menu);
    $cut = retention_cutoff($period, $ts);
    $ph = retention_placeholders($menu, $cut, $branchId);
    $base = ['ok' => false, 'menu' => $menu, 'label' => $meta['label'], 'period' => $period,
             'cutoff' => $cut, 'total' => 0, 'detail' => [], 'files' => 0, 'error' => ''];

    $pre = retention_count($menu, $period, $branchId, $ts);
    if ($pre['total'] <= 0 && array_sum($pre['detail']) <= 0) {
        return $base + ['ok' => true];
    }

    /* Daftar berkas foto yang ikut terhapus (dibersihkan dari penyimpanan lokal
       setelah transaksi berhasil). Mencakup foto klinis rekam medis DAN foto
       pasien (bila pasiennya sendiri yang dihapus). */
    $files = [];
    $patientPhotoFiles = [];
    if ($menu === 'pasien') {
        foreach (all('SELECT photo_file FROM patients WHERE photo_file IS NOT NULL AND photo_file <> \'\' AND id IN (' . $ph['{PAT}'] . ')') as $pp) {
            $patientPhotoFiles[] = (string)$pp['photo_file'];
        }
    }
    if ($menu === 'rekam_medis') {
        $files = all('SELECT local_path FROM medical_record_photos WHERE medical_record_id IN '
            . '(SELECT id FROM medical_records WHERE date < ?' . str_replace(' AND 1=1', '', $ph['{BR}']) . ')',
            [$cut]);
    } elseif ($menu === 'pasien') {
        $files = all('SELECT local_path FROM medical_record_photos WHERE medical_record_id IN ('
            . 'SELECT id FROM medical_records WHERE patient_id IN (' . $ph['{PAT}'] . '))');
    }

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $detail = [];
    $total = 0;
    try {
        foreach ($meta['steps'] as $st) {
            $sql = strtr($st['sql'], $ph);
            $cntSql = preg_replace('/^DELETE FROM\s+(\w+)/i', 'SELECT COUNT(*) FROM $1', $sql, 1);
            $n = (int)scalar($cntSql);
            if ($n <= 0) continue;
            /* WAJIB lewat q() — lihat catatan pada purge.php. */
            q($sql);
            $detail[$st['label']] = $n;
        }
        /* Langkah terakhir = tabel utama menu (urutan anak → induk). */
        $total = (int)($detail[(string)end($meta['steps'])['label']] ?? 0);
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        return $base + ['error' => 'Penghapusan dibatalkan (tidak ada data yang berubah): ' . $ex->getMessage()];
    }
    if ($total === 0) $total = $pre['total'];

    /* Bersihkan berkas foto lokal (best effort) supaya ruang penyimpanan ikut lega. */
    $removedFiles = 0;
    foreach ($files as $f) {
        $name = basename((string)($f['local_path'] ?? ''));
        if ($name === '') continue;
        $path = photo_dir('medical') . '/' . $name;
        if (is_file($path) && @unlink($path)) $removedFiles++;
    }
    /* Foto pasien (kolom patients.photo_file) dibersihkan dari folder pasien. */
    if (!empty($patientPhotoFiles)) {
        foreach ($patientPhotoFiles as $f) {
            $name = basename((string)$f);
            if ($name === '') continue;
            $path = photo_dir('patient') . '/' . $name;
            if (is_file($path) && @unlink($path)) $removedFiles++;
        }
    }
    return ['ok' => true, 'menu' => $menu, 'label' => $meta['label'], 'period' => $period,
            'cutoff' => $cut, 'total' => $total, 'detail' => $detail,
            'files' => $removedFiles, 'error' => ''];
}

/* ------------------------------------------------------------------ *
 * AKTIVITAS AKUN ("Aktivitas Saya Terbaru" di halaman Akun Saya)
 * ------------------------------------------------------------------ */

/**
 * Aktivitas akun memakai catatan Audit Log milik pengguna itu sendiri
 * (kolom user_id), jadi pembersihannya TIDAK pernah menyentuh aktivitas
 * pengguna lain.
 */
function activity_enabled(): bool
{
    return setting('activity_retention_active', '0') === '1';
}

function activity_period(): string
{
    $p = (string)setting('activity_retention_period', '30d');
    return isset(activity_periods()[$p]) ? $p : '30d';
}

/**
 * Tindakan PENTING yang tidak ikut dibersihkan walau sudah lama — supaya jejak
 * tindakan berisiko (mengosongkan data, restore, hapus per periode, rebranding)
 * tetap dapat diaudit. Ini disengaja dan dijelaskan di halaman pengaturan.
 */
function activity_protected_actions(): array
{
    return ['HAPUS SEMUA DATA', 'Restore Database', 'Hapus Data Per Periode',
            'Hapus Backup', 'Ubah Nama Klinik', 'Hapus Otomatis (Retensi Data)'];
}

/** Ringkasan aktivitas milik satu pengguna (dipakai pratinjau di halaman). */
function activity_stats(int $userId, ?string $period = null): array
{
    $period = $period ?? activity_period();
    $cut = retention_cutoff($period);
    $prot = activity_protected_actions();
    $ph = implode(',', array_fill(0, count($prot), '?'));
    $total = (int)scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?', [$userId]);
    $siap = (int)scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND created_at < ? AND action NOT IN (' . $ph . ')',
        array_merge([$userId, $cut], $prot));
    $dilindungi = (int)scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND created_at < ? AND action IN (' . $ph . ')',
        array_merge([$userId, $cut], $prot));
    return ['total' => $total, 'siap' => $siap, 'dilindungi' => $dilindungi, 'cutoff' => $cut, 'period' => $period];
}

/**
 * Bersihkan aktivitas LAMA milik pengguna yang sedang login.
 * Dipanggil setiap login (semua level) bila fitur ini diaktifkan Super Admin.
 *
 * @return array|null null bila tidak aktif / tidak ada yang perlu dibersihkan
 */
function activity_auto_run(int $userId): ?array
{
    if ($userId <= 0) return null;
    if (!activity_enabled()) return null;
    /* Mode pemeliharaan: tulis-menulis ditahan untuk level selain Super Admin. */
    if (function_exists('maintenance_on') && maintenance_on() && !is_super()) return null;

    $period = activity_period();
    $cut = retention_cutoff($period);
    $prot = activity_protected_actions();
    $ph = implode(',', array_fill(0, count($prot), '?'));
    $params = array_merge([$userId, $cut], $prot);
    $n = (int)scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND created_at < ? AND action NOT IN (' . $ph . ')', $params);
    if ($n <= 0) return null;
    q('DELETE FROM audit_logs WHERE user_id = ? AND created_at < ? AND action NOT IN (' . $ph . ')', $params);
    audit('Hapus Aktivitas Lama (Otomatis)', 'Akun Saya', $userId, null,
        ['terhapus' => $n, 'periode' => retention_period_label($period), 'batas_waktu' => $cut],
        'Aktivitas akun yang lebih lama dari ' . retention_period_label($period) . ' dibersihkan otomatis saat login');
    return ['ok' => true, 'deleted' => $n, 'period' => $period,
            'label' => retention_period_label($period), 'cutoff' => $cut];
}

/** Keterangan ringkas status pembersihan aktivitas akun. */
function activity_status_text(): string
{
    if (!activity_enabled()) {
        return 'Nonaktif — aktivitas akun dibiarkan menumpuk.';
    }
    $p = activity_period();
    return 'Aktif: saat ADA YANG LOGIN (semua level), aktivitas milik pengguna tersebut yang lebih lama dari '
        . retention_period_label($p) . ' dibersihkan otomatis sekali sehari. '
        . 'Tindakan penting (hapus semua data, restore, hapus per periode) tetap disimpan.';
}

/**
 * Penghapusan OTOMATIS — dipanggil saat login (platform tanpa cron).
 *
 * Dijalankan paling banyak sekali per hari, hanya untuk menu yang diaktifkan
 * Super Admin di Developer Settings.
 *
 * @return array|null null bila tidak ada yang perlu dijalankan
 */
function retention_auto_run(?int $userId = null): ?array
{
    /* $userId dipertahankan untuk keseragaman pemanggilan dengan backup_auto_run();
       audit() sudah memakai pengguna yang sedang login.
       Fungsi ini SENGAJA tidak membatasi level pengguna: siapa pun yang login
       (Kasir, Admin/Dokter, Direktur, maupun Super Admin) akan menjalankannya,
       sehingga pembersihan tetap berjalan walau Super Admin jarang login. */
    $today = date('Y-m-d');
    if (setting('retention_last_run') === $today) return null;
    /* Mode pemeliharaan = sedang memperbaiki data: jangan menghapus otomatis
       (Super Admin tetap bebas karena ia yang mengelola pemeliharaan). */
    if (function_exists('maintenance_on') && maintenance_on() && !is_super()) return null;

    $aktif = [];
    foreach (array_keys(retention_targets()) as $menu) {
        if (retention_enabled($menu)) $aktif[$menu] = retention_period($menu);
    }
    if (!$aktif) {
        /* Tidak ada yang aktif: catat tanggalnya supaya pemeriksaan tidak
           mengulang query setiap kali ada yang login pada hari yang sama. */
        set_setting('retention_last_run', $today);
        return null;
    }

    $hasil = [];
    $total = 0;
    foreach ($aktif as $menu => $period) {
        try {
            $r = retention_purge($menu, $period, null);
            if ($r['ok'] && $r['total'] > 0) {
                $hasil[$menu] = ['label' => $r['label'], 'periode' => retention_period_label($period),
                                 'jumlah' => $r['total'], 'berkas' => $r['files']];
                $total += $r['total'];
            }
        } catch (Throwable $ex) {
            $hasil[$menu] = ['label' => retention_meta($menu)['label'], 'error' => $ex->getMessage()];
        }
    }
    set_setting('retention_last_run', $today);
    set_setting('retention_last_at', date('Y-m-d H:i:s'));
    if ($total > 0) {
        set_setting('retention_last_result', json_encode(['tanggal' => date('Y-m-d H:i:s'),
            'total' => $total, 'rincian' => $hasil], JSON_UNESCAPED_UNICODE));
        audit('Hapus Otomatis (Retensi Data)', 'Pembersihan Data', null, null,
            ['total' => $total, 'rincian' => $hasil],
            'Penghapusan otomatis data lama dijalankan saat aplikasi dibuka (tanpa cron)');
        return ['ok' => true, 'total' => $total, 'rincian' => $hasil];
    }
    return null;
}

/* ------------------------------------------------------------------ *
 * Tombol HAPUS MANUAL per menu (Super Admin)
 * ------------------------------------------------------------------ */

/**
 * Penanganan POST hapus manual dari halaman menu.
 * WAJIB dipanggil di blok POST halaman terkait sebelum aksi lain.
 *
 * Pengaman berlapis (sama seperti fitur hapus yang sudah ada):
 *   1. hanya Super Admin (diperiksa server),
 *   2. password akun Super Admin harus benar,
 *   3. konfirmasi 2 tahap di antarmuka (modal kata kunci + dialog sistem),
 *   4. alasan penghapusan opsional tetapi selalu dicatat di Audit Log.
 *
 * @return array|null hasil penghapusan (sudah flash + audit) atau null bila bukan aksi ini
 */
function retention_handle_post(string $menu): ?array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return null;
    if ((string)($_POST['action'] ?? '') !== 'purge_period') return null;
    verify_csrf();
    $u = current_user();
    if (!is_super()) {
        deny('Penghapusan data per periode hanya dapat dilakukan oleh Super Admin.');
    }
    $period = (string)($_POST['period'] ?? '');
    $valid = retention_manual_periods();
    if (!isset($valid[$period])) {
        flash('Periode penghapusan tidak dikenal. Pilih salah satu pilihan yang tersedia.', 'error');
        header('Location: ' . retention_meta($menu)['back']);
        exit;
    }
    $pass = (string)($_POST['password'] ?? '');
    if ($pass === '' || !password_verify($pass, (string)($u['password_hash'] ?? ''))) {
        flash('Password Super Admin tidak sesuai — penghapusan DIBATALKAN dan tidak ada data yang berubah.', 'error');
        audit('Hapus Data Ditolak', retention_meta($menu)['label'], null, null,
            ['menu' => $menu, 'periode' => $period], 'Password Super Admin salah saat konfirmasi hapus per periode');
        header('Location: ' . retention_meta($menu)['back']);
        exit;
    }
    $branchVal = (string)($_POST['branch'] ?? 'all');
    $branchId = $branchVal === 'all' ? null : (int)$branchVal;
    if ($branchId !== null && !one('SELECT id FROM branches WHERE id = ?', [$branchId])) $branchId = null;
    $branchKey = $branchId === null ? 'Semua Cabang'
        : (string)scalar('SELECT name FROM branches WHERE id = ?', [$branchId], 'Semua Cabang');
    $reason = trim((string)($_POST['reason'] ?? ''));

    $res = retention_purge($menu, $period, $branchId);
    if (!$res['ok']) {
        flash($res['error'], 'error');
        header('Location: ' . retention_meta($menu)['back']);
        exit;
    }
    if ($res['total'] <= 0) {
        flash('Tidak ada data ' . retention_meta($menu)['label'] . ' yang lebih lama dari '
            . retention_period_label($period) . ' pada cakupan ' . $branchKey . ' — tidak ada yang dihapus.', 'info');
        header('Location: ' . retention_meta($menu)['back']);
        exit;
    }
    audit('Hapus Data Per Periode', retention_meta($menu)['label'], null,
        ['periode' => retention_period_label($period), 'cakupan' => $branchKey],
        ['terhapus' => $res['detail'], 'total' => $res['total'], 'batas_waktu' => $res['cutoff'],
         'berkas_foto' => $res['files']],
        $reason !== '' ? $reason : 'Penghapusan manual data lebih lama dari ' . retention_period_label($period));
    flash(num($res['total']) . ' data ' . retention_meta($menu)['label'] . ' yang lebih lama dari '
        . retention_period_label($period) . ' (cakupan: ' . $branchKey . ') berhasil dihapus'
        . ($res['files'] > 0 ? ' beserta ' . num($res['files']) . ' berkas foto' : '') . '. '
        . 'Rincian: ' . implode(', ', array_map(fn($k, $v) => $k . ' ' . num($v), array_keys($res['detail']), array_values($res['detail'])))
        . '. Tindakan ini tercatat di Audit Log.', 'warning');
    header('Location: ' . retention_meta($menu)['back']);
    exit;
}

/**
 * Tombol + modal "Hapus data per periode" — HANYA dirender untuk Super Admin.
 * Modal memakai mekanisme konfirmasi berat yang sudah ada (data-heavy-confirm:
 * ketik kata kunci → dialog konfirmasi kedua) + kolom password server-side.
 */
function retention_manual_button(string $menu, string $extraClass = 'btn-danger'): string
{
    if (function_exists('is_super') && !is_super()) return '';
    $meta = retention_meta($menu);
    $id = 'retentionModal_' . $menu;
    $periods = retention_manual_periods();
    $html = '<button type="button" class="btn ' . e($extraClass) . '" data-modal-open="' . e($id) . '">'
        . icon('trash') . ' Hapus Data per Periode</button>';
    $html .= '<div class="modal" id="' . e($id) . '"><div class="modal-box">'
        . '<div class="modal-head"><h3 style="color:#B3261E">Hapus ' . e($meta['label']) . ' per Periode</h3>'
        . '<button type="button" class="icon-btn" data-modal-close="' . e($id) . '">' . icon('x') . '</button></div>'
        . '<form method="post" data-heavy-confirm="HAPUS"'
        . ' data-heavy-warning="Menghapus <strong>permanen</strong> data ' . e($meta['label'])
        . ' yang lebih lama dari periode yang dipilih.<br><br>' . e($meta['warn'])
        . ' Cakupan cabang juga dapat dipilih di bawah (bawaan: Semua Cabang). Tindakan ini tidak dapat dibatalkan."'
        . ' data-heavy-confirm2="PERINGATAN KEDUA (terakhir): data ' . e($meta['label'])
        . ' yang lebih lama dari periode terpilih akan dihapus PERMANEN. Lanjutkan?">'
        . csrf_field()
        . '<input type="hidden" name="action" value="purge_period">'
        . '<input type="hidden" name="menu" value="' . e($menu) . '">'
        . '<div class="modal-body">'
        . '<div class="alert alert-error">' . e($meta['warn']) . '</div>'
        . '<div class="form-grid g2">'
        . '<div class="field"><label>Hapus data lebih lama dari <span class="req">*</span></label>'
        . '<select class="input" name="period" required>';
    foreach ($periods as $k => $lbl) {
        $html .= '<option value="' . e($k) . '">' . e($lbl) . ' terakhir dihapus (lebih lama dari ' . e($lbl) . ')</option>';
    }
    $html .= '</select><span class="hint">Batas waktu: data yang tanggalnya lebih lama dari periode ini yang dihapus. '
        . 'Periode untuk ' . e($meta['date_label']) . '.</span></div>'
        . '<div class="field"><label>Cakupan Cabang</label><select class="input" name="branch">'
        . '<option value="all">Semua Cabang (total)</option>';
    foreach (branches() as $b) {
        $html .= '<option value="' . (int)$b['id'] . '">' . e((string)$b['name']) . '</option>';
    }
    $html .= '</select></div>'
        . '<div class="field" style="grid-column:1/-1"><label>Password Super Admin <span class="req">*</span></label>'
        . '<input class="input" type="password" name="password" required autocomplete="current-password">'
        . '<span class="hint">Diperiksa di server. Password salah = tidak ada data yang dihapus.</span></div>'
        . '<div class="field" style="grid-column:1/-1"><label>Alasan penghapusan (opsional, tercatat di Audit Log)</label>'
        . '<input class="input" name="reason" placeholder="mis. membersihkan data lama sesuai kebijakan retensi"></div>'
        . '</div>'
        . '<div class="notice mt-2">Tindakan ini dicatat lengkap di Audit Log (jumlah baris per tabel). '
        . 'Struk/dokumen yang sudah dicetak atau dikirim tidak berubah.</div>'
        . '</div>'
        . '<div class="modal-foot">'
        . '<button type="button" class="btn" data-modal-close="' . e($id) . '">Batalkan</button>'
        . '<button class="btn btn-danger" type="submit">Hapus Sekarang (2x konfirmasi)</button>'
        . '</div></form></div></div>';
    return $html;
}

/** Keterangan ringkas status retensi sebuah menu (dipakai di kartu & halaman). */
function retention_menu_status_text(string $menu): string
{
    if (!retention_enabled($menu)) return 'Nonaktif — data lama dibiarkan menumpuk.';
    $meta = retention_meta($menu);
    $p = retention_period($menu);
    $n = retention_count($menu, $p, null);
    return 'Aktif: data yang lebih lama dari ' . retention_period_label($p) . ' dihapus otomatis '
        . '(dijalankan saat aplikasi dibuka, maksimal sekali sehari). '
        . 'Saat ini ' . num($n['total']) . ' data ' . $meta['label'] . ' sudah melewati batas itu.';
}
