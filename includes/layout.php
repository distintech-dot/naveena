<?php
/** Shared layout: sidebar, topbar, cards, modals. */

function brand_svg(int $size = 34): string
{
    return '<svg class="brand-mark" width="' . $size . '" height="' . (int)round($size * 0.8) . '" viewBox="0 0 100 80" aria-hidden="true">
      <path d="M12 46C14 24 30 10 46 6c4 24-6 38-34 40z" fill="#F4A7C8"/>
      <path d="M26 50C34 26 58 10 92 10c-4 30-32 46-66 40z" fill="#C2185B"/>
      <path d="M14 52c4 12 12 18 24 20-10 5-22 1-24-20z" fill="#A6CE39"/>
      <path d="M30 56c6 9 14 13 26 14-11 5-24 2-26-14z" fill="#8BC34A"/>
    </svg>';
}
/**
 * Logo klinik: memakai berkas yang diunggah Super Admin (Pengaturan > Identitas
 * Klinik) bila ada; kalau belum, tampilkan logo bawaan supaya tampilan tidak
 * kosong. Dipakai di sidebar, halaman login, struk, dan dokumen cetak.
 *
 * PENTING: logo bawaan yang disertakan aplikasi memuat TULISAN merek awal
 * ("naveena skincare"), jadi ia hanya dipakai selama nama klinik masih nama
 * bawaan tersebut. Setelah nama klinik diganti (mis. "Immoderma"), logo bawaan
 * TIDAK lagi ditampilkan — sebagai gantinya dipakai lambang netral + NAMA
 * KLINIK AKTIF (brand_block), agar tidak ada merek lama yang tertinggal di
 * sidebar/struk. Unggah logo sendiri di Pengaturan > Identitas Klinik.
 */
function brand_is_default_name(): bool
{
    return strcasecmp(clinic_name(), APP_NAME) === 0;
}
function brand_logo_src(): string
{
    $file = (string)setting('logo_file');
    $url  = (string)setting('logo_url');
    if ($file !== '') return 'logo.php?v=' . substr(md5($file), 0, 6);
    if ($url !== '' && preg_match('#^https?://#', $url)) return $url;
    // Logo bawaan aplikasi (memuat nama merek awal) — hanya bila namanya masih sama.
    if (brand_is_default_name() && is_readable(APP_DIR . '/assets/img/logo-naveena.png')) {
        return 'assets/img/logo-naveena.png';
    }
    return '';
}
function brand_block(bool $small = false): string
{
    $name = clinic_name();
    /* Nama klinik bisa panjang (mis. "Athena Beauty & Aesthetic Center").
       Kelas tambahan mengecilkan huruf + membungkus teks supaya sidebar, login,
       dan struk tetap rapi tanpa mendorong tata letak. */
    $len = (int)preg_match_all('/./us', $name);
    $cls = $len > 30 ? ' brand-xlong' : ($len > 18 ? ' brand-long' : '');
    $src = brand_logo_src();
    if ($src !== '') {
        return '<div class="brand brand-img' . ($small ? ' brand-sm' : '') . $cls . '">'
            . '<img class="logo-img" src="' . e($src) . '" alt="' . e($name) . '"></div>';
    }
    /* Tanpa logo: tampilkan nama klinik aktif (bukan nama bawaan yang ditulis
       mati di kode) supaya penggantian nama klinik ikut terlihat di sidebar. */
    return '<div class="brand' . ($small ? ' brand-sm' : '') . $cls . '">' . brand_svg($small ? 28 : 36) . '
      <div class="brand-text"><span class="brand-name">' . e($name) . '</span>'
      . (clinic_tagline() !== '' ? '<span class="brand-sub">' . e(clinic_tagline()) . '</span>' : '')
      . '</div></div>';
}

/**
 * Ubah teks pesan AI menjadi HTML ringan (tebal + baris baru + daftar "•").
 * Dipakai kartu percakapan AI Developer. Isi dibersihkan lebih dulu (`e()`), jadi
 * tidak ada HTML dari AI yang dieksekusi — hanya penanda sederhana yang diformat.
 */
function chat_md(string $teks): string
{
    $aman = e($teks);
    $aman = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $aman) ?? $aman;
    $aman = preg_replace('/(?m)^\s*[•\-]\s+/', '• ', $aman) ?? $aman;
    return nl2br($aman);
}

function nav_items(): array
{
    $items = [];
    if (has_perm('dashboard.view')) $items[] = ['dashboard.php', 'Dashboard', 'grid', 'dashboard'];
    $group = [];
    if (has_perm('reservation.view')) $group[] = ['reservasi.php', 'Reservasi', 'calendar', 'reservasi'];
    if (has_perm('patient.view')) $group[] = ['pasien.php', 'Data Pasien', 'users', 'pasien'];
    /* RONDE 35: nama menu memakai "Rekam Medis Elektronik" (permintaan pemilik).
       Ini murni TEKS tampilan — nama tabel/kolom, nomor RM (RM-<KODE>-…), izin
       (`medical.*`), dan riwayat audit TIDAK berubah, jadi tidak ada risiko. */
    if (has_perm('medical.view')) $group[] = ['rekam_medis.php', 'Rekam Medis Elektronik', 'file-medical', 'rekam_medis'];
    if (has_perm('medical.view')) $group[] = ['icd.php', 'Kamus ICD-10 / 9-CM', 'search', 'icd'];
    if ($group) $items[] = ['Operasional', $group];

    $kasir = [];
    if (has_perm('order.manage')) $kasir[] = ['order_baru.php', 'Order Baru', 'plus-circle', 'order_baru'];
    if (has_perm('order.view')) $kasir[] = ['order.php', 'Riwayat Order', 'receipt', 'order'];
    if ($kasir) $items[] = ['Kasir / Transaksi', $kasir];

    $inv = [];
    if (has_perm('treatment.view')) $inv[] = ['treatment.php', 'Master Treatment', 'sparkles', 'treatment'];
    if (has_perm('skincare.view')) $inv[] = ['skincare.php', 'Master Skincare', 'bottle', 'skincare'];
    if (has_perm('material.view')) $inv[] = ['bahan.php', 'Bahan Treatment', 'flask', 'bahan'];
    if (has_perm('supplier.manage')) $inv[] = ['suppliers.php', 'Supplier', 'truck', 'suppliers'];
    if (has_perm('inventory.view')) $inv[] = ['inventory_movement.php', 'Stok & Movement', 'activity', 'inventory_movement'];
    if ($inv) $items[] = ['Inventory', $inv];

    $rep = [];
    if (has_perm('report.view')) $rep[] = ['laporan.php', 'Laporan', 'chart', 'laporan'];
    if (has_perm('report.view')) $rep[] = ['top5.php', 'Top 5 Penjualan', 'trophy', 'top5'];
    if (has_perm('report.view')) $rep[] = ['top10_pasien.php', 'Top 10 Pasien', 'star', 'top10'];
    /* MENU KEUANGAN — tepat di bawah "Top 10 Pasien". Hanya untuk level owner
       (Super Admin & Direktur/Owner) karena memuat HPP & laba bersih yang
       bersifat internal perusahaan; level lain tidak melihat menunya dan
       halamannya ditolak di server. */
    if (has_perm('finance.view') && is_owner_level()) {
        $rep[] = ['keuangan.php', 'Keuangan', 'wallet', 'keuangan'];
    }
    if ($rep) $items[] = ['Laporan & Analisa', $rep];

    $set = [];
    if (has_perm('user.manage')) $set[] = ['users.php', 'Manajemen User', 'user-cog', 'users'];
    /* Cabang: `branch.manage` (Super Admin) dapat menambah/mengubah/menghapus,
       sedangkan `branch.view` (mis. Direktur/Owner) hanya melihat daftarnya. */
    if (has_perm('branch.manage') || has_perm('branch.view')) $set[] = ['branches.php', 'Data Cabang', 'building', 'branches'];
    if (has_perm('staff.manage')) $set[] = ['staff.php', 'Dokter & Terapis', 'stethoscope', 'staff'];
    if (has_perm('audit.view')) $set[] = ['audit_log.php', 'Audit Log', 'shield', 'audit'];
    if (has_perm('settings.manage')) $set[] = ['settings.php', 'Pengaturan Sistem', 'settings', 'settings'];
    /* Menu DEVELOPER SETTINGS — khusus Super Admin: nama klinik, penyimpanan data
       (retensi/hapus otomatis), backup, pemeliharaan, integrasi, data demo, dan
       pengosongan data. Dipisah dari Pengaturan Sistem supaya halaman pengaturan
       operasional tidak terlalu panjang dan tindakan berisiko terkumpul di satu
       tempat yang jelas. */
    if (is_super()) $set[] = ['developer.php', 'Developer Settings', 'shield', 'developer'];
    if (has_perm('patient.manage') || has_perm('medical.manage') || has_perm('skincare.manage') || has_perm('material.manage') || has_perm('treatment.manage')) {
        $set[] = ['import.php', 'Import Data (Excel/CSV)', 'upload', 'import'];
    }
    /* Menu Backup Database hanya untuk pemegang permission `backup.manage`
       (Super Admin) — level lain tidak boleh mengaksesnya sama sekali. */
    if (has_perm('backup.manage') || has_perm('maintenance.manage')) $set[] = ['backup.php', 'Backup Database', 'database', 'backup'];
    if ($set) $items[] = ['Pengaturan', $set];

    /* KELOMPOK "AI WORKSPACE" (ronde 42, permintaan pemilik) — menu AI dipisah
       menjadi kelompok tersendiri (seperti "Operasional", "Kasir / Transaksi",
       "Inventory") dan diletakkan PALING BAWAH sidebar.
       URUTAN: "AI Developer" lebih dulu, lalu "AI SETTINGS PALING BAWAH" — tempat
       paling bawah itu sengaja disiapkan untuk menu AI Assistant berikutnya.
       Khusus Super Admin (is_super()) karena AI Developer dapat mengubah kode
       aplikasi; server juga menolak level lain. */
    $ai = [];
    if (is_super()) {
        $ai[] = ['ai_developer.php', 'AI Developer', 'cpu', 'ai_developer'];
        $ai[] = ['ai_settings.php', 'AI Settings', 'sliders', 'ai_settings'];
    }
    if ($ai) $items[] = ['AI Workspace', $ai];
    return $items;
}

function icon(string $name): string
{
    $p = [
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1-3.5 3.5-5 6.5-5s5.5 1.5 6.5 5"/><path d="M17 11a3 3 0 1 0 0-6"/><path d="M18 20c-.2-2-.9-3.6-2-4.6"/>',
        'file-medical' => '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5"/><path d="M12 11v6M9 14h6"/>',
        'plus-circle' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'receipt'   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'sparkles'  => '<path d="M12 3l1.8 4.6L18 9.4l-4.2 1.8L12 16l-1.8-4.8L6 9.4l4.2-1.8z"/><path d="M18 15l.9 2.3 2.1.9-2.1.9L18 21l-.9-1.9-2.1-.9 2.1-.9z"/>',
        'bottle'    => '<path d="M10 3h4v3l2 3v11a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2V9l2-3z"/><path d="M8 12h8"/>',
        'flask'     => '<path d="M9 3h6v4l4 11a2 2 0 0 1-1.9 3H6.9A2 2 0 0 1 5 18L9 7z"/><path d="M6.5 15h11"/>',
        'truck'     => '<rect x="1.5" y="7" width="12" height="9" rx="1.5"/><path d="M13.5 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
        'activity'  => '<path d="M3 12h4l2.5-7 4 14L16 12h5"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'wallet'    => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M3 9h18"/><circle cx="16.5" cy="13.5" r="1.2"/>',
        'trophy'    => '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5v2a3 3 0 0 0 3 3M16 6h3v2a3 3 0 0 1-3 3"/><path d="M11 13h2l.5 5h-3z"/><path d="M8.5 21h7"/>',
        'star'      => '<path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6L3.3 9.3l6.1-.7z"/>',
        'user-cog'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1-3.5 3.5-5 6.5-5 1 0 2 .2 2.8.5"/><circle cx="17.5" cy="16.5" r="2.5"/><path d="M17.5 12.8v1.2M17.5 19v1.2M14.3 14.7l1 .6M19.7 17.7l1 .6M14.3 18.3l1-.6M19.7 15.3l1-.6"/>',
        'building'  => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/>',
        'stethoscope' => '<path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M10 13v3a5 5 0 0 0 5 5 5 5 0 0 0 5-5v-2"/><circle cx="20" cy="12" r="1.6"/>',
        'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8.4-8 9.8C7.5 20.4 4 17 4 12V6z"/><path d="M9 12l2 2 4-4"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M18.4 5.6l-1.8 1.8M7.4 16.6l-1.8 1.8"/>',
        'database'  => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'bell'      => '<path d="M18 16V11a6 6 0 1 0-12 0v5l-2 3h16z"/><path d="M10 22h4"/>',
        'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h9"/>',
        'print'     => '<path d="M7 8V3h10v5"/><rect x="4" y="8" width="16" height="8" rx="2"/><path d="M7 16h10v5H7z"/>',
        'download'  => '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/>',
        'upload'    => '<path d="M12 21V9"/><path d="M7 13l5-5 5 5"/><path d="M4 4h16"/>',
        'table'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16"/>',
        'edit'      => '<path d="M4 20h4l11-11-4-4L4 16z"/><path d="M14 5l4 4"/>',
        'trash'     => '<path d="M4 7h16"/><path d="M10 4h4v3h-4z"/><path d="M6 7l1 14h10l1-14"/>',
        'search'    => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
        'whatsapp'  => '<path d="M20 12a8 8 0 0 1-11.9 7L4 20l1.1-4A8 8 0 1 1 20 12z"/><path d="M9 9c0 4 2 6 6 6 1 0 1.5-.5 1.5-1.5L15 12.5l-1.5 1c-1-.4-2-1.4-2.4-2.4l1-1.5-1-1.5C10 7 9.5 7.5 9 9z"/>',
        'lock'      => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'key'       => '<circle cx="8" cy="15" r="3"/><path d="M10.5 12.5L20 3M16 7l2 2M14 9l2 2"/>',
        'x'         => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'cpu'       => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9zM9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 15h3M1 9h3M1 15h3"/>',
        'sliders'   => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>'
    ];
    $d = $p[$name] ?? $p['grid'];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

function render_sidebar(array $user, string $active): void
{
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-head">' . brand_block(true) . '</div>';
    echo '<nav class="nav">';
    foreach (nav_items() as $item) {
        if (!isset($item[1]) || is_string($item[1])) {
            echo nav_link($item, $active);
        } else {
            echo '<div class="nav-group"><div class="nav-group-title">' . e($item[0]) . '</div>';
            foreach ($item[1] as $sub) echo nav_link($sub, $active);
            echo '</div>';
        }
    }
    echo '</nav>';
    echo '<div class="sidebar-foot"><div class="sf-user"><div class="avatar">' . e(strtoupper(substr($user['name'], 0, 1))) . '</div>';
    echo '<div class="sf-meta"><strong>' . e($user['name']) . '</strong><small>' . e($user['role_name']) . ' · ' . e($user['branch_name'] ?? 'Semua Cabang') . '</small></div></div>';
    echo '<a class="btn btn-ghost btn-block btn-sm" href="profile.php">Ubah Password</a>';
    echo '<a class="btn btn-ghost btn-block btn-sm" href="logout.php">' . icon('logout') . ' Keluar</a></div>';
    echo '</aside>';
    echo '<div class="sidebar-overlay" id="sidebarOverlay"></div>';
}
function nav_link(array $item, string $active): string
{
    [$href, $label, $ico, $key] = $item;
    $is = $active === $key;
    return '<a class="nav-link' . ($is ? ' active' : '') . '" href="' . e($href) . '">' . icon($ico) . '<span>' . e($label) . '</span></a>';
}

function render_topbar(array $user, string $title): void
{
    $alerts = stock_alert_count();
    $bid = user_branch();
    echo '<header class="topbar">';
    echo '<button class="icon-btn mobile-only" id="menuToggle" type="button" aria-label="Menu">' . icon('menu') . '</button>';
    echo '<div class="topbar-title"><h1>' . e($title) . '</h1>';
    if ($bid) {
        $b = null;
        foreach (branches() as $x) if ((int)$x['id'] === $bid) $b = $x;
        echo '<div class="crumb">' . e($b['name'] ?? '') . '</div>';
    } else {
        echo '<div class="crumb">Mode Pusat · Semua Cabang</div>';
    }
    echo '</div><div class="topbar-actions">';
    if (is_owner_level()) {
        echo '<form method="post" action="switch_branch.php" class="branch-switch">';
        echo csrf_field();
        echo '<select name="branch_id" class="input input-sm" onchange="this.form.submit()">';
        echo '<option value=""' . ($bid === null ? ' selected' : '') . '>Semua Cabang</option>';
        foreach (branches() as $b) {
            echo '<option value="' . (int)$b['id'] . '"' . ($bid === (int)$b['id'] ? ' selected' : '') . '>' . e($b['name']) . '</option>';
        }
        echo '</select></form>';
    }
    echo '<a class="icon-btn" href="inventory_movement.php?alert=1" title="Notifikasi stok"><span class="bell">' . icon('bell') . ($alerts > 0 ? '<i class="dot">' . $alerts . '</i>' : '') . '</span></a>';
    echo '<a class="icon-btn" href="profile.php" title="' . e($user['name']) . '">' . icon('user-cog') . '</a>';
    echo '<a class="icon-btn" href="logout.php" title="Keluar">' . icon('logout') . '</a>';
    echo '</div></header>';
}

function page_head(string $title, string $active, array $opts = []): void
{
    $user = require_login();
    $fullTitle = $title . ' · ' . clinic_name();
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($fullTitle) . '</title>';
    echo '<link rel="stylesheet" href="assets/css/app.css">';
    echo '<style id="themeVars">' . theme_css() . '</style>';
    echo '<script>window.NAVEENA_THEME=' . js_json([
        'key' => theme_key(),
        'palette' => theme_chart_palette(),
        'series' => chart_series_colors(),
        'brand' => theme_current()['brand'],
        'accent' => theme_current()['accent'],
    ]) . ';</script>';
    echo '</head><body>';
    render_sidebar($user, $active);
    echo '<div class="shell"><div class="main">';
    render_topbar($user, $title);
    echo '<main class="content">';
    /* Mode pemeliharaan: beri tahu secara terbuka siapa yang dibatasi. */
    if (maintenance_on()) {
        if (maintenance_readonly($user)) {
            $mi = maintenance_info();
            echo '<div class="maint-banner maint-banner-view">'
                . '<span class="mb-ico">' . icon('settings') . '</span>'
                . '<div><strong>Mode pemeliharaan aktif — Anda hanya dapat melihat data.</strong>'
                . '<div class="mb-sub">Tambah, ubah, hapus, impor, dan ekspor dinonaktifkan sementara. '
                . 'Perkiraan selesai: ' . e($mi['until'] !== '' && strtotime($mi['until']) ? tgl($mi['until'], true) : 'belum ditentukan') . '.</div></div>'
                . '<a class="btn btn-sm" href="maintenance.php">Detail</a></div>';
        } else {
            echo '<div class="maint-banner maint-banner-super">'
                . '<span class="mb-ico">' . icon('settings') . '</span>'
                . '<div><strong>Mode pemeliharaan sedang AKTIF.</strong>'
                . '<div class="mb-sub">Kasir dan Admin/Dokter kini hanya bisa melihat data. Anda sebagai Super Admin tetap bebas.</div></div>'
                . '<a class="btn btn-sm" href="developer.php#pemeliharaan">Atur / Matikan</a></div>';
        }
    }
    foreach (take_flash() as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '" data-autohide="1">' . e($f['msg']) . '</div>';
    }
    if (!empty($opts['raw'])) return;
}

function page_foot(array $opts = []): void
{
    global $PAGE_SCRIPTS;
    echo '</main>';
    echo '<footer class="foot">' . e(clinic_name()) . ' — Sistem Manajemen Klinik Multi-Cabang · ' . tglIndo(date('Y-m-d')) . '</footer>';
    echo '</div></div>';
    // Modal konfirmasi berat (2 tahap) — dipakai form dengan data-heavy-confirm.
    echo '<div class="modal" id="confirmHeavy">
      <div class="modal-box">
        <div class="modal-head"><h3 style="color:#B3261E">⚠ Konfirmasi Tindakan Berbahaya</h3>
          <button type="button" class="icon-btn" data-heavy-cancel="1">' . icon('x') . '</button></div>
        <div class="modal-body">
          <div class="alert alert-error mb-2">Tindakan ini <strong>menghapus data secara permanen</strong> dan tidak dapat dibatalkan.</div>
          <div id="confirmHeavyText" class="mb-2"></div>
          <div class="field">
            <label>Ketik <code id="confirmHeavyWord"></code> untuk melanjutkan</label>
            <input class="input" id="confirmHeavyInput" autocomplete="off" placeholder="ketik kata di atas">
            <span class="hint">Ini peringatan pertama. Setelah ini masih ada satu konfirmasi lagi.</span>
          </div>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn" data-heavy-cancel="1">Batalkan</button>
          <button type="button" class="btn btn-danger" id="confirmHeavyBtn" disabled>Lanjutkan (peringatan 1/2)</button>
        </div>
      </div>
    </div>';
    echo '<script src="assets/js/app.js"></script>';
    if (!empty($PAGE_SCRIPTS)) foreach ($PAGE_SCRIPTS as $s) echo $s;
    /* Mode pemeliharaan: hentikan aksi tulis di sisi tampilan supaya pengguna
       tidak mengisi form panjang lalu baru ditolak server. Penegakan utama
       tetap di server (maintenance_gate()). */
    if (function_exists('maintenance_readonly') && maintenance_readonly()) {
        echo '<script>window.NAVEENA_MAINTENANCE = ' . js_json([
            'title' => maintenance_info()['title'],
            'message' => 'Selama pemeliharaan, penambahan/perubahan/ekspor data dinonaktifkan sementara. Data Anda TIDAK tersimpan dan tidak ada yang berubah.',
        ]) . ';</script>';
        echo '<script src="assets/js/maintenance.js"></script>';
    }
    echo '</body></html>';
}

/** Page title + action buttons bar */
function page_title(string $title, string $sub = '', string $actions = ''): void
{
    echo '<div class="page-head"><div><h2>' . e($title) . '</h2>' . ($sub !== '' ? '<p class="muted">' . e($sub) . '</p>' : '') . '</div>';
    if ($actions !== '') echo '<div class="page-actions">' . $actions . '</div>';
    echo '</div>';
}
function empty_state(string $msg = 'Belum ada data.'): string
{
    return '<div class="empty"><div class="empty-ico">' . icon('search') . '</div><p>' . e($msg) . '</p></div>';
}
function alert_box(string $type, string $msg): string
{
    return '<div class="alert alert-' . e($type) . '">' . $msg . '</div>';
}
