<?php
/**
 * Tema warna klinik.
 *
 * Warna aksen (brand) dipakai di seluruh tampilan lewat CSS variable, sehingga
 * mengganti tema cukup menimpa variabelnya. Warna status (berhasil/perhatian/
 * bahaya/info) dan warna teks sengaja TIDAK ikut berubah agar maknanya tetap
 * konsisten dan tetap terbaca.
 *
 * Setiap tema: brand (utama), brandDark (sidebar/gradien), accent (pendukung,
 * mis. skincare/produk), plus turunannya (soft, tint, line, bg, glow).
 */
declare(strict_types=1);

/**
 * ----- pengelompokan tema (keluarga warna) -----
 * Tiap tema punya `family` sehingga pemilih tema di Pengaturan Sistem bisa
 * ditata per baris warna: Merah → Pink → Biru → Gold → Grey → Hijau → Coklat →
 * Ungu, masing-masing 4 pilihan berjajar ke kanan (lihat theme_grouped()).
 */
function theme_families(): array
{
    return [
        'merah' => ['label' => 'Merah', 'desc' => 'Merah & oranye hangat'],
        'pink' => ['label' => 'Pink', 'desc' => 'Pink, magenta, & coral'],
        'biru' => ['label' => 'Biru', 'desc' => 'Biru, navy, & teal'],
        'gold' => ['label' => 'Gold', 'desc' => 'Gold, champagne, & rose gold'],
        'grey' => ['label' => 'Grey', 'desc' => 'Abu netral & grafit'],
        'hijau' => ['label' => 'Hijau', 'desc' => 'Hijau segar & natural'],
        'coklat' => ['label' => 'Coklat', 'desc' => 'Cokelat hangat & kopi'],
        'ungu' => ['label' => 'Ungu', 'desc' => 'Ungu mewah & lavendel'],
        /* 12 keluarga tambahan (ronde 34) supaya pilihan tema jauh lebih banyak:
           total 20 keluarga × 4 warna = 80 tema. Urutannya dipakai pemilih tema
           di Pengaturan Sistem sehingga tiap baris berisi 4 pilihan berjajar. */
        'tosca' => ['label' => 'Tosca', 'desc' => 'Tosca, aqua, & turquoise sejuk'],
        'langit' => ['label' => 'Langit', 'desc' => 'Biru langit, es, & cornflower'],
        'indigo' => ['label' => 'Indigo', 'desc' => 'Indigo, nila, & sapphire'],
        'denim' => ['label' => 'Denim', 'desc' => 'Denim, jeans, & baja'],
        'lime' => ['label' => 'Lime', 'desc' => 'Lime, zaitun, & matcha'],
        'sage' => ['label' => 'Sage', 'desc' => 'Sage, eucalyptus, & pakis'],
        'peach' => ['label' => 'Peach', 'desc' => 'Peach, salem, & apricot'],
        'mustard' => ['label' => 'Mustard', 'desc' => 'Mustard, kunyit, & madu'],
        'bronze' => ['label' => 'Bronze', 'desc' => 'Bronze, tembaga, & kuningan'],
        'bata' => ['label' => 'Bata', 'desc' => 'Bata, burgundy, & kayu rose'],
        'anggur' => ['label' => 'Anggur', 'desc' => 'Anggur, merlot, & wine'],
        'fuchsia' => ['label' => 'Fuchsia', 'desc' => 'Fuchsia, cerise, & orchid'],
    ];
}

/** Tema dikelompokkan menurut keluarga warna (dipakai tampilan Pengaturan). */
function theme_grouped(): array
{
    $out = [];
    foreach (array_keys(theme_families()) as $f) $out[$f] = [];
    foreach (theme_list() as $k => $t) $out[$t['family'] ?? 'lainnya'][$k] = $t;
    foreach ($out as $f => $rows) if (!$rows) unset($out[$f]);
    return $out;
}

/** Ubah "#RRGGBB" menjadi [r,g,b]. */
function theme_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

/** Campur warna dengan warna dasar (bawaan putih) sebesar $ratio (0–1). */
function theme_mix(string $hex, float $ratio, string $base = '#FFFFFF'): string
{
    [$r1, $g1, $b1] = theme_rgb($hex);
    [$r2, $g2, $b2] = theme_rgb($base);
    $r = (int)round($r1 * (1 - $ratio) + $r2 * $ratio);
    $g = (int)round($g1 * (1 - $ratio) + $g2 * $ratio);
    $b = (int)round($b1 * (1 - $ratio) + $b2 * $ratio);
    return sprintf('#%02X%02X%02X', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/**
 * Warna terang tema yang diturunkan dari warna utama: soft, tint, tint2, hover,
 * line, bg, bg3, chip, hover2. Dipakai tema baru pada kunci `core` agar tidak
 * perlu menulis 9 nilai manual dan tidak mungkin ada kunci yang kosong.
 */
function theme_derived(string $brand): array
{
    return [
        'soft' => theme_mix($brand, 0.82),
        'tint' => theme_mix($brand, 0.97),
        'tint2' => theme_mix($brand, 0.95),
        'hover' => theme_mix($brand, 0.98),
        'line' => theme_mix($brand, 0.86),
        'bg' => theme_mix($brand, 0.955),
        'bg3' => theme_mix($brand, 0.915),
        'chip' => theme_mix($brand, 0.905),
        'hover2' => theme_mix($brand, 0.94),
    ];
}

function theme_list(): array
{
    $list = [
        'magenta' => [
            'name' => 'Magenta Klasik', 'desc' => 'Identitas bawaan sistem (merah magenta)', 'family' => 'pink',
            'brand' => '#C2185B', 'brandDark' => '#8E0E42', 'brandMid' => '#E91E63', 'brandBright' => '#D6336C',
            'accent' => '#8BC34A', 'accentDark' => '#5E9B2A', 'accentMid' => '#7CB342',
            'soft' => '#F8D9E7', 'tint' => '#FFFBFD', 'tint2' => '#FDF7FA', 'hover' => '#FFFAFC',
            'line' => '#F0DDE7', 'bg' => '#FBF6F9', 'bg3' => '#F7EDF2', 'chip' => '#F2EAF0', 'hover2' => '#FFF3F8',
        ],
        'rosegold' => [
            'name' => 'Rose Gold', 'desc' => 'Elegan, kesan premium', 'family' => 'gold',
            'brand' => '#B76E79', 'brandDark' => '#8C4A55', 'brandMid' => '#C98A94', 'brandBright' => '#A85C68',
            'accent' => '#C9A227', 'accentDark' => '#9A7B1B', 'accentMid' => '#B08D20',
            'soft' => '#F6E3E5', 'tint' => '#FFFBFA', 'tint2' => '#FDF6F6', 'hover' => '#FFF9F9',
            'line' => '#EEDCDC', 'bg' => '#FAF4F4', 'bg3' => '#F5E9E9', 'chip' => '#EFE3E3', 'hover2' => '#FDF1F1',
        ],
        'emerald' => [
            'name' => 'Emerald Green', 'desc' => 'Segar, natural, klinik kulit', 'family' => 'hijau',
            'brand' => '#0F766E', 'brandDark' => '#0B5A54', 'brandMid' => '#148F85', 'brandBright' => '#0D6B64',
            'accent' => '#F59E0B', 'accentDark' => '#B87408', 'accentMid' => '#D98D09',
            'soft' => '#D5EDEA', 'tint' => '#F9FDFC', 'tint2' => '#F3FAF9', 'hover' => '#F7FCFB',
            'line' => '#D8E9E6', 'bg' => '#F4FAF9', 'bg3' => '#E9F4F2', 'chip' => '#E3EFED', 'hover2' => '#EFF9F7',
        ],
        'royal' => [
            'name' => 'Royal Purple', 'desc' => 'Modern, mewah', 'family' => 'ungu',
            'brand' => '#6D28D9', 'brandDark' => '#4C1D95', 'brandMid' => '#7C3AED', 'brandBright' => '#5B21B6',
            'accent' => '#F472B6', 'accentDark' => '#BE3E86', 'accentMid' => '#DB5A9E',
            'soft' => '#E8DFFB', 'tint' => '#FCFAFF', 'tint2' => '#F8F5FE', 'hover' => '#FAF8FF',
            'line' => '#E4DCF6', 'bg' => '#F7F5FD', 'bg3' => '#EFEBF9', 'chip' => '#EBE6F6', 'hover2' => '#F3EFFC',
        ],
        'ocean' => [
            'name' => 'Ocean Blue', 'desc' => 'Tenang, bersih, medis', 'family' => 'biru',
            'brand' => '#0369A1', 'brandDark' => '#075985', 'brandMid' => '#0284C7', 'brandBright' => '#036190',
            'accent' => '#22D3EE', 'accentDark' => '#0E9FB8', 'accentMid' => '#17BEDA',
            'soft' => '#D6EAF7', 'tint' => '#F9FCFF', 'tint2' => '#F3F9FD', 'hover' => '#F7FBFE',
            'line' => '#D6E6F2', 'bg' => '#F4F9FD', 'bg3' => '#E9F2F9', 'chip' => '#E3EDF5', 'hover2' => '#EFF7FC',
        ],
        'sunset' => [
            'name' => 'Sunset Orange', 'desc' => 'Hangat, ramah, energik', 'family' => 'merah',
            'brand' => '#C2410C', 'brandDark' => '#9A3412', 'brandMid' => '#EA580C', 'brandBright' => '#AD3A0A',
            'accent' => '#FBBF24', 'accentDark' => '#B98909', 'accentMid' => '#D9A209',
            'soft' => '#FBE0D0', 'tint' => '#FFFBF9', 'tint2' => '#FDF5F0', 'hover' => '#FFFAF6',
            'line' => '#F2DED2', 'bg' => '#FDF7F3', 'bg3' => '#F9EDE6', 'chip' => '#F4E7E0', 'hover2' => '#FCF1EA',
        ],
        'teal' => [
            'name' => 'Deep Teal', 'desc' => 'Profesional, kalem', 'family' => 'biru',
            'brand' => '#115E59', 'brandDark' => '#134E4A', 'brandMid' => '#147A73', 'brandBright' => '#0F5651',
            'accent' => '#34D399', 'accentDark' => '#129469', 'accentMid' => '#1FB884',
            'soft' => '#D3E9E7', 'tint' => '#F8FCFB', 'tint2' => '#F2F9F8', 'hover' => '#F6FBFA',
            'line' => '#D5E7E5', 'bg' => '#F3F9F8', 'bg3' => '#E8F3F1', 'chip' => '#E2EEEC', 'hover2' => '#EEF8F6',
        ],
        'berry' => [
            'name' => 'Berry Blush', 'desc' => 'Feminin, lembut', 'family' => 'pink',
            'brand' => '#9D174D', 'brandDark' => '#701A3F', 'brandMid' => '#BE185D', 'brandBright' => '#8A1444',
            'accent' => '#A78BFA', 'accentDark' => '#7C5CF0', 'accentMid' => '#9179F8',
            'soft' => '#F5D9E5', 'tint' => '#FFF9FC', 'tint2' => '#FDF3F8', 'hover' => '#FFF8FB',
            'line' => '#F0DBE5', 'bg' => '#FCF5F8', 'bg3' => '#F7EBF1', 'chip' => '#F2E5EB', 'hover2' => '#FBEEF4',
        ],
        'forest' => [
            'name' => 'Forest Green', 'desc' => 'Alami, herbal, menenangkan', 'family' => 'hijau',
            'brand' => '#166534', 'brandDark' => '#14532D', 'brandMid' => '#1A7A40', 'brandBright' => '#135C30',
            'accent' => '#A3E635', 'accentDark' => '#77B417', 'accentMid' => '#8CCD26',
            'soft' => '#D9EBDD', 'tint' => '#F8FCF9', 'tint2' => '#F2F9F4', 'hover' => '#F6FBF7',
            'line' => '#D9E8DC', 'bg' => '#F3F9F4', 'bg3' => '#E9F3EB', 'chip' => '#E3EEE5', 'hover2' => '#EFF7F1',
        ],
        'charcoal' => [
            'name' => 'Charcoal Gold', 'desc' => 'Minimalis, mewah, netral', 'family' => 'gold',
            'brand' => '#1F2937', 'brandDark' => '#111827', 'brandMid' => '#374151', 'brandBright' => '#1B2432',
            'accent' => '#D4AF37', 'accentDark' => '#A08420', 'accentMid' => '#BE9C2C',
            'soft' => '#E2E5EA', 'tint' => '#FBFBFC', 'tint2' => '#F6F7F9', 'hover' => '#F9FAFB',
            'line' => '#E1E4E9', 'bg' => '#F6F7F9', 'bg3' => '#EDEFF2', 'chip' => '#E7E9ED', 'hover2' => '#F3F4F7',
        ],
        'navy' => [
            'name' => 'Navy Elegan', 'desc' => 'Biru tua, formal, terpercaya', 'family' => 'biru',
            'brand' => '#1E3A8A', 'brandDark' => '#172554', 'brandMid' => '#2547A8', 'brandBright' => '#1B3479',
            'accent' => '#38BDF8', 'accentDark' => '#0E90C4', 'accentMid' => '#22A8DC',
            'soft' => '#DCE4F8', 'tint' => '#FBFCFE', 'tint2' => '#F5F7FD', 'hover' => '#F8FAFE',
            'line' => '#DEE5F5', 'bg' => '#F5F7FD', 'bg3' => '#EAF0FA', 'chip' => '#E4EBF7', 'hover2' => '#F0F4FC',
        ],
        'mocha' => [
            'name' => 'Mocha Latte', 'desc' => 'Cokelat hangat, ramah & bersahaja', 'family' => 'coklat',
            'brand' => '#8A5A44', 'brandDark' => '#6B4232', 'brandMid' => '#9E6B52', 'brandBright' => '#7A4E3B',
            'accent' => '#D9A56B', 'accentDark' => '#A87A45', 'accentMid' => '#C08F55',
            'soft' => '#F1E3D9', 'tint' => '#FFFCFA', 'tint2' => '#FBF6F2', 'hover' => '#FDF8F5',
            'line' => '#EBDDD2', 'bg' => '#FAF5F1', 'bg3' => '#F4E9E1', 'chip' => '#EFE4DB', 'hover2' => '#F9F0E9',
        ],
        'coral' => [
            'name' => 'Coral Peach', 'desc' => 'Lembut, hangat, feminin', 'family' => 'pink',
            'brand' => '#E8556D', 'brandDark' => '#C03A50', 'brandMid' => '#F07084', 'brandBright' => '#D24A60',
            'accent' => '#FFB59E', 'accentDark' => '#DB8064', 'accentMid' => '#ED9A80',
            'soft' => '#FBDDE3', 'tint' => '#FFFBFB', 'tint2' => '#FDF6F7', 'hover' => '#FFF9F9',
            'line' => '#F5DCE1', 'bg' => '#FDF4F6', 'bg3' => '#F9E7EB', 'chip' => '#F5E1E6', 'hover2' => '#FCEFF2',
        ],
        'plum' => [
            'name' => 'Plum Velvet', 'desc' => 'Ungu kevioletan, mewah & dewasa', 'family' => 'ungu',
            'brand' => '#7B3F73', 'brandDark' => '#5C2B56', 'brandMid' => '#8D4F84', 'brandBright' => '#6B3563',
            'accent' => '#E0A3C8', 'accentDark' => '#B06C95', 'accentMid' => '#C985AC',
            'soft' => '#EEDCEC', 'tint' => '#FFFCFF', 'tint2' => '#FAF5F9', 'hover' => '#FCF8FB',
            'line' => '#E8D8E5', 'bg' => '#F9F4F8', 'bg3' => '#F2E7F0', 'chip' => '#ECE1EA', 'hover2' => '#F7EFF5',
        ],
        'olive' => [
            'name' => 'Olive Sage', 'desc' => 'Hijau zaitun, natural & menenangkan', 'family' => 'hijau',
            'brand' => '#6B7A2F', 'brandDark' => '#4F5B22', 'brandMid' => '#7D8D3A', 'brandBright' => '#5D6B29',
            'accent' => '#C9A227', 'accentDark' => '#9A7B1B', 'accentMid' => '#B08D20',
            'soft' => '#E7EBD3', 'tint' => '#FDFEF9', 'tint2' => '#F7FAF0', 'hover' => '#FAFCF5',
            'line' => '#E0E6CD', 'bg' => '#F7FAF1', 'bg3' => '#EDF2E2', 'chip' => '#E7EDD9', 'hover2' => '#F3F7EA',
        ],
        'slate' => [
            'name' => 'Slate Gray', 'desc' => 'Abu netral, tenang, profesional', 'family' => 'grey',
            'brand' => '#475569', 'brandDark' => '#334155', 'brandMid' => '#566575', 'brandBright' => '#3E4B5C',
            'accent' => '#94A3B8', 'accentDark' => '#64748B', 'accentMid' => '#7C8CA1',
            'soft' => '#DFE4EA', 'tint' => '#FCFDFE', 'tint2' => '#F6F8FA', 'hover' => '#F9FAFC',
            'line' => '#DEE3E9', 'bg' => '#F6F8FA', 'bg3' => '#ECEFF3', 'chip' => '#E6EAEF', 'hover2' => '#F1F4F8',
        ],
        'lavender' => [
            'name' => 'Lavender Mist', 'desc' => 'Ungu pastel, lembut & modern', 'family' => 'ungu',
            'brand' => '#7C6BC4', 'brandDark' => '#5B4A9E', 'brandMid' => '#8F80D2', 'brandBright' => '#6B5AB4',
            'accent' => '#F0A6C8', 'accentDark' => '#C4709C', 'accentMid' => '#DA8AB4',
            'soft' => '#E7E2F7', 'tint' => '#FCFBFF', 'tint2' => '#F6F4FD', 'hover' => '#F9F8FE',
            'line' => '#E3DFF3', 'bg' => '#F6F5FC', 'bg3' => '#EEEBFA', 'chip' => '#E8E5F6', 'hover2' => '#F2F0FB',
        ],
        'cherry' => [
            'name' => 'Cherry Red', 'desc' => 'Merah tegas, berani & mencolok', 'family' => 'merah',
            'brand' => '#B91C1C', 'brandDark' => '#8F1414', 'brandMid' => '#D02A2A', 'brandBright' => '#A61919',
            'accent' => '#F59E0B', 'accentDark' => '#B87408', 'accentMid' => '#D98D09',
            'soft' => '#F8DCDC', 'tint' => '#FFFBFB', 'tint2' => '#FDF5F5', 'hover' => '#FFF8F8',
            'line' => '#F3DEDE', 'bg' => '#FCF5F5', 'bg3' => '#F8E9E9', 'chip' => '#F3E4E4', 'hover2' => '#FBEDED',
        ],
        /* ---- rekomendasi tambahan: 4 warna per keluarga (ronde 10) ----
           Tema baru cukup menuliskan 7 warna inti pada `core`
           (brand, brandDark, brandMid, brandBright, accent, accentDark, accentMid);
           warna terang (soft/tint/line/bg/…) diturunkan otomatis oleh
           theme_derived() sehingga selalu lengkap. */
        'maroon' => [
            'name' => 'Maroon Classic', 'desc' => 'Merah marun tua, berkelas', 'family' => 'merah',
            'core' => ['#7F1D1D', '#5C1414', '#9A2626', '#6E1919', '#D4A017', '#A07A0E', '#BE8F13'],
        ],
        'terracotta' => [
            'name' => 'Terracotta Warm', 'desc' => 'Merah bata hangat, earthy', 'family' => 'merah',
            'core' => ['#B45309', '#8A3F06', '#C66518', '#9C4708', '#7C9A5A', '#5C753F', '#6C8749'],
        ],
        'sakura' => [
            'name' => 'Pink Sakura', 'desc' => 'Pink lembut bunga sakura', 'family' => 'pink',
            'core' => ['#E75480', '#C43B66', '#F06B95', '#D34475', '#A78BFA', '#7C5CF0', '#9179F8'],
        ],
        'sky' => [
            'name' => 'Sky Blue', 'desc' => 'Biru langit cerah & ringan', 'family' => 'biru',
            'core' => ['#0EA5E9', '#0369A1', '#38BDF8', '#0B87BE', '#F59E0B', '#B87408', '#D98D09'],
        ],
        'champagne' => [
            'name' => 'Champagne Gold', 'desc' => 'Gold champagne lembut & mahal', 'family' => 'gold',
            'core' => ['#A98A3F', '#86702F', '#C2A354', '#977C38', '#5B8C7B', '#41685B', '#4E7A6B'],
        ],
        'amber' => [
            'name' => 'Amber Gold', 'desc' => 'Gold amber hangat, mewah', 'family' => 'gold',
            'core' => ['#B7791F', '#8E5D14', '#D69E2E', '#A46C19', '#2C7A7B', '#1E5C5D', '#256A6B'],
        ],
        'graphite' => [
            'name' => 'Graphite Noir', 'desc' => 'Grafit gelap, tegas & modern', 'family' => 'grey',
            'core' => ['#374151', '#1F2937', '#4B5563', '#2E3644', '#D4AF37', '#A08420', '#BE9C2C'],
        ],
        'silver' => [
            'name' => 'Silver Mist', 'desc' => 'Abu perak sejuk & bersih', 'family' => 'grey',
            'core' => ['#64748B', '#475569', '#7A8899', '#57657A', '#38BDF8', '#0E90C4', '#22A8DC'],
        ],
        'pebble' => [
            'name' => 'Pebble Grey', 'desc' => 'Abu batu netral, kalem', 'family' => 'grey',
            'core' => ['#6B7280', '#4B5563', '#838A97', '#5C6470', '#F0A6C8', '#C4709C', '#DA8AB4'],
        ],
        'mint' => [
            'name' => 'Mint Fresh', 'desc' => 'Hijau mint segar & bersih', 'family' => 'hijau',
            'core' => ['#0D9488', '#0B7268', '#14B8A6', '#0C837A', '#F472B6', '#BE3E86', '#DB5A9E'],
        ],
        'kopisusu' => [
            'name' => 'Kopi Susu', 'desc' => 'Cokelat susu lembut, hangat', 'family' => 'coklat',
            'core' => ['#A9805A', '#856346', '#BF9370', '#987351', '#6B7A2F', '#4F5B22', '#5D6B29'],
        ],
        'chocolate' => [
            'name' => 'Dark Chocolate', 'desc' => 'Cokelat tua pekat, elegan', 'family' => 'coklat',
            'core' => ['#5B3A29', '#402819', '#6F4834', '#4E3122', '#D9A56B', '#A87A45', '#C08F55'],
        ],
        'caramel' => [
            'name' => 'Caramel Toffee', 'desc' => 'Karamel manis, ramah & hangat', 'family' => 'coklat',
            'core' => ['#B07B36', '#8A5F26', '#C8944B', '#9E6E2E', '#7C9A5A', '#5C753F', '#6C8749'],
        ],
        'amethyst' => [
            'name' => 'Amethyst', 'desc' => 'Ungu kecubung cerah & modern', 'family' => 'ungu',
            'core' => ['#9333EA', '#6B21A8', '#A855F7', '#7E2AD1', '#F0A6C8', '#C4709C', '#DA8AB4'],
        ],

        /* ============ 48 TEMA TAMBAHAN (ronde 34) ============
           Semua warna terang (soft/tint/line/bg/…) DITURUNKAN otomatis dari
           warna utama oleh theme_derived(), jadi cukup menulis 7 warna inti. */
        'tosca' => ['name' => 'Tosca Segar', 'desc' => 'Tosca laut, segar & bersih', 'family' => 'tosca',
            'core' => ['#0E7490', '#155E75', '#0891B2', '#0C6C86', '#F59E0B', '#B87408', '#D98D09']],
        'aqua' => ['name' => 'Aqua Blue', 'desc' => 'Biru air muda, ringan', 'family' => 'tosca',
            'core' => ['#0891B2', '#0E7490', '#22D3EE', '#0A7E9C', '#FB7185', '#C7505F', '#E25F72']],
        'turquoise' => ['name' => 'Turquoise', 'desc' => 'Pirus cerah & ceria', 'family' => 'tosca',
            'core' => ['#14B8A6', '#0F766E', '#2DD4BF', '#12A594', '#F97316', '#BF560F', '#DB6413']],
        'laguna' => ['name' => 'Laguna', 'desc' => 'Hijau-biru laguna tenang', 'family' => 'tosca',
            'core' => ['#0D7A72', '#0A5C56', '#12968C', '#0B6B64', '#FFB703', '#BF8902', '#DB9C03']],

        'langit' => ['name' => 'Langit Cerah', 'desc' => 'Biru langit bersih', 'family' => 'langit',
            'core' => ['#0284C7', '#075985', '#38BDF8', '#036FAA', '#F59E0B', '#B87408', '#D98D09']],
        'es' => ['name' => 'Es Biru', 'desc' => 'Biru es sejuk & terang', 'family' => 'langit',
            'core' => ['#38A3D1', '#22708F', '#67C3E8', '#2E90BB', '#7C3AED', '#5B21B6', '#6B2BD4']],
        'azure' => ['name' => 'Azure', 'desc' => 'Biru azure cerah', 'family' => 'langit',
            'core' => ['#0172CB', '#01579B', '#29A3E0', '#0164B0', '#F472B6', '#BE3E86', '#DB5A9E']],
        'cornflower' => ['name' => 'Cornflower', 'desc' => 'Biru bunga jagung, lembut', 'family' => 'langit',
            'core' => ['#4F79D8', '#3A57A5', '#7A9BE8', '#4568C0', '#FB923C', '#C46C22', '#DB7C2B']],

        'indigo' => ['name' => 'Indigo Blue', 'desc' => 'Indigo dalam & elegan', 'family' => 'indigo',
            'core' => ['#4338CA', '#312E81', '#5B4BE0', '#3B31B5', '#F59E0B', '#B87408', '#D98D09']],
        'nila' => ['name' => 'Nila Malam', 'desc' => 'Biru nila malam', 'family' => 'indigo',
            'core' => ['#3730A3', '#272178', '#4C43C7', '#2F2A90', '#22D3EE', '#0E9FB8', '#17BEDA']],
        'sapphire' => ['name' => 'Sapphire', 'desc' => 'Biru safir berkilau', 'family' => 'indigo',
            'core' => ['#1D4ED8', '#1E3A8A', '#3B82F6', '#1A45C0', '#FACC15', '#B59409', '#D9AC0B']],
        'ultramarine' => ['name' => 'Ultramarine', 'desc' => 'Biru ultramarin pekat', 'family' => 'indigo',
            'core' => ['#2A46C9', '#1C2F8A', '#4864E0', '#243DB4', '#34D399', '#129469', '#1FB884']],

        'denim' => ['name' => 'Denim', 'desc' => 'Biru denim klasik', 'family' => 'denim',
            'core' => ['#365E9B', '#27446F', '#4B77BE', '#304F84', '#EAB308', '#B08A06', '#CE9E07']],
        'jeans' => ['name' => 'Jeans Biru', 'desc' => 'Biru jeans sehari-hari', 'family' => 'denim',
            'core' => ['#2F5E8F', '#234668', '#4780B8', '#2A537D', '#F97316', '#BF560F', '#DB6413']],
        'steel' => ['name' => 'Steel Blue', 'desc' => 'Biru baja tegas', 'family' => 'denim',
            'core' => ['#4A6C8C', '#35506A', '#6187A8', '#41607C', '#F43F5E', '#B32A43', '#D63450']],
        'cobalt' => ['name' => 'Cobalt', 'desc' => 'Biru kobalt menyala', 'family' => 'denim',
            'core' => ['#0B5ED7', '#09489F', '#2A7BEA', '#0A52BB', '#FACC15', '#B59409', '#D9AC0B']],

        'lime' => ['name' => 'Lime Fresh', 'desc' => 'Hijau lime cerah', 'family' => 'lime',
            'core' => ['#65A30D', '#4D7C0F', '#84CC16', '#5A910C', '#0891B2', '#0E7490', '#0A7E9C']],
        'zaitun' => ['name' => 'Zaitun', 'desc' => 'Hijau zaitun natural', 'family' => 'lime',
            'core' => ['#6B7A2F', '#4F5B22', '#88A03C', '#5E6C29', '#B45309', '#8A3F06', '#9C4708']],
        'pistachio' => ['name' => 'Pistachio', 'desc' => 'Hijau pistachio lembut', 'family' => 'lime',
            'core' => ['#8FA05A', '#6B7A3F', '#AEC07B', '#7E8E4E', '#6D28D9', '#4C1D95', '#5B21B6']],
        'matcha' => ['name' => 'Matcha', 'desc' => 'Hijau matcha pekat', 'family' => 'lime',
            'core' => ['#4D7C0F', '#3F6212', '#6B9E1C', '#456F0D', '#D97706', '#A75A04', '#C06805']],

        'sage' => ['name' => 'Sage Green', 'desc' => 'Hijau sage menenangkan', 'family' => 'sage',
            'core' => ['#7C9070', '#5B6B52', '#96AC88', '#6E8063', '#C2703E', '#95522C', '#AC5F34']],
        'eucalyptus' => ['name' => 'Eucalyptus', 'desc' => 'Hijau eucalyptus sejuk', 'family' => 'sage',
            'core' => ['#5C8A7B', '#436659', '#79A99A', '#527C6F', '#F59E0B', '#B87408', '#D98D09']],
        'juniper' => ['name' => 'Juniper', 'desc' => 'Hijau juniper dalam', 'family' => 'sage',
            'core' => ['#3F6B5A', '#2D4F42', '#53897A', '#38604F', '#EAB308', '#B08A06', '#CE9E07']],
        'pakis' => ['name' => 'Pakis', 'desc' => 'Hijau pakis segar', 'family' => 'sage',
            'core' => ['#4E7A46', '#3A5C34', '#6A9C60', '#456D3E', '#8B5CF6', '#6D33D9', '#7C45F0']],

        'peach' => ['name' => 'Peach Soft', 'desc' => 'Peach lembut & hangat', 'family' => 'peach',
            'core' => ['#E8794A', '#BF5A34', '#F59E7E', '#D06A40', '#0EA5E9', '#0369A1', '#0B87BE']],
        'salem' => ['name' => 'Salem Orange', 'desc' => 'Oranye salem ramah', 'family' => 'peach',
            'core' => ['#F97316', '#C2410C', '#FB923C', '#DB6413', '#1E40AF', '#1E3A8A', '#25479A']],
        'apricot' => ['name' => 'Apricot', 'desc' => 'Aprikot manis, cerah', 'family' => 'peach',
            'core' => ['#EAA055', '#C07E38', '#F3BE84', '#D28F45', '#7C3AED', '#5B21B6', '#6B2BD4']],
        'coralreef' => ['name' => 'Coral Reef', 'desc' => 'Karang laut, segar', 'family' => 'peach',
            'core' => ['#F26B5B', '#C4453A', '#F89080', '#D9584A', '#0F766E', '#0B5A54', '#0D6B64']],

        'mustard' => ['name' => 'Mustard', 'desc' => 'Kuning mustard hangat', 'family' => 'mustard',
            'core' => ['#D9A21B', '#A87A10', '#EDBE4A', '#C08E16', '#3B82F6', '#1D4ED8', '#2A6BE0']],
        'kunyit' => ['name' => 'Kunyit', 'desc' => 'Kuning kunyit cerah', 'family' => 'mustard',
            'core' => ['#E0A800', '#B08300', '#F2C236', '#C79500', '#7C3AED', '#5B21B6', '#6B2BD4']],
        'madu' => ['name' => 'Madu', 'desc' => 'Kuning madu keemasan', 'family' => 'mustard',
            'core' => ['#C79212', '#9A7009', '#DEB040', '#B0810F', '#0D9488', '#0B7268', '#0C837A']],
        'saffron' => ['name' => 'Saffron', 'desc' => 'Oranye keemasan, mewah', 'family' => 'mustard',
            'core' => ['#E28A0B', '#B26C05', '#F2A63F', '#C97A08', '#4338CA', '#312E81', '#3B31B5']],

        'bronze' => ['name' => 'Bronze', 'desc' => 'Perunggu tua & elegan', 'family' => 'bronze',
            'core' => ['#9A6B33', '#754F24', '#B78A4E', '#8A5F2C', '#1E40AF', '#1E3A8A', '#25479A']],
        'copper' => ['name' => 'Copper', 'desc' => 'Tembaga hangat', 'family' => 'bronze',
            'core' => ['#B06A3B', '#8A5029', '#C98A5E', '#9E5E34', '#0F766E', '#0B5A54', '#0D6B64']],
        'brass' => ['name' => 'Brass', 'desc' => 'Kuningan berkilau', 'family' => 'bronze',
            'core' => ['#A98342', '#82632C', '#C4A162', '#96763A', '#6D28D9', '#4C1D95', '#5B21B6']],
        'antique' => ['name' => 'Antique Gold', 'desc' => 'Emas antik klasik', 'family' => 'bronze',
            'core' => ['#8E7434', '#6B5824', '#AE9150', '#7E672E', '#B91C1C', '#8C1414', '#A31818']],

        'bata' => ['name' => 'Bata Merah', 'desc' => 'Merah bata membumi', 'family' => 'bata',
            'core' => ['#B34A2F', '#8A3722', '#C96A4E', '#A04429', '#0E7490', '#155E75', '#0C6C86']],
        'burgundy' => ['name' => 'Burgundy', 'desc' => 'Merah burgundy dalam', 'family' => 'bata',
            'core' => ['#8C2E42', '#6A1F30', '#A8435A', '#7D2839', '#D4A017', '#A07A0E', '#BE8F13']],
        'rosewood' => ['name' => 'Rosewood', 'desc' => 'Merah kayu rose hangat', 'family' => 'bata',
            'core' => ['#9C4A54', '#75343C', '#B6656F', '#8B4149', '#0D9488', '#0B7268', '#0C837A']],
        'brick' => ['name' => 'Brick', 'desc' => 'Merah bata cerah', 'family' => 'bata',
            'core' => ['#C1502E', '#963C22', '#DB6D4B', '#AD4728', '#2563EB', '#1D4ED8', '#2A6BE0']],

        'anggur' => ['name' => 'Anggur', 'desc' => 'Ungu anggur pekat', 'family' => 'anggur',
            'core' => ['#6D2E6B', '#521F50', '#8B4489', '#612864', '#EAB308', '#B08A06', '#CE9E07']],
        'merlot' => ['name' => 'Merlot', 'desc' => 'Merah anggur merlot', 'family' => 'anggur',
            'core' => ['#7E2553', '#5D1A3C', '#9C3B6D', '#701F49', '#22D3EE', '#0E9FB8', '#17BEDA']],
        'wine' => ['name' => 'Wine', 'desc' => 'Merah wine tua', 'family' => 'anggur',
            'core' => ['#8A2C4E', '#671F39', '#A64569', '#7A2745', '#F59E0B', '#B87408', '#D98D09']],
        'velvet' => ['name' => 'Velvet', 'desc' => 'Ungu beludru mewah', 'family' => 'anggur',
            'core' => ['#5B2A86', '#431F63', '#7742A8', '#4F2577', '#F472B6', '#BE3E86', '#DB5A9E']],

        'fuchsia' => ['name' => 'Fuchsia', 'desc' => 'Fuchsia cerah & berani', 'family' => 'fuchsia',
            'core' => ['#C026D3', '#911C9F', '#E051F0', '#A821B8', '#0EA5E9', '#0369A1', '#0B87BE']],
        'cerise' => ['name' => 'Cerise', 'desc' => 'Merah ceri menyala', 'family' => 'fuchsia',
            'core' => ['#D1366B', '#9E284F', '#EB5B8B', '#BB2F5E', '#22C55E', '#15803D', '#19934A']],
        'orchid' => ['name' => 'Orchid', 'desc' => 'Ungu anggrek lembut', 'family' => 'fuchsia',
            'core' => ['#B14BC2', '#8A3899', '#C96FD8', '#A045AF', '#FACC15', '#B59409', '#D9AC0B']],
        'mauve' => ['name' => 'Mauve', 'desc' => 'Ungu merah sendu', 'family' => 'fuchsia',
            'core' => ['#8C5A93', '#6B4370', '#A87BB0', '#7D5084', '#0D9488', '#0B7268', '#0C837A']],

    ];
    /* Tema dengan `core` (7 warna inti) dilengkapi warna terangnya di sini. */
    foreach ($list as $k => &$t) {
        if (isset($t['core']) && is_array($t['core']) && count($t['core']) === 7) {
            [$brand, $brandDark, $brandMid, $brandBright, $accent, $accentDark, $accentMid] = $t['core'];
            $t = array_merge([
                'name' => $t['name'] ?? $k,
                'desc' => $t['desc'] ?? '',
                'family' => $t['family'] ?? 'lainnya',
                'brand' => $brand, 'brandDark' => $brandDark, 'brandMid' => $brandMid, 'brandBright' => $brandBright,
                'accent' => $accent, 'accentDark' => $accentDark, 'accentMid' => $accentMid,
            ], theme_derived($brand));
        }
        unset($t['core']);
    }
    unset($t);
    return $list;
}

function theme_current(): array
{
    $all = theme_list();
    $key = (string)setting('theme', 'magenta');
    return $all[$key] ?? $all['magenta'];
}
function theme_key(): string
{
    $all = theme_list();
    $key = (string)setting('theme', 'magenta');
    return isset($all[$key]) ? $key : 'magenta';
}

/**
 * SKALA TAMPILAN (ronde 39) — setelan "Ukuran Tampilan" di Pengaturan Sistem.
 *
 * Pemilik merasa tampilan di PC/laptop terlalu besar dan baru terasa pas setelah
 * memperkecil peramban ke 80%. Karena hampir seluruh ukuran teks memakai `rem`
 * (relatif ke <html>) sedangkan teks dasar memakai px pada `body`, KEDUANYA
 * diskalakan dari satu angka `--ui-scale` (1 = 100%).
 */
function ui_scale(): float
{
    $v = (int)setting('ui_scale', '80');                 // bawaan 80% (permintaan pemilik)
    if (!in_array($v, [70, 75, 80, 85, 90, 95, 100], true)) $v = 80;
    return $v / 100;
}

/** Pilihan skala tampilan untuk Pengaturan Sistem. */
function ui_scale_options(): array
{
    return [
        70 => '70% (paling rapat)', 75 => '75%', 80 => '80% (disarankan untuk PC/laptop)',
        85 => '85%', 90 => '90%', 95 => '95%', 100 => '100% (bawaan sistem)',
    ];
}

/**
 * Blok CSS yang menimpa variabel warna sesuai tema terpilih.
 * Dipakai di semua halaman (termasuk halaman login, struk, dan dokumen cetak).
 */
function theme_css(): string
{
    $t = theme_current();
    $rules = [
        '--magenta' => $t['brand'],
        '--magenta-dark' => $t['brandDark'],
        '--pink' => $t['brandMid'],
        '--pink-soft' => $t['soft'],
        '--rose-bg' => $t['bg'],
        '--leaf' => $t['accent'],
        '--line' => $t['line'],
        '--brand-accent' => $t['accent'],
        '--brand-accent-dark' => $t['accentDark'],
        '--brand-accent-mid' => $t['accentMid'],
        '--brand-soft' => $t['soft'],
        '--brand-tint' => $t['tint'],
        '--brand-tint2' => $t['tint2'],
        '--brand-hover' => $t['hover'],
        '--brand-hover2' => $t['hover2'],
        '--brand-bg3' => $t['bg3'],
        '--brand-chip' => $t['chip'],
        '--brand-bright' => $t['brandBright'],
    ];
    $css = ':root{';
    foreach ($rules as $k => $v) $css .= $k . ':' . $v . ';';
    /* Skala tampilan dikirim bersama variabel tema supaya berlaku juga di halaman
       login, struk, dan dokumen cetak (semuanya memakai theme_css()). */
    /* WAJIB memakai titik desimal (bukan num() yang memakai KOMA gaya Indonesia):
       CSS tidak mengenal "0,80" sehingga calc(16px * 0,80) dianggap tidak sah dan
       seluruh skala tampilan gagal diterapkan (terjadi saat pengembangan). */
    $css .= '--ui-scale:' . sprintf('%.2f', ui_scale()) . ';';
    $css .= '}';
    // latar berhias gradien mengikuti tema
    $css .= 'body{background-image:'
        . 'radial-gradient(900px 420px at 8% -5%, ' . $t['brand'] . '1A, transparent 60%),'
        . 'radial-gradient(700px 380px at 96% 4%, ' . $t['accent'] . '1A, transparent 60%),'
        . 'radial-gradient(800px 500px at 70% 100%, ' . $t['brand'] . '12, transparent 60%);}';
    $css .= '.sidebar{background:linear-gradient(170deg,' . $t['brandDark'] . ' 0%,' . $t['brand'] . ' 52%,' . $t['brandMid'] . ' 100%);}';
    $css .= '.stat.accent{background:linear-gradient(140deg,' . $t['brand'] . ',' . $t['brandMid'] . ');}';
    $css .= '.btn-primary{background:linear-gradient(135deg,' . $t['brandMid'] . ',' . $t['brand'] . ');}';
    $css .= '.btn-leaf{background:linear-gradient(135deg,' . $t['accentMid'] . ',' . $t['accentDark'] . ');}';
    $css .= '.stat.leaf{background:linear-gradient(140deg,' . $t['accentDark'] . ',' . $t['accent'] . ');}';
    $css .= '.pg.active{background:linear-gradient(135deg,' . $t['brandMid'] . ',' . $t['brand'] . ');}';
    $css .= '.progress>i{background:linear-gradient(90deg,' . $t['brandMid'] . ',' . $t['brand'] . ');}';
    $css .= '.auth-visual{background:linear-gradient(160deg,' . $t['brandDark'] . ',' . $t['brand'] . ' 55%,' . $t['brandMid'] . ');}';
    $css .= '.rank.r1{background:linear-gradient(135deg,#F7C948,#E8A200);color:#4A3400;}';
    return $css;
}

/* ==================================================================
   WARNA GRAFIK
   ------------------------------------------------------------------
   Masalah yang diperbaiki (temuan pemilik klinik): grafik "perbandingan
   progres antar cabang" memakai palet lama yang 5 warna pertamanya berasal
   dari satu keluarga warna tema (brand, brandMid, brandDark, accent,
   accentDark) sehingga garis/batang seri ke-1..ke-3 nyaris tidak dapat
   dibedakan. Itu juga berbahaya begitu cabangnya lebih dari 7: warna
   ke-11 dan seterusnya MENGULANG warna pertama (modulo), sehingga dua seri
   berwarna sama.

   Palet baru memakai 48 warna TERKURASI (chart_palette_base(), dipilih
   dengan farthest-point sampling) sehingga:
     - 2 seri  : merah vs cyan (jarak warna 450),
     - 7 seri  : jarak minimum antar seri masih 187,
     - 12 seri : 137,  24 seri : 97,  48 seri : 62,
   dan warna ke-11 TIDAK lagi mengulang warna ke-1.
   Dipakai SERAGAM oleh semua grafik: layar (Chart.js), Excel, PDF/email
   (grafik sisi-server), dan dokumen laporan cetak.
   ================================================================== */

/**
 * PALET KATEGORIKAL TERKURASI (48 warna, urut dari yang paling berbeda).
 *
 * Dihasilkan sekali dengan metode "farthest-point sampling": dari roda warna
 * 24 hue × 6 tingkat kecerahan, dipilih satu per satu warna yang paling jauh
 * dari semua warna yang sudah dipilih, dimulai dari magenta merek (#C2185B).
 * Hasilnya: setiap awalan daftar ini punya warna-warna yang saling berjauhan —
 * itulah yang membuat grafik dengan 2, 7, 12, sampai 24 seri tetap terbaca.
 * Jarak warna minimum antar seri (diuji `warna_grafik_check.js`):
 *   2 seri ≈ 450, 7 seri ≈ 187, 12 seri ≈ 137, 24 seri ≈ 97, 48 seri ≈ 62.
 *
 * JANGAN menambah/menyusun ulang warna secara manual tanpa menjalankan uji itu:
 * menaruh dua warna mirip berdekatan akan mengembalikan masalah lama (dua seri
 * yang tampak berwarna sama).
 */
function chart_palette_base(): array
{
    return [
        '#C2185B', '#26C5C5', '#C5C526', '#165A16', '#2626C5', '#26C526',
        '#CB48CB', '#A96623', '#38165A', '#1E6F8A', '#761313', '#26C575',
        '#8823A9', '#548A1E', '#4869CB', '#16495A', '#CB488A', '#69CB48',
        '#1E8A54', '#CB8A48', '#5A4916', '#CB4848', '#761376', '#88A923',
        '#1E1E8A', '#8A48CB', '#C52626', '#761345', '#269DC5', '#2344A9',
        '#1E8A1E', '#8A391E', '#C5269D', '#C59D26', '#ABCB48', '#26C59D',
        '#C526C5', '#23A944', '#44A923', '#CB6948', '#4848CB', '#CBAB48',
        '#767613', '#23A988', '#4423A9', '#2388A9', '#75C526', '#488ACB',
    ];
}

/** Roda warna + tingkat kecerahan tambahan (hanya dipakai bila seri > 48). */
function chart_hue_wheel(): array
{
    return [5, 120, 240, 35, 155, 275, 60, 185, 305, 90, 210, 335];
}

/** Tingkat kecerahan tambahan: [saturasi %, terang %]. */
function chart_tone_levels(): array
{
    return [[62, 52], [70, 24], [56, 62], [66, 36]];
}

/** HSL → "#RRGGBB". */
function chart_hsl_hex(float $h, float $s, float $l): string
{
    $h = fmod(($h % 360) + 360, 360) / 360;
    $s = max(0.0, min(1.0, $s));
    $l = max(0.0, min(1.0, $l));
    if ($s <= 0.0) {
        $v = (int)round($l * 255);
        return sprintf('#%02X%02X%02X', $v, $v, $v);
    }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function (float $t) use ($p, $q): float {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    };
    return sprintf('#%02X%02X%02X',
        (int)round($f($h + 1 / 3) * 255), (int)round($f($h) * 255), (int)round($f($h - 1 / 3) * 255));
}

/** Mode palet: 'kontras' (bawaan) atau 'tema' (mengikuti warna tema dulu). */
function chart_palette_mode(): string
{
    $m = (string)setting('chart_palette_mode', 'kontras');
    return in_array($m, ['kontras', 'tema'], true) ? $m : 'kontras';
}

/**
 * Seluruh palet kategorikal: 48 warna terkuras + tambahan roda warna (untuk
 * keadaan yang sangat jarang, lebih dari 48 seri dalam satu grafik).
 * Mode 'tema' menaruh warna tema di depan, lalu warna lain yang cukup berbeda.
 */
function chart_palette_full(): array
{
    $base = chart_palette_base();
    $extra = [];
    foreach (chart_tone_levels() as $tone) {
        foreach (chart_hue_wheel() as $h) {
            $hex = chart_hsl_hex((float)$h, $tone[0] / 100, $tone[1] / 100);
            if (!in_array($hex, $base, true) && !in_array($hex, $extra, true)) $extra[] = $hex;
        }
    }
    if (chart_palette_mode() === 'tema') {
        $t = theme_current();
        $head = [$t['brand'], $t['accent'], $t['brandDark'], $t['accentDark']];
        /* Buang dari daftar warna yang terlalu mirip warna tema di depan,
           supaya seri ke-1..ke-4 benar-benar berbeda satu sama lain. */
        $keep = [];
        foreach (array_merge($base, $extra) as $hex) {
            $jauh = true;
            foreach ($head as $h) {
                if (chart_color_distance($hex, $h) < 40) { $jauh = false; break; }
            }
            if ($jauh) $keep[] = $hex;
        }
        return array_values(array_unique(array_merge($head, $keep)));
    }
    return array_values(array_unique(array_merge($base, $extra)));
}

/** Warna untuk $n seri kategorikal (tidak pernah mengulang selama $n ≤ 72). */
function chart_colors(int $n): array
{
    $n = max(1, $n);
    $all = chart_palette_full();
    if ($n <= count($all)) return array_slice($all, 0, $n);
    /* Lebih dari 72 seri: ulangi daftar (keadaan yang sangat jarang) — tetap
       dipakai warna berbeda-beda, hanya mungkin berulang setelah 72. */
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = $all[$i % count($all)];
    return $out;
}

/** Jarak warna sederhana (0..441) — dipakai untuk memastikan warna cukup beda. */
function chart_color_distance(string $a, string $b): float
{
    $p = function (string $hex): array {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    };
    [$r1, $g1, $b1] = $p($a);
    [$r2, $g2, $b2] = $p($b);
    /* Bobot persepsi (mata lebih peka pada hijau) supaya angkanya mendekati
       "terlihat berbeda atau tidak". */
    return sqrt(2 * ($r1 - $r2) ** 2 + 4 * ($g1 - $g2) ** 2 + 3 * ($b1 - $b2) ** 2);
}

/** Warna tetap untuk seri yang punya MAKNA (treatment/skincare/total/±). */
function chart_series_colors(): array
{
    return [
        /* Treatment (magenta merek) dan Skincare (hijau TUA) sengaja dibuat
           sangat kontras — versi lama memakai hijau muda #8BC34A yang
           perbedaannya tipis saat dicetak/dilihat di HP. */
        'treatment' => (string)setting('chart_color_treatment', '#C2185B'),
        'skincare'  => (string)setting('chart_color_skincare', '#2E7D32'),
        'total'     => (string)setting('chart_color_total', '#37474F'),
        'positif'   => (string)setting('chart_color_positif', '#2E7D32'),
        'negatif'   => (string)setting('chart_color_negatif', '#C62828'),
    ];
}

/** Satu warna seri bermakna (mis. chart_series_color('skincare')). */
function chart_series_color(string $key): string
{
    $s = chart_series_colors();
    return $s[$key] ?? $s['treatment'];
}

/** Warna pengisi area untuk seri Total (transparan dari warna garisnya). */
function chart_color_rgba(string $hex, float $alpha): string
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return 'rgba(' . hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ','
        . hexdec(substr($h, 4, 2)) . ',' . $alpha . ')';
}

/**
 * Palet grafik (nama lama dipertahankan) — kini berisi SELURUH palet
 * kategorikal sehingga pemakaian `palette[i % count]` pun tidak langsung
 * mengulang warna. Halaman mengirim daftar ini ke JavaScript dan JavaScript
 * memakai `Naveena.paletteFor(n)` untuk mengambil tepat n warna pertama.
 */
function theme_chart_palette(): array
{
    return chart_palette_full();
}
