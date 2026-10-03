<?php
/**
 * IDENTITAS KLINIK — satu variabel "nama klinik" untuk SELURUH aplikasi.
 *
 * Nama klinik disimpan pada setelan `company_name` (Pengaturan Sistem → Nama
 * Klinik, khusus Super Admin). Semua tampilan yang memuat nama klinik harus
 * memakai clinic_name() / setting('company_name') — TIDAK boleh menulis nama
 * klinik langsung di kode, supaya mengganti nama klinik (mis. "Naveena
 * Skincare" → "Immoderma") langsung berlaku di sidebar, login, struk, PDF
 * laporan, kartu member, email, pesan WhatsApp, ekspor, dan dokumen lainnya.
 *
 * Berkas ini juga berisi alat penggantian nama yang TERINTEGRASI:
 *   - clinic_rename_plan()  : pratinjau (tanpa mengubah apa pun)
 *   - clinic_rename_apply() : menjalankan penggantian + melaporkan sisanya
 * sehingga teks template (WA/email/catatan struk), nama pengirim email, catatan
 * kartu member, dan nama cabang yang memuat nama lama ikut diperbarui — bukan
 * hanya judul di layar.
 */
declare(strict_types=1);

/** Nama klinik yang sedang aktif (selalu terisi). */
function clinic_name(): string
{
    $n = trim((string)setting('company_name'));
    return $n !== '' ? $n : APP_NAME;
}

/** Tagline klinik (boleh kosong). */
function clinic_tagline(): string
{
    return trim((string)setting('company_tagline'));
}

/**
 * Label cabang tanpa awalan nama klinik — dipakai pada grafik/sumbu yang sempit
 * (mis. "Naveena Skincare Kaliwungu" → "Kaliwungu").
 *
 * Dulu pemotongan ini ditulis langsung sebagai str_replace('Naveena Skincare ', …)
 * di beberapa halaman; setelah nama klinik diganti, potongan itu tidak lagi
 * cocok sehingga label grafik kembali menampilkan nama panjang.
 */
function branch_short_label(string $name, ?string $clinicName = null): string
{
    $full = trim(preg_replace('/\s+/', ' ', $name));
    $clinic = trim((string)($clinicName ?? clinic_name()));
    if ($clinic !== '' && stripos($full, $clinic) === 0) {
        $rest = trim(substr($full, strlen($clinic)));
        $rest = ltrim($rest, "-–—·:, \t");
        if ($rest !== '') return $rest;
    }
    return $full;
}

/**
 * Ukuran huruf untuk NAMA KLINIK pada dokumen PDF (kop struk & kop laporan).
 *
 * Nama klinik bisa panjang (sampai 60 karakter), sedangkan lebar kertas struk
 * hanya 80 mm. Sebelumnya nama dicetak dengan `line()` yang TIDAK membungkus
 * teks, sehingga nama panjang meluber keluar tepi kertas. Sekarang ukurannya
 * mengecil seiring panjang nama + dicetak dengan `paragraph()` yang membungkus.
 */
function clinic_pdf_name_size(string $name, float $max = 15.0, float $min = 10.5): float
{
    return doc_line_fit_size($name, $max, $min);
}

/**
 * Ukuran huruf untuk satu baris teks dokumen: mengecil seiring panjang teks.
 * Dipakai untuk baris KOP (nama klinik & nama cabang) yang ruangnya terbatas —
 * mis. lebar kertas struk hanya 80 mm. Teks tetap dicetak dengan paragraph()
 * supaya bila masih panjang ia MEMBUNGKUS ke baris berikutnya.
 */
function doc_line_fit_size(string $text, float $max = 9.0, float $min = 6.8,
                           int $shortLen = 20, int $longLen = 46): float
{
    $len = (int)preg_match_all('/./us', trim($text));
    if ($len <= $shortLen) return $max;
    if ($len >= $longLen) return $min;
    $t = ($len - $shortLen) / max(1, $longLen - $shortLen);
    return round($max - ($max - $min) * $t, 1);
}

/* ------------------------------------------------------------------ *
 * Di mana nama klinik dipakai (ditampilkan di kartu Pengaturan)
 * ------------------------------------------------------------------ */
function clinic_name_places(): array
{
    return [
        'Sidebar, logo aplikasi, dan catatan kaki setiap halaman',
        'Halaman login & judul tab peramban (title)',
        'Dashboard (judul "Dashboard Pusat")',
        'Struk pembayaran — HTML, PDF, dan tautan struk publik tanpa login',
        'Kartu member — tampilan layar dan PDF ukuran kartu',
        'Dokumen laporan lengkap — HTML cetak, PDF, dan Excel (termasuk grafik)',
        'Ekspor data (CSV/Excel/PDF) dan berkas laporan bulanan',
        'Email — nama pengirim, subjek, isi, dan lampiran laporan bulanan',
        'Pesan WhatsApp — reservasi, pengingat dokter, dan struk',
        'Halaman mode pemeliharaan dan halaman "akses ditolak"',
        'Label grafik perbandingan cabang (nama klinik dipotong dari nama cabang)',
        'Kepala berkas backup database',
        'Nama cabang contoh pada fitur "Isi Data Demo"',
    ];
}

/** Label manusiawi untuk setelan teks yang mungkin memuat nama klinik. */
function clinic_setting_labels(): array
{
    return [
        'company_name'          => 'Nama Klinik',
        'company_tagline'       => 'Tagline Klinik',
        'company_address'       => 'Alamat Pusat',
        'company_phone'         => 'Telepon Klinik',
        'company_email'         => 'Email Klinik',
        'receipt_footer'        => 'Catatan Kaki Struk',
        'member_card_note'      => 'Catatan Kartu Member',
        'email_sender_name'     => 'Nama Pengirim Email',
        'email_recipient'       => 'Email Tujuan Laporan',
        'email_reply_to'        => 'Reply-To Email',
        'email_sender'          => 'Email Pengirim',
        'email_api_domain'      => 'Domain API Email',
        'email_receipt_subject' => 'Subjek Email Struk',
        'email_receipt_body'    => 'Isi Email Struk',
        'wa_template'           => 'Template WA Reservasi',
        'wa_template_doctor'    => 'Template WA Pengingat Dokter',
        'wa_receipt_template'   => 'Template WA Struk',
        'wa_api_sender'         => 'Pengirim WhatsApp API',
        'wa_sender_number'      => 'Nomor WhatsApp Pengirim',
        'pay_bank_name'         => 'Nama Bank',
        'pay_bank_holder'       => 'Nama Pemilik Rekening',
        'pay_note'              => 'Catatan Pembayaran',
        'maintenance_title'     => 'Judul Pengumuman Pemeliharaan',
        'maintenance_message'   => 'Pesan Pemeliharaan',
        'maintenance_contact_name' => 'Nama Kontak Pemeliharaan',
        'maintenance_note'      => 'Catatan Pemeliharaan',
    ];
}

/** Setelan yang isinya TEKS branding/template → ikut diganti otomatis. */
function clinic_name_setting_keys(): array
{
    return ['company_tagline', 'receipt_footer', 'member_card_note', 'email_sender_name',
        'email_receipt_subject', 'email_receipt_body', 'wa_template', 'wa_template_doctor',
        'wa_receipt_template', 'pay_note'];
}

/* ------------------------------------------------------------------ *
 * Penggantian nama klinik
 * ------------------------------------------------------------------ */
/** Periksa & rapikan nama klinik baru; kembalikan pesan galat bila tidak sah. */
function clinic_name_validate(string $raw): array
{
    $name = trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
    if ($name === '') {
        return ['ok' => false, 'name' => '', 'error' => 'Nama klinik tidak boleh kosong.'];
    }
    /* Tag/kurung sudut ditolak (bukan diam-diam dibuang) supaya pengguna tahu
       apa yang tersimpan — dan agar tidak ada jalan masuk teks HTML. */
    if (strpos($name, '<') !== false || strpos($name, '>') !== false) {
        return ['ok' => false, 'name' => $name,
            'error' => 'Nama klinik tidak boleh memuat tanda < > atau tag HTML. Hapus tanda tersebut lalu simpan lagi.'];
    }
    $len = (int)preg_match_all('/./us', $name);
    if ($len < 2) {
        return ['ok' => false, 'name' => $name, 'error' => 'Nama klinik terlalu pendek (minimal 2 karakter).'];
    }
    if ($len > 60) {
        return ['ok' => false, 'name' => $name, 'error' => 'Nama klinik terlalu panjang (maksimal 60 karakter) — nama panjang akan terpotong di sidebar dan kop dokumen.'];
    }
    /* Karakter yang berbahaya untuk HTML/PDF/CSV ditolak sejak awal. */
    if (preg_match('/["\\\\\x00-\x1F\x7F]/', $name)) {
        return ['ok' => false, 'name' => $name, 'error' => 'Nama klinik tidak boleh memuat kutip ganda atau karakter kendali.'];
    }
    /* Huruf (termasuk beraksen), angka, spasi, dan tanda baca umum saja. */
    if (preg_match('/[^\p{L}\p{N}\p{Zs}.\,\'\(\)\&\+\/\-#%°:!_]/u', $name)) {
        return ['ok' => false, 'name' => $name,
            'error' => 'Nama klinik hanya boleh memuat huruf, angka, spasi, dan tanda baca umum ( . , \' ( ) & + / - # % ° : ! _ ). Emoji atau simbol lain tidak didukung karena dokumen PDF (struk/laporan) memakai font terbatas.'];
    }
    return ['ok' => true, 'name' => $name, 'error' => ''];
}

/**
 * Susun rencana penggantian nama klinik (TIDAK mengubah apa pun).
 *
 * @param bool $withTemplates ikut mengganti nama lama pada teks template
 * @param bool $withBranches  ikut mengganti nama lama pada nama cabang
 * @return array{ok:bool,error:string,old:string,new:string,settings:array,branches:array,leftovers:array}
 */
function clinic_rename_plan(string $newName, bool $withTemplates = true, bool $withBranches = true): array
{
    $check = clinic_name_validate($newName);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error'], 'old' => clinic_name(), 'new' => '',
                'settings' => [], 'branches' => [], 'leftovers' => []];
    }
    $new = $check['name'];
    $old = clinic_name();
    $labels = clinic_setting_labels();
    $settings = [];
    $branches = [];

    if ($old !== '' && strcasecmp($old, $new) !== 0) {
        if ($withTemplates) {
            foreach (clinic_name_setting_keys() as $key) {
                $val = (string)setting($key);
                if ($val === '' || stripos($val, $old) === false) continue;
                $settings[] = ['key' => $key, 'label' => $labels[$key] ?? $key,
                    'before' => $val, 'after' => str_ireplace($old, $new, $val)];
            }
        }
        if ($withBranches) {
            foreach (all('SELECT id, code, name FROM branches ORDER BY id') as $b) {
                $nm = (string)$b['name'];
                if (stripos($nm, $old) === false) continue;
                $branches[] = ['id' => (int)$b['id'], 'code' => (string)$b['code'], 'before' => $nm,
                    'after' => str_ireplace($old, $new, $nm)];
            }
        }
    }
    return ['ok' => true, 'error' => '', 'old' => $old, 'new' => $new,
        'settings' => $settings, 'branches' => $branches,
        'leftovers' => clinic_leftover_report($old, $new)];
}

/**
 * Sisa tempat yang MASIH memuat nama lama setelah penggantian — supaya pengguna
 * tahu apa yang perlu dibereskan sendiri (mis. alamat email yang memakai domain
 * lama) dan tidak mengira semuanya otomatis berubah.
 *
 * @return array<int,array{where:string,label:string,text:string}>
 */
function clinic_leftover_report(string $oldName, string $newName = ''): array
{
    $old = trim($oldName);
    if ($old === '') return [];
    $labels = clinic_setting_labels();
    $out = [];
    /* Kata pertama nama lama (mis. "Naveena") dipakai untuk menangkap bentuk
       ringkas seperti domain email "naveenaskincare.id". */
    preg_match('/^\S+/u', $old, $m);
    $token = (string)($m[0] ?? '');
    $hit = function (string $text) use ($old, $token): bool {
        if ($text === '') return false;
        if (stripos($text, $old) !== false) return true;
        return $token !== '' && strlen($token) >= 4 && stripos($text, $token) !== false;
    };
    $autoKeys = clinic_name_setting_keys();
    foreach (array_keys($labels) as $key) {
        if ($key === 'company_name') continue;          // sudah diganti
        $val = (string)setting($key);
        /* Untuk PRATINJAU (sebelum dijalankan): teks yang memang ikut diganti
           otomatis dianggap sudah berubah, supaya daftar "perlu diperiksa"
           tidak menampilkan hal yang justru akan dibereskan sendiri. */
        if ($newName !== '' && in_array($key, $autoKeys, true)) {
            $val = str_ireplace($old, $newName, $val);
        }
        if (!$hit($val)) continue;
        $out[] = ['where' => 'setting', 'key' => $key, 'label' => $labels[$key] ?? $key,
            'text' => short_text($val, 120)];
    }
    /* Email cabang (mis. kaliwungu@naveenaskincare.id) tidak diganti otomatis:
       domain email adalah keputusan pemilik klinik, jadi hanya dilaporkan. */
    foreach (all('SELECT id, code, email FROM branches ORDER BY id') as $b) {
        $val = (string)($b['email'] ?? '');
        if (!$hit($val)) continue;
        $out[] = ['where' => 'branch', 'id' => (int)$b['id'],
            'label' => 'Email cabang (' . (string)$b['code'] . ')', 'text' => $val];
    }
    return $out;
}

/**
 * Jalankan penggantian nama klinik.
 *
 * Semua perubahan (nama klinik, teks template, nama cabang) dilakukan dalam
 * SATU transaksi sehingga tidak ada keadaan setengah jalan bila terjadi galat.
 *
 * @param array $opts ['templates'=>bool,'branches'=>bool,'reason'=>string]
 * @return array{ok:bool,error:string,renamed:bool,old:string,new:string,settings:array,branches:array,leftovers:array}
 */
function clinic_rename_apply(string $newName, array $opts = [], ?int $userId = null): array
{
    $withTemplates = $opts['templates'] ?? true;
    $withBranches = $opts['branches'] ?? true;
    $plan = clinic_rename_plan($newName, (bool)$withTemplates, (bool)$withBranches);
    if (!$plan['ok']) {
        return ['ok' => false, 'error' => $plan['error'], 'renamed' => false, 'old' => $plan['old'], 'new' => '',
                'settings' => [], 'branches' => [], 'leftovers' => []];
    }
    $old = $plan['old'];
    $new = $plan['new'];
    if (strcasecmp($old, $new) === 0) {
        /* Hanya beda huruf besar/kecil pun tetap disimpan (nama tampil berubah). */
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        set_setting('company_name', $new);
        foreach ($plan['settings'] as $s) {
            set_setting($s['key'], $s['after']);
        }
        foreach ($plan['branches'] as $b) {
            q('UPDATE branches SET name = ?, updated_at = datetime("now","localtime") WHERE id = ?',
                [$b['after'], (int)$b['id']]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        settings(true);
        return ['ok' => false, 'error' => 'Penggantian nama klinik dibatalkan (tidak ada data yang berubah): ' . $ex->getMessage(),
                'renamed' => false, 'old' => $old, 'new' => $old, 'settings' => [], 'branches' => [], 'leftovers' => []];
    }
    settings(true);
    $leftovers = clinic_leftover_report($old, $new);
    audit('Ubah Nama Klinik', 'Pengaturan', null, ['nama_klinik' => $old],
        ['nama_klinik' => $new,
         'teks_diperbarui' => array_map(fn($s) => $s['key'], $plan['settings']),
         'cabang_diperbarui' => array_map(fn($b) => $b['code'] . ' → ' . $b['after'], $plan['branches']),
         'sisa_diperiksa' => count($leftovers)],
        ($opts['reason'] ?? '') !== '' ? (string)$opts['reason']
            : 'Nama klinik diubah dari "' . $old . '" menjadi "' . $new . '"');
    return ['ok' => true, 'error' => '', 'renamed' => true, 'old' => $old, 'new' => $new,
        'settings' => $plan['settings'], 'branches' => $plan['branches'], 'leftovers' => $leftovers];
}
