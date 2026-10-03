<?php
/**
 * ATURAN KARTU MEMBER & DISKON BERLEVEL
 * =====================================
 *
 * Aturan bisnis klinik (semuanya dapat diubah di Pengaturan Sistem → Kartu Member):
 *
 *  1. AKTIVASI
 *     - Satu transaksi ≥ `member_activate_amount` (bawaan Rp 1.000.000) → kartu member
 *       langsung aktif pada level awal (Silver) DAN potongan member langsung berlaku
 *       pada transaksi tersebut.
 *     - Belum mencapai nilai itu → belum dapat kartu; kartu dapat diperoleh dari
 *       AKUMULASI transaksi satu tahun (lihat poin 2).
 *
 *  2. LEVEL DARI AKUMULASI PERIODE (default: 1 TAHUN, reset 1 Januari)
 *       akumulasi ≥ Rp  5.000.000 → Silver   (sekaligus membuka kartu bagi yang belum punya)
 *       akumulasi ≥ Rp 10.000.000 → Gold
 *       akumulasi ≥ Rp 20.000.000 → Platinum
 *       akumulasi ≥ Rp 40.000.000 → Diamond
 *       akumulasi ≥ Rp 60.000.000 → VVIP
 *     Ambang setiap level dapat diubah di Pengaturan. PERIODE akumulasinya juga dapat
 *     dipilih di Pengaturan → Kartu Member: **1 tahun / 3 tahun / 5 tahun / tanpa periode**.
 *     Reset berlaku BERSAMA untuk semua member dan selalu jatuh pada tanggal
 *     1 Januari tahun batas periode (periode "1 tahun" = awal tahun, seperti aturan lama).
 *
 *     Saat periode berakhir (`member_rollover_run()`):
 *       - level SETIAP member turun `member_downgrade_steps()` tingkat (0–3, dapat diatur;
 *         mis. Diamond dengan setelan 2 tingkat → Gold), turun paling jauh sampai level
 *         terendah; dan
 *       - akumulasi mulai dari NOL lagi (jendela perhitungan berpindah ke periode baru).
 *     Nomor member & kartu TIDAK ikut ter-reset. Level member yang turun karena periode
 *     baru akan naik kembali otomatis bila akumulasi periode itu melewati ambangnya.
 *
 *     Pelaksananya TIDAK bergantung pada halaman yang dibuka: `member_rollover_run()`
 *     dipanggil dari `member_sync_level()` (setiap transaksi tersimpan & saat pengaturan
 *     member disimpan) dan dari login (lihat `member_rollover_auto_run()`), dijaga
 *     penanda `member_period_applied` supaya penurunan hanya terjadi SEKALI per periode.
 *
 *  3. DISKON
 *     - Berlaku bila nilai transaksi ≥ `member_min_transaction` (bawaan Rp 100.000).
 *     - Besar diskon mengikuti LEVEL (Silver 5%, Gold 7,5%, Platinum 10%, Diamond 15%,
 *       VVIP 20% — dapat diubah).
 *     - Cakupan barang dapat dipilih: Treatment saja, Skincare saja, atau keduanya
 *       (`member_discount_scope`). Dasar perhitungan diskon adalah nilai barang yang
 *       termasuk cakupan, bukan seluruh transaksi.
 *     - Selalu dihitung ULANG di server dari data transaksi; angka dari halaman tidak
 *       dipercaya.
 *
 *  4. SAAT FITUR DIMATIKAN (`member_card_active` = 0)
 *     Tidak ada diskon, pilihan kartu tidak muncul di Order Baru, dan halaman pasien
 *     menampilkan pemberitahuan jujur bahwa fitur member sedang tidak aktif.
 */
declare(strict_types=1);

/* ================================================================== *
 * PERIODE AKUMULASI MEMBER (1 / 3 / 5 tahun atau tanpa periode)
 * ================================================================== */

/** Pilihan periode (tahun) → label. 0 = tanpa peresetan (akumulasi seumur kartu). */
function member_period_options(): array
{
    return [
        1 => '1 tahun — reset setiap awal tahun (1 Januari)',
        3 => '3 tahun — reset setiap 3 tahun (1 Januari)',
        5 => '5 tahun — reset setiap 5 tahun (1 Januari)',
        0 => 'Tanpa periode — akumulasi tidak pernah direset',
    ];
}

/** Periode akumulasi yang berlaku (0 = tanpa peresetan). */
function member_period_years(): int
{
    $v = (int)setting('member_period_years', '1');
    return array_key_exists($v, member_period_options()) ? $v : 1;
}

/** Label periode untuk tampilan ("1 tahun (reset 1 Januari)" / "tanpa periode"). */
function member_period_label(?int $years = null): string
{
    $y = $years ?? member_period_years();
    if ($y <= 0) return 'tanpa periode (tidak pernah direset)';
    return $y === 1 ? '1 tahun' : ($y . ' tahun');
}

/** Berapa tingkat level turun saat periode berakhir (0 = tidak ikut turun). */
function member_downgrade_steps(): int
{
    $v = (int)setting('member_downgrade_steps', '0');
    return max(0, min(3, $v));
}

/** Label penurunan level untuk tampilan. */
function member_downgrade_text(?int $steps = null): string
{
    $s = $steps ?? member_downgrade_steps();
    if ($s <= 0) return 'level tidak ikut turun (hanya akumulasi yang mulai dari nol)';
    return 'level turun ' . $s . ' tingkat' . ($s > 1 ? ' (mis. Diamond → level ' . max(1, 3 - $s) . ' tingkat di bawahnya)' : '');
}

/**
 * TAHUN batas periode yang sedang berjalan (awal periode = 1 Januari tahun ini).
 * Dihitung dari tahun jangkar (`member_period_anchor_year`) sehingga resetnya
 * BERSAMA untuk semua member dan selalu jatuh pada 1 Januari.
 * Mengembalikan 0 bila periodenya "tanpa peresetan".
 */
function member_period_start_year(?int $nowYear = null): int
{
    $n = member_period_years();
    if ($n <= 0) return 0;
    $now = $nowYear ?: (int)date('Y');
    $anchor = (int)setting('member_period_anchor_year', '0');
    if ($anchor <= 0) $anchor = $now;                 // jangkar belum diset → mulai tahun ini
    if ($anchor > $now) return $anchor;
    $k = intdiv($now - $anchor, $n);
    return $anchor + $k * $n;
}

/** Tanggal awal periode akumulasi ('YYYY-01-01'); '' bila tanpa periode. */
function member_period_start_date(?int $nowYear = null): string
{
    $y = member_period_start_year($nowYear);
    return $y > 0 ? sprintf('%04d-01-01', $y) : '';
}

/** Akhir periode (31 Desember tahun batas) sebagai label; '' bila tanpa periode. */
function member_period_end_date(?int $nowYear = null): string
{
    $y = member_period_start_year($nowYear);
    return $y > 0 ? sprintf('%04d-12-31', $y + member_period_years() - 1) : '';
}

/** Tanggal reset berikutnya (1 Januari); '' bila tanpa periode. */
function member_period_next_reset(?int $nowYear = null): string
{
    $y = member_period_start_year($nowYear);
    return $y > 0 ? sprintf('%04d-01-01', $y + member_period_years()) : '';
}

/**
 * Akumulasi nilai transaksi BERBAYAR pada PERIODE yang sedang berjalan.
 * Periode 1 tahun = tahun berjalan (perilaku lama); 3/5 tahun = jendela periode;
 * tanpa periode = seluruh riwayat transaksi berbayar pasien itu.
 */
function member_period_total(int $patientId): float
{
    $start = member_period_start_date();
    if ($start === '') {
        return (float)scalar("SELECT COALESCE(SUM(o.total),0) FROM orders o
                              WHERE o.patient_id = ? AND o.status = 'paid'", [$patientId], 0);
    }
    return (float)scalar("SELECT COALESCE(SUM(o.total),0) FROM orders o
                          WHERE o.patient_id = ? AND o.status = 'paid'
                            AND date(o.created_at) >= ?", [$patientId, $start], 0);
}

/**
 * Akumulasi pada periode tertentu (dipakai laporan/uji).
 * $year = tahun awal periode; null = periode yang sedang berjalan.
 */
function member_year_total(int $patientId, ?int $year = null): float
{
    if ($year === null || member_period_years() <= 0) return member_period_total($patientId);
    $n = member_period_years();
    return (float)scalar("SELECT COALESCE(SUM(o.total),0) FROM orders o
                          WHERE o.patient_id = ? AND o.status = 'paid'
                            AND strftime('%Y', o.created_at) >= ?
                            AND strftime('%Y', o.created_at) <= ?",
        [$patientId, (string)$year, (string)($year + $n - 1)], 0);
}

/** Jumlah transaksi berbayar pada periode berjalan (untuk keterangan di dokumen). */
function member_period_trx(int $patientId): int
{
    $start = member_period_start_date();
    if ($start === '') {
        return (int)scalar("SELECT COUNT(*) FROM orders o WHERE o.patient_id = ? AND o.status = 'paid'",
            [$patientId], 0);
    }
    return (int)scalar("SELECT COUNT(*) FROM orders o WHERE o.patient_id = ? AND o.status = 'paid'
                        AND date(o.created_at) >= ?", [$patientId, $start], 0);
}

/** Ringkasan periode untuk ditampilkan (dipakai kartu member, pengaturan, laporan). */
function member_period_info(?int $nowYear = null): array
{
    $n = member_period_years();
    $y = member_period_start_year($nowYear);
    return [
        'years' => $n,
        'label' => member_period_label($n),
        'start' => $y > 0 ? sprintf('%04d-01-01', $y) : '',
        'end' => member_period_end_date($nowYear),
        'next_reset' => member_period_next_reset($nowYear),
        'applied' => (int)setting('member_period_applied', '0'),
        'steps' => member_downgrade_steps(),
        'downgrade' => member_downgrade_text(),
    ];
}

/** Apakah periode baru sudah lewat dan penurunan level belum dijalankan? */
function member_rollover_pending(): bool
{
    $y = member_period_start_year();
    if ($y <= 0) return false;
    $applied = (int)setting('member_period_applied', '0');
    return $applied > 0 && $y > $applied;
}

/**
 * Jalankan reset periode BERSAMA untuk semua member: level turun
 * `member_downgrade_steps()` tingkat dan penanda periode diperbarui.
 * Akumulasi mulai dari nol dengan sendirinya karena jendela perhitungan
 * (member_period_start_date()) berpindah ke tahun batas yang baru.
 *
 * Dijaga penanda `member_period_applied` → hanya berjalan SEKALI per periode,
 * sehingga aman dipanggil dari beberapa jalur sekaligus (login, simpan
 * pengaturan, transaksi). Saat pertama kali diaktifkan (penanda kosong),
 * penanda hanya diisi TANPA menurunkan level siapa pun.
 *
 * @return array{ran:bool,from:int,to:int,steps:int,anggota:int,berubah:int,ditolak:int}
 */
function member_rollover_run(?int $userId = null): array
{
    $out = ['ran' => false, 'from' => 0, 'to' => 0, 'steps' => member_downgrade_steps(),
            'anggota' => 0, 'berubah' => 0, 'ditolak' => 0];
    $y = member_period_start_year();
    if ($y <= 0) return $out;                      // tanpa periode → tidak ada reset
    $applied = (int)setting('member_period_applied', '0');
    if ($applied <= 0) {                           // aktivasi pertama: jangan turunkan siapa pun
        set_setting('member_period_applied', (string)$y);
        return $out;
    }
    if ($y <= $applied) return $out;               // sudah dijalankan untuk periode ini

    $levels = member_levels();
    $keys = array_map(fn($l) => (string)$l['key'], $levels);
    $steps = member_downgrade_steps();
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach (all('SELECT id, member_level FROM patients WHERE member_card = 1') as $row) {
            $out['anggota']++;
            $from = (string)($row['member_level'] ?? '');
            $idx = array_search($from, $keys, true);
            if ($idx === false) $idx = 0;
            $toIdx = max(0, (int)$idx - $steps);
            q('UPDATE patients SET member_level = ?, member_level_at = datetime("now","localtime"),
                      updated_at = datetime("now","localtime") WHERE id = ?',
                [$keys[$toIdx], (int)$row['id']]);
            if ($keys[$toIdx] !== $from) $out['berubah']++;
        }
        set_setting('member_period_applied', (string)$y);
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        return $out;                               // ditolak: penanda TIDAK diubah (dicoba lagi nanti)
    }
    settings(true);
    $out['ran'] = true;
    $out['from'] = $applied;
    $out['to'] = $y;
    $out['ditolak'] = 0;
    audit('Reset Periode Kartu Member', 'Pasien', null,
        ['periode_berakhir' => $applied, 'periode_baru' => $y, 'penurunan_tingkat' => $steps],
        ['anggota' => $out['anggota'], 'level_berubah' => $out['berubah'],
         'keterangan' => 'Periode akumulasi member ' . member_period_label() . ' berakhir — akumulasi mulai dari nol'
             . ($steps > 0 ? ' dan level turun ' . $steps . ' tingkat' : ' (level tidak diturunkan)')],
        'Reset periode member dijalankan otomatis pada awal periode baru');
    /* Pemberitahuan di dashboard (transparan, bukan senyap). */
    set_setting('member_rollover_result', json_encode([
        'waktu' => date('Y-m-d H:i:s'), 'periode_baru' => $y, 'periode_lama' => $applied,
        'anggota' => $out['anggota'], 'berubah' => $out['berubah'], 'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * Reset periode saat pengguna login (tanpa cron) — dipanggil bersama backup &
 * retensi otomatis. Hanya benar-benar turun ketika periode baru dimulai.
 */
function member_rollover_auto_run(?int $userId = null): array
{
    return member_rollover_run($userId);
}

/** Level bawaan bila setelan belum pernah diubah. */
function member_default_levels(): array
{
    return [
        ['key' => 'silver',   'label' => 'Member Silver',   'pct' => 5,  'min_year' => 5000000],
        ['key' => 'gold',     'label' => 'Member Gold',     'pct' => 7.5, 'min_year' => 10000000],
        ['key' => 'platinum', 'label' => 'Member Platinum', 'pct' => 10, 'min_year' => 20000000],
        ['key' => 'diamond',  'label' => 'Member Diamond',  'pct' => 15, 'min_year' => 40000000],
        ['key' => 'vvip',     'label' => 'Member VVIP',     'pct' => 20, 'min_year' => 60000000],
    ];
}

/** Daftar level member (urut dari ambang terkecil) — dari setelan, atau bawaan. */
function member_levels(): array
{
    $rows = json_decode((string)setting('member_levels'), true);
    if (!is_array($rows) || !$rows) {
        /* Kompatibilitas: database yang masih memakai `member_discount_tiers`
           (tier diskon tanpa level) diubah otomatis ke daftar level. */
        $old = json_decode((string)setting('member_discount_tiers'), true);
        $rows = [];
        if (is_array($old) && $old) {
            $names = ['Member Silver', 'Member Gold', 'Member Platinum', 'Member Diamond', 'Member VVIP'];
            $keys  = ['silver', 'gold', 'platinum', 'diamond', 'vvip'];
            $defaultMin = [5000000, 10000000, 20000000, 40000000, 60000000];
            foreach (array_values($old) as $i => $t) {
                $rows[] = [
                    'key' => $keys[$i] ?? ('level' . ($i + 1)),
                    'label' => trim((string)($t['label'] ?? '')) ?: ($names[$i] ?? ('Level ' . ($i + 1))),
                    'pct' => (float)($t['pct'] ?? 0),
                    'min_year' => (float)($defaultMin[$i] ?? ((float)($t['min'] ?? 0) * 4)),
                ];
            }
        }
        if (!$rows) $rows = member_default_levels();
    }
    $out = [];
    foreach ($rows as $r) {
        $pct = (float)($r['pct'] ?? 0);
        if ($pct <= 0) continue;
        $out[] = [
            'key' => preg_replace('/[^a-z0-9_]/', '', strtolower((string)($r['key'] ?? 'level'))) ?: 'level',
            'label' => trim((string)($r['label'] ?? '')) ?: 'Member',
            'pct' => min(100.0, $pct),
            'min_year' => max(0.0, (float)($r['min_year'] ?? 0)),
        ];
    }
    if (!$out) $out = member_default_levels();
    usort($out, fn($a, $b) => $a['min_year'] <=> $b['min_year']);
    return $out;
}

/** Level dasar (ambang terkecil) — dipakai sebagai level awal kartu baru. */
function member_base_level(): array
{
    $lv = member_levels();
    return $lv[0];
}

/** Cari level berdasarkan key. */
function member_level_by_key(string $key): ?array
{
    foreach (member_levels() as $l) if ($l['key'] === $key) return $l;
    return null;
}

/**
 * Level yang berlaku untuk sebuah nilai akumulasi tahunan.
 * Bila akumulasi belum mencapai level terendah, kembalikan level dasar (kartu tetap ada).
 */
function member_level_for_total(float $yearTotal): array
{
    $lv = member_levels();
    $found = null;
    foreach ($lv as $l) if ($yearTotal + 0.001 >= $l['min_year']) $found = $l;
    return $found ?: $lv[0];
}

/** Level berikutnya setelah level tertentu (null bila sudah tertinggi). */
function member_next_level(array $level): ?array
{
    $lv = member_levels();
    foreach ($lv as $i => $l) {
        if ($l['key'] === $level['key']) return $lv[$i + 1] ?? null;
    }
    return null;
}

/** Cakupan barang yang mendapat diskon member: both|treatment|skincare. */
function member_scope(): string
{
    $s = (string)setting('member_discount_scope', 'both');
    return in_array($s, ['both', 'treatment', 'skincare'], true) ? $s : 'both';
}
/** Pilihan cakupan diskon + labelnya (dipakai Pengaturan, Order Baru, laporan). */
function member_scope_options(): array
{
    /* Label menyebutkan PAKET secara eksplisit (permintaan pemilik): cakupan
       "Treatment saja" juga berlaku untuk paket treatment, dan "Skincare saja"
       juga untuk paket produk — lihat member_item_in_scope(). */
    return [
        'both'      => 'Treatment & Skincare (termasuk semua paket)',
        'treatment' => 'Treatment saja (termasuk paket treatment)',
        'skincare'  => 'Skincare saja (termasuk paket produk)',
    ];
}

/**
 * Apakah sebuah baris item MASUK cakupan diskon member?
 *
 * Aturan (permintaan pemilik, ronde 38):
 *   • 'treatment' → treatment + PAKET TREATMENT
 *   • 'skincare'  → skincare  + PAKET PRODUK
 *   • 'both'      → treatment, paket treatment, skincare, dan paket produk
 *
 * Sebelumnya hanya jenis item yang persis sama yang dihitung, sehingga PAKET
 * (bertipe 'package') tidak pernah mendapat diskon sama sekali.
 */
function member_item_in_scope(string $scope, array $item): bool
{
    $t = (string)($item['type'] ?? '');
    if ($t === 'treatment')  return $scope === 'both' || $scope === 'treatment';
    if ($t === 'skincare')   return $scope === 'both' || $scope === 'skincare';
    if ($t === 'package') {
        if ($scope === 'both') return true;
        /* Jenis paket: 'treatment' atau 'product' (produk = skincare). */
        $kind = strtolower((string)($item['pkg_kind'] ?? 'treatment'));
        $produk = in_array($kind, ['product', 'skincare', 'produk'], true);
        return $scope === 'treatment' ? !$produk : $produk;
    }
    return false;                                  // material/bahan: tidak pernah berdiskon
}
/** Normalkan nilai cakupan apa pun menjadi salah satu dari both/treatment/skincare. */
function member_scope_norm($value, string $fallback = 'both'): string
{
    $v = (string)$value;
    return array_key_exists($v, member_scope_options()) ? $v : $fallback;
}
function member_scope_text(?string $scope = null): string
{
    $opts = member_scope_options();
    $s = $scope === null || $scope === '' ? member_scope() : member_scope_norm($scope);
    return $opts[$s];
}

/** Apakah fitur kartu member dipakai klinik? */
function member_card_enabled(): bool
{
    return setting('member_card_active', '1') === '1';
}

/** Nilai transaksi minimum agar diskon member berlaku. */
function member_min_transaction(): float
{
    return max(0.0, (float)setting('member_min_transaction', '100000'));
}

/** Nilai satu transaksi yang membuat kartu member langsung aktif. */
function member_activate_amount(): float
{
    return max(0.0, (float)setting('member_activate_amount', '1000000'));
}

/**
 * Ringkasan status member satu pasien (level, akumulasi PERIODE berjalan, progres).
 * Kunci `year`/`year_total` dipertahankan (dipakai banyak halaman) tetapi kini
 * berarti TAHUN AWAL PERIODE dan akumulasi pada periode tersebut — periode 1 tahun
 * = tahun berjalan seperti perilaku lama.
 */
function member_status(?array $p, ?int $year = null): array
{
    $info = member_period_info();
    if ($p && $year !== null && member_period_years() > 0) {
        $tot = member_year_total((int)$p['id'], $year);
    } else {
        $tot = $p ? member_period_total((int)$p['id']) : 0.0;
        $year = $year ?? ($info['start'] !== '' ? (int)substr($info['start'], 0, 4) : (int)date('Y'));
    }
    $lvl = member_level_for_total($tot);
    $reached = $tot + 0.001 >= $lvl['min_year'];
    return [
        'member' => $p ? ((int)($p['member_card'] ?? 0) === 1) : false,
        'level' => $lvl,
        'level_reached' => $reached,      // sudah memenuhi ambang level pada periode ini
        'year' => (int)$year,
        'year_total' => $tot,
        'period' => $info,
        'trx' => $p ? member_period_trx((int)$p['id']) : 0,
        'next' => member_next_level($lvl),
        'min_transaction' => member_min_transaction(),
        'scope' => member_scope(),
    ];
}

/** Jumlah member aktif (dibatasi cakupan cabang) — dipakai dashboard. */
function member_count(): int
{
    [$bs, $bp] = branch_sql('p.branch_id');
    return (int)scalar("SELECT COUNT(*) FROM patients p WHERE p.member_card = 1 AND p.status = 'active' {$bs}", $bp);
}

/** Label sumber perolehan kartu. */
function member_source_text(?string $src): string
{
    return [
        'beli' => 'Beli member / transaksi awal',
        'transaksi' => 'Otomatis dari akumulasi transaksi',
        'manual' => 'Diaktifkan petugas',
    ][(string)$src] ?? '-';
}

/** Ringkasan aturan (dipakai Pengaturan, kartu, dan Order Baru). */
function member_rules_text(): string
{
    $out = [];
    $akt = member_activate_amount();
    if ($akt > 0) $out[] = 'transaksi ≥ ' . money($akt) . ' langsung aktif';
    foreach (member_levels() as $l) {
        $out[] = num($l['pct'], $l['pct'] == (int)$l['pct'] ? 0 : 1) . '% (' . $l['label'] . ') bila akumulasi '
            . member_period_label() . ' ≥ ' . money($l['min_year']);
    }
    if (member_period_years() > 0) {
        $out[] = 'periode ' . member_period_label() . ' direset setiap 1 Januari'
            . (member_downgrade_steps() > 0 ? ' + level turun ' . member_downgrade_steps() . ' tingkat' : '');
    } else {
        $out[] = 'akumulasi TIDAK pernah direset (tanpa periode)';
    }
    return implode(' · ', $out);
}

/**
 * Aktifkan kartu member (idempotent).
 * @return bool true bila kartu BARU diberikan pada pemanggilan ini
 */
function grant_member_card(int $patientId, string $source = 'manual'): bool
{
    $p = one('SELECT id, member_card FROM patients WHERE id = ?', [$patientId]);
    if (!$p) return false;
    if ((int)$p['member_card'] === 1) return false;
    $base = member_base_level();
    q('UPDATE patients SET member_card = 1,
              member_since = COALESCE(member_since, datetime("now","localtime")),
              member_source = ?, member_level = ?, member_level_at = datetime("now","localtime"),
              updated_at = datetime("now","localtime") WHERE id = ?',
        [$source, $base['key'], $patientId]);
    return true;
}

/** Cabut status member (nomor member tetap tersimpan). */
function revoke_member_card(int $patientId): void
{
    q('UPDATE patients SET member_card = 0, member_source = NULL, member_level = NULL,
              updated_at = datetime("now","localtime") WHERE id = ?', [$patientId]);
}

/**
 * Sinkronkan level member pasien dengan akumulasi periode berjalan.
 *
 * Dipanggil setelah transaksi disimpan dan saat halaman pasien dibuka. Bila
 * akumulasi sudah melewati ambang level berikutnya, level dinaikkan otomatis
 * (dan dicatat di audit log oleh pemanggil bila perlu).
 *
 * @param bool $activateFromThisBill transaksi yang baru saja dibuat bernilai ≥
 *        member_activate_amount sehingga kartu harus langsung aktif
 * @return array ['activated'=>bool,'from'=>?string,'to'=>string,'changed'=>bool,'status'=>array]
 */
function member_sync_level(int $patientId, bool $activateFromThisBill = false, ?int $year = null, string $source = ''): array
{
    /* Pastikan reset periode (bila sudah lewat) dijalankan LEBIH DULU: tanpa ini,
       level pasien bisa masih memakai periode lama saat transaksi baru dihitung. */
    member_rollover_run();
    $p = one('SELECT * FROM patients WHERE id = ?', [$patientId]);
    if (!$p) return ['activated' => false, 'from' => null, 'to' => '', 'changed' => false, 'status' => []];
    $year = $year ?: (int)date('Y');
    $tot = member_year_total($patientId, $year);
    $isMember = (int)$p['member_card'] === 1;

    /* Aktivasi: dari nilai transaksi ini, atau dari akumulasi periode berjalan. */
    $activated = false;
    if (!$isMember) {
        $base = member_base_level();
        $byBill = $activateFromThisBill && member_activate_amount() > 0;
        $byYear = $tot + 0.001 >= $base['min_year'];
        if ($byBill || $byYear) {
            $src = $source !== '' ? $source : ($byBill ? 'beli' : 'transaksi');
            $activated = grant_member_card($patientId, $src);
            $isMember = true;
        }
    }
    if (!$isMember) {
        return ['activated' => false, 'from' => null, 'to' => '', 'changed' => false,
                'status' => member_status($p, $year)];
    }

    /* Naik level mengikuti akumulasi periode berjalan. */
    $want = member_level_for_total($tot);
    $from = (string)($p['member_level'] ?? '');
    $changed = $from !== $want['key'];
    if ($changed) {
        q('UPDATE patients SET member_level = ?, member_level_at = datetime("now","localtime"),
                  updated_at = datetime("now","localtime") WHERE id = ?', [$want['key'], $patientId]);
    }
    $fresh = one('SELECT * FROM patients WHERE id = ?', [$patientId]);
    return [
        'activated' => $activated,
        'from' => $from !== '' ? $from : null,
        'to' => $want['key'],
        'changed' => $changed,
        'status' => member_status($fresh, $year),
    ];
}

/**
 * Hitung potongan member untuk sebuah transaksi.
 *
 * @param array $items  daftar item bersih: [['type'=>'treatment|skincare','line'=>float], ...]
 * @param float $subtotal nilai transaksi (sebelum diskon manual)
 * @param bool  $useCard  petugas memilih "Ada kartu member"
 * @param float $yearTotal akumulasi PERIODE berjalan SEBELUM transaksi ini
 * @param bool  $activating transaksi ini nilainya mencapai ambang aktivasi
 * @param ?string $scopeOverride cakupan yang DIPILIH KASIR pada transaksi ini
 *        (both/treatment/skincare). Null = pakai setelan Pengaturan.
 * @return array{pct:float,base:float,amount:float,label:string,level:?array,eligible:bool,reason:string,scope:string}
 */
function member_discount_calc(array $items, float $subtotal, bool $useCard, float $yearTotal,
                              bool $activating, bool $isMember = true, ?string $scopeOverride = null): array
{
    $scope = member_scope_norm($scopeOverride === null || $scopeOverride === '' ? member_scope() : $scopeOverride);
    $zero = ['pct' => 0.0, 'base' => 0.0, 'amount' => 0.0, 'label' => '', 'level' => null,
             'eligible' => false, 'reason' => '', 'scope' => $scope];
    if (!member_card_enabled()) { $zero['reason'] = 'Fitur kartu member sedang tidak aktif.'; return $zero; }
    if (!$useCard) { $zero['reason'] = 'Tidak memakai kartu member.'; return $zero; }
    /* Belum punya kartu & transaksi ini belum mencapai ambang aktivasi →
       ketentuan member belum berlaku (kartu belum ada). */
    if (!$isMember && !$activating) {
        $zero['reason'] = 'Kartu member belum aktif — transaksi perlu mencapai '
            . money(member_activate_amount()) . ' atau akumulasi ' . member_period_label()
            . ' mencapai ' . money(member_base_level()['min_year']) . '.';
        return $zero;
    }

    /* Cakupan dipakai dari PILIHAN KASIR pada transaksi ini (bila ada);
       setelan Pengaturan hanya menjadi nilai bawaannya. */
    $base = 0.0;
    foreach ($items as $it) {
        if (member_item_in_scope($scope, $it)) $base += (float)($it['line'] ?? 0);
    }
    $zero['base'] = $base;
    $min = member_min_transaction();
    if ($min > 0 && $subtotal + 0.001 < $min) {
        $zero['reason'] = 'Nilai transaksi belum mencapai ' . money($min) . ' — diskon member belum berlaku.';
        return $zero;
    }
    if ($base <= 0) {
        $zero['reason'] = 'Tidak ada barang dalam cakupan "' . member_scope_text($scope) . '" pada transaksi ini.';
        return $zero;
    }

    /* Level: transaksi ini IKUT dihitung ke akumulasi periode berjalan, sehingga
       transaksi yang melewati ambang langsung memakai level barunya. */
    $lookAhead = $yearTotal + $subtotal;
    if (!$isMember) $lookAhead = max($lookAhead, member_base_level()['min_year']);
    $level = member_level_for_total($lookAhead);

    $amount = round($base * $level['pct'] / 100, 2);
    if ($amount > $subtotal) $amount = round($subtotal, 2);
    return [
        'pct' => (float)$level['pct'],
        'base' => $base,
        'amount' => $amount,
        'label' => $level['label'],
        'level' => $level,
        'eligible' => true,
        'reason' => '',
        'scope' => $scope,
    ];
}

/** Apakah pasien ini pemegang kartu member? */
function patient_is_member(?array $p): bool
{
    return $p && (int)($p['member_card'] ?? 0) === 1;
}

/** Teks batas minimal transaksi untuk diskon (dipakai struk/form). */
function member_min_text(): string
{
    $min = member_min_transaction();
    return $min > 0 ? 'minimal transaksi ' . money($min) : 'tanpa nilai minimum';
}

/* ------------------------------------------------------------------ *
 * Background kartu member (gambar unggahan)
 *
 * Kartu dicetak pada ukuran standar 85,6 × 54 mm. Pada 300 dpi itu berarti
 * 1012 × 638 px (rasio 1,586 : 1). Gambar apa pun yang diunggah akan:
 *   1. diperkecil/dikompres seperti unggahan lain (kind "membercard"), lalu
 *   2. DIPOTONG (cover) tepat 1012 × 638 px supaya rasio kartu selalu benar —
 *      gambar dengan ukuran/rasio berbeda menyesuaikan sendiri ukuran kartu,
 *      bagian yang berlebih dipotong, tidak pernah diregangkan.
 * ------------------------------------------------------------------ */

/** Ukuran kanvas kartu member (px, 300 dpi). */
function member_card_canvas(): array
{
    return ['w' => 1012, 'h' => 638];
}

/** Path berkas background kartu ('' bila belum diunggah). */
function member_card_bg_path(): string
{
    $f = (string)setting('member_card_bg_file');
    if ($f === '' || basename($f) !== $f) return '';
    $p = local_upload_dir() . '/' . $f;
    return is_readable($p) ? $p : '';
}

/**
 * Simpan background kartu dari berkas unggahan (kompres + potong sesuai rasio kartu).
 * @return array{file:string,width:int,height:int,bytes:int,mime:string,compressed:bool,saved:int}
 */
function member_card_bg_save(string $tmp, string $name): array
{
    $made = img_process_upload($tmp, $name, local_upload_dir(), 'membercard', 'membercard');
    $src = local_upload_dir() . '/' . $made['file'];
    $cv = member_card_canvas();

    if (!img_gd()) {
        /* Tanpa GD: berkas disimpan apa adanya (kartu akan memakai rasio gambar itu). */
        return $made;
    }
    $im = @imagecreatefromstring((string)@file_get_contents($src));
    if ($im === false) return $made;
    $sw = imagesx($im); $sh = imagesy($im);
    $dst = imagecreatetruecolor($cv['w'], $cv['h']);
    imagefilledrectangle($dst, 0, 0, $cv['w'], $cv['h'], imagecolorallocate($dst, 255, 255, 255));
    /* Cover: skala agar memenuhi kanvas, lalu potong bagian tengah. */
    $scale = max($cv['w'] / max(1, $sw), $cv['h'] / max(1, $sh));
    $nw = (int)round($sw * $scale); $nh = (int)round($sh * $scale);
    $dx = (int)round(($cv['w'] - $nw) / 2);
    $dy = (int)round(($cv['h'] - $nh) / 2);
    imagecopyresampled($dst, $im, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($im);
    /* Simpan sebagai PNG (dipakai oleh penulis PDF yang hanya menerima PNG). */
    $target = local_upload_dir() . '/membercard-card-' . bin2hex(random_bytes(6)) . '.png';
    $ok = @imagepng($dst, $target, 9);
    imagedestroy($dst);
    if (!$ok) return $made;
    @unlink($src);
    $made['file'] = basename($target);
    $made['width'] = $cv['w'];
    $made['height'] = $cv['h'];
    $made['bytes'] = (int)@filesize($target);
    $made['mime'] = 'image/png';
    $made['cropped'] = true;
    return $made;
}
