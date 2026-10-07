<?php
/**
 * Import Data — Pasien, Rekam Medis, Skincare, Bahan Treatment, Master Treatment.
 * Sumber: Excel (.xlsx), CSV/TXT, atau JSON. Tanpa library eksternal.
 *
 * Alur: unggah → petakan kolom (otomatis, bisa diubah) → pratinjau + validasi
 *       → jalankan → laporan per baris (+ unduh daftar error).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/importer.php';
require_once __DIR__ . '/includes/layout.php';
$user = current_user();

/* ------------------------------------------------------------------ *
 * Definisi jenis import
 * ------------------------------------------------------------------ */
function import_types(): array
{
    return [
        'pasien' => [
            'label' => 'Data Pasien', 'icon' => 'users', 'perm' => 'patient.manage',
            'dasar' => 'pasien.php',
            'keterangan' => 'Nama wajib. Bila NIK/telepon sudah ada, data pasien tersebut diperbarui (update), bukan diduplikasi.',
            'fields' => [
                'nama' => ['label' => 'Nama Lengkap', 'wajib' => true, 'aliases' => ['nama', 'nama_lengkap', 'nama_pasien', 'nama_pelanggan', 'name', 'patient']],
                'nik' => ['label' => 'NIK', 'aliases' => ['nik', 'no_ktp', 'nomor_ktp', 'no_nik', 'ktp']],
                'gender' => ['label' => 'Jenis Kelamin', 'aliases' => ['jenis_kelamin', 'jk', 'gender', 'kelamin']],
                'phone' => ['label' => 'Telepon/WhatsApp', 'aliases' => ['telepon', 'no_telepon', 'no_hp', 'hp', 'whatsapp', 'wa', 'telp', 'phone', 'nomor_telepon']],
                'email' => ['label' => 'Email (opsional)', 'aliases' => ['email', 'e-mail', 'surel', 'alamat_email']],
                'birth' => ['label' => 'Tanggal Lahir', 'aliases' => ['tanggal_lahir', 'tgl_lahir', 'lahir', 'birth_date', 'dob']],
                'address' => ['label' => 'Alamat', 'aliases' => ['alamat', 'address', 'alamat_lengkap']],
                'type' => ['label' => 'Status Pasien (Baru/Lama)', 'aliases' => ['status_pasien', 'tipe_pasien', 'patient_type', 'status']],
                'member' => ['label' => 'Nomor Member', 'aliases' => ['nomor_member', 'no_member', 'kode_member', 'member']],
                'branch' => ['label' => 'Cabang (opsional)', 'aliases' => ['cabang', 'branch', 'nama_cabang']],
            ],
            'contoh' => ['Siti Aminah', '3324001234560001', 'Perempuan', '081234567890', 'siti@email.com', '1995-04-12', 'Jl. Raya Kaliwungu No. 5', 'Baru', 'MC-KW-1001001', 'Kaliwungu'],
        ],
        'rekam_medis' => [
            'label' => 'Rekam Medis', 'icon' => 'file-medical', 'perm' => 'medical.manage',
            'dasar' => 'rekam_medis.php',
            'keterangan' => 'Pasien dicari lewat nomor pasien/NIK/telepon/nama. Bila belum terdaftar, pasien baru otomatis dibuat di cabang tujuan lalu baris tetap diimport. Kode ICD wajib ada di kamus resmi.',
            'fields' => [
                'tanggal' => ['label' => 'Tanggal Pemeriksaan', 'wajib' => true, 'aliases' => ['tanggal', 'tgl', 'date', 'tanggal_periksa', 'tanggal_pemeriksaan']],
                'nama_pasien' => ['label' => 'Nama Pasien', 'aliases' => ['nama_pasien', 'pasien', 'nama', 'patient', 'nama_lengkap']],
                'nomor_pasien' => ['label' => 'No. Pasien', 'aliases' => ['nomor_pasien', 'no_pasien', 'patient_number', 'no_rm_pasien']],
                'nik' => ['label' => 'NIK', 'aliases' => ['nik', 'no_ktp', 'nomor_ktp', 'ktp']],
                'telepon' => ['label' => 'Telepon', 'aliases' => ['telepon', 'no_telepon', 'no_hp', 'hp', 'whatsapp', 'wa', 'phone']],
                'dokter' => ['label' => 'Dokter', 'aliases' => ['dokter', 'doctor', 'dpjp', 'nama_dokter']],
                'terapis' => ['label' => 'Terapis', 'aliases' => ['terapis', 'therapist', 'nama_terapis']],
                'subjektif' => ['label' => 'Subjektif', 'aliases' => ['subjektif', 'subjective', 'keluhan', 'anamnesis']],
                'objektif' => ['label' => 'Objektif', 'aliases' => ['objektif', 'objective', 'pemeriksaan']],
                'diagnosis' => ['label' => 'Assessment / Diagnosa', 'aliases' => ['diagnosis', 'diagnosa', 'assessment', 'dx']],
                'icd10' => ['label' => 'ICD-10', 'aliases' => ['icd10', 'icd_10', 'kode_icd10', 'icd']],
                'tindakan' => ['label' => 'Tindakan', 'aliases' => ['tindakan', 'action', 'prosedur', 'terapi']],
                'icd9' => ['label' => 'ICD-9-CM', 'aliases' => ['icd9', 'icd_9', 'icd9cm', 'icd_9_cm', 'kode_icd9']],
                'solusi' => ['label' => 'Planning', 'aliases' => ['planning', 'plan', 'solusi', 'catatan', 'catatan_klinik', 'solution', 'saran']],
            ],
            'contoh' => ['2026-08-15', 'Siti Aminah', '', '3324001234560001', '081234567890', 'dr. Ratna', 'Nia', 'Kulit kusam dan berjerawat', 'Komedo di area T', 'Acne vulgaris ringan', 'L70.0', 'Facial acne care', '99.83', 'Kontrol 2 minggu'],
        ],
        'skincare' => [
            'label' => 'Inventory — Produk Skincare', 'icon' => 'bottle', 'perm' => 'skincare.manage',
            'dasar' => 'skincare.php',
            'keterangan' => 'Angka stok di file bisa diperlakukan sebagai stok akhir (menimpa) atau tambahan stok — dipilih saat proses. Semua perubahan stok otomatis tercatat di Inventory Movement.',
            'fields' => [
                'code' => ['label' => 'Kode Produk', 'wajib' => true, 'aliases' => ['kode', 'kode_produk', 'kode_barang', 'code', 'sku', 'kode_item']],
                'name' => ['label' => 'Nama Produk', 'wajib' => true, 'aliases' => ['nama', 'nama_produk', 'nama_barang', 'produk', 'name']],
                'category' => ['label' => 'Kategori', 'aliases' => ['kategori', 'category', 'jenis', 'group']],
                /* Harga beli produk = HPP produk (dipakai perhitungan laba). */
                'purchase' => ['label' => 'HPP / Harga Beli', 'aliases' => ['harga_beli', 'harga_pokok', 'hpp', 'modal', 'purchase_price', 'harga_modal']],
                'selling' => ['label' => 'Harga Jual', 'aliases' => ['harga_jual', 'harga', 'selling_price', 'harga_jual_umum', 'price']],
                'stock' => ['label' => 'Stok', 'aliases' => ['stok', 'stock', 'jumlah', 'qty', 'quantity', 'stok_akhir', 'sisa_stok']],
                'min' => ['label' => 'Stok Minimum', 'aliases' => ['stok_minimum', 'minimum_stok', 'min_stok', 'minimum', 'min', 'stok_min']],
                'unit' => ['label' => 'Satuan', 'aliases' => ['satuan', 'unit', 'uom', 'kemasan']],
                'supplier' => ['label' => 'Supplier', 'aliases' => ['supplier', 'pemasok', 'distributor', 'nama_supplier']],
                'status' => ['label' => 'Status (Aktif/Nonaktif)', 'aliases' => ['status', 'status_produk', 'aktif']],
                'branch' => ['label' => 'Cabang (opsional)', 'aliases' => ['cabang', 'branch']],
            ],
            'contoh' => ['SK-GLS-01', 'Glass Skin Serum 30ml', 'Serum', '85000', '150000', '20', '5', 'pcs', 'PT Beauty Supply Indonesia', 'Aktif', 'Kaliwungu'],
        ],
        'bahan' => [
            'label' => 'Inventory — Bahan Treatment', 'icon' => 'flask', 'perm' => 'material.manage',
            'dasar' => 'bahan.php',
            'keterangan' => 'Sama seperti produk skincare: stok bisa ditimpa atau ditambahkan, dan seluruh pergerakan stok tercatat.',
            'fields' => [
                'code' => ['label' => 'Kode Bahan', 'wajib' => true, 'aliases' => ['kode', 'kode_bahan', 'kode_barang', 'code', 'sku']],
                'name' => ['label' => 'Nama Bahan', 'wajib' => true, 'aliases' => ['nama', 'nama_bahan', 'nama_barang', 'bahan', 'name']],
                'category' => ['label' => 'Kategori', 'aliases' => ['kategori', 'category', 'jenis']],
                'price' => ['label' => 'Harga', 'aliases' => ['harga', 'harga_beli', 'harga_pokok', 'hpp', 'price']],
                'stock' => ['label' => 'Stok', 'aliases' => ['stok', 'stock', 'jumlah', 'qty', 'quantity', 'stok_akhir']],
                'min' => ['label' => 'Stok Minimum', 'aliases' => ['stok_minimum', 'minimum_stok', 'min_stok', 'minimum', 'min']],
                'unit' => ['label' => 'Satuan', 'aliases' => ['satuan', 'unit', 'uom']],
                'supplier' => ['label' => 'Supplier', 'aliases' => ['supplier', 'pemasok', 'distributor']],
                'status' => ['label' => 'Status (Aktif/Nonaktif)', 'aliases' => ['status', 'aktif']],
                'branch' => ['label' => 'Cabang (opsional)', 'aliases' => ['cabang', 'branch']],
            ],
            'contoh' => ['BH-CLN-01', 'Cleansing Milk Base', 'Cleanser', '120000', '20', '5', 'liter', 'PT Beauty Supply Indonesia', 'Aktif', 'Kaliwungu'],
        ],
        'treatment' => [
            'label' => 'Master Treatment', 'icon' => 'sparkles', 'perm' => 'treatment.manage',
            'dasar' => 'treatment.php',
            'keterangan' => 'Daftar layanan treatment beserta harga, HPP (harga pokok untuk perhitungan laba), dan durasi. '
                . 'Kode treatment unik per cabang. Bila kolom HPP tidak ada di berkas, HPP yang sudah tersimpan tidak diubah.',
            'fields' => [
                'code' => ['label' => 'Kode Treatment', 'wajib' => true, 'aliases' => ['kode', 'kode_treatment', 'code', 'sku']],
                'name' => ['label' => 'Nama Treatment', 'wajib' => true, 'aliases' => ['nama', 'nama_treatment', 'treatment', 'layanan', 'name']],
                'category' => ['label' => 'Kategori', 'aliases' => ['kategori', 'category', 'jenis']],
                'normal_price' => ['label' => 'Harga Normal', 'aliases' => ['harga_normal', 'harga', 'normal_price', 'tarif']],
                'promo_price' => ['label' => 'Harga Promo', 'aliases' => ['harga_promo', 'promo', 'promo_price', 'diskon_harga']],
                /* HPP dipakai perhitungan laba (menu Keuangan). Bila kolom ini
                   tidak ada di berkas, HPP yang sudah tersimpan DIPERTAHANKAN
                   (tidak dikosongkan) — lihat imp_treatment(). */
                'hpp' => ['label' => 'HPP (Harga Pokok)', 'aliases' => ['hpp', 'harga_pokok', 'harga_pokok_penjualan', 'modal', 'biaya', 'hpp_treatment']],
                'duration' => ['label' => 'Durasi (menit)', 'aliases' => ['durasi', 'duration', 'menit', 'waktu']],
                'status' => ['label' => 'Status (Aktif/Nonaktif)', 'aliases' => ['status', 'aktif']],
                'branch' => ['label' => 'Cabang (opsional)', 'aliases' => ['cabang', 'branch']],
            ],
            /* Contoh HARUS seurutan dengan daftar `fields` di atas — termasuk
               kolom HPP yang baru (kalau tidak, berkas template jadi bergeser). */
            'contoh' => ['TR-FAC-09', 'Facial Glow Premium', 'Facial', '275000', '225000', '96000', '75', 'Aktif', 'Kaliwungu'],
        ],
    ];
}
function import_type_meta(string $type): array
{
    $t = import_types();
    if (!isset($t[$type])) throw new RuntimeException('Jenis import tidak dikenal.');
    return $t[$type];
}

/* ------------------------------------------------------------------ *
 * Normalisasi nilai
 * ------------------------------------------------------------------ */
function norm_gender(string $v): string
{
    $s = strtolower(clean_cell($v));
    if ($s === '') return '';
    if (preg_match('/^(l|lk|laki|pria|male|m)$/', $s) || strpos($s, 'laki') !== false || strpos($s, 'pria') !== false) return 'Laki-laki';
    if (preg_match('/^(p|pr|perempuan|wanita|female|f)$/', $s) || strpos($s, 'perempuan') !== false || strpos($s, 'wanita') !== false) return 'Perempuan';
    return '';
}
function norm_status(string $v): string
{
    $s = strtolower(clean_cell($v));
    if ($s === '') return 'active';
    if (strpos($s, 'non') !== false || strpos($s, 'inactive') !== false || $s === 'tidak' || $s === '0') return 'inactive';
    return 'active';
}
function norm_patient_type(string $v): string
{
    $s = strtolower(clean_cell($v));
    return strpos($s, 'lama') !== false ? 'Lama' : 'Baru';
}
function find_branch_by_text(string $text): ?array
{
    $s = strtolower(clean_cell($text));
    if ($s === '') return null;
    foreach (branches() as $b) {
        $name = strtolower($b['name']);
        $code = strtolower($b['code']);
        // cocokkan nama lengkap, nama tanpa awalan "naveena skincare", atau kode cabang
        if ($s === $name || $s === $code || strpos($name, $s) !== false
            || (strlen($s) >= 3 && strpos($name, $s) !== false)
            || preg_match('/\b' . preg_quote($s, '/') . '\b/', $name)) {
            return $b;
        }
    }
    return null;
}

/* ------------------------------------------------------------------ *
 * Pemroses per jenis
 * ------------------------------------------------------------------ */
function do_import(string $type, array $data, array $map, array $opt, int $targetBranch, array $user): array
{
    $rows = $data['rows'];
    $res = ['success' => 0, 'updated' => 0, 'failed' => 0, 'warnings' => 0, 'errors' => []];
    $batchName = $opt['filename'] ?? 'file';

    foreach ($rows as $i => $row) {
        $rowNo = $i + 2;   // baris 1 = header
        try {
            switch ($type) {
                case 'pasien':      $out = imp_pasien($row, $map, $targetBranch, $user); break;
                case 'rekam_medis': $out = imp_rekam_medis($row, $map, $targetBranch, $user); break;
                case 'skincare':    $out = imp_skincare($row, $map, $targetBranch, $opt); break;
                case 'bahan':       $out = imp_bahan($row, $map, $targetBranch, $opt); break;
                case 'treatment':   $out = imp_treatment($row, $map, $targetBranch); break;
                default: throw new RuntimeException('Jenis import tidak dikenal.');
            }
            $res[$out['status'] === 'updated' ? 'updated' : 'success']++;
            foreach ($out['warnings'] ?? [] as $w) {
                $res['warnings']++;
                $res['errors'][] = ['row' => $rowNo, 'sev' => 'warning', 'msg' => $w, 'raw' => implode(' | ', array_slice($row, 0, 8))];
            }
        } catch (Throwable $ex) {
            $res['failed']++;
            $res['errors'][] = ['row' => $rowNo, 'sev' => 'error', 'msg' => $ex->getMessage(), 'raw' => implode(' | ', array_slice($row, 0, 8))];
        }
    }
    return $res;
}

function imp_pasien(array $row, array $map, int $branch, array $user): array
{
    $name = cell($row, $map, 'nama');
    if ($name === '') throw new RuntimeException('Nama pasien kosong.');
    $nik = preg_replace('/\s+/', '', cell($row, $map, 'nik'));
    $phone = cell($row, $map, 'phone');
    $email = trim(cell($row, $map, 'email'));
    $warn = [];
    if ($nik !== '' && !preg_match('/^\d{8,20}$/', $nik)) throw new RuntimeException('NIK "' . $nik . '" tidak valid (harus 8–20 digit).');
    if ($phone !== '' && !preg_match('/^[0-9\+\-\s\(\)]{8,20}$/', $phone)) throw new RuntimeException('Nomor telepon "' . $phone . '" tidak valid.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email "' . $email . '" tidak valid.');
    $branchText = cell($row, $map, 'branch');
    if ($branchText !== '' && is_owner_level()) {
        $b = find_branch_by_text($branchText);
        if ($b) $branch = (int)$b['id'];
        else $warn[] = 'Cabang "' . $branchText . '" tidak dikenal — memakai cabang tujuan yang dipilih.';
    } elseif ($branchText !== '' && !is_owner_level()) {
        $b = find_branch_by_text($branchText);
        if ($b && (int)$b['id'] !== $branch) throw new RuntimeException('Baris ini untuk cabang "' . $branchText . '", sedangkan akun Anda hanya boleh mengisi cabang sendiri.');
    }
    $gender = norm_gender(cell($row, $map, 'gender'));
    if ($gender === '' && cell($row, $map, 'gender') !== '') $warn[] = 'Jenis kelamin "' . cell($row, $map, 'gender') . '" tidak dikenali — dibiarkan kosong.';
    $birth = parse_date_cell(cell($row, $map, 'birth'));
    if ($birth === '' && cell($row, $map, 'birth') !== '') $warn[] = 'Tanggal lahir "' . cell($row, $map, 'birth') . '" tidak dapat dibaca — dibiarkan kosong.';

    /* Cari data yang sudah ada: NIK -> telepon -> nomor member.
       PEMBATASAN CABANG (audit isolasi ronde 55): pencocokan hanya mencari di CABANG
       TUJUAN impor. Bila NIK/telepon ternyata milik pasien CABANG LAIN, baris itu
       DITOLAK dengan pesan yang jelas (bukan diam-diam menimpa data cabang lain).
       Perilaku lama (mencari lintas cabang) aman karena diperiksa assert_branch, tetapi
       pesannya membingungkan dan pencariannya sendiri tidak dibatasi. */
    $existing = null;
    $br = (int)$branch > 0 ? ' AND branch_id = ' . (int)$branch : '';
    $cariPasien = function (string $kolom, string $nilai) use ($br, $branch) {
        $row = one('SELECT * FROM patients WHERE ' . $kolom . ' = ?' . $br . ' LIMIT 1', [$nilai]);
        if ($row) return $row;
        $lain = one('SELECT id, branch_id, name FROM patients WHERE ' . $kolom . ' = ? LIMIT 1', [$nilai]);
        if ($lain && (int)$lain['branch_id'] !== (int)$branch) {
            throw new RuntimeException('Data dengan ' . $kolom . ' itu sudah terdaftar sebagai pasien '
                . 'cabang lain (' . (string)$lain['name'] . '). Impor ini untuk cabang yang Anda pilih — '
                . 'periksa kembali atau ubah cabang tujuan impor.');
        }
        return null;
    };
    if ($nik !== '') $existing = $cariPasien('nik', $nik);
    if (!$existing) { $p = cell($row, $map, 'phone'); if ($p !== '') $existing = $cariPasien('phone', $p); }
    if (!$existing) { $m = cell($row, $map, 'member'); if ($m !== '') $existing = $cariPasien('member_number', $m); }

    $data = [
        'name' => $name,
        'gender' => $gender !== '' ? $gender : null,
        'nik' => $nik !== '' ? $nik : null,
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'address' => cell($row, $map, 'address') ?: null,
        'birth_date' => $birth !== '' ? $birth : null,
        'patient_type' => norm_patient_type(cell($row, $map, 'type')),
        'branch_id' => $branch,
    ];
    if ($existing) {
        assert_branch((int)$existing['branch_id']);
        $sets = []; $params = [];
        foreach ($data as $k => $v) {
            if ($k === 'branch_id') continue;
            if ($v === null || $v === '') continue;         // jangan kosongkan data lama
            $sets[] = "$k = ?"; $params[] = $v;
        }
        if ($sets) {
            $params[] = $existing['id'];
            q('UPDATE patients SET ' . implode(', ', $sets) . ', updated_at = datetime("now","localtime") WHERE id = ?', $params);
        }
        return ['status' => 'updated', 'warnings' => $warn, 'id' => (int)$existing['id']];
    }
    $pnum = next_patient_number($branch);
    $mnum = cell($row, $map, 'member') ?: next_member_number($branch);
    q('INSERT INTO patients (patient_number, member_number, name, gender, nik, address, phone, email, patient_type, birth_date, branch_id, created_by, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
        [$pnum, $mnum, $name, $data['gender'], $data['nik'], $data['address'], $data['phone'], $data['email'], $data['patient_type'], $data['birth_date'], $branch, $user['id']]);
    return ['status' => 'created', 'warnings' => $warn, 'id' => (int)db()->lastInsertId()];
}

function imp_lookup_pasien(array $row, array $map, int $branch, array $user, array &$warn): ?array
{
    $nomor = cell($row, $map, 'nomor_pasien');
    $nik = preg_replace('/\s+/', '', cell($row, $map, 'nik'));
    $telp = cell($row, $map, 'telepon');
    $nama = cell($row, $map, 'nama_pasien');
    [$bs, $bp] = branch_sql('branch_id');
    $find = function (string $sql, array $p) use ($bp) {
        return one($sql, array_merge($p, $bp));
    };
    $found = null;
    if ($nomor !== '') $found = $find('SELECT * FROM patients WHERE patient_number = ?' . $bs, [$nomor]);
    if (!$found && $nik !== '') $found = $find('SELECT * FROM patients WHERE nik = ?' . $bs, [$nik]);
    if (!$found && $telp !== '') $found = $find('SELECT * FROM patients WHERE phone = ?' . $bs, [$telp]);
    if (!$found && $nama !== '') $found = $find('SELECT * FROM patients WHERE name = ?' . $bs, [$nama]);
    if ($found) return $found;
    if ($nama === '' && $nik === '' && $telp === '' && $nomor === '') {
        throw new RuntimeException('Kolom pasien kosong (isi minimal nama, NIK, atau nomor pasien).');
    }
    // buat pasien baru sesuai pilihan user
    if ($nama === '') throw new RuntimeException('Pasien belum terdaftar dan nama kosong — tidak dapat dibuat otomatis.');
    $pnum = next_patient_number($branch);
    $mnum = next_member_number($branch);
    $emailBaru = trim(cell($row, $map, 'email'));
    q('INSERT INTO patients (patient_number, member_number, name, nik, phone, email, patient_type, branch_id, created_by, created_at)
       VALUES (?,?,?,?,?,?,"Baru",?,?,datetime("now","localtime"))',
        [$pnum, $mnum, $nama, $nik !== '' ? $nik : null, $telp !== '' ? $telp : null,
         ($emailBaru !== '' && filter_var($emailBaru, FILTER_VALIDATE_EMAIL)) ? $emailBaru : null,
         $branch, $user['id']]);
    $newId = (int)db()->lastInsertId();
    $warn[] = 'Pasien "' . $nama . '" belum terdaftar — dibuat otomatis dengan nomor ' . $pnum . '.';
    return one('SELECT * FROM patients WHERE id = ?', [$newId]);
}

function imp_rekam_medis(array $row, array $map, int $branch, array $user): array
{
    $warn = [];
    $tglRaw = cell($row, $map, 'tanggal');
    $date = parse_date_cell($tglRaw);
    if ($tglRaw !== '' && $date === '') throw new RuntimeException('Tanggal "' . $tglRaw . '" tidak dapat dibaca.');
    if ($date === '') { $date = date('Y-m-d'); $warn[] = 'Tanggal kosong — memakai tanggal hari ini.'; }
    $pat = imp_lookup_pasien($row, $map, $branch, $user, $warn);

    $icd10 = strtoupper(clean_cell(cell($row, $map, 'icd10')));
    $icd9  = clean_cell(cell($row, $map, 'icd9'));
    $r10 = $icd10 !== '' ? icd_lookup('icd10', $icd10) : null;
    if ($icd10 !== '' && !$r10) throw new RuntimeException('Kode ICD-10 "' . $icd10 . '" tidak ada di kamus resmi.');
    $r9 = $icd9 !== '' ? icd_lookup('icd9cm', $icd9) : null;
    if ($icd9 !== '' && !$r9) throw new RuntimeException('Kode ICD-9-CM "' . $icd9 . '" tidak ada di kamus resmi.');

    // dokter / terapis dicocokkan berdasarkan nama pada cabang ini
    $docName = cell($row, $map, 'dokter');
    $thName  = cell($row, $map, 'terapis');
    $doc = $docName !== '' ? one('SELECT * FROM doctors WHERE name = ? AND branch_id = ? LIMIT 1', [$docName, $branch]) : null;
    if ($docName !== '' && !$doc) $warn[] = 'Dokter "' . $docName . '" tidak ada di data dokter cabang ini — disimpan sebagai teks saja.';
    $th = $thName !== '' ? one('SELECT * FROM therapists WHERE name = ? AND branch_id = ? LIMIT 1', [$thName, $branch]) : null;
    if ($thName !== '' && !$th) $warn[] = 'Terapis "' . $thName . '" tidak ada di data terapis cabang ini — disimpan sebagai teks saja.';
    $staff = $doc['name'] ?? ($th['name'] ?? ($docName ?: $thName));

    $num = next_medical_number($branch);
    q('INSERT INTO medical_records (record_number, patient_id, doctor_id, therapist_id, staff_name, branch_id, date,
          subjective, objective, diagnosis, icd10, icd10_desc, action, icd9, icd9_desc, solution, status, created_by, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"final",?,datetime("now","localtime"))',
        [$num, (int)$pat['id'], $doc['id'] ?? null, $th['id'] ?? null, $staff ?: null, $branch, $date,
         cell($row, $map, 'subjektif') ?: null, cell($row, $map, 'objektif') ?: null,
         cell($row, $map, 'diagnosis') ?: null, $icd10 ?: null,
         $r10 ? (string)($r10['name_id'] ?: $r10['name_en']) : null,
         cell($row, $map, 'tindakan') ?: null, $icd9 ?: null,
         $r9 ? (string)($r9['name_id'] ?: $r9['name_en']) : null,
         cell($row, $map, 'solusi') ?: null, $user['id']]);
    /* Status pasien bisa berubah menjadi "Lama" bila impor ini menambah hari kunjungan. */
    try { patient_sync_type((int)$pat['id'], (int)$user['id']); } catch (Throwable $e) { /* lanjut */ }
    return ['status' => 'created', 'warnings' => $warn];
}

function imp_skincare(array $row, array $map, int $branch, array $opt): array
{
    $warn = [];
    $code = cell($row, $map, 'code');
    $name = cell($row, $map, 'name');
    if ($code === '') throw new RuntimeException('Kode produk kosong.');
    if ($name === '') throw new RuntimeException('Nama produk kosong.');
    $buy = parse_money_cell(cell($row, $map, 'purchase'));
    $sell = parse_money_cell(cell($row, $map, 'selling'));
    $min = parse_money_cell(cell($row, $map, 'min'));
    $stockRaw = cell($row, $map, 'stock');
    $stock = $stockRaw === '' ? null : parse_money_cell($stockRaw);
    if ($buy < 0 || $sell < 0 || $min < 0) throw new RuntimeException('Harga/stok minimum tidak boleh negatif.');
    if ($stock !== null && $stock < 0) throw new RuntimeException('Stok tidak boleh negatif.');
    $unit = cell($row, $map, 'unit') ?: 'pcs';
    $cat = cell($row, $map, 'category') ?: null;
    $sup = cell($row, $map, 'supplier') ?: null;
    $status = norm_status(cell($row, $map, 'status'));

    $ex = one('SELECT * FROM skincare_products WHERE code = ? AND branch_id = ?', [$code, $branch]);
    if ($ex) {
        q('UPDATE skincare_products SET name=?, category=?, purchase_price=?, selling_price=?, minimum_stock=?, unit=?, supplier_name=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
            [$name, $cat, $buy, $sell, $min, $unit, $sup, $status, $ex['id']]);
        q('UPDATE inventory SET minimum_stock=?, status=? WHERE item_type="skincare" AND item_id=?', [$min, $status, $ex['id']]);
        if ($stock !== null) {
            $cur = (float)scalar('SELECT stock FROM skincare_products WHERE id = ?', [$ex['id']]);
            if ($opt['stock_mode'] === 'add') {
                if ($stock > 0) inv_apply('skincare', (int)$ex['id'], $stock, 'Pembelian', 'Import ' . $opt['filename'] . ': tambah stok');
            } elseif (abs($stock - $cur) > 0.0001) {
                inv_apply('skincare', (int)$ex['id'], $stock - $cur, 'Adjustment',
                    'Import ' . $opt['filename'] . ': stok akhir dari file (' . qty_text($cur) . ' → ' . qty_text($stock) . ')');
            }
        }
        return ['status' => 'updated', 'warnings' => $warn];
    }
    $open = $stock ?? 0;
    q('INSERT INTO skincare_products (code, name, category, purchase_price, selling_price, stock, minimum_stock, unit, supplier_name, branch_id, status, created_at)
       VALUES (?,?,?,?,?,0,?,?,?,?,?,datetime("now","localtime"))',
        [$code, $name, $cat, $buy, $sell, $min, $unit, $sup, $branch, $status]);
    $id = (int)db()->lastInsertId();
    if ($open > 0) {
        inv_apply('skincare', $id, $open, $opt['stock_mode'] === 'add' ? 'Pembelian' : 'Stok Awal',
            'Import ' . $opt['filename'] . ': produk baru' . ($opt['stock_mode'] === 'add' ? ' (stok awal dari file)' : ''));
    } else {
        q('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status) VALUES ("skincare",?,?,0,?,?)', [$id, $branch, $min, $status]);
    }
    return ['status' => 'created', 'warnings' => $warn];
}

function imp_bahan(array $row, array $map, int $branch, array $opt): array
{
    $warn = [];
    $code = cell($row, $map, 'code');
    $name = cell($row, $map, 'name');
    if ($code === '') throw new RuntimeException('Kode bahan kosong.');
    if ($name === '') throw new RuntimeException('Nama bahan kosong.');
    $price = parse_money_cell(cell($row, $map, 'price'));
    $min = parse_money_cell(cell($row, $map, 'min'));
    $stockRaw = cell($row, $map, 'stock');
    $stock = $stockRaw === '' ? null : parse_money_cell($stockRaw);
    if ($price < 0 || $min < 0) throw new RuntimeException('Harga/stok minimum tidak boleh negatif.');
    if ($stock !== null && $stock < 0) throw new RuntimeException('Stok tidak boleh negatif.');
    $unit = cell($row, $map, 'unit') ?: 'pcs';
    $status = norm_status(cell($row, $map, 'status'));
    $ex = one('SELECT * FROM treatment_materials WHERE code = ? AND branch_id = ?', [$code, $branch]);
    if ($ex) {
        q('UPDATE treatment_materials SET name=?, category=?, price=?, minimum_stock=?, unit=?, supplier_name=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
            [$name, cell($row, $map, 'category') ?: null, $price, $min, $unit, cell($row, $map, 'supplier') ?: null, $status, $ex['id']]);
        q('UPDATE inventory SET minimum_stock=?, status=? WHERE item_type="material" AND item_id=?', [$min, $status, $ex['id']]);
        if ($stock !== null) {
            $cur = (float)scalar('SELECT stock FROM treatment_materials WHERE id = ?', [$ex['id']]);
            if ($opt['stock_mode'] === 'add') {
                if ($stock > 0) inv_apply('material', (int)$ex['id'], $stock, 'Pembelian', 'Import ' . $opt['filename'] . ': tambah stok');
            } elseif (abs($stock - $cur) > 0.0001) {
                inv_apply('material', (int)$ex['id'], $stock - $cur, 'Adjustment',
                    'Import ' . $opt['filename'] . ': stok akhir dari file (' . qty_text($cur) . ' → ' . qty_text($stock) . ')');
            }
        }
        return ['status' => 'updated', 'warnings' => $warn];
    }
    $open = $stock ?? 0;
    q('INSERT INTO treatment_materials (code, name, category, stock, minimum_stock, unit, price, supplier_name, branch_id, status, created_at)
       VALUES (?,?,?,0,?,?,?,?,?,?,datetime("now","localtime"))',
        [$code, $name, cell($row, $map, 'category') ?: null, $min, $unit, $price, cell($row, $map, 'supplier') ?: null, $branch, $status]);
    $id = (int)db()->lastInsertId();
    if ($open > 0) {
        inv_apply('material', $id, $open, $opt['stock_mode'] === 'add' ? 'Pembelian' : 'Stok Awal',
            'Import ' . $opt['filename'] . ': bahan baru');
    } else {
        q('INSERT INTO inventory (item_type, item_id, branch_id, stock, minimum_stock, status) VALUES ("material",?,?,0,?,?)', [$id, $branch, $min, $status]);
    }
    return ['status' => 'created', 'warnings' => $warn];
}

function imp_treatment(array $row, array $map, int $branch): array
{
    $code = cell($row, $map, 'code');
    $name = cell($row, $map, 'name');
    if ($code === '') throw new RuntimeException('Kode treatment kosong.');
    if ($name === '') throw new RuntimeException('Nama treatment kosong.');
    $np = parse_money_cell(cell($row, $map, 'normal_price'));
    $pp = parse_money_cell(cell($row, $map, 'promo_price'));
    $dur = (int)parse_money_cell(cell($row, $map, 'duration'));
    if ($np < 0 || $pp < 0) throw new RuntimeException('Harga tidak boleh negatif.');
    if ($dur < 0) throw new RuntimeException('Durasi tidak boleh negatif.');
    if ($dur === 0) $dur = 60;
    $cat = cell($row, $map, 'category') ?: null;
    $status = norm_status(cell($row, $map, 'status'));
    /* HPP: hanya diubah bila selnya benar-benar berisi angka. Sel kosong berarti
       "jangan ubah" sehingga impor untuk memperbarui harga TIDAK menghapus HPP
       yang sudah diisi (pernah menjadi risiko: HPP hilang → laba kacau). */
    $hppCell = trim((string)cell($row, $map, 'hpp'));
    $hpp = $hppCell === '' ? null : max(0.0, parse_money_cell($hppCell));
    $ex = one('SELECT * FROM treatments WHERE code = ? AND branch_id = ?', [$code, $branch]);
    if ($ex) {
        if ($hpp === null) {
            q('UPDATE treatments SET name=?, category=?, normal_price=?, promo_price=?, duration=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                [$name, $cat, $np, $pp, $dur, $status, $ex['id']]);
        } else {
            q('UPDATE treatments SET name=?, category=?, normal_price=?, promo_price=?, hpp=?, duration=?, status=?, updated_at=datetime("now","localtime") WHERE id=?',
                [$name, $cat, $np, $pp, $hpp, $dur, $status, $ex['id']]);
        }
        return ['status' => 'updated', 'warnings' => []];
    }
    q('INSERT INTO treatments (code, name, category, normal_price, promo_price, hpp, duration, branch_id, status, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
        [$code, $name, $cat, $np, $pp, (float)($hpp ?? 0), $dur, $branch, $status]);
    return ['status' => 'created', 'warnings' => []];
}

/* ------------------------------------------------------------------ *
 * Routing
 * ------------------------------------------------------------------ */
$types = import_types();
$type  = gp('type', 'pasien');
if (!isset($types[$type])) $type = 'pasien';
$meta = $types[$type];
require_perm($meta['perm']);
$scope = scope_branch();

/* target branch */
$targetBranch = $scope;
if ($targetBranch === null) {
    $tb = (int)gp('branch_id');
    if (!$tb) $tb = (int)(branches()[0]['id'] ?? 0);
    $targetBranch = $tb;
}
if (!$targetBranch) die('Tidak ada cabang tujuan.');
assert_branch($targetBranch);

$errors = [];

/* ---- Unduh template ---- */
if (gp('template') !== '') {
    $fmt = gp('format', 'xlsx');
    $headers = array_map(fn($f) => $f['label'], $meta['fields']);
    $rowsOut = [$headers, $meta['contoh']];
    $slug = str_replace('_', '-', $type);
    if ($fmt === 'csv') {
        audit('Unduh Template Import', 'Import Data', null, null, ['type' => $type], 'Template CSV diunduh');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="template-import-' . $slug . '.csv"');
        echo "\xEF\xBB\xBF";
        $o = fopen('php://output', 'w');
        foreach ($rowsOut as $r) fputcsv($o, $r);
        fclose($o);
        exit;
    }
    $bin = xlsx_write($rowsOut, 'Template');
    audit('Unduh Template Import', 'Import Data', null, null, ['type' => $type], 'Template Excel diunduh');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template-import-' . $slug . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

/* ---- Unduh daftar error batch ---- */
if (gp('errors') !== '') {
    $bid = (int)gp('errors');
    $batch = one('SELECT * FROM import_batches WHERE id = ?', [$bid]);
    if (!$batch) {
        flash('Riwayat import tidak ditemukan.', 'error');
        header('Location: import.php');
        exit;
    }
    $list = all('SELECT * FROM import_errors WHERE batch_id = ? ORDER BY row_no, id', [$bid]);
    audit('Unduh Laporan Import', 'Import Data', $bid, null, ['baris' => count($list)], 'Unduh rincian error import');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-import-' . $bid . '-' . $batch['type'] . '.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Baris', 'Tingkat', 'Pesan', 'Data']);
    foreach ($list as $l) fputcsv($o, [$l['row_no'], $l['severity'] === 'warning' ? 'Peringatan' : 'Gagal', $l['message'], $l['raw']]);
    fclose($o);
    exit;
}

/* ---- Batalkan sesi import ---- */
if (gp('cancel') !== '') {
    if (!empty($_SESSION['imp']['file']) && is_file($_SESSION['imp']['file'])) @unlink($_SESSION['imp']['file']);
    unset($_SESSION['imp']);
    flash('Proses import dibatalkan. Tidak ada data yang diubah.');
    header('Location: import.php?type=' . $type);
    exit;
}

/* ---- Langkah 1: unggah + pratinjau ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    verify_csrf();
    try {
        if (empty($_FILES['file']['name']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Pilih file yang akan diimport terlebih dahulu.');
        }
        $orig = (string)$_FILES['file']['name'];
        $size = (int)$_FILES['file']['size'];
        if ($size > IMPORT_MAX_BYTES) throw new RuntimeException('Ukuran file melebihi batas ' . num(IMPORT_MAX_BYTES / 1048576) . ' MB.');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            throw new RuntimeException('Format .xls (Excel lama/BIFF) belum didukung. Buka file di Excel lalu pilih "Simpan Sebagai" → Excel Workbook (.xlsx) atau CSV, kemudian unggah kembali.');
        }
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt', 'tsv', 'json'], true)) {
            throw new RuntimeException('Format .' . $ext . ' belum didukung. Gunakan Excel (.xlsx), CSV, TXT, atau JSON.');
        }
        $dir = import_tmp_dir();
        $tmp = $dir . '/import-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) throw new RuntimeException('Gagal menyimpan file sementara.');

        $data = read_tabular($tmp, $orig);
        $map = auto_map_columns($data['keys'], array_map(fn($f) => $f['aliases'], $meta['fields']));

        $_SESSION['imp'] = [
            'type' => $type, 'file' => $tmp, 'orig' => $orig, 'ext' => $ext,
            'headers' => $data['headers'], 'rowcount' => count($data['rows']),
            'branch' => $targetBranch,
        ];
        $preview = array_slice($data['rows'], 0, 15);
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: import.php?type=' . $type);
        exit;
    }
}

/* ---- Langkah 2: jalankan import ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    verify_csrf();
    try {
        $ctx = $_SESSION['imp'] ?? null;
        if (!$ctx || !is_file($ctx['file'])) throw new RuntimeException('Sesi import sudah berakhir. Silakan unggah ulang file.');
        $mapIn = (array)($_POST['map'] ?? []);
        $map = [];
        foreach ($meta['fields'] as $f => $_) {
            if (isset($mapIn[$f]) && $mapIn[$f] !== '') $map[$f] = (int)$mapIn[$f];
        }
        foreach ($meta['fields'] as $f => $def) {
            if (!empty($def['wajib']) && !isset($map[$f])) {
                throw new RuntimeException('Kolom "' . $def['label'] . '" harus dipetakan ke salah satu kolom file.');
            }
        }
        $stockMode = ($_POST['stock_mode'] ?? 'overwrite') === 'add' ? 'add' : 'overwrite';
        $branch = is_owner_level() ? (int)($_POST['branch_id'] ?? $ctx['branch']) : (int)user_branch();
        assert_branch($branch);

        $data = read_tabular($ctx['file'], $ctx['orig']);
        $opt = ['filename' => $ctx['orig'], 'stock_mode' => $stockMode];
        $res = do_import($type, $data, $map, $opt, $branch, $user);

        q('INSERT INTO import_batches (type, filename, file_type, branch_id, user_id, user_name, options, total, success, updated, failed, warnings, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime("now","localtime"))',
            [$type, $ctx['orig'], $ctx['ext'], $branch, $user['id'], $user['name'],
             json_encode(['stock_mode' => $stockMode, 'map' => $map], JSON_UNESCAPED_UNICODE),
             count($data['rows']), $res['success'], $res['updated'], $res['failed'], $res['warnings']]);
        $bid = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO import_errors (batch_id, row_no, severity, message, raw) VALUES (?,?,?,?,?)');
        foreach (array_slice($res['errors'], 0, 1000) as $er) {
            $ins->execute([$bid, $er['row'], $er['sev'], $er['msg'], $er['raw']]);
        }
        audit('Import Data', 'Import Data', $bid, null, [
            'jenis' => $type, 'file' => $ctx['orig'], 'total' => count($data['rows']),
            'baru' => $res['success'], 'diperbarui' => $res['updated'], 'gagal' => $res['failed'],
        ], 'Import dari file ' . $ctx['orig']);
        @unlink($ctx['file']);
        unset($_SESSION['imp']);
        flash('Import selesai: ' . num($res['success']) . ' data baru, ' . num($res['updated']) . ' diperbarui, '
            . num($res['failed']) . ' gagal' . ($res['warnings'] ? ', ' . num($res['warnings']) . ' peringatan' : '') . '.',
            $res['failed'] > 0 ? 'warning' : 'success');
        header('Location: import.php?type=' . $type . '&batch=' . $bid);
        exit;
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        header('Location: import.php?type=' . $type);
        exit;
    }
}

/* ---- Data tampilan ---- */
$ctx = $_SESSION['imp'] ?? null;
if ($ctx && $ctx['type'] !== $type) $ctx = null;
if ($ctx && !is_file($ctx['file'])) { unset($_SESSION['imp']); $ctx = null; }
$preview = [];
$previewRaw = [];
if ($ctx) {
    try {
        $d = read_tabular($ctx['file'], $ctx['orig']);
        $previewRaw = array_slice($d['rows'], 0, 15);
        $autoMap = auto_map_columns($d['keys'], array_map(fn($f) => $f['aliases'], $meta['fields']));
        $preview = ['headers' => $d['headers'], 'keys' => $d['keys'], 'rows' => $previewRaw, 'total' => count($d['rows'])];
        if (empty($_POST['map'])) $_POST['map'] = $autoMap;
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        unset($_SESSION['imp']);
        $ctx = null;
    }
}
$batch = null;
$batchErrors = [];
if ((int)gp('batch') > 0) {
    $batch = one('SELECT b.*, br.name AS branch_name FROM import_batches b LEFT JOIN branches br ON br.id = b.branch_id WHERE b.id = ?', [(int)gp('batch')]);
    if ($batch) $batchErrors = all('SELECT * FROM import_errors WHERE batch_id = ? ORDER BY severity DESC, row_no LIMIT 200', [(int)gp('batch')]);
}
$histWhere = '';
$histParams = [];
if ($scope !== null) { $histWhere = ' WHERE b.branch_id = ?'; $histParams[] = $scope; }
$history = all('SELECT b.*, br.name AS branch_name FROM import_batches b LEFT JOIN branches br ON br.id = b.branch_id'
    . $histWhere . ' ORDER BY b.id DESC LIMIT 12', $histParams);

page_head('Import Data', 'import');
?>
<div class="page-head">
  <div>
    <h2>Import Data dari Excel / CSV</h2>
    <p class="muted">Pindahkan data lama ke sistem: pasien, rekam medis, dan inventory. Didukung .xlsx, .csv, .txt, dan .json.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="export.php?type=<?= e($type === 'pasien' ? 'pasien' : ($type === 'skincare' ? 'skincare' : ($type === 'treatment' ? 'treatment' : 'inventory'))) ?>&format=csv"><?= icon('download') ?> Contoh hasil export</a>
  </div>
</div>

<div class="tabs">
  <?php foreach ($types as $k => $t): ?>
    <a class="tab<?= $k === $type ? ' active' : '' ?>" href="import.php?type=<?= e($k) ?>"><?= icon($t['icon']) ?> <?= e($t['label']) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-head"><h3>1. Unduh Template (opsional tapi disarankan)</h3>
    <span class="muted">Isi data mulai baris ke-2</span></div>
  <div class="card-body">
    <p class="muted"><?= e($meta['keterangan']) ?></p>
    <div class="flex flex-wrap gap-sm">
      <a class="btn btn-leaf" href="import.php?type=<?= e($type) ?>&template=1&format=xlsx"><?= icon('download') ?> Template Excel (.xlsx)</a>
      <a class="btn" href="import.php?type=<?= e($type) ?>&template=1&format=csv"><?= icon('download') ?> Template CSV</a>
    </div>
    <div class="table-wrap mt-2">
      <table class="tbl">
        <thead><tr><th>Kolom</th><th>Wajib</th><th>Contoh Isi</th></tr></thead>
        <tbody>
        <?php foreach ($meta['fields'] as $f => $def): ?>
          <tr>
            <td><strong><?= e($def['label']) ?></strong><div class="small muted"><?= e(implode(', ', array_slice($def['aliases'], 0, 4))) ?></div></td>
            <td><?= !empty($def['wajib']) ? badge('Wajib', 'red') : badge('Opsional', 'gray') ?></td>
            <td class="small"><?= e($meta['contoh'][array_search($f, array_keys($meta['fields']), true)] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="notice mt-2">Nama kolom di file tidak harus sama persis — sistem mencocokkan otomatis (mis. "No HP" atau "telepon" sama-sama dikenali sebagai Telepon) dan Anda tetap bisa mengubah pemetaannya di langkah berikut.</div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>2. Unggah File</h3></div>
  <form method="post" enctype="multipart/form-data" data-loading="1">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
    <div class="card-body">
      <div class="form-grid g3">
        <div class="field"><label>File Data <span class="req">*</span></label>
          <input class="input" type="file" name="file" accept=".xlsx,.xlsm,.csv,.txt,.tsv,.json" required>
          <span class="hint">Maksimal <?= num(IMPORT_MAX_BYTES / 1048576) ?> MB · maksimal <?= num(IMPORT_MAX_ROWS) ?> baris per proses.</span></div>
        <?php if (is_owner_level()): ?>
          <div class="field"><label>Cabang Tujuan <span class="req">*</span></label>
            <select class="input" name="branch_id"><?= opt_branches($targetBranch) ?></select>
            <span class="hint">Data akan masuk ke cabang ini (kecuali kolom Cabang di file menyebut cabang lain).</span></div>
        <?php else: ?>
          <div class="field"><label>Cabang Tujuan</label>
            <input class="input" value="<?= e((string)scalar('SELECT name FROM branches WHERE id = ?', [$targetBranch], '-')) ?>" disabled>
            <span class="hint">Akun Anda hanya dapat mengisi data untuk cabang ini.</span></div>
        <?php endif; ?>
        <div class="field"><label>&nbsp;</label>
          <button class="btn btn-primary btn-block" type="submit"><?= icon('upload') ?> Baca File &amp; Pratinjau</button></div>
      </div>
      <div class="notice mt-2">Di Excel, format kolom NIK dan nomor telepon sebagai <strong>Text</strong> supaya angka nol di depan / digit panjang tidak berubah.</div>
    </div>
  </form>
</div>

<?php if ($preview): ?>
<div class="card" id="step3">
  <div class="card-head"><h3>3. Petakan Kolom &amp; Jalankan</h3>
    <span class="muted"><?= e($ctx['orig']) ?> · <?= num($preview['total']) ?> baris data terbaca</span></div>
  <form method="post" id="runForm" data-loading="1" data-confirm="Jalankan import sekarang? Pastikan pemetaan kolom sudah benar.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="run">
    <div class="card-body">
      <?php if (is_owner_level()): ?>
        <div class="form-grid g3">
          <div class="field"><label>Cabang Tujuan</label>
            <select class="input" name="branch_id"><?= opt_branches($ctx['branch']) ?></select></div>
          <?php if (in_array($type, ['skincare', 'bahan'], true)): ?>
          <div class="field"><label>Perlakuan Angka Stok di File</label>
            <select class="input" name="stock_mode">
              <option value="overwrite">Stok akhir — menimpa stok sistem (selisih dicatat sebagai Adjustment)</option>
              <option value="add">Tambahan stok — ditambahkan ke stok yang ada (dicatat sebagai Pembelian)</option>
            </select></div>
          <?php endif; ?>
        </div>
      <?php elseif (in_array($type, ['skincare', 'bahan'], true)): ?>
        <div class="form-grid g2">
          <div class="field"><label>Perlakuan Angka Stok di File</label>
            <select class="input" name="stock_mode">
              <option value="overwrite">Stok akhir — menimpa stok sistem (selisih dicatat sebagai Adjustment)</option>
              <option value="add">Tambahan stok — ditambahkan ke stok yang ada (dicatat sebagai Pembelian)</option>
            </select>
            <span class="hint">Pilih <strong>Tambahan stok</strong> bila file berisi barang yang baru datang, dan <strong>Stok akhir</strong> bila file adalah hasil stock opname.</span></div>
        </div>
      <?php endif; ?>

      <div class="section-title">Pemetaan Kolom</div>
      <div class="form-grid g3">
        <?php foreach ($meta['fields'] as $f => $def): ?>
          <div class="field">
            <label><?= e($def['label']) ?> <?= !empty($def['wajib']) ? '<span class="req">*</span>' : '' ?></label>
            <select class="input" name="map[<?= e($f) ?>]">
              <option value="">— tidak dipakai —</option>
              <?php foreach ($preview['headers'] as $i => $h): ?>
                <option value="<?= $i ?>"<?= (string)($_POST['map'][$f] ?? '') === (string)$i ? ' selected' : '' ?>>
                  Kolom <?= e(chr(65 + min(25, $i))) ?>: <?= e($h !== '' ? $h : '(tanpa judul)') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="section-title">Pratinjau (15 baris pertama)</div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>#</th><?php foreach ($preview['headers'] as $h): ?><th><?= e($h !== '' ? $h : '(tanpa judul)') ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($preview['rows'] as $i => $r): ?>
            <tr><td class="small muted"><?= $i + 2 ?></td>
              <?php foreach ($preview['headers'] as $j => $h): ?><td class="small"><?= e((string)($r[$j] ?? '')) ?></td><?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($preview['total'] > count($preview['rows'])): ?>
        <p class="muted mt-1">…dan <?= num($preview['total'] - count($preview['rows'])) ?> baris lainnya akan ikut diproses.</p>
      <?php endif; ?>
    </div>
    <div class="modal-foot" style="border-radius:0 0 var(--radius) var(--radius);justify-content:space-between">
      <a class="btn" href="import.php?type=<?= e($type) ?>&cancel=1">Batalkan</a>
      <button class="btn btn-primary" type="submit" id="btnRunImport"><?= icon('upload') ?> Import <?= num($preview['total']) ?> Baris</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($preview): ?>
<script>
/* Setelah file dibaca, halaman cukup panjang — arahkan perhatian ke bagian pemetaan.
   Gulirannya SENGAJA tanpa animasi (behavior 'auto'): animasi gulir membuat seluruh
   halaman bergerak beberapa detik sehingga tombol sulit ditekan (dan membuat pengujian
   otomatis gagal dengan "element is not stable").
   Disorot dengan garis tepi, bukan dengan mengubah style berulang. */
document.addEventListener('DOMContentLoaded', function () {
  var s = document.getElementById('step3');
  if (!s) return;
  s.scrollIntoView({ block: 'start' });
  s.classList.add('flash-target');
  setTimeout(function () { s.classList.remove('flash-target'); }, 2200);
});
</script>
<?php endif; ?>

<?php if ($batch): ?>
<div class="card">
  <div class="card-head"><h3>Hasil Import #<?= (int)$batch['id'] ?></h3>
    <a class="btn btn-sm" href="import.php?errors=<?= (int)$batch['id'] ?>"><?= icon('download') ?> Unduh Rincian (CSV)</a></div>
  <div class="card-body">
    <div class="grid g4">
      <div class="stat leaf"><span class="lbl">Data Baru</span><span class="val"><?= num($batch['success']) ?></span><span class="sub">ditambahkan</span></div>
      <div class="stat"><span class="lbl">Diperbarui</span><span class="val"><?= num($batch['updated']) ?></span><span class="sub">data lama diperbarui</span></div>
      <div class="stat <?= $batch['failed'] > 0 ? '' : 'leaf' ?>"><span class="lbl">Gagal</span><span class="val"><?= num($batch['failed']) ?></span><span class="sub">baris dilewati</span></div>
      <div class="stat"><span class="lbl">Peringatan</span><span class="val"><?= num($batch['warnings']) ?></span><span class="sub">tetap diimport, perlu ditinjau</span></div>
    </div>
    <p class="muted mt-2">File: <?= e($batch['filename']) ?> · Cabang: <?= e($batch['branch_name'] ?: '-') ?> · Oleh: <?= e($batch['user_name']) ?> · <?= e(tgl($batch['created_at'], true)) ?></p>

    <?php if ($batchErrors): ?>
      <div class="section-title">Rincian Baris Bermasalah</div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Baris</th><th>Tingkat</th><th>Pesan</th><th>Data</th></tr></thead>
          <tbody>
          <?php foreach ($batchErrors as $er): ?>
            <tr>
              <td class="small"><?= (int)$er['row_no'] ?></td>
              <td><?= $er['severity'] === 'warning' ? badge('Peringatan', 'yellow') : badge('Gagal', 'red') ?></td>
              <td><?= e($er['message']) ?></td>
              <td class="small muted"><?= e(item_short((string)$er['raw'], 70)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted mt-1">Perbaiki baris di atas pada file Anda, lalu ulangi import hanya untuk baris tersebut.</p>
    <?php else: ?>
      <div class="alert alert-success">Tidak ada baris bermasalah. Seluruh data berhasil diproses.</div>
    <?php endif; ?>
    <div class="flex mt-2">
      <a class="btn btn-primary" href="<?= e($meta['dasar']) ?>">Lihat <?= e($meta['label']) ?></a>
      <a class="btn" href="import.php?type=<?= e($type) ?>">Import Lagi</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card tight">
  <div class="card-head"><h3>Riwayat Import</h3><span class="muted">12 terakhir</span></div>
  <div class="table-wrap">
    <?php if (!$history): ?>
      <?= empty_state('Belum ada riwayat import.') ?>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>#</th><th>Waktu</th><th>Jenis</th><th>File</th><th>Cabang</th><th>Oleh</th><th class="num">Baris</th><th class="num">Baru</th><th class="num">Update</th><th class="num">Gagal</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($history as $h):
          $lbl = $types[$h['type']]['label'] ?? $h['type']; ?>
          <tr>
            <td class="small"><?= (int)$h['id'] ?></td>
            <td class="small nowrap"><?= e(tgl($h['created_at'], true)) ?></td>
            <td class="small"><?= e($lbl) ?></td>
            <td class="small"><?= e(item_short((string)$h['filename'], 34)) ?></td>
            <td class="small"><?= e($h['branch_name'] ?: '-') ?></td>
            <td class="small"><?= e($h['user_name']) ?></td>
            <td class="num"><?= num($h['total']) ?></td>
            <td class="num"><?= num($h['success']) ?></td>
            <td class="num"><?= num($h['updated']) ?></td>
            <td class="num"><?= (int)$h['failed'] > 0 ? '<strong>' . num($h['failed']) . '</strong>' : num($h['failed']) ?></td>
            <td class="nowrap"><div class="row-actions">
              <a class="btn btn-sm" href="import.php?type=<?= e($h['type']) ?>&batch=<?= (int)$h['id'] ?>">Lihat</a>
              <?php if ((int)$h['failed'] > 0 || (int)$h['warnings'] > 0): ?>
                <a class="btn btn-sm" href="import.php?errors=<?= (int)$h['id'] ?>">CSV</a>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="notice">
  Seluruh proses import tercatat di <a href="audit_log.php">Audit Log</a> (jumlah baris, nama file, cabang, dan siapa yang memproses).
  Perubahan stok dari import juga tercatat di <a href="inventory_movement.php">Inventory Movement</a> sehingga bisa ditelusuri.
</div>
<?php page_foot(); ?>
