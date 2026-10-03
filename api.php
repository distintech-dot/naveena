<?php
/** Lightweight JSON endpoints for autocomplete / lookups. */
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json');

/* Status pemeliharaan boleh dicek tanpa login: halaman pemeliharaan
   memakainya untuk mengarahkan pengunjung kembali ke halaman masuk. */
if ((string)($_GET['a'] ?? '') === 'maintenance_status') {
    echo json_encode([
        'ok' => true,
        'maintenance' => maintenance_on(),
        'title' => maintenance_info()['title'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$u = require_login();

$a = (string)gp('a');
$term = '%' . gp('q') . '%';

/** Branch used for master-data lookups (super admin may target a branch). */
$branch = is_owner_level() ? (int)gp('branch', (int)(user_branch() ?? 0)) : (int)user_branch();
$out = ['ok' => true, 'items' => []];

try {
    switch ($a) {
        case 'patient':
            require_perm('patient.view');
            /* FILTER CABANG (perbaikan ronde 37): daftar pasien mengikuti CABANG
               YANG DIPILIH pada halaman (parameter `branch`), bukan cakupan akun.
               Sebelumnya Super Admin/Direktur selalu melihat pasien SEMUA cabang
               walau sudah memilih cabang Kaliwungu — akibatnya pasien Cepiring
               bisa terpilih di Reservasi/Order Baru/Rekam Medis cabang Kaliwungu.
               Akun yang terpaku satu cabang tetap terkunci ke cabangnya
               (branch_sql() dipakai sebagai pembatas tambahan). */
            $pb = (int)gp('branch', 0);
            [$bs, $bp] = branch_sql('p.branch_id');
            if ($pb > 0) {
                assert_branch($pb);                       // non-owner tidak boleh cabang lain
                $bs = ' AND p.branch_id = ?';
                $bp = [$pb];
            }
            $rows = all("SELECT p.*, b.name AS branch_name FROM patients p JOIN branches b ON b.id=p.branch_id
                         WHERE p.status='active' {$bs}
                           AND (p.name LIKE ? OR p.nik LIKE ? OR p.phone LIKE ? OR p.email LIKE ?
                                OR p.patient_number LIKE ? OR p.member_number LIKE ?)
                         ORDER BY p.name LIMIT 20",
                array_merge($bp, [$term, $term, $term, $term, $term, $term]));
            foreach ($rows as $r) {
                $out['items'][] = [
                    'id' => (int)$r['id'],
                    'label' => '<strong>' . e($r['name']) . '</strong>'
                        . ((int)($r['member_card'] ?? 0) === 1 ? ' <span class="badge badge-yellow">MEMBER</span>' : '')
                        . '<small>' . e($r['patient_number']) . ' · ' . e($r['phone'] ?: '-') . ' · ' . e($r['branch_name']) . ' · ' . e($r['patient_type']) . '</small>',
                    'name' => $r['name'], 'number' => $r['patient_number'], 'phone' => $r['phone'],
                    'email' => (string)($r['email'] ?? ''),
                    'member' => $r['member_number'], 'branch_id' => (int)$r['branch_id'], 'type' => $r['patient_type'],
                    /* Nama cabang pasien dipakai Order Baru untuk memberi tahu
                       (dan berpindah) bila pasien berasal dari cabang lain. */
                    'branch_name' => (string)($r['branch_name'] ?? ''),
                    'is_member' => (int)($r['member_card'] ?? 0) === 1,
                    /* Akumulasi periode berjalan dipakai Order Baru untuk menampilkan
                       level & diskon pasien YANG BARU DIPILIH (sebelumnya hanya data
                       pasien yang tampil saat halaman dimuat, sehingga pilihan cakupan
                       diskon & diskon tidak ikut berubah setelah memilih pasien lain). */
                    'year_total' => (int)($r['member_card'] ?? 0) === 1 ? member_period_total((int)$r['id']) : 0.0,
                ];
            }
            break;

        case 'treatment':
            require_perm('treatment.view');
            $rows = all("SELECT t.*, b.name AS branch_name FROM treatments t JOIN branches b ON b.id=t.branch_id
                         WHERE t.status='active' AND t.branch_id = ? AND (t.name LIKE ? OR t.code LIKE ? OR t.category LIKE ?)
                         ORDER BY t.name LIMIT 20", [$branch, $term, $term, $term]);
            foreach ($rows as $r) {
                $price = (float)$r['promo_price'] > 0 ? (float)$r['promo_price'] : (float)$r['normal_price'];
                $out['items'][] = [
                    'id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'],
                    'price' => $price, 'normal_price' => (float)$r['normal_price'], 'promo_price' => (float)$r['promo_price'],
                    'duration' => (int)$r['duration'], 'branch_id' => (int)$r['branch_id'],
                    'label' => '<strong>' . e($r['name']) . '</strong><small>' . e($r['code']) . ' · ' . e($r['category']) . ' · ' . money($price) . ' · ' . num($r['duration']) . ' menit</small>',
                ];
            }
            break;

        case 'skincare':
            require_perm('skincare.view');
            $rows = all("SELECT s.* FROM skincare_products s WHERE s.status='active' AND s.branch_id = ?
                         AND (s.name LIKE ? OR s.code LIKE ? OR s.category LIKE ?) ORDER BY s.name LIMIT 20", [$branch, $term, $term, $term]);
            foreach ($rows as $r) {
                $out['items'][] = [
                    'id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'],
                    'price' => (float)$r['selling_price'], 'stock' => (float)$r['stock'], 'unit' => $r['unit'],
                    'label' => '<strong>' . e($r['name']) . '</strong><small>' . e($r['code']) . ' · stok ' . qty_text($r['stock']) . ' ' . e($r['unit']) . ' · ' . money($r['selling_price']) . '</small>',
                ];
            }
            break;

        case 'package':
            /* Pencarian PAKET untuk Order Baru (paket treatment & paket produk). */
            if (!has_perm('treatment.view') && !has_perm('skincare.view')) {
                deny('Anda tidak memiliki akses ke paket.');
            }
            $rows = all("SELECT p.*, b.name AS branch_name FROM packages p JOIN branches b ON b.id = p.branch_id
                         WHERE p.status='active' AND p.branch_id = ? AND (p.name LIKE ? OR p.code LIKE ?)
                         ORDER BY p.name LIMIT 20", [$branch, $term, $term]);
            foreach ($rows as $r) {
                $comps = package_items((int)$r['id']);
                $hppAuto = (float)$r['hpp'] > 0 ? (float)$r['hpp'] : package_hpp_calc((int)$r['id']);
                $out['items'][] = [
                    'id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'],
                    'kind' => (string)$r['kind'], 'price' => (float)$r['price'], 'hpp' => $hppAuto,
                    'branch_id' => (int)$r['branch_id'], 'components' => array_map(fn($c) => [
                        'type' => $c['item_type'], 'name' => $c['name'], 'qty' => (float)$c['quantity'],
                        'unit' => $c['unit'],
                    ], $comps),
                    'label' => '<strong>' . e($r['name']) . '</strong> <span class="badge badge-blue">'
                        . e($r['kind'] === 'product' ? 'PAKET PRODUK' : 'PAKET TREATMENT') . '</span>'
                        . '<small>' . e($r['code']) . ' · ' . num(count($comps)) . ' item · ' . money($r['price']) . '</small>',
                ];
            }
            break;

        case 'material':
            require_perm('material.view');
            $rows = all("SELECT m.* FROM treatment_materials m WHERE m.status='active' AND m.branch_id = ?
                         AND (m.name LIKE ? OR m.code LIKE ? OR m.category LIKE ?) ORDER BY m.name LIMIT 20", [$branch, $term, $term, $term]);
            foreach ($rows as $r) {
                $out['items'][] = [
                    'id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'],
                    'stock' => (float)$r['stock'], 'unit' => $r['unit'], 'price' => (float)$r['price'],
                    'label' => '<strong>' . e($r['name']) . '</strong><small>' . e($r['code']) . ' · stok ' . qty_text($r['stock']) . ' ' . e($r['unit']) . '</small>',
                ];
            }
            break;

        case 'icd10':
        case 'icd9cm':
            // Kamus ICD bersifat global (bukan data cabang), hanya butuh login.
            $kind = $a === 'icd9cm' ? 'icd9cm' : 'icd10';
            if (!has_perm('medical.view') && !has_perm('medical.manage')) {
                deny('Anda tidak memiliki akses ke kamus ICD.');
            }
            $rows = icd_search($kind, (string)gp('q'), (int)gp('limit', 25));
            foreach ($rows as $r) {
                $out['items'][] = [
                    'id' => (int)$r['id'],
                    'code' => $r['code'],
                    'code_norm' => $r['code_norm'],
                    'name' => $r['name_id'] ?: $r['name_en'],
                    'name_en' => $r['name_en'],
                    'name_id' => $r['name_id'],
                    'kind' => $kind,
                    'label' => '<strong>' . e($r['code']) . '</strong> — ' . e($r['name_id'] ?: $r['name_en'])
                        . (empty($r['name_id']) ? ' <small>(judul resmi Inggris)</small>'
                            : (($r['name_en'] && strcasecmp($r['name_id'], $r['name_en']) !== 0)
                                ? '<small>' . e($r['name_en']) . '</small>' : '')),
                ];
            }
            break;

        case 'icd_check':
            $kind = gp('kind') === 'icd9cm' ? 'icd9cm' : 'icd10';
            $row = icd_lookup($kind, (string)gp('code'));
            $out['found'] = (bool)$row;
            $out['item'] = $row ? ['code' => $row['code'], 'name' => $row['name_id'] ?: $row['name_en']] : null;
            break;

        case 'patient_detail':
            require_perm('patient.view');
            $p = one('SELECT p.*, b.name AS branch_name FROM patients p JOIN branches b ON b.id=p.branch_id WHERE p.id = ?', [(int)gp('id')]);
            if (!$p) throw new RuntimeException('Pasien tidak ditemukan.');
            assert_branch((int)$p['branch_id']);
            $out['item'] = $p;
            break;

        case 'stock_alerts':
            require_perm('inventory.view');
            $out['items'] = stock_alerts(50);
            break;

        /* Status mode pemeliharaan — dipakai halaman pemeliharaan untuk
           mengarahkan pengguna kembali begitu pemeliharaan selesai. */
        /* ===== DAFTAR ACUAN FORMULIR RESERVASI UNTUK SATU CABANG =====
           Dipakai saat pemilik memindahkan kolom "Cabang" pada modal Reservasi:
           pilihan dokter, terapis, dan treatment langsung dimuat ulang agar
           hanya memuat data cabang tersebut (ronde 36). */
        case 'res_form':
            require_perm('reservation.manage');
            $bid = (int)gp('branch', (int)($branch ?: 0));
            if ($bid <= 0) { $out['error'] = 'Cabang belum dipilih.'; break; }
            assert_branch($bid);
            $isOwner = is_owner_level();
            $out['branch'] = $bid;
            $out['branch_name'] = (string)scalar('SELECT name FROM branches WHERE id = ?', [$bid], '');
            $out['treatments'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
                all('SELECT id, name FROM treatments WHERE status="active" AND branch_id = ? ORDER BY name', [$bid]));
            $out['doctors'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
                all('SELECT id, name FROM doctors WHERE status="active" AND branch_id = ? ORDER BY name', [$bid]));
            $out['therapists'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
                all('SELECT id, name FROM therapists WHERE status="active" AND branch_id = ? ORDER BY name', [$bid]));
            /* Pemilik boleh memakai cabang mana pun; akun terpaku satu cabang hanya
               boleh cabangnya sendiri (assert_branch sudah menjaga hal itu). */
            $out['owner_level'] = $isOwner;
            break;

        case 'maintenance_status':
            $out['maintenance'] = maintenance_on();
            $out['readonly'] = maintenance_readonly();
            break;

        default:
            $out = ['ok' => false, 'error' => 'Aksi tidak dikenal.'];
    }
} catch (Throwable $ex) {
    http_response_code(400);
    $out = ['ok' => false, 'error' => $ex->getMessage()];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
