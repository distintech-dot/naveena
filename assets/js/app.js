/* Naveena Skincare — UI helpers */
(function () {
  'use strict';

  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));

  window.Naveena = {
    rupiah(n) {
      n = Number(n || 0);
      return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    /* Rupiah RINGKAS untuk label sumbu grafik supaya tidak memakan lebar
       (mis. "Rp 1,2 jt" / "Rp 350 rb") — di kartu grafik yang sempit, label
       panjang seperti "Rp 12.000.000" menghabiskan hampir seluruh lebararea
       sehingga batang grafiknya nyaris tak terlihat. Nilai penuh tetap
       ditampilkan pada tooltip. */
    rupiahShort(n) {
      n = Number(n || 0);
      var abs = Math.abs(n);
      var f = function (v, suf) {
        var s = v.toLocaleString('id-ID', { maximumFractionDigits: v < 10 ? 1 : 0 });
        return 'Rp ' + s + ' ' + suf;
      };
      if (abs >= 1e12) return f(n / 1e12, 'T');
      if (abs >= 1e9) return f(n / 1e9, 'M');
      if (abs >= 1e6) return f(n / 1e6, 'jt');
      if (abs >= 1e3) return f(n / 1e3, 'rb');
      return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    angka(n) {
      return Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });
    },
    openModal(id) { const m = document.getElementById(id); if (m) m.classList.add('open'); },
    closeModal(id) {
      const m = id ? document.getElementById(id) : null;
      if (m) m.classList.remove('open');
      else $$('.modal.open').forEach((x) => x.classList.remove('open'));
    },
    loading(on) {
      let el = $('#globalLoading');
      if (!el) {
        el = document.createElement('div');
        el.id = 'globalLoading';
        el.className = 'overlay-load';
        el.innerHTML = '<div class="spinner"></div>';
        document.body.appendChild(el);
      }
      el.classList.toggle('on', !!on);
    },
    async get(url, params) {
      const q = new URLSearchParams(params || {});
      const res = await fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + q.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    },
    /* Generic autocomplete against api.php */
    suggest(opts) {
      const input = typeof opts.input === 'string' ? $(opts.input) : opts.input;
      const box = typeof opts.box === 'string' ? $(opts.box) : opts.box;
      if (!input || !box) return;
      let timer = null, items = [], hl = -1;

      const close = () => { box.classList.remove('open'); hl = -1; };
      const render = () => {
        if (!items.length) {
          box.innerHTML = '<div class="item"><small>Tidak ada hasil.</small></div>';
        } else {
          box.innerHTML = items.map((it, i) =>
            '<div class="item' + (i === hl ? ' hl' : '') + '" data-i="' + i + '">' + it.label + '</div>').join('');
        }
        box.classList.add('open');
      };
      const choose = (i) => { const it = items[i]; if (!it) return; close(); opts.onPick(it); };

      input.addEventListener('input', function () {
        const term = input.value.trim();
        clearTimeout(timer);
        if (term.length < (opts.min || 1)) { close(); return; }
        timer = setTimeout(async () => {
          try {
            const data = await window.Naveena.get('api.php', Object.assign({ a: opts.action, q: term }, opts.params || {}));
            items = data.items || [];
            hl = items.length ? 0 : -1;
            render();
          } catch (e) { close(); }
        }, 180);
      });
      input.addEventListener('keydown', function (ev) {
        if (!box.classList.contains('open')) return;
        if (ev.key === 'ArrowDown') { hl = Math.min(hl + 1, items.length - 1); render(); ev.preventDefault(); }
        else if (ev.key === 'ArrowUp') { hl = Math.max(hl - 1, 0); render(); ev.preventDefault(); }
        else if (ev.key === 'Enter') { if (hl >= 0) { choose(hl); ev.preventDefault(); } }
        else if (ev.key === 'Escape') { close(); }
      });
      box.addEventListener('mousedown', function (ev) {
        const it = ev.target.closest('.item');
        if (it && it.dataset.i !== undefined) { choose(Number(it.dataset.i)); ev.preventDefault(); }
      });
      document.addEventListener('click', function (ev) {
        if (!box.contains(ev.target) && ev.target !== input) close();
      });
    },
    chart(id, config) {
      const c = document.getElementById(id);
      if (!c || typeof Chart === 'undefined') return null;
      return new Chart(c.getContext('2d'), config);
    },
    /* Simpan satu grafik sebagai PNG (latar putih agar siap dicetak/dibagikan). */
    chartPng(id, filename) {
      const src = document.getElementById(id);
      if (!src || !src.width) return false;
      const tmp = document.createElement('canvas');
      tmp.width = src.width;
      tmp.height = src.height;
      const ctx = tmp.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, tmp.width, tmp.height);
      ctx.drawImage(src, 0, 0);
      const a = document.createElement('a');
      a.href = tmp.toDataURL('image/png');
      a.download = (filename || id) + '.png';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      return true;
    },
    /* Simpan seluruh grafik pada halaman sebagai PNG (berurutan agar tidak
     * diblokir sebagai "banyak unduhan sekaligus" oleh browser). */
    chartsPng(prefix) {
      const all = Array.prototype.slice.call(document.querySelectorAll('canvas[data-chart]'))
        .filter((c) => c.width > 0);
      let targets = all;
      if (prefix) {
        const key = String(prefix).toLowerCase();
        const matched = all.filter((c) => c.id.toLowerCase().indexOf(key) >= 0
          || String(c.dataset.chart || '').toLowerCase().indexOf(key) >= 0);
        if (matched.length) targets = matched;   // tanpa kecocokan -> unduh semua
      }
      targets.forEach((c, i) => {
        const name = (c.dataset.chart || c.id)
          .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        setTimeout(() => window.Naveena.chartPng(c.id, name), i * 400);
      });
      return targets.length;
    },
    /**
     * Tabel tetap berbentuk tabel di semua ukuran layar — hanya digeser ke
     * samping. Fungsi ini menandai wadah tabel yang isinya lebih lebar dari
     * wadahnya dan menampilkan petunjuk "geser ke samping" pada layar kecil,
     * supaya pengguna HP tahu masih ada kolom lain di sebelah kanan.
     */
    tableScroll() {
      const wraps = Array.prototype.slice.call(document.querySelectorAll('.table-wrap'));
      wraps.forEach((wrap) => {
        const over = wrap.scrollWidth > wrap.clientWidth + 2;
        wrap.classList.toggle('is-scrollable', over);
        let hint = wrap.nextElementSibling;
        const hasHint = hint && hint.classList && hint.classList.contains('table-hint');
        if (!over) { if (hasHint) hint.remove(); return; }
        if (hasHint) return;
        hint = document.createElement('p');
        hint.className = 'table-hint';
        hint.innerHTML = '<span class="arw">&#8596;</span> Geser tabel ke samping untuk melihat kolom lainnya.';
        wrap.insertAdjacentElement('afterend', hint);
      });
      return wraps.length;
    },
    /**
     * ==================================================================
     * GESER TABEL DENGAN TAHAN-KLIK (drag) — ronde 40
     *
     * Keluhan pemilik: pada kolom tabel yang panjang ke kanan, untuk menggeser
     * harus menggulir halaman ke bawah dulu mencari batang geser (scrollbar)
     * karena batangnya berada di DASAR wadah tabel. Sekarang tabel dapat
     * digeser dengan MENAHAN KLIK pada isi tabel lalu menggerakkan tetikus
     * (kiri/kanan) — sama seperti menyeret peta.
     *
     * Dipasang untuk SEMUA wadah tabel (`.table-wrap`), termasuk tabel yang
     * dibangun JavaScript dan wadah ber-batas-tinggi (scroll vertikal).
     * Beberapa hal yang dijaga:
     *   • klik pada tombol/tautan/kolom isian/checkbox TIDAK dianggap seret,
     *     sehingga aksi (Simpan, buka detail, centang) tetap berjalan normal;
     *   • klik singkat tanpa gerakan tetap diteruskan (tidak diblokir);
     *   • saat menyeret, teks dalam tabel tidak ikut terseleksi (ada kelas
     *     `is-dragging` yang mematikan seleksi + memakai kursor "grabbing").
     * ==================================================================
     */
    tableDragScroll() {
      const wraps = Array.prototype.slice.call(document.querySelectorAll('.table-wrap'));
      let aktif = 0;
      wraps.forEach((wrap) => {
        if (wrap.dataset.dragReady === '1') return;   // jangan pasang dua kali
        wrap.dataset.dragReady = '1';
        aktif++;

        let mulaiX = 0, mulaiY = 0, awalLeft = 0, awalTop = 0;
        let menyeret = false, siapSeret = false, gerak = 0;

        /* Hanya tombol kiri. Elemen interaktif dilewati supaya klik normal tetap
           bekerja (tombol Simpan/Hapus, tautan, kolom isian, checkbox, select). */
        const interaktif = (el) => !!(el && el.closest
          && el.closest('a, button, input, select, textarea, label, [data-no-drag], .suggest, .status-opt'));

        const berhenti = () => {
          if (!siapSeret) return;
          siapSeret = false;
          if (menyeret) {
            menyeret = false;
            wrap.classList.remove('is-dragging');
            document.body.classList.remove('table-dragging');
          }
        };

        wrap.addEventListener('mousedown', (e) => {
          if (e.button !== 0 || interaktif(e.target)) return;
          if (wrap.scrollWidth <= wrap.clientWidth + 2) return;   // tidak perlu digeser
          siapSeret = true;
          menyeret = false;
          gerak = 0;
          mulaiX = e.clientX;
          mulaiY = e.clientY;
          awalLeft = wrap.scrollLeft;
          awalTop = wrap.scrollTop;
        });

        /* Pendengar dipasang pada `document` supaya seretan tetap terasa walau
           kursor keluar dari wadah tabel (mis. melewati tepi kartu). */
        document.addEventListener('mousemove', (e) => {
          if (!siapSeret) return;
          const dx = e.clientX - mulaiX;
          const dy = e.clientY - mulaiY;
          gerak = Math.max(gerak, Math.abs(dx) + Math.abs(dy));
          if (!menyeret) {
            if (gerak < 6) return;                 // belum cukup jauh: biarkan klik biasa
            menyeret = true;
            wrap.classList.add('is-dragging');
            document.body.classList.add('table-dragging');
          }
          wrap.scrollLeft = awalLeft - dx;
          /* Geser tegak hanya bila wadahnya memang punya guliran tegak
             (mis. tabel "Perhitungan" yang dibatasi tingginya). */
          if (wrap.scrollHeight > wrap.clientHeight + 2) wrap.scrollTop = awalTop - dy;
          e.preventDefault();
        });

        document.addEventListener('mouseup', (e) => {
          if (menyeret) {
            /* Cegah "klik" yang tidak disengaja ikut terpicu setelah seretan
               (mis. mengenai tautan di dalam sel). */
            const blokir = (ev) => { ev.preventDefault(); ev.stopPropagation(); };
            document.addEventListener('click', blokir, { capture: true, once: true });
            setTimeout(() => document.removeEventListener('click', blokir, true), 0);
          }
          berhenti();
        });

        /* Kursor keluar jendela / tab berpindah: hentikan seretan. */
        document.addEventListener('mouseleave', berhenti);
        window.addEventListener('blur', berhenti);
      });
      return aktif;
    },
    /**
     * Penjaga "perubahan belum disimpan" untuk sebuah form:
     *  - menandai form sebagai berubah (kotor) saat ada isian yang diubah,
     *  - menampilkan penanda di halaman selama belum disimpan,
     *  - menanyakan konfirmasi saat akan menyimpan,
     *  - memperingatkan sebelum meninggalkan halaman (klik menu/tautan,
     *    tombol Back, tutup tab, atau reload) bila perubahan belum disimpan.
     * Data tetap TIDAK tersimpan sampai tombol simpan benar-benar ditekan.
     */
    dirtyGuard(form, opts) {
      const f = typeof form === 'string' ? $(form) : form;
      if (!f) return null;
      const o = opts || {};
      /* 'note' & 'mark' boleh berupa selector CSS atau elemen langsung. */
      const pick = (v) => (!v ? [] : (typeof v === 'string'
        ? Array.prototype.slice.call(document.querySelectorAll(v)) : [v]));
      const nodes = pick;
      const notes = nodes(o.note);
      const marks = nodes(o.mark);
      const leaveMsg = () => o.leaveMessage || 'Perubahan belum disimpan. Yakin meninggalkan halaman ini?';
      let dirty = false;
      let suppress = false;      // jangan tanya lagi (sedang menyimpan / sudah setuju keluar)

      f.dataset.dirtyGuard = '1';
      const show = () => {
        notes.forEach((el) => el.classList.toggle('on', dirty));
        marks.forEach((el) => el.classList.toggle('dirty', dirty));
        f.classList.toggle('is-dirty', dirty);
      };
      const setDirty = (v) => { if (dirty !== v) { dirty = v; show(); } };
      const isDirty = () => dirty;

      /* Satu isian berubah = form kotor. Mengetik lalu mengembalikan isi ke
         nilai asal tetap dianggap kotor (lebih aman daripada diam-diam sama). */
      f.addEventListener('input', () => setDirty(true), true);
      f.addEventListener('change', () => setDirty(true), true);
      f.addEventListener('reset', () => setDirty(false));

      /* Konfirmasi sebelum menyimpan. Bila dibatalkan, form tidak dikirim
         sehingga data di sistem tetap seperti semula. */
      if (o.confirmSave !== false) {
        f.addEventListener('submit', (ev) => {
          if (!dirty) return;                     // tidak ada perubahan
          const msg = typeof o.confirmSave === 'string' ? o.confirmSave
            : 'Simpan perubahan pada data ini?';
          if (!window.confirm(msg)) { ev.preventDefault(); return; }
          suppress = true;
          setDirty(false);
        }, true);
      }

      /* Peringatan sebelum meninggalkan halaman (klik menu/tautan, tombol Back,
         tutup tab, atau muat ulang). */
      window.addEventListener('beforeunload', (ev) => {
        if (!dirty || suppress) return;
        ev.preventDefault();
        ev.returnValue = leaveMsg();
        return ev.returnValue;
      });
      document.addEventListener('click', (ev) => {
        if (!dirty || suppress) return;
        const a = ev.target.closest('a[href]');
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || a.target === '_blank'
          || href.indexOf('javascript:') === 0) return;
        if (!window.confirm(leaveMsg())) { ev.preventDefault(); return; }
        /* Pengguna setuju keluar: jangan tanya lagi lewat beforeunload. */
        suppress = true;
        setTimeout(() => { suppress = false; }, 1500);
      }, true);

      const api = {
        isDirty, setDirty, mark: () => setDirty(true),
        /* dipakai sebelum submit programatik agar tidak ditanya dua kali */
        submit() { suppress = true; setDirty(false); f.submit(); },
        reset() { suppress = true; setDirty(false); }
      };
      f._dirtyGuard = api;
      return api;
    },
    /* Palet grafik: 48 warna TERKURASI yang urutannya sudah disusun agar selalu
       berbeda jauh (dipakai SERAGAM di semua grafik: layar, Excel, PDF/email).
       `paletteFor(n)` mengambil tepat n warna pertama sehingga tidak ada dua
       seri yang berwarna sama — dulu daftarnya hanya 10 dan dipakai dengan
       `% length`, sehingga seri ke-11 mengulang warna seri ke-1. */
    palette: (window.NAVEENA_THEME && window.NAVEENA_THEME.palette)
      || ['#C2185B', '#26C5C5', '#C5C526', '#165A16', '#2626C5', '#26C526'],
    paletteFor(n) {
      const p = (window.NAVEENA_THEME && window.NAVEENA_THEME.palette) || this.palette;
      const k = Math.max(1, Math.min(Number(n) || 1, p.length));
      return p.slice(0, k);
    },
    /* Warna seri yang punya makna (diatur di Pengaturan → Warna Grafik). */
    series(key) {
      const s = (window.NAVEENA_THEME && window.NAVEENA_THEME.series) || {};
      return s[key] || { treatment: '#C2185B', skincare: '#2E7D32', total: '#37474F',
        positif: '#2E7D32', negatif: '#C62828' }[key] || '#C2185B';
    },
    /* "#RRGGBB" + tingkat transparansi → "rgba(...)", untuk pengisi area grafik. */
    rgba(hex, alpha) {
      const h = String(hex || '#000000').replace('#', '');
      const v = h.length === 3 ? h[0] + h[0] + h[1] + h[1] + h[2] + h[2] : h;
      const n = parseInt(v, 16);
      return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + (alpha == null ? 1 : alpha) + ')';
    }
  };

  /* ==================================================================
   * PENJAGA KIRIM SEKALI — mencegah DATA GANDA.
   *
   * Permintaan pemilik: di jaringan lambat, tombol Simpan terasa "tidak jalan"
   * lalu diklik berkali-kali sehingga data tersimpan dua kali (mis. reservasi
   * ganda). Penjaga ini berlaku untuk SEMUA form (tambah/ubah/hapus) sekaligus:
   *   • klik/Enter kedua pada form yang SEDANG dikirim diabaikan;
   *   • tombol kirim tidak bisa diklik lagi selama pengiriman berjalan (ditandai
   *     kelas CSS, BUKAN atribut disabled — supaya nama/nilai tombol yang diklik
   *     tetap ikut terkirim ke server);
   *   • bila pengiriman DIBATALKAN oleh skrip lain (validasi atau konfirmasi
   *     "yakin hapus?"), penanda dibuka kembali agar pengguna bisa mengirim
   *     setelah memperbaiki isian;
   *   • bila halaman tidak berpindah (mis. validasi server menampilkan pesan),
   *     penanda dibuka kembali setelah 12 detik.
   * Sisi server juga dijaga sekali-pakai lewat token `_once` (config.php) supaya
   * duplikat tetap tidak terjadi walau JavaScript mati.
   * ================================================================== */
  (function () {
    var RELEASE_MS = 12000;
    function release(form) {
      if (!form) return;
      delete form.dataset.submitting;
      form.classList.remove('is-submitting');
    }
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (!f || f.tagName !== 'FORM') return;
      if (f.dataset.noSubmitGuard === '1') return;
      if (f.dataset.submitting === '1') {
        /* Permintaan yang sama sedang berjalan → jangan kirim dua kali. */
        ev.preventDefault();
        ev.stopImmediatePropagation();
        return;
      }
      f.dataset.submitting = '1';
      f.classList.add('is-submitting');
      /* Bila ada skrip lain yang membatalkan pengiriman ini (validasi/konfirmasi),
         buka lagi supaya tidak mengunci pengguna. */
      setTimeout(function () { if (ev.defaultPrevented) release(f); }, 0);
      setTimeout(function () { release(f); }, RELEASE_MS);
    }, true);
    /* Formulir yang direset (mis. modal ditutup) tidak boleh tetap terkunci. */
    document.addEventListener('reset', function (ev) {
      if (ev.target && ev.target.tagName === 'FORM') release(ev.target);
    }, true);
  })();

  /* ============================================================================
   PAGINASI TANPA MEMUAT ULANG HALAMAN (permintaan pemilik)
   ============================================================================
   Nomor halaman (1, 2, 3, …) pada `.pagination` dimuat lewat fetch lalu HANYA
   bagian daftar (tabel + kontrol halaman) yang diganti — halaman tidak berkedip
   dan posisi gulir tetap. Setiap kartu berhalaman punya kunci sendiri
   (`data-pg`, mis. `page_trx`) sehingga mengklik halaman pada satu kartu TIDAK
   mengubah kartu lain.

   Bila fetch gagal (mis. jaringan terputus), halaman tetap dibuka seperti biasa
   sehingga navigasi tidak pernah "mati".
   ========================================================================== */
  /**
   * Wadah tabel MILIK sebuah kontrol halaman.
   *
   * PENTING (perbaikan bug nyata): dulu hanya `previousElementSibling` yang
   * diperiksa, padahal `Naveena.tableScroll()` menyisipkan
   * `<p class="table-hint">` TEPAT SETELAH wadah tabel. Akibatnya, begitu tabel
   * cukup lebar untuk digeser (di layar kecil SELALU, di desktop tergantung
   * panjang kolom), wadah tabel tidak lagi dikenali → nomor halaman berganti
   * tetapi ISINYA TIDAK — pemilik harus menyegarkan halaman dulu. Sekarang wadah
   * dicari dengan menelusuri elemen-elemen sebelumnya.
   */
  function wrapOfPagination(pag) {
    let el = pag ? pag.previousElementSibling : null;
    let n = 0;
    while (el && n++ < 8) {
      if (el.classList && el.classList.contains('table-wrap')) return el;
      el = el.previousElementSibling;
    }
    return null;
  }

  document.addEventListener('click', async function (ev) {
    const a = ev.target.closest && ev.target.closest('.pagination a.pg');
    if (!a) return;
    const href = a.getAttribute('href');
    const pag = a.closest('.pagination');
    if (!href || !pag) return;
    ev.preventDefault();

    /* Pencocokan daftar memakai KUNCI kartu (`data-pg`, mis. `page_trx`) bila ada,
       baru jatuh ke urutan. */
    const kunci = pag.getAttribute('data-pg') || '';
    const semuaPag = Array.prototype.slice.call(document.querySelectorAll('.pagination'));
    const idx = semuaPag.indexOf(pag);
    const wrap = wrapOfPagination(pag);

    pag.classList.add('is-loading');
    try {
      const res = await fetch(href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      /* Cari kontrol halaman sepadan: utamakan KUNCI kartu; bila kuncinya sama
         dipakai lebih dari satu kartu (mis. dua daftar sama-sama `page`), jatuh ke
         urutan supaya tidak salah mengambil kontrol milik kartu lain. */
      let pagBaru = null;
      if (kunci !== '') {
        const kandidat = doc.querySelectorAll('.pagination[data-pg="' + kunci.replace(/"/g, '\\"') + '"]');
        if (kandidat.length === 1) pagBaru = kandidat[0];
      }
      if (!pagBaru) pagBaru = doc.querySelectorAll('.pagination')[idx >= 0 ? idx : 0];
      if (!pagBaru) throw new Error('daftar halaman tidak ditemukan');
      const wrapBaru = wrapOfPagination(pagBaru);
      /* Keduanya wajib ada: kalau tabelnya tidak ditemukan, lebih baik membuka
         halamannya seperti biasa daripada menampilkan nomor yang tidak sesuai isi. */
      if (!wrap || !wrapBaru) throw new Error('wadah tabel tidak ditemukan');
      wrap.innerHTML = wrapBaru.innerHTML;
      pag.innerHTML = pagBaru.innerHTML;
      pag.classList.remove('is-loading');
      /* Alamat di bilah peramban ikut berubah supaya tautan dapat dibagikan /
         disegarkan tanpa kehilangan halaman yang sedang dibuka. */
      if (window.history && window.history.replaceState) window.history.replaceState(null, '', href);
      /* Fitur tabel (petunjuk geser & geser-tahan-klik) dipasang ulang untuk isi baru. */
      window.Naveena.tableScroll();
      window.Naveena.tableDragScroll();
      if (wrap) {
        const atas = wrap.getBoundingClientRect().top + window.pageYOffset - 90;
        window.scrollTo({ top: Math.max(0, atas), behavior: 'smooth' });
      }
    } catch (e) {
      /* Gagal memuat sebagian → buka halamannya seperti biasa (jangan diam). */
      pag.classList.remove('is-loading');
      window.location.href = href;
    }
  });

  /* ============================================================================
   BATAS UKURAN BERKAS YANG DIPILIH (sisi peramban) — permintaan pemilik
   ============================================================================
   Kolom berkas yang punya `data-max-kb` (foto pasien/dokter/terapis = 300 KB,
   foto rekam medis = 500 KB) DIPERIKSA saat berkas dipilih. Bila ada berkas yang
   terlalu besar: pilihannya dikosongkan (tidak diunggah) dan muncul pesan jelas
   di bawah kolomnya. Aturan angkanya berasal dari PHP (`img_source_max_kb()`),
   jadi tidak ada angka batas yang ditulis dua kali.
   `data-image-only-kb="1"` dipakai kolom yang juga menerima dokumen (rekam medis):
   batas hanya berlaku untuk berkas GAMBAR, dokumen mengikuti batas lain.
   ========================================================================== */
  (function () {
    const pesan = (input, teks) => {
      const field = input.closest('.field') || input.parentElement;
      if (!field) return;
      let el = field.querySelector('.file-error');
      if (!el) {
        el = document.createElement('div');
        el.className = 'file-error';
        el.setAttribute('role', 'alert');
        field.appendChild(el);
      }
      el.textContent = teks;
    };
    const bersihkan = (input) => {
      const field = input.closest('.field') || input.parentElement;
      if (!field) return;
      const el = field.querySelector('.file-error');
      if (el) el.remove();
    };
    document.querySelectorAll('input[type=file][data-max-kb]').forEach((input) => {
      input.addEventListener('change', function () {
        bersihkan(input);
        const maxKb = parseInt(input.getAttribute('data-max-kb'), 10);
        if (!maxKb || !input.files || !input.files.length) return;
        const hanyaGambar = input.getAttribute('data-image-only-kb') === '1';
        const besar = Array.from(input.files).filter((f) => {
          if (hanyaGambar && !/^image\//.test(f.type || '')) return false;
          return f.size > maxKb * 1024;
        });
        if (!besar.length) return;
        const daftar = besar.map((f) => f.name + ' (' + Math.round(f.size / 1024) + ' KB)').join(', ');
        pesan(input, 'Ukuran foto melebihi ' + maxKb + ' KB: ' + daftar
          + ' — berkas tidak diunggah. Perkecil/kompres dulu lalu pilih kembali.');
        try { input.value = ''; } catch (e) { /* sebagian peramban menolak */ }
        if (input.files && input.files.length) {
          /* Peramban yang tidak mengizinkan pengosongan: jangan lanjutkan. */
          input.setAttribute('data-too-big', '1');
        }
      });
      /* Cegah formulir terkirim selagi berkasnya masih terlalu besar. */
      const form = input.form;
      if (form) {
        form.addEventListener('submit', function (ev) {
          if (input.getAttribute('data-too-big') === '1') {
            ev.preventDefault();
            pesan(input, 'Pilih foto lain yang lebih kecil dulu (maksimal '
              + input.getAttribute('data-max-kb') + ' KB) sebelum menyimpan.');
          }
        });
      }
      input.addEventListener('input', () => input.removeAttribute('data-too-big'));
    });
  })();

  /* ============================================================================
   FOTO DIPERBESAR (lightbox) — permintaan pemilik
   ============================================================================
   Foto pasien / dokter / terapis / lampiran klinis dapat DIKLIK untuk dilihat
   lebih besar. Elemen apa pun yang punya atribut `data-zoom="<url gambar>"` akan
   membuka lapisan gelap berisi gambar ukuran besar (dapat diperbesar lagi dengan
   klik pada gambar — zoom 1×/2×). Tertutup lewat tombol ×, klik latar, atau Esc.
   Berlaku otomatis untuk avatar yang fotonya sudah diunggah (lihat person_avatar()).
   ========================================================================== */
  (function () {
    let box = null;
    const tutup = () => { if (box) { box.remove(); box = null; document.documentElement.style.overflow = ''; } };
    const buka = (url, alt) => {
      if (!url) return;
      tutup();
      box = document.createElement('div');
      box.className = 'zoom-box';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-label', 'Foto diperbesar');
      const img = document.createElement('img');
      img.src = url; img.alt = alt || 'Foto';
      img.className = 'zoom-img';
      const ket = document.createElement('div');
      ket.className = 'zoom-cap';
      ket.textContent = 'Klik gambar untuk memperbesar/mengecilkan · Esc atau klik latar untuk menutup';
      const x = document.createElement('button');
      x.type = 'button'; x.className = 'zoom-close'; x.setAttribute('aria-label', 'Tutup');
      x.innerHTML = '&times;';
      x.addEventListener('click', (e) => { e.stopPropagation(); tutup(); });
      img.addEventListener('click', (e) => { e.stopPropagation(); img.classList.toggle('is-big'); });
      box.addEventListener('click', tutup);
      box.appendChild(img); box.appendChild(ket); box.appendChild(x);
      document.body.appendChild(box);
      document.documentElement.style.overflow = 'hidden';
    };
    document.addEventListener('click', function (ev) {
      const t = ev.target.closest && ev.target.closest('[data-zoom]');
      if (!t) return;
      ev.preventDefault();
      buka(t.getAttribute('data-zoom'), t.getAttribute('alt') || '');
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') tutup(); });
    /* Dipakai juga oleh skrip halaman (mis. tombol "Perbesar" pada kartu foto). */
    window.Naveena = window.Naveena || {};
    window.Naveena.zoom = buka;
  })();

  document.addEventListener('DOMContentLoaded', function () {
    window.Naveena.tableScroll();
    window.Naveena.tableDragScroll();
    /* Tabel yang dibangun JavaScript (mis. baris item pada Order Baru) atau
       lebar layar berubah ikut diperiksa, tanpa perlu memanggil ulang manual. */
    const refresh = () => {
      clearTimeout(refresh.timer);
      refresh.timer = setTimeout(() => {
        window.Naveena.tableScroll();
        /* Tabel baru (dibangun JavaScript) juga mendapat fitur geser-tahan-klik;
           fungsi ini melewati wadah yang sudah dipasangi agar tidak dobel. */
        window.Naveena.tableDragScroll();
      }, 120);
    };
    window.addEventListener('resize', refresh);
    if (window.MutationObserver) new MutationObserver(refresh).observe(document.body, { childList: true, subtree: true });

    // Sidebar (mobile)
    const toggle = $('#menuToggle');
    if (toggle) toggle.addEventListener('click', () => document.body.classList.toggle('nav-open'));
    const ov = $('#sidebarOverlay');
    if (ov) ov.addEventListener('click', () => document.body.classList.remove('nav-open'));

    // Modals
    $$('[data-modal-open]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      window.Naveena.openModal(b.getAttribute('data-modal-open'));
    }));
    $$('[data-modal-close]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      window.Naveena.closeModal(b.getAttribute('data-modal-close') || undefined);
    }));
    /* ==================================================================
     * MODAL TIDAK LAGI MENUTUP SAAT DIKLIK DI LUARNYA (ronde 39).
     *
     * Keluhan pemilik: saat mengisi form (reservasi/pasien/inventory/pengaturan)
     * kartunya sering "keluar sendiri" karena klik tak sengaja di area gelap di
     * luar kartu — padahal isian sudah diisi. Sekarang menutup modal HANYA lewat
     * tombol × (data-modal-close) atau tombol Batal (juga data-modal-close).
     *
     * Modal yang memang ingin bisa ditutup dengan klik luar dapat memakai atribut
     * `data-modal-backdrop-close` (dipakai modal pratinjau/notifikasi sederhana).
     * ================================================================== */
    $$('.modal').forEach((m) => m.addEventListener('click', (e) => {
      if (e.target !== m) return;                       // klik di dalam kartu: abaikan
      if (m.getAttribute('data-modal-backdrop-close') === null) return;   // default: tahan
      m.classList.remove('open');
    }));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.Naveena.closeModal(); });

    /* ---- Konfirmasi BERAT 2 tahap (modal + dialog) ----------------------
     * Dipakai untuk tindakan merusak: hapus permanen pasien/rekam medis/order,
     * purge audit log. Tahap 1: modal + wajib mengetik kata kunci.
     * Tahap 2: dialog konfirmasi sistem. Dua-duanya harus dilalui.          */
    let heavyForm = null;
    const heavyModal = () => document.getElementById('confirmHeavy');
    const closeHeavy = () => {
      const m = heavyModal();
      if (m) m.classList.remove('open');
      heavyForm = null;
    };
    document.addEventListener('click', (ev) => {
      if (ev.target.closest('[data-heavy-cancel]')) { ev.preventDefault(); closeHeavy(); }
    });

    /* Penting: klik tombol submit pada form berat ditangani DI SINI (bukan hanya
     * lewat event 'submit'). Kalau form masih belum valid (mis. kolom alasan
     * kosong), browser tidak memicu 'submit' sama sekali sehingga modal peringatan
     * tidak pernah muncul — tombol tampak "mati". Sekarang: validasi dulu, baru
     * tampilkan peringatan tahap 1. */
    document.addEventListener('click', (ev) => {
      /* Penting: tombol boleh berada di LUAR form-nya (dipakai atribut `form="…"`,
         mis. tombol "Kembali ke Default" yang berdampingan dengan tombol simpan pada
         form lain). Karena itu formnya diambil dari `btn.form` (form terkait), bukan
         dari leluhur DOM — pola `form[data-heavy-confirm] button` tidak menemukannya
         dan peringatan 2 tahap akan terlewat. */
      const btn = ev.target.closest('button[type=submit], input[type=submit]');
      if (!btn) return;
      const f = btn.form || btn.closest('form');
      if (!f || !f.dataset || !f.dataset.heavyConfirm) return;
      if (typeof f.checkValidity === 'function' && !f.checkValidity()) return;  // biarkan validasi bawaan tampil
      ev.preventDefault();
      openHeavy(f);
    });

    /** Tampilkan peringatan tahap 1 untuk form tertentu. */
    function openHeavy(f) {
      const m = heavyModal();
      if (!m) {
        if (!window.confirm(f.dataset.heavyWarning || 'Yakin?')) return;
        if (!window.confirm(f.dataset.heavyConfirm2 || 'Peringatan terakhir: lanjutkan?')) return;
        f.dataset.heavyOk = '1';
        if (f.dataset.loading) window.Naveena.loading(true);
        f.submit();
        return;
      }
      heavyForm = f;
      /* Jenis tindakan menentukan kalimat peringatan: tindakan yang BUKAN penghapusan
         (mis. "Kembali ke Default") tidak boleh diberi peringatan menghapus data —
         peringatan yang salah membuat pemilik ragu memakai tombolnya. */
      const alertBox = document.getElementById('confirmHeavyAlert');
      const judul = m.querySelector('.modal-head h3');
      if (alertBox) {
        if (f.dataset.heavyKind === 'reset') {
          alertBox.className = 'alert alert-info mb-2';
          alertBox.innerHTML = 'Tindakan ini <strong>mengganti teks/pengaturan</strong> ke bawaan — '
            + 'tidak ada data yang dihapus, dan Anda masih dapat mengubahnya lagi kapan saja.';
          if (judul) judul.innerHTML = '⚠ Konfirmasi Perubahan Pengaturan';
        } else {
          alertBox.className = 'alert alert-error mb-2';
          alertBox.innerHTML = 'Tindakan ini <strong>menghapus data secara permanen</strong> dan tidak dapat dibatalkan.';
          if (judul) judul.innerHTML = '⚠ Konfirmasi Tindakan Berbahaya';
        }
      }
      document.getElementById('confirmHeavyText').innerHTML = f.dataset.heavyWarning || 'Tindakan ini tidak dapat dibatalkan.';
      document.getElementById('confirmHeavyWord').textContent = f.dataset.heavyConfirm;
      heavyInput.value = '';
      heavyBtn.disabled = true;
      m.classList.add('open');
      setTimeout(() => heavyInput.focus(), 100);
    }
    const heavyBtn = document.getElementById('confirmHeavyBtn');
    const heavyInput = document.getElementById('confirmHeavyInput');
    if (heavyInput) {
      heavyInput.addEventListener('input', () => {
        const want = (heavyForm && heavyForm.dataset.heavyConfirm) || '';
        heavyBtn.disabled = heavyInput.value.trim().toUpperCase() !== want.toUpperCase();
      });
    }
    if (heavyBtn) {
      heavyBtn.addEventListener('click', () => {
        const f = heavyForm;
        if (!f) return;
        // ---- PERINGATAN KEDUA ----
        const pesan = 'PERINGATAN KEDUA (terakhir).\n\n' + (f.dataset.heavyConfirm2 || 'Data akan dihapus PERMANEN dan tidak dapat dikembalikan. Yakin melanjutkan?');
        if (!window.confirm(pesan)) return;
        closeHeavy();
        f.dataset.heavyOk = '1';
        if (f.dataset.loading) window.Naveena.loading(true);
        f.submit();
      });
    }

    // Confirm actions
    document.addEventListener('submit', function (ev) {
      const f = ev.target;
      if (f.dataset && f.dataset.heavyConfirm) {
        ev.preventDefault();
        if (f.dataset.heavyOk === '1') { f.dataset.heavyOk = ''; if (f.dataset.loading) window.Naveena.loading(true); f.submit(); return; }
        openHeavy(f);
        return;
      }
      if (f.dataset && f.dataset.confirm && !window.confirm(f.dataset.confirm)) { ev.preventDefault(); return; }
      if (f.dataset && f.dataset.loading) window.Naveena.loading(true);
    });
    document.addEventListener('click', function (ev) {
      const a = ev.target.closest('a[data-confirm]');
      if (a && !window.confirm(a.getAttribute('data-confirm'))) ev.preventDefault();
    });

    // Autohide flash
    $$('.alert[data-autohide]').forEach((el) => setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 450); }, 4200));

    // Print buttons
    $$('[data-print]').forEach((b) => b.addEventListener('click', (e) => { e.preventDefault(); window.print(); }));

    /* Push GitHub memakai POST AJAX; kredensial tidak pernah dikirim ke browser. */
    $$('[data-github-push]').forEach((button) => button.addEventListener('click', async () => {
      const form = button.form;
      const status = form && form.querySelector('[data-github-status]');
      if (!form || !status) return;
      const repo = form.querySelector('[name="github_repo"]');
      const token = form.querySelector('[name="github_token"]');
      if (!repo || !repo.value.trim() || !token || (!token.value.trim() && !form.querySelector('[name="github_token"]').placeholder.includes('tersimpan'))) {
        status.className = 'alert alert-warning mt-2';
        status.textContent = 'Simpan repository dan token GitHub terlebih dahulu.';
        status.style.display = '';
        return;
      }
      const csrf = form.querySelector('[name="_csrf"]');
      const once = form.querySelector('[name="_once"]');
      const body = new FormData();
      if (csrf) body.append('_csrf', csrf.value);
      if (once) body.append('_once', once.value);
      /* Kolom yang baru diisi ikut dikirim supaya SATU klik sudah cukup:
         server menyimpan pengaturan ini lebih dulu, baru menjalankan push.
         (Dulu pengguna harus menekan "Simpan" dulu — kalau tidak, push memakai
         setelan lama yang masih kosong dan selalu gagal.) */
      const branch = form.querySelector('[name="github_branch"]');
      body.append('github_repo', repo.value.trim());
      if (branch && branch.value.trim()) body.append('github_branch', branch.value.trim());
      if (token.value.trim()) body.append('github_token', token.value.trim());
      button.disabled = true;
      const label = button.textContent;
      button.textContent = 'Memproses push…';
      status.className = 'alert alert-info mt-2';
      status.textContent = 'Mengirim perubahan ke GitHub…';
      status.style.display = '';
      window.Naveena.loading(true);
      try {
        const res = await fetch(button.dataset.endpoint || 'api.php?a=push_github', {
          method: 'POST', body, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (data._once) {
          const nextOnce = form.querySelector('[name="_once"]');
          if (nextOnce) nextOnce.value = data._once;
        }
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
        status.className = 'alert alert-success mt-2';
        status.textContent = data.message || 'Push GitHub berhasil.';
      } catch (err) {
        status.className = 'alert alert-error mt-2';
        status.textContent = 'Push GitHub gagal: ' + (err.message || 'kesalahan tidak diketahui');
      } finally {
        window.Naveena.loading(false);
        button.disabled = false;
        button.textContent = label;
      }
    }));

    // Auto-submit filter selects
    $$('select[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));

    // Pilihan supplier: tampilkan kolom isi manual bila dipilih
    $$('[data-supplier-select]').forEach((sel) => {
      const target = document.getElementById(sel.getAttribute('data-supplier-select'));
      if (!target) return;
      const upd = () => { target.classList.toggle('hide', sel.value !== '__new__'); };
      sel.addEventListener('change', upd);
      upd();
    });

    // Show/hide custom date range
    /* Filter periode dengan input tanggal kustom.
       PENTING: seluruh kolom [data-period-custom] harus ditampilkan/disembunyikan
       bersama. Dulu hanya kolom PERTAMA yang diambil (querySelector), sehingga saat
       memilih "Custom tanggal" kolom "Dari" muncul tetapi kolom "Sampai" TETAP
       tersembunyi — pengguna tidak bisa mengisi tanggal akhir, jadi rentangnya
       berakhir di tanggal bawaan (gejala: "tanggal dari ada, sampai belum ada"). */
    $$('[data-period]').forEach((sel) => {
      const form = sel.form;
      const boxes = form ? form.querySelectorAll('[data-period-custom]') : [];
      if (!boxes.length) return;
      /* Kolom "Custom tanggal" yang TERSEMBUNYI ikut dinonaktifkan. Tanpa ini,
         nilainya tetap terkirim saat formulir dikirim sehingga pilihan periode lain
         (mis. "3 bulan terakhir"/"Tahun ini") dianggap "Custom tanggal" dengan
         tanggal LAMA — gejalanya: memilih periode tampak tidak berpengaruh.
         Ketika "Custom tanggal" dipilih, kolomnya ditampilkan DAN diaktifkan lagi. */
      const upd = () => {
        const kustom = sel.value === 'custom';
        boxes.forEach((b) => {
          b.style.display = kustom ? 'flex' : 'none';
          b.querySelectorAll('input,select,textarea').forEach((inp) => { inp.disabled = !kustom; });
        });
      };
      sel.addEventListener('change', upd); upd();
    });
  });
})();
