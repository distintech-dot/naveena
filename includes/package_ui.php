<?php
/**
 * UI BERSAMA UNTUK PAKET TREATMENT & PAKET PRODUK.
 *
 * Dipakai oleh `treatment.php` (jenis paket = treatment) dan `skincare.php`
 * (jenis paket = product) sehingga tombol "+ Paket …", modal penyusun paket,
 * serta daftar paketnya sama persis di kedua halaman.
 *
 * Modal menyusun paket dari item yang SUDAH ADA di inventory (treatment,
 * produk skincare, dan/atau bahan treatment), menghitung HPP serta harga usulan
 * dari komponen, dan menyimpannya ke tabel `packages` + `package_items`.
 */
declare(strict_types=1);

/** Jenis paket dari halaman yang memanggil. */
function package_ui_kind(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'skincare.php' ? 'product' : 'treatment';
}

/**
 * Pilihan komponen yang boleh dipakai paket ini.
 *
 * PENTING (perbaikan): pilihan HARUS dibatasi cakupan cabang pengguna. Sebelumnya
 * daftar ini selalu memuat item SELURUH cabang, sehingga Admin/Dokter atau Kasir
 * yang hanya berwenang di satu cabang melihat item cabang lain (dan bisa
 * menyusun paket lintas cabang — yang kemudian ditolak server). Untuk level owner
 * (Super Admin & Direktur) semua cabang tetap ditampilkan karena mereka memang
 * dapat memilih cabang paketnya.
 */
function package_ui_options(string $kind): array
{
    $scope = scope_branch();                       // null = semua cabang (owner)
    /* Filter cabang ditulis per tabel supaya tidak bergantung alias yang sama. */
    $where = function (string $alias) use ($scope): array {
        return $scope === null ? ['', []] : [' AND ' . $alias . '.branch_id = ?', [(int)$scope]];
    };
    $out = [];
    if ($kind === 'product') {
        [$wSql, $wP] = $where('s');
        $out[] = ['group' => 'Produk Skincare', 'type' => 'skincare',
            'rows' => all('SELECT s.id, s.code, s.name, s.branch_id, b.name AS branch_name,
                                  COALESCE(s.selling_price,0) AS price, COALESCE(s.purchase_price,0) AS hpp,
                                  COALESCE(s.unit,"pcs") AS unit, s.status
                           FROM skincare_products s JOIN branches b ON b.id = s.branch_id
                           WHERE 1=1' . $wSql . '
                           ORDER BY b.name, s.name', $wP)];
    } else {
        [$tSql, $tP] = $where('t');
        $out[] = ['group' => 'Treatment', 'type' => 'treatment',
            'rows' => all('SELECT t.id, t.code, t.name, t.branch_id, b.name AS branch_name,
                                  COALESCE(t.normal_price,0) AS price, COALESCE(t.hpp,0) AS hpp,
                                  "kali" AS unit, t.status
                           FROM treatments t JOIN branches b ON b.id = t.branch_id
                           WHERE 1=1' . $tSql . '
                           ORDER BY b.name, t.name', $tP)];
        [$mSql, $mP] = $where('m');
        $out[] = ['group' => 'Bahan Treatment (opsional)', 'type' => 'material',
            'rows' => all('SELECT m.id, m.code, m.name, m.branch_id, b.name AS branch_name,
                                  COALESCE(m.price,0) AS price, COALESCE(m.price,0) AS hpp,
                                  COALESCE(m.unit,"pcs") AS unit, m.status
                           FROM treatment_materials m JOIN branches b ON b.id = m.branch_id
                           WHERE 1=1' . $mSql . '
                           ORDER BY b.name, m.name', $mP)];
    }
    return $out;
}

/**
 * Tangani POST paket (save / delete / toggle). Dipanggil dari halaman master.
 * @return bool true bila permintaan ini memang untuk paket (sudah ditangani).
 */
function package_ui_handle_post(string $kind): bool
{
    $act = (string)($_POST['action'] ?? '');
    if (!in_array($act, ['pkg_save', 'pkg_delete', 'pkg_toggle'], true)) return false;
    require_perm($kind === 'product' ? 'skincare.manage' : 'treatment.manage');

    if ($act === 'pkg_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pkg = package_get($id);
        if (!$pkg) throw new RuntimeException('Paket tidak ditemukan.');
        if ((string)$pkg['kind'] !== $kind) throw new RuntimeException('Jenis paket tidak sesuai halaman ini.');
        assert_branch((int)$pkg['branch_id']);
        if (package_used($id)) {
            throw new RuntimeException('Paket "' . $pkg['name'] . '" sudah pernah dipakai pada transaksi sehingga tidak dapat dihapus. Nonaktifkan saja paket ini.');
        }
        q('DELETE FROM package_items WHERE package_id = ?', [$id]);
        q('DELETE FROM packages WHERE id = ?', [$id]);
        audit('Hapus Paket', 'Master Data', $id, $pkg, null, 'Paket ' . package_kind_label($kind) . ' dihapus (belum pernah dipakai transaksi)');
        flash('Paket "' . $pkg['name'] . '" dihapus.');
        return true;
    }

    if ($act === 'pkg_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pkg = package_get($id);
        if (!$pkg) throw new RuntimeException('Paket tidak ditemukan.');
        assert_branch((int)$pkg['branch_id']);
        $new = ($pkg['status'] === 'active') ? 'inactive' : 'active';
        q('UPDATE packages SET status = ?, updated_at = datetime("now","localtime") WHERE id = ?', [$new, $id]);
        audit('Ubah Status Paket', 'Master Data', $id, ['status' => $pkg['status']], ['status' => $new],
            'Status paket diubah');
        flash('Paket "' . $pkg['name'] . '" kini ' . ($new === 'active' ? 'AKTIF (dapat dijual di kasir)' : 'NONAKTIF (tidak muncul di kasir)') . '.');
        return true;
    }

    /* ---- simpan (baru atau ubah) ---- */
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Nama paket wajib diisi.');
    if ((int)preg_match_all('/./us', $name) > 80) throw new RuntimeException('Nama paket maksimal 80 karakter.');
    $branch = is_owner_level() ? resolve_branch_input($_POST['branch_id'] ?? 0) : (int)current_user()['branch_id'];
    assert_branch($branch);
    $price = max(0.0, qty_parse($_POST['price'] ?? 0));
    $hppIn = qty_parse($_POST['hpp'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    /* Komponen dikirim sebagai tiga array sejajar. */
    $types = (array)($_POST['item_type'] ?? []);
    $ids = (array)($_POST['item_id'] ?? []);
    $qtys = (array)($_POST['item_qty'] ?? []);
    $items = [];
    foreach ($ids as $i => $iidRaw) {
        $items[] = ['type' => (string)($types[$i] ?? ''), 'id' => (int)$iidRaw, 'qty' => $qtys[$i] ?? 0];
    }

    /* Verifikasi setiap komponen: ada, milik cabang yang sama, dan aktif. */
    $valid = [];
    foreach ($items as $it) {
        if ($it['id'] <= 0) continue;
        $tbl = $it['type'] === 'treatment' ? 'treatments'
            : ($it['type'] === 'skincare' ? 'skincare_products' : ($it['type'] === 'material' ? 'treatment_materials' : ''));
        if ($tbl === '') throw new RuntimeException('Jenis komponen paket tidak dikenal.');
        if ($kind === 'product' && $it['type'] !== 'skincare') {
            throw new RuntimeException('Paket produk hanya boleh berisi produk skincare.');
        }
        if ($kind === 'treatment' && $it['type'] === 'skincare') {
            throw new RuntimeException('Paket treatment tidak boleh berisi produk skincare — gunakan Paket Produk.');
        }
        $row = one("SELECT id, name, branch_id, status FROM {$tbl} WHERE id = ?", [$it['id']]);
        if (!$row) throw new RuntimeException('Komponen paket tidak ditemukan (mungkin sudah dihapus).');
        if ((int)$row['branch_id'] !== $branch) {
            throw new RuntimeException('Komponen "' . $row['name'] . '" milik cabang lain. Paket dan isinya harus cabang yang sama.');
        }
        if ($row['status'] !== 'active') {
            throw new RuntimeException('Komponen "' . $row['name'] . '" sedang nonaktif — aktifkan dulu atau keluarkan dari paket.');
        }
        $valid[] = $it;
    }
    if (!$valid) throw new RuntimeException('Paket harus berisi minimal satu item.');

    if ($id > 0) {
        $old = package_get($id);
        if (!$old) throw new RuntimeException('Paket tidak ditemukan.');
        if ((string)$old['kind'] !== $kind) throw new RuntimeException('Jenis paket tidak sesuai halaman ini.');
        assert_branch((int)$old['branch_id']);
        q('UPDATE packages SET name=?, price=?, hpp=?, note=?, branch_id=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
            [$name, $price, $hppIn, $note, $branch, $status, $id]);
        $n = package_items_save($id, $valid);
        /* HPP boleh 0 (mis. belum diisi) → hitung dari komponen sebagai cadangan. */
        $hppFinal = $hppIn > 0 ? $hppIn : package_hpp_calc($id);
        if (abs($hppFinal - $hppIn) > 0.004) q('UPDATE packages SET hpp = ? WHERE id = ?', [$hppFinal, $id]);
        audit('Edit Paket', 'Master Data', $id,
            ['nama' => $old['name'], 'harga' => (float)$old['price'], 'hpp' => (float)$old['hpp'], 'komponen' => (int)$old['item_count']],
            ['nama' => $name, 'harga' => $price, 'hpp' => $hppFinal, 'komponen' => $n],
            'Paket ' . package_kind_label($kind) . ' diperbarui');
        flash('Paket "' . $name . '" diperbarui (' . $n . ' komponen, harga ' . money($price) . ', HPP ' . money($hppFinal) . ').');
    } else {
        $code = next_package_number($branch, $kind);
        q('INSERT INTO packages (code, name, kind, price, hpp, note, branch_id, status, created_at)
           VALUES (?,?,?,?,?,?,?,?,datetime("now","localtime"))',
            [$code, $name, $kind, $price, 0, $note, $branch, $status]);
        $newId = (int)db()->lastInsertId();
        $n = package_items_save($newId, $valid);
        $hppFinal = $hppIn > 0 ? $hppIn : package_hpp_calc($newId);
        q('UPDATE packages SET hpp = ? WHERE id = ?', [$hppFinal, $newId]);
        audit('Tambah Paket', 'Master Data', $newId, null,
            ['kode' => $code, 'nama' => $name, 'jenis' => $kind, 'harga' => $price, 'hpp' => $hppFinal, 'komponen' => $n],
            'Paket ' . package_kind_label($kind) . ' baru: ' . $n . ' komponen');
        flash('Paket ' . package_kind_label($kind) . ' "' . $name . '" ditambahkan (' . $code . ' · ' . $n
            . ' komponen · harga ' . money($price) . ' · HPP ' . money($hppFinal) . '). Paket langsung dapat dipilih di Order Baru.');
    }
    return true;
}

/**
 * Satu baris komponen pada modal paket.
 * $it = null berarti baris KOSONG (dipakai untuk paket baru) — tanpa baris ini
 * pengguna tidak bisa menambahkan item sama sekali karena tombol "+ Tambah Baris"
 * bekerja dengan menggandakan baris yang ada.
 */
function package_ui_row(array $opts, ?array $it = null): string
{
    $type = $it['item_type'] ?? ($opts[0]['type'] ?? 'treatment');
    $id = (int)($it['item_id'] ?? 0);
    $qty = $it ? qty_text($it['quantity']) : '1';
    $unit = (string)($it['unit'] ?? '');
    $price = (float)($it['price'] ?? 0);
    $sub = (float)($it['line_price'] ?? 0);
    $h = '<tr class="pkg-row">'
      . '<td><select class="input input-sm pkg-type" name="item_type[]" onchange="pkgRowChange(this)">';
    foreach ($opts as $g) {
        $h .= '<option value="' . e($g['type']) . '"' . ($type === $g['type'] ? ' selected' : '') . '>'
            . e($g['group']) . '</option>';
    }
    $h .= '</select></td><td><select class="input input-sm pkg-item" name="item_id[]" onchange="pkgRowChange(this)">';
    foreach ($opts as $g) {
        foreach ($g['rows'] as $r) {
            $sel = ($type === $g['type'] && $id === (int)$r['id']) ? ' selected' : '';
            $h .= '<option value="' . (int)$r['id'] . '" data-type="' . e($g['type']) . '" data-branch="' . (int)$r['branch_id']
                . '" data-price="' . (float)$r['price'] . '" data-hpp="' . (float)$r['hpp'] . '" data-unit="' . e($r['unit']) . '"' . $sel . '>'
                . e($r['branch_name'] . ' — ' . $r['code'] . ' — ' . $r['name']) . ($r['status'] === 'active' ? '' : ' (nonaktif)')
                . '</option>';
        }
    }
    $h .= '</select></td>'
      . '<td><input class="input input-sm pkg-qty" type="text" inputmode="decimal" name="item_qty[]" value="' . e($qty) . '" oninput="pkgRecalc()">'
      . '<span class="small muted pkg-unit">' . e($unit) . '</span></td>'
      . '<td class="num pkg-price">' . money($price) . '</td>'
      . '<td class="num pkg-sub">' . money($sub) . '</td>'
      . '<td><button type="button" class="btn btn-sm btn-danger" onclick="pkgRemoveRow(this)">×</button></td></tr>';
    return $h;
}

/** Tombol "+ Paket Treatment/Produk" untuk halaman master. */
function package_ui_button(string $kind): string
{
    $perm = $kind === 'product' ? has_perm('skincare.manage') : has_perm('treatment.manage');
    if (!$perm) return '';
    return '<button class="btn btn-leaf" data-modal-open="pkgModal" onclick="resetPkgForm()">'
        . icon('plus-circle') . ' Paket ' . ($kind === 'product' ? 'Produk' : 'Treatment') . '</button>';
}

/**
 * Kartu daftar paket + modal penyusun. $kind = 'treatment' | 'product'.
 * Semua data komponen sudah tertanam di modal (dengan data-branch) sehingga
 * berpindah cabang cukup memfilter pilihan tanpa memuat ulang halaman.
 */
function package_ui_card(string $kind): void
{
    $scope = scope_branch();
    $list = packages($kind, false, $scope !== null ? (int)$scope : null);
    $opts = package_ui_options($kind);
    $editId = (int)gp('pkg');
    $edit = $editId > 0 ? package_get($editId) : null;
    if ($edit && (string)$edit['kind'] !== $kind) $edit = null;
    $editItems = $edit ? package_items((int)$edit['id']) : [];
    $canManage = $kind === 'product' ? has_perm('skincare.manage') : has_perm('treatment.manage');
    $title = 'Paket ' . ($kind === 'product' ? 'Produk' : 'Treatment');
    ?>
<div class="card mt-2" id="paket">
  <div class="card-head">
    <h3><?= e($title) ?></h3>
    <span class="muted"><?= num(count($list)) ?> paket · dijual di Order Baru, stok isi paket ikut berkurang</span>
  </div>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="notice">
      Paket berisi <strong>item yang sudah ada di inventory</strong> (<?= $kind === 'product'
        ? 'produk skincare' : 'treatment dan/atau bahan treatment' ?>). Saat paket dipilih di kasir,
      setiap komponennya <strong>otomatis mengurangi stok</strong> di inventory, dan harga paket yang
      ditagihkan adalah <strong>harga paket</strong> (bukan jumlah harga komponen).
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Nama Paket</th><th class="num">Isi</th><th class="num">Harga Paket</th>
        <th class="num">HPP</th><th class="num">Margin</th><th>Cabang</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($list as $pk): $margin = (float)$pk['price'] > 0
            ? ((float)$pk['price'] - (float)$pk['hpp']) / (float)$pk['price'] * 100 : null; ?>
          <tr>
            <td class="small nowrap"><?= e($pk['code']) ?></td>
            <td><strong><?= e($pk['name']) ?></strong>
              <?php if ($pk['note'] !== '' && $pk['note'] !== null): ?>
                <div class="small muted"><?= e(short_text((string)$pk['note'], 70)) ?></div>
              <?php endif; ?></td>
            <td class="num"><?= num((int)$pk['item_count']) ?> item
              <div class="small muted">
                <?php foreach (package_items((int)$pk['id']) as $ci): ?>
                  <div><?= e(short_text($ci['name'], 26)) ?> × <?= qty_text($ci['quantity']) ?></div>
                <?php endforeach; ?>
              </div></td>
            <td class="num"><strong><?= money((float)$pk['price']) ?></strong></td>
            <td class="num"><?= money((float)$pk['hpp']) ?></td>
            <td class="num"><?= $margin !== null ? num($margin, 1) . '%' : '-' ?></td>
            <td class="small"><?= e(branch_short_label((string)$pk['branch_name'])) ?></td>
            <td><?= badge($pk['status'] === 'active' ? 'Aktif' : 'Nonaktif', $pk['status'] === 'active' ? 'green' : 'gray') ?></td>
            <td class="nowrap">
              <?php if ($canManage): ?>
              <div class="row-actions">
                <a class="btn btn-sm" href="?pkg=<?= (int)$pk['id'] ?>#paket">Edit</a>
                <form method="post" class="inline-form" data-confirm="Ubah status paket ini?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="pkg_toggle">
                  <input type="hidden" name="id" value="<?= (int)$pk['id'] ?>">
                  <button class="btn btn-sm" type="submit"><?= $pk['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" class="inline-form"
                      data-confirm="Hapus paket &quot;<?= e($pk['name']) ?>&quot;? Paket yang sudah pernah dipakai transaksi tidak dapat dihapus.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="pkg_delete">
                  <input type="hidden" name="id" value="<?= (int)$pk['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                </form>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?>
          <tr><td colspan="9" class="muted center">Belum ada <?= e(strtolower($title)) ?>.
            <?= $canManage ? 'Klik tombol "Paket ' . ($kind === 'product' ? 'Produk' : 'Treatment') . '" di atas untuk membuatnya.' : '' ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="modal<?= $edit ? ' open' : '' ?>" id="pkgModal">
  <div class="modal-box" style="max-width:900px">
    <form method="post" id="pkgForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="pkg_save">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="modal-head">
        <h3 id="pkgTitle"><?= $edit ? 'Edit' : 'Tambah' ?> <?= e($title) ?></h3>
        <button type="button" class="icon-btn" data-modal-close="pkgModal"><?= icon('x') ?></button>
      </div>
      <div class="modal-body">
        <div class="form-grid g2">
          <div class="field"><label>Nama Paket <span class="req">*</span></label>
            <input class="input" name="name" id="pkg_name" maxlength="80" required
                   value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="mis. Paket Glowing 3x Facial + Serum"></div>
          <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang <span class="req">*</span></label>
            <select class="input" name="branch_id" id="pkg_branch" onchange="filterPkgItems()">
              <?= opt_branches($edit ? (int)$edit['branch_id'] : ($scope ?: null)) ?>
            </select>
            <span class="hint">Paket dan seluruh isinya harus dari cabang yang sama.</span></div>
          <?php else: ?>
            <?php /* PENTING: di dalam FUNGSI, variabel halaman ($user) tidak tersedia —
                     gunakan user_branch() supaya cabang pengguna benar-benar terisi
                     (sebelumnya nilainya kosong sehingga paket gagal disimpan). */ ?>
            <input type="hidden" name="branch_id" id="pkg_branch" value="<?= (int)(user_branch() ?? 0) ?>">
          <?php endif; ?>
          <div class="field"><label>Status</label>
            <select class="input" name="status">
              <option value="active"<?= (($edit['status'] ?? '') === 'active') ? ' selected' : '' ?>>Aktif (dapat dipilih di Order Baru)</option>
              <option value="inactive"<?= (($edit['status'] ?? '') === 'inactive') ? ' selected' : '' ?>>Nonaktif</option>
            </select></div>
          <div class="field"><label>Catatan <span class="muted small">(opsional)</span></label>
            <input class="input" name="note" value="<?= e((string)($edit['note'] ?? '')) ?>" maxlength="120"></div>
        </div>

        <div class="section-title">Isi Paket</div>
        <div class="table-wrap">
          <table class="tbl" id="pkgItems">
            <thead><tr><th style="width:20%">Jenis</th><th>Item</th><th style="width:14%">Jumlah</th>
              <th class="num" style="width:16%">Harga Item</th><th class="num" style="width:16%">Subtotal</th><th></th></tr></thead>
            <tbody id="pkgRows">
              <?php /* Selalu ada minimal SATU baris (baris kosong untuk paket baru) —
                       tanpa itu pengguna tidak punya tempat memilih item. */ ?>
              <?php if ($editItems): ?>
                <?php foreach ($editItems as $it) echo package_ui_row($opts, $it); ?>
              <?php else: ?>
                <?= package_ui_row($opts, null) ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="flex gap-sm mt-2 flex-wrap">
          <button type="button" class="btn btn-sm" onclick="pkgAddRow()"><?= icon('plus-circle') ?> Tambah Baris Item</button>
          <button type="button" class="btn btn-sm" onclick="pkgRecalc(true)"><?= icon('settings') ?> Hitung Harga &amp; HPP dari Komponen</button>
          <span class="muted small">Harga paket boleh lebih murah dari jumlah harga komponen.</span>
        </div>

        <div class="form-grid g2 mt-2">
          <div class="field"><label>Harga Paket (Rp) <span class="req">*</span></label>
            <input class="input" type="text" inputmode="numeric" name="price" id="pkg_price"
                   value="<?= e((string)(int)round((float)($edit['price'] ?? 0))) ?>">
            <span class="hint">Harga ini yang ditagihkan ke pasien. Usulan dari komponen: <strong id="pkgSumPrice">Rp 0</strong>.</span></div>
          <div class="field"><label>HPP Paket (Rp)</label>
            <input class="input" type="text" inputmode="numeric" name="hpp" id="pkg_hpp"
                   value="<?= e((string)(int)round((float)($edit['hpp'] ?? 0))) ?>">
            <span class="hint">HPP dari komponen: <strong id="pkgSumHpp">Rp 0</strong>. Dipakai menghitung laba di menu Keuangan.</span></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-modal-close="pkgModal">Batal</button>
        <button class="btn btn-primary" type="submit">Simpan Paket</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var OPTS = <?= js_json(array_map(function ($g) {
        return ['type' => $g['type'], 'label' => $g['group'], 'rows' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'],
                'branch' => (int)$r['branch_id'], 'branch_name' => $r['branch_name'],
                'price' => (float)$r['price'], 'hpp' => (float)$r['hpp'], 'unit' => $r['unit'],
                'active' => $r['status'] === 'active'];
        }, $g['rows'])];
    }, $opts)) ?>;

  function rupiah(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); }
  function qtyVal(s) { return parseFloat(String(s || '').replace(/\./g, '').replace(',', '.')) || 0; }
  function branchNow() {
    var b = document.getElementById('pkg_branch');
    return b ? parseInt(b.value, 10) || 0 : 0;
  }
  /* Isi pilihan item sesuai JENIS + CABANG yang dipilih. */
  function fillItems(row) {
    var sel = row.querySelector('.pkg-item');
    var type = row.querySelector('.pkg-type').value;
    var br = branchNow();
    var keep = sel.value;
    var group = null;
    OPTS.forEach(function (g) { if (g.type === type) group = g; });
    sel.innerHTML = '';
    if (!group) return;
    var shown = 0;
    group.rows.forEach(function (it) {
      var okBranch = !br || it.branch === br;
      if (!okBranch) return;
      var o = document.createElement('option');
      o.value = it.id;
      o.textContent = it.branch_name + ' — ' + it.code + ' — ' + it.name + (it.active ? '' : ' (nonaktif)');
      /* data-branch ikut ditulis supaya penyaringan per cabang dapat
         diperiksa/diuji dari sisi peramban (dipakai juga saat mendiagnosa). */
      o.dataset.branch = it.branch; o.dataset.price = it.price; o.dataset.hpp = it.hpp; o.dataset.unit = it.unit;
      sel.appendChild(o);
      shown++;
    });
    if (shown === 0) {
      var o2 = document.createElement('option');
      o2.value = ''; o2.textContent = '— belum ada item pada cabang ini —';
      sel.appendChild(o2);
    } else if (keep) {
      Array.prototype.forEach.call(sel.options, function (o) { if (o.value === keep) sel.value = keep; });
    }
  }
  window.filterPkgItems = function () {
    Array.prototype.forEach.call(document.querySelectorAll('#pkgRows .pkg-row'), function (row) {
      fillItems(row); pkgRowChange(row.querySelector('.pkg-type'));
    });
    pkgRecalc();
  };
  window.pkgRowChange = function (el) {
    var row = el.closest('.pkg-row');
    if (el.classList.contains('pkg-type')) fillItems(row);
    var sel = row.querySelector('.pkg-item');
    var o = sel.options[sel.selectedIndex];
    if (o) {
      row.querySelector('.pkg-price').textContent = rupiah(o.dataset.price);
      row.querySelector('.pkg-unit').textContent = o.dataset.unit || '';
    }
    pkgRecalc();
  };
  window.pkgRecalc = function (fill) {
    var sumP = 0, sumH = 0;
    Array.prototype.forEach.call(document.querySelectorAll('#pkgRows .pkg-row'), function (row) {
      var sel = row.querySelector('.pkg-item');
      var o = sel.options[sel.selectedIndex];
      var q = qtyVal(row.querySelector('.pkg-qty').value);
      var p = o ? Number(o.dataset.price || 0) : 0;
      var h = o ? Number(o.dataset.hpp || 0) : 0;
      row.querySelector('.pkg-sub').textContent = rupiah(p * q);
      sumP += p * q; sumH += h * q;
    });
    document.getElementById('pkgSumPrice').textContent = rupiah(sumP);
    document.getElementById('pkgSumHpp').textContent = rupiah(sumH);
    if (fill) {
      document.getElementById('pkg_price').value = Math.round(sumP);
      document.getElementById('pkg_hpp').value = Math.round(sumH);
    }
  };
  window.pkgAddRow = function () {
    var body = document.getElementById('pkgRows');
    var tpl = body.querySelector('.pkg-row');
    if (!tpl) return;
    var row = tpl.cloneNode(true);
    row.querySelector('.pkg-qty').value = '1';
    body.appendChild(row);
    fillItems(row);
    pkgRowChange(row.querySelector('.pkg-type'));
    row.scrollIntoView({ block: 'nearest' });
  };
  window.pkgRemoveRow = function (btn) {
    var body = document.getElementById('pkgRows');
    if (body.querySelectorAll('.pkg-row').length <= 1) {
      /* Sisakan satu baris kosong: kosongkan pilihan jumlahnya. */
      btn.closest('.pkg-row').querySelector('.pkg-qty').value = '0';
    } else {
      btn.closest('.pkg-row').remove();
    }
    pkgRecalc();
  };
  window.resetPkgForm = function () {
    var f = document.getElementById('pkgForm');
    f.reset();
    document.getElementById('pkgForm').querySelector('input[name=id]').value = '0';
    document.getElementById('pkgTitle').textContent = 'Tambah <?= e($title) ?>';
    var rows = document.getElementById('pkgRows');
    /* sisakan satu baris, lalu segarkan pilihan item sesuai cabang */
    while (rows.querySelectorAll('.pkg-row').length > 1) rows.querySelector('.pkg-row:last-child').remove();
    var first = rows.querySelector('.pkg-qty');
    if (first) first.value = '1';
    filterPkgItems();
  };
  /* Baris pertama yang tampil harus ikut difilter cabang saat modal dibuka. */
  document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('pkgModal')) filterPkgItems();
  });
})();
</script>
<?php endif; ?>
<?php
}
