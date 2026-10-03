<?php
/**
 * PAKET TREATMENT & PAKET PRODUK
 * ==============================
 *
 * Paket = sekumpulan item yang sudah ada di master (treatment, produk skincare,
 * dan/atau bahan treatment) yang dijual sebagai SATU kesatuan dengan harga
 * khusus paket, mis. "Paket Glowing 3x Facial + Serum".
 *
 * Aturan yang dijaga di sini:
 *  1. Komponen hanya boleh item milik CABANG yang sama (dan masih aktif).
 *  2. HPP paket = jumlah (HPP tiap komponen × jumlahnya). Nilai ini boleh
 *     disesuaikan manual oleh pemilik, tetapi tombol "Hitung Ulang HPP" selalu
 *     dapat mengembalikannya ke hasil hitungan komponen.
 *  3. Saat paket DIJUAL (order_create.php):
 *       - satu baris order_items bertipe `package` menyimpan harga & HPP paket,
 *       - setiap komponen dicatat sebagai baris `package_item` berharga 0
 *         (jejak pemakaian) SEKALIGUS mengurangi stok produk/bahan lewat
 *         inv_apply() — jadi inventory selalu konsisten,
 *       - pembatalan (void/refund/hapus permanen) mengembalikan stok komponen.
 *  4. Harga komponen TIDAK dihitung ulang saat transaksi; yang dipakai adalah
 *     harga paket (itu inti paket: harga khusus).
 */
declare(strict_types=1);

/** Label jenis paket. */
function package_kind_label(string $kind): string
{
    return $kind === 'product' ? 'Paket Produk' : 'Paket Treatment';
}

/** Halaman master yang mengelola paket ini (tombol "+" berada di sana). */
function package_kind_page(string $kind): string
{
    return $kind === 'product' ? 'skincare.php' : 'treatment.php';
}

/** Daftar paket (dibatasi cakupan cabang bila diminta). */
function packages(string $kind = '', bool $onlyActive = false, ?int $branchId = null): array
{
    $w = [];
    $p = [];
    if (in_array($kind, ['treatment', 'product'], true)) { $w[] = 'p.kind = ?'; $p[] = $kind; }
    if ($onlyActive) $w[] = "p.status = 'active'";
    if ($branchId !== null) { $w[] = 'p.branch_id = ?'; $p[] = $branchId; }
    return all('SELECT p.*, b.name AS branch_name,
                       (SELECT COUNT(*) FROM package_items pi WHERE pi.package_id = p.id) AS item_count
                FROM packages p JOIN branches b ON b.id = p.branch_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.name', $p);
}

/** Satu paket (dengan nama cabang). */
function package_get(int $id): ?array
{
    return one('SELECT p.*, b.name AS branch_name FROM packages p JOIN branches b ON b.id = p.branch_id
                WHERE p.id = ?', [$id]);
}

/**
 * Komponen sebuah paket, lengkap dengan nama/harga/HPP itemnya (dari master).
 * @return array<int,array{item_type:string,item_id:int,quantity:float,name:string,code:string,
 *                          unit:string,price:float,hpp:float,line_price:float,line_hpp:float,active:bool}>
 */
function package_items(int $packageId): array
{
    $rows = all('SELECT * FROM package_items WHERE package_id = ? ORDER BY position ASC, id ASC', [$packageId]);
    $out = [];
    foreach ($rows as $r) {
        $type = (string)$r['item_type'];
        $id = (int)$r['item_id'];
        $qty = (float)$r['quantity'];
        $src = null;
        if ($type === 'treatment') $src = one('SELECT name, code, COALESCE(normal_price,0) price, COALESCE(hpp,0) hpp,
                                                      "kali" unit, status FROM treatments WHERE id = ?', [$id]);
        elseif ($type === 'skincare') $src = one('SELECT name, code, COALESCE(selling_price,0) price,
                                                         COALESCE(purchase_price,0) hpp, COALESCE(unit,"pcs") unit,
                                                         status FROM skincare_products WHERE id = ?', [$id]);
        elseif ($type === 'material') $src = one('SELECT name, code, COALESCE(price,0) price, COALESCE(price,0) hpp,
                                                         COALESCE(unit,"pcs") unit, status FROM treatment_materials WHERE id = ?', [$id]);
        $out[] = [
            'item_type' => $type, 'item_id' => $id, 'quantity' => $qty,
            'name' => (string)($src['name'] ?? '(item dihapus)'),
            'code' => (string)($src['code'] ?? ''), 'unit' => (string)($src['unit'] ?? ''),
            'price' => (float)($src['price'] ?? 0), 'hpp' => (float)($src['hpp'] ?? 0),
            'line_price' => round((float)($src['price'] ?? 0) * $qty, 2),
            'line_hpp' => round((float)($src['hpp'] ?? 0) * $qty, 2),
            'active' => ($src['status'] ?? 'active') === 'active',
        ];
    }
    return $out;
}

/** HPP paket = jumlah HPP komponen × jumlahnya (pembulatan rupiah). */
function package_hpp_calc(int $packageId): float
{
    $sum = 0.0;
    foreach (package_items($packageId) as $it) $sum += $it['line_hpp'];
    return round($sum, 2);
}

/** Harga normal paket = jumlah harga komponen (sebagai usulan awal). */
function package_price_sum(int $packageId): float
{
    $sum = 0.0;
    foreach (package_items($packageId) as $it) $sum += $it['line_price'];
    return round($sum, 2);
}

/** Simpan komponen paket (mengganti seluruh komponen yang lama). */
function package_items_save(int $packageId, array $items): int
{
    q('DELETE FROM package_items WHERE package_id = ?', [$packageId]);
    $pos = 0;
    $n = 0;
    foreach ($items as $it) {
        $type = (string)($it['type'] ?? '');
        if (!in_array($type, ['treatment', 'skincare', 'material'], true)) continue;
        $id = (int)($it['id'] ?? 0);
        $qty = qty_parse($it['qty'] ?? 0);
        if ($id <= 0 || $qty <= 0) continue;
        q('INSERT INTO package_items (package_id, item_type, item_id, quantity, position) VALUES (?,?,?,?,?)',
            [$packageId, $type, $id, $qty, $pos++]);
        $n++;
    }
    return $n;
}

/**
 * Awalan kode paket menurut JENISNYA (permintaan pemilik: supaya Paket Treatment
 * dan Paket Produk mudah dibedakan dari kodenya):
 *   • Paket Treatment → `PTR-<KODE CABANG>-1001001`
 *   • Paket Produk    → `PSK-<KODE CABANG>-1001001`
 * Seri 7 angka mulai 1001001 dan berjalan TERPISAH per jenis + per cabang,
 * sehingga nomor paket treatment tidak "memakan" nomor paket produk.
 */
function package_code_prefix(string $kind): string
{
    return $kind === 'product' ? 'PSK' : 'PTR';
}

/** Nomor paket otomatis: <PTR|PSK>-<KODE CABANG>-<7 angka mulai 1001001>. */
function next_package_number(int $branchId, string $kind = 'treatment'): string
{
    $code = (string)scalar('SELECT code FROM branches WHERE id = ?', [$branchId], 'XX');
    $prefix = package_code_prefix($kind) . '-' . $code . '-';
    /* Awalan LAMA (`PKT-`) tetap dihitung supaya seri paket yang sudah dibuat
       tidak pernah menerbitkan nomor kembar setelah perubahan awalan ini. */
    $legacyPkt = 'PKT-' . $code . '-%';
    $legacyNew = $prefix . '%';
    /* seq_next($scanLike, $outPrefix, $table, $column, $pad, $start, $legacy) —
       URUTANNYA beda dari dugaan (kolom sebelum pad). */
    return seq_next($legacyNew, $prefix, 'packages', 'code', 7, 1001001, [$legacyPkt]);
}

/**
 * Kebutuhan stok komponen paket untuk $qty paket.
 * @return array<int,array{item_type:string,item_id:int,name:string,need:float,stock:float,unit:string}>
 */
function package_stock_needs(int $packageId, float $qty): array
{
    $need = [];
    foreach (package_items($packageId) as $it) {
        if (!in_array($it['item_type'], ['skincare', 'material'], true)) continue;
        $key = $it['item_type'] . ':' . $it['item_id'];
        if (!isset($need[$key])) {
            $need[$key] = ['item_type' => $it['item_type'], 'item_id' => $it['item_id'],
                'name' => $it['name'], 'need' => 0.0, 'stock' => 0.0, 'unit' => $it['unit']];
        }
        $need[$key]['need'] += $it['quantity'] * $qty;
    }
    foreach ($need as $k => $v) {
        $table = $v['item_type'] === 'skincare' ? 'skincare_products' : 'treatment_materials';
        $need[$k]['stock'] = (float)scalar("SELECT COALESCE(stock,0) FROM {$table} WHERE id = ?", [$v['item_id']], 0);
    }
    return array_values($need);
}

/**
 * Kurangi/ kembalikan stok komponen paket.
 * $sign = -1 (dijual / dipakai) atau +1 (dibatalkan / dikembalikan).
 */
function package_apply_stock(int $packageId, float $qty, int $sign, string $reason, array $extra = []): int
{
    $n = 0;
    foreach (package_items($packageId) as $it) {
        if (!in_array($it['item_type'], ['skincare', 'material'], true)) continue;
        $delta = $sign * $it['quantity'] * $qty;
        if (abs($delta) < 0.000001) continue;
        inv_apply($it['item_type'], (int)$it['item_id'], $delta,
            $sign < 0 ? 'Pemakaian Internal' : 'Return', $reason, $extra);
        $n++;
    }
    return $n;
}

/** Apakah paket sudah dipakai transaksi (tidak boleh dihapus permanen)? */
function package_used(int $packageId): bool
{
    return (int)scalar('SELECT COUNT(*) FROM order_items WHERE package_id = ?', [$packageId], 0) > 0;
}
