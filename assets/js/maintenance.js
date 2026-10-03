/* Naveena Skincare — penjaga tampilan saat MODE PEMELIHARAAN aktif.
 *
 * Server sudah menolak setiap aksi tulis (lihat includes/maintenance.php).
 * Skrip ini hanya membuat keadaannya jelas bagi pengguna level kasir/admin:
 *   - tombol simpan pada form (POST) dimatikan + diberi keterangan,
 *   - menu yang butuh kelola data (Order Baru, Import) ditandai nonaktif,
 *   - tautan ekspor/unduh data tidak dijalankan, melainkan memunculkan pesan.
 */
(function () {
  'use strict';
  var info = window.NAVEENA_MAINTENANCE || {};
  var TITLE = info.title || 'Mode pemeliharaan aktif';

  /* ---- 1. Tombol simpan pada form POST dimatikan ---------------- */
  function lockForms() {
    document.querySelectorAll('form').forEach(function (f) {
      var method = (f.getAttribute('method') || 'get').toLowerCase();
      if (method !== 'post') return;                       // form filter (GET) tetap jalan
      if (f.hasAttribute('data-maintenance-allow')) return; // pengecualian (mis. login)
      f.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) {
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
        if (!b.title) b.title = TITLE + ' — aksi ini sementara dinonaktifkan';
      });
    });
  }

  /* ---- 2. Tautan ekspor/impor/backup tidak dijalankan ----------- */
  var BLOCKED = /(^|\/)(export\.php|import\.php|backup\.php|purge\.php)(\?|$)/;
  function blockLinks() {
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest && ev.target.closest('a[href]');
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (!BLOCKED.test(href)) return;
      ev.preventDefault();
      ev.stopPropagation();
      notice('Menu ini dinonaktifkan sementara selama pemeliharaan.');
    }, true);
    /* Menu sidebar yang khusus mengubah data: beri tanda & cegah klik */
    var NAV_BLOCKED = /(^|\/)(order_baru\.php|import\.php|purge\.php|backup\.php|users\.php|branches\.php|staff\.php)(\?|$)/;
    document.querySelectorAll('.nav-link[href]').forEach(function (a) {
      if (!NAV_BLOCKED.test(a.getAttribute('href') || '')) return;
      a.classList.add('nav-link-locked');
      a.setAttribute('title', TITLE + ' — menu ini sementara dinonaktifkan (hanya lihat data)');
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        notice('Menu "' + a.textContent.trim() + '" dinonaktifkan sementara selama pemeliharaan.');
      });
    });
  }

  /* ---- 3. Form POST yang tetap dikirim (mis. lewat tombol khusus) */
  function guardSubmit() {
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (!f || (f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
      if (f.hasAttribute('data-maintenance-allow')) return;
      ev.preventDefault();
      notice('Aksi tidak dijalankan: ' + TITLE + '. Data tidak berubah.');
    }, true);
  }

  /* ---- 4. Pesan singkat di atas layar --------------------------- */
  var box = null;
  function notice(msg) {
    if (!box) {
      box = document.createElement('div');
      box.className = 'maint-toast';
      box.setAttribute('role', 'status');
      document.body.appendChild(box);
    }
    box.textContent = msg;
    box.classList.add('on');
    clearTimeout(notice._t);
    notice._t = setTimeout(function () { box.classList.remove('on'); }, 4200);
  }

  function run() { lockForms(); blockLinks(); guardSubmit(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  window.NAVEENA_MAINTENANCE_NOTICE = notice;
})();
