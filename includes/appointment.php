<?php
/**
 * Helper RESERVASI (appointment) — dipakai bersama oleh menu Reservasi,
 * Order Baru (pra-isi item), dan form Rekam Medis (pra-isi pasien/tanggal/petugas).
 *
 * Satu reservasi boleh memuat BEBERAPA treatment; daftarnya disimpan di tabel
 * `appointment_treatments`. Kolom lama `appointments.treatment_id` tetap diisi
 * dengan treatment PERTAMA agar fitur lama (pesan WhatsApp, ekspor, detail
 * pasien) tidak berubah perilakunya.
 *
 * PAKET TREATMENT (permintaan pemilik): pilihan pada formulir reservasi juga
 * memuat paket treatment/produk milik cabang itu. Paket TIDAK punya baris di
 * tabel `treatments`, jadi disimpan di kolom `treatment_id` yang sama dengan
 * **id NEGATIF** (`-id_paket`). Cara ini aman karena kolom itu hanya punya
 * foreign key ke `appointments` (bukan ke `treatments`), kolom lama
 * `appointments.treatment_id` tetap diisi treatment BIASA saja (paket → 0),
 * dan seluruh tampilan nama dibaca lewat helper di berkas ini sehingga paket
 * selalu muncul dengan awalan "[Paket]".
 */
declare(strict_types=1);

/** Apakah nilai kolom `treatment_id` reservasi menunjuk sebuah PAKET. */
function res_is_package(int $storedId): bool
{
    return $storedId < 0;
}

/** Id paket dari nilai tersimpan (0 bila bukan paket). */
function res_package_id(int $storedId): int
{
    return $storedId < 0 ? -$storedId : 0;
}

/**
 * Daftar item satu reservasi (urut sesuai urutan dipilih saat menyimpan).
 *
 * @return array<int,array{treatment_id:int,kind:string,name:string,normal_price:mixed,promo_price:mixed}>
 */
function res_treatments(int $appointmentId): array
{
    $rows = all('SELECT at.treatment_id,
                        CASE WHEN at.treatment_id < 0 THEN "package" ELSE "treatment" END AS kind,
                        CASE WHEN at.treatment_id < 0
                             THEN COALESCE("[Paket] " || pk.name, "[Paket] (dihapus)")
                             ELSE COALESCE(t.name, "-") END AS name,
                        t.normal_price, t.promo_price,
                        pk.price AS package_price
                 FROM appointment_treatments at
                 LEFT JOIN treatments t ON t.id = at.treatment_id
                 LEFT JOIN packages pk ON pk.id = -at.treatment_id
                 WHERE at.appointment_id = ? ORDER BY at.position, at.id', [$appointmentId]);
    if ($rows) {
        foreach ($rows as &$r) {
            $r['treatment_id'] = (int)$r['treatment_id'];
            if ($r['kind'] === 'package') {
                $r['normal_price'] = $r['package_price'];
                $r['promo_price'] = 0;
            }
        }
        unset($r);
        return $rows;
    }
    /* Data lama (sebelum fitur multi-treatment): pakai kolom treatment_id. */
    $tid = (int)scalar('SELECT COALESCE(treatment_id, 0) FROM appointments WHERE id = ?', [$appointmentId], 0);
    if ($tid <= 0) return [];
    $one = one('SELECT id, name, normal_price, promo_price FROM treatments WHERE id = ?', [$tid]);
    return $one ? [['treatment_id' => (int)$one['id'], 'kind' => 'treatment', 'name' => (string)$one['name'],
                    'normal_price' => $one['normal_price'], 'promo_price' => $one['promo_price'],
                    'package_price' => null]] : [];
}

/**
 * Simpan ulang daftar item satu reservasi.
 *
 * Nilai POSITIF = treatment, NEGATIF = paket (`-id_paket`); id duplikat diabaikan.
 */
function res_save_treatments(int $appointmentId, array $ids): int
{
    q('DELETE FROM appointment_treatments WHERE appointment_id = ?', [$appointmentId]);
    $pos = 0;
    $seen = [];
    foreach ($ids as $tid) {
        $tid = (int)$tid;
        if ($tid === 0 || isset($seen[$tid])) continue;
        $seen[$tid] = true;
        q('INSERT INTO appointment_treatments (appointment_id, treatment_id, position) VALUES (?,?,?)',
            [$appointmentId, $tid, $pos]);
        $pos++;
    }
    return $pos;
}

/** Treatment BIASA pertama (dipakai untuk kolom lama appointments.treatment_id). */
function res_first_treatment(array $ids): int
{
    foreach ($ids as $tid) if ((int)$tid > 0) return (int)$tid;
    return 0;
}

/** Ringkasan teks daftar item reservasi (mis. "Facial, [Paket] Glowing 3x"). */
function res_items_text(int $appointmentId): string
{
    $names = array_values(array_filter(array_map(fn($t) => (string)($t['name'] ?? ''), res_treatments($appointmentId))));
    return implode(', ', $names);
}
