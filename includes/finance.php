<?php
/**
 * KEUANGAN — HPP, LABA KOTOR, BIAYA OPERASIONAL, DAN LABA BERSIH
 * =============================================================
 *
 * Permintaan pemilik klinik: satu tempat untuk melihat untung/rugi klinik,
 * dengan dua tingkat laporan yang bisa dipilih di Pengaturan Sistem:
 *
 *   DASAR   : Total Pendapatan (Omzet) = pendapatan treatment + pendapatan
 *             skincare − diskon transaksi − diskon member;
 *             HPP treatment + HPP produk;
 *             LABA BERSIH = omzet − HPP.
 *
 *   LENGKAP : semua yang di atas, ditambah LABA KOTOR dan rincian BIAYA
 *             OPERASIONAL (gaji, listrik & air, marketing, sewa bangunan,
 *             aplikasi, pajak, operasional lainnya — dapat ditambah/diganti
 *             nama sendiri), TOTAL BIAYA OPERASIONAL, lalu LABA BERSIH.
 *
 * Biaya operasional diisi langsung nominalnya beserta PERIODE PEMBAYARAN
 * (1/3/6/12/24/36/60 bulan) karena kenyataannya berbeda-beda: sewa bangunan
 * biasanya dibayar tahunan, sedangkan gaji/listrik/marketing/pajak bulanan.
 * Sistem menghitung porsi biaya untuk rentang laporan yang dipilih secara
 * proporsional (nominal ÷ periode, lalu dikalikan panjang rentang laporan),
 * sehingga memilih periode "bulan ini" maupun "tahun ini" tetap konsisten.
 *
 * HPP diambil dari SNAPSHOT pada tiap baris transaksi (order_items.hpp) supaya
 * mengubah HPP master TIDAK mengubah laba transaksi lama. Baris lama (sebelum
 * kolom ini ada) bernilai 0 dan dihitung dari master sebagai cadangan.
 *
 * Angka HPP & laba bersih bersifat INTERNAL: halaman/tombol/kartunya hanya
 * ditampilkan untuk level owner (Super Admin & Direktur) lewat permission
 * `finance.view` + pemeriksaan `is_owner_level()` di server.
 */
declare(strict_types=1);

/** Mode laporan keuangan: 'dasar' (omzet − HPP) atau 'lengkap' (+ biaya operasional). */
function finance_mode(): string
{
    $m = (string)setting('finance_mode', 'dasar');
    return in_array($m, ['dasar', 'lengkap'], true) ? $m : 'dasar';
}

/** Label mode untuk tampilan. */
function finance_mode_label(?string $mode = null): string
{
    return ($mode ?? finance_mode()) === 'lengkap' ? 'Laporan Lengkap' : 'Laporan Dasar';
}

/** Pilihan periode pembayaran biaya operasional (bulan) → label. */
function finance_period_options(): array
{
    return [
        1 => 'Setiap bulan',
        3 => 'Setiap 3 bulan',
        6 => 'Setiap 6 bulan',
        12 => 'Setiap 1 tahun',
        24 => 'Setiap 2 tahun',
        36 => 'Setiap 3 tahun',
        60 => 'Setiap 5 tahun',
    ];
}

/** Normalkan periode yang dikirim form/halaman. */
function finance_period_norm($v): int
{
    $n = (int)$v;
    return array_key_exists($n, finance_period_options()) ? $n : 1;
}

/** Label periode ("Setiap 1 tahun" / "Setiap bulan"). */
function finance_period_label($months): string
{
    $o = finance_period_options();
    $m = finance_period_norm($months);
    return $o[$m];
}

/** Rata-rata hari per bulan (hanya untuk keterangan/kompatibilitas). */
function finance_days_per_month(): float
{
    return 30.4375;
}

/**
 * Banyak BULAN KALENDER yang terpakai oleh rentang laporan (berbobot).
 *
 * Penting (perbaikan yang dilaporkan pemilik, ronde 33): dulu porsi biaya
 * dihitung per HARI dengan pembagi 30,4375 hari/bulan. Akibatnya rentang
 * "3 bulan" (= 92 hari) dihitung sebagai 3,0226 bulan sehingga biaya bulanan
 * Rp 25.000.000 menghasilkan Rp 75.564.685 — ada "tambahan" Rp 564.685 yang
 * tidak sesuai nominal yang diisi (pemilik mengharapkan 3 × 25 juta = 75 juta).
 * Sekarang hitungannya memakai PANJANG BULAN SEBENARNYA: tiap bulan yang
 * tersentuh dihitung (jumlah hari terpakai ÷ jumlah hari bulan itu), sehingga
 * rentang 3 bulan penuh = tepat 3,0 bulan; rentang sebagian bulan tetap
 * diprorata secara wajar.
 *
 * @return array{months:float,days:int,labels:string[]}
 */
function finance_month_weights(string $ps, string $pe): array
{
    $t1 = strtotime($ps);
    $t2 = strtotime($pe);
    if ($t1 === false || $t2 === false || $t2 < $t1) return ['months' => 0.0, 'days' => 0, 'labels' => []];
    $days = (int)floor(($t2 - $t1) / 86400) + 1;
    $cursor = strtotime(date('Y-m-01', $t1));
    $months = 0.0;
    $labels = [];
    $guard = 0;
    while ($cursor <= $t2 && $guard++ < 600) {
        $inMonth = (int)date('t', $cursor);
        $from = max($t1, $cursor);
        $to = min($t2, strtotime(date('Y-m-t', $cursor)));
        if ($to >= $from && $inMonth > 0) {
            $covered = (int)floor(($to - $from) / 86400) + 1;
            $months += $covered / $inMonth;
            $labels[] = date('M Y', $cursor) . ' (' . $covered . '/' . $inMonth . ')';
        }
        $cursor = strtotime(date('Y-m-01', strtotime('+1 month', $cursor)));
    }
    return ['months' => round($months, 6), 'days' => $days, 'labels' => $labels];
}

/**
 * Porsi satu baris nominal untuk rentang laporan.
 *
 * @param array  $row    baris nominal (amount, period_months)
 * @param float  $months bobot bulan dari finance_month_weights()
 */
function finance_cost_share_months(array $row, float $months): float
{
    $period = finance_period_norm($row['period_months'] ?? 1);
    $amount = (float)($row['amount'] ?? 0);
    if ($amount == 0.0 || $months <= 0) return 0.0;
    return round($amount / $period * $months, 2);
}

/**
 * Pilihan TINGGI MAKSIMAL tabel "Perhitungan <mode>" pada menu Keuangan (px).
 *
 * Permintaan pemilik (ronde 32): kartu perhitungan bisa semakin panjang ke bawah
 * begitu jumlah pos biaya / cabang bertambah. Karena itu tingginya dibatasi
 * setelan dan isinya dapat DIGULIR ke atas-bawah seperti tabel biasa. Nilai
 * bawaan = tinggi tampilan saat ini supaya tidak ada yang berubah dulu; 0 =
 * tanpa batas (seluruh isi tampil).
 */
function finance_calc_height_options(): array
{
    /* Label SENGAJA pendek: pilihan yang panjang membuat pemilih melebarkan
       kartu di layar HP (± 320px) karena lebar bawaan <select> mengikuti teks
       pilihan terpanjang. Keterangan px & jumlah baris ada di bawah tabel. */
    return [
        420  => 'Ringkas (± 9 baris)',
        620  => 'Sedang (± 14 baris)',
        820  => 'Tinggi (± 18 baris)',
        1240 => 'Bawaan sekarang (± 28 baris)',
        0    => 'Tanpa batas (seluruh isi)',
    ];
}

/** Normalkan tinggi maksimal yang dikirim formulir (px, 0 = tanpa batas). */
function finance_calc_height_norm($v): int
{
    $n = (int)$v;
    return array_key_exists($n, finance_calc_height_options()) ? $n : 1240;
}

/** Tinggi maksimal tabel perhitungan yang berlaku (px; 0 = tanpa batas). */
function finance_calc_height(): int
{
    return finance_calc_height_norm(setting('finance_calc_max_px', '1240'));
}

/** Label setelan tinggi ("1.240 px (bawaan …)" / "tanpa batas"). */
function finance_calc_height_label(?int $px = null): string
{
    $px = $px ?? finance_calc_height();
    $o = finance_calc_height_options();
    $lbl = (string)($o[$px] ?? '');
    return $px > 0 ? ($lbl . ' — ' . num($px) . ' px') : $lbl;
}

/** Jumlah cakupan biaya = jumlah cabang (untuk pembagian biaya bersama). */
function finance_cost_scope_count(): int
{
    $n = 0;
    try { $n = count(branches()); } catch (Throwable $ex) { $n = 0; }
    return max(1, $n);
}

/**
 * Porsi biaya BERSAMA saat satu cabang dilihat sendirian.
 *
 * Permintaan pemilik (ronde 31): biaya operasional pada cakupan "Semua cabang"
 * adalah nominal untuk SELURUH klinik, jadi saat laporan difilter ke satu cabang
 * hanya sebagian yang dibebankan ke cabang itu — dibagi RATA ke semua cabang
 * (2 cabang = masing-masing separuh, 3 cabang = sepertiga, dst). Sebelumnya
 * porsi dihitung dari omzet cabang yang SEDANG tersaring, sehingga cabang
 * pertama menanggung 100% biaya bersama (laba satu cabang tampak minus
 * sementara totalnya untung).
 */
function finance_company_share(?int $branchId): float
{
    if ($branchId === null) return 1.0;                 // tampilan semua cabang
    return round(1 / finance_cost_scope_count(), 6);
}

/**
 * Baris nominal sebuah pos biaya pada satu cakupan (0 = semua cabang).
 * Mengembalikan null bila cakupan itu belum pernah diisi.
 */
function finance_cost_amount(int $costId, int $scope): ?array
{
    return one('SELECT * FROM finance_cost_amounts WHERE cost_id = ? AND branch_id = ?', [$costId, $scope]);
}

/**
 * Semua pos biaya beserta nominalnya pada SATU cakupan (untuk halaman pengaturan).
 *
 * Tiap baris memuat MODE pos biaya itu (per cabang / semua cabang) sehingga
 * pengguna tahu nominal cakupan ini sedang dipakai atau tidak.
 */
function finance_cost_rows_for_scope(int $scope): array
{
    $costs = all('SELECT * FROM finance_costs ORDER BY sort ASC, id ASC');
    $n = finance_cost_scope_count();
    $out = [];
    foreach ($costs as $c) {
        $id = (int)$c['id'];
        $mode = ((string)($c['cost_mode'] ?? 'branch') === 'company') ? 'company' : 'branch';
        $a = finance_cost_amount($id, $scope);
        $amount = (float)($a['amount'] ?? 0);
        $status = (string)($a['status'] ?? 'active');
        /* Nominal per cabang yang sedang terisi (untuk rincian "berlaku"). */
        $filled = all('SELECT branch_id, amount FROM finance_cost_amounts
                       WHERE cost_id = ? AND branch_id <> 0 AND COALESCE(amount,0) > 0 ORDER BY branch_id', [$id]);
        $company = finance_cost_amount($id, 0);
        $companyAmt = (float)($company['amount'] ?? 0);
        $counted = ($mode === 'company')
            ? ($scope === 0 && $amount > 0 && $status === 'active')
            : ($scope > 0 && $amount > 0 && $status === 'active');
        $out[] = [
            'id' => $id, 'name' => (string)$c['name'], 'category' => (string)($c['category'] ?? ''),
            'note' => (string)($c['note'] ?? ''), 'sort' => (int)$c['sort'],
            'scope' => $scope,
            'amount' => $amount,
            'period_months' => finance_period_norm($a['period_months'] ?? 1),
            'status' => $status,
            'has_row' => $a !== null,
            'mode' => $mode,
            'mode_label' => finance_cost_mode_label($mode),
            'counted' => $counted,
            /* Dipakai kolom "Yang berlaku": cakupan mana yang sedang dihitung. */
            'applied_label' => ($mode === 'company')
                ? 'Semua cabang (biaya bersama)'
                : (count($filled) ? 'Per cabang: ' . implode(', ', array_map(
                        fn($f) => finance_cost_scope_label((int)$f['branch_id']), $filled)) : 'Belum ada nominal'),
            'applied_amount' => ($mode === 'company') ? $companyAmt
                : array_sum(array_map(fn($f) => (float)$f['amount'], $filled)),
            'applied_scope' => ($mode === 'company') ? 0 : $scope,
            'applicable' => $counted,
            'company_amount' => $companyAmt,
            'branch_filled' => count($filled),
            'branch_total' => $n,
            'branch_rows' => array_map(fn($f) => ['scope' => (int)$f['branch_id'],
                'label' => finance_cost_scope_label((int)$f['branch_id']), 'amount' => (float)$f['amount']], $filled),
        ];
    }
    return $out;
}

/** Peta cakupan yang BERLAKU untuk tiap pos biaya: cost_id => scope. */
function finance_cost_applied_map(): array
{
    $out = [];
    foreach (all('SELECT cost_id, branch_id FROM finance_cost_amounts WHERE applicable = 1') as $r) {
        $out[(int)$r['cost_id']] = (int)$r['branch_id'];
    }
    return $out;
}

/** Label cakupan biaya ("Semua cabang" / nama cabang). */
function finance_cost_scope_label(int $scope): string
{
    if ($scope <= 0) return 'Semua cabang';
    $n = scalar('SELECT name FROM branches WHERE id = ?', [$scope], '');
    return $n !== '' ? (string)$n : 'Cabang #' . $scope;
}

/** Cakupan biaya yang sedang DIPAKAI (pilihan di halaman Keuangan). */
function finance_cost_scope_pick(): int
{
    $v = (int)gp('cost_scope', 0);
    if ($v <= 0) return 0;
    return one('SELECT id FROM branches WHERE id = ?', [$v]) ? $v : 0;
}

/**
 * MODE sebuah pos biaya (permintaan pemilik, ronde 33):
 *
 *   'branch'  → nominal PER CABANG dipakai bersama-sama: SEMUA cabang yang
 *               nominalnya terisi ikut dihitung (isi cabang 1 simpan, cabang 2
 *               simpan → cabang 1 & 2 terhitung, bukan saling menggantikan).
 *   'company' → nominal "Semua cabang" (biaya bersama) dipakai SENDIRI dan
 *               menggantikan seluruh isian per cabang untuk pos biaya itu
 *               (nilainya tetap tersimpan, dapat dipakai kembali kapan saja).
 *
 * Mode ditentukan saat MENYIMPAN cakupan (lihat finance_cost_save_scope()) —
 * jadi memilih cakupan "Semua cabang" lalu Simpan = mode company, dan memilih
 * cakupan sebuah cabang lalu Simpan = mode branch.
 */
function finance_cost_mode(int $costId): string
{
    $m = (string)scalar('SELECT COALESCE(cost_mode,"branch") FROM finance_costs WHERE id = ?', [$costId], 'branch');
    return $m === 'company' ? 'company' : 'branch';
}

/** Apakah pos biaya ini memakai nominal "Semua cabang" (biaya bersama)? */
function finance_cost_is_company(int $costId): bool
{
    return finance_cost_mode($costId) === 'company';
}

/** Label mode untuk tampilan. */
function finance_cost_mode_label(string $mode): string
{
    return $mode === 'company' ? 'Semua cabang (biaya bersama)' : 'Per cabang';
}

/**
 * Segarkan penanda `applicable` dari mode + nominal yang terisi.
 *
 * Penanda ini hanya untuk TAMPILAN (menandai baris/cakupan mana yang sedang
 * dipakai); perhitungan memakai mode pos biaya di atas.
 */
function finance_cost_sync_applicable(?int $costId = null): void
{
    $ids = $costId !== null ? [$costId] : array_map(fn($r) => (int)$r['id'], all('SELECT id FROM finance_costs'));
    foreach ($ids as $id) {
        q('UPDATE finance_cost_amounts SET applicable = 0 WHERE cost_id = ?', [$id]);
        $mode = finance_cost_mode($id);
        if ($mode === 'company') {
            q("UPDATE finance_cost_amounts SET applicable = 1
               WHERE cost_id = ? AND branch_id = 0 AND status = 'active' AND COALESCE(amount,0) > 0", [$id]);
        } else {
            q("UPDATE finance_cost_amounts SET applicable = 1
               WHERE cost_id = ? AND branch_id <> 0 AND status = 'active' AND COALESCE(amount,0) > 0", [$id]);
        }
    }
}

/**
 * Baris nominal yang IKUT DIHITUNG pada laporan.
 *
 * Mode 'company' → hanya baris "Semua cabang" yang dipakai.
 * Mode 'branch'  → SELURUH baris per cabang yang terisi & aktif dipakai
 *                  bersama-sama (tiap cabang dibebani nominalnya sendiri).
 */
function finance_cost_effective_rows(bool $onlyActive = true): array
{
    $w = ["((c.cost_mode = 'company' AND a.branch_id = 0)
           OR (COALESCE(c.cost_mode,'branch') <> 'company' AND a.branch_id <> 0))",
          'COALESCE(a.amount,0) > 0'];
    if ($onlyActive) $w[] = "a.status = 'active'";
    return all('SELECT c.id cost_id, c.name, c.category, c.note,
                       COALESCE(c.cost_mode,\'branch\') cost_mode,
                       a.id amount_id, a.branch_id scope, a.amount, a.period_months, a.status
                FROM finance_costs c
                JOIN finance_cost_amounts a ON a.cost_id = c.id
                WHERE ' . implode(' AND ', $w) . '
                ORDER BY c.sort ASC, c.id ASC, a.branch_id ASC');
}

/** Daftar pos biaya operasional (definisi) — dipakai halaman/ekspor/uji. */
function finance_costs(bool $onlyActive = true, ?int $branchId = null): array
{
    return all('SELECT * FROM finance_costs' . ($onlyActive ? " WHERE status = 'active'" : '')
        . ' ORDER BY sort ASC, id ASC');
}

/** Pos biaya yang ikut dihitung (padanan lama `finance_costs_active`). */
function finance_costs_active(): array
{
    return finance_cost_effective_rows(true);
}

/**
 * Biaya operasional pada rentang laporan — DIKELOMPOKKAN PER POS BIAYA.
 *
 * Permintaan pemilik (ronde 33): tabel perhitungan TIDAK boleh menampilkan
 * "Gaji" berulang tiap cabang (akan sangat panjang bila cabangnya banyak).
 * Karena itu tiap pos biaya menjadi SATU baris, dengan rincian per cabang di
 * dalamnya, mis. "Gaji Rp 50.000.000 (Kaliwungu Rp 25.000.000 + Cepiring
 * Rp 25.000.000)".
 *
 * Aturan mode (lihat finance_cost_mode()):
 *   'branch'  → SEMUA cabang yang nominalnya terisi dihitung bersama;
 *   'company' → hanya nominal "Semua cabang" yang dihitung (menggantikan
 *               per cabang), dan saat satu cabang difilter hanya SEBAGIAN yang
 *               dibebankan ke cabang itu (dibagi rata ke semua cabang).
 *
 * @param int|null $branchId cabang yang sedang dilihat; null = semua cabang
 * @return array{rows:array,total:float,days:int,months:float,full_total:float,
 *               company_share:float,branches:int,labels:array}
 */
function finance_operational_costs(array $f, ?int $branchId = null): array
{
    $ps = (string)($f['ps'] ?? '');
    $pe = (string)($f['pe'] ?? '');
    $kosong = ['rows' => [], 'total' => 0.0, 'days' => 0, 'months' => 0.0, 'full_total' => 0.0,
               'company_share' => 1.0, 'branches' => finance_cost_scope_count(), 'labels' => []];
    if ($ps === '' || $pe === '' || strtotime($ps) === false || strtotime($pe) === false) return $kosong;

    $mw = finance_month_weights($ps, $pe);
    $months = (float)$mw['months'];
    if ($months <= 0) return $kosong;
    $n = finance_cost_scope_count();
    $companyShare = finance_company_share($branchId);

    $groups = [];       // cost_id => baris gabungan
    $total = 0.0;
    $fullTotal = 0.0;
    foreach (finance_cost_effective_rows(true) as $c) {
        $costId = (int)$c['cost_id'];
        $scope = (int)$c['scope'];
        $isCompany = ($scope === 0);
        $full = finance_cost_share_months($c, $months);
        /* Cakupan mana yang dibebankan pada tampilan ini. */
        if ($branchId === null) {
            $take = $full;                                  // semua cabang
        } elseif ($isCompany) {
            $take = round($full / $n, 2);                   // bersama: dibagi rata
        } elseif ($scope === $branchId) {
            $take = $full;                                  // biaya cabang ini sendiri
        } else {
            continue;                                       // biaya cabang lain
        }
        if (!isset($groups[$costId])) {
            $groups[$costId] = [
                'id' => $costId, 'name' => (string)$c['name'], 'category' => (string)($c['category'] ?? ''),
                'note' => (string)($c['note'] ?? ''), 'mode' => (string)($c['cost_mode'] ?? 'branch'),
                'amount' => 0.0, 'share' => 0.0, 'parts' => [],
            ];
        }
        $groups[$costId]['amount'] += (float)$c['amount'];
        $groups[$costId]['share'] += $take;
        $groups[$costId]['parts'][] = [
            'scope' => $scope, 'scope_label' => finance_cost_scope_label($scope),
            'is_company' => $isCompany,
            'amount' => (float)$c['amount'],
            'share' => $take, 'share_all' => $full,
            'period_months' => finance_period_norm($c['period_months'] ?? 1),
            'period_label' => finance_period_label($c['period_months'] ?? 1),
            'monthly' => round((float)$c['amount'] / finance_period_norm($c['period_months'] ?? 1), 2),
        ];
        $total += $take;
        $fullTotal += $full;
    }

    /* Susun hasil akhir per pos biaya (urutan mengikuti sort pos biaya). */
    $rows = [];
    foreach (finance_costs(false) as $c) {
        $id = (int)$c['id'];
        if (!isset($groups[$id])) continue;
        $g = $groups[$id];
        $periods = array_values(array_unique(array_map(fn($p) => (int)$p['period_months'], $g['parts'])));
        $g['amount'] = round($g['amount'], 2);
        $g['share'] = round($g['share'], 2);
        $g['period_months'] = $periods[0] ?? 1;
        $g['period_label'] = count($periods) > 1 ? 'periode beragam'
            : finance_period_label($periods[0] ?? 1);
        $g['monthly'] = round(array_sum(array_map(fn($p) => (float)$p['monthly'], $g['parts'])), 2);
        $g['is_company'] = ($g['mode'] === 'company');
        /* Label cakupan untuk dokumen (CSV/Excel/PDF/email) — satu baris per pos. */
        $g['scope_label'] = $g['is_company']
            ? 'Semua cabang (biaya bersama)'
            : (count($g['parts']) > 1 ? 'Per cabang (' . num(count($g['parts'])) . ' cabang)'
                                      : ($g['parts'][0]['scope_label'] ?? 'Per cabang'));
        /* Rincian singkat untuk kolom/tabel: "Kaliwungu 25.000.000 + Cepiring …". */
        $g['breakdown'] = implode(' + ', array_map(
            fn($p) => $p['scope_label'] . ' ' . money((float)$p['amount']), $g['parts']));
        /* Cakupan yang terisi untuk pos ini di SELURUH cakupan (untuk pemberitahuan
           "masih ada cabang yang belum diisi"). */
        $g['filled_scopes'] = array_map(fn($r) => (int)$r['branch_id'],
            all('SELECT branch_id FROM finance_cost_amounts WHERE cost_id = ? AND COALESCE(amount,0) > 0', [$id]));
        $g['all_parts'] = array_map(fn($r) => [
            'scope' => (int)$r['branch_id'], 'scope_label' => finance_cost_scope_label((int)$r['branch_id']),
            'amount' => (float)$r['amount'], 'status' => (string)$r['status'],
        ], all('SELECT branch_id, amount, status FROM finance_cost_amounts
                 WHERE cost_id = ? AND COALESCE(amount,0) > 0 ORDER BY branch_id', [$id]));
        $rows[] = $g;
    }

    return ['rows' => $rows, 'total' => round($total, 2), 'days' => (int)$mw['days'],
            'months' => round($months, 6), 'full_total' => round($fullTotal, 2),
            'company_share' => $companyShare, 'branches' => $n, 'labels' => $mw['labels']];
}

/**
 * Porsi tiap cabang untuk biaya bersama = DIBAGI RATA (permintaan pemilik).
 * Dulu porsi dihitung dari omzet sehingga cabang yang omzetnya kecil hampir
 * tidak menanggung biaya bersama. Nilai dijumlahkan tepat 1.0.
 *
 * @return array<int,float> branch_id => porsi
 */
function finance_branch_shares(?array $f = null): array
{
    $all = array_map(fn($b) => (int)$b['id'], branches());
    $n = max(1, count($all));
    $out = [];
    $acc = 0.0;
    foreach ($all as $i => $id) {
        $share = ($i === count($all) - 1) ? round(1.0 - $acc, 6) : round(1 / $n, 6);
        $out[$id] = max(0.0, $share);
        $acc += $out[$id];
    }
    if (!$out) $out[0] = 1.0;
    return $out;
}

/**
 * Simpan seluruh daftar biaya pada SATU cakupan (satu tombol Simpan di bawah).
 *
 * ATURAN MODE (permintaan pemilik, ronde 33):
 *   • menyimpan cakupan "Semua cabang" (0) dengan nominal → pos biaya itu memakai
 *     BIAYA BERSAMA; seluruh isian per cabangnya berhenti dipakai (nilainya tetap
 *     tersimpan & dapat dipakai kembali kapan saja);
 *   • menyimpan cakupan SEBUAH CABANG dengan nominal → pos biaya itu kembali
 *     memakai nominal PER CABANG: SEMUA cabang yang terisi ikut dihitung
 *     (cabang lain TIDAK ikut dinonaktifkan — dulu di sinilah bugnya: menyimpan
 *     cabang 2 membuat perhitungan cabang 1 hilang).
 *
 * Pos biaya yang nominalnya DIKOSONGKAN pada cakupan yang sedang disimpan tidak
 * mengubah mode pos biaya itu (agar menyimpan cakupan cabang yang masih kosong
 * tidak diam-diam mematikan biaya bersama).
 *
 * @param array $rows [['id'=>int,'name'=>string,'amount'=>mixed,'period'=>int,'active'=>bool], ...]
 */
function finance_cost_save_scope(int $scope, array $rows): array
{
    $res = ['saved' => 0, 'added' => 0, 'replaced' => [], 'applied' => [], 'zeroed' => 0,
            'mode_company' => [], 'mode_branch' => []];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '' && $id > 0) $name = (string)scalar('SELECT name FROM finance_costs WHERE id = ?', [$id], '');
        if ($name === '') continue;
        $amount = max(0.0, qty_parse($r['amount'] ?? 0));
        $period = finance_period_norm($r['period'] ?? 1);
        $status = !empty($r['active']) ? 'active' : 'inactive';
        if ($id > 0) {
            $row = one('SELECT * FROM finance_costs WHERE id = ?', [$id]);
            if (!$row) continue;
            q('UPDATE finance_costs SET name = ? WHERE id = ?', [$name, $id]);
        } else {
            q('INSERT INTO finance_costs (name, amount, period_months, sort, status, created_at, cost_mode)
               VALUES (?,0,1,?, "active", datetime("now","localtime"), "branch")',
                [$name, (int)scalar('SELECT COALESCE(MAX(sort),0)+10 FROM finance_costs')]);
            $id = (int)db()->lastInsertId();
            $res['added']++;
        }
        q('INSERT INTO finance_cost_amounts (cost_id, branch_id, amount, period_months, status, applicable, updated_at)
           VALUES (?,?,?,?,?,0,datetime("now","localtime"))
           ON CONFLICT(cost_id, branch_id) DO UPDATE SET amount = excluded.amount,
             period_months = excluded.period_months, status = excluded.status,
             updated_at = excluded.updated_at', [$id, $scope, $amount, $period, $status]);
        $res['saved']++;

        /* Cakupan yang disimpan hanya mengubah MODE bila nominalnya terisi
           (mengosongkan nominal = pos biaya itu berhenti dihitung, mode tetap). */
        if ($amount > 0) {
            $oldMode = finance_cost_mode($id);
            if ($scope <= 0) {
                /* Biaya bersama menggantikan per cabang → catat mana yang tergantikan. */
                foreach (all("SELECT branch_id FROM finance_cost_amounts
                               WHERE cost_id = ? AND branch_id <> 0 AND COALESCE(amount,0) > 0", [$id]) as $o) {
                    if ($oldMode === 'branch') {
                        $res['replaced'][] = ['cost' => $name, 'scope' => finance_cost_scope_label((int)$o['branch_id'])];
                    }
                }
                if ($oldMode !== 'company') $res['mode_company'][] = $name;
                q('UPDATE finance_costs SET cost_mode = "company" WHERE id = ?', [$id]);
            } else {
                if ($oldMode === 'company') $res['mode_branch'][] = $name;
                q('UPDATE finance_costs SET cost_mode = "branch" WHERE id = ?', [$id]);
            }
        } else {
            $res['zeroed']++;
        }
        $res['applied'][] = $name;
        finance_cost_sync_applicable($id);
    }
    /* Rapikan mode pos biaya yang tidak punya nominal sama sekali: biarkan
       modenya, tetapi penandanya disegarkan supaya tidak ada baris "berlaku"
       yang nominalnya kosong. */
    $res['replaced'] = array_values(array_unique($res['replaced'], SORT_REGULAR));
    return $res;
}

/**
 * Pos biaya yang akan KEHILANGAN status dipakai bila cakupan tertentu disimpan
 * dengan nominal — dipakai antarmuka untuk memberi tahu (dan menentukan
 * perlu-tidaknya peringatan 2x) SEBELUM menyimpan.
 *
 *   • cakupan "Semua cabang" (0) → isian PER CABANG untuk pos itu berhenti dipakai;
 *   • cakupan sebuah cabang      → isian "Semua cabang" untuk pos itu berhenti dipakai.
 *
 * Nilai yang berhenti dipakai tetap TERSIMPAN dan dapat dipakai kembali kapan saja
 * dengan menyimpan ulang pada cakupannya.
 *
 * @param int   $scope cakupan yang akan disimpan (0 = semua cabang)
 * @param array $rows  seperti finance_cost_save_scope()
 * @return array<int,array{cost:string,scope:string,scope_id:int}>
 */
function finance_cost_save_scope_replacements(int $scope, array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) continue;
        if (max(0.0, qty_parse($r['amount'] ?? 0)) <= 0) continue;
        $name = (string)scalar('SELECT name FROM finance_costs WHERE id = ?', [$id], '');
        $mode = finance_cost_mode($id);
        if ($scope <= 0) {
            if ($mode !== 'branch') continue;
            foreach (all("SELECT branch_id FROM finance_cost_amounts
                           WHERE cost_id = ? AND branch_id <> 0 AND COALESCE(amount,0) > 0", [$id]) as $o) {
                $out[] = ['cost' => $name, 'scope' => finance_cost_scope_label((int)$o['branch_id']), 'scope_id' => (int)$o['branch_id']];
            }
        } else {
            if ($mode !== 'company') continue;
            $co = one('SELECT branch_id FROM finance_cost_amounts
                       WHERE cost_id = ? AND branch_id = 0 AND COALESCE(amount,0) > 0', [$id]);
            if ($co) $out[] = ['cost' => $name, 'scope' => 'Semua cabang (biaya bersama)', 'scope_id' => 0];
        }
    }
    return $out;
}

/**
 * Ringkasan berapa cakupan yang sudah terisi untuk tiap pos biaya — dipakai
 * antarmuka untuk mengingatkan "kalau sudah mengisi cabang 1, lanjutkan mengisi
 * cabang lain" (biaya per cabang dihitung untuk semua cabang yang terisi).
 *
 * @return array{scopes:array<int,array{scope:int,label:string,filled:int,total:int}>,missing:array<int,string>}
 */
function finance_cost_scope_progress(): array
{
    $costs = all('SELECT id, cost_mode FROM finance_costs');
    $total = count($costs);
    $counts = [];
    foreach (all('SELECT branch_id, COUNT(*) n FROM finance_cost_amounts
                  WHERE COALESCE(amount,0) > 0 GROUP BY branch_id') as $r) {
        $counts[(int)$r['branch_id']] = (int)$r['n'];
    }
    $companyMode = 0;
    foreach ($costs as $c) if ((string)($c['cost_mode'] ?? 'branch') === 'company') $companyMode++;
    $scopes = [[
        'scope' => 0, 'label' => 'Semua cabang',
        'filled' => (int)($counts[0] ?? 0),
        /* Untuk biaya bersama, yang relevan = pos biaya yang MODE-nya company. */
        'total' => max(1, $total),
    ]];
    $missing = [];
    foreach (branches() as $b) {
        $sid = (int)$b['id'];
        $scopes[] = ['scope' => $sid, 'label' => (string)$b['name'],
                     'filled' => (int)($counts[$sid] ?? 0), 'total' => max(1, $total)];
        if ((int)($counts[$sid] ?? 0) === 0) $missing[] = (string)$b['name'];
    }
    return ['scopes' => $scopes, 'missing' => $missing, 'total' => $total, 'company_mode' => $companyMode];
}

/** Hapus sebuah pos biaya beserta semua nominal cakupannya. */
function finance_cost_delete(int $costId): ?array
{
    $row = one('SELECT * FROM finance_costs WHERE id = ?', [$costId]);
    if (!$row) return null;
    q('DELETE FROM finance_cost_amounts WHERE cost_id = ?', [$costId]);
    q('DELETE FROM finance_costs WHERE id = ?', [$costId]);
    return $row;
}


/**
 * Ringkasan keuangan untuk satu filter laporan (lihat report_filters()).
 *
 * Omzet dihitung dari BARIS BARANG (treatment & skincare) dikurangi diskon
 * transaksi dan diskon member — bukan dari `orders.total`, karena total
 * transaksi memuat KODE UNIK transfer/QRIS yang hanya alat pencocokan mutasi.
 *
 * @param array $f filter laporan
 * @param bool  $withCosts hitung biaya operasional (mode lengkap)
 * @param int|null $branchId cabang yang dilihat (null = semua cabang). Biaya
 *        bersama dibagi RATA ke seluruh cabang saat satu cabang dilihat.
 * @param float|null $companyShare (usang — dihitung otomatis, diabaikan)
 */
function finance_summary(array $f, bool $withCosts = true, ?int $branchId = null,
                         ?float $companyShare = null): array
{
    $items = one("SELECT
            COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.subtotal ELSE 0 END),0) tr,
            COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.subtotal ELSE 0 END),0) sk,
            /* PAKET: pendapatan & HPP paket (baris bertipe 'package'). Komponen
               paket dicatat bertipe 'package_item' berharga 0 sehingga TIDAK
               dihitung dua kali di sini. */
            COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.subtotal ELSE 0 END),0) pkg,
            COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.quantity * oi.hpp ELSE 0 END),0) hpp_pkg,
            COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity *
                CASE WHEN oi.hpp > 0 THEN oi.hpp ELSE COALESCE(t.hpp, 0) END ELSE 0 END),0) hpp_tr,
            COALESCE(SUM(CASE WHEN oi.item_type='skincare' THEN oi.quantity *
                COALESCE(s.purchase_price, 0) ELSE 0 END),0) hpp_sk,
            COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity ELSE 0 END),0) qty_tr,
            COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.quantity ELSE 0 END),0) qty_sk,
            COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.quantity ELSE 0 END),0) qty_pkg
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        LEFT JOIN treatments t ON t.id = oi.treatment_id
        LEFT JOIN skincare_products s ON s.id = oi.skincare_id
        WHERE {$f['sql']}", $f['params']);
    $ord = one("SELECT COUNT(*) trx, COALESCE(SUM(o.subtotal),0) subtotal,
                       COALESCE(SUM(o.discount),0) disc,
                       COALESCE(SUM(o.member_discount),0) member_disc,
                       COALESCE(SUM(o.total),0) total
                FROM orders o WHERE {$f['sql']}", $f['params']);

    $tr = (float)$items['tr'];
    $sk = (float)$items['sk'];
    $pkg = (float)$items['pkg'];
    $disc = (float)$ord['disc'];
    $memberDisc = (float)$ord['member_disc'];
    $omzet = round($tr + $sk + $pkg - $disc - $memberDisc, 2);
    $hppTr = round((float)$items['hpp_tr'], 2);
    $hppSk = round((float)$items['hpp_sk'], 2);
    $hppPkg = round((float)$items['hpp_pkg'], 2);
    $hppTotal = round($hppTr + $hppSk + $hppPkg, 2);
    $labaKotor = round($omzet - $hppTotal, 2);

    $costs = $withCosts ? finance_operational_costs($f, $branchId)
        : ['rows' => [], 'total' => 0.0, 'days' => 0, 'months' => 0.0, 'full_total' => 0.0, 'company_share' => 1.0];
    $labaBersih = round($labaKotor - (float)$costs['total'], 2);

    return [
        'mode' => finance_mode(),
        'with_costs' => $withCosts,
        'pendapatan_treatment' => round($tr, 2),
        'pendapatan_skincare' => round($sk, 2),
        'pendapatan_paket' => round($pkg, 2),
        'diskon' => round($disc, 2),
        'diskon_member' => round($memberDisc, 2),
        'omzet' => $omzet,
        'hpp_treatment' => $hppTr,
        'hpp_produk' => $hppSk,
        'hpp_paket' => $hppPkg,
        'hpp_total' => $hppTotal,
        'laba_kotor' => $labaKotor,
        'biaya_rows' => $costs['rows'],
        'biaya_total' => (float)$costs['total'],
        'biaya_company_share' => (float)($costs['company_share'] ?? 1.0),
        'biaya_scopes' => (int)($costs['branches'] ?? finance_cost_scope_count()),
        'biaya_full' => (float)$costs['full_total'],
        'biaya_days' => (int)$costs['days'],
        'biaya_months' => round((float)($costs['months'] ?? 0), 4),
        'laba_bersih' => $labaBersih,
        'margin' => $omzet > 0 ? round($labaBersih / $omzet * 100, 1) : null,
        'trx' => (int)$ord['trx'],
        'qty_treatment' => (float)$items['qty_tr'],
        'qty_skincare' => (float)$items['qty_sk'],
        'qty_paket' => (float)($items['qty_pkg'] ?? 0),
        'total_bayar' => round((float)$ord['total'], 2),
        'subtotal' => round((float)$ord['subtotal'], 2),
    ];
}

/**
 * Laba bersih per periode (mengikuti grafik laporan): harian bila rentang
 * ≤ 62 hari, bulanan bila lebih panjang. Dipakai grafik laba bersih dan
 * perbandingan antar cabang.
 *
 * @return array{labels:array,labels_full:array,laba:array,omzet:array,hpp:array,biaya:array,
 *               branches:array<string,array>,granularity:string,total:float}
 */
function finance_series(array $f, ?int $maxBuckets = 24): array
{
    $ps = $f['ps'];
    $pe = $f['pe'];
    $days = (int)floor((strtotime($pe) - strtotime($ps)) / 86400) + 1;
    $daily = $days <= 62;
    $withCosts = finance_mode() === 'lengkap';

    $labels = [];
    $labelsFull = [];
    $buckets = [];      // key => ['omzet'=>, 'hpp'=>]
    $order = [];

    if ($daily) {
        $cursor = strtotime($ps);
        $guard = 0;
        while ($cursor <= strtotime($pe) && $guard++ < 400) {
            $k = date('Y-m-d', $cursor);
            $labels[] = date('d/m', $cursor);
            $labelsFull[] = tglIndo($k);
            $buckets[$k] = ['omzet' => 0.0, 'hpp' => 0.0];
            $order[] = $k;
            $cursor = strtotime('+1 day', $cursor);
        }
        $keyExpr = "date(o.created_at)";
    } else {
        $cursor = strtotime(date('Y-m-01', strtotime($ps)));
        $endTs = strtotime($pe);
        $guard = 0;
        while ($cursor <= $endTs && $guard++ < $maxBuckets) {
            $k = date('Y-m', $cursor);
            $labels[] = date('M', $cursor) . " '" . substr($k, 2, 2);
            $labelsFull[] = tglIndo($k . '-01');
            $buckets[$k] = ['omzet' => 0.0, 'hpp' => 0.0];
            $order[] = $k;
            $cursor = strtotime('+1 month', $cursor);
        }
        if (!$order) {
            $k = date('Y-m', $endTs);
            $labels[] = date('M', $endTs) . " '" . substr($k, 2, 2);
            $labelsFull[] = tglIndo($k . '-01');
            $buckets[$k] = ['omzet' => 0.0, 'hpp' => 0.0];
            $order[] = $k;
        }
        $keyExpr = "strftime('%Y-%m', o.created_at)";
    }

    $fill = function (array $sf) use (&$buckets, $keyExpr): void {
        $rows = all("SELECT $keyExpr k,
                COALESCE(SUM(CASE WHEN oi.item_type IN ('treatment','skincare','package') THEN oi.subtotal ELSE 0 END),0) bruto,
                COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity *
                        CASE WHEN oi.hpp > 0 THEN oi.hpp ELSE COALESCE(t.hpp,0) END
                    WHEN oi.item_type='skincare' THEN oi.quantity * COALESCE(s.purchase_price,0)
                    WHEN oi.item_type='package'  THEN oi.quantity * oi.hpp
                    ELSE 0 END),0) hpp
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN treatments t ON t.id = oi.treatment_id
            LEFT JOIN skincare_products s ON s.id = oi.skincare_id
            WHERE {$sf['sql']} GROUP BY k", $sf['params']);
        foreach ($rows as $r) {
            if (!isset($buckets[$r['k']])) continue;
            $buckets[$r['k']]['omzet'] += (float)$r['bruto'];
            $buckets[$r['k']]['hpp'] += (float)$r['hpp'];
        }
        /* Diskon transaksi & diskon member mengurangi omzet pada periode itu. */
        $dis = all("SELECT $keyExpr k,
                COALESCE(SUM(o.discount + o.member_discount),0) d
            FROM orders o WHERE {$sf['sql']} GROUP BY k", $sf['params']);
        foreach ($dis as $r) {
            if (!isset($buckets[$r['k']])) continue;
            $buckets[$r['k']]['omzet'] -= (float)$r['d'];
        }
    };
    $fill($f);

    /* Biaya operasional dialokasikan rata per BUCKET (bukan per transaksi):
       total porsi biaya untuk rentang dibagi jumlah bucket. */
    $costs = $withCosts ? finance_operational_costs($f) : ['rows' => [], 'total' => 0.0];
    $perBucket = $order ? (float)$costs['total'] / count($order) : 0.0;

    $laba = [];
    $omzet = [];
    $hpp = [];
    $biaya = [];
    foreach ($order as $k) {
        $o = round($buckets[$k]['omzet'], 2);
        $h = round($buckets[$k]['hpp'], 2);
        $omzet[] = $o;
        $hpp[] = $h;
        $biaya[] = round($perBucket, 2);
        $laba[] = round($o - $h - $perBucket, 2);
    }

    /* Perbandingan antar cabang (hanya bila cakupan semua cabang). Biaya tiap
       cabang dihitung SENDIRI-SENDIRI lewat finance_operational_costs() supaya
       cabang tidak dibebani biaya cabang lain dan biaya bersama hanya sebagian
       (dulu seluruh biaya dibebankan ke setiap cabang sehingga grafik per
       cabang tampak jauh lebih rugi daripada totalnya). */
    $branches = [];
    if ($f['scope'] === null) {
        foreach (branches() as $b) {
            $bf = $f;
            $bf['sql'] .= ' AND o.branch_id = ?';
            $bf['params'][] = (int)$b['id'];
            $bc = $withCosts ? finance_operational_costs($f, (int)$b['id']) : ['total' => 0.0];
            $s2 = finance_series_for_branch($bf, $order, $daily, (float)$bc['total'], count($order));
            $branches[(string)$b['name']] = $s2;
        }
    }

    return [
        'labels' => $labels, 'labels_full' => $labelsFull,
        'laba' => $laba, 'omzet' => $omzet, 'hpp' => $hpp, 'biaya' => $biaya,
        'branches' => $branches, 'granularity' => $daily ? 'harian' : 'bulanan',
        'total' => round(array_sum($laba), 2),
        'per_bucket_cost' => round($perBucket, 2),
    ];
}

/** Seri laba bersih untuk satu cabang (memakai bucket yang sama dengan induknya). */
function finance_series_for_branch(array $bf, array $order, bool $daily, float $costTotal, int $nBuckets): array
{
    $keyExpr = $daily ? "date(o.created_at)" : "strftime('%Y-%m', o.created_at)";
    $map = [];
    foreach ($order as $k) $map[$k] = ['omzet' => 0.0, 'hpp' => 0.0];
    $rows = all("SELECT $keyExpr k,
            COALESCE(SUM(CASE WHEN oi.item_type IN ('treatment','skincare','package') THEN oi.subtotal ELSE 0 END),0) bruto,
            COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity *
                    CASE WHEN oi.hpp > 0 THEN oi.hpp ELSE COALESCE(t.hpp,0) END
                WHEN oi.item_type='skincare' THEN oi.quantity * COALESCE(s.purchase_price,0)
                WHEN oi.item_type='package'  THEN oi.quantity * oi.hpp
                ELSE 0 END),0) hpp
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        LEFT JOIN treatments t ON t.id = oi.treatment_id
        LEFT JOIN skincare_products s ON s.id = oi.skincare_id
        WHERE {$bf['sql']} GROUP BY k", $bf['params']);
    foreach ($rows as $r) {
        if (!isset($map[$r['k']])) continue;
        $map[$r['k']]['omzet'] += (float)$r['bruto'];
        $map[$r['k']]['hpp'] += (float)$r['hpp'];
    }
    $dis = all("SELECT $keyExpr k, COALESCE(SUM(o.discount + o.member_discount),0) d
        FROM orders o WHERE {$bf['sql']} GROUP BY k", $bf['params']);
    foreach ($dis as $r) {
        if (!isset($map[$r['k']])) continue;
        $map[$r['k']]['omzet'] -= (float)$r['d'];
    }
    $perBucket = $nBuckets > 0 ? $costTotal / $nBuckets : 0.0;
    $out = [];
    foreach ($order as $k) $out[] = round($map[$k]['omzet'] - $map[$k]['hpp'] - $perBucket, 2);
    return $out;
}

/**
 * Ringkasan keuangan per cabang (untuk tabel & ekspor).
 * Mode lengkap: biaya operasional ikut dialokasikan per cabang.
 */
function finance_per_branch(array $f): array
{
    $rows = [];
    if ($f['scope'] !== null) {
        $one = one('SELECT id, name, code FROM branches WHERE id = ?', [$f['scope']]);
        $list = $one ? [$one] : [];
    } else {
        $list = branches();
    }
    /* Biaya bersama dibagi RATA ke seluruh cabang (finance_company_share()),
       sehingga jumlah laba seluruh cabang = laba pada tampilan "Semua Cabang". */
    foreach ($list as $b) {
        $bf = $f;
        if ($f['scope'] === null) {
            $bf['sql'] .= ' AND o.branch_id = ?';
            $bf['params'][] = (int)$b['id'];
        }
        $s = finance_summary($bf, finance_mode() === 'lengkap', (int)$b['id']);
        $s['branch_id'] = (int)$b['id'];
        $s['branch'] = (string)$b['name'];
        $s['branch_code'] = (string)($b['code'] ?? '');
        $rows[] = $s;
    }
    return $rows;
}

/**
 * Apakah blok KEUANGAN harus ikut pada dokumen laporan yang sedang dibuat?
 *
 * Normalnya hanya untuk level owner. Namun laporan bulanan yang dikirim ke
 * DIREKTUR/OWNER lewat email dibuat oleh pengguna yang sedang login (bisa saja
 * kasir), sehingga diperlukan cara MEMAKSA sertakan blok keuangan — dan itu
 * hanya dilakukan oleh kode pengirim email laporan keuangan (server), tidak
 * dapat dipicu dari halaman publik mana pun.
 */
function finance_report_include(?bool $force = null): bool
{
    static $forceFlag = false;
    if ($force !== null) $forceFlag = $force;
    if ($forceFlag) return true;
    if (!function_exists('has_perm')) return false;
    try {
        return has_perm('finance.view') && is_owner_level();
    } catch (Throwable $ex) {
        return false;
    }
}
