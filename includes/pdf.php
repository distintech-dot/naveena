<?php
/**
 * Pembuat PDF minimal — menghasilkan berkas PDF asli tanpa library eksternal
 * (server ini tidak punya ekstensi PDF/zip). Dipakai untuk struk/invoice dan
 * untuk lampiran yang dikirim ke WhatsApp.
 *
 * Font: Helvetica & Helvetica-Bold (base-14, tidak perlu menyematkan font).
 * Teks dikodekan ke WinAnsiEncoding; karakter di luar jangkauan diganti padanan
 * ASCII terdekat supaya struk tetap terbaca (tidak muncul simbol rusak).
 */
declare(strict_types=1);

require_once __DIR__ . '/png.php';

class MiniPdf
{
    public float $W;
    public float $H;
    public float $y;
    public float $margin;
    public float $lineHeight = 11.5;
    public bool $dry = false;

    private array $pages = [];
    private string $cur = '';
    private int $pageCount = 0;
    /** Gambar yang dipakai: name => ['w','h','data'] (data = RGB mentah) */
    private array $images = [];
    /** Gambar per halaman: index halaman => [name => true] */
    private array $pageImages = [];
    /** Tinggi halaman dipakai saat render; diisi dari hasil pengukuran. */
    private float $pageHeight = 800;

    private const HELV = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
        '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722,
        'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];
    private const HELV_BOLD = [
        ' ' => 278, '!' => 333, '"' => 474, '#' => 556, '$' => 556, '%' => 889, '&' => 722, "'" => 238,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 333, ';' => 333, '<' => 584, '=' => 584, '>' => 584, '?' => 611,
        '@' => 975, 'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 333, '\\' => 278, ']' => 333, '^' => 584, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'e' => 556, 'f' => 333, 'g' => 611,
        'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611, 'o' => 611,
        'p' => 611, 'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556, 'w' => 778,
        'x' => 556, 'y' => 556, 'z' => 500, '{' => 389, '|' => 280, '}' => 389, '~' => 584,
    ];

    public function __construct(float $width = 226.77, float $height = 800, float $margin = 13)
    {
        $this->W = $width;
        $this->H = $height;
        $this->margin = $margin;
        $this->pageHeight = $height;
        $this->y = $height - $margin;
        $this->newPage();
    }

    /** Panjang teks (poin) memakai metrik font base-14. */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $table = $bold ? self::HELV_BOLD : self::HELV;
        $w = 0.0;
        foreach ($this->toWinAnsi($s) as $ch) {
            $w += $table[$ch] ?? 556;
        }
        return $w * $size / 1000;
    }

    /** Ubah string UTF-8 → array karakter WinAnsi yang didukung font. */
    public function toWinAnsi(string $s): array
    {
        $map = [
            '—' => '-', '–' => '-', '−' => '-', '•' => '*', '’' => "'", '‘' => "'",
            '“' => '"', '”' => '"', '…' => '...', '✓' => 'v', '⚠' => '!', '️' => '', '❤' => '<3',
            '≤' => '<=', '≥' => '>=', '×' => 'x', '·' => '.', '∞' => '8', '“' => '"',
            'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'É' => 'E', 'Á' => 'A', 'Ó' => 'O', 'ç' => 'c',
        ];
        $s = strtr($s, $map);
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($s[$i]);
            if ($b < 0x80) { $out[] = $s[$i]; continue; }
            // multi-byte UTF-8 -> cari padanan; kalau tidak ada pakai '?'
            $cp = null;
            if (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i++;
            } elseif (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
                $i += 2;
            } elseif (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $i += 3;
                $cp = 0x3F;
            }
            if ($cp !== null && $cp >= 160 && $cp <= 255) {
                $out[] = chr($cp);          // Latin-1 aman di WinAnsi
            } else {
                $out[] = '?';
            }
        }
        return $out;
    }

    public function newPage(): void
    {
        if ($this->pageCount > 0) $this->pages[] = $this->cur;
        $this->cur = '';
        $this->pageCount++;
        $this->pageImages[$this->pageCount - 1] = $this->pageImages[$this->pageCount - 1] ?? [];
        $this->y = $this->pageHeight - $this->margin;
    }

    public function ensure(float $needed): void
    {
        if ($this->y - $needed < $this->margin) $this->newPage();
    }

    public function gap(float $h): void
    {
        $this->y -= $h;
    }

    private function raw(string $ops): void
    {
        if (!$this->dry) $this->cur .= $ops . "\n";
    }

    private function esc(string $s): string
    {
        $out = '';
        foreach ($this->toWinAnsi($s) as $ch) {
            if ($ch === '(' || $ch === ')' || $ch === '\\') $out .= '\\' . $ch;
            elseif (ord($ch) < 32) $out .= ' ';
            else $out .= $ch;
        }
        return $out;
    }

    public function text(float $x, string $s, float $size = 8.5, bool $bold = false, float $y = null): void
    {
        $y = $y ?? $this->y;
        $font = $bold ? '/F2' : '/F1';
        $this->raw(sprintf("BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET", $font, $size, $x, $y, $this->esc($s)));
    }

    public function textRight(float $xRight, string $s, float $size = 8.5, bool $bold = false, float $y = null): void
    {
        $w = $this->textWidth($s, $size, $bold);
        $this->text($xRight - $w, $s, $size, $bold, $y);
    }

    public function textCenter(string $s, float $size = 8.5, bool $bold = false, float $y = null): void
    {
        $w = $this->textWidth($s, $size, $bold);
        $this->text(($this->W - $w) / 2, $s, $size, $bold, $y);
    }

    /** Baris teks mengalir (memakai kursor y lalu turun). */
    public function line(string $s, float $size = 8.5, bool $bold = false, float $indent = 0, bool $center = false): void
    {
        $this->ensure($this->lineHeight);
        if ($center) $this->textCenter($s, $size, $bold);
        else $this->text($this->margin + $indent, $s, $size, $bold);
        $this->gap($this->lineHeight);
    }

    /** Dua kolom: label kiri, nilai rata kanan pada baris yang sama. */
    public function kv(string $label, string $value, float $size = 8.5, bool $boldVal = false, float $gapY = null): void
    {
        $this->ensure($gapY ?? $this->lineHeight);
        $this->text($this->margin, $label, $size);
        $this->textRight($this->W - $this->margin, $value, $size, $boldVal);
        $this->gap($gapY ?? $this->lineHeight);
    }

    /**
     * Kotak berisi warna (dipakai kartu member: pita warna & blok aksen).
     * Warna diberikan dalam "#RRGGBB"; koordinat PDF memakai titik (pt) dengan
     * titik nol di kiri-bawah.
     */
    public function fillRect(float $x, float $y, float $w, float $h, string $hex = '#C2185B'): void
    {
        $c = ltrim($hex, '#');
        if (strlen($c) === 3) $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        $r = hexdec(substr($c, 0, 2)) / 255;
        $g = hexdec(substr($c, 2, 2)) / 255;
        $b = hexdec(substr($c, 4, 2)) / 255;
        $this->raw(sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f", $r, $g, $b, $x, $y, $w, $h));
        /* Kembalikan warna teks ke hitam (non-stroking) supaya tulisan berikutnya
           tidak ikut berwarna. */
        $this->raw('0 g');
    }

    /**
     * Gambar latar penuh (full-bleed) TANPA menggeser kursor dan tanpa `ensure()`
     * — dipakai kartu member yang latarnya menutupi seluruh halaman. Memakai
     * ensure() akan memicu halaman baru karena tingginya sama dengan tinggi halaman.
     */
    public function backgroundImage(string $path): bool
    {
        if (!is_readable($path)) return false;
        $needH = (int)ceil($this->H * 300 / 72);
        $png = $needH > 0 ? png_scaled_cached($path, $needH) : png_to_rgb((string)file_get_contents($path));
        if ($png === null) return false;
        $name = 'Im' . (count($this->images) + 1);
        $hash = md5($png['rgb']);
        foreach ($this->images as $n => $img) {
            if ($img['hash'] === $hash) { $name = $n; break; }
        }
        if (!isset($this->images[$name])) {
            $this->images[$name] = ['w' => $png['width'], 'h' => $png['height'],
                                    'data' => gzcompress($png['rgb'], 9), 'hash' => $hash];
        }
        $this->pageImages[$this->pageCount - 1][$name] = true;
        $this->raw(sprintf("q %.2F 0 0 %.2F 0 0 cm /%s Do Q", $this->W, $this->H, $name));
        return true;
    }

    /**
     * Letakkan gambar TEPAT pada kotak (x, yAtas) — koordinat absolut, kursor
     * teks tidak digeser dan TIDAK memicu halaman baru.
     *
     * PENTING (perbaikan): versi lama memanggil imagePngFile() yang menggambar
     * memakai KURSOR ($this->y), bukan $yTop, sehingga gambar mendarat di posisi
     * yang tidak diminta — logo di dalam kartu member mencetak di tempat lain
     * (di atas kartu, tidak menyatu) dan latar kartu tidak mengisi kotaknya.
     */
    public function imagePngAt(string $path, float $x, float $yTop, float $maxWidth, float $maxHeight, float $dpi = 300.0): array
    {
        $saveY = $this->y;
        $res = $this->imagePngFile($path, $x, $yTop, $maxWidth, $maxHeight, $dpi, false);
        $this->y = $saveY;
        return $res;
    }

    /** Kembalikan kursor teks ke tepi atas halaman (setelah menggambar latar penuh). */
    public function setCursorTop(): void
    {
        $this->y = $this->H - $this->margin;
    }

    /** Garis berwarna (mengikuti tema) — garis abu-abu lama tetap dipakai di tempat lain. */
    public function lineColored(float $x1, float $y1, float $x2, float $y2, string $hex = '#C2185B', float $width = 1.0): void
    {
        $c = ltrim($hex, '#');
        if (strlen($c) === 3) $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        $this->raw(sprintf("%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S 0 G",
            $width, hexdec(substr($c, 0, 2)) / 255, hexdec(substr($c, 2, 2)) / 255, hexdec(substr($c, 4, 2)) / 255,
            $x1, $y1, $x2, $y2));
    }

    /** Teks pada koordinat absolut (tanpa menggeser kursor) — dipakai kartu member. */
    public function textAt(float $x, float $y, string $s, float $size = 8.5, bool $bold = false, string $hex = ''): void
    {
        if ($hex !== '') {
            $c = ltrim($hex, '#');
            if (strlen($c) === 3) $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
            $this->raw(sprintf("%.3F %.3F %.3F rg", hexdec(substr($c, 0, 2)) / 255, hexdec(substr($c, 2, 2)) / 255, hexdec(substr($c, 4, 2)) / 255));
        }
        $this->text($x, $s, $size, $bold, $y);
        if ($hex !== '') $this->raw('0 g');
    }

    /** Garis lurus antar dua titik (dipakai pemisah pada kartu). */
    public function lineAt(float $x1, float $y1, float $x2, float $y2, float $gray = 0.75): void
    {
        $this->raw(sprintf("%.2F w %.2F G %.2F %.2F m %.2F %.2F l S 0 G", 0.6, $gray, $x1, $y1, $x2, $y2));
    }

    public function hr(float $inset = 0): void
    {
        $this->ensure(6);
        $y = $this->y + 3;
        if (!$this->dry) {
            $this->cur .= sprintf("0.5 w 0.6 G %.2F %.2F m %.2F %.2F l S\n1 G\n",
                $this->margin + $inset, $y, $this->W - $this->margin - $inset, $y);
        }
        $this->gap(7);
    }

    /** Pecah teks menjadi beberapa baris sesuai lebar maksimum. */
    public function wrap(string $s, float $maxWidth, float $size = 8.5, bool $bold = false): array
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return [''];
        $words = explode(' ', $s);
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->textWidth($try, $size, $bold) <= $maxWidth || $cur === '') {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    public function paragraph(string $s, float $size = 8.5, bool $bold = false, float $indent = 0, bool $center = false): void
    {
        $max = $this->W - $this->margin * 2 - $indent;
        foreach ($this->wrap($s, $max, $size, $bold) as $l) {
            $this->line($l, $size, $bold, $indent, $center);
        }
    }

    /** Dua kolom dengan pembungkusan teks (mis. nama item panjang). */
    public function kvWrap(string $label, string $value, float $size = 8.5, float $splitRatio = 0.62): void
    {
        $maxLabel = ($this->W - $this->margin * 2) * $splitRatio;
        $labelLines = $this->wrap($label, $maxLabel, $size);
        $valueLines = $this->wrap($value, ($this->W - $this->margin * 2) - $maxLabel - 4, $size);
        $n = max(count($labelLines), count($valueLines));
        for ($i = 0; $i < $n; $i++) {
            $this->ensure($this->lineHeight);
            if (isset($labelLines[$i])) $this->text($this->margin, $labelLines[$i], $size);
            if (isset($valueLines[$i])) $this->textRight($this->W - $this->margin, $valueLines[$i], $size, true);
            $this->gap($this->lineHeight);
        }
    }

    /**
     * Tempelkan berkas PNG pada dokumen.
     *
     * @param bool $flow true (bawaan): mengalir seperti teks — memakai kursor
     *                   ($this->y) sebagai batas atas, boleh pindah halaman, dan
     *                   kursor digeser turun. false: koordinat mutlak ($yTop
     *                   akan dihormati, tanpa perpindahan halaman, tanpa geser
     *                   kursor) — dipakai kartu member.
     */
    public function imagePngFile(string $path, float $x, float $yTop, float $maxWidth, float $maxHeight,
                                 float $dpi = 300.0, bool $flow = true): array
    {
        if (!is_readable($path)) return ['w' => 0.0, 'h' => 0.0];
        /* Perkecil SAAT MEMBACA (bukan setelah) supaya logo beresolusi besar
           tidak menghabiskan memory_limit PHP. 300 dpi cukup untuk hasil cetak.
           Hasilnya di-cache ke berkas: gambar besar hanya diproses sekali. */
        $needH = (int)ceil($maxHeight * $dpi / 72.0);
        /* Pengaman: gambar yang sangat besar TANPA cache bisa memerlukan puluhan
           detik untuk didekode (PNG filtering bersifat berurutan). Bila cache
           belum ada, lewati saja logo agar dokumen tidak menggantung —
           Pengaturan akan menampilkan peringatan agar logo diperkecil. */
        if ($needH > 0 && !png_cache_exists($path, $needH)) {
            $dim = png_dimensions((string)file_get_contents($path));
            if ($dim && ($dim['width'] > 2000 || $dim['height'] > 2000)) {
                return ['w' => 0.0, 'h' => 0.0];
            }
        }
        $png = $needH > 0 ? png_scaled_cached($path, $needH) : png_to_rgb((string)file_get_contents($path));
        if ($png === null) return ['w' => 0.0, 'h' => 0.0];

        $name = 'Im' . (count($this->images) + 1);
        // hindari duplikasi data gambar yang sama
        $hash = md5($png['rgb']);
        foreach ($this->images as $n => $img) {
            if ($img['hash'] === $hash) { $name = $n; break; }
        }
        if (!isset($this->images[$name])) {
            /* /FlateDecode pada PDF = aliran zlib, jadi pakai gzcompress()
               (gzdeflate menghasilkan deflate mentah -> ditolak pembaca PDF). */
            $this->images[$name] = ['w' => $png['width'], 'h' => $png['height'],
                                    'data' => gzcompress($png['rgb'], 9), 'hash' => $hash];
        }
        $scale = min($maxWidth / $png['width'], $maxHeight / $png['height']);
        $w = $png['width'] * $scale;
        $h = $png['height'] * $scale;
        /* ensure() HARUS dipanggil SEBELUM mencatat gambar ke halaman: bila sisa
           ruang tidak cukup, ia membuka halaman baru. Kalau pencatatannya
           dilakukan lebih dulu, gambar tergambar di halaman baru tetapi terdaftar
           di halaman lama sehingga tidak muncul di pembaca PDF
           ("XObject 'ImN' is unknown" — pernah terjadi pada lampiran grafik). */
        if ($flow) $this->ensure($h);
        $this->pageImages[$this->pageCount - 1][$name] = true;
        $top = $flow ? $this->y : $yTop;
        $y = $top - $h;              // yTop adalah batas atas gambar
        $this->raw(sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q", $w, $h, $x, $y, $name));
        /* PENTING: kursor PDF adalah GARIS DASAR teks, sedangkan gambar diletakkan
           dari tepi bawahnya. Tanpa tambahan tinggi baris, teks baris berikutnya
           naik masuk ke area gambar — judul "LAPORAN LENGKAP KINERJA KLINIK"
           pernah menabrak logo pada "PDF Lengkap + Grafik". */
        if ($flow) $this->gap($h + $this->lineHeight);
        return ['w' => $w, 'h' => $h];
    }

    /**
     * Tempelkan PNG rata tengah dengan batas ukuran tertentu.
     *
     * Lebar yang benar-benar digambar dihitung dulu (memakai cache yang sama
     * dengan imagePngFile, termasuk pemotongan bagian kosong) — kalau hanya
     * memakai dimensi berkas aslinya, logo yang punya banyak area transparan
     * akan tampak bergeser dari tengah.
     */
    public function imagePngCentered(string $path, float $maxWidth, float $maxHeight): array
    {
        $s = $this->imagePngSize($path, $maxWidth, $maxHeight);
        if ($s['w'] <= 0) return ['w' => 0.0, 'h' => 0.0];
        $x = ($this->W - $s['w']) / 2;
        return $this->imagePngFile($path, $x, $this->y, $maxWidth, $maxHeight);
    }

    /**
     * Ukuran tampil (pt) sebuah PNG bila dibatasi maxWidth/maxHeight, memakai
     * cache pemotongan/pengecilan yang sama dengan imagePngFile (tanpa
     * menggambar apa pun).
     */
    public function imagePngSize(string $path, float $maxWidth, float $maxHeight, float $dpi = 300.0): array
    {
        if (!is_readable($path)) return ['w' => 0.0, 'h' => 0.0];
        $needH = (int)ceil($maxHeight * $dpi / 72.0);
        /* Pengaman yang sama dengan imagePngFile: gambar sangat besar tanpa
           cache dilewati supaya dokumen tidak menggantung. */
        if ($needH > 0 && !png_cache_exists($path, $needH)) {
            $dim = png_dimensions((string)file_get_contents($path));
            if ($dim && ($dim['width'] > 2000 || $dim['height'] > 2000)) return ['w' => 0.0, 'h' => 0.0];
        }
        $png = $needH > 0 ? png_scaled_cached($path, $needH) : png_to_rgb((string)file_get_contents($path));
        if ($png === null) return ['w' => 0.0, 'h' => 0.0];
        $scale = min($maxWidth / $png['width'], $maxHeight / $png['height']);
        return ['w' => $png['width'] * $scale, 'h' => $png['height'] * $scale];
    }

    /** Apakah ada gambar yang bisa ditempel (mis. logo tersedia). */
    public function hasImage(string $path): bool
    {
        return is_readable($path) && png_to_rgb((string)file_get_contents($path), 64) !== null;
    }

    /** Bangun berkas PDF. */
    public function output(): string
    {
        $pages = $this->pages;
        if ($this->cur !== '') $pages[] = $this->cur;
        if (!$pages) $pages = [''];

        $objects = [];
        $nPages = count($pages);
        $firstPageObj = 5;
        $kids = [];
        for ($i = 0; $i < $nPages; $i++) $kids[] = ($firstPageObj + $i * 2) . ' 0 R';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $nPages . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        /* Nomor objek untuk gambar, tepat setelah objek halaman terakhir —
           tanpa nomor yang terlewat, karena lubang nomor membuat tabel xref
           menunjuk objek kosong dan pembaca PDF menolak berkasnya. */
        $imgObjNum = $firstPageObj + $nPages * 2;
        $imgRefs = [];
        foreach ($this->images as $name => $img) {
            $imgRefs[$name] = $imgObjNum;
            /* Penting: newline harus karakter asli, bukan literal "\n".
               Gabungkan dengan string berkutip ganda agar \n benar-benar ditulis. */
            $objects[$imgObjNum] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB "
                . "/BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n",
                $img['w'], $img['h'], strlen($img['data'])
            ) . $img['data'] . "\nendstream";
            $imgObjNum++;
        }
        for ($i = 0; $i < $nPages; $i++) {
            $pageObj = $firstPageObj + $i * 2;
            $contentObj = $pageObj + 1;
            $xo = '';
            foreach (array_keys($this->pageImages[$i] ?? []) as $name) {
                if (isset($imgRefs[$name])) $xo .= ' /' . $name . ' ' . $imgRefs[$name] . ' 0 R';
            }
            $resources = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($xo !== '' ? ' /XObject <<' . $xo . ' >>' : '');
            $objects[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> /Contents %d 0 R >>',
                $this->W, $this->pageHeight, $resources, $contentObj
            );
            $objects[$contentObj] = '<< /Length ' . strlen($pages[$i]) . " >>\nstream\n" . $pages[$i] . "endstream";
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $maxObj = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF\n";
        return $pdf;
    }
}
