<?php
/**
 * BERSIHKAN TABEL GLOBAL DARI BERKAS CABANG (migrasi arsitektur).
 * =============================================================
 * Arsitektur aplikasi HANYA memakai:
 *   • `central.sqlite`  → data GLOBAL/sistem (pengguna, peran & izin, pengaturan,
 *     daftar cabang, kamus ICD, biaya operasional, audit, backup, AI, dsb.);
 *   • `branch_XXX.sqlite` → HANYA data operasional cabang (pasien, rekam medis,
 *     reservasi, transaksi, stok, master treatment/skincare/bahan, paket).
 *
 * Berkas cabang yang dibuat versi lama IKUT membuat seluruh tabel global, sehingga
 * menimbulkan duplikasi besar: kamus ICD 15.966 baris dan 116 baris pengaturan di
 * SETIAP cabang. Fungsi di sini membuang tabel global itu dari berkas cabang.
 *
 * KEAMANAN:
 *   1. **Pratinjau dulu** — hanya melaporkan, tidak mengubah apa pun.
 *   2. Tabel OPERASIONAL tidak pernah disentuh.
 *   3. Sebelum menghapus, isi setiap tabel global DIPERIKSA: bila ada baris yang
 *      TIDAK ada di central (data yang bisa hilang), penghapusan DITOLAK dan
 *      dilaporkan — jadi tidak ada data yang lenyap tanpa disadari.
 *   4. Snapshot pengaman dibuat lebih dulu (backup paket lengkap).
 */
declare(strict_types=1);

/**
 * Tabel global yang ada di sebuah berkas cabang, beserta jumlah barisnya.
 *
 * @return array<int,array{tabel:string,baris:int,di_central:int,aman:bool,alasan:string}>
 */
function branch_global_tables_report(string $path): array
{
    $out = [];
    if (!is_file($path)) return $out;
    $pdo = db_open($path);
    $tabel = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $global = db_route_global_tables();
    /* Central dibaca untuk membandingkan jumlah baris (jangan sampai ada data
       yang hanya ada di berkas cabang lalu terbuang). */
    $central = null;
    try {
        if (is_file(db_central_path())) $central = db_open(db_central_path());
    } catch (Throwable $e) { $central = null; }

    foreach ($tabel as $t) {
        if (!in_array($t, $global, true)) continue;
        $n = (int)$pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
        $diCentral = null;
        if ($central) {
            try { $diCentral = (int)$central->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn(); }
            catch (Throwable $e) { $diCentral = null; }
        }
        $aman = true; $alasan = '';
        if ($n > 0) {
            $cek = branch_global_table_check($pdo, $central, $t);
            $aman = $cek['aman'];
            $alasan = $cek['catatan'];
        }
        $out[] = ['tabel' => $t, 'baris' => $n, 'di_central' => $diCentral,
            'aman' => $aman, 'alasan' => $alasan];
    }
    return $out;
}

/**
 * KEBIJAKAN PEMERIKSAAN per tabel global.
 *
 * Tidak semua tabel global dapat dibandingkan dengan cara yang sama. Salinan pada
 * berkas cabang dibuat SAAT BERKAS ITU DIBUAT (dari data.sqlite), jadi:
 *   • `icd_codes` — data rujukan tetap: isinya harus menjadi bagian dari central;
 *   • `settings`  — SALINAN LAMA: kuncinya harus ada di central, sedangkan NILAINYA
 *     boleh berbeda (central selalu yang berlaku; salinan cabang justru basi —
 *     mis. `email_sender` masih kosong di cabang tetapi sudah terisi di central);
 *   • `finance_costs` / `finance_cost_amounts` — pos biaya global: dibandingkan
 *     menurut NAMA pos & nominalnya (id berbeda karena berkas cabang memakai lantai
 *     id), dan baris bernominal 0 adalah sisa kosong;
 *   • `demo_batches` — dibandingkan menurut batch_id;
 *   • tabel lain — dibandingkan baris demi baris (nilai harus benar-benar ada di central).
 *
 * @return array{aman:bool,catatan:string}
 */
function branch_global_table_check(PDO $cabang, ?PDO $central, string $tabel): array
{
    if (function_exists('db_open') && !($central instanceof PDO)) {
        return ['aman' => false, 'catatan' => 'central belum dapat dibuka — tidak dihapus'];
    }
    try {
        if ($tabel === 'settings') {
            $kunci = $cabang->query('SELECT key FROM settings')->fetchAll(PDO::FETCH_COLUMN);
            if (!$kunci) return ['aman' => true, 'catatan' => ''];
            $punyaCentral = $central->query('SELECT key FROM settings')->fetchAll(PDO::FETCH_COLUMN);
            $hilang = array_values(array_diff($kunci, $punyaCentral));
            return $hilang
                ? ['aman' => false, 'catatan' => count($hilang) . ' kunci TIDAK ada di central (mis. ' . implode(', ', array_slice($hilang, 0, 3)) . ')']
                : ['aman' => true, 'catatan' => 'seluruh ' . count($kunci) . ' kunci ada di central (nilai central yang berlaku)'];
        }
        if ($tabel === 'icd_codes') {
            $ada = $central->query('SELECT COUNT(*) FROM icd_codes')->fetchColumn();
            if ((int)$ada === 0) return ['aman' => false, 'catatan' => 'kamus ICD di central kosong'];
            $kopi = 0;
            foreach ($cabang->query('SELECT kind, code FROM icd_codes') as $r) {
                $st = $central->prepare('SELECT COUNT(*) FROM icd_codes WHERE kind = ? AND code = ?');
                $st->execute([$r['kind'], $r['code']]);
                if ((int)$st->fetchColumn() === 0) $kopi++;
            }
            return $kopi === 0
                ? ['aman' => true, 'catatan' => 'seluruh kode ada di kamus central (salinan murni)']
                : ['aman' => false, 'catatan' => $kopi . ' kode tidak ada di kamus central'];
        }
        if ($tabel === 'finance_costs') {
            $nama = $cabang->query('SELECT name FROM finance_costs')->fetchAll(PDO::FETCH_COLUMN);
            $punya = $central->query('SELECT name FROM finance_costs')->fetchAll(PDO::FETCH_COLUMN);
            $hilang = array_values(array_diff($nama, $punya));
            return $hilang
                ? ['aman' => false, 'catatan' => 'pos biaya tidak ada di central: ' . implode(', ', array_slice($hilang, 0, 3))]
                : ['aman' => true, 'catatan' => 'seluruh pos biaya sudah ada di central'];
        }
        if ($tabel === 'finance_cost_amounts') {
            /* Nominal bukan nol yang TIDAK ada di central berarti data asli. */
            $pakai = [];
            foreach ($cabang->query('SELECT fc.name AS nama, fca.branch_id, fca.amount, fca.applicable
                                     FROM finance_cost_amounts fca JOIN finance_costs fc ON fc.id = fca.cost_id') as $r) {
                $pakai[] = $r;
            }
            if (!$pakai) return ['aman' => true, 'catatan' => ''];
            $berisi = array_filter($pakai, fn($r) => (float)$r['amount'] != 0.0 || (int)$r['applicable'] === 1);
            if (!$berisi) return ['aman' => true, 'catatan' => 'seluruh baris bernominal 0 (sisa kosong)'];
            $cocok = 0;
            foreach ($berisi as $r) {
                $st = $central->prepare('SELECT COUNT(*) FROM finance_cost_amounts fca JOIN finance_costs fc ON fc.id = fca.cost_id
                                         WHERE fc.name = ? AND fca.branch_id = ? AND fca.amount = ?');
                $st->execute([$r['nama'], (int)$r['branch_id'], (float)$r['amount']]);
                if ((int)$st->fetchColumn() > 0) $cocok++;
            }
            return $cocok === count($berisi)
                ? ['aman' => true, 'catatan' => 'seluruh nominal terisi juga ada di central']
                : ['aman' => false, 'catatan' => (count($berisi) - $cocok) . ' nominal tidak ada di central'];
        }
        if ($tabel === 'demo_batches') {
            $id = $cabang->query('SELECT batch_id FROM demo_batches')->fetchAll(PDO::FETCH_COLUMN);
            if (!$id) return ['aman' => true, 'catatan' => ''];
            $punya = $central->query('SELECT batch_id FROM demo_batches')->fetchAll(PDO::FETCH_COLUMN);
            $hilang = array_values(array_diff($id, $punya));
            return $hilang
                ? ['aman' => false, 'catatan' => count($hilang) . ' batch tidak ada di central']
                : ['aman' => true, 'catatan' => 'seluruh batch tercatat di central'];
        }
        /* Tabel lain: bandingkan baris demi baris (nilai harus ada di central). */
        $susun = function ($row) { ksort($row); return json_encode($row, JSON_UNESCAPED_UNICODE); };
        $isi = [];
        foreach ($cabang->query('SELECT * FROM "' . $tabel . '"') as $r) $isi[$susun($r)] = true;
        if (!$isi) return ['aman' => true, 'catatan' => 'tabel kosong'];
        foreach ($central->query('SELECT * FROM "' . $tabel . '"') as $r) {
            unset($isi[$susun($r)]);
            if (!$isi) break;
        }
        return $isi === []
            ? ['aman' => true, 'catatan' => 'seluruh baris juga ada di central']
            : ['aman' => false, 'catatan' => count($isi) . ' baris tidak ada di central'];
    } catch (Throwable $e) {
        return ['aman' => false, 'catatan' => 'tidak dapat diperiksa (' . $e->getMessage() . ')'];
    }
}

/**
 * Buang tabel global dari berkas cabang.
 *
 * @param bool $apply  false = hanya pratinjau
 * @return array{ok:bool,error:string,cabang:array<int,array>,dibuang:int,ukuran:string}
 */
function branch_global_purge(bool $apply = false): array
{
    $hasil = ['ok' => true, 'error' => '', 'cabang' => [], 'dibuang' => 0, 'ukuran' => ''];
    $daftar = [];
    foreach (branches() as $b) {
        $p = db_branch_path((int)$b['id']);
        if (!is_file($p) || filesize($p) < 4096) continue;
        $laporan = branch_global_tables_report($p);
        if (!$laporan) continue;
        $daftar[] = ['path' => $p, 'code' => (string)$b['code'], 'id' => (int)$b['id'], 'tabel' => $laporan];
    }
    if (!$daftar) {
        $hasil['error'] = 'Tidak ada tabel global pada berkas cabang — tidak ada yang perlu dibersihkan.';
        return $hasil;
    }
    /* Menolak bila ada tabel yang isinya tidak seluruhnya ada di central. */
    $berbahaya = [];
    foreach ($daftar as $d) {
        foreach ($d['tabel'] as $t) if (!$t['aman']) $berbahaya[] = $d['code'] . '.' . $t['tabel'] . ' (' . $t['alasan'] . ')';
    }
    if ($berbahaya) {
        $hasil['ok'] = false;
        $hasil['error'] = 'DITOLAK: ada tabel global pada berkas cabang yang isinya TIDAK seluruhnya ada di central: '
            . implode('; ', array_slice($berbahaya, 0, 4)) . '. Pindahkan/verifikasi dulu — tidak ada yang dihapus.';
        return $hasil;
    }
    $hasil['cabang'] = $daftar;
    $ukuranAwal = 0;
    foreach ($daftar as $d) $ukuranAwal += (int)filesize($d['path']);
    $hasil['ukuran'] = round($ukuranAwal / 1048576, 2) . ' MB';
    if (!$apply) return $hasil;

    $dibuang = 0;
    foreach ($daftar as $d) {
        $p = $d['path'];
        $sebelum = (int)filesize($p);
        $pdo = db_open($p);
        try {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('BEGIN IMMEDIATE');
            foreach ($d['tabel'] as $t) {
                $pdo->exec('DROP TABLE IF EXISTS "' . $t['tabel'] . '"');
                $dibuang++;
            }
            $pdo->exec('COMMIT');
            $pdo->exec('VACUUM');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
            $hasil['ok'] = false;
            $hasil['error'] = 'Gagal membersihkan ' . basename($p) . ': ' . $e->getMessage();
            return $hasil;
        }
        $sesudah = (int)filesize($p);
        $d['sebelum'] = $sebelum;
        $d['sesudah'] = $sesudah;
        /* Penanda versi skema ditulis ulang supaya berkas dikenali "sudah mutakhir". */
        try {
            $pdo->exec('PRAGMA user_version = ' . schema_version_code());
        } catch (Throwable $e) { /* opsional */ }
    }
    $hasil['dibuang'] = $dibuang;
    $ukuranAkhir = 0;
    foreach ($daftar as $d) $ukuranAkhir += (int)filesize($d['path']);
    $hasil['ukuran'] = round($ukuranAwal / 1048576, 2) . ' MB → ' . round($ukuranAkhir / 1048576, 2) . ' MB';
    return $hasil;
}

/**
 * Apakah SEMUA berkas cabang sudah ramping (tanpa tabel global)?
 * @return array{bersih:bool,bermasalah:array<int,string>}
 */
function branch_globals_clean(): array
{
    $bermasalah = [];
    foreach (branches() as $b) {
        $p = db_branch_path((int)$b['id']);
        if (!is_file($p) || filesize($p) < 4096) continue;
        $laporan = branch_global_tables_report($p);
        foreach ($laporan as $t) $bermasalah[] = (string)$b['code'] . '.' . $t['tabel'];
    }
    return ['bersih' => $bersih = ($bermasalah === []), 'bermasalah' => $bermasalah];
}
