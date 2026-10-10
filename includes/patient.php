<?php
/**
 * STATUS PASIEN OTOMATIS & KUNJUNGAN.
 *
 * Permintaan pemilik klinik: pasien yang sudah datang/treatment minimal 3 KALI
 * pada HARI YANG BERBEDA tidak boleh lagi berstatus "Baru" — statusnya otomatis
 * menjadi "Lama".
 *
 * Dasar hitungannya adalah HARI kunjungan yang berbeda, bukan jumlah transaksi:
 * dua transaksi pada hari yang sama dihitung satu kunjungan (pasien memang hanya
 * datang sekali), sedangkan satu transaksi + satu rekam medis pada hari berbeda
 * dihitung dua kunjungan.
 */
declare(strict_types=1);

/** Minimal jumlah HARI kunjungan berbeda sebelum status menjadi "Lama". */
const PATIENT_LAMA_MIN_VISITS = 3;

/**
 * Jumlah HARI kunjungan berbeda milik seorang pasien.
 *
 * Sumber kunjungan (digabung, hari kembar dihitung sekali):
 *   - transaksi (order) yang tidak dibatalkan, berdasarkan tanggal transaksi;
 *   - rekam medis, berdasarkan tanggal periksa.
 */
function patient_visit_days(int $patientId): int
{
    if ($patientId <= 0) return 0;
    /* CAKUPAN CABANG EKSPLISIT: seluruh query pasien berjalan pada basis data CABANG milik
       pasien itu (mode `legacy` = koneksi utama, jadi perilakunya tidak berubah). */
    $c = db_conn_for_record('patients', $patientId);
    return (int)scalar_on($c,
        "SELECT COUNT(*) FROM (
            SELECT date(o.created_at) AS d FROM orders o
             WHERE o.patient_id = ? AND o.status <> 'void' AND o.created_at IS NOT NULL
            UNION
            SELECT m.date AS d FROM medical_records m
             WHERE m.patient_id = ? AND m.date IS NOT NULL AND m.date <> ''
        )", [$patientId, $patientId]);
}

/**
 * EKSPRESI SQL "KUNJUNGAN" — SATU DEFINISI untuk semua daftar/laporan.
 *
 * Kunjungan = **jumlah HARI BERBEDA** pasien datang, dihitung dari transaksi
 * (semua status selain void) **dan** rekam medis. Jadi:
 *   • 3 transaksi dalam sehari = 1 kunjungan (bukan 3),
 *   • hari yang hanya berisi rekam medis (tanpa transaksi) tetap terhitung.
 *
 * Sama dengan `patient_visit_days()`. Dulu beberapa halaman memakai
 * `COUNT(*) FROM orders` sebagai "Kunjungan" (itu JUMLAH TRANSAKSI) sehingga angka
 * pada Daftar Pasien, Detail Pasien, dan Top 10 Pasien saling berbeda — pemilik
 * bertanya "kunjungan dihitung berdasarkan apa?". Sekarang semuanya memakai
 * ekspresi ini.
 *
 * @param string $aliasP alias tabel patients pada query pemanggil (mis. 'p')
 * @param string $from   batas awal (Y-m-d) — kosong = tanpa batas
 * @param string $to     batas akhir (Y-m-d) — kosong = tanpa batas
 */
function patient_visits_sql(string $aliasP = 'p', string $from = '', string $to = ''): string
{
    /* Tanggal disisipkan langsung (bukan parameter) karena ekspresi ini dipakai di
       TENGAH query pemanggil — menambah parameter akan menggeser urutan `?` milik
       pemanggil. Karena itu nilainya WAJIB berbentuk Y-m-d; selain itu diabaikan. */
    $aman = fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    $wO = ''; $wM = '';
    if ($aman($from)) { $wO .= " AND date(o2.created_at) >= '" . $from . "'"; $wM .= " AND m2.date >= '" . $from . "'"; }
    if ($aman($to))   { $wO .= " AND date(o2.created_at) <= '" . $to . "'";   $wM .= " AND m2.date <= '" . $to . "'"; }
    return '(SELECT COUNT(*) FROM (
                SELECT date(o2.created_at) AS d FROM orders o2
                 WHERE o2.patient_id = ' . $aliasP . '.id AND o2.status <> \'void\'
                   AND o2.created_at IS NOT NULL' . $wO . '
                UNION
                SELECT m2.date AS d FROM medical_records m2
                 WHERE m2.patient_id = ' . $aliasP . '.id AND COALESCE(m2.date, \'\') <> \'\'' . $wM . '
            ))';
}

/** Daftar tanggal kunjungan (urut menaik) — dipakai untuk keterangan rinci. */
function patient_visit_dates(int $patientId): array
{
    if ($patientId <= 0) return [];
    $c = db_conn_for_record('patients', $patientId);
    $rows = all_on($c,
        "SELECT d FROM (
            SELECT date(o.created_at) AS d FROM orders o
             WHERE o.patient_id = ? AND o.status <> 'void' AND o.created_at IS NOT NULL
            UNION
            SELECT m.date AS d FROM medical_records m
             WHERE m.patient_id = ? AND m.date IS NOT NULL AND m.date <> ''
        ) ORDER BY d", [$patientId, $patientId]);
    return array_map(fn($r) => (string)$r['d'], $rows);
}

/**
 * Sinkronkan satu pasien: bila hari kunjungannya sudah mencapai ambang, status
 * diubah menjadi "Lama" (hanya naik, tidak pernah dikembalikan menjadi "Baru").
 *
 * @return bool true bila status DIUBAH oleh pemanggilan ini
 */
function patient_sync_type(int $patientId, ?int $actorId = null): bool
{
    if ($patientId <= 0) return false;
    $c = db_conn_for_record('patients', $patientId);
    $p = one_on($c, 'SELECT id, name, patient_type FROM patients WHERE id = ?', [$patientId]);
    if (!$p) return false;
    if ((string)$p['patient_type'] === 'Lama') return false;        // sudah benar
    $days = patient_visit_days($patientId);
    if ($days < PATIENT_LAMA_MIN_VISITS) return false;
    q_on($c, 'UPDATE patients SET patient_type = "Lama", updated_at = datetime("now","localtime") WHERE id = ?', [$patientId]);
    audit('Status Pasien Otomatis', 'Pasien', $patientId,
        ['status' => $p['patient_type'] ?: 'Baru'],
        ['status' => 'Lama', 'hari_kunjungan' => $days],
        'Status pasien otomatis menjadi "Lama" karena sudah ' . $days . ' hari kunjungan (minimal '
        . PATIENT_LAMA_MIN_VISITS . ' hari berbeda)');
    return true;
}

/**
 * Sinkronkan sekaligus untuk beberapa pasien (dipakai impor data).
 *
 * @param array<int,int|string> $ids
 * @return int jumlah pasien yang statusnya berubah
 */
function patient_sync_types(array $ids, ?int $actorId = null): int
{
    $n = 0;
    $seen = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0 || isset($seen[$id])) continue;
        $seen[$id] = true;
        if (patient_sync_type($id, $actorId)) $n++;
    }
    return $n;
}

/**
 * Keterangan singkat untuk dipakai di halaman Pasien (transparansi aturan).
 */
function patient_type_rule_text(): string
{
    return 'Status pasien berubah otomatis dari "Baru" menjadi "Lama" setelah '
        . PATIENT_LAMA_MIN_VISITS . ' hari kunjungan yang berbeda (transaksi atau rekam medis). '
        . 'Status hanya naik dan dapat diubah manual oleh petugas bila diperlukan.';
}

/**
 * UMUR PASIEN dalam bentuk teks (permintaan pemilik: tampilkan umur di bawah
 * Tanggal Lahir pada kartu Profil Pasien).
 *
 * Dihitung dari tanggal lahir sampai HARI INI (zona waktu aplikasi = WIB),
 * memakai selisih tahun-bulan-hari yang benar (bukan sekadar selisih hari ÷ 365).
 * Contoh hasil: "27 tahun 3 bulan" (bulan ditampilkan bila > 0) atau "8 bulan".
 * Tanggal lahir kosong / tidak sah / di masa depan → '' (tidak ada yang ditampilkan).
 */
function age_text(?string $birthDate, bool $withMonths = true): string
{
    $b = trim((string)$birthDate);
    if ($b === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $b)) return '';
    $lahir = substr($b, 0, 10);
    $now = date('Y-m-d');
    if ($lahir > $now) return '';
    [$ty, $tm, $td] = array_map('intval', explode('-', $now));
    [$ly, $lm, $ld] = array_map('intval', explode('-', $lahir));
    $tahun = $ty - $ly;
    $bulan = $tm - $lm;
    if ($td < $ld) { $bulan--; }        // belum lewat tanggalnya pada bulan ini
    if ($bulan < 0) { $tahun--; $bulan += 12; }
    $out = [];
    if ($tahun > 0) $out[] = num($tahun) . ' tahun';
    if ($withMonths && $bulan > 0) $out[] = num($bulan) . ' bulan';
    return implode(' ', $out);
}

