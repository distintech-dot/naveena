<?php
/**
 * MEMBERSHIP UPGRADE — pasien yang NAIK LEVEL kartu membernya.
 *
 * Permintaan pemilik: menu di bawah "Riwayat Order" untuk mengetahui bila ada pasien
 * yang naik level kartu membernya, dengan tabel: No · Nama Pasien · Member ID ·
 * Level Lama · Level Baru · Tgl Upgrade · Total Transaksi · WhatsApp · Email · Status.
 * Bila pasien selesai transaksi dan levelnya naik, aplikasi mengirim OTOMATIS email
 * ucapan selamat + benefit diskon beserta LAMPIRAN PDF kartu lengkap (2 halaman);
 * status terkirim/belum terlihat di tabel dan pengiriman dapat diulang manual.
 *
 * Pengaturan template & sakelar kirim otomatis ada di halaman ini juga (kartu
 * "Pesan & Pengiriman") — pola yang sama dengan menu Keuangan, supaya Pengaturan
 * Sistem tidak menumpuk.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/template_default.php';
require_perm('patient.view');
$user = current_user();
$bolehKirim = has_perm('patient.manage');

$aksi = (string)($_POST['action'] ?? '');

/* ------------------------------------------------------------------ *
 * AKSI: simpan pengaturan pesan & pengiriman
 * ------------------------------------------------------------------ */
if ($aksi === 'setelan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$bolehKirim) deny('Hanya petugas yang boleh mengubah pengaturan pesan Membership Upgrade.');
    $auto = ($_POST['email_member_upgrade_auto'] ?? '0') === '1' ? '1' : '0';
    $subj = trim((string)($_POST['email_member_upgrade_subject'] ?? ''));
    if ($subj === '') $subj = 'Selamat! Level Kartu Member Anda naik menjadi {level}';
    set_setting('email_member_upgrade_auto', $auto);
    set_setting('email_member_upgrade_subject', $subj);
    set_setting('email_member_upgrade_body', (string)($_POST['email_member_upgrade_body'] ?? ''));
    set_setting('wa_member_upgrade_template', (string)($_POST['wa_member_upgrade_template'] ?? ''));
    audit('Ubah Pesan Membership Upgrade', 'Membership', null, null,
        ['otomatis' => $auto], 'Template ucapan selamat & sakelar kirim otomatis disimpan');
    flash('Pesan Membership Upgrade disimpan. Kirim otomatis: ' . ($auto === '1' ? 'AKTIF' : 'NONAKTIF') . '.', 'success');
    header('Location: member_upgrade.php');
    exit;
}

/* ------------------------------------------------------------------ *
 * AKSI: kembalikan template ke default (konfirmasi 2 tahap dari formulir terpisah)
 * ------------------------------------------------------------------ */
if ($aksi === 'default' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$bolehKirim) deny('Hanya petugas yang boleh mengembalikan template ke default.');
    $grup = (string)($_POST['grup'] ?? '');
    if (!isset(template_default_groups()[$grup])) {
        flash('Kelompok template tidak dikenal.', 'error');
    } else {
        $r = template_default_restore($grup);
        audit('Kembalikan Template Membership ke Default', 'Membership', null, $r['dari'], $r['ke'],
            'Template ucapan selamat Membership Upgrade dikembalikan ke default');
        flash('Template ' . template_default_groups()[$grup]['label'] . ' dikembalikan ke default ('
            . count($r['ke']) . ' teks).', 'success');
    }
    header('Location: member_upgrade.php');
    exit;
}

/* ------------------------------------------------------------------ *
 * AKSI: kirim email (manual / ulang) & siapkan WhatsApp
 * ------------------------------------------------------------------ */
if ($aksi === 'kirim_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$bolehKirim) deny('Hanya petugas yang boleh mengirim email Membership Upgrade.');
    $id = (int)($_POST['id'] ?? 0);
    $r = member_upgrade_row($id);
    if (!$r) {
        flash('Riwayat kenaikan level tidak ditemukan.', 'error');
    } else {
        assert_branch((int)$r['branch_id']);
        $err = null;
        $ok = member_upgrade_email_send($id, $err, false);
        flash($ok
            ? 'Email ucapan selamat + kartu PDF terkirim ke ' . member_upgrade_email_to($id) . '.'
            : 'Email BELUM terkirim: ' . $err, $ok ? 'success' : 'error');
    }
    header('Location: member_upgrade.php?' . qs(['page' => page_no()]));
    exit;
}

if ($aksi === 'wa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$bolehKirim) deny('Hanya petugas yang boleh menyiapkan pesan WhatsApp.');
    $id = (int)($_POST['id'] ?? 0);
    $r = member_upgrade_row($id);
    if ($r) {
        assert_branch((int)$r['branch_id']);
        member_upgrade_mark_wa($id);
        audit('Siapkan WhatsApp Membership Upgrade', 'Membership', (int)$r['patient_id'], null,
            ['wa_status' => 'prepared'], 'Pesan ucapan selamat disiapkan untuk WhatsApp');
        /* Tautan WA disiapkan di sisi server; dibuka petugas lewat tombol (bukan
           window.open setelah await — popup blocker memblokirnya). */
        $_SESSION['wa_upgrade'] = ['id' => $id, 'pesan' => member_upgrade_wa_message($id),
            'telepon' => wa_number((string)$r['phone'])];
    }
    header('Location: member_upgrade.php?' . qs(['page' => page_no(), 'wa' => $id]));
    exit;
}

/* ------------------------------------------------------------------ *
 * FILTER & DAFTAR
 * ------------------------------------------------------------------ */
$f = [
    'dari' => (string)gp('dari', date('Y-m-d', strtotime('-1 year'))),
    'sampai' => (string)gp('sampai', date('Y-m-d')),
    'status' => (string)gp('status', ''),
    'q' => trim((string)gp('q', '')),
];
$perPage = per_page();
$page = page_no();
$idx = member_upgrade_list($f, $perPage, $page);
$ringkas = member_upgrade_summary();

/* Pesan WhatsApp yang siap dikirim (setelah aksi `wa`). */
$waSiap = null;
$waId = (int)gp('wa', 0);
if ($waId > 0 && !empty($_SESSION['wa_upgrade']) && (int)$_SESSION['wa_upgrade']['id'] === $waId) {
    $waSiap = $_SESSION['wa_upgrade'];
    unset($_SESSION['wa_upgrade']);
}

$statusLabel = function (?string $s): array {
    if ($s === 'sent') return ['Terkirim', 'ok'];
    if ($s === 'failed') return ['Gagal', 'bad'];
    return ['Belum dikirim', 'off'];
};

page_head('Membership Upgrade', 'member_upgrade');
?>
<div class="page-head">
  <div>
    <h2>Membership Upgrade</h2>
    <p class="muted">Pasien yang <strong>naik level kartu member</strong>. Email ucapan selamat
      beserta kartu PDF (2 halaman) dikirim otomatis setelah transaksi — bila gagal, dapat dikirim ulang di sini.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-sm" href="laporan.php?ps=<?= e($f['dari']) ?>&pe=<?= e($f['sampai']) ?>"><?= icon('chart') ?> Laporan</a>
  </div>
</div>

<div class="grid g4">
  <div class="stat"><span class="lbl">Total Kenaikan</span><span class="val"><?= num($ringkas['total']) ?></span>
    <span class="sub">sejak pencatatan dimulai</span></div>
  <div class="stat stat-ok"><span class="lbl">Email Terkirim</span><span class="val"><?= num($ringkas['terkirim']) ?></span>
    <span class="sub">ucapan selamat + kartu PDF</span></div>
  <div class="stat"><span class="lbl">Belum Dikirim</span><span class="val"><?= num($ringkas['belum']) ?></span>
    <span class="sub">dapat dikirim manual</span></div>
  <div class="stat"><span class="lbl">Gagal Kirim</span><span class="val"><?= num($ringkas['gagal']) ?></span>
    <span class="sub">periksa alasan di tabel</span></div>
</div>

<?php if ($waSiap): ?>
<div class="card mt-3" id="waSiap">
  <div class="card-head"><h3>Pesan WhatsApp Siap Dikirim</h3>
    <span class="muted small">WhatsApp tidak dikirim otomatis — petugas yang menekan kirim</span></div>
  <div class="card-body">
    <div class="notice">Tautan WhatsApp sudah disiapkan untuk pasien ini. Isi pesannya dapat Anda periksa dulu,
      lalu klik tombol hijau di bawah untuk membukanya di WhatsApp.</div>
    <pre style="white-space:pre-wrap;background:var(--bg-soft,#f7f7f9);padding:12px;border-radius:10px;margin:12px 0;font-family:inherit;font-size:13px"><?= e((string)$waSiap['pesan']) ?></pre>
    <div class="flex gap-sm">
      <a class="btn btn-primary" id="waUpgradeLink" target="_blank" rel="noopener"
         href="https://wa.me/<?= e((string)$waSiap['telepon']) ?>?text=<?= rawurlencode((string)$waSiap['pesan']) ?>">
        <?= icon('whatsapp') ?> Buka WhatsApp</a>
      <a class="btn btn-sm" href="member_upgrade.php">Tutup pesan ini</a>
    </div>
    <div class="small muted mt-2">Sudah tercatat sebagai <strong>"Disiapkan, belum terkirim"</strong> — WhatsApp hanya bisa
      menyampaikan teks, jadi kartu PDF tetap dikirim lewat email.</div>
  </div>
</div>
<?php endif; ?>

<div class="card mt-3">
  <form class="filter-bar" method="get">
    <div class="field"><label>Dari</label><input class="input" type="date" name="dari" value="<?= e($f['dari']) ?>"></div>
    <div class="field"><label>Sampai</label><input class="input" type="date" name="sampai" value="<?= e($f['sampai']) ?>"></div>
    <div class="field"><label>Status Email</label>
      <select class="input" name="status">
        <option value="">Semua status</option>
        <option value="sent"<?= $f['status'] === 'sent' ? ' selected' : '' ?>>Sudah terkirim</option>
        <option value="belum"<?= $f['status'] === 'belum' ? ' selected' : '' ?>>Belum terkirim</option>
      </select></div>
    <div class="field"><label>Cari</label>
      <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="nama / member ID / telepon / email"></div>
    <div class="field"><label>Per halaman</label><?= per_page_inline() ?></div>
    <div class="field" style="align-self:flex-end">
      <button class="btn btn-primary" type="submit"><?= icon('search') ?> Filter</button></div>
    <div class="field" style="align-self:flex-end">
      <a class="btn btn-sm" href="member_upgrade.php">Reset</a></div>
  </form>
</div>

<div class="card">
  <div class="card-head"><h3>Daftar Kenaikan Level</h3>
    <span class="muted small"><?= num($idx['total']) ?> data pada filter ini</span></div>
  <div class="card-body">
    <?php if (!$idx['rows']): ?>
      <p class="muted">Belum ada pasien yang naik level pada rentang/filter ini.
        Kenaikan level tercatat otomatis begitu transaksi membuat akumulasi pasien melewati ambang level berikutnya
        (lihat Pengaturan Sistem → Kartu Member untuk daftar level &amp; ambangnya).</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:44px">No</th>
            <th>Nama Pasien</th>
            <th>Member ID</th>
            <th>Level Lama</th>
            <th>Level Baru</th>
            <th>Tgl Upgrade</th>
            <th class="num">Total Transaksi</th>
            <th>WhatsApp</th>
            <th>Email</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php $no = ($page - 1) * $perPage; foreach ($idx['rows'] as $r): $no++;
            [$stTxt, $stTone] = $statusLabel((string)$r['email_status']);
            $email = trim((string)$r['email']);
            $telp = wa_number((string)$r['phone']);
        ?>
          <tr>
            <td><?= num($no) ?></td>
            <td>
              <a href="pasien_detail.php?id=<?= (int)$r['patient_id'] ?>"><strong><?= e($r['name']) ?></strong></a>
              <?php if (is_owner_level() && (string)($r['branch_name'] ?? '') !== ''): ?>
                <div class="small muted"><?= e((string)$r['branch_name']) ?></div>
              <?php endif; ?>
              <?php if ((string)$r['invoice_number'] !== ''): ?>
                <div class="small muted">dari <?= e((string)$r['invoice_number']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="mono"><?= e((string)$r['member_number']) ?></span></td>
            <td><?= e((string)$r['old_label']) ?></td>
            <td><strong><?= e((string)$r['new_label']) ?></strong>
              <div class="small muted"><?= e(num((float)$r['new_pct'], (float)$r['new_pct'] == (int)$r['new_pct'] ? 0 : 1)) ?>%</div></td>
            <td><?= e(tglIndo(substr((string)$r['upgraded_at'], 0, 10))) ?>
              <div class="small muted"><?= e(substr((string)$r['upgraded_at'], 11, 5)) ?></div></td>
            <td class="num"><?= money((float)$r['total_amount']) ?></td>
            <td>
              <?php if ($telp !== ''): ?>
                <?php if ($bolehKirim): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="wa">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm" type="submit" title="Siapkan pesan WhatsApp"><?= icon('whatsapp') ?> <?= e($telp) ?></button>
                  </form>
                <?php else: ?>
                  <span class="mono"><?= e($telp) ?></span>
                <?php endif; ?>
                <div class="small muted"><?= (string)$r['wa_status'] === 'prepared' ? 'Disiapkan, belum terkirim' : 'Belum dikirim' ?></div>
              <?php else: ?>
                <span class="muted small">tanpa nomor</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($email !== ''): ?>
                <span class="small"><?= e($email) ?></span>
              <?php else: ?>
                <span class="muted small">belum ada email</span>
              <?php endif; ?>
              <?php if ((string)$r['email_error'] !== '' && (string)$r['email_status'] === 'failed'): ?>
                <div class="small" style="color:var(--danger,#b3261e)"><?= e(short_text((string)$r['email_error'], 90)) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="pill <?= e($stTone) ?>"><?= e($stTxt) ?></span>
              <?php if ((string)$r['email_sent_at'] !== '' && (string)$r['email_status'] === 'sent'): ?>
                <div class="small muted"><?= e(tglIndo(substr((string)$r['email_sent_at'], 0, 10))) ?> <?= e(substr((string)$r['email_sent_at'], 11, 5)) ?></div>
              <?php endif; ?>
              <?php if ($bolehKirim): ?>
                <form method="post" class="mt-1">
                  <?= csrf_field() ?><input type="hidden" name="action" value="kirim_email">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm" type="submit"
                          <?= (string)$r['email_status'] === 'sent' ? 'data-confirm="Email untuk kenaikan level ini sudah pernah dikirim. Kirim lagi?"' : '' ?>>
                    <?= icon('mail') ?> <?= (string)$r['email_status'] === 'sent' ? 'Kirim ulang' : 'Kirim email' ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination((int)$idx['total'], $perPage, $page, 'page') ?>
    <?php endif; ?>
  </div>
</div>

<?php /* ---------------- KARTU PESAN & PENGIRIMAN ---------------- */ ?>
<div class="card mt-3" id="pesan">
  <div class="card-head"><h3>Pesan Ucapan Selamat &amp; Pengiriman</h3>
    <span class="muted small">template email/WhatsApp dan sakelar kirim otomatis</span></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="setelan">
    <div class="card-body">
      <div class="notice">
        Email otomatis dikirim <strong>setelah transaksi tersimpan</strong> dan level pasien naik, beserta
        <strong>lampiran PDF kartu member lengkap (2 halaman)</strong>. Bila pasien belum punya email atau layanan
        email belum siap, pengiriman gagal dan dapat diulang dari tabel di atas.
      </div>
      <div class="form-grid g2 mt-2">
        <div class="field" style="grid-column:1/-1">
          <label class="flex gap-sm" style="align-items:center">
            <input type="checkbox" name="email_member_upgrade_auto" value="1"
                   <?= setting('email_member_upgrade_auto', '1') === '1' ? 'checked' : '' ?>>
            <span>Kirim email ucapan selamat secara <strong>otomatis</strong> saat level pasien naik</span>
          </label>
          <span class="hint">Dimatikan → kenaikan level tetap tercatat di tabel, tetapi email harus dikirim manual.</span>
        </div>
        <div class="field" style="grid-column:1/-1">
          <label>Subjek Email</label>
          <input class="input" name="email_member_upgrade_subject" autocomplete="off"
                 value="<?= e(setting('email_member_upgrade_subject', 'Selamat! Level Kartu Member Anda naik menjadi {level}')) ?>">
        </div>
        <div class="field" style="grid-column:1/-1">
          <label>Isi Email</label>
          <textarea class="input" name="email_member_upgrade_body" rows="14"><?= e(setting('email_member_upgrade_body')) ?></textarea>
          <span class="hint">Variabel: {nama} {member} {level} {level_lama} {diskon} {benefit} {akumulasi}
            {periode} {klinik} {cabang} {tanggal}</span>
        </div>
        <div class="field" style="grid-column:1/-1">
          <label>Pesan WhatsApp</label>
          <textarea class="input" name="wa_member_upgrade_template" rows="8"><?= e(setting('wa_member_upgrade_template')) ?></textarea>
          <span class="hint">WhatsApp <strong>tidak dikirim otomatis</strong> (kartu PDF tidak bisa dilampirkan lewat WA):
            tombol WhatsApp pada tabel menyiapkan pesan ini untuk dibuka petugas, dan statusnya tercatat
            "Disiapkan, belum terkirim".</span>
        </div>
      </div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius)">
      <button class="btn btn-primary" type="submit">Simpan Pesan</button>
    </div>
  </form>
  <?php if ($bolehKirim): ?>
  <div class="card-body" style="border-top:1px solid var(--line)">
    <?php
    /* Tombol hanya aktif bila teksnya memang sudah berbeda dari bakuannya. */
    $bedaEmail = template_default_changed('member_email');
    $bedaWa = template_default_changed('member_wa');
    ?>
    <div class="flex flex-wrap gap-sm" style="align-items:center">
      <form method="post" data-heavy-kind="reset"
            data-heavy-confirm="KEMBALIKAN TEKS EMAIL KE DEFAULT"
            data-heavy-warning="Subjek &amp; isi email ucapan selamat Membership Upgrade akan dikembalikan ke template DEFAULT (bakuannya). Tulisan yang Anda ubah pada kedua kolom itu akan DIGANTI. Pengaturan lain pada kartu ini (sakelar kirim otomatis &amp; template WhatsApp) TIDAK berubah."
            data-heavy-confirm2="PERINGATAN KEDUA (terakhir): kembalikan teks email ke default sekarang?">
        <?= csrf_field() ?><input type="hidden" name="action" value="default">
        <input type="hidden" name="grup" value="member_email">
        <button class="btn btn-sm" type="submit" <?= !$bedaEmail ? 'disabled title="Teks sudah sama dengan default"' : '' ?>><?= icon('refresh') ?> Kembalikan Teks Email ke Default</button>
      </form>
      <form method="post" data-heavy-kind="reset"
            data-heavy-confirm="KEMBALIKAN TEMPLATE WHATSAPP KE DEFAULT"
            data-heavy-warning="Pesan WhatsApp ucapan selamat akan dikembalikan ke template DEFAULT (bakuannya). Tulisan yang Anda ubah akan DIGANTI. Pengaturan lain pada kartu ini TIDAK berubah."
            data-heavy-confirm2="PERINGATAN KEDUA (terakhir): kembalikan template WhatsApp ke default sekarang?">
        <?= csrf_field() ?><input type="hidden" name="action" value="default">
        <input type="hidden" name="grup" value="member_wa">
        <button class="btn btn-sm" type="submit" <?= !$bedaWa ? 'disabled title="Teks sudah sama dengan default"' : '' ?>><?= icon('refresh') ?> Kembalikan Template WhatsApp ke Default</button>
      </form>
    </div>
    <div class="small muted mt-2">Tombol pengembalian hanya aktif bila teksnya memang sudah berbeda dari bakuannya —
      dikembalikan dengan konfirmasi 2 tahap.</div>
  </div>
  <?php endif; ?>
</div>

<?php page_foot(); ?>
