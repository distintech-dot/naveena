<?php
/**
 * Schema definition, migration + first-run seed.
 * Everything runs inside ONE explicit transaction (BEGIN IMMEDIATE) so the
 * idempotent statements below never pay a disk fsync each on every request.
 */
declare(strict_types=1);

/**
 * Ruang lingkup skema yang sedang dibangun: 'central' (bawaan) atau 'branch'.
 *
 * Berkas CABANG memakai DDL yang klausa foreign key ke tabel GLOBAL-nya dibuang
 * (SQLite tidak mendukung FK antar berkas; klausa itu justru menolak setiap
 * insert — lihat db_route_strip_cross_fk()). Selain itu berkas cabang juga TIDAK
 * ditanami data contoh (seed_core) karena data contoh itu milik central.
 */
function db_schema_scope(): string
{
    return $GLOBALS['DB_SCHEMA_SCOPE'] ?? 'central';
}
/**
 * Id CABANG yang sedang dibangun skemanya (dipakai seed data contoh operasional).
 * Diisi db_branch_create()/db_route_ensure_branch_schema() sebelum skema diterapkan.
 */
function db_schema_branch_id(): int
{
    return (int)($GLOBALS['DB_SCHEMA_BRANCH_ID'] ?? 0);
}

/**
 * Apakah seed data contoh boleh dijalankan pada skema yang sedang dibangun?
 *
 * Berkas CABANG juga perlu ditanami data contoh (dokter, terapis, supplier, master
 * treatment/skincare/bahan) karena data itu MILIK CABANG. Baris milik cabang lain
 * yang ikut tertanam saat berkas dibuat dibuang oleh db_branch_purge_foreign_rows()
 * dan tidak pernah terlihat karena view menyaring per cabang.
 */
function db_schema_seed_allowed(): bool
{
    return true;
}
/** Jalankan $fn dengan ruang lingkup skema tertentu ('central'|'branch'). */
function db_schema_with_scope(string $scope, callable $fn)
{
    $lama = $GLOBALS['DB_SCHEMA_SCOPE'] ?? 'central';
    $GLOBALS['DB_SCHEMA_SCOPE'] = $scope;
    try { return $fn(); } finally { $GLOBALS['DB_SCHEMA_SCOPE'] = $lama; }
}

/**
 * Apakah skema sebuah basis data SUDAH mengikuti aplikasi?
 *
 * Satu sumber penilaian untuk SEMUA berkas (central maupun tiap berkas cabang):
 * versi skema HARUS sama **dan** sidik jari daftar tambahan kolom harus cocok.
 * Dipakai jalur cepat `ensure_schema()` dan gerbang migrasi berkas cabang
 * (`db_route_ensure_branch_schema()`) — supaya kolom baru tidak pernah "tertinggal"
 * hanya karena lupa menaikkan SCHEMA_VERSION (kejadian nyata: `backups.auto_run`).
 */
/**
 * Angka versi skema (untuk berkas CABANG yang tidak punya tabel `settings`).
 *
 * Berkas cabang TIDAK memuat tabel pengaturan (data global hanya di central), jadi
 * versi skemanya dicatat pada `PRAGMA user_version` — field bawaan SQLite yang selalu
 * ada di setiap berkas dan tidak memerlukan tabel apa pun. Angkanya diturunkan dari
 * versi + sidik jari daftar tambahan kolom sehingga perilakunya sama dengan central.
 */
function schema_version_code(): int
{
    static $kode = null;
    if ($kode === null) {
        $kode = (int)(hexdec(substr(md5(SCHEMA_VERSION . '|' . schema_adds_fingerprint()), 0, 7)) & 0x7FFFFFFF);
    }
    return $kode;
}

function schema_is_current(PDO $pdo): bool
{
    /* Berkas CABANG: penandanya `PRAGMA user_version` (tanpa tabel settings). */
    if (function_exists('db_schema_scope') && db_schema_scope() === 'branch') {
        try {
            return (int)$pdo->query('PRAGMA user_version')->fetchColumn() === schema_version_code();
        } catch (Throwable $e) {
            return false;
        }
    }
    try {
        $v = (string)$pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
        $fp = (string)$pdo->query("SELECT value FROM settings WHERE key = 'schema_adds_fp'")->fetchColumn();
    } catch (Throwable $e) {
        return false;                     // tabel settings belum ada → basis data baru
    }
    /* Sidik jari DDL ikut diperiksa supaya tabel/indeks baru di `schema_ddl_raw()`
       TIDAK PERNAH tertinggal pada pemasangan yang sudah berjalan (lihat catatan
       schema_ddl_fingerprint()). */
    return $v === SCHEMA_VERSION && $fp === schema_adds_fingerprint()
        && schema_ddl_setting($pdo) === schema_ddl_fingerprint();
}

/** Sidik jari DDL tersimpan (dibuat saat migrasi). Tahan gagal untuk basis data baru. */
function schema_ddl_setting(PDO $pdo): string
{
    try {
        return (string)$pdo->query("SELECT value FROM settings WHERE key = 'schema_ddl_fp'")->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
}

/** Baca satu setelan langsung dari PDO (dipakai jalur cepat ensure_schema). */
function scalar_schema_setting(PDO $pdo, string $key): string
{
    try {
        $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? '' : (string)$v;
    } catch (Throwable $e) {
        return '';
    }
}

function ensure_schema(PDO $pdo): void
{
    // Fast path: already migrated — versi skema SAMA **dan** daftar tambahan kolom
    // tidak berubah sejak terakhir dijalankan (lihat schema_adds_fingerprint()).
    if (schema_is_current($pdo)) return;

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        /* PENTING (anti-balapan): permintaan lain bisa saja sudah menyelesaikan
           migrasi ini saat kita menunggu kunci tulis di BEGIN IMMEDIATE di atas.
           Karena itu versi skema diperiksa ULANG setelah kunci didapat — tanpa
           ini, dua permintaan yang datang bersamaan akan menjalankan migrasi dua
           kali (dulu menyebabkan migrasi penggeser waktu jalan berkali-kali). */
        if (db_schema_scope() === 'branch') {
            $ulang = schema_is_current($pdo);        // PRAGMA user_version (dibaca ulang)
        } else {
            try {
                $again = (string)$pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
                $fpAgain = (string)scalar_schema_setting($pdo, 'schema_adds_fp');
            } catch (Throwable $e) {
                $again = '';   // tabel settings belum ada → database benar-benar baru
                $fpAgain = '';
            }
            $ulang = ($again === SCHEMA_VERSION && $fpAgain === schema_adds_fingerprint()
                && scalar_schema_setting($pdo, 'schema_ddl_fp') === schema_ddl_fingerprint());
        }
        if ($ulang) {
            $pdo->exec('ROLLBACK');
            return;
        }
        foreach (schema_ddl(db_schema_scope()) as $sql) {
            $pdo->exec($sql);
        }
        /* BERKAS CABANG: lantai id diterapkan SEKARANG — tabel baru dibuat & MASIH
           KOSONG, tepat sebelum data contoh ditanam. Tanpa urutan ini, id data
           contoh (treatment/skincare/bahan/dokter) mulai dari 1 di SETIAP cabang
           sehingga bertabrakan antar cabang: pencarian `WHERE id = ?` pada tampilan
           "semua cabang" menemukan baris cabang lain (dulu muncul keluhan
           "Treatment ... bukan milik cabang ini" di Order Baru). */
        if (db_schema_scope() === 'branch'
            && function_exists('db_branch_apply_id_floor_pdo')
            && db_schema_branch_id() > 0) {
            db_branch_apply_id_floor_pdo($pdo, db_schema_branch_id());
        }
        run_migrations($pdo);
        if (db_schema_seed_allowed()) seed_core($pdo);
        // Setelah peran & permission ada (seed_core), pastikan semua level —
        // termasuk Kasir — boleh MELIHAT & MENGEDIT rekam medis.
        /* Berkas cabang hanya memuat data operasional — akun/role adalah data global. */
        if (db_schema_scope() !== 'branch') {
            grant_kasir_medical($pdo);
            ensure_direktur_seed($pdo);
        }
        ensure_staff_seed($pdo);
        /* KAMUS ICD hanya ada di CENTRAL (data global). Berkas cabang TIDAK lagi
           menyimpan kamusnya — dulu 15.966 baris terduplikasi di setiap cabang. */
        if (db_schema_scope() !== 'branch') seed_icd_dictionary($pdo);
        if (db_schema_scope() === 'branch') {
            /* Penanda versi skema pada berkas CABANG: `PRAGMA user_version`
               (berkas cabang tidak punya tabel settings). */
            $pdo->exec('PRAGMA user_version = ' . schema_version_code());
        } else {
            /* Baris versi skema + sidik jari daftar tambahan kolom (central). */
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_adds_fp', '" . schema_adds_fingerprint() . "')
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_ddl_fp', '" . schema_ddl_fingerprint() . "')
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        }
        $pdo->exec('COMMIT');
        /* Migrasi di atas bisa mengubah isi settings (mis. status struk). Cache
           pengaturan bersifat statis, jadi harus disegarkan agar pemanggilan
           berikutnya pada request yang sama membaca nilai terbaru. */
        if (function_exists('settings')) settings(true);
        /* PENTING: rekaman template default dijalankan SETELAH cache disegarkan.
           Bila dijalankan di dalam transaksi (sebelum refresh), `setting()` masih
           membaca cache LAMA yang belum memuat nilai bawaan yang baru disisipkan
           seed_core — akibatnya rekaman email/WhatsApp tidak pernah terbentuk
           dan tombol "Kembali ke Default" tidak punya bakuannya. */
        if (function_exists('template_default_snapshot_ensure')) {
            template_default_snapshot_ensure();
        }
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
    /* Dokter & terapis adalah data OPERASIONAL milik cabang. Di basis data CENTRAL
       tabelnya harus KOSONG — kalau tidak, baris sisa di central akan menghalangi
       penghapusan cabang (FK `branches`) dan membuat central memuat data operasional
       (dulu terjadi: central berisi 2 dokter per cabang, sehingga "Hapus Semua Data"
       gagal dengan "FOREIGN KEY constraint failed"). */
    if (db_schema_scope() === 'central') return;
    /* Berkas CABANG tidak memiliki tabel `branches`; data contoh dokter/terapis di
       sana sudah ditanam seed_core() untuk cabang PEMILIK berkas ini. */
    if (db_schema_scope() === 'branch') return;
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

/** Apakah sebuah tabel ADA pada basis data ini? (dipakai penjaga migrasi)
 *
 * PENTING: simpanan (cache) dikunci per-KONEKSI. Tanpa kunci itu, hasil dari
 * basis data central "menempel" saat koneksi berikutnya (berkas cabang) diperiksa
 * — akibatnya tabel yang tidak ada dianggap ada dan ALTER TABLE menggagalkan
 * seluruh transaksi skema (pernah terjadi saat migrasi central/branch).
 */
function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $kunci = spl_object_id($pdo) . '|' . $table;
    if (array_key_exists($kunci, $cache)) return $cache[$kunci];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);
        return $cache[$kunci] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$kunci] = false;
    }
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

/**
 * DAFTAR TAMBAHAN KOLOM (additive migrations) — SATU SUMBER.
 *
 * Dipisahkan dari `run_migrations()` supaya sidik jarinya dapat dihitung oleh
 * `ensure_schema()` (lihat `schema_adds_fingerprint()`).
 *
 * @return array<int,array{0:string,1:string,2:string}>
 */
function schema_adds(): array
{
    return [
        /* Jenis berkas backup ('sql' dump · 'package' zip central+cabang · 'branch'
           salinan satu basis data cabang) + cabangnya — dipakai halaman Backup
           untuk memilih cara pemulihan yang tepat (ronde 59). */
        ['backups', 'kind', "TEXT DEFAULT 'sql'"],
        ['backups', 'branch_id', 'INTEGER'],
        /* Penanda satu SET backup otomatis (mis. '2026-10-07'): satu kali jadwal
           berjalan dapat menghasilkan beberapa berkas (paket lengkap + per cabang).
           Batas "Jumlah Backup Otomatis Disimpan" memangkas per SET supaya tidak
           ada set yang terhapus separuh. */
        ['backups', 'auto_run', 'TEXT'],
        ['medical_records', 'icd10_desc', 'TEXT'],
        ['medical_records', 'icd9_desc', 'TEXT'],
        // jejak pengiriman struk ke WhatsApp
        ['orders', 'receipt_url', 'TEXT'],
        ['orders', 'receipt_sent_at', 'TEXT'],
        ['orders', 'receipt_sent_to', 'TEXT'],
        ['orders', 'receipt_sent_via', 'TEXT'],
        ['orders', 'receipt_status', 'TEXT'],
        /* STRUK LEWAT EMAIL punya kolom SENDIRI (perbaikan ronde 48).
           Sebelumnya pengiriman email menulis ke kolom `receipt_*` yang dipakai
           WhatsApp, sehingga di halaman Detail Transaksi baris "Struk WhatsApp"
           menampilkan ALAMAT EMAIL pasien — membingungkan. Sekarang dipisah. */
        ['orders', 'receipt_email_to', 'TEXT'],
        ['orders', 'receipt_email_status', 'TEXT'],
        ['orders', 'receipt_email_sent_at', 'TEXT'],
        // Foto orang (pasien/dokter/terapis) + waktu perubahan untuk cache-busting
        ['patients', 'photo_file', 'TEXT'],
        ['patients', 'photo_updated_at', 'TEXT'],
        ['doctors', 'photo_file', 'TEXT'],
        ['doctors', 'photo_updated_at', 'TEXT'],
        ['therapists', 'photo_file', 'TEXT'],
        ['therapists', 'photo_updated_at', 'TEXT'],
        /* Email tenaga medis (ronde 60) — dipakai daftar, form, dan ekspor. */
        ['doctors', 'email', 'TEXT'],
        ['therapists', 'email', 'TEXT'],
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
        /* HARGA NORMAL (sebelum promo) yang BERLAKU SAAT TRANSAKSI.
           Permintaan pemilik: di Order Baru harga normal ditampilkan dicoret di
           samping harga promo supaya pasien tahu treatment itu sedang promo.
           Nilainya disimpan sebagai SNAPSHOT pada baris transaksi (sama polanya
           dengan HPP) agar struk/riwayat lama tetap menunjukkan harga normal yang
           benar walau harga master diubah kemudian. */
        ['order_items', 'price_normal', 'REAL'],
        /* KEAMANAN LOGIN (ronde 38): kunci TOTP (Google Authenticator) per pengguna.
           `totp_enabled` + `totp_confirmed_at` hanya terisi setelah pemilik benar-benar
           memindai QR dan kodenya terbukti benar — jadi 2FA tidak pernah "aktif palsu". */
        ['users', 'totp_secret', 'TEXT'],
        ['users', 'totp_enabled', 'INTEGER'],
        ['users', 'totp_confirmed_at', 'TEXT'],
        /* AI DEVELOPER — kolom jawaban mentah ditambahkan SETELAH tabel ai_tasks
           dibuat di bawah (lihat catatan di sana), BUKAN di daftar $adds. */
    ];
}

/**
 * SIDIK JARI daftar tambahan kolom.
 *
 * KENAPA ADA (kejadian nyata): `ensure_schema()` punya jalur cepat "versi skema sama →
 * tidak perlu migrasi". Ketika satu kolom baru ditambahkan ke `$adds` TANPA menaikkan
 * SCHEMA_VERSION, basis data yang sudah terpasang TIDAK PERNAH mendapat kolom itu —
 * dan aplikasi baru gagal di tengah jalan ("table backups has no column named auto_run"
 * saat backup dibuat, yaitu SETIAP kali pengguna login). Dengan sidik jari ini jalur
 * cepat otomatis batal begitu daftarnya berubah, sehingga kelalaian menaikkan versi
 * tidak lagi bisa membuat kolom hilang di pemasangan yang sudah berjalan.
 */
function schema_adds_fingerprint(): string
{
    static $fp = null;
    if ($fp === null) $fp = substr(md5(json_encode(schema_adds())), 0, 12);
    return $fp;
}

/**
 * Sidik jari SKEMA LENGKAP (versi + daftar tambahan kolom + seluruh DDL tabel).
 *
 * MENGAPA perlu: jalur cepat `schema_is_current()` dulu hanya memakai SCHEMA_VERSION
 * dan sidik jari daftar KOLOM. Akibatnya tabel/indeks baru yang ditambahkan ke
 * `schema_ddl_raw()` (atau ke `run_migrations()`) **tidak pernah sampai ke pemasangan
 * yang sudah berjalan** selama versinya tidak dinaikkan — kejadian nyata (ronde 64e):
 * tabel `user_sessions` & `login_blocks` tidak terbentuk di produksi walau versinya
 * sudah 1.38.0, sehingga Login Management mustahil bekerja.
 *
 * Dengan sidik jari ini, PERUBAHAN DDL APA PUN (menambah tabel/indeks di
 * `schema_ddl_raw()`) otomatis membatalkan jalur cepat sehingga migrasi berjalan.
 * Untuk tabel yang HANYA ada di `run_migrations()`, naikkan SCHEMA_VERSION seperti
 * biasa — atau (lebih baik) taruh di `schema_ddl_raw()` supaya ikut terpantau di sini
 * dan otomatis dibuat pada pemasangan baru.
 */
function schema_ddl_fingerprint(): string
{
    static $fp = null;
    if ($fp === null) {
        $fp = substr(md5(SCHEMA_VERSION . '|' . implode("\n", schema_ddl_raw())), 0, 16);
    }
    return $fp;
}

/** Additive migrations (idempotent, guarded) — runs inside the schema transaction. */
function run_migrations(PDO $pdo): void
{
    /* BERKAS CABANG hanya memuat tabel OPERASIONAL. Setiap pernyataan migrasi yang
       menyebut tabel GLOBAL (pengguna, peran, pengaturan, kamus ICD, biaya operasional,
       audit, backup, demo, AI, dsb.) DILEWATI — tabelnya memang tidak ada di berkas
       cabang, dan dulu hal itu menggagalkan seluruh transaksi skema ("no such table").
       Penyaringnya memeriksa NAMA TABEL pada teks SQL, jadi lengkap: CREATE TABLE,
       CREATE INDEX, dan ALTER TABLE sekaligus. */
    $branch = (db_schema_scope() === 'branch');
    /* SATU SUMBER DATA UNTUK SETIAP TABEL (FINAL AUDIT):
       · berkas CABANG  → pernyataan yang menyebut tabel GLOBAL dilewati;
       · basis data CENTRAL → pernyataan yang menyebut tabel OPERASIONAL dilewati.
       Sebelumnya central tetap membuat/menambah kolom tabel operasional (wadah kosong),
       sehingga sebuah tabel bisa punya definisi di DUA basis data — sumber kebingungan
       dan risiko data terbelah. Data operasional kini HANYA ada di berkas cabang. */
    $namaGlobal = $branch ? db_route_global_tables() : [];
    $namaOper = $branch ? [] : schema_branch_table_list();
    $ex = function (string $sql) use ($pdo, $namaGlobal, $namaOper) {
        foreach ($namaGlobal as $g) {
            if (preg_match('/\b' . preg_quote($g, '/') . '\b/i', $sql)) return;
        }
        foreach ($namaOper as $g) {
            if (preg_match('/\b' . preg_quote($g, '/') . '\b/i', $sql)) return;
        }
        $pdo->exec($sql);
    };

    $adds = schema_adds();
    foreach ($adds as [$t, $col, $type]) {
        /* Tabelnya mungkin tidak ada di berkas ini (tabel GLOBAL tidak dibuat di
           berkas CABANG) — dahulu ALTER TABLE pada tabel yang tidak ada membuat
           SELURUH transaksi skema gagal, jadi dijaga di sini. */
        if (!table_exists($pdo, $t)) continue;
        if (!table_has_column($pdo, $t, $col)) {
            $ex("ALTER TABLE {$t} ADD COLUMN {$col} {$type}");
        }
    }
    /* Permintaan pembayaran (jalur OTOMATIS): transaksi BELUM dibuat sampai
       gateway menyatakan lunas. Muatan transaksi disimpan di sini supaya
       notifikasi gateway (tanpa sesi pengguna) bisa menyelesaikannya.
       Kolom `payload` menyimpan data transaksi, `raw` menyimpan balasan gateway
       apa adanya untuk keperluan penelusuran. */
    $ex("CREATE TABLE IF NOT EXISTS pay_pending (
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
    $ex('CREATE INDEX IF NOT EXISTS idx_pay_pending_ref ON pay_pending(ref)');

    /* Tabel treatment per reservasi (dibuat di sini juga supaya database yang
       sudah terpasang ikut mendapatkannya; CREATE TABLE IF NOT EXISTS aman
       dijalankan berulang). */
    $ex("CREATE TABLE IF NOT EXISTS appointment_treatments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_id INTEGER NOT NULL,
        treatment_id INTEGER NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_appt_treat ON appointment_treatments(appointment_id)');

    /* PAKET treatment/produk (CREATE TABLE IF NOT EXISTS aman dijalankan berulang;
       juga diperlukan oleh database yang sudah terpasang). */
    $ex("CREATE TABLE IF NOT EXISTS packages (
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
    $ex("CREATE TABLE IF NOT EXISTS package_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        package_id INTEGER NOT NULL,
        item_type TEXT NOT NULL,
        item_id INTEGER NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        position INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_package_items_pkg ON package_items(package_id)');

    /* BIAYA OPERASIONAL (menu Keuangan — mode "Laporan Lengkap"). Setiap baris
       punya nominal DAN periode pembayaran (1/3/6/12/24/36/60 bulan) sehingga
       biaya tahunan (mis. sewa bangunan) maupun bulanan (gaji, listrik & air,
       marketing, pajak, operasional lain) dapat diisi apa adanya dan sistem
       menghitung porsi untuk periode laporan secara proporsional. */
    $ex("CREATE TABLE IF NOT EXISTS finance_costs (
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
    $ex('CREATE INDEX IF NOT EXISTS idx_finance_costs_sort ON finance_costs(sort)');
    /* MODE pos biaya (ronde 33) — WAJIB ditambahkan SETELAH tabelnya dibuat:
       tabel `finance_costs` dibuat di blok ini, sedangkan daftar `$adds` di atas
       dijalankan lebih dulu (kalau diisi di sana, instalasi baru gagal dengan
       "no such table: finance_costs"). */
    if (!table_has_column($pdo, 'finance_costs', 'cost_mode')) {
        $ex("ALTER TABLE finance_costs ADD COLUMN cost_mode TEXT NOT NULL DEFAULT 'branch'");
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
    $ex("CREATE TABLE IF NOT EXISTS finance_cost_amounts (
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
    $ex('CREATE INDEX IF NOT EXISTS idx_fc_amounts_cost ON finance_cost_amounts(cost_id)');
    /* Contoh awal supaya pemilik klinik tinggal mengisi nominalnya. */
    if (!$branch && (int)$pdo->query('SELECT COUNT(*) FROM finance_costs')->fetchColumn() === 0) {
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
        $ex("CREATE INDEX IF NOT EXISTS {$idx} ON {$target}");
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
    $ex("UPDATE orders SET receipt_status = 'prepared'
                WHERE receipt_status IS NULL AND receipt_sent_at IS NOT NULL
                  AND (receipt_sent_via IS NULL OR receipt_sent_via NOT LIKE '%API%')");
    $ex("UPDATE orders SET receipt_status = 'sent'
                WHERE receipt_status IS NULL AND receipt_sent_at IS NOT NULL AND receipt_sent_via LIKE '%API%'");

    /* Tabel KEAMANAN LOGIN (ronde 38): "ingat saya", lupa password, kode
       pemulihan, dan kode verifikasi cadangan lewat email. Semua nilai sensitif
       disimpan sebagai HASH. */
    $ex("CREATE TABLE IF NOT EXISTS login_remember (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        selector TEXT NOT NULL UNIQUE,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        last_used_at TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_login_remember_sel ON login_remember(selector)');
    $ex("CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_password_resets_hash ON password_resets(token_hash)');
    $ex("CREATE TABLE IF NOT EXISTS recovery_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_recovery_codes_user ON recovery_codes(user_id)');
    $ex("CREATE TABLE IF NOT EXISTS login_2fa_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        purpose TEXT NOT NULL DEFAULT 'email',
        code_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_login_2fa_user ON login_2fa_codes(user_id)');

    /* TABEL AI DEVELOPER (ronde 41): menyimpan tiap permintaan pengembangan
       (revisi/perbaikan/tambah fitur) beserta RENCANA, PATCH yang diusulkan AI,
       hasil uji di folder staging, dan keadaan penerapan/rollback. Disimpan di
       basis data supaya persetujuan Super Admin & riwayatnya tetap ada. */
    $ex("CREATE TABLE IF NOT EXISTS ai_tasks (
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
        raw_reply TEXT,
        finish_reason TEXT,
        /* RONDE 44: lampiran (Excel/PDF/gambar) + brief dari mode kolaborasi,
           halaman pratinjau yang dipilih AI, dan PEMAKAIAN TOKEN per tugas. */
        attachments TEXT,
        brief TEXT,
        preview_page TEXT,
        engine TEXT,
        calls INTEGER DEFAULT 0,
        tokens_in INTEGER DEFAULT 0,
        tokens_out INTEGER DEFAULT 0,
        tokens_think INTEGER DEFAULT 0,
        tokens_total INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        updated_at TEXT
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_tasks_status ON ai_tasks(status)');
    /* RONDE 43: kolom jawaban MENTAH AI + alasan berhenti (finishReason) supaya
       kegagalan seperti "JSON tidak sah"/terpotong dapat ditelusuri pemilik.
       PENTING: ditambahkan DI SINI (setelah tabelnya dibuat), bukan di daftar
       `$adds` di atas — pada database BARU tabel ai_tasks belum ada saat daftar
       itu dijalankan, sehingga ALTER TABLE akan gagal dan MEMBATALKAN seluruh
       pembuatan skema (instalasi baru jadi kosong). Sudah pernah terjadi saat
       menambahkan kolom ini. */
    $kolomAiTasks = [
        'raw_reply' => 'TEXT', 'finish_reason' => 'TEXT',
        /* RONDE 44: lampiran, brief kolaborasi, halaman pratinjau, & pemakaian token. */
        'attachments' => 'TEXT', 'brief' => 'TEXT', 'preview_page' => 'TEXT', 'engine' => 'TEXT',
        'calls' => 'INTEGER DEFAULT 0', 'tokens_in' => 'INTEGER DEFAULT 0',
        'tokens_out' => 'INTEGER DEFAULT 0', 'tokens_think' => 'INTEGER DEFAULT 0',
        'tokens_total' => 'INTEGER DEFAULT 0',
        /* RONDE 45: laporan akhir + saran AI, dan judul singkat untuk daftar percakapan. */
        'suggestions' => 'TEXT', 'summary' => 'TEXT', 'title' => 'TEXT',
        /* Ringkasan + daftar pemeriksaan yang GAGAL dari uji staging (ronde 48).
           Berkas log bisa dibersihkan oleh pembersih folder sementara, sehingga
           alasannya hilang; teks ini disimpan agar tetap dapat dibaca kapan pun. */
        'test_ringkas' => 'TEXT',
        /* RONDE 50: maksud permintaan (obrolan vs pekerjaan), status workflow resmi,
           berkas yang benar-benar terbaca/dilewati, dan hasil penelusuran dampak.
           Semuanya dipakai agar STATUS MESIN & STATUS TAMPILAN selalu konsisten
           dan kegagalan AI dapat ditelusuri tanpa menebak. */
        'intent' => 'TEXT', 'intent_note' => 'TEXT', 'workflow' => 'TEXT',
        /* Mode yang DIMINTA pemilik (auto/jawab/audit/rencana/kerjakan) — dipisah dari
           kolom `intent` yang menyimpan JENIS pekerjaan hasil klasifikasi. */
        'intent_mode' => 'TEXT',
        'audit_status' => 'TEXT', 'files_read' => 'TEXT', 'files_skipped' => 'TEXT',
        'impact_note' => 'TEXT',
    ];
    foreach ($kolomAiTasks as $col => $type) {
        if (!table_has_column($pdo, 'ai_tasks', $col)) {
            $ex("ALTER TABLE ai_tasks ADD COLUMN {$col} {$type}");
        }
    }
    /* Berkas lampiran tiap permintaan (Excel/PDF/gambar). Isi berkasnya disimpan
       DI LUAR folder aplikasi yang disajikan publik (`naveena_ai/uploads`). */
    $ex("CREATE TABLE IF NOT EXISTS ai_task_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        name TEXT,
        ext TEXT,
        mime TEXT,
        size INTEGER,
        path TEXT,
        chars INTEGER DEFAULT 0,
        sent INTEGER DEFAULT 0,
        note TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_task_files ON ai_task_files(task_id)');
    /* Catatan PEMAKAIAN TOKEN setiap panggilan AI (untuk menjawab "habis berapa
       token setiap pengerjaan") — satu baris per panggilan, dengan tujuannya. */
    $ex("CREATE TABLE IF NOT EXISTS ai_usage_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER,
        provider TEXT,
        model TEXT,
        purpose TEXT,
        calls INTEGER DEFAULT 1,
        tokens_in INTEGER DEFAULT 0,
        tokens_out INTEGER DEFAULT 0,
        tokens_think INTEGER DEFAULT 0,
        tokens_total INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_usage_task ON ai_usage_log(task_id)');
    /* LANGKAH BERJALAN (ronde 45): supaya pemilik dapat MELIHAT prosesnya seperti
       agen — "sedang membaca berkas", "sedang menyusun patch", "sedang diuji".
       Ditulis pekerja AI dari waktu ke waktu dan dibaca halaman lewat polling. */
    $ex("CREATE TABLE IF NOT EXISTS ai_steps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        kind TEXT DEFAULT 'info',
        text TEXT,
        detail TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_steps_task ON ai_steps(task_id)');
    /* PERCAKAPAN (ronde 45): pesan pemilik & jawaban AI dalam satu utas, sehingga
       perintah lanjutan dapat melanjutkan pekerjaan yang sama (seperti mengobrol). */
    $ex("CREATE TABLE IF NOT EXISTS ai_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        text TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_messages_task ON ai_messages(task_id)');
    /* JEJAK KERJA AI (ronde 50): satu baris per FASE pekerjaan (klasifikasi maksud,
       penelusuran berkas, pembacaan, penelusuran dampak, penyusunan patch, uji,
       hasil akhir). Dipakai agar kegagalan AI Developer dapat ditelusuri sendiri
       oleh pemilik/dev tanpa menebak-nebak — termasuk berkas yang gagal dibaca
       beserta alasannya. */
    $ex("CREATE TABLE IF NOT EXISTS ai_traces (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        phase TEXT,
        data TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_traces_task ON ai_traces(task_id)');

    /* JOB ENGINE AI DEVELOPER (ronde 53) — pekerjaan berat berjalan sebagai JOB di
       latar belakang dengan identitas, heartbeat, checkpoint, retry, resume dan
       pembatalan. Status job diturunkan dari status tugas sehingga UI & mesin selalu
       memakai sumber status yang sama. */
    $ex("CREATE TABLE IF NOT EXISTS ai_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        job_id TEXT,
        type TEXT DEFAULT 'pipeline',
        status TEXT NOT NULL DEFAULT 'QUEUED',
        current_step TEXT,
        progress INTEGER DEFAULT 0,
        heartbeat TEXT,
        started_at TEXT,
        updated_at TEXT,
        finished_at TEXT,
        retry_count INTEGER DEFAULT 0,
        max_retry INTEGER DEFAULT 2,
        checkpoint TEXT,
        error TEXT,
        note TEXT,
        cancel_requested INTEGER DEFAULT 0,
        steps_json TEXT,
        files_found INTEGER DEFAULT 0,
        files_read INTEGER DEFAULT 0,
        files_skipped INTEGER DEFAULT 0,
        worker_pid INTEGER
    )");
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_jobs_task ON ai_jobs(task_id)');
    $ex('CREATE INDEX IF NOT EXISTS idx_ai_jobs_status ON ai_jobs(status)');

    /* Migrasi data GLOBAL (biaya operasional & status audit AI) HANYA di central. */
    if (!$branch) {
        migrate_finance_cost_amounts($pdo);
        migrate_finance_cost_modes($pdo);
        migrate_ai_audit_status($pdo);
    }
    /* PELACAKAN DATA DEMO (ronde 54) — seluruh record yang dibuat tombol "Isi Data Demo"
       diberi `demo_batch_id` sehingga dapat dilacak, dilaporkan, dan dihapus per batch
       dengan urutan dependency yang aman. Laporan produksi mengecualikan demo secara
       bawaan; basis data lama tetap aman karena kolomnya ditambah dengan nilai NULL.

       JEBAKAN YANG WAJIB DIHINDARI (pelajaran ronde 43): ALTER TABLE ke tabel yang
       BELUM ADA membatalkan SELURUH transaksi pembuatan skema (database baru jadi
       kosong). Karena itu setiap tabel diperiksa keberadaannya lebih dulu, dan blok ini
       diletakkan di AKHIR run_migrations() setelah semua tabel selesai dibuat. */
    $ex("CREATE TABLE IF NOT EXISTS demo_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id TEXT NOT NULL,
        created_by INTEGER,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        note TEXT,
        status TEXT DEFAULT 'ACTIVE',
        summary TEXT
    )");
    $ex('CREATE UNIQUE INDEX IF NOT EXISTS idx_demo_batches ON demo_batches(batch_id)');
    /* Nama tabel HARUS sama dengan yang ada di aplikasi (mis. 'treatment_materials',
       bukan 'materials') — nama yang salah membuat ALTER dilewati sehingga penghapusan
       batch demo gagal dengan "no such column". */
    $tabelDemo = ['patients', 'medical_records', 'appointments', 'appointment_treatments',
                  'orders', 'order_items', 'payments', 'inventory_movements', 'packages',
                  'treatments', 'skincare_products', 'treatment_materials', 'suppliers',
                  'member_upgrades'];
    foreach ($tabelDemo as $tbl) {
        $ada = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name="
            . $pdo->quote($tbl))->fetchColumn();
        if ($ada === 0) continue;                    // tabel belum ada → lewati (jangan batalkan skema)
        if (!table_has_column($pdo, $tbl, 'demo_batch_id')) {
            $ex("ALTER TABLE {$tbl} ADD COLUMN demo_batch_id TEXT");
        }
        $ex("CREATE INDEX IF NOT EXISTS idx_{$tbl}_demo ON {$tbl}(demo_batch_id)");
    }

    /* ---------------------------------------------------------------- *
     * BERSIHKAN WADAH OPERASIONAL KOSONG DI CENTRAL (FINAL AUDIT)
     * ---------------------------------------------------------------- *
     * Central HANYA memuat data global/sistem. Tabel operasional sisa dari
     * arsitektur lama (dibuat sebagai "wadah kosong") dibuang HANYA bila
     * benar-benar KOSONG — tabel yang masih berisi baris DIBIARKAN dan
     * dilaporkan lewat $GLOBALS supaya tidak ada data yang hilang tanpa
     * disetujui. Berkas CABANG tidak tersentuh sama sekali.
     * Idempoten: sesudah dijalankan, tabelnya tidak ada lagi. */
    if (!$branch) {
        $dibuang = [];
        foreach (schema_branch_table_list() as $t) {
            try {
                $ada = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name="
                    . $pdo->quote($t))->fetchColumn();
                if ($ada === 0) continue;
                $baris = (int)$pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
                if ($baris > 0) {
                    $GLOBALS['DB_CENTRAL_OPS_NOT_EMPTY'][$t] = $baris;
                    continue;
                }
                $pdo->exec('DROP TABLE IF EXISTS "' . $t . '"');
                $dibuang[] = $t;
            } catch (Throwable $e) {
                $GLOBALS['DB_CENTRAL_OPS_ERROR'][$t] = $e->getMessage();
            }
        }
        if ($dibuang) $GLOBALS['DB_CENTRAL_OPS_DROPPED'] = $dibuang;
    }
}

/**
 * PERBAIKAN DATA LAMA: baris AI yang menyimpulkan "tidak ada perubahan" padahal
 * auditnya TIDAK lengkap (regression #926/#927/#930 — ronde 52).
 *
 * Dulu jawaban AI yang meminta membaca berkas tambahan dan menyatakan "berkas yang
 * tersedia belum cukup" tetap dikonversi menjadi NO_CHANGE hanya karena daftar ops-nya
 * kosong. Baris-baris lama itu kini ditandai apa adanya sebagai AUDIT_INCOMPLETE supaya
 * status tersimpan, workflow, dan tampilan konsisten dengan kenyataannya.
 *
 * Idempoten: setelah diperbarui, statusnya bukan 'noop' lagi sehingga tidak ikut diproses
 * ulang pada migrasi berikutnya. HANYA menyentuh metadata tugas AI — tidak ada tabel
 * bisnis, skema, atau data klinik yang diubah.
 */
function migrate_ai_audit_status(PDO $pdo): void
{
    try {
        /* Dua kelompok diperbaiki:
           (1) baris lama yang MASIH 'noop' padahal auditnya belum lengkap → diubah statusnya;
           (2) baris yang SUDAH audit_incomplete tetapi daftar alasannya masih kosong →
               dilengkapi alasannya supaya UI dapat menampilkan berkas/area yang belum
               diperiksa (permintaan pemilik). Keduanya idempoten: setelah diisi, barisnya
               tidak lagi cocok dengan syarat di bawah. */
        $rows = $pdo->query("SELECT id, plan, raw_reply, files_skipped, files_read, status, audit_status
              FROM ai_tasks WHERE status = 'noop'
                 OR (status = 'audit_incomplete' AND COALESCE(files_skipped, '') IN ('', '[]', 'null'))")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;   // tabel belum ada (instalasi baru) → tidak ada yang perlu diperbaiki
    }
    if (!$rows) return;
    $upd = $pdo->prepare("UPDATE ai_tasks SET status = 'audit_incomplete', workflow = 'AUDIT_INCOMPLETE',
        audit_status = 'belum_lengkap', stage = ?, files_skipped = ? WHERE id = ?");
    foreach ($rows as $r) {
        $teks = strtolower((string)($r['plan'] ?? '') . ' ' . (string)($r['raw_reply'] ?? ''));
        $skipped = trim((string)($r['files_skipped'] ?? ''));
        $adaSkipped = ($skipped !== '' && $skipped !== '[]' && $skipped !== 'null');
        $ragu = (bool)preg_match('/audit_incomplete|audit belum lengkap|belum cukup|belum lengkap|'
            . 'perlu membaca|tidak cukup untuk|belum dapat disimpulkan|dependency belum/', $teks);
        $sudahTertanda = ((string)($r['status'] ?? '') === 'audit_incomplete');
        if (!$ragu && !$adaSkipped && !$sudahTertanda) continue;
        /* Baris yang sudah tertanda tetapi alasannya kosong tetap diproses untuk
           MELENGKAPI alasannya (status tidak diubah lagi). */
        $ragu = $ragu || $sudahTertanda;
        /* Alasannya IKUT dicatat supaya UI dapat menampilkan berkas/area mana yang belum
           selesai diperiksa (permintaan pemilik). Sumbernya: daftar berkas yang diminta AI
           pada jawaban mentahnya, ditambah pernyataan AI bahwa auditnya belum cukup. */
        $alasan = [];
        $raw = (string)($r['raw_reply'] ?? '');
        /* Berkas yang diminta AI DAN benar-benar belum terbaca (tidak ada di files_read)
           yang dilaporkan sebagai "belum selesai diperiksa" — supaya daftar alasannya
           jujur, bukan menyebut berkas yang sebenarnya sudah dibaca. */
        $sudahDibaca = [];
        if (preg_match('~^\s*\{.*\}$~s', (string)($r['files_read'] ?? ''))) {
            $fr = json_decode((string)$r['files_read'], true);
            if (is_array($fr)) $sudahDibaca = array_keys($fr);
        }
        if (preg_match('~"read"\s*:\s*\[(.*?)\]~s', $raw, $m)) {
            if (preg_match_all('~"([^"]{3,120})"~', $m[1], $mm)) {
                foreach (array_slice(array_unique($mm[1]), 0, 12) as $f) {
                    if (in_array($f, $sudahDibaca, true)) continue;
                    $alasan[] = 'berkas yang diminta AI belum selesai diperiksa: ' . $f;
                }
            }
        }
        if (preg_match('~(audit_incomplete[^."]{0,160}|audit belum (lengkap|cukup)[^."]{0,160}|'
            . 'belum cukup[^."]{0,160}|belum lengkap[^."]{0,160}|perlu membaca[^."]{0,160})~i',
            (string)($r['plan'] ?? ''), $mp)) {
            $alasan[] = 'pernyataan AI: ' . trim((string)$mp[1]);
        }
        if (!$alasan) $alasan[] = 'AI menyatakan auditnya belum cukup untuk menyimpulkan perubahan.';
        $upd->execute(['Audit belum lengkap (AUDIT_INCOMPLETE) — diperbaiki otomatis (ronde 52)',
            json_encode(array_values(array_unique($alasan)), JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
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

/**
 * Nama tabel yang dibaca dari sebuah pernyataan DDL (`CREATE TABLE` / `CREATE INDEX`).
 * Null bila tabelnya tidak dapat dipastikan.
 */
function schema_statement_table(string $sql): ?string
{
    if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $m)) {
        return $m[1];
    }
    if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?[A-Za-z_][A-Za-z0-9_]*[`"\]]?\s+ON\s+[`"\[]?([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $m)) {
        return $m[1];
    }
    return null;
}

/** Daftar tabel OPERASIONAL (milik berkas cabang) — dari satu sumber di db_route.php. */
function schema_branch_table_list(): array
{
    if (function_exists('db_route_branch_tables')) return db_route_branch_tables();
    /* Cadangan bila modul routing belum dimuat (urutan pemuatan berubah kelak). */
    return ['patients', 'medical_records', 'medical_record_photos', 'appointments',
        'appointment_treatments', 'orders', 'order_items', 'payments',
        'inventory', 'inventory_movements', 'treatments', 'skincare_products',
        'treatment_materials', 'suppliers', 'doctors', 'therapists', 'packages', 'package_items',
        'member_upgrades'];
}

/**
 * DDL untuk sebuah ruang lingkup.
 *
 * • `central` → SELURUH tabel (global + operasional; tabel operasional tetap dibuat
 *   sebagai wadah kosong supaya koneksi utama punya definisi tabel yang lengkap —
 *   datanya sendiri hidup di berkas cabang).
 * • `branch`  → **HANYA tabel operasional**. Tabel GLOBAL (pengguna, peran, pengaturan,
 *   kamus ICD, biaya operasional, audit, backup, AI, dsb.) TIDAK dibuat di berkas
 *   cabang sama sekali — itulah sumber duplikasi yang harus dibersihkan
 *   (icd_codes 15.966 baris & settings terduplikasi di setiap cabang).
 *   Klausa foreign key ke tabel global juga dibuang (FK antar berkas tidak didukung).
 */
function schema_ddl(string $scope = 'central'): array
{
    $ddl = schema_ddl_raw();
    $oper = array_flip(array_map('strtolower', schema_branch_table_list()));
    if ($scope === 'branch') {
        $out = [];
        foreach ($ddl as $sql) {
            $t = schema_statement_table($sql);
            if ($t === null) continue;                        // tidak dipastikan → jangan buat
            if (!isset($oper[strtolower($t)])) continue;      // tabel global → bukan milik cabang
            $out[] = db_route_strip_cross_fk($sql);
        }
        return $out;
    }
    /* CENTRAL (FINAL AUDIT): hanya tabel GLOBAL/SISTEM. Tabel operasional TIDAK
       dibuat di central sama sekali — datanya hidup di berkas masing-masing cabang
       dan disajikan lewat TEMP VIEW (includes/db_route.php). Dulu central ikut
       membuat wadah kosong untuk 19 tabel operasional, sehingga definisi satu tabel
       ada di DUA basis data. */
    $out = [];
    foreach ($ddl as $sql) {
        $t = schema_statement_table($sql);
        if ($t !== null && isset($oper[strtolower($t)])) continue;
        $out[] = $sql;
    }
    return $out;
}

function schema_ddl_raw(): array
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
        /* `email` (ronde 60): alamat email tenaga medis — dipakai untuk mengirim
           jadwal/notifikasi dan ditampilkan pada daftar & ekspor. Kolomnya juga
           ditambahkan ke daftar `schema_adds()` supaya pemasangan LAMA ikut dapat
           (sidik jari daftar kolom memicu migrasi walau versi skema tidak naik). */
        /* RIWAYAT NAIK LEVEL KARTU MEMBER (ronde 64d) — "Membership Upgrade".
           Satu baris = satu kali seorang pasien naik level kartu member, direkam
           otomatis setelah transaksi tersimpan (lihat includes/member_upgrade.php).
           Kolomnya menyimpan SNAPSHOT nama level/diskon saat itu supaya riwayat tetap
           benar walau aturan level di Pengaturan diubah kemudian.
           `email_status`: '' (belum dikirim) · 'sent' · 'failed'.
           `wa_status`   : '' (belum) · 'prepared' (tautan WA sudah dibuka petugas).
           Tabel OPERASIONAL (per cabang) → ikut db_route_branch_tables(). */
        "CREATE TABLE IF NOT EXISTS member_upgrades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            branch_id INTEGER NOT NULL,
            member_number TEXT,
            old_level TEXT, old_label TEXT, old_pct REAL,
            new_level TEXT NOT NULL, new_label TEXT, new_pct REAL,
            upgraded_at TEXT NOT NULL,
            total_amount REAL NOT NULL DEFAULT 0,
            order_id INTEGER, invoice_number TEXT,
            email_to TEXT, email_status TEXT, email_sent_at TEXT, email_error TEXT,
            wa_status TEXT, wa_sent_at TEXT,
            demo_batch_id TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (branch_id) REFERENCES branches(id),
            FOREIGN KEY (patient_id) REFERENCES patients(id)
        )",
        "CREATE INDEX IF NOT EXISTS idx_member_upgrades_patient ON member_upgrades(patient_id)",
        "CREATE INDEX IF NOT EXISTS idx_member_upgrades_time ON member_upgrades(upgraded_at)",
        /* LOGIN MANAGEMENT (ronde 64e) — daftar sesi yang sedang/pernah aktif.
           Sesi PHP berbasis berkas, jadi "mengeluarkan perangkat" dilakukan dengan
           menandai baris ini `revoked`; pada permintaan berikutnya sesi itu mendapati
           dirinya dicabut lalu dipaksa keluar (login_manage_boot()).
           Tabel GLOBAL (hanya dibuat di central; berkas cabang menyaringnya lewat
           schema_branch_table_list()). */
        "CREATE TABLE IF NOT EXISTS user_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            branch_id INTEGER,
            sid_hash TEXT NOT NULL,
            device_id TEXT,
            ip TEXT,
            user_agent TEXT,
            device_label TEXT,
            browser TEXT,
            platform TEXT,
            jenis TEXT,
            via TEXT DEFAULT 'password',
            login_at TEXT NOT NULL,
            last_activity TEXT,
            logout_at TEXT,
            revoked INTEGER NOT NULL DEFAULT 0,
            revoked_by INTEGER,
            revoked_at TEXT,
            revoke_reason TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_user_sessions_sid ON user_sessions(sid_hash)",
        "CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id)",
        /* Pemblokiran PERANGKAT. `user_id = 0` = diblokir untuk SEMUA akun. */
        "CREATE TABLE IF NOT EXISTS login_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            user_id INTEGER NOT NULL DEFAULT 0,
            reason TEXT,
            created_by INTEGER,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            removed_by INTEGER,
            removed_at TEXT,
            active INTEGER NOT NULL DEFAULT 1
        )",
        "CREATE INDEX IF NOT EXISTS idx_login_blocks_device ON login_blocks(device_id)",
        "CREATE TABLE IF NOT EXISTS doctors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, phone TEXT, email TEXT, specialization TEXT, schedule TEXT,
            branch_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now','localtime')), updated_at TEXT,
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        )",
        "CREATE TABLE IF NOT EXISTS therapists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, phone TEXT, email TEXT, specialization TEXT, schedule TEXT,
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
            /* HARGA NORMAL (sebelum promo) saat transaksi — dipakai menampilkan
               harga normal yang dicoret di samping harga promo (snapshot). */
            price_normal REAL NOT NULL DEFAULT 0,
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
            /* kind: 'sql' (dump seluruh data) · 'package' (zip central+cabang) ·
               'branch' (salinan satu basis data cabang — ronde 59) */
            kind TEXT DEFAULT 'sql', branch_id INTEGER,
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
    /* BERKAS CABANG tidak memiliki tabel `branches` (data global) — hitungannya
       hanya dilakukan di central. */
    $count = (db_schema_scope() === 'branch') ? 0 : (int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();

    /* BERKAS CABANG: bagian GLOBAL (peran, izin, pengaturan, daftar cabang, akun
       pengguna) DILEWATI — semuanya milik basis data central dan dibaca dari sana.
       Data CONTOH OPERASIONAL (dokter, terapis, supplier, master treatment/skincare/
       bahan, stok) TETAP ditanam karena memang milik cabang. */
    if (db_schema_scope() !== 'branch') {

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
        /* Sidik jari daftar tambahan kolom — lihat schema_adds_fingerprint(). */
        'schema_adds_fp'      => schema_adds_fingerprint(),
        'company_name'        => 'Naveena Skincare',
        'company_tagline'     => 'Klinik Kecantikan & Skincare',
        'company_address'     => 'Jl. Raya Kaliwungu, Kendal',
        'company_phone'       => '0812-0000-0000',
        'company_email'       => 'info@naveenaskincare.id',
        'receipt_footer'      => 'Terima kasih telah mempercayakan perawatan kulit Anda kepada Naveena Skincare.',
        /* FAVICON klinik (permintaan pemilik): ikon tab peramban, format
           .ico/.png/.svg maksimal 15 KB. Kosong = memakai ikon bawaan aplikasi. */
        'favicon_file'        => '',
        'favicon_bytes'       => '',
        'favicon_ext'         => '',
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
        /* Batas total ukuran FOTO yang ikut ke dalam paket backup (MB). 0 = hanya
           mencatat metadata/path-nya. Foto (pasien/dokter/terapis/lampiran rekam
           medis) disimpan sebagai berkas di luar basis data, jadi tanpa ini paket
           backup tidak dapat memulihkan gambarnya. */
        'backup_media_max_mb' => '40',
        /* GAMBAR LATAR WEB (ronde 64): tautan gambar daring (tanpa unggahan) yang
           dipilih acak setiap halaman dimuat — hemat ruang penyimpanan. */
        'wallpaper_mode'     => 'off',           // off | login | all
        'wallpaper_category' => 'campuran',      // campuran | kecantikan | treatment | skincare | kesehatan
        'wallpaper_urls'     => '',              // tautan sendiri (satu per baris), kosong = bawaan tema
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
        /* CAKUPAN MODE PEMELIHARAAN (ronde 66): '0' = GLOBAL (semua cabang),
           atau id satu cabang. Daftar cabangnya dibaca dari tabel `branches`
           sehingga cabang baru otomatis bisa dipilih. */
        'maintenance_branch'    => '0',
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
        /* RONDE 50: mode obrolan (AI membedakan bertanya vs minta dikerjakan) dan
           penelusuran dampak (mencari berkas lain yang ikut terpengaruh). */
        'ai_chat_mode'          => '1',
        'ai_impact_scan'        => '1',
        /* RONDE 51: auto error recovery (self-healing) — AI memperbaiki error uji
           sendiri lalu menguji ulang, tanpa menyembunyikan error. */
        'ai_heal_on'            => '1',
        'ai_heal_rounds'        => '3',
        /* RONDE 52: batas percobaan ulang bila HARNESS uji sendiri tidak berjalan
           (mis. port/sisa proses) — berbeda dari putaran perbaikan kode. */
        'ai_harness_retry'      => '2',
        /* RONDE 53 — JOB ENGINE: batas percobaan ulang, ambang worker dianggap
           stalled (detik), dan langkah baku pekerjaan. */
        'ai_job_max_retry'      => '2',
        /* RONDE 54: laporan produksi MENGECUALIKAN data demo secara bawaan; laporan
           demo/uji dapat memasukkannya bila konteksnya memang demo. */
        'report_include_demo'   => '0',
        'ai_job_stall_seconds'  => '900',
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
        /* ---- MEMBERSHIP UPGRADE (ronde 64d) ----------------------------------
           Ucapan selamat + penjelasan benefit diskon saat level kartu member pasien
           NAIK. Dikirim otomatis setelah transaksi tersimpan (bila `..._auto` = 1)
           dan dapat dikirim ulang manual dari halaman Membership Upgrade.
           Lampiran: PDF kartu member lengkap 2 halaman (kartu + status & aturan). */
        'email_member_upgrade_auto'    => '1',
        'email_member_upgrade_subject' => 'Selamat! Level Kartu Member Anda naik menjadi {level}',
        'email_member_upgrade_body'    => "Halo {nama} 🙏\n\nKabar baik! Level kartu member Anda di {klinik} {cabang} baru saja NAIK.\n\nLevel sebelumnya: {level_lama}\nLevel sekarang   : {level}\nAkumulasi transaksi {periode}: {akumulasi}\n\nBenefit diskon Anda sekarang: {diskon}\n{benefit}\n\nBersama email ini kami lampirkan kartu member lengkap Anda (PDF) yang memuat\npratinjau kartu beserta status dan aturan diskon yang berlaku.\n\nTerima kasih telah mempercayakan perawatan kulit Anda kepada {klinik}.\n\nSalam sehat,\n{klinik} {cabang}",
        'wa_member_upgrade_template'   => "Halo Kak {nama} 🙏\n\nSelamat! Level kartu member Kakak di {klinik} {cabang} naik menjadi *{level}*.\n\nBenefit diskon sekarang: {diskon}\nAkumulasi transaksi {periode}: {akumulasi}\n\nKartu member lengkap akan kami kirim ke email Kakak.\n\nSalam sehat,\n{klinik} {cabang}",
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

    }   // akhir bagian GLOBAL

    /* PENTING (arsitektur central + satu basis data per cabang): data CONTOH
       OPERASIONAL di bawah ini TIDAK ditanam di basis data CENTRAL saat pengalihan
       aktif — data itu milik berkas cabang. Tanpa penjaga ini, central ikut memuat
       baris operasional sehingga jumlahnya tidak lagi sama dengan sumber migrasi. */
    if (db_schema_scope() === 'central') {
        /* Central HANYA memuat data global/sistem — data contoh operasional
           (dokter, terapis, supplier, master treatment/skincare/bahan, stok) ditanam
           saat berkas masing-masing CABANG dibuat. */
        return;
    }

    /* Berkas CABANG: daftar cabang (`branches`) adalah data GLOBAL sehingga tabelnya
       kosong di berkas cabang — sasaran data contoh operasionalnya adalah cabang
       PEMILIK berkas ini (diberitahu pemanggil lewat db_schema_branch_id()). */
    if (db_schema_scope() === 'branch') {
        $bid = ['cabang' => max(1, db_schema_branch_id())];
    }

    /* IDEMPOTEN (FINAL AUDIT): data contoh dokter/terapis/supplier HANYA diisi bila
       tabelnya masih KOSONG. Sebelumnya pernyataannya tanpa penjaga, sehingga setiap
       kali skema berkas cabang diterapkan ulang (mis. karena naik versi skema) data
       contoh TAMBAH LAGI dan menumpuk di dokter/terapis/supplier produksi — kejadian
       nyata: satu kali migrasi menambah 2 dokter, 2 terapis, dan 3 supplier per cabang.
       Master treatment/skincare/bahan di bawah sudah aman (ON CONFLICT). */
    $kosongDokter = (int)$pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() === 0;
    $kosongTerapis = (int)$pdo->query('SELECT COUNT(*) FROM therapists')->fetchColumn() === 0;
    $kosongSupplier = (int)$pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn() === 0;

    // ---- Dokter & Terapis (contoh awal, boleh diedit/dinonaktifkan) -----
    if ($kosongDokter || $kosongTerapis) {
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
            if ($kosongDokter) foreach ($doctors as $d) $stDoc->execute([$d[0], $d[1], $d[2], $d[3], $b_id, 'active']);
            if ($kosongTerapis) foreach ($therapists as $t) $stThe->execute([$t[0], $t[1], $t[2], $t[3], $b_id, 'active']);
        }
    }

    // ---- Suppliers ------------------------------------------------
    if ($kosongSupplier) {
        $st = $pdo->prepare('INSERT INTO suppliers (name, phone, address, branch_id) VALUES (?,?,?,?)');
        foreach ([['PT Beauty Supply Indonesia', '021-5550100', 'Jakarta'], ['CV Glow Distribusi', '024-7770200', 'Semarang'], ['PT Dermacare Jaya', '021-5550300', 'Tangerang']] as $s) {
            foreach ($bid as $bc => $b_id) $st->execute([$s[0], $s[1], $s[2], $b_id]);
        }
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
    /* IDEMPOTEN (perbaikan penting): tabel `inventory` punya UNIQUE(item_type, item_id)
       — jadi seed ini TIDAK boleh memakai INSERT biasa. Dulu ia biasa, dan pada basis
       data yang SUDAH berisi baris inventory untuk item yang sama, seluruh transaksi
       skema GAGAL ("UNIQUE constraint failed: inventory.item_type, inventory.item_id")
       sehingga migrasi (dan setiap kolom baru) pada berkas itu tidak pernah diterapkan
       — kegagalannya senyap karena pemanggil menangkapnya. Sekarang baris yang sudah
       ada dilewati, dan pergerakan "Stok Awal" hanya ditulis untuk baris yang benar-benar
       BARU dibuat supaya riwayat stok tidak berganda. */
    $sst = $pdo->prepare('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status)
                          VALUES (?,?,?,?,?,?) ON CONFLICT(item_type, item_id) DO NOTHING');
    $mst = $pdo->prepare('INSERT INTO inventory_movements (inventory_id, item_type, item_id, item_name, item_code, type, quantity, stock_before, stock_after, reason, user_id, user_name, branch_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([['skincare', 'skincare_products', $uid['superadmin@naveena.id'] ?? null], ['material', 'treatment_materials', $uid['superadmin@naveena.id'] ?? null]] as $cfg) {
        foreach ($pdo->query("SELECT * FROM {$cfg[1]}") as $row) {
            $sst->execute([$cfg[0], (int)$row['id'], (int)$row['branch_id'], (float)$row['stock'], (float)$row['minimum_stock'], 'active']);
            if ($sst->rowCount() === 0) continue;      // sudah ada → jangan gandakan pergerakan stok
            $invId = (int)$pdo->lastInsertId();
            $mst->execute([$invId, $cfg[0], (int)$row['id'], $row['name'], $row['code'], 'Stok Awal',
                (float)$row['stock'], 0, (float)$row['stock'], 'Stok awal saat pembuatan sistem', $cfg[2], 'Super Admin', (int)$row['branch_id']]);
        }
    }
}
