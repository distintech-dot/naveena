<?php
/**
 * Schema definition, migration + first-run seed.
 * Everything runs inside ONE explicit transaction (BEGIN IMMEDIATE) so the
 * idempotent statements below never pay a disk fsync each on every request.
 */
declare(strict_types=1);

function ensure_schema(PDO $pdo): void
{
    // Fast path: already migrated.
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
        if ($v === SCHEMA_VERSION) return;
    } catch (Throwable $e) {
        // tables not created yet
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        /* PENTING (anti-balapan): permintaan lain bisa saja sudah menyelesaikan
           migrasi ini saat kita menunggu kunci tulis di BEGIN IMMEDIATE di atas.
           Karena itu versi skema diperiksa ULANG setelah kunci didapat — tanpa
           ini, dua permintaan yang datang bersamaan akan menjalankan migrasi dua
           kali (dulu menyebabkan migrasi penggeser waktu jalan berkali-kali). */
        try {
            $again = (string)$pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
        } catch (Throwable $e) {
            $again = '';   // tabel settings belum ada → database benar-benar baru
        }
        if ($again === SCHEMA_VERSION) {
            $pdo->exec('ROLLBACK');
            return;
        }
        foreach (schema_ddl() as $sql) {
            $pdo->exec($sql);
        }
        run_migrations($pdo);
        seed_core($pdo);
        // Setelah peran & permission ada (seed_core), pastikan semua level —
        // termasuk Kasir — boleh MELIHAT & MENGEDIT rekam medis.
        grant_kasir_medical($pdo);
        ensure_direktur_seed($pdo);
        ensure_staff_seed($pdo);
        seed_icd_dictionary($pdo);
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $pdo->exec('COMMIT');
        /* Migrasi di atas bisa mengubah isi settings (mis. status struk). Cache
           pengaturan bersifat statis, jadi harus disegarkan agar pemanggilan
           berikutnya pada request yang sama membaca nilai terbaru. */
        if (function_exists('settings')) settings(true);
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Pastikan ada contoh data dokter & terapis. Versi awal aplikasi tidak
 * menyertakannya, sehingga reservasi tidak bisa memilih dokter/terapis dan
 * tombol "WA Dokter" tidak pernah muncul. Hanya diisi bila tabelnya KOSONG
 * supaya data yang sudah diisi pengguna tidak pernah ditimpa.
 */
function ensure_staff_seed(PDO $pdo): void
{
    $branches = $pdo->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN);
    if (!$branches) return;
    $docs = [
        ['dr. Ratna Kusuma', '0812-3000-0011', 'Kulit & Estetika', 'Senin–Jumat 10.00–17.00'],
        ['dr. Bagas Prakoso', '0812-3000-0012', 'Dermatologi', 'Selasa–Sabtu 13.00–19.00'],
    ];
    $thes = [
        ['Nia Puspita', '0812-3000-0021', 'Facial & Peeling', 'Senin–Sabtu 09.00–17.00'],
        ['Sari Melati', '0812-3000-0022', 'Body Treatment', 'Senin–Sabtu 10.00–19.00'],
    ];
    if ((int)$pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO doctors (name, phone, specialization, schedule, branch_id, status, created_at)
                             VALUES (?,?,?,?,?,?,datetime("now","localtime"))');
        foreach ($branches as $bId) foreach ($docs as $d) $st->execute([$d[0], $d[1], $d[2], $d[3], (int)$bId, 'active']);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM therapists')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO therapists (name, phone, specialization, schedule, branch_id, status, created_at)
                             VALUES (?,?,?,?,?,?,datetime("now","localtime"))');
        foreach ($branches as $bId) foreach ($thes as $t) $st->execute([$t[0], $t[1], $t[2], $t[3], (int)$bId, 'active']);
    }
}

/**
 * Beri peran Kasir hak melihat & mengedit rekam medis (permintaan klinik: semua
 * level boleh melihat & mengedit rekam medis). Idempoten — aman dipanggil
 * berulang, dan tetap berjalan pada database yang sudah terpasang
 * (dipanggil setiap kali versi skema naik).
 */
function grant_kasir_medical(PDO $pdo): void
{
    foreach (['medical.view', 'medical.manage'] as $code) {
        $pdo->exec("INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id FROM roles r, permissions p
                    WHERE r.code = 'kasir' AND p.code = " . $pdo->quote($code));
    }
}

/**
 * Pastikan role "Direktur / Owner" beserta akun login bawaannya tersedia.
 *
 * Dijalankan SETELAH seed_core() supaya role & permission sudah ada. Aman
 * dijalankan berulang (INSERT OR IGNORE / ON CONFLICT DO NOTHING): pada
 * database yang sudah terpasang, hanya baris yang belum ada yang dibuat.
 * Akun bawaan sebaiknya segera diganti passwordnya lewat Manajemen User.
 */
function ensure_direktur_seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO roles (code, name) VALUES ('direktur', 'Direktur / Owner') ON CONFLICT(code) DO NOTHING");
    $hasRole = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE code = 'direktur'")->fetchColumn();
    if ($hasRole === 0) return;

    /* Permission yang membentuk peran Direktur: seluruh hak operasional &
       manajerial, TANPA hak sistem (maintenance, backup/restore, kelola cabang,
       integrasi sistem). */
    $allowed = ['dashboard.view', 'reservation.view', 'reservation.manage', 'patient.view', 'patient.manage',
        'medical.view', 'medical.manage', 'order.view', 'order.manage', 'order.void', 'payment.manage',
        'inventory.view', 'inventory.manage', 'inventory.delete', 'treatment.view', 'treatment.manage',
        'skincare.view', 'skincare.manage', 'material.view', 'material.manage', 'supplier.manage',
        'report.view', 'report.allbranch', 'export.data', 'staff.manage', 'audit.view',
        'user.manage', 'settings.manage', 'branch.view',
        /* KEUNGAN: laporan HPP & laba bersih hanya untuk Super Admin & Direktur/Owner
           (data internal perusahaan). */
        'finance.view'];
    foreach ($allowed as $code) {
        $pdo->exec("INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id FROM roles r, permissions p
                    WHERE r.code = 'direktur' AND p.code = " . $pdo->quote($code));
    }
    /* Bersihkan hak sistem bila pernah diberikan (mis. sisa percobaan) supaya
       Direktur benar-benar tidak bisa maintenance/backup/kelola cabang. */
    foreach (['maintenance.manage', 'backup.manage', 'branch.manage', 'system.integration'] as $code) {
        $pdo->exec("DELETE FROM role_permissions WHERE role_id = (SELECT id FROM roles WHERE code='direktur')
                    AND permission_id = (SELECT id FROM permissions WHERE code = " . $pdo->quote($code) . ")");
    }

    /* Satu akun bawaan Direktur/Owner — tanpa cabang (cakupan semua cabang). */
    $st = $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, branch_id, phone, status, created_at)
                         VALUES (?,?,?,(SELECT id FROM roles WHERE code = ?),NULL,?,?,datetime("now","localtime"))
                         ON CONFLICT(email) DO NOTHING');
    $st->execute(['Direktur / Owner', 'direktur@naveena.id', password_hash('Direktur#2025', PASSWORD_DEFAULT),
        'direktur', '0812-9000-0002', 'active']);
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        foreach ($pdo->query('PRAGMA table_info("' . $table . '")') as $c) {
            if (strcasecmp((string)$c['name'], $column) === 0) return true;
        }
    } catch (Throwable $e) {
    }
    return false;
}

/** Additive migrations (idempotent, guarded) — runs inside the schema transaction. */
function run_migrations(PDO $pdo): void
{
    $adds = [
        ['medical_records', 'icd10_desc', 'TEXT'],
        ['medical_records', 'icd9_desc', 'TEXT'],
        // jejak pengiriman struk ke WhatsApp
        ['orders', 'receipt_url', 'TEXT'],
        ['orders', 'receipt_sent_at', 'TEXT'],
        ['orders', 'receipt_sent_to', 'TEXT'],
        ['orders', 'receipt_sent_via', 'TEXT'],
        ['orders', 'receipt_status', 'TEXT'],
        // Foto orang (pasien/dokter/terapis) + waktu perubahan untuk cache-busting
        ['patients', 'photo_file', 'TEXT'],
        ['patients', 'photo_updated_at', 'TEXT'],
        ['doctors', 'photo_file', 'TEXT'],
        ['doctors', 'photo_updated_at', 'TEXT'],
        ['therapists', 'photo_file', 'TEXT'],
        ['therapists', 'photo_updated_at', 'TEXT'],
        // keterangan gambar pada rekam medis (dipakai juga utk doc)
        ['medical_record_photos', 'file_size', 'INTEGER'],
        ['medical_record_photos', 'dimensions', 'TEXT'],
        /* KARTU MEMBER: status keanggotaan pasien + jejak diskon member pada transaksi. */
        ['patients', 'member_card', 'INTEGER'],
        ['patients', 'member_level', 'TEXT'],
        ['patients', 'member_level_at', 'TEXT'],
        /* Email pasien (opsional) — dipakai untuk mengirim struk/laporan lewat email. */
        ['patients', 'email', 'TEXT'],
        ['patients', 'member_since', 'TEXT'],
        ['patients', 'member_source', 'TEXT'],
        ['orders', 'member_card', 'INTEGER'],
        ['orders', 'member_pct', 'REAL'],
        ['orders', 'member_discount', 'REAL'],
        ['orders', 'member_tier', 'TEXT'],
        /* Bahan treatment yang dipakai pada sebuah transaksi (opsional).
           Disimpan sebagai baris order_items bertipe 'material' dengan harga &
           subtotal 0 supaya TIDAK pernah ikut perhitungan penjualan/struk,
           tetapi tetap punya jejak pemakaian + pengurangan stok inventory. */
        ['order_items', 'material_id', 'INTEGER'],
        /* Cakupan barang yang mendapat diskon member PADA TRANSAKSI ITU
           (both/treatment/skincare). Diisi dari pilihan kasir di Order Baru —
           setelan `member_discount_scope` hanya menjadi nilai bawaannya. */
        ['orders', 'member_scope', 'TEXT'],
        /* Kode unik transfer/QRIS (3 digit) yang ditambahkan ke total. */
        ['orders', 'unique_code', 'INTEGER'],
        /* KEUANGAN (permintaan pemilik): HPP pada master treatment + snapshot HPP
           pada baris transaksi supaya laba transaksi lama tidak berubah ketika
           HPP master diubah. Produk skincare memakai kolom `purchase_price`
           yang sudah ada (harga beli = HPP produk). */
        ['treatments', 'hpp', 'REAL'],
        ['order_items', 'hpp', 'REAL'],
        /* PAKET: baris transaksi paket menunjuk paketnya (komponen paket dicatat
           sebagai baris terpisah bertipe 'package_item' berharga 0 agar stok &
           jejak pemakaiannya tetap terlihat). */
        ['order_items', 'package_id', 'INTEGER'],
        /* KEAMANAN LOGIN (ronde 38): kunci TOTP (Google Authenticator) per pengguna.
           `totp_enabled` + `totp_confirmed_at` hanya terisi setelah pemilik benar-benar
           memindai QR dan kodenya terbukti benar — jadi 2FA tidak pernah "aktif palsu". */
        ['users', 'totp_secret', 'TEXT'],
        ['users', 'totp_enabled', 'INTEGER'],
        ['users', 'totp_confirmed_at', 'TEXT'],
    ];
    foreach ($adds as [$t, $col, $type]) {
        if (!table_has_column($pdo, $t, $col)) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$col} {$type}");
        }
    }
    /* Permintaan pembayaran (jalur OTOMATIS): transaksi BELUM dibuat sampai
       gateway menyatakan lunas. Muatan transaksi disimpan di sini supaya
       notifikasi gateway (tanpa sesi pengguna) bisa menyelesaikannya.
       Kolom `payload` menyimpan data transaksi, `raw` menyimpan balasan gateway
       apa adanya untuk keperluan penelusuran. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS pay_pending (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT NOT NULL UNIQUE,
        order_payload TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        method TEXT NOT NULL DEFAULT 'QRIS',
        status TEXT NOT NULL DEFAULT 'pending',
        gateway TEXT, gateway_ref TEXT, qr_string TEXT, raw TEXT,
        branch_id INTEGER, user_id INTEGER, order_id INTEGER,
        created_at TEXT DEFAULT (datetime('now','localtime')), expires_at TEXT, paid_at TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pay_pending_ref ON pay_pending(ref)');

    /* Tabel treatment per reservasi (dibuat di sini juga supaya database yang
       sudah terpasang ikut mendapatkannya; CREATE TABLE IF NOT EXISTS aman
       dijalankan berulang). */
    $pdo->exec("CREATE TABLE IF NOT EXISTS appointment_treatments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_id INTEGER NOT NULL,
        treatment_id INTEGER NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_treat ON appointment_treatments(appointment_id)');

    /* PAKET treatment/produk (CREATE TABLE IF NOT EXISTS aman dijalankan berulang;
       juga diperlukan oleh database yang sudah terpasang). */
    $pdo->exec("CREATE TABLE IF NOT EXISTS packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL, name TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'treatment',
        price REAL NOT NULL DEFAULT 0, hpp REAL NOT NULL DEFAULT 0,
        note TEXT,
        branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
        UNIQUE (code, branch_id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS package_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        package_id INTEGER NOT NULL,
        item_type TEXT NOT NULL,
        item_id INTEGER NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        position INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_package_items_pkg ON package_items(package_id)');

    /* BIAYA OPERASIONAL (menu Keuangan — mode "Laporan Lengkap"). Setiap baris
       punya nominal DAN periode pembayaran (1/3/6/12/24/36/60 bulan) sehingga
       biaya tahunan (mis. sewa bangunan) maupun bulanan (gaji, listrik & air,
       marketing, pajak, operasional lain) dapat diisi apa adanya dan sistem
       menghitung porsi untuk periode laporan secara proporsional. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_costs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        period_months INTEGER NOT NULL DEFAULT 1,
        category TEXT,
        note TEXT,
        branch_id INTEGER,
        /* MODE pos biaya (ronde 33). 'branch' = nominal PER CABANG dipakai
           bersama-sama (semua cabang yang terisi ikut dihitung). 'company' =
           hanya nominal cakupan Semua cabang (biaya bersama) yang dipakai dan
           menggantikan isian per cabang untuk pos biaya itu. CATATAN: jangan
           menulis titik-koma di dalam komentar SQL ini — isi sqlite_master
           ditulis apa adanya oleh db_dump() dan pemecah pernyataan backup
           memakainya untuk memulihkan basis data. */
        cost_mode TEXT NOT NULL DEFAULT 'branch',
        sort INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_finance_costs_sort ON finance_costs(sort)');
    /* MODE pos biaya (ronde 33) — WAJIB ditambahkan SETELAH tabelnya dibuat:
       tabel `finance_costs` dibuat di blok ini, sedangkan daftar `$adds` di atas
       dijalankan lebih dulu (kalau diisi di sana, instalasi baru gagal dengan
       "no such table: finance_costs"). */
    if (!table_has_column($pdo, 'finance_costs', 'cost_mode')) {
        $pdo->exec("ALTER TABLE finance_costs ADD COLUMN cost_mode TEXT NOT NULL DEFAULT 'branch'");
    }
    /* NOMINAL PER CAKUPAN (permintaan pemilik, ronde 31): satu pos biaya
       (mis. "Gaji") dapat diisi BEDA untuk tiap cakupan — biaya bersama
       (branch_id = 0 = semua cabang) atau tiap cabang — karena kebutuhan
       operasional tiap cabang berbeda. Kolom `applicable` menandai cakupan
       yang sedang DIPAKAI untuk pos biaya itu; penyimpanan pada cakupan
       "Semua cabang" menonaktifkan isian per cabang (nilainya tetap
       tersimpan dan dapat dipakai kembali kapan saja), sedangkan penyimpanan
       pada cakupan sebuah cabang menonaktifkan isian "semua cabang" untuk
       biaya tersebut (cabang lebih khusus daripada bersama). */
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_cost_amounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cost_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL DEFAULT 0,
        amount REAL NOT NULL DEFAULT 0,
        period_months INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'active',
        applicable INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT,
        UNIQUE (cost_id, branch_id),
        FOREIGN KEY (cost_id) REFERENCES finance_costs(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fc_amounts_cost ON finance_cost_amounts(cost_id)');
    /* Contoh awal supaya pemilik klinik tinggal mengisi nominalnya. */
    if ((int)$pdo->query('SELECT COUNT(*) FROM finance_costs')->fetchColumn() === 0) {
        $seed = [
            ['Gaji', 1, 'Gaji & Tunjangan', 10],
            ['Listrik & Air', 1, 'Utilitas', 20],
            ['Marketing', 1, 'Pemasaran', 30],
            ['Aplikasi', 1, 'Langganan & Perangkat Lunak', 40],
            ['Pajak', 1, 'Pajak', 50],
            ['Operasional Lainnya', 1, 'Lain-lain', 60],
            ['Sewa Bangunan', 12, 'Sewa & Tempat', 70],
        ];
        $ins = $pdo->prepare('INSERT INTO finance_costs (name, amount, period_months, category, sort)
                              VALUES (?, 0, ?, ?, ?)');
        foreach ($seed as $r) $ins->execute([$r[0], $r[1], $r[2], $r[3]]);
    }

    /* Indeks untuk kolom yang baru ditambahkan di atas — WAJIB dijalankan
       setelah kolomnya ada (database lama belum punya material_id). */
    foreach ([
        'idx_order_items_material' => 'order_items(material_id)',
    ] as $idx => $target) {
        $pdo->exec("CREATE INDEX IF NOT EXISTS {$idx} ON {$target}");
    }
    /* CATATAN PENTING (jangan dihidupkan kembali):
       Dulu di sini ada "perbaikan zona waktu sekali saja" yang menggeser SEMUA
       kolom tanda waktu +7 jam, dijaga oleh setelan `tz_shifted`. Blok itu sudah
       lama diterapkan sehingga berkas ini MENGHAPUSNYA: pada database yang
       setelannya hilang (atau terbaca sebelum transaksi lain commit) blok itu
       terjalankan ulang dan menggeser seluruh riwayat klinik 7 jam ke depan —
       ini pernah benar-benar terjadi di produksi (3x jalan = data bergeser 21
       jam) dan harus dipulihkan manual. Instalasi baru tidak butuh blok ini
       (tidak ada baris lama untuk digeser), dan database yang sudah terpasang
       sudah benar. Migrasi satu-kali yang mengubah data pengguna TIDAK boleh
       hidup lagi di jalur otomatis seperti ini. */

    // Koreksi data: baris lama yang tercatat "terkirim" padahal kenyataannya hanya
    // DISIAPKAN lewat deep link wa.me (WhatsApp API belum dipakai) ditandai ulang
    // supaya sistem tidak menampilkan klaim pengiriman yang tidak terjadi.
    $pdo->exec("UPDATE orders SET receipt_status = 'prepared'
                WHERE receipt_status IS NULL AND receipt_sent_at IS NOT NULL
                  AND (receipt_sent_via IS NULL OR receipt_sent_via NOT LIKE '%API%')");
    $pdo->exec("UPDATE orders SET receipt_status = 'sent'
                WHERE receipt_status IS NULL AND receipt_sent_at IS NOT NULL AND receipt_sent_via LIKE '%API%'");

    /* Tabel KEAMANAN LOGIN (ronde 38): "ingat saya", lupa password, kode
       pemulihan, dan kode verifikasi cadangan lewat email. Semua nilai sensitif
       disimpan sebagai HASH. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_remember (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        selector TEXT NOT NULL UNIQUE,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        last_used_at TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_remember_sel ON login_remember(selector)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_hash ON password_resets(token_hash)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS recovery_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recovery_codes_user ON recovery_codes(user_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_2fa_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        purpose TEXT NOT NULL DEFAULT 'email',
        code_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_2fa_user ON login_2fa_codes(user_id)');

    /* TABEL AI DEVELOPER (ronde 41): menyimpan tiap permintaan pengembangan
       (revisi/perbaikan/tambah fitur) beserta RENCANA, PATCH yang diusulkan AI,
       hasil uji di folder staging, dan keadaan penerapan/rollback. Disimpan di
       basis data supaya persetujuan Super Admin & riwayatnya tetap ada. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        request TEXT NOT NULL,
        provider TEXT,
        model TEXT,
        status TEXT NOT NULL DEFAULT 'draft',
        stage TEXT,
        plan TEXT,
        patch TEXT,
        diff TEXT,
        lint TEXT,
        files TEXT,
        suite TEXT,
        test_status TEXT,
        test_log TEXT,
        staging_dir TEXT,
        snapshot_dir TEXT,
        applied_at TEXT,
        rolled_back_at TEXT,
        error TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        updated_at TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_tasks_status ON ai_tasks(status)');

    migrate_finance_cost_amounts($pdo);
    migrate_finance_cost_modes($pdo);
}

/**
 * Tetapkan MODE tiap pos biaya operasional (ronde 33) dan segarkan penanda
 * `applicable`.
 *
 * Latar (bug yang dilaporkan pemilik): pada ronde 31, menyimpan cakupan SEBUAH
 * CABANG mematikan cakupan cabang LAIN, sehingga setelah mengisi cabang 1 lalu
 * cabang 2 hanya cabang terakhir yang dihitung (nominal cabang 1 "hilang" dari
 * perhitungan). Yang benar: isian per cabang dipakai BERSAMA (semua cabang yang
 * terisi dihitung) dan hanya cakupan "Semua cabang" yang menggantikannya.
 *
 * Idempoten: dijalankan tiap kali versi skema naik, hasilnya selalu sama.
 */
function migrate_finance_cost_modes(PDO $pdo): void
{
    try {
        if (!table_has_column($pdo, 'finance_costs', 'cost_mode')) return;
        $costs = $pdo->query('SELECT id FROM finance_costs')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    foreach ($costs as $id) {
        $id = (int)$id;
        $company = $pdo->prepare("SELECT COUNT(*) FROM finance_cost_amounts
            WHERE cost_id = ? AND branch_id = 0 AND COALESCE(amount,0) > 0 AND status = 'active'");
        $company->execute([$id]);
        $hasCompany = (int)$company->fetchColumn() > 0;
        /* Biaya bersama hanya dipakai bila TIDAK ada isian per cabang, ATAU
           isian per cabangnya kosong semua. Bila keduanya terisi, isian per
           cabang yang menang (mode 'branch') karena lebih khusus. */
        $branch = $pdo->prepare("SELECT COUNT(*) FROM finance_cost_amounts
            WHERE cost_id = ? AND branch_id <> 0 AND COALESCE(amount,0) > 0");
        $branch->execute([$id]);
        $hasBranch = (int)$branch->fetchColumn() > 0;
        $mode = ($hasCompany && !$hasBranch) ? 'company' : 'branch';
        $pdo->prepare('UPDATE finance_costs SET cost_mode = ? WHERE id = ?')->execute([$mode, $id]);
        /* Segarkan penanda tampilan: mode 'branch' → SEMUA cabang terisi berlaku. */
        $pdo->prepare('UPDATE finance_cost_amounts SET applicable = 0 WHERE cost_id = ?')->execute([$id]);
        if ($mode === 'company') {
            $pdo->prepare("UPDATE finance_cost_amounts SET applicable = 1
                WHERE cost_id = ? AND branch_id = 0 AND COALESCE(amount,0) > 0 AND status = 'active'")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE finance_cost_amounts SET applicable = 1
                WHERE cost_id = ? AND branch_id <> 0 AND COALESCE(amount,0) > 0 AND status = 'active'")->execute([$id]);
        }
    }
}

/**
 * Pindahkan NILAI biaya operasional lama ke tabel `finance_cost_amounts`
 * (nominal per cakupan). Dijalankan sekali saja: bila tabel nominal sudah
 * berisi baris, tidak ada yang dikerjakan.
 *
 * Alasan (permintaan pemilik, ronde 31): dulu setiap nilai adalah SATU BARIS
 * `finance_costs` — "Gaji" dibuat dua baris (satu per cabang), sehingga daftar
 * biaya menjadi panjang bila cabangnya bertambah. Sekarang satu pos biaya punya
 * satu nominal untuk tiap cakupan (bersama / per cabang).
 *
 * Aturan cakupan yang BERLAKU hasil migrasi: isian per cabang diutamakan
 * (lebih khusus), dan bila tidak ada baris per cabang yang bernilai, baris
 * "Semua cabang" yang berlaku. Nilai yang tidak terpakai TETAP disimpan.
 */
function migrate_finance_cost_amounts(PDO $pdo): void
{
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM finance_cost_amounts')->fetchColumn() > 0) return;
        $rows = $pdo->query('SELECT * FROM finance_costs ORDER BY sort ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;                                    // tabel belum ada → tidak ada yang dimigrasikan
    }
    if (!$rows) return;

    /* Kelompokkan per NAMA: baris bernama sama (mis. "Gaji" per cabang) menjadi
       satu pos biaya dengan beberapa nominal cakupan. */
    $groups = [];
    foreach ($rows as $r) {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', (string)$r['name'])));
        $groups[$key][] = $r;
    }
    $ins = $pdo->prepare('INSERT OR IGNORE INTO finance_cost_amounts
        (cost_id, branch_id, amount, period_months, status, applicable, updated_at)
        VALUES (?,?,?,?,?,0,datetime("now","localtime"))');
    foreach ($groups as $members) {
        $keep = $members[0];                       // baris pertama = pos biaya yang dipertahankan
        $sort = (int)$keep['sort'];
        $cat = (string)($keep['category'] ?? '');
        $note = (string)($keep['note'] ?? '');
        foreach ($members as $m) {
            $sort = min($sort, (int)$m['sort']);
            if ($cat === '' && (string)($m['category'] ?? '') !== '') $cat = (string)$m['category'];
            if ($note === '' && (string)($m['note'] ?? '') !== '') $note = (string)$m['note'];
            $scope = ($m['branch_id'] === null) ? 0 : (int)$m['branch_id'];
            $ins->execute([(int)$keep['id'], $scope, (float)$m['amount'],
                max(1, (int)$m['period_months']),
                ((string)$m['status'] === 'inactive') ? 'inactive' : 'active']);
        }
        /* Rapikan definisi pos biaya yang dipertahankan. */
        $pdo->prepare('UPDATE finance_costs SET sort = ?, category = ?, note = ? WHERE id = ?')
            ->execute([$sort, ($cat !== '' ? $cat : null), ($note !== '' ? $note : null), (int)$keep['id']]);
        /* Hapus baris duplikatnya (nominalnya sudah dipindah di atas). */
        foreach ($members as $i => $m) {
            if ($i === 0) continue;
            $pdo->prepare('DELETE FROM finance_costs WHERE id = ?')->execute([(int)$m['id']]);
        }
    }
    /* Tandai cakupan yang berlaku untuk tiap pos biaya. */
    $ids = array_column($pdo->query('SELECT id FROM finance_costs')->fetchAll(PDO::FETCH_ASSOC), 'id');
    foreach ($ids as $id) {
        $id = (int)$id;
        $pdo->prepare("UPDATE finance_cost_amounts SET applicable = CASE
                WHEN branch_id <> 0 AND COALESCE(amount,0) > 0 AND status = 'active' THEN 1
                WHEN branch_id =  0 AND COALESCE(amount,0) > 0 AND status = 'active' THEN 1
                ELSE 0 END WHERE cost_id = ?")->execute([$id]);
        /* Bila TIDAK ADA baris bernilai: baris "Semua cabang" (atau baris
           pertama) ditandai berlaku supaya pemilik klinik punya titik awal. */
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_cost_amounts WHERE cost_id = ? AND applicable = 1');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() === 0) {
            $st = $pdo->prepare('SELECT id FROM finance_cost_amounts WHERE cost_id = ?
                                 ORDER BY (branch_id = 0) DESC, branch_id ASC, id ASC LIMIT 1');
            $st->execute([$id]);
            $pick = (int)$st->fetchColumn();
            if ($pick > 0) $pdo->prepare('UPDATE finance_cost_amounts SET applicable = 1 WHERE id = ?')->execute([$pick]);
        }
    }
}

/**
 * Load the ICD-10 / ICD-9-CM dictionary from the official source files shipped
 * with the app (data/icd10.tsv, data/icd9cm.tsv). Reloads when the shipped
 * dataset version changes (ICD_DATASET_VERSION).
 */
function seed_icd_dictionary(PDO $pdo, bool $force = false): int
{
    $existing = 0;
    try {
        $existing = (int)$pdo->query('SELECT COUNT(*) FROM icd_codes')->fetchColumn();
        $ver = (string)$pdo->query("SELECT value FROM settings WHERE key = 'icd_dataset_version'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
    if ($existing > 0 && $ver === ICD_DATASET_VERSION && !$force) return 0;
    if ($existing > 0) $pdo->exec('DELETE FROM icd_codes');

    $files = ['icd10' => 'icd10.tsv', 'icd9cm' => 'icd9cm.tsv'];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO icd_codes (kind, code, code_norm, name_en, name_id) VALUES (?,?,?,?,?)');
    $n = 0;
    foreach ($files as $kind => $file) {
        $path = dirname(__DIR__) . '/data/' . $file;
        if (!is_readable($path)) continue;
        $fh = fopen($path, 'r');
        if (!$fh) continue;
        while (($row = fgetcsv($fh, 0, "\t", '"', '\\')) !== false) {
            if (count($row) < 2) continue;
            $code = trim((string)$row[0]);
            if ($code === '') continue;
            $en = trim((string)$row[1]);
            // Kolom ke-3 kosong = belum ada terjemahan resmi -> NULL (jangan diisi
            // dengan teks Inggris supaya asal-usulnya tetap jelas di UI).
            $id = isset($row[2]) ? trim((string)$row[2]) : '';
            $ins->execute([$kind, $code, strtoupper(str_replace('.', '', $code)), $en, ($id !== '' ? $id : null)]);
            $n++;
        }
        fclose($fh);
    }
    $pdo->prepare("INSERT INTO settings (key, value) VALUES ('icd_dataset_version', ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([ICD_DATASET_VERSION]);
    return $n;
}

function schema_ddl(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            address TEXT, phone TEXT, email TEXT, opening_hours TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE, name TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, module TEXT
        )",
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INTEGER NOT NULL, permission_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, permission_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
            role_id INTEGER NOT NULL, branch_id INTEGER,
            phone TEXT, status TEXT NOT NULL DEFAULT 'active',
            last_login TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (role_id) REFERENCES roles(id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_number TEXT NOT NULL UNIQUE, member_number TEXT,
            member_card INTEGER NOT NULL DEFAULT 0, member_since TEXT, member_source TEXT,
            member_level TEXT, member_level_at TEXT, email TEXT,
            name TEXT NOT NULL, gender TEXT, nik TEXT, address TEXT, phone TEXT,
            patient_type TEXT DEFAULT 'Baru', birth_date TEXT, notes TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_by INTEGER,
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS doctors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, phone TEXT, specialization TEXT, schedule TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS therapists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, phone TEXT, specialization TEXT, schedule TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_number TEXT NOT NULL UNIQUE,
            patient_id INTEGER NOT NULL, doctor_id INTEGER, therapist_id INTEGER,
            treatment_id INTEGER, branch_id INTEGER NOT NULL,
            date TEXT NOT NULL, time TEXT, status TEXT NOT NULL DEFAULT 'Menunggu',
            notes TEXT, created_by INTEGER, converted_order_id INTEGER,
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        /* Daftar treatment pada satu reservasi. Sebuah reservasi boleh memuat
           BEBERAPA treatment (treatment pertama juga disalin ke
           appointments.treatment_id demi kompatibilitas fitur lama: pesan WA,
           ekspor, dan detail pasien). */
        "CREATE TABLE IF NOT EXISTS pay_pending (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref TEXT NOT NULL UNIQUE, order_payload TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0, method TEXT NOT NULL DEFAULT 'QRIS',
            status TEXT NOT NULL DEFAULT 'pending',
            gateway TEXT, gateway_ref TEXT, qr_string TEXT, raw TEXT,
            branch_id INTEGER, user_id INTEGER, order_id INTEGER,
            created_at TEXT DEFAULT (datetime('now','localtime')), expires_at TEXT, paid_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS appointment_treatments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            treatment_id INTEGER NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        )",
        "CREATE TABLE IF NOT EXISTS medical_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_number TEXT NOT NULL UNIQUE,
            patient_id INTEGER NOT NULL, doctor_id INTEGER, therapist_id INTEGER,
            staff_name TEXT, branch_id INTEGER NOT NULL, date TEXT NOT NULL,
            subjective TEXT, objective TEXT, diagnosis TEXT, icd10 TEXT,
            action TEXT, icd9 TEXT, solution TEXT,
            status TEXT NOT NULL DEFAULT 'final', amended_from INTEGER,
            created_by INTEGER, updated_by INTEGER,
            locked_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS medical_record_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            medical_record_id INTEGER NOT NULL,
            file_url TEXT NOT NULL, storage TEXT DEFAULT 'remote', local_path TEXT,
            type TEXT DEFAULT 'image', caption TEXT, uploaded_by INTEGER,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (medical_record_id) REFERENCES medical_records(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS treatments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL, name TEXT NOT NULL, category TEXT,
            normal_price REAL NOT NULL DEFAULT 0, promo_price REAL NOT NULL DEFAULT 0,
            /* HPP = harga pokok per satu kali treatment (dipakai perhitungan
               laba di menu Keuangan). */
            hpp REAL NOT NULL DEFAULT 0,
            duration INTEGER DEFAULT 60, branch_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            UNIQUE (code, branch_id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, phone TEXT, address TEXT, email TEXT,
            branch_id INTEGER, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS skincare_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL, name TEXT NOT NULL, category TEXT,
            purchase_price REAL NOT NULL DEFAULT 0, selling_price REAL NOT NULL DEFAULT 0,
            stock REAL NOT NULL DEFAULT 0, minimum_stock REAL NOT NULL DEFAULT 0,
            unit TEXT DEFAULT 'pcs', supplier_id INTEGER, supplier_name TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            UNIQUE (code, branch_id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        /* PAKET (treatment / produk): kumpulan treatment atau produk skincare
           yang dijual sebagai satu paket. `hpp` disimpan di paket dan DIHITUNG
           ULANG dari komponennya (dapat disesuaikan manual). Saat paket dijual,
           stok setiap komponen skincare/bahan ikut berkurang. */
        "CREATE TABLE IF NOT EXISTS packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL, name TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'treatment',
            price REAL NOT NULL DEFAULT 0, hpp REAL NOT NULL DEFAULT 0,
            note TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            UNIQUE (code, branch_id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS package_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER NOT NULL,
            item_type TEXT NOT NULL,
            item_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 1,
            position INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS treatment_materials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL, name TEXT NOT NULL, category TEXT,
            stock REAL NOT NULL DEFAULT 0, minimum_stock REAL NOT NULL DEFAULT 0,
            unit TEXT DEFAULT 'pcs', price REAL NOT NULL DEFAULT 0,
            supplier_id INTEGER, supplier_name TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            UNIQUE (code, branch_id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_type TEXT NOT NULL, item_id INTEGER NOT NULL,
            branch_id INTEGER NOT NULL, stock REAL NOT NULL DEFAULT 0,
            minimum_stock REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'active',
            UNIQUE (item_type, item_id)
        )",
        "CREATE TABLE IF NOT EXISTS inventory_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inventory_id INTEGER, item_type TEXT NOT NULL, item_id INTEGER NOT NULL,
            item_name TEXT, item_code TEXT, type TEXT NOT NULL,
            quantity REAL NOT NULL, stock_before REAL NOT NULL, stock_after REAL NOT NULL,
            reason TEXT, ref_type TEXT, ref_id INTEGER,
            user_id INTEGER, user_name TEXT, branch_id INTEGER NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number TEXT NOT NULL UNIQUE,
            patient_id INTEGER NOT NULL, user_id INTEGER, cashier_name TEXT,
            branch_id INTEGER NOT NULL, appointment_id INTEGER,
            subtotal REAL NOT NULL DEFAULT 0, discount REAL NOT NULL DEFAULT 0,
            member_card INTEGER NOT NULL DEFAULT 0, member_pct REAL NOT NULL DEFAULT 0,
            member_discount REAL NOT NULL DEFAULT 0, member_tier TEXT,
            total REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'paid',
            payment_status TEXT NOT NULL DEFAULT 'paid',
            notes TEXT,
            void_reason TEXT, void_by INTEGER, void_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        /* order_items.hpp = HPP yang BERLAKU SAAT TRANSAKSI (snapshot). Tanpa
           snapshot, mengubah HPP master akan mengubah laba transaksi lama. */
        "CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL, item_type TEXT NOT NULL,
            treatment_id INTEGER, skincare_id INTEGER, material_id INTEGER, package_id INTEGER,
            item_code TEXT, item_name TEXT,
            quantity REAL NOT NULL DEFAULT 1, price REAL NOT NULL DEFAULT 0,
            subtotal REAL NOT NULL DEFAULT 0,
            /* HPP per unit SAAT TRANSAKSI (snapshot): dari treatments.hpp untuk
               treatment dan skincare_products.purchase_price untuk produk.
               Tanpa snapshot, mengubah HPP master ikut mengubah laba transaksi
               lama; baris lama bernilai 0 dan dihitung dari master sebagai cadangan. */
            hpp REAL NOT NULL DEFAULT 0,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL, method TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'valid',
            ref_no TEXT, notes TEXT, paid_at TEXT DEFAULT (datetime('now','localtime')),
            created_by INTEGER, created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER, user_name TEXT, user_role TEXT, branch_id INTEGER,
            action TEXT NOT NULL, module TEXT NOT NULL, record_id TEXT,
            old_value TEXT, new_value TEXT, reason TEXT, ip_address TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS email_report_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient TEXT, period TEXT, status TEXT, message TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT, size INTEGER, note TEXT, created_by INTEGER,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY, value TEXT
        )",
        // Riwayat import data dari file Excel/CSV.
        "CREATE TABLE IF NOT EXISTS import_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL, filename TEXT, file_type TEXT,
            branch_id INTEGER, user_id INTEGER, user_name TEXT,
            options TEXT,
            total INTEGER NOT NULL DEFAULT 0,
            success INTEGER NOT NULL DEFAULT 0,
            updated INTEGER NOT NULL DEFAULT 0,
            failed INTEGER NOT NULL DEFAULT 0,
            warnings INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )",
        "CREATE TABLE IF NOT EXISTS import_errors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            row_no INTEGER, severity TEXT NOT NULL DEFAULT 'error',
            message TEXT, raw TEXT,
            FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE
        )",
        "CREATE INDEX IF NOT EXISTS idx_import_errors_batch ON import_errors(batch_id)",
        "CREATE INDEX IF NOT EXISTS idx_import_batches_date ON import_batches(created_at)",
        // Kamus ICD (ICD-10 diagnosis + ICD-9-CM tindakan).
        // Diisi dari data/icd10.tsv & data/icd9cm.tsv (sumber resmi, lihat README data).
        "CREATE TABLE IF NOT EXISTS icd_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            code TEXT NOT NULL,
            code_norm TEXT NOT NULL,
            name_en TEXT NOT NULL,
            name_id TEXT,
            UNIQUE (kind, code)
        )",
        "CREATE INDEX IF NOT EXISTS idx_icd_kind_code ON icd_codes(kind, code_norm)",
        "CREATE INDEX IF NOT EXISTS idx_icd_kind_name ON icd_codes(kind, name_id)",
        // Indexes (reporting / filtering performance)
        "CREATE INDEX IF NOT EXISTS idx_orders_branch_date ON orders(branch_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_orders_patient ON orders(patient_id)",
        "CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)",
        "CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id)",
        "CREATE INDEX IF NOT EXISTS idx_order_items_treatment ON order_items(treatment_id)",
        "CREATE INDEX IF NOT EXISTS idx_order_items_skincare ON order_items(skincare_id)",
        /* CATATAN: indeks untuk kolom baru (mis. material_id) dibuat di
           run_migrations() SETELAH kolomnya ditambahkan — kalau ditulis di sini,
           CREATE INDEX akan gagal pada database lama yang belum punya kolomnya. */
        "CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(order_id)",
        "CREATE INDEX IF NOT EXISTS idx_patients_branch ON patients(branch_id)",
        "CREATE INDEX IF NOT EXISTS idx_patients_name ON patients(name)",
        "CREATE INDEX IF NOT EXISTS idx_patients_nik ON patients(nik)",
        "CREATE INDEX IF NOT EXISTS idx_patients_phone ON patients(phone)",
        "CREATE INDEX IF NOT EXISTS idx_movements_branch_date ON inventory_movements(branch_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_movements_item ON inventory_movements(item_type, item_id)",
        "CREATE INDEX IF NOT EXISTS idx_audit_date ON audit_logs(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_appointments_branch_date ON appointments(branch_id, date)",
        "CREATE INDEX IF NOT EXISTS idx_medical_branch_date ON medical_records(branch_id, date)",
    ];
}

function seed_core(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();

    // ---- Roles -------------------------------------------------------
    $roles = [
        ['super_admin',  'Super Admin'],
        ['direktur',     'Direktur / Owner'],
        ['admin_dokter', 'Admin / Dokter'],
        ['kasir',        'Kasir'],
    ];
    $st = $pdo->prepare('INSERT INTO roles (code, name) VALUES (?,?) ON CONFLICT(code) DO NOTHING');
    foreach ($roles as $r) $st->execute($r);
    $role_id = [];
    foreach ($pdo->query('SELECT id, code FROM roles') as $r) $role_id[$r['code']] = (int)$r['id'];

    // ---- Permissions -------------------------------------------------
    $perms = [
        ['dashboard.view', 'Lihat Dashboard', 'Dashboard'],
        ['reservation.view', 'Lihat Reservasi', 'Reservasi'],
        ['reservation.manage', 'Kelola Reservasi', 'Reservasi'],
        ['patient.view', 'Lihat Data Pasien', 'Pasien'],
        ['patient.manage', 'Kelola Data Pasien', 'Pasien'],
        ['medical.view', 'Lihat Rekam Medis', 'Rekam Medis'],
        ['medical.manage', 'Kelola Rekam Medis', 'Rekam Medis'],
        ['order.view', 'Lihat Order / Transaksi', 'Kasir'],
        ['order.manage', 'Buat Order / Transaksi', 'Kasir'],
        ['order.void', 'Void / Refund Transaksi', 'Kasir'],
        ['payment.manage', 'Kelola Pembayaran', 'Kasir'],
        ['inventory.view', 'Lihat Inventory', 'Inventory'],
        ['inventory.manage', 'Kelola Inventory & Stok', 'Inventory'],
        ['inventory.delete', 'Hapus Inventory', 'Inventory'],
        ['treatment.view', 'Lihat Master Treatment', 'Master Data'],
        ['treatment.manage', 'Kelola Master Treatment', 'Master Data'],
        ['skincare.view', 'Lihat Master Skincare', 'Master Data'],
        ['skincare.manage', 'Kelola Master Skincare', 'Master Data'],
        ['material.view', 'Lihat Bahan Treatment', 'Master Data'],
        ['material.manage', 'Kelola Bahan Treatment', 'Master Data'],
        ['supplier.manage', 'Kelola Supplier', 'Master Data'],
        ['report.view', 'Lihat Laporan', 'Laporan'],
        ['finance.view', 'Lihat Keuangan (HPP, Laba Kotor & Laba Bersih)', 'Keuangan'],
        ['report.allbranch', 'Laporan Semua Cabang', 'Laporan'],
        ['export.data', 'Export Data (PDF/Excel/CSV)', 'Laporan'],
        ['user.manage', 'Manajemen User', 'Pengaturan'],
        ['branch.manage', 'Manajemen Cabang (tambah/ubah/hapus)', 'Pengaturan'],
        ['branch.view', 'Lihat Data Cabang (baca-saja)', 'Pengaturan'],
        ['staff.manage', 'Manajemen Dokter/Terapis/Kasir', 'Pengaturan'],
        ['audit.view', 'Lihat Audit Log', 'Pengaturan'],
        ['settings.manage', 'Pengaturan Sistem', 'Pengaturan'],
        ['backup.manage', 'Backup & Restore Database', 'Pengaturan'],
        ['maintenance.manage', 'Mode Pemeliharaan (Maintenance)', 'Pengaturan'],
        ['system.integration', 'Integrasi Sistem (Satu Sehat & Kamus ICD)', 'Pengaturan'],
    ];
    $st = $pdo->prepare('INSERT INTO permissions (code, name, module) VALUES (?,?,?) ON CONFLICT(code) DO NOTHING');
    foreach ($perms as $p) $st->execute($p);
    $pid = [];
    foreach ($pdo->query('SELECT id, code FROM permissions') as $p) $pid[$p['code']] = (int)$p['id'];

    $grants = [
        'super_admin'  => array_keys($pid),
        /* Direktur / Owner: level manajemen di bawah Super Admin.
           Semua perluasan hak operasional & manajerial (laporan semua cabang,
           manajemen user, pengaturan sistem, melihat cabang) TETAPI tanpa hak
           sistem yang merusak/berisiko: maintenance, backup/restore, dan
           tambah/ubah/hapus cabang tetap khusus Super Admin. */
        'direktur'     => ['dashboard.view', 'reservation.view', 'reservation.manage', 'patient.view', 'patient.manage',
            'medical.view', 'medical.manage', 'order.view', 'order.manage', 'order.void', 'payment.manage',
            'inventory.view', 'inventory.manage', 'inventory.delete', 'treatment.view', 'treatment.manage',
            'skincare.view', 'skincare.manage', 'material.view', 'material.manage', 'supplier.manage',
            'report.view', 'report.allbranch', 'export.data', 'staff.manage', 'audit.view',
            'user.manage', 'settings.manage', 'branch.view'],
        'admin_dokter' => ['dashboard.view', 'reservation.view', 'reservation.manage', 'patient.view', 'patient.manage',
            'medical.view', 'medical.manage', 'order.view', 'order.manage', 'payment.manage',
            'inventory.view', 'inventory.manage', 'inventory.delete', 'treatment.view', 'treatment.manage',
            'skincare.view', 'skincare.manage', 'material.view', 'material.manage', 'supplier.manage',
            'report.view', 'export.data', 'staff.manage', 'audit.view'],
        'kasir'        => ['dashboard.view', 'reservation.view', 'reservation.manage', 'patient.view', 'patient.manage',
            'order.view', 'order.manage', 'payment.manage', 'inventory.view', 'inventory.manage', 'inventory.delete',
            'treatment.view', 'skincare.view', 'skincare.manage', 'material.view', 'material.manage', 'supplier.manage'],
    ];
    $st = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?) ON CONFLICT DO NOTHING');
    foreach ($grants as $rc => $codes) {
        foreach ($codes as $code) {
            if (isset($pid[$code])) $st->execute([$role_id[$rc], $pid[$code]]);
        }
    }

    // ---- Settings defaults ------------------------------------------
    $defaults = [
        'schema_version'      => SCHEMA_VERSION,
        'company_name'        => 'Naveena Skincare',
        'company_tagline'     => 'Klinik Kecantikan & Skincare',
        'company_address'     => 'Jl. Raya Kaliwungu, Kendal',
        'company_phone'       => '0812-0000-0000',
        'company_email'       => 'info@naveenaskincare.id',
        'receipt_footer'      => 'Terima kasih telah mempercayakan perawatan kulit Anda kepada Naveena Skincare.',
        'default_per_page'    => '25',
        'email_service_active' => '0',
        'email_recipient'     => 'superadmin@naveena.id',
        'email_sender'        => '',
        'email_schedule_day'  => '1',
        'email_schedule_time' => '08:00',
        'smtp_host'           => '',
        'smtp_port'           => '587',
        'smtp_secure'         => 'tls',
        'smtp_user'           => '',
        'smtp_pass'           => '',
        'wa_api_active'       => '0',
        'wa_api_url'          => '',
        'wa_api_token'        => '',
        'wa_api_sender'       => '',
        'wa_template'         => "Halo Kak {nama}\n\nKami dari {klinik} {cabang}.\nMengingatkan reservasi Kakak:\nTanggal: {tanggal}\nJam: {jam}\nTreatment: {treatment}\nDokter/Terapis: {dokter}\n\nMohon konfirmasi kehadirannya ya\nTerima kasih ❤️",
        'backup_active'       => '0',
        'backup_schedule'     => 'harian',
        /* Backup otomatis tanpa cron: penanda periode terakhir yang SUDAH dibuat
           (harian=YYYY-MM-DD, mingguan=YYYY-Www, bulanan=YYYY-MM) + info terakhir. */
        'backup_last_period'  => '',
        'backup_last_at'      => '',
        'backup_last_file'    => '',
        'backup_last_error'   => '',
        'backup_keep'         => '7',            // jumlah backup otomatis yang disimpan
        // Integrasi Satu Sehat (Kemenkes). Status apa adanya: selama kredensial
        // belum diisi, sistem menampilkan "belum dikonfigurasi" dan tidak
        // mengirim/mengklaim apa pun. Verifikasi kode ICD berjalan lokal
        // memakai kamus resmi (tabel icd_codes).
        // Email: jalur HTTPS API (server memblokir port SMTP)
        'email_mode'           => 'auto',
        'email_api_provider'   => 'resend',
        'email_api_url'        => '',
        'email_api_key'        => '',
        'email_api_domain'     => '',
        'email_sender_name'    => 'Naveena Skincare',
        'email_reply_to'       => 'distintech@gmail.com',
        'email_schedule_mode'  => 'auto',
        // Kompresi foto unggahan (px maksimum & mutu JPEG)
        'photo_max_patient'    => '480',
        'photo_max_staff'      => '480',
        'photo_max_medical'    => '1400',
        'photo_max_logo'       => '800',
        'photo_quality'        => '80',
        'photo_target_kb'      => '0',
        /* ---- Kartu Member & diskon otomatis (dapat diubah di Pengaturan) ----
           Tier diskon: makin besar nilai transaksi, makin besar potongannya.
           Disimpan sebagai JSON supaya jumlah tier bebas diubah dari Pengaturan. */
        'member_card_active'    => '1',
        /* Satu transaksi ≥ nilai ini → kartu langsung aktif (level awal) + diskon berlaku. */
        'member_activate_amount' => '1000000',
        /* Nilai transaksi minimum agar diskon member berlaku. */
        'member_min_transaction' => '100000',
        /* Cakupan barang yang mendapat diskon: both | treatment | skincare */
        'member_discount_scope'  => 'both',
        /* Level member: key, label, pct (diskon), min_year (akumulasi per PERIODE
           — lihat `member_period_years`, 1/3/5 tahun atau 0 = tanpa periode).
           Ambang ini sekaligus jalur aktivasi kartu bagi pasien yang belum punya. */
        'member_levels'         => '[{"key":"silver","label":"Member Silver","pct":5,"min_year":5000000},'
            . '{"key":"gold","label":"Member Gold","pct":7.5,"min_year":10000000},'
            . '{"key":"platinum","label":"Member Platinum","pct":10,"min_year":20000000},'
            . '{"key":"diamond","label":"Member Diamond","pct":15,"min_year":40000000},'
            . '{"key":"vvip","label":"Member VVIP","pct":20,"min_year":60000000}]',
        /* PERIODE AKUMULASI MEMBER (permintaan pemilik klinik):
           `member_period_years` = 1 / 3 / 5 tahun, atau 0 = tanpa peresetan.
           Reset berlaku BERSAMA semua member dan jatuh pada 1 Januari tahun batas
           periode; saat reset, level turun `member_downgrade_steps` tingkat (0-3).
           `member_period_anchor_year` = tahun jangkar penghitungan batas periode
           (dipasang otomatis saat pertama kali diatur), `member_period_applied` =
           tahun batas periode yang penurunannya SUDAH dijalankan (penjaga agar
           reset hanya sekali per periode). */
        'member_period_years'    => '1',
        'member_downgrade_steps' => '0',
        'member_period_anchor_year' => '',
        'member_period_applied'  => '',
        /* Kompatibilitas mundur: dibaca hanya bila `member_levels` kosong. */
        'member_card_auto_amount' => '5000000',
        'member_card_price'     => '0',
        'member_card_note'      => 'Kartu member ini dapat digunakan di semua cabang Naveena Skincare dan tidak dapat dipindahtangankan.',
        'member_card_bg_file'   => '',
        /* KEUANGAN: mode laporan (dasar = omzet − HPP; lengkap = + biaya
           operasional) dan daftar biaya operasionalnya ada di tabel
           `finance_costs` (dapat ditambah/diganti nama/diisi nominal & periode). */
        'finance_mode'          => 'dasar',
        /* Tinggi maksimal tabel "Perhitungan ..." pada menu Keuangan (px) — isinya
           digulir ke atas/bawah bila melebihi batas ini; 0 = tanpa batas. Bawaan
           1240 px = tinggi tampilan saat ini, jadi tidak ada yang berubah dulu. */
        'finance_calc_max_px'   => '1240',
        /* Peran yang AKUN BAWAAN-nya ditampilkan di halaman login (ronde 37).
           Kosong = semua peran. Diatur di Developer Settings → Pengaturan Umum. */
        'login_hint_roles'      => 'super_admin,direktur,admin_dokter,kasir',
        /* Ukuran tampilan (%) — pengganti kebiasaan menekan Ctrl+− pada peramban.
           Bawaan 80% karena tampilan 100% terasa terlalu besar di PC/laptop. */
        'ui_scale'              => '80',
        /* ---- AI DEVELOPER (ronde 41) ----
           Penyedia AI untuk membantu revisi/perbaikan/penambahan fitur.
           Bawaan 'gemini' karena kunci Gemini sudah tersedia di produksi.
           `ai_scope` = folder yang boleh dibaca/diubah AI (dipisah koma):
           'naveena' (aplikasi) dan 'naveena_dev/test' (skrip uji). */
        'ai_enabled'            => '1',
        'ai_provider'           => 'gemini',
        'ai_api_key'            => '',
        'ai_model'              => 'gemini-flash-latest',
        'ai_scope'              => 'naveena,naveena_dev/test',
        'ai_max_files'          => '12',
        'ai_max_file_kb'        => '120',
        /* Nama suite WAJIB sama persis dengan yang ada di run_all.sh
           (mis. "sintaks-js", bukan "sintaks" — dulu salah sehingga pilihan
           bawaan tidak ada di daftar). */
        'ai_default_suite'      => 'sintaks-js',
        'ai_mock_reply'         => '',
        /* KEAMANAN LOGIN (ronde 38): lingkup wajib 2FA, durasi "ingat saya"
           (1/3/7 hari), dan batas tidak aktif tanpa "ingat saya" (1/3/5/8 jam). */
        'login_security_enabled' => '1',
        'second_factor_levels'   => 'super_admin',
        'remember_days'          => '1',
        'idle_hours'             => '3',
        /* Email: subjek & pembuka yang dipakai saat mengirim struk lewat email. */
        'email_receipt_subject' => 'Struk {invoice} — {klinik}',
        'email_receipt_body'    => '',
        /* Kirim struk otomatis begitu kasir menyimpan transaksi (hanya berjalan
           bila layanan email sudah dikonfigurasi & pasien punya email). */
        'email_receipt_auto'    => '0',
        /* Masa berlaku tautan unduh struk PDF yang disertakan pada email. */
        'receipt_link_days'     => '30',
        /* Kunci tanda tangan tautan struk (diisi otomatis saat pertama dipakai). */
        'receipt_link_secret'   => '',
        /* ---- PEMBAYARAN ---- */
        'pay_gateway'          => 'none',        // none | midtrans | xendit
        'pay_gateway_env'      => 'sandbox',     // sandbox | production
        'pay_gateway_base_url' => '',            // URL API kustom (opsional)
        'pay_gateway_server_key' => '',          // Midtrans server key / Xendit secret key
        'pay_gateway_client_key' => '',          // Midtrans client key (opsional)
        'pay_gateway_merchant_id' => '',         // Midtrans merchant id (opsional)
        'pay_bank_name'        => '',
        'pay_bank_account'     => '',
        'pay_bank_holder'      => '',
        'pay_qris_file'        => '',            // gambar QRIS statis klinik
        'pay_note'             => 'Mohon kirim bukti transfer setelah melakukan pembayaran.',
        'pay_unique_code'      => '1',           // tambah 3 digit kode unik
        'pay_expire_minutes'   => '15',
        /* Alamat publik aplikasi (opsional) — hanya dipakai bila tautan perlu
           dipaksa; kalau kosong sistem memakai header platform (lihat
           app_public_base() di includes/mailer.php). */
        'public_base_url'       => '',
        'satu_sehat_active'    => '0',
        'satu_sehat_env'       => 'sandbox',
        'satu_sehat_org_id'    => '',
        'satu_sehat_client_id' => '',
        'satu_sehat_client_secret' => '',
        'satu_sehat_token_url' => 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1/accesstoken?grant_type=client_credentials',
        'satu_sehat_fhir_url'  => 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1',
        'icd_source_note'      => 'ICD-10: WHO ICD-10 (2019) + terjemahan Indonesia · ICD-9-CM: CMS Volume 3 (prosedur)',
        // Pengiriman struk ke WhatsApp
        'wa_sender_number'     => '',
        'wa_receipt_link'      => '1',
        'wa_doctor_active'     => '1',
        /* Nama klinik pada template memakai VARIABEL {klinik} (bukan teks tetap)
           supaya perubahan nama klinik langsung ikut terpakai di pesan WhatsApp —
           lihat wa_doctor_message()/wa_reservation_message()/wa_receipt_template(). */
        'wa_template_doctor'   => "Selamat pagi/siang Dokter {dokter},\n\nPengingat jadwal praktik di {klinik} {cabang}:\nTanggal: {tanggal}\nJam: {jam}\nPasien: {nama}\nTreatment: {treatment}\n\nMohon konfirmasi ketersediaannya. Terima kasih.",
        'wa_receipt_template'  => "Halo Kak {nama} 🙏\n\nTerima kasih telah melakukan perawatan di {klinik} {cabang}.\n\nRincian transaksi Kakak:\nNo. Invoice: {invoice}\nTanggal: {tanggal}\nTotal: {total}\nMetode: {metode}\n\nStruk digital: {link}\n\nSalam sehat,\n{klinik} {cabang}",
    ];
    $st = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT(key) DO NOTHING');
    foreach ($defaults as $k => $v) $st->execute([$k, (string)$v]);

    if ($count > 0) return; // already seeded operational data

    // ---- Branches ----------------------------------------------------
    $branch_rows = [
        ['KW', 'Naveena Skincare Kaliwungu', 'Jl. Raya Kaliwungu No. 12, Kaliwungu, Kendal', '0812-1000-0001', 'kaliwungu@naveenaskincare.id', 'Senin–Sabtu 09.00–20.00'],
        ['CP', 'Naveena Skincare Cepiring',  'Jl. Raya Cepiring No. 45, Cepiring, Kendal',    '0812-1000-0002', 'cepiring@naveenaskincare.id',  'Senin–Sabtu 09.00–20.00'],
        ['BT', 'Naveena Skincare Batang',    'Jl. Ahmad Yani No. 8, Batang, Jawa Tengah',    '0812-1000-0003', 'batang@naveenaskincare.id',    'Senin–Sabtu 09.00–20.00'],
    ];
    $st = $pdo->prepare('INSERT INTO branches (code, name, address, phone, email, opening_hours) VALUES (?,?,?,?,?,?)');
    foreach ($branch_rows as $b) $st->execute($b);
    $bid = [];
    foreach ($pdo->query('SELECT id, code FROM branches') as $b) $bid[$b['code']] = (int)$b['id'];

    // ---- Users (one login per level, per branch) ---------------------
    $users = [
        ['Super Admin', 'superadmin@naveena.id', 'SuperAdmin#2025', 'super_admin', null, '0812-9000-0001'],
        ['Direktur / Owner', 'direktur@naveena.id', 'Direktur#2025', 'direktur', null, '0812-9000-0002'],
        ['Admin / Dokter Kaliwungu', 'admin.kaliwungu@naveena.id', 'Admin#2025', 'admin_dokter', $bid['KW'], '0812-9000-0011'],
        ['Admin / Dokter Cepiring',  'admin.cepiring@naveena.id',  'Admin#2025', 'admin_dokter', $bid['CP'], '0812-9000-0012'],
        ['Admin / Dokter Batang',    'admin.batang@naveena.id',    'Admin#2025', 'admin_dokter', $bid['BT'], '0812-9000-0013'],
        ['Kasir Kaliwungu', 'kasir.kaliwungu@naveena.id', 'Kasir#2025', 'kasir', $bid['KW'], '0812-9000-0021'],
        ['Kasir Cepiring',  'kasir.cepiring@naveena.id',  'Kasir#2025', 'kasir', $bid['CP'], '0812-9000-0022'],
        ['Kasir Batang',    'kasir.batang@naveena.id',    'Kasir#2025', 'kasir', $bid['BT'], '0812-9000-0023'],
    ];
    $st = $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, branch_id, phone) VALUES (?,?,?,?,?,?) ON CONFLICT(email) DO NOTHING');
    foreach ($users as $u) {
        $st->execute([$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT), $role_id[$u[3]], $u[4], $u[5]]);
    }
    $uid = [];
    foreach ($pdo->query('SELECT id, email FROM users') as $u) $uid[$u['email']] = (int)$u['id'];

    // ---- Dokter & Terapis (contoh awal, boleh diedit/dinonaktifkan) -----
    $doctors = [
        ['dr. Ratna Kusuma', '0812-3000-0011', 'Kulit & Estetika', 'Senin–Jumat 10.00–17.00'],
        ['dr. Bagas Prakoso', '0812-3000-0012', 'Dermatologi', 'Selasa–Sabtu 13.00–19.00'],
    ];
    $therapists = [
        ['Nia Puspita', '0812-3000-0021', 'Facial & Peeling', 'Senin–Sabtu 09.00–17.00'],
        ['Sari Melati', '0812-3000-0022', 'Body Treatment', 'Senin–Sabtu 10.00–19.00'],
    ];
    $stDoc = $pdo->prepare('INSERT INTO doctors (name, phone, specialization, schedule, branch_id, status, created_at) VALUES (?,?,?,?,?,?,datetime("now","localtime"))');
    $stThe = $pdo->prepare('INSERT INTO therapists (name, phone, specialization, schedule, branch_id, status, created_at) VALUES (?,?,?,?,?,?,datetime("now","localtime"))');
    foreach ($bid as $b_id) {
        foreach ($doctors as $d) $stDoc->execute([$d[0], $d[1], $d[2], $d[3], $b_id, 'active']);
        foreach ($therapists as $t) $stThe->execute([$t[0], $t[1], $t[2], $t[3], $b_id, 'active']);
    }

    // ---- Suppliers ------------------------------------------------
    $st = $pdo->prepare('INSERT INTO suppliers (name, phone, address, branch_id) VALUES (?,?,?,?)');
    foreach ([['PT Beauty Supply Indonesia', '021-5550100', 'Jakarta'], ['CV Glow Distribusi', '024-7770200', 'Semarang'], ['PT Dermacare Jaya', '021-5550300', 'Tangerang']] as $s) {
        foreach ($bid as $bc => $b_id) $st->execute([$s[0], $s[1], $s[2], $b_id]);
    }

    // ---- Starter master data per branch (boleh diedit / dihapus) ----
    $treatments = [
        ['TR-FAC-01', 'Facial Glow Basic', 'Facial', 150000, 0, 60],
        ['TR-FAC-02', 'Facial Brightening Premium', 'Facial', 250000, 199000, 75],
        ['TR-ACN-01', 'Acne Care Treatment', 'Acne', 200000, 0, 60],
        ['TR-ACN-02', 'Acne Peeling', 'Acne', 275000, 0, 45],
        ['TR-ANT-01', 'Anti Aging Lift', 'Anti Aging', 350000, 299000, 90],
        ['TR-LAS-01', 'Laser Rejuvenation', 'Laser', 500000, 0, 45],
        ['TR-BOD-01', 'Body Whitening Treatment', 'Body', 300000, 0, 60],
    ];
    $st = $pdo->prepare('INSERT INTO treatments (code, name, category, normal_price, promo_price, duration, branch_id, status) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(code, branch_id) DO NOTHING');
    foreach ($bid as $b_id) {
        foreach ($treatments as $t) $st->execute([$t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $b_id, 'active']);
    }

    $skincare = [
        ['SK-GLS-01', 'Glass Skin Serum 30ml', 'Serum', 85000, 150000, 10, 3, 'pcs', 'PT Beauty Supply Indonesia'],
        ['SK-SUN-01', 'Sunscreen SPF 50 PA++++', 'Sunscreen', 60000, 110000, 12, 5, 'pcs', 'PT Beauty Supply Indonesia'],
        ['SK-FAC-01', 'Facial Wash Gentle Cleanser', 'Cleanser', 35000, 65000, 15, 5, 'pcs', 'CV Glow Distribusi'],
        ['SK-TON-01', 'Toner Brightening', 'Toner', 45000, 85000, 12, 4, 'pcs', 'CV Glow Distribusi'],
        ['SK-MOI-01', 'Moisturizer Hydrating', 'Moisturizer', 55000, 95000, 10, 4, 'pcs', 'PT Dermacare Jaya'],
        ['SK-ACN-01', 'Acne Spot Cream', 'Treatment Cream', 40000, 75000, 8, 3, 'pcs', 'PT Dermacare Jaya'],
    ];
    $st = $pdo->prepare('INSERT INTO skincare_products (code, name, category, purchase_price, selling_price, stock, minimum_stock, unit, supplier_name, branch_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(code, branch_id) DO NOTHING');
    foreach ($bid as $b_id) {
        foreach ($skincare as $s) $st->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $s[8], $b_id, 'active']);
    }

    $materials = [
        ['BH-CLN-01', 'Cleansing Milk Base', 'Cleanser', 20, 5, 'liter', 120000, 'PT Beauty Supply Indonesia'],
        ['BH-SER-01', 'Serum Base Glow', 'Serum', 15, 5, 'liter', 250000, 'PT Dermacare Jaya'],
        ['BH-PEE-01', 'Peeling Solution AHA 20%', 'Peeling', 10, 3, 'liter', 300000, 'PT Dermacare Jaya'],
        ['BH-MAS-01', 'Mask Powder Brightening', 'Mask', 18, 5, 'kg', 180000, 'CV Glow Distribusi'],
        ['BH-LAS-01', 'Laser Gel Conductor', 'Laser', 12, 4, 'liter', 150000, 'PT Beauty Supply Indonesia'],
    ];
    $st = $pdo->prepare('INSERT INTO treatment_materials (code, name, category, stock, minimum_stock, unit, price, supplier_name, branch_id, status) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(code, branch_id) DO NOTHING');
    foreach ($bid as $b_id) {
        foreach ($materials as $m) $st->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $b_id, 'active']);
    }

    // ---- Inventory mirror rows + opening stock movements -------------
    $sst = $pdo->prepare('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status) VALUES (?,?,?,?,?,?)');
    $mst = $pdo->prepare('INSERT INTO inventory_movements (inventory_id, item_type, item_id, item_name, item_code, type, quantity, stock_before, stock_after, reason, user_id, user_name, branch_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([['skincare', 'skincare_products', $uid['superadmin@naveena.id'] ?? null], ['material', 'treatment_materials', $uid['superadmin@naveena.id'] ?? null]] as $cfg) {
        foreach ($pdo->query("SELECT * FROM {$cfg[1]}") as $row) {
            $sst->execute([$cfg[0], (int)$row['id'], (int)$row['branch_id'], (float)$row['stock'], (float)$row['minimum_stock'], 'active']);
            $invId = (int)$pdo->lastInsertId();
            $mst->execute([$invId, $cfg[0], (int)$row['id'], $row['name'], $row['code'], 'Stok Awal',
                (float)$row['stock'], 0, (float)$row['stock'], 'Stok awal saat pembuatan sistem', $cfg[2], 'Super Admin', (int)$row['branch_id']]);
        }
    }
}
