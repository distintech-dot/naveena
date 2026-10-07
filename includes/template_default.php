<?php
/**
 * TEMPLATE BAWAAN (DEFAULT) UNTUK TEKS EMAIL & WHATSAPP
 * ====================================================
 * Permintaan pemilik: pada Pengaturan Sistem → kartu "Email Struk ke Pasien" dan
 * kartu "WhatsApp" perlu tombol **Kembali ke Default**, supaya teks yang sudah
 * berkali-kali diubah dapat dikembalikan ke template bakunya — dengan konfirmasi
 * 2 tahap agar tidak terpencet tanpa sengaja.
 *
 * TEMPLATE YANG SEKARANG = DEFAULT (permintaan pemilik). Karena itu nilai baku
 * diambil dari **rekaman** nilai yang berlaku saat fitur ini dipasang
 * (`tpl_default_<kunci>`), bukan dari teks bawaan versi lama. Rekaman dibuat sekali
 * oleh template_default_snapshot_ensure() dan tidak pernah berubah lagi — jadi
 * mengubah-ubah template TIDAK mengubah bakuannya, dan tombol "Kembali ke Default"
 * selalu mengembalikan teks yang benar.
 *
 * Bila rekaman belum ada (instalasi baru), dipakai teks bawaan aplikasi.
 */

/** Kunci setelan yang punya tombol "Kembali ke Default". */
function template_default_keys(): array
{
    return ['email_receipt_subject', 'email_receipt_body',
        'wa_template', 'wa_template_doctor', 'wa_receipt_template'];
}

/** Kelompok kunci per kartu pengaturan (dipakai tombol & pesan hasil). */
function template_default_groups(): array
{
    return [
        'email' => ['label' => 'Email Struk ke Pasien',
            'keys' => ['email_receipt_subject', 'email_receipt_body']],
        'wa' => ['label' => 'WhatsApp',
            'keys' => ['wa_template', 'wa_template_doctor', 'wa_receipt_template']],
    ];
}

/**
 * Teks BAWAAN aplikasi (versi rilis terakhir) untuk sebuah kunci — dipakai sebagai
 * cadangan bila rekaman default belum ada (instalasi baru / berkas rekaman hilang).
 */
function template_code_default(string $key): string
{
    switch ($key) {
        case 'email_receipt_subject':
            return function_exists('receipt_email_default_subject') ? receipt_email_default_subject() : 'Struk {invoice} — {klinik}';
        case 'email_receipt_body':
            /* Fungsi template email hidup di includes/mailer.php, sedangkan rekaman
               default dijalankan saat skema disiapkan (mailer belum tentu dimuat) →
               teks bawaannya disalin di sini sebagai cadangan yang SAMA. */
            if (function_exists('receipt_email_default_body')) return receipt_email_default_body();
            $hari = (int)setting('receipt_link_days', '30');
            if ($hari <= 0) $hari = 30;
            return "Halo {nama},\n\nTerima kasih telah melakukan perawatan di {klinik} {cabang}.\n"
                . "Berikut rincian transaksi Anda:\nNo. Invoice: {invoice}\nTanggal: {tanggal}\n"
                . "Total: {total}\nMetode: {metode}\n\n"
                . "Struk PDF juga dapat diunduh pada tautan berikut (berlaku " . $hari . " hari):\n{link}\n\n"
                . "Salam sehat,\n{klinik}";
        case 'wa_template':
            return "Halo Kak {nama}\n\nKami dari {klinik} {cabang}.\nMengingatkan reservasi Kakak:\n"
                . "Tanggal: {tanggal}\nJam: {jam}\nTreatment: {treatment}\nDokter/Terapis: {dokter}\n\n"
                . "Mohon konfirmasi kehadirannya ya\nTerima kasih ❤️";
        case 'wa_template_doctor':
            return "Selamat pagi/siang Dokter {dokter},\n\nPengingat jadwal praktik di {klinik} {cabang}:\n"
                . "Tanggal: {tanggal}\nJam: {jam}\nPasien: {nama}\nTreatment: {treatment}\n\n"
                . "Mohon konfirmasi ketersediaannya. Terima kasih.";
        case 'wa_receipt_template':
            return "Halo Kak {nama} 🙏\n\nTerima kasih telah melakukan perawatan di {klinik} {cabang}.\n\n"
                . "Rincian transaksi Kakak:\nNo. Invoice: {invoice}\nTanggal: {tanggal}\nTotal: {total}\n"
                . "Metode: {metode}\n\nStruk digital: {link}\n\nSalam sehat,\n{klinik} {cabang}";
    }
    return '';
}

/** Nilai baku (default) yang berlaku untuk sebuah kunci template. */
function template_default_value(string $key): string
{
    $rec = (string)setting('tpl_default_' . $key, '');
    if (trim($rec) !== '') return $rec;
    return template_code_default($key);
}

/**
 * Rekam nilai yang BERLAKU SEKARANG sebagai default (sekali saja, idempoten).
 *
 * Dipanggil saat pemasangan/naik versi: template yang sedang dipakai pemilik
 * menjadi bakuannya, sehingga tombol "Kembali ke Default" mengembalikan teks itu.
 *
 * @return int jumlah kunci yang direkam
 */
function template_default_snapshot_ensure(): int
{
    $n = 0;
    foreach (template_default_keys() as $k) {
        if (trim((string)setting('tpl_default_' . $k, '')) !== '') continue;   // sudah terekam
        $now = (string)setting($k, '');
        $val = trim($now) !== '' ? $now : template_code_default($k);
        if (trim($val) === '') continue;
        set_setting('tpl_default_' . $k, $val);
        $n++;
    }
    return $n;
}

/**
 * Kembalikan seluruh teks pada satu kelompok (email / wa) ke bakuannya.
 *
 * @return array{dari:array<string,string>,ke:array<string,string>}
 */
function template_default_restore(string $grup): array
{
    $groups = template_default_groups();
    $keys = $groups[$grup]['keys'] ?? [];
    $dari = [];
    $ke = [];
    foreach ($keys as $k) {
        $dari[$k] = (string)setting($k, '');
        $ke[$k] = template_default_value($k);
        set_setting($k, $ke[$k]);
    }
    return ['dari' => $dari, 'ke' => $ke];
}

/**
 * Apakah salah satu teks pada kelompok ini BERBEDA dari bakuannya?
 * Dipakai untuk menonaktifkan tombol bila memang tidak ada yang perlu dikembalikan.
 */
function template_default_changed(string $grup): array
{
    $groups = template_default_groups();
    $beda = [];
    foreach ($groups[$grup]['keys'] ?? [] as $k) {
        if ((string)setting($k, '') !== template_default_value($k)) $beda[] = $k;
    }
    return $beda;
}

/** Jumlah baris teks pada sebuah template (untuk keterangan singkat). */
function template_default_ringkas(string $key): string
{
    $v = template_default_value($key);
    $baris = substr_count($v, "\n") + 1;
    return num($baris) . ' baris · ' . num(strlen($v)) . ' huruf';
}
