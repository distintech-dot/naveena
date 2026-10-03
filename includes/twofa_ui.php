<?php
/**
 * KARTU PENYIAPAN VERIFIKASI 2 LANGKAH (Google Authenticator).
 * ==========================================================
 *
 * Dipakai DUA tempat dengan komponen yang sama supaya tidak ada dua cara berbeda:
 *   • Developer Settings → kartu "Keamanan Login" (akun Super Admin sendiri),
 *   • Profil Saya → semua level dapat menyiapkan 2FA untuk akunnya.
 *
 * ATURAN PENTING (permintaan pemilik): 2FA hanya boleh AKTIF setelah pemilik
 * benar-benar memindai QR dan memasukkan kode yang benar. Sebelum dikonfirmasi,
 * statusnya "belum aktif" dan tidak pernah dipakai untuk memblokir login.
 * Karena email belum tentu bisa mengirim, kode cadangan lewat email hanya
 * ditawarkan bila layanan email sudah dikonfigurasi.
 */
declare(strict_types=1);

require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/login_security.php';

/** Nomor langkah/aksi penyiapan 2FA (dipakai kedua halaman). */
function twofa_handle_post(array $user, string $act): void
{
    if ($act === 'twofa_start') {
        /* Buat kunci baru (belum aktif) + tampilkan QR untuk dipindai. */
        $secret = totp_secret();
        q('UPDATE users SET totp_secret = ?, totp_enabled = 0, totp_confirmed_at = NULL WHERE id = ?',
            [$secret, (int)$user['id']]);
        audit('Mulai Penyiapan 2FA', 'Auth', (int)$user['id'], null, null,
            'Kunci TOTP baru dibuat — menunggu pemindaian QR & verifikasi kode');
        flash('Kunci baru dibuat. Pindai QR di bawah dengan Google Authenticator, lalu masukkan '
            . '6 angka yang muncul untuk MENGAKTIFKAN. Selama belum diverifikasi, 2FA belum aktif.');
        return;
    }
    if ($act === 'twofa_confirm') {
        $kode = (string)($_POST['code'] ?? '');
        $secret = (string)($user['totp_secret'] ?? '');
        if ($secret === '') {
            flash('Belum ada kunci 2FA. Klik "Mulai Penyiapan" lebih dulu.', 'error');
            return;
        }
        if (!totp_verify($secret, $kode)) {
            audit('Verifikasi 2FA Gagal', 'Auth', (int)$user['id'], null, null,
                'Kode dari aplikasi authenticator tidak cocok saat penyiapan');
            flash('Kode tidak cocok. Pastikan waktu di HP Anda otomatis (jam harus akurat) lalu coba lagi.',
                'error');
            return;
        }
        q('UPDATE users SET totp_enabled = 1, totp_confirmed_at = datetime("now","localtime") WHERE id = ?',
            [(int)$user['id']]);
        audit('Aktifkan 2FA', 'Auth', (int)$user['id'], null, null,
            'Verifikasi 2 langkah (Google Authenticator) AKTIF setelah kode terbukti benar');
        flash('Verifikasi 2 langkah AKTIF. Simpan kode pemulihan Anda di tempat aman — kode itu '
            . 'satu-satunya cara masuk bila HP hilang.');
        return;
    }
    if ($act === 'twofa_disable') {
        $pass = (string)($_POST['password'] ?? '');
        if (!password_verify($pass, (string)$user['password_hash'])) {
            audit('Matikan 2FA Ditolak', 'Auth', (int)$user['id'], null, null, 'Kata sandi salah');
            flash('Kata sandi tidak sesuai — verifikasi 2 langkah TIDAK dimatikan.', 'error');
            return;
        }
        q('UPDATE users SET totp_enabled = 0, totp_confirmed_at = NULL WHERE id = ?', [(int)$user['id']]);
        audit('Matikan 2FA', 'Auth', (int)$user['id'], null, null, 'Verifikasi 2 langkah dimatikan');
        flash('Verifikasi 2 langkah dimatikan untuk akun ini.', 'warning');
        return;
    }
    if ($act === 'twofa_recovery') {
        $pass = (string)($_POST['password'] ?? '');
        if (!password_verify($pass, (string)$user['password_hash'])) {
            flash('Kata sandi tidak sesuai — kode pemulihan tidak dibuat.', 'error');
            return;
        }
        $kode = recovery_codes_generate((int)$user['id'], 8);
        $_SESSION['twofa_recovery_show'] = $kode;      // ditampilkan SEKALI
        flash('8 kode pemulihan baru dibuat. Salin sekarang — kode hanya ditampilkan sekali.');
        return;
    }
    if ($act === 'twofa_email_test') {
        $err = '';
        $ok = twofa_email_code_send($user, $err);
        flash($ok ? 'Kode verifikasi cadangan dikirim ke email terdaftar.' : ('Gagal mengirim kode: ' . $err),
            $ok ? 'success' : 'error');
        return;
    }
}

/** Kartu penyiapan 2FA (dipakai Developer Settings & Profil Saya). */
function twofa_render_card(array $user, string $formAction, string $id = 'keamanan2fa'): void
{
    $aktif = user_2fa_active($user);
    $secret = (string)($user['totp_secret'] ?? '');
    $sisaKode = recovery_codes_remaining((int)$user['id']);
    $tampilKode = $_SESSION['twofa_recovery_show'] ?? null;
    unset($_SESSION['twofa_recovery_show']);
    $scope = login_security()['scope'];
    $wajib = twofa_required_for((string)($user['role_code'] ?? ''));
    ?>
<div class="card" id="<?= e($id) ?>">
  <div class="card-head">
    <h3>Verifikasi 2 Langkah (Google Authenticator)</h3>
    <span>
      <?= $aktif ? badge('AKTIF untuk akun ini', 'green') : badge('Belum aktif', 'gray') ?>
      <?php if ($wajib && !$aktif): ?><?= badge('Diwajibkan untuk level Anda', 'yellow') ?><?php endif; ?>
    </span>
  </div>
  <div class="card-body">
    <p class="muted">Setelah aktif, setiap kali masuk Anda akan diminta <strong>6 angka dari aplikasi
      Google Authenticator</strong> (di HP) sesudah email &amp; kata sandi benar. Bila HP hilang, gunakan
      <strong>kode pemulihan</strong>. Level yang diwajibkan saat ini:
      <strong><?= e(twofa_level_options()[$scope] ?? $scope) ?></strong> — dapat diubah Super Admin.</p>

    <?php if ($aktif): ?>
      <div class="alert alert-info">
        <strong>2FA aktif sejak <?= e(tgl((string)$user['totp_confirmed_at'], true)) ?>.</strong>
        Kode pemulihan tersisa: <strong><?= num($sisaKode) ?></strong> dari 8.
        <?php if ($sisaKode <= 2): ?> <span class="badge badge-yellow">Segera buat ulang</span><?php endif; ?>
      </div>
      <div class="flex gap-sm flex-wrap">
        <form method="post" action="<?= e($formAction) ?>" class="flex gap-sm flex-wrap"
              data-confirm="Matikan verifikasi 2 langkah untuk akun ini?">
          <?= csrf_field() ?><input type="hidden" name="action" value="twofa_disable">
          <div class="field" style="min-width:200px"><label>Kata sandi Anda</label>
            <input class="input input-sm" type="password" name="password" required></div>
          <button class="btn btn-danger btn-sm" type="submit" style="align-self:flex-end">Matikan 2FA</button>
        </form>
        <form method="post" action="<?= e($formAction) ?>" class="flex gap-sm flex-wrap"
              data-confirm="Buat 8 kode pemulihan BARU? Kode lama tidak berlaku lagi.">
          <?= csrf_field() ?><input type="hidden" name="action" value="twofa_recovery">
          <div class="field" style="min-width:200px"><label>Kata sandi Anda</label>
            <input class="input input-sm" type="password" name="password" required></div>
          <button class="btn btn-sm" type="submit" style="align-self:flex-end">Buat Kode Pemulihan Baru</button>
        </form>
        <?php if (mail_configured()): ?>
          <form method="post" action="<?= e($formAction) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="twofa_email_test">
            <button class="btn btn-sm" type="submit">Kirim Kode Uji ke Email</button>
          </form>
        <?php endif; ?>
      </div>

    <?php elseif ($secret === ''): ?>
      <form method="post" action="<?= e($formAction) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="twofa_start">
        <button class="btn btn-primary" type="submit"><?= icon('settings') ?> Mulai Penyiapan (tampilkan QR)</button>
      </form>

    <?php else: ?>
      <?php
      $uri = totp_uri($secret, (string)$user['email'], clinic_name());
      $svg = qr_svg($uri, 4, 'M');
      $kelompok = trim(chunk_split($secret, 4, ' '));
      ?>
      <div class="flex gap-lg flex-wrap mt-2" style="align-items:flex-start">
        <div style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px">
          <?= $svg ?>
          <div class="small muted center mt-1">Pindai dengan Google Authenticator</div>
        </div>
        <div style="min-width:280px;flex:1">
          <div class="section-title" style="margin-top:0">1. Pindai QR</div>
          <p class="small muted">Buka <strong>Google Authenticator</strong> → tombol <strong>+</strong> →
            <em>Pindai kode QR</em>. Bila kamera tidak bisa, pilih <em>Masukkan kunci penyiapan</em> lalu
            tulis kunci di bawah.</p>
          <div class="field"><label>Kunci penyiapan (bila tidak bisa memindai)</label>
            <input class="input" value="<?= e($kelompok) ?>" readonly onclick="this.select()">
            <span class="hint">Klik kolom di atas untuk memilih seluruh kunci, lalu salin.</span></div>
          <div class="section-title">2. Masukkan kode dari aplikasi</div>
          <form method="post" action="<?= e($formAction) ?>" class="flex gap-sm flex-wrap" style="align-items:flex-end">
            <?= csrf_field() ?><input type="hidden" name="action" value="twofa_confirm">
            <div class="field"><label>6 angka dari aplikasi</label>
              <input class="input" name="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                     placeholder="123456" required autocomplete="one-time-code"></div>
            <button class="btn btn-primary" type="submit">Aktifkan 2FA</button>
          </form>
          <div class="notice small mt-2">Kode baru disebut <strong>aktif</strong> setelah verifikasi ini
            berhasil. Selama belum diverifikasi, login Anda TIDAK berubah.</div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (is_array($tampilKode) && $tampilKode): ?>
      <div class="alert alert-warning mt-3">
        <strong>Kode pemulihan Anda (ditampilkan SEKALI — simpan sekarang):</strong>
        <div class="grid g4 mt-1" style="gap:8px">
          <?php foreach ($tampilKode as $k): ?>
            <code style="display:block;padding:7px 9px;background:#fff;border:1px solid var(--line);border-radius:8px;text-align:center"><?= e($k) ?></code>
          <?php endforeach; ?>
        </div>
        <div class="small mt-1">Setiap kode hanya dapat dipakai <strong>satu kali</strong>. Cetak atau simpan
          di tempat aman (bukan di dalam aplikasi ini).</div>
      </div>
    <?php endif; ?>
  </div>
</div>
    <?php
}
