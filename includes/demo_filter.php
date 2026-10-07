<?php
/**
 * PENYARING DATA DEMO PADA LAPORAN (ronde 54)
 * ==========================================
 * Permintaan pemilik (PDF Master Upgrade bagian 4): "Report production mengecualikan demo
 * secara default melalui demo_batch_id. Report demo/test dapat memasukkan demo bila
 * context-nya memang demo."
 *
 * Cara pakai (satu sumber, konsisten di seluruh laporan):
 *   $sql .= demo_exclude_sql('o');      // menambah "AND o.demo_batch_id IS NULL"
 *   $sql .= report_demo_clause('o');    // bentuk sadar-konteks (mengikuti setelan/tombol)
 *
 * PENTING: fungsi ini AMAN untuk basis data yang belum punya kolom `demo_batch_id`
 * (mengembalikan string kosong), sehingga modul lama tidak pernah gagal hanya karena
 * migrasi belum dijalankan.
 */

/** Apakah laporan saat ini boleh memuat data demo? (setelan + permintaan eksplisit) */
function report_include_demo(): bool
{
    if (isset($_GET['demo']) && (string)$_GET['demo'] === '1') return true;
    if (isset($_POST['demo']) && (string)$_POST['demo'] === '1') return true;
    return (string)setting('report_include_demo', '0') === '1';
}

/** Apakah kolom demo_batch_id ada pada sebuah tabel? (di-cache) */
function demo_filter_column_ready(string $tabel): bool
{
    static $cache = [];
    if (isset($cache[$tabel])) return $cache[$tabel];
    try {
        $n = (int)scalar("SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = 'demo_batch_id'", [$tabel]);
    } catch (Throwable $e) { $n = 0; }
    return $cache[$tabel] = ($n > 0);
}

/**
 * Potongan SQL untuk mengecualikan data demo pada sebuah alias tabel.
 * Contoh: `demo_exclude_sql('o')` → ` AND o.demo_batch_id IS NULL`
 */
function demo_exclude_sql(string $alias, string $tabel = ''): string
{
    if (report_include_demo()) return '';
    if ($tabel !== '' && !demo_filter_column_ready($tabel)) return '';
    $alias = trim($alias);
    if ($alias === '') return '';
    return ' AND ' . $alias . '.demo_batch_id IS NULL';
}

/** Bentuk sadar-konteks: '' bila demo boleh ikut, selain itu potongan pengecualian. */
function report_demo_clause(string $alias): string
{
    return demo_exclude_sql($alias);
}

/** Keterangan jujur untuk ditampilkan pada laporan. */
function report_demo_note(): string
{
    if (report_include_demo()) return 'Laporan ini MEMASUKKAN data demo (mode uji/demo).';
    return 'Data demo dikecualikan dari laporan ini (mode produksi).';
}
