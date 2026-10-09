<?php
/**
 * MEMBERSHIP UPGRADE — RIWAYAT NAIK LEVEL KARTU MEMBER + UCAPAN SELAMAT
 * =====================================================================
 * Permintaan pemilik: sebuah menu untuk MENGETAHUI bila ada pasien yang NAIK
 * LEVEL kartu membernya, dan bila itu terjadi **setelah transaksi tersimpan**
 * aplikasi mengirim OTOMATIS email ucapan selamat + penjelasan benefit diskon
 * beserta LAMPIRAN PDF kartu lengkap (2 halaman). Tabelnya harus menunjukkan
 * sudah terkirim atau belum, dan pengiriman dapat diulang manual.
 *
 * Kolom tabel yang diminta: No · Nama Pasien · Member ID · Level Lama · Level Baru ·
 * Tgl Upgrade · Total Transaksi · WhatsApp · Email · Status.
 *
 * CATATAN DESAIN:
 *   • Perekaman HANYA dilakukan dari jalur TRANSAKSI (`order_create.php`). Jalur
 *     sinkronisasi massal (mis. saat aturan level di Pengaturan diubah) sengaja
 *     TIDAK memanggil perekaman — kalau ikut, mengubah ambang level akan mengirim
 *     email ucapan selamat ke puluhan pasien sekaligus.
 *   • Yang dianggap "naik level": peringkat level BARU lebih tinggi dari yang lama,
 *     TERMASUK saat kartu baru diberikan (kartu baru = naik dari "belum berkartu").
 *     PENURUNAN level (reset periode) TIDAK dicatat di sini — itu bukan kenaikan.
 *   • Nama level & besar diskon disimpan sebagai SNAPSHOT supaya riwayat tetap benar
 *     walau aturan level diubah kemudian.
 */

/** Peringkat sebuah level (0 = terendah). -1 bila level tidak dikenal. */
function member_upgrade_rank(?string $key): int
{
    $key = (string)$key;
    if ($key === '') return -1;
    foreach (member_levels() as $i => $l) if ($l['key'] === $key) return $i;
    return -1;
}

/**
 * Apakah perubahan level ini sebuah KENAIKAN?
 * Level lama boleh kosong ('' / null) = pasien belum berkartu → kenaikan dari nol.
 */
function member_upgrade_is_upgrade(?string $oldKey, string $newKey): bool
{
    $baru = member_upgrade_rank($newKey);
    if ($baru < 0) return false;                       // level baru tidak dikenal → jangan dicatat
    $lama = member_upgrade_rank($oldKey);
    if ($lama < 0) return $baru >= 0;                  // belum berkartu → kartu/level pertama = kenaikan
    return $baru > $lama;
}

/** Label level untuk tampilan ('' → keterangan jujur "Belum berkartu"). */
function member_upgrade_level_label(?string $key): string
{
    $lv = member_level_by_key((string)$key);
    if ($lv) return $lv['label'];
    return $key === '' || $key === null ? 'Belum berkartu' : $key;
}

/** Persen diskon sebuah level (0 bila tidak dikenal). */
function member_upgrade_level_pct(?string $key): float
{
    $lv = member_level_by_key((string)$key);
    return $lv ? (float)$lv['pct'] : 0.0;
}

/**
 * Uraian BENEFIT yang berlaku pada level tertentu (dipakai isi email & WA).
 * Menyebut cakupan diskon yang berlaku + level berikutnya sebagai pemacu.
 */
function member_upgrade_benefit_text(array $level, float $akumulasi = 0.0): string
{
    $out = [];
    $pct = num((float)$level['pct'], (float)$level['pct'] == (int)$level['pct'] ? 0 : 1);
    $out[] = '• Diskon ' . $pct . '% untuk ' . member_scope_text() . ', berlaku pada transaksi '
        . 'minimal ' . money(member_min_transaction()) . '.';
    $next = member_next_level($level);
    if ($next) {
        $kurang = max(0.0, (float)$next['min_year'] - $akumulasi);
        $out[] = '• Menuju ' . $next['label'] . ': perlu akumulasi ' . member_period_label() . ' '
            . money((float)$next['min_year']) . ($kurang > 0 ? ' — kurang ' . money($kurang) . ' lagi' : ' — sudah tercapai')
            . ' (diskon ' . num((float)$next['pct'], (float)$next['pct'] == (int)$next['pct'] ? 0 : 1) . '%).';
    } else {
        $out[] = '• Anda berada pada level TERTINGGI — diskon terbesar sudah Anda nikmati.';
    }
    $out[] = '• Kartu member digital dapat diunduh kapan saja dari halaman pasien.';
    return implode("\n", $out);
}

/**
 * Kirim PDF kartu lengkap (2 halaman) untuk seorang pasien sebagai byte.
 * Memakai SATU sumber kode dengan halaman "Kartu Member Digital"
 * (`mcard_full_pdf()`) sehingga lampiran email identik dengan yang dicetak pemilik.
 */
function member_upgrade_pdf_bytes(array $patient, ?array $level = null): string
{
    require_once __DIR__ . '/pdf.php';
    require_once __DIR__ . '/receipt.php';            // tglIndo()
    require_once __DIR__ . '/member_card_pdf.php';
    $p = mcard_patient_row($patient);
    $lv = $level ?: (member_level_by_key((string)($p['member_level'] ?? '')) ?: member_status($p)['level']);
    return mcard_full_pdf($p, $lv, member_levels(), theme_current(),
        (string)($p['member_since'] ?: $p['created_at']), (string)setting('member_card_note'),
        member_card_bg_path(), logo_pdf_path());
}

/** Nama berkas lampiran PDF kartu. */
function member_upgrade_pdf_name(array $patient): string
{
    $no = preg_replace('/[^A-Za-z0-9\-]/', '-', (string)($patient['member_number'] ?? ''));
    return 'kartu-member-lengkap-' . ($no !== '' ? $no : ('pasien-' . (int)($patient['id'] ?? 0))) . '.pdf';
}

/* ------------------------------------------------------------------ *
 * PEREKAMAN
 * ------------------------------------------------------------------ */

/**
 * Catat satu kenaikan level (idempoten untuk level & periode yang sama).
 *
 * @return int id baris; 0 bila tidak direkam (bukan kenaikan / sudah pernah dicatat)
 */
function member_upgrade_record(int $patientId, ?string $oldLevel, string $newLevel,
                               float $totalAmount, ?int $orderId = null, string $invoice = '',
                               ?int $branchId = null, ?string $at = null): int
{
    if (!member_upgrade_is_upgrade($oldLevel, $newLevel)) return 0;

    /* Penjaga duplikat: satu pasien tidak dicatat dua kali untuk level yang SAMA pada
       periode akumulasi berjalan (transaksi beruntun / kirim ulang form tidak boleh
       menghasilkan dua baris & dua email). */
    $awal = member_period_start_date();
    $sama = one('SELECT id FROM member_upgrades WHERE patient_id = ? AND new_level = ?
                 AND upgraded_at >= ? ORDER BY id DESC LIMIT 1',
        [$patientId, $newLevel, $awal !== '' ? $awal : '1900-01-01']);
    if ($sama) return 0;

    $p = one('SELECT id, name, branch_id, member_number, email FROM patients WHERE id = ?', [$patientId]);
    if (!$p) return 0;

    $baru = member_level_by_key($newLevel);
    $lama = member_level_by_key((string)$oldLevel);
    q('INSERT INTO member_upgrades
        (patient_id, branch_id, member_number, old_level, old_label, old_pct,
         new_level, new_label, new_pct, upgraded_at, total_amount, order_id, invoice_number, email_to)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$patientId, (int)($branchId ?: $p['branch_id']), (string)($p['member_number'] ?? ''),
         (string)($oldLevel ?? ''), member_upgrade_level_label($oldLevel), member_upgrade_level_pct($oldLevel),
         $newLevel, (string)($baru['label'] ?? $newLevel), (float)($baru['pct'] ?? 0),
         $at !== null ? $at : date('Y-m-d H:i:s'), $totalAmount,
         $orderId ?: null, $invoice, (string)($p['email'] ?? '')]);
    return (int)db()->lastInsertId();
}

/**
 * Dipanggil SETELAH transaksi tersimpan (order_create.php).
 *
 * Merekam kenaikan level lalu — bila setelan mengizinkan — mengirim email ucapan
 * selamat beserta lampiran PDF kartu lengkap. Kegagalan email TIDAK pernah
 * menggagalkan transaksi: hasilnya dilaporkan apa adanya di pesan kasir.
 *
 * @return array{id:int,email:?array,pesan:string}
 */
function member_upgrade_after_order(array $patient, array $sync, ?int $orderId, string $invoice): array
{
    $hasil = ['id' => 0, 'email' => null, 'pesan' => ''];
    if (empty($sync['changed'])) return $hasil;
    $lama = (string)($sync['from'] ?? '');
    $baru = (string)($sync['to'] ?? '');
    if (!member_upgrade_is_upgrade($lama, $baru)) return $hasil;

    $total = (float)($sync['status']['year_total'] ?? 0);
    $id = member_upgrade_record((int)$patient['id'], $lama, $baru, $total, $orderId, $invoice,
        (int)($patient['branch_id'] ?? 0));
    if ($id <= 0) return $hasil;                      // sudah pernah dicatat pada periode ini
    $hasil['id'] = $id;
    $kartuBaru = $lama === '';
    audit($kartuBaru ? 'Kartu Member Baru (Membership Upgrade)' : 'Level Member Naik',
        'Pasien', (int)$patient['id'],
        ['level' => $lama], ['level' => $baru, 'akumulasi' => $total],
        ($kartuBaru ? 'Kartu member baru diberikan' : 'Kenaikan level kartu member')
        . ($invoice !== '' ? ' dari transaksi ' . $invoice : ''));

    /* Kalimat pembuka yang sesuai keadaannya (kartu BARU vs naik level). */
    $pembuka = $kartuBaru
        ? ' Pasien ini kini memiliki KARTU MEMBER baru (level ' . member_upgrade_level_label($baru) . ')'
        : ' Level member naik menjadi ' . member_upgrade_level_label($baru);

    if ((string)setting('email_member_upgrade_auto', '1') !== '1') {
        $hasil['pesan'] = $pembuka . ' — email ucapan selamat TIDAK dikirim otomatis '
            . '(dimatikan di menu Membership Upgrade).';
        return $hasil;
    }
    $err = null;
    $kirim = member_upgrade_email_send($id, $err, true);
    $hasil['email'] = ['ok' => $kirim, 'error' => (string)$err];
    $hasil['pesan'] = $pembuka . ' — '
        . ($kirim
            ? 'email ucapan selamat + kartu PDF sudah dikirim ke ' . member_upgrade_email_to($id) . '.'
            : 'email BELUM terkirim (' . $err . '). Dapat dikirim ulang dari menu Membership Upgrade.');
    return $hasil;
}

/* ------------------------------------------------------------------ *
 * TEMPLATE & PENGIRIMAN
 * ------------------------------------------------------------------ */

/** Ganti variabel template ({nama}, {level}, …) dengan nilai sebenarnya. */
function member_upgrade_render(string $tpl, array $ctx): string
{
    $cari = [];
    $ganti = [];
    foreach ($ctx as $k => $v) { $cari[] = '{' . $k . '}'; $ganti[] = (string)$v; }
    return str_replace($cari, $ganti, $tpl);
}

/** Susun nilai variabel template dari satu baris riwayat. */
function member_upgrade_ctx(int $id): array
{
    $r = member_upgrade_row($id);
    if (!$r) return [];
    $next = member_level_by_key((string)$r['new_level']);
    $akum = (float)$r['total_amount'];
    return [
        'nama' => (string)$r['name'],
        'member' => (string)$r['member_number'],
        'level' => (string)$r['new_label'],
        'level_lama' => (string)$r['old_label'],
        'diskon' => member_upgrade_discount_text((string)$r['new_level']),
        'benefit' => $next ? member_upgrade_benefit_text($next, $akum) : '',
        'akumulasi' => money($akum),
        'periode' => member_period_label(),
        'klinik' => clinic_name(),
        'cabang' => (string)($r['branch_name'] ?? ''),
        'tanggal' => function_exists('tglIndo') ? tglIndo(substr((string)$r['upgraded_at'], 0, 10)) : (string)$r['upgraded_at'],
    ];
}

/** Kalimat besar diskon level: "diskon 7,5% untuk Treatment & Skincare (termasuk semua paket)". */
function member_upgrade_discount_text(string $levelKey): string
{
    $lv = member_level_by_key($levelKey);
    if (!$lv) return '-';
    $pct = num((float)$lv['pct'], (float)$lv['pct'] == (int)$lv['pct'] ? 0 : 1);
    return 'diskon ' . $pct . '% untuk ' . member_scope_text();
}

/** Subjek email upgrade yang berlaku sekarang (template + nilai variabel). */
function member_upgrade_email_subject(int $id): string
{
    $ctx = member_upgrade_ctx($id);
    $tpl = (string)setting('email_member_upgrade_subject', '');
    if (trim($tpl) === '') $tpl = 'Selamat! Level Kartu Member Anda naik menjadi {level}';
    return member_upgrade_render($tpl, $ctx);
}

/**
 * Susun isi email (HTML) untuk satu baris riwayat.
 *
 * Teks template ditulis sebagai teks biasa (dengan variabel), lalu dibungkus
 * `email_wrap_html()` milik includes/mailer.php supaya tampilannya seragam dengan
 * email lain (kop klinik, warna tema).
 *
 * JEBAKAN: parameter ke-2 `email_wrap_html()` adalah HTML isi pesan (BUKAN daftar
 * baris) — daftar baris `['cells'=>…]` hanya dipakai untuk tabel lampiran. Kirim
 * teks biasa sebagai HTML yang sudah di-escape + `<br>`.
 */
function member_upgrade_email_html(int $id): string
{
    require_once __DIR__ . '/mailer.php';
    $ctx = member_upgrade_ctx($id);
    $tpl = (string)setting('email_member_upgrade_body', '');
    if (trim($tpl) === '') $tpl = "Halo {nama},\n\nLevel kartu member Anda sekarang: {level}\n{benefit}";
    $teks = member_upgrade_render($tpl, $ctx);
    return email_wrap_html('Level Kartu Member Naik — ' . (string)($ctx['nama'] ?? ''), nl2br(e($teks)));
}

/** Alamat email tujuan yang tercatat pada baris riwayat (diperbarui dari data pasien). */
function member_upgrade_email_to(int $id): string
{
    $r = member_upgrade_row($id);
    if (!$r) return '';
    $mail = trim((string)($r['email'] ?? ''));
    return $mail;
}

/**
 * Kirim email ucapan selamat + lampiran PDF kartu lengkap.
 *
 * @param bool $auto true = pengiriman otomatis (hormati penjaga kirim-ulang),
 *                    false = permintaan manual petugas (boleh kirim ulang).
 */
function member_upgrade_email_send(int $id, ?string &$err = null, bool $auto = false): bool
{
    require_once __DIR__ . '/mailer.php';
    $r = member_upgrade_row($id);
    if (!$r) { $err = 'Riwayat kenaikan level tidak ditemukan.'; return false; }
    if ((int)$r['patient_id'] <= 0) { $err = 'Pasien tidak ditemukan.'; return false; }
    $p = one('SELECT * FROM patients WHERE id = ?', [(int)$r['patient_id']]);
    if (!$p) { $err = 'Pasien tidak ditemukan.'; return false; }

    $to = trim((string)($p['email'] ?? ''));
    /* Alamat disimpan ulang apa adanya supaya tabel selalu memuat alamat terkini. */
    q('UPDATE member_upgrades SET email_to = ? WHERE id = ?', [$to, $id]);
    if ($to === '') {
        $err = 'Pasien belum memiliki alamat email. Lengkapi email pada data pasien lalu kirim ulang.';
        member_upgrade_mark_email($id, 'failed', '', $err);
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = 'Alamat email pasien tidak valid: ' . $to;
        member_upgrade_mark_email($id, 'failed', $to, $err);
        return false;
    }
    /* Pengaman kirim ULANG (pola yang sudah dipakai email struk): pengiriman
       otomatis tidak boleh menimpa baris yang sudah "terkirim" pada detik yang sama. */
    if ($auto && (string)$r['email_status'] === 'sent'
        && time() - strtotime((string)($r['email_sent_at'] ?: $r['upgraded_at'])) < 60) {
        $err = 'Email untuk kenaikan level ini baru saja dikirim.';
        return true;
    }

    $pdf = '';
    try {
        $pdf = member_upgrade_pdf_bytes($p, member_level_by_key((string)$r['new_level']));
    } catch (Throwable $e) {
        $pdf = '';
    }
    $lampiran = [];
    if ($pdf !== '' && substr($pdf, 0, 5) === '%PDF-') {
        $lampiran[] = ['name' => member_upgrade_pdf_name($p), 'mime' => 'application/pdf', 'data' => $pdf];
    }
    $ok = send_email($to, member_upgrade_email_subject($id), member_upgrade_email_html($id), $err, $lampiran);
    member_upgrade_mark_email($id, $ok ? 'sent' : 'failed', $to, $ok ? '' : (string)$err);
    audit($ok ? 'Email Membership Upgrade Terkirim' : 'Email Membership Upgrade Gagal', 'Pasien',
        (int)$r['patient_id'], ['level' => $r['new_level']],
        ['ke' => $to, 'lampiran' => count($lampiran), 'otomatis' => $auto ? 1 : 0], (string)$err);
    return $ok;
}

/** Simpan hasil pengiriman email pada baris riwayat. */
function member_upgrade_mark_email(int $id, string $status, string $to, string $error = ''): void
{
    q('UPDATE member_upgrades SET email_status = ?, email_to = ?, email_error = ?,
              email_sent_at = ' . ($status === 'sent' ? 'datetime("now","localtime")' : 'email_sent_at') . '
        WHERE id = ?', [$status, $to, $error, $id]);
}

/** Catat bahwa tautan WhatsApp sudah dibuka petugas ("disiapkan, belum terkirim"). */
function member_upgrade_mark_wa(int $id): void
{
    q('UPDATE member_upgrades SET wa_status = "prepared", wa_sent_at = datetime("now","localtime") WHERE id = ?', [$id]);
}

/** Isi pesan WhatsApp untuk satu baris riwayat. */
function member_upgrade_wa_message(int $id): string
{
    $ctx = member_upgrade_ctx($id);
    $tpl = (string)setting('wa_member_upgrade_template', '');
    if (trim($tpl) === '') {
        $tpl = "Halo Kak {nama}, selamat! Level kartu member Kakak naik menjadi *{level}*.\n\n"
            . "Benefit diskon: {diskon}\n{klinik}";
    }
    return member_upgrade_render($tpl, $ctx);
}

/* ------------------------------------------------------------------ *
 * PEMBACAAN (dipakai halaman & ekspor)
 * ------------------------------------------------------------------ */

/** Satu baris riwayat + data pasien & cabang. */
function member_upgrade_row(int $id): ?array
{
    $r = one('SELECT mu.*, p.name, p.phone, p.email, p.member_number AS p_member, b.name AS branch_name
              FROM member_upgrades mu
              JOIN patients p ON p.id = mu.patient_id
              LEFT JOIN branches b ON b.id = mu.branch_id
              WHERE mu.id = ?', [$id]);
    if (!$r) return null;
    if (trim((string)$r['member_number']) === '') $r['member_number'] = (string)($r['p_member'] ?? '');
    return $r;
}

/**
 * Daftar riwayat kenaikan level (mengikuti cakupan cabang akun).
 *
 * @param array $f ['dari'=>?, 'sampai'=>?, 'status'=>?, 'q'=>?, 'branch'=>?]
 * @return array{rows:array,total:int}
 */
function member_upgrade_list(array $f, int $perPage, int $page): array
{
    [$bs, $bp] = branch_sql('mu.branch_id');
    $w = ['1=1'];
    $p = [];
    /* Cakupan cabang akun (non-owner selalu dipin ke cabangnya oleh branch_sql). */
    if ($bs !== '') { $w[] = trim(preg_replace('/^\s*AND\s*/i', '', $bs)); foreach ($bp as $v) $p[] = $v; }
    if (!empty($f['dari'])) { $w[] = 'date(mu.upgraded_at) >= ?'; $p[] = (string)$f['dari']; }
    if (!empty($f['sampai'])) { $w[] = 'date(mu.upgraded_at) <= ?'; $p[] = (string)$f['sampai']; }
    if (!empty($f['status'])) {
        if ($f['status'] === 'sent') { $w[] = "mu.email_status = 'sent'"; }
        elseif ($f['status'] === 'belum') { $w[] = "(mu.email_status IS NULL OR mu.email_status = '' OR mu.email_status = 'failed')"; }
    }
    if (!empty($f['q'])) {
        $w[] = '(p.name LIKE ? OR mu.member_number LIKE ? OR p.phone LIKE ? OR COALESCE(p.email,"") LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    $where = implode(' AND ', $w);
    $total = (int)scalar("SELECT COUNT(*) FROM member_upgrades mu JOIN patients p ON p.id = mu.patient_id
                          WHERE {$where}", $p);
    $perPage = max(1, $perPage);
    $offset = max(0, ($page - 1) * $perPage);
    $rows = all("SELECT mu.*, p.name, p.phone, p.email, b.name AS branch_name
                 FROM member_upgrades mu
                 JOIN patients p ON p.id = mu.patient_id
                 LEFT JOIN branches b ON b.id = mu.branch_id
                 WHERE {$where}
                 ORDER BY mu.upgraded_at DESC, mu.id DESC
                 LIMIT {$perPage} OFFSET {$offset}", $p);
    return ['rows' => $rows, 'total' => $total];
}

/** Ringkasan untuk kartu statistik halaman. */
function member_upgrade_summary(): array
{
    [$bs, $bp] = branch_sql('mu.branch_id');
    $w = $bs !== '' ? trim(preg_replace('/^\s*AND\s*/i', '', $bs)) : '1=1';
    $r = one("SELECT COUNT(*) total,
                     SUM(CASE WHEN mu.email_status = 'sent' THEN 1 ELSE 0 END) terkirim,
                     SUM(CASE WHEN mu.email_status = 'failed' THEN 1 ELSE 0 END) gagal,
                     SUM(CASE WHEN COALESCE(mu.email_status,'') = '' THEN 1 ELSE 0 END) belum
              FROM member_upgrades mu WHERE {$w}", $bp);
    return [
        'total' => (int)($r['total'] ?? 0),
        'terkirim' => (int)($r['terkirim'] ?? 0),
        'gagal' => (int)($r['gagal'] ?? 0),
        'belum' => (int)($r['belum'] ?? 0),
    ];
}
