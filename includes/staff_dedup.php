<?php
/**
 * PEMBERSIH DATA DOKTER & TERAPIS YANG DUPLIKAT
 * ============================================
 * Permintaan pemilik: di menu "Dokter & Terapis" banyak baris kembar — hapus yang
 * tidak perlu.
 *
 * CARA KERJA (aman, tidak menyentuh riwayat):
 *   1. Baris dianggap kembar bila **nama sama** (tanpa membedakan huruf besar/kecil
 *      dan spasi berlebih) **pada cabang yang sama**.
 *   2. Dari setiap kelompok kembar dipilih SATU baris yang DIPERTAHANKAN, urutan
 *      pilihannya:
 *        a. yang paling banyak dirujuk riwayat (reservasi & rekam medis),
 *        b. bila seri → yang masih AKTIF (supaya tidak mematikan data yang dipakai),
 *        c. bila masih seri → yang paling lama dibuat (created_at), lalu id terkecil.
 *   3. Seluruh rujukan (`appointments.doctor_id/therapist_id`,
 *      `medical_records.doctor_id/therapist_id`) DIPINDAHKAN ke baris yang
 *      dipertahankan, jadi riwayat TIDAK hilang dan tidak ada rujukan menggantung.
 *   4. Foto: bila baris yang dipertahankan belum punya foto sedangkan baris kembar
 *      punya, berkas fotonya dipakai (nama berkas dipindahkan, file tetap di disk).
 *   5. Email/telepon yang kosong pada baris yang dipertahankan diisi dari kembarannya
 *      (tidak menimpa nilai yang sudah ada).
 *   6. Baris kembar dihapus di dalam SATU transaksi.
 *
 * Semua fungsi memakai cakupan SELURUH CABANG (ini alat perapian data untuk Super
 * Admin) dan melaporkan apa adanya berapa baris yang digabung/dihapus.
 */

/** Kelompok baris kembar pada satu tabel ('doctors' | 'therapists'). */
function staff_dup_groups(string $table): array
{
    $table = $table === 'therapists' ? 'therapists' : 'doctors';
    $fkDoc = $table === 'doctors' ? 'doctor_id' : 'therapist_id';

    /* Hitung rujukan tiap baris dari riwayat (reservasi + rekam medis).
       Penghitungannya SENGAJA lintas cabang: ini alat perapian untuk Super Admin dan
       jumlah rujukan dipakai menentukan baris mana yang dipertahankan — baris dengan
       rujukan terbanyak (di cabang mana pun) itulah yang disimpan. */
    $pakai = [];
    foreach (all("/* cross-branch */ SELECT {$fkDoc} id, COUNT(*) n FROM appointments
                  WHERE {$fkDoc} IS NOT NULL GROUP BY {$fkDoc}") as $r) {
        $pakai[(int)$r['id']] = ($pakai[(int)$r['id']] ?? 0) + (int)$r['n'];
    }
    foreach (all("/* cross-branch */ SELECT {$fkDoc} id, COUNT(*) n FROM medical_records
                  WHERE {$fkDoc} IS NOT NULL GROUP BY {$fkDoc}") as $r) {
        $pakai[(int)$r['id']] = ($pakai[(int)$r['id']] ?? 0) + (int)$r['n'];
    }

    $baris = all("SELECT * FROM {$table} ORDER BY id");
    $kelompok = [];
    foreach ($baris as $b) {
        /* Nama dinormalkan: huruf kecil, spasi/tanda baca dirapikan. */
        $kunci = mb_strtolower_fallback((string)$b['name']);
        $kunci = preg_replace('/\s+/', ' ', trim((string)$kunci));
        $kunci = (int)$b['branch_id'] . '|' . $kunci;
        $kelompok[$kunci][] = $b;
    }

    $out = [];
    foreach ($kelompok as $kunci => $anggota) {
        if (count($anggota) < 2) continue;
        /* Pilih yang dipertahankan. */
        usort($anggota, function ($a, $b) use ($pakai) {
            $ra = $pakai[(int)$a['id']] ?? 0;
            $rb = $pakai[(int)$b['id']] ?? 0;
            if ($ra !== $rb) return $rb <=> $ra;                    // rujukan terbanyak
            $aa = $a['status'] === 'active' ? 1 : 0;
            $ab = $b['status'] === 'active' ? 1 : 0;
            if ($aa !== $ab) return $ab <=> $aa;                    // masih aktif
            $ca = (string)($a['created_at'] ?? ''); $cb = (string)($b['created_at'] ?? '');
            if ($ca !== $cb) return $ca <=> $cb;                    // paling lama
            return (int)$a['id'] <=> (int)$b['id'];                 // id terkecil
        });
        $simpan = $anggota[0];
        $hapus = array_slice($anggota, 1);
        $out[] = [
            'kunci' => $kunci, 'simpan' => $simpan, 'hapus' => $hapus,
            'rujukan' => (int)($pakai[(int)$simpan['id']] ?? 0),
            'rujukan_dibuang' => array_sum(array_map(fn($x) => (int)($pakai[(int)$x['id']] ?? 0), $hapus)),
        ];
    }
    return $out;
}

/** Normalisasi nama tanpa bergantung mbstring (tidak selalu tersedia di CLI). */
function mb_strtolower_fallback(string $s): string
{
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}

/**
 * Ringkasan untuk pratinjau (tanpa mengubah apa pun).
 *
 * @return array{doctors:array,therapists:array,rows:int,files:int}
 */
function staff_dup_scan(): array
{
    $out = ['doctors' => [], 'therapists' => [], 'rows' => 0, 'files' => 0];
    foreach (['doctors', 'therapists'] as $t) {
        foreach (staff_dup_groups($t) as $g) {
            $namaCabang = (string)scalar('SELECT name FROM branches WHERE id = ?', [(int)$g['simpan']['branch_id']], '');
            $out[$t][] = [
                'nama' => (string)$g['simpan']['name'],
                'cabang' => $namaCabang,
                'simpan_id' => (int)$g['simpan']['id'],
                'simpan_status' => (string)$g['simpan']['status'],
                'hapus' => array_map(fn($x) => ['id' => (int)$x['id'], 'status' => (string)$x['status'],
                    'photo' => (string)($x['photo_file'] ?? '')], $g['hapus']),
                'rujukan' => $g['rujukan'],
                'rujukan_dibuang' => $g['rujukan_dibuang'],
            ];
            $out['rows'] += count($g['hapus']);
            foreach ($g['hapus'] as $x) if (trim((string)($x['photo_file'] ?? '')) !== '') $out['files']++;
        }
    }
    return $out;
}

/**
 * Jalankan penggabungan + penghapusan.
 *
 * @return array{ok:bool,rows:int,groups:int,refs:int,files:int,error:string,detail:array}
 */
function staff_dup_apply(?int $userId = null): array
{
    $hasil = ['ok' => false, 'rows' => 0, 'groups' => 0, 'refs' => 0, 'files' => 0, 'error' => '', 'detail' => []];
    $rencana = [];
    foreach (['doctors', 'therapists'] as $t) $rencana[$t] = staff_dup_groups($t);
    if (!$rencana['doctors'] && !$rencana['therapists']) {
        $hasil['ok'] = true;
        $hasil['error'] = '';
        return $hasil;
    }

    q('BEGIN IMMEDIATE');
    try {
        foreach (['doctors', 'therapists'] as $t) {
            $fk = $t === 'doctors' ? 'doctor_id' : 'therapist_id';
            foreach ($rencana[$t] as $g) {
                $simpan = $g['simpan'];
                $keepId = (int)$simpan['id'];
                $namaCabang = (string)scalar('SELECT name FROM branches WHERE id = ?', [(int)$simpan['branch_id']], '');

                /* 1. Pindahkan seluruh rujukan riwayat ke baris yang dipertahankan. */
                $pindah = 0;
                foreach ($g['hapus'] as $h) {
                    $hid = (int)$h['id'];
                    foreach (['appointments', 'medical_records'] as $tbl) {
                        $n = q("UPDATE {$tbl} SET {$fk} = ? WHERE {$fk} = ?", [$keepId, $hid])->rowCount();
                        $pindah += max(0, (int)$n);
                    }
                }
                $hasil['refs'] += $pindah;

                /* 2. Lengkapi data yang kosong pada baris yang dipertahankan. */
                $isi = [];
                $param = [];
                foreach (['phone', 'email', 'specialization', 'schedule'] as $kol) {
                    if (trim((string)($simpan[$kol] ?? '')) !== '') continue;
                    foreach ($g['hapus'] as $h) {
                        if (trim((string)($h[$kol] ?? '')) !== '') { $isi[] = "{$kol} = ?"; $param[] = (string)$h[$kol]; break; }
                    }
                }
                /* 3. Foto: pakai milik kembarannya bila yang dipertahankan belum punya. */
                $fotoBaru = '';
                if (trim((string)($simpan['photo_file'] ?? '')) === '') {
                    foreach ($g['hapus'] as $h) {
                        if (trim((string)($h['photo_file'] ?? '')) !== '') { $fotoBaru = (string)$h['photo_file']; $isi[] = 'photo_file = ?'; $param[] = $fotoBaru; $hasil['files']++; break; }
                    }
                }
                if ($isi) {
                    $param[] = $keepId;
                    q("UPDATE {$t} SET " . implode(', ', $isi) . ' WHERE id = ?', $param);
                }

                /* 4. Hapus baris kembar. */
                foreach ($g['hapus'] as $h) {
                    q("DELETE FROM {$t} WHERE id = ?", [(int)$h['id']]);
                    $hasil['rows']++;
                }
                $hasil['groups']++;
                $hasil['detail'][] = ['tabel' => $t, 'nama' => (string)$simpan['name'], 'cabang' => $namaCabang,
                    'simpan' => $keepId, 'dihapus' => count($g['hapus']), 'rujukan_dipindah' => $pindah];
            }
        }
        q('COMMIT');
        $hasil['ok'] = true;
    } catch (Throwable $e) {
        try { q('ROLLBACK'); } catch (Throwable $x) { /* abaikan */ }
        $hasil['ok'] = false;
        $hasil['error'] = $e->getMessage();
        return $hasil;
    }

    if ($userId !== null && $hasil['rows'] > 0) {
        audit('Gabungkan Dokter/Terapis Kembar', 'Pengaturan', null,
            ['baris_kembar' => $hasil['rows']],
            ['digabung' => $hasil['groups'], 'dihapus' => $hasil['rows'], 'rujukan_dipindah' => $hasil['refs'],
             'foto_dipakai' => $hasil['files']],
            'Menghapus baris dokter/terapis kembar pada cabang yang sama; seluruh rujukan riwayat dipindahkan '
            . 'ke baris yang dipertahankan');
    }
    return $hasil;
}
