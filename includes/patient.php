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
    return (int)scalar(
        "SELECT COUNT(*) FROM (
            SELECT date(o.created_at) AS d FROM orders o
             WHERE o.patient_id = ? AND o.status <> 'void' AND o.created_at IS NOT NULL
            UNION
            SELECT m.date AS d FROM medical_records m
             WHERE m.patient_id = ? AND m.date IS NOT NULL AND m.date <> ''
        )", [$patientId, $patientId]);
}

/** Daftar tanggal kunjungan (urut menaik) — dipakai untuk keterangan rinci. */
function patient_visit_dates(int $patientId): array
{
    if ($patientId <= 0) return [];
    $rows = all(
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
    $p = one('SELECT id, name, patient_type FROM patients WHERE id = ?', [$patientId]);
    if (!$p) return false;
    if ((string)$p['patient_type'] === 'Lama') return false;        // sudah benar
    $days = patient_visit_days($patientId);
    if ($days < PATIENT_LAMA_MIN_VISITS) return false;
    q('UPDATE patients SET patient_type = "Lama", updated_at = datetime("now","localtime") WHERE id = ?', [$patientId]);
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
