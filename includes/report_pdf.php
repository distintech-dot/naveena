<?php
/**
 * Dokumen PDF laporan lengkap yang dibuat di sisi server (MiniPdf) —
 * berisi tabel angka DAN gambar grafik, sehingga bisa:
 *   - diunduh langsung sebagai berkas PDF, dan
 *   - dilampirkan pada email laporan otomatis.
 *
 * Grafik dibuat oleh includes/chartimg.php (GD). Bila GD tidak tersedia,
 * dokumen tetap dibuat tanpa grafik disertai keterangan.
 */
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/chartimg.php';

/** Judul periode/cakupan untuk kop dokumen. */
function report_pdf_meta(array $f): array
{
    $scopeName = $f['scope'] === null
        ? 'Semua Cabang'
        : (string)scalar('SELECT name FROM branches WHERE id=?', [$f['scope']], '-');
    return [
        'periode' => tglIndo($f['ps']) . ' — ' . tglIndo($f['pe']),
        'cakupan' => $scopeName,
        'status' => $f['status'],
    ];
}

/** Bangun PDF laporan lengkap + grafik. Mengembalikan ['bytes','filename']. */
function report_pdf_build(array $B, array $user, string $filename = ''): array
{
    $f = $B['filters'];
    $tot = $B['totals'];
    $meta = report_pdf_meta($f);
    $charts = report_charts($B);

    $W = 595.28;   // A4 portrait
    $M = 40;

    // ---- ukur dulu supaya tinggi halaman pas ----
    $probe = new MiniPdf($W, 3000, $M);
    $probe->dry = true;
    report_pdf_render($probe, $B, $user, $charts, $meta);
    $used = 3000 - $probe->y;
    $height = max(800, min(3000, $used + $M + 20));

    $pdf = new MiniPdf($W, $height, $M);
    report_pdf_render($pdf, $B, $user, $charts, $meta);
    $bytes = $pdf->output();
    if ($filename === '') {
        $filename = 'laporan-lengkap-' . $f['ps'] . '-sd-' . $f['pe'] . '.pdf';
    }
    return ['bytes' => $bytes, 'filename' => $filename, 'charts' => count($charts)];
}

function report_pdf_render(MiniPdf $p, array $B, array $user, array $charts, array $meta): void
{
    $f = $B['filters'];
    $tot = $B['totals'];
    $t = theme_current();

    /* ---------- Kop ----------
       Tata letak: logo di tengah-atas, diberi jarak yang cukup sebelum judul
       supaya judul tidak menempel/menabrak logo (dulu jaraknya hanya satu
       tinggi baris sehingga judul tampak menempel pada logo). */
    $logo = logo_pdf_path();   // PDF hanya mendukung PNG (logo JPEG dikonversi otomatis)
    $drawn = ['w' => 0.0, 'h' => 0.0];
    if ($logo !== '') $drawn = $p->imagePngCentered($logo, 150, 40);
    if ($drawn['w'] > 0) {
        $p->gap(10);                              // udara antara logo dan judul
    } else {
        /* Nama panjang dibungkus & ukurannya mengecil agar kop tetap rapi. */
        $p->paragraph(clinic_name(), clinic_pdf_name_size(clinic_name(), 17.0, 12.0), true, 0, true);
        $p->gap(2);
    }
    $p->line('LAPORAN LENGKAP KINERJA KLINIK', 13, true, 0, true);
    $p->gap(1);
    $p->line($meta['periode'], 10, false, 0, true);
    $p->line($meta['cakupan'] . ' · status transaksi: ' . $meta['status'], 9, false, 0, true);
    $p->line(setting('company_address'), 8, false, 0, true);
    $p->gap(4);
    $p->line('Dicetak: ' . tglIndo(date('Y-m-d')) . ' ' . date('H:i') . ' WIB · oleh ' . ($user['name'] ?? '-')
        . ' (' . ($user['role_name'] ?? '-') . ')', 7.5, false, 0, true);
    $p->hr();

    /* ---------- 1. Ringkasan ---------- */
    $p->line('1. RINGKASAN PERIODE', 10.5, true);
    $p->gap(2);
    $p->kv('Total Pendapatan', money($tot['total']), 10.5, true);
    $p->kv('Jumlah Transaksi', num($tot['trx']) . ' transaksi');
    $p->kv('Rata-rata per Transaksi', money($tot['avg']));
    $p->kv('Subtotal (sebelum diskon)', money($tot['subtotal']));
    $p->kv('Total Diskon', money($tot['disc']));
    $p->kv('Pendapatan Treatment', money($tot['tr']) . '  (' . num($tot['tr_q']) . ' treatment)');
    $p->kv('Penjualan Skincare', money($tot['sk']) . '  (' . num($tot['sk_q']) . ' produk)');
    if ((float)($tot['pkg'] ?? 0) > 0) {
        $p->kv('Pendapatan Paket', money($tot['pkg']) . '  (' . num($tot['pkg_q'] ?? 0) . ' paket)');
    }
    $p->kv('Total Pembayaran Valid', money($tot['pay_total']) . '  (' . num($tot['pay_n']) . ' pembayaran)');
    $p->gap(4);

    /* ---------- Grafik ---------- */
    if ($charts) {
        $p->hr();
        $p->line('GRAFIK', 10.5, true);
        $p->gap(3);
        foreach ($charts as $c) {
            $tmp = tmpfile();
            if ($tmp === false) continue;
            $path = stream_get_meta_data($tmp)['uri'];
            fwrite($tmp, $c['png']);
            $p->line($c['title'], 9, true);
            $p->gap(1);
            $p->imagePngCentered($path, $p->W - $p->margin * 2, 175);
            $p->gap(6);
            fclose($tmp);
        }
    } else {
        $p->paragraph('Grafik tidak disertakan karena ekstensi GD tidak tersedia di server ini.', 8, true);
    }

    /* ---------- 2. Perbandingan cabang ---------- */
    $p->hr();
    $p->line('2. PERBANDINGAN CABANG', 10.5, true);
    $p->gap(2);
    $cols = [$p->margin, $p->margin + 200, $p->margin + 280, $p->margin + 360];
    $p->text($cols[0], 'Cabang', 8.5, true);
    $p->text($cols[1], 'Transaksi', 8.5, true);
    $p->text($cols[2], 'Treatment', 8.5, true);
    $p->textRight($p->W - $p->margin, 'Pendapatan', 8.5, true);
    $p->gap(12);
    $sumB = array_sum(array_column($B['branches'], 'total'));
    foreach ($B['branches'] as $b) {
        $p->ensure(12);
        $p->text($cols[0], short_text((string)$b['name'], 38), 8.5);
        $p->text($cols[1], num($b['trx']), 8.5);
        $p->text($cols[2], money($b['tr']), 8.5);
        $p->textRight($p->W - $p->margin, money($b['total']), 8.5, true);
        $p->gap(11);
    }
    if ($B['branches']) {
        $p->gap(1);
        $p->text($cols[0], 'TOTAL', 8.5, true);
        $p->text($cols[1], num(array_sum(array_column($B['branches'], 'trx'))), 8.5, true);
        $p->text($cols[2], money(array_sum(array_column($B['branches'], 'tr'))), 8.5, true);
        $p->textRight($p->W - $p->margin, money($sumB), 8.5, true);
    }
    $p->gap(8);

    /* ---------- 3. Progres berkala ---------- */
    $monthly = $B['monthly'];
    $p->hr();
    $p->line('3. PROGRES ' . ($monthly['granularity'] === 'harian' ? 'HARIAN' : 'BULANAN')
        . ' (' . num($monthly['buckets']) . ' ' . $monthly['label_suffix'] . ')', 10.5, true);
    $p->gap(2);
    if ($monthly['growth'] !== null) {
        $p->line('Perubahan terakhir: ' . ($monthly['growth'] >= 0 ? '+' : '') . num($monthly['growth'], 1) . '% dibanding periode sebelumnya', 8.5);
        $p->gap(2);
    }
    $p->text($p->margin, 'Periode', 8.5, true);
    $p->text($p->margin + 150, 'Transaksi', 8.5, true);
    $p->text($p->margin + 230, 'Treatment', 8.5, true);
    $p->text($p->margin + 310, 'Skincare', 8.5, true);
    $p->textRight($p->W - $p->margin, 'Total', 8.5, true);
    $p->gap(12);
    foreach ($monthly['labels_full'] as $i => $lbl) {
        $p->ensure(11);
        $p->text($p->margin, short_text((string)$lbl, 30), 8.2);
        $p->text($p->margin + 150, num($monthly['trx'][$i]), 8.2);
        $p->text($p->margin + 230, money($monthly['tr'][$i]), 8.2);
        $p->text($p->margin + 310, money($monthly['sk'][$i]), 8.2);
        $p->textRight($p->W - $p->margin, money($monthly['total'][$i]), 8.2, true);
        $p->gap(10.5);
    }
    /* Keterangan ASAL ANGKA kolom "Total" di atas — supaya dokumen cetak tidak
       menampilkan angka tanpa penjelasan (permintaan pemilik). Sumbernya sama
       dengan halaman Laporan (report_income_breakdown). */
    $inc = $B['income'] ?? report_income_breakdown($f);
    $p->ensure(30);
    $p->gap(3);
    $p->paragraph('Catatan: ' . income_formula_text() . '.', 8.0);
    $p->gap(1);
    $rinci = 'Treatment ' . money($inc['tr']) . ' + Skincare ' . money($inc['sk']);
    if ($inc['pkg'] > 0) $rinci .= ' + Paket ' . money($inc['pkg']);
    $rinci .= ' - Diskon ' . money($inc['disc']) . ' - Diskon member ' . money($inc['member_disc']);
    if ($inc['unique'] > 0) $rinci .= ' + Kode unik ' . money($inc['unique']);
    $rinci .= ' = ' . money($inc['total']) . '.';
    $p->paragraph($rinci . ' Bahan treatment tidak dihitung (tidak dijual); isi paket tidak dihitung dua kali.', 8.0);
    $incNote = income_breakdown_note($inc);
    if ($incNote !== '') { $p->gap(1); $p->paragraph($incNote, 8.0); }
    $p->gap(8);

    /* ---------- 4. Metode pembayaran ---------- */
    if (!empty($B['methods'])) {
        $p->hr();
        $p->line('4. METODE PEMBAYARAN', 10.5, true);
        $p->gap(2);
        foreach ($B['methods'] as $m) {
            $p->kv($m['method'] . '  (' . num($m['n']) . 'x)', money($m['total']), 8.5);
        }
        $p->gap(8);
    }

    /* ---------- 5. Kinerja kasir ---------- */
    if (!empty($B['cashiers'])) {
        $p->hr();
        $p->line('5. KINERJA KASIR', 10.5, true);
        $p->gap(2);
        foreach ($B['cashiers'] as $c) {
            $p->ensure(11);
            $p->text($p->margin, short_text((string)$c['nama'], 34), 8.5);
            $p->text($p->margin + 230, num($c['trx']) . ' transaksi', 8.5);
            $p->textRight($p->W - $p->margin, money($c['total']), 8.5, true);
            $p->gap(11);
        }
        $p->gap(8);
    }

    /* ---------- 6. Top 5 ---------- */
    $topTr = array_slice($B['treatments'] ?? [], 0, 5);
    $topSk = array_slice($B['skincares'] ?? [], 0, 5);
    if ($topTr || $topSk) {
        $p->hr();
        $p->line('6. TOP 5 PENJUALAN', 10.5, true);
        $p->gap(2);
        if ($topTr) {
            $p->line('Treatment', 9, true);
            foreach ($topTr as $i => $r) {
                $p->ensure(11);
                $p->text($p->margin, ($i + 1) . '. ' . short_text((string)$r['nama'], 42), 8.5);
                $p->textRight($p->W - $p->margin, qty_text($r['q']) . 'x  ' . money($r['s']), 8.5);
                $p->gap(11);
            }
            $p->gap(4);
        }
        if ($topSk) {
            $p->line('Skincare', 9, true);
            foreach ($topSk as $i => $r) {
                $p->ensure(11);
                $p->text($p->margin, ($i + 1) . '. ' . short_text((string)$r['nama'], 42), 8.5);
                $p->textRight($p->W - $p->margin, qty_text($r['q']) . 'x  ' . money($r['s']), 8.5);
                $p->gap(11);
            }
        }
        $p->gap(8);
    }

    /* ---------- 7. Rincian item ---------- */
    foreach ([['treatment', 'RINCIAN TREATMENT'], ['skincare', 'RINCIAN SKINCARE']] as [$key, $title]) {
        $rows = $B[$key === 'treatment' ? 'treatments' : 'skincares'] ?? [];
        if (!$rows) continue;
        $p->hr();
        $p->line('7. ' . $title . ' (' . num(count($rows)) . ' item)', 10.5, true);
        $p->gap(2);
        $grand = array_sum(array_column($rows, 's')) ?: 1;
        foreach ($rows as $i => $r) {
            $p->ensure(11);
            $p->text($p->margin, ($i + 1) . '. ' . short_text((string)$r['nama'], 40), 8.2);
            $p->text($p->margin + 300, qty_text($r['q']) . 'x', 8.2);
            $p->textRight($p->W - $p->margin - 52, money($r['s']), 8.2);
            $p->textRight($p->W - $p->margin, num($r['s'] / $grand * 100, 1) . '%', 8.2);
            $p->gap(10.5);
        }
        $p->gap(6);
    }

    /* ---------- KEUANGAN (khusus owner) ---------- */
    if (finance_report_include()) {
        $fins = finance_summary($f, finance_mode() === 'lengkap', $f['scope']);
        $p->hr();
        $p->line('9. KEUANGAN — HPP, LABA KOTOR & LABA BERSIH (internal)', 10.5, true);
        $p->paragraph('Mode ' . finance_mode_label($fins['mode']) . '. Omzet = nilai barang (treatment + skincare) '
            . 'dikurangi diskon transaksi & diskon member; kode unik transfer/QRIS tidak dihitung.', 7.5);
        $p->gap(2);
        $p->kv('Pendapatan Treatment', money($fins['pendapatan_treatment']), 8.5);
        $p->kv('Pendapatan Skincare', money($fins['pendapatan_skincare']), 8.5);
        $p->kv('Pendapatan Paket', money($fins['pendapatan_paket']), 8.5);
        $p->kv('Pengurangan Diskon Transaksi', '- ' . money($fins['diskon']), 8.5);
        $p->kv('Pengurangan Diskon Member', '- ' . money($fins['diskon_member']), 8.5);
        $p->kv('TOTAL PENDAPATAN (OMZET)', money($fins['omzet']), 9, true);
        $p->kv('HPP Treatment', '- ' . money($fins['hpp_treatment']), 8.5);
        $p->kv('HPP Produk', '- ' . money($fins['hpp_produk']), 8.5);
        $p->kv('HPP Paket', '- ' . money($fins['hpp_paket']), 8.5);
        if ($fins['with_costs']) {
            $p->kv('LABA KOTOR', money($fins['laba_kotor']), 9, true);
            foreach ($fins['biaya_rows'] as $c) {
                $p->kv('  ' . $c['name'] . ' (' . $c['period_label'] . ' · '
                    . ($c['scope_label'] ?? 'Semua cabang') . ')', '- ' . money($c['share']), 8);
                /* Rincian per cabang (permintaan pemilik: satu baris per pos biaya). */
                if (!empty($c['breakdown']) && count($c['parts'] ?? []) > 1) {
                    $p->kv('      =', $c['breakdown'], 7);
                }
            }
            $p->kv('  Total Biaya Operasional', '- ' . money($fins['biaya_total']), 8.5, true);
        }
        $p->kv('LABA BERSIH', money($fins['laba_bersih']), 10, true);
        if ($fins['margin'] !== null) $p->kv('Margin Laba Bersih', num($fins['margin'], 1) . '% dari omzet', 8);
        $p->gap(4);
        $p->line('Laba bersih per cabang', 9, true);
        $p->gap(1);
        foreach (finance_per_branch($f) as $b) {
            $p->ensure(11);
            $p->text($p->margin, short_text(branch_short_label($b['branch']), 30), 8.2);
            $p->text($p->margin + 200, 'omzet ' . money($b['omzet']), 8.2);
            $p->textRight($p->W - $p->margin, money($b['laba_bersih']), 8.5, true);
            $p->gap(10.5);
        }
        $p->gap(6);
    }

    /* ---------- 7b. Pemakaian kartu member ---------- */
    $muse = $B['member_usage'] ?? [];
    if ($muse) {
        $p->hr();
        $p->line('8. PEMAKAIAN KARTU MEMBER (diskon otomatis)', 10.5, true);
        $p->paragraph('Diskon member dihitung otomatis dari nilai transaksi. Aturan: ' . member_rules_text() . '.', 7.5);
        $p->gap(2);
        $st = 0; $ss = 0; $sd = 0;
        foreach ($muse as $r) {
            $st += (int)$r['trx']; $ss += (float)$r['subtotal']; $sd += (float)$r['disc'];
            $p->ensure(11);
            $p->text($p->margin, short_text((string)$r['tier'], 34), 8.2);
            $p->text($p->margin + 190, member_scope_text((string)($r['scope'] ?? 'both')), 7.6);
            $p->text($p->margin + 300, num($r['trx']) . ' trx', 8.2);
            $p->textRight($p->W - $p->margin, money($r['disc']), 8.2);
            $p->gap(10.5);
        }
        $p->gap(2);
        $p->text($p->margin, 'TOTAL DISKON MEMBER', 8.5, true);
        $p->text($p->margin + 300, num($st) . ' trx', 8.5, true);
        $p->textRight($p->W - $p->margin, money($sd), 8.5, true);
        $p->gap(12);
    }

    /* ---------- 8. Pemakaian bahan treatment (tidak dijual ke pasien) ---------- */
    $mats = $B['materials'] ?? [];
    if ($mats) {
        $p->hr();
        $p->line('9. PEMAKAIAN BAHAN TREATMENT (tidak ditagihkan)', 10.5, true);
        $p->paragraph('Bahan treatment adalah pelengkap proses treatment dan TIDAK dijual ke pasien: '
            . 'tidak muncul di struk dan tidak menambah pendapatan. Nilai di bawah hanya informasi biaya.', 7.5);
        $p->gap(2);
        $nq = 0; $nv = 0;
        foreach ($mats as $r) {
            $hrg = !empty($r['material_id'])
                ? (float)scalar('SELECT price FROM treatment_materials WHERE id=?', [(int)$r['material_id']], 0) : 0;
            $nq += (float)$r['q']; $nv += $hrg * (float)$r['q'];
            $p->ensure(11);
            $p->text($p->margin, short_text((string)$r['nama'], 40), 8.2);
            $p->text($p->margin + 300, qty_text($r['q']), 8.2);
            $p->textRight($p->W - $p->margin - 52, num($r['trx']) . ' trx', 8.2);
            $p->textRight($p->W - $p->margin, money($hrg * (float)$r['q']), 8.2);
            $p->gap(10.5);
        }
        $p->gap(2);
        $p->text($p->margin, 'TOTAL PEMAKAIAN', 8.5, true);
        $p->text($p->margin + 300, qty_text($nq), 8.5, true);
        $p->textRight($p->W - $p->margin, money($nv), 8.5, true);
        $p->gap(12);
    }

    $p->hr();
    $p->paragraph('Dokumen ini dibuat otomatis oleh ' . clinic_name() . ' Management System pada '
        . tglIndo(date('Y-m-d')) . ' ' . date('H:i') . ' WIB. Seluruh angka dihitung dari database klinik '
        . 'menggunakan filter yang sama dengan tampilan layar.', 7.5);
}


/* ================================================================== *
 * DOKUMEN PDF KEUANGAN (menu Keuangan — khusus owner)
 * Omzet → HPP → laba kotor → biaya operasional → laba bersih, plus
 * rincian per cabang dan grafik laba bersih.
 * ================================================================== */
function finance_pdf_bytes(array $f, array $sum, array $perBranch, array $costs, array $series): string
{
    $W = 595.28;
    $M = 40;
    $probe = new MiniPdf($W, 4000, $M);
    $probe->dry = true;
    finance_pdf_render($probe, $f, $sum, $perBranch, $costs, $series);
    $used = 4000 - $probe->y;
    $height = max(800, min(4000, $used + $M + 20));

    $pdf = new MiniPdf($W, $height, $M);
    finance_pdf_render($pdf, $f, $sum, $perBranch, $costs, $series);
    return $pdf->output();
}

function finance_pdf_render(MiniPdf $p, array $f, array $sum, array $perBranch, array $costs, array $series): void
{
    $scopeName = $f['scope'] === null
        ? 'Semua Cabang'
        : (string)scalar('SELECT name FROM branches WHERE id=?', [$f['scope']], '-');

    /* ---- Kop (sama gayanya dengan dokumen laporan lain) ---- */
    $logo = logo_pdf_path();
    $drawn = ['w' => 0.0, 'h' => 0.0];
    if ($logo !== '') $drawn = $p->imagePngCentered($logo, 150, 40);
    if ($drawn['w'] > 0) {
        $p->gap(10);
    } else {
        $p->paragraph(clinic_name(), clinic_pdf_name_size(clinic_name(), 17.0, 12.0), true, 0, true);
        $p->gap(2);
    }
    $p->line('LAPORAN KEUANGAN — ' . strtoupper(finance_mode_label($sum['mode'])), 13, true, 0, true);
    $p->gap(1);
    $p->line(tglIndo($f['ps']) . ' — ' . tglIndo($f['pe']), 10, false, 0, true);
    $p->line($scopeName, 9, false, 0, true);
    $p->gap(4);
    $p->line('Dicetak: ' . tglIndo(date('Y-m-d')) . ' ' . date('H:i') . ' WIB', 7.5, false, 0, true);
    $p->hr();

    /* ---- Perhitungan ---- */
    $p->line('PERHITUNGAN LABA RUGI', 10.5, true);
    $p->gap(2);
    $p->kv('Pendapatan Treatment', money($sum['pendapatan_treatment']));
    $p->kv('Pendapatan Skincare', money($sum['pendapatan_skincare']));
    $p->kv('Pengurangan Diskon Transaksi', '- ' . money($sum['diskon']));
    $p->kv('Pengurangan Diskon Member', '- ' . money($sum['diskon_member']));
    $p->hr();
    $p->kv('TOTAL PENDAPATAN (OMZET)', money($sum['omzet']), 10, true);
    $p->kv('HPP Treatment', '- ' . money($sum['hpp_treatment']));
    $p->kv('HPP Produk', '- ' . money($sum['hpp_produk']));
    if ($sum['with_costs']) {
        $p->hr();
        $p->kv('LABA KOTOR', money($sum['laba_kotor']), 10, true);
        $p->gap(2);
        $p->line('Biaya Operasional', 9, true);
        foreach ($costs['rows'] as $c) {
            $p->kv('  ' . $c['name'] . '  (' . $c['period_label'] . ' ' . money($c['amount']) . ')',
                '- ' . money($c['share']));
        }
        $p->kv('  Total Biaya Operasional', '- ' . money($sum['biaya_total']), 9, true);
    }
    $p->hr();
    $p->kv('LABA BERSIH', money($sum['laba_bersih']), 11, true);
    if ($sum['margin'] !== null) $p->kv('Margin Laba Bersih', num($sum['margin'], 1) . '% dari omzet', 9);
    $p->gap(3);
    $p->paragraph('Catatan: omzet dihitung dari nilai barang (treatment + skincare) dikurangi diskon transaksi '
        . 'dan diskon member; kode unik transfer/QRIS tidak dihitung karena hanya alat pencocokan mutasi bank. '
        . 'Biaya operasional diprorata sesuai periode pembayarannya untuk rentang laporan ini ('
        . num((int)$costs['days']) . ' hari). HPP memakai harga pokok saat transaksi terjadi.', 7.6);

    /* ---- Per cabang ---- */
    if ($perBranch) {
        $p->hr();
        $p->line('RINCIAN PER CABANG', 10.5, true);
        $p->gap(2);
        foreach ($perBranch as $b) {
            $p->kv(branch_short_label($b['branch']) . '  (' . num($b['trx']) . ' transaksi)', '');
            $p->kv('   Omzet', money($b['omzet']), 8.5);
            $p->kv('   HPP Treatment', '- ' . money($b['hpp_treatment']), 8.5);
            $p->kv('   HPP Produk', '- ' . money($b['hpp_produk']), 8.5);
            if ($sum['with_costs']) $p->kv('   Biaya Operasional', '- ' . money($b['biaya_total']), 8.5);
            $p->kv('   Laba Bersih', money($b['laba_bersih']), 9, true);
            $p->gap(2);
        }
    }

    /* ---- Grafik laba bersih ---- */
    if (function_exists('chart_available') && chart_available()) {
        $png = chart_bar([
            'labels' => $series['labels'],
            'series' => [[
                'label' => 'Laba Bersih',
                'data' => $series['laba'],
                'color' => chart_hex2rgb(chart_series_color('positif')),
            ]],
        ], 'Laba Bersih per ' . ($series['granularity'] === 'harian' ? 'Hari' : 'Bulan'), 900, 340);
        if ($png) {
            $p->hr();
            $p->line('GRAFIK LABA BERSIH', 10.5, true);
            $p->gap(2);
            $tmp = tmpfile();
            if ($tmp !== false) {
                $path = stream_get_meta_data($tmp)['uri'];
                fwrite($tmp, $png);
                $p->imagePngCentered($path, $p->W - $p->margin * 2, 175);
                fclose($tmp);
            }
        }
        if ($series['branches'] && count($series['branches']) > 1) {
            $bSeries = [];
            $pal = array_map('chart_hex2rgb', chart_colors(count($series['branches'])));
            $i = 0;
            foreach ($series['branches'] as $name => $vals) {
                $bSeries[] = ['label' => branch_short_label((string)$name), 'data' => $vals, 'color' => $pal[$i % count($pal)]];
                $i++;
            }
            $png2 = chart_bar(['labels' => $series['labels'], 'series' => $bSeries],
                'Laba Bersih per Cabang', 900, 340);
            if ($png2) {
                $p->gap(4);
                $tmp = tmpfile();
                if ($tmp !== false) {
                    $path = stream_get_meta_data($tmp)['uri'];
                    fwrite($tmp, $png2);
                    $p->imagePngCentered($path, $p->W - $p->margin * 2, 175);
                    fclose($tmp);
                }
            }
        }
    } else {
        $p->paragraph('Grafik tidak disertakan karena ekstensi GD tidak tersedia di server ini.', 8, true);
    }
}
