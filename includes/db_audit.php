<?php
/**
 * ALAT AUDIT CAKUPAN QUERY (ronde 54)
 * ===================================
 * Permintaan pemilik (PDF Master Upgrade bagian 3): "Repository-wide query audit:
 * SELECT, INSERT, UPDATE, DELETE, JOIN, subquery, aggregate, dashboard, report, search,
 * filter, export, API/AJAX, webhook dan background job harus diperiksa untuk branch
 * context dan database yang tepat."
 *
 * Alat ini memindai SELURUH berkas PHP aplikasi, mengumpulkan setiap pemanggilan query
 * (`q(`, `one(`, `all(`, `scalar(`, dan padanannya untuk koneksi tertentu `*_on($conn, …)`),
 * lalu menilai apakah
 * query itu:
 *   • menyebut `branch_id` (atau memakai helper cakupan yang sudah membatasi cabang), atau
 *   • berada di berkas/tabel yang memang GLOBAL (users, roles, permissions, settings,
 *     branches, icd, ai_*, db_*), atau
 *   • perlu diperiksa (query tabel operasional TANPA pembatasan cabang).
 *
 * Hasilnya dipakai sebagai DAFTAR KERJA yang jujur: modul yang belum dibatasi akan
 * dilaporkan apa adanya (bukan diklaim selesai). Uji otomatis memakainya sebagai penjaga
 * regresi agar jumlah temuan tidak bertambah.
 *
 * Pemakaian: php naveena_dev/tools/audit_query_scope.php [--json]
 */

/**
 * Berkas khusus Super Admin / owner yang MEMANG bekerja lintas cabang, sehingga tidak
 * memerlukan pembatas cabang per query. Daftar ini eksplisit (bukan diam-diam) supaya
 * laporan audit jujur: query di berkas ini TIDAK dihitung sebagai temuan.
 */
function db_audit_owner_files(): array
{
    return ['developer.php', 'includes/db_manager.php', 'includes/db_audit.php',
        'includes/demo_data.php', 'demo_data.php', 'purge.php', 'backup.php',
        'includes/backup_lib.php', 'includes/app_summary.php', 'includes/retention.php',
        'includes/report_document.php', 'includes/report_pdf.php', 'includes/xlsx.php',
        'includes/chartimg.php', 'includes/monthly_report.php', 'includes/db_manager.php'];
}

/**
 * Berkas "ANAK": modul pustaka yang seluruh query-nya hanya dijalankan dengan id INDUK
 * (paket, reservasi, pasien, pesanan) yang cabangnya sudah diperiksa pemanggilnya
 * (`assert_branch()` di halaman pemanggil, atau cabang ditentukan fungsi pembuatnya).
 *
 * Daftar ini SENGAJA terbuka (bukan diabaikan diam-diam): temuannya tetap DITAMPILKAN di
 * laporan sebagai kelompok tersendiri ("berkas anak — cakupan dari induk") supaya dapat
 * ditinjau manusia. Saat pengalihan koneksi central/branch, modul-modul ini harus menerima
 * cabang dari induknya — lihat `db_conn_for_record()` di includes/db_manager.php.
 */
function db_audit_child_files(): array
{
    return [
        'includes/package.php' => 'fungsi paket hanya dipanggil dengan id paket yang cabangnya diperiksa pemanggil',
        'includes/appointment.php' => 'treatment reservasi hanya untuk id reservasi yang cabangnya diperiksa pemanggil',
        'includes/patient.php' => 'status pasien otomatis hanya untuk id pasien dari alur transaksi/rekam medis ber-cabang',
        'includes/order_create.php' => 'penulisan item & pembayaran memakai cabang yang ditentukan order_create()',
        'includes/payment.php' => 'pembayaran memakai id pesanan dari tagihan (pay_pending) milik aplikasi sendiri',
        'includes/receipt.php' => 'struk hanya untuk id pesanan yang cabangnya diperiksa halaman struk',
        'rekam_medis_form.php' => 'lampiran klinis hanya untuk id rekam medis yang cabangnya diperiksa halaman',
    ];
}

/** Tabel yang memang GLOBAL (bukan milik satu cabang). */
function db_audit_global_tables(): array
{
    return ['branches', 'users', 'roles', 'permissions', 'role_permissions', 'user_permissions',
        'grants', 'settings', 'icd_codes', 'import_batches', 'import_errors', 'audit_logs',
        'backups', 'db_registry', 'db_migrations', 'email_report_logs', 'pay_pending',
        'ai_tasks', 'ai_task_files', 'ai_usage_log', 'ai_steps', 'ai_messages', 'ai_traces',
        'ai_jobs', 'login_remember', 'password_resets', 'twofa', 'finance_costs',
        'finance_cost_amounts', 'sqlite_master', 'sqlite_sequence'];
}

/** Tabel OPERASIONAL (milik cabang). */
function db_audit_operational_tables(): array
{
    return ['patients', 'medical_records', 'medical_record_photos', 'appointments',
        'appointment_treatments', 'orders', 'order_items', 'payments', 'inventory_movements',
        'treatments', 'skincare_products', 'materials', 'suppliers', 'packages', 'package_items',
        'doctors', 'therapists', 'staff', 'expenses', 'incomes', 'finance_expenses'];
}

/**
 * Helper pembatas cabang yang DIKENAL alat audit. Variabel yang diisi hasil salah satu
 * helper ini dianggap membawa pembatas cabang (lihat db_audit_tainted_vars()).
 */
function db_audit_scope_helpers(): array
{
    return ['bscope', 'branch_sql', 'scope_branch', 'resolve_branch_input', 'sc',
        'branch_where', 'branch_filter', 'assert_branch', 'user_branch',
        /* Koneksi cabang eksplisit: `$c = db_conn_for_record('patients', $id)` lalu query
           memakai $c (`scalar_on($c, …)`) — cabangnya ditentukan record itu sendiri. */
        'db_conn_for_record', 'dbo'];
}

/**
 * Apakah potongan kode ini membawa pembatas cabang?
 *
 * Dianggap YA bila menyebut kolom `branch_id`, placeholder `{BR}`/`{AND}`, memanggil
 * helper cakupan, atau MEMAKAI variabel yang sudah terbukti membawa pembatas cabang
 * (hasil db_audit_tainted_vars()). Inilah yang membuat audit mengenali pola nyata aplikasi
 * ini: `$w[] = 'o.branch_id = ?'` → `$w` → `$base = "… WHERE {$w}"` → `SELECT … {$base}`.
 */
function db_audit_text_scoped(string $teks, array $tainted): bool
{
    if ($teks === '') return false;
    if (preg_match('/\bbranch_id\b|\{BR\}|\{AND\}|\{PAT\}/i', $teks)) return true;
    $helper = implode('|', array_map(fn($h) => preg_quote($h, '/'), db_audit_scope_helpers()));
    if (preg_match('/\b(?:' . $helper . ')\s*\(/i', $teks)) return true;
    foreach (array_keys($tainted) as $v) {
        if (preg_match('/(?<![\w$])\$' . preg_quote((string)$v, '/') . '\b/', $teks)) return true;
    }
    return false;
}

/**
 * Variabel yang NILAINYA membawa pembatas cabang — analisis "taint" sederhana.
 *
 * Tanpa analisis ini, audit selalu menuduh query yang sebenarnya sudah dibatasi cabang
 * (mis. `$bs = $scope === null ? '' : ' AND o.branch_id = ?';` lalu `{$bs}`), sehingga
 * daftar kerja tidak pernah dapat diselesaikan dan laporannya tidak dapat dipercaya.
 *
 * Dianggap membawa pembatas cabang bila:
 *   1. diisi hasil helper cakupan — termasuk bentuk DESTRUKTURANSI
 *      (`[$bs, $bp] = branch_sql('p.branch_id')`, `[$b1, $b2] = sc('o.branch_id', $scope)`);
 *   2. diisi (`=`) atau ditambah (`[] =`) potongan SQL yang menyebut branch_id / {BR} / {AND};
 *   3. memakai variabel lain yang sudah membawa pembatas (rantai `$w` → `$base`).
 *
 * @return array<string,bool> nama variabel (tanpa `$`) => true
 */
function db_audit_tainted_vars(string $isi): array
{
    $baris = explode("\n", $isi);
    $tainted = [];
    /* Diulang sampai stabil supaya rantai variabel (lebih dari satu tingkat) ikut terbaca. */
    for ($pass = 0; $pass < 8; $pass++) {
        $sebelum = count($tainted);
        foreach ($baris as $i => $ln) {
            if (strpos($ln, '=') === false) continue;
            $stmt = db_audit_statement_at($baris, $i);
            /* (a) DESTRUKTURANSI: `[$bs, $bp] = branch_sql(...)`, `[$b1, $b2] = sc(...)`.
               Bentuk ini paling sering dipakai aplikasi dan tidak terlihat oleh pola
               `$var = ...` biasa — sumber positif palsu terbesar kedua. */
            if (preg_match_all('/\[([^\]]+)\]\s*=\s*/', $stmt, $dm, PREG_OFFSET_CAPTURE)) {
                foreach ($dm[1] as $k => $grp) {
                    $kanan = substr($stmt, (int)$dm[0][$k][1] + strlen((string)$dm[0][$k][0]));
                    if (!db_audit_text_scoped($kanan, $tainted)) continue;
                    if (preg_match_all('/\$([A-Za-z_]\w*)/', (string)$grp[0], $vm)) {
                        foreach ($vm[1] as $v) $tainted[$v] = true;
                    }
                }
            }
            if (!preg_match_all('/(\$[A-Za-z_]\w*)\s*(?:\[[^\]]*\])?\s*(?:\.=|=(?![=>]))/', $stmt, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($mm[0] as $k => $hit) {
                $nama = ltrim($mm[1][$k][0], '$');
                /* Sisi KANAN tanda '=' saja yang dinilai (bagian setelah posisi kecocokan). */
                $kanan = substr($stmt, (int)$hit[1]);
                if (db_audit_text_scoped($kanan, $tainted)) $tainted[$nama] = true;
            }
        }
        if (count($tainted) === $sebelum) break;
    }
    return $tainted;
}

/** Rangkaikan pernyataan (sampai titik-koma) mulai baris ke-$i — maksimal 14 baris. */
function db_audit_statement_at(array $baris, int $i, int $maks = 14): string
{
    $buf = [];
    $n = count($baris);
    for ($j = $i; $j < $n && $j < $i + $maks; $j++) {
        $buf[] = $baris[$j];
        if (strpos($baris[$j], ';') !== false) break;
    }
    return implode(' ', $buf);
}

/**
 * Potongan pernyataan yang benar-benar MENYUSUN SQL (argumen parameter dipotong).
 *
 * Penting: tanpa pemotongan ini, variabel daftar parameter (`$params`, yang ikut "tercemar"
 * karena berisi nilai cabang) membuat query TANPA pembatas cabang tampak aman — yaitu
 * kebalikan dari yang dibutuhkan audit. Potongan diambil sampai akhir literal terakhir
 * yang memuat kata kunci SQL, sehingga `{$base}` (di dalam string) tetap terbaca.
 */
function db_audit_sql_span(string $stmt): string
{
    if (preg_match_all('~([\x27"])((?:\\.|(?!\1).)*)\1~s', $stmt, $mm, PREG_OFFSET_CAPTURE)) {
        $akhir = null;
        foreach ($mm[0] as $k => $hit) {
            $isiLit = (string)$mm[2][$k][0];
            if (preg_match('/\b(SELECT|FROM|WHERE|JOIN|INSERT|UPDATE|DELETE|GROUP|ORDER)\b/i', $isiLit)) {
                $akhir = (int)$hit[1] + strlen((string)$hit[0]);
            }
        }
        if ($akhir !== null) return substr($stmt, 0, $akhir);
    }
    return $stmt;
}

/**
 * Apakah kunci `['sql']` pada filter laporan dapat dipercaya membawa pembatas cabang?
 *
 * `includes/reports.php` adalah SATU SUMBER filter laporan (report_filters() /
 * report_filters_manual()) dan keduanya menambahkan `o.branch_id = ?` ke kunci 'sql'
 * saat pemakainya bukan level pemilik. Sifat ini DIPERIKSA ULANG di sini: begitu
 * reports.php berhenti menambahkan branch_id, kepercayaan ini otomatis batal dan
 * seluruh query pemakainya kembali muncul di daftar kerja.
 */
function db_audit_sql_element_trusted(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $isi = (string)@file_get_contents(APP_DIR . '/includes/reports.php');
    $ok = (bool)preg_match('/\$w\[\]\s*=\s*[\'"][^\'"]*branch_id/i', $isi);
    return $ok;
}

/**
 * Pindai satu berkas PHP: kembalikan daftar query yang perlu diperiksa.
 *
 * Pola AMAN yang dikenali (semuanya sudah diverifikasi dengan membaca kodenya):
 *   1. SQL menyebut `branch_id`.
 *   2. Potongan kode memakai placeholder `{BR}`/`{AND}` (purge/retensi).
 *   3. Pemanggilan helper cakupan (`bscope()`, `branch_sql()`, `scope_branch()`, `sc()`, …).
 *   4. Pemakaian variabel yang membawa pembatas cabang (lihat db_audit_tainted_vars()),
 *      termasuk lewat rantai `$w` → `$base` → `{$base}`.
 *   5. `{$f['sql']}` dari filter laporan (diverifikasi db_audit_sql_element_trusted()).
 *   6. Penanda eksplisit lintas cabang (komentar `cross-branch` — operasi owner/atribut
      klinik yang memang bekerja lintas cabang; hasilnya DICATAT terpisah di laporan) atau
      koneksi cabang eksplisit (`db_for_branch()`, `db_conn_for_record()`).
 *   7. WAJIB/DELETE per-id di berkas yang sudah menerapkan cakupan cabang.
 *
 * @return array<int,array{file:string,line:int,jenis:string,tabel:string,alasan:string,cuplik:string}>
 */
function db_audit_scan_file(string $path, string $rel): array
{
    $kosong = ['hard' => [], 'anak' => [], 'cross' => []];
    $isi = @file_get_contents($path);
    if ($isi === false) return $kosong;
    $keluar = [];
    $anak = [];
    $cross = [];
    /* Berkas ANAK: cakupan cabang diambil dari INDUK — temuannya dilaporkan sebagai kelompok
       tersendiri (tetap terlihat), bukan dihitung sebagai temuan keras. */
    $alasanAnak = db_audit_child_files()[$rel] ?? null;
    $barisSemua = explode("\n", $isi);
    $operasional = db_audit_operational_tables();
    /* Berkas khusus Super Admin/owner: pekerjaannya memang lintas cabang. */
    if (in_array($rel, db_audit_owner_files(), true)) return $kosong;
    $adaHelper = (bool)preg_match('/\b(bscope|branch_sql|scope_branch|assert_branch|user_branch|resolve_branch_input|sc)\s*\(/', $isi);
    $adaScope = (bool)preg_match('/\bscope_branch\s*\(|\bbscope\s*\(|\bbranch_sql\s*\(|\bsc\s*\(/', $isi);
    /* Variabel yang NILAINYA berasal dari helper cakupan cabang (lihat db_audit_tainted_vars()). */
    $varCabang = db_audit_tainted_vars($isi);
    $sqlElementTrusted = db_audit_sql_element_trusted();

    foreach ($barisSemua as $no => $baris) {
        if (!preg_match('/\b(q|one|all|scalar)(?:_on)?\s*\(/', $baris)) continue;
        /* PENTING: analisis HANYA satu pernyataan (sampai titik-koma), bukan 12 baris
           berikutnya. Jendela lebar mencampur beberapa pernyataan sekaligus sehingga
           tabel/kolom dari pernyataan LAIN ikut terbaca dan tabel yang dilaporkan salah. */
        $potongan = db_audit_statement_at($barisSemua, $no, 30);
        $potongan = preg_replace('~//[^\n]*~', ' ', $potongan) ?? $potongan;
        /* DDL bukan query operasional. */
        if (preg_match('/\b(CREATE\s+(TABLE|INDEX|VIEW)|ALTER\s+TABLE|DROP\s+TABLE|PRAGMA)\b/i', $potongan)) continue;
        /* PENTING: ambil string yang benar-benar SQL — BUKAN string pertama pada baris itu
           (bisa berupa 'active', 'skincare.view', dsb.). Tanpa ini, audit menganalisis teks
           yang salah lalu menuduh query yang sebenarnya sudah terbatas cabang. */
        /* PHP menyusun SQL dengan MENYAMBUNG beberapa literal (mis. "SELECT … WHERE "
           . "'active'" . " AND branch_id = ?"). Mengambil satu literal saja membuat
           bagian penting (branch_id) tidak terlihat — sumber positif palsu terbesar.
           Karena itu seluruh literal pada pernyataan itu DIGABUNG lalu dianalisis. */
        $bagian = [];
        if (preg_match_all('~([\x27\x22])((?:\\.|(?!\1).)*)\1~s', $potongan, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $hit) {
                $t = $hit[2];
                if (strlen(trim($t)) >= 3) $bagian[] = $t;
            }
        }
        $sql = trim(implode(' ', $bagian));
        if ($sql === '' || !preg_match('/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $sql)) continue;
        $tabelKena = null;
        foreach ($operasional as $tbl) {
            if (preg_match('/\b(FROM|JOIN|INTO|UPDATE|DELETE\s+FROM)\s+[`"]?' . preg_quote($tbl, '/') . '\b/i', $sql)) {
                $tabelKena = $tbl; break;
            }
        }
        if ($tabelKena === null) continue;

        /* Teks penyusun SQL saja (argumen parameter dipotong) untuk penilaian cakupan. */
        $span = db_audit_sql_span($potongan);

        /* ---- Alasan AMAN yang dikenali (supaya laporan tidak menuduh salah) ---- */
        $aman = '';
        /* 1. Kolom branch_id atau placeholder {BR}/{AND} langsung di SQL. */
        if (preg_match('/\bbranch_id\b/i', $sql)) $aman = 'kolom branch_id pada SQL';
        elseif (preg_match('/\{\{?BR\}?\}|\{AND\}|\{PAT\}/', $span)) $aman = 'placeholder {BR}/{AND}';
        /* 2. Helper cakupan dipanggil di sekitar query. */
        elseif (preg_match('/\b(bscope|branch_sql|scope_branch|assert_branch|sc)\s*\(/', $span)) $aman = 'helper cakupan cabang';
        /* 3. Filter cabang lewat VARIABEL yang membawa pembatas cakupan. */
        elseif (db_audit_text_scoped($span, $varCabang)) $aman = 'filter cabang lewat variabel cakupan';
        /* 4. Filter laporan `{$f['sql']}` (satu sumber filter yang menambahkan branch_id). */
        elseif ($sqlElementTrusted && preg_match('/\[\s*[\'"]sql[\'"]\s*\]/', $span)) {
            $aman = 'filter laporan report_filters() yang menyertakan branch_id';
        }
        /* 5. Penanda eksplisit lintas cabang / koneksi cabang eksplisit. */
        elseif (preg_match('~/\*\s*cross-branch\s*\*/|db_for_branch\s*\(|db_conn_for_record\s*\(|db_scope_check\s*\(~', $potongan)) {
            $aman = 'penanda cross-branch / koneksi cabang eksplisit';
            /* DICATAT (bukan dibuang): operasi lintas cabang tetap terlihat pemilik. */
            $cross[] = ['file' => $rel, 'line' => $no + 1, 'tabel' => $tabelKena,
                'cuplik' => trim(substr(preg_replace('/\s+/', ' ', $sql), 0, 120))];
        }
        /* 6. WAJIB/DELETE per-id yang sudah diverifikasi cabang (assert_branch/scope_branch
              ada di berkas yang sama, dan query dibatasi id/parent-id). */
        elseif (($adaHelper || $adaScope)
            && preg_match('/\b[a-z_]*id\s*=\s*\?/i', $sql)) {
            $aman = 'dibatasi id (induknya sudah diperiksa cabang di berkas ini)';
        }
        /* 7. Daftar id yang sudah tersaring cabang (mis. IN ({$in}) dari query ber-cakupan). */
        elseif (preg_match('/IN\s*\(\{\$\w+\}\)|ORDER BY id/i', $sql) && $adaScope) {
            $aman = 'daftar id hasil query ber-cakupan cabang';
        }
        if ($aman !== '') continue;

        $temuan = [
            'file' => $rel, 'line' => $no + 1,
            'jenis' => (preg_match('/\bINSERT\b/i', $sql) ? 'INSERT'
                : (preg_match('/\bUPDATE\b/i', $sql) ? 'UPDATE'
                : (preg_match('/\bDELETE\b/i', $sql) ? 'DELETE' : 'SELECT'))),
            'tabel' => $tabelKena,
            'alasan' => 'query tabel operasional tanpa pembatasan cabang terdeteksi',
            'cuplik' => trim(substr(preg_replace('/\s+/', ' ', $sql), 0, 150)),
        ];
        if ($alasanAnak !== null) {
            $temuan['alasan'] = $alasanAnak;
            $anak[] = $temuan;
        } else {
            $keluar[] = $temuan;
        }
    }
    return ['hard' => $keluar, 'anak' => $anak, 'cross' => $cross];
}

/**
 * Audit SELURUH aplikasi.
 *
 * @return array{berkas:int,query_diperiksa:int,perlu_diperiksa:array,tabel_terlibat:array,branch_helper:int}
 */
function db_audit_repository(?string $root = null): array
{
    $root = $root ?? APP_DIR;
    $temuan = [];
    $anak = [];
    $cross = [];
    $berkas = 0;
    $queryDiperiksa = 0;
    $helper = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $p = $f->getPathname();
        if (strpos($p, '/assets/vendor/') !== false) continue;
        $rel = substr($p, strlen($root) + 1);
        $berkas++;
        $isi = (string)@file_get_contents($p);
        $queryDiperiksa += (int)preg_match_all('/\b(q|one|all|scalar)(?:_on)?\s*\(/', $isi);
        $helper += (int)preg_match_all('/\b(bscope|branch_sql|scope_branch|assert_branch)\s*\(/', $isi);
        $scan = db_audit_scan_file($p, $rel);
        foreach ($scan['hard'] as $t) $temuan[] = $t;
        foreach ($scan['anak'] as $t) $anak[] = $t;
        foreach ($scan['cross'] as $t) $cross[] = $t;
    }
    $perTabel = [];
    foreach ($temuan as $t) $perTabel[$t['tabel']] = ($perTabel[$t['tabel']] ?? 0) + 1;
    arsort($perTabel);
    return ['berkas' => $berkas, 'query_diperiksa' => $queryDiperiksa,
        'perlu_diperiksa' => $temuan, 'tabel_terlibat' => $perTabel, 'branch_helper' => $helper,
        'anak' => $anak, 'cross_branch' => $cross, 'berkas_anak' => count(db_audit_child_files())];
}

/** Ringkasan singkat untuk ditampilkan di Developer Settings. */
function db_audit_summary_text(array $hasil): string
{
    $n = count($hasil['perlu_diperiksa']);
    $anak = count($hasil['anak'] ?? []);
    $cross = count($hasil['cross_branch'] ?? []);
    $tambahan = ($anak > 0 ? ' · ' . $anak . ' query di berkas anak (cakupan dari induk, '
            . (int)($hasil['berkas_anak'] ?? 0) . ' modul) dicatat terpisah' : '')
        . ($cross > 0 ? ' · ' . $cross . ' operasi bertanda cross-branch dicatat terpisah' : '');
    if ($n === 0) {
        return 'Seluruh query tabel operasional sudah dibatasi cabang ('
            . $hasil['query_diperiksa'] . ' query diperiksa)' . $tambahan . '.';
    }
    return $n . ' query perlu diperiksa (dari ' . $hasil['query_diperiksa'] . ' query): '
        . implode(', ', array_map(fn($k, $v) => $k . ' (' . $v . ')',
            array_keys($hasil['tabel_terlibat']), $hasil['terlibat'] ?? array_values($hasil['tabel_terlibat'])))
        . $tambahan . '.';
}
