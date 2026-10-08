<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/patient_form.php';
require_perm('patient.view');

$id = (int)gp('id');
$p  = one('SELECT p.*, b.name AS branch_name, u.name AS created_by_name
           FROM patients p JOIN branches b ON b.id = p.branch_id
           LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?', [$id]);
if (!$p) {
    flash('Data pasien tidak ditemukan.', 'error');
    header('Location: pasien.php');
    exit;
}
assert_branch((int)$p['branch_id']);

/* ---- Aksi kartu member (aktifkan / cabut) — dijalankan petugas yang berhak ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_perm('patient.manage');
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'member_grant') {
            $newCard = grant_member_card($id, 'manual');
            audit('Aktifkan Kartu Member', 'Pasien', $id, ['member' => 0], ['member' => 1, 'sumber' => 'manual'],
                'Kartu member diaktifkan dari halaman detail pasien');
            flash($newCard
                ? 'Kartu member diaktifkan. Kartu digital siap diunduh & dicetak.'
                : 'Pasien ini sudah memiliki kartu member.');
        } elseif ($act === 'member_revoke') {
            revoke_member_card($id);
            audit('Cabut Kartu Member', 'Pasien', $id, ['member' => 1], ['member' => 0], 'Status member dicabut petugas');
            flash('Status member dicabut (nomor member tetap tersimpan).', 'warning');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: pasien_detail.php?id=' . $id);
    exit;
}

$period = gp('period', 'month');
[$ps, $pe] = resolve_period($period, gp('start'), gp('end'));

/* SATU PERIODE UNTUK SEMUA DAFTAR (permintaan pemilik): filter periode di panel
   atas berlaku untuk KETIGA daftar di halaman ini — riwayat transaksi, riwayat
   reservasi, dan riwayat rekam medis. Filter terpisah per kartu sudah DIHAPUS
   supaya tidak ada dua pengaturan tanggal yang membingungkan.
   Bawaannya "Bulan ini"; pilih "Seluruh riwayat" untuk melihat semuanya. */
$histLabel = $period === 'all' ? 'seluruh riwayat' : 'periode ' . tgl($ps) . ' — ' . tgl($pe);

$sum = one("SELECT COUNT(*) trx, COALESCE(SUM(total),0) total FROM orders WHERE patient_id=? AND status='paid'", [$id]);
$qty = one("SELECT
      COALESCE(SUM(CASE WHEN oi.item_type='treatment' THEN oi.quantity ELSE 0 END),0) tr_qty,
      COALESCE(SUM(CASE WHEN oi.item_type='skincare'  THEN oi.quantity ELSE 0 END),0) sk_qty,
      COALESCE(SUM(CASE WHEN oi.item_type='material'  THEN oi.quantity ELSE 0 END),0) mat_qty
    FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.patient_id=? AND o.status='paid'", [$id]);
$last = one("SELECT MAX(created_at) last FROM orders WHERE patient_id=? AND status='paid'", [$id]);
/* INFO LEVEL MEMBER (permintaan pemilik): ditampilkan di kartu Profil Pasien tepat di
   bawah Nomor Member. Level & akumulasi dihitung dari transaksi berstatus dibayar pada
   periode yang berlaku (aturan yang SAMA dengan kartu member & diskon) — bukan dari
   kolom yang bisa basi. Bila pasien belum pemegang kartu, keadaannya dinyatakan apa adanya. */
$isMemberNow = patient_is_member($p);
$memberInfo = $isMemberNow ? member_status($p) : null;

/* ------------------------------------------------------------------ *
 * DAFTAR BERHALAMAN PER KARTU (permintaan pemilik)
 * ------------------------------------------------------------------ *
 * Dulu keempat daftar di halaman ini menampilkan SEMUA baris (transaksi tanpa
 * batas, reservasi & rekam medis `LIMIT 100`), sehingga memilih periode
 * "Seluruh riwayat" membuat halaman sangat panjang dan harus digulir jauh.
 * Sekarang setiap kartu memakai jumlah baris per halaman yang SAMA dengan menu
 * lain (Pengaturan → default 25, dapat dipilih 10/25/50/100) dan punya NOMOR
 * HALAMAN sendiri (`page_trx`, `page_app`, `page_med`, `page_mat`) sehingga
 * membuka halaman 2 pada satu kartu tidak menggeser kartu yang lain. Klik nomor
 * halaman tidak memuat ulang halaman (ditangani app.js lewat `data-pg`).
 */
$perPage = per_page();

$trxPage  = pagination_page('page_trx');
$appsPage = pagination_page('page_app');
$medsPage = pagination_page('page_med');
$matPage  = pagination_page('page_mat');
$off = function (int $page) use ($perPage): int { return max(0, ($page - 1) * $perPage); };

$ordersTotal = (int)scalar('SELECT COUNT(*) FROM orders WHERE patient_id = ? AND date(created_at) BETWEEN ? AND ?', [$id, $ps, $pe]);
$orders = all("SELECT o.*, b.name AS branch_name, u.name AS cashier_name,
                      (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id AND oi.item_type <> 'material') items,
                      (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id AND oi.item_type = 'material') materials
               FROM orders o JOIN branches b ON b.id=o.branch_id LEFT JOIN users u ON u.id=o.user_id
               WHERE o.patient_id=? AND date(o.created_at) BETWEEN ? AND ?
               ORDER BY o.id DESC LIMIT {$perPage} OFFSET " . $off($trxPage), [$id, $ps, $pe]);
/* Reservasi & rekam medis juga MENGIKUTI filter periode di atas. Sebelumnya
   keduanya selalu menampilkan seluruh riwayat, sehingga data di luar rentang
   tanggal tetap muncul walau filter sudah diterapkan (dilaporkan pemilik). */
$appsTotalPeriod = (int)scalar('SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND date BETWEEN ? AND ?', [$id, $ps, $pe]);
$apps = all("SELECT a.*, t.name AS treatment_name, d.name AS doctor_name, th.name AS therapist_name, b.name AS branch_name,
                     (SELECT GROUP_CONCAT(COALESCE(t2.name, ''), ', ')
                        FROM appointment_treatments at2 LEFT JOIN treatments t2 ON t2.id = at2.treatment_id
                       WHERE at2.appointment_id = a.id) AS treatments_all
             FROM appointments a LEFT JOIN treatments t ON t.id=a.treatment_id
             LEFT JOIN doctors d ON d.id=a.doctor_id LEFT JOIN therapists th ON th.id=a.therapist_id
             JOIN branches b ON b.id=a.branch_id
             WHERE a.patient_id=? AND a.date BETWEEN ? AND ?
             ORDER BY a.date DESC, a.id DESC LIMIT {$perPage} OFFSET " . $off($appsPage), [$id, $ps, $pe]);
$appsTotal = (int)scalar('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$id]);
$meds = [];
$medsAll = 0;
$medsTotalPeriod = 0;
if (has_perm('medical.view')) {
    /* Nama dokter & terapis di-join TERPISAH supaya keduanya bisa tampil — kolom
       `staff_name` tidak pernah ada pada query ini sehingga kolom "Dokter/Terapis"
       selalu tampil "-" (bug kecil yang ikut diperbaiki). */
    $medsTotalPeriod = (int)scalar('SELECT COUNT(*) FROM medical_records WHERE patient_id = ? AND date BETWEEN ? AND ?', [$id, $ps, $pe]);
    $meds = all('SELECT m.*, b.name AS branch_name, d.name AS doctor_name, th.name AS therapist_name
                 FROM medical_records m JOIN branches b ON b.id=m.branch_id
                 LEFT JOIN doctors d ON d.id=m.doctor_id
                 LEFT JOIN therapists th ON th.id=m.therapist_id
                 WHERE m.patient_id=? AND m.date BETWEEN ? AND ?
                 ORDER BY m.date DESC, m.id DESC LIMIT ' . $perPage . ' OFFSET ' . $off($medsPage), [$id, $ps, $pe]);
    /* Jumlah seluruh rekam medis pasien ini (tanpa filter) supaya petugas tahu
       ada riwayat lain di luar periode terpilih. */
    $medsAll = (int)scalar('SELECT COUNT(*) FROM medical_records WHERE patient_id = ?', [$id]);
}
/* Bahan treatment yang pernah dipakai pada perawatan pasien ini (tidak ditagihkan,
   hanya tercatat sebagai pemakaian) — berguna untuk melihat riwayat pemakaian bahan. */
$matTotal = (int)scalar("SELECT COUNT(DISTINCT oi.item_name) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                         WHERE o.patient_id=? AND o.status='paid' AND oi.item_type='material'", [$id]);
$materials = all("SELECT oi.item_name, oi.item_code, COALESCE(SUM(oi.quantity),0) q,
                         COUNT(DISTINCT o.id) trx, MAX(date(o.created_at)) terakhir, oi.material_id
                  FROM order_items oi JOIN orders o ON o.id=oi.order_id
                  WHERE o.patient_id=? AND o.status='paid' AND oi.item_type='material'
                  GROUP BY oi.item_name ORDER BY q DESC, oi.item_name LIMIT {$perPage} OFFSET " . $off($matPage), [$id]);

page_head('Detail Pasien', 'pasien');

/* Panel filter periode dipakai BERSAMA oleh tiga daftar di halaman ini
   (riwayat transaksi, rekam medis, dan reservasi) supaya tidak ada daftar yang
   diam-diam menampilkan data di luar rentang tanggal yang dipilih. */
function pd_period_bar(int $patientId, string $period, string $ps, string $pe): void
{
    ?>
<div class="card tight">
  <form class="filter-bar" method="get">
    <input type="hidden" name="id" value="<?= $patientId ?>">
    <div class="field"><label>Periode</label>
      <select class="input input-sm" name="period" data-period data-autosubmit>
        <?php foreach (['today' => 'Hari ini', '7d' => '7 hari', 'month' => 'Bulan ini', '3m' => '3 bulan',
                        'year' => 'Tahun ini', 'all' => 'Seluruh riwayat', 'custom' => 'Custom tanggal'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field" data-period-custom style="display:none"><label>Dari</label>
      <input type="date" class="input input-sm" name="start" value="<?= e($ps) ?>"></div>
    <div class="field" data-period-custom style="display:none"><label>Sampai</label>
      <input type="date" class="input input-sm" name="end" value="<?= e($pe) ?>"></div>
    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
    <div class="field"><label>Per halaman</label><?= per_page_inline() ?></div>
    <span class="muted">Berlaku untuk <strong>transaksi, rekam medis, reservasi, dan bahan</strong> di bawah —
      periode terpilih <strong><?= e(tglIndo($ps)) ?> — <?= e(tglIndo($pe)) ?></strong></span>
  </form>
</div>
    <?php
}

?>
<div class="page-head">
  <div>
    <div class="person-cell mb-1"><?= person_avatar('patient', $p, 56) ?>
      <h2 style="margin:0"><?= e($p['name']) ?></h2></div>
    <p class="muted"><?= e($p['patient_number']) ?> · Member <?= e($p['member_number'] ?: '-') ?> · <?= e($p['branch_name']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="pasien.php"><?= icon('users') ?> Daftar Pasien</a>
    <?php if ($p['phone']): ?>
      <a class="btn btn-leaf" target="_blank" rel="noopener" href="<?= e(wa_link($p['phone'], 'Halo Kak ' . $p['name'] . ', kami dari ' . clinic_name() . ' ' . $p['branch_name'] . '. ')) ?>"><?= icon('whatsapp') ?> WhatsApp</a>
    <?php endif; ?>
    <?php if (has_perm('order.manage')): ?>
      <a class="btn btn-primary" href="order_baru.php?patient_id=<?= (int)$p['id'] ?>"><?= icon('plus-circle') ?> Order Baru</a>
    <?php endif; ?>
    <?php if (member_card_enabled() && patient_is_member($p)): ?>
      <a class="btn btn-leaf" href="member_card.php?id=<?= (int)$p['id'] ?>"><?= icon('star') ?> Kartu Member</a>
      <a class="btn" href="member_card.php?id=<?= (int)$p['id'] ?>&format=pdf"><?= icon('download') ?> Kartu PDF</a>
    <?php elseif (member_card_enabled() && has_perm('patient.manage')): ?>
      <form method="post" data-confirm="Aktifkan kartu member untuk pasien ini?">
        <?= csrf_field() ?><input type="hidden" name="action" value="member_grant">
        <button class="btn btn-leaf" type="submit"><?= icon('star') ?> Aktifkan Kartu Member</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid g3">
  <div class="stat accent"><span class="lbl">Total Pengeluaran</span><span class="val"><?= money($sum['total']) ?></span><span class="sub"><?= num($sum['trx']) ?> transaksi</span></div>
  <div class="stat"><span class="lbl">Total Kunjungan</span><span class="val"><?= num($sum['trx']) ?></span><span class="sub">Terakhir: <?= e($last['last'] ? tgl($last['last']) : '-') ?></span></div>
  <div class="stat"><span class="lbl">Total Treatment</span><span class="val"><?= num($qty['tr_qty']) ?></span><span class="sub">tindakan treatment</span></div>
  <div class="stat"><span class="lbl">Total Skincare</span><span class="val"><?= num($qty['sk_qty']) ?></span><span class="sub">produk dibeli</span></div>
  <div class="stat"><span class="lbl">Bahan Treatment Terpakai</span><span class="val"><?= qty_text($qty['mat_qty']) ?></span>
    <span class="sub"><?= $materials ? 'dari ' . num(count($materials)) . ' jenis bahan' : 'tanpa pemakaian bahan' ?></span></div>
  <div class="stat<?= patient_is_member($p) ? ' accent' : '' ?>">
    <span class="lbl">Kartu Member</span>
    <span class="val" style="font-size:1.05rem"><?= patient_is_member($p) ? 'Aktif' : 'Belum ada' ?></span>
    <span class="sub">
      <?php if (patient_is_member($p)): ?>
        sejak <?= e(tgl($p['member_since'] ?: $p['created_at'])) ?> · <?= e(member_source_text($p['member_source'])) ?>
      <?php else: ?>
        <?php if (!member_card_enabled()): ?>
          <strong>Mohon maaf</strong>, fitur member sedang <strong>tidak aktif</strong>
          <?php if (patient_is_member($p)): ?>— kartu &amp; diskon member tidak berlaku sementara ini<?php endif; ?>
        <?php else: ?>
          Otomatis dapat kartu bila transaksi ≥ <?= money(member_activate_amount()) ?>
          atau akumulasi <?= e(member_period_label()) ?> ≥ <?= money(member_base_level()['min_year']) ?>
        <?php endif; ?>
      <?php endif; ?>
    </span>
  </div>
</div>

<?php pd_period_bar((int)$p['id'], $period, $ps, $pe); ?>

<div class="grid g2 mt-2">
  <div class="card">
    <div class="card-head"><h3>Profil Pasien</h3>
      <?php if (has_perm('patient.manage')): ?>
        <button class="btn btn-sm" type="button" data-modal-open="patientModal"><?= icon('edit') ?> Edit</button>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <dl class="kv">
        <dt>Nama Lengkap</dt><dd><?= e($p['name']) ?></dd>
        <dt>No. Pasien</dt><dd><?= e($p['patient_number']) ?></dd>
        <dt>Nomor Member</dt><dd><?= e($p['member_number'] ?: '-') ?></dd>
        <dt>Level Member</dt>
        <dd>
          <?php if ($memberInfo): ?>
            <strong><?= e($memberInfo['level']['label']) ?></strong>
            · diskon <?= num((float)$memberInfo['level']['pct'], (float)$memberInfo['level']['pct'] == (int)$memberInfo['level']['pct'] ? 0 : 1) ?>%
            <div class="small muted">
              Akumulasi <?= e(member_period_label()) ?>: <?= money((float)$memberInfo['year_total']) ?>
              (<?= num((int)$memberInfo['trx']) ?> transaksi)
              <?php if ($memberInfo['next']): ?>
                · menuju <?= e($memberInfo['next']['label']) ?>:
                kurang <?= money(max(0, (float)$memberInfo['next']['min_year'] - (float)$memberInfo['year_total'])) ?>
              <?php else: ?>
                · sudah di level tertinggi
              <?php endif; ?>
            </div>
          <?php elseif (!member_card_enabled()): ?>
            <span class="muted">fitur kartu member sedang tidak aktif</span>
          <?php else: ?>
            <span class="muted">belum ada kartu member</span>
            <div class="small muted">Kartu otomatis diberikan bila transaksi ≥ <?= money(member_activate_amount()) ?>
              atau akumulasi <?= e(member_period_label()) ?> ≥ <?= money(member_base_level()['min_year']) ?>.</div>
          <?php endif; ?>
        </dd>
        <dt>NIK</dt><dd><?= e($p['nik'] ?: '-') ?></dd>
        <dt>Jenis Kelamin</dt><dd><?= e($p['gender'] ?: '-') ?></dd>
        <dt>Tanggal Lahir</dt><dd><?= e($p['birth_date'] ? tgl($p['birth_date']) : '-') ?></dd>
        <dt>Nomor Telepon</dt><dd><?= e($p['phone'] ?: '-') ?></dd>
        <dt>Email</dt><dd><?php if (($p['email'] ?? '') !== ''): ?>
          <a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a>
          <?php else: ?><span class="muted">belum diisi</span>
          <?php if (has_perm('patient.manage')): ?> · <a href="#" onclick="document.querySelector('[data-modal-open=patientModal]').click();return false;">tambahkan</a><?php endif; ?>
        <?php endif; ?></dd>
        <dt>Alamat</dt><dd><?= e($p['address'] ?: '-') ?></dd>
        <dt>Status Pasien</dt><dd><?= badge($p['patient_type'] ?: 'Baru', $p['patient_type'] === 'Lama' ? 'blue' : 'pink') ?></dd>
        <dt>Cabang</dt><dd><?= e($p['branch_name']) ?></dd>
        <dt>Terdaftar</dt><dd><?= e(tgl($p['created_at'], true)) ?><?= $p['created_by_name'] ? ' oleh ' . e($p['created_by_name']) : '' ?></dd>
      </dl>
    </div>
  </div>

  <div class="card tight">
    <div class="card-head"><h3>Riwayat Reservasi</h3>
      <div class="flex gap-sm" style="align-items:center;flex-wrap:wrap">
        <span class="muted"><?= num($appsTotalPeriod) ?> <?= e($histLabel) ?><?php if ($appsTotal > $appsTotalPeriod): ?> dari <?= num($appsTotal) ?><?php endif; ?></span>
        <?php if ($appsTotal > $appsTotalPeriod): ?>
          <span class="badge badge-gray" title="Ada riwayat lain di luar periode terpilih"><?= num($appsTotal) ?> total riwayat</span>
        <?php endif; ?>
      </div></div>
    <div class="table-wrap">
      <?php if (!$apps): ?><?= empty_state($appsTotal > 0
          ? 'Tidak ada reservasi pada periode ini. Pasien ini punya ' . num($appsTotal) . ' riwayat — pilih periode "Seluruh riwayat" untuk melihat semuanya.'
          : 'Belum ada reservasi.') ?><?php else: ?>
      <table class="tbl">
        <thead><tr><th>No. Reservasi</th><th>Tanggal</th><th>Treatment</th><th>Petugas</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($apps as $a): ?>
          <tr>
            <td class="small"><?= e($a['appointment_number']) ?></td>
            <td><?= e(tgl($a['date'])) ?><div class="small muted"><?= e($a['time']) ?></div></td>
            <td><?= e(trim((string)($a['treatments_all'] ?? '')) !== '' ? $a['treatments_all'] : ($a['treatment_name'] ?: '-')) ?></td>
            <td class="small"><?= e($a['doctor_name'] ?: ($a['therapist_name'] ?: '-')) ?></td>
            <td><?= appointment_status_badge($a['status']) ?></td>
            <?php /* Tombol Lihat (permintaan pemilik): membuka reservasi itu di daftar
                     Reservasi (disaring dengan nomor reservasinya). */ ?>
            <td class="nowrap">
              <a class="btn btn-sm" href="reservasi.php?view=list&amp;q=<?= urlencode((string)$a['appointment_number']) ?>">
                <?= icon('search') ?> Lihat</a>
              <?php if (has_perm('reservation.manage')): ?>
                <a class="btn btn-sm" href="reservasi.php?action=edit&amp;id=<?= (int)$a['id'] ?>">Ubah</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?= pagination($appsTotalPeriod, $perPage, $appsPage, 'page_app') ?>
  </div>
</div>

<div class="card tight mt-2">
  <div class="card-head"><h3>Riwayat Transaksi</h3>
    <span class="muted"><?= num($ordersTotal) ?> <?= e($histLabel) ?></span>
  </div>
  <div class="table-wrap">
    <?php if (!$orders): ?><?= empty_state('Belum ada transaksi pada periode ini.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Invoice</th><th>Tanggal</th><th>Kasir</th><th>Cabang</th><th class="num">Item</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_detail.php?id=<?= (int)$o['id'] ?>"><?= e($o['invoice_number']) ?></a></td>
          <td><?= e(tgl($o['created_at'], true)) ?></td>
          <td class="small"><?= e($o['cashier_name'] ?: '-') ?></td>
          <td class="small"><?= e($o['branch_name']) ?></td>
          <td class="num"><?= num($o['items']) ?><?php if ((int)$o['materials'] > 0): ?>
            <div class="small muted">+<?= num($o['materials']) ?> bahan</div><?php endif; ?></td>
          <td class="num"><?= money($o['total']) ?></td>
          <td><?= order_status_badge($o['status']) ?></td>
          <td><a class="btn btn-sm" href="struk.php?id=<?= (int)$o['id'] ?>" target="_blank">Struk</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($ordersTotal, $perPage, $trxPage, 'page_trx') ?>
</div>

<?php if (has_perm('medical.view')): ?>
<div class="card tight mt-2">
  <div class="card-head"><h3>Riwayat Rekam Medis</h3>
    <div class="flex gap-sm" style="align-items:center;flex-wrap:wrap">
      <span class="muted"><?= num($medsTotalPeriod) ?> <?= e($histLabel) ?><?php if ($medsAll > $medsTotalPeriod): ?> dari <?= num($medsAll) ?><?php endif; ?></span>
      <?php if ($medsAll > $medsTotalPeriod): ?>
        <span class="badge badge-gray" title="Ada riwayat lain di luar periode terpilih"><?= num($medsAll) ?> total riwayat</span>
      <?php endif; ?>
      <?php if (has_perm('medical.manage')): ?><a class="btn btn-sm btn-primary" href="rekam_medis_form.php?patient_id=<?= (int)$p['id'] ?>"><?= icon('plus-circle') ?> Rekam Medis Baru</a><?php endif; ?>
    </div>
  </div>
  <div class="table-wrap">
    <?php if (!$meds): ?><?= empty_state($medsAll > 0
        ? 'Tidak ada rekam medis pada periode ini. Pasien ini punya ' . num($medsAll) . ' riwayat — pilih periode "Seluruh riwayat" untuk melihat semuanya.'
        : 'Belum ada rekam medis.') ?><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Nomor</th><th>Tanggal</th><th>Dokter/Terapis</th><th>Assessment / Diagnosa</th><th>ICD-10</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($meds as $m): ?>
        <tr>
          <td class="small"><?= e($m['record_number']) ?></td>
          <td><?= e(tgl($m['date'])) ?></td>
          <td class="small"><?= e(staff_both_text($m['doctor_name'] ?? '', $m['therapist_name'] ?? '') ?: '-') ?></td>
          <td><?= e($m['diagnosis'] ?: '-') ?></td>
          <td class="small"><?= e($m['icd10'] ?: '-') ?></td>
          <td><?= record_status_badge($m['status']) ?></td>
          <td><a class="btn btn-sm" href="rekam_medis_form.php?id=<?= (int)$m['id'] ?>">Lihat</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?= pagination($medsTotalPeriod, $perPage, $medsPage, 'page_med') ?>
</div>
<?php endif; ?>

<?php if ($matTotal > 0): ?>
<div class="card tight mt-2">
  <div class="card-head">
    <h3>Bahan Treatment yang Pernah Dipakai</h3>
    <span class="muted"><?= num($matTotal) ?> jenis bahan · tidak ditagihkan ke pasien</span>
  </div>
  <div class="card-body" style="border-bottom:1px solid var(--line)">
    <div class="notice">Bahan treatment dipakai sebagai pelengkap proses treatment, bukan produk yang dijual.
      Catatan ini hanya untuk riwayat perawatan pasien; nilai bahan tidak masuk tagihan pasien.</div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Bahan</th><th>Kode</th><th class="num">Total Dipakai</th><th class="num">Jumlah Transaksi</th><th>Terakhir Dipakai</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($materials as $m): ?>
        <tr>
          <td><?= e($m['item_name']) ?></td>
          <td class="small"><?= e($m['item_code'] ?: '-') ?></td>
          <td class="num"><?= qty_text($m['q']) ?></td>
          <td class="num"><?= num($m['trx']) ?></td>
          <td><?= e(tgl($m['terakhir'])) ?></td>
          <td><?php if (!empty($m['material_id'])): ?><a class="btn btn-sm" href="bahan.php?action=stock&id=<?= (int)$m['material_id'] ?>">Stok</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($matTotal, $perPage, $matPage, 'page_mat') ?>
</div>
<?php endif; ?>
<?php /* Formulir Edit pasien DI HALAMAN INI (permintaan pemilik): dikirim ke
   pasien.php dengan penanda `back`, sehingga setelah menyimpan pengguna kembali ke
   halaman detail pasien ini — bukan dilempar ke daftar pasien. */
patient_form_modal($p, 'pasien_detail.php?id=' . (int)$p['id'], false); ?>
<?php page_foot(); ?>
