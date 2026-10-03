<?php
/**
 * Helper RESERVASI (appointment) — dipakai bersama oleh menu Reservasi,
 * Order Baru (pra-isi item), dan form Rekam Medis (pra-isi pasien/tanggal/petugas).
 *
 * Satu reservasi boleh memuat BEBERAPA treatment; daftarnya disimpan di tabel
 * `appointment_treatments`. Kolom lama `appointments.treatment_id` tetap diisi
 * dengan treatment PERTAMA agar fitur lama (pesan WhatsApp, ekspor, detail
 * pasien) tidak berubah perilakunya.
 */
declare(strict_types=1);

/** Daftar treatment satu reservasi (urut sesuai urutan dipilih saat menyimpan). */
function res_treatments(int $appointmentId): array
{
    $rows = all('SELECT at.treatment_id, COALESCE(t.name, "-") AS name,
                        t.normal_price, t.promo_price
                 FROM appointment_treatments at
                 LEFT JOIN treatments t ON t.id = at.treatment_id
                 WHERE at.appointment_id = ? ORDER BY at.position, at.id', [$appointmentId]);
    if ($rows) return $rows;
    /* Data lama (sebelum fitur multi-treatment): pakai kolom treatment_id. */
    $tid = (int)scalar('SELECT COALESCE(treatment_id, 0) FROM appointments WHERE id = ?', [$appointmentId], 0);
    if ($tid <= 0) return [];
    $one = one('SELECT id, name, normal_price, promo_price FROM treatments WHERE id = ?', [$tid]);
    return $one ? [['treatment_id' => (int)$one['id'], 'name' => (string)$one['name'],
                    'normal_price' => $one['normal_price'], 'promo_price' => $one['promo_price']]] : [];
}

/** Simpan ulang daftar treatment satu reservasi (id duplikat diabaikan). */
function res_save_treatments(int $appointmentId, array $ids): int
{
    q('DELETE FROM appointment_treatments WHERE appointment_id = ?', [$appointmentId]);
    $pos = 0;
    $seen = [];
    foreach ($ids as $tid) {
        $tid = (int)$tid;
        if ($tid <= 0 || isset($seen[$tid])) continue;
        $seen[$tid] = true;
        q('INSERT INTO appointment_treatments (appointment_id, treatment_id, position) VALUES (?,?,?)',
            [$appointmentId, $tid, $pos]);
        $pos++;
    }
    return $pos;
}

/** Treatment pertama (dipakai untuk kolom lama appointments.treatment_id). */
function res_first_treatment(array $ids): int
{
    foreach ($ids as $tid) if ((int)$tid > 0) return (int)$tid;
    return 0;
}
