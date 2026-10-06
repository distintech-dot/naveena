<?php
/**
 * PENGISIAN DATA DEMO — dipakai tombol "Isi Data Demo" di Pengaturan Sistem
 * (khusus Super Admin).
 *
 * Tujuan: setelah data lama dikosongkan (tombol "Hapus Semua Data"), aplikasi
 * dapat langsung diisi data contoh agar semua menu, laporan, dan grafik terlihat
 * hidup — mis. saat menduplikasi aplikasi ini untuk klinik lain.
 *
 * Isi data demo:
 *   - 2 cabang (dibuat bila belum ada),
 *   - dokter & terapis tiap cabang,
 *   - supplier, master treatment, master skincare, bahan treatment + stok awal,
 *   - 30 pasien (dibagi ke 2 cabang), sebagian memakai Kartu Member,
 *   - rekam medis (dengan kode ICD dari kamus resmi),
 *   - reservasi (termasuk yang multi-treatment),
 *   - transaksi tiap cabang dari awal bulan DEMO_MONTHS-1 bulan lalu SAMPAI HARI
 *     INI (item treatment/skincare/bahan + pembayaran).
 *
 * Transaksi dibuat lewat order_create() — SATU PINTU yang sama dengan kasir —
 * sehingga harga, stok, diskon member, nomor invoice, dan jejak stok konsisten
 * dengan data sungguhan (bukan insert manual yang bisa menyimpang dari aturan).
 *
 * PENTING — sifat "TOPI UP" (bukan menumpuk): data yang sudah ada TIDAK
 * digandakan. Pasien hanya dibuat sampai 15 per cabang, master hanya bila
 * kodenya belum ada, rekam medis hanya untuk pasien yang belum punya, reservasi
 * contoh hanya bila belum ada 10 per cabang, dan pada bagian transaksi setiap
 * HARI yang sudah punya transaksi di cabang itu DILEWATI. Karena itu menekan
 * tombol "Isi Data Demo" lagi pada bulan berikutnya akan MELENGKAPI bulan baru
 * (yang tadinya kosong) tanpa menggandakan data bulan yang sudah terisi.
 *
 * JEBAKAN YANG PERNAH TERJADI (jangan diulang): rentang ini SEMPAT ditulis sebagai
 * tanggal TETAP ('2025-11-01' s.d. '2026-10-05'). Akibatnya tombol "Isi Data Demo"
 * berhenti menambah data setelah tanggal itu — bulan baru tetap kosong, dan uji
 * `hapus-data-demo` gagal. Selalu hitung rentangnya dari TANGGAL HARI INI.
 */
declare(strict_types=1);

/** Berapa bulan data transaksi demo dibuat (termasuk bulan berjalan). */
const DEMO_MONTHS = 3;

/**
 * Rentang tanggal data demo (bergulir, dihitung saat dipanggil).
 * @return array{mulai:string,akhir:string}
 */
function demo_date_range(): array
{
    /* PENTING: ->setTime(0,0) WAJIB pada kedua ujung. `first day of this month`
       masih memuat JAM saat ini (mis. 04:57) sehingga tanggal berjam 04:57 tidak
       pernah "<= hari ini 00:00" dan HARI TERAKHIR (hari ini) terlewat — data demo
       pun berhenti sehari sebelumnya. */
    $mulai = (new DateTimeImmutable('first day of this month'))
        ->modify('-' . (DEMO_MONTHS - 1) . ' months')->setTime(0, 0);
    $akhir = (new DateTimeImmutable('today'))->setTime(0, 0);
    return ['mulai' => $mulai->format('Y-m-d'), 'akhir' => $akhir->format('Y-m-d')];
}

/** Daftar cabang contoh: dipakai bila cabang belum ada / kurang dari 2. */
function demo_branch_presets(): array
{
    return [
        ['code' => 'KW', 'name' => clinic_name() . ' Kaliwungu', 'phone' => '0294-381001',
         'address' => 'Jl. Raya Kaliwungu No. 12, Kendal'],
        ['code' => 'CP', 'name' => clinic_name() . ' Cepiring', 'phone' => '0294-381002',
         'address' => 'Jl. Raya Cepiring No. 45, Kendal'],
    ];
}

/** Pastikan ada 2 cabang; kembalikan daftar cabang yang dipakai (maks 2). */
function demo_ensure_branches(array &$out): array
{
    $have = all('SELECT * FROM branches ORDER BY id');
    if (count($have) >= 2) {
        $chosen = array_slice($have, 0, 2);
    } else {
        foreach (demo_branch_presets() as $preset) {
            if (count($have) >= 2) break;
            if (one('SELECT id FROM branches WHERE code = ?', [$preset['code']])) continue;
            q('INSERT INTO branches (code, name, address, phone, email, opening_hours, status)
               VALUES (?,?,?,?,?,?, "active")',
                [$preset['code'], $preset['name'], $preset['address'], $preset['phone'],
                 strtolower(str_replace(' ', '', $preset['code'])) . '@naveena.id', '09.00 - 21.00']);
            $out['cabang']++;
            $have = all('SELECT * FROM branches ORDER BY id');
        }
        $chosen = array_slice($have, 0, 2);
    }
    return $chosen;
}

/** Kode ICD dari kamus (dilewati bila tidak ada di kamus resmi). */
function demo_icd(string $kind, array $codes): array
{
    foreach ($codes as $c) {
        $r = icd_lookup($kind, $c);
        if ($r) return ['code' => (string)$r['code'], 'desc' => (string)($r['name_id'] ?: $r['name_en'])];
    }
    return ['code' => '', 'desc' => ''];
}

/** Apakah data operasional sudah ada (dipakai untuk peringatan di halaman). */
function demo_operational_counts(): array
{
    return [
        'patients' => (int)scalar('SELECT COUNT(*) FROM patients'),
        'orders' => (int)scalar('SELECT COUNT(*) FROM orders'),
        'medical_records' => (int)scalar('SELECT COUNT(*) FROM medical_records'),
        'appointments' => (int)scalar('SELECT COUNT(*) FROM appointments'),
        'treatments' => (int)scalar('SELECT COUNT(*) FROM treatments'),
        'skincare' => (int)scalar('SELECT COUNT(*) FROM skincare_products'),
        'materials' => (int)scalar('SELECT COUNT(*) FROM treatment_materials'),
    ];
}

/**
 * Isi seluruh data demo.
 *
 * @return array ringkasan jumlah baris yang dibuat per bagian
 */
function demo_seed(?int $userId = null): array
{
    @set_time_limit(0);
    $out = ['cabang' => 0, 'dokter' => 0, 'terapis' => 0, 'supplier' => 0, 'treatment' => 0,
            'skincare' => 0, 'bahan' => 0, 'stok_awal' => 0, 'pasien' => 0, 'kartu_member' => 0,
            'rekam_medis' => 0, 'reservasi' => 0, 'transaksi' => 0,
            'hari_terisi' => 0, 'hari_dilewati' => 0, 'restok' => 0, 'warnings' => []];
    $user = current_user();
    if (!$user) throw new RuntimeException('Sesi pengguna tidak ditemukan.');
    $user = ['id' => (int)$user['id'], 'name' => (string)$user['name']];

    /* Email struk otomatis dimatikan sementara: data demo tidak boleh mengirimi
       pasien contoh sebuah email sungguhan. Setelan dikembalikan seperti semula. */
    $emailAuto = setting('email_receipt_auto');
    set_setting('email_receipt_auto', '0');
    /* Cakupan cabang disetel ke SEMUA cabang selama pengisian supaya transaksi
       contoh pada setiap cabang dapat dibuat walau pemilih cabang di topbar
       sedang dipin ke satu cabang. */
    $savedActive = $_SESSION['active_branch'] ?? null;
    unset($_SESSION['active_branch']);
    try {
        $branches = demo_ensure_branches($out);
        if (!$branches) throw new RuntimeException('Tidak ada cabang untuk diisi data demo.');
        /* Daftar id cabang yang dipakai demo (untuk biaya operasional per cabang). */
        $demoBranchIds = array_map(fn($b) => (int)$b['id'], $branches);

        /* ---------- Master per cabang ---------- */
        $doctorNames = [
            'dr. Ratna Sari, Sp.KK', 'dr. Bagus Prasetyo', 'dr. Anisa Rahmawati',
            'dr. Hendra Wijaya, Sp.KK',
        ];
        $therapistNames = ['Wulan Safitri', 'Sari Indah', 'Ningsih Rahayu', 'Dewi Anggraini', 'Rina Puspita'];
        $supplierNames = [
            ['PT Dermindo Sejahtera', '021-5566778', 'supplier@dermindo.co.id'],
            ['CV Beauty Supply Nusantara', '024-7788991', 'sales@beautysupply.id'],
            ['PT Glowlab Indonesia', '022-4433221', 'order@glowlab.co.id'],
        ];
        $treatmentData = [
            ['TR-FACIAL', 'Facial Glow Basic', 'Facial', 250000, 0, 60],
            ['TR-FACIAL-AC', 'Facial Acne Treatment', 'Facial', 320000, 295000, 75],
            ['TR-FACIAL-BR', 'Facial Brightening', 'Facial', 350000, 0, 75],
            ['TR-PEEL', 'Chemical Peeling', 'Peeling', 450000, 420000, 45],
            ['TR-PEEL-D', 'Diamond Peeling', 'Peeling', 400000, 0, 45],
            ['TR-MICRO', 'Mikrodermabrasi', 'Peeling', 375000, 0, 50],
            ['TR-LASER', 'Laser Rejuvenation', 'Laser', 850000, 799000, 45],
            ['TR-BOTOX', 'Botox Wajah', 'Injeksi', 2500000, 0, 30],
            ['TR-FILLER', 'Filler Bibir', 'Injeksi', 3200000, 0, 40],
            ['TR-BODY', 'Body Treatment Slimming', 'Body', 550000, 499000, 90],
            ['TR-WAX', 'Waxing Kaki', 'Waxing', 175000, 0, 30],
            ['TR-KONSUL', 'Konsultasi Dokter', 'Konsultasi', 100000, 0, 20],
        ];
        $skincareData = [
            ['SK-CLEANSER', 'Gentle Cleanser 100ml', 'Pembersih', 45000, 89000, 120, 'pcs'],
            ['SK-TONER', 'Balancing Toner 150ml', 'Toner', 55000, 99000, 90, 'pcs'],
            ['SK-SERUM-VC', 'Vitamin C Serum 20ml', 'Serum', 120000, 219000, 70, 'pcs'],
            ['SK-SERUM-AC', 'Acne Serum 20ml', 'Serum', 110000, 199000, 80, 'pcs'],
            ['SK-MOIST', 'Moisturizer Hyaluronic 50ml', 'Pelembap', 95000, 179000, 100, 'pcs'],
            ['SK-SUNSCREEN', 'Sunscreen SPF50 PA+++', 'Sunscreen', 85000, 159000, 150, 'pcs'],
            ['SK-MASK', 'Clay Mask 60gr', 'Masker', 70000, 129000, 60, 'pcs'],
            ['SK-EYE', 'Eye Cream 15ml', 'Mata', 105000, 189000, 45, 'pcs'],
            ['SK-SCAR', 'Scar Cream 20gr', 'Perawatan', 88000, 165000, 55, 'pcs'],
            ['SK-CLEANSING', 'Cleansing Milk 200ml', 'Pembersih', 62000, 115000, 85, 'pcs'],
        ];
        $materialData = [
            ['BH-FACIAL', 'Bahan Facial Glow (paket)', 'Bahan Perawatan', 150, 'pcs', 25000],
            ['BH-PEEL-AHA', 'AHA Peeling Solution', 'Bahan Perawatan', 60, 'liter', 180000],
            ['BH-PEEL-TCA', 'TCA Peeling Solution', 'Bahan Perawatan', 40, 'liter', 320000],
            ['BH-MASK-POW', 'Bubuk Masker Alginat', 'Bahan Perawatan', 80, 'kg', 210000],
            ['BH-ELASTIN', 'Elastin Serum', 'Bahan Perawatan', 45, 'liter', 260000],
            ['BH-AMA', 'Ampoule Vitamin C', 'Bahan Perawatan', 90, 'pcs', 45000],
            ['BH-ANEST', 'Krim Anestesi Topikal', 'Bahan Medis', 70, 'pcs', 65000],
            ['BH-ALCOHOL', 'Alkohol Swab 70%', 'Bahan Medis', 200, 'pcs', 8000],
        ];
        $icd10Pool = [
            ['L70.0', 'L20.9', 'L30.9'], ['L81.7', 'L85.0'], ['L70.9', 'L73.8'],
            ['L57.0', 'L56.2'], ['L65.9', 'L64.9'],
        ];
        $icd9Pool = [['86.3', '86.4'], ['99.82'], ['86.09', '86.3']];

        $master = [];      // [$branchId => ['tr'=>[], 'sk'=>[], 'bh'=>[], 'doc'=>[], 'th'=>[]]]
        foreach ($branches as $bi => $b) {
            $bid = (int)$b['id'];
            $master[$bid] = ['tr' => [], 'sk' => [], 'bh' => [], 'doc' => [], 'th' => []];

            /* dokter & terapis (dipakai ulang bila cabang sudah punya) */
            $master[$bid]['doc'] = all('SELECT * FROM doctors WHERE branch_id = ? AND status = "active" ORDER BY id', [$bid]);
            if (!$master[$bid]['doc']) {
                foreach ([array_slice($doctorNames, 0, 2), [$doctorNames[2], $doctorNames[3]]][$bi % 2] as $dn) {
                    q('INSERT INTO doctors (name, phone, specialization, schedule, branch_id, status, created_at)
                       VALUES (?,?,?,?,?,"active",datetime("now","localtime"))',
                        [$dn, '0812' . str_pad((string)random_int(1000000, 9999999), 7, '0'),
                         'Dermatologi & Estetika', 'Senin-Sabtu 09.00-17.00', $bid]);
                    $out['dokter']++;
                }
                $master[$bid]['doc'] = all('SELECT * FROM doctors WHERE branch_id = ? ORDER BY id', [$bid]);
            }
            $master[$bid]['th'] = all('SELECT * FROM therapists WHERE branch_id = ? AND status = "active" ORDER BY id', [$bid]);
            if (!$master[$bid]['th']) {
                foreach (array_slice($therapistNames, $bi, 3) ?: array_slice($therapistNames, 0, 3) as $tn) {
                    q('INSERT INTO therapists (name, phone, specialization, schedule, branch_id, status, created_at)
                       VALUES (?,?,?,?,?,"active",datetime("now","localtime"))',
                        [$tn, '0813' . str_pad((string)random_int(1000000, 9999999), 7, '0'),
                         'Terapis Perawatan Kulit', 'Senin-Sabtu 09.00-20.00', $bid]);
                    $out['terapis']++;
                }
                $master[$bid]['th'] = all('SELECT * FROM therapists WHERE branch_id = ? ORDER BY id', [$bid]);
            }

            /* supplier (per cabang: nama yang sama boleh ada di cabang lain) */
            $supIds = [];
            foreach ($supplierNames as $si => $s) {
                $exist = one('SELECT * FROM suppliers WHERE name = ? AND branch_id = ?', [$s[0], $bid]);
                if ($exist) { $supIds[] = $exist; continue; }
                q('INSERT INTO suppliers (name, phone, address, email, branch_id, status, created_at)
                   VALUES (?,?,?,?,?,"active",datetime("now","localtime"))',
                    [$s[0], $s[1], 'Kawasan Industri ' . ($si % 2 ? 'Semarang' : 'Kendal'), $s[2], $bid]);
                $out['supplier']++;
                $supIds[] = one('SELECT * FROM suppliers WHERE id = ?', [(int)db()->lastInsertId()]);
            }

            /* treatment */
            foreach ($treatmentData as $t) {
                if (one('SELECT id FROM treatments WHERE code = ? AND branch_id = ?', [$t[0], $bid])) continue;
                /* HPP treatment = perkiraan biaya bahan/pokok ±35% dari harga normal
                   supaya menu Keuangan (laba) langsung berisi angka yang masuk akal. */
                $hpp = (float)round($t[3] * 0.35);
                q('INSERT INTO treatments (code, name, category, normal_price, promo_price, hpp, duration, branch_id, status, created_at)
                   VALUES (?,?,?,?,?,?,?,?,"active",datetime("now","localtime"))',
                    [$t[0], $t[1], $t[2], $t[3], $t[4], $hpp, $t[5], $bid]);
                $out['treatment']++;
                $out['hpp_treatment'] = (int)($out['hpp_treatment'] ?? 0) + 1;
            }
            /* Data lama tanpa HPP diisi juga (idempoten, tidak menimpa yang sudah ada). */
            foreach (all('SELECT id, normal_price FROM treatments WHERE branch_id = ? AND COALESCE(hpp,0) = 0', [$bid]) as $tz) {
                q('UPDATE treatments SET hpp = ? WHERE id = ?', [round((float)$tz['normal_price'] * 0.35), (int)$tz['id']]);
                $out['hpp_treatment'] = (int)($out['hpp_treatment'] ?? 0) + 1;
            }
            $master[$bid]['tr'] = all('SELECT * FROM treatments WHERE branch_id = ? AND status="active" ORDER BY id', [$bid]);

            /* skincare + stok awal */
            foreach ($skincareData as $s) {
                if (one('SELECT id FROM skincare_products WHERE code = ? AND branch_id = ?', [$s[0], $bid])) continue;
                $sup = $supIds ? $supIds[array_rand($supIds)] : null;
                q('INSERT INTO skincare_products (code, name, category, purchase_price, selling_price, stock,
                        minimum_stock, unit, supplier_id, supplier_name, branch_id, status, created_at)
                   VALUES (?,?,?,?,?,0,?,?,?,?,?,"active",datetime("now","localtime"))',
                    [$s[0], $s[1], $s[2], $s[3], $s[4], 10, $s[6], $sup['id'] ?? null, $sup['name'] ?? null, $bid]);
                $out['skincare']++;
            }
            foreach (all('SELECT * FROM skincare_products WHERE branch_id = ? ORDER BY id', [$bid]) as $sk) {
                if ((float)$sk['stock'] > 0) continue;      // sudah ada stok (data lama) -> jangan gandakan
                $target = 0;
                foreach ($skincareData as $s) if ($s[0] === $sk['code']) $target = (float)$s[5];
                if ($target <= 0) continue;
                inv_apply('skincare', (int)$sk['id'], $target, 'Stok Awal', 'Stok awal data demo',
                    ['ref_type' => 'demo']);
                $out['stok_awal']++;
            }
            /* HPP produk = harga beli; bila ada yang 0 (data lama) isi perkiraan 55% harga jual. */
            foreach (all('SELECT id, selling_price FROM skincare_products WHERE branch_id = ? AND COALESCE(purchase_price,0) = 0', [$bid]) as $sz) {
                q('UPDATE skincare_products SET purchase_price = ? WHERE id = ?', [round((float)$sz['selling_price'] * 0.55), (int)$sz['id']]);
                $out['hpp_produk'] = (int)($out['hpp_produk'] ?? 0) + 1;
            }
            $master[$bid]['sk'] = all('SELECT * FROM skincare_products WHERE branch_id = ? AND status="active" ORDER BY id', [$bid]);

            /* bahan treatment + stok awal */
            foreach ($materialData as $m) {
                if (one('SELECT id FROM treatment_materials WHERE code = ? AND branch_id = ?', [$m[0], $bid])) continue;
                $sup = $supIds ? $supIds[array_rand($supIds)] : null;
                q('INSERT INTO treatment_materials (code, name, category, stock, minimum_stock, unit, price,
                        supplier_id, supplier_name, branch_id, status, created_at)
                   VALUES (?,?,?,0,?,?,?,?,?,?,"active",datetime("now","localtime"))',
                    [$m[0], $m[1], $m[2], max(2, round($m[3] * 0.15)), $m[4], $m[5], $sup['id'] ?? null, $sup['name'] ?? null, $bid]);
                $out['bahan']++;
            }
            foreach (all('SELECT * FROM treatment_materials WHERE branch_id = ? ORDER BY id', [$bid]) as $bh) {
                if ((float)$bh['stock'] > 0) continue;
                $target = 0;
                foreach ($materialData as $m) if ($m[0] === $bh['code']) $target = (float)$m[3];
                if ($target <= 0) continue;
                inv_apply('material', (int)$bh['id'], $target, 'Stok Awal', 'Stok awal data demo',
                    ['ref_type' => 'demo']);
                $out['stok_awal']++;
            }
            $master[$bid]['bh'] = all('SELECT * FROM treatment_materials WHERE branch_id = ? AND status="active" ORDER BY id', [$bid]);

            /* ---------- PAKET CONTOH (treatment & produk) ----------
               Dibuat dari item yang barusan ada supaya menu paket & kasir langsung
               dapat dicoba; HPP dihitung otomatis dari komponennya. */
            $mkPackage = function (string $kind, string $name, array $comps, float $price) use ($bid, &$out): void {
                if (one('SELECT id FROM packages WHERE name = ? AND branch_id = ?', [$name, $bid])) return;
                $code = next_package_number($bid, $kind);
                q('INSERT INTO packages (code, name, kind, price, hpp, note, branch_id, status, created_at)
                   VALUES (?,?,?,?,0,?,?,"active",datetime("now","localtime"))',
                    [$code, $name, $kind, $price, 'Paket contoh data demo', $bid]);
                $pidNew = (int)db()->lastInsertId();
                $items = [];
                foreach ($comps as $c) {
                    if (($c['type'] ?? '') === 'treatment') {
                        $row = one('SELECT id FROM treatments WHERE code = ? AND branch_id = ?', [$c['code'], $bid]);
                    } elseif (($c['type'] ?? '') === 'material') {
                        $row = one('SELECT id FROM treatment_materials WHERE code = ? AND branch_id = ?', [$c['code'], $bid]);
                    } else {
                        $row = one('SELECT id FROM skincare_products WHERE code = ? AND branch_id = ?', [$c['code'], $bid]);
                    }
                    if ($row) $items[] = ['type' => $c['type'], 'id' => (int)$row['id'], 'qty' => $c['qty']];
                }
                if (!$items) { q('DELETE FROM packages WHERE id = ?', [$pidNew]); return; }
                package_items_save($pidNew, $items);
                q('UPDATE packages SET hpp = ? WHERE id = ?', [package_hpp_calc($pidNew), $pidNew]);
                $out['paket'] = (int)($out['paket'] ?? 0) + 1;
            };
            $mkPackage('treatment', 'Paket Facial Glow 3x + Serum Ampul',
                [['type' => 'treatment', 'code' => 'TR-FACIAL', 'qty' => 3],
                 ['type' => 'material', 'code' => 'BH-AMA', 'qty' => 3]], 650000);
            $mkPackage('treatment', 'Paket Peeling + Masker',
                [['type' => 'treatment', 'code' => 'TR-PEEL', 'qty' => 1],
                 ['type' => 'material', 'code' => 'BH-MASK-POW', 'qty' => 1]], 500000);
            $mkPackage('product', 'Paket Home Care Basic (Cleanser + Toner + Moisturizer)',
                [['type' => 'skincare', 'code' => 'SK-CLEANSER', 'qty' => 1],
                 ['type' => 'skincare', 'code' => 'SK-TONER', 'qty' => 1],
                 ['type' => 'skincare', 'code' => 'SK-MOIST', 'qty' => 1]], 320000);
            $mkPackage('product', 'Paket Serum & Sunscreen',
                [['type' => 'skincare', 'code' => 'SK-SERUM-VC', 'qty' => 1],
                 ['type' => 'skincare', 'code' => 'SK-SUNSCREEN', 'qty' => 1]], 340000);
        }

        /* ---------- BIAYA OPERASIONAL (menu Keuangan → Laporan Lengkap) ----------
           Diisi dengan nominal REALISTIS untuk klinik kecil sehingga LABA BERSIH
           pada data demo masuk akal (TIDAK minus). Dua kelompok:
             • per cabang (gaji, listrik & air, marketing, operasional lain) —
               kebutuhan tiap cabang berbeda, jadi diisi pada cakupan MASING-MASING
               cabang dan dibebankan penuh ke cabang tersebut;
             • bersama semua cabang (sewa bangunan, aplikasi, pajak) — dibagi RATA
               ke tiap cabang saat laporan difilter ke satu cabang.
           HANYA mengisi baris yang nominalnya masih 0, sehingga angka yang sudah
           diisi pemilik klinik TIDAK pernah ditimpa oleh tombol "Isi Data Demo". */
        $demoCosts = [
            ['Gaji', 4000000, 1, true],
            ['Listrik & Air', 1000000, 1, true],
            ['Marketing', 750000, 1, true],
            ['Operasional Lainnya', 300000, 1, true],
            ['Sewa Bangunan', 48000000, 12, false],
            ['Aplikasi', 250000, 1, false],
            ['Pajak', 500000, 1, false],
        ];
        $setCost = function (string $cname, float $amount, int $period, ?int $cbranch) use (&$out): void {
            /* Nominal disimpan PER CAKUPAN (tabel finance_cost_amounts): satu pos
               biaya punya satu nominal untuk tiap cakupan (0 = semua cabang).
               HANYA mengisi yang masih 0 sehingga angka pemilik tidak pernah
               ditimpa, dan hanya menonaktifkan cakupan lain yang juga masih
               kosong (jangan mengubah konfigurasi pemilik). */
            $scope = ($cbranch === null) ? 0 : (int)$cbranch;
            $cost = one('SELECT id FROM finance_costs WHERE LOWER(TRIM(name)) = LOWER(?) ORDER BY id LIMIT 1', [$cname]);
            if ($cost) {
                $costId = (int)$cost['id'];
            } else {
                q('INSERT INTO finance_costs (name, amount, period_months, sort, status, created_at)
                   VALUES (?,0,1,?,"active",datetime("now","localtime"))',
                    [$cname, (int)scalar('SELECT COALESCE(MAX(sort),0)+10 FROM finance_costs')]);
                $costId = (int)db()->lastInsertId();
            }
            $row = one('SELECT * FROM finance_cost_amounts WHERE cost_id = ? AND branch_id = ?', [$costId, $scope]);
            if (!$row) {
                q('INSERT INTO finance_cost_amounts (cost_id, branch_id, amount, period_months, status, applicable, updated_at)
                   VALUES (?,?,0,?,"active",0,datetime("now","localtime"))', [$costId, $scope, $period]);
                $row = one('SELECT * FROM finance_cost_amounts WHERE cost_id = ? AND branch_id = ?', [$costId, $scope]);
            }
            if ((float)$row['amount'] != 0.0) return;              // sudah diisi pemilik → jangan diubah
            q('UPDATE finance_cost_amounts SET amount = ?, period_months = ?, applicable = 1,
                      updated_at = datetime("now","localtime") WHERE id = ?',
                [$amount, $period, (int)$row['id']]);
            /* Cakupan yang baru diisi menjadi yang BERLAKU (aturan sama dengan
               tombol Simpan di menu Keuangan) — tetapi hanya mengosongkan
               cakupan lain yang juga belum berisi. */
            if ($scope === 0) {
                q('UPDATE finance_cost_amounts SET applicable = 0
                   WHERE cost_id = ? AND branch_id <> 0 AND COALESCE(amount,0) = 0', [$costId]);
            } else {
                q('UPDATE finance_cost_amounts SET applicable = 0
                   WHERE cost_id = ? AND branch_id = 0 AND COALESCE(amount,0) = 0', [$costId]);
            }
            $out['biaya_operasional'] = (int)($out['biaya_operasional'] ?? 0) + 1;
        };
        foreach ($demoCosts as [$cname, $amount, $period, $perBranch]) {
            if ($perBranch) {
                foreach ($demoBranchIds as $dbid) $setCost($cname, $amount, $period, (int)$dbid);
            } else {
                $setCost($cname, $amount, $period, null);
            }
        }

        /* ---------- Pasien (30, dibagi ke 2 cabang) ---------- */
        $firstNames = ['Siti', 'Rina', 'Dewi', 'Ayu', 'Bella', 'Citra', 'Maya', 'Nadia', 'Intan', 'Lia',
            'Putri', 'Ratna', 'Sari', 'Widya', 'Yuni', 'Anisa', 'Fitri', 'Gita', 'Hana', 'Ika'];
        $lastNames = ['Aminah', 'Wati', 'Lestari', 'Pertiwi', 'Safira', 'Kirana', 'Sari', 'Putri',
            'Permata', 'Anggraini', 'Handayani', 'Maulida', 'Novita', 'Rahmawati', 'Setiawan'];
        $addrPool = ['Jl. Pemuda No. 12', 'Jl. Kartini No. 8', 'Perum Grand Kaliwungu Blok C4',
            'Jl. Soekarno-Hatta No. 77', 'Dusun Krajan RT 02 RW 05', 'Jl. Diponegoro No. 21',
            'Perum Cepiring Asri Blok A2', 'Jl. Raya Brangsong No. 45'];
        $patientIds = [];
        foreach ($branches as $bi => $b) {
            $bid = (int)$b['id'];
            /* TOP UP: hanya dibuat sampai 15 pasien per cabang supaya menekan
               "Isi Data Demo" lagi tidak menggandakan daftar pasien. */
            $patientIds[$bid] = array_map(fn($r) => (int)$r['id'],
                all('SELECT id FROM patients WHERE branch_id = ? ORDER BY id', [$bid]));
            $sudahAda = count($patientIds[$bid]);
            for ($i = $sudahAda; $i < 15; $i++) {
                $name = $firstNames[($bi * 15 + $i) % count($firstNames)] . ' '
                      . $lastNames[($i * 3 + $bi) % count($lastNames)];
                $gender = ($i % 3 === 0) ? 'Laki-laki' : 'Perempuan';
                $phone = '08' . str_pad((string)random_int(1000000000, 9999999999), 10, '0');
                $dob = date('Y-m-d', strtotime('-' . random_int(18, 55) . ' years -' . random_int(0, 300) . ' days'));
                /* Jam diambil dari TANGGAL (bukan sekarang + sekian jam) supaya
                   tanggal registrasi tidak pernah "melompat" ke hari berikutnya. */
                $created = date('Y-m-d', strtotime('-' . random_int(3, 45) . ' days'))
                    . sprintf(' %02d:%02d:00', random_int(9, 19), random_int(0, 59));
                $email = ($i % 3 === 0) ? strtolower(preg_replace('/[^a-z]/i', '', $name)) . '@contoh.id' : '';
                q('INSERT INTO patients (patient_number, member_number, name, gender, nik, address, phone, email,
                        patient_type, birth_date, branch_id, status, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,"active",?,?)',
                    [next_patient_number($bid), next_member_number($bid), $name, $gender,
                     '3324' . str_pad((string)random_int(100000000000, 999999999999), 12, '0'),
                     $addrPool[array_rand($addrPool)], $phone, $email,
                     $i < 5 ? 'Baru' : 'Lama', $dob, $bid, $user['id'], $created]);
                $pid = (int)db()->lastInsertId();
                $patientIds[$bid][] = $pid;
                $out['pasien']++;
                $patientIds[$bid][] = $pid;
            }
            /* 4 pasien pertama tiap cabang diberi Kartu Member (contoh).
               grant_member_card() idempotent, jadi aman dipanggil berulang. */
            foreach (array_slice($patientIds[$bid], 0, 4) as $mpid) {
                if (grant_member_card($mpid, 'beli')) {
                    $out['kartu_member']++;
                    q('UPDATE patients SET member_since = COALESCE((SELECT created_at FROM patients WHERE id = ?), datetime("now","localtime")) WHERE id = ?',
                        [$mpid, $mpid]);
                }
            }
            if ($out['pasien'] > 0) {
                audit('Isi Data Demo', 'Pasien', null, null,
                    ['pasien_baru' => $out['pasien'], 'cabang' => $b['name']], 'Pasien contoh untuk data demo');
            }
        }

        /* ---------- Rekam medis, reservasi, transaksi ---------- */
        foreach ($branches as $bi => $b) {
            $bid = (int)$b['id'];
            $docs = $master[$bid]['doc'];
            $ths = $master[$bid]['th'];
            $trs = $master[$bid]['tr'];
            if (!$docs || !$ths || !$trs) throw new RuntimeException('Master data cabang ' . $b['name'] . ' belum lengkap.');

            /* Rekam medis: 1–2 catatan per pasien (contoh SOAP + kode ICD) */
            /* Tanggal rekam medis dibuat di dalam rentang data demo yang sama
               (bergulir), supaya tidak ada rekam medis bertanggal "masa depan". */
            $rentang = demo_date_range();
            $medicalStart = new DateTimeImmutable($rentang['mulai']);
            $medicalDays = max(1, (int)$medicalStart->diff(new DateTimeImmutable($rentang['akhir']))->format('%a'));
            foreach ($patientIds[$bid] as $pi => $pid) {
                /* TOP UP: pasien yang sudah punya rekam medis tidak ditambahi lagi. */
                if ((int)scalar('SELECT COUNT(*) FROM medical_records WHERE patient_id = ?', [$pid]) > 0) continue;
                $recs = ($pi % 3 === 0) ? 2 : 1;
                for ($k = 0; $k < $recs; $k++) {
                    $doc = $docs[array_rand($docs)];
                    $th  = $ths[array_rand($ths)];
                    $date = $medicalStart->modify('+' . random_int(0, $medicalDays) . ' days')->format('Y-m-d');
                    $icd10 = demo_icd('icd10', $icd10Pool[($pi + $k) % count($icd10Pool)]);
                    $icd9  = demo_icd('icd9cm', $icd9Pool[($k) % count($icd9Pool)]);
                    $status = $k === 0 ? 'Selesai' : ['Proses', 'Terjadwal'][$pi % 2];
                    q('INSERT INTO medical_records (record_number, patient_id, doctor_id, therapist_id, staff_name,
                            branch_id, date, subjective, objective, diagnosis, icd10, icd10_desc, action, icd9, icd9_desc,
                            solution, status, created_by, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [next_medical_number($bid), $pid, (int)$doc['id'], (int)$th['id'],
                         staff_both_text($doc['name'], $th['name']),
                         $bid, $date,
                         'Pasien datang dengan keluhan kulit wajah kurang bercahaya dan terdapat jerawat kecil.',
                         'Kulit wajah berminyak, komedo pada area hidung, tidak ada tanda infeksi.',
                         $icd10['code'] !== '' ? 'Kondisi kulit sesuai kode ICD terpilih' : 'Kondisi kulit ringan',
                         $icd10['code'], $icd10['desc'],
                         'Pembersihan wajah, pemberian serum, dan perawatan sesuai kondisi kulit.',
                         $icd9['code'], $icd9['desc'],
                         'Kontrol 2 minggu, pemakaian sunscreen pagi dan malam, hindari paparan sinar matahari langsung.',
                         $status, $user['id'],
                         date('Y-m-d H:i:s', strtotime($date) + random_int(9, 18) * 3600)]);
                    $out['rekam_medis']++;
                }
            }
            audit('Isi Data Demo', 'Rekam Medis', null, null, ['cabang' => $b['name']],
                'Rekam medis contoh untuk data demo');

            /* Reservasi (sebagian multi-treatment) */
            $statuses = ['Menunggu', 'Confirmed', 'Hadir', 'Selesai', 'Cancel'];
            /* TOP UP: reservasi contoh hanya dibuat bila cabang ini belum punya 10. */
            $adaResv = (int)scalar("SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND notes = 'Reservasi contoh data demo'", [$bid]);
            $buatResv = max(0, 10 - $adaResv);
            for ($k = $adaResv; $k < $adaResv + $buatResv; $k++) {
                $pid = $patientIds[$bid][array_rand($patientIds[$bid])];
                $date = date('Y-m-d', strtotime(($k < 7 ? '-' : '+') . random_int(1, 6) . ' days'));
                $pick = (array)array_rand($trs, min(count($trs), random_int(1, 3)));
                $ids = [];
                foreach ($pick as $idx) $ids[] = (int)$trs[$idx]['id'];
                $doc = $docs[array_rand($docs)];
                $th  = $ths[array_rand($ths)];
                q('INSERT INTO appointments (appointment_number, patient_id, doctor_id, therapist_id, treatment_id,
                        branch_id, date, time, status, notes, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [next_appointment_number($bid), $pid, (int)$doc['id'], (int)$th['id'], $ids[0],
                     $bid, $date, sprintf('%02d:00', random_int(9, 18)),
                     $statuses[$k % count($statuses)],
                     'Reservasi contoh data demo', $user['id'], date('Y-m-d H:i:s', strtotime($date) + 8 * 3600)]);
                $apptId = (int)db()->lastInsertId();
                res_save_treatments($apptId, $ids);
                $out['reservasi']++;
            }
            audit('Isi Data Demo', 'Reservasi', null, null, ['cabang' => $b['name']],
                'Reservasi contoh untuk data demo');

            /* Transaksi: dari awal bulan DEMO_MONTHS-1 bulan lalu SAMPAI HARI INI,
               3–5 transaksi per hari per cabang. Hari yang sudah punya transaksi
               di cabang ini DILEWATI, jadi pengisian ulang hanya melengkapi hari
               yang masih kosong dalam rentang tetap (tidak menggandakan). */
            $users = all('SELECT id, name, branch_id FROM users WHERE branch_id = ? AND status = "active"', [$bid]);
            $cashier = $users ? ['id' => (int)$users[0]['id'], 'name' => (string)$users[0]['name']] : $user;
            $methods = ['Cash', 'QRIS', 'Transfer', 'Debit'];
            /* PENTING: setTime(0,0) WAJIB. `first day of this month` masih memuat
               JAM saat ini (mis. 04:57), sehingga bila tidak dinolkan, tanggal
               dengan jam 04:57 tidak pernah "<= hari ini 00:00" dan HARI TERAKHIR
               (hari ini) terlewat — itulah sebabnya bulan berjalan bisa kosong. */
            $rentangTx = demo_date_range();
            $mulai = new DateTimeImmutable($rentangTx['mulai']);
            $hariIni = new DateTimeImmutable($rentangTx['akhir']);
            $hariList = [];
            for ($d = $mulai; $d <= $hariIni; $d = $d->modify('+1 day')) $hariList[] = $d->format('Y-m-d');
            foreach ($hariList as $tanggal) {
                /* Lewati hari yang sudah ada transaksinya (data demo lama ATAU
                   transaksi sungguhan) supaya tidak ada data kembar. */
                if ((int)scalar('SELECT COUNT(*) FROM orders WHERE branch_id = ? AND date(created_at) = ?', [$bid, $tanggal]) > 0) {
                    $out['hari_dilewati'] = (int)($out['hari_dilewati'] ?? 0) + 1;
                    continue;
                }
                /* Restok otomatis (seperti klinik membeli ulang produk & bahan):
                   tanpa ini stok contoh akan habis di tengah periode sehingga
                   transaksi demo berikutnya gagal karena stok tidak mencukupi. */
                foreach (all('SELECT * FROM skincare_products WHERE branch_id = ? AND status = "active"', [$bid]) as $sk) {
                    $min = max(5, (float)$sk['minimum_stock']);
                    if ((float)$sk['stock'] < $min * 2) {
                        inv_apply('skincare', (int)$sk['id'], $min * 6, 'Pembelian',
                            'Restok produk untuk data demo', ['ref_type' => 'demo']);
                        $out['restok'] = (int)($out['restok'] ?? 0) + 1;
                    }
                }
                foreach (all('SELECT * FROM treatment_materials WHERE branch_id = ? AND status = "active"', [$bid]) as $bh) {
                    $min = max(2, (float)$bh['minimum_stock']);
                    if ((float)$bh['stock'] < $min * 2) {
                        inv_apply('material', (int)$bh['id'], $min * 6, 'Pembelian',
                            'Restok bahan untuk data demo', ['ref_type' => 'demo']);
                        $out['restok'] = (int)($out['restok'] ?? 0) + 1;
                    }
                }
                $n = random_int(3, 5);
                for ($k = 0; $k < $n; $k++) {
                    $pid = $patientIds[$bid][array_rand($patientIds[$bid])];
                    $pat = one('SELECT member_card FROM patients WHERE id = ?', [$pid]);
                    $isMember = (int)($pat['member_card'] ?? 0) === 1;
                    $t = $trs[array_rand($trs)];
                    $price = (float)$t['promo_price'] > 0 ? (float)$t['promo_price'] : (float)$t['normal_price'];
                    $items = [['type' => 'treatment', 'id' => (int)$t['id'], 'qty' => random_int(1, 2), 'price' => $price]];
                    if (random_int(0, 1) === 1) {
                        $sk = $master[$bid]['sk'][array_rand($master[$bid]['sk'])];
                        $items[] = ['type' => 'skincare', 'id' => (int)$sk['id'], 'qty' => random_int(1, 2),
                                    'price' => (float)$sk['selling_price']];
                    }
                    if (random_int(1, 3) === 1) {
                        $bh = $master[$bid]['bh'][array_rand($master[$bid]['bh'])];
                        $qty = $bh['unit'] === 'liter' ? [0.5, 1, 1.5][random_int(0, 2)] : random_int(1, 4);
                        $items[] = ['type' => 'material', 'id' => (int)$bh['id'], 'qty' => $qty, 'price' => 0];
                    }
                    /* Sesekali jual PAKET (supaya menu Keuangan & laporan paket pada
                       data demo benar-benar berisi). Bila stok isi paket tidak cukup,
                       transaksi dilewati oleh order_create() dan dicatat sebagai warning. */
                    $pkgList = packages('', true, $bid);
                    if ($pkgList && random_int(1, 4) === 1) {
                        $pk = $pkgList[array_rand($pkgList)];
                        $items = [['type' => 'package', 'id' => (int)$pk['id'], 'qty' => 1, 'price' => (float)$pk['price']]];
                    }
                    $in = [
                        'patient_id' => $pid,
                        'items' => $items,
                        'method' => $methods[array_rand($methods)],
                        'paid_confirm' => '1',
                        'unique_code' => 0,
                        'member_card' => $isMember ? '1' : '0',
                        'member_scope' => $isMember ? ['treatment', 'skincare', 'both'][random_int(0, 2)] : '',
                        'notes' => 'Transaksi contoh data demo',
                    ];
                    $r = null;
                    try {
                        $r = order_create($in, $cashier, $bid);
                    } catch (Throwable $ex) {
                        /* Satu transaksi contoh yang gagal (mis. stok produk
                           kebetulan kosong) tidak boleh menggagalkan seluruh
                           pengisian data demo — dicatat lalu dilewati. */
                        $out['warnings'][] = 'Satu transaksi dilewati (' . $b['name'] . '): ' . $ex->getMessage();
                        if (count($out['warnings']) > 20) $out['warnings'] = array_slice($out['warnings'], 0, 20);
                        continue;
                    }
                    $oid = (int)$r['order_id'];
                    /* order_create() selalu memakai waktu SEKARANG, jadi tanggal
                       transaksi digeser ke hari yang sedang diisi. Jam dipatok dari
                       TANGGAL tersebut supaya tidak pernah jatuh ke hari berikutnya
                       (mis. dibuat pukul 17.00 WIB + 18 jam). */
                    $when = $tanggal . sprintf(' %02d:%02d:00', random_int(9, 20), random_int(0, 59));
                    q('UPDATE orders SET created_at = ?, updated_at = ? WHERE id = ?', [$when, $when, $oid]);
                    q('UPDATE payments SET paid_at = ?, created_at = ? WHERE order_id = ?', [$when, $when, $oid]);
                    q('UPDATE inventory_movements SET created_at = ? WHERE ref_type = "order" AND ref_id = ?', [$when, $oid]);
                    $out['transaksi']++;
                }
                $out['hari_terisi'] = (int)($out['hari_terisi'] ?? 0) + 1;
            }
            audit('Isi Data Demo', 'Kasir', null, null,
                ['cabang' => $b['name'], 'transaksi_baru' => $out['transaksi'],
                 'hari_terisi' => $out['hari_terisi'] ?? 0, 'hari_dilewati' => $out['hari_dilewati'] ?? 0],
                'Transaksi contoh ' . $rentangTx['mulai'] . ' s.d. ' . $rentangTx['akhir'] . ' untuk data demo');
        }
    } finally {
        if ($savedActive !== null) $_SESSION['active_branch'] = $savedActive;
        set_setting('email_receipt_auto', $emailAuto === '' ? '0' : $emailAuto);
    }
    return $out;
}
