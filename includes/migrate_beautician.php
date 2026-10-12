<?php
/**
 * GANTI NAMA "Terapis" MENJADI "Beautician" PADA TEKS TERSIMPAN (RONDE 79).
 *
 * Perubahan nama ini menyentuh DUA tempat yang berbeda:
 *   1. TEKS di basis data CENTRAL (setelan): template WhatsApp/email yang sudah
 *      disunting pemilik masih memuat "Dokter/Terapis" — teks itu yang dikirim ke
 *      pasien/dokter, jadi harus ikut berubah.
 *   2. JUDUL/keahlian tenaga medis (`therapists.specialization`) di berkas CABANG
 *      (mis. "Terapis Perawatan Kulit") — tampil pada daftar Dokter & Beautician.
 *
 * YANG SENGAJA TIDAK DISENTUH: `audit_logs` (riwayat tindakan lampau = catatan
 * sejarah) dan teks bebas yang ditulis pengguna (catatan klinis, nama orang).
 * Kedua fungsi ini IDEMPOTEN: setelah dijalankan, pencarian kata itu tidak
 * menemukan apa pun lagi sehingga aman dijalankan berulang.
 */
function migrate_beautician_label_central(PDO $pdo): void
{
    try {
        $st = $pdo->prepare("SELECT key, value FROM settings WHERE value LIKE '%Terapis%'");
        $st->execute();
        $ubah = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$ubah) return;
        $up = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
        foreach ($ubah as $r) {
            $baru = str_replace(['Dokter/Terapis', 'Dokter / Terapis', 'Terapis'], ['Dokter/Beautician', 'Dokter / Beautician', 'Beautician'], (string)$r['value']);
            if ($baru !== (string)$r['value']) $up->execute([$baru, (string)$r['key']]);
        }
    } catch (Throwable $e) { /* tabel settings belum ada — lewati */ }
}

/** Ganti kata "Terapis" pada judul/keahlian tenaga medis di berkas CABANG. */
function migrate_beautician_label_branch(PDO $pdo): void
{
    try {
        $ada = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='therapists'")->fetchColumn();
        if ($ada === 0) return;
        $st = $pdo->prepare("SELECT id, specialization FROM therapists WHERE specialization LIKE '%Terapis%'");
        $st->execute();
        $ubah = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$ubah) return;
        $up = $pdo->prepare('UPDATE therapists SET specialization = ? WHERE id = ?');
        foreach ($ubah as $r) {
            $up->execute([str_replace('Terapis', 'Beautician', (string)$r['specialization']), (int)$r['id']]);
        }
    } catch (Throwable $e) { /* tanpa tabel therapists — lewati */ }
}
