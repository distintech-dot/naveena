<?php
/**
 * KARTU MEMBER DIGITAL — tampilan siap cetak + unduh PDF asli (UKURAN KARTU).
 *
 * Kartu terdiri dari DUA SISI (seperti kartu ATM pada umumnya):
 *   HALAMAN 1 (depan)  : identitas pemegang kartu
 *   HALAMAN 2 (belakang): keuntungan level & catatan kartu
 *
 * Ukuran kartu standar 85,6 × 54 mm = 242,6 × 153,1 pt. Background dapat
 * diunggah di Pengaturan Sistem → Kartu Member; gambar otomatis dipotong
 * 1012 × 638 px sehingga selalu memenuhi kartu tanpa gepeng. Bila belum ada
 * background, kartu memakai warna tema sistem (teks tetap hitam agar terbaca).
 *
 * Semua teks pengguna (nama, nomor, catatan) dipotong/dikecilkan otomatis agar
 * tidak pernah keluar dari area kartu (lihat member_card_fit()).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/receipt.php';   // tglIndo
require_once __DIR__ . '/includes/pdf.php';       // MiniPdf + gambar PNG
require_once __DIR__ . '/includes/member_card_pdf.php';
require_perm('patient.view');
$user = current_user();

$id = (int)gp('id');
$p = one('SELECT p.*, b.name AS branch_name, b.address AS branch_address, b.phone AS branch_phone,
                 b.code AS branch_code
          FROM patients p JOIN branches b ON b.id = p.branch_id WHERE p.id = ?', [$id]);
if (!$p) {
    flash('Pasien tidak ditemukan.', 'error');
    header('Location: pasien.php');
    exit;
}
assert_branch((int)$p['branch_id']);

if (!patient_is_member($p)) {
    flash('Pasien ini belum memiliki kartu member. Kartu diberikan otomatis saat transaksi mencapai '
        . money(member_activate_amount()) . ' atau akumulasi ' . member_period_label() . ' mencapai '
        . money(member_base_level()['min_year']) . ', atau diaktifkan petugas di halaman Detail Pasien.', 'warning');
    header('Location: pasien_detail.php?id=' . $id);
    exit;
}

$status  = member_status($p);
$level   = $status['level'];
$since   = (string)($p['member_since'] ?: $p['created_at']);
$company = clinic_name();
$note    = (string)setting('member_card_note');
$theme   = theme_current();
$levels  = member_levels();
$cardBg  = member_card_bg_path();
$cardLogo = logo_pdf_path();

/** Konteks gambar kartu (dipakai kedua dokumen PDF). */
function mcard_ctx(array $p, array $level, array $levels, array $theme, string $since, string $note,
                   bool $hasBg, string $logo, bool $border): array
{
    return [
        'p' => $p, 'level' => $level, 'levels' => $levels, 'theme' => $theme, 'since' => $since,
        'note' => $note, 'hasBg' => $hasBg, 'logo' => $logo, 'border' => $border,
        'company' => clinic_name(),
        'phone' => (string)($p['branch_phone'] ?: setting('company_phone')),
    ];
}

/** Kirim berkas PDF ke peramban lalu hentikan skrip. */
function mcard_send(string $bytes, string $fname): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/* ------------------------------------------------------------------ *
 * PDF 1: KARTU (2 halaman, UKURAN KARTU: sisi depan & sisi belakang)
 * ------------------------------------------------------------------ */
if (gp('format') === 'pdf') {
    $pdf = new MiniPdf(MCARD_W, MCARD_H, 10);
    $hasBg = false;
    if ($cardBg !== '' && $pdf->backgroundImage($cardBg)) {
        $hasBg = true;
        $pdf->setCursorTop();
    }
    mcard_front($pdf, 0, 0, mcard_ctx($p, $level, $levels, $theme, $since, $note, $hasBg, $cardLogo, false));

    $pdf->newPage();
    if ($hasBg) {
        $pdf->backgroundImage($cardBg);
        $pdf->setCursorTop();
    }
    mcard_back($pdf, 0, 0, mcard_ctx($p, $level, $levels, $theme, $since, $note, $hasBg, $cardLogo, false));

    $fname = 'kartu-member-' . preg_replace('/[^A-Za-z0-9\-]/', '-', (string)$p['member_number']) . '.pdf';
    audit('Unduh Kartu Member', 'Pasien', $id, null,
        ['member' => $p['member_number'], 'level' => $level['key']], 'Kartu member digital diunduh (PDF 2 sisi)');
    mcard_send($pdf->output(), $fname);
}

/* ------------------------------------------------------------------ *
 * PDF 2: DOKUMEN LENGKAP (2 halaman A4)
 *   Halaman 1 — pratinjau KEDUA kartu (persis seperti halaman Kartu Member Digital)
 *   Halaman 2 — status member & aturan diskon yang berlaku
 * ------------------------------------------------------------------ */
if (gp('format') === 'pdf_lengkap') {
    $pdf = new MiniPdf(595.28, 841.89, 40);          // A4
    $hasBg = $cardBg !== '';
    $printedAt = tglIndo(date('Y-m-d')) . ' ' . date('H:i');

    /* Jarak antar bagian dokumen — dikumpulkan di satu tempat supaya rapi dan
       proporsional (permintaan pemilik). Sebelumnya judul menempel logo karena
       setelah gambar hanya tersisa tinggi baris (jarak nyata cuma 1,9 pt). */
    $GAP_LOGO_TITLE = 12.0;     // bawah gambar logo → huruf atas judul
    $GAP_TITLE_SUB  = 3.0;      // judul → baris keterangan (nama · nomor · cabang)
    $GAP_SUB_RULE   = 10.0;     // keterangan → garis pemisah
    $GAP_RULE_CARD  = 14.0;     // garis → tepi atas kartu
    $GAP_CARD_CAP   = 16.0;     // bawah kartu → keterangan sisi kartu
    $GAP_CAP_NOTE   = 15.0;     // keterangan sisi → paragraf penjelasan
    $docFoot = function (string $s) use ($pdf): void {
        $pdf->text(40, $s, 7.6, false, 34);
    };

    /**
     * Kepala dokumen (kop): logo tengah-atas, judul, keterangan, garis pemisah.
     * Jaraknya eksplisit sehingga tidak pernah lagi menempel seperti sebelumnya.
     */
    $docHead = function (string $title, string $sub) use ($pdf, $company, $cardLogo,
        $GAP_LOGO_TITLE, $GAP_TITLE_SUB, $GAP_SUB_RULE): void {
        $pakaiLogo = false;
        if ($cardLogo !== '') {
            $s = $pdf->imagePngCentered($cardLogo, 132, 38);
            $pakaiLogo = $s['w'] > 0;
        }
        if (!$pakaiLogo) {
            $pdf->line($company, 14, true, 0, true);
            $pdf->gap($GAP_LOGO_TITLE);
        } else {
            /* imagePngCentered() sudah menurunkan kursor sebesar tinggi baris
               (tanpa itu judul menempel gambar). Tinggi huruf kapital ±0,72 em,
               jadi jarak nyata gambar→judul = GAP_LOGO_TITLE. */
            $pdf->gap($GAP_LOGO_TITLE + 0.72 * 13.5 - $pdf->lineHeight);
        }
        $pdf->line($title, 13.5, true, 0, true);
        $pdf->gap($GAP_TITLE_SUB);
        $pdf->line($sub, 8.6, false, 0, true);
        $pdf->gap($GAP_SUB_RULE);
        $pdf->hr(0);
    };

    /* ---------------- HALAMAN 1: pratinjau dua kartu ---------------- */
    $docHead('KARTU MEMBER DIGITAL', (string)$p['name'] . '  ·  ' . (string)$p['member_number']
        . '  ·  ' . (string)$p['branch_name']);
    $pdf->gap($GAP_RULE_CARD);

    $gapCards = 24.0;
    $card1X = (595.28 - (2 * MCARD_W + $gapCards)) / 2;
    $card2X = $card1X + MCARD_W + $gapCards;
    $cardTop = $pdf->y;
    $cardY = $cardTop - MCARD_H;

    /* Latar kartu (bila diunggah) digambar TEPAT di kotak kartunya — memakai
       imagePngAt() yang kini benar-benar menghormati koordinat mutlak. */
    if ($hasBg) {
        foreach ([$card1X, $card2X] as $cx) {
            $pdf->fillRect($cx, $cardY, MCARD_W, MCARD_H, '#FFFFFF');
            $pdf->imagePngAt($cardBg, $cx, $cardTop, MCARD_W + 0.01, MCARD_H + 0.01);
        }
    }
    $ctx = mcard_ctx($p, $level, $levels, $theme, $since, $note, $hasBg, $cardLogo, true);
    mcard_front($pdf, $card1X, $cardY, $ctx);
    mcard_back($pdf, $card2X, $cardY, $ctx);

    $capY = $cardY - $GAP_CARD_CAP;
    $pdf->text($card1X, 'Sisi depan — identitas pemegang kartu', 8.4, false, $capY);
    $pdf->text($card2X, 'Sisi belakang — keuntungan level & catatan', 8.4, false, $capY);
    $pdf->y = $capY - $GAP_CAP_NOTE;
    $pdf->paragraph('Ukuran tiap kartu pada halaman ini 85,6 x 54 mm (seukuran kartu ATM) dan kedua sisi '
        . 'ditampilkan bersebelahan. Saat mencetak, pilih ukuran kertas A4 dengan skala "100% / ukuran '
        . 'sebenarnya" agar kartu dapat langsung digunting. Halaman 2 memuat status member dan aturan '
        . 'diskon yang sedang berlaku.', 8.4);
    $docFoot($company . '  ·  ' . (string)($p['branch_phone'] ?: setting('company_phone'))
        . '  ·  dicetak ' . $printedAt);

    /* ---------------- HALAMAN 2: status member & aturan diskon ---------------- */
    $pdf->newPage();
    $docHead('STATUS MEMBER & ATURAN DISKON YANG BERLAKU', (string)$p['name'] . '  ·  '
        . (string)$p['member_number'] . '  ·  ' . (string)$p['branch_name']);
    $pdf->gap(6);

    /** Satu baris "label + nilai" seperti daftar di halaman kartu. */
    $dl = function (string $label, string $value) use ($pdf): void {
        $pdf->line($label, 8.6, true);
        $pdf->gap(1);
        $pdf->paragraph($value, 9.4);
        $pdf->gap(6);
    };

    $pdf->line('STATUS MEMBER', 10.5, true);
    $pdf->gap(3);
    $pdf->paragraph('Data per ' . $printedAt . '. Level dan akumulasi dihitung otomatis dari transaksi '
        . 'berstatus dibayar pada periode ' . member_period_label() . '.', 8.6);
    $pdf->gap(4);
    $dl('Level saat ini', $level['label'] . ' - diskon '
        . num((float)$level['pct'], (float)$level['pct'] == (int)$level['pct'] ? 0 : 1) . '% untuk '
        . member_scope_text() . '.');
    $dl('Akumulasi ' . member_period_label() . ' (' . tglIndo((string)$status['period']['start']) . ' s.d. '
        . tglIndo((string)$status['period']['end']) . ')', money((float)$status['year_total'])
        . ' (dihitung dari ' . num((int)$status['trx']) . ' transaksi berstatus dibayar pada periode ini).');
    if ($status['next']) {
        $kurang = max(0.0, (float)$status['next']['min_year'] - (float)$status['year_total']);
        $dl('Menuju ' . $status['next']['label'], 'Perlu akumulasi ' . money((float)$status['next']['min_year'])
            . ' - kurang ' . money($kurang) . ' lagi (diskon level itu '
            . num((float)$status['next']['pct'], (float)$status['next']['pct'] == (int)$status['next']['pct'] ? 0 : 1)
            . '%).');
    } else {
        $dl('Level tertinggi', 'Sudah mencapai level member tertinggi yang berlaku di klinik ini.');
    }
    $dl('Diperoleh dari', member_source_text((string)$p['member_source']));
    $dl('Member sejak', tglIndo($since) . '  ·  cabang ' . (string)$p['branch_name']);

    $pdf->gap(4);
    $pdf->line('ATURAN DISKON YANG BERLAKU', 10.5, true);
    $pdf->gap(3);
    foreach ($levels as $l) {
        $on = (string)$l['key'] === (string)$level['key'];
        $pdf->kv(($on ? '* ' : '') . (string)$l['label'] . ' - diskon '
            . num((float)$l['pct'], (float)$l['pct'] == (int)$l['pct'] ? 0 : 1) . '%',
            'akumulasi ' . member_period_label() . ' >= ' . money((float)$l['min_year']));
    }
    $pdf->hr(0);
    $pdf->gap(2);
    $pdf->paragraph('Kartu member terbuka otomatis bila satu transaksi mencapai '
        . money(member_activate_amount()) . ' atau akumulasi ' . member_period_label() . ' mencapai '
        . money((float)$levels[0]['min_year']) . '. Diskon dihitung otomatis pada setiap transaksi '
        . 'dengan nilai minimal ' . money(member_min_transaction()) . ' untuk '
        . member_scope_text() . '. Besaran diskon selalu dihitung ulang oleh sistem dari data '
        . 'transaksi, bukan dari angka yang diisi petugas.', 9.0);
    if (member_period_years() > 0) {
        $pdf->gap(4);
        $pdf->line('Periode akumulasi member', 8.6, true);
        $pdf->gap(1);
        $pdf->paragraph('Akumulasi dihitung per periode ' . member_period_label()
            . ' dan direset bersama untuk semua member setiap 1 Januari. Periode berjalan: '
            . tglIndo((string)$status['period']['start']) . ' s.d. ' . tglIndo((string)$status['period']['end'])
            . ', reset berikutnya ' . tglIndo((string)$status['period']['next_reset']) . '. Saat periode berakhir'
            . (member_downgrade_steps() > 0
                ? ', level kartu turun ' . num(member_downgrade_steps()) . ' tingkat'
                : ', level kartu TIDAK diturunkan')
            . ' dan akumulasi mulai dari nol; nomor member tidak berubah.', 9.0);
    } else {
        $pdf->gap(4);
        $pdf->paragraph('Akumulasi member pada klinik ini TIDAK direset (tanpa periode): level dihitung dari '
            . 'seluruh transaksi berbayar yang pernah tercatat untuk pasien ini.', 9.0);
    }
    if ($note !== '') {
        $pdf->gap(4);
        $pdf->line('Catatan kartu', 8.6, true);
        $pdf->gap(1);
        $pdf->paragraph($note, 9.0);
    }
    if (!member_card_enabled()) {
        $pdf->gap(6);
        $pdf->paragraph('PEMBERITAHUAN: fitur kartu member sedang TIDAK AKTIF, sehingga diskon member '
            . 'tidak berlaku sampai admin klinik mengaktifkannya kembali. Dokumen ini dicetak sebagai arsip.', 9.0);
    }
    $docFoot($company . '  ·  dicetak ' . $printedAt . '  ·  halaman 2 dari 2');

    $fname = 'kartu-member-lengkap-' . preg_replace('/[^A-Za-z0-9\-]/', '-', (string)$p['member_number']) . '.pdf';
    audit('Cetak Kartu Member Lengkap', 'Pasien', $id, null,
        ['member' => $p['member_number'], 'level' => $level['key'], 'halaman' => 2],
        'Dokumen kartu member lengkap dicetak (pratinjau kartu + status & aturan diskon)');
    mcard_send($pdf->output(), $fname);
}

page_head('Kartu Member — ' . $p['name'], 'pasien');
?>
<div class="page-head">
  <div>
    <h2>Kartu Member Digital</h2>
    <p class="muted"><?= e($p['name']) ?> · <?= e($p['member_number']) ?> · <?= e($p['branch_name']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="pasien_detail.php?id=<?= (int)$p['id'] ?>"><?= icon('users') ?> Detail Pasien</a>
    <a class="btn" href="member_card.php?id=<?= (int)$p['id'] ?>&format=pdf"><?= icon('download') ?> Unduh PDF Kartu (2 sisi)</a>
    <a class="btn btn-primary" href="member_card.php?id=<?= (int)$p['id'] ?>&format=pdf_lengkap"><?= icon('print') ?> Cetak PDF Lengkap (2 halaman)</a>
    <button class="btn" data-print><?= icon('print') ?> Cetak</button>
  </div>
</div>

<?php if (!member_card_enabled()): ?>
  <div class="alert alert-warning">
    <strong>Mohon maaf</strong>, fitur kartu member untuk saat ini <strong>sedang tidak aktif</strong>.
    Kartu ini hanya ditampilkan sebagai arsip — diskon member tidak berlaku sampai fitur diaktifkan kembali
    oleh admin klinik. Terima kasih atas pengertiannya.
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h3>Pratinjau Kartu</h3>
    <span class="muted">Ukuran kartu standar (85,6 × 54 mm) · 2 sisi · siap dicetak</span>
  </div>
  <div class="card-body">
    <div class="mcard-set">
      <?php $bgUrl = setting('member_card_bg_file') !== '' ? 'member_bg.php?v=' . substr(md5(setting('member_card_bg_file')), 0, 6) : ''; ?>
      <div class="mcard<?= $bgUrl !== '' ? ' has-bg' : '' ?>" style="--mcard-brand:<?= e($theme['brand']) ?>;--mcard-dark:<?= e($theme['brandDark']) ?><?= $bgUrl !== '' ? ';--mcard-bg:url(' . e($bgUrl) . ')' : '' ?>">
        <div class="mcard-front">
          <?php if ($bgUrl === ''): ?>
            <div class="mcard-side">
              <span class="mcard-tag" aria-hidden="true"><?php foreach (str_split('MEMBER') as $ch): ?><i><?= $ch ?></i><?php endforeach; ?></span>
            </div>
          <?php endif; ?>
          <div class="mcard-front-body">
            <div class="mcard-head">
              <?php if (brand_logo_src() !== ''): ?>
                <div class="mcard-logo<?= $bgUrl !== '' ? ' on-bg' : '' ?>"><img src="<?= e(brand_logo_src()) ?>" alt="<?= e($company) ?>"></div>
              <?php else: ?>
                <div class="mcard-co"><?= e($company) ?></div>
              <?php endif; ?>
              <div class="mcard-line"></div>
            </div>
            <div class="mcard-id">
              <div class="mcard-name"><?= e($p['name']) ?></div>
              <div class="mcard-level">Member Card - <?= e($level['label']) ?></div>
            </div>
            <div class="mcard-rows">
              <div><span>No. Member</span><strong><?= e($p['member_number']) ?></strong></div>
              <div><span>No. Pasien</span><strong><?= e($p['patient_number']) ?></strong></div>
              <div><span>Member Sejak</span><strong><?= e(tglIndo($since)) ?></strong></div>
              <div><span>Cabang</span><strong><?= e($p['branch_name']) ?></strong></div>
            </div>
          </div>
        </div>
      </div>

      <div class="mcard<?= $bgUrl !== '' ? ' has-bg' : '' ?>" style="--mcard-brand:<?= e($theme['brand']) ?>;--mcard-dark:<?= e($theme['brandDark']) ?><?= $bgUrl !== '' ? ';--mcard-bg:url(' . e($bgUrl) . ')' : '' ?>">
        <div class="mcard-back">
          <div class="mcard-back-title">KEUNTUNGAN LEVEL MEMBER</div>
          <div class="mcard-line"></div>
          <ul class="mcard-benefits">
            <?php foreach ($levels as $l): ?>
              <li<?= $l['key'] === $level['key'] ? ' class="on"' : '' ?>><?= e($l['label']) ?> ~ Diskon
                <?= num($l['pct'], $l['pct'] == (int)$l['pct'] ? 0 : 1) ?>%</li>
            <?php endforeach; ?>
          </ul>
          <div class="mcard-line"></div>
          <p class="mcard-note">Benefit member aktif: setiap transaksi minimal <strong><?= money(member_min_transaction()) ?></strong>
            mendapat diskon otomatis sesuai level kartu (<?= e(member_scope_text()) ?>).
            Akumulasi dihitung <?= e(member_period_label()) ?><?= member_period_years() > 0
              ? ' dan direset bersama setiap 1 Januari' . (member_downgrade_steps() > 0
                  ? ' (level turun ' . num(member_downgrade_steps()) . ' tingkat)' : '') : '' ?>.</p>
          <?php if ($note !== ''): ?><p class="mcard-note muted"><?= e($note) ?></p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-head"><h3>Status Member</h3></div>
    <div class="card-body">
      <dl class="kv">
        <dt>Level saat ini</dt><dd><strong><?= e($level['label']) ?></strong> · diskon
          <?= num($level['pct'], $level['pct'] == (int)$level['pct'] ? 0 : 1) ?>%</dd>
        <dt>Akumulasi <?= e($status['period']['label']) ?></dt><dd><strong><?= money($status['year_total']) ?></strong>
          <div class="small muted">
            <?php if (member_period_years() > 0): ?>
              Periode berjalan <?= e(tglIndo($status['period']['start'])) ?> s.d. <?= e(tglIndo($status['period']['end'])) ?>,
              reset berikutnya <strong><?= e(tglIndo($status['period']['next_reset'])) ?></strong>
              <?= member_downgrade_steps() > 0
                  ? ' — saat reset, level turun ' . num(member_downgrade_steps()) . ' tingkat'
                  : ' — saat reset, level tidak ikut turun' ?>; nomor member tidak berubah.
              <?= num((int)$status['trx']) ?> transaksi berbayar pada periode ini.
            <?php else: ?>
              Tanpa periode: akumulasi dihitung dari seluruh transaksi berbayar pasien ini dan tidak pernah direset.
            <?php endif; ?>
          </div></dd>
        <?php if ($status['next']): ?>
          <dt>Menuju <?= e($status['next']['label']) ?></dt>
          <dd>Perlu akumulasi <?= money($status['next']['min_year']) ?>
            <div class="progress mt-1"><i style="width:<?= min(100, $status['next']['min_year'] > 0 ? $status['year_total'] / $status['next']['min_year'] * 100 : 0) ?>%"></i></div>
            <div class="small muted">Kurang <?= money(max(0, $status['next']['min_year'] - $status['year_total'])) ?> lagi</div></dd>
        <?php else: ?>
          <dt>Level tertinggi</dt><dd>Sudah mencapai level member tertinggi.</dd>
        <?php endif; ?>
        <dt>Diperoleh dari</dt><dd><?= e(member_source_text($p['member_source'])) ?></dd>
      </dl>
      <?php if (has_perm('patient.manage')): ?>
        <form method="post" action="pasien_detail.php" class="mt-2"
              data-confirm="Cabut status member pasien ini? Nomor member tetap tersimpan, kartu digital tidak dapat diunduh lagi.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="member_revoke">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">Cabut Status Member</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Aturan Diskon yang Berlaku</h3></div>
    <div class="card-body">
      <ul class="list-clean">
        <?php foreach ($levels as $l): ?>
          <li><span><?= e($l['label']) ?></span><strong>diskon <?= num($l['pct'], $l['pct'] == (int)$l['pct'] ? 0 : 1) ?>%
            <span class="muted small">· akumulasi <?= e(member_period_label()) ?> ≥ <?= money($l['min_year']) ?></span></strong></li>
        <?php endforeach; ?>
      </ul>
      <div class="notice mt-2">
        Kartu baru terbuka otomatis bila satu transaksi ≥ <strong><?= money(member_activate_amount()) ?></strong>
        atau akumulasi <?= e(member_period_label()) ?> mencapai <strong><?= money($levels[0]['min_year']) ?></strong>.
        Diskon berlaku untuk <strong><?= e(member_scope_text()) ?></strong> dengan nilai transaksi minimal
        <strong><?= money(member_min_transaction()) ?></strong>.
      </div>
      <?php if (member_period_years() > 0): ?>
      <div class="notice mt-2">
        <strong>Periode akumulasi: <?= e(member_period_label()) ?></strong> — direset bersamaan untuk seluruh member
        setiap <strong>1 Januari</strong> (periode berjalan <?= e(tglIndo($status['period']['start'])) ?> s.d.
        <?= e(tglIndo($status['period']['end'])) ?>, berikutnya <?= e(tglIndo($status['period']['next_reset'])) ?>).
        <?= member_downgrade_steps() > 0
            ? 'Saat periode berakhir, level kartu <strong>turun ' . num(member_downgrade_steps()) . ' tingkat</strong>'
              . ' (paling jauh ke level terendah) dan akumulasi mulai dari nol.'
            : 'Saat periode berakhir, <strong>level kartu tidak diturunkan</strong> — hanya akumulasi yang mulai dari nol.' ?>
        Level naik kembali otomatis bila akumulasi periode baru melewati ambangnya.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
audit('Lihat Kartu Member', 'Pasien', $id, null, ['member' => $p['member_number']], 'Kartu member digital dibuka');
page_foot();
