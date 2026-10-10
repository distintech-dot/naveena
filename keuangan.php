<?php
/**
 * KEUANGAN — omzet, HPP, laba kotor, biaya operasional, dan laba bersih.
 *
 * HANYA untuk level OWNER (Super Admin & Direktur/Owner): data HPP & laba
 * bersih bersifat internal perusahaan. Penegakannya di server (permission
 * `finance.view` + `is_owner_level()`), bukan hanya menu yang disembunyikan.
 *
 * Dua mode laporan. Pilihan mode di halaman ini bersifat PRATINJAU: memilih
 * dari daftar hanya mengganti tampilan, dan baru TERSIMPAN setelah menekan
 * tombol "Terapkan Mode" (dengan konfirmasi 2x). Semua pengaturan keuangan
 * (mode + biaya operasional per cabang) berada di HALAMAN INI — kartu
 * "Keuangan" di Pengaturan Sistem sudah dihapus supaya tidak ada dua tempat:
 *   - Laporan Dasar   : omzet (treatment + skincare − diskon − diskon member),
 *                       HPP treatment, HPP produk, lalu LABA BERSIH.
 *   - Laporan Lengkap : ditambah LABA KOTOR dan biaya operasional (gaji, listrik
 *                       & air, marketing, sewa bangunan, aplikasi, pajak,
 *                       operasional lainnya — dapat ditambah/diganti nama),
 *                       TOTAL BIAYA OPERASIONAL, lalu LABA BERSIH.
 *
 * Biaya operasional punya PERIODE PEMBAYARAN (bulanan … 5 tahun) sehingga
 * nominal tahunan (mis. sewa bangunan) otomatis diprorata untuk periode laporan.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/pdf.php';
require_once __DIR__ . '/includes/report_pdf.php';
require_once __DIR__ . '/includes/chart_js.php';
require_perm('finance.view');
if (!is_owner_level()) {
    deny('Menu Keuangan (HPP & laba bersih) hanya dapat diakses oleh Direktur/Owner dan Super Admin.');
}
$user = current_user();

/* ---------------------------------------------------------------- *
 * Aksi: ganti mode laporan (owner) + kelola biaya operasional
 * ---------------------------------------------------------------- */
/* Kembali ke halaman ini dengan FILTER & CAKUPAN YANG SAMA setelah menyimpan.
   Form POST dikirim ke URL halaman (query string ikut terbawa), jadi filter
   diambil dari $_GET — sebelumnya redirect hanya memakai kolom `keep` sehingga
   periode, cakupan cabang, dan pilihan cakupan biaya HILANG setiap kali
   menyimpan dan pengguna harus memilihnya ulang. */
$backQs = http_build_query(array_diff_key($_GET, ['keep' => '']));
$backUrl = 'keuangan.php' . ($backQs !== '' ? '?' . $backQs : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'apply_mode') {
            /* Menerapkan mode laporan = mengubah cara SELURUH laporan keuangan
               dihitung (termasuk laba di dashboard & dokumen cetak). Karena itu
               hanya tersimpan saat tombol "Terapkan Mode" ditekan, dan antarmuka
               meminta konfirmasi 2x lebih dulu. */
            $m = (string)($_POST['finance_mode'] ?? 'dasar');
            if (!in_array($m, ['dasar', 'lengkap'], true)) throw new RuntimeException('Mode laporan tidak dikenal.');
            $old = finance_mode();
            if ($old === $m) {
                flash('Mode laporan sudah "' . finance_mode_label($m) . '" — tidak ada yang diubah.', 'warning');
            } else {
                set_setting('finance_mode', $m);
                audit('Terapkan Mode Laporan Keuangan', 'Keuangan', null,
                    ['mode' => $old], ['mode' => $m, 'alasan' => trim((string)($_POST['reason'] ?? ''))],
                    'Mode laporan keuangan diterapkan (' . finance_mode_label($old) . ' → ' . finance_mode_label($m) . ')');
                flash('Mode laporan DITERAPKAN dan tersimpan: "' . finance_mode_label($old) . '" → "'
                    . finance_mode_label($m) . '". Angka laba pada dashboard, laporan, dokumen cetak, Excel, '
                    . 'dan email laporan otomatis kini memakai mode ini.');
            }
        }
        if ($act === 'cost_save_scope') {
            /* SATU tombol Simpan untuk seluruh daftar biaya pada SATU cakupan
               (mis. "Semua cabang" atau satu cabang). Nilai cakupan lain TETAP
               tersimpan — hanya status BERLAKU-nya yang berpindah (lihat
               finance_cost_save_scope()). */
            $scope = (int)($_POST['cost_scope'] ?? 0);
            if ($scope > 0 && !one('SELECT id FROM branches WHERE id = ?', [$scope])) {
                throw new RuntimeException('Cakupan cabang tidak ditemukan.');
            }
            /* Tombol Hapus berada di dalam form yang sama (satu form utuh per
               daftar) dan hanya mengirim `do=delete` + `cost_id`. */
            if ((string)($_POST['do'] ?? '') === 'delete') {
                $id = (int)($_POST['cost_id'] ?? 0);
                $old = finance_cost_delete($id);
                if (!$old) throw new RuntimeException('Biaya tidak ditemukan.');
                audit('Hapus Biaya Operasional', 'Keuangan', $id,
                    ['nama' => $old['name']], null, 'Pos biaya operasional dihapus (semua cakupan)');
                flash('Pos biaya "' . $old['name'] . '" dihapus dari semua cakupan.', 'warning');
                header('Location: ' . $backUrl);
                exit;
            }
            $rows = [];
            foreach ((array)($_POST['amount'] ?? []) as $id => $amt) {
                $id = (int)$id;
                if ($id <= 0) continue;
                $rows[] = [
                    'id' => $id,
                    'name' => (string)($_POST['name'][$id] ?? ''),
                    'amount' => $amt,
                    'period' => (int)($_POST['period'][$id] ?? 1),
                    'active' => isset($_POST['active'][$id]),
                ];
            }
            if (!$rows) throw new RuntimeException('Tidak ada baris biaya yang dikirim.');
            $res = finance_cost_save_scope($scope, $rows);
            $scopeLbl = finance_cost_scope_label($scope);
            audit('Simpan Biaya Operasional', 'Keuangan', null, null,
                ['cakupan' => $scopeLbl, 'baris' => $res['saved'],
                 'tidak_berlaku_lagi' => array_map(fn($r) => $r['cost'] . ' (' . $r['scope'] . ')', $res['replaced'])],
                'Biaya operasional disimpan untuk cakupan ' . $scopeLbl);
            $msg = $res['saved'] . ' pos biaya disimpan untuk cakupan "' . $scopeLbl . '".';
            if ($scope > 0) {
                /* Biaya per cabang dihitung untuk SEMUA cabang yang terisi —
                   jadi pengguna diingatkan melanjutkan ke cabang berikutnya. */
                $blm = [];
                foreach (finance_cost_scope_progress()['scopes'] as $sp) {
                    if ((int)$sp['scope'] > 0 && (int)$sp['filled'] === 0) $blm[] = (string)$sp['label'];
                }
                if ($blm) {
                    $msg .= ' Lanjutkan mengisi cakupan cabang lain: ' . implode(', ', $blm)
                        . ' — nominal tiap cabang dihitung bersama-sama (bukan saling menggantikan).';
                } else {
                    $msg .= ' Semua cakupan cabang sudah terisi dan dihitung bersama-sama.';
                }
                if (!empty($res['mode_branch'])) {
                    $msg .= ' Pos biaya berikut kembali memakai nominal PER CABANG (cakupan "Semua cabang" tidak dipakai lagi): '
                        . implode(', ', array_slice($res['mode_branch'], 0, 8)) . '.';
                }
            }
            if ($res['replaced']) {
                $txt = [];
                foreach ($res['replaced'] as $r) $txt[] = $r['cost'] . ' (' . $r['scope'] . ')';
                $msg .= ' Isian per cabang berikut kini TIDAK BERLAKU lagi karena sudah tercakup "Semua cabang": '
                    . implode(', ', array_slice($txt, 0, 6)) . (count($txt) > 6 ? ' …' : '')
                    . '. Nilainya tetap tersimpan dan dapat dipakai kembali kapan saja dengan menyimpan ulang pada cakupan cabangnya.';
            }
            flash($msg, $res['replaced'] ? 'warning' : 'success');
        }
        if ($act === 'cost_delete') {
            $id = (int)($_POST['cost_id'] ?? 0);
            $old = finance_cost_delete($id);
            if (!$old) throw new RuntimeException('Biaya tidak ditemukan.');
            audit('Hapus Biaya Operasional', 'Keuangan', $id,
                ['nama' => $old['name']], null, 'Pos biaya operasional dihapus (semua cakupan)');
            flash('Pos biaya "' . $old['name'] . '" dihapus dari semua cakupan.', 'warning');
            header('Location: ' . $backUrl);
            exit;
        }
        if ($act === 'cost_add') {
            $scope = (int)($_POST['cost_scope'] ?? 0);
            if ($scope > 0 && !one('SELECT id FROM branches WHERE id = ?', [$scope])) {
                throw new RuntimeException('Cakupan cabang tidak ditemukan.');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Nama biaya wajib diisi.');
            if ((int)preg_match_all('/./us', $name) > 60) throw new RuntimeException('Nama biaya maksimal 60 karakter.');
            $dup = one('SELECT id FROM finance_costs WHERE LOWER(TRIM(name)) = LOWER(?)', [$name]);
            if ($dup) throw new RuntimeException('Pos biaya "' . $name . '" sudah ada — isi nominalnya pada daftar di atas.');
            $period = finance_period_norm($_POST['period_months'] ?? 1);
            $amount = max(0.0, qty_parse($_POST['amount'] ?? 0));
            $sort = (int)scalar('SELECT COALESCE(MAX(sort),0)+10 FROM finance_costs');
            q('INSERT INTO finance_costs (name, amount, period_months, sort, status, created_at)
               VALUES (?,0,1,?,"active",datetime("now","localtime"))', [$name, $sort]);
            $newId = (int)db()->lastInsertId();
            finance_cost_save_scope($scope, [[
                'id' => $newId, 'name' => $name, 'amount' => $amount, 'period' => $period, 'active' => true,
            ]]);
            $scopeLbl = finance_cost_scope_label($scope);
            audit('Tambah Biaya Operasional', 'Keuangan', $newId, null,
                ['nama' => $name, 'nominal' => $amount, 'periode' => $period, 'cakupan' => $scopeLbl],
                'Pos biaya operasional baru ditambahkan (' . $scopeLbl . ')');
            flash('Pos biaya "' . $name . '" ditambahkan pada cakupan "' . $scopeLbl . '" ('
                . money($amount) . ' ' . strtolower(finance_period_label($period)) . ').');
        }
        if ($act === 'calc_height') {
            /* Tinggi maksimal tabel "Perhitungan …" — murni pengaturan TAMPILAN
               (tidak mengubah angka laporan apa pun). */
            $px = finance_calc_height_norm($_POST['finance_calc_max_px'] ?? '');
            $old = finance_calc_height();
            if ($old === $px) {
                flash('Tinggi maksimal tabel perhitungan sudah ' . finance_calc_height_label($px)
                    . ' — tidak ada yang diubah.', 'warning');
            } else {
                set_setting('finance_calc_max_px', (string)$px);
                audit('Ubah Tinggi Tabel Perhitungan', 'Keuangan', null,
                    ['tinggi_px' => $old], ['tinggi_px' => $px],
                    'Tinggi maksimal tabel perhitungan diubah menjadi ' . finance_calc_height_label($px));
                flash('Tinggi maksimal tabel perhitungan disimpan: ' . finance_calc_height_label($px) . '.');
            }
        }
        if ($act === 'cost_save' || $act === 'cost_toggle') {
            /* Aksi lama (satu baris = satu form) sudah digantikan
               `cost_save_scope`; blok ini hanya pengaman bila ada tautan lama. */
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: ' . $backUrl);
    exit;
}

/* ---------------------------------------------------------------- *
 * Data laporan
 * ---------------------------------------------------------------- */
$f = report_filters();
/* Mode TERSIMPAN vs mode PRATINJAU:
   - `$modeSaved` = yang berlaku sekarang (angka resmi di dashboard/laporan/dokumen).
   - `$mode`      = yang sedang DILIHAT di halaman ini (boleh berbeda = pratinjau).
   Pratinjau tidak mengubah apa pun sampai tombol "Terapkan Mode" ditekan. */
$modeSaved = finance_mode();
$modePick = (string)gp('mode_preview', $modeSaved);
if (!in_array($modePick, ['dasar', 'lengkap'], true)) $modePick = $modeSaved;
$previewing = ($modePick !== $modeSaved);
$mode = $modePick;
$withCosts = $mode === 'lengkap';
$scopeBranch = $f['scope'] !== null ? (int)$f['scope'] : null;
$sum = finance_summary($f, $withCosts, $scopeBranch);
$perBranch = finance_per_branch($f);
$series = finance_series($f);
/* Pilihan CAKUPAN biaya (di ATAS daftar biaya): "Semua cabang" (0) atau satu
   cabang. Cakupan yang dipilih hanya menentukan DAFTAR mana yang sedang diisi;
   nominal tiap cakupan tersimpan sendiri-sendiri. */
$costScope = finance_cost_scope_pick();
$costRows = finance_cost_rows_for_scope($costScope);
$costScopeLbl = finance_cost_scope_label($costScope);
$costBranchN = finance_cost_scope_count();
$costs = finance_operational_costs($f, $scopeBranch);
/* Peringatan 2x saat menyimpan cakupan "Semua cabang": isian PER CABANG untuk
   pos biaya yang diisi di situ berhenti berlaku. Daftar di bawah dipakai untuk
   teks peringatan; konfirmasi barunya sendiri muncul saat tombol Simpan ditekan
   DAN ada nominal yang diisi (lihat skrip halaman). */
$branchApplied = all("SELECT c.id, c.name, a.branch_id, a.amount
                      FROM finance_cost_amounts a JOIN finance_costs c ON c.id = a.cost_id
                      WHERE a.branch_id <> 0 AND COALESCE(a.amount,0) > 0
                        AND COALESCE(c.cost_mode,'branch') <> 'company'
                      ORDER BY c.sort ASC, a.branch_id ASC");
$branchAppliedCostIds = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $branchApplied)));
$branchAppliedAll = array_map(fn($r) => $r['name'] . ' (' . finance_cost_scope_label((int)$r['branch_id']) . ')', $branchApplied);
$branchAppliedTxt = implode(', ', array_slice(array_unique($branchAppliedAll), 0, 8))
    . (count($branchAppliedAll) > 8 ? ' …' : '');
$hasBranchApplied = $branchApplied !== [];
/* Sisi sebaliknya: menyimpan cakupan CABANG membuat pos biaya yang sedang
   memakai biaya bersama kembali ke nominal per cabang (diberitahukan, tanpa
   konfirmasi 2x karena nilainya tetap tersimpan). */
$companyModeCosts = all("SELECT name FROM finance_costs WHERE COALESCE(cost_mode,'branch') = 'company' ORDER BY sort");
$companyModeNames = array_map(fn($r) => (string)$r['name'], $companyModeCosts);
$companyModeTxt = implode(', ', array_slice($companyModeNames, 0, 8)) . (count($companyModeNames) > 8 ? ' …' : '');
/* Kemajuan pengisian tiap cakupan — dipakai mengingatkan "lanjutkan isi cabang
   lain" (permintaan pemilik ronde 33). */
$scopeProgress = finance_cost_scope_progress();
$nextScope = null;
if ($costScope > 0) {
    foreach ($scopeProgress['scopes'] as $sp) {
        if ((int)$sp['scope'] > 0 && (int)$sp['scope'] !== $costScope && (int)$sp['filled'] === 0) { $nextScope = $sp; break; }
    }
} elseif ($scopeProgress['missing'] !== []) {
    $nextScope = $scopeProgress['scopes'][1] ?? null;      // cabang pertama yang belum diisi
}
/* Ringkasan SATU BARIS per pos biaya: cakupan mana yang sedang dihitung untuk
   pos itu (per cabang: semua cabang yang terisi · atau biaya bersama). */
$costScopeOverview = [];
foreach ($costs['rows'] as $r) {
    $costScopeOverview[] = [
        'name' => (string)$r['name'],
        'scope' => (string)($r['scope_label'] ?? '-'),
        'is_company' => !empty($r['is_company']),
        'amount' => (float)$r['amount'],
        'detail' => implode(', ', array_map(fn($pt) => $pt['scope_label'], $r['parts'])),
        'share' => (float)$r['share'],
    ];
}

/* Ekspor CSV langsung dari halaman ini (data yang sama dengan yang dilihat). */
if (gp('format') === 'csv') {
    require_perm('export.data');
    $rows = [
        ['LAPORAN KEUANGAN — ' . finance_mode_label($mode), ''],
        ['Periode', tglIndo($f['ps']) . ' s.d. ' . tglIndo($f['pe'])],
        ['Cakupan', $f['scope'] === null ? 'Semua Cabang' : (string)scalar('SELECT name FROM branches WHERE id = ?', [$f['scope']])],
        ['', ''],
        ['Pendapatan Treatment', (string)$sum['pendapatan_treatment']],
        ['Pendapatan Skincare', (string)$sum['pendapatan_skincare']],
        ['Pendapatan Paket', (string)$sum['pendapatan_paket']],
        ['Diskon Transaksi', '-' . (string)$sum['diskon']],
        ['Diskon Member', '-' . (string)$sum['diskon_member']],
        ['TOTAL OMZET', (string)$sum['omzet']],
        ['HPP Treatment', (string)$sum['hpp_treatment']],
        ['HPP Produk', (string)$sum['hpp_produk']],
        ['HPP Paket', (string)$sum['hpp_paket']],
    ];
    if ($withCosts) {
        $rows[] = ['LABA KOTOR', (string)$sum['laba_kotor']];
        foreach ($costs['rows'] as $c) {
            $rows[] = [$c['name'] . ' (' . $c['period_label'] . ' · ' . ($c['scope_label'] ?? 'Semua cabang') . ')',
                '-' . (string)$c['share']];
            /* Rincian per cabang pada satu baris pos biaya (ronde 33). */
            if (!empty($c['breakdown']) && count($c['parts'] ?? []) > 1) {
                $rows[] = ['   = ' . $c['breakdown'], ''];
            }
        }
        $rows[] = ['TOTAL BIAYA OPERASIONAL', '-' . (string)$sum['biaya_total']];
    }
    $rows[] = ['LABA BERSIH', (string)$sum['laba_bersih']];
    if ($sum['margin'] !== null) $rows[] = ['Margin Laba Bersih (%)', (string)$sum['margin']];
    $rows[] = ['', ''];
    $rows[] = ['RINCIAN PER CABANG', ''];
    $rows[] = ['Cabang', 'Omzet', 'HPP Treatment', 'HPP Produk'];
    if ($withCosts) $rows[] = [];
    foreach ($perBranch as $b) {
        $rows[] = [$b['branch'], (string)$b['omzet'], (string)$b['hpp_treatment'], (string)$b['hpp_produk']];
    }
    audit('Export CSV', 'Keuangan', null, null, ['periode' => $f['ps'] . ' s.d. ' . $f['pe'], 'mode' => $mode], 'Ekspor laporan keuangan CSV');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="keuangan-' . $f['ps'] . '_sd_' . $f['pe'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [clinic_name() . ' — Laporan Keuangan (' . finance_mode_label($mode) . ')']);
    fputcsv($out, []);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/* Ekspor PDF (dokumen cetak) dari halaman ini. */
if (gp('format') === 'pdf') {
    require_perm('export.data');
    $bytes = finance_pdf_bytes($f, $sum, $perBranch, $costs, $series);
    audit('Export PDF', 'Keuangan', null, null, ['periode' => $f['ps'] . ' s.d. ' . $f['pe'], 'mode' => $mode], 'Ekspor laporan keuangan PDF');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="keuangan-' . $f['ps'] . '_' . $f['pe'] . '.pdf"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

$scopeLbl = $f['scope'] === null ? 'Semua Cabang'
    : (string)scalar('SELECT name FROM branches WHERE id = ?', [$f['scope']]);

page_head('Keuangan — ' . finance_mode_label($mode), 'keuangan');
?>
<div class="page-head">
  <div>
    <h2>Laporan Keuangan</h2>
    <p class="muted">Omzet, HPP, dan laba · <strong><?= e(finance_mode_label($mode)) ?></strong> ·
      <?= e($scopeLbl) ?> · <?= e(tgl($f['ps'])) ?> — <?= e(tgl($f['pe'])) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="export.php?type=keuangan&amp;format=xlsx&amp;<?= e(qs([], [])) ?>"><?= icon('database') ?> Excel</a>
    <a class="btn btn-primary" href="keuangan.php?<?= e(qs([], [])) ?>&amp;format=pdf"><?= icon('download') ?> PDF</a>
    <button class="btn" data-print><?= icon('print') ?> Cetak</button>
  </div>
</div>

<div class="alert alert-info">
  <strong>Menu ini khusus Direktur/Owner &amp; Super Admin</strong> — HPP dan laba bersih bersifat internal
  perusahaan, jadi tidak ditampilkan kepada level lain (menu &amp; kartunya tidak muncul untuk mereka).
  <?php if ($previewing): ?>
    <br><strong>Sedang PRATINJAU mode <?= e(finance_mode_label($mode)) ?></strong> — angka di halaman ini
    belum tersimpan. Mode yang BERLAKU sekarang: <strong><?= e(finance_mode_label($modeSaved)) ?></strong>.
    Tekan <em>Terapkan Mode</em> di panel di bawah bila ingin memakai mode ini secara permanen
    (dipakai juga oleh dashboard, laporan, dokumen cetak, Excel, dan email laporan otomatis).
  <?php else: ?>
    <br>Mode laporan yang <strong>berlaku &amp; tersimpan</strong>: <strong><?= e(finance_mode_label($modeSaved)) ?></strong>
    <?= $withCosts
        ? '(termasuk laba kotor, biaya operasional, dan laba bersih).'
        : '(omzet − HPP; biaya operasional belum dihitung).' ?>
  <?php endif; ?>
</div>

<?php /* ================= PENGATURAN KEUANGAN (dipindah ke halaman ini) =================
   Semua pengaturan keuangan ada di sini supaya tidak ada dua tempat: pilih mode
   (dengan PRATINJAU + tombol Terapkan Mode), dan kelola biaya operasional
   per cabang (nominal + periode pembayaran). */ ?>
<div class="card" id="modepilihan">
  <div class="card-head">
    <h3>Sistem Laporan Keuangan</h3>
    <span><?= badge('Berlaku: ' . finance_mode_label($modeSaved), $modeSaved === 'lengkap' ? 'green' : 'gray') ?>
      <?php if ($previewing): ?><?= badge('Pratinjau: ' . finance_mode_label($mode), 'yellow') ?><?php endif; ?></span>
  </div>
  <div class="card-body">
    <div class="alert alert-info" style="margin-top:0">
      <strong>Pratinjau dulu, terapkan kemudian.</strong> Memilih salah satu mode di bawah hanya
      menampilkan PRATINJAU di halaman ini (angka tidak tersimpan dan tidak mengubah laporan lain).
      Bila sudah cocok, tekan <strong>Terapkan Mode</strong> — tersimpan permanen dan dipakai
      dashboard, laporan, dokumen cetak, Excel, serta email laporan otomatis.
    </div>
    <div class="grid g2">
      <?php foreach (['dasar' => ['Laporan Dasar', 'Total pendapatan (omzet) − HPP treatment − HPP produk = laba bersih.'],
                                'lengkap' => ['Laporan Lengkap', 'Ditambah laba kotor dan biaya operasional (gaji, listrik & air, marketing, sewa bangunan, aplikasi, pajak, operasional lainnya) sehingga laba bersih lebih akurat.']]
                     as $mk => $mv): ?>
        <label class="status-opt<?= $mode === $mk ? ' active' : '' ?>" id="mode_opt_<?= e($mk) ?>">
          <?php /* PENTING: JANGAN memakai js_json() di dalam atribut HTML — kutip
                   ganda JSON akan MENUTUP atribut onclick sehingga skrip terpotong
                   ("Unexpected end of input"). Gunakan e() (kutip tunggal + escaped). */ ?>
          <input type="radio" name="mode_preview_pick" value="<?= e($mk) ?>"<?= $mode === $mk ? ' checked' : '' ?>
                 onclick="window.location='keuangan.php?<?= e(qs(['mode_preview' => $mk], [])) ?>';">
          <span class="st-name"><?= e($mv[0]) ?>
            <?php if ($modeSaved === $mk): ?><span class="badge badge-green">sedang berlaku</span><?php endif; ?>
            <?php if ($previewing && $mode === $mk && $modeSaved !== $mk): ?><span class="badge badge-yellow">pratinjau</span><?php endif; ?>
          </span>
          <span class="st-desc"><?= e($mv[1]) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="flex gap-sm mt-2 flex-wrap" style="align-items:center">
      <?php if ($previewing): ?>
        <span class="badge badge-yellow"><?= icon('chart') ?> Pratinjau aktif — belum diterapkan</span>
        <form method="post" data-heavy-confirm="TERAPKAN MODE"
              data-heavy-warning="Menerapkan mode laporan keuangan <strong>mengubah cara SELURUH laporan menghitung laba</strong> — termasuk kartu laba di dashboard, halaman Laporan, dokumen cetak/PDF, Excel, dan email laporan otomatis. Pastikan angka pratinjau sudah benar."
              data-heavy-confirm2="PERINGATAN KEDUA (terakhir): mode laporan keuangan akan DITERAPKAN dan tersimpan. Lanjutkan?"
              class="flex gap-sm flex-wrap" style="align-items:flex-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="apply_mode">
          <input type="hidden" name="finance_mode" value="<?= e($mode) ?>">
          <input type="hidden" name="keep" value="<?= e(qs(['mode_preview' => null], ['page', 'per_page'])) ?>">
          <div class="field" style="min-width:240px"><label>Alasan perubahan (opsional, tercatat di audit)</label>
            <input class="input input-sm" name="reason" placeholder="mis. mulai menghitung biaya operasional"></div>
          <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Terapkan Mode <?= e(finance_mode_label($mode)) ?></button>
        </form>
        <a class="btn" href="keuangan.php?<?= e(qs(['mode_preview' => null], [])) ?>">Batalkan pratinjau</a>
      <?php else: ?>
        <form method="post" data-heavy-confirm="TERAPKAN MODE"
              data-heavy-warning="Mode yang dipilih sama dengan mode yang sudah berlaku — tidak ada yang berubah."
              data-heavy-confirm2="Lanjutkan?" class="flex gap-sm">
          <?= csrf_field() ?><input type="hidden" name="action" value="apply_mode">
          <input type="hidden" name="finance_mode" value="<?= e($modeSaved) ?>">
          <button class="btn" type="submit"><?= icon('settings') ?> Terapkan Mode <?= e(finance_mode_label($modeSaved)) ?></button>
        </form>
        <span class="muted small">Mode ini sudah berlaku. Pilih mode lain di atas untuk melihat pratinjaunya lebih dulu.</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php /* ---------- Filter periode ---------- */ ?>
<div class="card">
  <div class="card-head">
    <h3>Periode &amp; Cakupan Cabang</h3>
    <span class="muted"><?= e($scopeLbl) ?> · <?= e(tgl($f['ps'])) ?> — <?= e(tgl($f['pe'])) ?></span>
  </div>
  <form class="filter-bar" method="get">
    <?php if ($previewing): ?><input type="hidden" name="mode_preview" value="<?= e($mode) ?>"><?php endif; ?>
    <div class="field"><label>Periode</label>
      <select class="input input-sm" name="period" data-period data-autosubmit>
        <?php foreach (['today' => 'Hari ini', '7d' => '7 hari', 'month' => 'Bulan ini', '3m' => '3 bulan', 'year' => 'Tahun ini', 'custom' => 'Custom tanggal', 'all' => 'Seluruh riwayat'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= $f['period'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field" data-period-custom style="display:none"><label>Dari</label>
      <input type="date" class="input input-sm" name="start" value="<?= e($f['ps']) ?>"></div>
    <div class="field" data-period-custom style="display:none"><label>Sampai</label>
      <input type="date" class="input input-sm" name="end" value="<?= e($f['pe']) ?>"></div>
    <?php if (is_owner_level()): ?>
    <div class="field"><label>Cabang</label>
      <select class="input input-sm" name="branch" data-autosubmit>
        <option value=""<?= $f['scope'] === null ? ' selected' : '' ?>>Semua Cabang</option>
        <?php foreach (branches() as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= (string)$f['scope'] === (string)$b['id'] ? ' selected' : '' ?>><?= e(branch_short_label((string)$b['name'])) ?></option>
        <?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
  </form>
</div>

<?php /* ---------- Kartu ringkasan ---------- */ ?>
<div class="grid g4 mb-3">
  <div class="stat accent"><span class="lbl">Total Pendapatan (Omzet)</span>
    <span class="val"><?= money($sum['omzet']) ?></span>
    <span class="sub"><?= num($sum['trx']) ?> transaksi</span></div>
  <div class="stat"><span class="lbl">Total HPP</span><span class="val"><?= money($sum['hpp_total']) ?></span>
    <span class="sub">Treatment <?= money($sum['hpp_treatment']) ?> · Produk <?= money($sum['hpp_produk']) ?>
      <?= $sum['hpp_paket'] > 0 ? '· Paket ' . money($sum['hpp_paket']) : '' ?></span></div>
  <?php if ($withCosts): ?>
    <div class="stat brown"><span class="lbl">Laba Kotor</span><span class="val"><?= money($sum['laba_kotor']) ?></span>
      <span class="sub">Omzet − HPP</span></div>
    <div class="stat"><span class="lbl">Total Biaya Operasional</span><span class="val"><?= money($sum['biaya_total']) ?></span>
      <span class="sub"><?= num(count($costs['rows'])) ?> pos biaya · <?= num((int)$costs['days']) ?> hari</span></div>
  <?php else: ?>
    <div class="stat"><span class="lbl">Total Diskon</span><span class="val"><?= money($sum['diskon'] + $sum['diskon_member']) ?></span>
      <span class="sub">Sudah dikurangkan dari omzet di atas</span></div>
    <div class="stat"><span class="lbl">Mode Laporan</span><span class="val" style="font-size:1.05rem">Dasar</span>
      <span class="sub">biaya operasional belum dihitung</span></div>
  <?php endif; ?>
</div>
<div class="grid g4 mb-3">
  <div class="stat leaf"><span class="lbl">LABA BERSIH</span><span class="val"><?= money($sum['laba_bersih']) ?></span>
    <span class="sub"><?= $sum['margin'] !== null ? 'margin ' . num($sum['margin'], 1) . '% dari omzet' : 'belum ada omzet' ?></span></div>
  <div class="stat gold"><span class="lbl">Pendapatan Treatment</span><span class="val"><?= money($sum['pendapatan_treatment']) ?></span>
    <span class="sub"><?= qty_text($sum['qty_treatment']) ?> item</span></div>
  <div class="stat pink"><span class="lbl">Pendapatan Skincare</span><span class="val"><?= money($sum['pendapatan_skincare']) ?></span>
    <span class="sub"><?= qty_text($sum['qty_skincare']) ?> item</span></div>
  <div class="stat"><span class="lbl">Pendapatan Paket</span><span class="val"><?= money($sum['pendapatan_paket']) ?></span>
    <span class="sub"><?= qty_text($sum['qty_paket']) ?> paket</span></div>
</div>

<?php /* ---------- PERHITUNGAN: kartu SENDIRI lebar penuh + tabel bergulir ----------
   Permintaan pemilik (ronde 32): kartu perhitungan laporan lengkap dipisah dari
   kartu grafik laba bersih supaya dapat memakai lebar layar (lebih lega ke
   samping), dan tingginya DIBATASI setelan + isinya dapat digulir ke atas/bawah
   seperti tabel biasa — supaya kartu tidak memanjang ke bawah saat pos biaya
   atau cabang bertambah. */ ?>
<?php $calcMax = finance_calc_height(); ?>
<div class="card" id="perhitungan">
  <div class="card-head">
    <h3>Perhitungan <?= e(finance_mode_label($mode)) ?></h3>
    <span class="muted"><?= e(tgl($f['ps'])) ?> — <?= e(tgl($f['pe'])) ?></span>
  </div>
  <div class="card-body">
    <div class="table-toolbar">
      <form method="post" class="flex gap-sm flex-wrap" style="align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="calc_height">
        <input type="hidden" name="keep" value="<?= e(qs([], [])) ?>">
        <label class="small muted" for="calcMaxSel">Tinggi maksimal tabel perhitungan</label>
        <select class="input input-sm" name="finance_calc_max_px" id="calcMaxSel">
          <?php foreach (finance_calc_height_options() as $hk => $hv): ?>
            <option value="<?= (int)$hk ?>"<?= $calcMax === (int)$hk ? ' selected' : '' ?>><?= e($hv) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit" id="calcHeightSave"><?= icon('save') ?> Simpan</button>
      </form>
      <div class="small muted table-scroll-note">
        <?php if ($calcMax > 0): ?>
          <?= icon('table') ?> Tabel dibatasi <strong><?= e(num($calcMax)) ?> px</strong>
          (± <?= num((int)floor($calcMax / 44)) ?> baris) dan dapat
          <strong>digulir ke atas/bawah</strong> bila pos biaya atau cabang bertambah.
        <?php else: ?>
          <?= icon('table') ?> Tabel ditampilkan <strong>seluruhnya</strong> (tanpa batas tinggi).
        <?php endif; ?>
      </div>
    </div>
    <div class="table-wrap<?= $calcMax > 0 ? ' scroll-y' : '' ?>" id="calcTableWrap"
         <?= $calcMax > 0 ? 'style="max-height:' . (int)$calcMax . 'px"' : '' ?>>
      <table class="tbl">
        <tbody>
          <tr><td>Pendapatan Treatment</td><td class="num"><?= money($sum['pendapatan_treatment']) ?></td></tr>
          <tr><td>Pendapatan Skincare</td><td class="num"><?= money($sum['pendapatan_skincare']) ?></td></tr>
          <tr><td>Pendapatan Paket</td><td class="num"><?= money($sum['pendapatan_paket']) ?></td></tr>
          <tr><td class="muted">Pengurangan Diskon Transaksi</td><td class="num muted">− <?= money($sum['diskon']) ?></td></tr>
          <tr><td class="muted">Pengurangan Diskon Member</td><td class="num muted">− <?= money($sum['diskon_member']) ?></td></tr>
          <tr><th>Total Pendapatan (Omzet)</th><th class="num"><?= money($sum['omzet']) ?></th></tr>
          <tr><td>HPP Treatment</td><td class="num muted">− <?= money($sum['hpp_treatment']) ?></td></tr>
          <tr><td>HPP Produk</td><td class="num muted">− <?= money($sum['hpp_produk']) ?></td></tr>
          <tr><td>HPP Paket</td><td class="num muted">− <?= money($sum['hpp_paket']) ?></td></tr>
          <?php if ($withCosts): ?>
            <tr><th>Laba Kotor</th><th class="num"><?= money($sum['laba_kotor']) ?></th></tr>
            <?php foreach ($costs['rows'] as $c): ?>
              <?php /* SATU baris per pos biaya (permintaan pemilik ronde 33): nominal
                       semua cabang yang terisi dijumlahkan di sini, rinciannya
                       ditulis di bawahnya — jadi "Gaji" tidak berulang tiap cabang
                       walau cabangnya banyak. */ ?>
              <tr>
                <td><?= e($c['name']) ?>
                  <div class="small muted">
                    <?= e($c['is_company'] ? 'Semua cabang (biaya bersama)' : 'Per cabang') ?>
                    · <?= e($c['period_label']) ?>
                    · nominal <?= money($c['amount']) ?>
                    <?php if (!empty($c['is_company']) && $costs['branches'] > 1): ?>
                      (dibagi rata ke <?= num((int)$costs['branches']) ?> cabang)<?php endif; ?>
                  </div>
                  <?php if (count($c['parts']) > 1): ?>
                    <div class="small muted">= <?= e($c['breakdown']) ?></div>
                  <?php endif; ?>
                  <?php if (!$c['is_company'] && count($c['all_parts']) < $costs['branches']): ?>
                    <div class="small muted">⚠ baru <?= num(count($c['all_parts'])) ?> dari <?= num((int)$costs['branches']) ?> cabang terisi
                      — lengkapi agar seluruh cabang ikut tertagih</div>
                  <?php endif; ?>
                </td>
                <td class="num muted">− <?= money($c['share']) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr><th>Total Biaya Operasional</th><th class="num">− <?= money($sum['biaya_total']) ?></th></tr>
          <?php endif; ?>
          <tr><th>LABA BERSIH</th><th class="num"><?= money($sum['laba_bersih']) ?></th></tr>
        </tbody>
      </table>
    </div>
    <div class="notice mt-2 small">
      Omzet dihitung dari nilai barang (treatment + skincare + paket) dikurangi diskon transaksi &amp; diskon member;
      <strong>kode unik transfer/QRIS tidak dihitung</strong> karena hanya alat pencocokan mutasi bank.
      HPP memakai harga pokok <em>saat transaksi terjadi</em> (snapshot), sehingga mengubah HPP master tidak
      mengubah laba transaksi lama.
      <br><br><strong>Cara membaca biaya operasional (prorata):</strong> kolom <em>Nominal</em> adalah angka
      yang Anda isi sesuai <em>periode pembayarannya</em> (mis. Rp 25.000.000 setiap bulan), sedangkan angka
      di tabel ini adalah <strong>porsi untuk rentang laporan</strong>
      (<?= e(tgl($f['ps'])) ?> — <?= e(tgl($f['pe'])) ?> = <?= e(num($costs['months'], 2)) ?> bulan,
      <?= num((int)$costs['days']) ?> hari). Jadi nominal bulanan pada rentang 3 bulan menjadi
      <?= e(num($costs['months'], 2)) ?> × nominal — itulah sebabnya angkanya tidak selalu sama persis
      dengan nominal yang diisi. Perhitungannya memakai <strong>panjang bulan sebenarnya</strong>, sehingga
      rentang 3 bulan penuh tepat 3 × nominal (tanpa pembulatan +Rp 564.685 seperti hitungan hari 30,4375).
    </div>
  </div>
</div>

<?php /* ---------- Laba Bersih (kartu sendiri, di ATAS kartu per cabang) ---------- */ ?>
<div class="card" id="lababersih">
  <div class="card-head"><h3>Laba Bersih <span class="muted small"><?= $series['granularity'] === 'harian' ? 'harian' : 'bulanan' ?></span></h3>
    <span class="muted"><?= money($series['total']) ?> total pada rentang ini</span></div>
  <div class="card-body">
    <div class="chart-box lg"><canvas id="finChart"></canvas></div>
    <div class="small muted mt-1">Omzet − HPP <?= $withCosts ? '− biaya operasional' : '' ?> per
      <?= $series['granularity'] === 'harian' ? 'hari' : 'bulan' ?>.</div>
  </div>
</div>

<?php if ($series['branches']): ?>
<div class="card">
  <div class="card-head"><h3>Laba Bersih per Cabang</h3>
    <span class="muted">Perbandingan pada rentang yang sama</span></div>
  <div class="card-body">
    <div class="chart-box"><canvas id="finBranchChart"></canvas></div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Rincian per Cabang</h3>
    <span class="muted"><?= num(count($perBranch)) ?> cabang</span></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Cabang</th><th class="num">Omzet</th><th class="num">HPP Treatment</th><th class="num">HPP Produk</th>
      <th class="num">HPP Paket</th>
      <?php if ($withCosts): ?><th class="num">Biaya Operasional</th><th class="num">di antaranya biaya bersama</th><?php endif; ?>
      <th class="num">Laba Bersih</th><th class="num">Margin</th></tr></thead>
    <tbody>
      <?php foreach ($perBranch as $b): ?>
        <tr>
          <td><strong><?= e(branch_short_label($b['branch'])) ?></strong>
            <div class="small muted"><?= num($b['trx']) ?> transaksi</div></td>
          <td class="num"><?= money($b['omzet']) ?></td>
          <td class="num"><?= money($b['hpp_treatment']) ?></td>
          <td class="num"><?= money($b['hpp_produk']) ?></td>
          <td class="num"><?= money($b['hpp_paket']) ?></td>
          <?php if ($withCosts): ?>
            <?php
            $bCompany = 0.0;
            foreach (($b['biaya_rows'] ?? []) as $br) if (!empty($br['is_company'])) $bCompany += (float)$br['share'];
            ?>
            <td class="num">− <?= money($b['biaya_total']) ?></td>
            <td class="num small muted"><?= money($bCompany) ?>
              (<?= num(($b['biaya_company_share'] ?? 0) * 100, 1) ?>%)</td>
          <?php endif; ?>
          <td class="num"><strong><?= money($b['laba_bersih']) ?></strong></td>
          <td class="num"><?= $b['margin'] !== null ? num($b['margin'], 1) . '%' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>TOTAL</th><th class="num"><?= money($sum['omzet']) ?></th>
      <th class="num"><?= money($sum['hpp_treatment']) ?></th><th class="num"><?= money($sum['hpp_produk']) ?></th>
      <th class="num"><?= money($sum['hpp_paket']) ?></th>
      <?php if ($withCosts): ?>
        <?php $sumCompany = 0.0; foreach ($costs['rows'] as $cr) if (!empty($cr['is_company'])) $sumCompany += (float)$cr['share']; ?>
        <th class="num">− <?= money($sum['biaya_total']) ?></th>
        <th class="num small"><?= money($sumCompany) ?></th>
      <?php endif; ?>
      <th class="num"><?= money($sum['laba_bersih']) ?></th>
      <th class="num"><?= $sum['margin'] !== null ? num($sum['margin'], 1) . '%' : '-' ?></th></tr></tfoot>
  </table></div>
  <?php if ($withCosts): ?>
  <div class="card-body" style="border-top:1px solid var(--line)">
    <div class="notice small">
      <strong>Biaya bersama</strong> (yang ditandai <em>Semua cabang</em>) dibagi ke setiap cabang sesuai
      <strong>porsi omzetnya</strong> pada periode ini, sehingga total biaya seluruh cabang sama dengan
      biaya pada tampilan "Semua Cabang" dan laba bersih per cabang tidak tampak rugi hanya karena
      biaya bersama dibebankan berkali-kali. Angka porsi tiap cabang terlihat pada kolom
      "di antaranya biaya bersama".
    </div>
  </div>
  <?php endif; ?>
</div>

<?php /* ---------- Biaya operasional: SATU pilihan cakupan di atas + SATU tombol simpan ---------- */ ?>
<?php if ($withCosts): ?>
<div class="card" id="biayaoperasional">
  <div class="card-head">
    <h3>Biaya Operasional</h3>
    <span class="muted"><?= num(count($costs['rows'])) ?> pos dihitung · <?= money($sum['biaya_total']) ?>
      untuk <?= num((int)$costs['days']) ?> hari</span>
  </div>
  <div class="card-body">
    <div class="alert alert-info">
      Isi <strong>nominal</strong> dan pilih <strong>periode pembayarannya</strong> sesuai kenyataan —
      mis. sewa bangunan dibayar <em>setiap 1 tahun</em>, sedangkan gaji/listrik &amp; air/marketing/pajak
      <em>setiap bulan</em>. Sistem otomatis menghitung porsi biaya untuk periode laporan yang dipilih.
      <br><br><strong>Cara mengisi:</strong> pilih <strong>cakupan</strong> di bawah ini lebih dulu
      (<em>Semua cabang</em> atau satu cabang), isi nominal untuk cakupan itu, lalu tekan
      <strong>Simpan</strong> <em>satu kali</em> di bawah daftar. Nominal tiap cakupan tersimpan
      sendiri-sendiri, jadi gaji cabang Kaliwungu dan gaji cabang Cepiring dapat diisi berbeda tanpa
      membuat daftar menjadi panjang walau cabangnya bertambah.
      <br><br><strong>Aturan perhitungannya (penting):</strong>
      <ul class="small" style="margin:6px 0 0 18px;padding:0">
        <li><strong>Mengisi cakupan sebuah cabang</strong> (mis. Kaliwungu) → pos biaya itu memakai
          nominal <strong>PER CABANG</strong> dan <strong>SEMUA cabang yang terisi ikut dihitung
          bersama-sama</strong>. Jadi setelah mengisi Kaliwungu lalu Cepiring, keduanya terhitung
          (bukan saling menggantikan) — biaya Gaji = Kaliwungu + Cepiring.</li>
        <li><strong>Mengisi cakupan “Semua Cabang”</strong> → pos biaya itu memakai
          <strong>biaya bersama</strong> dan menggantikan seluruh isian per cabangnya (nilainya tetap
          tersimpan, bisa dipakai kembali kapan saja dengan menyimpan ulang cakupan cabangnya).
          Saat laporan difilter ke satu cabang, biaya bersama hanya <strong>sebagian</strong> yang
          dibebankan ke cabang itu — <strong>dibagi rata ke <?= num($costBranchN) ?> cabang</strong>.</li>
      </ul>
      Dengan aturan ini <strong>jumlah laba seluruh cabang selalu sama dengan laba “Semua Cabang”</strong>
      dan tidak ada cabang yang tampak minus.
    </div>

    <?php /* 1) PILIHAN CAKUPAN — di ATAS daftar biaya (permintaan pemilik). */ ?>
    <form class="filter-bar" method="get" id="costScopeForm">
      <?php foreach (['period' => $f['period'], 'start' => $f['ps'], 'end' => $f['pe'],
                      'branch' => ($f['scope'] !== null ? (string)$f['scope'] : ''),
                      'status' => $f['status'], 'mode_preview' => $previewing ? $mode : '',
                      'keep' => gp('keep')] as $hk => $hv): ?>
        <?php if ((string)$hv !== ''): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e((string)$hv) ?>"><?php endif; ?>
      <?php endforeach; ?>
      <?php /* JANGAN memakai min-width besar di sini: di layar sempit (≤320px) kolom
               menjadi lebih lebar daripada layar sehingga halaman bergeser ke samping.
               Lebarnya cukup dari CSS (.filter-bar .field). */ ?>
      <div class="field" style="flex:1 1 240px">
        <label>Cakupan biaya yang diatur</label>
        <select class="input input-sm" name="cost_scope" id="costScope" data-cost-scope
                data-current="<?= (int)$costScope ?>">
          <option value="0"<?= $costScope === 0 ? ' selected' : '' ?>>Semua Cabang (biaya bersama)</option>
          <?php foreach (branches() as $bb): ?>
            <option value="<?= (int)$bb['id'] ?>"<?= $costScope === (int)$bb['id'] ? ' selected' : '' ?>><?= e((string)$bb['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Daftar di bawah menampilkan isian cakupan ini. Ganti cakupan untuk mengisi
          nominal cabang lain — jumlah pilihan bertambah sendiri bila cabang bertambah.
          Cakupan "Semua Cabang" adalah biaya bersama yang <strong>dibagi rata ke
          <?= num($costBranchN) ?> cabang</strong>.</span>
      </div>
      <button class="btn btn-sm" type="submit"><?= icon('search') ?> Tampilkan</button>
      <span class="badge <?= $costScope === 0 ? 'badge-yellow' : 'badge-green' ?>">Sedang diisi: <?= e($costScopeLbl) ?></span>
    </form>

    <?php /* Kemajuan pengisian tiap cakupan + ajakan melanjutkan ke cabang lain
       (permintaan pemilik: "kalo sudah ngisi cabang 1 maka lanjutkan isi cabang lain"). */ ?>
    <div class="alert <?= $scopeProgress['missing'] ? 'alert-warning' : 'alert-info' ?>" id="costScopeProgress">
      <strong>Cakupan biaya yang sudah terisi:</strong>
      <?php foreach ($scopeProgress['scopes'] as $sp): $aktif = ((int)$sp['scope'] === $costScope); ?>
        <span class="badge <?= (int)$sp['filled'] === 0 ? 'badge-yellow' : ($aktif ? 'badge-blue' : 'badge-green') ?>">
          <?= e((string)$sp['label']) ?>: <?= num((int)$sp['filled']) ?> pos<?= (int)$sp['filled'] === 0 ? ' (belum diisi)' : '' ?>
        </span>
      <?php endforeach; ?>
      <?php if ($scopeProgress['missing']): ?>
        <div class="mt-1 small">
          <strong>Lanjutkan mengisi cakupan cabang:</strong>
          <?= e(implode(', ', $scopeProgress['missing'])) ?>.
          Biaya per cabang dihitung untuk <strong>semua cabang yang terisi</strong>, jadi isian tiap
          cabang tidak menggantikan cabang lain — isi satu per satu sampai semua cabang selesai.
        </div>
        <div class="flex gap-sm flex-wrap mt-1">
          <?php foreach ($scopeProgress['scopes'] as $sp): ?>
            <?php if ((int)$sp['scope'] === 0 || (int)$sp['scope'] === $costScope) continue; ?>
            <a class="btn btn-sm<?= (int)$sp['filled'] === 0 ? ' btn-primary' : '' ?>"
               href="keuangan.php?<?= e(qs(['cost_scope' => (int)$sp['scope']], [])) ?>"
               data-cost-scope-link="<?= (int)$sp['scope'] ?>">
              <?= icon('edit') ?> <?= (int)$sp['filled'] === 0 ? 'Isi' : 'Ubah' ?> cakupan <?= e((string)$sp['label']) ?>
            </a>
          <?php endforeach; ?>
          <a class="btn btn-sm" href="keuangan.php?<?= e(qs(['cost_scope' => 0], [])) ?>"
             data-cost-scope-link="0"><?= icon('settings') ?> Ubah cakupan Semua Cabang</a>
        </div>
      <?php else: ?>
        <div class="mt-1 small">Semua cakupan cabang sudah terisi dan ikut dihitung bersama-sama.</div>
      <?php endif; ?>
    </div>

    <?php if ($costScope === 0 && $hasBranchApplied): ?>
      <div class="alert alert-warning" id="costReplaceWarn">
        <strong>Perhatian — isian per cabang bisa tergantikan.</strong>
        Cakupan <em>Semua Cabang</em> berlaku untuk seluruh cabang. Saat ini biaya berikut diisi
        <strong>per cabang</strong>: <strong><?= e($branchAppliedTxt) ?></strong>.
        Bila Anda mengisi pos biaya di bawah ini pada cakupan <em>Semua Cabang</em> lalu menekan Simpan,
        isian PER CABANG untuk pos biaya itu <strong>tidak berlaku lagi</strong> (nominalnya tetap
        tersimpan dan dapat dipakai kembali kapan saja dengan membuka cakupan cabangnya lalu menekan
        Simpan). Karena itu penyimpanan cakupan ini meminta <strong>konfirmasi 2 kali</strong>.
      </div>
    <?php endif; ?>

    <?php if ($costScope > 0 && $companyModeNames): ?>
      <div class="notice small" id="costScopeSwitchNote">
        <strong>Cakupan per cabang lebih khusus daripada "Semua cabang".</strong>
        Pos biaya berikut sekarang memakai cakupan <em>Semua cabang (biaya bersama)</em>:
        <strong><?= e($companyModeTxt) ?></strong>.
        Menyimpan nominal pada cakupan ini membuat pos biaya itu kembali memakai
        <strong>nominal PER CABANG</strong> (semua cabang yang terisi ikut dihitung) dan nominal
        "Semua cabang"-nya berhenti dipakai — nilainya tetap tersimpan dan dapat dipakai kembali
        kapan saja dengan membuka cakupan <em>Semua cabang</em> lalu menekan Simpan.
      </div>
    <?php endif; ?>

    <?php /* 2) DAFTAR BIAYA CAKUPAN TERPILIH + SATU TOMBOL SIMPAN DI BAWAH. */ ?>
    <form method="post" id="costForm"
      data-branch-costs="<?= e(implode(',', $branchAppliedCostIds)) ?>"
      <?php if ($costScope === 0 && $hasBranchApplied): ?>
        data-heavy-confirm="SIMPAN SEMUA CABANG"
        data-heavy-warning="Cakupan <strong>Semua Cabang</strong> berlaku untuk seluruh cabang, jadi pos biaya yang Anda isi di sini akan <strong>menggantikan</strong> isian PER CABANG-nya (saat ini: <?= e($branchAppliedTxt) ?>). Nominal per cabangnya tetap tersimpan dan dapat dipakai kembali kapan saja dengan membuka cakupan cabang tersebut lalu menekan Simpan."
        data-heavy-confirm2="PERINGATAN KEDUA (terakhir): simpan biaya cakupan &quot;Semua Cabang&quot; dan nonaktifkan isian per cabang untuk pos biaya yang diisi?"
      <?php endif; ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cost_save_scope">
      <input type="hidden" name="cost_scope" value="<?= (int)$costScope ?>">
      <input type="hidden" name="cost_id" id="costDeleteId" value="">
      <?php /* Penanda aksi disimpan di kolom tersembunyi (BUKAN pada tombol):
               form dengan konfirmasi 2x dikirim lewat form.submit() sehingga
               nilai tombol yang diklik TIDAK ikut terkirim. */ ?>
      <input type="hidden" name="do" id="costDoAction" value="save">
      <input type="hidden" name="keep" value="<?= e(qs([], [])) ?>">
      <div class="fc-list">
        <div class="fc-head">
          <span>Nama Biaya</span><span>Periode Pembayaran</span><span>Nominal (Rp) — <?= e($costScopeLbl) ?></span>
          <span>Per Bulan</span><span>Porsi Periode Ini</span><span>Dihitung</span><span>Yang berlaku</span><span>Aksi</span>
        </div>
        <?php foreach ($costRows as $c): $cActive = $c['status'] === 'active'; ?>
          <?php $share = null; foreach ($costs['rows'] as $cr) if ((int)$cr['id'] === (int)$c['id']) $share = $cr; ?>
          <div class="fc-row<?= $cActive ? '' : ' fc-off' ?>">
            <span class="fc-cell"><span class="fc-lbl">Nama Biaya</span>
              <input class="input input-sm" name="name[<?= (int)$c['id'] ?>]" value="<?= e($c['name']) ?>" maxlength="60">
              <span class="small muted">berlaku untuk semua cakupan</span></span>
            <span class="fc-cell"><span class="fc-lbl">Periode Pembayaran</span>
              <select class="input input-sm" name="period[<?= (int)$c['id'] ?>]">
                <?php foreach (finance_period_options() as $pk => $pv): ?>
                  <option value="<?= (int)$pk ?>"<?= (int)$c['period_months'] === (int)$pk ? ' selected' : '' ?>><?= e($pv) ?></option>
                <?php endforeach; ?>
              </select></span>
            <span class="fc-cell"><span class="fc-lbl">Nominal (Rp) — <?= e($costScopeLbl) ?></span>
              <input class="input input-sm" type="text" inputmode="numeric" name="amount[<?= (int)$c['id'] ?>]"
                     value="<?= $c['amount'] > 0 ? e((string)(int)round($c['amount'])) : '' ?>"
                     placeholder="0" data-cost-amount data-cost-id="<?= (int)$c['id'] ?>"></span>
            <span class="fc-cell fc-num"><span class="fc-lbl">Per Bulan</span>
              <?= $c['amount'] > 0 ? money($c['amount'] / max(1, $c['period_months'])) : '<span class="muted">—</span>' ?></span>
            <span class="fc-cell fc-num"><span class="fc-lbl">Porsi Periode Ini</span>
              <?php if (!$c['counted']): ?>
                <span class="muted">tidak dihitung</span>
              <?php elseif (!$share): ?>
                <span class="muted">—</span>
              <?php else: ?>
                <?php /* Porsi SEMUA cakupan yang dihitung untuk pos ini (per cabang
                         dijumlahkan), bukan hanya cakupan yang sedang diisi. */ ?>
                <?= money($share['share']) ?>
                <?php if (count($share['parts']) > 1): ?>
                  <span class="small muted"><?= num(count($share['parts'])) ?> cakupan</span>
                <?php endif; ?>
              <?php endif; ?></span>
            <span class="fc-cell"><span class="fc-lbl">Dihitung</span>
              <label class="check"><input type="checkbox" name="active[<?= (int)$c['id'] ?>]" value="1"<?= $cActive ? ' checked' : '' ?>> <span>hitung</span></label>
              <span class="small muted"><?= $c['amount'] > 0 ? money($c['amount']) : 'nominal belum diisi' ?></span></span>
            <span class="fc-cell"><span class="fc-lbl">Yang berlaku</span>
              <?php if ($c['mode'] === 'company'): ?>
                <?= badge('Semua cabang (biaya bersama)', 'yellow') ?>
                <span class="small muted">nominal per cabang tidak dipakai<?= $c['branch_filled'] ? ' (' . num($c['branch_filled']) . ' cabang terisi, tetap tersimpan)' : '' ?></span>
              <?php else: ?>
                <?= badge('Per cabang', 'green') ?>
                <span class="small muted"><?= e($c['applied_label']) ?>
                  <?= $c['branch_filled'] > 0 && $c['branch_filled'] < $c['branch_total']
                      ? ' · ' . num($c['branch_filled']) . ' dari ' . num($c['branch_total']) . ' cabang terisi' : '' ?></span>
              <?php endif; ?></span>
            <span class="fc-cell fc-actions">
              <button class="btn btn-sm btn-danger" type="submit"
                      data-cost-delete="<?= (int)$c['id'] ?>"
                      data-confirm="Hapus pos biaya &quot;<?= e($c['name']) ?>&quot; dari SEMUA cakupan?">Hapus</button>
            </span>
          </div>
        <?php endforeach; ?>
        <?php if (!$costRows): ?>
          <div class="fc-empty muted">Belum ada pos biaya. Tambahkan pos pertama di bawah.</div>
        <?php endif; ?>
      </div>
      <div class="flex gap-sm mt-2 flex-wrap" style="align-items:center">
        <button class="btn btn-primary" type="submit" id="costSaveBtn">
          <?= icon('save') ?> Simpan Biaya Cakupan <?= e($costScopeLbl) ?></button>
        <div class="unsaved-note" id="costDirtyNote" role="status" aria-live="polite">
          <span class="ico-wrap"><?= icon('bell') ?></span>
          <span>Isian biaya di atas <strong>belum disimpan</strong>. Tekan
            <strong>Simpan</strong> agar tersimpan — bila Anda pindah cakupan atau keluar tanpa
            menyimpan, isian pada cakupan ini tidak ikut tersimpan.</span>
        </div>
        <span class="muted small">Satu tombol simpan untuk seluruh daftar cakupan ini.</span>
      </div>
    </form>

    <?php /* 3) TAMBAH POS BIAYA BARU (langsung pada cakupan yang sedang diisi). */ ?>
    <div class="section-title">Tambah Pos Biaya Baru</div>
    <form method="post" class="filter-bar">
      <?= csrf_field() ?><input type="hidden" name="action" value="cost_add">
      <input type="hidden" name="cost_scope" value="<?= (int)$costScope ?>">
      <input type="hidden" name="keep" value="<?= e(qs([], [])) ?>">
      <div class="field"><label>Nama Pos Biaya</label>
        <input class="input input-sm" name="name" placeholder="mis. Gaji / Listrik &amp; Air / Sewa bangunan" maxlength="60" required>
        <span class="hint">Pos biaya dipakai bersama semua cakupan — jadi pos baru langsung muncul juga
          saat Anda membuka cakupan cabang lain (tinggal diisi nominalnya).</span></div>
      <div class="field"><label>Periode Pembayaran</label>
        <select class="input input-sm" name="period_months">
          <?php foreach (finance_period_options() as $pk => $pv): ?>
            <option value="<?= (int)$pk ?>"<?= $pk === 1 ? ' selected' : '' ?>><?= e($pv) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Nominal (Rp) — <?= e($costScopeLbl) ?></label>
        <input class="input input-sm" type="text" inputmode="numeric" name="amount" placeholder="0"></div>
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('plus-circle') ?> Tambah Pos Biaya</button>
    </form>

    <?php /* 4) RINGKASAN: cakupan mana yang sedang DIHITUNG untuk tiap pos biaya.
       Satu baris per pos biaya (bukan per cabang) supaya tetap ringkas walau
       cabangnya banyak. */ ?>
    <div class="section-title">Ringkasan Cakupan yang Berlaku</div>
    <?php if ($costScopeOverview): ?>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Pos Biaya</th><th>Cakupan yang dihitung</th><th>Rincian per cabang</th>
          <th class="num">Nominal</th><th class="num">Porsi periode ini</th></tr></thead>
        <tbody>
          <?php foreach ($costScopeOverview as $o): ?>
            <tr>
              <td><?= e($o['name']) ?></td>
              <td><?= badge($o['scope'], $o['is_company'] ? 'yellow' : 'green') ?></td>
              <td class="small muted"><?= e($o['detail'] !== '' ? $o['detail'] : '—') ?></td>
              <td class="num"><?= money($o['amount']) ?></td>
              <td class="num"><?= money($o['share']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="notice small mt-2">
        Hanya nominal pada cakupan di atas yang ikut dihitung pada laporan. Nominal cakupan lain tetap
        tersimpan sebagai pilihan — buka cakupannya lalu tekan Simpan untuk memakainya kembali.
      </div>
    <?php else: ?>
      <div class="fc-empty muted">Belum ada pos biaya yang dipakai pada laporan.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
/* Grafik: pakai helper bersama (chart_js.php) + Naveena.palette dari app.js. */
$PAGE_SCRIPTS = [];
ob_start();
?>
<script src="assets/vendor/chart.umd.min.js"></script>
<script><?= income_charts_js() ?></script>
<script>
(function () {
  var PAL = Naveena.paletteFor(12);
  var money = Naveena.rupiah, short = Naveena.rupiahShort;
  Naveena.chart('finChart', {
    type: 'bar',
    data: {
      labels: <?= js_json($series['labels']) ?>,
      datasets: [
        { label: 'Laba Bersih', data: <?= js_json($series['laba']) ?>,
          backgroundColor: <?= js_json($series['laba']) ?>.map(function (v) { return v >= 0 ? Naveena.series('positif') : Naveena.series('negatif'); }),
          borderRadius: 4, maxBarThickness: 34 }
      ]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { callbacks: { label: function (c) { return 'Laba bersih: ' + money(c.parsed.y); } } } },
      scales: { x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } },
        y: { beginAtZero: true, ticks: { callback: function (v) { return short(v); }, font: { size: 10 } }, grid: { drawTicks: false } } } }
  });
  <?php if ($series['branches']): ?>
  (function () {
    var dat = <?= js_json(array_map(function ($name, $vals) {
        return ['label' => branch_short_label((string)$name), 'data' => $vals, 'tension' => .3,
                'borderWidth' => 2.2, 'pointRadius' => 3, 'pointBorderColor' => '#fff', 'pointBorderWidth' => 1.2,
                'pointBackgroundColor' => ''];
    }, array_keys($series['branches']), array_values($series['branches']))) ?>;
    var cols = Naveena.paletteFor(dat.length);
    dat.forEach(function (d, i) {
      d.borderColor = cols[i]; d.pointBackgroundColor = cols[i]; d.backgroundColor = 'transparent';
      if (dat.length > 8 && i % 2 === 1) d.borderDash = [6, 4];
    });
    Naveena.chart('finBranchChart', {
      type: 'line',
      data: { labels: <?= js_json($series['labels']) ?>, datasets: dat },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
          tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.parsed.y); } } } },
        scales: { x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } } },
          y: { ticks: { callback: function (v) { return short(v); }, font: { size: 10 } }, grid: { drawTicks: false } } } }
    });
  })();
  <?php endif; ?>
})();
</script>
<script>
/* ============================================================================
 * BIAYA OPERASIONAL — satu pilihan cakupan di atas + satu tombol simpan.
 *
 *  1. Berpindah cakupan saat masih ada isian yang belum disimpan → tanya dulu
 *     (isian lama tidak hilang begitu saja).
 *  2. Menyimpan cakupan "Semua cabang" memakai konfirmasi 2 TAHAP karena isian
 *     per cabang untuk pos biaya yang diisi menjadi tidak berlaku. Bila tidak
 *     ada nominal yang diisi, konfirmasi itu dilewati supaya tidak mengganggu
 *     (penanganan klik app.js dihentikan lewat stopPropagation + heavyOk).
 *  3. Tombol Hapus (di dalam form yang sama) memakai konfirmasi sederhana dan
 *     mengisi kolom tersembunyi cost_id lebih dulu.
 * ========================================================================== */
(function () {
  var scopeForm = document.getElementById('costScopeForm');
  var scopeSel = document.getElementById('costScope');
  var costForm = document.getElementById('costForm');
  if (!costForm) return;
  var guard = (window.Naveena && Naveena.dirtyGuard) ? Naveena.dirtyGuard(costForm, {
    note: '#costDirtyNote', mark: '#costSaveBtn', confirmSave: false,
    leaveMessage: 'Isian biaya operasional BELUM disimpan.\n\n'
      + 'Klik OK untuk tetap meninggalkan halaman (isian pada cakupan ini tidak tersimpan), '
      + 'atau Cancel untuk kembali dan menekan tombol Simpan.'
  }) : null;

  if (scopeSel && scopeForm) {
    scopeSel.addEventListener('change', function () {
      if (guard && guard.isDirty()
          && !window.confirm('Isian pada cakupan ini belum disimpan. Pindah cakupan dan tinggalkan perubahan?')) {
        scopeSel.value = scopeSel.getAttribute('data-current') || '0';
        return;
      }
      if (guard) guard.reset();           /* jangan ditanya dua kali saat halaman berpindah */
      scopeForm.submit();
    });
  }

  var isCompany = (String(costForm.querySelector('input[name=cost_scope]').value) === '0');
  var saveBtn = document.getElementById('costSaveBtn');
  if (saveBtn) saveBtn.addEventListener('click', function (ev) {
    if (isCompany) {
      /* Hanya pos biaya yang PUNYA isian per cabang yang perlu dikonfirmasi —
         kalau nominal pos itu tidak diisi, tidak ada yang tergantikan. */
      var branchCosts = (costForm.getAttribute('data-branch-costs') || '').split(',').filter(Boolean);
      var filled = Array.prototype.some.call(costForm.querySelectorAll('[data-cost-amount]'), function (inp) {
        if (branchCosts.indexOf(String(inp.getAttribute('data-cost-id'))) < 0) return false;
        var v = String(inp.value).replace(/\./g, '').replace(',', '.');
        return parseFloat(v) > 0;
      });
      if (filled) {
        costForm.dataset.heavyOk = '';     /* konfirmasi 2x dijalankan app.js */
      } else {
        /* Tidak ada nominal yang diisi → tidak ada isian per cabang yang
           tergantikan, jadi konfirmasi dilewati. */
        costForm.dataset.heavyOk = '1';
        ev.stopPropagation();
      }
    }
    if (guard) guard.reset();
  });

  /* Tombol Hapus: satu form dengan daftar biaya, jadi id-nya ditulis ke kolom
     tersembunyi lebih dulu (dan dikonfirmasi lebih dulu juga). */
  var costDo = document.getElementById('costDoAction');
  if (costDo) costDo.value = 'save';
  var delId = document.getElementById('costDeleteId');
  if (delId) costForm.querySelectorAll('button[data-cost-delete]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      var pesan = btn.getAttribute('data-confirm') || 'Hapus pos biaya ini?';
      if (!window.confirm(pesan)) { ev.preventDefault(); return; }
      delId.value = btn.getAttribute('data-cost-delete');
      costDo.value = 'delete';
      /* Menghapus pos biaya bukan "menyimpan cakupan", jadi konfirmasi 2x milik
         penyimpanan tidak dipakai (dan penanganan app.js dihentikan). */
      costForm.dataset.heavyOk = '1';
      ev.stopPropagation();
      if (guard) guard.reset();
    });
  });
})();
</script>
<?php
$PAGE_SCRIPTS[] = ob_get_clean();
audit('Lihat Laporan Keuangan', 'Keuangan', null, null,
    ['periode' => $f['ps'] . ' s.d. ' . $f['pe'], 'mode' => $mode, 'omzet' => $sum['omzet'], 'laba_bersih' => $sum['laba_bersih']],
    'Laporan keuangan dibuka');
page_foot();
