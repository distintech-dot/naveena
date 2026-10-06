<?php
/**
 * KOLOM CHAT AI DEVELOPER (ronde 49).
 * ===================================
 * Dipakai KARTU TUNGGAL di `ai_developer.php`, baik untuk permintaan BARU maupun
 * untuk melanjutkan/memperbaiki percakapan yang sedang dibuka — supaya pemilik
 * cukup memakai satu kotak yang sama seperti mengobrol.
 *
 * Kalimat ajaib yang diminta pemilik: **"lanjutkan dan terapkan"** — ditulis di
 * kotak ini, ia menerapkan perubahan (setelah uji otomatis lulus) tanpa perlu
 * mencari tombol.
 *
 * Variabel yang diharapkan sudah ada: `$t` (array tugas atau null) dan `$suite`.
 */
$chatBaru = !$t;
$chatContoh = [
    'Ubah icon menu AI Developer dan AI Settings jadi lebih jelas dan mudah dikenali.',
    'Di halaman Data Pasien, tambahkan kolom email pada tabel.',
    'Dari lampiran ini, ambil hanya nama, jenis kelamin, tanggal lahir, NIK, WA, email, dan alamat — masukkan ke cabang Kaliwungu. Nomor pasien dan nomor member dibuat otomatis.',
    'Buat halaman baru untuk mencatat keluhan pasien beserta tindak lanjutnya.',
];
?>
<div class="field">
  <label><?= $chatBaru ? 'Tulis perintah Anda seperti mengobrol biasa'
      : 'Tulis perbaikan lanjutan — atau <code>lanjutkan dan terapkan</code> bila sudah cocok' ?></label>
  <textarea class="input auto-grow" name="request" rows="4" required maxlength="8000"
    placeholder="<?= $chatBaru
      ? "Contoh:&#10;• Ubah icon menu AI Developer dan AI Settings jadi lebih jelas.&#10;• Di halaman Data Pasien, tambahkan kolom email pada tabel.&#10;• Ambil dari lampiran Excel ini hanya nama, jenis kelamin, ttl, NIK, WA, email, dan alamat — masukkan ke cabang Kaliwungu; nomor pasien &amp; nomor member dibuat otomatis."
      : "Contoh:&#10;• lanjutkan dan terapkan&#10;• icon-nya masih tertukar, tolong periksa lagi.&#10;• kolom itu kurang lebar, coba rapikan." ?>"></textarea>
  <span class="hint">Tidak perlu memilih menu — AI <strong>menelusuri sendiri</strong> berkas yang terkait.
    <?php if ($chatBaru): ?>
      Bila perintah Anda menyangkut data, lampirkan berkasnya (Excel/PDF/gambar).
    <?php else: ?>
      Tulisan di sini <strong>melanjutkan</strong> percakapan ini, jadi AI mengingat permintaan &amp; hasil
      sebelumnya.
    <?php endif; ?>
  </span>
</div>

<div class="form-grid g2 mt-2">
  <div class="field"><label>Lampiran (format apa pun: Excel, PDF, gambar, dokumen)</label>
    <input class="input" type="file" name="files[]" multiple
           accept=".xlsx,.xls,.csv,.tsv,.txt,.json,.md,.pdf,.docx,.pptx,.odt,.ods,.rtf,image/*">
    <span class="hint"><?= e(ai_upload_limit_text()) ?> Maksimal 8 berkas per permintaan.
      Isi Excel/CSV/PDF/dokumen dibaca sebagai teks; <strong>gambar &amp; PDF juga dibaca
      langsung oleh model AI</strong> (Gemini).</span></div>
  <div class="field"><label>Periksa hasil dengan</label>
    <select class="input" name="suite">
      <option value="auto"<?= (string)($t['suite'] ?? 'auto') === 'auto' ? ' selected' : '' ?>>Otomatis — AI memilih yang paling sesuai</option>
      <?php foreach ($suite as $s): ?>
        <option value="<?= e($s) ?>"<?= (string)($t['suite'] ?? '') === $s ? ' selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <span class="hint">Uji ini dijalankan <strong>sendiri oleh AI</strong> di salinan aplikasi setelah usulan
      selesai — Anda tidak perlu menekan tombol uji.</span></div>
</div>

<?php /* RONDE 50: mode boleh dipaksa, tetapi TIDAK WAJIB — bawaannya "Otomatis"
         (AI menebak sendiri dari isi pesan), sesuai permintaan pemilik agar AI
         Developer dapat diajak ngobrol tanpa memilih mode setiap saat. */ ?>
<?php $modusKini = (string)($t['intent_mode'] ?? 'auto'); ?>
<div class="field mt-2" style="max-width:460px">
  <label>Mode (opsional — biarkan <em>Otomatis</em> bila tidak yakin)</label>
  <select class="input" name="modus">
    <?php foreach (ai_intent_modes() as $mk => $ml): ?>
      <option value="<?= e($mk) ?>"<?= $modusKini === $mk ? ' selected' : '' ?>><?= e($ml) ?></option>
    <?php endforeach; ?>
  </select>
  <span class="hint">Dengan <strong>Otomatis</strong>, AI membedakan sendiri: pertanyaan/diskusi/minta saran
    dijawab tanpa mengubah kode, sedangkan permintaan pekerjaan langsung dikerjakan (dan diuji sendiri).</span>
</div>

<?php if (!$chatBaru): ?>
  <div class="field mt-2" style="max-width:340px">
    <label>Kata sandi Anda <span class="muted">(diperlukan hanya saat menerapkan perubahan)</span></label>
    <input class="input" type="password" name="password" autocomplete="current-password"
           placeholder="kata sandi login Anda">
    <span class="hint">Penerapan menulis ulang berkas aplikasi, jadi tetap memakai kata sandi
      Super Admin — salinan pengaman dibuat otomatis.</span>
  </div>
<?php endif; ?>

<div class="mt-2">
  <div class="muted small">Perintah yang bisa diklik (akan mengisi kotak di atas):</div>
  <div class="flex gap-sm flex-wrap mt-1">
    <?php if (!$chatBaru): ?>
      <button class="btn btn-sm btn-primary" type="button" data-isi="lanjutkan dan terapkan">
        <?= icon('check') ?> lanjutkan dan terapkan</button>
    <?php endif; ?>
    <?php foreach ($chatContoh as $c): ?>
      <button class="btn btn-sm" type="button" data-contoh="<?= e($c) ?>"><?= e(short_text($c, 62)) ?></button>
    <?php endforeach; ?>
  </div>
</div>

<div class="flex gap-sm flex-wrap mt-2" style="align-items:center">
  <button class="btn btn-primary" type="submit"<?= ai_ready() ? '' : ' disabled' ?>>
    <?= icon('sparkles') ?> <?= $chatBaru ? 'Kirim ke AI' : 'Kirim &amp; lanjutkan' ?></button>
  <?php if (!$chatBaru): ?>
    <span class="muted small">Ketik <code>lanjutkan dan terapkan</code> untuk menerapkan perubahan
      (setelah uji otomatis lulus), atau tulis perbaikannya bila masih ada yang kurang.</span>
  <?php else: ?>
    <span class="muted small">Alur: AI menelusuri kode → usulan perubahan → <strong>uji sendiri di salinan</strong>
      → pesan + pratinjau di percakapan → Anda ketik <em>lanjutkan dan terapkan</em> → baru diterapkan.</span>
  <?php endif; ?>
</div>
<?php if ($chatBaru): ?>
  <div class="notice small mt-2">
    AI <strong>tidak langsung mengubah kode</strong>. Semuanya bisa Anda lihat dulu di percakapan:
    <em>pesan hasil</em>, <em>pratinjau halaman</em>, <em>perbedaan kode (diff)</em>, dan <em>hasil uji</em>.
    Butuh penjelasan tiap bagian? Lihat <a href="#panduan">Panduan</a>.
  </div>
<?php endif; ?>
