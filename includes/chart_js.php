<?php
/**
 * Skrip bersama untuk grafik tren pendapatan — DIPAKAI SEMUA MENU.
 *
 * Susunan grafiknya disengaja sama di dashboard, halaman Laporan, dokumen
 * laporan cetak/PDF, dan grafik sisi-server supaya tidak ada lagi perbedaan
 * tampilan antar menu:
 *
 *   1) BATANG BERJAJARAN "Treatment vs Skincare" — sumbunya hanya dibatasi
 *      nilai kedua seri itu, sehingga batang skincare (yang nilainya biasanya
 *      jauh lebih kecil) tetap terlihat dan bisa dibandingkan langsung.
 *   2) GRAFIK TERPISAH "Total Pendapatan" (garis + area) dengan sumbunya
 *      sendiri, jadi nilai total selalu terbaca.
 *
 * Sebelumnya kedua seri ditumpuk dan garis Total memakai sumbu yang sama:
 * garis Total jatuh tepat di puncak tumpukan (seolah hilang) dan nilai
 * skincare nyaris tak terlihat.
 *
 * Catatan: JANGAN memakai `Naveena.*` di dalam skrip ini. Dokumen cetak
 * (includes/report_document.php) hanya memuat Chart.js tanpa app.js — pernah
 * kejadian skrip berhenti di tengah karena memanggil Naveena.rupiahShort()
 * sehingga grafik-grafik berikutnya kosong.
 */
declare(strict_types=1);

/** Skrip grafik tren pendapatan (tanpa dependensi app.js). */
function income_charts_js(): string
{
    /* Warna bawaan = warna seri yang diatur di Pengaturan → Warna Grafik
       (pemanggil tetap boleh menimpanya lewat trColor/skColor/totalColor). */
    $ser = chart_series_colors();
    $js = <<<'JS'
(function () {
  if (typeof Chart === 'undefined') return;

  /* Rupiah ringkas untuk label sumbu (nilai penuh tetap di tooltip). */
  function short(v) {
    v = Number(v || 0);
    var a = Math.abs(v);
    if (a >= 1e9) return 'Rp ' + (v / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M';
    if (a >= 1e6) return 'Rp ' + (v / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
    if (a >= 1e3) return 'Rp ' + Math.round(v / 1e3).toLocaleString('id-ID') + ' rb';
    return 'Rp ' + v.toLocaleString('id-ID');
  }
  function money(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); }
  function axisX(labels) {
    return { grid: { display: false },
      ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 }, callback: function (val) {
        var lab = this.getLabelForValue(val); return lab; } } };
  }
  function axisY(shortFn) {
    return { beginAtZero: true, grid: { drawTicks: false },
      ticks: { font: { size: 10 }, callback: function (v) { return shortFn(v); } } };
  }

  /**
   * Gambar dua grafik dari satu set data.
   * o = { bars, total, labels, tr, sk, totalData, animation, colors... }
   */
  window.NaveenaIncome = function (o) {
    if (!o) return;
    var m = o.money || money;
    var sh = o.short || short;
    var anim = o.animation === false ? false : undefined;
    var legend = { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } };

    /* ---------- 1. Treatment vs Skincare (batang berjajaran) ---------- */
    var barsEl = document.getElementById(o.bars);
    if (barsEl) {
      new Chart(barsEl.getContext('2d'), {
        type: 'bar',
        data: {
          labels: o.labels,
          datasets: [
            { label: 'Treatment', data: o.tr, backgroundColor: o.trColor || '__TR__',
              borderRadius: 4, maxBarThickness: 30 },
            { label: 'Skincare', data: o.sk, backgroundColor: o.skColor || '__SK__',
              borderRadius: 4, maxBarThickness: 30 }
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false, animation: anim,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: legend,
            tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + m(c.parsed.y); } } } },
          scales: { x: axisX(o.labels), y: axisY(sh) }
        }
      });
    }

    /* ---------- 2. Total pendapatan (garis + area, sumbu sendiri) ---------- */
    var totEl = document.getElementById(o.total);
    if (totEl) {
      new Chart(totEl.getContext('2d'), {
        type: 'line',
        data: {
          labels: o.labels,
          datasets: [{
            label: 'Total Pendapatan',
            data: o.totalData,
            borderColor: o.totalColor || '__TOT__',
            backgroundColor: o.totalFill || '__TOTFILL__',
            fill: true, tension: .3, pointRadius: 3, pointBackgroundColor: o.totalColor || '__TOT__',
            borderWidth: 2
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false, animation: anim,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: legend,
            tooltip: { callbacks: { label: function (c) { return 'Total: ' + m(c.parsed.y); } } } },
          scales: { x: axisX(o.labels), y: axisY(sh) }
        }
      });
    }
  };
})();
JS;
    return str_replace(
        ['__TR__', '__SK__', '__TOT__', '__TOTFILL__'],
        [$ser['treatment'], $ser['skincare'], $ser['total'], chart_color_rgba($ser['total'], 0.14)],
        $js);
}
