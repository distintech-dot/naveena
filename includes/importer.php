<?php
/**
 * Import helper — membaca file tabel dari berbagai format TANPA library eksternal
 * (ekstensi zip/SimpleXML tidak tersedia di lingkungan ini):
 *   - .xlsx  : ZIP dibaca sendiri (gzinflate) lalu XML di-parse dengan regex
 *   - .csv / .txt / .tsv : fgetcsv + deteksi pemisah otomatis
 *   - .json  : array of object
 *   - .xls (BIFF lama) : tidak didukung, diberi pesan jelas
 * Juga menyediakan penulis .xlsx sederhana untuk file template.
 */
declare(strict_types=1);

const IMPORT_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB per file
const IMPORT_MAX_ROWS  = 5000;               // baris data per proses

/* ------------------------------------------------------------------ *
 * Utilitas teks & konversi nilai
 * ------------------------------------------------------------------ */
/** Ubah byte non-UTF8 (Windows-1252 / Latin-1) menjadi UTF-8 tanpa mbstring/iconv. */
function to_utf8(string $s): string
{
    if ($s === '') return '';
    if (preg_match('//u', $s)) return $s;   // sudah UTF-8 valid
    static $cp1252 = [
        0x80 => '€', 0x82 => '‚', 0x83 => 'ƒ', 0x84 => '„', 0x85 => '…', 0x86 => '†', 0x87 => '‡',
        0x88 => 'ˆ', 0x89 => '‰', 0x8A => 'Š', 0x8B => '‹', 0x8C => 'Œ', 0x8E => 'Ž', 0x91 => '‘',
        0x92 => '’', 0x93 => '“', 0x94 => '”', 0x95 => '•', 0x96 => '–', 0x97 => '—', 0x98 => '˜',
        0x99 => '™', 0x9A => 'š', 0x9B => '›', 0x9C => 'œ', 0x9E => 'ž', 0x9F => 'Ÿ',
    ];
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $b = ord($s[$i]);
        if ($b < 0x80) { $out .= $s[$i]; continue; }
        if (isset($cp1252[$b])) { $out .= $cp1252[$b]; continue; }
        $out .= chr(0xC0 | ($b >> 6)) . chr(0x80 | ($b & 0x3F));   // Latin-1 -> UTF-8
    }
    return $out;
}
function clean_cell($v): string
{
    $v = (string)$v;
    $v = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $v);
    return trim($v);
}
/** Nilai numerik: hilangkan notasi ilmiah (penting untuk NIK besar) dan nol di belakang. */
function num_cell(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $v)) return $v;
    if (stripos($v, 'e') !== false) {
        $f = (float)$v;
        return (abs($f - round($f)) < 0.0000001) ? sprintf('%.0f', $f) : rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }
    if (strpos($v, '.') !== false) {
        $v = rtrim(rtrim($v, '0'), '.');
        return $v === '' ? '0' : $v;
    }
    return $v;
}
function header_key(string $s): string
{
    $s = strtolower(clean_cell($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim((string)$s, '_');
}
/** Normalisasi tanggal: terima Y-m-d, d/m/Y, d-m-Y, d.m.Y, "28 September 2026", serial Excel. */
function parse_date_cell(string $v): string
{
    $v = clean_cell($v);
    if ($v === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})/', $v, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($mo > 12 && $d <= 12) { $t = $d; $d = $mo; $mo = $t; }   // format M/d/Y
        if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    if (preg_match('/^\d{4}[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int)substr($v, 0, 4), (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/^\d+([\.,]\d+)?$/', $v)) {   // serial Excel
        $n = (float)str_replace(',', '.', $v);
        if ($n > 20000 && $n < 80000) {
            $ts = (int)round(($n - 25569) * 86400);
            return gmdate('Y-m-d', $ts);
        }
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : '';
}
function parse_money_cell(string $v): float
{
    $v = clean_cell($v);
    if ($v === '') return 0.0;
    $neg = (strpos($v, '-') === 0) || preg_match('/^\(.*\)$/', $v);
    $v = preg_replace('/[^0-9,\.]/', '', $v);
    if ($v === '') return 0.0;
    // "150.000,50" (Indonesia) vs "150,000.50" (Inggris)
    if (preg_match('/,\d{1,2}$/', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '', $v);
    }
    $f = (float)$v;
    return $neg ? -$f : $f;
}

/* ------------------------------------------------------------------ *
 * Pembaca ZIP + XLSX
 * ------------------------------------------------------------------ */
function zip_read_entries(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false) throw new RuntimeException('Gagal membaca file.');
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('File bukan arsip XLSX yang valid (struktur ZIP tidak ditemukan).');
    $count = unpack('v', substr($data, $eocd + 10, 2))[1];
    $cdOff = unpack('V', substr($data, $eocd + 16, 4))[1];
    $entries = [];
    $p = $cdOff;
    for ($i = 0; $i < $count; $i++) {
        if (substr($data, $p, 4) !== "PK\x01\x02") break;
        $method = unpack('v', substr($data, $p + 10, 2))[1];
        $csize  = unpack('V', substr($data, $p + 20, 4))[1];
        $usize  = unpack('V', substr($data, $p + 24, 4))[1];
        $nlen   = unpack('v', substr($data, $p + 28, 2))[1];
        $elen   = unpack('v', substr($data, $p + 30, 2))[1];
        $clen   = unpack('v', substr($data, $p + 32, 2))[1];
        $lho    = unpack('V', substr($data, $p + 42, 4))[1];
        $name   = substr($data, $p + 46, $nlen);
        $lhlen  = unpack('v', substr($data, $lho + 26, 2))[1];
        $doff   = $lho + 30 + $lhlen + unpack('v', substr($data, $lho + 28, 2))[1];
        $raw    = substr($data, $doff, $csize);
        $content = false;
        if ($method === 0) {
            $content = $raw;
        } elseif ($method === 8) {
            $content = @gzinflate($raw);
            if ($content === false) $content = @gzuncompress($raw);
        }
        if ($content === false && $usize === 0) $content = '';
        if ($content !== false) $entries[$name] = $content;
        $p += 46 + $nlen + $elen + $clen;
    }
    if (!$entries) throw new RuntimeException('Isi arsip XLSX tidak dapat dibaca.');
    return $entries;
}

function xlsx_shared_strings(array $entries): array
{
    $out = [];
    if (!isset($entries['xl/sharedStrings.xml'])) return $out;
    $xml = $entries['xl/sharedStrings.xml'];
    if (preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $xml, $m)) {
        foreach ($m[1] as $si) {
            $txt = '';
            if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $si, $t)) {
                foreach ($t[1] as $piece) $txt .= html_entity_decode($piece, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $out[] = clean_cell($txt);
        }
    }
    return $out;
}

/** Daftar format tanggal dari styles.xml (untuk mengenali sel tanggal). */
function xlsx_date_styles(array $entries): array
{
    $builtin = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];
    $custom = [];
    $styleDate = [];
    if (!isset($entries['xl/styles.xml'])) return $styleDate;
    $xml = $entries['xl/styles.xml'];
    if (preg_match_all('#<numFmt\b[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"#', $xml, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $code = preg_replace('#\[[^\]]*\]#', '', $row[2]);
            $code = preg_replace('#"[^"]*"#', '', $code);
            if (preg_match('/[yYdD]/', $code) || preg_match('/m{1,4}/', $code)) {
                $custom[(int)$row[1]] = true;
            }
        }
    }
    if (preg_match('#<cellXfs\b[^>]*>(.*?)</cellXfs>#s', $xml, $m)) {
        if (preg_match_all('#<xf\b[^>]*/?>#', $m[1], $xf)) {
            foreach ($xf[0] as $i => $node) {
                if (preg_match('#numFmtId="(\d+)"#', $node, $nm)) {
                    $id = (int)$nm[1];
                    $styleDate[$i] = in_array($id, $builtin, true) || isset($custom[$id]);
                } else {
                    $styleDate[$i] = false;
                }
            }
        }
    }
    return $styleDate;
}

function xlsx_first_sheet_path(array $entries): string
{
    $target = 'xl/worksheets/sheet1.xml';
    if (isset($entries['xl/workbook.xml'], $entries['xl/_rels/workbook.xml.rels'])) {
        if (preg_match('#<sheet\b[^>]*r:id="([^"]+)"#', $entries['xl/workbook.xml'], $m)) {
            $rid = $m[1];
            if (preg_match('#<Relationship\b[^>]*Id="' . preg_quote($rid, '#') . '"[^>]*Target="([^"]+)"#', $entries['xl/_rels/workbook.xml.rels'], $r)) {
                $t = ltrim(str_replace('\\', '/', $r[1]), '/');
                $t = preg_replace('#^\.\./#', '', $t);
                if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
                if (isset($entries[$t])) $target = $t;
            }
        } elseif (preg_match('#<Relationship\b[^>]*Target="([^"]*worksheets/[^"]+)"#', $entries['xl/_rels/workbook.xml.rels'], $r)) {
            $t = ltrim(preg_replace('#^\.\./#', '', str_replace('\\', '/', $r[1])), '/');
            if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
            if (isset($entries[$t])) $target = $t;
        }
    }
    if (!isset($entries[$target])) {
        foreach (array_keys($entries) as $k) {
            if (preg_match('#^xl/worksheets/sheet\d*\.xml$#', $k)) { $target = $k; break; }
        }
    }
    return $target;
}

function col_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return max(0, $n - 1);
}

function excel_serial_to_date(float $serial, bool $date1904 = false): string
{
    $days = (int)floor($serial);
    if ($date1904) return gmdate('Y-m-d', (int)round(($days - 24107) * 86400));
    if ($days >= 61) $days--;   // koreksi bug tahun 1900 Excel
    return gmdate('Y-m-d', (int)round(($days - 25568) * 86400));
}

/** Baca sheet pertama sebuah .xlsx menjadi array of rows (array of string). */
function xlsx_rows(string $path, int $maxRows = IMPORT_MAX_ROWS + 50): array
{
    $entries = zip_read_entries($path);
    $shared  = xlsx_shared_strings($entries);
    $dateSty = xlsx_date_styles($entries);
    $date1904 = false;
    if (isset($entries['xl/workbook.xml']) && preg_match('#<workbookPr\b[^>]*date1904="(1|true)"#', $entries['xl/workbook.xml'])) {
        $date1904 = true;
    }
    $sheetPath = xlsx_first_sheet_path($entries);
    if (!isset($entries[$sheetPath])) throw new RuntimeException('Sheet pertama tidak ditemukan di dalam file XLSX.');
    $xml = $entries[$sheetPath];

    $rows = [];
    if (!preg_match_all('#<row\b([^>]*)>(.*?)</row>#s', $xml, $rm, PREG_SET_ORDER)) return $rows;
    foreach ($rm as $rowNode) {
        if (count($rows) >= $maxRows) break;
        $cells = [];
        if (preg_match_all('#<c\b([^>]*)(?:/>|>(.*?)</c>)#s', $rowNode[2], $cm, PREG_SET_ORDER)) {
            $autoIdx = 0;
            foreach ($cm as $cell) {
                $attrs = $cell[1];
                $inner = $cell[2] ?? '';
                $ref = '';
                if (preg_match('#r="([A-Z]+\d+)"#', $attrs, $rf)) $ref = $rf[1];
                $idx = $ref !== '' ? col_index($ref) : $autoIdx;
                $autoIdx = $idx + 1;
                $type = '';
                if (preg_match('#\bt="([^"]+)"#', $attrs, $tm)) $type = $tm[1];
                $styleIdx = 0;
                if (preg_match('#\bs="(\d+)"#', $attrs, $sm)) $styleIdx = (int)$sm[1];

                $val = '';
                if ($type === 'inlineStr') {
                    if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $inner, $tm2)) {
                        foreach ($tm2[1] as $piece) $val .= html_entity_decode($piece, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                } elseif (preg_match('#<v\b[^>]*>(.*?)</v>#s', $inner, $vm)) {
                    $raw = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($type === 's') {
                        $idx2 = (int)trim($raw);
                        $val = $shared[$idx2] ?? '';
                    } elseif ($type === 'b') {
                        $val = trim($raw) === '1' ? '1' : '0';
                    } elseif ($type === 'str' || $type === 'e') {
                        $val = $raw;
                    } else {
                        $num = num_cell(trim($raw));
                        if ($num !== '' && preg_match('/^-?\d+(\.\d+)?$/', $num) && !empty($dateSty[$styleIdx])) {
                            $val = excel_serial_to_date((float)$num, $date1904);
                        } else {
                            $val = $num;
                        }
                    }
                } elseif (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $inner, $tm3)) {
                    foreach ($tm3[1] as $piece) $val .= html_entity_decode($piece, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                $cells[$idx] = clean_cell($val);
            }
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
            $rows[] = $line;
        } else {
            $rows[] = [];
        }
    }
    return $rows;
}

/* ------------------------------------------------------------------ *
 * CSV / TSV / JSON
 * ------------------------------------------------------------------ */
function detect_delimiter(string $sample): string
{
    $counts = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    $first = strtok($sample, "\n");
    if ($first === false) $first = $sample;
    foreach ($counts as $d => $_) $counts[$d] = substr_count($first, $d);
    arsort($counts);
    $best = key($counts);
    return $counts[$best] > 0 ? $best : ',';
}
function csv_rows(string $path, int $maxRows = IMPORT_MAX_ROWS + 50): array
{
    $raw = (string)file_get_contents($path);
    $raw = to_utf8($raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);   // BOM
    $delim = detect_delimiter(substr($raw, 0, 4000));
    $tmp = tmpfile();
    fwrite($tmp, $raw);
    rewind($tmp);
    $rows = [];
    while (($r = fgetcsv($tmp, 0, $delim, '"', '\\')) !== false) {
        if (count($rows) >= $maxRows) break;
        $rows[] = array_map(fn($c) => clean_cell((string)$c), $r);
    }
    fclose($tmp);
    return $rows;
}
function json_rows(string $path, int $maxRows = IMPORT_MAX_ROWS + 50): array
{
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Isi file JSON tidak valid.');
    if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
    $rows = [];
    if (!$data) return $rows;
    $keys = array_keys((array)reset($data));
    $head = array_map(fn($k) => clean_cell((string)$k), $keys);
    $rows[] = $head;
    foreach ($data as $item) {
        if (count($rows) > $maxRows) break;
        if (!is_array($item)) continue;
        $line = [];
        foreach ($keys as $k) $line[] = clean_cell((string)($item[$k] ?? ''));
        $rows[] = $line;
    }
    return $rows;
}

/**
 * Baca file apa pun menjadi ['headers' => [...], 'rows' => [[...], ...]].
 * Baris kosong dan baris yang seluruhnya kosong dibuang.
 */
function read_tabular(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xls') {
        throw new RuntimeException('Format .xls (Excel lama) tidak dapat dibaca. Buka di Excel lalu pilih "Simpan Sebagai" → Excel Workbook (.xlsx) atau CSV.');
    }
    if ($ext === 'xlsx' || $ext === 'xlsm') {
        $all = xlsx_rows($path);
    } elseif ($ext === 'json') {
        $all = json_rows($path);
    } elseif (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
        $all = csv_rows($path);
    } else {
        throw new RuntimeException('Format .' . $ext . ' belum didukung. Gunakan .xlsx, .csv, .txt, atau .json.');
    }
    // buang baris kosong di awal
    while ($all && !array_filter($all[0], fn($c) => trim((string)$c) !== '')) array_shift($all);
    if (!$all) throw new RuntimeException('File tidak berisi data.');
    $headers = array_map(fn($h) => clean_cell((string)$h), array_shift($all));
    $rows = [];
    foreach ($all as $r) {
        if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
        if (count($rows) >= IMPORT_MAX_ROWS) break;
        $line = [];
        foreach ($headers as $i => $h) $line[$i] = clean_cell((string)($r[$i] ?? ''));
        $rows[] = $line;
    }
    return ['headers' => $headers, 'rows' => $rows, 'keys' => array_map('header_key', $headers)];
}

/* ------------------------------------------------------------------ *
 * Pemetaan kolom otomatis
 * ------------------------------------------------------------------ */
/** $aliases = ['nama' => ['nama','nama_lengkap','name'], ...] */
function auto_map_columns(array $keys, array $aliases): array
{
    $map = [];
    foreach ($aliases as $field => $words) {
        foreach ($keys as $i => $k) {
            if ($k === '') continue;
            foreach ($words as $w) {
                if ($k === $w) { $map[$field] = $i; break 2; }
            }
        }
    }
    foreach ($aliases as $field => $words) {
        if (isset($map[$field])) continue;
        foreach ($keys as $i => $k) {
            if ($k === '') continue;
            foreach ($words as $w) {
                if ($w !== '' && strpos($k, $w) !== false) { $map[$field] = $i; break 2; }
            }
        }
    }
    return $map;
}
function cell(array $row, array $map, string $field): string
{
    if (!isset($map[$field])) return '';
    return clean_cell((string)($row[$map[$field]] ?? ''));
}

/* ------------------------------------------------------------------ *
 * Penulis XLSX sederhana (untuk file template)
 * ------------------------------------------------------------------ */
function xlsx_write(array $rows, string $sheetName = 'Template'): string
{
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $ri => $row) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $val) {
            $ref = '';
            $n = $ci + 1;
            while ($n > 0) { $m = ($n - 1) % 26; $ref = chr(65 + $m) . $ref; $n = (int)(($n - $m) / 26); }
            $ref .= ($ri + 1);
            $v = (string)$val;
            if ($v !== '' && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } elseif ($v !== '') {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            } else {
                $sheet .= '<c r="' . $ref . '"/>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $parts = [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];

    $zip = '';
    $central = '';
    foreach ($parts as $name => $content) {
        $crc = crc32($content);
        $comp = gzdeflate($content, 6);
        $local = "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 8) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', strlen($comp)) . pack('V', strlen($content))
            . pack('v', strlen($name)) . pack('v', 0) . $name;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 8) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', strlen($comp)) . pack('V', strlen($content))
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', 0) . pack('V', strlen($zip)) . $name;
        $zip .= $local . $comp;
    }
    $zip .= $central . "PK\x05\x06" . pack('v', 0) . pack('v', 0)
        . pack('v', count($parts)) . pack('v', count($parts))
        . pack('V', strlen($central)) . pack('V', strlen($zip)) . pack('v', 0);
    return $zip;
}

/* ------------------------------------------------------------------ *
 * Direktori file upload sementara (di luar folder publik)
 * ------------------------------------------------------------------ */
function import_tmp_dir(): string
{
    $base = dirname(APP_DIR) . '/naveena_imports';
    if (!is_dir($base)) @mkdir($base, 0770, true);
    if (!is_dir($base) || !is_writable($base)) {
        $base = rtrim(sys_get_temp_dir(), '/') . '/naveena_imports';
        if (!is_dir($base)) @mkdir($base, 0770, true);
    }
    // bersihkan file > 1 hari
    foreach (glob($base . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
    }
    return $base;
}
