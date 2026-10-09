<?php
/**
 * Data agregat laporan — dipakai bersama oleh halaman Laporan (laporan.php),
 * halaman Top 5, dan ekspor PDF/Excel supaya angka di layar dan di dokumen
 * selalu berasal dari perhitungan yang sama.
 */
declare(strict_types=1);

/** Filter laporan dari query string (aman terhadap batas cabang). */
function report_filters(): array
{
    $scope = scope_branch();
    $period = gp('period', 'month');
    [$ps, $pe] = resolve_period($period, gp('start'), gp('end'));
    if (gp('from') !== '') { $ps = gp('from'); }
    if (gp('to') !== '')   { $pe = gp('to'); }

    $status = gp('status', 'paid');
    if (!in_array($status, ['paid', 'void', 'refund'], true)) $status = 'paid';

    $w = ['o.status = ?', 'date(o.created_at) BETWEEN ? AND ?'];
    $p = [$status, $ps, $pe];
    if ($scope !== null) { $w[] = 'o.branch_id = ?'; $p[] = $scope; }
    if (gp('cashier') !== '')   { $w[] = 'o.user_id = ?'; $p[] = (int)gp('cashier'); }
    if (gp('method') !== '')    { $w[] = 'EXISTS (SELECT 1 FROM payments pm WHERE pm.order_id=o.id AND pm.method = ? AND pm.status="valid")'; $p[] = gp('method'); }
    if (gp('treatment') !== '') { $w[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.treatment_id = ?)'; $p[] = (int)gp('treatment'); }
    if (gp('skincare') !== '')  { $w[] = 'EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.skincare_id = ?)'; $p[] = (int)gp('skincare'); }
    if (gp('cashier') !== '' && gp('branch') !== '' && is_owner_level()) { /* sudah ditangani scope */ }

    return [
        'scope' => $scope, 'period' => $period, 'ps' => $ps, 'pe' => $pe,
        'status' => $status, 'sql' => implode(' AND ', $w), 'params' => $p,
    ];
}

/** Ringkasan angka utama pada rentang filter. */
function report_totals(array $f): array
{
    $tot = one("SELECT COUNT(*) trx, COALESCE(SUM(o.subtotal),0) subtotal,
                       COALESCE(SUM(o.discount),0) disc, COALESCE(SUM(o.total),0) total,
                       COALESCE(SUM(o.member_discount),0) member_disc,
                       COALESCE(SUM(CASE WHEN o.member_card = 1 THEN 1 ELSE 0 END),0) member_trx
                FROM orders o WHERE {$f['sql']}", $f['params']);
    $items = one("SELECT
          COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.subtotal ELSE 0 END),0) tr,
          COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.subtotal ELSE 0 END),0) sk,
          /* PAKET dihitung terpisah supaya pendapatan paket terlihat sendiri. */
          COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.subtotal ELSE 0 END),0) pkg,
          COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity ELSE 0 END),0) tr_q,
          COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.quantity ELSE 0 END),0) sk_q,
          COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.quantity ELSE 0 END),0) pkg_q
        FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE {$f['sql']}", $f['params']);
    $pay = one("SELECT COALESCE(SUM(pm.amount),0) total, COUNT(*) n FROM payments pm
                JOIN orders o ON o.id=pm.order_id WHERE pm.status='valid' AND {$f['sql']}", $f['params']);

    /* ---- FAN-OUT untuk 10–15 CABANG (ronde 65) -------------------------------
       Batas ATTACH SQLite hanya 10 basis data per koneksi (central + 9 cabang) dan
       tidak dapat dinaikkan saat berjalan. Bila pemilik klinik membuka laporan
       "Semua Cabang" sedangkan cabangnya lebih banyak daripada slot ATTACH, cabang
       sisanya TIDAK terlihat oleh view — tanpa penambahan di bawah, angka laporan
       akan tampak lebih kecil dari kenyataan (data hilang diam-diam).
       Karena itu query yang SAMA dijalankan pada tiap cabang yang tidak ter-ATTACH
       (satu berkas pada satu waktu, koneksi terpisah) lalu dijumlahkan DI SINI.
       Data transaksi tidak dipindahkan ke central sama sekali. */
    $tambahan = ['trx' => 0, 'subtotal' => 0.0, 'disc' => 0.0, 'total' => 0.0, 'member_disc' => 0.0,
        'member_trx' => 0, 'tr' => 0.0, 'sk' => 0.0, 'pkg' => 0.0, 'tr_q' => 0.0, 'sk_q' => 0.0,
        'pkg_q' => 0.0, 'pay_total' => 0.0, 'pay_n' => 0];
    if (function_exists('db_route_missing_branches') && db_route_missing_branches()) {
        $a = db_cross_sum("SELECT COUNT(*) trx, COALESCE(SUM(o.subtotal),0) subtotal,
                                  COALESCE(SUM(o.discount),0) disc, COALESCE(SUM(o.total),0) total,
                                  COALESCE(SUM(o.member_discount),0) member_disc,
                                  COALESCE(SUM(CASE WHEN o.member_card = 1 THEN 1 ELSE 0 END),0) member_trx
                           FROM orders o WHERE {$f['sql']}",
            $f['params'], ['trx', 'subtotal', 'disc', 'total', 'member_disc', 'member_trx']);
        $b = db_cross_sum("SELECT
                 COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.subtotal ELSE 0 END),0) tr,
                 COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.subtotal ELSE 0 END),0) sk,
                 COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.subtotal ELSE 0 END),0) pkg,
                 COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity ELSE 0 END),0) tr_q,
                 COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.quantity ELSE 0 END),0) sk_q,
                 COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.quantity ELSE 0 END),0) pkg_q
               FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE {$f['sql']}",
            $f['params'], ['tr', 'sk', 'pkg', 'tr_q', 'sk_q', 'pkg_q']);
        $c = db_cross_sum("SELECT COALESCE(SUM(pm.amount),0) total, COUNT(*) n FROM payments pm
                           JOIN orders o ON o.id=pm.order_id WHERE pm.status='valid' AND {$f['sql']}",
            $f['params'], ['total', 'n']);
        foreach (['trx', 'subtotal', 'disc', 'total', 'member_disc', 'member_trx'] as $k) $tambahan[$k] = $a[$k];
        foreach (['tr', 'sk', 'pkg', 'tr_q', 'sk_q', 'pkg_q'] as $k) $tambahan[$k] = $b[$k];
        $tambahan['pay_total'] = $c['total'];
        $tambahan['pay_n'] = (int)$c['n'];
        $tot['trx'] = (int)$tot['trx'] + (int)$tambahan['trx'];
        $tot['subtotal'] = (float)$tot['subtotal'] + $tambahan['subtotal'];
        $tot['disc'] = (float)$tot['disc'] + $tambahan['disc'];
        $tot['total'] = (float)$tot['total'] + $tambahan['total'];
        $tot['member_disc'] = (float)$tot['member_disc'] + $tambahan['member_disc'];
        $tot['member_trx'] = (int)$tot['member_trx'] + (int)$tambahan['member_trx'];
        $items['tr'] = (float)$items['tr'] + $b['tr'];
        $items['sk'] = (float)$items['sk'] + $b['sk'];
        $items['pkg'] = (float)$items['pkg'] + $b['pkg'];
        $items['tr_q'] = (float)$items['tr_q'] + $b['tr_q'];
        $items['sk_q'] = (float)$items['sk_q'] + $b['sk_q'];
        $items['pkg_q'] = (float)$items['pkg_q'] + $b['pkg_q'];
        $pay['total'] = (float)$pay['total'] + $c['total'];
        $pay['n'] = (int)$pay['n'] + (int)$c['n'];
    }

    return [
        'trx' => (int)$tot['trx'], 'subtotal' => (float)$tot['subtotal'], 'disc' => (float)$tot['disc'],
        'total' => (float)$tot['total'], 'tr' => (float)$items['tr'], 'sk' => (float)$items['sk'],
        'pkg' => (float)$items['pkg'],
        'member_disc' => (float)$tot['member_disc'], 'member_trx' => (int)$tot['member_trx'],
        'tr_q' => (float)$items['tr_q'], 'sk_q' => (float)$items['sk_q'], 'pkg_q' => (float)$items['pkg_q'],
        'pay_total' => (float)$pay['total'], 'pay_n' => (int)$pay['n'],
        'avg' => ((int)$tot['trx'] > 0) ? (float)$tot['total'] / (int)$tot['trx'] : 0.0,
    ];
}

/** Rekap pemakaian kartu member per tier (untuk laporan & dokumen cetak). */
function report_member_usage(array $f): array
{
    /* Dikelompokkan per LEVEL + CAKUPAN diskon yang dipilih kasir pada tiap
       transaksi (both/treatment/skincare) supaya terlihat mana yang dipakai. */
    return db_cross_group("SELECT COALESCE(NULLIF(o.member_tier,''), 'Tanpa tier') tier,
                       COALESCE(NULLIF(o.member_scope,''), 'both') scope,
                       COUNT(*) trx, COALESCE(SUM(o.member_discount),0) disc,
                       COALESCE(SUM(o.subtotal),0) subtotal
                FROM orders o WHERE {$f['sql']} AND o.member_card = 1
                GROUP BY tier, scope", $f['params'], ['tier', 'scope'], 'disc');
}

/**
 * Potongan SQL: nilai item per ORDER (satu baris per `order_id`).
 *
 * MENGGANTIKAN subquery berkorelasi `SUM((SELECT SUM(oi.subtotal) … WHERE oi.order_id=o.id))`
 * yang dijalankan ULANG untuk setiap baris `orders`. Terukur pada data nyata: dua rekap
 * (per cabang & per kasir) masing-masing 109 ms + 91 ms — setelah diganti satu agregasi
 * `order_items` + JOIN, keduanya di bawah 5 ms. Angka yang dihasilkan IDENTIK.
 *
 * `$pkg` hanya dipakai sebagian pemanggil (kolomnya tetap dihitung karena murah —
 * satu agregasi yang sama).
 */
function report_items_join_sql(string $alias = 'oit'): string
{
    return "LEFT JOIN (SELECT oi2.order_id,
                    SUM(CASE WHEN oi2.item_type='treatment' THEN oi2.subtotal ELSE 0 END) tr,
                    SUM(CASE WHEN oi2.item_type='skincare'  THEN oi2.subtotal ELSE 0 END) sk,
                    SUM(CASE WHEN oi2.item_type='package'   THEN oi2.subtotal ELSE 0 END) pkg
              FROM order_items oi2 GROUP BY oi2.order_id) {$alias} ON {$alias}.order_id = o.id";
}

/** Rekap per cabang (termasuk rincian treatment & skincare). */
function report_per_branch(array $f): array
{
    /* `db_cross_group()` memastikan SELURUH cabang ikut terhitung — cabang di luar
       batas ATTACH SQLite dilayani fan-out (satu berkas pada satu waktu) lalu
       barisnya digabung per cabang. Tanpa ini, tabel "per cabang" kehilangan
       cabang ke-10 dan seterusnya tanpa pesan kesalahan. */
    return db_cross_group("SELECT b.id branch_id, b.name, b.code,
                        COUNT(DISTINCT o.id) trx, COALESCE(SUM(o.total),0) total,
                        COALESCE(SUM(o.discount),0) disc,
                        COALESCE(SUM(oit.tr),0) tr,
                        COALESCE(SUM(oit.sk),0) sk
                 FROM orders o " . report_items_join_sql() . "
                 JOIN branches b ON b.id=o.branch_id
                 WHERE {$f['sql']} GROUP BY b.id", $f['params'], ['branch_id'], 'total');
}

/**
 * Progres berkala yang MENGIKUTI filter yang diterapkan (tanggal, cabang, kasir,
 * metode bayar, item) — sebelumnya grafik ini selalu 12 bulan terakhir sehingga
 * tampak "tidak terintegrasi" dengan setting penerapan.
 *
 * Cara kerja:
 *  - rentang <= 62 hari  -> rincian HARIAN sesuai tanggal yang dipilih
 *  - rentang lebih panjang -> ringkasan BULANAN yang mencakup rentang tersebut
 *  - perbandingan antar cabang memakai cakupan & filter yang sama
 */
function report_monthly(array $f, ?int $maxMonths = 24): array
{
    $ps = $f['ps'];
    $pe = $f['pe'];
    $days = (int)floor((strtotime($pe) - strtotime($ps)) / 86400) + 1;
    $daily = $days <= 62;

    if ($daily) {
        $d = report_daily($f);
        $labels = []; $labelsFull = []; $total = []; $tr = []; $sk = []; $pkg = []; $trx = [];
        $cursor = strtotime($ps);
        $guard = 0;
        while ($cursor <= strtotime($pe) && $guard++ < 400) {
            $k = date('Y-m-d', $cursor);
            $i = array_search($k, $d['labels'] === [] ? [] : [], true);   // tidak dipakai
            $labels[] = date('d/m', $cursor);
            $labelsFull[] = tglIndo($k);
            // ambil dari seri harian (sudah mengikuti filter yang sama)
            $idx = array_search(date('d/m', $cursor), $d['labels'], true);
            $total[] = $idx !== false ? $d['total'][$idx] : 0.0;
            $tr[]    = $idx !== false ? $d['tr'][$idx] : 0.0;
            $sk[]    = $idx !== false ? $d['sk'][$idx] : 0.0;
            $pkg[]   = $idx !== false ? ($d['pkg'][$idx] ?? 0.0) : 0.0;
            $trx[]   = $idx !== false ? $d['trx'][$idx] : 0;
            $cursor = strtotime('+1 day', $cursor);
        }
        $branchesSeries = [];
        if ($f['scope'] === null) {
            /* SATU query untuk semua cabang (bukan satu query per cabang): hasilnya
               dikelompokkan per (cabang, tanggal) lalu disusun menjadi seri.
               Dengan 20–30 cabang cara lama berarti 20–30 query — dan untuk cabang di
               luar batas ATTACH hasilnya NOL karena view tidak memuatnya. */
            $perBranchDay = db_cross_group("SELECT o.branch_id bid, date(o.created_at) d,
                                              COALESCE(SUM(o.total),0) total
                                       FROM orders o WHERE {$f['sql']} GROUP BY bid, d",
                $f['params'], ['bid', 'd']);
            $peta = [];
            foreach ($perBranchDay as $r) $peta[(int)$r['bid']][$r['d']] = (float)$r['total'];
            /* Urutan label HARUS sama dengan `report_daily()` (menyusuri hari dari ps s/d pe). */
            $tanggal = [];
            $cur = strtotime($ps);
            $g = 0;
            while ($cur <= strtotime($pe) && $g++ < 400) { $tanggal[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }
            foreach (branches() as $b) {
                $seri = [];
                foreach ($tanggal as $t) $seri[] = $peta[(int)$b['id']][$t] ?? 0.0;
                $branchesSeries[$b['name']] = $seri;
            }
        }
        $granularity = 'harian';
    } else {
        $months = [];
        $cursor = strtotime(date('Y-m-01', strtotime($ps)));
        $endTs = strtotime($pe);
        $guard = 0;
        while ($cursor <= $endTs && $guard++ < $maxMonths) {
            $months[] = date('Y-m', $cursor);
            $cursor = strtotime('+1 month', $cursor);
        }
        if (!$months) $months = [date('Y-m', $endTs)];

        $rows = db_cross_group("SELECT strftime('%Y-%m', o.created_at) m, COALESCE(SUM(o.total),0) total, COUNT(DISTINCT o.id) trx
                     FROM orders o WHERE {$f['sql']} GROUP BY m", $f['params'], ['m']);
        $byMonth = [];
        foreach ($rows as $r) $byMonth[$r['m']] = $r;

        $itemRows = db_cross_group("SELECT strftime('%Y-%m', o.created_at) m, oi.item_type,
                                COALESCE(SUM(oi.subtotal),0) s
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id
                         WHERE {$f['sql']} GROUP BY m, oi.item_type", $f['params'], ['m', 'item_type']);
        $items = [];
        foreach ($itemRows as $r) $items[$r['m']][$r['item_type']] = (float)$r['s'];

        $labels = []; $labelsFull = []; $total = []; $tr = []; $sk = []; $pkg = []; $trx = [];
        foreach ($months as $m) {
            $labels[] = date('M', strtotime($m . '-01')) . " '" . substr($m, 2, 2);
            $labelsFull[] = tglIndo($m . '-01');
            $total[] = isset($byMonth[$m]) ? (float)$byMonth[$m]['total'] : 0.0;
            $trx[]   = isset($byMonth[$m]) ? (int)$byMonth[$m]['trx'] : 0;
            $tr[]    = $items[$m]['treatment'] ?? 0.0;
            $sk[]    = $items[$m]['skincare'] ?? 0.0;
            $pkg[]   = $items[$m]['package'] ?? 0.0;
        }
        $branchesSeries = [];
        if ($f['scope'] === null) {
            /* Satu query untuk SEMUA cabang (bukan satu per cabang) — cabang di luar
               batas ATTACH tidak akan terlihat oleh view, jadi fan-out ikut dipakai. */
            $perBranchMonth = db_cross_group("SELECT o.branch_id bid, strftime('%Y-%m', o.created_at) m,
                                                COALESCE(SUM(o.total),0) total
                                         FROM orders o WHERE {$f['sql']} GROUP BY bid, m",
                $f['params'], ['bid', 'm']);
            $peta = [];
            foreach ($perBranchMonth as $r) $peta[(int)$r['bid']][$r['m']] = (float)$r['total'];
            foreach (branches() as $b) {
                $series = [];
                foreach ($months as $m) $series[] = $peta[(int)$b['id']][$m] ?? 0.0;
                $branchesSeries[$b['name']] = $series;
            }
        }
        $granularity = 'bulanan';
    }

    $n = count($total);
    $last = $n > 0 ? $total[$n - 1] : 0.0;
    $prev = $n > 1 ? $total[$n - 2] : 0.0;
    $growth = $prev > 0 ? (($last - $prev) / $prev) * 100 : null;

    return [
        'labels' => $labels, 'labels_full' => $labelsFull, 'total' => $total,
        'tr' => $tr, 'sk' => $sk, 'pkg' => $pkg, 'trx' => $trx, 'branches' => $branchesSeries,
        'start' => $ps, 'end' => $pe, 'last' => $last, 'prev' => $prev, 'growth' => $growth,
        'sum' => array_sum($total), 'granularity' => $granularity, 'buckets' => $n,
        'label_suffix' => $granularity === 'harian' ? 'Hari' : 'Bulan',
    ];
}

/**
 * RINCIAN "TOTAL PENDAPATAN" — satu sumber untuk semua keterangan grafik.
 *
 * Permintaan pemilik: grafik Total Pendapatan harus diberi keterangan asal
 * angkanya. Yang benar (diverifikasi dari kode, JANGAN dikira-kira):
 *
 *   total = pendapatan treatment + skincare + PAKET − diskon manual − diskon member
 *           + KODE UNIK pembayaran
 *
 *   • Kode unik (3 digit) memang DISIMPAN pada `orders.total` oleh order_create()
 *     (dipakai mencocokkan mutasi transfer/QRIS), jadi ia ikut di grafik ini.
 *     Berbeda dengan menu Keuangan yang sengaja TIDAK menghitung kode unik
 *     sebagai omzet (lihat finance_summary()) — perbedaan ini dinyatakan pada
 *     keterangannya supaya tidak menyesatkan.
 *   • Baris `package_item` (isi paket) berharga 0 sehingga tidak dihitung dua kali.
 *   • Bahan treatment tidak dijual (harga 0) sehingga tidak pernah masuk total.
 *
 * @return array{tr:float,sk:float,pkg:float,subtotal:float,disc:float,member_disc:float,
 *               unique:float,before_unique:float,total:float,trx:int,selisih:float,
 *               selisih_item:float}
 */
function report_income_breakdown(array $f): array
{
    $items = one("SELECT
            COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.subtotal ELSE 0 END),0) tr,
            COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.subtotal ELSE 0 END),0) sk,
            COALESCE(SUM(CASE WHEN oi.item_type='package'   THEN oi.subtotal ELSE 0 END),0) pkg
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE {$f['sql']}", $f['params']);
    $ord = one("SELECT COUNT(*) trx,
                       COALESCE(SUM(o.subtotal),0) subtotal,
                       COALESCE(SUM(o.total),0) total,
                       COALESCE(SUM(o.discount),0) disc,
                       COALESCE(SUM(o.member_discount),0) member_disc,
                       COALESCE(SUM(o.unique_code),0) uniq
                FROM orders o WHERE {$f['sql']}", $f['params']);
    $tr = (float)$items['tr'];
    $sk = (float)$items['sk'];
    $pkg = (float)$items['pkg'];
    $subtotal = (float)$ord['subtotal'];
    $disc = (float)$ord['disc'];
    $memberDisc = (float)$ord['member_disc'];
    $uniq = (float)$ord['uniq'];
    $total = (float)$ord['total'];
    /* `before_unique` dihitung dari SUBTOTAL transaksi (bukan jumlah baris item),
       karena `orders.total` memang ditulis dari subtotal itu oleh order_create().
       Selisih antara subtotal dan jumlah baris item dilaporkan terpisah
       (`selisih_item`) — biasanya 0, dan hanya tidak nol pada data impor/lama. */
    $sebelumUniq = round($subtotal - $disc - $memberDisc, 2);
    return ['tr' => $tr, 'sk' => $sk, 'pkg' => $pkg, 'subtotal' => $subtotal,
        'disc' => $disc, 'member_disc' => $memberDisc,
        'unique' => $uniq, 'before_unique' => $sebelumUniq, 'total' => $total,
        'trx' => (int)$ord['trx'],
        'selisih' => round($total - $sebelumUniq, 2),
        'selisih_item' => round($subtotal - ($tr + $sk + $pkg), 2)];
}

/**
 * Kalimat keterangan rumus Total Pendapatan (dipakai halaman Laporan, dokumen cetak,
 * dan PDF supaya bunyinya SAMA di semua tempat).
 */
function income_formula_text(): string
{
    return 'Total Pendapatan = pendapatan treatment + skincare + paket − diskon manual '
        . '− diskon member + kode unik pembayaran';
}

/**
 * Catatan tambahan yang JUJUR bila subtotal transaksi tidak sama dengan jumlah
 * baris itemnya (hanya mungkin pada data impor/lama). Kosong bila selisihnya nol.
 */
function income_breakdown_note(array $inc): string
{
    if (abs((float)($inc['selisih_item'] ?? 0)) <= 0.5) return '';
    return 'Catatan: pada periode ini subtotal transaksi berbeda '
        . money((float)$inc['selisih_item']) . ' dari jumlah baris itemnya (terjadi pada data '
        . 'impor/data lama). Grafik & total memakai SUBTOTAL transaksi, jadi angkanya tetap sah.';
}

/** Komposisi metode pembayaran. */
function report_payment_methods(array $f): array
{    return db_cross_group("SELECT pm.method, COUNT(*) n, COALESCE(SUM(pm.amount),0) total
                FROM payments pm JOIN orders o ON o.id=pm.order_id
                WHERE pm.status='valid' AND {$f['sql']}
                GROUP BY pm.method", $f['params'], ['method'], 'total');
}

/** Kinerja per kasir (untuk perbandingan internal). */
function report_cashier_perf(array $f): array
{
    return db_cross_group("SELECT COALESCE(u.name, o.cashier_name, '-') nama, COUNT(DISTINCT o.id) trx,
                       COALESCE(SUM(o.total),0) total,
                       COALESCE(SUM(oit.tr),0) tr,
                       COALESCE(SUM(oit.sk),0) sk
                FROM orders o " . report_items_join_sql() . "
                LEFT JOIN users u ON u.id = o.user_id
                WHERE {$f['sql']} GROUP BY COALESCE(u.name, o.cashier_name)", $f['params'], ['nama'], 'total');
}

/** Peringkat item (treatment / skincare) lengkap dengan kategori. */
function report_top_items(string $type, array $f, int $limit = 0): array
{
    $isSk = $type === 'skincare';
    $cat  = $isSk ? 's.category' : 't.category';
    $join = $isSk ? 'LEFT JOIN skincare_products s ON s.id = oi.skincare_id' : 'LEFT JOIN treatments t ON t.id = oi.treatment_id';
    return db_cross_group("SELECT oi.item_name nama, oi.item_code kode, COALESCE({$cat},'-') kategori,
                       COALESCE(SUM(oi.quantity),0) q, COALESCE(SUM(oi.subtotal),0) s,
                       COUNT(DISTINCT o.id) trx
                FROM order_items oi JOIN orders o ON o.id=oi.order_id {$join}
                WHERE {$f['sql']} AND oi.item_type = ?
                GROUP BY oi.item_name",
        array_merge($f['params'], [$type]), ['nama'], 's', $limit);
}

/**
 * Pemakaian BAHAN TREATMENT pada periode terpilih (dari order_items bertipe
 * 'material'). Bahan tidak dijual sehingga TIDAK masuk pendapatan — rekap ini
 * hanya untuk melihat pemakaian obat/ bahan pelengkap proses treatment.
 */
function report_material_usage(array $f, int $limit = 0): array
{
    return db_cross_group("SELECT oi.item_name nama, oi.item_code kode, oi.material_id,
                       COALESCE(SUM(oi.quantity),0) q, COUNT(DISTINCT o.id) trx
                FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE {$f['sql']} AND oi.item_type = 'material'
                GROUP BY oi.item_name", $f['params'], ['nama'], 'q', $limit);
}

/** Pergerakan harian dalam rentang filter (untuk grafik garis). */
function report_daily(array $f): array
{
    $rows = db_cross_group("SELECT date(o.created_at) d, COALESCE(SUM(o.total),0) total, COUNT(DISTINCT o.id) trx,
                        COALESCE(SUM(oit.tr),0) tr,
                        COALESCE(SUM(oit.sk),0) sk,
                        COALESCE(SUM(oit.pkg),0) pkg
                 FROM orders o " . report_items_join_sql() . "
                 WHERE {$f['sql']} GROUP BY d", $f['params'], ['d']);
    $map = [];
    foreach ($rows as $r) $map[$r['d']] = $r;

    $days = (int)floor((strtotime($f['pe']) - strtotime($f['ps'])) / 86400) + 1;
    $byMonth = $days > 92;   // periode panjang -> ringkas per bulan
    $labels = []; $total = []; $tr = []; $sk = []; $pkg = []; $trx = [];
    if ($byMonth) {
        $agg = [];
        foreach ($rows as $r) {
            $k = substr($r['d'], 0, 7);
            if (!isset($agg[$k])) $agg[$k] = ['total' => 0, 'tr' => 0, 'sk' => 0, 'pkg' => 0, 'trx' => 0];
            $agg[$k]['total'] += (float)$r['total'];
            $agg[$k]['tr'] += (float)$r['tr'];
            $agg[$k]['sk'] += (float)$r['sk'];
            $agg[$k]['pkg'] += (float)$r['pkg'];
            $agg[$k]['trx'] += (int)$r['trx'];
        }
        $cursor = strtotime(date('Y-m-01', strtotime($f['ps'])));
        $guard = 0;
        while ($cursor <= strtotime($f['pe']) && $guard++ < 60) {
            $k = date('Y-m', $cursor);
            $labels[] = date('M', $cursor) . " '" . date('y', $cursor);
            $total[] = $agg[$k]['total'] ?? 0.0;
            $tr[]    = $agg[$k]['tr'] ?? 0.0;
            $sk[]    = $agg[$k]['sk'] ?? 0.0;
            $pkg[]   = $agg[$k]['pkg'] ?? 0.0;
            $trx[]   = $agg[$k]['trx'] ?? 0;
            $cursor = strtotime('+1 month', $cursor);
        }
    } else {
        $cursor = strtotime($f['ps']);
        $guard = 0;
        while ($cursor <= strtotime($f['pe']) && $guard++ < 400) {
            $k = date('Y-m-d', $cursor);
            $labels[] = date('d/m', $cursor);
            $total[] = isset($map[$k]) ? (float)$map[$k]['total'] : 0.0;
            $tr[]    = isset($map[$k]) ? (float)$map[$k]['tr'] : 0.0;
            $sk[]    = isset($map[$k]) ? (float)$map[$k]['sk'] : 0.0;
            $pkg[]   = isset($map[$k]) ? (float)$map[$k]['pkg'] : 0.0;
            $trx[]   = isset($map[$k]) ? (int)$map[$k]['trx'] : 0;
            $cursor = strtotime('+1 day', $cursor);
        }
    }
    return ['labels' => $labels, 'total' => $total, 'tr' => $tr, 'sk' => $sk, 'pkg' => $pkg,
        'trx' => $trx, 'by_month' => $byMonth];
}

/**
 * TOP PASIEN — SATU SUMBER ANGKA untuk SEMUA tempat.
 *
 * Dipakai: halaman "Top 10 Pasien", lampiran isi email laporan bulanan, dokumen
 * cetak/PDF laporan lengkap, dan ekspor Excel/CSV. Sebelum dipusatkan di sini,
 * keempat tempat itu memakai query masing-masing sehingga **kolomnya berbeda-beda**
 * (ada yang menaruh JUMLAH TRANSAKSI pada kolom berlabel "Kunjungan", ada yang
 * mencetak jumlah transaksi dua kali, dan ada yang kehilangan kolom kunjungan sama
 * sekali) — akibatnya urutan 1–10 tampak tidak sesuai dengan angka yang tertera.
 *
 * URUTAN BAWAAN (KEPUTUSAN PEMILIK: peringkat menurut NILAI RUPIAH TERTINGGI):
 *   1) **TOTAL TRANSAKSI (Rp) TERBESAR**,        ← penentu UTAMA
 *   2) jumlah transaksi terbanyak,                ← penentu kedua
 *   3) nama pasien A→Z  ← penentu terakhir agar hasilnya SELALU sama (tanpa ini
 *      pasien dengan nilai sama bisa bergeser-geser sehingga daftar tampak
 *      "salah urut").
 *
 * Karena itu kolom **"Total Transaksi"** diletakkan SEBELUM "Jumlah Transaksi"
 * pada tabel (`top_patients_columns()`) supaya angka yang menentukan peringkat
 * langsung terlihat di sebelah kiri — mudah diperiksa pemilik: nilainya selalu
 * menurun 1 → 10.
 * `$urut = 'transaksi'` tetap tersedia bila suatu saat ingin diurutkan menurut
 * jumlah transaksi — satu tempat, semua pemakai ikut berubah.
 *
 * Cabang yang dilaporkan = cabang PASIEN (`p.branch_id`), sama dengan daftar
 * pasien di aplikasi (sebelumnya ada tempat yang memakai cabang transaksi
 * sehingga satu pasien bisa muncul dengan cabang berbeda).
 *
 * @param string $urut 'total' (bawaan: NILAI Rp TERBESAR) atau 'transaksi' (jumlah terbanyak)
 * @return array<int,array{id:int,name:string,patient_number:string,member_number:string,
 *                         phone:string,patient_type:string,branch_name:string,
 *                         trx:int,visits:int,total:float,last_visit:string}>
 */
function report_top_patients(array $f, int $limit = 10, string $urut = 'total'): array
{
    $lim = $limit > 0 ? ' LIMIT ' . (int)$limit : '';
    /* BAWAAN = NILAI TRANSAKSI TERBESAR (keputusan pemilik). Jumlah transaksi dipakai
       sebagai penentu kedua, lalu nama pasien agar hasilnya deterministik. */
    $order = $urut === 'transaksi' ? 'trx DESC, total DESC' : 'total DESC, trx DESC';
    /* KUNJUNGAN memakai definisi bersama patient_visits_sql() — jumlah HARI berbeda
       pasien datang (transaksi non-void ATAU rekam medis) — dan DIBATASI pada periode
       yang sedang dilihat supaya sebanding dengan kolom lain (Total & Jumlah
       Transaksi). Kartu "Total Kunjungan" di Detail Pasien tetap memakai seluruh
       riwayat (namanya memang TOTAL). */
    $visitsSql = patient_visits_sql('p', (string)($f['ps'] ?? ''), (string)($f['pe'] ?? ''));
    /* `db_cross_group()`: peringkat Top 10 dihitung dari SELURUH cabang (termasuk cabang
       di luar batas ATTACH SQLite). Urutan & pembatasan diulang setelah digabung supaya
       urutannya tetap benar — dulu peringkatnya hanya berasal dari 9 cabang pertama. */
    $rows = db_cross_group("SELECT p.id, p.name, p.patient_number, p.member_number, p.phone, p.patient_type,
                       b.name AS branch_name,
                       COUNT(DISTINCT o.id) trx,
                       {$visitsSql} visits,
                       COALESCE(SUM(o.total),0) total,
                       MAX(o.created_at) last_visit
                FROM orders o JOIN patients p ON p.id = o.patient_id
                JOIN branches b ON b.id = p.branch_id
                WHERE {$f['sql']}
                GROUP BY p.id", $f['params'], ['id']);
    usort($rows, function ($a, $b) use ($urut) {
        if ($urut === 'transaksi') {
            if ((int)$b['trx'] !== (int)$a['trx']) return (int)$b['trx'] <=> (int)$a['trx'];
            if ((float)$b['total'] !== (float)$a['total']) return (float)$b['total'] <=> (float)$a['total'];
        } else {
            if ((float)$b['total'] !== (float)$a['total']) return (float)$b['total'] <=> (float)$a['total'];
            if ((int)$b['trx'] !== (int)$a['trx']) return (int)$b['trx'] <=> (int)$a['trx'];
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}

/**
 * KEPALA KOLOM Top Pasien — SATU definisi agar halaman, dokumen cetak, dan
 * ekspor Excel tidak pernah menyebut kolom yang berbeda untuk data yang sama.
 */
function top_patients_columns(): array
{
    /* "Total Transaksi" (penentu peringkat) sengaja diletakkan SEBELUM "Jumlah
       Transaksi" supaya angka yang menentukan urutan terlihat lebih dulu. */
    return ['#', 'Nama Pasien', 'No. Member', 'Kunjungan', 'Total Transaksi', 'Jumlah Transaksi', 'Cabang'];
}

/**
 * Filter laporan dengan rentang tanggal & cakupan yang ditentukan pemanggil
 * (dipakai laporan bulanan via email yang tidak membaca $_GET).
 */
function report_filters_manual(string $ps, string $pe, ?int $scope = null, string $status = 'paid'): array
{
    if (!in_array($status, ['paid', 'void', 'refund'], true)) $status = 'paid';
    $w = ['o.status = ?', 'date(o.created_at) BETWEEN ? AND ?'];
    $p = [$status, $ps, $pe];
    if ($scope !== null) { $w[] = 'o.branch_id = ?'; $p[] = $scope; }
    return [
        'scope' => $scope, 'period' => 'custom', 'ps' => $ps, 'pe' => $pe,
        'status' => $status, 'sql' => implode(' AND ', $w), 'params' => $p,
    ];
}

/** Susun seluruh data laporan dari struktur filter yang diberikan. */
function report_bundle_for(array $f, bool $withItems = true): array
{
    $b = [
        'filters' => $f,
        'totals' => report_totals($f),
        'branches' => report_per_branch($f),
        'daily' => report_daily($f),
        'monthly' => report_monthly($f),
        'methods' => report_payment_methods($f),
        'cashiers' => report_cashier_perf($f),
        /* Rincian asal "Total Pendapatan" (dipakai keterangan grafik di halaman
           Laporan, dokumen cetak, dan PDF — supaya bunyinya & angkanya sama). */
        'income' => report_income_breakdown($f),
    ];
    if ($withItems) {
        $b['treatments'] = report_top_items('treatment', $f);
        $b['skincares'] = report_top_items('skincare', $f);
        /* Pemakaian bahan treatment — dipakai halaman Laporan & ekspor;
           tidak pernah ikut total pendapatan (harga bahan 0). */
        $b['materials'] = report_material_usage($f);
        /* Pemakaian kartu member (diskon otomatis) — ikut dihitung di laporan. */
        $b['member_usage'] = report_member_usage($f);
    }
    return $b;
}

/** Semua data laporan dalam satu struktur (dipakai halaman + ekspor). */
function report_bundle(bool $withItems = true): array
{
    return report_bundle_for(report_filters(), $withItems);
}
